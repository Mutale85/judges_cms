/*
 * Local transcription with whisper.cpp (https://github.com/ggml-org/whisper.cpp).
 *
 * The audio window is written to a private temporary file (mode 0600) in
 * SECURE_TMP_DIR — use a RAM-backed tmpfs such as /dev/shm so plaintext audio
 * never reaches persistent storage — and is overwritten and deleted as soon as
 * the engine has finished.
 */
import { spawn } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { toWav } from '../wav.js';

// whisper.cpp language codes for the configured app languages. Local
// languages need fine-tuned models; accuracy must be measured before use.
const LANG = { en: 'en', bem: 'auto', nya: 'auto', toi: 'auto', loz: 'auto' };

export class WhisperCppProvider {
    name = 'whisper-cpp';

    constructor(config) {
        if (!config.whisperModel) throw new Error('WHISPER_CPP_MODEL must point to a ggml model file.');
        this.bin = config.whisperBin;
        this.model = config.whisperModel;
        this.threads = config.whisperThreads;
        this.tmpDir = config.secureTmpDir;
    }

    async transcribe(pcm, sampleRate, language = 'en') {
        const base = path.join(this.tmpDir, `jc-${crypto.randomBytes(12).toString('hex')}`);
        const wavPath = `${base}.wav`;
        const jsonPath = `${base}.json`;
        const wav = toWav(pcm, sampleRate);
        fs.writeFileSync(wavPath, wav, { mode: 0o600, flag: 'wx' });
        try {
            await run(this.bin, [
                '-m', this.model,
                '-f', wavPath,
                '-l', LANG[language] ?? 'auto',
                '-t', String(this.threads),
                '-oj', '-of', base,
                '-np',
            ]);
            const out = JSON.parse(fs.readFileSync(jsonPath, 'utf8'));
            return (out.transcription || []).map((s) => ({
                start: (s.offsets?.from ?? 0) / 1000,
                end: (s.offsets?.to ?? 0) / 1000,
                text: String(s.text || '').trim(),
            })).filter((s) => s.text && !/^\[(BLANK_AUDIO|MUSIC|NOISE)\]$/i.test(s.text));
        } finally {
            shred(wavPath, wav.length);
            shred(jsonPath);
        }
    }
}

function run(bin, args) {
    return new Promise((resolve, reject) => {
        const child = spawn(bin, args, { stdio: ['ignore', 'ignore', 'pipe'] });
        let stderr = '';
        child.stderr.on('data', (d) => { stderr = (stderr + d).slice(-2000); });
        child.on('error', reject);
        child.on('close', (code) => (code === 0 ? resolve() : reject(new Error(`whisper.cpp exited with ${code}: ${stderr.split('\n').slice(-3).join(' ')}`))));
    });
}

/** Best-effort overwrite before unlinking temporary plaintext. */
function shred(file, size) {
    try {
        const len = size ?? fs.statSync(file).size;
        const fd = fs.openSync(file, 'r+');
        fs.writeSync(fd, Buffer.alloc(len));
        fs.fsyncSync(fd);
        fs.closeSync(fd);
    } catch { /* file may not exist */ }
    fs.rmSync(file, { force: true });
}
