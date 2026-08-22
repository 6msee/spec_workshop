#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use UpFix\Db\Connection;
use UpFix\Support\Env;

Env::load(__DIR__ . '/..');

$connection = Connection::fromEnv();
$pdo = $connection->pdo();

const MIGRATIONS_DIR = __DIR__ . '/../database/migrations';

function ensureTrackingTable(Connection $connection): void
{
    $connection->run(<<<SQL
        IF OBJECT_ID(N'dbo.schema_migrations', N'U') IS NULL
        CREATE TABLE dbo.schema_migrations (
            filename    NVARCHAR(200) NOT NULL PRIMARY KEY,
            applied_at  DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME()
        )
        SQL);
}

/**
 * @return string[] filenames (basename only) sorted ascending by the
 *                   leading three-digit numeric prefix
 */
function listUpMigrations(): array
{
    $files = glob(MIGRATIONS_DIR . '/*_up.sql') ?: [];
    $basenames = array_map('basename', $files);

    usort($basenames, static function (string $a, string $b): int {
        return prefixOf($a) <=> prefixOf($b) ?: strcmp($a, $b);
    });

    return $basenames;
}

function prefixOf(string $filename): int
{
    return (int) substr($filename, 0, 3);
}

/** @return string[] */
function appliedMigrations(Connection $connection): array
{
    $rows = $connection->run('SELECT filename FROM dbo.schema_migrations')->fetchAll();

    return array_column($rows, 'filename');
}

function cmdUp(Connection $connection): int
{
    ensureTrackingTable($connection);

    $applied = appliedMigrations($connection);
    $all = listUpMigrations();
    $highestAppliedPrefix = 0;

    foreach ($applied as $appliedFile) {
        $highestAppliedPrefix = max($highestAppliedPrefix, prefixOf($appliedFile));
    }

    $pending = array_values(array_diff($all, $applied));

    if ($pending === []) {
        echo "0 pending migrations.\n";

        return 0;
    }

    $pdo = $connection->pdo();

    foreach ($pending as $filename) {
        if (prefixOf($filename) < $highestAppliedPrefix) {
            echo "notice: out-of-order migration applied: {$filename}\n";
        }

        $sql = file_get_contents(MIGRATIONS_DIR . '/' . $filename);

        if ($sql === false) {
            fwrite(STDERR, "Could not read migration file: {$filename}\n");

            return 1;
        }

        $pdo->beginTransaction();

        try {
            foreach (splitBatches($sql) as $batch) {
                if (trim($batch) === '') {
                    continue;
                }

                $connection->run($batch);
            }

            $connection->run(
                'INSERT INTO dbo.schema_migrations (filename) VALUES (:filename)',
                ['filename' => $filename],
            );

            $pdo->commit();
            echo "Applied: {$filename}\n";
        } catch (\Throwable $e) {
            $pdo->rollBack();
            fwrite(STDERR, "FAILED applying {$filename}: {$e->getMessage()}\n");

            return 1;
        }
    }

    return 0;
}

/**
 * SQL Server batch separator convention: split on a line containing only
 * "GO" (case-insensitive, optional surrounding whitespace). Migrations in
 * this project do not currently use GO, but the runner supports it so a
 * future migration can use multi-batch DDL safely.
 *
 * @return string[]
 */
function splitBatches(string $sql): array
{
    return preg_split('/^\s*GO\s*$/mi', $sql) ?: [$sql];
}

function cmdStatus(Connection $connection): int
{
    ensureTrackingTable($connection);

    $applied = appliedMigrations($connection);
    $all = listUpMigrations();

    foreach ($all as $filename) {
        $state = in_array($filename, $applied, true) ? 'applied' : 'pending';
        echo "{$state}: {$filename}\n";
    }

    return 0;
}

function cmdDown(Connection $connection, int $steps): int
{
    ensureTrackingTable($connection);

    $applied = appliedMigrations($connection);
    // Order applied filenames by prefix descending so the most recently
    // numbered migration is reversed first.
    usort($applied, static fn (string $a, string $b): int => prefixOf($b) <=> prefixOf($a));

    $toReverse = array_slice($applied, 0, $steps);
    $pdo = $connection->pdo();

    foreach ($toReverse as $upFilename) {
        $downFilename = str_replace('_up.sql', '_down.sql', $upFilename);
        $downPath = MIGRATIONS_DIR . '/' . $downFilename;

        if (!file_exists($downPath)) {
            fwrite(STDERR, "Missing down script for {$upFilename}: {$downFilename}\n");

            return 1;
        }

        $sql = file_get_contents($downPath);

        $pdo->beginTransaction();

        try {
            foreach (splitBatches((string) $sql) as $batch) {
                if (trim($batch) === '') {
                    continue;
                }

                $connection->run($batch);
            }

            $connection->run(
                'DELETE FROM dbo.schema_migrations WHERE filename = :filename',
                ['filename' => $upFilename],
            );

            $pdo->commit();
            echo "Reverted: {$upFilename}\n";
        } catch (\Throwable $e) {
            $pdo->rollBack();
            fwrite(STDERR, "FAILED reverting {$upFilename}: {$e->getMessage()}\n");

            return 1;
        }
    }

    return 0;
}

$command = $argv[1] ?? 'up';

switch ($command) {
    case 'up':
        exit(cmdUp($connection));
    case 'status':
        exit(cmdStatus($connection));
    case 'down':
        $steps = 1;

        foreach (array_slice($argv, 2) as $arg) {
            if (str_starts_with($arg, '--step=')) {
                $steps = (int) substr($arg, strlen('--step='));
            }
        }

        exit(cmdDown($connection, $steps));
    default:
        fwrite(STDERR, "Unknown command: {$command}. Usage: migrate.php [up|status|down --step=N]\n");
        exit(1);
}
