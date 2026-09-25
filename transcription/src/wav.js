/** 44-byte RIFF header for 16-bit PCM, matching RecordingService::wavHeader() in PHP. */
export function wavHeader(dataBytes, sampleRate, channels = 1, bits = 16) {
    const blockAlign = (channels * bits) / 8;
    const h = Buffer.alloc(44);
    h.write('RIFF', 0);
    h.writeUInt32LE(36 + dataBytes, 4);
    h.write('WAVE', 8);
    h.write('fmt ', 12);
    h.writeUInt32LE(16, 16);
    h.writeUInt16LE(1, 20);
    h.writeUInt16LE(channels, 22);
    h.writeUInt32LE(sampleRate, 24);
    h.writeUInt32LE(sampleRate * blockAlign, 28);
    h.writeUInt16LE(blockAlign, 32);
    h.writeUInt16LE(bits, 34);
    h.write('data', 36);
    h.writeUInt32LE(dataBytes, 40);
    return h;
}

export const toWav = (pcm, sampleRate) => Buffer.concat([wavHeader(pcm.length, sampleRate), pcm]);
