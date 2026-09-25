<?php
use JC\Service\HearingService;

$certified = $rec['transcript_status'] === 'certified';
?>
<?php if (!$certified): ?>
    <div class="jc-watermark">DRAFT — NOT AN OFFICIAL RECORD</div>
<?php endif; ?>
<div class="text-center mb-4">
    <div class="text-uppercase small fw-semibold"><?= e($chambers['court'] ?? '') ?></div>
    <h1 class="h4 mb-1"><?= $certified ? 'Certified transcript of proceedings' : 'Draft transcript of proceedings' ?></h1>
    <div><?= e($case['case_number']) ?> — <?= e($case['title']) ?></div>
    <div class="small"><?= e(HearingService::TYPES[$hearing['hearing_type']] ?? '') ?> · <?= e(local_time($rec['started_at'] ?? $hearing['scheduled_at'], 'l j F Y, H:i')) ?> · <?= e($chambers['name'] ?? '') ?></div>
</div>

<table class="table table-sm jc-transcript-table">
    <tbody>
    <?php foreach ($segments as $s): ?>
        <tr>
            <td class="text-nowrap text-muted small"><?= e(format_duration_ms($s['start_ms'])) ?></td>
            <td class="text-nowrap fw-semibold"><?= e($s['speaker'] ?? '') ?></td>
            <td><?= e($s['text']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<hr>
<div class="small">
    <div>Recording SHA-256: <span class="jc-hash"><?= e($rec['sha256'] ?? 'not finalised') ?></span></div>
    <?php if ($certified): ?>
        <div>Certified: <?= e(local_time($rec['certified_at'])) ?> · Certificate: <span class="jc-hash"><?= e($rec['certification_hash']) ?></span>
            (<?= $certificationValid ? 'verified' : 'VERIFICATION FAILED' ?>)</div>
    <?php else: ?>
        <div>This transcript was produced with machine assistance and has not been certified. It must be checked against the original recording.</div>
    <?php endif; ?>
</div>
