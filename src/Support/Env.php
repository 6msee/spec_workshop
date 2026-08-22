<?php

declare(strict_types=1);

namespace UpFix\Support;

use Dotenv\Dotenv;

/**
 * Thin wrapper around vlucas/phpdotenv. Loads the process environment once
 * and exposes a required/optional accessor so a missing configuration key
 * fails loudly (RuntimeException naming the key) instead of silently
 * becoming an empty string somewhere downstream.
 */
final class Env
{
    private static bool $loaded = false;

    public static function load(string $rootDir): void
    {
        if (self::$loaded) {
            return;
        }

        $dotenv = Dotenv::createImmutable($rootDir);
        $dotenv->load();

        self::$loaded = true;
    }

    /**
     * @throws \RuntimeException when $key is absent and no $default was passed by the caller
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value !== false && $value !== null && $value !== '') {
            return (string) $value;
        }

        if (func_num_args() >= 2) {
            // Caller explicitly supplied a default (even if it is null itself) — honor it.
            return $default;
        }

        throw new \RuntimeException("Missing required environment variable: {$key}");
    }
}
