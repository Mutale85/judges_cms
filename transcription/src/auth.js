/*
 * Service-to-service authentication, compatible with app/src/Security/ServiceAuth.php.
 */
import crypto from 'node:crypto';

export const MAX_SKEW = 300;

const b64url = (buf) => Buffer.from(buf).toString('base64url');

export class ServiceAuth {
    constructor(secret) {
        if (!secret || secret.length < 32) throw new Error('SERVICE_HMAC_SECRET must be at least 32 characters.');
        this.secret = secret;
        this.seenNonces = new Map();
    }

    /** Verifies a browser capture token minted by PHP. Returns claims or null. */
    verifyToken(token) {
        if (typeof token !== 'string') return null;
        const parts = token.split('.');
        if (parts.length !== 2) return null;
        const expected = b64url(crypto.createHmac('sha256', this.secret).update(`token.${parts[0]}`).digest());
        if (!safeEqual(expected, parts[1])) return null;
        let claims;
        try {
            claims = JSON.parse(Buffer.from(parts[0], 'base64url').toString('utf8'));
        } catch {
            return null;
        }
        if (!claims || typeof claims !== 'object' || (claims.exp ?? 0) < Math.floor(Date.now() / 1000)) return null;
        return claims;
    }

    signature(method, path, ts, nonce, body) {
        const canonical = `${method.toUpperCase()}\n${path}\n${ts}\n${nonce}\n${crypto.createHash('sha256').update(body).digest('hex')}`;
        return crypto.createHmac('sha256', this.secret).update(canonical).digest('hex');
    }

    signRequest(method, path, body) {
        const ts = String(Math.floor(Date.now() / 1000));
        const nonce = crypto.randomBytes(16).toString('hex');
        return {
            'X-JC-Timestamp': ts,
            'X-JC-Nonce': nonce,
            'X-JC-Signature': this.signature(method, path, ts, nonce, body),
        };
    }

    /** Verifies a signed request from PHP (timestamp window + single-use nonce). */
    verifyRequest(method, path, body, headers) {
        const ts = headers['x-jc-timestamp'];
        const nonce = headers['x-jc-nonce'];
        const sig = headers['x-jc-signature'];
        if (!ts || !nonce || !sig || !/^\d+$/.test(ts) || !/^[a-f0-9]{32}$/.test(nonce)) return false;
        const now = Math.floor(Date.now() / 1000);
        if (Math.abs(now - parseInt(ts, 10)) > MAX_SKEW) return false;
        if (!safeEqual(this.signature(method, path, ts, nonce, body), sig)) return false;
        for (const [n, exp] of this.seenNonces) {
            if (exp < now) this.seenNonces.delete(n);
        }
        if (this.seenNonces.has(nonce)) return false;
        this.seenNonces.set(nonce, now + 2 * MAX_SKEW);
        return true;
    }
}

function safeEqual(a, b) {
    const x = Buffer.from(String(a));
    const y = Buffer.from(String(b));
    return x.length === y.length && crypto.timingSafeEqual(x, y);
}
