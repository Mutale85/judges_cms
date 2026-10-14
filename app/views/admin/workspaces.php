<?php
use JC\Service\SubscriptionService;

$stateBadge = ['trial' => 'text-bg-info', 'active' => 'text-bg-success', 'grace' => 'text-bg-warning', 'expired' => 'text-bg-danger', 'cancelled' => 'text-bg-secondary'];
?>
<form class="row g-2 mb-3" method="get" action="/admin/workspaces" data-no-ajax>
    <div class="col-md-6"><input type="search" class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search workspace, court or owner" aria-label="Search"></div>
    <div class="col-md-3">
        <select class="form-select" name="state" aria-label="Subscription" data-autosubmit>
            <option value="">All subscriptions</option>
            <?php foreach (SubscriptionService::STATES as $k => $v): ?><option value="<?= e($k) ?>" <?= $state === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2 d-grid"><button class="btn btn-outline-secondary" type="submit">Filter</button></div>
</form>
<div class="card jc-card">
    <div class="card-header small text-muted"><?= count($workspaces) ?> workspace<?= count($workspaces) === 1 ? '' : 's' ?></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Workspace</th><th>Owner</th><th>Type</th><th>Plan</th><th>Subscription</th><th>Paid up to</th><th class="text-end">People</th><th class="text-end">Matters</th></tr></thead>
            <tbody>
            <?php if (!$workspaces): ?><tr><td colspan="8" class="text-center text-muted py-4">No workspaces match.</td></tr><?php endif; ?>
            <?php foreach ($workspaces as $w): ?>
                <tr>
                    <td><a class="fw-semibold" href="/admin/workspaces/<?= (int) $w['id'] ?>"><?= e($w['name']) ?></a><div class="small text-muted"><?= e($w['court']) ?></div></td>
                    <td class="small"><?= e($w['owner_name']) ?><div class="text-muted"><?= e($w['owner_email']) ?></div></td>
                    <td class="small"><?= $w['mode'] === 'arbitration' ? 'Arbitration' : 'Court' ?></td>
                    <td class="small"><?= e($w['plan']['name'] ?? '—') ?><?= $w['plan'] ? '<div class="text-muted">' . e(SubscriptionService::CYCLES[$w['billing_cycle']] ?? '') . '</div>' : '' ?></td>
                    <td><span class="badge <?= $stateBadge[$w['state']] ?? '' ?>"><?= e(SubscriptionService::STATES[$w['state']] ?? $w['state']) ?></span></td>
                    <td class="small text-nowrap"><?= $w['current_period_end'] ? e(local_time($w['current_period_end'], 'j M Y')) : 'No end date' ?></td>
                    <td class="text-end"><?= (int) $w['member_count'] ?></td>
                    <td class="text-end"><?= (int) $w['case_count'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
