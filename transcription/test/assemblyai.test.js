/*
 * AssemblyAI integration, tested against a local fake of its REST and
 * streaming APIs (same endpoints and message shapes as the official SDK).
 */
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import WebSocket, { WebSocketServer } from 'ws';
import { ServiceAuth } from '../src/auth.js';
import { AssemblyAIProvider, toSegments } from '../src/providers/assemblyai.js';
import { createContext, createServer } from '../src/server.js';
import { attributeSpeakers } from '../src/speakers.js';

const KEY = 'test-assemblyai-key';
const SECRET = 'x'.repeat(40);

function fakeAssemblyAI({ failTranscript = false } = {}) {
    const state = { uploads: [], submitted: [], deleted: [], polls: 0, streams: [] };
    const server = http.createServer((req, res) => {
        const chunks = [];
        req.on('data', (c) => chunks.push(c));
        req.on('end', () => {
            const body = Buffer.concat(chunks);
            const send = (status, obj) => { res.writeHead(status, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(obj)); };
            if (req.headers.authorization !== KEY) return send(401, { error: 'bad key' });
            if (req.method === 'POST' && req.url === '/v2/upload') {
                state.uploads.push(body);
                return send(200, { upload_url: 'https://cdn.example/upload/abc' });
            }
            if (req.method === 'POST' && req.url === '/v2/transcript') {
                state.submitted.push(JSON.parse(body.toString()));
                return send(200, { id: 'tr_1', status: 'queued' });
            }
            if (req.method === 'GET' && req.url === '/v2/transcript/tr_1') {
                state.polls++;
                if (state.polls < 2) return send(200, { id: 'tr_1', status: 'processing' });
                if (failTranscript) return send(200, { id: 'tr_1', status: 'error', error: 'audio too short' });
                return send(200, {
                    id: 'tr_1',
                    status: 'completed',
                    text: 'Counsel, you may proceed. Much obliged, My Lord.',
                    utterances: [
                        { speaker: 'A', start: 0, end: 1400, text: 'Counsel, you may proceed.', confidence: 0.95,
                            words: [{ text: 'Counsel,', start: 0, end: 500, confidence: 0.9 }, { text: 'you', start: 500, end: 700, confidence: 1 }, { text: 'may', start: 700, end: 900, confidence: 1 }, { text: 'proceed.', start: 900, end: 1400, confidence: 0.95 }] },
                        { speaker: 'B', start: 1600, end: 3000, text: 'Much obliged, My Lord.', confidence: 0.9,
                            words: [{ text: 'Much', start: 1600, end: 1900, confidence: 0.9 }, { text: 'obliged,', start: 1900, end: 2300, confidence: 0.9 }, { text: 'My', start: 2300, end: 2600, confidence: 0.9 }, { text: 'Lord.', start: 2600, end: 3000, confidence: 0.9 }] },
                    ],
                });
            }
            if (req.method === 'DELETE' && req.url === '/v2/transcript/tr_1') {
                state.deleted.push('tr_1');
                return send(200, { id: 'tr_1', status: 'completed' });
            }
            return send(404, { error: 'not found' });
        });
    });
    // Universal-Streaming v3 fake: one formatted turn per ~1 s of audio.
    const wss = new WebSocketServer({ noServer: true });
    server.on('upgrade', (req, socket, head) => {
        if (!req.url.startsWith('/v3/ws') || req.headers.authorization !== KEY) {
            socket.destroy();
            return;
        }
        wss.handleUpgrade(req, socket, head, (ws) => {
            const stream = { query: new URL(req.url, 'http://x').searchParams, bytes: 0, terminated: false };
            state.streams.push(stream);
            let turn = 0;
            let emittedUpTo = 0;
            ws.send(JSON.stringify({ type: 'Begin', id: 's1', expires_at: 0 }));
            ws.on('message', (data, isBinary) => {
                if (!isBinary) {
                    if (JSON.parse(data.toString()).type === 'Terminate') {
                        stream.terminated = true;
                        ws.send(JSON.stringify({ type: 'Termination', audio_duration_seconds: stream.bytes / 32000, session_duration_seconds: 1 }));
                        ws.close();
                    }
                    return;
                }
                stream.bytes += data.length;
                const ms = (stream.bytes / 32000) * 1000;
                if (ms - emittedUpTo >= 1000) {
                    const words = [{ text: 'Testimony', start: emittedUpTo + 100, end: emittedUpTo + 600, confidence: 0.9, word_is_final: true }];
                    ws.send(JSON.stringify({ type: 'Turn', turn_order: turn, turn_is_formatted: false, end_of_turn: false, transcript: 'testimony', end_of_turn_confidence: 0.2, words }));
                    ws.send(JSON.stringify({ type: 'Turn', turn_order: turn, turn_is_formatted: true, end_of_turn: true, transcript: `Testimony ${turn}.`, end_of_turn_confidence: 0.9, words }));
                    turn++;
                    emittedUpTo = ms;
                }
            });
        });
    });
    return new Promise((resolve) => server.listen(0, '127.0.0.1', () => {
        const port = server.address().port;
        resolve({ server, state, baseUrl: `http://127.0.0.1:${port}`, streamingUrl: `ws://127.0.0.1:${port}/v3/ws` });
    }));
}

function providerConfig(fake, extra = {}) {
    return {
        appEnv: 'test', assemblyaiKey: KEY, assemblyaiBaseUrl: fake.baseUrl, assemblyaiStreamingUrl: fake.streamingUrl,
        assemblyaiLive: true, assemblyaiStreamingModel: 'universal-streaming-english', assemblyaiSpeechModels: [],
        assemblyaiKeyterms: ['My Lord', 'affidavit'], assemblyaiPollMs: 10, assemblyaiTimeoutMs: 10000, ...extra,
    };
}

test('batch: upload, diarized transcript, and remote deletion', async () => {
    const fake = await fakeAssemblyAI();
    try {
        const p = new AssemblyAIProvider(providerConfig(fake));
        const pcm = crypto.randomBytes(32000);
        const segs = await p.transcribeStream([pcm.subarray(0, 10000), pcm.subarray(10000)], pcm.length, 16000, 'en');
        assert.equal(fake.state.uploads[0].length, 44 + pcm.length, 'WAV header + PCM streamed');
        assert.deepEqual(fake.state.uploads[0].subarray(44), pcm);
        const req = fake.state.submitted[0];
        assert.equal(req.speaker_labels, true);
        assert.equal(req.language_code, 'en');
        assert.deepEqual(req.keyterms_prompt, ['My Lord', 'affidavit']);
        assert.deepEqual(segs.map((s) => [s.diarized, s.text, s.start, s.end]), [['A', 'Counsel, you may proceed.', 0, 1.4], ['B', 'Much obliged, My Lord.', 1.6, 3]]);
        assert.deepEqual(fake.state.deleted, ['tr_1'], 'transcript deleted from AssemblyAI after retrieval');
    } finally {
        fake.server.close();
    }
});

test('batch: failures still delete the remote transcript', async () => {
    const fake = await fakeAssemblyAI({ failTranscript: true });
    try {
        const p = new AssemblyAIProvider(providerConfig(fake));
        await assert.rejects(p.transcribe(crypto.randomBytes(3200), 16000, 'en'), /audio too short/);
        assert.deepEqual(fake.state.deleted, ['tr_1']);
    } finally {
        fake.server.close();
    }
});

test('languages AssemblyAI does not support are never sent', async () => {
    const fake = await fakeAssemblyAI();
    try {
        const p = new AssemblyAIProvider(providerConfig(fake));
        for (const lang of ['bem', 'nya', 'toi', 'loz']) {
            assert.equal(p.supports(lang), false);
            assert.deepEqual(await p.transcribe(crypto.randomBytes(3200), 16000, lang), []);
        }
        assert.equal(fake.state.uploads.length, 0);
        assert.equal(p.openLiveStream({ language: 'bem', sampleRate: 16000, onTurn() {} }), null);
    } finally {
        fake.server.close();
    }
});

test('production refuses non-TLS AssemblyAI endpoints', () => {
    assert.throws(() => new AssemblyAIProvider({ ...providerConfig({ baseUrl: 'http://x', streamingUrl: 'ws://x' }), appEnv: 'production' }), /https/);
});

test('long utterances are split into replayable passages at sentence ends', () => {
    const words = [];
    for (let i = 0; i < 60; i++) words.push({ text: i % 10 === 9 ? `w${i}.` : `w${i}`, start: i * 1000, end: i * 1000 + 800, confidence: 1 });
    const segs = toSegments({ utterances: [{ speaker: 'A', words, text: '' }] });
    assert.ok(segs.length >= 3, `got ${segs.length}`);
    assert.ok(segs.every((s) => s.end - s.start <= 40));
    assert.equal(segs.map((s) => s.text).join(' ').split(' ').length, 60, 'no words lost');
});

test('diarized voices take the dominant manual label', () => {
    const timeline = [{ at_ms: 0, label: 'Judge' }, { at_ms: 10000, label: 'Witness' }];
    const out = attributeSpeakers([
        { start_ms: 0, end_ms: 4000, diarized: 'A' },
        { start_ms: 4000, end_ms: 9000, diarized: 'A' },
        { start_ms: 10000, end_ms: 15000, diarized: 'B' },
        { start_ms: 15000, end_ms: 16000, diarized: 'A' }, // judge interjects after the tap moved on
        { start_ms: 20000, end_ms: 21000, diarized: null },
        { start_ms: 22000, end_ms: 23000, diarized: 'C' },
    ], timeline);
    assert.deepEqual(out.map((s) => s.speaker), ['Judge', 'Judge', 'Witness', 'Judge', 'Witness', 'Witness']);
    assert.equal(attributeSpeakers([{ start_ms: 0, end_ms: 10, diarized: 'Z' }], []).at(0).speaker, 'Speaker Z');
});

test('service: live draft via streaming, then full-hearing pass with named speakers', async () => {
    const fake = await fakeAssemblyAI();
    const calls = [];
    const auth = new ServiceAuth(SECRET);
    const php = http.createServer((req, res) => {
        let body = '';
        req.on('data', (c) => { body += c; });
        req.on('end', () => {
            calls.push({ path: req.url, ok: auth.verifyRequest('POST', req.url, body, req.headers), body: JSON.parse(body) });
            res.writeHead(200, { 'Content-Type': 'application/json' });
            res.end('{}');
        });
    });
    await new Promise((r) => php.listen(0, '127.0.0.1', r));
    const storage = fs.mkdtempSync(path.join(os.tmpdir(), 'jc-aai-'));
    const ctx = createContext({
        ...providerConfig(fake),
        keyring: `k1:${Buffer.alloc(32, 9).toString('base64')}`, activeKeyId: 'k1', serviceSecret: SECRET,
        phpBaseUrl: `http://127.0.0.1:${php.address().port}`, storagePath: storage, provider: 'assemblyai',
        languageRoutes: 'bem:mock', finalPass: true, liveWindowSeconds: 2, jobWindowSeconds: 5, asrConcurrency: 1,
        sessionGraceMinutes: 30, chunkSize: 4096, allowedOrigins: [],
    });
    const server = createServer(ctx);
    await new Promise((r) => server.listen(0, '127.0.0.1', r));
    const token = (rec, lang) => {
        const payload = Buffer.from(JSON.stringify({ sub: 1, rec, scope: 'record', lang, sr: 16000, exp: Math.floor(Date.now() / 1000) + 300 })).toString('base64url');
        return `${payload}.${crypto.createHmac('sha256', SECRET).update(`token.${payload}`).digest('base64url')}`;
    };
    const connect = (rec, lang) => new Promise((resolve) => {
        const ws = new WebSocket(`ws://127.0.0.1:${server.address().port}/capture`);
        const inbox = [];
        ws.on('message', (raw) => inbox.push(JSON.parse(raw.toString())));
        ws.on('open', () => { ws.send(JSON.stringify({ type: 'auth', token: token(rec, lang) })); resolve({ ws, inbox }); });
    });
    const until = async (fn, ms = 8000) => {
        const end = Date.now() + ms;
        while (Date.now() < end) { if (fn()) return; await new Promise((r) => setTimeout(r, 25)); }
        throw new Error('condition not met');
    };
    try {
        const { ws, inbox } = await connect(1, 'en');
        await until(() => inbox.some((m) => m.type === 'ready'));
        ws.send(JSON.stringify({ type: 'speaker', label: 'Judge', at_ms: 0, cid: 'c1' }));
        await until(() => inbox.some((m) => m.type === 'ack_ctl'));
        for (let seq = 0; seq < 8; seq++) { // 4 s of audio in 0.5 s frames
            const f = Buffer.alloc(4 + 16000);
            f.writeUInt32BE(seq, 0);
            crypto.randomFillSync(f, 4);
            ws.send(f);
            await new Promise((r) => setTimeout(r, 20));
        }
        await until(() => inbox.some((m) => m.type === 'segment'));
        assert.ok(inbox.some((m) => m.type === 'partial'), 'partial text shown while speaking');
        const live = inbox.find((m) => m.type === 'segment').segment;
        assert.equal(live.speaker, 'Judge');
        assert.match(live.text, /^Testimony \d\.$/);
        assert.equal(fake.state.streams[0].query.get('encoding'), 'pcm_s16le');
        assert.equal(fake.state.streams[0].query.get('sample_rate'), '16000');

        ws.send(JSON.stringify({ type: 'stop', final_seq: 7 }));
        await until(() => inbox.some((m) => m.type === 'finalized'));
        assert.ok(fake.state.streams[0].terminated, 'stream terminated cleanly on stop');

        // The full-hearing pass runs after sealing and replaces the draft.
        await until(() => calls.some((c) => c.path.endsWith('/transcribed')));
        const final = calls.find((c) => c.body.replace_machine === true);
        assert.ok(final.ok, 'signed');
        assert.deepEqual(final.body.segments.map((s) => s.speaker), ['Judge', 'Judge'], 'diarized voices named from the tapped timeline');
        assert.ok(final.body.segments.every((s) => s.seq >= 5000000));
        assert.equal(fake.state.uploads[0].length, 44 + 8 * 16000, 'whole sealed hearing uploaded once');
        assert.deepEqual(fake.state.deleted, ['tr_1']);
        ws.close();

        // A Bemba hearing is routed to the local engine: nothing goes to AssemblyAI.
        const before = { uploads: fake.state.uploads.length, streams: fake.state.streams.length };
        const b = await connect(2, 'bem');
        await until(() => b.inbox.some((m) => m.type === 'ready'));
        for (let seq = 0; seq < 6; seq++) {
            const f = Buffer.alloc(4 + 16000);
            f.writeUInt32BE(seq, 0);
            b.ws.send(f);
        }
        b.ws.send(JSON.stringify({ type: 'stop', final_seq: 5 }));
        await until(() => b.inbox.some((m) => m.type === 'finalized'));
        assert.deepEqual({ uploads: fake.state.uploads.length, streams: fake.state.streams.length }, before);
        assert.ok(calls.some((c) => c.path === '/internal/recordings/2/segments'), 'local engine produced the draft');
        b.ws.close();
    } finally {
        await server.shutdown();
        php.closeAllConnections();
        php.close();
        fake.server.closeAllConnections();
        fake.server.close();
    }
});
