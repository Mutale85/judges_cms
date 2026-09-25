<?php

declare(strict_types=1);

namespace JC\Core;

use JC\Security\Crypto;

/**
 * Hardened PHP session.
 *
 *  - Cookie: Secure (over HTTPS), HttpOnly, SameSite=Strict, "__Host-" prefix.
 *  - Strict mode, ID regeneration on privilege change, idle and absolute timeouts.
 *  - Session files are encrypted at rest with a dedicated key purpose.
 */
final class Session
{
    private bool $started = false;

    public function __construct(private readonly Crypto $crypto)
    {
    }

    public function start(Request $request): void
    {
        if ($this->started || PHP_SAPI === 'cli') {
            $this->started = true;
            $_SESSION ??= [];
            return;
        }
        $secure = $request->isSecure();
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) (Config::int('SESSION_ABSOLUTE_HOURS', 12) * 3600));
        session_name($secure ? '__Host-jcsid' : 'jcsid');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_set_save_handler(new EncryptedSessionHandler($this->crypto), true);
        session_start();
        $this->started = true;

        $now = time();
        $idle = Config::int('SESSION_IDLE_MINUTES', 30) * 60;
        $absolute = Config::int('SESSION_ABSOLUTE_HOURS', 12) * 3600;
        $created = (int) ($_SESSION['_created'] ?? $now);
        $last = (int) ($_SESSION['_last'] ?? $now);
        if (isset($_SESSION['user_id']) && ($now - $last > $idle || $now - $created > $absolute)) {
            $this->destroy();
            session_start();
            $_SESSION['_flash'] = ['warning' => 'Your session expired. Please sign in again.'];
        }
        $_SESSION['_created'] ??= $now;
        $_SESSION['_last'] = $now;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['_created'] = time();
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][$type] = $message;
    }

    /** @return array<string,string> */
    public function takeFlash(): array
    {
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flash;
    }

    public function csrfToken(): string
    {
        return $_SESSION['_csrf'] ??= bin2hex(random_bytes(32));
    }

    public function validCsrf(?string $token): bool
    {
        return is_string($token) && isset($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $token);
    }
}
