<?php

declare(strict_types=1);

namespace JC\Service;

use JC\Core\App;

/**
 * Subscription plans and each workspace's subscription.
 *
 * A workspace (chambers) is on a plan with a billing cycle and a paid-up date
 * (current_period_end). New self-service workspaces start on a free trial.
 * Payments are submitted by the workspace owner (mobile money or bank
 * reference) and confirmed by a platform administrator, which extends the
 * paid-up date by one cycle.
 *
 * When the paid-up date passes there is a grace period; after it the workspace
 * becomes read-only. Nothing is ever deleted: the owner can still read and
 * export their data and renew at any time. Workspaces with no end date (set
 * up by an institution administrator) never lapse.
 */
final class SubscriptionService
{
    public const CYCLES = ['monthly' => 'Monthly', 'annual' => 'Annual'];

    public const METHODS = [
        'airtel_money' => 'Airtel Money',
        'mtn_momo' => 'MTN MoMo',
        'zamtel_kwacha' => 'Zamtel Kwacha',
        'bank_transfer' => 'Bank transfer',
        'card' => 'Card',
        'cash' => 'Cash / cheque',
        'other' => 'Other',
    ];

    public const STATES = [
        'trial' => 'Free trial',
        'active' => 'Active',
        'grace' => 'Payment overdue',
        'expired' => 'Lapsed (read-only)',
        'cancelled' => 'Cancelled (read-only)',
    ];

    public function __construct(private readonly App $app)
    {
    }

    private function settings(): SettingsService
    {
        return $this->app->service(SettingsService::class);
    }

    // --- Plans ----------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function plans(bool $activeOnly = true): array
    {
        $rows = $this->app->db()->fetchAll(
            'SELECT * FROM plans' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, price_monthly, id'
        );
        return array_map([$this, 'decoratePlan'], $rows);
    }

    /** @return array<string,mixed>|null */
    public function plan(?int $id): ?array
    {
        if ($id === null) {
            return null;
        }
        $row = $this->app->db()->fetch('SELECT * FROM plans WHERE id = ?', [$id]);
        return $row ? $this->decoratePlan($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function planByCode(string $code): ?array
    {
        $row = $this->app->db()->fetch('SELECT * FROM plans WHERE code = ?', [$code]);
        return $row ? $this->decoratePlan($row) : null;
    }

    /** @param array<string,mixed> $data */
    public function savePlan(array $data, ?int $id = null): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        $code = strtolower(trim((string) ($data['code'] ?? '')));
        if ($name === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $code)) {
            throw new \InvalidArgumentException('A plan needs a name and a short code (letters, digits, - or _).');
        }
        $monthly = $this->amount($data['price_monthly'] ?? '');
        $annual = $this->amount($data['price_annual'] ?? '');
        $existing = $this->app->db()->value('SELECT id FROM plans WHERE code = ?', [$code]);
        if ($existing && (int) $existing !== $id) {
            throw new \InvalidArgumentException('Another plan already uses that code.');
        }
        $maxStaff = trim((string) ($data['max_staff'] ?? ''));
        $currency = strtoupper(trim((string) ($data['currency'] ?? ''))) ?: ($this->settings()->get('currency', 'ZMW') ?? 'ZMW');
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('Currency must be a three-letter code such as ZMW or USD.');
        }
        $row = [
            'code' => $code,
            'name' => mb_substr($name, 0, 120),
            'description' => mb_substr(trim((string) ($data['description'] ?? '')), 0, 500) ?: null,
            'price_monthly' => $monthly,
            'price_annual' => $annual,
            'currency' => $currency,
            'max_staff' => $maxStaff === '' ? null : max(0, (int) $maxStaff),
            'includes_transcription' => !empty($data['includes_transcription']) ? 1 : 0,
            'features' => trim(str_replace("\r", '', (string) ($data['features'] ?? ''))) ?: null,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'is_featured' => !empty($data['is_featured']) ? 1 : 0,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'updated_at' => now_utc(),
        ];
        if ($id !== null) {
            $this->app->db()->update('plans', $row, ['id' => $id]);
            return $id;
        }
        return $this->app->db()->insert('plans', $row + ['created_at' => now_utc()]);
    }

    public function deletePlan(int $id): void
    {
        $used = (int) $this->app->db()->value('SELECT COUNT(*) FROM chambers WHERE plan_id = ?', [$id])
            + (int) $this->app->db()->value('SELECT COUNT(*) FROM payments WHERE plan_id = ?', [$id]);
        if ($used > 0) {
            throw new \InvalidArgumentException('This plan is in use by workspaces or payments. Deactivate it instead, so nobody new can choose it.');
        }
        $this->app->db()->run('DELETE FROM plans WHERE id = ?', [$id]);
    }

    public function price(array $plan, string $cycle): float
    {
        return (float) ($cycle === 'annual' ? $plan['price_annual'] : $plan['price_monthly']);
    }

    // --- Workspace subscription -----------------------------------------------

    public function startTrial(int $chambersId): void
    {
        $plan = $this->planByCode((string) $this->settings()->get('trial_plan', 'chambers')) ?? ($this->plans()[0] ?? null);
        $ends = now_utc(max(1, $this->settings()->int('trial_days', 30)) * 86400);
        $this->app->db()->update('chambers', [
            'plan_id' => $plan['id'] ?? null,
            'subscription_status' => 'trial',
            'trial_ends_at' => $ends,
            'current_period_end' => $ends,
        ], ['id' => $chambersId]);
    }

    /**
     * The workspace's effective state, derived from the stored status and dates.
     *
     * @param array<string,mixed> $chambers
     */
    public function state(array $chambers): string
    {
        $stored = (string) ($chambers['subscription_status'] ?? 'active');
        if ($stored === 'cancelled') {
            return 'cancelled';
        }
        $end = $chambers['current_period_end'] ?? null;
        if ($end === null || $end === '') {
            return $stored === 'trial' ? 'trial' : 'active';
        }
        $now = now_utc();
        if ($end > $now) {
            return $stored === 'trial' ? 'trial' : 'active';
        }
        // A trial that ends has no grace period: there was nothing paid to be overdue on.
        if ($stored !== 'trial') {
            $graceEnd = gmdate('Y-m-d H:i:s', strtotime($end . ' UTC') + max(0, $this->settings()->int('grace_days', 7)) * 86400);
            if ($graceEnd > $now) {
                return 'grace';
            }
        }
        return 'expired';
    }

    /** @param array<string,mixed> $chambers */
    public function isWritable(array $chambers): bool
    {
        return !in_array($this->state($chambers), ['expired', 'cancelled'], true);
    }

    /** @param array<string,mixed> $chambers */
    public function daysLeft(array $chambers): ?int
    {
        $end = $chambers['current_period_end'] ?? null;
        if ($end === null || $end === '') {
            return null;
        }
        return (int) floor((strtotime($end . ' UTC') - time()) / 86400);
    }

    /**
     * A one-line status for the banner shown to the workspace, or null when
     * nothing needs saying.
     *
     * @param array<string,mixed> $chambers
     */
    public function notice(array $chambers): ?array
    {
        $state = $this->state($chambers);
        $days = $this->daysLeft($chambers);
        return match (true) {
            $state === 'trial' && $days !== null && $days <= 7 => ['warning', $days <= 0 ? 'Your free trial ends today.' : "Your free trial ends in {$days} day" . ($days === 1 ? '' : 's') . '.'],
            $state === 'grace' => ['warning', 'Your subscription payment is overdue. Renew now to keep the workspace editable.'],
            $state === 'expired' => ['danger', 'This workspace\'s subscription has lapsed, so it is read-only. Nothing has been deleted.'],
            $state === 'cancelled' => ['danger', 'This workspace\'s subscription is cancelled, so it is read-only. Nothing has been deleted.'],
            default => null,
        };
    }

    /**
     * Staff (everyone except the owner) the plan allows; null = unlimited.
     *
     * @param array<string,mixed> $chambers
     */
    public function staffLimit(array $chambers): ?int
    {
        $plan = $this->plan(isset($chambers['plan_id']) ? (int) $chambers['plan_id'] : null);
        return $plan === null || $plan['max_staff'] === null ? null : (int) $plan['max_staff'];
    }

    /** @param array<string,mixed> $chambers */
    public function canAddStaff(array $chambers): bool
    {
        $limit = $this->staffLimit($chambers);
        if ($limit === null) {
            return true;
        }
        $count = (int) $this->app->db()->value(
            "SELECT COUNT(*) FROM chamber_members WHERE chambers_id = ? AND role <> 'judge' AND status = 'active'",
            [(int) $chambers['id']]
        );
        return $count < $limit;
    }

    /** @param array<string,mixed> $chambers */
    public function includesTranscription(array $chambers): bool
    {
        $plan = $this->plan(isset($chambers['plan_id']) ? (int) $chambers['plan_id'] : null);
        return $plan === null || (int) $plan['includes_transcription'] === 1;
    }

    // --- Payments ---------------------------------------------------------------

    public function submitPayment(int $chambersId, int $userId, int $planId, string $cycle, string $method, string $reference, ?string $notes = null): int
    {
        $plan = $this->plan($planId);
        if ($plan === null || (int) $plan['is_active'] !== 1) {
            throw new \InvalidArgumentException('Choose an available plan.');
        }
        if (!isset(self::CYCLES[$cycle])) {
            throw new \InvalidArgumentException('Choose monthly or annual billing.');
        }
        if (!isset(self::METHODS[$method])) {
            throw new \InvalidArgumentException('Choose how you paid.');
        }
        $reference = trim($reference);
        if (mb_strlen($reference) < 4) {
            throw new \InvalidArgumentException('Enter the transaction or deposit reference so we can match your payment.');
        }
        if ($this->app->db()->value("SELECT 1 FROM payments WHERE chambers_id = ? AND status = 'pending'", [$chambersId])) {
            throw new \InvalidArgumentException('You already have a payment awaiting confirmation. We will confirm it shortly.');
        }
        return $this->app->db()->insert('payments', [
            'chambers_id' => $chambersId,
            'plan_id' => $planId,
            'billing_cycle' => $cycle,
            'amount' => number_format($this->price($plan, $cycle), 2, '.', ''),
            'currency' => $plan['currency'],
            'method' => $method,
            'reference' => mb_substr($reference, 0, 190),
            'status' => 'pending',
            'notes' => $notes !== null && trim($notes) !== '' ? mb_substr(trim($notes), 0, 1000) : null,
            'submitted_by' => $userId,
            'created_at' => now_utc(),
        ]);
    }

    /**
     * Confirms a payment and extends the workspace by one billing cycle, from
     * the current paid-up date if it is still in the future, otherwise from now.
     */
    public function confirmPayment(int $paymentId, int $adminId): void
    {
        $db = $this->app->db();
        $db->transaction(function () use ($db, $paymentId, $adminId): void {
            $payment = $db->fetch('SELECT * FROM payments WHERE id = ?' . $db->forUpdate(), [$paymentId]);
            if ($payment === null || $payment['status'] !== 'pending') {
                throw new \InvalidArgumentException('Only pending payments can be confirmed.');
            }
            $chambers = $db->fetch('SELECT * FROM chambers WHERE id = ?' . $db->forUpdate(), [(int) $payment['chambers_id']]);
            $now = now_utc();
            $paidUp = $chambers['current_period_end'] ?? null;
            // Remaining trial or paid time is kept: the new cycle starts when the current one ends.
            $start = ($chambers['subscription_status'] !== 'cancelled' && $paidUp !== null && $paidUp > $now) ? $paidUp : $now;
            $end = self::addCycle($start, (string) $payment['billing_cycle']);
            $db->update('payments', [
                'status' => 'confirmed',
                'period_start' => $start,
                'period_end' => $end,
                'reviewed_by' => $adminId,
                'reviewed_at' => $now,
            ], ['id' => $paymentId]);
            $db->update('chambers', [
                'plan_id' => (int) $payment['plan_id'],
                'billing_cycle' => $payment['billing_cycle'],
                'subscription_status' => 'active',
                'current_period_end' => $end,
            ], ['id' => (int) $payment['chambers_id']]);
        });
    }

    public function rejectPayment(int $paymentId, int $adminId, string $reason): void
    {
        $affected = $this->app->db()->update('payments', [
            'status' => 'rejected',
            'notes' => mb_substr(trim($reason), 0, 1000) ?: 'Payment could not be matched.',
            'reviewed_by' => $adminId,
            'reviewed_at' => now_utc(),
        ], ['id' => $paymentId, 'status' => 'pending']);
        if ($affected === 0) {
            throw new \InvalidArgumentException('Only pending payments can be rejected.');
        }
    }

    /**
     * Administrator override: set plan, status and paid-up date directly
     * (complimentary access, corrections, cancellations).
     */
    public function adminUpdate(int $chambersId, ?int $planId, string $status, ?string $periodEnd, string $cycle): void
    {
        if (!in_array($status, ['trial', 'active', 'cancelled'], true)) {
            throw new \InvalidArgumentException('Unknown subscription status.');
        }
        if ($planId !== null && $this->plan($planId) === null) {
            throw new \InvalidArgumentException('Unknown plan.');
        }
        $end = null;
        if ($periodEnd !== null && $periodEnd !== '') {
            $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $periodEnd, app_timezone());
            if ($dt === false) {
                throw new \InvalidArgumentException('Paid-up date must be a valid date.');
            }
            // End of that day, local time.
            $end = $dt->modify('+1 day -1 second')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        $this->app->db()->update('chambers', [
            'plan_id' => $planId,
            'subscription_status' => $status,
            'billing_cycle' => isset(self::CYCLES[$cycle]) ? $cycle : 'monthly',
            'current_period_end' => $end,
            'trial_ends_at' => $status === 'trial' ? $end : null,
        ], ['id' => $chambersId]);
    }

    /** @return list<array<string,mixed>> */
    public function payments(?int $chambersId = null, ?string $status = null, int $limit = 200): array
    {
        $sql = 'SELECT p.*, pl.name AS plan_name, c.name AS chambers_name, c.court FROM payments p
                JOIN plans pl ON pl.id = p.plan_id JOIN chambers c ON c.id = p.chambers_id WHERE 1 = 1';
        $params = [];
        if ($chambersId !== null) {
            $sql .= ' AND p.chambers_id = ?';
            $params[] = $chambersId;
        }
        if ($status !== null && $status !== '') {
            $sql .= ' AND p.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY p.id DESC LIMIT ' . max(1, $limit);
        return $this->app->db()->fetchAll($sql, $params);
    }

    public static function addCycle(string $fromUtc, string $cycle): string
    {
        $dt = new \DateTimeImmutable($fromUtc, new \DateTimeZone('UTC'));
        return $dt->modify($cycle === 'annual' ? '+1 year' : '+1 month')->format('Y-m-d H:i:s');
    }

    private function amount(mixed $value): string
    {
        $value = str_replace([',', ' '], '', trim((string) $value));
        if ($value === '' || !is_numeric($value) || (float) $value < 0) {
            throw new \InvalidArgumentException('Prices must be zero or a positive amount.');
        }
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decoratePlan(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['feature_list'] = array_values(array_filter(array_map('trim', explode("\n", (string) ($row['features'] ?? '')))));
        return $row;
    }
}
