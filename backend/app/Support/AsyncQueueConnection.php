<?php

namespace App\Support;

/**
 * External-API jobs must be persisted for a worker; never let them fall back to
 * Laravel's inline or after-response queue drivers.
 */
final class AsyncQueueConnection
{
    private const NON_ASYNC_DRIVERS = ['sync', 'deferred', 'background', 'null'];

    public static function resolve(?string $connection, string $fallback = 'database'): string
    {
        $connection = trim((string) $connection);

        return self::isAsynchronous($connection, []) ? $connection : $fallback;
    }

    /** @param list<string> $visited */
    private static function isAsynchronous(string $connection, array $visited): bool
    {
        if ($connection === '' || in_array($connection, $visited, true)) {
            return false;
        }

        $configuration = config('queue.connections.'.$connection);
        if (! is_array($configuration) || ! is_string($configuration['driver'] ?? null)) {
            return false;
        }

        $driver = $configuration['driver'];
        if (in_array($driver, self::NON_ASYNC_DRIVERS, true)) {
            return false;
        }

        if ($driver !== 'failover') {
            return true;
        }

        $fallbackConnections = $configuration['connections'] ?? null;
        if (! is_array($fallbackConnections) || $fallbackConnections === []) {
            return false;
        }

        $visited[] = $connection;
        foreach ($fallbackConnections as $fallbackConnection) {
            if (! is_string($fallbackConnection)
                || ! self::isAsynchronous($fallbackConnection, $visited)) {
                return false;
            }
        }

        return true;
    }
}
