<?php

declare(strict_types=1);

use JC\Security\Crypto;
use JC\Security\CryptoException;
use JC\Security\FieldCipher;
use JC\Security\FileCrypto;
use JC\Security\KeyRing;

function ring(string $active = 'test1'): KeyRing
{
    return new KeyRing(['test1' => str_repeat("\x01", 32), 'test2' => str_repeat("\x02", 32)], $active);
}

test('field encryption round-trips and is randomised', function (): void {
    $c = new Crypto(ring());
    $a = $c->encrypt('Mwansa v Phiri', 'cases.title');
    $b = $c->encrypt('Mwansa v Phiri', 'cases.title');
    check($a !== $b, 'ciphertexts must differ (random nonce)');
    check(str_starts_with((string) $a, 'jc1:test1:'));
    same('Mwansa v Phiri', $c->decrypt($a, 'cases.title'));
    same(null, $c->encrypt(null, 'x'));
});

test('ciphertext is bound to its column and purpose', function (): void {
    $c = new Crypto(ring());
    $ct = $c->encrypt('secret', 'cases.title');
    throws(fn () => $c->decrypt($ct, 'cases.summary'), CryptoException::class, 'moving a value to another column must fail');
    throws(fn () => $c->decrypt($ct, 'cases.title', 'judicial-notes'), CryptoException::class, 'another key purpose must fail');
});

test('tampered ciphertext is rejected', function (): void {
    $c = new Crypto(ring());
    $ct = (string) $c->encrypt('secret', 'x');
    $raw = base64_decode(explode(':', $ct)[2]);
    $raw[14] = chr(ord($raw[14]) ^ 1);
    throws(fn () => $c->decrypt('jc1:test1:' . base64_encode($raw), 'x'), CryptoException::class);
});

test('key rotation: old ciphertext stays readable, new data uses the active key', function (): void {
    $old = (new Crypto(ring('test1')))->encrypt('before rotation', 'x');
    $c = new Crypto(ring('test2'));
    same('before rotation', $c->decrypt($old, 'x'));
    check($c->needsRotation($old));
    check(!$c->needsRotation($c->encrypt('after', 'x')));
});

test('key ring rejects bad keys', function (): void {
    throws(fn () => new KeyRing(['a' => 'short'], 'a'), CryptoException::class);
    throws(fn () => new KeyRing(['a' => str_repeat('x', 32)], 'b'), CryptoException::class);
});

test('blind index is deterministic, normalised and column-specific', function (): void {
    $c = new Crypto(ring());
    same($c->blindIndex('Judge@Court.ZM ', 'users.email'), $c->blindIndex('judge@court.zm', 'users.email'));
    check($c->blindIndex('x', 'users.email') !== $c->blindIndex('x', 'cases.case_number'));
});

test('field cipher encrypts registered columns only', function (): void {
    $f = new FieldCipher(new Crypto(ring()));
    $row = $f->encryptRow('cases', ['title' => 'T', 'case_type' => 'civil', 'summary' => null]);
    check(str_starts_with($row['title'], 'jc1:'));
    same('civil', $row['case_type']);
    same(null, $row['summary']);
    same('T', $f->decryptRow('cases', $row)['title']);
});

test('judicial notes use a separate key from case fields', function (): void {
    $r = ring();
    check($r->subkey('judicial-notes') !== $r->subkey('field'));
    check($r->subkey('sealed-kek') !== $r->subkey('file-kek'));
});

test('file encryption round-trips at chunk boundaries', function (string $tmp): void {
    foreach ([0, 1, 65535, 65536, 65537, 200000] as $size) {
        $plain = $size > 0 ? random_bytes($size) : '';
        file_put_contents("{$tmp}/in", $plain);
        @unlink("{$tmp}/out.jcf");
        $dek = FileCrypto::newDataKey();
        $r = FileCrypto::encryptFile("{$tmp}/in", "{$tmp}/out.jcf", $dek);
        same(hash('sha256', $plain), $r['sha256'], "hash for {$size}");
        same($size, FileCrypto::plaintextSize("{$tmp}/out.jcf"), "size for {$size}");
        same($plain, FileCrypto::decryptToString("{$tmp}/out.jcf", $dek), "content for {$size}");
    }
});

test('file range decryption returns exact bytes', function (string $tmp): void {
    $plain = random_bytes(300000);
    file_put_contents("{$tmp}/in", $plain);
    @unlink("{$tmp}/r.jcf");
    $dek = FileCrypto::newDataKey();
    FileCrypto::encryptFile("{$tmp}/in", "{$tmp}/r.jcf", $dek);
    foreach ([[0, 0], [65530, 65545], [131072, 131072], [299990, 299999], [1000, 250000]] as [$s, $e]) {
        $out = '';
        FileCrypto::decrypt("{$tmp}/r.jcf", $dek, function (string $d) use (&$out): void { $out .= $d; }, $s, $e);
        same(substr($plain, $s, $e - $s + 1), $out, "range {$s}-{$e}");
    }
});

test('file truncation, reordering and bit flips are detected', function (string $tmp): void {
    $plain = random_bytes(200000);
    file_put_contents("{$tmp}/in", $plain);
    @unlink("{$tmp}/t.jcf");
    $dek = FileCrypto::newDataKey();
    FileCrypto::encryptFile("{$tmp}/in", "{$tmp}/t.jcf", $dek);
    $good = file_get_contents("{$tmp}/t.jcf");
    $block = 65536 + 16;

    file_put_contents("{$tmp}/bad.jcf", substr($good, 0, 20 + 2 * $block));         // drop final chunk
    throws(fn () => FileCrypto::verify("{$tmp}/bad.jcf", $dek), CryptoException::class, 'truncation');

    $swapped = substr($good, 0, 20) . substr($good, 20 + $block, $block) . substr($good, 20, $block) . substr($good, 20 + 2 * $block);
    file_put_contents("{$tmp}/bad.jcf", $swapped);
    throws(fn () => FileCrypto::verify("{$tmp}/bad.jcf", $dek), CryptoException::class, 'reordering');

    $flipped = $good;
    $flipped[5000] = chr(ord($flipped[5000]) ^ 0x80);
    file_put_contents("{$tmp}/bad.jcf", $flipped);
    throws(fn () => FileCrypto::verify("{$tmp}/bad.jcf", $dek), CryptoException::class, 'bit flip');

    throws(fn () => FileCrypto::verify("{$tmp}/t.jcf", FileCrypto::newDataKey()), CryptoException::class, 'wrong key');
    same(hash('sha256', $plain), FileCrypto::verify("{$tmp}/t.jcf", $dek));
});

test('encrypted file on disk contains no plaintext', function (string $tmp): void {
    $plain = str_repeat('CONFIDENTIAL AFFIDAVIT OF THE WITNESS ', 1000);
    file_put_contents("{$tmp}/in", $plain);
    @unlink("{$tmp}/p.jcf");
    FileCrypto::encryptFile("{$tmp}/in", "{$tmp}/p.jcf", FileCrypto::newDataKey());
    check(!str_contains((string) file_get_contents("{$tmp}/p.jcf"), 'CONFIDENTIAL'));
});
