/*
 * AssemblyAI speech-to-text (https://www.assemblyai.com).
 *
 * AssemblyAI is an EXTERNAL processor: court audio leaves the institution's
 * infrastructure. The provider therefore:
 *  - only talks to AssemblyAI over TLS (https:// and wss:// are enforced in
 *    production);
 *  - sends audio only for languages AssemblyAI actually supports, and never
 *    anything else (no case names, parties or metadata);
 *  - deletes every transcript from AssemblyAI as soon as the result has been
 *    retrieved, whether or not transcription succeeded;
 *  - cannot be enabled in production without EXTERNAL_ASR_APPROVED=true, a
 *    deliberate, documented decision (see docs/SECURITY.md).
 *
 * Two modes:
 *  - batch   POST /v2/upload -> POST /v2/transcript -> poll -> DELETE, with
 *            speaker diarization; used for the full-hearing pass;
 *  - live    Universal-Streaming WebSocket (v3) for the draft that appears
 *            in the recording console while the hearing is in progress.
 */
import WebSocket from 'ws';
import { log } from '../log.js';
import { wavHeader } from '../wav.js';

// Languages the app offers, mapped to AssemblyAI language codes. Bemba,
// Nyanja, Tonga and Lozi have no AssemblyAI model: those hearings are recorded
// and sealed as usual but are never sent for machine transcription.
const LANGUAGE_CODES = { en: 'en' };

export class AssemblyAIProvider {
    name = 'assemblyai';
    external = true;
    liveMode = 'stream';

    constructor(config) {
        if (!config.assemblyaiKey) throw new Error('ASSEMBLYAI_API_KEY is required for ASR_PROVIDER=assemblyai.');
        this.key = config.assemblyaiKey;
        this.baseUrl = config.assemblyaiBaseUrl.replace(/\/$/, '');
        this.streamingUrl = config.assemblyaiStreamingUrl;
        this.speechModels = config.assemblyaiSpeechModels;
        this.streamingModel = config.assemblyaiStreamingModel;
        this.keyterms = config.assemblyaiKeyterms;
        this.pollMs = config.assemblyaiPollMs;
        this.timeoutMs = config.assemblyaiTimeoutMs;
        this.live = config.assemblyaiLive;
        if (config.appEnv === 'production' && (!this.baseUrl.startsWith('https://') || !this.streamingUrl.startsWith('wss://'))) {
            throw new Error('AssemblyAI endpoints must use https:// and wss:// in production.');
        }
    }

    supports(language) {
        return language in LANGUAGE_CODES;
    }

    /** Batch transcription of an in-memory PCM buffer (16-bit mono). */
    async transcribe(pcm, sampleRate, language = 'en') {
        return this.transcribeStream([pcm], pcm.length, sampleRate, language);
    }

    /**
     * Batch transcription of a whole recording supplied as an (async) iterable
     * of PCM chunks, streamed to AssemblyAI as it is decrypted so a long
     * hearing is never held in memory or written to disk in plaintext.
     */
    async transcribeStream(chunks, totalBytes, sampleRate, language = 'en') {
        if (!this.supports(language)) {
            log.info('asr_language_not_supported', { provider: this.name, language });
            return [];
        }
        const uploadUrl = await this.upload(chunks, totalBytes, sampleRate);
        const transcript = await this.request('POST', '/v2/transcript', {
            audio_url: uploadUrl,
            language_code: LANGUAGE_CODES[language],
            speaker_labels: true,
            punctuate: true,
            format_text: true,
            ...(this.speechModels.length ? { speech_models: this.speechModels } : {}),
            ...(this.keyterms.length ? { keyterms_prompt: this.keyterms } : {}),
        });
        try {
            const done = await this.waitForCompletion(transcript.id);
            return toSegments(done);
        } finally {
            await this.remove(transcript.id);
        }
    }

    /**
     * Batch transcription of an uploaded audio file in its own format (MP3,
     * M4A, WAV…), streamed as it is decrypted. AssemblyAI detects the format.
     */
    async transcribeFile(chunks, totalBytes, language = 'en') {
        return this.transcribeStream(chunks, totalBytes, null, language);
    }

    /** sampleRate null = the chunks are already a complete audio file; no WAV header is added. */
    async upload(chunks, totalBytes, sampleRate) {
        const header = sampleRate === null ? null : wavHeader(totalBytes, sampleRate);
        async function* body() {
            if (header) yield header;
            for await (const chunk of chunks) yield chunk;
        }
        const res = await fetch(`${this.baseUrl}/v2/upload`, {
            method: 'POST',
            headers: { Authorization: this.key, 'Content-Type': 'application/octet-stream' },
            body: body(),
            duplex: 'half',
            signal: AbortSignal.timeout(this.timeoutMs),
        });
        const json = await parse(res, 'upload');
        if (!json.upload_url) throw new Error('AssemblyAI upload returned no upload_url.');
        return json.upload_url;
    }

    async waitForCompletion(id) {
        const deadline = Date.now() + this.timeoutMs;
        for (;;) {
            const t = await this.request('GET', `/v2/transcript/${encodeURIComponent(id)}`);
            if (t.status === 'completed') return t;
            if (t.status === 'error') throw new Error(`AssemblyAI transcription failed: ${t.error || 'unknown error'}`);
            if (Date.now() > deadline) throw new Error('AssemblyAI transcription timed out.');
            await new Promise((r) => setTimeout(r, this.pollMs));
        }
    }

    /** Deletes the transcript (and its data) from AssemblyAI. Failures are logged, never ignored silently. */
    async remove(id) {
        try {
            await this.request('DELETE', `/v2/transcript/${encodeURIComponent(id)}`);
            log.info('asr_remote_transcript_deleted', { provider: this.name, id });
        } catch (err) {
            log.error('asr_remote_delete_failed', { provider: this.name, id, error: err.message });
        }
    }

    async request(method, path, body) {
        const res = await fetch(this.baseUrl + path, {
            method,
            headers: { Authorization: this.key, ...(body ? { 'Content-Type': 'application/json' } : {}) },
            body: body ? JSON.stringify(body) : undefined,
            signal: AbortSignal.timeout(60000),
        });
        return parse(res, `${method} ${path.split('/').slice(0, 3).join('/')}`);
    }

    /**
     * Opens a real-time stream for the live draft. Callers send 16 kHz PCM16
     * frames of 50–1000 ms; `onTurn` receives each finished, formatted turn with
     * timestamps relative to the first audio sent on this stream.
     */
    openLiveStream(options) {
        if (!this.live || !this.supports(options.language)) return null;
        return new AssemblyAILiveStream(this, options);
    }
}

export class AssemblyAILiveStream {
    constructor(provider, { sampleRate, onTurn, onPartial = () => {}, onClose = () => {} }) {
        this.onTurn = onTurn;
        this.onPartial = onPartial;
        this.onClose = onClose;
        this.pending = [];
        this.open = false;
        this.closed = false;
        const url = new URL(provider.streamingUrl);
        url.searchParams.set('sample_rate', String(sampleRate));
        url.searchParams.set('encoding', 'pcm_s16le');
        url.searchParams.set('format_turns', 'true');
        if (provider.streamingModel) url.searchParams.set('speech_model', provider.streamingModel);
        if (provider.keyterms.length) url.searchParams.set('keyterms_prompt', JSON.stringify(provider.keyterms));
        this.terminated = new Promise((resolve) => { this.resolveTerminated = resolve; });

        this.ws = new WebSocket(url, { headers: { Authorization: provider.key } });
        this.ws.on('open', () => {
            this.open = true;
            for (const frame of this.pending) this.ws.send(frame);
            this.pending = [];
        });
        this.ws.on('message', (raw) => {
            let msg;
            try { msg = JSON.parse(raw.toString()); } catch { return; }
            if (msg.type === 'Turn') {
                if (msg.end_of_turn && msg.turn_is_formatted && msg.transcript) {
                    const words = Array.isArray(msg.words) ? msg.words : [];
                    this.onTurn({
                        turn_order: msg.turn_order,
                        text: msg.transcript,
                        start: words.length ? words[0].start : 0,
                        end: words.length ? words[words.length - 1].end : 0,
                        confidence: words.length ? words.reduce((a, w) => a + (w.confidence ?? 0), 0) / words.length : undefined,
                    });
                } else if (!msg.end_of_turn) {
                    this.onPartial(msg.transcript || '');
                }
            } else if (msg.type === 'Termination') {
                this.resolveTerminated();
            } else if (msg.type === 'Error' || msg.error) {
                log.error('asr_stream_error', { error: String(msg.error || 'unknown') });
            }
        });
        this.ws.on('close', (code) => {
            const wasOpen = this.open;
            this.open = false;
            this.resolveTerminated();
            if (!this.closed) {
                this.closed = true;
                this.onClose({ code, unexpected: true, wasOpen });
            }
        });
        this.ws.on('error', (err) => log.error('asr_stream_socket_error', { error: err.message }));
    }

    send(pcm) {
        if (this.closed) return;
        if (this.open) this.ws.send(pcm);
        else if (this.pending.length < 60) this.pending.push(pcm); // ≤30 s while connecting
    }

    /** Flushes the final turn and closes the stream. */
    async close(timeoutMs = 5000) {
        if (this.closed) return;
        this.closed = true;
        if (this.open) {
            this.ws.send(JSON.stringify({ type: 'Terminate' }));
            await Promise.race([this.terminated, new Promise((r) => setTimeout(r, timeoutMs))]);
        }
        this.ws.close();
    }
}

async function parse(res, what) {
    const text = await res.text();
    let json = {};
    try { json = text ? JSON.parse(text) : {}; } catch { /* non-JSON error body */ }
    if (!res.ok) throw new Error(`AssemblyAI ${what} failed (${res.status}): ${json.error || text.slice(0, 200)}`);
    return json;
}

/**
 * Converts an AssemblyAI transcript into segments. Utterances (one per speaker
 * turn) are split into passages of roughly 10–40 s at sentence boundaries so
 * reviewers can replay precisely.
 */
export function toSegments(transcript) {
    const utterances = Array.isArray(transcript.utterances) && transcript.utterances.length
        ? transcript.utterances
        : transcript.text ? [{ speaker: null, words: transcript.words || [], text: transcript.text, start: 0, end: 0, confidence: transcript.confidence }] : [];
    const out = [];
    for (const u of utterances) {
        const words = Array.isArray(u.words) ? u.words : [];
        if (!words.length) {
            out.push({ start: (u.start || 0) / 1000, end: (u.end || 0) / 1000, text: String(u.text || '').trim(), confidence: u.confidence, diarized: u.speaker || null });
            continue;
        }
        let group = [];
        const flush = () => {
            if (!group.length) return;
            out.push({
                start: group[0].start / 1000,
                end: group[group.length - 1].end / 1000,
                text: group.map((w) => w.text).join(' ').trim(),
                confidence: group.reduce((a, w) => a + (w.confidence ?? 0), 0) / group.length,
                diarized: u.speaker || null,
            });
            group = [];
        };
        for (const w of words) {
            group.push(w);
            const span = w.end - group[0].start;
            if ((span >= 10000 && /[.?!]["')\]]?$/.test(w.text)) || span >= 40000) flush();
        }
        flush();
    }
    return out.filter((s) => s.text);
}
