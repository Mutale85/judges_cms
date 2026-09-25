<?php
/** @var JC\Core\App $app */
/** @var string $content */
$flash = $app->session()->takeFlash();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Sign in') ?> · Judicial Chambers</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="jc-guest">
<main class="container">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5 col-xl-4">
            <div class="text-center mb-4 mt-5">
                <i class="bi bi-bank2 jc-guest-logo"></i>
                <h1 class="h4 mt-2 mb-0">Judicial Chambers</h1>
                <p class="text-muted small">Digital chambers &amp; courtroom management</p>
            </div>
            <?php foreach ($flash as $type => $message): ?>
                <div class="alert alert-<?= e($type) ?>" role="alert"><?= e($message) ?></div>
            <?php endforeach; ?>
            <?= $content ?>
            <p class="text-center text-muted small mt-4"><i class="bi bi-lock-fill"></i> Authorised judicial personnel only. Access is logged.</p>
        </div>
    </div>
</main>
<script src="/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="/assets/app.js"></script>
<?php foreach ($view->scripts() as $script): ?>
    <script src="<?= e($script) ?>"></script>
<?php endforeach; ?>
</body>
</html>
