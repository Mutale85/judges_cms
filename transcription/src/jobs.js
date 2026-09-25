/*
 * Asynchronous (re-)transcription of a finalised recording. The encrypted file
 * is decrypted chunk by chunk in memory, cut into windows and fed to the speech
 * engine; results replace the previous machine draft in PHP. Passages a person
 * has corrected are preserved by the PHP side.
 */
import fs from 'node:fs';
import path from 'node:path';
import { decryptField, iterateEncryptedFile, unwrapKey } from './crypto.js';
import { log } from './log.js';

export const JOB_SEQ_BASE = 5_000_000;

export async function runTranscriptionJob(ctx, job) {
    const recId = job.recording_id;
    const sampleRate = job.sample_rate || 16000;
    if (!/^[a-f0-9]{40}$/.test(job.storage_key || '')) throw new Error('Invalid storage key.');
    const file = path.join(ctx.recordingsDir, `${job.storage_key}.jcf`);
    const dek = unwrapKey(ctx.ring, job.wrapped_key, `recordings:${job.storage_key}`);

    let speakers = [];
    const speakerFile = path.join(ctx.recordingsDir, `${job.storage_key}.speakers`);
    if (fs.existsSync(speakerFile)) {
        speakers = JSON.parse(decryptField(ctx.ring, fs.readFileSync(speakerFile, 'utf8'), `speakers:${job.storage_key}`).toString('utf8'));
    }
    const speakerAt = (ms) => {
        let label = null;
        for (const s of speakers) {
            if (s.at_ms <= ms) label = s.label;
            else break;
        }
        return label;
    };

    const windowBytes = ctx.config.jobWindowSeconds * sampleRate * 2;
    const segments = [];
    let window = [];
    let size = 0;
    let offset = 0;
    let index = 0;

    const flush = async () => {
        if (size === 0) return;
        const pcm = Buffer.concat(window);
        const startMs = Math.floor((offset / 2 / sampleRate) * 1000);
        const raw = await ctx.queue.push(() => ctx.provider.transcribe(pcm, sampleRate, job.language || 'en'));
        raw.forEach((s, i) => {
            const start = startMs + Math.round(s.start * 1000);
            const end = startMs + Math.round(s.end * 1000);
            segments.push({ seq: JOB_SEQ_BASE + index * 1000 + i, start_ms: start, end_ms: end, speaker: speakerAt((start + end) / 2), text: s.text, confidence: s.confidence });
        });
        offset += pcm.length;
        pcm.fill(0);
        window = [];
        size = 0;
        index++;
    };

    try {
        for (const { plain } of iterateEncryptedFile(file, dek)) {
            let data = plain;
            while (data.length) {
                const take = Math.min(windowBytes - size, data.length);
                window.push(data.subarray(0, take));
                size += take;
                data = data.subarray(take);
                if (size >= windowBytes) await flush();
            }
        }
        await flush();

        for (let i = 0; i < Math.max(segments.length, 1); i += 200) {
            await ctx.php.post(`/internal/recordings/${recId}/segments`, { segments: segments.slice(i, i + 200), replace_machine: i === 0 });
        }
        await ctx.php.post(`/internal/recordings/${recId}/transcribed`, { segments: segments.length });
        log.info('transcription_job_done', { rec: recId, windows: index, segments: segments.length });
    } catch (err) {
        log.error('transcription_job_failed', { rec: recId, error: err.message });
        await ctx.php.post(`/internal/recordings/${recId}/failed`, { reason: err.message }).catch(() => {});
    } finally {
        dek.fill(0);
    }
}
