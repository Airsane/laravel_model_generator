<?php

namespace Reliese\Meta\SqlServer;

use Illuminate\Database\SqlServerConnection;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Reliese\Meta\Blueprint;
use Illuminate\Support\Fluent;
use Illuminate\Database\Connection;

/**
 * SQLServer Schema Metadata Handling
 * Adapted from PostgreSQL Schema implementation
 * Date: 2024-12-07
 */
class Schema implements \Reliese\Meta\Schema
{
	/**
	 * @var string
	 */
	protected $schema;

	/**
	 * @var SqlServerConnection
	 */
	protected $connection;

	/**
	 * @var bool
	 */
	protected $loaded = false;

	/**
	 * @var Blueprint[]
	 */
	protected $tables = [];

	/**
	 * @var string
	 */
	protected $schema_database;

	/**
	 * Schema constructor.
	 *
	 * @param string $schema
	 * @param SqlServerConnection $connection
	 */
	public function __construct(string $schema, SqlServerConnection $connection)
	{
		$this->schema_database = Config::get("database.connections.sqlsrv.schema", 'dbo');
		$this->schema = $schema;
		$this->connection = $connection;

		$this->load();
	}

	/**
	 * Loads schema's tables' information from the database.
	 */
	protected function load(): void
	{
		$tables = $this->fetchTables();
		foreach ($tables as $table) {
			$blueprint = new Blueprint($this->connection->getName(), $this->schema, $table);
			$this->fillColumns($blueprint);
			$this->fillConstraints($blueprint);
			$this->tables[$table] = $blueprint;
		}
		$this->loaded = true;
	}

	/**
	 * Fetch tables for the current schema
	 *
	 * @return array
	 */
	protected function fetchTables(): array
	{
		$rows = $this->arraify($this->connection->select(
			"SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES " .
			"WHERE TABLE_SCHEMA = '$this->schema_database' AND TABLE_TYPE = 'BASE TABLE'"
		));

		return array_column($rows, 'TABLE_NAME');
	}

	/**
	 * Fill columns for a given blueprint
	 *
	 * @param Blueprint $blueprint
	 */
	protected function fillColumns(Blueprint $blueprint): void
	{
		$rows = $this->arraify($this->connection->select(
			"SELECT * FROM INFORMATION_SCHEMA.COLUMNS " .
			"WHERE TABLE_SCHEMA = '$this->schema_database' " .
			"AND TABLE_NAME = " . $this->wrap($blueprint->table())
		));

		foreach ($rows as $column) {
			$blueprint->withColumn(
				$this->parseColumn($column)
			);
		}
	}

	/**
	 * Parse column metadata
	 *
	 * @param array $metadata
	 * @return Fluent
	 */
	protected function parseColumn(array $metadata): Fluent
	{
		return (new Column($metadata))->normalize();
	}

	/**
	 * Fill constraints for a given blueprint
	 *
	 * @param Blueprint $blueprint
	 */
	protected function fillConstraints(Blueprint $blueprint): void
	{
		$relations = $this->fetchTableRelations($blueprint->table());
		$this->fillPrimaryKey($relations, $blueprint);
		$this->fillRelations($relations, $blueprint);
		$this->fillIndexes($blueprint);
	}

	/**
	 * Fetch table relations
	 *
	 * @param string $tableName
	 * @return array
	 */
	protected function fetchTableRelations(string $tableName): array
	{
		$sql = "
        SELECT 
		c1.name AS column_name,
		OBJECT_NAME(fkc.referenced_object_id) AS referenced_table,
		c2.name AS referenced_column,
		fk.name AS constraint_name,
		CASE 
			WHEN kc.type = 'PK' THEN 'p'
			WHEN i.is_unique = 1 THEN 'u'
			ELSE 'f'
		END AS constraint_type
		FROM sys.foreign_keys fk
		INNER JOIN sys.foreign_key_columns fkc ON fk.object_id = fkc.constraint_object_id
		INNER JOIN sys.columns c1 ON fkc.parent_object_id = c1.object_id AND fkc.parent_column_id = c1.column_id
		INNER JOIN sys.columns c2 ON fkc.referenced_object_id = c2.object_id AND fkc.referenced_column_id = c2.column_id
		INNER JOIN sys.tables t ON t.object_id = fk.parent_object_id
		LEFT JOIN sys.key_constraints kc ON fk.parent_object_id = kc.parent_object_id AND kc.type = 'PK'
		LEFT JOIN sys.indexes i ON i.object_id = fk.parent_object_id AND i.is_unique = 1
		WHERE t.name = '$tableName' AND SCHEMA_NAME(t.schema_id) = '$this->schema_database';
		";

		return $this->arraify($this->connection->select($sql));
	}

	/**
	 * Fill primary key for blueprint
	 *
	 * @param array $relations
	 * @param Blueprint $blueprint
	 */
	protected function fillPrimaryKey(array $relations, Blueprint $blueprint): void
	{
		$pk = [];
		foreach ($relations as $row) {
			if ($row['constraint_type'] === 'p') {
				$pk[] = $row['column_name'];
			}
		}

		if (!empty($pk)) {
			$key = [
				'name' => 'primary',
				'index' => '',
				'columns' => $pk,
			];

			$blueprint->withPrimaryKey(new Fluent($key));
		}
	}

	/**
	 * Fill indexes for blueprint
	 *
	 * @param Blueprint $blueprint
	 */
	protected function fillIndexes(Blueprint $blueprint): void
	{
		$indexSql = "
        SELECT 
            i.name AS index_name,
            COL_NAME(ic.object_id, ic.column_id) AS column_name,
            i.is_unique,
            i.is_primary_key
        FROM sys.indexes i
        INNER JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id
        INNER JOIN sys.tables t ON t.object_id = i.object_id
        WHERE t.name = '{$blueprint->table()}' 
        AND SCHEMA_NAME(t.schema_id) = '$this->schema_database'
        AND i.is_primary_key = 0
        ";

		$indexes = $this->arraify($this->connection->select($indexSql));

		$processedIndexes = [];
		foreach ($indexes as $index) {
			$indexName = $index['index_name'];

			if (!isset($processedIndexes[$indexName])) {
				$processedIndexes[$indexName] = [
					'name' => $index['is_unique'] ? 'unique' : 'index',
					'columns' => [$index['column_name']],
					'index' => $indexName,
				];
			} else {
				$processedIndexes[$indexName]['columns'][] = $index['column_name'];
			}
		}

		foreach ($processedIndexes as $indexData) {
			$blueprint->withIndex(new Fluent($indexData));
		}
	}

	/**
	 * Fill relations for blueprint
	 *
	 * @param array $relations
	 * @param Blueprint $blueprint
	 */
	protected function fillRelations(array $relations, Blueprint $blueprint): void
	{
		$fk = [];
		foreach ($relations as $row) {
			if ($row['constraint_type'] === 'f') {
				$relName = $row['constraint_name'];
				if (!array_key_exists($relName, $fk)) {
					$fk[$relName] = [
						'columns' => [],
						'ref' => [],
					];
				}
				$fk[$relName]['columns'][] = $row['column_name'];
				$fk[$relName]['ref'][] = $row['referenced_column'];
				$fk[$relName]['table'] = $row['referenced_table'];
			}
		}

		foreach ($fk as $row) {
			$relation = [
				'name' => 'foreign',
				'index' => '',
				'columns' => $row['columns'],
				'references' => $row['ref'],
				'on' => [$this->schema, $row['table']],
			];

			$blueprint->withRelation(new Fluent($relation));
		}
	}

	/**
	 * Quick conversion of database results to array
	 *
	 * @param $data
	 * @return mixed
	 */
	protected function arraify($data)
	{
		return json_decode(json_encode($data), true);
	}

	/**
	 * Wrap values for SQL queries
	 *
	 * @param string $table
	 * @return string
	 */
	protected function wrap(string $table): string
	{
		$pieces = explode('.', str_replace('\'', '', $table));

		return implode('.', array_map(function ($piece) {
			return "'$piece'";
		}, $pieces));
	}

	/**
	 * Get available schemas/databases
	 *
	 * @param Connection $connection
	 * @return array
	 */
	public static function schemas(Connection $connection): array
	{
		$schemas = $connection->select('SELECT name FROM sys.databases');
		$schemas = array_column($schemas, 'name');

		return array_diff($schemas, [
			'master',
			'tempdb',
			'model',
			'msdb',
		]);
	}

	/**
	 * Get current schema
	 *
	 * @return string
	 */
	public function schema(): string
	{
		return $this->schema;
	}

	/**
	 * Check if table exists in schema
	 *
	 * @param string $table
	 * @return bool
	 */
	public function has($table): bool
	{
		return array_key_exists($table, $this->tables);
	}

	/**
	 * Get all tables
	 *
	 * @return Blueprint[]
	 */
	public function tables(): array
	{
		return $this->tables;
	}

	/**
	 * Get specific table
	 *
	 * @param string $table
	 * @return Blueprint
	 * @throws InvalidArgumentException
	 */
	public function table($table): Blueprint
	{
		if (!$this->has($table)) {
			throw new InvalidArgumentException("Table [$table] does not belong to schema [$this->schema]");
		}

		return $this->tables[$table];
	}

	/**
	 * Get connection
	 *
	 * @return Connection
	 */
	public function connection()
	{
		return $this->connection;
	}

	/**
	 * Find tables referencing a given table
	 *
	 * @param Blueprint $table
	 * @return array
	 */
	public function referencing(Blueprint $table): array
	{
		$references = [];

		foreach ($this->tables as $blueprint) {
			foreach ($blueprint->references($table) as $reference) {
				$references[] = [
					'blueprint' => $blueprint,
					'reference' => $reference,
				];
			}
		}

		return $references;
	}
}