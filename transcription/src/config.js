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
    languageRoutes: env('ASR_LANGUAGE_ROUTES', ''),
    whisperBin: env('WHISPER_CPP_BIN', 'whisper-cli'),
    whisperModel: env('WHISPER_CPP_MODEL', ''),
    whisperThreads: int('WHISPER_CPP_THREADS', 4),
    httpAsrUrl: env('HTTP_ASR_URL', ''),
    httpAsrModel: env('HTTP_ASR_MODEL', 'whisper-1'),
    httpAsrKey: env('HTTP_ASR_API_KEY', ''),

    // AssemblyAI (external processor — see docs/SECURITY.md before enabling)
    assemblyaiKey: env('ASSEMBLYAI_API_KEY', ''),
    assemblyaiBaseUrl: env('ASSEMBLYAI_BASE_URL', 'https://api.assemblyai.com'),
    assemblyaiStreamingUrl: env('ASSEMBLYAI_STREAMING_URL', 'wss://streaming.assemblyai.com/v3/ws'),
    assemblyaiLive: (env('ASSEMBLYAI_LIVE', 'true') || '').toLowerCase() === 'true',
    assemblyaiStreamingModel: env('ASSEMBLYAI_STREAMING_MODEL', 'universal-streaming-english'),
    assemblyaiSpeechModels: (env('ASSEMBLYAI_SPEECH_MODELS', '') || '').split(',').map((s) => s.trim()).filter(Boolean),
    assemblyaiKeyterms: (env('ASSEMBLYAI_KEYTERMS', '') || '').split(',').map((s) => s.trim()).filter(Boolean).slice(0, 100),
    assemblyaiPollMs: int('ASSEMBLYAI_POLL_MS', 3000),
    assemblyaiTimeoutMs: int('ASSEMBLYAI_TIMEOUT_MINUTES', 120) * 60 * 1000,
    externalAsrApproved: (env('EXTERNAL_ASR_APPROVED', 'false') || '').toLowerCase() === 'true',
    // After a recording is sealed, re-transcribe the whole hearing in one pass
    // (consistent speaker diarization across the hearing) to replace the live draft.
    finalPass: (env('ASR_FINAL_PASS', 'true') || '').toLowerCase() === 'true',

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
    if (config.provider === 'assemblyai' && !config.assemblyaiKey) problems.push('ASSEMBLYAI_API_KEY is required for ASR_PROVIDER=assemblyai.');
    if (isProduction()) {
        if (!config.tlsCert || !config.tlsKey) problems.push('TLS_CERT and TLS_KEY are required in production: audio must never travel unencrypted.');
        if (!config.phpBaseUrl.startsWith('https://')) problems.push('PHP_INTERNAL_URL must use https:// in production.');
        if (config.allowedOrigins.length === 0) problems.push('ALLOWED_ORIGINS must list the PHP application origin in production.');
        const usesAssembly = config.provider === 'assemblyai' || /:\s*assemblyai\b/.test(config.languageRoutes || '');
        if (usesAssembly && !config.externalAsrApproved) {
            problems.push('ASR_PROVIDER=assemblyai sends court audio to an external processor. Set EXTERNAL_ASR_APPROVED=true only after the transfer has been approved (Data Protection Act 2021 assessment, DPA with AssemblyAI).');
        }
    }
    return problems;
}
