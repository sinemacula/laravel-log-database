<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Log\Database\DatabaseHandler;
use SineMacula\Log\Database\Models\LogMessage;
use Tests\TestCase;

/**
 * Tests for the DatabaseHandler.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(DatabaseHandler::class)]
final class DatabaseHandlerTest extends TestCase
{
    /**
     * Test that write creates a LogMessage record in the database.
     *
     * @return void
     */
    public function testWriteCreatesLogMessageRecord(): void
    {
        Config::set('logging.channels.database.level', 'debug');

        $handler = new DatabaseHandler;

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'database',
            level: Level::Info,
            message: 'Test log message',
            context: [],
        );

        $handler->handle($record);

        $this->assertDatabaseHas('logs', [
            'level'   => 'INFO',
            'message' => 'Test log message',
        ]);
    }

    /**
     * Test that write stores level, message, and context.
     *
     * @return void
     */
    public function testWriteStoresLevelMessageAndContext(): void
    {
        Config::set('logging.channels.database.level', 'debug');

        $handler = new DatabaseHandler;

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'database',
            level: Level::Warning,
            message: 'Warning message',
            context: ['key' => 'value'],
        );

        $handler->handle($record);

        /** @phpstan-ignore staticMethod.notFound */
        $log = LogMessage::first();

        self::assertNotNull($log);
        self::assertSame('WARNING', $log->level);
        self::assertSame('Warning message', $log->message);
        self::assertStringContainsString('key', $log->getRawOriginal('context'));
    }

    /**
     * Test that write converts Throwable context to string.
     *
     * @return void
     */
    public function testWriteConvertsThrowableContextToString(): void
    {
        Config::set('logging.channels.database.level', 'debug');

        $handler   = new DatabaseHandler;
        $exception = new \RuntimeException('Something went wrong');

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'database',
            level: Level::Error,
            message: 'Error occurred',
            context: ['exception' => $exception],
        );

        $handler->handle($record);

        /** @phpstan-ignore staticMethod.notFound */
        $log = LogMessage::first();

        self::assertNotNull($log);
        self::assertStringContainsString('Something went wrong', $log->getRawOriginal('context'));
        self::assertStringNotContainsString('RuntimeException Object', $log->getRawOriginal('context'));
    }

    /**
     * Test that write respects minimum log level.
     *
     * @return void
     */
    public function testWriteRespectsMinimumLogLevel(): void
    {
        Config::set('logging.channels.database.level', 'error');

        $handler = new DatabaseHandler;

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'database',
            level: Level::Debug,
            message: 'Debug message that should be skipped',
            context: [],
        );

        $handler->handle($record);

        $this->assertDatabaseMissing('logs', [
            'message' => 'Debug message that should be skipped',
        ]);
    }

    /**
     * Test that the fallback re-emits the record at its original severity
     * rather than demoting it to debug (which a fallback channel threshold
     * could silently drop).
     *
     * @return void
     */
    public function testFallbackReEmitsRecordAtOriginalSeverity(): void
    {
        Config::set('logging.channels.database.level', 'debug');
        Config::set('logging.channels.fallback.channels', ['single']);

        Log::shouldReceive('stack')
            ->andReturnSelf();
        Log::shouldReceive('log')
            ->once()
            ->withArgs(fn (mixed $level, mixed $message): bool => $level === 'error'
                && is_string($message)
                && str_contains($message, 'Should fall back'));
        Log::shouldReceive('error')
            ->once();

        // Drop the logs table so the insert fails
        Schema::drop('logs');

        $handler = new DatabaseHandler;

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'database',
            level: Level::Error,
            message: 'Should fall back',
            context: [],
        );

        $handler->handle($record);
    }

    /**
     * Test that write stores the record datetime as created_at.
     *
     * @return void
     */
    public function testWriteStoresRecordDatetimeAsCreatedAt(): void
    {
        Config::set('logging.channels.database.level', 'debug');

        $handler  = new DatabaseHandler;
        $datetime = new \DateTimeImmutable('2026-01-02 03:04:05.123456');

        $record = new LogRecord(
            datetime: $datetime,
            channel: 'database',
            level: Level::Info,
            message: 'Dated log message',
            context: [],
        );

        $handler->handle($record);

        /** @phpstan-ignore staticMethod.notFound */
        $log = LogMessage::first();

        self::assertNotNull($log);
        self::assertNotNull($log->created_at);
        self::assertSame('2026-01-02 03:04:05.123456', $log->created_at->format('Y-m-d H:i:s.u'));
    }

    /**
     * Test that write stores records at exactly the minimum level.
     *
     * @return void
     */
    public function testWriteStoresRecordAtExactMinimumLevel(): void
    {
        Config::set('logging.channels.database.level', 'error');

        $handler = new DatabaseHandler;

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'database',
            level: Level::Error,
            message: 'Error at threshold',
            context: [],
        );

        $handler->handle($record);

        $this->assertDatabaseHas('logs', [
            'level'   => 'ERROR',
            'message' => 'Error at threshold',
        ]);
    }

    /**
     * Test that the fallback uses the single channel when the fallback
     * configuration is missing.
     *
     * @return void
     */
    public function testFallbackUsesSingleChannelWhenConfigMissing(): void
    {
        Config::set('logging.channels.database.level', 'debug');
        Config::set('logging.channels.fallback', []);

        Log::shouldReceive('stack')
            ->twice()
            ->with(['single'])
            ->andReturnSelf();
        Log::shouldReceive('log')
            ->once();
        Log::shouldReceive('error')
            ->once();

        // Drop the logs table so the insert fails
        Schema::drop('logs');

        $handler = new DatabaseHandler;

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'database',
            level: Level::Info,
            message: 'Fallback without config',
            context: [],
        );

        $handler->handle($record);
    }

    /**
     * Test that the fallback logs the formatted record and the failure reason
     * with the full exception detail.
     *
     * @return void
     */
    public function testFallbackLogsFailureWithExceptionDetail(): void
    {
        Config::set('logging.channels.database.level', 'debug');
        Config::set('logging.channels.fallback.channels', ['single']);

        Log::shouldReceive('stack')
            ->twice()
            ->with(['single'])
            ->andReturnSelf();

        Log::shouldReceive('log')
            ->once()
            ->withArgs(fn (mixed $level, mixed $message): bool => $level === 'info'
                && is_string($message)
                && str_contains($message, 'database.INFO')
                && str_contains($message, 'Should fall back'));

        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn (mixed $message, mixed $context = null): bool => $message === 'Could not log to the database.'
                && is_array($context)
                && isset($context['exception'])
                && is_string($context['exception'])
                && $context['exception'] !== '');

        // Drop the logs table so the insert fails
        Schema::drop('logs');

        $handler = new DatabaseHandler;

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'database',
            level: Level::Info,
            message: 'Should fall back',
            context: [],
        );

        $handler->handle($record);
    }

    /**
     * Test that high-level records are stored when the minimum level is debug.
     *
     * @return void
     */
    public function testHighLevelRecordIsStoredAtDebugMinimum(): void
    {
        Config::set('logging.channels.database.level', 'debug');

        $handler = new DatabaseHandler;

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'database',
            level: Level::Emergency,
            message: 'Emergency log',
            context: [],
        );

        $handler->handle($record);

        $this->assertDatabaseHas('logs', [
            'level'   => 'EMERGENCY',
            'message' => 'Emergency log',
        ]);
    }

    /**
     * Test that stored context can be read back through the model cast.
     *
     * @return void
     */
    public function testWriteStoresContextThatRoundTripsThroughTheCast(): void
    {
        Config::set('logging.channels.database.level', 'debug');

        $handler = new DatabaseHandler;

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'database',
            level: Level::Info,
            message: 'Round trip',
            context: ['key' => 'value', 'nested' => ['a' => 1]],
        );

        $handler->handle($record);

        /** @phpstan-ignore staticMethod.notFound */
        $log = LogMessage::first();

        self::assertNotNull($log);
        self::assertSame('value', $log->context['key']);

        $nested = $log->context['nested'];
        self::assertIsArray($nested);
        self::assertSame(1, $nested['a']);
    }

    /**
     * Test that an empty context is stored as a null column rather than an
     * encoded "null" string.
     *
     * @return void
     */
    public function testWriteStoresEmptyContextAsNull(): void
    {
        Config::set('logging.channels.database.level', 'debug');

        $handler = new DatabaseHandler;

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'database',
            level: Level::Info,
            message: 'No context',
            context: [],
        );

        $handler->handle($record);

        $this->assertDatabaseHas('logs', [
            'message' => 'No context',
            'context' => null,
        ]);
    }

    /**
     * Test that write stores the record's channel name.
     *
     * @return void
     */
    public function testWriteStoresTheChannel(): void
    {
        Config::set('logging.channels.database.level', 'debug');

        $handler = new DatabaseHandler;

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'audit',
            level: Level::Info,
            message: 'Channel stored',
            context: [],
        );

        $handler->handle($record);

        $this->assertDatabaseHas('logs', [
            'channel' => 'audit',
            'message' => 'Channel stored',
        ]);
    }

    /**
     * Test that write stores the record's extra data, read back via the cast.
     *
     * @return void
     */
    public function testWriteStoresExtra(): void
    {
        Config::set('logging.channels.database.level', 'debug');

        $handler = new DatabaseHandler;

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'database',
            level: Level::Info,
            message: 'With extra',
            context: [],
            extra: ['request_id' => 'abc-123'],
        );

        $handler->handle($record);

        /** @phpstan-ignore staticMethod.notFound */
        $log = LogMessage::first();

        self::assertNotNull($log);
        self::assertSame('abc-123', $log->extra['request_id']);
    }

    /**
     * Test that empty extra is stored as a null column rather than an encoded
     * "null" string.
     *
     * @return void
     */
    public function testWriteStoresEmptyExtraAsNull(): void
    {
        Config::set('logging.channels.database.level', 'debug');

        $handler = new DatabaseHandler;

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'database',
            level: Level::Info,
            message: 'No extra',
            context: [],
            extra: [],
        );

        $handler->handle($record);

        $this->assertDatabaseHas('logs', [
            'message' => 'No extra',
            'extra'   => null,
        ]);
    }

    /**
     * Test that the handler reads its minimum level from the channel config
     * passed at construction rather than the global logging config.
     *
     * @return void
     */
    public function testWriteUsesMinimumLevelFromPassedConfig(): void
    {
        $handler = new DatabaseHandler(['level' => 'error']);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'database',
            level: Level::Debug,
            message: 'Below the configured threshold',
            context: [],
        );

        $handler->handle($record);

        $this->assertDatabaseMissing('logs', [
            'message' => 'Below the configured threshold',
        ]);
    }

    /**
     * Test that a context that cannot be JSON-encoded routes to the fallback
     * instead of being silently stored as corrupt JSON.
     *
     * @return void
     */
    public function testUnencodableContextFallsBackInsteadOfStoringCorruptJson(): void
    {
        Config::set('logging.channels.database.level', 'debug');
        Config::set('logging.channels.fallback.channels', ['single']);

        Log::shouldReceive('stack')
            ->andReturnSelf();
        Log::shouldReceive('log')
            ->once();
        Log::shouldReceive('error')
            ->once();

        $handler  = new DatabaseHandler;
        $resource = fopen('php://memory', 'r');

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'database',
            level: Level::Info,
            message: 'Unencodable context',
            context: ['handle' => $resource],
        );

        $handler->handle($record);

        fclose($resource);

        $this->assertDatabaseMissing('logs', [
            'message' => 'Unencodable context',
        ]);
    }

    /**
     * Test that database-driver channels are excluded from the fallback so the
     * failure path cannot route back into itself and recurse.
     *
     * @return void
     */
    public function testFallbackExcludesDatabaseChannelsToAvoidRecursion(): void
    {
        Config::set('logging.channels.database', ['driver' => 'database', 'level' => 'debug']);
        Config::set('logging.channels.fallback.channels', ['database', 'single']);

        // The database channel must be filtered out of the fallback (and the
        // remaining channels re-indexed) so the failure path cannot re-enter
        // itself; only the single channel remains.
        Log::shouldReceive('stack')
            ->with(['single'])
            ->andReturnSelf();
        Log::shouldReceive('log')
            ->once();
        Log::shouldReceive('error')
            ->once();

        // Drop the logs table so the insert fails
        Schema::drop('logs');

        $handler = new DatabaseHandler(['level' => 'debug']);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'database',
            level: Level::Error,
            message: 'Recurse',
            context: [],
        );

        $handler->handle($record);
    }
}
