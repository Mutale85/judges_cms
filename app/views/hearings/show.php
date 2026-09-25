<?php
use JC\Service\HearingService;
use JC\Service\RecordingService;

$hid = (int) $hearing['id'];
$recordable = in_array($hearing['status'], ['confirmed', 'in_progress', 'completed'], true);
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="/cases">Cases</a></li>
    <li class="breadcrumb-item"><a href="/cases/<?= (int) $case['id'] ?>?tab=hearings"><?= e($case['case_number']) ?></a></li>
    <li class="breadcrumb-item active">Hearing</li>
</ol></nav>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card jc-card mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="text-muted small"><?= e(HearingService::TYPES[$hearing['hearing_type']] ?? '') ?></div>
                        <h1 class="h4"><?= e(local_time($hearing['scheduled_at'], 'l j F Y, H:i')) ?></h1>
                        <div><?= e($case['case_number']) ?> — <?= e($case['title']) ?></div>
                        <div class="text-muted small"><?= (int) $hearing['duration_minutes'] ?> minutes<?= $hearing['courtroom'] ? ' · ' . e($hearing['courtroom']) : '' ?></div>
                    </div>
                    <div><span class="badge fs-6 text-bg-light border"><?= e(HearingService::STATUSES[$hearing['status']] ?? '') ?></span></div>
                </div>
                <?php if ($hearing['notes']): ?><hr><div class="small"><strong>Notes:</strong> <?= nl2br(e($hearing['notes'])) ?></div><?php endif; ?>
                <?php if ($hearing['outcome']): ?><div class="small mt-2"><strong>Outcome:</strong> <?= nl2br(e($hearing['outcome'])) ?></div><?php endif; ?>
                <?php if ($hearing['adjourned_from_id']): ?><div class="small mt-2"><a href="/hearings/<?= (int) $hearing['adjourned_from_id'] ?>">← Adjourned from an earlier sitting</a></div><?php endif; ?>
            </div>
        </div>

        <div class="card jc-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-mic"></i> Recordings</span>
            </div>
            <div class="list-group list-group-flush">
                <?php if (!$recordings): ?><div class="list-group-item text-muted">No recordings for this hearing.</div><?php endif; ?>
                <?php foreach ($recordings as $r): ?>
                    <a class="list-group-item list-group-item-action d-flex justify-content-between" href="/recordings/<?= (int) $r['id'] ?><?= $r['sha256'] === null && $r['status'] !== 'failed' ? '/capture' : '' ?>">
                        <span>Recording #<?= (int) $r['id'] ?> · <?= e(format_duration_ms($r['duration_ms'])) ?> · <?= e(RecordingService::LANGUAGES[$r['language']] ?? $r['language']) ?></span>
                        <span><span class="badge text-bg-light border"><?= e(label($r['status'])) ?></span> <span class="badge text-bg-light border"><?= e(label($r['transcript_status'])) ?></span></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php if ($auth->can('recordings.record') && $recordable): ?>
                <div class="card-body border-top">
                    <form method="post" action="/hearings/<?= $hid ?>/recordings" class="d-flex flex-wrap gap-2 align-items-center">
                        <?= $view->csrfField() ?>
                        <label class="small" for="language">Language</label>
                        <select class="form-select form-select-sm w-auto" id="language" name="language">
                            <?php foreach (RecordingService::LANGUAGES as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
                        </select>
                        <button class="btn btn-danger" type="submit"><i class="bi bi-record-circle"></i> Open recording console</button>
                    </form>
                    <p class="small text-muted mt-2 mb-0">Only record proceedings you are authorised to record under the court's rules.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-5">
        <?php if ($auth->can('hearings.confirm')): ?>
            <?php if ($hearing['status'] === 'proposed'): ?>
                <div class="card jc-card mb-3 border-warning">
                    <div class="card-body">
                        <p class="mb-2">This date was proposed by staff and awaits your confirmation.</p>
                        <form method="post" action="/hearings/<?= $hid ?>/confirm"><?= $view->csrfField() ?><button class="btn btn-success" type="submit"><i class="bi bi-check2"></i> Confirm hearing</button></form>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (!in_array($hearing['status'], ['adjourned', 'cancelled', 'completed'], true)): ?>
                <div class="card jc-card mb-3">
                    <div class="card-header">Adjourn</div>
                    <div class="card-body">
                        <form method="post" action="/hearings/<?= $hid ?>/adjourn" class="row g-2">
                            <?= $view->csrfField() ?>
                            <div class="col-12"><input class="form-control" type="datetime-local" name="scheduled_at" required></div>
                            <div class="col-12"><input class="form-control" name="reason" placeholder="Reason (e.g. at the request of counsel for the defendant)"></div>
                            <div class="col-12"><button class="btn btn-warning" type="submit">Adjourn to new date</button></div>
                        </form>
                    </div>
                </div>
                <div class="card jc-card">
                    <div class="card-header">Close hearing</div>
                    <div class="card-body">
                        <form method="post" action="/hearings/<?= $hid ?>/complete" class="row g-2">
                            <?= $view->csrfField() ?>
                            <div class="col-12"><textarea class="form-control" name="outcome" rows="3" placeholder="Outcome / directions given"></textarea></div>
                            <div class="col-12 d-flex gap-2">
                                <button class="btn btn-success" type="submit" name="status" value="completed">Mark completed</button>
                                <button class="btn btn-outline-secondary" type="submit" name="status" value="cancelled">Cancel hearing</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
