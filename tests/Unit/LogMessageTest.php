<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Log\Database\Models\LogMessage;
use Tests\TestCase;

/**
 * Tests for the LogMessage model.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(LogMessage::class)]
final class LogMessageTest extends TestCase
{
    /**
     * Test that the model uses the 'logs' table.
     *
     * @return void
     */
    public function testModelUsesLogsTable(): void
    {
        $model = new LogMessage;

        self::assertSame('logs', $model->getTable());
    }

    /**
     * Test that the model uses HasUuids trait.
     *
     * @return void
     */
    public function testModelUsesHasUuidsTrait(): void
    {
        $traits = class_uses_recursive(LogMessage::class);

        self::assertArrayHasKey(HasUuids::class, $traits);
    }

    /**
     * Test that the model uses MassPrunable trait.
     *
     * @return void
     */
    public function testModelUsesMassPrunableTrait(): void
    {
        $traits = class_uses_recursive(LogMessage::class);

        self::assertArrayHasKey(MassPrunable::class, $traits);
    }

    /**
     * Test that timestamps are disabled.
     *
     * @return void
     */
    public function testTimestampsAreDisabled(): void
    {
        $model = new LogMessage;

        self::assertFalse($model->usesTimestamps());
    }

    /**
     * Test that the fillable attributes are correct.
     *
     * @return void
     */
    public function testFillableFieldsAreCorrect(): void
    {
        $model = new LogMessage;

        self::assertSame(['level', 'channel', 'message', 'context', 'extra', 'created_at'], $model->getFillable());
    }

    /**
     * Test that casts are correctly defined.
     *
     * @return void
     */
    public function testCastsAreCorrectlyDefined(): void
    {
        $model = new LogMessage;

        $casts = $model->getCasts();

        self::assertSame('string', $casts['level']);
        self::assertSame('string', $casts['channel']);
        self::assertSame('string', $casts['message']);
        self::assertSame(AsArrayObject::class, $casts['context']);
        self::assertSame(AsArrayObject::class, $casts['extra']);
        self::assertSame('immutable_datetime', $casts['created_at']);
    }

    /**
     * Test that the model uses the connection configured for the channel.
     *
     * @return void
     */
    public function testModelUsesConfiguredConnection(): void
    {
        Config::set('logging.channels.database.connection', 'secondary');

        self::assertSame('secondary', (new LogMessage)->getConnectionName());
    }

    /**
     * Test that the model falls back to the default connection when none is
     * configured for the channel.
     *
     * @return void
     */
    public function testModelFallsBackToDefaultConnectionWhenNoneConfigured(): void
    {
        Config::set('logging.channels.database.connection', null);

        self::assertNull((new LogMessage)->getConnectionName());
    }

    /**
     * Test that the configured channel connection takes precedence over a
     * connection set directly on the model instance.
     *
     * @return void
     */
    public function testConfiguredConnectionTakesPrecedenceOverInstanceConnection(): void
    {
        Config::set('logging.channels.database.connection', 'from-config');

        $model = new LogMessage;
        $model->setConnection('from-instance');

        self::assertSame('from-config', $model->getConnectionName());
    }

    /**
     * Test that prunable returns a query scoped to configured days.
     *
     * @return void
     */
    public function testPrunableReturnsScopedQuery(): void
    {
        Config::set('logging.channels.database.days', 30);

        $model = new LogMessage;
        $query = $model->prunable();

        /** @phpstan-ignore staticMethod.dynamicCall */
        $sql = $query->toRawSql();

        self::assertStringContainsString('created_at', $sql);
        self::assertStringContainsString('<=', $sql);
    }

    /**
     * Test that prunable cuts off at exactly the configured number of days.
     *
     * @return void
     */
    public function testPrunableCutsOffAtConfiguredDays(): void
    {
        Carbon::setTestNow('2026-01-15 12:00:00');

        try {

            Config::set('logging.channels.database.days', 30);

            /** @phpstan-ignore staticMethod.dynamicCall */
            $binding = (new LogMessage)->prunable()->getBindings()[0];

            self::assertInstanceOf(\DateTimeInterface::class, $binding);
            self::assertSame(
                Carbon::now()->subDays(30)->format('Y-m-d H:i:s.u'),
                $binding->format('Y-m-d H:i:s.u'),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Test that prunable truncates a fractional days value to whole days.
     *
     * @return void
     */
    public function testPrunableTruncatesFractionalDays(): void
    {
        Carbon::setTestNow('2026-01-15 12:00:00');

        try {

            Config::set('logging.channels.database.days', 30.75);

            /** @phpstan-ignore staticMethod.dynamicCall */
            $binding = (new LogMessage)->prunable()->getBindings()[0];

            self::assertInstanceOf(\DateTimeInterface::class, $binding);
            self::assertSame(
                Carbon::now()->subDays(30)->format('Y-m-d H:i:s.u'),
                $binding->format('Y-m-d H:i:s.u'),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Test that pruning is disabled (prunes nothing) when the days value is not
     * numeric, rather than wiping the entire table.
     *
     * @return void
     */
    public function testPrunablePrunesNothingWhenDaysIsNotNumeric(): void
    {
        Config::set('logging.channels.database.days', 'not-a-number');

        // @phpstan-ignore staticMethod.notFound
        LogMessage::create([
            'level'      => 'INFO',
            'message'    => 'Ancient record',
            'created_at' => now()->subYears(5),
        ]);

        // @phpstan-ignore staticMethod.dynamicCall
        (new LogMessage)->prunable()->delete();

        $this->assertDatabaseHas('logs', ['message' => 'Ancient record']);
    }

    /**
     * Test that pruning is disabled when the days value is zero, rather than
     * wiping the entire table.
     *
     * @return void
     */
    public function testPrunablePrunesNothingWhenDaysIsZero(): void
    {
        Config::set('logging.channels.database.days', 0);

        // @phpstan-ignore staticMethod.notFound
        LogMessage::create([
            'level'      => 'INFO',
            'message'    => 'Ancient record',
            'created_at' => now()->subYears(5),
        ]);

        // @phpstan-ignore staticMethod.dynamicCall
        (new LogMessage)->prunable()->delete();

        $this->assertDatabaseHas('logs', ['message' => 'Ancient record']);
    }

    /**
     * Test that pruning is disabled when a fractional days value rounds down to
     * zero, rather than wiping the entire table.
     *
     * @return void
     */
    public function testPrunablePrunesNothingWhenDaysRoundsDownToZero(): void
    {
        Config::set('logging.channels.database.days', 0.5);

        // @phpstan-ignore staticMethod.notFound
        LogMessage::create([
            'level'      => 'INFO',
            'message'    => 'Ancient record',
            'created_at' => now()->subYears(5),
        ]);

        // @phpstan-ignore staticMethod.dynamicCall
        (new LogMessage)->prunable()->delete();

        $this->assertDatabaseHas('logs', ['message' => 'Ancient record']);
    }

    /**
     * Test that a model can be created with valid attributes.
     *
     * @return void
     */
    public function testModelCanBeCreatedWithValidAttributes(): void
    {
        /** @phpstan-ignore staticMethod.notFound */
        $log = LogMessage::create([
            'level'      => 'INFO',
            'message'    => 'Test message',
            'context'    => json_encode(['key' => 'value']),
            'created_at' => now(),
        ]);

        self::assertNotNull($log->id);
        $this->assertDatabaseHas('logs', [
            'level'   => 'INFO',
            'message' => 'Test message',
        ]);
    }
}
