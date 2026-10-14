<?php

declare(strict_types=1);

namespace JC\Service;

use JC\Core\App;
use JC\Security\Permissions;

final class UserService
{
    public const MAX_FAILED_LOGINS = 5;
    public const LOCK_MINUTES = 15;

    public function __construct(private readonly App $app)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->app->cipher()->decryptRow('users', $this->app->db()->fetch('SELECT * FROM users WHERE id = ?', [$id]));
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $bidx = $this->app->cipher()->blindIndex('users', 'email', $email);
        return $this->app->cipher()->decryptRow('users', $this->app->db()->fetch('SELECT * FROM users WHERE email_bidx = ?', [$bidx]));
    }

    public function create(string $email, string $fullName, string $password, bool $isAdmin = false, ?string $phone = null, bool $mustChange = true, bool $emailVerified = true): int
    {
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid e-mail address is required.');
        }
        if ($this->findByEmail($email) !== null) {
            throw new \InvalidArgumentException('A user with that e-mail address already exists.');
        }
        self::assertStrongPassword($password);
        $now = now_utc();
        $row = $this->app->cipher()->encryptRow('users', [
            'email' => $email,
            'full_name' => trim($fullName),
            'phone' => $phone,
        ]);
        return $this->app->db()->insert('users', $row + [
            'email_bidx' => $this->app->cipher()->blindIndex('users', 'email', $email),
            'password_hash' => self::hash($password),
            'is_admin' => $isAdmin ? 1 : 0,
            'status' => 'active',
            'must_change_password' => $mustChange ? 1 : 0,
            'email_verified_at' => $emailVerified ? $now : null,
            'mfa_enabled' => 0,
            'failed_logins' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Verifies credentials with account lockout. Returns the user on success.
     *
     * @return array<string,mixed>|null
     */
    public function attempt(string $email, string $password): ?array
    {
        $user = $this->findByEmail($email);
        if ($user === null) {
            // Spend comparable time so response timing does not reveal valid accounts.
            static $dummy = null;
            $dummy ??= self::hash(bin2hex(random_bytes(16)));
            password_verify($password, $dummy);
            return null;
        }
        if ($user['status'] !== 'active') {
            return null;
        }
        if ($user['locked_until'] !== null && $user['locked_until'] > now_utc()) {
            throw new \RuntimeException('This account is temporarily locked after repeated failed sign-in attempts. Try again later.');
        }
        if (!password_verify($password, (string) $user['password_hash'])) {
            $failed = (int) $user['failed_logins'] + 1;
            $update = ['failed_logins' => $failed];
            if ($failed >= self::MAX_FAILED_LOGINS) {
                $update = ['failed_logins' => 0, 'locked_until' => now_utc(self::LOCK_MINUTES * 60)];
            }
            $this->app->db()->update('users', $update, ['id' => $user['id']]);
            $this->app->audit()->record('auth.login_failed', (int) $user['id'], null, 'user', (int) $user['id'], ['locked' => $failed >= self::MAX_FAILED_LOGINS], $this->app->request()?->ip());
            return null;
        }
        if (password_needs_rehash((string) $user['password_hash'], self::algorithm())) {
            $this->app->db()->update('users', ['password_hash' => self::hash($password)], ['id' => $user['id']]);
        }
        $this->app->db()->update('users', ['failed_logins' => 0, 'locked_until' => null], ['id' => $user['id']]);
        return $user;
    }

    public function recordLogin(int $userId): void
    {
        $this->app->db()->update('users', ['last_login_at' => now_utc()], ['id' => $userId]);
    }

    public function changePassword(int $userId, string $password): void
    {
        self::assertStrongPassword($password);
        $this->app->db()->update('users', [
            'password_hash' => self::hash($password),
            'must_change_password' => 0,
            'updated_at' => now_utc(),
        ], ['id' => $userId]);
    }

    public function resetPassword(int $userId, string $temporary): void
    {
        self::assertStrongPassword($temporary);
        $this->app->db()->update('users', [
            'password_hash' => self::hash($temporary),
            'must_change_password' => 1,
            'failed_logins' => 0,
            'locked_until' => null,
            'updated_at' => now_utc(),
        ], ['id' => $userId]);
    }

    public function enableMfa(int $userId, string $secret, int $step): void
    {
        $this->app->db()->update('users', $this->app->cipher()->encryptRow('users', ['mfa_secret' => $secret]) + [
            'mfa_enabled' => 1,
            'mfa_last_step' => $step,
            'updated_at' => now_utc(),
        ], ['id' => $userId]);
    }

    public function markMfaStep(int $userId, int $step): void
    {
        $this->app->db()->update('users', ['mfa_last_step' => $step], ['id' => $userId]);
    }

    public function resetMfa(int $userId): void
    {
        $this->app->db()->update('users', ['mfa_secret' => null, 'mfa_enabled' => 0, 'mfa_last_step' => null], ['id' => $userId]);
    }

    public function setStatus(int $userId, string $status): void
    {
        $this->app->db()->update('users', ['status' => $status, 'updated_at' => now_utc()], ['id' => $userId]);
    }

    // --- Chambers ------------------------------------------------------------

    public function createChambers(string $name, string $court, ?string $division, ?string $location, int $judgeUserId): int
    {
        return $this->app->db()->transaction(function () use ($name, $court, $division, $location, $judgeUserId): int {
            $id = $this->app->db()->insert('chambers', [
                'name' => $name,
                'court' => $court,
                'division' => $division,
                'location' => $location,
                'judge_user_id' => $judgeUserId,
                'created_at' => now_utc(),
            ]);
            $this->addMember($id, $judgeUserId, 'judge', null);
            return $id;
        });
    }

    /** @param list<string>|null $permissions */
    public function addMember(int $chambersId, int $userId, string $role, ?int $invitedBy, ?array $permissions = null): int
    {
        if (!isset(Permissions::ROLES[$role])) {
            throw new \InvalidArgumentException('Unknown role.');
        }
        $caps = $permissions === null ? Permissions::defaultsFor($role) : Permissions::sanitise($role, $permissions);
        $now = now_utc();
        return $this->app->db()->insert('chamber_members', [
            'chambers_id' => $chambersId,
            'user_id' => $userId,
            'role' => $role,
            'permissions' => json_encode($caps),
            'status' => 'active',
            'invited_by' => $invitedBy,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @param list<string> $permissions */
    public function updateMember(int $memberId, string $role, array $permissions, string $status): void
    {
        if (!isset(Permissions::ROLES[$role]) || $role === 'judge') {
            throw new \InvalidArgumentException('Invalid staff role.');
        }
        $this->app->db()->update('chamber_members', [
            'role' => $role,
            'permissions' => json_encode(Permissions::sanitise($role, $permissions)),
            'status' => in_array($status, ['active', 'suspended'], true) ? $status : 'suspended',
            'updated_at' => now_utc(),
        ], ['id' => $memberId]);
    }

    /** @return list<array<string,mixed>> */
    public function members(int $chambersId): array
    {
        $rows = $this->app->db()->fetchAll(
            'SELECT m.*, u.email, u.full_name, u.phone, u.status AS user_status, u.mfa_enabled, u.last_login_at
             FROM chamber_members m JOIN users u ON u.id = m.user_id WHERE m.chambers_id = ? ORDER BY m.role, m.id',
            [$chambersId]
        );
        return array_map(function (array $r): array {
            $r = $this->app->cipher()->decryptRow('users', $r);
            $r['permissions'] = json_decode((string) $r['permissions'], true) ?: [];
            return $r;
        }, $rows);
    }

    /** @return array<string,mixed>|null */
    public function member(int $chambersId, int $memberId): ?array
    {
        foreach ($this->members($chambersId) as $m) {
            if ((int) $m['id'] === $memberId) {
                return $m;
            }
        }
        return null;
    }

    public static function assertStrongPassword(string $password): void
    {
        if (mb_strlen($password) < 12) {
            throw new \InvalidArgumentException('Passwords must be at least 12 characters long.');
        }
        $classes = (int) preg_match('/[a-z]/', $password) + (int) preg_match('/[A-Z]/', $password)
            + (int) preg_match('/\d/', $password) + (int) preg_match('/[^A-Za-z0-9]/', $password);
        if ($classes < 3) {
            throw new \InvalidArgumentException('Passwords must mix at least three of: lower case, upper case, digits and symbols.');
        }
    }

    public static function temporaryPassword(): string
    {
        return 'Jc-' . substr(strtr(base64_encode(random_bytes(12)), '+/', 'Xy'), 0, 14) . '9!';
    }

    private static function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    private static function hash(string $password): string
    {
        return password_hash($password, self::algorithm());
    }
}
