<?php
use JC\Service\HearingService;

$certified = $rec['transcript_status'] === 'certified';
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="utf-8">
<title><?= e($case['case_number']) ?> — transcript</title>
<style>
    body { font-family: "Times New Roman", serif; font-size: 12pt; }
    h1 { font-size: 14pt; text-align: center; margin: 0 0 4pt; }
    .c { text-align: center; }
    .muted { color: #555; font-size: 10pt; }
    table { border-collapse: collapse; width: 100%; margin-top: 12pt; }
    td { vertical-align: top; padding: 3pt 6pt; border-bottom: 0.5pt solid #ccc; }
    .t { white-space: nowrap; color: #555; font-size: 10pt; width: 1%; }
    .s { white-space: nowrap; font-weight: bold; width: 1%; }
</style>
</head>
<body>
<p class="c"><b><?= e(mb_strtoupper((string) ($chambers['court'] ?? ''))) ?></b></p>
<h1><?= $certified ? 'CERTIFIED TRANSCRIPT OF PROCEEDINGS' : 'DRAFT TRANSCRIPT OF PROCEEDINGS' ?></h1>
<p class="c"><?= e($case['case_number']) ?><br><?= e($case['title']) ?></p>
<p class="c muted"><?= e(HearingService::TYPES[$hearing['hearing_type']] ?? '') ?> · <?= e(local_time($rec['started_at'] ?? $hearing['scheduled_at'], 'l j F Y, H:i')) ?> · <?= e($chambers['name'] ?? '') ?></p>
<?php if (!$certified): ?>
<p class="c"><b>DRAFT — NOT AN OFFICIAL RECORD.</b> Produced with machine assistance; check it against the original recording.</p>
<?php endif; ?>
<table>
<?php foreach ($segments as $s): ?>
<tr><td class="t"><?= e(format_duration_ms($s['start_ms'])) ?></td><td class="s"><?= e($s['speaker'] ?? '') ?></td><td><?= e($s['text']) ?></td></tr>
<?php endforeach; ?>
</table>
<p class="muted">Recording SHA-256: <?= e($rec['sha256'] ?? 'not finalised') ?><?php if ($certified): ?><br>Certified <?= e(local_time($rec['certified_at'])) ?> · certificate <?= e($rec['certification_hash']) ?><?php endif; ?></p>
</body>
</html>
