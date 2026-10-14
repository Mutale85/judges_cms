<?php
use JC\Service\HearingService;
use JC\Service\ReminderService;

$todayKey = (new DateTimeImmutable('today', app_timezone()))->format('Y-m-d');
$step = $calView === 'month' ? 'month' : 'week';
$prev = $calView === 'month' ? $anchor->modify('-1 month') : $anchor->modify('-7 days');
$next = $calView === 'month' ? $anchor->modify('+1 month') : $anchor->modify('+7 days');
$kinds = ReminderService::kinds();

$renderItems = static function (array $day) use ($kinds): void {
    foreach ($day['hearings'] as $h): ?>
        <a href="/hearings/<?= (int) $h['id'] ?>" class="jc-event jc-event-<?= e($h['status']) ?>" title="<?= e(HearingService::STATUSES[$h['status']] ?? '') ?>">
            <span class="fw-semibold"><?= e(local_time($h['scheduled_at'], 'H:i')) ?></span> <?= e($h['case_number']) ?>
            <span class="d-block text-muted"><?= e(HearingService::TYPES[$h['hearing_type']] ?? '') ?><?= $h['courtroom'] ? ' · ' . e($h['courtroom']) : '' ?></span>
        </a>
    <?php endforeach;
    foreach ($day['reminders'] as $r): ?>
        <a href="<?= $r['case_id'] ? '/cases/' . (int) $r['case_id'] . '?tab=reminders' : '/reminders' ?>" class="jc-event jc-event-reminder<?= $r['status'] === 'done' ? ' jc-done' : '' ?>">
            <i class="bi bi-bell"></i> <?= e($r['title']) ?>
            <span class="d-block text-muted"><?= e($kinds[$r['kind']] ?? '') ?><?= $r['case_number'] ? ' · ' . e($r['case_number']) : '' ?></span>
        </a>
    <?php endforeach;
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h1 class="h3 mb-0">Calendar</h1>
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <div class="btn-group btn-group-sm" role="group" aria-label="View">
            <a class="btn btn-outline-secondary<?= $calView === 'week' ? ' active' : '' ?>" href="/calendar?view=week&date=<?= e($selected) ?>">Week</a>
            <a class="btn btn-outline-secondary<?= $calView === 'month' ? ' active' : '' ?>" href="/calendar?view=month&date=<?= e($selected) ?>">Month</a>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="/calendar?view=<?= e($calView) ?>&date=<?= e($prev->format('Y-m-d')) ?>" aria-label="Previous <?= e($step) ?>"><i class="bi bi-chevron-left"></i></a>
        <span class="fw-semibold jc-serif">
            <?= $calView === 'month' ? e($anchor->format('F Y')) : e($anchor->format('j M')) . ' – ' . e($anchor->modify('+6 days')->format('j M Y')) ?>
        </span>
        <a class="btn btn-outline-secondary btn-sm" href="/calendar?view=<?= e($calView) ?>&date=<?= e($next->format('Y-m-d')) ?>" aria-label="Next <?= e($step) ?>"><i class="bi bi-chevron-right"></i></a>
        <a class="btn btn-outline-secondary btn-sm" href="/calendar?view=<?= e($calView) ?>">Today</a>
        <?php if ($auth->can('reminders.manage')): ?><a class="btn btn-dark btn-sm" href="/reminders#new"><i class="bi bi-plus-lg"></i> Reminder</a><?php endif; ?>
    </div>
</div>

<?php if ($calView === 'month'): ?>
    <div class="jc-month">
        <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d): ?><div class="jc-month-head"><?= $d ?></div><?php endforeach; ?>
        <?php foreach ($days as $day => $items):
            $dt = new DateTimeImmutable($day, app_timezone());
            $out = $dt->format('m') !== $anchor->format('m'); ?>
            <div class="jc-month-day<?= $out ? ' jc-out' : '' ?><?= $day === $todayKey ? ' jc-today' : '' ?>">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="jc-day-n"><?= e($dt->format('j')) ?></span>
                    <span class="d-md-none small text-muted"><?= e($dt->format('D j M')) ?></span>
                    <?php if ($items['hearings']): ?><a href="/calendar/cause-list?date=<?= e($day) ?>" class="small text-muted" title="<?= e(term('Cause list')) ?>"><i class="bi bi-list-ol"></i></a><?php endif; ?>
                </div>
                <?php $renderItems($items); ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="jc-week">
        <?php foreach ($days as $day => $items):
            $dt = new DateTimeImmutable($day, app_timezone()); ?>
            <div class="card jc-card h-100<?= $day === $todayKey ? ' border-dark' : '' ?>">
                <div class="card-header d-flex justify-content-between small">
                    <span class="fw-semibold"><?= e($dt->format('D j M')) ?></span>
                    <a href="/calendar/cause-list?date=<?= e($day) ?>" title="<?= e(term('Cause list')) ?>"><i class="bi bi-list-ol"></i></a>
                </div>
                <div class="card-body p-2">
                    <?php if (!$items['hearings'] && !$items['reminders']): ?><div class="text-muted small">—</div><?php endif; ?>
                    <?php $renderItems($items); ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<p class="small text-muted mt-3">Proposed hearings (dashed) await confirmation by the <?= e(term('judge')) ?>. Reminders and deadlines appear in amber. Schedule hearings from a <?= e(term('case')) ?>'s <em>Hearings</em> tab.</p>
