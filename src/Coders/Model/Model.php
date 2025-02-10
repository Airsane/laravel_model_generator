<?php

/**
 * Created by Cristian.
 * Date: 11/09/16 12:11 PM.
 */

namespace Reliese\Coders\Model;

use Illuminate\Support\Str;
use Reliese\Meta\Blueprint;
use Illuminate\Support\Fluent;
use Illuminate\Database\Eloquent\SoftDeletes;
use Reliese\Coders\Model\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Reliese\Coders\Model\Relations\ReferenceFactory;

class Model
{
    /**
     * @var \Reliese\Meta\Blueprint
     */
    private Blueprint $blueprint;

    /**
     * @var \Reliese\Coders\Model\Factory
     */
    private Factory $factory;

    /**
     * @var array
     */
    private array $properties = [];

    /**
     * @var Relation[]
     */
    private array $relations = [];

    /**
     * @var \Reliese\Meta\Blueprint[]
     */
    private array $references = [];

    /**
     * @var array
     */
    private array $hidden = [];

    /**
     * @var array
     */
    private array $fillable = [];

    /**
     * @var array
     */
    private array $casts = [];

    /**
     * @var \Reliese\Coders\Model\Mutator[]
     */
    private array $mutators = [];

    /**
     * @var \Reliese\Coders\Model\Mutation[]
     */
    private array $mutations = [];

    /**
     * @var array
     */
    private array $hints = [];

    /**
     * @var string
     */
    private string $namespace;

    /**
     * @var string
     */
    private string $parentClass;

    /**
     * @var bool
     */
    private bool $timestamps = true;

    /**
     * @var string
     */
    private string $CREATED_AT;

    /**
     * @var string
     */
    private string $UPDATED_AT;

    /**
     * @var bool
     */
    private bool $softDeletes = false;

    /**
     * @var string
     */
    private string $DELETED_AT;

    /**
     * @var bool
     */
    private bool $showConnection = false;

    /**
     * @var string
     */
    private string $connection;

    /**
     * @var \Illuminate\Support\Fluent
     */
    private Fluent $primaryKeys;

    /**
     * @var \Illuminate\Support\Fluent
     */
    private Fluent $primaryKeyColumn;

    /**
     * @var int
     */
    private int $perPage;

    /**
     * @var string
     */
    private string $dateFormat;

    /**
     * @var bool
     */
    private bool $loadRelations;

    /**
     * @var bool
     */
    private bool $hasCrossDatabaseRelationships = false;

    /**
     * @var string
     */
    private string $tablePrefix = '';

    /**
     * @var string
     */
    private string $relationNameStrategy = '';

    /**
     * @var bool
     */
    private bool $definesReturnTypes = false;

    /**
     * ModelClass constructor.
     *
     * @param \Reliese\Meta\Blueprint $blueprint
     * @param \Reliese\Coders\Model\Factory $factory
     * @param \Reliese\Coders\Model\Mutator[] $mutators
     * @param bool $loadRelations
     */
    public function __construct(Blueprint $blueprint, Factory $factory, array $mutators = [], bool $loadRelations = true)
    {
        $this->blueprint = $blueprint;
        $this->factory = $factory;
        $this->loadRelations = $loadRelations;
        $this->mutators = $mutators;
        $this->configure();
        $this->fill();
    }

    protected function configure():self
    {
        $this->withNamespace($this->config('namespace'));
        $this->withParentClass($this->config('parent'));

        // Timestamps settings
        $this->withTimestamps($this->config('timestamps.enabled', $this->config('timestamps', true)));
        $this->withCreatedAtField($this->config('timestamps.fields.CREATED_AT', $this->getDefaultCreatedAtField()));
        $this->withUpdatedAtField($this->config('timestamps.fields.UPDATED_AT', $this->getDefaultUpdatedAtField()));

        // Soft deletes settings
        $this->withSoftDeletes($this->config('soft_deletes.enabled', $this->config('soft_deletes', false)));
        $this->withDeletedAtField($this->config('soft_deletes.field', $this->getDefaultDeletedAtField()));

        // Connection settings
        $this->withConnection($this->config('connection', false));
        $this->withConnectionName($this->blueprint->connection());

        // Pagination settings
        $this->withPerPage($this->config('per_page', $this->getDefaultPerPage()));

        // Dates settings
        $this->withDateFormat($this->config('date_format', $this->getDefaultDateFormat()));

        // Table Prefix settings
        $this->withTablePrefix($this->config('table_prefix', $this->getDefaultTablePrefix()));

        // Relation name settings
        $this->withRelationNameStrategy($this->config('relation_name_strategy', $this->getDefaultRelationNameStrategy()));

        $this->definesReturnTypes = $this->config('enable_return_types', false);

        return $this;
    }

    /**
     * Parses the model information.
     */
    protected function fill():void
    {
        $this->primaryKeys = $this->blueprint->primaryKey();

        // Process columns
        foreach ($this->blueprint->columns() as $column) {
            $this->parseColumn($column);
        }

        if (! $this->loadRelations) {
            return;
        }

        foreach ($this->blueprint->relations() as $relation) {
            $model = $this->makeRelationModel($relation);
            $belongsTo = new BelongsTo($relation, $this, $model);
            $this->relations[$belongsTo->name()] = $belongsTo;
        }

        foreach ($this->factory->referencing($this) as $related) {
            $factory = new ReferenceFactory($related, $this);
            $references = $factory->make();
            foreach ($references as $reference) {
                $this->relations[$reference->name()] = $reference;
            }
        }
    }

    /**
     * @param \Illuminate\Support\Fluent $column
     */
    protected function parseColumn(Fluent $column):void
    {
        // TODO: Check type cast is OK
        $cast = $column->type;

        $propertyName = $this->usesPropertyConstants() ? 'self::'.strtoupper($column->name) : $column->name;

        // Due to some casting problems when converting null to a Carbon instance,
        // we are going to treat Soft Deletes field as string.
        if ($column->name == $this->getDeletedAtField()) {
            $cast = 'string';
        }

        // Track attribute casts, ignoring timestamps
        if ($cast != 'string' && !in_array($propertyName, [$this->CREATED_AT, $this->UPDATED_AT])) {
            $this->casts[$propertyName] = $cast;
        }

        foreach ($this->config('casts', []) as $pattern => $casting) {
            if (Str::is($pattern, $column->name)) {
                $this->casts[$propertyName] = $cast = $casting;
                break;
            }
        }

        if ($this->isHidden($column->name)) {
            $this->hidden[] = $propertyName;
        }

        if ($this->isFillable($column->name)) {
            $this->fillable[] = $propertyName;
        }

        $this->mutate($column->name);

        // Track comment hints
        if (! empty($column->comment)) {
            $this->hints[$column->name] = $column->comment;
        }

        // Track PHP type hints
        $hint = $this->phpTypeHint($cast, $column->nullable);
        $this->properties[$column->name] = $hint;

        if ($column->name == $this->getPrimaryKey()) {
            $this->primaryKeyColumn = $column;
        }
    }

    /**
     * @param string $column
     */
    protected function mutate(string $column):void
    {
        foreach ($this->mutators as $mutator) {
            if ($mutator->applies($column, $this->getBlueprint())) {
                $this->mutations[] = new Mutation(
                    $mutator->getName($column, $this),
                    $mutator->getBody($column, $this)
                );
            }
        }
    }

    /**
     * @param \Illuminate\Support\Fluent $relation
     *
     * @return $this|\Reliese\Coders\Model\Model
     */
    public function makeRelationModel(Fluent $relation):self
    {
        list($database, $table) = array_values($relation->on);

        if ($this->blueprint->is($database, $table)) {
            return $this;
        }

        return $this->factory->makeModel($database, $table, false);
    }

    /**
     * @param string $castType
     * @param bool $nullable
     *
     * @todo Make tests
     *
     * @return string
     */
    public function phpTypeHint(string $castType, bool $nullable):string
    {
        $type = $castType;

        switch ($castType) {
            case 'object':
                $type = '\stdClass';
                break;
            case 'array':
            case 'json':
                $type = 'array';
                break;
            case 'collection':
                $type = '\Illuminate\Support\Collection';
                break;
            case 'datetime':
                $type = '\Carbon\Carbon';
                break;
            case 'binary':
                $type = 'string';
                break;
        }

        if ($nullable) {
            return $type.'|null';
        }

        return $type;
    }

    /**
     * @return string
     */
    public function getSchema():string
    {
        return $this->blueprint->schema();
    }

    /**
     * @return string
     */
    public function getTable(bool $andRemovePrefix = false):string
    {
        if ($andRemovePrefix) {
            return $this->removeTablePrefix($this->blueprint->table());
        }

        return $this->blueprint->table();
    }

    /**
     * @return string
     */
    public function getQualifiedTable():string
    {
        return $this->blueprint->qualifiedTable();
    }

    /**
     * @return string
     */
    public function getTableForQuery():string
    {
        return $this->shouldQualifyTableName()
            ? $this->getQualifiedTable()
            : $this->getTable();
    }

    /**
     * @return bool
     */
    public function shouldQualifyTableName():bool
    {
        return $this->config('qualified_tables', false);
    }

    /**
     * @return bool
     */
    public function shouldPluralizeTableName():bool
    {
        $pluralize = (bool) $this->config('pluralize', true);

        $overridePluralizeFor = $this->config('override_pluralize_for', []);
        if (count($overridePluralizeFor) > 0) {
            foreach ($overridePluralizeFor as $except) {
                if ($except == $this->getTable()) {
                    return ! $pluralize;
                }
            }
        }

        return $pluralize;
    }

    /**
     * @return bool
     */
    public function shouldLowerCaseTableName():bool
    {
        return (bool) $this->config('lower_table_name_first', false);
    }

    /**
     * @param \Reliese\Meta\Blueprint[] $references
     */
    public function withReferences(array $references):void
    {
        $this->references = $references;
    }

    /**
     * @param string $namespace
     *
     * @return $this
     */
    public function withNamespace(string $namespace):self
    {
        $this->namespace = $namespace;

        return $this;
    }

    /**
     * @return string
     */
    public function getNamespace():string
    {
        return $this->namespace;
    }

    /**
     * @return string
     */
    public function getRelationNameStrategy():string
    {
        return $this->relationNameStrategy;
    }

    /**
     * @return string
     */
    public function getBaseNamespace():string
    {
        return $this->usesBaseFiles()
            ? $this->getNamespace().'\\Base'
            : $this->getNamespace();
    }

    /**
     * @param string $parent
     *
     * @return $this
     */
    public function withParentClass(string $parent):self
    {
        $this->parentClass = '\\' . ltrim($parent, '\\');

        return $this;
    }

    /**
     * @return string
     */
    public function getParentClass():string
    {
        return $this->parentClass;
    }

    /**
     * @return string
     */
    public function getQualifiedUserClassName():string
    {
        return '\\'.$this->getNamespace().'\\'.$this->getClassName();
    }

    /**
     * @return string
     */
    public function getClassName():string
    {
        // Model names can be manually overridden by users in the config file.
        // If a config entry exists for this table, use that name, rather than generating one.
        $overriddenName = $this->config('model_names.' . $this->getTable());
        if ($overriddenName) {
            return $overriddenName;
        }

        if ($this->shouldLowerCaseTableName()) {
            return Str::studly(Str::lower($this->getRecordName()));
        }

        return Str::studly($this->getRecordName());
    }

    /**
     * @return string
     */
    public function getRecordName():string
    {
        if ($this->shouldPluralizeTableName()) {
            return Str::singular($this->removeTablePrefix($this->blueprint->table()));
        }

        return $this->removeTablePrefix($this->blueprint->table());
    }

    /**
     * @param bool $timestampsEnabled
     *
     * @return $this
     */
    public function withTimestamps(bool $timestampsEnabled):self
    {
        $this->timestamps = $timestampsEnabled;

        return $this;
    }

    /**
     * @return bool
     */
    public function usesTimestamps():bool
    {
        return $this->timestamps &&
               $this->blueprint->hasColumn($this->getCreatedAtField()) &&
               $this->blueprint->hasColumn($this->getUpdatedAtField());
    }

    /**
     * @param string $field
     *
     * @return $this
     */
    public function withCreatedAtField(string $field):self
    {
        $this->CREATED_AT = $field;

        return $this;
    }

    /**
     * @return string
     */
    public function getCreatedAtField():string
    {
        return $this->CREATED_AT;
    }

    /**
     * @return bool
     */
    public function hasCustomCreatedAtField():bool
    {
        return $this->usesTimestamps() &&
               $this->getCreatedAtField() != $this->getDefaultCreatedAtField();
    }

    /**
     * @return string
     */
    public function getDefaultCreatedAtField():string
    {
        return Eloquent::CREATED_AT;
    }

    /**
     * @param string $field
     *
     * @return $this
     */
    public function withUpdatedAtField(string $field):self
    {
        $this->UPDATED_AT = $field;

        return $this;
    }

    /**
     * @return string
     */
    public function getUpdatedAtField():string
    {
        return $this->UPDATED_AT;
    }

    /**
     * @return bool
     */
    public function hasCustomUpdatedAtField():bool
    {
        return $this->usesTimestamps() &&
               $this->getUpdatedAtField() != $this->getDefaultUpdatedAtField();
    }

    /**
     * @return string
     */
    public function getDefaultUpdatedAtField():string
    {
        return Eloquent::UPDATED_AT;
    }

    /**
     * @param bool $softDeletesEnabled
     *
     * @return $this
     */
    public function withSoftDeletes(bool $softDeletesEnabled):self
    {
        $this->softDeletes = $softDeletesEnabled;

        return $this;
    }

    /**
     * @return bool
     */
    public function usesSoftDeletes():bool
    {
        return $this->softDeletes &&
               $this->blueprint->hasColumn($this->getDeletedAtField());
    }

    /**
     * @param string $field
     *
     * @return $this
     */
    public function withDeletedAtField(string $field):self
    {
        $this->DELETED_AT = $field;

        return $this;
    }

    /**
     * @return string
     */
    public function getDeletedAtField():string
    {
        return $this->DELETED_AT;
    }

    /**
     * @return bool
     */
    public function hasCustomDeletedAtField():bool
    {
        return $this->usesSoftDeletes() &&
               $this->getDeletedAtField() != $this->getDefaultDeletedAtField();
    }

    /**
     * @return string
     */
    public function getDefaultDeletedAtField():string
    {
        return 'deleted_at';
    }

    /**
     * @return array
     */
    public function getTraits():array
    {
        $traits = $this->config('use', []);

        if (! is_array($traits)) {
            throw new \RuntimeException('Config use must be an array of valid traits to append to each model.');
        }

        if ($this->usesSoftDeletes()) {
            $traits = array_merge([SoftDeletes::class], $traits);
        }

        return $traits;
    }

    /**
     * @return bool
     */
    public function needsTableName():bool
    {
        return false === $this->shouldQualifyTableName() ||
            $this->shouldRemoveTablePrefix() ||
            $this->blueprint->table() != Str::plural($this->getRecordName()) ||
            ! $this->shouldPluralizeTableName();
    }

    /**
     * @return string
     */
    public function shouldRemoveTablePrefix():bool
    {
        return ! empty($this->tablePrefix);
    }

    /**
     * @param string $tablePrefix
     */
    public function withTablePrefix(string $tablePrefix):void
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * @param string $relationNameStrategy
     */
    public function withRelationNameStrategy(string $relationNameStrategy):void
    {
        $this->relationNameStrategy = $relationNameStrategy;
    }

    /**
     * @param string $table
     */
    public function removeTablePrefix(string $table):string
    {
        if (($this->shouldRemoveTablePrefix()) && (substr($table, 0, strlen($this->tablePrefix)) == $this->tablePrefix)) {
            $table = substr($table, strlen($this->tablePrefix));
        }

        return $table;
    }

    /**
     * @param bool $showConnection
     */
    public function withConnection(bool $showConnection):void
    {
        $this->showConnection = $showConnection;
    }

    /**
     * @param string $connection
     */
    public function withConnectionName(string $connection):void
    {
        $this->connection = $connection;
    }

    /**
     * @return bool
     */
    public function shouldShowConnection():bool
    {
        return (bool) $this->showConnection;
    }

    /**
     * @return string
     */
    public function getConnectionName():string
    {
        return $this->connection;
    }

    /**
     * @return bool
     */
    public function hasCustomPrimaryKey():bool
    {
        return count($this->primaryKeys->columns) == 1 &&
               $this->getPrimaryKey() != $this->getDefaultPrimaryKeyField();
    }

    /**
     * @return string
     */
    public function getDefaultPrimaryKeyField():string
    {
        return 'id';
    }

    /**
     * @todo: Improve it
     * @return string
     */
    public function getPrimaryKey():string
    {
        if (empty($this->primaryKeys->columns)) {
            return;
        }

        return $this->primaryKeys->columns[0];
    }

    /**
     * @return string
     * @todo: check
     */
    public function getPrimaryKeyType():string
    {
        return $this->primaryKeyColumn->type;
    }

    /**
     * @todo: Check whether it is necessary
     * @return bool
     */
    public function hasCustomPrimaryKeyCast():bool
    {
        return $this->getPrimaryKeyType() != $this->getDefaultPrimaryKeyType();
    }

    /**
     * @return string
     */
    public function getDefaultPrimaryKeyType():string
    {
        return 'int';
    }

    /**
     * @return bool
     */
    public function doesNotAutoincrement():bool
    {
        return ! $this->autoincrement();
    }

    /**
     * @return bool
     */
    public function autoincrement():bool
    {
        if ($this->primaryKeyColumn) {
            return $this->primaryKeyColumn->autoincrement === true;
        }

        return false;
    }

    /**
     * @param $perPage
     */
    public function withPerPage(int $perPage):void
    {
        $this->perPage = $perPage;
    }

    /**
     * @return int
     */
    public function getPerPage():int
    {
        return $this->perPage;
    }

    /**
     * @return bool
     */
    public function hasCustomPerPage():bool
    {
        return $this->perPage != $this->getDefaultPerPage();
    }

    /**
     * @return int
     */
    public function getDefaultPerPage():int
    {
        return 15;
    }

    /**
     * @param string $format
     *
     * @return $this
     */
    public function withDateFormat(string $format):self
    {
        $this->dateFormat = $format;

        return $this;
    }

    /**
     * @return string
     */
    public function getDateFormat():string
    {
        return $this->dateFormat;
    }

    /**
     * @return bool
     */
    public function hasCustomDateFormat():bool
    {
        return $this->dateFormat != $this->getDefaultDateFormat();
    }

    /**
     * @return string
     */
    public function getDefaultDateFormat():string
    {
        return 'Y-m-d H:i:s';
    }

    /**
     * @return string
     */
    public function getDefaultTablePrefix():string
    {
        return '';
    }

    /**
     * @return string
     */
    public function getDefaultRelationNameStrategy():string
    {
        return 'related';
    }

    /**
     * @return bool
     */
    public function hasCasts():bool
    {
        return ! empty($this->getCasts());
    }

    /**
     * @return array
     */
    public function getCasts():array
    {
        if (
            array_key_exists($this->getPrimaryKey(), $this->casts) &&
            $this->autoincrement()
        ) {
            unset($this->casts[$this->getPrimaryKey()]);
        }

        return $this->casts;
    }

    /**
     * @return bool
     */
    public function hasDates():bool
    {
        return ! empty($this->getDates());
    }

    /**
     * @return array
     */
    public function getDates():array
    {
        return array_diff(
            array_filter($this->casts, function (string $cast) {
                return $cast === 'datetime';
            }),
            [$this->CREATED_AT, $this->UPDATED_AT]
        );
    }

    /**
     * @return bool
     */
    public function usesSnakeAttributes():bool
    {
        return (bool) $this->config('snake_attributes', true);
    }

    /**
     * @return bool
     */
    public function doesNotUseSnakeAttributes():bool
    {
        return ! $this->usesSnakeAttributes();
    }

    /**
     * @return bool
     */
    public function hasHints():bool
    {
        return ! empty($this->getHints());
    }

    /**
     * @return array
     */
    public function getHints():array
    {
        return $this->hints;
    }

    /**
     * @return array
     */
    public function getProperties():array
    {
        return $this->properties;
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public function hasProperty(string $name):bool
    {
        return array_key_exists($name, $this->getProperties());
    }

    /**
     * @return \Reliese\Coders\Model\Relation[]
     */
    public function getRelations():array
    {
        return $this->relations;
    }

    /**
     * @return bool
     */
    public function hasRelations():bool
    {
        return ! empty($this->relations);
    }

    /**
     * @return \Reliese\Coders\Model\Mutation[]
     */
    public function getMutations():array
    {
        return $this->mutations;
    }

    /**
     * @param string $column
     *
     * @return bool
     */
    public function isHidden(string $column):bool
    {
        $attributes = $this->config('hidden', []);

        if (! is_array($attributes)) {
            throw new \RuntimeException('Config field [hidden] must be an array of attributes to hide from array or json.');
        }

        foreach ($attributes as $pattern) {
            if (Str::is($pattern, $column)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return bool
     */
    public function hasHidden():bool
    {
        return ! empty($this->hidden);
    }

    /**
     * @return array
     */
    public function getHidden():array
    {
        return $this->hidden;
    }

    /**
     * @param string $column
     *
     * @return bool
     */
    public function isFillable(string $column):bool
    {
        $guarded = $this->config('guarded', []);

        if (! is_array($guarded)) {
            throw new \RuntimeException('Config field [guarded] must be an array of attributes to protect from mass assignment.');
        }

        $protected = [
            $this->getCreatedAtField(),
            $this->getUpdatedAtField(),
            $this->getDeletedAtField(),
        ];

        if ($this->primaryKeys->columns) {
            $protected = array_merge($protected, $this->primaryKeys->columns);
        }

        foreach (array_merge($guarded, $protected) as $pattern) {
            if (Str::is($pattern, $column)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return bool
     */
    public function hasFillable():bool
    {
        return ! empty($this->fillable);
    }

    /**
     * @return array
     */
    public function getFillable():array
    {
        return $this->fillable;
    }

    /**
     * @return \Reliese\Meta\Blueprint
     */
    public function getBlueprint():Blueprint
    {
        return $this->blueprint;
    }

    /**
     * @param \Illuminate\Support\Fluent $command
     *
     * @return bool
     */
    public function isPrimaryKey(Fluent $command):bool
    {
        foreach ((array) $this->primaryKeys->columns as $column) {
            if (! in_array($column, $command->columns)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param \Illuminate\Support\Fluent $command
     *
     * @return bool
     */
    public function isUniqueKey(Fluent $command):bool
    {
        return $this->blueprint->isUniqueKey($command);
    }

    /**
     * @return bool
     */
    public function usesBaseFiles():bool
    {
        return $this->config('base_files', false);
    }

    /**
     * @return bool
     */
    public function usesPropertyConstants():bool
    {
        return $this->config('with_property_constants', false);
    }

    /**
     * @return bool
     */
    public function usesColumnList():bool
    {
        return $this->config('with_column_list', false);
    }

    /**
     * @return int
     */
    public function indentWithSpace():int
    {
        return (int) $this->config('indent_with_space', 0);
    }

    /**
     * @return bool
     */
    public function usesHints():bool
    {
        return $this->config('hints', false);
    }

    /**
     * @return bool
     */
    public function doesNotUseBaseFiles():bool
    {
        return ! $this->usesBaseFiles();
    }

    /**
     * @param string|null $key
     * @param mixed $default
     *
     * @return mixed
     */
    public function config(?string $key = null, mixed $default = null):mixed
    {
        return $this->factory->config($this->getBlueprint(), $key, $default);
    }

    /**
     * @return bool
     */
    public function fillableInBaseFiles(): bool
    {
        return $this->config('fillable_in_base_files', false);
    }

    /**
     * @return bool
     */
    public function hiddenInBaseFiles(): bool
    {
        return $this->config('hidden_in_base_files', false);
    }

    /**
     * @return bool
     */
    public function definesReturnTypes():bool
    {
        return $this->definesReturnTypes;
    }
}
