<?php

declare(strict_types = 1);

namespace SineMacula\Log\Database\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * The log message model.
 *
 * @property string $id
 * @property string $level
 * @property string|null $channel
 * @property string $message
 * @property \Illuminate\Database\Eloquent\Casts\ArrayObject<array-key, mixed> $context
 * @property \Illuminate\Database\Eloquent\Casts\ArrayObject<array-key, mixed> $extra
 * @property \Carbon\CarbonImmutable $created_at
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class LogMessage extends Model
{
    use HasUuids, MassPrunable;

    /** @var bool Indicates if the model should be timestamped */
    public $timestamps = false;

    /** @var string|null The table associated with the model */
    protected $table = 'logs';

    /** @var array<int, string> The attributes that are mass assignable */
    protected $fillable = ['level', 'channel', 'message', 'context', 'extra', 'created_at'];

    /** @var string|null The storage format of the model's date columns */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * Get the current connection name for the model.
     *
     * Allows the log connection to be configured independently of the
     * application's default connection (e.g. to write logs outside the host
     * request's database transaction).
     *
     * @return string|null
     */
    #[\Override]
    public function getConnectionName(): ?string
    {
        $connection = config('logging.channels.database.connection');

        return is_string($connection) ? $connection : parent::getConnectionName();
    }

    /**
     * Get the prunable model query.
     *
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function prunable(): Builder
    {
        $days = config('logging.channels.database.days');

        // Without a positive retention window configured, prune nothing. A zero
        // or non-numeric value must NOT fall through to `subDays(0)` (== now),
        // which would match every row and silently wipe the entire table. An
        // empty key set resolves to a "0 = 1" predicate that matches no rows.
        if (!is_numeric($days) || (int) $days <= 0) {
            return self::query()->whereKey([]);
        }

        return self::query()->where('created_at', '<=', now()->subDays((int) $days));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'level'      => 'string',
            'channel'    => 'string',
            'message'    => 'string',
            'context'    => AsArrayObject::class,
            'extra'      => AsArrayObject::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
