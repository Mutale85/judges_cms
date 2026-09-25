<?php

declare(strict_types=1);

namespace JC\Security;

use JC\Core\Config;
use JC\Core\Database;

/**
 * Authentication between the PHP application and the Node transcription service.
 *
 *  - Browser -> Node: short-lived capability tokens minted by PHP
 *    (base64url(payload).base64url(HMAC-SHA256)). They name the user, recording
 *    and scope, and expire after a few minutes.
 *  - Node <-> PHP: every request is signed over method, path, timestamp, nonce
 *    and body hash. Stale timestamps and replayed nonces are rejected.
 *
 * Both channels also run over TLS; the signatures stop a compromised network
 * position or a mis-routed request from injecting transcript data.
 */
final class ServiceAuth
{
    public const MAX_SKEW = 300;

    public function __construct(private readonly string $secret)
    {
        if (strlen($secret) < 32) {
            throw new CryptoException('SERVICE_HMAC_SECRET must be at least 32 characters.');
        }
    }

    public static function fromConfig(): self
    {
        return new self(Config::require('SERVICE_HMAC_SECRET'));
    }

    /** @param array<string,mixed> $claims */
    public function issueToken(array $claims, int $ttl = 300): string
    {
        $claims['iat'] = time();
        $claims['exp'] = time() + $ttl;
        $claims['jti'] = bin2hex(random_bytes(12));
        $payload = self::b64url(json_encode($claims, JSON_THROW_ON_ERROR));
        return $payload . '.' . self::b64url(hash_hmac('sha256', 'token.' . $payload, $this->secret, true));
    }

    /** @return array<string,mixed>|null */
    public function verifyToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        $expected = self::b64url(hash_hmac('sha256', 'token.' . $parts[0], $this->secret, true));
        if (!hash_equals($expected, $parts[1])) {
            return null;
        }
        $claims = json_decode((string) self::b64urlDecode($parts[0]), true);
        if (!is_array($claims) || ($claims['exp'] ?? 0) < time()) {
            return null;
        }
        return $claims;
    }

    /** @return array<string,string> headers to attach to an outgoing request */
    public function signRequest(string $method, string $path, string $body): array
    {
        $ts = (string) time();
        $nonce = bin2hex(random_bytes(16));
        return [
            'X-JC-Timestamp' => $ts,
            'X-JC-Nonce' => $nonce,
            'X-JC-Signature' => $this->signature($method, $path, $ts, $nonce, $body),
        ];
    }

    public function verifyRequest(Database $db, string $method, string $path, string $body, ?string $ts, ?string $nonce, ?string $signature): bool
    {
        if ($ts === null || $nonce === null || $signature === null || !ctype_digit($ts) || !preg_match('/^[a-f0-9]{32}$/', $nonce)) {
            return false;
        }
        if (abs(time() - (int) $ts) > self::MAX_SKEW) {
            return false;
        }
        if (!hash_equals($this->signature($method, $path, $ts, $nonce, $body), $signature)) {
            return false;
        }
        // Replay protection: each nonce is accepted once within the skew window.
        $db->run('DELETE FROM service_nonces WHERE expires_at < ?', [gmdate('Y-m-d H:i:s')]);
        try {
            $db->insert('service_nonces', ['nonce' => $nonce, 'expires_at' => gmdate('Y-m-d H:i:s', time() + 2 * self::MAX_SKEW)]);
        } catch (\PDOException) {
            return false;
        }
        return true;
    }

    private function signature(string $method, string $path, string $ts, string $nonce, string $body): string
    {
        $canonical = strtoupper($method) . "\n" . $path . "\n" . $ts . "\n" . $nonce . "\n" . hash('sha256', $body);
        return hash_hmac('sha256', $canonical, $this->secret);
    }

    public static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $data): string|false
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);
    }
}
