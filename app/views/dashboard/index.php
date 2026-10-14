<?php
use JC\Service\HearingService;

$chambers = $auth->chambers();
$statusBadge = static fn (string $s) => match ($s) {
    'confirmed' => 'text-bg-primary', 'in_progress' => 'text-bg-danger', 'completed' => 'text-bg-success',
    'adjourned' => 'text-bg-warning', 'cancelled' => 'text-bg-secondary', default => 'text-bg-light border',
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-end mb-4 gap-2">
    <div>
        <div class="text-muted small text-uppercase jc-eyebrow"><?= e($chambers['court']) ?><?= $chambers['division'] ? ' · ' . e($chambers['division']) : '' ?></div>
        <h1 class="h3 mb-0"><?= e($chambers['name']) ?></h1>
    </div>
    <div class="d-flex gap-2">
        <?php if ($auth->can('cases.manage')): ?><a href="/cases/new" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Register <?= e(term('case')) ?></a><?php endif; ?>
        <a href="/calendar/cause-list" class="btn btn-outline-secondary"><i class="bi bi-list-ol"></i> Today's <?= e(strtolower(term('Cause list'))) ?></a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><a href="/cases" class="text-decoration-none"><div class="card jc-stat"><div class="card-body"><div class="jc-stat-value"><?= (int) $openCases ?></div><div class="jc-stat-label">Active <?= e(term('Case') === 'Case' ? 'matters' : term('cases')) ?></div></div></div></a></div>
    <div class="col-6 col-lg-3"><a href="/reminders?show=week" class="text-decoration-none"><div class="card jc-stat"><div class="card-body"><div class="jc-stat-value"><?= count($dueThisWeek) ?></div><div class="jc-stat-label">Due this week</div></div></div></a></div>
    <div class="col-6 col-lg-3"><div class="card jc-stat"><div class="card-body"><div class="jc-stat-value"><?= count($reserved) ?></div><div class="jc-stat-label"><?= e(term('Judgments')) ?> pending</div></div></div></div>
    <div class="col-6 col-lg-3"><a href="/calendar" class="text-decoration-none"><div class="card jc-stat"><div class="card-body"><div class="jc-stat-value"><?= count($today) ?></div><div class="jc-stat-label">Hearings today</div></div></div></a></div>
</div>

<div class="row g-4">
    <div class="col-xl-7">
        <div class="card jc-card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-calendar-event"></i> Today's hearings</span>
                <a href="/calendar" class="small">Full calendar</a>
            </div>
            <div class="list-group list-group-flush">
                <?php if (!$today): ?><div class="list-group-item text-muted">No hearings scheduled today.</div><?php endif; ?>
                <?php foreach ($today as $h): ?>
                    <a href="/hearings/<?= (int) $h['id'] ?>" class="list-group-item list-group-item-action d-flex gap-3 align-items-center">
                        <div class="jc-time"><?= e(local_time($h['scheduled_at'], 'H:i')) ?></div>
                        <div class="flex-grow-1">
                            <div class="fw-semibold"><?= e($h['case_number']) ?> — <?= e($h['case_title']) ?></div>
                            <div class="small text-muted"><?= e(HearingService::TYPES[$h['hearing_type']] ?? $h['hearing_type']) ?><?= $h['courtroom'] ? ' · ' . e($h['courtroom']) : '' ?></div>
                        </div>
                        <span class="badge <?= $statusBadge($h['status']) ?>"><?= e(HearingService::STATUSES[$h['status']] ?? $h['status']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($proposed && $auth->can('hearings.confirm')): ?>
            <div class="card jc-card mb-4 border-warning">
                <div class="card-header"><i class="bi bi-hourglass-split"></i> Hearings awaiting your confirmation</div>
                <div class="list-group list-group-flush">
                    <?php foreach ($proposed as $h): ?>
                        <a href="/hearings/<?= (int) $h['id'] ?>" class="list-group-item list-group-item-action">
                            <span class="fw-semibold"><?= e(local_time($h['scheduled_at'])) ?></span> · <?= e($h['case_number']) ?> — <?= e(HearingService::TYPES[$h['hearing_type']] ?? '') ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="card jc-card">
            <div class="card-header"><i class="bi bi-calendar-week"></i> Next two weeks</div>
            <div class="list-group list-group-flush">
                <?php if (!$upcoming): ?><div class="list-group-item text-muted">Nothing scheduled.</div><?php endif; ?>
                <?php foreach (array_slice($upcoming, 0, 12) as $h): ?>
                    <a href="/hearings/<?= (int) $h['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between">
                        <span><span class="text-muted small me-2"><?= e(local_time($h['scheduled_at'], 'D j M H:i')) ?></span><?= e($h['case_number']) ?> — <?= e(HearingService::TYPES[$h['hearing_type']] ?? '') ?></span>
                        <span class="badge <?= $statusBadge($h['status']) ?>"><?= e(HearingService::STATUSES[$h['status']] ?? '') ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="card jc-card mb-4">
            <div class="card-header d-flex justify-content-between"><span><i class="bi bi-bell"></i> Due this week</span><a href="/reminders" class="small">All reminders</a></div>
            <div class="list-group list-group-flush">
                <?php if (!$dueThisWeek): ?><div class="list-group-item text-muted">Nothing due in the next seven days.</div><?php endif; ?>
                <?php foreach (array_slice($dueThisWeek, 0, 8) as $r): ?>
                    <a class="list-group-item list-group-item-action d-flex justify-content-between gap-2" href="<?= $r['case_id'] ? '/cases/' . (int) $r['case_id'] . '?tab=reminders' : '/reminders' ?>">
                        <span>
                            <span class="fw-semibold"><?= e($r['title']) ?></span>
                            <span class="d-block small <?= $r['overdue'] ? 'text-danger' : 'text-muted' ?>"><?= $r['overdue'] ? 'Overdue · ' : '' ?><?= e(local_time($r['due_at'], 'D j M, H:i')) ?><?= $r['case_number'] ? ' · ' . e($r['case_number']) : '' ?></span>
                        </span>
                        <span class="jc-pill jc-prio-<?= e($r['priority']) ?> align-self-start"><?= e(JC\Service\ReminderService::kinds()[$r['kind']] ?? '') ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card jc-card mb-4">
            <div class="card-header d-flex justify-content-between"><span><i class="bi bi-check2-square"></i> My tasks</span><a href="/tasks" class="small">All tasks</a></div>
            <div class="list-group list-group-flush">
                <?php if (!$myTasks): ?><div class="list-group-item text-muted">No open tasks.</div><?php endif; ?>
                <?php foreach (array_slice($myTasks, 0, 8) as $t): ?>
                    <div class="list-group-item">
                        <div class="fw-semibold"><?= e($t['title']) ?></div>
                        <div class="small text-muted">
                            <?= $t['case_number'] ? e($t['case_number']) . ' · ' : '' ?>
                            <?= $t['due_on'] ? 'Due ' . e(date('j M', strtotime($t['due_on']))) . ' · ' : '' ?>from <?= e($t['assigner_name']) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($reserved): ?>
            <div class="card jc-card mb-4">
                <div class="card-header"><i class="bi bi-journal-text"></i> <?= e(term('Judgments')) ?> reserved</div>
                <div class="list-group list-group-flush">
                    <?php foreach ($reserved as $c): ?>
                        <a class="list-group-item list-group-item-action" href="/cases/<?= (int) $c['id'] ?>"><?= e($c['case_number']) ?> — <?= e($c['title']) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="card jc-card">
            <div class="card-header d-flex justify-content-between"><span><i class="bi bi-folder2-open"></i> Recently updated <?= e(term('cases')) ?></span><a href="/cases" class="small">All <?= e(term('cases')) ?></a></div>
            <div class="list-group list-group-flush">
                <?php if (!$recentCases): ?><div class="list-group-item text-muted">No <?= e(term('cases')) ?> yet.<?php if ($auth->can('cases.manage')): ?> <a href="/cases/new">Register the first one</a>.<?php endif; ?></div><?php endif; ?>
                <?php foreach ($recentCases as $c): ?>
                    <a class="list-group-item list-group-item-action" href="/cases/<?= (int) $c['id'] ?>">
                        <?php if ((int) $c['sealed'] === 1): ?><i class="bi bi-lock-fill text-danger" title="Sealed"></i><?php endif; ?>
                        <span class="fw-semibold"><?= e($c['case_number']) ?></span> — <?= e($c['title']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
