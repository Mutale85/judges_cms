<?php

declare(strict_types=1);

namespace JC\Service;

use JC\Core\App;
use JC\Core\HttpException;

/**
 * Reminders and deadlines: ruling and judgment dates, filing deadlines,
 * status dates and plain reminders, with a priority and optional recurrence.
 *
 * A reminder may belong to a case; it is then visible only to people who can
 * see that case (sealed cases included). Completing a recurring reminder rolls
 * it forward to its next date instead of closing it.
 */
final class ReminderService
{
    public const KINDS = [
        'reminder' => 'Reminder',
        'deadline' => 'Deadline',
        'ruling' => 'Ruling due',
        'judgment' => 'Judgment due',
        'filing' => 'Filing / submissions',
        'status' => 'Status date',
    ];

    public const PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];

    public const RECURRENCE = ['none' => 'Does not repeat', 'daily' => 'Every day', 'weekly' => 'Every week', 'monthly' => 'Every month'];

    public function __construct(private readonly App $app)
    {
    }

    /** Kind labels in the workspace's wording ("Award due" for arbitrators). */
    public static function kinds(): array
    {
        return array_map(static fn (string $label) => str_replace(['Ruling', 'Judgment'], [term('Ruling'), term('Judgment')], $label), self::KINDS);
    }

    /** @param array<string,mixed> $data */
    public function create(int $chambersId, int $userId, array $data): int
    {
        $row = $this->validate($data);
        $now = now_utc();
        return $this->app->db()->insert('reminders', $this->app->cipher()->encryptRow('reminders', $row + [
            'chambers_id' => $chambersId,
            'status' => 'open',
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]));
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $row = $this->validate($data);
        $this->app->db()->update('reminders', $this->app->cipher()->encryptRow('reminders', $row + ['updated_at' => now_utc()]), ['id' => $id]);
    }

    /**
     * Marks a reminder done. A recurring reminder moves to its next future
     * occurrence and stays open. Returns the new due date for recurring ones.
     *
     * @param array<string,mixed> $reminder
     */
    public function complete(array $reminder, int $userId): ?string
    {
        if ($reminder['recurrence'] !== 'none') {
            $next = new \DateTimeImmutable($reminder['due_at'], new \DateTimeZone('UTC'));
            $step = match ($reminder['recurrence']) { 'daily' => '+1 day', 'weekly' => '+1 week', default => '+1 month' };
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            do {
                $next = $next->modify($step);
            } while ($next <= $now);
            $due = $next->format('Y-m-d H:i:s');
            $this->app->db()->update('reminders', ['due_at' => $due, 'completed_by' => $userId, 'completed_at' => now_utc(), 'updated_at' => now_utc()], ['id' => (int) $reminder['id']]);
            return $due;
        }
        $this->app->db()->update('reminders', ['status' => 'done', 'completed_by' => $userId, 'completed_at' => now_utc(), 'updated_at' => now_utc()], ['id' => (int) $reminder['id']]);
        return null;
    }

    public function reopen(int $id): void
    {
        $this->app->db()->update('reminders', ['status' => 'open', 'completed_by' => null, 'completed_at' => null, 'updated_at' => now_utc()], ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->app->db()->run('DELETE FROM reminders WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->app->cipher()->decryptRow('reminders', $this->app->db()->fetch('SELECT * FROM reminders WHERE id = ?', [$id]));
    }

    /**
     * Loads a reminder of the current chambers that the user may see.
     *
     * @return array<string,mixed>
     */
    public function requireAccessible(int $id): array
    {
        $reminder = $this->find($id);
        if ($reminder === null || !$this->visible($reminder)) {
            throw new HttpException(404, 'Reminder not found.');
        }
        return $reminder;
    }

    /**
     * Reminders visible to the current user, optionally limited to a UTC due
     * window, a status and a case.
     *
     * @return list<array<string,mixed>>
     */
    public function list(?string $fromUtc = null, ?string $toUtc = null, ?string $status = 'open', ?int $caseId = null): array
    {
        $chambersId = $this->app->auth()->requireChambersId();
        $sql = 'SELECT r.*, c.case_number, c.title AS case_title, c.sealed, c.chambers_id AS c_chambers
                FROM reminders r LEFT JOIN cases c ON c.id = r.case_id WHERE r.chambers_id = ?';
        $params = [$chambersId];
        if ($fromUtc !== null) {
            $sql .= ' AND r.due_at >= ?';
            $params[] = $fromUtc;
        }
        if ($toUtc !== null) {
            $sql .= ' AND r.due_at < ?';
            $params[] = $toUtc;
        }
        if ($status !== null) {
            $sql .= ' AND r.status = ?';
            $params[] = $status;
        }
        if ($caseId !== null) {
            $sql .= ' AND r.case_id = ?';
            $params[] = $caseId;
        }
        $sql .= ' ORDER BY r.due_at, r.id';
        $crypto = $this->app->crypto();
        $out = [];
        foreach ($this->app->db()->fetchAll($sql, $params) as $row) {
            if (!$this->visible($row)) {
                continue;
            }
            $row = $this->app->cipher()->decryptRow('reminders', $row);
            $row['case_number'] = $row['case_number'] !== null ? $crypto->decrypt($row['case_number'], 'cases.case_number') : null;
            $row['case_title'] = $row['case_title'] !== null ? $crypto->decrypt($row['case_title'], 'cases.title') : null;
            $row['overdue'] = $row['status'] === 'open' && $row['due_at'] < now_utc();
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Open reminders that are overdue or due within $days, for the bell.
     *
     * @return list<array<string,mixed>>
     */
    public function dueSoon(int $days = 7): array
    {
        return $this->list(null, now_utc($days * 86400), 'open');
    }

    /** @param array<string,mixed> $row */
    private function visible(array $row): bool
    {
        $auth = $this->app->auth();
        if ((int) $row['chambers_id'] !== $auth->chambersId()) {
            return false;
        }
        if ($row['case_id'] === null) {
            return true;
        }
        $sealed = $row['sealed'] ?? $this->app->db()->value('SELECT sealed FROM cases WHERE id = ?', [(int) $row['case_id']]);
        return $auth->canAccessCase(['id' => (int) $row['case_id'], 'chambers_id' => (int) $row['chambers_id'], 'sealed' => (int) $sealed]);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function validate(array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 300) {
            throw new \InvalidArgumentException('Give the reminder a short title.');
        }
        $date = (string) ($data['due_date'] ?? '');
        $time = (string) ($data['due_time'] ?? '') ?: '09:00';
        $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && preg_match('/^\d{2}:\d{2}$/', $time) ? local_to_utc("{$date}T{$time}") : null;
        if ($due === null) {
            throw new \InvalidArgumentException('Choose a valid due date.');
        }
        $caseId = !empty($data['case_id']) ? (int) $data['case_id'] : null;
        if ($caseId !== null) {
            $this->app->service(CaseService::class)->requireAccessible($caseId);
        }
        return [
            'case_id' => $caseId,
            'kind' => isset(self::KINDS[$data['kind'] ?? '']) ? $data['kind'] : 'reminder',
            'title' => $title,
            'details' => trim((string) ($data['details'] ?? '')) !== '' ? trim((string) $data['details']) : null,
            'due_at' => $due,
            'priority' => isset(self::PRIORITIES[$data['priority'] ?? '']) ? $data['priority'] : 'normal',
            'recurrence' => isset(self::RECURRENCE[$data['recurrence'] ?? '']) ? $data['recurrence'] : 'none',
        ];
    }
}
