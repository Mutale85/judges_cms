<?php

declare(strict_types=1);

namespace JC\Security;

/**
 * Streaming authenticated encryption for documents and court recordings.
 *
 * Every file gets its own random 256-bit data key (DEK) which is stored only in
 * wrapped form (see Crypto::wrapKey). The file is split into fixed-size chunks
 * that are each sealed with AES-256-GCM:
 *
 *   header  = "JCF1" | 0x01 | 000000 | chunkSize (u32 BE) | noncePrefix (8 bytes)   (20 bytes)
 *   chunk i = GCM(key=DEK, nonce=noncePrefix || u32BE(i), aad=header || finalFlag) || tag(16)
 *
 * Chunk indices in the nonce stop reordering, and the final flag stops
 * truncation: the last chunk must carry finalFlag=1 (it may be empty). Because
 * chunks are independent, byte ranges can be decrypted for audio seeking
 * without decrypting the whole file.
 *
 * The Node transcription service writes recordings in exactly this format
 * (transcription/src/crypto.js).
 */
final class FileCrypto
{
    public const MAGIC = 'JCF1';
    public const HEADER_LEN = 20;
    public const TAG_LEN = 16;
    public const DEFAULT_CHUNK = 65536;

    public static function newDataKey(): string
    {
        return random_bytes(32);
    }

    public static function header(int $chunkSize, string $noncePrefix): string
    {
        return self::MAGIC . "\x01\x00\x00\x00" . pack('N', $chunkSize) . $noncePrefix;
    }

    /**
     * Encrypts $in into $out. Returns the SHA-256 of the plaintext and its length.
     *
     * @param resource $in
     * @param resource $out
     * @return array{sha256:string,bytes:int}
     */
    public static function encryptStream($in, $out, string $dek, int $chunkSize = self::DEFAULT_CHUNK): array
    {
        $prefix = random_bytes(8);
        $header = self::header($chunkSize, $prefix);
        fwrite($out, $header);
        $hash = hash_init('sha256');
        $bytes = 0;
        $index = 0;

        $current = self::readFull($in, $chunkSize);
        while (true) {
            $next = $current === '' ? '' : self::readFull($in, $chunkSize);
            $final = $next === '' || strlen($current) < $chunkSize;
            hash_update($hash, $current);
            $bytes += strlen($current);
            fwrite($out, self::sealChunk($current, $dek, $header, $prefix, $index, $final));
            if ($final) {
                break;
            }
            $current = $next;
            $index++;
        }
        return ['sha256' => hash_final($hash), 'bytes' => $bytes];
    }

    /** @return array{sha256:string,bytes:int} */
    public static function encryptFile(string $source, string $destination, string $dek): array
    {
        $in = fopen($source, 'rb');
        $tmp = $destination . '.part';
        $out = fopen($tmp, 'xb');
        if ($in === false || $out === false) {
            throw new CryptoException('Unable to open files for encryption.');
        }
        try {
            $result = self::encryptStream($in, $out, $dek);
            fflush($out);
        } finally {
            fclose($in);
            fclose($out);
        }
        chmod($tmp, 0600);
        rename($tmp, $destination);
        return $result;
    }

    /** Plaintext length derived from the ciphertext file size. */
    public static function plaintextSize(string $path): int
    {
        [$chunkSize] = self::readHeader($path);
        $body = filesize($path) - self::HEADER_LEN;
        $block = $chunkSize + self::TAG_LEN;
        $full = intdiv($body, $block);
        $rem = $body % $block;
        return $full * $chunkSize + max($rem - self::TAG_LEN, 0);
    }

    /**
     * Decrypts bytes [$start, $end] (inclusive) of the plaintext and passes them
     * to $sink. With $allowIncomplete a file whose final chunk was never written
     * (e.g. the recorder crashed) can still be recovered.
     *
     * @param callable(string):void $sink
     */
    public static function decrypt(
        string $path,
        string $dek,
        callable $sink,
        int $start = 0,
        ?int $end = null,
        bool $allowIncomplete = false
    ): void {
        [$chunkSize, $prefix, $header] = self::readHeader($path);
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new CryptoException('Unable to open encrypted file.');
        }
        try {
            $block = $chunkSize + self::TAG_LEN;
            $body = filesize($path) - self::HEADER_LEN;
            $chunks = intdiv($body + $block - 1, $block);
            $total = self::plaintextSize($path);
            $end = $end === null ? $total - 1 : min($end, $total - 1);
            if ($total === 0) {
                // Still authenticate the (empty) final chunk.
                fseek($fh, self::HEADER_LEN);
                self::openChunk((string) fread($fh, $block), $dek, $header, $prefix, 0, true);
                return;
            }
            if ($start > $end) {
                return;
            }
            $first = intdiv($start, $chunkSize);
            $last = intdiv($end, $chunkSize);
            fseek($fh, self::HEADER_LEN + $first * $block);
            for ($i = $first; $i <= $last; $i++) {
                $sealed = (string) fread($fh, $block);
                $isLast = $i === $chunks - 1;
                try {
                    $plain = self::openChunk($sealed, $dek, $header, $prefix, $i, $isLast);
                } catch (CryptoException $e) {
                    if (!($allowIncomplete && $isLast)) {
                        throw $e;
                    }
                    $plain = self::openChunk($sealed, $dek, $header, $prefix, $i, false);
                }
                $from = $i === $first ? $start - $i * $chunkSize : 0;
                $to = $i === $last ? $end - $i * $chunkSize : strlen($plain) - 1;
                $sink(substr($plain, $from, $to - $from + 1));
            }
            // If we stopped early, make sure the file is not truncated.
            if ($last < $chunks - 1 && !$allowIncomplete) {
                fseek($fh, self::HEADER_LEN + ($chunks - 1) * $block);
                self::openChunk((string) fread($fh, $block), $dek, $header, $prefix, $chunks - 1, true);
            }
        } finally {
            fclose($fh);
        }
    }

    public static function decryptToString(string $path, string $dek): string
    {
        $buffer = '';
        self::decrypt($path, $dek, static function (string $data) use (&$buffer): void {
            $buffer .= $data;
        });
        return $buffer;
    }

    /** Authenticates every chunk and returns the plaintext SHA-256. */
    public static function verify(string $path, string $dek): string
    {
        $hash = hash_init('sha256');
        self::decrypt($path, $dek, static function (string $data) use ($hash): void {
            hash_update($hash, $data);
        });
        return hash_final($hash);
    }

    public static function sealChunk(string $plain, string $dek, string $header, string $prefix, int $index, bool $final): string
    {
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $dek, OPENSSL_RAW_DATA, $prefix . pack('N', $index), $tag, $header . ($final ? "\x01" : "\x00"), self::TAG_LEN);
        if ($ct === false) {
            throw new CryptoException('Chunk encryption failed.');
        }
        return $ct . $tag;
    }

    private static function openChunk(string $sealed, string $dek, string $header, string $prefix, int $index, bool $final): string
    {
        if (strlen($sealed) < self::TAG_LEN) {
            throw new CryptoException('Encrypted file is truncated.');
        }
        $plain = openssl_decrypt(
            substr($sealed, 0, -self::TAG_LEN),
            'aes-256-gcm',
            $dek,
            OPENSSL_RAW_DATA,
            $prefix . pack('N', $index),
            substr($sealed, -self::TAG_LEN),
            $header . ($final ? "\x01" : "\x00")
        );
        if ($plain === false) {
            throw new CryptoException("Encrypted file failed its integrity check at chunk {$index}.");
        }
        return $plain;
    }

    /** @return array{0:int,1:string,2:string} */
    private static function readHeader(string $path): array
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new CryptoException('Encrypted file not found.');
        }
        $header = (string) fread($fh, self::HEADER_LEN);
        fclose($fh);
        if (strlen($header) !== self::HEADER_LEN || !str_starts_with($header, self::MAGIC) || $header[4] !== "\x01") {
            throw new CryptoException('Not a Judicial Chambers encrypted file.');
        }
        $chunkSize = unpack('N', substr($header, 8, 4))[1];
        if ($chunkSize < 1024 || $chunkSize > 16 * 1024 * 1024) {
            throw new CryptoException('Invalid chunk size in encrypted file header.');
        }
        return [$chunkSize, substr($header, 12, 8), $header];
    }

    /** @param resource $fh */
    private static function readFull($fh, int $length): string
    {
        $data = '';
        while (strlen($data) < $length && !feof($fh)) {
            $piece = fread($fh, $length - strlen($data));
            if ($piece === false || $piece === '') {
                break;
            }
            $data .= $piece;
        }
        return $data;
    }
}
