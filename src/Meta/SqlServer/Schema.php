<?php

namespace Reliese\Meta\SqlServer;

use Illuminate\Support\Facades\Config;
use Reliese\Meta\Blueprint;
use Illuminate\Support\Fluent;
use Illuminate\Database\Connection;

class Schema implements \Reliese\Meta\Schema
{
    /**
     * @var string
     */
    protected $schema;

    /**
     * @var string
     */
    protected $schema_database;

    /**
     * @var \Illuminate\Database\Connection
     */
    protected $connection;

    /**
     * @var bool
     */
    protected $loaded = false;

    /**
     * @var \Reliese\Meta\Blueprint[]
     */
    protected $tables = [];

    /**
     * Schema constructor.
     *
     * @param string $schema
     * @param \Illuminate\Database\Connection $connection
     * @param string $schema_database
     */
    public function __construct($schema, $connection)
    {
        $this->schema = $schema;
        $this->connection = $connection;
        $this->schema_database = Config::get("database.connections.sqlsrv.schema", 'dbo');;

        $this->load();
    }

    /**
     * @return \Doctrine\DBAL\Schema\AbstractSchemaManager
     */
    public function manager()
    {
        return $this->connection->getDoctrineSchemaManager();
    }

    /**
     * Loads schema's tables' information from the database.
     */
    protected function load()
    {
        $tables = $this->fetchTables($this->schema);
        foreach ($tables as $table) {
            $blueprint = new Blueprint($this->connection->getName(), $this->schema, $table);
            $this->fillColumns($blueprint);
            $this->fillConstraints($blueprint);
            $this->tables[$table] = $blueprint;
        }
        $this->loaded = true;
    }

    /**
     * @param string $schema
     *
     * @return array
     */
    protected function fetchTables()
    {
        $rows = $this->arraify($this->connection->select(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_SCHEMA = '{$this->schema_database}'"
        ));

        return array_column($rows, 'TABLE_NAME');
    }

    /**
     * @param \Reliese\Meta\Blueprint $blueprint
     */
    protected function fillColumns(Blueprint $blueprint)
    {
        $rows = $this->arraify($this->connection->select(
            'SELECT c.*, CASE WHEN OBJECTPROPERTY(OBJECT_ID(c.TABLE_SCHEMA + \'.\' + c.TABLE_NAME), \'TableHasIdentity\') = 1 AND
            COLUMNPROPERTY(OBJECT_ID(c.TABLE_SCHEMA + \'.\' + c.TABLE_NAME), c.COLUMN_NAME, \'IsIdentity\') = 1
            THEN 1 ELSE 0 END AS IS_IDENTITY
            FROM INFORMATION_SCHEMA.COLUMNS c
            WHERE c.TABLE_NAME = '.$this->wrap($blueprint->table()).' AND c.TABLE_SCHEMA = \''.$this->schema_database.'\''
        ));

        foreach ($rows as $column) {
            $blueprint->withColumn(
                $this->parseColumn($column)
            );
        }
    }

    /**
     * @param array $metadata
     *
     * @return \Illuminate\Support\Fluent
     */
    protected function parseColumn($metadata)
    {
        return (new Column($metadata))->normalize();
    }

    /**
     * @param \Reliese\Meta\Blueprint $blueprint
     */
    protected function fillConstraints(Blueprint $blueprint)
    {
        // Get primary keys
        $primaryKeys = $this->getPrimaryKeys($blueprint);
        if (!empty($primaryKeys)) {
            $blueprint->withPrimaryKey(new Fluent([
                'name' => 'primary',
                'index' => '',
                'columns' => $primaryKeys
            ]));
        }

        // Get foreign keys
        $foreignKeys = $this->getForeignKeys($blueprint);
        foreach ($foreignKeys as $foreignKey) {
            $blueprint->withRelation(new Fluent($foreignKey));
        }

        // Get indexes
        $indexes = $this->getIndexes($blueprint);
        foreach ($indexes as $index) {
            $blueprint->withIndex(new Fluent($index));
        }
    }

    protected function getPrimaryKeys(Blueprint $blueprint)
    {
        $keys = $this->arraify($this->connection->select(
            "SELECT c.name AS column_name
            FROM sys.indexes i
            INNER JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id
            INNER JOIN sys.columns c ON ic.object_id = c.object_id AND ic.column_id = c.column_id
            INNER JOIN sys.objects o ON i.object_id = o.object_id
            INNER JOIN sys.schemas s ON o.schema_id = s.schema_id
            WHERE i.is_primary_key = 1
            AND s.name = '{$this->schema_database}'
            AND OBJECT_NAME(i.object_id) = " . $this->wrap($blueprint->table())
        ));

        return array_column($keys, 'column_name');
    }

    protected function getForeignKeys(Blueprint $blueprint)
    {
        $constraints = $this->arraify($this->connection->select(
            "SELECT
                fk.name as constraint_name,
                pc.name as ref_column,
                rc.name as column_name,
                ro.name as ref_table
            FROM sys.foreign_keys fk
            INNER JOIN sys.foreign_key_columns fkc ON fkc.constraint_object_id = fk.object_id
            INNER JOIN sys.columns rc ON fkc.parent_object_id = rc.object_id AND fkc.parent_column_id = rc.column_id
            INNER JOIN sys.columns pc ON fkc.referenced_object_id = pc.object_id AND fkc.referenced_column_id = pc.column_id
            INNER JOIN sys.objects ro ON fk.referenced_object_id = ro.object_id
            INNER JOIN sys.objects o ON fk.parent_object_id = o.object_id
            INNER JOIN sys.schemas s ON o.schema_id = s.schema_id
            WHERE s.name = '{$this->schema_database}'
            AND OBJECT_NAME(fk.parent_object_id) = " . $this->wrap($blueprint->table())
        ));

        $foreignKeys = [];
        foreach ($constraints as $constraint) {
            $foreignKeys[] = [
                'name' => 'foreign',
                'index' => $constraint['constraint_name'],
                'columns' => [$constraint['column_name']],
                'references' => [$constraint['ref_column']],
                'on' => [$this->schema, $constraint['ref_table']]
            ];
        }

        return $foreignKeys;
    }

    protected function getIndexes(Blueprint $blueprint)
    {
        $indexes = $this->arraify($this->connection->select(
            "SELECT
                i.name as index_name,
                c.name as column_name,
                i.is_unique
            FROM sys.indexes i
            INNER JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id
            INNER JOIN sys.columns c ON ic.object_id = c.object_id AND ic.column_id = c.column_id
            INNER JOIN sys.objects o ON i.object_id = o.object_id
            INNER JOIN sys.schemas s ON o.schema_id = s.schema_id
            WHERE i.is_primary_key = 0
            AND s.name = '{$this->schema_database}'
            AND OBJECT_NAME(i.object_id) = " . $this->wrap($blueprint->table())
        ));

        $result = [];
        foreach ($indexes as $index) {
            $result[] = [
                'name' => $index['is_unique'] ? 'unique' : 'index',
                'columns' => [$index['column_name']],
                'index' => $index['index_name'],
            ];
        }

        return $result;
    }

    /**
     * Quick little hack since it is no longer possible to set PDO's fetch mode
     * to PDO::FETCH_ASSOC.
     *
     * @param $data
     * @return mixed
     */
    protected function arraify($data)
    {
        return json_decode(json_encode($data), true);
    }

    /**
     * Wrap within square brackets for SQL Server.
     *
     * @param string $table
     * @return string
     */
    protected function wrap($table)
    {
        return "'$table'";
    }

    /**
     * @param \Illuminate\Database\Connection $connection
     * @return array
     */
    public static function schemas(Connection $connection)
    {
        $schemas = $connection->select("SELECT name FROM sys.databases WHERE name NOT IN ('master', 'tempdb', 'model', 'msdb')");
        return array_column($schemas, 'name');
    }

    /**
     * @return string
     */
    public function schema()
    {
        return $this->schema;
    }

    /**
     * @param string $table
     * @return bool
     */
    public function has($table)
    {
        return array_key_exists($table, $this->tables);
    }

    /**
     * @return \Reliese\Meta\Blueprint[]
     */
    public function tables()
    {
        return $this->tables;
    }

    /**
     * @param string $table
     * @return \Reliese\Meta\Blueprint
     */
    public function table($table)
    {
        if (!$this->has($table)) {
            throw new \InvalidArgumentException("Table [$table] does not belong to schema [{$this->schema}]");
        }

        return $this->tables[$table];
    }

    /**
     * @return \Illuminate\Database\Connection
     */
    public function connection()
    {
        return $this->connection;
    }

    /**
     * @param \Reliese\Meta\Blueprint $table
     * @return array
     */
    public function referencing(Blueprint $table)
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
