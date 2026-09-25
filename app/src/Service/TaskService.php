<?php

declare(strict_types=1);

namespace JC\Service;

use JC\Core\App;

final class TaskService
{
    public const STATUSES = ['open' => 'Open', 'in_progress' => 'In progress', 'done' => 'Done', 'cancelled' => 'Cancelled'];

    public function __construct(private readonly App $app)
    {
    }

    /** @param array<string,mixed> $data */
    public function create(int $chambersId, int $assignedBy, array $data): int
    {
        if (trim((string) ($data['title'] ?? '')) === '' || empty($data['assigned_to'])) {
            throw new \InvalidArgumentException('A task needs a title and an assignee.');
        }
        $member = $this->app->db()->value(
            "SELECT 1 FROM chamber_members WHERE chambers_id = ? AND user_id = ? AND status = 'active'",
            [$chambersId, (int) $data['assigned_to']]
        );
        if (!$member) {
            throw new \InvalidArgumentException('Tasks can only be assigned to members of these chambers.');
        }
        $due = (string) ($data['due_on'] ?? '');
        return $this->app->db()->insert('tasks', $this->app->cipher()->encryptRow('tasks', [
            'chambers_id' => $chambersId,
            'case_id' => !empty($data['case_id']) ? (int) $data['case_id'] : null,
            'title' => trim((string) $data['title']),
            'details' => ($data['details'] ?? '') !== '' ? $data['details'] : null,
            'assigned_to' => (int) $data['assigned_to'],
            'assigned_by' => $assignedBy,
            'due_on' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : null,
            'status' => 'open',
            'created_at' => now_utc(),
        ]));
    }

    public function setStatus(int $taskId, string $status): void
    {
        if (!isset(self::STATUSES[$status])) {
            throw new \InvalidArgumentException('Unknown task status.');
        }
        $this->app->db()->update('tasks', [
            'status' => $status,
            'completed_at' => $status === 'done' ? now_utc() : null,
        ], ['id' => $taskId]);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->app->cipher()->decryptRow('tasks', $this->app->db()->fetch('SELECT * FROM tasks WHERE id = ?', [$id]));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function list(int $chambersId, ?int $assignedTo = null, bool $openOnly = false, ?int $caseId = null): array
    {
        $sql = 'SELECT t.*, u.full_name AS assignee_name, b.full_name AS assigner_name, c.case_number
                FROM tasks t JOIN users u ON u.id = t.assigned_to JOIN users b ON b.id = t.assigned_by
                LEFT JOIN cases c ON c.id = t.case_id
                WHERE t.chambers_id = ?';
        $params = [$chambersId];
        if ($assignedTo !== null) {
            $sql .= ' AND t.assigned_to = ?';
            $params[] = $assignedTo;
        }
        if ($caseId !== null) {
            $sql .= ' AND t.case_id = ?';
            $params[] = $caseId;
        }
        if ($openOnly) {
            $sql .= " AND t.status IN ('open', 'in_progress')";
        }
        $sql .= ' ORDER BY CASE WHEN t.due_on IS NULL THEN 1 ELSE 0 END, t.due_on, t.id DESC';
        $crypto = $this->app->crypto();
        return array_map(function (array $r) use ($crypto): array {
            $r = $this->app->cipher()->decryptRow('tasks', $r);
            $r['assignee_name'] = $crypto->decrypt($r['assignee_name'], 'users.full_name');
            $r['assigner_name'] = $crypto->decrypt($r['assigner_name'], 'users.full_name');
            $r['case_number'] = $r['case_number'] !== null ? $crypto->decrypt($r['case_number'], 'cases.case_number') : null;
            return $r;
        }, $this->app->db()->fetchAll($sql, $params));
    }
}
