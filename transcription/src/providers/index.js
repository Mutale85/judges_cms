/*
 * Speech-to-text engines. Each provider exposes
 *   transcribe(pcm: Buffer (16-bit mono), sampleRate, language) =>
 *       Promise<Array<{ start: seconds, end: seconds, text, confidence? }>>
 * with times relative to the start of the supplied audio.
 *
 * All providers run on infrastructure the court controls; no audio is sent to
 * a third-party cloud service and no case data is used for model training.
 */
import { MockProvider } from './mock.js';
import { WhisperCppProvider } from './whispercpp.js';
import { HttpWhisperProvider } from './http.js';

export function createProvider(config) {
    switch (config.provider) {
        case 'whisper-cpp':
            return new WhisperCppProvider(config);
        case 'http':
            return new HttpWhisperProvider(config);
        case 'mock':
            return new MockProvider();
        default:
            throw new Error(`Unknown ASR_PROVIDER '${config.provider}'.`);
    }
}
