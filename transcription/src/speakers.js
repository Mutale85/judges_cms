/*
 * Speaker attribution.
 *
 * Two sources are combined:
 *  - the manual timeline: labels the court reporter taps in the recording
 *    console ("Judge", "Counsel for the plaintiff", ...), and
 *  - machine diarization (AssemblyAI "Speaker A", "Speaker B", ...), which is
 *    precise about *when* the speaker changes but does not know who they are.
 *
 * Each diarized voice is given the manual label that covers most of its
 * speaking time, provided that label clearly dominates; otherwise it keeps its
 * neutral "Speaker X" name for a person to confirm during review.
 */

export function speakerAt(timeline, ms) {
    let label = null;
    for (const entry of timeline) {
        if (entry.at_ms <= ms) label = entry.label;
        else break;
    }
    return label;
}

/**
 * @param {Array<{start_ms:number,end_ms:number,diarized?:string|null}>} segments
 * @param {Array<{at_ms:number,label:string}>} timeline  sorted by at_ms
 * @returns the same segments with a `speaker` property
 */
export function attributeSpeakers(segments, timeline, { dominance = 0.6 } = {}) {
    const totals = new Map(); // diarized -> Map(label -> ms)
    for (const s of segments) {
        if (!s.diarized) continue;
        const label = speakerAt(timeline, Math.floor((s.start_ms + s.end_ms) / 2));
        if (!label) continue;
        const byLabel = totals.get(s.diarized) ?? new Map();
        byLabel.set(label, (byLabel.get(label) ?? 0) + Math.max(1, s.end_ms - s.start_ms));
        totals.set(s.diarized, byLabel);
    }
    const names = new Map();
    for (const [voice, byLabel] of totals) {
        const sum = [...byLabel.values()].reduce((a, b) => a + b, 0);
        const [best, ms] = [...byLabel.entries()].sort((a, b) => b[1] - a[1])[0];
        if (ms / sum >= dominance) names.set(voice, best);
    }
    return segments.map((s) => ({
        ...s,
        speaker: s.diarized
            ? names.get(s.diarized) ?? `Speaker ${s.diarized}`
            : speakerAt(timeline, Math.floor((s.start_ms + s.end_ms) / 2)),
    }));
}
