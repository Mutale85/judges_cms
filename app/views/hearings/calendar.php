<?php use JC\Service\HearingService; ?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h1 class="h3 mb-0">Judicial calendar</h1>
    <div class="d-flex gap-2 align-items-center">
        <a class="btn btn-outline-secondary btn-sm" href="/calendar?date=<?= e($weekStart->modify('-7 days')->format('Y-m-d')) ?>"><i class="bi bi-chevron-left"></i></a>
        <span class="fw-semibold"><?= e($weekStart->format('j M')) ?> – <?= e($weekStart->modify('+6 days')->format('j M Y')) ?></span>
        <a class="btn btn-outline-secondary btn-sm" href="/calendar?date=<?= e($weekStart->modify('+7 days')->format('Y-m-d')) ?>"><i class="bi bi-chevron-right"></i></a>
        <a class="btn btn-outline-secondary btn-sm" href="/calendar">This week</a>
    </div>
</div>

<div class="row row-cols-1 row-cols-md-2 row-cols-xl-7 g-2 jc-week">
    <?php foreach ($days as $day => $items):
        $dt = new DateTimeImmutable($day, app_timezone());
        $isToday = $day === (new DateTimeImmutable('today', app_timezone()))->format('Y-m-d'); ?>
        <div class="col">
            <div class="card jc-card h-100<?= $isToday ? ' border-primary' : '' ?>">
                <div class="card-header d-flex justify-content-between small">
                    <span class="fw-semibold"><?= e($dt->format('D j M')) ?></span>
                    <a href="/calendar/cause-list?date=<?= e($day) ?>" title="Cause list"><i class="bi bi-list-ol"></i></a>
                </div>
                <div class="card-body p-2">
                    <?php if (!$items): ?><div class="text-muted small">—</div><?php endif; ?>
                    <?php foreach ($items as $h): ?>
                        <a href="/hearings/<?= (int) $h['id'] ?>" class="jc-event jc-event-<?= e($h['status']) ?> d-block mb-2">
                            <div class="small fw-semibold"><?= e(local_time($h['scheduled_at'], 'H:i')) ?> · <?= e($h['case_number']) ?></div>
                            <div class="small"><?= e(HearingService::TYPES[$h['hearing_type']] ?? '') ?></div>
                            <div class="small text-muted"><?= e(HearingService::STATUSES[$h['status']] ?? '') ?><?= $h['courtroom'] ? ' · ' . e($h['courtroom']) : '' ?></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<p class="small text-muted mt-3">Proposed hearings (dashed) await the judge's confirmation. Schedule hearings from a case's <em>Hearings</em> tab.</p>
