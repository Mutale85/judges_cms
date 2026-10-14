<?php use JC\Service\HearingService; ?>
<div class="text-center mb-4">
    <div class="text-uppercase small fw-semibold"><?= e($chambers['court'] ?? '') ?></div>
    <h1 class="h4 mb-0"><?= e(term('Cause list')) ?></h1>
    <div><?= e($chambers['name'] ?? '') ?></div>
    <div class="fw-semibold"><?= e($date->format('l, j F Y')) ?></div>
</div>
<table class="table table-bordered">
    <thead><tr><th>#</th><th>Time</th><th><?= e(term('Case number')) ?></th><th>Parties</th><th>Business</th><th>Courtroom</th></tr></thead>
    <tbody>
    <?php if (!$items): ?><tr><td colspan="6" class="text-center text-muted">No matters listed.</td></tr><?php endif; ?>
    <?php foreach ($items as $i => $h): ?>
        <tr>
            <td><?= $i + 1 ?></td>
            <td><?= e(local_time($h['scheduled_at'], 'H:i')) ?></td>
            <td><?= (int) $h['sealed'] === 1 ? 'SEALED MATTER' : e($h['case_number']) ?></td>
            <td><?= (int) $h['sealed'] === 1 ? '—' : e($h['case_title']) ?></td>
            <td><?= e(HearingService::TYPES[$h['hearing_type']] ?? '') ?></td>
            <td><?= e($h['courtroom'] ?? '') ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<p class="small text-muted">Sealed matters are listed without identifying details.</p>
