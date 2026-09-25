<?php

declare(strict_types=1);

namespace JC\Security;

/**
 * Authenticated field-level encryption (AES-256-GCM) for data at rest.
 *
 * Ciphertext format:  jc1:<keyId>:<base64(nonce[12] || ciphertext || tag[16])>
 *
 * The additional authenticated data binds each ciphertext to its purpose, key
 * id and context (normally "table.column"), so a value cannot be copied into a
 * different column or table without decryption failing.
 *
 * The same format is implemented in transcription/src/crypto.js.
 */
final class Crypto
{
    private const VERSION = 'jc1';
    private const CIPHER = 'aes-256-gcm';

    public function __construct(private readonly KeyRing $ring)
    {
    }

    public function ring(): KeyRing
    {
        return $this->ring;
    }

    public function encrypt(?string $plaintext, string $context, string $purpose = 'field'): ?string
    {
        if ($plaintext === null) {
            return null;
        }
        $keyId = $this->ring->activeId();
        $key = $this->ring->subkey($purpose, $keyId);
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::aad($purpose, $keyId, $context),
            16
        );
        if ($ciphertext === false) {
            throw new CryptoException('Encryption failed.');
        }
        return self::VERSION . ':' . $keyId . ':' . base64_encode($nonce . $ciphertext . $tag);
    }

    public function decrypt(?string $value, string $context, string $purpose = 'field'): ?string
    {
        if ($value === null) {
            return null;
        }
        $parts = explode(':', $value, 3);
        if (count($parts) !== 3 || $parts[0] !== self::VERSION) {
            throw new CryptoException("Value for {$context} is not a recognised ciphertext.");
        }
        [, $keyId, $b64] = $parts;
        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) < 28) {
            throw new CryptoException("Malformed ciphertext for {$context}.");
        }
        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, -16);
        $ciphertext = substr($raw, 12, -16);
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->ring->subkey($purpose, $keyId),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::aad($purpose, $keyId, $context)
        );
        if ($plaintext === false) {
            throw new CryptoException("Integrity check failed while decrypting {$context}.");
        }
        return $plaintext;
    }

    public function keyIdOf(string $ciphertext): ?string
    {
        $parts = explode(':', $ciphertext, 3);
        return count($parts) === 3 ? $parts[1] : null;
    }

    public function needsRotation(?string $ciphertext): bool
    {
        return $ciphertext !== null && $this->keyIdOf($ciphertext) !== $this->ring->activeId();
    }

    /** Wraps a per-object data encryption key (DEK) under the key ring. */
    public function wrapKey(string $dek, string $context, string $purpose = 'file-kek'): string
    {
        return (string) $this->encrypt($dek, $context, $purpose);
    }

    public function unwrapKey(string $wrapped, string $context, string $purpose = 'file-kek'): string
    {
        $dek = (string) $this->decrypt($wrapped, $context, $purpose);
        if (strlen($dek) !== 32) {
            throw new CryptoException('Unwrapped data key has an invalid length.');
        }
        return $dek;
    }

    /**
     * Keyed, deterministic index that allows exact-match lookups (e.g. login by
     * e-mail, search by case number) without storing the plaintext.
     */
    public function blindIndex(string $value, string $context): string
    {
        $normalised = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
        return hash_hmac('sha256', $context . "\0" . $normalised, $this->ring->subkey('blind-index'));
    }

    /** Keyed MAC used for tamper-evidence (audit chain, certified transcripts). */
    public function mac(string $data, string $purpose, ?string $keyId = null): string
    {
        return hash_hmac('sha256', $data, $this->ring->subkey('mac/' . $purpose, $keyId));
    }

    private static function aad(string $purpose, string $keyId, string $context): string
    {
        return self::VERSION . '|' . $purpose . '|' . $keyId . '|' . $context;
    }
}
