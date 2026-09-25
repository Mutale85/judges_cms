<?php

declare(strict_types=1);

namespace JC\Core;

use JC\Security\Crypto;
use JC\Security\CryptoException;

/**
 * Encrypts session payloads before PHP writes them to the session store, so
 * session files on disk (or in a shared cache) never hold readable data.
 */
final class EncryptedSessionHandler extends \SessionHandler
{
    public function __construct(private readonly Crypto $crypto)
    {
    }

    public function read(string $id): string|false
    {
        $data = parent::read($id);
        if ($data === false || $data === '') {
            return $data === false ? '' : $data;
        }
        try {
            return (string) $this->crypto->decrypt($data, 'session:' . hash('sha256', $id), 'session');
        } catch (CryptoException) {
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        return parent::write($id, (string) $this->crypto->encrypt($data, 'session:' . hash('sha256', $id), 'session'));
    }
}
