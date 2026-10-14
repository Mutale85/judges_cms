/**
 * Development provider: produces clearly-labelled placeholder text so the
 * whole pipeline can be exercised without a speech model installed.
 */
export class MockProvider {
    name = 'mock';

    /** Uploaded audio files cannot be decoded without a codec; return one labelled placeholder. */
    async transcribeFile(chunks, totalBytes) {
        let bytes = 0;
        for await (const chunk of chunks) bytes += chunk.length;
        if (bytes !== totalBytes) throw new Error('Uploaded file length mismatch.');
        return [{ start: 0, end: 5, text: `[Mock transcription of an uploaded file — ${totalBytes} bytes. Configure AssemblyAI to transcribe uploads.]`, confidence: 0.5 }];
    }

    async transcribe(pcm, sampleRate) {
        const seconds = pcm.length / 2 / sampleRate;
        if (seconds < 0.5) return [];
        // Report average loudness so testers can see audio is really flowing.
        let sum = 0;
        for (let i = 0; i < pcm.length; i += 2) sum += Math.abs(pcm.readInt16LE(i));
        const level = Math.round((sum / (pcm.length / 2) / 32768) * 100);
        const segments = [];
        for (let t = 0; t < seconds; t += 5) {
            const end = Math.min(t + 5, seconds);
            segments.push({
                start: t,
                end,
                text: `[Mock transcription — install a speech model to transcribe. ${end.toFixed(1)}s audio, level ${level}%]`,
                confidence: 0.5,
            });
        }
        return segments;
    }
}
