<?php
use JC\Service\HearingService;
use JC\Service\RecordingService;

$view->addScript('/assets/transcript.js');
$rid = (int) $rec['id'];
$certified = $rec['transcript_status'] === 'certified';
$canEdit = $auth->can('transcripts.edit') && !$certified;
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="/cases/<?= (int) $case['id'] ?>"><?= e($case['case_number']) ?></a></li>
    <li class="breadcrumb-item"><a href="/hearings/<?= (int) $rec['hearing_id'] ?>">Hearing <?= e(local_time($hearing['scheduled_at'] ?? null, 'j M Y')) ?></a></li>
    <li class="breadcrumb-item active">Recording #<?= $rid ?></li>
</ol></nav>

<div class="row g-4" id="transcript" data-rec-id="<?= $rid ?>">
    <div class="col-xl-8">
        <div class="card jc-card mb-3 jc-sticky-player">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
                    <div>
                        <h1 class="h5 mb-0">Transcript review</h1>
                        <div class="small text-muted"><?= e($case['case_number']) ?> · <?= e(HearingService::TYPES[$hearing['hearing_type']] ?? '') ?> · <?= e(RecordingService::LANGUAGES[$rec['language']] ?? '') ?></div>
                    </div>
                    <div>
                        <?php if ($certified): ?>
                            <span class="badge text-bg-success fs-6"><i class="bi bi-patch-check-fill"></i> Certified record</span>
                        <?php else: ?>
                            <span class="badge text-bg-warning fs-6"><i class="bi bi-exclamation-triangle"></i> DRAFT — not an official record</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($rec['sha256'] !== null): ?>
                    <audio controls preload="metadata" class="w-100" data-role="player" src="/recordings/<?= $rid ?>/audio"></audio>
                <?php else: ?>
                    <div class="alert alert-info mb-0">The recording is <?= e($rec['status']) ?>. Audio will be available once it is finalised.</div>
                <?php endif; ?>
                <div class="input-group input-group-sm mt-2">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="search" class="form-control" data-role="search" placeholder="Search within the transcript">
                </div>
            </div>
        </div>

        <div class="card jc-card">
            <div class="list-group list-group-flush" data-role="segments">
                <?php if (!$segments): ?><div class="list-group-item text-muted">No transcript text yet.</div><?php endif; ?>
                <?php foreach ($segments as $s): $edited = $s['edited_by'] !== null; ?>
                    <div class="list-group-item jc-segment" id="seg-<?= (int) $s['id'] ?>" data-start="<?= (int) $s['start_ms'] ?>" data-end="<?= (int) $s['end_ms'] ?>">
                        <div class="d-flex gap-3">
                            <button type="button" class="btn btn-link btn-sm p-0 jc-ts" data-seek="<?= (int) $s['start_ms'] ?>" title="Play from here"><?= e(format_duration_ms($s['start_ms'])) ?></button>
                            <div class="flex-grow-1">
                                <div class="small fw-semibold jc-speaker-label"><?= e($s['speaker'] ?? 'Unidentified speaker') ?>
                                    <?php if ($edited): ?><span class="badge text-bg-info ms-1" title="Corrected by a reviewer">corrected</span><?php endif; ?>
                                    <?php if ($s['source'] === 'manual'): ?><span class="badge text-bg-secondary ms-1">added manually</span><?php endif; ?>
                                    <?php if ($s['confidence'] !== null && (float) $s['confidence'] < 0.6 && !$edited): ?><span class="badge text-bg-warning ms-1" title="Low machine confidence">check</span><?php endif; ?>
                                </div>
                                <div class="jc-segment-text"><?= e($s['text']) ?></div>
                                <?php if ($edited && $s['machine_text'] !== null && $s['machine_text'] !== $s['text']): ?>
                                    <details class="small text-muted mt-1"><summary>Original machine text</summary><?= e($s['machine_text']) ?></details>
                                <?php endif; ?>
                                <?php if ($canEdit): ?>
                                    <form method="post" action="/recordings/<?= $rid ?>/segments/<?= (int) $s['id'] ?>" class="jc-segment-edit d-none mt-2">
                                        <?= $view->csrfField() ?>
                                        <input class="form-control form-control-sm mb-1" name="speaker" value="<?= e($s['speaker'] ?? '') ?>" placeholder="Speaker">
                                        <textarea class="form-control form-control-sm mb-1" name="text" rows="3" required><?= e($s['text']) ?></textarea>
                                        <button class="btn btn-sm btn-primary" type="submit">Save correction</button>
                                        <button class="btn btn-sm btn-outline-secondary" type="button" data-action="cancel-edit">Cancel</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                            <?php if ($canEdit): ?><button type="button" class="btn btn-sm btn-outline-secondary align-self-start" data-action="edit" title="Correct"><i class="bi bi-pencil"></i></button><?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($canEdit): ?>
            <div class="card jc-card mt-3">
                <div class="card-header">Add a missing passage</div>
                <div class="card-body">
                    <form method="post" action="/recordings/<?= $rid ?>/segments" class="row g-2">
                        <?= $view->csrfField() ?>
                        <div class="col-md-2"><input class="form-control" name="start" placeholder="00:12:30" required data-role="current-time"></div>
                        <div class="col-md-2"><input class="form-control" name="end" placeholder="00:12:45"></div>
                        <div class="col-md-3"><input class="form-control" name="speaker" placeholder="Speaker"></div>
                        <div class="col-md-5"><input class="form-control" name="text" placeholder="Text" required></div>
                        <div class="col-12"><button class="btn btn-outline-primary btn-sm" type="submit">Add passage</button></div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-xl-4">
        <div class="card jc-card mb-3">
            <div class="card-header"><i class="bi bi-bookmarks"></i> Bookmarks</div>
            <div class="list-group list-group-flush">
                <?php if (!$bookmarks): ?><div class="list-group-item text-muted small">No bookmarks.</div><?php endif; ?>
                <?php foreach ($bookmarks as $b): ?>
                    <button type="button" class="list-group-item list-group-item-action" data-seek="<?= (int) $b['at_ms'] ?>">
                        <span class="text-muted small me-2"><?= e(format_duration_ms($b['at_ms'])) ?></span><?= e($b['label']) ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <?php if ($canEdit): ?>
                <div class="card-body border-top">
                    <form method="post" action="/recordings/<?= $rid ?>/bookmarks" class="d-flex gap-2">
                        <?= $view->csrfField() ?>
                        <input class="form-control form-control-sm w-25" name="at" placeholder="00:00:00" data-role="current-time">
                        <input class="form-control form-control-sm" name="label" placeholder="Label" required>
                        <button class="btn btn-sm btn-outline-primary" type="submit">Add</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <div class="card jc-card mb-3">
            <div class="card-header"><i class="bi bi-shield-check"></i> Integrity of the original</div>
            <div class="card-body small">
                <?php if ($rec['sha256'] !== null): ?>
                    <dl class="mb-2">
                        <dt>SHA-256 of original audio</dt><dd class="jc-hash"><?= e($rec['sha256']) ?></dd>
                        <?php if ($rec['chunk_chain']): ?><dt>Capture chain hash</dt><dd class="jc-hash"><?= e($rec['chunk_chain']) ?></dd><?php endif; ?>
                        <dt>Duration</dt><dd><?= e(format_duration_ms($rec['duration_ms'])) ?> (<?= e(format_bytes($rec['bytes'])) ?> PCM, <?= (int) $rec['sample_rate'] ?> Hz)</dd>
                        <dt>Last verified</dt><dd><?= e(local_time($rec['integrity_verified_at'])) ?></dd>
                    </dl>
                    <form method="post" action="/recordings/<?= $rid ?>/verify"><?= $view->csrfField() ?><button class="btn btn-sm btn-outline-secondary" type="submit">Re-verify now</button></form>
                <?php else: ?>
                    <p class="mb-0 text-muted">Not finalised yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card jc-card mb-3">
            <div class="card-header"><i class="bi bi-patch-check"></i> Certification</div>
            <div class="card-body small">
                <?php if ($certified): ?>
                    <p class="mb-1">Certified <?= e(local_time($rec['certified_at'])) ?>.</p>
                    <p class="mb-1">Certificate hash: <span class="jc-hash"><?= e($rec['certification_hash']) ?></span></p>
                    <?php if ($certificationValid): ?>
                        <div class="alert alert-success py-1 px-2 mb-0">Transcript matches its certificate.</div>
                    <?php else: ?>
                        <div class="alert alert-danger py-1 px-2 mb-0">The transcript no longer matches its certificate. Investigate immediately.</div>
                    <?php endif; ?>
                <?php elseif ($auth->can('transcripts.certify')): ?>
                    <p>Certifying freezes the transcript as the official record of these proceedings. Review every passage against the audio first.</p>
                    <form method="post" action="/recordings/<?= $rid ?>/certify">
                        <?= $view->csrfField() ?>
                        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="confirm" value="yes" id="certify-confirm" required><label class="form-check-label" for="certify-confirm">I have reviewed this transcript against the recording and certify it as accurate.</label></div>
                        <button class="btn btn-success btn-sm" type="submit" <?= $rec['sha256'] === null ? 'disabled' : '' ?>>Certify transcript</button>
                    </form>
                <?php else: ?>
                    <p class="mb-0 text-muted">Draft. Only the judge can certify a transcript.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="d-grid gap-2">
            <a class="btn btn-outline-secondary" href="/recordings/<?= $rid ?>/export" target="_blank" rel="noopener"><i class="bi bi-printer"></i> Printable transcript</a>
            <?php if ($canEdit && $rec['sha256'] !== null): ?>
                <form method="post" action="/recordings/<?= $rid ?>/retranscribe" data-confirm="Run machine transcription again? Passages you have corrected will be kept."><?= $view->csrfField() ?><button class="btn btn-outline-secondary w-100" type="submit"><i class="bi bi-arrow-repeat"></i> Re-run machine transcription</button></form>
            <?php endif; ?>
        </div>
    </div>
</div>
