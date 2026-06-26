<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Log\Database\DatabaseHandler;
use SineMacula\Log\Database\DatabaseLogger;
use SineMacula\Log\Database\Models\LogMessage;
use Tests\TestCase;

/**
 * Integration tests for database log persistence.
 *
 * These exercise the full write / read / prune path against a real database
 * connection. They run on in-memory SQLite by default and against MySQL and
 * PostgreSQL in CI (selected via the DB_DRIVER environment variable), so the
 * driver-sensitive parts of the schema - the JSON context column, the UUID
 * primary key, and the microsecond `created_at` timestamp - are validated on
 * every supported engine.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(DatabaseHandler::class)]
#[CoversClass(DatabaseLogger::class)]
#[CoversClass(LogMessage::class)]
final class DatabaseLoggingIntegrationTest extends TestCase
{
    /**
     * Test that a log record persists and reads back intact across drivers.
     *
     * @return void
     */
    public function testPersistsAndReadsBackARecord(): void
    {
        Config::set('logging.channels.database.level', 'debug');

        $handler = new DatabaseHandler;

        $record = new LogRecord(
            datetime: new \DateTimeImmutable('2026-01-02 03:04:05.123456'),
            channel: 'database',
            level: Level::Warning,
            message: 'Integration message',
            context: ['user_id' => 7, 'meta' => ['ip' => '127.0.0.1', 'tags' => ['a', 'b']]],
            extra: ['request_id' => 'rid-9'],
        );

        $handler->handle($record);

        $log = LogMessage::query()->first();

        self::assertNotNull($log);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $log->id,
        );
        self::assertSame('WARNING', $log->level);
        self::assertSame('database', $log->channel);
        self::assertSame('Integration message', $log->message);
        self::assertSame(7, $log->context['user_id']);
        self::assertSame('rid-9', $log->extra['request_id']);

        $meta = $log->context['meta'];
        self::assertIsArray($meta);
        self::assertSame('127.0.0.1', $meta['ip']);
        self::assertSame(['a', 'b'], $meta['tags']);
        self::assertSame('2026-01-02 03:04:05.123456', $log->created_at->format('Y-m-d H:i:s.u'));
    }

    /**
     * Test that records logged through the registered `database` channel reach
     * the database via the full driver stack.
     *
     * @return void
     */
    public function testLogsThroughTheDatabaseChannel(): void
    {
        Config::set('logging.channels.database', [
            'driver' => 'database',
            'level'  => 'debug',
        ]);

        Log::channel('database')->info('Via the channel', ['scope' => 'integration']);

        $log = LogMessage::query()->first();

        self::assertNotNull($log);
        self::assertSame('INFO', $log->level);
        self::assertSame('Via the channel', $log->message);
        self::assertSame('integration', $log->context['scope']);
    }

    /**
     * Test that pruning removes records older than the retention window while
     * keeping recent ones.
     *
     * @return void
     */
    public function testPrunesRecordsOlderThanRetentionWindow(): void
    {
        Config::set('logging.channels.database.days', 30);

        Carbon::setTestNow('2026-01-31 12:00:00');

        try {

            LogMessage::query()->create([
                'level'      => 'INFO',
                'message'    => 'Old record',
                'created_at' => Carbon::now()->subDays(40),
            ]);
            LogMessage::query()->create([
                'level'      => 'INFO',
                'message'    => 'Recent record',
                'created_at' => Carbon::now()->subDays(10),
            ]);

            (new LogMessage)->prunable()->delete();

            $this->assertDatabaseMissing('logs', ['message' => 'Old record']);
            $this->assertDatabaseHas('logs', ['message' => 'Recent record']);
        } finally {
            Carbon::setTestNow();
        }
    }
}
