<?php

/**
 * Created by Cristian.
 * Date: 05/09/16 11:41 PM.
 */

namespace Reliese\Coders\Model\Relations;

use Illuminate\Support\Str;
use Reliese\Support\Dumper;
use Illuminate\Support\Fluent;
use Reliese\Coders\Model\Model;
use Reliese\Coders\Model\Relation;

class BelongsTo implements Relation
{
    /**
     * @var \Illuminate\Support\Fluent
     */
    protected Fluent $command;

    /**
     * @var \Reliese\Coders\Model\Model
     */
    protected Model $parent;

    /**
     * @var \Reliese\Coders\Model\Model
     */
    protected Model $related;

    /**
     * BelongsToWriter constructor.
     *
     * @param \Illuminate\Support\Fluent $command
     * @param \Reliese\Coders\Model\Model $parent
     * @param \Reliese\Coders\Model\Model $related
     */
    public function __construct(Fluent $command, Model $parent, Model $related)
    {
        $this->command = $command;
        $this->parent = $parent;
        $this->related = $related;
    }

    /**
     * @return string
     */
    public function name():string
    {
        switch ($this->parent->getRelationNameStrategy()) {
            case 'foreign_key':
                $relationName = RelationHelper::stripSuffixFromForeignKey(
                    $this->parent->usesSnakeAttributes(),
                    $this->otherKey(),
                    $this->foreignKey()
                );
                break;
            default:
            case 'related':
                $relationName = $this->related->getClassName();
                break;
        }

        if ($this->parent->usesSnakeAttributes()) {
            return Str::snake($relationName);
        }

        return Str::camel($relationName);
    }

    /**
     * @return string
     */
    public function body():string
    {
        $body = 'return $this->belongsTo(';

        $body .= $this->related->getQualifiedUserClassName().'::class';

        if ($this->needsForeignKey()) {
            $foreignKey = $this->parent->usesPropertyConstants()
                ? $this->parent->getQualifiedUserClassName().'::'.strtoupper($this->foreignKey())
                : $this->foreignKey();
            $body .= ', '.Dumper::export($foreignKey);
        }

        if ($this->needsOtherKey()) {
            $otherKey = $this->related->usesPropertyConstants()
                ? $this->related->getQualifiedUserClassName().'::'.strtoupper($this->otherKey())
                : $this->otherKey();
            $body .= ', '.Dumper::export($otherKey);
        }

        $body .= ')';

        if ($this->hasCompositeOtherKey()) {
            // We will assume that when this happens the referenced columns are a composite primary key
            // or a composite unique key. Otherwise it should be a has-many relationship which is not
            // supported at the moment. @todo: Improve relationship resolution.
            foreach ($this->command->references as $index => $column) {
                $body .= "\n\t\t\t\t\t->where(".
                    Dumper::export($this->qualifiedOtherKey($index)).
                    ", '=', ".
                    Dumper::export($this->qualifiedForeignKey($index)).
                    ')';
            }
        }

        $body .= ';';

        return $body;
    }

    /**
     * @return string
     */
    public function hint():string
    {
        $base =  $this->related->getQualifiedUserClassName();

        if ($this->isNullable()) {
            $base .= '|null';
        }

        return $base;
    }

    /**
     * @return string
     */
    public function returnType():string
    {
        return \Illuminate\Database\Eloquent\Relations\BelongsTo::class;
    }

    /**
     * @return bool
     */
    protected function needsForeignKey():bool
    {
        $defaultForeignKey = $this->related->getRecordName().'_id';

        return $defaultForeignKey != $this->foreignKey() || $this->needsOtherKey();
    }

    /**
     * @param int $index
     *
     * @return string
     */
    protected function foreignKey(int $index = 0):string
    {
        return $this->command->columns[$index];
    }

    /**
     * @param int $index
     *
     * @return string
     */
    protected function qualifiedForeignKey(int $index = 0):string
    {
        return $this->parent->getTable().'.'.$this->foreignKey($index);
    }

    /**
     * @return bool
     */
    protected function needsOtherKey():bool
    {
        $defaultOtherKey = $this->related->getPrimaryKey();

        return $defaultOtherKey != $this->otherKey();
    }

    /**
     * @param int $index
     *
     * @return string
     */
    protected function otherKey(int $index = 0):string
    {
        return $this->command->references[$index];
    }

    /**
     * @param int $index
     *
     * @return string
     */
    protected function qualifiedOtherKey(int $index = 0):string
    {
        return $this->related->getTable().'.'.$this->otherKey($index);
    }

    /**
     * Whether the "other key" is a composite foreign key.
     *
     * @return bool
     */
    protected function hasCompositeOtherKey():bool
    {
        return count($this->command->references) > 1;
    }

    /**
     * @return bool
     */
    private function isNullable():bool
    {
        return (bool) $this->parent->getBlueprint()->column($this->foreignKey())->get('nullable');
    }
}
