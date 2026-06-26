<?php

declare(strict_types = 1);

namespace SineMacula\Log\Database;

use Illuminate\Support\Facades\Log;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use SineMacula\Log\Database\Models\LogMessage;

/**
 * Custom Monolog handler for database logging.
 *
 * This handler stores log records in the database using the LogMessage model.
 * It serialises exceptions for storage and falls back to other configured log
 * channels if the database write fails, preserving the original severity.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class DatabaseHandler extends AbstractProcessingHandler
{
    /**
     * Create a new database handler instance.
     *
     * The channel's minimum level is resolved once and handed to the parent
     * handler so Monolog gates records natively via isHandling() before they
     * are formatted, rather than re-evaluating the threshold per record.
     *
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config['level'] ?? config('logging.channels.database.level', 'debug'));
    }

    /**
     * Write a log record to the database.
     *
     * @param  \Monolog\LogRecord  $record
     * @return void
     */
    #[\Override]
    protected function write(LogRecord $record): void
    {
        $context = $record->context;

        // Convert exception objects to string before storing
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $context['exception'] = (string) $context['exception'];
        }

        try {
            $attributes = [
                'level'      => $record->level->getName(),
                'channel'    => $record->channel,
                'message'    => $record->message,
                'created_at' => $record->datetime,
            ];

            // Only persist the JSON columns when present. Verify each is
            // JSON-encodable first: the LogMessage model casts them with
            // AsArrayObject, which encodes without JSON_THROW_ON_ERROR, so an
            // unencodable value (resource, recursive array, malformed UTF-8)
            // would be silently stored as corrupt JSON. Throwing here routes it
            // to the fallback instead. Empty values are left as null.
            foreach (['context' => $context, 'extra' => $record->extra] as $column => $value) {
                if (empty($value)) {
                    continue;
                }

                json_encode($value, JSON_THROW_ON_ERROR);
                $attributes[$column] = $value;
            }

            LogMessage::query()->create($attributes);
        } catch (\Throwable $e) {
            $this->logToFallback($record, $e);
        }
    }

    /**
     * Log to the configured fallback channels when the database write fails.
     *
     * @param  \Monolog\LogRecord  $record
     * @param  \Throwable  $exception
     * @return void
     */
    private function logToFallback(LogRecord $record, \Throwable $exception): void
    {
        $channels = $this->resolveFallbackChannels();
        $level    = strtolower($record->level->getName());

        // Re-emit at the record's original severity so a fallback channel's own
        // level threshold does not silently drop a high-severity record.
        Log::stack($channels)->log($level, $record->formatted ?? $record->message);

        // Surface the failure with the full exception detail (class, message,
        // and trace) so a database outage is diagnosable, not just its message.
        Log::stack($channels)->error('Could not log to the database.', [
            'exception' => (string) $exception,
        ]);
    }

    /**
     * Resolve the fallback channels, excluding any backed by the database
     * driver so the failure path can never route back into this handler and
     * recurse.
     *
     * @return array<int, string>
     */
    private function resolveFallbackChannels(): array
    {
        /** @var list<string> $configured */
        $configured = (array) config('logging.channels.fallback.channels');

        $channels = array_values(array_filter(
            $configured,
            static fn (string $channel): bool => config("logging.channels.{$channel}.driver") !== 'database',
        ));

        // Default to single when nothing usable remains: no fallback
        // configured, or every channel was itself a database driver.
        return $channels === [] ? ['single'] : $channels;
    }
}
