<?php

declare(strict_types = 1);

namespace SineMacula\Log\Database;

use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the database log driver.
 *
 * Publishes the logs table migration, loads it for test environments, and
 * registers the `database` custom Monolog driver with Laravel's log manager.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class LogDatabaseServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the database log driver.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'log-database-migrations');

        $this->app->make(LogManager::class)->extend('database', fn ($app, array $config) => (new DatabaseLogger)->__invoke($config));
    }
}
