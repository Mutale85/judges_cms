<?php

declare(strict_types=1);

namespace JC\Service;

use JC\Core\App;
use JC\Core\HttpException;
use JC\Core\Terminology;

final class CaseService
{
    public const TYPES = [
        'civil' => 'Civil',
        'criminal' => 'Criminal',
        'constitutional' => 'Constitutional',
        'commercial' => 'Commercial',
        'appellate' => 'Appellate',
        'family' => 'Family',
        'construction' => 'Construction',
        'investment' => 'Investment',
        'employment' => 'Employment',
        'other' => 'Other',
    ];

    /** Case types offered in each workspace mode (all remain valid stored values). */
    private const MODE_TYPES = [
        'court' => ['civil', 'criminal', 'constitutional', 'commercial', 'appellate', 'family', 'other'],
        'arbitration' => ['commercial', 'construction', 'investment', 'employment', 'other'],
    ];

    /** Arbitral wording for tribunal stages. */
    private const ARBITRATION_STATUSES = [
        'registered' => 'Notice of arbitration',
        'active' => 'Pleadings',
        'hearing' => 'Evidentiary hearing',
        'judgment_reserved' => 'Award reserved',
        'stayed' => 'Suspended',
        'concluded' => 'Award rendered',
        'closed' => 'Closed',
    ];

    public const STATUSES = [
        'registered' => 'Registered',
        'active' => 'Active',
        'hearing' => 'Under hearing',
        'judgment_reserved' => 'Judgment reserved',
        'stayed' => 'Stayed',
        'concluded' => 'Concluded',
        'closed' => 'Closed',
    ];

    public const PARTY_ROLES = [
        'plaintiff' => 'Plaintiff',
        'defendant' => 'Defendant',
        'petitioner' => 'Petitioner',
        'applicant' => 'Applicant',
        'respondent' => 'Respondent',
        'appellant' => 'Appellant',
        'accused' => 'Accused',
        'prosecution' => 'Prosecution',
        'advocate' => 'Advocate',
        'witness' => 'Witness',
        'interested_party' => 'Interested party',
        'claimant' => 'Claimant',
        'counsel' => 'Counsel',
        'expert' => 'Expert',
    ];

    private const MODE_PARTY_ROLES = [
        'court' => ['plaintiff', 'defendant', 'petitioner', 'applicant', 'respondent', 'appellant', 'accused', 'prosecution', 'advocate', 'witness', 'interested_party'],
        'arbitration' => ['claimant', 'respondent', 'counsel', 'witness', 'expert', 'interested_party'],
    ];

    /** @return array<string,string> case types for the current workspace mode */
    public static function types(): array
    {
        return array_intersect_key(self::TYPES, array_flip(self::MODE_TYPES[Terminology::mode()]));
    }

    /** @return array<string,string> */
    public static function statuses(): array
    {
        return Terminology::isArbitration() ? self::ARBITRATION_STATUSES : self::STATUSES;
    }

    /** @return array<string,string> */
    public static function partyRoles(): array
    {
        return array_intersect_key(self::PARTY_ROLES, array_flip(self::MODE_PARTY_ROLES[Terminology::mode()]));
    }

    public static function typeLabel(string $type): string
    {
        return self::TYPES[$type] ?? label($type);
    }

    public static function statusLabel(string $status): string
    {
        return self::statuses()[$status] ?? label($status);
    }

    public static function partyRoleLabel(string $role): string
    {
        return self::PARTY_ROLES[$role] ?? label($role);
    }

    public function __construct(private readonly App $app)
    {
    }

    /** @param array<string,mixed> $data */
    public function create(int $chambersId, int $userId, array $data): int
    {
        $this->validate($data);
        $bidx = $this->app->cipher()->blindIndex('cases', 'case_number', (string) $data['case_number']);
        if ($this->app->db()->value('SELECT id FROM cases WHERE chambers_id = ? AND case_number_bidx = ?', [$chambersId, $bidx])) {
            throw new \InvalidArgumentException('A case with that number is already registered in these chambers.');
        }
        $now = now_utc();
        return $this->app->db()->transaction(function () use ($chambersId, $userId, $data, $bidx, $now): int {
            $id = $this->app->db()->insert('cases', $this->app->cipher()->encryptRow('cases', [
                'chambers_id' => $chambersId,
                'case_number' => trim((string) $data['case_number']),
                'case_number_bidx' => $bidx,
                'title' => trim((string) $data['title']),
                'case_type' => $data['case_type'],
                'status' => 'registered',
                'status_changed_at' => $now,
                'sealed' => !empty($data['sealed']) ? 1 : 0,
                'summary' => ($data['summary'] ?? '') !== '' ? $data['summary'] : null,
                'filed_on' => ($data['filed_on'] ?? '') !== '' ? $data['filed_on'] : null,
                'registered_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
            // Whoever registers a case is assigned to it, so staff keep access to their own work.
            $this->assign($id, $userId, $userId);
            return $id;
        });
    }

    /** @param array<string,mixed> $data */
    public function update(int $caseId, array $data): void
    {
        $this->validate($data);
        $this->app->db()->update('cases', $this->app->cipher()->encryptRow('cases', [
            'case_number' => trim((string) $data['case_number']),
            'case_number_bidx' => $this->app->cipher()->blindIndex('cases', 'case_number', (string) $data['case_number']),
            'title' => trim((string) $data['title']),
            'case_type' => $data['case_type'],
            'summary' => ($data['summary'] ?? '') !== '' ? $data['summary'] : null,
            'filed_on' => ($data['filed_on'] ?? '') !== '' ? $data['filed_on'] : null,
            'updated_at' => now_utc(),
        ]), ['id' => $caseId]);
    }

    public function setStatus(int $caseId, string $status): void
    {
        if (!isset(self::STATUSES[$status])) {
            throw new \InvalidArgumentException('Unknown status.');
        }
        $now = now_utc();
        $this->app->db()->update('cases', [
            'status' => $status,
            'status_changed_at' => $now,
            'concluded_at' => in_array($status, ['concluded', 'closed'], true) ? $now : null,
            'updated_at' => $now,
        ], ['id' => $caseId]);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->app->cipher()->decryptRow('cases', $this->app->db()->fetch('SELECT * FROM cases WHERE id = ?', [$id]));
    }

    /**
     * Loads a case and enforces the current user's access to it.
     *
     * @return array<string,mixed>
     */
    public function requireAccessible(int $id): array
    {
        $case = $this->find($id);
        if ($case === null || !$this->app->auth()->canAccessCase($case)) {
            // Same response for "missing" and "forbidden" so sealed cases are not disclosed.
            throw new HttpException(404, 'Case not found.');
        }
        return $case;
    }

    /**
     * Cases the current user may see. Encrypted columns cannot be searched in
     * SQL, so the free-text filter runs after decryption; case numbers can also
     * be matched exactly through their blind index.
     *
     * @return list<array<string,mixed>>
     */
    public function listAccessible(?string $search = null, ?string $status = null, ?string $type = null): array
    {
        $auth = $this->app->auth();
        $chambersId = $auth->requireChambersId();
        $sql = 'SELECT c.* FROM cases c WHERE c.chambers_id = ?';
        $params = [$chambersId];
        if (!$auth->isJudge()) {
            $sql .= ' AND (EXISTS (SELECT 1 FROM case_assignments a WHERE a.case_id = c.id AND a.user_id = ?)';
            $params[] = $auth->id();
            if ($auth->can('cases.all')) {
                $sql .= ' OR c.sealed = 0';
            }
            $sql .= ')';
        }
        if ($status && isset(self::STATUSES[$status])) {
            $sql .= ' AND c.status = ?';
            $params[] = $status;
        }
        if ($type && isset(self::TYPES[$type])) {
            $sql .= ' AND c.case_type = ?';
            $params[] = $type;
        }
        $sql .= ' ORDER BY c.updated_at DESC';
        $cases = $this->app->cipher()->decryptRows('cases', $this->app->db()->fetchAll($sql, $params));

        if ($search !== null && $search !== '') {
            $needle = mb_strtolower($search);
            $cases = array_values(array_filter($cases, static fn (array $c) =>
                str_contains(mb_strtolower((string) $c['case_number']), $needle)
                || str_contains(mb_strtolower((string) $c['title']), $needle)));
        }
        return $cases;
    }

    // --- Parties -------------------------------------------------------------

    /** @param array<string,mixed> $data */
    public function addParty(int $caseId, array $data): int
    {
        if (!isset(self::PARTY_ROLES[$data['party_role'] ?? '']) || trim((string) ($data['name'] ?? '')) === '') {
            throw new \InvalidArgumentException('Party role and name are required.');
        }
        return $this->app->db()->insert('case_parties', $this->app->cipher()->encryptRow('case_parties', [
            'case_id' => $caseId,
            'party_role' => $data['party_role'],
            'name' => trim((string) $data['name']),
            'representing' => ($data['representing'] ?? '') !== '' ? $data['representing'] : null,
            'contact' => ($data['contact'] ?? '') !== '' ? $data['contact'] : null,
            'created_at' => now_utc(),
        ]));
    }

    public function removeParty(int $caseId, int $partyId): void
    {
        $this->app->db()->run('DELETE FROM case_parties WHERE id = ? AND case_id = ?', [$partyId, $caseId]);
    }

    /** @return list<array<string,mixed>> */
    public function parties(int $caseId): array
    {
        return $this->app->cipher()->decryptRows('case_parties', $this->app->db()->fetchAll(
            'SELECT * FROM case_parties WHERE case_id = ? ORDER BY id',
            [$caseId]
        ));
    }

    // --- Staff assignment ----------------------------------------------------

    public function assign(int $caseId, int $userId, int $assignedBy): void
    {
        if ($this->app->db()->value('SELECT 1 FROM case_assignments WHERE case_id = ? AND user_id = ?', [$caseId, $userId])) {
            return;
        }
        $this->app->db()->insert('case_assignments', [
            'case_id' => $caseId,
            'user_id' => $userId,
            'assigned_by' => $assignedBy,
            'created_at' => now_utc(),
        ]);
    }

    public function unassign(int $caseId, int $userId): void
    {
        $this->app->db()->run('DELETE FROM case_assignments WHERE case_id = ? AND user_id = ?', [$caseId, $userId]);
    }

    /** @return list<array<string,mixed>> */
    public function assignees(int $caseId): array
    {
        $rows = $this->app->db()->fetchAll(
            'SELECT a.user_id, u.full_name, m.role FROM case_assignments a
             JOIN users u ON u.id = a.user_id
             JOIN cases c ON c.id = a.case_id
             LEFT JOIN chamber_members m ON m.user_id = a.user_id AND m.chambers_id = c.chambers_id
             WHERE a.case_id = ? ORDER BY a.id',
            [$caseId]
        );
        return array_map(fn (array $r) => $this->app->cipher()->decryptRow('users', $r), $rows);
    }

    /**
     * Seals or unseals a case. Document data keys are re-wrapped under the key
     * purpose for sealed material and the files move to the sealed store; the
     * file contents themselves do not need to be re-encrypted.
     */
    public function setSealed(int $caseId, bool $sealed): void
    {
        $this->app->db()->transaction(function () use ($caseId, $sealed): void {
            $this->app->db()->update('cases', ['sealed' => $sealed ? 1 : 0, 'updated_at' => now_utc()], ['id' => $caseId]);
            $this->app->service(DocumentService::class)->rekeyForCase($caseId, $sealed);
        });
    }

    /** @param array<string,mixed> $data */
    private function validate(array $data): void
    {
        if (trim((string) ($data['case_number'] ?? '')) === '' || trim((string) ($data['title'] ?? '')) === '') {
            throw new \InvalidArgumentException('Case number and title are required.');
        }
        if (!isset(self::TYPES[$data['case_type'] ?? ''])) {
            throw new \InvalidArgumentException('Choose a valid case type.');
        }
        $filed = (string) ($data['filed_on'] ?? '');
        if ($filed !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filed)) {
            throw new \InvalidArgumentException('Filing date must be a valid date.');
        }
    }
}
