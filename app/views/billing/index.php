<?php
use JC\Core\Terminology;
use JC\Service\SubscriptionService;

$badge = ['trial' => 'text-bg-info', 'active' => 'text-bg-success', 'grace' => 'text-bg-warning', 'expired' => 'text-bg-danger', 'cancelled' => 'text-bg-secondary'];
$payBadge = ['pending' => 'text-bg-warning', 'confirmed' => 'text-bg-success', 'rejected' => 'text-bg-danger'];
$hasPending = (bool) array_filter($payments, static fn ($p) => $p['status'] === 'pending');
?>
<h1 class="h3 mb-3">Workspace &amp; billing</h1>

<div class="row g-4">
    <div class="col-xl-7">
        <div class="card jc-card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Subscription</span>
                <span class="badge <?= $badge[$state] ?? 'text-bg-light' ?>"><?= e(SubscriptionService::STATES[$state] ?? $state) ?></span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-4"><div class="text-muted small">Plan</div><div class="fw-semibold"><?= e($plan['name'] ?? 'Institutional (no plan)') ?></div></div>
                    <div class="col-sm-4">
                        <div class="text-muted small"><?= $state === 'trial' ? 'Trial ends' : 'Paid up to' ?></div>
                        <div class="fw-semibold"><?= $chambers['current_period_end'] ? e(local_time($chambers['current_period_end'], 'j M Y')) : 'No end date' ?></div>
                        <?php if ($daysLeft !== null && $daysLeft >= 0): ?><div class="small text-muted"><?= (int) $daysLeft ?> day<?= $daysLeft === 1 ? '' : 's' ?> left</div><?php endif; ?>
                    </div>
                    <div class="col-sm-4"><div class="text-muted small">Staff allowed</div><div class="fw-semibold"><?= $staffLimit === null ? 'Unlimited' : (int) $staffLimit ?></div></div>
                </div>
                <?php if (in_array($state, ['expired', 'cancelled'], true)): ?>
                    <div class="alert alert-danger small mt-3 mb-0">The workspace is read-only. Everything remains viewable and exportable; submit a payment below to restore full access.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card jc-card mb-4">
            <div class="card-header">Renew or change plan</div>
            <div class="card-body">
                <?php if ($instructions): ?><div class="alert alert-light border small"><?= nl2br(e($instructions)) ?></div><?php endif; ?>
                <?php if ($hasPending): ?>
                    <p class="text-muted mb-0"><i class="bi bi-hourglass-split"></i> A payment is awaiting confirmation. We will extend your subscription as soon as it is matched.</p>
                <?php elseif (!$plans): ?>
                    <p class="text-muted mb-0">No plans are available at the moment. Please contact support.</p>
                <?php else: ?>
                    <form method="post" action="/billing/pay" id="pay-form">
                        <?= $view->csrfField() ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="plan_id">Plan</label>
                                <select class="form-select" id="plan_id" name="plan_id" required>
                                    <?php foreach ($plans as $p): ?>
                                        <option value="<?= (int) $p['id'] ?>" data-monthly="<?= e(money($p['price_monthly'], $p['currency'])) ?>" data-annual="<?= e(money($p['price_annual'], $p['currency'])) ?>" <?= (int) ($chambers['plan_id'] ?? 0) === $p['id'] ? 'selected' : '' ?>>
                                            <?= e($p['name']) ?> — <?= $p['max_staff'] === null ? 'unlimited staff' : 'up to ' . (int) $p['max_staff'] . ' staff' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <div class="form-label">Billing</div>
                                <div class="btn-group w-100" role="group">
                                    <input type="radio" class="btn-check" name="billing_cycle" id="cycle-m" value="monthly" <?= ($chambers['billing_cycle'] ?? 'monthly') !== 'annual' ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-dark" for="cycle-m">Monthly</label>
                                    <input type="radio" class="btn-check" name="billing_cycle" id="cycle-a" value="annual" <?= ($chambers['billing_cycle'] ?? '') === 'annual' ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-dark" for="cycle-a">Annual</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="method">Paid by</label>
                                <select class="form-select" id="method" name="method" required>
                                    <?php foreach (SubscriptionService::METHODS as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="reference">Transaction / deposit reference</label>
                                <input class="form-control" id="reference" name="reference" required minlength="4" maxlength="190" placeholder="e.g. MP260914.1532.A12345">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="notes">Note (optional)</label>
                                <input class="form-control" id="notes" name="notes" maxlength="500" placeholder="e.g. paid from the chambers' account">
                            </div>
                        </div>
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                            <div>Amount due: <span class="fw-bold jc-serif fs-5" id="pay-amount">—</span></div>
                            <button class="btn btn-dark" type="submit">Submit payment for confirmation</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card jc-card">
            <div class="card-header">Payment history</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0 small">
                    <thead><tr><th>Submitted</th><th>Plan</th><th>Amount</th><th>Method / reference</th><th>Status</th><th>Covers</th></tr></thead>
                    <tbody>
                    <?php if (!$payments): ?><tr><td colspan="6" class="text-muted text-center py-3">No payments yet.</td></tr><?php endif; ?>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td class="text-nowrap"><?= e(local_time($p['created_at'], 'j M Y')) ?></td>
                            <td><?= e($p['plan_name']) ?> · <?= e(SubscriptionService::CYCLES[$p['billing_cycle']] ?? '') ?></td>
                            <td class="text-nowrap"><?= e(money($p['amount'], $p['currency'])) ?></td>
                            <td><?= e(SubscriptionService::METHODS[$p['method']] ?? $p['method']) ?><div class="text-muted"><?= e($p['reference']) ?></div></td>
                            <td><span class="badge <?= $payBadge[$p['status']] ?? 'text-bg-light' ?>"><?= e(label($p['status'])) ?></span><?php if ($p['status'] === 'rejected' && $p['notes']): ?><div class="text-muted"><?= e($p['notes']) ?></div><?php endif; ?></td>
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
            <div class="card-header">Workspace details</div>
            <div class="card-body">
                <form method="post" action="/billing/workspace">
                    <?= $view->csrfField() ?>
                    <div class="mb-3">
                        <div class="form-label">Workspace type</div>
                        <?php foreach (Terminology::MODES as $k => $v): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="mode" id="mode-<?= e($k) ?>" value="<?= e($k) ?>" <?= ($chambers['mode'] ?? 'court') === $k ? 'checked' : '' ?>>
                                <label class="form-check-label" for="mode-<?= e($k) ?>"><?= e($v) ?></label>
                            </div>
                        <?php endforeach; ?>
                        <div class="form-text">Arbitration mode uses arbitral wording (references, claimants, awards). Your records are unchanged when you switch.</div>
                    </div>
                    <div class="mb-2"><label class="form-label" for="ws-name">Name</label><input class="form-control" id="ws-name" name="name" required maxlength="190" value="<?= e($chambers['name']) ?>"></div>
                    <div class="mb-2"><label class="form-label" for="ws-court"><?= e(term('Court')) ?></label><input class="form-control" id="ws-court" name="court" required maxlength="190" value="<?= e($chambers['court']) ?>"></div>
                    <div class="row g-2 mb-3">
                        <div class="col-6"><label class="form-label" for="ws-division">Division</label><input class="form-control" id="ws-division" name="division" maxlength="190" value="<?= e($chambers['division'] ?? '') ?>"></div>
                        <div class="col-6"><label class="form-label" for="ws-location">Location</label><input class="form-control" id="ws-location" name="location" maxlength="190" value="<?= e($chambers['location'] ?? '') ?>"></div>
                    </div>
                    <button class="btn btn-dark" type="submit">Save details</button>
                </form>
            </div>
        </div>
        <div class="card jc-card">
            <div class="card-header">Your data</div>
            <div class="card-body small">
                <p>Your workspace belongs to you. Export it at any time:</p>
                <ul class="mb-0">
                    <li>Transcripts: <em>Export to Word</em> on any recording.</li>
                    <li>Returns: <a href="/reports">Returns &amp; reports</a> → CSV or print to PDF.</li>
                    <li>Documents: download from each <?= e(term('case')) ?>'s bundle in their original format.</li>
                    <li>Calendar: print the daily <?= e(strtolower(term('Cause list'))) ?>.</li>
                </ul>
            </div>
        </div>
    </div>
</div>
