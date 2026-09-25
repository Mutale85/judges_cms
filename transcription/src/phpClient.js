/*
 * Signed HTTPS calls from the transcription service to the PHP application.
 * Custom certificate authorities are honoured through NODE_EXTRA_CA_CERTS.
 */
import { log } from './log.js';

export class PhpClient {
    constructor(baseUrl, auth) {
        this.baseUrl = baseUrl;
        this.auth = auth;
    }

    async post(path, payload, { retries = 5 } = {}) {
        const url = new URL(this.baseUrl + path);
        const body = JSON.stringify(payload);
        let lastError;
        for (let attempt = 0; attempt <= retries; attempt++) {
            try {
                // Re-sign every attempt: nonces are single use.
                const res = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', ...this.auth.signRequest('POST', url.pathname, body) },
                    body,
                    signal: AbortSignal.timeout(30000),
                });
                const text = await res.text();
                if (res.ok) {
                    return text ? JSON.parse(text) : {};
                }
                const err = new Error(`PHP responded ${res.status} for ${path}: ${text.slice(0, 200)}`);
                err.status = res.status;
                if (res.status >= 400 && res.status < 500) {
                    throw err; // not retryable
                }
                lastError = err;
            } catch (err) {
                if (err.status && err.status < 500) throw err;
                lastError = err;
            }
            const delay = Math.min(1000 * 2 ** attempt, 30000);
            log.warn('php_call_retry', { path, attempt, delay, error: lastError?.message });
            await new Promise((r) => setTimeout(r, delay));
        }
        throw lastError;
    }
}
