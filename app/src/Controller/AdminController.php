<?php

declare(strict_types=1);

namespace JC\Controller;

use JC\Core\HttpException;
use JC\Core\Request;
use JC\Core\Response;
use JC\Security\Permissions;
use JC\Service\SettingsService;
use JC\Service\SubscriptionService;
use JC\Service\UserService;

/**
 * Platform administration: registered users, workspaces, subscription plans
 * and prices, payments and platform settings.
 *
 * Administrators manage accounts, not case files: nothing here reads case
 * content, only counts.
 */
final class AdminController extends Controller
{
    private const PER_PAGE = 50;

    private function subs(): SubscriptionService
    {
        return $this->app->service(SubscriptionService::class);
    }

    private function userService(): UserService
    {
        return $this->app->service(UserService::class);
    }

    /** @param array<string,mixed> $data */
    private function page(string $template, array $data): Response
    {
        return $this->render($template, $data + [
            'pendingPayments' => (int) $this->app->db()->value("SELECT COUNT(*) FROM payments WHERE status = 'pending'"),
        ], 'layout/admin');
    }

    // --- Overview -------------------------------------------------------------

    public function index(Request $request): Response
    {
        $db = $this->app->db();
        $subs = $this->subs();
        $states = array_fill_keys(array_keys(SubscriptionService::STATES), 0);
        foreach ($db->fetchAll('SELECT * FROM chambers') as $c) {
            $states[$subs->state($c)]++;
        }
        $monthStart = (new \DateTimeImmutable('first day of this month 00:00', app_timezone()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $revenueMonth = $db->fetchAll(
            "SELECT currency, SUM(amount) AS total FROM payments WHERE status = 'confirmed' AND reviewed_at >= ? GROUP BY currency",
            [$monthStart]
        );
        $revenueAll = $db->fetchAll("SELECT currency, SUM(amount) AS total FROM payments WHERE status = 'confirmed' GROUP BY currency");

        // Sign-ups per month for the last 12 months.
        $months = [];
        $cursor = new \DateTimeImmutable('first day of this month', app_timezone());
        for ($i = 11; $i >= 0; $i--) {
            $months[$cursor->modify("-{$i} months")->format('Y-m')] = 0;
        }
        foreach ($db->fetchAll('SELECT created_at FROM chambers WHERE created_at >= ?', [now_utc(-400 * 86400)]) as $row) {
            $key = local_time($row['created_at'], 'Y-m');
            if (isset($months[$key])) {
                $months[$key]++;
            }
        }

        $recent = array_map(fn ($c) => $this->decorateWorkspace($c), $db->fetchAll(
            'SELECT c.*, u.full_name AS owner_name, u.email AS owner_email FROM chambers c JOIN users u ON u.id = c.judge_user_id ORDER BY c.id DESC LIMIT 8'
        ));

        return $this->page('admin/index', [
            'title' => 'Overview',
            'userCount' => (int) $db->value('SELECT COUNT(*) FROM users'),
            'unverified' => (int) $db->value('SELECT COUNT(*) FROM users WHERE email_verified_at IS NULL'),
            'workspaceCount' => (int) $db->value('SELECT COUNT(*) FROM chambers'),
            'signups30' => (int) $db->value('SELECT COUNT(*) FROM chambers WHERE created_at >= ?', [now_utc(-30 * 86400)]),
            'states' => $states,
            'revenueMonth' => $revenueMonth,
            'revenueAll' => $revenueAll,
            'months' => $months,
            'recent' => $recent,
            'payments' => $subs->payments(null, 'pending', 10),
        ]);
    }

    // --- Users -------------------------------------------------------------------

    public function users(Request $request): Response
    {
        $q = mb_strtolower(trim((string) $request->input('q')));
        $filter = (string) $request->input('filter');
        $rows = $this->app->cipher()->decryptRows('users', $this->app->db()->fetchAll('SELECT * FROM users ORDER BY id DESC'));

        $memberships = [];
        foreach ($this->app->db()->fetchAll(
            'SELECT m.user_id, m.role, m.status, c.id AS chambers_id, c.name FROM chamber_members m JOIN chambers c ON c.id = m.chambers_id ORDER BY c.name'
        ) as $m) {
            $memberships[(int) $m['user_id']][] = $m;
        }

        $users = [];
        foreach ($rows as $u) {
            $u['memberships'] = $memberships[(int) $u['id']] ?? [];
            if ($q !== '' && !str_contains(mb_strtolower($u['full_name'] . ' ' . $u['email'] . ' ' . implode(' ', array_column($u['memberships'], 'name'))), $q)) {
                continue;
            }
            $match = match ($filter) {
                'owners' => in_array('judge', array_column($u['memberships'], 'role'), true),
                'staff' => array_diff(array_column($u['memberships'], 'role'), ['judge']) !== [],
                'unverified' => $u['email_verified_at'] === null,
                'suspended' => $u['status'] !== 'active',
                'admins' => (int) $u['is_admin'] === 1,
                default => true,
            };
            if ($match) {
                $users[] = $u;
            }
        }
        $total = count($users);
        $pageNo = max(1, (int) $request->input('page', '1'));
        $users = array_slice($users, ($pageNo - 1) * self::PER_PAGE, self::PER_PAGE);

        $data = [
            'title' => 'Registered users',
            'users' => $users,
            'total' => $total,
            'pageNo' => $pageNo,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'q' => (string) $request->input('q'),
            'filter' => $filter,
        ];
        // Live search: the page's script asks for just the table.
        if ($request->input('partial') === '1') {
            return Response::html($this->app->view()->partial('admin/_users_table', $data));
        }
        return $this->page('admin/users', $data);
    }

    public function userStatus(Request $request): Response
    {
        $user = $this->user($request);
        if ((int) $user['id'] === $this->auth()->id()) {
            return $this->redirect('/admin/users', 'You cannot suspend your own account.', 'danger');
        }
        $status = $request->input('status') === 'suspended' ? 'suspended' : 'active';
        $this->userService()->setStatus((int) $user['id'], $status);
        $this->adminLog('admin.user_' . $status, 'user', (int) $user['id']);
        return $this->redirect($this->back($request, '/admin/users'), "{$user['full_name']} is now {$status}.");
    }

    public function userResetMfa(Request $request): Response
    {
        $user = $this->user($request);
        $this->userService()->resetMfa((int) $user['id']);
        $this->adminLog('admin.user_mfa_reset', 'user', (int) $user['id']);
        return $this->redirect($this->back($request, '/admin/users'), "Two-factor authentication reset for {$user['full_name']}. They will scan a new QR code at their next sign-in.");
    }

    public function userVerify(Request $request): Response
    {
        $user = $this->user($request);
        $this->app->db()->run('UPDATE users SET email_verified_at = ? WHERE id = ? AND email_verified_at IS NULL', [now_utc(), (int) $user['id']]);
        $this->adminLog('admin.user_verified', 'user', (int) $user['id']);
        return $this->redirect($this->back($request, '/admin/users'), "E-mail address of {$user['full_name']} marked as confirmed.");
    }

    public function userResetPassword(Request $request): Response
    {
        $user = $this->user($request);
        $temp = UserService::temporaryPassword();
        $this->userService()->resetPassword((int) $user['id'], $temp);
        $this->adminLog('admin.user_password_reset', 'user', (int) $user['id']);
        return $this->redirect($this->back($request, '/admin/users'), "Temporary password for {$user['full_name']}: {$temp} — share it through a secure channel. It must be changed at the next sign-in.");
    }

    // --- Workspaces ----------------------------------------------------------------

    public function workspaces(Request $request): Response
    {
        $q = mb_strtolower(trim((string) $request->input('q')));
        $state = (string) $request->input('state');
        $rows = $this->app->db()->fetchAll(
            'SELECT c.*, u.full_name AS owner_name, u.email AS owner_email,
                    (SELECT COUNT(*) FROM chamber_members m WHERE m.chambers_id = c.id AND m.status = \'active\') AS member_count,
                    (SELECT COUNT(*) FROM cases k WHERE k.chambers_id = c.id) AS case_count
             FROM chambers c JOIN users u ON u.id = c.judge_user_id ORDER BY c.id DESC'
        );
        $workspaces = [];
        foreach ($rows as $row) {
            $w = $this->decorateWorkspace($row);
            if ($q !== '' && !str_contains(mb_strtolower($w['name'] . ' ' . $w['court'] . ' ' . $w['owner_name'] . ' ' . $w['owner_email']), $q)) {
                continue;
            }
            if ($state !== '' && $w['state'] !== $state) {
                continue;
            }
            $workspaces[] = $w;
        }
        return $this->page('admin/workspaces', [
            'title' => 'Workspaces',
            'workspaces' => $workspaces,
            'q' => (string) $request->input('q'),
            'state' => $state,
        ]);
    }

    public function workspace(Request $request): Response
    {
        $row = $this->app->db()->fetch(
            'SELECT c.*, u.full_name AS owner_name, u.email AS owner_email,
                    (SELECT COUNT(*) FROM cases k WHERE k.chambers_id = c.id) AS case_count,
                    (SELECT COUNT(*) FROM hearings h WHERE h.chambers_id = c.id) AS hearing_count,
                    (SELECT COUNT(*) FROM recordings r WHERE r.chambers_id = c.id) AS recording_count
             FROM chambers c JOIN users u ON u.id = c.judge_user_id WHERE c.id = ?',
            [(int) $request->param('id')]
        ) ?? throw new HttpException(404);
        return $this->page('admin/workspace', [
            'title' => $row['name'],
            'w' => $this->decorateWorkspace($row),
            'members' => $this->userService()->members((int) $row['id']),
            'plans' => $this->subs()->plans(false),
            'payments' => $this->subs()->payments((int) $row['id']),
        ]);
    }

    public function workspaceSubscription(Request $request): Response
    {
        $id = (int) $request->param('id');
        $back = "/admin/workspaces/{$id}";
        return $this->attempt($back, function () use ($request, $id, $back): Response {
            if (!$this->app->db()->value('SELECT 1 FROM chambers WHERE id = ?', [$id])) {
                throw new HttpException(404);
            }
            $planId = $request->int('plan_id');
            $this->subs()->adminUpdate($id, $planId, (string) $request->input('subscription_status'), $request->input('current_period_end'), (string) $request->input('billing_cycle'));
            $this->adminLog('admin.subscription_changed', 'chambers', $id, [
                'plan_id' => $planId,
                'status' => $request->input('subscription_status'),
                'period_end' => $request->input('current_period_end'),
            ]);
            return $this->redirect($back, 'Subscription updated.');
        });
    }

    // --- Plans & prices ----------------------------------------------------------------

    public function plans(Request $request): Response
    {
        $usage = [];
        foreach ($this->app->db()->fetchAll('SELECT plan_id, COUNT(*) AS n FROM chambers WHERE plan_id IS NOT NULL GROUP BY plan_id') as $r) {
            $usage[(int) $r['plan_id']] = (int) $r['n'];
        }
        return $this->page('admin/plans', [
            'title' => 'Plans & prices',
            'plans' => $this->subs()->plans(false),
            'usage' => $usage,
            'currency' => $this->app->service(SettingsService::class)->get('currency', 'ZMW'),
        ]);
    }

    public function storePlan(Request $request): Response
    {
        return $this->attempt('/admin/plans', function () use ($request): Response {
            $id = $this->subs()->savePlan($request->post);
            $this->adminLog('admin.plan_created', 'plan', $id, $this->planAudit($request));
            return $this->redirect('/admin/plans', 'Plan created.');
        });
    }

    public function updatePlan(Request $request): Response
    {
        $id = (int) $request->param('id');
        $this->subs()->plan($id) ?? throw new HttpException(404);
        return $this->attempt('/admin/plans', function () use ($request, $id): Response {
            $this->subs()->savePlan($request->post, $id);
            $this->adminLog('admin.plan_updated', 'plan', $id, $this->planAudit($request));
            return $this->redirect('/admin/plans', 'Plan saved. New prices apply to payments submitted from now on.');
        });
    }

    public function deletePlan(Request $request): Response
    {
        $id = (int) $request->param('id');
        return $this->attempt('/admin/plans', function () use ($id): Response {
            $this->subs()->deletePlan($id);
            $this->adminLog('admin.plan_deleted', 'plan', $id);
            return $this->redirect('/admin/plans', 'Plan deleted.');
        });
    }

    // --- Payments ----------------------------------------------------------------------

    public function payments(Request $request): Response
    {
        $status = (string) $request->input('status', 'pending');
        return $this->page('admin/payments', [
            'title' => 'Payments',
            'payments' => $this->subs()->payments(null, $status === 'all' ? null : $status, 500),
            'status' => $status,
        ]);
    }

    public function confirmPayment(Request $request): Response
    {
        $id = (int) $request->param('id');
        return $this->attempt('/admin/payments', function () use ($id, $request): Response {
            $this->subs()->confirmPayment($id, $this->auth()->id());
            $this->adminLog('admin.payment_confirmed', 'payment', $id);
            return $this->redirect($this->back($request, '/admin/payments'), 'Payment confirmed and the subscription extended.');
        });
    }

    public function rejectPayment(Request $request): Response
    {
        $id = (int) $request->param('id');
        return $this->attempt('/admin/payments', function () use ($id, $request): Response {
            $this->subs()->rejectPayment($id, $this->auth()->id(), (string) $request->input('reason'));
            $this->adminLog('admin.payment_rejected', 'payment', $id);
            return $this->redirect($this->back($request, '/admin/payments'), 'Payment rejected. The workspace owner sees the reason on their billing page.');
        });
    }

    // --- Settings ------------------------------------------------------------------------

    public function settings(Request $request): Response
    {
        return $this->page('admin/settings', [
            'title' => 'Platform settings',
            'settings' => $this->app->service(SettingsService::class)->all(),
            'plans' => $this->subs()->plans(false),
        ]);
    }

    public function saveSettings(Request $request): Response
    {
        return $this->attempt('/admin/settings', function () use ($request): Response {
            $settings = $this->app->service(SettingsService::class);
            foreach (['trial_days', 'grace_days'] as $n) {
                $v = (string) $request->input($n);
                if (!ctype_digit($v) || (int) $v > 365) {
                    throw new \InvalidArgumentException('Trial and grace periods must be whole numbers of days (0–365).');
                }
            }
            $currency = strtoupper((string) $request->input('currency'));
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                throw new \InvalidArgumentException('Currency must be a three-letter code such as ZMW.');
            }
            $email = (string) $request->input('support_email');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Enter a valid support e-mail address.');
            }
            if ($this->subs()->planByCode((string) $request->input('trial_plan')) === null) {
                throw new \InvalidArgumentException('Choose the plan used during the trial.');
            }
            $settings->set('signup_enabled', $request->input('signup_enabled') === '1' ? '1' : '0');
            $settings->set('trial_days', (string) $request->input('trial_days'));
            $settings->set('grace_days', (string) $request->input('grace_days'));
            $settings->set('trial_plan', (string) $request->input('trial_plan'));
            $settings->set('currency', $currency);
            $settings->set('support_email', $email);
            $settings->set('payment_instructions', mb_substr(str_replace("\r", '', (string) ($request->post['payment_instructions'] ?? '')), 0, 4000));
            $this->adminLog('admin.settings_changed', null, null);
            return $this->redirect('/admin/settings', 'Settings saved.');
        });
    }

    // --- Helpers -----------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function user(Request $request): array
    {
        return $this->userService()->find((int) $request->param('id')) ?? throw new HttpException(404);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decorateWorkspace(array $row): array
    {
        $crypto = $this->app->crypto();
        $row['owner_name'] = $crypto->decrypt($row['owner_name'], 'users.full_name');
        $row['owner_email'] = $crypto->decrypt($row['owner_email'], 'users.email');
        $row['state'] = $this->subs()->state($row);
        $row['plan'] = $this->subs()->plan(isset($row['plan_id']) ? (int) $row['plan_id'] : null);
        return $row;
    }

    /** @return array<string,mixed> */
    private function planAudit(Request $request): array
    {
        return [
            'code' => $request->input('code'),
            'price_monthly' => $request->input('price_monthly'),
            'price_annual' => $request->input('price_annual'),
            'active' => $request->input('is_active') === '1',
        ];
    }

    private function back(Request $request, string $default): string
    {
        $back = (string) $request->input('back');
        return str_starts_with($back, '/admin') && !str_contains($back, '//') ? $back : $default;
    }

    /** Admin actions are platform events, not any workspace's. */
    private function adminLog(string $action, ?string $entityType, ?int $entityId, array $details = []): void
    {
        $this->app->audit()->record($action, $this->auth()->id(), null, $entityType, $entityId, $details, $this->app->request()?->ip());
    }

    public static function roleSummary(array $memberships): string
    {
        return implode(', ', array_map(static fn ($m) => Permissions::ROLES[$m['role']] . ' · ' . $m['name'], $memberships));
    }
}
