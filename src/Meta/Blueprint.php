<?php

/**
 * Created by Cristian.
 * Date: 18/09/16 08:19 PM.
 */

namespace Reliese\Meta;

use Illuminate\Support\Fluent;

class Blueprint
{
    /**
     * @var string
     */
    protected string $connection;

    /**
     * @var string
     */
    protected string $schema;

    /**
     * @var string
     */
    protected string $table;

    /**
     * @var \Illuminate\Support\Fluent[]
     */
    protected array $columns = [];

    /**
     * @var \Illuminate\Support\Fluent[]
     */
    protected array $indexes = [];

    /**
     * @var \Illuminate\Support\Fluent[]
     */
    protected array $unique = [];

    /**
     * @var \Illuminate\Support\Fluent[]
     */
    protected array $relations = [];

    /**
     * @var \Illuminate\Support\Fluent
     */
    protected Fluent $primaryKey;

    /**
     * @var bool
     */
    protected bool $isView;

    /**
     * Blueprint constructor.
     *
     * @param string $connection
     * @param string $schema
     * @param string $table
     */
    public function __construct(string $connection, string $schema, string $table, bool $isView = false)
    {
        $this->connection = $connection;
        $this->schema = $schema;
        $this->table = $table;
        $this->isView = $isView;
    }

    /**
     * @return string
     */
    public function schema(): string
    {
        return $this->schema;
    }

    /**
     * @return string
     */
    public function table(): string
    {
        return $this->table;
    }

    /**
     * @return string
     */
    public function qualifiedTable(): string
    {
        return $this->schema().'.'.$this->table();
    }

    /**
     * @param \Illuminate\Support\Fluent $column
     *
     * @return $this
     */
    public function withColumn(Fluent $column): self
    {
        $this->columns[$column->name] = $column;

        return $this;
    }

    /**
     * @return \Illuminate\Support\Fluent[]
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public function hasColumn(string $name): bool
    {
        return array_key_exists($name, $this->columns);
    }

    /**
     * @param string $name
     *
     * @return \Illuminate\Support\Fluent
     */
    public function column(string $name): Fluent
    {
        if (! $this->hasColumn($name)) {
            throw new \InvalidArgumentException("Column [$name] does not belong to table [{$this->qualifiedTable()}]");
        }

        return $this->columns[$name];
    }

    /**
     * @param \Illuminate\Support\Fluent $index
     *
     * @return $this
     */
    public function withIndex(Fluent $index): self
    {
        $this->indexes[] = $index;

        if ($index->name == 'unique') {
            $this->unique[] = $index;
        }

        return $this;
    }

    /**
     * @return \Illuminate\Support\Fluent[]
     */
    public function indexes(): array
    {
        return $this->indexes;
    }

    /**
     * @param \Illuminate\Support\Fluent $index
     *
     * @return $this
     */
    public function withRelation(Fluent $index): self
    {
        $this->relations[] = $index;

        return $this;
    }

    /**
     * @return \Illuminate\Support\Fluent[]
     */
    public function relations(): array
    {
        return $this->relations;
    }

    /**
     * @param \Illuminate\Support\Fluent $primaryKey
     *
     * @return $this
     */
    public function withPrimaryKey(Fluent $primaryKey): self
    {
        $this->primaryKey = $primaryKey;

        return $this;
    }

    /**
     * @return \Illuminate\Support\Fluent
     */
    public function primaryKey(): Fluent
    {
        if ($this->primaryKey) {
            return $this->primaryKey;
        }

        if (! empty($this->unique)) {
            return current($this->unique);
        }

        $nullPrimaryKey = new Fluent(['columns' => []]);

        return $nullPrimaryKey;
    }

    /**
     * @return bool
     */
    public function hasCompositePrimaryKey(): bool
    {
        return count($this->primaryKey->columns) > 1;
    }

    /**
     * @return string
     */
    public function connection(): string
    {
        return $this->connection;
    }

    /**
     * @param string $database
     * @param string $table
     *
     * @return bool
     */
    public function is(string $database, string $table): bool
    {
        return $database == $this->schema() && $table == $this->table();
    }

    /**
     * @param \Reliese\Meta\Blueprint $table
     *
     * @return array
     */
    public function references(self $table): array
    {
        $references = [];

        foreach ($this->relations() as $relation) {
            list($foreignDatabase, $foreignTable) = array_values($relation->on);
            if ($table->is($foreignDatabase, $foreignTable)) {
                $references[] = $relation;
            }
        }

        return $references;
    }

    /**
     * @param \Illuminate\Support\Fluent $constraint
     *
     * @return bool
     */
    public function isUniqueKey(Fluent $constraint): bool
    {
        foreach ($this->unique as $index) {

            // We only need to consider cases, when UNIQUE KEY is presented by only ONE column
            if (count($index->columns) === 1 && isset($index->columns[0])) {
                if (in_array($index->columns[0], $constraint->columns)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return bool
     */
    public function isView(): bool
    {
        return $this->isView;
    }
}
