<?php

declare(strict_types=1);

namespace UpFix\Db;

use PDO;
use PDOException;
use PDOStatement;
use UpFix\Support\Env;

/**
 * PDO sqlsrv connection factory + parameterised query helper + SQL Server
 * deadlock (error 1205) retry wrapper. This is the ONLY seam the rest of
 * the application may use to talk to the database — every statement goes
 * through run() so parameter binding is the only way user input reaches
 * SQL (SPEC.md 10.2).
 */
final class Connection
{
    private PDO $pdo;

    public function __construct(
        string $host,
        string $port,
        string $dbName,
        string $user,
        string $pass,
        string $encrypt = 'yes',
        string $trustServerCert = 'yes',
    ) {
        $dsn = sprintf(
            'sqlsrv:Server=%s,%s;Database=%s;Encrypt=%s;TrustServerCertificate=%s;LoginTimeout=5',
            $host,
            $port,
            $dbName,
            $encrypt,
            $trustServerCert,
        );

        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public static function fromEnv(): self
    {
        return new self(
            host: Env::get('DB_HOST'),
            port: Env::get('DB_PORT', '1433'),
            dbName: Env::get('DB_NAME'),
            user: Env::get('DB_USER'),
            pass: Env::get('DB_PASS', ''),
            encrypt: Env::get('DB_ENCRYPT', 'yes'),
            trustServerCert: Env::get('DB_TRUST_SERVER_CERT', 'yes'),
        );
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Always prepare() + execute($params) — the only way user input may
     * reach SQL. Never call the raw PDO query method with interpolated strings.
     *
     * @param array<int|string, mixed> $params
     */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    /**
     * Runs $fn, retrying up to $maxAttempts total attempts when the caught
     * PDOException carries SQL Server error 1205 (deadlock victim). Any
     * other PDOException is rethrown immediately without retry.
     *
     * Empirically confirmed against the real installed pdo_sqlsrv driver
     * (msphpsql 5.13.3, PHP 8.5.4, ODBC Driver 18.6.2.1): a deadlock raised
     * by SQL Server surfaces as a PDOException whose errorInfo array is
     * [SQLSTATE, driver-specific error code, message]; errorInfo[1] carries
     * the underlying SQL Server error number (1205 for a deadlock victim),
     * matching RESEARCH.md Assumption A3's predicted shape exactly — see
     * 01-01-SUMMARY.md for the captured var_dump.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function withDeadlockRetry(callable $fn, int $maxAttempts = 3): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $fn();
            } catch (PDOException $e) {
                $sqlServerCode = $e->errorInfo[1] ?? null;
                $attempt++;

                if ($sqlServerCode !== 1205 || $attempt >= $maxAttempts) {
                    throw $e;
                }

                usleep(random_int(50_000, 200_000));
            }
        }
    }
}
