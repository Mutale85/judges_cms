/*
 * Development tool: behaves like the courtroom recording console so the whole
 * pipeline (WSS capture -> encryption -> transcription -> PHP) can be tested
 * without a browser or microphone.
 *
 *   node scripts/simulate-courtroom.js <ws-url> <capture-token> [seconds] [--drop]
 *
 * Streams a synthetic tone as 16 kHz PCM, switches speakers, adds a bookmark,
 * optionally drops the connection half-way and resumes, then stops and waits
 * for the recording to be sealed.
 */
import WebSocket from 'ws';

const [wsUrl, token, secondsArg, ...flags] = process.argv.slice(2);
if (!wsUrl || !token) {
    console.error('usage: node scripts/simulate-courtroom.js <ws-url> <token> [seconds] [--drop]');
    process.exit(2);
}
const seconds = parseInt(secondsArg || '30', 10);
const drop = flags.includes('--drop');
const origin = process.env.ORIGIN || 'http://127.0.0.1:8080';
const sampleRate = 16000;
const frameSamples = 8000;

const frames = [];
for (let f = 0; f < Math.ceil((seconds * sampleRate) / frameSamples); f++) {
    const buf = Buffer.alloc(4 + frameSamples * 2);
    buf.writeUInt32BE(f, 0);
    for (let i = 0; i < frameSamples; i++) {
        const t = (f * frameSamples + i) / sampleRate;
        buf.writeInt16LE(Math.round(Math.sin(2 * Math.PI * 220 * t) * 8000), 4 + i * 2);
    }
    frames.push(buf);
}

let acked = -1;
let dropped = false;

function connect() {
    return new Promise((resolve, reject) => {
        const ws = new WebSocket(wsUrl, { headers: { Origin: origin } });
        ws.on('open', () => ws.send(JSON.stringify({ type: 'auth', token })));
        ws.on('error', reject);
        ws.on('message', (raw) => {
            const msg = JSON.parse(raw.toString());
            if (msg.type === 'ready') resolve({ ws, nextSeq: msg.next_seq });
            if (msg.type === 'ack') acked = Math.max(acked, msg.seq);
            if (msg.type === 'segment') console.log(`segment ${msg.segment.seq} @${msg.segment.start_ms}ms [${msg.segment.speaker}] ${msg.segment.text.slice(0, 60)}`);
            if (msg.type === 'finalized') {
                console.log(`FINALIZED sha256=${msg.sha256}`);
                ws.close();
                process.exit(0);
            }
            if (msg.type === 'error') console.log('ERROR', msg.message);
        });
    });
}

let { ws, nextSeq } = await connect();
console.log('connected, next_seq', nextSeq);
const ctl = (m) => ws.send(JSON.stringify({ ...m, cid: `${m.type}-${Math.random().toString(36).slice(2)}` }));
ctl({ type: 'speaker', label: 'Judge', at_ms: 0 });

for (let seq = nextSeq; seq < frames.length; seq++) {
    if (drop && !dropped && seq === Math.floor(frames.length / 2)) {
        dropped = true;
        console.log(`dropping connection at frame ${seq} (acked ${acked})`);
        ws.terminate();
        await new Promise((r) => setTimeout(r, 300));
        ({ ws, nextSeq } = await connect());
        console.log('reconnected, service asks to resume from frame', nextSeq);
        seq = nextSeq - 1;
        continue;
    }
    ws.send(frames[seq]);
    if (seq === Math.floor(frames.length / 3)) {
        ctl({ type: 'speaker', label: 'Counsel for the plaintiff', at_ms: (seq * frameSamples * 1000) / sampleRate });
        ctl({ type: 'bookmark', label: 'Key admission', at_ms: (seq * frameSamples * 1000) / sampleRate });
    }
    await new Promise((r) => setTimeout(r, 5));
}

// Wait for all frames to be durable, then seal.
const deadline = Date.now() + 10000;
while (Date.now() < deadline && acked < frames.length - 1) {
    await new Promise((r) => setTimeout(r, 100));
    if (acked >= frames.length - 3) break; // tail is flushed on stop
}
ws.send(JSON.stringify({ type: 'stop', final_seq: frames.length - 1 }));
setTimeout(() => {
    console.error('timed out waiting for finalisation');
    process.exit(1);
}, 60000);
