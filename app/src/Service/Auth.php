<?php

declare(strict_types=1);

namespace JC\Service;

use JC\Core\App;
use JC\Core\Config;
use JC\Core\HttpException;
use JC\Core\Terminology;
use JC\Security\Permissions;

/**
 * Who is signed in, which chambers they are working in, and what they may do.
 *
 * Sign-in has two stages: after a correct password the session holds a
 * "pending" user id; it becomes a full session only after the TOTP code is
 * verified (or, for roles that require MFA, after it is enrolled).
 */
final class Auth
{
    private ?array $user = null;
    private ?array $chambers = null;
    private ?array $member = null;
    /** @var list<string> */
    private array $caps = [];
    private bool $loaded = false;

    public function __construct(private readonly App $app)
    {
    }

    public function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;
        $session = $this->app->session();
        $userId = $session->get('user_id');
        if (!is_int($userId)) {
            return;
        }
        $user = $this->app->service(UserService::class)->find($userId);
        if ($user === null || $user['status'] !== 'active') {
            $session->destroy();
            return;
        }
        $this->user = $user;

        $memberships = $this->memberships();
        $chambersId = $session->get('chambers_id');
        $current = null;
        foreach ($memberships as $m) {
            if ($m['chambers_id'] === $chambersId) {
                $current = $m;
            }
        }
        $current ??= $memberships[0] ?? null;
        if ($current !== null) {
            $session->set('chambers_id', $current['chambers_id']);
            $this->chambers = $this->app->db()->fetch('SELECT * FROM chambers WHERE id = ?', [$current['chambers_id']]);
            Terminology::setMode($this->chambers['mode'] ?? 'court');
            $this->member = $current;
            $this->caps = $current['role'] === 'judge'
                ? Permissions::defaultsFor('judge')
                : Permissions::sanitise($current['role'], json_decode($current['permissions'], true) ?: []);
        }
    }

    /** Called after password + second factor succeed. */
    public function completeLogin(int $userId): void
    {
        $session = $this->app->session();
        $session->regenerate();
        $session->forget('pending_user_id');
        $session->forget('pending_stage');
        $session->set('user_id', $userId);
        $this->loaded = false;
        $this->load();
    }

    public function beginLogin(int $userId, string $stage): void
    {
        $session = $this->app->session();
        $session->regenerate();
        $session->set('pending_user_id', $userId);
        $session->set('pending_stage', $stage);
    }

    public function pendingUserId(): ?int
    {
        $id = $this->app->session()->get('pending_user_id');
        return is_int($id) ? $id : null;
    }

    public function pendingStage(): ?string
    {
        return $this->app->session()->get('pending_stage');
    }

    public function pendingStageUrl(): string
    {
        return $this->pendingStage() === 'mfa_setup' ? '/mfa/setup' : '/login/mfa';
    }

    public function logout(): void
    {
        $this->app->session()->destroy();
        $this->user = $this->chambers = $this->member = null;
        Terminology::setMode('court');
        $this->caps = [];
    }

    public function check(): bool
    {
        $this->load();
        return $this->user !== null;
    }

    public function id(): int
    {
        return (int) $this->requireUser()['id'];
    }

    /** @return array<string,mixed> */
    public function user(): array
    {
        return $this->requireUser();
    }

    public function isAdmin(): bool
    {
        return $this->check() && (int) $this->user['is_admin'] === 1;
    }

    public function mustChangePassword(): bool
    {
        return $this->check() && (int) $this->user['must_change_password'] === 1;
    }

    /** @return array<string,mixed>|null */
    public function chambers(): ?array
    {
        $this->load();
        return $this->chambers;
    }

    public function chambersId(): ?int
    {
        return $this->chambers() ? (int) $this->chambers['id'] : null;
    }

    public function requireChambersId(): int
    {
        return $this->chambersId() ?? throw new HttpException(403, 'You are not a member of any chambers yet.');
    }

    public function role(): ?string
    {
        $this->load();
        return $this->member['role'] ?? null;
    }

    public function isJudge(): bool
    {
        return $this->role() === 'judge';
    }

    public function can(string $capability): bool
    {
        $this->load();
        return in_array($capability, $this->caps, true);
    }

    public function authorize(string $capability): void
    {
        if (!$this->can($capability)) {
            throw new HttpException(403);
        }
    }

    /** @return list<array<string,mixed>> */
    public function memberships(): array
    {
        if ($this->user === null) {
            return [];
        }
        $rows = $this->app->db()->fetchAll(
            "SELECT m.*, c.name AS chambers_name, c.court FROM chamber_members m JOIN chambers c ON c.id = m.chambers_id
             WHERE m.user_id = ? AND m.status = 'active' ORDER BY c.name",
            [(int) $this->user['id']]
        );
        return array_map(static function (array $r): array {
            $r['chambers_id'] = (int) $r['chambers_id'];
            return $r;
        }, $rows);
    }

    public function switchChambers(int $chambersId): bool
    {
        foreach ($this->memberships() as $m) {
            if ($m['chambers_id'] === $chambersId) {
                $this->app->session()->set('chambers_id', $chambersId);
                $this->loaded = false;
                $this->load();
                return true;
            }
        }
        return false;
    }

    /**
     * Case-level access control:
     *  - the judge sees every case in their chambers;
     *  - sealed cases are visible to staff only when explicitly assigned;
     *  - other staff see assigned cases, or all unsealed cases with "cases.all".
     *
     * @param array<string,mixed> $case
     */
    public function canAccessCase(array $case): bool
    {
        if (!$this->check() || (int) $case['chambers_id'] !== $this->chambersId() || !$this->can('cases.view')) {
            return false;
        }
        if ($this->isJudge()) {
            return true;
        }
        $assigned = (bool) $this->app->db()->value(
            'SELECT 1 FROM case_assignments WHERE case_id = ? AND user_id = ?',
            [(int) $case['id'], $this->id()]
        );
        if ((int) $case['sealed'] === 1) {
            return $assigned;
        }
        return $assigned || $this->can('cases.all');
    }

    public function mfaRequired(array $user): bool
    {
        if ((int) $user['is_admin'] === 1) {
            return true;
        }
        $roles = array_filter(array_map('trim', explode(',', Config::get('MFA_REQUIRED_ROLES', 'judge,marshal,clerk,judicial_assistant,researcher,court_reporter') ?? '')));
        $userRoles = array_column($this->app->db()->fetchAll(
            "SELECT role FROM chamber_members WHERE user_id = ? AND status = 'active'",
            [(int) $user['id']]
        ), 'role');
        return array_intersect($roles, $userRoles) !== [];
    }

    /** @return array<string,mixed> */
    private function requireUser(): array
    {
        $this->load();
        return $this->user ?? throw new HttpException(403);
    }
}
