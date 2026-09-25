<?php

declare(strict_types=1);

namespace JC\Core;

final class Migrator
{
    public function __construct(private readonly Database $db, private readonly string $dir)
    {
    }

    /** @return list<string> migrations applied in this run */
    public function migrate(): array
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(190) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL)'
        );
        $done = array_column($this->db->fetchAll('SELECT version FROM schema_migrations'), 'version');
        $files = glob($this->dir . '/*.php') ?: [];
        sort($files);
        $applied = [];
        foreach ($files as $file) {
            $version = basename($file, '.php');
            if (in_array($version, $done, true)) {
                continue;
            }
            $statements = (require $file)($this->db->driver());
            // MySQL DDL auto-commits, so statements run individually; SQLite gets a transaction.
            if ($this->db->driver() === 'sqlite') {
                $this->db->transaction(function () use ($statements, $version): void {
                    foreach ($statements as $sql) {
                        $this->db->pdo()->exec($sql);
                    }
                    $this->db->insert('schema_migrations', ['version' => $version, 'applied_at' => now_utc()]);
                });
            } else {
                foreach ($statements as $sql) {
                    $this->db->pdo()->exec($sql);
                }
                $this->db->insert('schema_migrations', ['version' => $version, 'applied_at' => now_utc()]);
            }
            $applied[] = $version;
        }
        return $applied;
    }
}
