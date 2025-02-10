<?php

/**
 * Created by Cristian.
 * Date: 02/10/16 07:56 PM.
 */

namespace Reliese\Meta;

/**
 * Created by Cristian.
 * Date: 18/09/16 06:50 PM.
 */
interface Schema
{
    /**
     * @return \Illuminate\Database\ConnectionInterface
     */
    public function connection(): \Illuminate\Database\ConnectionInterface;

    /**
     * @return string
     */
    public function schema(): string;

    /**
     * @return \Reliese\Meta\Blueprint[]
     */
    public function tables(): array;

    /**
     * @param string $table
     *
     * @return bool
     */
    public function has(string $table): bool;

    /**
     * @param string $table
     *
     * @return \Reliese\Meta\Blueprint
     */
    public function table(string $table): Blueprint;

    /**
     * @param \Reliese\Meta\Blueprint $table
     *
     * @return array
     */
    public function referencing(Blueprint $table): array;
}
