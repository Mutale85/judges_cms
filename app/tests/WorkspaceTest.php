<?php

declare(strict_types=1);

use JC\Core\App;
use JC\Core\Config;
use JC\Core\HttpException;
use JC\Core\Request;
use JC\Core\Terminology;
use JC\Service\AccountService;
use JC\Service\CaseService;
use JC\Service\Mailer;
use JC\Service\ReminderService;
use JC\Service\ReportService;
use JC\Service\SettingsService;
use JC\Service\SubscriptionService;
use JC\Service\UserService;

function signupData(array $over = []): array
{
    return $over + [
        'mode' => 'court',
        'full_name' => 'Hon. Mr Justice K. Mulenga',
        'court' => 'High Court for Zambia',
        'email' => 'mulenga@t.zm',
        'password' => 'Kafue-River-2026!',
        'password_confirmation' => 'Kafue-River-2026!',
        'accept_terms' => '1',
    ];
}

function tokenFrom(string $link): string
{
    parse_str((string) parse_url($link, PHP_URL_QUERY), $q);
    return (string) $q['token'];
}

test('sign-up creates an unverified owner, a trial workspace and sends a confirmation link', function (): void {
    $app = freshApp();
    $accounts = $app->service(AccountService::class);
    $ids = $accounts->register(signupData(['mode' => 'arbitration']));
    $user = $app->service(UserService::class)->find($ids['user_id']);
    same(null, $user['email_verified_at']);
    same(0, (int) $user['must_change_password']);
    $chambers = $app->db()->fetch('SELECT * FROM chambers WHERE id = ?', [$ids['chambers_id']]);
    same('arbitration', $chambers['mode']);
    same('trial', $chambers['subscription_status']);
    same('trial', $app->service(SubscriptionService::class)->state($chambers));
    same('judge', $app->db()->value('SELECT role FROM chamber_members WHERE user_id = ?', [$ids['user_id']]));

    $mail = $app->service(Mailer::class)->sent;
    same(1, count($mail));
    check(str_contains($mail[0]['text'], '/verify-email?token='), 'link in e-mail');
    $token = tokenFrom((string) $accounts->lastLink);
    $stored = $app->db()->value('SELECT token_hash FROM user_tokens WHERE user_id = ?', [$ids['user_id']]);
    same(hash('sha256', $token), $stored, 'only the hash is stored');

    same($ids['user_id'], $accounts->verifyEmail($token));
    same(null, $accounts->verifyEmail($token), 'single use');
    check($app->service(UserService::class)->find($ids['user_id'])['email_verified_at'] !== null);

    throws(fn () => $accounts->register(signupData()), InvalidArgumentException::class, 'duplicate e-mail');
    throws(fn () => $accounts->register(signupData(['email' => 'x@t.zm', 'accept_terms' => ''])), InvalidArgumentException::class, 'terms');
    throws(fn () => $accounts->register(signupData(['email' => 'y@t.zm', 'password_confirmation' => 'nope'])), InvalidArgumentException::class, 'mismatch');
});

test('sign-up can be switched off from settings', function (): void {
    $app = freshApp();
    $app->service(SettingsService::class)->set('signup_enabled', '0');
    throws(fn () => $app->service(AccountService::class)->register(signupData()), InvalidArgumentException::class);
});

test('password reset: generic for unknown addresses, single-use, expiring, rate limited', function (): void {
    $app = freshApp();
    $accounts = $app->service(AccountService::class);
    $users = $app->service(UserService::class);
    $id = $users->create('reset@t.zm', 'R', 'Original-Pass-2026!');
    $accounts->requestPasswordReset('nobody@t.zm');
    same(0, count($app->service(Mailer::class)->sent), 'nothing sent to unknown address');

    $accounts->requestPasswordReset('RESET@t.zm');
    $token = tokenFrom((string) $accounts->lastLink);
    check($accounts->validResetToken($token));
    $accounts->lastLink = null;
    $accounts->requestPasswordReset('reset@t.zm');
    same(null, $accounts->lastLink, 'second request within a minute is ignored');

    throws(fn () => $accounts->resetPassword($token, 'weak', 'weak'), InvalidArgumentException::class);
    $accounts->resetPassword($token, 'Brand-New-Pass-2026!', 'Brand-New-Pass-2026!');
    check($users->attempt('reset@t.zm', 'Brand-New-Pass-2026!') !== null);
    throws(fn () => $accounts->resetPassword($token, 'Brand-New-Pass-2027!', 'Brand-New-Pass-2027!'), InvalidArgumentException::class, 'token reused');

    // Expired tokens are rejected.
    $app->db()->run('DELETE FROM user_tokens');
    $accounts->requestPasswordReset('reset@t.zm');
    $token = tokenFrom((string) $accounts->lastLink);
    $app->db()->run('UPDATE user_tokens SET expires_at = ?', [now_utc(-10)]);
    check(!$accounts->validResetToken($token), 'expired');
});

test('subscriptions: trial expiry, grace period, payment confirmation and read-only gate', function (): void {
    $app = freshApp();
    $subs = $app->service(SubscriptionService::class);
    $ids = $app->service(AccountService::class)->register(signupData());
    $cid = $ids['chambers_id'];
    $load = fn () => $app->db()->fetch('SELECT * FROM chambers WHERE id = ?', [$cid]);

    $app->db()->update('chambers', ['current_period_end' => now_utc(-60)], ['id' => $cid]);
    same('expired', $subs->state($load()), 'a lapsed trial has no grace');
    check(!$subs->isWritable($load()));

    $plan = $subs->planByCode('solo');
    $pid = $subs->submitPayment($cid, $ids['user_id'], $plan['id'], 'annual', 'airtel_money', 'MP12345');
    throws(fn () => $subs->submitPayment($cid, $ids['user_id'], $plan['id'], 'monthly', 'airtel_money', 'MP99999'), InvalidArgumentException::class, 'one pending at a time');
    same($plan['price_annual'], $app->db()->value('SELECT amount FROM payments WHERE id = ?', [$pid]));

    $subs->confirmPayment($pid, 1);
    $c = $load();
    same('active', $subs->state($c));
    same((int) $plan['id'], (int) $c['plan_id']);
    check($c['current_period_end'] > now_utc(360 * 86400), 'extended by a year');
    throws(fn () => $subs->confirmPayment($pid, 1), InvalidArgumentException::class, 'no double confirmation');

    // Paid period ended 3 days ago: grace (7 days by default), still writable.
    $app->db()->update('chambers', ['current_period_end' => now_utc(-3 * 86400)], ['id' => $cid]);
    same('grace', $subs->state($load()));
    check($subs->isWritable($load()));
    $app->db()->update('chambers', ['current_period_end' => now_utc(-10 * 86400)], ['id' => $cid]);
    same('expired', $subs->state($load()));

    // Workspaces without an end date (institutional) never lapse.
    $subs->adminUpdate($cid, null, 'active', null, 'monthly');
    same('active', $subs->state($load()));
    $subs->adminUpdate($cid, $plan['id'], 'cancelled', null, 'monthly');
    check(!$subs->isWritable($load()));
});

test('read-only gate blocks writes for lapsed workspaces but not billing', function (): void {
    $app = freshApp();
    $ids = $app->service(AccountService::class)->register(signupData());
    $app->db()->run('UPDATE users SET email_verified_at = ?', [now_utc()]);
    $app->db()->update('chambers', ['current_period_end' => now_utc(-60)], ['id' => $ids['chambers_id']]);
    $router = require JC_ROOT . '/routes.php';

    $request = static function (string $path, array $post = []) use ($app): Request {
        return new Request('POST', $path, [], $post + ['_csrf' => $_SESSION['_csrf']], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'], []);
    };
    $_SESSION = ['user_id' => $ids['user_id'], 'chambers_id' => $ids['chambers_id'], '_csrf' => 'tok'];
    $as = new App($app->db(), ring());
    $res = $as->handle($request('/cases', ['case_number' => '1', 'title' => 'T', 'case_type' => 'civil']), $router);
    same(402, $res->status);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM cases'));

    $_SESSION['_csrf'] = 'tok';
    $as = new App($app->db(), ring());
    $res = $as->handle($request('/billing/pay', ['plan_id' => '1', 'billing_cycle' => 'monthly', 'method' => 'mtn_momo', 'reference' => 'REF-0001']), $router);
    same(200, $res->status, 'billing stays usable: ' . $res->body);
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM payments WHERE status = 'pending'"));
});

test('admin routes require a platform administrator', function (): void {
    [$app, $chambers, $judge] = chambersFixture();
    $router = require JC_ROOT . '/routes.php';
    $app->db()->run('UPDATE users SET must_change_password = 0');
    $_SESSION = ['user_id' => $judge, 'chambers_id' => $chambers, '_csrf' => 'tok'];
    $res = (new App($app->db(), ring()))->handle(new Request('GET', '/admin/users', [], [], [], []), $router);
    same(403, $res->status);

    $adminId = $app->service(UserService::class)->create('root@t.zm', 'Root', 'Admin-Pass-2026!', true, null, false);
    $_SESSION = ['user_id' => $adminId, '_csrf' => 'tok'];
    $res = (new App($app->db(), ring()))->handle(new Request('GET', '/admin/users', [], [], [], []), $router);
    same(200, $res->status);
    check(str_contains($res->body, 'judge@t.zm'), 'decrypted e-mails listed');

    $_SESSION = ['user_id' => $adminId, '_csrf' => 'tok'];
    $res = (new App($app->db(), ring()))->handle(new Request('POST', '/admin/plans', [], [
        '_csrf' => 'tok', 'code' => 'bench', 'name' => 'Bench', 'price_monthly' => '1,200', 'price_annual' => '12000', 'currency' => 'zmw', 'is_active' => '1',
    ], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'], []), $router);
    same(200, $res->status, $res->body);
    same(1200.0, (float) $app->db()->value("SELECT price_monthly FROM plans WHERE code = 'bench'"));
});

test('plan limits: staff cap and transcription', function (): void {
    [$app, $chambers] = chambersFixture();
    $subs = $app->service(SubscriptionService::class);
    $solo = $subs->planByCode('solo');
    $app->db()->update('chambers', ['plan_id' => $solo['id']], ['id' => $chambers]);
    $c = $app->db()->fetch('SELECT * FROM chambers WHERE id = ?', [$chambers]);
    same(1, $subs->staffLimit($c));
    check(!$subs->canAddStaff($c), 'fixture already has two staff');
    $app->db()->run('UPDATE plans SET includes_transcription = 0 WHERE id = ?', [$solo['id']]);
    check(!$subs->includesTranscription($c));
    throws(fn () => $subs->deletePlan($solo['id']), InvalidArgumentException::class, 'plan in use');
});

test('reminders: encrypted, case-scoped visibility, recurrence rolls forward', function (): void {
    [$app, $chambers, $judge, $marshal] = chambersFixture();
    $asJudge = actAs($app, $judge, $chambers);
    $cases = $asJudge->service(CaseService::class);
    $sealed = $cases->create($chambers, $judge, ['case_number' => 'S/1', 'title' => 'Sealed', 'case_type' => 'civil', 'sealed' => true]);
    $rem = $asJudge->service(ReminderService::class);
    $tomorrow = (new DateTimeImmutable('tomorrow', app_timezone()))->format('Y-m-d');
    $rem->create($chambers, $judge, ['title' => 'Deliver ruling', 'due_date' => $tomorrow, 'kind' => 'ruling', 'case_id' => $sealed]);
    $general = $rem->create($chambers, $judge, ['title' => 'Weekly review', 'due_date' => '2020-01-06', 'recurrence' => 'weekly']);
    throws(fn () => $rem->create($chambers, $judge, ['title' => 'x', 'due_date' => 'bad']), InvalidArgumentException::class);

    $raw = $app->db()->value('SELECT title FROM reminders WHERE id = ?', [$general]);
    check(!str_contains((string) $raw, 'Weekly'), 'title encrypted at rest');
    same(2, count($rem->dueSoon()));

    $asMarshal = actAs($app, $marshal, $chambers);
    same(['Weekly review'], array_column($asMarshal->service(ReminderService::class)->list(null, null, 'open'), 'title'), 'sealed case reminder hidden');
    throws(fn () => $asMarshal->service(ReminderService::class)->requireAccessible($general + 1000), HttpException::class);

    $r = $rem->find($general);
    $next = $rem->complete($r, $judge);
    check($next !== null && $next > now_utc(), 'rolled into the future');
    same('open', $rem->find($general)['status']);
    same(0, (strtotime($next . ' UTC') - strtotime($r['due_at'] . ' UTC')) % (7 * 86400), 'whole weeks later');
});

test('reports: registered, concluded, pending output and CSV injection guard', function (): void {
    [$app, $chambers, $judge] = chambersFixture();
    $asJudge = actAs($app, $judge, $chambers);
    $cases = $asJudge->service(CaseService::class);
    $a = $cases->create($chambers, $judge, ['case_number' => '=CMD()', 'title' => 'Formula', 'case_type' => 'civil']);
    $b = $cases->create($chambers, $judge, ['case_number' => 'B/2', 'title' => 'Two', 'case_type' => 'commercial']);
    $cases->setStatus($a, 'judgment_reserved');
    $cases->setStatus($b, 'concluded');
    $today = (new DateTimeImmutable('today', app_timezone()))->format('Y-m-d');
    $reports = $asJudge->service(ReportService::class);
    $r = $reports->returns($today, $today);
    same(2, count($r['registered']));
    same(1, count($r['concluded']));
    same(1, count($r['pending']));
    same(50, (int) $r['clearance_rate']);
    $csv = $reports->csv($r);
    check(str_contains($csv, "'=CMD()"), 'formula neutralised');
});

test('arbitration mode changes wording, not data', function (): void {
    Terminology::setMode('arbitration');
    same('Reference', term('Case'));
    same('Award reserved', CaseService::statusLabel('judgment_reserved'));
    check(isset(CaseService::partyRoles()['claimant']) && !isset(CaseService::partyRoles()['plaintiff']));
    same('Arbitrator', JC\Security\Permissions::roleLabel('judge'));
    Terminology::setMode('court');
    same('Case', term('Case'));
    same('Judgment reserved', CaseService::statusLabel('judgment_reserved'));
});

test('ajax requests get JSON: errors stay on the page, successes redirect', function (): void {
    $app = freshApp();
    $router = require JC_ROOT . '/routes.php';
    $_SESSION = ['_csrf' => 'tok'];
    $headers = ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];
    $res = (new App($app->db(), ring()))->handle(new Request('POST', '/login', [], ['_csrf' => 'tok', 'email' => 'no@t.zm', 'password' => 'x'], $headers, []), $router);
    same(422, $res->status);
    $json = json_decode($res->body, true);
    same(false, $json['ok']);
    check(str_contains($json['message'], 'incorrect'));

    $res = (new App($app->db(), ring()))->handle(new Request('POST', '/login', [], ['_csrf' => 'bad'], $headers, []), $router);
    same(419, $res->status);
    same('application/json', $res->headers['Content-Type']);
});

test('public pages render for guests', function (): void {
    $app = freshApp();
    $router = require JC_ROOT . '/routes.php';
    foreach (['/' => 'private', '/pricing' => 'Chambers', '/signup' => 'Create workspace', '/terms' => 'Terms', '/privacy' => 'Privacy', '/forgot-password' => 'Reset'] as $path => $needle) {
        $_SESSION = [];
        $res = (new App($app->db(), ring()))->handle(new Request('GET', $path, [], [], [], []), $router);
        same(200, $res->status, $path);
        check(str_contains($res->body, $needle), "{$path} contains {$needle}");
        check(!str_contains($res->body, 'style="') || $path === '/', "{$path}: no inline styles (CSP)");
    }
    Config::set('APP_ENV', 'testing');
});
