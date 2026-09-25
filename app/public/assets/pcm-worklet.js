/*
 * AudioWorklet that converts microphone input to 16 kHz mono 16-bit PCM,
 * the format stored as the original court recording and fed to the speech
 * engine. Posts ~0.5 s frames plus an RMS level for the meter.
 */
class PcmCaptureProcessor extends AudioWorkletProcessor {
    constructor(options) {
        super();
        this.targetRate = (options.processorOptions && options.processorOptions.targetRate) || 16000;
        this.ratio = sampleRate / this.targetRate;
        this.frameSamples = Math.round(this.targetRate / 2);
        this.buffer = new Int16Array(this.frameSamples);
        this.filled = 0;
        this.pos = 0;        // fractional read position carried across blocks
        this.acc = 0;        // running sum for the current output sample
        this.accCount = 0;
        this.sumSquares = 0;
        this.active = true;
        this.port.onmessage = (e) => {
            if (e.data === 'flush') {
                this.flush();
            } else if (e.data === 'stop') {
                this.flush();
                this.active = false;
            }
        };
    }

    flush() {
        if (this.filled > 0) {
            const out = this.buffer.slice(0, this.filled);
            const rms = Math.sqrt(this.sumSquares / this.filled);
            this.port.postMessage({ pcm: out.buffer, rms }, [out.buffer]);
            this.filled = 0;
            this.sumSquares = 0;
        }
    }

    process(inputs) {
        if (!this.active) {
            return false;
        }
        const input = inputs[0];
        if (!input || input.length === 0) {
            return true;
        }
        const channel = input[0];
        for (let i = 0; i < channel.length; i++) {
            // Average the input samples that fall into each output sample
            // (a simple box low-pass filter before decimation).
            this.acc += channel[i];
            this.accCount++;
            this.pos += 1;
            if (this.pos >= this.ratio) {
                this.pos -= this.ratio;
                const v = Math.max(-1, Math.min(1, this.acc / this.accCount));
                this.acc = 0;
                this.accCount = 0;
                this.buffer[this.filled++] = v < 0 ? v * 0x8000 : v * 0x7fff;
                this.sumSquares += v * v;
                if (this.filled === this.frameSamples) {
                    this.flush();
                    this.buffer = new Int16Array(this.frameSamples);
                }
            }
        }
        return true;
    }
}

registerProcessor('pcm-capture', PcmCaptureProcessor);
