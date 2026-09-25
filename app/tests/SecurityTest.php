<?php

declare(strict_types=1);

use JC\Core\App;
use JC\Core\Config;
use JC\Core\Database;
use JC\Core\Migrator;
use JC\Core\Request;
use JC\Core\Response;
use JC\Security\Permissions;
use JC\Security\ServiceAuth;
use JC\Security\Totp;
use JC\Security\TransportSecurity;

/**
 * A migrated, empty database. Uses in-memory SQLite, or a MySQL/MariaDB test
 * database when TEST_MYSQL_DSN (+ TEST_MYSQL_USER/PASS/CA) is set. The MySQL
 * database is wiped for every test — never point it at real data.
 */
function freshApp(): App
{
    if ($dsn = getenv('TEST_MYSQL_DSN')) {
        $opts = getenv('TEST_MYSQL_CA') ? [PDO::MYSQL_ATTR_SSL_CA => getenv('TEST_MYSQL_CA')] : [];
        $pdo = new PDO($dsn, getenv('TEST_MYSQL_USER') ?: null, getenv('TEST_MYSQL_PASS') ?: null, $opts);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $pdo->exec("DROP TABLE `{$table}`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $db = new Database($pdo);
        (new Migrator($db, JC_ROOT . '/database/migrations'))->migrate();
        return new App($db, ring());
    }
    $db = new Database(new PDO('sqlite::memory:'));
    (new Migrator($db, JC_ROOT . '/database/migrations'))->migrate();
    return new App($db, ring());
}

test('TOTP matches the RFC 6238 test vectors', function (): void {
    // RFC 6238 appendix B, SHA-1 secret "12345678901234567890" (8-digit values truncated to 6).
    $secret = Totp::base32Encode('12345678901234567890');
    same('287082', Totp::code($secret, 59));
    same('081804', Totp::code($secret, 1111111109));
    same('050471', Totp::code($secret, 1111111111));
    same('005924', Totp::code($secret, 1234567890));
});

test('TOTP rejects reuse of an already used time step', function (): void {
    $secret = Totp::generateSecret();
    $now = 1_800_000_000;
    $step = Totp::verify($secret, Totp::code($secret, $now), null, $now);
    check($step !== null);
    same(null, Totp::verify($secret, Totp::code($secret, $now), $step, $now), 'replayed code');
    same(null, Totp::verify($secret, '000000x', null, $now));
});

test('capture tokens verify and expire', function (): void {
    $auth = new ServiceAuth(str_repeat('k', 40));
    $token = $auth->issueToken(['rec' => 7, 'scope' => 'record']);
    same(7, $auth->verifyToken($token)['rec']);
    same(null, $auth->verifyToken($token . 'x'));
    same(null, (new ServiceAuth(str_repeat('z', 40)))->verifyToken($token), 'different secret');
    same(null, $auth->verifyToken($auth->issueToken(['rec' => 7], -10)), 'expired');
});

test('signed service requests reject tampering and replay', function (): void {
    $app = freshApp();
    $auth = new ServiceAuth(str_repeat('k', 40));
    $body = '{"segments":[]}';
    $h = $auth->signRequest('POST', '/internal/recordings/1/segments', $body);
    check($auth->verifyRequest($app->db(), 'POST', '/internal/recordings/1/segments', '{"segments":[1]}', $h['X-JC-Timestamp'], $h['X-JC-Nonce'], $h['X-JC-Signature']) === false, 'body tamper');
    check($auth->verifyRequest($app->db(), 'POST', '/internal/recordings/2/segments', $body, $h['X-JC-Timestamp'], $h['X-JC-Nonce'], $h['X-JC-Signature']) === false, 'path tamper');
    check($auth->verifyRequest($app->db(), 'POST', '/internal/recordings/1/segments', $body, $h['X-JC-Timestamp'], $h['X-JC-Nonce'], $h['X-JC-Signature']), 'valid');
    check($auth->verifyRequest($app->db(), 'POST', '/internal/recordings/1/segments', $body, $h['X-JC-Timestamp'], $h['X-JC-Nonce'], $h['X-JC-Signature']) === false, 'replay');
    $old = (string) (time() - 3600);
    check($auth->verifyRequest($app->db(), 'POST', '/x', $body, $old, bin2hex(random_bytes(16)), 'sig') === false, 'stale');
});

test('audit chain verifies and detects tampering', function (): void {
    $app = freshApp();
    for ($i = 0; $i < 5; $i++) {
        $app->audit()->record('test.event', null, 1, 'case', $i, ['n' => $i], '10.0.0.1');
    }
    same(true, $app->audit()->verify()['ok']);
    throws(fn () => $app->db()->run("UPDATE audit_log SET action = 'x' WHERE id = 2"), PDOException::class, 'trigger must block updates');
    throws(fn () => $app->db()->run('DELETE FROM audit_log WHERE id = 5'), PDOException::class, 'trigger must block deletes');
    // Simulate an attacker who bypasses the triggers.
    $app->db()->pdo()->exec('DROP TRIGGER audit_log_no_update');
    $app->db()->pdo()->exec('DROP TRIGGER audit_log_no_delete');
    $app->db()->run("UPDATE audit_log SET entity_id = 99 WHERE id = 3");
    $r = $app->audit()->verify();
    same(false, $r['ok']);
    same(3, $r['failed_id']);
    $app->db()->run('UPDATE audit_log SET entity_id = 2 WHERE id = 3');
    same(true, $app->audit()->verify()['ok'], 'restored');
    $app->db()->run('DELETE FROM audit_log WHERE id = 5');
    same(false, $app->audit()->verify()['ok'], 'truncation from the end');
});

test('audit details and IP addresses are encrypted at rest', function (): void {
    $app = freshApp();
    $app->audit()->record('document.downloaded', null, 1, 'document', 1, ['case_id' => 42], '196.46.1.2');
    $row = $app->db()->fetch('SELECT details, ip_address FROM audit_log');
    check(!str_contains((string) $row['details'], '42') && !str_contains((string) $row['ip_address'], '196.46'));
});

test('staff can never be granted judge-only capabilities', function (): void {
    $caps = Permissions::sanitise('marshal', ['cases.view', 'staff.manage', 'judgments.approve', 'transcripts.certify', 'bogus']);
    same(['cases.view'], $caps);
    check(in_array('staff.manage', Permissions::defaultsFor('judge'), true));
});

test('production requires HTTPS and responses carry HSTS and CSP', function (): void {
    Config::set('APP_ENV', 'production');
    try {
        $plain = new Request('GET', '/cases', [], [], ['REQUEST_URI' => '/cases'], []);
        $r = TransportSecurity::enforce($plain);
        same(301, $r?->status);
        same(403, TransportSecurity::enforce(new Request('POST', '/login', [], [], [], []))?->status);
        $secure = new Request('GET', '/cases', [], [], ['HTTPS' => 'on'], []);
        same(null, TransportSecurity::enforce($secure));
        $resp = new Response('ok');
        TransportSecurity::applyHeaders($secure, $resp);
        check(str_contains($resp->headers['Strict-Transport-Security'], 'max-age=63072000'));
        check(str_contains($resp->headers['Content-Security-Policy'], "script-src 'self'"));
        same('no-store, max-age=0', $resp->headers['Cache-Control']);
        // Forwarded proto is only honoured from a trusted proxy.
        Config::set('TRUSTED_PROXIES', '10.0.0.5');
        check((new Request('GET', '/', [], [], ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'https'], []))->isSecure());
        check(!(new Request('GET', '/', [], [], ['REMOTE_ADDR' => '8.8.8.8', 'HTTP_X_FORWARDED_PROTO' => 'https'], []))->isSecure());
    } finally {
        Config::set('APP_ENV', 'testing');
    }
});

test('MySQL connections are refused in production without TLS', function (): void {
    Config::set('APP_ENV', 'production');
    Config::set('DB_DSN', 'mysql:host=db.example;dbname=x');
    Config::set('DB_SSL_CA', '');
    try {
        throws(fn () => Database::fromConfig(), RuntimeException::class);
    } finally {
        Config::set('APP_ENV', 'testing');
    }
});
