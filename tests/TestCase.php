<?php

declare(strict_types = 1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use SineMacula\Log\Database\LogDatabaseServiceProvider;

/**
 * Base test case for the laravel-log-database package.
 *
 * Provides a database connection (in-memory SQLite by default, or MySQL /
 * PostgreSQL when the DB_DRIVER environment variable is set), registers the
 * package service provider, and runs the package migration so the `logs` table
 * is available for handler and model tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    /**
     * Get the package providers.
     *
     * @param  mixed  $app
     * @return array<int, class-string>
     */
    #[\Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [
            LogDatabaseServiceProvider::class,
        ];
    }

    /**
     * Define the environment setup.
     *
     * @param  mixed  $app
     * @return void
     */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        /** @var \Illuminate\Config\Repository $config */
        $config = $app['config'];

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', $this->getDatabaseConnection());
    }

    /**
     * Get the database connection configuration.
     *
     * Reads the DB_DRIVER environment variable to determine which database to
     * use. Defaults to in-memory SQLite for fast local testing.
     *
     * @return array<string, mixed>
     */
    private function getDatabaseConnection(): array
    {
        $driver = env('DB_DRIVER', 'sqlite');

        return match ($driver) {
            'mysql' => [
                'driver'   => 'mysql',
                'host'     => env('DB_HOST', '127.0.0.1'),
                'port'     => env('DB_PORT', '3306'),
                'database' => env('DB_DATABASE', 'log_database_test'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
                'prefix'   => '',
                'charset'  => 'utf8mb4',
            ],
            'pgsql' => [
                'driver'   => 'pgsql',
                'host'     => env('DB_HOST', '127.0.0.1'),
                'port'     => env('DB_PORT', '5432'),
                'database' => env('DB_DATABASE', 'log_database_test'),
                'username' => env('DB_USERNAME', 'postgres'),
                'password' => env('DB_PASSWORD', ''),
                'prefix'   => '',
                'charset'  => 'utf8',
            ],
            default => [
                'driver'   => 'sqlite',
                'database' => ':memory:',
                'prefix'   => '',
            ],
        };
    }
}
