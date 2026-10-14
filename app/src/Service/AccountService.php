<?php

declare(strict_types=1);

namespace JC\Service;

use JC\Core\App;
use JC\Core\Config;

/**
 * Self-service accounts: creating a workspace, confirming the e-mail address
 * and resetting a forgotten password.
 *
 * Links carry a random 256-bit token; only its SHA-256 is stored, tokens are
 * single-use and expire. Resetting a password never bypasses the second
 * factor: the authenticator code is still required at sign-in.
 */
final class AccountService
{
    public const VERIFY_TTL = 48 * 3600;
    public const RESET_TTL = 3600;
    /** Minimum seconds between two e-mails of the same kind to one account. */
    public const RESEND_INTERVAL = 60;

    public function __construct(private readonly App $app)
    {
    }

    private function users(): UserService
    {
        return $this->app->service(UserService::class);
    }

    /**
     * Creates the owner's account and their private workspace on a free trial,
     * then sends the confirmation e-mail.
     *
     * @param array<string,mixed> $data
     * @return array{user_id:int,chambers_id:int}
     */
    public function register(array $data): array
    {
        if (!$this->app->service(SettingsService::class)->bool('signup_enabled', true)) {
            throw new \InvalidArgumentException('New sign-ups are paused at the moment. Please contact support.');
        }
        $mode = ($data['mode'] ?? 'court') === 'arbitration' ? 'arbitration' : 'court';
        $name = trim((string) ($data['full_name'] ?? ''));
        $court = trim((string) ($data['court'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        if (mb_strlen($name) < 3 || mb_strlen($name) > 150) {
            throw new \InvalidArgumentException('Enter your full name as it should appear in the workspace.');
        }
        if (mb_strlen($court) < 2 || mb_strlen($court) > 190) {
            throw new \InvalidArgumentException($mode === 'arbitration' ? 'Enter your arbitral institution or practice.' : 'Enter your court.');
        }
        if ($password !== (string) ($data['password_confirmation'] ?? '')) {
            throw new \InvalidArgumentException('The two passwords do not match.');
        }
        if (empty($data['accept_terms'])) {
            throw new \InvalidArgumentException('Please accept the Terms of Use and Privacy Policy.');
        }
        $division = trim((string) ($data['division'] ?? ''));
        $location = trim((string) ($data['location'] ?? ''));

        $ids = $this->app->db()->transaction(function () use ($email, $name, $password, $data, $mode, $court, $division, $location): array {
            $users = $this->users();
            $userId = $users->create($email, $name, $password, false, ($data['phone'] ?? '') !== '' ? (string) $data['phone'] : null, false, false);
            $chambersName = ($mode === 'arbitration' ? 'Tribunal of ' : 'Chambers of ') . $name;
            $chambersId = $users->createChambers(mb_substr($chambersName, 0, 190), $court, $division ?: null, $location ?: null, $userId);
            $this->app->db()->update('chambers', ['mode' => $mode, 'self_registered' => 1], ['id' => $chambersId]);
            $this->app->service(SubscriptionService::class)->startTrial($chambersId);
            return ['user_id' => $userId, 'chambers_id' => $chambersId];
        });
        $this->app->audit()->record('account.registered', $ids['user_id'], $ids['chambers_id'], 'user', $ids['user_id'], ['mode' => $mode], $this->app->request()?->ip());
        $this->sendVerification($ids['user_id']);
        return $ids;
    }

    public function sendVerification(int $userId, bool $force = true): bool
    {
        $user = $this->users()->find($userId);
        if ($user === null || $user['email_verified_at'] !== null) {
            return false;
        }
        if (!$force && $this->recentlySent($userId, 'verify_email')) {
            return false;
        }
        $token = $this->issue($userId, 'verify_email', self::VERIFY_TTL);
        $this->lastLink = absolute_url('/verify-email?token=' . $token);
        return $this->app->service(Mailer::class)->sendTemplate((string) $user['email'], 'Confirm your e-mail address', 'verify', [
            'name' => $user['full_name'],
            'link' => $this->lastLink,
        ]);
    }

    /** Confirms an e-mail address. Returns the user id, or null for an invalid or expired link. */
    public function verifyEmail(string $token): ?int
    {
        $userId = $this->consume($token, 'verify_email');
        if ($userId === null) {
            return null;
        }
        $this->app->db()->update('users', ['email_verified_at' => now_utc(), 'updated_at' => now_utc()], ['id' => $userId]);
        $this->app->audit()->record('account.email_verified', $userId, null, 'user', $userId, [], $this->app->request()?->ip());
        return $userId;
    }

    /**
     * Starts a password reset. Always behaves the same whether or not the
     * address has an account, so the form cannot be used to discover users.
     */
    public function requestPasswordReset(string $email): void
    {
        $user = $this->users()->findByEmail(trim($email));
        if ($user === null || $user['status'] !== 'active' || $this->recentlySent((int) $user['id'], 'reset_password')) {
            return;
        }
        $token = $this->issue((int) $user['id'], 'reset_password', self::RESET_TTL);
        $this->lastLink = absolute_url('/reset-password?token=' . $token);
        $this->app->service(Mailer::class)->sendTemplate((string) $user['email'], 'Reset your password', 'reset', [
            'name' => $user['full_name'],
            'link' => $this->lastLink,
            'minutes' => intdiv(self::RESET_TTL, 60),
        ]);
        $this->app->audit()->record('account.reset_requested', (int) $user['id'], null, 'user', (int) $user['id'], [], $this->app->request()?->ip());
    }

    public function validResetToken(string $token): bool
    {
        return $this->lookup($token, 'reset_password') !== null;
    }

    public function resetPassword(string $token, string $password, string $confirmation): int
    {
        if ($password !== $confirmation) {
            throw new \InvalidArgumentException('The two passwords do not match.');
        }
        UserService::assertStrongPassword($password);
        $userId = $this->consume($token, 'reset_password')
            ?? throw new \InvalidArgumentException('This reset link is invalid or has expired. Request a new one.');
        $this->users()->resetPassword($userId, $password);
        $this->app->db()->update('users', ['must_change_password' => 0], ['id' => $userId]);
        // Following a link from the inbox also proves the address.
        $this->app->db()->run('UPDATE users SET email_verified_at = ? WHERE id = ? AND email_verified_at IS NULL', [now_utc(), $userId]);
        $this->app->audit()->record('account.password_reset', $userId, null, 'user', $userId, [], $this->app->request()?->ip());
        return $userId;
    }

    /**
     * The most recent link generated in this process. Shown on screen only in
     * development with MAIL_DRIVER=log, so the flow can be tried without SMTP.
     */
    public ?string $lastLink = null;

    public static function showLinksOnScreen(): bool
    {
        return Config::get('APP_ENV') === 'development' && strtolower((string) Config::get('MAIL_DRIVER', 'log')) === 'log';
    }

    private function issue(int $userId, string $purpose, int $ttl): string
    {
        $token = bin2hex(random_bytes(32));
        $db = $this->app->db();
        // Only the newest link of each kind works.
        $db->run('UPDATE user_tokens SET used_at = ? WHERE user_id = ? AND purpose = ? AND used_at IS NULL', [now_utc(), $userId, $purpose]);
        $db->insert('user_tokens', [
            'user_id' => $userId,
            'purpose' => $purpose,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now_utc($ttl),
            'created_at' => now_utc(),
        ]);
        return $token;
    }

    /** @return array<string,mixed>|null */
    private function lookup(string $token, string $purpose): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        return $this->app->db()->fetch(
            'SELECT * FROM user_tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > ?',
            [hash('sha256', $token), $purpose, now_utc()]
        );
    }

    private function consume(string $token, string $purpose): ?int
    {
        $row = $this->lookup($token, $purpose);
        if ($row === null) {
            return null;
        }
        // Conditional update: two simultaneous uses of one link cannot both succeed.
        $used = $this->app->db()->run('UPDATE user_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL', [now_utc(), (int) $row['id']])->rowCount();
        return $used === 1 ? (int) $row['user_id'] : null;
    }

    private function recentlySent(int $userId, string $purpose): bool
    {
        return (bool) $this->app->db()->value(
            'SELECT 1 FROM user_tokens WHERE user_id = ? AND purpose = ? AND created_at > ?',
            [$userId, $purpose, now_utc(-self::RESEND_INTERVAL)]
        );
    }
}
