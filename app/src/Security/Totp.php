<?php

declare(strict_types=1);

namespace JC\Security;

/**
 * RFC 6238 time-based one-time passwords (compatible with Google
 * Authenticator, Microsoft Authenticator, FreeOTP, etc.).
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function code(string $secret, ?int $time = null): string
    {
        $counter = intdiv($time ?? time(), 30);
        $hmac = hash_hmac('sha1', pack('J', $counter), self::base32Decode($secret), true);
        $offset = ord($hmac[19]) & 0x0f;
        $value = ((ord($hmac[$offset]) & 0x7f) << 24)
            | (ord($hmac[$offset + 1]) << 16)
            | (ord($hmac[$offset + 2]) << 8)
            | ord($hmac[$offset + 3]);
        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Accepts the current step and one step either side. Returns the matched
     * time step so callers can refuse to accept the same code twice.
     */
    public static function verify(string $secret, string $code, ?int $lastUsedStep = null, ?int $time = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $time ??= time();
        foreach ([-1, 0, 1] as $drift) {
            $t = $time + $drift * 30;
            $step = intdiv($t, 30);
            if ($lastUsedStep !== null && $step <= $lastUsedStep) {
                continue;
            }
            if (hash_equals(self::code($secret, $t), $code)) {
                return $step;
            }
        }
        return null;
    }

    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            rawurlencode($issuer),
            rawurlencode($account),
            $secret,
            rawurlencode($issuer)
        );
    }

    public static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $data): string
    {
        $data = strtoupper(rtrim($data, '='));
        $bits = '';
        foreach (str_split($data) as $char) {
            $pos = strpos(self::ALPHABET, $char);
            if ($pos === false) {
                throw new \InvalidArgumentException('Invalid base32 secret.');
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
