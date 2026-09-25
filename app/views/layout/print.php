<?php /** @var string $content */ ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= e($title ?? 'Document') ?></title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="jc-print">
<div class="container py-4">
    <div class="d-print-none mb-3 d-flex gap-2">
        <button type="button" class="btn btn-primary btn-sm" data-print>Print / save as PDF</button>
        <a href="/" class="btn btn-outline-secondary btn-sm" data-back>Back</a>
    </div>
    <?= $content ?>
</div>
<script src="/assets/app.js"></script>
</body>
</html>
