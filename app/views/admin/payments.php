<?php
use JC\Service\SubscriptionService;

$payBadge = ['pending' => 'text-bg-warning', 'confirmed' => 'text-bg-success', 'rejected' => 'text-bg-danger'];
?>
<div class="btn-group btn-group-sm mb-3">
    <?php foreach (['pending' => 'To confirm', 'confirmed' => 'Confirmed', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $v): ?>
        <a class="btn btn-outline-secondary<?= $status === $k ? ' active' : '' ?>" href="/admin/payments?status=<?= e($k) ?>"><?= e($v) ?></a>
    <?php endforeach; ?>
</div>
<div class="card jc-card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>Submitted</th><th>Workspace</th><th>Plan</th><th>Amount</th><th>Method &amp; reference</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if (!$payments): ?><tr><td colspan="7" class="text-center text-muted py-4">No payments.</td></tr><?php endif; ?>
            <?php foreach ($payments as $p): ?>
                <tr>
                    <td class="small text-nowrap"><?= e(local_time($p['created_at'], 'j M Y H:i')) ?></td>
                    <td><a href="/admin/workspaces/<?= (int) $p['chambers_id'] ?>" class="fw-semibold"><?= e($p['chambers_name']) ?></a><div class="small text-muted"><?= e($p['court']) ?></div></td>
                    <td class="small"><?= e($p['plan_name']) ?> · <?= e(SubscriptionService::CYCLES[$p['billing_cycle']] ?? '') ?></td>
                    <td class="text-nowrap fw-semibold"><?= e(money($p['amount'], $p['currency'])) ?></td>
                    <td class="small"><?= e(SubscriptionService::METHODS[$p['method']] ?? $p['method']) ?><div class="font-monospace"><?= e($p['reference']) ?></div><?php if ($p['notes']): ?><div class="text-muted"><?= e($p['notes']) ?></div><?php endif; ?></td>
                    <td>
                        <span class="badge <?= $payBadge[$p['status']] ?? '' ?>"><?= e(label($p['status'])) ?></span>
                        <?php if ($p['period_end']): ?><div class="small text-muted text-nowrap">to <?= e(local_time($p['period_end'], 'j M Y')) ?></div><?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if ($p['status'] === 'pending'): ?>
                            <div class="d-flex flex-column gap-1 align-items-end">
                                <form method="post" action="/admin/payments/<?= (int) $p['id'] ?>/confirm" data-confirm="Confirm <?= e(money($p['amount'], $p['currency'])) ?> received (reference <?= e($p['reference']) ?>)? The subscription is extended by one <?= $p['billing_cycle'] === 'annual' ? 'year' : 'month' ?>.">
                                    <?= $view->csrfField() ?><input type="hidden" name="back" value="/admin/payments?status=<?= e($status) ?>">
                                    <button class="btn btn-sm btn-success" type="submit"><i class="bi bi-check2"></i> Confirm</button>
                                </form>
                                <form method="post" action="/admin/payments/<?= (int) $p['id'] ?>/reject" class="d-flex gap-1" data-confirm="Reject this payment? The owner will see your reason." data-danger>
                                    <?= $view->csrfField() ?><input type="hidden" name="back" value="/admin/payments?status=<?= e($status) ?>">
                                    <input class="form-control form-control-sm" name="reason" placeholder="Reason" maxlength="300" required aria-label="Reason for rejecting">
                                    <button class="btn btn-sm btn-outline-danger" type="submit">Reject</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
