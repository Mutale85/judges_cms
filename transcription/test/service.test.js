/*
 * Runs the real WSS server against a fake PHP endpoint and checks the capture
 * protocol: auth, durable acks, reconnect/resume, speaker labels, sealing.
 */
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import WebSocket from 'ws';
import { ServiceAuth } from '../src/auth.js';
import { digestEncryptedFile, unwrapKey } from '../src/crypto.js';
import { createContext, createServer, recoverSessions } from '../src/server.js';

const SECRET = 'x'.repeat(40);
const RING = `k1:${Buffer.alloc(32, 5).toString('base64')}`;
const auth = new ServiceAuth(SECRET);

function startFakePhp() {
    const calls = [];
    const server = http.createServer((req, res) => {
        let body = '';
        req.on('data', (c) => { body += c; });
        req.on('end', () => {
            const ok = auth.verifyRequest(req.method, new URL(req.url, 'http://x').pathname, body, req.headers);
            calls.push({ path: req.url, ok, body: body ? JSON.parse(body) : null });
            res.writeHead(ok ? 200 : 401, { 'Content-Type': 'application/json' });
            res.end('{"ok":true}');
        });
    });
    return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve({ server, calls, url: `http://127.0.0.1:${server.address().port}` })));
}

async function startService(phpUrl, storagePath) {
    const cfg = {
        appEnv: 'test', keyring: RING, activeKeyId: 'k1', serviceSecret: SECRET, phpBaseUrl: phpUrl,
        storagePath, provider: 'mock', liveWindowSeconds: 2, jobWindowSeconds: 5, asrConcurrency: 1,
        sessionGraceMinutes: 30, chunkSize: 4096, allowedOrigins: ['https://chambers.test'],
    };
    const ctx = createContext(cfg);
    await recoverSessions(ctx);
    const server = createServer(ctx);
    await new Promise((r) => server.listen(0, '127.0.0.1', r));
    return { ctx, server, url: `ws://127.0.0.1:${server.address().port}/capture` };
}

function client(url, token) {
    const ws = new WebSocket(url, { headers: { Origin: 'https://chambers.test' } });
    const inbox = [];
    const waiters = [];
    ws.on('message', (raw) => {
        const msg = JSON.parse(raw.toString());
        inbox.push(msg);
        for (const w of [...waiters]) if (w.pred(msg)) { waiters.splice(waiters.indexOf(w), 1); w.resolve(msg); }
    });
    const wait = (pred, ms = 5000) => new Promise((resolve, reject) => {
        const found = inbox.find(pred);
        if (found) return resolve(found);
        waiters.push({ pred, resolve });
        setTimeout(() => reject(new Error('timeout waiting for message')), ms);
    });
    return new Promise((resolve, reject) => {
        ws.on('open', () => { ws.send(JSON.stringify({ type: 'auth', token })); resolve({ ws, inbox, wait }); });
        ws.on('error', reject);
    });
}

function frame(seq, samples = 1600) {
    const b = Buffer.alloc(4 + samples * 2);
    b.writeUInt32BE(seq, 0);
    for (let i = 0; i < samples; i++) b.writeInt16LE((seq * 31 + i) % 20000, 4 + i * 2);
    return b;
}

const token = (rec, extra = {}) => {
    const payload = Buffer.from(JSON.stringify({ sub: 1, rec, scope: 'record', lang: 'en', sr: 16000, exp: Math.floor(Date.now() / 1000) + 300, ...extra })).toString('base64url');
    return `${payload}.${crypto.createHmac('sha256', SECRET).update(`token.${payload}`).digest('base64url')}`;
};

test('capture: auth, reconnect, speakers, sealing and integrity', async () => {
    const storage = fs.mkdtempSync(path.join(os.tmpdir(), 'jc-svc-'));
    const php = await startFakePhp();
    const svc = await startService(php.url, storage);
    try {
        // Bad token is refused.
        const bad = await client(svc.url, token(1).replace(/.$/, 'A'));
        await bad.wait((m) => m.type === 'error');

        let c = await client(svc.url, token(1));
        const ready = await c.wait((m) => m.type === 'ready');
        assert.equal(ready.next_seq, 0);
        c.ws.send(JSON.stringify({ type: 'speaker', label: 'Judge', at_ms: 0, cid: 's1' }));
        await c.wait((m) => m.type === 'ack_ctl' && m.cid === 's1');
        const frames = Array.from({ length: 60 }, (_, i) => frame(i));
        for (let i = 0; i < 30; i++) c.ws.send(frames[i]);
        await c.wait((m) => m.type === 'ack' && m.seq >= 20);
        c.ws.terminate();

        // Reconnect: the service tells us where to resume.
        c = await client(svc.url, token(1));
        const again = await c.wait((m) => m.type === 'ready');
        assert.equal(again.next_seq, 30, 'service kept frames received before the drop');
        c.ws.send(JSON.stringify({ type: 'speaker', label: 'Witness', at_ms: 3000, cid: 's2' }));
        c.ws.send(JSON.stringify({ type: 'bookmark', label: 'Exhibit P1', at_ms: 3500, cid: 'b1' }));
        for (let i = 25; i < 60; i++) c.ws.send(frames[i]); // includes duplicates 25..29
        c.ws.send(JSON.stringify({ type: 'stop', final_seq: 59 }));
        const fin = await c.wait((m) => m.type === 'finalized', 10000);

        const expected = crypto.createHash('sha256').update(Buffer.concat(frames.map((f) => f.subarray(4)))).digest('hex');
        assert.equal(fin.sha256, expected, 'sealed original equals exactly the audio sent, without duplicates');

        const finalize = php.calls.find((x) => x.path.endsWith('/finalize'));
        assert.ok(finalize.ok, 'finalize request was correctly signed');
        const dek = unwrapKey(svc.ctx.ring, finalize.body.wrapped_key, `recordings:${finalize.body.storage_key}`);
        assert.equal(digestEncryptedFile(path.join(storage, 'recordings', `${finalize.body.storage_key}.jcf`), dek).sha256, expected);
        assert.ok(php.calls.some((x) => x.path.endsWith('/started') && x.ok));
        assert.ok(php.calls.some((x) => x.path.endsWith('/bookmarks') && x.body.label === 'Exhibit P1'));

        await new Promise((r) => setTimeout(r, 500));
        const segs = php.calls.filter((x) => x.path.endsWith('/segments')).flatMap((x) => x.body.segments);
        assert.ok(segs.length > 0, 'live transcription produced segments');
        assert.equal(segs.find((s) => s.start_ms < 3000).speaker, 'Judge');
        assert.equal(segs.find((s) => s.start_ms >= 4000)?.speaker, 'Witness');

        // No plaintext audio or sidecar is left behind.
        const files = fs.readdirSync(path.join(storage, 'recordings'));
        assert.ok(files.every((f) => f.endsWith('.jcf') || f.endsWith('.speakers')), files.join(','));
        c.ws.close();
    } finally {
        await svc.server.shutdown();
        php.server.closeAllConnections();
        php.server.close();
    }
});

test('capture: service restart mid-hearing resumes the same recording', async () => {
    const storage = fs.mkdtempSync(path.join(os.tmpdir(), 'jc-svc-'));
    const php = await startFakePhp();
    let svc = await startService(php.url, storage);
    const frames = Array.from({ length: 40 }, (_, i) => frame(i, 1000));
    try {
        let c = await client(svc.url, token(7));
        await c.wait((m) => m.type === 'ready');
        let acked = -1;
        c.ws.on('message', (raw) => { const m = JSON.parse(raw); if (m.type === 'ack') acked = Math.max(acked, m.seq); });
        for (let i = 0; i < 25; i++) c.ws.send(frames[i]);
        await c.wait((m) => m.type === 'ack' && m.seq >= 15);
        await new Promise((r) => setTimeout(r, 200));
        c.ws.terminate();
        await svc.server.shutdown(); // simulated crash: sidecars stay on disk

        // "Crash" and restart: the sidecar lets the new process pick up where durable data ends.
        svc = await startService(php.url, storage);
        c = await client(svc.url, token(7));
        const ready = await c.wait((m) => m.type === 'ready');
        assert.ok(ready.next_seq <= 25 && ready.next_seq === ready.durable_seq + 1, 'resume from last durable frame');
        for (let i = ready.next_seq; i < 40; i++) c.ws.send(frames[i]);
        c.ws.send(JSON.stringify({ type: 'stop', final_seq: 39 }));
        const fin = await c.wait((m) => m.type === 'finalized', 10000);
        const expected = crypto.createHash('sha256').update(Buffer.concat(frames.map((f) => f.subarray(4)))).digest('hex');
        assert.equal(fin.sha256, expected, 'no audio lost or duplicated across the restart');
        c.ws.close();
    } finally {
        await svc.server.shutdown();
        php.server.closeAllConnections();
        php.server.close();
    }
});

test('capture: foreign origins and bad paths are refused', async () => {
    const storage = fs.mkdtempSync(path.join(os.tmpdir(), 'jc-svc-'));
    const php = await startFakePhp();
    const svc = await startService(php.url, storage);
    try {
        const ws = new WebSocket(svc.url, { headers: { Origin: 'https://evil.example' } });
        await new Promise((resolve) => ws.on('error', resolve));
        const ws2 = new WebSocket(svc.url.replace('/capture', '/other'), { headers: { Origin: 'https://chambers.test' } });
        await new Promise((resolve) => ws2.on('error', resolve));
    } finally {
        await svc.server.shutdown();
        php.server.closeAllConnections();
        php.server.close();
    }
});
