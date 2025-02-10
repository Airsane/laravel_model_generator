<?php

/**
 * Created by Cristian.
 * Date: 18/09/16 08:36 PM.
 */

namespace Reliese\Meta\Sqlite;

use Illuminate\Support\Fluent;

class Column implements \Reliese\Meta\Column
{
    /**
     * @var array
     */
    protected array $metadata;

    /**
     * @var array
     */
    protected array $metas = [
        'type', 'name', 'autoincrement', 'nullable', 'default', 'comment',
    ];

    /**
     * @var array
     */
    public static array $mappings = [
        'string' => ['varchar', 'text', 'string', 'char', 'enum', 'tinytext', 'mediumtext', 'longtext'],
        'datetime' => ['datetime', 'year', 'date', 'time', 'timestamp'],
        'int' => ['bigint', 'int', 'integer', 'tinyint', 'smallint', 'mediumint'],
        'float' => ['float', 'decimal', 'numeric', 'dec', 'fixed', 'double', 'real', 'double precision'],
        'boolean' => ['longblob', 'blob', 'bit'],
    ];

    /**
     * MysqlColumn constructor.
     *
     * @param array $metadata
     */
    public function __construct(array $metadata = [])
    {
        $this->metadata = $metadata;
    }

    /**
     * @return \Illuminate\Support\Fluent
     */
    public function normalize():Fluent
    {
        $attributes = new Fluent();

        foreach ($this->metas as $meta) {
            $this->{'parse'.ucfirst($meta)}($attributes);
        }

        return $attributes;
    }

    /**
     * @param \Illuminate\Support\Fluent $attributes
     */
    protected function parseType(Fluent $attributes):void
    {
        $dataType = $this->metadata->getType()->getName();

        foreach (static::$mappings as $phpType => $database) {
            if (in_array($dataType, $database)) {
                $attributes['type'] = $phpType;
            }
        }

        if ($attributes['type'] == 'int') {
            $attributes['unsigned'] = $this->metadata->getUnsigned();
        }
    }

    /**
     * @param \Illuminate\Support\Fluent $attributes
     */
    protected function parseName(Fluent $attributes):void
    {
        $attributes['name'] = $this->metadata->getName();
    }

    /**
     * @param \Illuminate\Support\Fluent $attributes
     */
    protected function parseAutoincrement(Fluent $attributes):void
    {
        $attributes['autoincrement'] = $this->metadata->getAutoincrement();
    }

    /**
     * @param \Illuminate\Support\Fluent $attributes
     */
    protected function parseNullable(Fluent $attributes):void
    {
        $attributes['nullable'] = $this->metadata->getNotnull();
    }

    /**
     * @param \Illuminate\Support\Fluent $attributes
     */
    protected function parseDefault(Fluent $attributes):void
    {
        $attributes['default'] = $this->metadata->getDefault();
    }

    /**
     * @param \Illuminate\Support\Fluent $attributes
     */
    protected function parseComment(Fluent $attributes):void
    {
        $attributes['comment'] = $this->metadata->getComment();
    }
}
