<?php

/**
 * Created by Cristian.
 * Date: 11/10/16 11:43 PM.
 */

namespace Reliese\Coders\Model;

use Illuminate\Support\Str;

class Mutation
{
    /**
     * @var string
     */
    protected string $name;

    /**
     * @var string
     */
    protected string $body;

    /**
     * Mutation constructor.
     *
     * @param string $name
     * @param string $body
     */
    public function __construct(string $name, string $body)
    {
        $this->name = $name;
        $this->body = $body;
    }

    /**
     * @return string
     */
    public function name(): string
    {
        return 'get'.Str::studly($this->name).'Attribute';
    }

    /**
     * @return string
     */
    public function body(): string
    {
        return 'return '.$this->body.';';
    }
}
