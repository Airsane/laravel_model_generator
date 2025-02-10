<?php

namespace Reliese\Meta\Sqlite;

use Reliese\Meta\Blueprint;
use Illuminate\Support\Fluent;
use Illuminate\Database\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
/**
 * Created by Cristian.
 * Date: 18/09/16 06:50 PM.
 */
class Schema implements \Reliese\Meta\Schema
{
    /**
     * @var string
     */
    protected string $schema;

    /**
     * @var \Illuminate\Database\SQLiteConnection
     */
    protected Connection $connection;

    /**
     * @var bool
     */
    protected bool $loaded = false;

    /**
     * @var \Reliese\Meta\Blueprint[]
     */
    protected array $tables = [];

    /**
     * Mapper constructor.
     *
     * @param string $schema
     * @param \Illuminate\Database\MySqlConnection $connection
     */
    public function __construct(string $schema, Connection $connection)
    {
        $this->schema = $schema;
        $this->connection = $connection;
        /* Sqlite has a bool type that doctrine isn't registering */
        $this->connection->getDoctrineConnection()->getDatabasePlatform()->registerDoctrineTypeMapping('bool', 'boolean');
        $this->load();
    }

    /**
     * @return \Doctrine\DBAL\Schema\AbstractSchemaManager
     * @todo: Use Doctrine instead of raw database queries
     */
    public function manager():AbstractSchemaManager
    {
        return $this->connection->getDoctrineSchemaManager();
    }

    /**
     * Loads schema's tables' information from the database.
     */
    protected function load():void
    {
        $tables = $this->fetchTables();

        foreach ($tables as $table) {
            $blueprint = new Blueprint($this->connection->getName(), $this->schema, $table);
            $this->fillColumns($blueprint);
            $this->fillConstraints($blueprint);
            $this->tables[$table] = $blueprint;
        }
    }

    /**
     * @return array
     * @internal param string $schema
     */
    protected function fetchTables():array
    {
        $names = $this->manager()->listTableNames();

        return array_diff($names, [
            'sqlite_master',
            'sqlite_sequence',
            'sqlite_stat1',
        ]);
    }

    /**
     * @param \Reliese\Meta\Blueprint $blueprint
     */
    protected function fillColumns(Blueprint $blueprint):void
    {
        $columns = $this->manager()->listTableColumns($blueprint->table());

        foreach ($columns as $column) {
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
    protected function fillConstraints(Blueprint $blueprint):void
    {
        $this->fillPrimaryKey($blueprint);
        $this->fillIndexes($blueprint);

        $this->fillRelations($blueprint);
    }

    /**
     * Quick little hack since it is no longer possible to set PDO's fetch mode
     * to PDO::FETCH_ASSOC.
     *
     * @param $data
     * @return mixed
     */
    protected function arraify(mixed $data):array
    {
        return json_decode(json_encode($data), true);
    }

    /**
     * @param \Reliese\Meta\Blueprint $blueprint
     * @todo: Support named primary keys
     */
    protected function fillPrimaryKey(Blueprint $blueprint):void
    {
        $indexes = $this->manager()->listTableIndexes($blueprint->table());

        $key = [
            'name' => 'primary',
            'index' => '',
            'columns' => optional($indexes['primary']??null)->getColumns()?:[],
        ];

        $blueprint->withPrimaryKey(new Fluent($key));
    }

    /**
     * @param \Reliese\Meta\Blueprint $blueprint
     * @internal param string $sql
     */
    protected function fillIndexes(Blueprint $blueprint):void
    {
        $indexes = $this->manager()->listTableIndexes($blueprint->table());
        unset($indexes['primary']);

        foreach ($indexes as $setup) {
            $index = [
                'name' => $setup->isUnique() ? 'unique' : 'index',
                'columns' => $setup->getColumns(),
                'index' => $setup->getName(),
            ];
            $blueprint->withIndex(new Fluent($index));
        }
    }

    /**
     * @param \Reliese\Meta\Blueprint $blueprint
     * @todo: Support named foreign keys
     */
    protected function fillRelations(Blueprint $blueprint):void
    {
        $relations = $this->manager()->listTableForeignKeys($blueprint->table());

        foreach ($relations as $setup) {
            $table = ['database' => '', 'table'=>$setup->getForeignTableName()];

            $relation = [
                'name' => 'foreign',
                'index' => '',
                'columns' => $setup->getColumns(),
                'references' => $setup->getForeignColumns(),
                'on' => $table,
            ];

            $blueprint->withRelation(new Fluent($relation));
        }
    }

    /**
     * @param \Illuminate\Database\Connection $connection
     *
     * @return array
     */
    public static function schemas(Connection $connection)
    {
        return ['database'];
    }

    /**
     * @return string
     */
    public function schema():string
    {
        return $this->schema;
    }

    /**
     * @param string $table
     *
     * @return bool
     */
    public function has(string $table):bool
    {
        return array_key_exists($table, $this->tables);
    }

    /**
     * @return \Reliese\Meta\Blueprint[]
     */
    public function tables():array
    {
        return $this->tables;
    }

    /**
     * @param string $table
     *
     * @return \Reliese\Meta\Blueprint
     */
    public function table(string $table):Blueprint
    {
        if (! $this->has($table)) {
            throw new \InvalidArgumentException("Table [$table] does not belong to schema [{$this->schema}]");
        }

        return $this->tables[$table];
    }

    /**
     * @return \Illuminate\Database\MySqlConnection
     */
    public function connection():Connection
    {
        return $this->connection;
    }

    /**
     * @param \Reliese\Meta\Blueprint $table
     *
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
