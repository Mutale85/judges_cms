/*
 * One live courtroom recording.
 *
 * Audio frames arrive over WSS as [u32 seq][PCM16 mono]. They are appended to
 * an encrypted JCF1 file (per-recording data key, wrapped under the key ring)
 * and, in parallel, cut into windows for live speech-to-text.
 *
 * Durability: a frame is acknowledged to the browser only once every byte of
 * it is inside a sealed, fsync'd chunk. Until then the browser keeps it in its
 * encrypted outbox. A small sidecar file (holding only the *wrapped* key and
 * counters) lets the service resume the same recording after a restart.
 */
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { EncryptedFileWriter, digestEncryptedFile, encryptField, wrapKey, unwrapKey } from './crypto.js';
import { log } from './log.js';

export class RecordingSession {
    constructor(ctx, state) {
        this.ctx = ctx;
        this.recId = state.recording_id;
        this.userId = state.user_id;
        this.language = state.language;
        this.sampleRate = state.sample_rate;
        this.storageKey = state.storage_key;
        this.wrappedKey = state.wrapped_key;
        this.durableSeq = state.durable_seq;
        this.durableSeqEnd = state.durable_seq_end;
        this.speakers = state.speakers || [];
        this.windowIndex = state.window_index || 0;
        this.controlSeen = new Set(state.control_seen || []);
        this.sealed = state.sealed === true;
        this.dek = unwrapKey(ctx.ring, this.wrappedKey, `recordings:${this.storageKey}`);

        this.expectedSeq = this.durableSeq + 1;
        this.frameEnds = [];
        this.window = [];
        this.windowBytes = 0;
        this.socket = null;
        this.stopping = false;
        this.finalized = false;
        this.resyncSent = false;
        this.graceTimer = null;
        this.pendingWindows = new Set();
    }

    static sidecarPath(ctx, recId) {
        return path.join(ctx.recordingsDir, `rec-${recId}.session.json`);
    }

    /** Opens a new recording, or resumes one interrupted by a service restart. */
    static async open(ctx, recId, claims) {
        const sidecar = RecordingSession.sidecarPath(ctx, recId);
        if (fs.existsSync(sidecar)) {
            const state = JSON.parse(fs.readFileSync(sidecar, 'utf8'));
            const session = new RecordingSession(ctx, state);
            if (session.sealed) {
                // Sealed before the restart; only the report to PHP is outstanding.
                session.writer = { closed: true, totalBytes: 0, durableBytes: 0 };
                session.skipBytes = 0;
                return session;
            }
            session.writer = new EncryptedFileWriter(session.filePath, session.dek, { resume: true });
            // Bytes of the next frame that were already sealed before the restart.
            session.skipBytes = Math.max(0, session.writer.durableBytes - session.durableSeqEnd);
            log.info('recording_resumed', { rec: recId, durable_seq: session.durableSeq });
            return session;
        }
        const storageKey = crypto.randomBytes(20).toString('hex');
        const dek = crypto.randomBytes(32);
        const state = {
            recording_id: recId,
            user_id: claims.sub,
            language: claims.lang || 'en',
            sample_rate: claims.sr || 16000,
            storage_key: storageKey,
            wrapped_key: wrapKey(ctx.ring, dek, `recordings:${storageKey}`),
            durable_seq: -1,
            durable_seq_end: 0,
            speakers: [],
            window_index: 0,
            created_at: new Date().toISOString(),
        };
        dek.fill(0);
        const session = new RecordingSession(ctx, state);
        session.writer = new EncryptedFileWriter(session.filePath, session.dek, { chunkSize: ctx.config.chunkSize });
        session.skipBytes = 0;
        session.persist();
        await ctx.php.post(`/internal/recordings/${recId}/started`, { user_id: claims.sub, storage_key: storageKey });
        log.info('recording_started', { rec: recId });
        return session;
    }

    get filePath() {
        return path.join(this.ctx.recordingsDir, `${this.storageKey}.jcf`);
    }

    persist() {
        const state = {
            recording_id: this.recId,
            user_id: this.userId,
            language: this.language,
            sample_rate: this.sampleRate,
            storage_key: this.storageKey,
            wrapped_key: this.wrappedKey,
            durable_seq: this.durableSeq,
            durable_seq_end: this.durableSeqEnd,
            speakers: this.speakers,
            window_index: this.windowIndex,
            control_seen: [...this.controlSeen].slice(-500),
            sealed: this.sealed,
        };
        const file = RecordingSession.sidecarPath(this.ctx, this.recId);
        fs.writeFileSync(`${file}.tmp`, JSON.stringify(state), { mode: 0o600 });
        fs.renameSync(`${file}.tmp`, file);
    }

    attach(socket) {
        if (this.socket && this.socket !== socket) {
            this.socket.close(4000, 'Superseded by a newer connection');
        }
        this.socket = socket;
        clearTimeout(this.graceTimer);
        this.resyncSent = false;
        this.send({
            type: 'ready',
            next_seq: this.expectedSeq,
            durable_seq: this.durableSeq,
            stored_samples: Math.floor(this.writer.totalBytes / 2),
        });
    }

    /** Asks the client to resend from the first frame we are missing. */
    resync() {
        this.resyncSent = true;
        this.send({ type: 'ready', next_seq: this.expectedSeq, durable_seq: this.durableSeq, stored_samples: Math.floor(this.writer.totalBytes / 2) });
    }

    detach(socket) {
        if (this.socket !== socket) return;
        this.socket = null;
        if (this.finalized || this.stopping || this.ctx.shuttingDown) return;
        // If the courtroom never reconnects, seal what we have so the original is preserved.
        this.graceTimer = setTimeout(() => {
            log.warn('recording_auto_finalise', { rec: this.recId });
            this.stop('auto').catch((err) => log.error('auto_finalise_failed', { rec: this.recId, error: err.message }));
        }, this.ctx.config.sessionGraceMinutes * 60 * 1000);
        this.graceTimer.unref();
    }

    send(msg) {
        if (this.socket && this.socket.readyState === 1) {
            this.socket.send(JSON.stringify(msg));
        }
    }

    onFrame(buf) {
        if (this.stopping || this.sealed || buf.length < 4) return;
        const seq = buf.readUInt32BE(0);
        if (seq < this.expectedSeq) {
            this.send({ type: 'ack', seq: this.durableSeq });
            return;
        }
        if (seq > this.expectedSeq) {
            if (!this.resyncSent) {
                this.resync();
            }
            return;
        }
        this.resyncSent = false;
        let pcm = buf.subarray(4);
        if (pcm.length % 2 !== 0) pcm = pcm.subarray(0, pcm.length - 1);
        if (this.skipBytes > 0) {
            const skip = Math.min(this.skipBytes, pcm.length);
            pcm = pcm.subarray(skip);
            this.skipBytes -= skip;
        }
        const copy = Buffer.from(pcm);
        const flushed = this.writer.write(copy);
        this.frameEnds.push([seq, this.writer.totalBytes]);
        this.expectedSeq = seq + 1;
        this.addToWindow(copy);
        if (flushed) this.markDurable();
    }

    markDurable() {
        const durable = this.writer.closed ? Infinity : this.writer.durableBytes;
        let changed = false;
        while (this.frameEnds.length && this.frameEnds[0][1] <= durable) {
            const [seq, end] = this.frameEnds.shift();
            this.durableSeq = seq;
            this.durableSeqEnd = end;
            changed = true;
        }
        if (changed) {
            this.persist();
            this.send({ type: 'ack', seq: this.durableSeq });
        }
    }

    currentMs() {
        return Math.floor((this.writer.totalBytes / 2 / this.sampleRate) * 1000);
    }

    async onControl(msg) {
        if (typeof msg.cid !== 'string' || msg.cid.length > 80) return;
        if (this.controlSeen.has(msg.cid)) {
            this.send({ type: 'ack_ctl', cid: msg.cid });
            return;
        }
        const atMs = Math.max(0, Math.min(Number(msg.at_ms) || 0, this.currentMs() + 60000));
        if (msg.type === 'speaker') {
            const label = String(msg.label || '').slice(0, 120);
            this.speakers.push({ at_ms: atMs, label });
            this.speakers.sort((a, b) => a.at_ms - b.at_ms);
        } else if (msg.type === 'bookmark') {
            await this.ctx.php.post(`/internal/recordings/${this.recId}/bookmarks`, {
                at_ms: atMs,
                label: String(msg.label || 'Bookmark').slice(0, 200),
                user_id: this.userId,
            });
        } else {
            return;
        }
        this.controlSeen.add(msg.cid);
        this.persist();
        this.send({ type: 'ack_ctl', cid: msg.cid });
    }

    speakerAt(ms) {
        let label = null;
        for (const s of this.speakers) {
            if (s.at_ms <= ms) label = s.label;
            else break;
        }
        return label;
    }

    addToWindow(pcm) {
        this.window.push(pcm);
        this.windowBytes += pcm.length;
        if (this.windowBytes >= this.ctx.config.liveWindowSeconds * this.sampleRate * 2) {
            this.cutWindow();
        }
    }

    /** Hands the current audio window to the speech engine without blocking capture. */
    cutWindow() {
        if (this.windowBytes === 0) return Promise.resolve();
        const pcm = Buffer.concat(this.window);
        const endBytes = this.writer.totalBytes;
        const startMs = Math.floor(((endBytes - pcm.length) / 2 / this.sampleRate) * 1000);
        const index = this.windowIndex++;
        this.window = [];
        this.windowBytes = 0;
        const job = this.ctx.queue.push(async () => {
            const raw = await this.ctx.provider.transcribe(pcm, this.sampleRate, this.language);
            pcm.fill(0);
            const segments = raw.map((s, i) => {
                const start = startMs + Math.round(s.start * 1000);
                const end = startMs + Math.round(s.end * 1000);
                return {
                    seq: index * 1000 + i,
                    start_ms: start,
                    end_ms: end,
                    speaker: this.speakerAt(Math.floor((start + end) / 2)),
                    text: s.text,
                    confidence: s.confidence,
                };
            });
            if (segments.length) {
                await this.ctx.php.post(`/internal/recordings/${this.recId}/segments`, { segments });
                for (const segment of segments) this.send({ type: 'segment', segment });
            }
        }).catch((err) => log.error('live_transcription_failed', { rec: this.recId, window: index, error: err.message }));
        this.pendingWindows.add(job);
        job.finally(() => this.pendingWindows.delete(job));
        return job;
    }

    /**
     * Seals the original recording and reports its integrity data to PHP.
     * Sealing happens once; reporting is retried until PHP accepts it.
     */
    async stop(reason = 'user') {
        if (this.finalized || this.stopping) return;
        this.stopping = true;
        clearTimeout(this.graceTimer);
        try {
            if (!this.sealed) {
                this.cutWindow();
                this.writer.finish();
                this.markDurable();
                // Keep the speaker timeline (encrypted) so re-transcription can reuse it.
                fs.writeFileSync(
                    path.join(this.ctx.recordingsDir, `${this.storageKey}.speakers`),
                    encryptField(this.ctx.ring, JSON.stringify(this.speakers), `speakers:${this.storageKey}`),
                    { mode: 0o600 }
                );
                this.sealed = true;
                this.persist();
            }
            const digest = digestEncryptedFile(this.filePath, this.dek);
            await this.ctx.php.post(`/internal/recordings/${this.recId}/finalize`, {
                storage_key: this.storageKey,
                wrapped_key: this.wrappedKey,
                sha256: digest.sha256,
                chunk_chain: digest.chunkChain,
                reason,
            });
            this.finalized = true;
            fs.rmSync(RecordingSession.sidecarPath(this.ctx, this.recId), { force: true });
            this.ctx.sessions.delete(this.recId);
            this.send({ type: 'finalized', sha256: digest.sha256 });
            log.info('recording_finalised', { rec: this.recId, bytes: digest.bytes, reason });
            await Promise.allSettled([...this.pendingWindows]);
            this.dek.fill(0);
        } catch (err) {
            this.stopping = false;
            this.send({ type: 'error', message: 'The recording is sealed on the server but could not yet be registered. Retrying automatically.' });
            this.graceTimer = setTimeout(() => this.stop(reason).catch(() => {}), 60000);
            this.graceTimer.unref();
            throw err;
        }
    }
}
