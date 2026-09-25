/*
 * Structured logs. Never log audio, transcript text, tokens or keys — only
 * identifiers and operational facts.
 */
function write(level, event, fields = {}) {
    const line = JSON.stringify({ ts: new Date().toISOString(), level, event, ...fields });
    (level === 'error' ? process.stderr : process.stdout).write(line + '\n');
}

export const log = {
    info: (event, fields) => write('info', event, fields),
    warn: (event, fields) => write('warn', event, fields),
    error: (event, fields) => write('error', event, fields),
};
