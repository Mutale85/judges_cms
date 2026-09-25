/**
 * Small in-process job queue so long hearings and re-transcription jobs never
 * block audio capture. Concurrency is bounded by ASR_CONCURRENCY (speech
 * models are CPU/GPU heavy).
 */
export class JobQueue {
    constructor(concurrency = 1) {
        this.concurrency = Math.max(1, concurrency);
        this.running = 0;
        this.jobs = [];
    }

    push(fn) {
        return new Promise((resolve, reject) => {
            this.jobs.push({ fn, resolve, reject });
            this.next();
        });
    }

    get size() {
        return this.jobs.length + this.running;
    }

    next() {
        while (this.running < this.concurrency && this.jobs.length) {
            const { fn, resolve, reject } = this.jobs.shift();
            this.running++;
            Promise.resolve()
                .then(fn)
                .then(resolve, reject)
                .finally(() => {
                    this.running--;
                    this.next();
                });
        }
    }
}
