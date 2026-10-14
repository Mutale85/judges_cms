<?php
use JC\Security\Permissions;
use JC\Service\SubscriptionService;

$stateBadge = ['trial' => 'text-bg-info', 'active' => 'text-bg-success', 'grace' => 'text-bg-warning', 'expired' => 'text-bg-danger', 'cancelled' => 'text-bg-secondary'];
$payBadge = ['pending' => 'text-bg-warning', 'confirmed' => 'text-bg-success', 'rejected' => 'text-bg-danger'];
$storedStatus = in_array($w['subscription_status'], ['trial', 'active', 'cancelled'], true) ? $w['subscription_status'] : 'active';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="/admin/workspaces">Workspaces</a></li><li class="breadcrumb-item active"><?= e($w['name']) ?></li></ol></nav>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h3 mb-0"><?= e($w['name']) ?></h1>
        <div class="text-muted"><?= e($w['court']) ?><?= $w['division'] ? ' · ' . e($w['division']) : '' ?><?= $w['location'] ? ' · ' . e($w['location']) : '' ?> · <?= $w['mode'] === 'arbitration' ? 'Arbitration' : 'Court' ?> workspace</div>
    </div>
    <span class="badge fs-6 <?= $stateBadge[$w['state']] ?? '' ?>"><?= e(SubscriptionService::STATES[$w['state']] ?? $w['state']) ?></span>
</div>

<div class="row g-4">
    <div class="col-xl-7">
        <div class="row g-3 mb-4">
            <div class="col-4"><div class="card jc-stat"><div class="card-body"><div class="jc-stat-value"><?= (int) $w['case_count'] ?></div><div class="jc-stat-label">Matters</div></div></div></div>
            <div class="col-4"><div class="card jc-stat"><div class="card-body"><div class="jc-stat-value"><?= (int) $w['hearing_count'] ?></div><div class="jc-stat-label">Hearings</div></div></div></div>
            <div class="col-4"><div class="card jc-stat"><div class="card-body"><div class="jc-stat-value"><?= (int) $w['recording_count'] ?></div><div class="jc-stat-label">Recordings</div></div></div></div>
        </div>
        <div class="card jc-card mb-4">
            <div class="card-header">People</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Name</th><th>Role</th><th>2FA</th><th>Status</th><th>Last sign-in</th></tr></thead>
                    <tbody>
                    <?php foreach ($members as $m): ?>
                        <tr>
                            <td><div class="fw-semibold"><?= e($m['full_name']) ?></div><div class="small text-muted"><?= e($m['email']) ?></div></td>
                            <td class="small"><?= e(Permissions::ROLES[$m['role']] ?? $m['role']) ?></td>
                            <td><?= (int) $m['mfa_enabled'] === 1 ? '<i class="bi bi-shield-fill-check text-success" title="Enabled"></i>' : '<i class="bi bi-shield-exclamation text-warning" title="Not set up"></i>' ?></td>
                            <td><span class="badge <?= $m['status'] === 'active' && $m['user_status'] === 'active' ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= e(label($m['user_status'] !== 'active' ? $m['user_status'] : $m['status'])) ?></span></td>
                            <td class="small text-nowrap"><?= $m['last_login_at'] ? e(local_time($m['last_login_at'], 'j M Y H:i')) : 'Never' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer small text-muted">Manage individual accounts from <a href="/admin/users?q=<?= e(rawurlencode($w['owner_email'])) ?>">Registered users</a>. Case content is not visible to administrators.</div>
        </div>
        <div class="card jc-card">
            <div class="card-header">Payments</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0 small">
                    <thead><tr><th>Submitted</th><th>Plan</th><th>Amount</th><th>Reference</th><th>Status</th><th>Covers</th></tr></thead>
                    <tbody>
                    <?php if (!$payments): ?><tr><td colspan="6" class="text-muted text-center py-3">No payments.</td></tr><?php endif; ?>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td class="text-nowrap"><?= e(local_time($p['created_at'], 'j M Y')) ?></td>
                            <td><?= e($p['plan_name']) ?> · <?= e(SubscriptionService::CYCLES[$p['billing_cycle']] ?? '') ?></td>
                            <td class="text-nowrap"><?= e(money($p['amount'], $p['currency'])) ?></td>
                            <td><?= e(SubscriptionService::METHODS[$p['method']] ?? $p['method']) ?><div class="text-muted"><?= e($p['reference']) ?></div></td>
                            <td><span class="badge <?= $payBadge[$p['status']] ?? '' ?>"><?= e(label($p['status'])) ?></span></td>
                            <td class="text-nowrap"><?= $p['period_end'] ? e(local_time($p['period_start'], 'j M Y')) . ' – ' . e(local_time($p['period_end'], 'j M Y')) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card jc-card mb-4">
            <div class="card-header">Owner</div>
            <div class="card-body">
                <div class="fw-semibold"><?= e($w['owner_name']) ?></div>
                <div class="text-muted small"><?= e($w['owner_email']) ?></div>
                <div class="small mt-2">Workspace created <?= e(local_time($w['created_at'], 'j M Y')) ?><?= (int) $w['self_registered'] === 1 ? ' by self-service sign-up' : ' by an administrator' ?>.</div>
            </div>
        </div>
        <div class="card jc-card">
            <div class="card-header">Subscription override</div>
            <div class="card-body">
                <form method="post" action="/admin/workspaces/<?= (int) $w['id'] ?>/subscription" data-confirm="Change this workspace's subscription?">
                    <?= $view->csrfField() ?>
                    <div class="mb-2">
                        <label class="form-label small" for="plan_id">Plan</label>
                        <select class="form-select" id="plan_id" name="plan_id">
                            <option value="">No plan (institutional / unlimited)</option>
                            <?php foreach ($plans as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) ($w['plan_id'] ?? 0) === $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?><?= (int) $p['is_active'] === 1 ? '' : ' (inactive)' ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small" for="subscription_status">Status</label>
                            <select class="form-select" id="subscription_status" name="subscription_status">
                                <?php foreach (['trial' => 'Free trial', 'active' => 'Active', 'cancelled' => 'Cancelled (read-only)'] as $k => $v): ?><option value="<?= e($k) ?>" <?= $storedStatus === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small" for="billing_cycle">Billing</label>
                            <select class="form-select" id="billing_cycle" name="billing_cycle">
                                <?php foreach (SubscriptionService::CYCLES as $k => $v): ?><option value="<?= e($k) ?>" <?= $w['billing_cycle'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small" for="current_period_end">Paid up to / trial ends</label>
                        <input class="form-control" type="date" id="current_period_end" name="current_period_end" value="<?= $w['current_period_end'] ? e(local_time($w['current_period_end'], 'Y-m-d')) : '' ?>">
                        <div class="form-text">Leave empty for no end date (complimentary or institutional access).</div>
                    </div>
                    <button class="btn btn-dark w-100" type="submit">Save subscription</button>
                </form>
            </div>
        </div>
    </div>
</div>
