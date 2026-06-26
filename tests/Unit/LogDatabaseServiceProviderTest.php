<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Log\Logger;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Log\Database\LogDatabaseServiceProvider;
use Tests\TestCase;

/**
 * Tests for the LogDatabaseServiceProvider.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(LogDatabaseServiceProvider::class)]
final class LogDatabaseServiceProviderTest extends TestCase
{
    /**
     * Test that boot registers the `database` log driver with the log manager.
     *
     * @return void
     */
    public function testBootRegistersTheDatabaseLogDriver(): void
    {
        /** @var \Illuminate\Foundation\Application $app */
        $app = $this->app;

        (new LogDatabaseServiceProvider($app))->boot();

        Config::set('logging.channels.database', ['driver' => 'database']);

        // Resolve the channel through the registered driver. The underlying
        // Monolog logger is named 'database', which distinguishes the package's
        // driver from Laravel's emergency-logger fallback.
        $channel = $app->make(LogManager::class)->channel('database');
        self::assertInstanceOf(Logger::class, $channel);

        $monolog = $channel->getLogger();
        self::assertInstanceOf(\Monolog\Logger::class, $monolog);
        self::assertSame('database', $monolog->getName());
    }

    /**
     * Test that boot loads the package migrations from the package directory.
     *
     * @return void
     */
    public function testBootLoadsThePackageMigrations(): void
    {
        /** @var \Illuminate\Foundation\Application $app */
        $app = $this->app;

        (new LogDatabaseServiceProvider($app))->boot();

        $expected = realpath(__DIR__ . '/../../database/migrations');
        $paths    = array_map('realpath', $app->make(Migrator::class)->paths());

        self::assertContains($expected, $paths);
    }

    /**
     * Test that boot publishes the migration from the package directory under
     * the expected tag.
     *
     * @return void
     */
    public function testBootPublishesTheMigrationFromThePackageDirectory(): void
    {
        /** @var \Illuminate\Foundation\Application $app */
        $app = $this->app;

        (new LogDatabaseServiceProvider($app))->boot();

        $paths    = ServiceProvider::pathsToPublish(LogDatabaseServiceProvider::class, 'log-database-migrations');
        $expected = realpath(__DIR__ . '/../../database/migrations');
        $sources  = array_map('realpath', array_keys($paths));

        self::assertContains($expected, $sources);
    }
}
