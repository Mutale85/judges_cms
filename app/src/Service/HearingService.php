<?php

declare(strict_types=1);

namespace JC\Service;

use JC\Core\App;

final class HearingService
{
    public const TYPES = [
        'mention' => 'Mention',
        'directions' => 'Directions / status conference',
        'interlocutory' => 'Interlocutory application',
        'trial' => 'Trial / hearing',
        'continued_hearing' => 'Continued hearing',
        'submissions' => 'Oral submissions',
        'ruling' => 'Delivery of ruling',
        'judgment' => 'Delivery of judgment',
        'sentencing' => 'Sentencing',
        'chambers' => 'Chambers appointment',
    ];

    public const STATUSES = [
        'proposed' => 'Proposed',
        'confirmed' => 'Confirmed',
        'in_progress' => 'In progress',
        'adjourned' => 'Adjourned',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    public function __construct(private readonly App $app)
    {
    }

    /** @param array<string,mixed> $data */
    public function propose(array $case, array $data, int $userId, bool $confirm): int
    {
        $scheduled = local_to_utc((string) ($data['scheduled_at'] ?? ''));
        if ($scheduled === null) {
            throw new \InvalidArgumentException('Choose a valid date and time.');
        }
        if (!isset(self::TYPES[$data['hearing_type'] ?? ''])) {
            throw new \InvalidArgumentException('Choose a hearing type.');
        }
        $duration = max(5, min(600, (int) ($data['duration_minutes'] ?? 60)));
        $now = now_utc();
        return $this->app->db()->insert('hearings', $this->app->cipher()->encryptRow('hearings', [
            'case_id' => (int) $case['id'],
            'chambers_id' => (int) $case['chambers_id'],
            'scheduled_at' => $scheduled,
            'duration_minutes' => $duration,
            'hearing_type' => $data['hearing_type'],
            'courtroom' => ($data['courtroom'] ?? '') !== '' ? $data['courtroom'] : null,
            'status' => $confirm ? 'confirmed' : 'proposed',
            'notes' => ($data['notes'] ?? '') !== '' ? $data['notes'] : null,
            'proposed_by' => $userId,
            'confirmed_by' => $confirm ? $userId : null,
            'confirmed_at' => $confirm ? $now : null,
            'adjourned_from_id' => $data['adjourned_from_id'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]));
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->app->cipher()->decryptRow('hearings', $this->app->db()->fetch('SELECT * FROM hearings WHERE id = ?', [$id]));
    }

    public function confirm(int $id, int $userId): void
    {
        $this->app->db()->update('hearings', ['status' => 'confirmed', 'confirmed_by' => $userId, 'confirmed_at' => now_utc(), 'updated_at' => now_utc()], ['id' => $id]);
    }

    public function setStatus(int $id, string $status, ?string $outcome = null): void
    {
        $data = ['status' => $status, 'updated_at' => now_utc()];
        if ($outcome !== null && $outcome !== '') {
            $data += $this->app->cipher()->encryptRow('hearings', ['outcome' => $outcome]);
        }
        $this->app->db()->update('hearings', $data, ['id' => $id]);
    }

    /**
     * Adjourns a hearing to a new date: the original is kept (status
     * "adjourned") and a new confirmed hearing is linked to it.
     *
     * @param array<string,mixed> $hearing
     * @param array<string,mixed> $case
     */
    public function adjourn(array $hearing, array $case, string $newLocal, string $reason, int $userId): int
    {
        return $this->app->db()->transaction(function () use ($hearing, $case, $newLocal, $reason, $userId): int {
            $this->setStatus((int) $hearing['id'], 'adjourned', $reason !== '' ? 'Adjourned: ' . $reason : 'Adjourned');
            return $this->propose($case, [
                'scheduled_at' => $newLocal,
                'hearing_type' => $hearing['hearing_type'],
                'duration_minutes' => $hearing['duration_minutes'],
                'courtroom' => $hearing['courtroom'],
                'notes' => $reason !== '' ? 'Adjourned from earlier sitting: ' . $reason : null,
                'adjourned_from_id' => (int) $hearing['id'],
            ], $userId, true);
        });
    }

    /**
     * Hearings in a UTC time window for the current chambers, limited to cases
     * the current user can access.
     *
     * @return list<array<string,mixed>>
     */
    public function between(string $fromUtc, string $toUtc, ?int $caseId = null): array
    {
        $auth = $this->app->auth();
        $sql = 'SELECT h.*, c.case_number, c.title AS case_title, c.sealed, c.chambers_id AS c_chambers, c.id AS c_id
                FROM hearings h JOIN cases c ON c.id = h.case_id
                WHERE h.chambers_id = ? AND h.scheduled_at >= ? AND h.scheduled_at < ?';
        $params = [$auth->requireChambersId(), $fromUtc, $toUtc];
        if ($caseId !== null) {
            $sql .= ' AND h.case_id = ?';
            $params[] = $caseId;
        }
        $sql .= ' ORDER BY h.scheduled_at';
        return $this->decorate($this->app->db()->fetchAll($sql, $params));
    }

    /** @return list<array<string,mixed>> */
    public function forCase(int $caseId): array
    {
        return $this->decorate($this->app->db()->fetchAll(
            'SELECT h.*, c.case_number, c.title AS case_title, c.sealed, c.chambers_id AS c_chambers, c.id AS c_id
             FROM hearings h JOIN cases c ON c.id = h.case_id WHERE h.case_id = ? ORDER BY h.scheduled_at DESC',
            [$caseId]
        ));
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function decorate(array $rows): array
    {
        $auth = $this->app->auth();
        $crypto = $this->app->crypto();
        $out = [];
        foreach ($rows as $row) {
            $case = ['id' => $row['c_id'], 'chambers_id' => $row['c_chambers'], 'sealed' => $row['sealed']];
            if (!$auth->canAccessCase($case)) {
                continue;
            }
            $row = $this->app->cipher()->decryptRow('hearings', $row);
            $row['case_number'] = $crypto->decrypt($row['case_number'], 'cases.case_number');
            $row['case_title'] = $crypto->decrypt($row['case_title'], 'cases.title');
            $out[] = $row;
        }
        return $out;
    }
}
