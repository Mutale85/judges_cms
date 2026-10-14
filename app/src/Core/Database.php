<?php

declare(strict_types=1);

namespace JC\Core;

use PDO;

/**
 * Thin PDO wrapper. MySQL is the production target; SQLite is supported for
 * local development and the test suite.
 *
 * In transit: MySQL connections are made over TLS whenever DB_SSL_CA is set, and
 * in production the connection is refused unless TLS is configured (unless the
 * database is reached over a local UNIX socket).
 */
final class Database
{
    private PDO $pdo;
    private string $driver;
    /** Nesting depth of transaction(): PDO does not report SQLite transactions begun with exec(). */
    private int $depth = 0;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $this->driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($this->driver === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
        }
    }

    public static function fromConfig(): self
    {
        $dsn = Config::require('DB_DSN');
        $options = [];

        if (str_starts_with($dsn, 'mysql:')) {
            $ca = Config::get('DB_SSL_CA');
            $usesSocket = str_contains($dsn, 'unix_socket=');
            if ($ca) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
                if ($cert = Config::get('DB_SSL_CERT')) {
                    $options[PDO::MYSQL_ATTR_SSL_CERT] = $cert;
                }
                if ($key = Config::get('DB_SSL_KEY')) {
                    $options[PDO::MYSQL_ATTR_SSL_KEY] = $key;
                }
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = Config::bool('DB_SSL_VERIFY', true);
            } elseif (Config::isProduction() && !$usesSocket) {
                throw new \RuntimeException(
                    'Refusing to connect to MySQL over the network without TLS. Set DB_SSL_CA (see docs/SECURITY.md).'
                );
            }
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4, time_zone = '+00:00'";
        }

        $pdo = new PDO($dsn, Config::get('DB_USER'), Config::get('DB_PASS'), $options);
        $db = new self($pdo);

        if ($db->driver === 'mysql' && Config::get('DB_SSL_CA')) {
            $cipher = $db->fetch("SHOW SESSION STATUS LIKE 'Ssl_cipher'");
            if (!$cipher || ($cipher['Value'] ?? '') === '') {
                throw new \RuntimeException('MySQL connection is not encrypted although DB_SSL_CA is set.');
            }
        }
        return $db;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    /** @param array<int|string,mixed> $params */
    public function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** @param array<int|string,mixed> $params @return array<string,mixed>|null */
    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<int|string,mixed> $params @return list<array<string,mixed>> */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @param array<int|string,mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** @param array<string,mixed> $data */
    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', array_map(static fn ($c) => ':' . $c, $cols))
        );
        $this->run($sql, $data);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        $set = [];
        $params = [];
        foreach ($data as $col => $value) {
            $set[] = "{$col} = :set_{$col}";
            $params["set_{$col}"] = $value;
        }
        $cond = [];
        foreach ($where as $col => $value) {
            $cond[] = "{$col} = :where_{$col}";
            $params["where_{$col}"] = $value;
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $set), implode(' AND ', $cond));
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * Runs $fn inside a transaction. On SQLite the transaction takes the write
     * lock immediately so read-then-write sequences (e.g. the audit hash chain)
     * are serialised.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->depth > 0 || $this->pdo->inTransaction()) {
            return $fn();
        }
        if ($this->driver === 'sqlite') {
            $this->pdo->exec('BEGIN IMMEDIATE');
        } else {
            $this->pdo->beginTransaction();
        }
        $this->depth = 1;
        try {
            $result = $fn();
            if ($this->driver === 'sqlite') {
                $this->pdo->exec('COMMIT');
            } else {
                $this->pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($this->driver === 'sqlite') {
                $this->pdo->exec('ROLLBACK');
            } elseif ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            $this->depth = 0;
        }
    }

    /** Row-lock clause for SELECTs inside a transaction (no-op on SQLite). */
    public function forUpdate(): string
    {
        return $this->driver === 'mysql' ? ' FOR UPDATE' : '';
    }
}
