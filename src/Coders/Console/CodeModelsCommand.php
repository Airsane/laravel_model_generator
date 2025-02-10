<?php

namespace Reliese\Coders\Console;

use Illuminate\Console\Command;
use Reliese\Coders\Model\Factory;
use Illuminate\Contracts\Config\Repository;

class CodeModelsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected string $signature = 'code:models
                            {--s|schema= : The name of the MySQL database}
                            {--c|connection= : The name of the connection}
                            {--t|table= : The name of the table}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected string $description = 'Parse connection schema into models';

    /**
     * @var \Reliese\Coders\Model\Factory
     */
    protected Factory $models;

    /**
     * @var \Illuminate\Contracts\Config\Repository
     */
    protected Repository $config;

    /**
     * Create a new command instance.
     *
     * @param \Reliese\Coders\Model\Factory $models
     * @param \Illuminate\Contracts\Config\Repository $config
     */
    public function __construct(Factory $models, Repository $config)
    {
        parent::__construct();

        $this->models = $models;
        $this->config = $config;
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $connection = $this->getConnection();
        $schema = $this->getSchema($connection);
        $table = $this->getTable();

        // Check whether we just need to generate one table
        if ($table) {
            $this->models->on($connection)->create($schema, $table);
            $this->info("Check out your models for $table");
        }

        // Otherwise map the whole database
        else {
            $this->models->on($connection)->map($schema);
            $this->info("Check out your models for $schema");
        }
    }

    /**
     * @return string
     */
    protected function getConnection(): string
    {
        return $this->option('connection') ?: $this->config->get('database.default');
    }

    /**
     * @param string $connection
     *
     * @return string
     */
    protected function getSchema(string $connection): string
    {
        return $this->option('schema') ?: $this->config->get("database.connections.$connection.database");
    }

    /**
     * @return string
     */
    protected function getTable(): string
    {
        return $this->option('table');
    }
}
