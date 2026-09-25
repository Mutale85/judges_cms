<?php

declare(strict_types=1);

namespace JC\Security;

use JC\Core\Database;

/**
 * Append-only, hash-chained audit trail.
 *
 * Each entry stores entry_hash = HMAC(auditKey, prev_hash | fields...). Because
 * the chain is keyed, someone with write access to the database but without the
 * key ring cannot forge or re-link entries, and `bin/jc audit:verify` detects
 * any deletion, insertion or modification. Database triggers additionally
 * reject UPDATE and DELETE on audit_log.
 */
final class AuditLog
{
    public function __construct(
        private readonly Database $db,
        private readonly FieldCipher $cipher,
    ) {
    }

    /** @param array<string,mixed> $details */
    public function record(
        string $action,
        ?int $userId,
        ?int $chambersId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        array $details = [],
        ?string $ip = null,
    ): void {
        $this->db->transaction(function () use ($action, $userId, $chambersId, $entityType, $entityId, $details, $ip): void {
            $head = $this->db->fetch('SELECT last_hash, last_id FROM audit_head WHERE id = 1' . $this->db->forUpdate());
            $prev = $head['last_hash'] ?? str_repeat('0', 64);

            $row = $this->cipher->encryptRow('audit_log', [
                'occurred_at' => gmdate('Y-m-d H:i:s'),
                'user_id' => $userId,
                'chambers_id' => $chambersId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'details' => json_encode($details, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'ip_address' => $ip,
                'prev_hash' => $prev,
            ]);
            $row['mac_key_id'] = $this->cipher->crypto()->ring()->activeId();
            $row['entry_hash'] = $this->hashEntry($row);
            $id = $this->db->insert('audit_log', $row);
            $this->db->update('audit_head', ['last_hash' => $row['entry_hash'], 'last_id' => $id], ['id' => 1]);
        });
    }

    /**
     * Walks the full chain.
     *
     * @return array{ok:bool,count:int,error:?string,failed_id:?int}
     */
    public function verify(): array
    {
        $prev = str_repeat('0', 64);
        $count = 0;
        $lastId = 0;
        $stmt = $this->db->run('SELECT * FROM audit_log ORDER BY id ASC');
        while ($row = $stmt->fetch()) {
            $count++;
            if ($row['prev_hash'] !== $prev) {
                return ['ok' => false, 'count' => $count, 'error' => 'Chain broken (entry missing or re-ordered).', 'failed_id' => (int) $row['id']];
            }
            if (!hash_equals($this->hashEntry($row), (string) $row['entry_hash'])) {
                return ['ok' => false, 'count' => $count, 'error' => 'Entry contents do not match their hash.', 'failed_id' => (int) $row['id']];
            }
            $prev = (string) $row['entry_hash'];
            $lastId = (int) $row['id'];
        }
        $head = $this->db->fetch('SELECT last_hash, last_id FROM audit_head WHERE id = 1');
        if ($count > 0 && ($head === null || $head['last_hash'] !== $prev || (int) $head['last_id'] !== $lastId)) {
            return ['ok' => false, 'count' => $count, 'error' => 'Chain head does not match the latest entry (entries removed from the end?).', 'failed_id' => null];
        }
        return ['ok' => true, 'count' => $count, 'error' => null, 'failed_id' => null];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function forChambers(int $chambersId, int $limit = 200, int $offset = 0): array
    {
        $rows = $this->db->fetchAll(
            'SELECT a.*, u.full_name AS user_name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
             WHERE a.chambers_id = ? ORDER BY a.id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
            [$chambersId]
        );
        return array_map(function (array $row): array {
            $userName = $row['user_name'];
            $row = $this->cipher->decryptRow('audit_log', $row);
            $row['user_name'] = $userName !== null ? $this->cipher->crypto()->decrypt($userName, 'users.full_name') : null;
            $row['details'] = json_decode((string) $row['details'], true) ?: [];
            return $row;
        }, $rows);
    }

    /** @param array<string,mixed> $row */
    private function hashEntry(array $row): string
    {
        $fields = [
            $row['prev_hash'],
            $row['occurred_at'],
            (string) ($row['user_id'] ?? ''),
            (string) ($row['chambers_id'] ?? ''),
            $row['action'],
            (string) ($row['entity_type'] ?? ''),
            (string) ($row['entity_id'] ?? ''),
            (string) ($row['details'] ?? ''),
            (string) ($row['ip_address'] ?? ''),
        ];
        // The MAC key id is stored per entry so the chain stays verifiable after key rotation.
        return $this->cipher->crypto()->mac(implode("\x1f", $fields), 'audit-chain', (string) $row['mac_key_id']);
    }
}
