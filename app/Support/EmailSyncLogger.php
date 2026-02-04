<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Helper class for email sync logging.
 * Routes all logs to the dedicated email-sync channel.
 */
class EmailSyncLogger
{
    protected static string $channel = 'email-sync';

    public static function info(string $message, array $context = []): void
    {
        Log::channel(static::$channel)->info($message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        Log::channel(static::$channel)->debug($message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        Log::channel(static::$channel)->warning($message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        Log::channel(static::$channel)->error($message, $context);
    }
}
