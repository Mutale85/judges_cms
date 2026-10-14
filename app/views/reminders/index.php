<?php
use JC\Service\ReminderService;

$kinds = ReminderService::kinds();
$manage = $auth->can('reminders.manage');
$form = $editing ?? null;
$back = '/reminders' . ($show !== 'open' ? '?show=' . $show : '');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
        <h1 class="h3 mb-0">Reminders &amp; deadlines</h1>
        <div class="text-muted small"><?= e(term('Ruling')) ?> and <?= e(term('judgment')) ?> dates, filing deadlines and status dates for your <?= e(term('chambers')) ?>.</div>
    </div>
    <div class="btn-group btn-group-sm">
        <?php foreach (['open' => 'All open', 'week' => 'Due this week', 'overdue' => 'Overdue', 'done' => 'Done'] as $k => $v): ?>
            <a class="btn btn-outline-secondary<?= $show === $k ? ' active' : '' ?>" href="/reminders?show=<?= e($k) ?>"><?= e($v) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="row g-4">
    <div class="<?= $manage ? 'col-xl-8' : 'col-12' ?>">
        <div class="card jc-card">
            <div class="list-group list-group-flush">
                <?php if (!$reminders): ?><div class="list-group-item text-muted py-4 text-center">Nothing here.</div><?php endif; ?>
                <?php foreach ($reminders as $r): ?>
                    <div class="list-group-item">
                        <div class="d-flex flex-wrap justify-content-between gap-2">
                            <div class="flex-grow-1">
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <span class="fw-semibold<?= $r['status'] === 'done' ? ' text-decoration-line-through text-muted' : '' ?>"><?= e($r['title']) ?></span>
                                    <span class="jc-pill"><?= e($kinds[$r['kind']] ?? '') ?></span>
                                    <?php if ($r['priority'] !== 'normal'): ?><span class="jc-pill jc-prio-<?= e($r['priority']) ?>"><?= e(ReminderService::PRIORITIES[$r['priority']]) ?></span><?php endif; ?>
                                    <?php if ($r['recurrence'] !== 'none'): ?><span class="jc-pill" title="Repeats"><i class="bi bi-arrow-repeat"></i> <?= e(ReminderService::RECURRENCE[$r['recurrence']]) ?></span><?php endif; ?>
                                </div>
                                <div class="small <?= $r['overdue'] ? 'text-danger fw-semibold' : 'text-muted' ?>">
                                    <?= $r['overdue'] ? 'Overdue · ' : '' ?><?= e(local_time($r['due_at'])) ?>
                                    <?php if ($r['case_id']): ?> · <a href="/cases/<?= (int) $r['case_id'] ?>"><?= e($r['case_number']) ?></a> <?= e(mb_strimwidth((string) $r['case_title'], 0, 60, '…')) ?><?php endif; ?>
                                </div>
                                <?php if ($r['details']): ?><div class="small mt-1"><?= nl2br(e($r['details'])) ?></div><?php endif; ?>
                            </div>
                            <?php if ($manage): ?>
                                <div class="d-flex gap-1 align-items-start">
                                    <?php if ($r['status'] === 'open'): ?>
                                        <form method="post" action="/reminders/<?= (int) $r['id'] ?>/complete"><?= $view->csrfField() ?><input type="hidden" name="back" value="<?= e($back) ?>"><button class="btn btn-sm btn-outline-success" type="submit" title="Mark done"><i class="bi bi-check2"></i> Done</button></form>
                                        <a class="btn btn-sm btn-outline-secondary" href="/reminders?edit=<?= (int) $r['id'] ?>#new" title="Edit"><i class="bi bi-pencil"></i></a>
                                    <?php else: ?>
                                        <form method="post" action="/reminders/<?= (int) $r['id'] ?>/reopen"><?= $view->csrfField() ?><input type="hidden" name="back" value="<?= e($back) ?>"><button class="btn btn-sm btn-outline-secondary" type="submit">Reopen</button></form>
                                    <?php endif; ?>
                                    <form method="post" action="/reminders/<?= (int) $r['id'] ?>/delete" data-confirm="Delete this reminder?" data-danger><?= $view->csrfField() ?><input type="hidden" name="back" value="<?= e($back) ?>"><button class="btn btn-sm btn-outline-danger" type="submit" title="Delete" aria-label="Delete"><i class="bi bi-trash"></i></button></form>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php if ($manage): ?>
        <div class="col-xl-4" id="new">
            <div class="card jc-card">
                <div class="card-header"><?= $form ? 'Edit reminder' : 'New reminder' ?></div>
                <div class="card-body">
                    <?= $view->partial('reminders/_form', ['reminder' => $form, 'cases' => $cases, 'preselectCase' => $preselectCase, 'back' => '/reminders']) ?>
                    <?php if ($form): ?><a href="/reminders" class="btn btn-link btn-sm w-100 mt-1">Cancel editing</a><?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
