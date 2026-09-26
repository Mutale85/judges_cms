/*
 * A self-hosted, OpenAI-compatible transcription endpoint running inside the
 * court's network, e.g. faster-whisper-server / speaches or the whisper.cpp
 * server. Use https:// so audio stays encrypted in transit; set
 * NODE_EXTRA_CA_CERTS if the endpoint uses a private CA.
 */
import { toWav } from '../wav.js';

export class HttpWhisperProvider {
    name = 'http';

    constructor(config) {
        if (!config.httpAsrUrl) throw new Error('HTTP_ASR_URL is required for ASR_PROVIDER=http.');
        if (config.appEnv === 'production' && !config.httpAsrUrl.startsWith('https://')) {
            throw new Error('HTTP_ASR_URL must use https:// in production.');
        }
        this.url = config.httpAsrUrl;
        this.model = config.httpAsrModel;
        this.key = config.httpAsrKey;
    }

    async transcribe(pcm, sampleRate, language = 'en') {
        const form = new FormData();
        form.append('file', new Blob([toWav(pcm, sampleRate)], { type: 'audio/wav' }), 'window.wav');
        form.append('model', this.model);
        form.append('response_format', 'verbose_json');
        // English as ISO 639-1; Zambian languages as ISO 639-3 (bem, nya, toi, loz),
        // the codes used by MMS / Zambezi Voice models.
        form.append('language', language);
        const res = await fetch(this.url, {
            method: 'POST',
            body: form,
            headers: this.key ? { Authorization: `Bearer ${this.key}` } : {},
            signal: AbortSignal.timeout(10 * 60 * 1000),
        });
        if (!res.ok) throw new Error(`ASR endpoint responded ${res.status}`);
        const body = await res.json();
        const segments = Array.isArray(body.segments) ? body.segments : [{ start: 0, end: pcm.length / 2 / sampleRate, text: body.text || '' }];
        return segments.map((s) => ({
            start: Number(s.start) || 0,
            end: Number(s.end) || 0,
            text: String(s.text || '').trim(),
            // avg_logprob is roughly -1..0; map to a 0..1 indicator for reviewers.
            confidence: typeof s.avg_logprob === 'number' ? Math.max(0, Math.min(1, Math.exp(s.avg_logprob))) : undefined,
        })).filter((s) => s.text);
    }
}
