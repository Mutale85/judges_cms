<?php
use JC\Service\ReminderService;

/** @var array<string,mixed>|null $reminder */
$r = $reminder ?? null;
$due = $r ? new DateTimeImmutable($r['due_at'], new DateTimeZone('UTC')) : null;
$due = $due?->setTimezone(app_timezone());
$fixedCase = $fixedCase ?? null;
?>
<form method="post" action="<?= $r ? '/reminders/' . (int) $r['id'] : '/reminders' ?>">
    <?= $view->csrfField() ?>
    <input type="hidden" name="back" value="<?= e($back) ?>">
    <div class="mb-2"><input class="form-control" name="title" required maxlength="300" placeholder="e.g. Deliver ruling on preliminary objection" value="<?= e($r['title'] ?? '') ?>" aria-label="Title"></div>
    <div class="row g-2 mb-2">
        <div class="col-7"><input class="form-control" type="date" name="due_date" required value="<?= e($due?->format('Y-m-d') ?? '') ?>" aria-label="Due date"></div>
        <div class="col-5"><input class="form-control" type="time" name="due_time" value="<?= e($due?->format('H:i') ?? '09:00') ?>" aria-label="Time"></div>
    </div>
    <div class="row g-2 mb-2">
        <div class="col-6">
            <select class="form-select" name="kind" aria-label="Kind">
                <?php foreach (ReminderService::kinds() as $k => $v): ?><option value="<?= e($k) ?>" <?= ($r['kind'] ?? 'reminder') === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-6">
            <select class="form-select" name="priority" aria-label="Priority">
                <?php foreach (ReminderService::PRIORITIES as $k => $v): ?><option value="<?= e($k) ?>" <?= ($r['priority'] ?? 'normal') === $k ? 'selected' : '' ?>><?= e($v) ?> priority</option><?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="mb-2">
        <select class="form-select" name="recurrence" aria-label="Repeats">
            <?php foreach (ReminderService::RECURRENCE as $k => $v): ?><option value="<?= e($k) ?>" <?= ($r['recurrence'] ?? 'none') === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
        </select>
    </div>
    <?php if ($fixedCase !== null): ?>
        <input type="hidden" name="case_id" value="<?= (int) $fixedCase ?>">
    <?php else: ?>
        <div class="mb-2">
            <select class="form-select" name="case_id" aria-label="<?= e(term('Case')) ?>">
                <option value="">No specific <?= e(term('case')) ?></option>
                <?php foreach ($cases as $c): $sel = (int) ($r['case_id'] ?? $preselectCase ?? 0) === (int) $c['id']; ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $sel ? 'selected' : '' ?>><?= e($c['case_number']) ?> — <?= e(mb_strimwidth($c['title'], 0, 50, '…')) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <div class="mb-3"><textarea class="form-control" name="details" rows="3" placeholder="Notes (optional)" aria-label="Notes"><?= e($r['details'] ?? '') ?></textarea></div>
    <button class="btn btn-dark w-100" type="submit"><?= $r ? 'Save changes' : 'Add reminder' ?></button>
</form>
