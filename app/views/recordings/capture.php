<?php
use JC\Service\HearingService;
use JC\Service\RecordingService;

$view->addScript('/assets/recorder.js');
$chambers = $auth->chambers();
?>
<div id="recorder"
     class="jc-recorder"
     data-rec-id="<?= (int) $rec['id'] ?>"
     data-ws-url="<?= e($wsUrl) ?>"
     data-token-url="/recordings/<?= (int) $rec['id'] ?>/token"
     data-review-url="/recordings/<?= (int) $rec['id'] ?>"
     data-sample-rate="<?= (int) $rec['sample_rate'] ?>">

    <div class="card jc-card mb-3">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <div class="text-muted small"><i class="bi bi-mic-fill"></i> Courtroom recording</div>
                <div class="fw-semibold"><?= e($chambers['court'] ?? '') ?> · <?= e($case['case_number']) ?></div>
                <div class="small text-muted"><?= e(HearingService::TYPES[$hearing['hearing_type']] ?? '') ?> · <?= e(RecordingService::LANGUAGES[$rec['language']] ?? $rec['language']) ?></div>
            </div>
            <div class="text-center">
                <div class="jc-rec-indicator" data-role="indicator"><span class="jc-rec-dot"></span> <span data-role="state">Ready</span></div>
                <div class="jc-rec-clock" data-role="clock">00:00:00</div>
            </div>
            <div class="text-end">
                <div class="small" data-role="connection"><i class="bi bi-circle"></i> Not connected</div>
                <div class="small text-muted" data-role="buffer"></div>
                <div class="jc-level mt-1"><div class="jc-level-bar" data-role="level"></div></div>
            </div>
        </div>
        <div class="card-footer d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-danger" data-action="start"><i class="bi bi-record-circle"></i> Start recording</button>
            <button type="button" class="btn btn-outline-dark" data-action="stop" disabled><i class="bi bi-stop-circle"></i> Stop &amp; finalise</button>
            <div class="input-group w-auto">
                <input type="text" class="form-control" data-role="bookmark-label" placeholder="Bookmark label" maxlength="200">
                <button type="button" class="btn btn-outline-primary" data-action="bookmark" disabled><i class="bi bi-bookmark-plus"></i> Bookmark <kbd>B</kbd></button>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-3">
            <div class="card jc-card">
                <div class="card-header">Who is speaking?</div>
                <div class="card-body d-grid gap-2" data-role="speakers">
                    <?php foreach (['Judge', 'Counsel for the plaintiff', 'Counsel for the defendant', 'Witness', 'Clerk', 'Accused'] as $i => $speaker): ?>
                        <button type="button" class="btn btn-outline-secondary text-start jc-speaker" data-speaker="<?= e($speaker) ?>"><kbd><?= $i + 1 ?></kbd> <?= e($speaker) ?></button>
                    <?php endforeach; ?>
                    <div class="input-group input-group-sm mt-2">
                        <input type="text" class="form-control" data-role="custom-speaker" placeholder="Other speaker…" maxlength="120">
                        <button type="button" class="btn btn-outline-secondary" data-action="custom-speaker">Set</button>
                    </div>
                    <p class="small text-muted mb-0 mt-2">Tap the current speaker as the floor changes. Labels are attached to the transcript by timestamp and can be corrected later.</p>
                </div>
            </div>
        </div>
        <div class="col-lg-9">
            <div class="card jc-card">
                <div class="card-header d-flex justify-content-between">
                    <span>Live draft transcript</span>
                    <span class="small text-muted">Machine draft — must be checked against the recording</span>
                </div>
                <div class="card-body jc-live" data-role="live">
                    <p class="text-muted mb-0" data-role="placeholder">The draft transcript appears here as the hearing proceeds.</p>
                </div>
            </div>
            <div class="alert alert-secondary small mt-3 mb-0">
                <i class="bi bi-shield-lock"></i> Audio travels over an encrypted WebSocket (TLS) and is encrypted (AES-256-GCM) by the transcription service as it arrives. Speech-to-text: <strong><?= e(RecordingService::processorNotice()) ?></strong>. If the network drops, audio is kept in this browser (encrypted with a non-exportable key) and uploaded automatically when the connection returns. Do not close this tab until the recording is finalised.
            </div>
        </div>
    </div>
</div>
