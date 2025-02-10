<?php

/**
 * Created by Cristian.
 * Date: 10/10/16 11:46 PM.
 */

namespace Reliese\Coders\Model;

use Reliese\Meta\Blueprint;

class Mutator
{
    /**
     * @var \Closure
     */
    protected \Closure $condition;

    /**
     * @var string
     */
    protected string $name;

    /**
     * @var string
     */
    protected string $body;

    /**
     * @param \Closure $condition
     *
     * @return $this
     */
    public function when(\Closure $condition): self
    {
        $this->condition = $condition;

        return $this;
    }

    /**
     * @param string $column
     * @param \Reliese\Meta\Blueprint $blueprint
     *
     * @return mixed
     */
    public function applies(string $column, Blueprint $blueprint): bool
    {
        return call_user_func($this->condition, $column, $blueprint);
    }

    /**
     * @param \Closure $name
     *
     * @return $this
     */
    public function name(\Closure $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @param string $attribute
     * @param \Reliese\Coders\Model\Model $model
     *
     * @return string
     */
    public function getName(string $attribute, Model $model): string
    {
        return call_user_func($this->name, $attribute, $model);
    }

    /**
     * @param \Closure $body
     *
     * @return $this
     */
    public function body(\Closure $body): self
    {
        $this->body = $body;

        return $this;
    }

    /**
     * @param string $attribute
     * @param \Reliese\Coders\Model\Model $model
     *
     * @return string
     */
    public function getBody(string $attribute, Model $model): string
    {
        return call_user_func($this->body, $attribute, $model);
    }
}
