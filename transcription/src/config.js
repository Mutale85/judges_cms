import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

/** Loads transcription/.env (real environment variables take precedence). */
function loadDotEnv() {
    const file = path.join(root, '.env');
    if (!fs.existsSync(file)) {
        return;
    }
    for (const raw of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
        const line = raw.trim();
        if (!line || line.startsWith('#') || !line.includes('=')) {
            continue;
        }
        const idx = line.indexOf('=');
        const key = line.slice(0, idx).trim();
        let value = line.slice(idx + 1).trim();
        if (value.length >= 2 && (value[0] === '"' || value[0] === "'") && value.at(-1) === value[0]) {
            value = value.slice(1, -1);
        }
        if (process.env[key] === undefined) {
            process.env[key] = value;
        }
    }
}

loadDotEnv();

const env = (key, def = undefined) => (process.env[key] === undefined || process.env[key] === '' ? def : process.env[key]);
const int = (key, def) => (env(key) === undefined ? def : parseInt(env(key), 10));

export const config = {
    root,
    appEnv: env('APP_ENV', 'production'),
    host: env('HOST', '0.0.0.0'),
    port: int('PORT', 8443),

    // TLS for data in transit. Required in production.
    tlsCert: env('TLS_CERT'),
    tlsKey: env('TLS_KEY'),
    tlsCa: env('TLS_CA'),               // optional: require client certificates signed by this CA (mTLS)

    // Browsers allowed to open capture sockets (the PHP application's origin).
    allowedOrigins: (env('ALLOWED_ORIGINS', '') || '').split(',').map((s) => s.trim()).filter(Boolean),

    keyring: env('KEYRING', ''),
    activeKeyId: env('ACTIVE_KEY_ID', ''),
    serviceSecret: env('SERVICE_HMAC_SECRET', ''),

    // Where to report segments and finalisation (the PHP application, HTTPS).
    phpBaseUrl: (env('PHP_INTERNAL_URL', '') || '').replace(/\/$/, ''),

    storagePath: env('STORAGE_PATH', path.join(root, 'storage')),
    secureTmpDir: env('SECURE_TMP_DIR', '/dev/shm'),

    provider: env('ASR_PROVIDER', 'mock'),
    whisperBin: env('WHISPER_CPP_BIN', 'whisper-cli'),
    whisperModel: env('WHISPER_CPP_MODEL', ''),
    whisperThreads: int('WHISPER_CPP_THREADS', 4),
    httpAsrUrl: env('HTTP_ASR_URL', ''),
    httpAsrModel: env('HTTP_ASR_MODEL', 'whisper-1'),
    httpAsrKey: env('HTTP_ASR_API_KEY', ''),

    liveWindowSeconds: int('LIVE_WINDOW_SECONDS', 20),
    jobWindowSeconds: int('JOB_WINDOW_SECONDS', 30),
    asrConcurrency: int('ASR_CONCURRENCY', 1),
    sessionGraceMinutes: int('SESSION_GRACE_MINUTES', 30),
    chunkSize: int('CHUNK_SIZE', 65536),
};

export const isProduction = () => config.appEnv === 'production';

export function validateConfig() {
    const problems = [];
    if (!config.keyring || !config.activeKeyId) problems.push('KEYRING and ACTIVE_KEY_ID are required (same values as the PHP application).');
    if ((config.serviceSecret || '').length < 32) problems.push('SERVICE_HMAC_SECRET must be at least 32 characters (same value as the PHP application).');
    if (!config.phpBaseUrl) problems.push('PHP_INTERNAL_URL is required.');
    if (isProduction()) {
        if (!config.tlsCert || !config.tlsKey) problems.push('TLS_CERT and TLS_KEY are required in production: audio must never travel unencrypted.');
        if (!config.phpBaseUrl.startsWith('https://')) problems.push('PHP_INTERNAL_URL must use https:// in production.');
        if (config.allowedOrigins.length === 0) problems.push('ALLOWED_ORIGINS must list the PHP application origin in production.');
    }
    return problems;
}
