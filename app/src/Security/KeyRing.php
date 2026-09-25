<?php

declare(strict_types=1);

namespace JC\Security;

use JC\Core\Config;

/**
 * Holds the master key-encryption keys (KEKs).
 *
 * KEYRING is a comma separated list of "keyId:base64(32 random bytes)". The
 * ACTIVE_KEY_ID key encrypts all new data; older keys stay in the ring so
 * existing ciphertext can still be decrypted until it is re-encrypted with
 * `bin/jc keys:rotate`.
 *
 * Purpose-specific subkeys are derived with HKDF-SHA256 so that, for example,
 * the key protecting judicial notes is independent of the key protecting case
 * fields, and the blind-index key is never used for encryption. The Node
 * transcription service performs the identical derivation (transcription/src/crypto.js).
 */
final class KeyRing
{
    public const HKDF_SALT = 'judicial-chambers-v1';

    /** @var array<string,string> keyId => raw 32-byte key */
    private array $keys;
    private string $activeId;

    /** @var array<string,string> */
    private array $cache = [];

    /** @param array<string,string> $keys */
    public function __construct(array $keys, string $activeId)
    {
        if ($keys === []) {
            throw new CryptoException('Key ring is empty.');
        }
        foreach ($keys as $id => $key) {
            if (!preg_match('/^[A-Za-z0-9]{1,16}$/', (string) $id)) {
                throw new CryptoException('Invalid key id in key ring.');
            }
            if (strlen($key) !== 32) {
                throw new CryptoException("Key '{$id}' must be exactly 32 bytes.");
            }
        }
        if (!isset($keys[$activeId])) {
            throw new CryptoException('ACTIVE_KEY_ID is not present in the key ring.');
        }
        $this->keys = $keys;
        $this->activeId = $activeId;
    }

    public static function fromConfig(): self
    {
        $ring = Config::require('KEYRING');
        $keys = [];
        foreach (explode(',', $ring) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            [$id, $b64] = array_pad(explode(':', $entry, 2), 2, '');
            $raw = base64_decode($b64, true);
            if ($raw === false) {
                throw new CryptoException("Key '{$id}' is not valid base64.");
            }
            $keys[$id] = $raw;
        }
        return new self($keys, Config::require('ACTIVE_KEY_ID'));
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    public function activeId(): string
    {
        return $this->activeId;
    }

    public function has(string $keyId): bool
    {
        return isset($this->keys[$keyId]);
    }

    public function subkey(string $purpose, ?string $keyId = null): string
    {
        $keyId ??= $this->activeId;
        if (!isset($this->keys[$keyId])) {
            throw new CryptoException("Unknown key id '{$keyId}'.");
        }
        $cacheKey = $keyId . '|' . $purpose;
        return $this->cache[$cacheKey] ??= hash_hkdf('sha256', $this->keys[$keyId], 32, 'jc/' . $purpose, self::HKDF_SALT);
    }
}
