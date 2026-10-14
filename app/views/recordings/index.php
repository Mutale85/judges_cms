<?php
use JC\Service\HearingService;
use JC\Service\RecordingService;

$statusLabel = ['none' => 'No transcript yet', 'draft' => 'Machine draft', 'under_review' => 'Under review', 'certified' => 'Certified'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
        <h1 class="h3 mb-0">Transcription</h1>
        <div class="text-muted small">Record hearings live from a hearing page, or upload a dictation or a recording made elsewhere. Speech-to-text: <?= e(RecordingService::processorNotice()) ?>.</div>
    </div>
</div>

<div class="row g-4">
    <div class="<?= $canUpload ? 'col-xl-8' : 'col-12' ?>">
        <div class="card jc-card">
            <div class="card-header">Recent recordings</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>When</th><th><?= e(term('Case')) ?></th><th>Sitting</th><th>Source</th><th>Transcript</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$recordings): ?><tr><td colspan="6" class="text-muted text-center py-4">No recordings yet.</td></tr><?php endif; ?>
                    <?php foreach ($recordings as $rec): ?>
                        <tr>
                            <td class="text-nowrap small"><?= e(local_time($rec['started_at'] ?? $rec['created_at'], 'j M Y, H:i')) ?></td>
                            <td><a href="/cases/<?= (int) $rec['case_id'] ?>" class="fw-semibold"><?= e($rec['case_number']) ?></a><div class="small text-muted"><?= e(mb_strimwidth($rec['case_title'], 0, 48, '…')) ?></div></td>
                            <td class="small"><?= e(HearingService::TYPES[$rec['hearing_type']] ?? '') ?></td>
                            <td class="small"><?= ($rec['source'] ?? 'live') === 'upload' ? '<i class="bi bi-upload"></i> ' . e($rec['original_name'] ?? 'Upload') : '<i class="bi bi-broadcast"></i> Live' ?></td>
                            <td><span class="jc-pill"><?= e($rec['status'] === 'processing' ? 'Transcribing…' : ($rec['status'] === 'failed' ? 'Failed' : ($statusLabel[$rec['transcript_status']] ?? $rec['transcript_status']))) ?></span></td>
                            <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="/recordings/<?= (int) $rec['id'] ?>">Open</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php if ($canUpload): ?>
        <div class="col-xl-4">
            <div class="card jc-card">
                <div class="card-header"><i class="bi bi-upload"></i> Upload a dictation or recording</div>
                <div class="card-body">
                    <?php if (!$cases): ?>
                        <p class="text-muted small mb-0">Register a <?= e(term('case')) ?> first: every recording is saved against a <?= e(term('case')) ?>.</p>
                    <?php else: ?>
                        <form method="post" action="/transcription/upload" enctype="multipart/form-data">
                            <?= $view->csrfField() ?>
                            <div class="mb-2">
                                <label class="form-label small" for="up-case"><?= e(term('Case')) ?></label>
                                <select class="form-select" id="up-case" name="case_id" required>
                                    <option value="">Choose…</option>
                                    <?php foreach ($cases as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $preselectCase === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['case_number']) ?> — <?= e(mb_strimwidth($c['title'], 0, 40, '…')) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <?= $view->partial('recordings/_upload_fields') ?>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
