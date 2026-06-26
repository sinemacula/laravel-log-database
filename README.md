# Laravel Log Database

[![Latest Stable Version](https://img.shields.io/packagist/v/sinemacula/laravel-log-database.svg)](https://packagist.org/packages/sinemacula/laravel-log-database)
[![Build Status](https://github.com/sinemacula/laravel-log-database/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-log-database/actions/workflows/tests.yml)
[![Quality Gates](https://github.com/sinemacula/laravel-log-database/actions/workflows/quality-gates.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-log-database/actions/workflows/quality-gates.yml)
[![Maintainability](https://qlty.sh/gh/sinemacula/projects/laravel-log-database/maintainability.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-log-database)
[![Code Coverage](https://qlty.sh/gh/sinemacula/projects/laravel-log-database/coverage.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-log-database)
[![Total Downloads](https://img.shields.io/packagist/dt/sinemacula/laravel-log-database.svg)](https://packagist.org/packages/sinemacula/laravel-log-database)

A custom Monolog log driver for Laravel that persists log records to a database table through an Eloquent model.
Registering the package's `database` log channel routes your application's logs into a `logs` table instead of, or
alongside, the filesystem, so they can be queried, filtered, and retained like any other model data.

The driver is deliberately thin. It adds per-channel minimum-level filtering, serialises any exception in the log
context to a string before storage, prunes old records on a retention schedule, and falls back to a configured channel
stack if the database write ever fails, so a logging backend outage never costs you the log line.

## How It Works

Laravel's logging stack is built on Monolog. This package registers a custom `database` driver with the log manager;
when you log to that channel, records flow through `DatabaseHandler`, which writes them to the `logs` table via the
`LogMessage` Eloquent model.

A few rules hold for every record before it is stored:

- **Level filtering.** Records below the channel's configured minimum level are dropped before any database work is
  done.
- **Exception serialisation.** If the context carries a `Throwable` under the `exception` key, it is cast to a string,
  so the full stack trace is stored as text rather than a serialised object.
- **Fallback on failure.** If the insert throws (for example, the database is unreachable), the record is re-emitted to
  a configured fallback channel stack so it is never silently lost.
- **Retention pruning.** `LogMessage` is mass-prunable, so Laravel's `model:prune` command removes records older than
  the configured retention window.

## Installation

```bash
composer require sinemacula/laravel-log-database
```

The service provider is registered automatically through package discovery. Publish and run the migration to create the
`logs` table:

```bash
php artisan vendor:publish --tag=log-database-migrations
php artisan migrate
```

## Configuration

Register a `database` channel in `config/logging.php`:

```php
'database' => [
    'driver'     => 'database',
    'level'      => env('LOG_LEVEL', 'debug'),
    'days'       => env('LOG_DATABASE_DAYS', 30),
    'connection' => env('LOG_DATABASE_CONNECTION'),
],
```

- `level` - the minimum Monolog level to persist; anything below it is ignored.
- `days` - how many days records are kept before `model:prune` removes them. Pruning is disabled when this
  is unset, zero, or non-numeric (it never deletes the whole table).
- `connection` - optional database connection to write logs on. Leave unset to use the application's
  default connection; point it at a separate connection to keep logs out of the host request's transaction
  (see Considerations).

Define a fallback channel that is used when a database write fails:

```php
'fallback' => [
    'driver'   => 'stack',
    'channels' => ['single'],
],
```

Then point your application at the channel by setting `LOG_CHANNEL=database`, or include `database` in a `stack` channel
to write to several destinations at once.

## Usage

Once the `database` channel is configured, log to it like any other Laravel channel:

```php
use Illuminate\Support\Facades\Log;

Log::channel('database')->info('Order shipped', ['order_id' => 42]);
Log::channel('database')->error('Payment failed', ['exception' => $throwable]);
```

Or set `LOG_CHANNEL=database` to make it the default destination for the `Log` facade and the `logger()` helper:

```php
Log::warning('Cache miss rate is high', ['rate' => 0.82]);
```

The `exception` entry is stored as its full string representation; all other context is stored as JSON.

## Pruning Old Records

`LogMessage` is mass-prunable. Schedule Laravel's `model:prune` command to delete records past the retention window
configured by the channel's `days` option:

```php
use Illuminate\Support\Facades\Schedule;
use SineMacula\Log\Database\Models\LogMessage;

Schedule::command('model:prune', ['--model' => LogMessage::class])->daily();
```

Records older than `logging.channels.database.days` days are removed on each run.

## Table Schema

| Column       | Type                 | Notes                                                  |
|--------------|----------------------|--------------------------------------------------------|
| `id`         | `uuid` (primary)     | Generated via `HasUuids`                               |
| `level`      | `string`             | Monolog level name (e.g. `INFO`, `ERROR`), indexed     |
| `channel`    | `string`, nullable   | The emitting Monolog channel                           |
| `message`    | `longText`           | The log message                                        |
| `context`    | `json`, nullable     | Log context as JSON; throwables serialised to a string |
| `extra`      | `json`, nullable     | Monolog processor output (e.g. correlation ids)        |
| `created_at` | `datetime(6)`        | Record timestamp, microsecond precision, indexed       |

## Considerations

- **Writes are synchronous.** Each record is written to the database inline, on the request that logged
  it. This keeps logs durable and immediately queryable, but high-volume logging on a hot path adds
  per-record write latency - raise the channel's minimum `level`, or route verbose channels elsewhere, if
  that matters for your workload.
- **Transactions.** Because the driver writes through Eloquent, a record logged inside a database
  transaction that later rolls back is rolled back with it. Set a separate `connection` on the channel to
  write logs outside the host request's transaction.
- **Sensitive data.** Messages, context, and serialised exception traces are stored in cleartext in a
  queryable table - a wider exposure surface than a host file log (replicas, backups, snapshots). Attach a
  [Monolog processor](https://laravel.com/docs/logging#customizing-monolog-for-channels) to the channel to
  scrub sensitive values before they are persisted, and consider setting `zend.exception_ignore_args=1` so
  exception traces do not capture call arguments.

## Requirements

- PHP ^8.3
- Laravel 12 (`illuminate/support ^12.9`)

## Testing

```bash
composer test                # PHPUnit suite in parallel via Paratest
composer test:coverage       # suite with Clover coverage output
composer test:mutation       # Infection mutation gate (min MSI 90)
composer test:mutation:full  # full mutation suite without thresholds
composer check               # static analysis and lint via qlty
composer format              # format via qlty
composer smells              # duplication / complexity smells via qlty
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a list of notable changes.

## Contributing

Contributions are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines on branching, commits, code
quality, and pull requests.

## Security

If you discover a security vulnerability, please report it responsibly. See [SECURITY.md](SECURITY.md) for the
disclosure policy and contact details.

## License

Licensed under the [Apache License, Version 2.0](https://www.apache.org/licenses/LICENSE-2.0).
