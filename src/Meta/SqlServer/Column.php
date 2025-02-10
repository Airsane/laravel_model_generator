<?php

namespace Reliese\Meta\SqlServer;

use Illuminate\Support\Arr;
use Illuminate\Support\Fluent;
class Column implements \Reliese\Meta\Column
{
	/**
	 * @var array
	 */
	protected $metadata;

	/**
	 * @var array
	 */
	protected array $metas = [
		'type',
		'name',
		'autoincrement',
		'nullable',
		'default',
		'comment',
	];

	/**
	 * @var array
	 * SQLServer-specific type mappings
	 */
	public static array $mappings = [
		'string' => ['varchar', 'nvarchar', 'char', 'nchar', 'text', 'ntext', 'xml', 'uniqueidentifier'],
		'datetime' => ['datetime', 'datetime2', 'datetimeoffset', 'smalldatetime', 'date', 'time'],
		'int' => ['int', 'bigint', 'smallint', 'tinyint', 'bit'],
		'float' => ['decimal', 'numeric', 'real', 'float', 'money', 'smallmoney'],
		'boolean' => ['bit'],
		'binary' => ['binary', 'varbinary', 'image', 'filestream'],
	];

	/**
	 * SQLServerColumn constructor.
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
			$this->{'parse' . ucfirst($meta)}($attributes);
		}

		return $attributes;
	}

	/**
	 * @param \Illuminate\Support\Fluent $attributes
	 */
	protected function parseType(Fluent $attributes):void
	{
		$dataType = $this->get('DATA_TYPE', 'varchar');
		$attributes['type'] = $dataType;

		foreach (static::$mappings as $phpType => $database) {
			if (in_array($dataType, $database)) {
				$attributes['type'] = $phpType;
			}
		}

		$this->parsePrecision($dataType, $attributes);
	}

	/**
	 * @param string $databaseType
	 * @param \Illuminate\Support\Fluent $attributes
	 */
	protected function parsePrecision(string $databaseType, Fluent $attributes):void
	{
		$precision = $this->get('numeric_precision', null);
		$scale = $this->get('numeric_scale', null);

		// Handle boolean/bit special case
		if ($databaseType == 'bit') {
			$attributes['type'] = 'bool';
			$attributes['size'] = 1;
			return;
		}

		// Set size and scale for numeric types
		if ($precision !== null) {
			$attributes['size'] = (int)$precision;
		}

		if ($scale !== null) {
			$attributes['scale'] = (int)$scale;
		}
	}

	/**
	 * @param \Illuminate\Support\Fluent $attributes
	 */
	protected function parseName(Fluent $attributes):void
	{
		$attributes['name'] = $this->get('column_name');
	}

	/**
	 * @param \Illuminate\Support\Fluent $attributes
	 */
	protected function parseAutoincrement(Fluent $attributes):void
	{
		$isIdentity = $this->get('is_identity');
		$attributes['autoincrement'] = $isIdentity !== null && (int)$isIdentity === 1;
	}

	/**
	 * @param \Illuminate\Support\Fluent $attributes
	 */
	protected function parseNullable(Fluent $attributes):void
	{
		$attributes['nullable'] = $this->get('is_nullable') === 'YES';
	}

	/**
	 * @param \Illuminate\Support\Fluent $attributes
	 */
	protected function parseDefault(Fluent $attributes):void
	{
		$defaultConstraint = $this->get('column_default', null);

		// Remove surrounding parentheses and potential default constraint syntax
		if ($defaultConstraint) {
			$defaultConstraint = trim($defaultConstraint, '()');
			$defaultConstraint = preg_replace('/^(N)?\'|\'(N)?$/', '', $defaultConstraint);
		}

		$attributes['default'] = $defaultConstraint;
	}

	/**
	 * @param \Illuminate\Support\Fluent $attributes
	 */
	protected function parseComment(Fluent $attributes):void
	{
		// SQLServer comments are typically stored in extended properties
		// This might require additional metadata retrieval
		$attributes['comment'] = null;
	}

	/**
	 * @param string $key
	 * @param mixed $default
	 *
	 * @return mixed
	 */
	protected function get(string $key, mixed $default = null):mixed
	{
		return Arr::get($this->metadata, strtoupper($key), $default);
	}
}
