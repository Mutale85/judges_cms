import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { EncryptedFileWriter, KeyRing, decryptField, digestEncryptedFile, encryptField, iterateEncryptedFile, unwrapKey, wrapKey } from '../src/crypto.js';

const RING = `test1:${Buffer.alloc(32, 1).toString('base64')},test2:${Buffer.alloc(32, 2).toString('base64')}`;
const ring = KeyRing.parse(RING, 'test1');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'jc-node-'));
const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../app');

function php(code) {
    const bootstrap = `require '${appRoot}/bootstrap.php'; $ring = new JC\\Security\\KeyRing(['test1' => str_repeat("\\x01", 32), 'test2' => str_repeat("\\x02", 32)], 'test1'); $c = new JC\\Security\\Crypto($ring);`;
    return execFileSync('php', ['-r', bootstrap + code], { encoding: 'utf8' });
}

let phpAvailable = true;
try { execFileSync('php', ['-v']); } catch { phpAvailable = false; }

test('field encryption round-trips and binds context', () => {
    const ct = encryptField(ring, 'Kafue v Zambezi', 'cases.title');
    assert.match(ct, /^jc1:test1:/);
    assert.equal(decryptField(ring, ct, 'cases.title').toString(), 'Kafue v Zambezi');
    assert.throws(() => decryptField(ring, ct, 'cases.summary'));
    assert.throws(() => decryptField(ring, ct, 'cases.title', 'judicial-notes'));
});

test('wrapped data keys round-trip', () => {
    const dek = crypto.randomBytes(32);
    assert.deepEqual(unwrapKey(ring, wrapKey(ring, dek, 'recordings:abc'), 'recordings:abc'), dek);
});

test('streaming writer output is authenticated and complete', () => {
    const file = path.join(tmp, 'a.jcf');
    const dek = crypto.randomBytes(32);
    const w = new EncryptedFileWriter(file, dek, { chunkSize: 4096 });
    const parts = [];
    for (let i = 0; i < 25; i++) {
        const b = crypto.randomBytes(1000);
        parts.push(b);
        w.write(b);
    }
    w.finish();
    const all = Buffer.concat(parts);
    const d = digestEncryptedFile(file, dek);
    assert.equal(d.sha256, crypto.createHash('sha256').update(all).digest('hex'));
    assert.equal(d.bytes, all.length);
});

test('exact multiple of the chunk size ends with an empty final chunk', () => {
    const file = path.join(tmp, 'b.jcf');
    const dek = crypto.randomBytes(32);
    const w = new EncryptedFileWriter(file, dek, { chunkSize: 1024 });
    w.write(Buffer.alloc(2048, 7));
    w.finish();
    assert.equal(digestEncryptedFile(file, dek).bytes, 2048);
});

test('an unfinished recording is detected as incomplete', () => {
    const file = path.join(tmp, 'c.jcf');
    const dek = crypto.randomBytes(32);
    const w = new EncryptedFileWriter(file, dek, { chunkSize: 1024 });
    w.write(Buffer.alloc(5000, 1));
    w.abort();
    assert.throws(() => digestEncryptedFile(file, dek));
});

test('writer resumes after a crash keeping only sealed chunks', () => {
    const file = path.join(tmp, 'd.jcf');
    const dek = crypto.randomBytes(32);
    const w = new EncryptedFileWriter(file, dek, { chunkSize: 1024 });
    w.write(Buffer.alloc(2500, 3)); // 2 chunks sealed, 452 bytes pending (lost in the crash)
    w.abort();
    fs.appendFileSync(file, Buffer.alloc(100, 9)); // torn partial write
    const r = new EncryptedFileWriter(file, dek, { resume: true });
    assert.equal(r.durableBytes, 2048);
    r.write(Buffer.alloc(452, 3));
    r.finish();
    const out = Buffer.concat([...iterateEncryptedFile(file, dek)].map((c) => c.plain));
    assert.deepEqual(out, Buffer.alloc(2500, 3));
});

test('PHP can decrypt what Node encrypted (fields and files)', { skip: !phpAvailable }, () => {
    const field = encryptField(ring, 'Witness testimony', 'transcript_segments.text');
    assert.equal(php(`echo $c->decrypt('${field}', 'transcript_segments.text');`), 'Witness testimony');

    const file = path.join(tmp, 'node.jcf');
    const dek = crypto.randomBytes(32);
    const plain = crypto.randomBytes(150000);
    const w = new EncryptedFileWriter(file, dek, { chunkSize: 65536 });
    w.write(plain);
    w.finish();
    const wrapped = wrapKey(ring, dek, 'recordings:k');
    const hash = php(`$dek = $c->unwrapKey('${wrapped}', 'recordings:k', 'recording-kek'); echo JC\\Security\\FileCrypto::verify('${file}', $dek);`);
    assert.equal(hash, crypto.createHash('sha256').update(plain).digest('hex'));
});

test('Node can decrypt what PHP encrypted (fields and files)', { skip: !phpAvailable }, () => {
    const field = php(`echo $c->encrypt('Counsel for the plaintiff', 'transcript_segments.speaker');`);
    assert.equal(decryptField(ring, field, 'transcript_segments.speaker').toString(), 'Counsel for the plaintiff');

    const src = path.join(tmp, 'php-in.bin');
    const out = path.join(tmp, 'php.jcf');
    const plain = crypto.randomBytes(140000);
    fs.writeFileSync(src, plain);
    const wrapped = php(`$dek = JC\\Security\\FileCrypto::newDataKey(); JC\\Security\\FileCrypto::encryptFile('${src}', '${out}', $dek); echo $c->wrapKey($dek, 'recordings:p', 'recording-kek');`);
    const dek = unwrapKey(ring, wrapped, 'recordings:p');
    assert.equal(digestEncryptedFile(out, dek).sha256, crypto.createHash('sha256').update(plain).digest('hex'));
});
