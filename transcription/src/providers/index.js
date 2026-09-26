/*
 * Speech-to-text engines. Each provider exposes
 *   transcribe(pcm: Buffer (16-bit mono), sampleRate, language) =>
 *       Promise<Array<{ start: seconds, end: seconds, text, confidence? }>>
 * with times relative to the start of the supplied audio.
 *
 * Optional capabilities:
 *   supports(language)            false => audio is never sent for that language
 *   transcribeStream(chunks, totalBytes, sampleRate, language)
 *                                 whole-recording pass (streamed, not buffered)
 *   openLiveStream({...})         real-time draft instead of windowed batches
 *   external = true               audio leaves court infrastructure
 *
 * whisper-cpp and http run on infrastructure the court controls. assemblyai is
 * an external processor (see docs/SECURITY.md).
 */
import { MockProvider } from './mock.js';
import { WhisperCppProvider } from './whispercpp.js';
import { HttpWhisperProvider } from './http.js';
import { AssemblyAIProvider } from './assemblyai.js';

/**
 * Per-language routing. ASR_PROVIDER is the default engine; ASR_LANGUAGE_ROUTES
 * sends particular hearing languages elsewhere, e.g.
 *   ASR_PROVIDER=assemblyai
 *   ASR_LANGUAGE_ROUTES=bem:http,nya:http,toi:http,loz:http
 * routes English to AssemblyAI and the Zambian languages to a self-hosted
 * engine (e.g. a Zambezi Voice / MMS fine-tuned model behind HTTP_ASR_URL).
 * Returns a function language -> provider.
 */
export function createRouter(config) {
    const cache = new Map();
    const build = (name) => {
        if (!cache.has(name)) cache.set(name, createProvider({ ...config, provider: name }));
        return cache.get(name);
    };
    const routes = new Map();
    for (const entry of (config.languageRoutes || '').split(',').map((s) => s.trim()).filter(Boolean)) {
        const [lang, name] = entry.split(':').map((s) => s.trim());
        if (lang && name) routes.set(lang, name);
    }
    const fallback = build(config.provider);
    for (const name of routes.values()) build(name); // fail fast on misconfiguration
    const route = (language) => (routes.has(language) ? build(routes.get(language)) : fallback);
    route.all = () => [...cache.values()];
    return route;
}

export function createProvider(config) {
    switch (config.provider) {
        case 'whisper-cpp':
            return new WhisperCppProvider(config);
        case 'http':
            return new HttpWhisperProvider(config);
        case 'assemblyai':
            return new AssemblyAIProvider(config);
        case 'mock':
            return new MockProvider();
        default:
            throw new Error(`Unknown ASR_PROVIDER '${config.provider}'.`);
    }
}
