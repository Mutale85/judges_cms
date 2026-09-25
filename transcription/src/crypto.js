/*
 * Encryption at rest — byte-for-byte compatible with the PHP application
 * (app/src/Security/{KeyRing,Crypto,FileCrypto}.php).
 *
 *  - Master keys come from KEYRING; purpose subkeys via HKDF-SHA256.
 *  - Field format:  jc1:<keyId>:<base64(nonce12 | ciphertext | tag16)>, AES-256-GCM,
 *                   AAD = "jc1|<purpose>|<keyId>|<context>".
 *  - File format "JCF1": 20-byte header, then AES-256-GCM chunks with
 *    nonce = noncePrefix(8) | u32be(index) and AAD = header | finalFlag.
 */
import crypto from 'node:crypto';
import fs from 'node:fs';

export const HKDF_SALT = 'judicial-chambers-v1';
export const MAGIC = Buffer.from('JCF1');
export const HEADER_LEN = 20;
export const TAG_LEN = 16;

export class KeyRing {
    constructor(keys, activeId) {
        if (!keys.size) throw new Error('Key ring is empty.');
        for (const [id, key] of keys) {
            if (!/^[A-Za-z0-9]{1,16}$/.test(id)) throw new Error('Invalid key id in key ring.');
            if (key.length !== 32) throw new Error(`Key '${id}' must be exactly 32 bytes.`);
        }
        if (!keys.has(activeId)) throw new Error('ACTIVE_KEY_ID is not present in the key ring.');
        this.keys = keys;
        this.activeId = activeId;
        this.cache = new Map();
    }

    static parse(ring, activeId) {
        const keys = new Map();
        for (const entry of ring.split(',').map((s) => s.trim()).filter(Boolean)) {
            const idx = entry.indexOf(':');
            keys.set(entry.slice(0, idx), Buffer.from(entry.slice(idx + 1), 'base64'));
        }
        return new KeyRing(keys, activeId);
    }

    subkey(purpose, keyId = this.activeId) {
        const master = this.keys.get(keyId);
        if (!master) throw new Error(`Unknown key id '${keyId}'.`);
        const cacheKey = `${keyId}|${purpose}`;
        if (!this.cache.has(cacheKey)) {
            this.cache.set(cacheKey, Buffer.from(crypto.hkdfSync('sha256', master, HKDF_SALT, `jc/${purpose}`, 32)));
        }
        return this.cache.get(cacheKey);
    }
}

const aad = (purpose, keyId, context) => Buffer.from(`jc1|${purpose}|${keyId}|${context}`);

export function encryptField(ring, plaintext, context, purpose = 'field') {
    const keyId = ring.activeId;
    const nonce = crypto.randomBytes(12);
    const cipher = crypto.createCipheriv('aes-256-gcm', ring.subkey(purpose, keyId), nonce, { authTagLength: TAG_LEN });
    cipher.setAAD(aad(purpose, keyId, context));
    const ct = Buffer.concat([cipher.update(Buffer.isBuffer(plaintext) ? plaintext : Buffer.from(String(plaintext))), cipher.final()]);
    return `jc1:${keyId}:${Buffer.concat([nonce, ct, cipher.getAuthTag()]).toString('base64')}`;
}

export function decryptField(ring, value, context, purpose = 'field') {
    const parts = value.split(':');
    if (parts.length !== 3 || parts[0] !== 'jc1') throw new Error(`Value for ${context} is not a recognised ciphertext.`);
    const [, keyId, b64] = parts;
    const raw = Buffer.from(b64, 'base64');
    if (raw.length < 28) throw new Error(`Malformed ciphertext for ${context}.`);
    const decipher = crypto.createDecipheriv('aes-256-gcm', ring.subkey(purpose, keyId), raw.subarray(0, 12), { authTagLength: TAG_LEN });
    decipher.setAAD(aad(purpose, keyId, context));
    decipher.setAuthTag(raw.subarray(raw.length - TAG_LEN));
    return Buffer.concat([decipher.update(raw.subarray(12, raw.length - TAG_LEN)), decipher.final()]);
}

export const wrapKey = (ring, dek, context, purpose = 'recording-kek') => encryptField(ring, dek, context, purpose);

export function unwrapKey(ring, wrapped, context, purpose = 'recording-kek') {
    const dek = decryptField(ring, wrapped, context, purpose);
    if (dek.length !== 32) throw new Error('Unwrapped data key has an invalid length.');
    return dek;
}

export function fileHeader(chunkSize, noncePrefix) {
    const header = Buffer.alloc(HEADER_LEN);
    MAGIC.copy(header, 0);
    header[4] = 1;
    header.writeUInt32BE(chunkSize, 8);
    noncePrefix.copy(header, 12);
    return header;
}

function chunkNonce(prefix, index) {
    const nonce = Buffer.alloc(12);
    prefix.copy(nonce, 0);
    nonce.writeUInt32BE(index, 8);
    return nonce;
}

export function sealChunk(plain, dek, header, prefix, index, final) {
    const cipher = crypto.createCipheriv('aes-256-gcm', dek, chunkNonce(prefix, index), { authTagLength: TAG_LEN });
    cipher.setAAD(Buffer.concat([header, Buffer.from([final ? 1 : 0])]));
    return Buffer.concat([cipher.update(plain), cipher.final(), cipher.getAuthTag()]);
}

export function openChunk(sealed, dek, header, prefix, index, final) {
    if (sealed.length < TAG_LEN) throw new Error('Encrypted file is truncated.');
    const decipher = crypto.createDecipheriv('aes-256-gcm', dek, chunkNonce(prefix, index), { authTagLength: TAG_LEN });
    decipher.setAAD(Buffer.concat([header, Buffer.from([final ? 1 : 0])]));
    decipher.setAuthTag(sealed.subarray(sealed.length - TAG_LEN));
    return Buffer.concat([decipher.update(sealed.subarray(0, sealed.length - TAG_LEN)), decipher.final()]);
}

export function readHeader(fd) {
    const header = Buffer.alloc(HEADER_LEN);
    if (fs.readSync(fd, header, 0, HEADER_LEN, 0) !== HEADER_LEN || !header.subarray(0, 4).equals(MAGIC) || header[4] !== 1) {
        throw new Error('Not a Judicial Chambers encrypted file.');
    }
    const chunkSize = header.readUInt32BE(8);
    if (chunkSize < 1024 || chunkSize > 16 * 1024 * 1024) throw new Error('Invalid chunk size in encrypted file header.');
    return { header, chunkSize, prefix: header.subarray(12, 20) };
}

/**
 * Append-only encrypted writer used while a hearing is being recorded.
 * Plaintext never touches the disk: data is buffered in memory until a whole
 * chunk is available, then sealed and written (and fsync'd). finish() writes
 * the final chunk that marks the file complete.
 */
export class EncryptedFileWriter {
    constructor(filePath, dek, { chunkSize = 65536, resume = false } = {}) {
        this.path = filePath;
        this.dek = dek;
        this.pending = [];
        this.pendingLength = 0;
        if (resume) {
            // Re-open after a service restart: keep only whole, sealed chunks.
            this.fd = fs.openSync(filePath, 'r+');
            const { header, chunkSize: cs, prefix } = readHeader(this.fd);
            this.header = header;
            this.prefix = prefix;
            this.chunkSize = cs;
            const body = fs.fstatSync(this.fd).size - HEADER_LEN;
            this.index = Math.floor(body / (cs + TAG_LEN));
            fs.ftruncateSync(this.fd, HEADER_LEN + this.index * (cs + TAG_LEN));
        } else {
            this.chunkSize = chunkSize;
            this.prefix = crypto.randomBytes(8);
            this.header = fileHeader(chunkSize, this.prefix);
            this.fd = fs.openSync(filePath, 'wx', 0o600);
            fs.writeSync(this.fd, this.header);
            this.index = 0;
        }
        this.position = HEADER_LEN + this.index * (this.chunkSize + TAG_LEN);
        this.closed = false;
    }

    /** Plaintext bytes safely sealed on disk. */
    get durableBytes() {
        return this.index * this.chunkSize;
    }

    get totalBytes() {
        return this.durableBytes + this.pendingLength;
    }

    /** Returns true if at least one chunk was sealed to disk. */
    write(data) {
        if (this.closed) throw new Error('Writer is closed.');
        this.pending.push(data);
        this.pendingLength += data.length;
        let flushed = false;
        while (this.pendingLength >= this.chunkSize) {
            const all = Buffer.concat(this.pending);
            this.writeChunk(all.subarray(0, this.chunkSize), false);
            const rest = all.subarray(this.chunkSize);
            this.pending = rest.length ? [Buffer.from(rest)] : [];
            this.pendingLength = rest.length;
            flushed = true;
        }
        if (flushed) fs.fsyncSync(this.fd);
        return flushed;
    }

    writeChunk(plain, final) {
        const sealed = sealChunk(plain, this.dek, this.header, this.prefix, this.index, final);
        fs.writeSync(this.fd, sealed, 0, sealed.length, this.position);
        this.position += sealed.length;
        this.index++;
    }

    finish() {
        if (this.closed) return;
        this.writeChunk(Buffer.concat(this.pending), true);
        this.pending = [];
        this.pendingLength = 0;
        fs.fsyncSync(this.fd);
        fs.closeSync(this.fd);
        this.closed = true;
    }

    abort() {
        if (!this.closed) {
            fs.closeSync(this.fd);
            this.closed = true;
        }
    }
}

/**
 * Yields the decrypted chunks of a JCF1 file in order, authenticating each one
 * and enforcing the final-chunk marker (truncation is detected).
 */
export function* iterateEncryptedFile(filePath, dek) {
    const fd = fs.openSync(filePath, 'r');
    try {
        const { header, chunkSize, prefix } = readHeader(fd);
        const block = chunkSize + TAG_LEN;
        const body = fs.fstatSync(fd).size - HEADER_LEN;
        const chunks = Math.ceil(body / block);
        if (chunks === 0) throw new Error('Encrypted file has no chunks.');
        const buf = Buffer.alloc(block);
        for (let i = 0; i < chunks; i++) {
            const n = fs.readSync(fd, buf, 0, block, HEADER_LEN + i * block);
            const sealed = Buffer.from(buf.subarray(0, n));
            yield { plain: openChunk(sealed, dek, header, prefix, i, i === chunks - 1), sealed, index: i };
        }
    } finally {
        fs.closeSync(fd);
    }
}

/**
 * Verifies a whole file. Returns the SHA-256 of the plaintext and a hash chain
 * over the sealed chunks (evidence of the capture sequence).
 */
export function digestEncryptedFile(filePath, dek) {
    const sha = crypto.createHash('sha256');
    let chain = Buffer.alloc(32);
    let bytes = 0;
    for (const { plain, sealed } of iterateEncryptedFile(filePath, dek)) {
        chain = crypto.createHash('sha256').update(chain).update(crypto.createHash('sha256').update(sealed).digest()).digest();
        sha.update(plain);
        bytes += plain.length;
    }
    return { sha256: sha.digest('hex'), chunkChain: chain.toString('hex'), bytes };
}
