<?php

declare(strict_types=1);

namespace JC\Service;

use JC\Core\App;

/**
 * Private judicial notes and deliberations. Stored in their own table and
 * encrypted under the dedicated "judicial-notes" key purpose; only the judge
 * and staff explicitly granted "notes.private" can read them.
 */
final class NoteService
{
    public function __construct(private readonly App $app)
    {
    }

    public function create(int $caseId, int $authorId, string $title, string $body): int
    {
        if (trim($title) === '' || trim($body) === '') {
            throw new \InvalidArgumentException('A note needs a title and some text.');
        }
        $now = now_utc();
        return $this->app->db()->insert('judicial_notes', $this->app->cipher()->encryptRow('judicial_notes', [
            'case_id' => $caseId,
            'author_id' => $authorId,
            'title' => trim($title),
            'body' => $body,
            'created_at' => $now,
            'updated_at' => $now,
        ]));
    }

    public function update(int $noteId, string $title, string $body): void
    {
        if (trim($title) === '' || trim($body) === '') {
            throw new \InvalidArgumentException('A note needs a title and some text.');
        }
        $this->app->db()->update('judicial_notes', $this->app->cipher()->encryptRow('judicial_notes', [
            'title' => trim($title),
            'body' => $body,
        ]) + ['updated_at' => now_utc()], ['id' => $noteId]);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->app->cipher()->decryptRow('judicial_notes', $this->app->db()->fetch('SELECT * FROM judicial_notes WHERE id = ?', [$id]));
    }

    /** @return list<array<string,mixed>> */
    public function forCase(int $caseId): array
    {
        $rows = $this->app->db()->fetchAll(
            'SELECT n.*, u.full_name AS author_name FROM judicial_notes n JOIN users u ON u.id = n.author_id WHERE n.case_id = ? ORDER BY n.updated_at DESC',
            [$caseId]
        );
        return array_map(function (array $r): array {
            $r = $this->app->cipher()->decryptRow('judicial_notes', $r);
            $r['author_name'] = $this->app->crypto()->decrypt($r['author_name'], 'users.full_name');
            return $r;
        }, $rows);
    }
}
