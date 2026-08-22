<?php

declare(strict_types=1);

namespace UpFix\Support;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;

/**
 * Structured JSON-line logging, one channel per named component
 * (e.g. Logger::get('db'), Logger::get('http')). Every handler shares
 * the same {STORAGE_PATH}/logs/app.log destination this phase.
 */
final class Logger
{
    /** @var array<string, MonologLogger> */
    private static array $channels = [];

    public static function get(string $channel): MonologLogger
    {
        if (isset(self::$channels[$channel])) {
            return self::$channels[$channel];
        }

        $storagePath = Env::get('STORAGE_PATH', __DIR__ . '/../../storage');
        $logDir = rtrim($storagePath, '/') . '/logs';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }

        $handler = new StreamHandler($logDir . '/app.log', Level::Debug);
        $handler->setFormatter(new JsonFormatter());

        $logger = new MonologLogger($channel);
        $logger->pushHandler($handler);

        self::$channels[$channel] = $logger;

        return $logger;
    }
}
