/*
 * Judicial Chambers transcription service.
 *
 *   WSS  /capture   browsers stream courtroom audio (capability token from PHP)
 *   POST /jobs      PHP queues re-transcription of a finalised recording (signed)
 *   GET  /health    liveness
 *
 * In production the service only listens over TLS (HTTPS/WSS, TLS 1.2+), and
 * all callbacks to PHP go over HTTPS with signed requests.
 */
import fs from 'node:fs';
import http from 'node:http';
import https from 'node:https';
import path from 'node:path';
import { WebSocketServer } from 'ws';
import { ServiceAuth } from './auth.js';
import { config, isProduction, validateConfig } from './config.js';
import { KeyRing } from './crypto.js';
import { runTranscriptionJob } from './jobs.js';
import { log } from './log.js';
import { PhpClient } from './phpClient.js';
import { createProvider } from './providers/index.js';
import { JobQueue } from './queue.js';
import { RecordingSession } from './session.js';

export function createContext(cfg = config) {
    const auth = new ServiceAuth(cfg.serviceSecret);
    const recordingsDir = path.join(cfg.storagePath, 'recordings');
    fs.mkdirSync(recordingsDir, { recursive: true, mode: 0o700 });
    return {
        config: cfg,
        auth,
        ring: KeyRing.parse(cfg.keyring, cfg.activeKeyId),
        php: new PhpClient(cfg.phpBaseUrl, auth),
        provider: createProvider(cfg),
        queue: new JobQueue(cfg.asrConcurrency),
        sessions: new Map(),
        opening: new Map(),
        recordingsDir,
    };
}

async function sessionFor(ctx, recId, claims) {
    if (ctx.sessions.has(recId)) return ctx.sessions.get(recId);
    // Serialise concurrent opens of the same recording.
    if (!ctx.opening.has(recId)) {
        ctx.opening.set(recId, RecordingSession.open(ctx, recId, claims).then((s) => {
            ctx.sessions.set(recId, s);
            return s;
        }).finally(() => ctx.opening.delete(recId)));
    }
    return ctx.opening.get(recId);
}

function readBody(req, limit = 1024 * 1024) {
    return new Promise((resolve, reject) => {
        let size = 0;
        const chunks = [];
        req.on('data', (c) => {
            size += c.length;
            if (size > limit) {
                reject(new Error('Body too large'));
                req.destroy();
            } else {
                chunks.push(c);
            }
        });
        req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
        req.on('error', reject);
    });
}

function json(res, status, body) {
    res.writeHead(status, {
        'Content-Type': 'application/json',
        'Cache-Control': 'no-store',
        'X-Content-Type-Options': 'nosniff',
        ...(isProduction() ? { 'Strict-Transport-Security': 'max-age=63072000; includeSubDomains' } : {}),
    });
    res.end(JSON.stringify(body));
}

export function createServer(ctx) {
    const handler = async (req, res) => {
        const url = new URL(req.url, 'http://localhost');
        try {
            if (req.method === 'GET' && url.pathname === '/health') {
                return json(res, 200, { ok: true, provider: ctx.provider.name, sessions: ctx.sessions.size, queue: ctx.queue.size });
            }
            if (req.method === 'POST' && url.pathname === '/jobs') {
                // With TLS_CA set, job submission additionally requires a client certificate (mTLS).
                if (ctx.config.tlsCa && !req.socket.authorized) {
                    return json(res, 401, { error: 'Client certificate required' });
                }
                const body = await readBody(req);
                if (!ctx.auth.verifyRequest('POST', url.pathname, body, req.headers)) {
                    return json(res, 401, { error: 'Invalid signature' });
                }
                const job = JSON.parse(body);
                runTranscriptionJob(ctx, job).catch((err) => log.error('job_crashed', { error: err.message }));
                return json(res, 202, { queued: true });
            }
            return json(res, 404, { error: 'Not found' });
        } catch (err) {
            log.error('http_error', { path: url.pathname, error: err.message });
            return json(res, 500, { error: 'Internal error' });
        }
    };

    let server;
    if (ctx.config.tlsCert && ctx.config.tlsKey) {
        server = https.createServer({
            cert: fs.readFileSync(ctx.config.tlsCert),
            key: fs.readFileSync(ctx.config.tlsKey),
            ...(ctx.config.tlsCa ? { ca: fs.readFileSync(ctx.config.tlsCa), requestCert: true, rejectUnauthorized: false } : {}),
            minVersion: 'TLSv1.2',
            honorCipherOrder: true,
            ciphers: [
                'TLS_AES_256_GCM_SHA384', 'TLS_CHACHA20_POLY1305_SHA256', 'TLS_AES_128_GCM_SHA256',
                'ECDHE-ECDSA-AES256-GCM-SHA384', 'ECDHE-RSA-AES256-GCM-SHA384',
                'ECDHE-ECDSA-CHACHA20-POLY1305', 'ECDHE-RSA-CHACHA20-POLY1305',
                'ECDHE-ECDSA-AES128-GCM-SHA256', 'ECDHE-RSA-AES128-GCM-SHA256',
            ].join(':'),
        }, handler);
    } else {
        if (isProduction()) throw new Error('Refusing to start without TLS in production.');
        log.warn('tls_disabled', { note: 'Development only: audio is not encrypted in transit.' });
        server = http.createServer(handler);
    }

    const wss = new WebSocketServer({ noServer: true, maxPayload: 1024 * 1024 });

    server.on('upgrade', (req, socket, head) => {
        const url = new URL(req.url, 'http://localhost');
        const origin = req.headers.origin || '';
        if (url.pathname !== '/capture') {
            socket.destroy();
            return;
        }
        if (ctx.config.allowedOrigins.length && !ctx.config.allowedOrigins.includes(origin)) {
            log.warn('ws_origin_rejected', { origin });
            socket.write('HTTP/1.1 403 Forbidden\r\n\r\n');
            socket.destroy();
            return;
        }
        wss.handleUpgrade(req, socket, head, (ws) => wss.emit('connection', ws, req));
    });

    wss.on('connection', (ws) => {
        let session = null;
        const authTimer = setTimeout(() => ws.close(4001, 'Authentication timeout'), 10000);

        ws.on('message', async (data, isBinary) => {
            try {
                if (!session) {
                    if (isBinary) return;
                    const msg = JSON.parse(data.toString('utf8'));
                    const claims = msg.type === 'auth' ? ctx.auth.verifyToken(msg.token) : null;
                    if (!claims || claims.scope !== 'record' || !Number.isInteger(claims.rec)) {
                        ws.send(JSON.stringify({ type: 'error', message: 'Not authorised to record.', fatal: true }));
                        ws.close(4003, 'Unauthorised');
                        return;
                    }
                    clearTimeout(authTimer);
                    session = await sessionFor(ctx, claims.rec, claims);
                    session.attach(ws);
                    return;
                }
                if (isBinary) {
                    session.onFrame(data);
                    return;
                }
                const msg = JSON.parse(data.toString('utf8'));
                if (msg.type === 'stop') {
                    // Only seal once every frame the client says it sent has arrived.
                    if (Number.isInteger(msg.final_seq) && session.expectedSeq - 1 < msg.final_seq) {
                        session.resync();
                        return;
                    }
                    await session.stop('user');
                } else {
                    await session.onControl(msg);
                }
            } catch (err) {
                log.error('ws_message_failed', { rec: session?.recId, error: err.message });
                if (ws.readyState === 1) ws.send(JSON.stringify({ type: 'error', message: 'The transcription service could not process that request.' }));
            }
        });

        ws.on('close', () => {
            clearTimeout(authTimer);
            if (session) session.detach(ws);
        });
    });

    /**
     * Graceful stop: close sockets and timers but keep session sidecars, so
     * recordings in progress resume when the service comes back.
     */
    server.shutdown = () => new Promise((resolve) => {
        ctx.shuttingDown = true;
        for (const client of wss.clients) client.terminate();
        for (const session of ctx.sessions.values()) {
            clearTimeout(session.graceTimer);
            if (!session.writer.closed) session.writer.abort();
        }
        server.close(() => resolve());
        server.closeAllConnections?.();
    });

    return server;
}

/** After a restart, reload interrupted recordings so they can resume or be sealed. */
export async function recoverSessions(ctx) {
    for (const name of fs.readdirSync(ctx.recordingsDir)) {
        const m = /^rec-(\d+)\.session\.json$/.exec(name);
        if (!m) continue;
        const recId = parseInt(m[1], 10);
        try {
            const session = await sessionFor(ctx, recId, {});
            // Starts the grace timer: resumes if the courtroom reconnects, otherwise seals.
            session.detach(null);
        } catch (err) {
            log.error('recovery_failed', { rec: recId, error: err.message });
        }
    }
}

if (import.meta.url === `file://${process.argv[1]}`) {
    const problems = validateConfig();
    if (problems.length) {
        for (const p of problems) log.error('config', { problem: p });
        process.exit(1);
    }
    const ctx = createContext();
    const server = createServer(ctx);
    await recoverSessions(ctx);
    server.listen(config.port, config.host, () => {
        log.info('listening', { host: config.host, port: config.port, tls: Boolean(config.tlsCert), provider: ctx.provider.name });
    });
    for (const signal of ['SIGTERM', 'SIGINT']) {
        process.on(signal, async () => {
            log.info('shutting_down', { signal, sessions: ctx.sessions.size });
            await server.shutdown();
            process.exit(0);
        });
    }
}
