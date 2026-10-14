<?php
use JC\Service\CaseService;
use JC\Service\HearingService;

$print = ($app->request()?->input('print') ?? '') === '1';
$query = http_build_query(['period' => $period, 'from' => $r['from'], 'to' => $r['to']]);
$maxStatus = max(1, ...array_values($r['by_status'] ?: [0]));
$maxType = max(1, ...array_values($r['by_type'] ?: [0]));
?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
    <div>
        <?php if ($print): ?><div class="text-uppercase small fw-semibold"><?= e($chambers['court'] ?? '') ?></div><?php endif; ?>
        <h1 class="h3 mb-0">Returns &amp; reports</h1>
        <div class="text-muted small"><?= e($chambers['name'] ?? '') ?> · <?= e(date('j M Y', strtotime($r['from']))) ?> – <?= e(date('j M Y', strtotime($r['to']))) ?></div>
    </div>
    <?php if (!$print): ?>
        <div class="d-flex flex-wrap gap-2">
            <form class="d-flex flex-wrap gap-2" method="get" action="/reports" data-no-ajax>
                <select class="form-select form-select-sm w-auto" name="period" aria-label="Period" data-period>
                    <?php foreach (['month' => 'This month', 'last_month' => 'Last month', 'quarter' => 'This quarter', 'year' => 'This year', 'custom' => 'Custom'] as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $period === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
                <input class="form-control form-control-sm w-auto" type="date" name="from" value="<?= e($r['from']) ?>" aria-label="From">
                <input class="form-control form-control-sm w-auto" type="date" name="to" value="<?= e($r['to']) ?>" aria-label="To">
                <button class="btn btn-sm btn-outline-secondary" type="submit">Show</button>
            </form>
            <a class="btn btn-sm btn-outline-secondary" href="/reports?<?= e($query) ?>&print=1"><i class="bi bi-printer"></i> Print</a>
            <a class="btn btn-sm btn-dark" href="/reports/export?<?= e($query) ?>"><i class="bi bi-download"></i> CSV</a>
        </div>
    <?php endif; ?>
</div>

<div class="row g-3 mb-4">
    <?php foreach ([
        [count($r['registered']), term('Cases') . ' registered'],
        [count($r['concluded']), term('Cases') . ' concluded'],
        [$r['clearance_rate'] !== null ? $r['clearance_rate'] . '%' : '—', 'Clearance rate'],
        [count($r['pending']), term('Judgments') . ' pending'],
        [$r['open_count'], 'Open docket'],
        [$r['median_age_days'] !== null ? $r['median_age_days'] . ' d' : '—', 'Median age of open ' . term('cases')],
        [$r['hearings_total'], 'Hearings in period'],
        [$r['adjournments'], 'Adjournments'],
    ] as [$value, $label]): ?>
        <div class="col-6 col-md-3"><div class="card jc-stat"><div class="card-body"><div class="jc-stat-value"><?= e((string) $value) ?></div><div class="jc-stat-label"><?= e($label) ?></div></div></div></div>
    <?php endforeach; ?>
</div>

<div class="row g-4">
    <div class="col-xl-7">
        <div class="card jc-card mb-4">
            <div class="card-header"><?= e(term('Judgments')) ?> and <?= e(term('ruling')) ?>s pending (oldest first)</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th><?= e(term('Case number')) ?></th><th>Title</th><th class="text-end">Days reserved</th></tr></thead>
                    <tbody>
                    <?php if (!$r['pending']): ?><tr><td colspan="3" class="text-muted text-center py-3">Nothing reserved.</td></tr><?php endif; ?>
                    <?php foreach ($r['pending'] as $c): ?>
                        <tr>
                            <td class="text-nowrap"><a href="/cases/<?= (int) $c['id'] ?>"><?= e($c['case_number']) ?></a></td>
                            <td><?= e($c['title']) ?></td>
                            <td class="text-end <?= $c['days_pending'] > 90 ? 'text-danger fw-semibold' : '' ?>"><?= (int) $c['days_pending'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card jc-card mb-4">
            <div class="card-header"><?= e(term('Cases')) ?> concluded in the period</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th><?= e(term('Case number')) ?></th><th>Title</th><th>Type</th><th>Concluded</th></tr></thead>
                    <tbody>
                    <?php if (!$r['concluded']): ?><tr><td colspan="4" class="text-muted text-center py-3">None in this period.</td></tr><?php endif; ?>
                    <?php foreach ($r['concluded'] as $c): ?>
                        <tr>
                            <td class="text-nowrap"><a href="/cases/<?= (int) $c['id'] ?>"><?= e($c['case_number']) ?></a></td>
                            <td><?= e($c['title']) ?></td>
                            <td><?= e(CaseService::typeLabel($c['case_type'])) ?></td>
                            <td class="text-nowrap"><?= e(local_time($c['concluded_at'], 'j M Y')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card jc-card">
            <div class="card-header"><?= e(term('Cases')) ?> registered in the period</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th><?= e(term('Case number')) ?></th><th>Title</th><th>Status</th><th>Registered</th></tr></thead>
                    <tbody>
                    <?php if (!$r['registered']): ?><tr><td colspan="4" class="text-muted text-center py-3">None in this period.</td></tr><?php endif; ?>
                    <?php foreach ($r['registered'] as $c): ?>
                        <tr>
                            <td class="text-nowrap"><a href="/cases/<?= (int) $c['id'] ?>"><?= e($c['case_number']) ?></a></td>
                            <td><?= e($c['title']) ?></td>
                            <td><?= e(CaseService::statusLabel($c['status'])) ?></td>
                            <td class="text-nowrap"><?= e(local_time($c['created_at'], 'j M Y')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="card jc-card mb-4">
            <div class="card-header">Shape of the open docket — by stage</div>
            <div class="card-body">
                <?php foreach ($r['by_status'] as $status => $n): ?>
                    <div class="d-flex justify-content-between small"><span><?= e(CaseService::statusLabel($status)) ?></span><span class="fw-semibold"><?= (int) $n ?></span></div>
                    <div class="jc-bar mb-2" role="img" aria-label="<?= (int) $n ?>"><span data-w="<?= round($n / $maxStatus * 100) ?>"></span></div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="card jc-card mb-4">
            <div class="card-header">Open docket — by type</div>
            <div class="card-body">
                <?php if (!$r['by_type']): ?><div class="text-muted small">No open <?= e(term('cases')) ?>.</div><?php endif; ?>
                <?php foreach ($r['by_type'] as $type => $n): ?>
                    <div class="d-flex justify-content-between small"><span><?= e(CaseService::typeLabel($type)) ?></span><span class="fw-semibold"><?= (int) $n ?></span></div>
                    <div class="jc-bar mb-2" role="img" aria-label="<?= (int) $n ?>"><span data-w="<?= round($n / $maxType * 100) ?>"></span></div>
                <?php endforeach; ?>
                <?php if ($r['aged_over_year']): ?><div class="small text-danger mt-2"><?= (int) $r['aged_over_year'] ?> open <?= e(term('cases')) ?> older than a year.</div><?php endif; ?>
            </div>
        </div>
        <div class="card jc-card mb-4">
            <div class="card-header">Hearings in the period</div>
            <ul class="list-group list-group-flush">
                <?php if (!$r['hearings_by_status']): ?><li class="list-group-item text-muted small">No hearings.</li><?php endif; ?>
                <?php foreach ($r['hearings_by_status'] as $s => $n): ?>
                    <li class="list-group-item d-flex justify-content-between small"><span><?= e(HearingService::STATUSES[$s] ?? $s) ?></span><span class="fw-semibold"><?= (int) $n ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="card jc-card">
            <div class="card-header">Output due in the next 30 days</div>
            <ul class="list-group list-group-flush">
                <?php if (!$r['output_due']): ?><li class="list-group-item text-muted small">No <?= e(term('ruling')) ?> or <?= e(term('judgment')) ?> reminders set.</li><?php endif; ?>
                <?php foreach ($r['output_due'] as $rem): ?>
                    <li class="list-group-item small d-flex justify-content-between gap-2">
                        <span><?= e($rem['title']) ?><?= $rem['case_number'] ? ' · ' . e($rem['case_number']) : '' ?></span>
                        <span class="<?= $rem['overdue'] ? 'text-danger fw-semibold' : 'text-muted' ?> text-nowrap"><?= e(local_time($rem['due_at'], 'j M')) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($r['overdue_reminders']): ?><div class="card-footer small text-danger"><?= (int) $r['overdue_reminders'] ?> reminder(s) overdue in total.</div><?php endif; ?>
        </div>
    </div>
</div>
