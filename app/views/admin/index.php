<?php
use JC\Service\SubscriptionService;

$maxMonth = max(1, ...array_values($months));
$stateBadge = ['trial' => 'text-bg-info', 'active' => 'text-bg-success', 'grace' => 'text-bg-warning', 'expired' => 'text-bg-danger', 'cancelled' => 'text-bg-secondary'];
$fmt = static fn (array $rows) => $rows ? implode(' · ', array_map(static fn ($r) => money($r['total'], $r['currency']), $rows)) : '—';
?>
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><a class="text-decoration-none" href="/admin/users"><div class="card jc-stat"><div class="card-body"><div class="jc-stat-value"><?= (int) $userCount ?></div><div class="jc-stat-label">Registered users<?= $unverified ? ' · ' . (int) $unverified . ' unconfirmed' : '' ?></div></div></div></a></div>
    <div class="col-6 col-lg-3"><a class="text-decoration-none" href="/admin/workspaces"><div class="card jc-stat"><div class="card-body"><div class="jc-stat-value"><?= (int) $workspaceCount ?></div><div class="jc-stat-label">Workspaces · <?= (int) $signups30 ?> new in 30 days</div></div></div></a></div>
    <div class="col-6 col-lg-3"><div class="card jc-stat"><div class="card-body"><div class="jc-stat-value fs-4"><?= e($fmt($revenueMonth)) ?></div><div class="jc-stat-label">Confirmed this month</div></div></div></div>
    <div class="col-6 col-lg-3"><a class="text-decoration-none" href="/admin/payments"><div class="card jc-stat"><div class="card-body"><div class="jc-stat-value"><?= (int) $pendingPayments ?></div><div class="jc-stat-label">Payments to confirm</div></div></div></a></div>
</div>

<div class="row g-4">
    <div class="col-xl-7">
        <div class="card jc-card mb-4">
            <div class="card-header">New workspaces per month</div>
            <div class="card-body">
                <div class="jc-cols" role="img" aria-label="New workspaces per month for the last 12 months">
                    <?php foreach ($months as $ym => $n): ?>
                        <div><b><?= (int) $n ?></b><span data-h="<?= round($n / $maxMonth * 85) ?>"></span><?= e(date('M', strtotime($ym . '-01'))) ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="card jc-card">
            <div class="card-header d-flex justify-content-between"><span>Latest workspaces</span><a class="small" href="/admin/workspaces">All</a></div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Workspace</th><th>Owner</th><th>Plan</th><th>Status</th><th>Created</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent as $w): ?>
                        <tr>
                            <td><a class="fw-semibold" href="/admin/workspaces/<?= (int) $w['id'] ?>"><?= e($w['name']) ?></a><div class="small text-muted"><?= e($w['court']) ?></div></td>
                            <td class="small"><?= e($w['owner_name']) ?><div class="text-muted"><?= e($w['owner_email']) ?></div></td>
                            <td class="small"><?= e($w['plan']['name'] ?? '—') ?></td>
                            <td><span class="badge <?= $stateBadge[$w['state']] ?? '' ?>"><?= e(SubscriptionService::STATES[$w['state']] ?? $w['state']) ?></span></td>
                            <td class="small text-nowrap"><?= e(local_time($w['created_at'], 'j M Y')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card jc-card mb-4">
            <div class="card-header">Subscriptions</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($states as $s => $n): ?>
                    <a class="list-group-item list-group-item-action d-flex justify-content-between" href="/admin/workspaces?state=<?= e($s) ?>">
                        <span><span class="badge <?= $stateBadge[$s] ?? '' ?> me-2">&nbsp;</span><?= e(SubscriptionService::STATES[$s]) ?></span><span class="fw-semibold"><?= (int) $n ?></span>
                    </a>
                <?php endforeach; ?>
            </ul>
            <div class="card-footer small text-muted">All-time confirmed revenue: <?= e($fmt($revenueAll)) ?></div>
        </div>
        <div class="card jc-card">
            <div class="card-header d-flex justify-content-between"><span>Payments to confirm</span><a class="small" href="/admin/payments">All payments</a></div>
            <div class="list-group list-group-flush">
                <?php if (!$payments): ?><div class="list-group-item text-muted small">Nothing waiting.</div><?php endif; ?>
                <?php foreach ($payments as $p): ?>
                    <a class="list-group-item list-group-item-action small d-flex justify-content-between" href="/admin/payments">
                        <span><span class="fw-semibold"><?= e($p['chambers_name']) ?></span><span class="d-block text-muted"><?= e($p['plan_name']) ?> · <?= e(SubscriptionService::METHODS[$p['method']] ?? '') ?> · <?= e($p['reference']) ?></span></span>
                        <span class="fw-semibold text-nowrap"><?= e(money($p['amount'], $p['currency'])) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
