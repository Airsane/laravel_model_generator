<?php

/**
 * Created by Cristian.
 * Date: 11/09/16 09:26 PM.
 */

namespace Reliese\Coders\Model\Relations;

use Reliese\Support\Dumper;
use Illuminate\Support\Fluent;
use Reliese\Coders\Model\Model;
use Reliese\Coders\Model\Relation;

abstract class HasOneOrMany implements Relation
{
    /**
     * @var \Illuminate\Support\Fluent
     */
    protected $command;

    /**
     * @var \Reliese\Coders\Model\Model
     */
    protected $parent;

    /**
     * @var \Reliese\Coders\Model\Model
     */
    protected $related;

    /**
     * HasManyWriter constructor.
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
    abstract public function hint():string;

    /**
     * @return string
     */
    abstract public function name():string;

    /**
     * @return string
     */
    public function body():string
    {
        $body = 'return $this->'.$this->method().'(';

        $body .= $this->related->getQualifiedUserClassName().'::class';

        if ($this->needsForeignKey()) {
            $foreignKey = $this->parent->usesPropertyConstants()
                ? $this->related->getQualifiedUserClassName().'::'.strtoupper($this->foreignKey())
                : $this->foreignKey();
            $body .= ', '.Dumper::export($foreignKey);
        }

        if ($this->needsLocalKey()) {
            $localKey = $this->related->usesPropertyConstants()
                ? $this->related->getQualifiedUserClassName().'::'.strtoupper($this->localKey())
                : $this->localKey();
            $body .= ', '.Dumper::export($localKey);
        }

        $body .= ');';

        return $body;
    }

    /**
     * @return string
     */
    abstract protected function method():string;

    /**
     * @return bool
     */
    protected function needsForeignKey()
    {
        $defaultForeignKey = $this->parent->getRecordName().'_id';

        return $defaultForeignKey != $this->foreignKey() || $this->needsLocalKey();
    }

    /**
     * @return string
     */
    protected function foreignKey():string
    {
        return $this->command->columns[0];
    }

    /**
     * @return bool
     */
    protected function needsLocalKey():bool
    {
        return $this->parent->getPrimaryKey() != $this->localKey();
    }

    /**
     * @return string
     */
    protected function localKey():string
    {
        return $this->command->references[0];
    }
}
