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
    <title><?= e($title ?? 'Sign in') ?> · <?= e(app_name()) ?></title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/vendor/sweetalert2/sweetalert2.min.css">
    <link rel="stylesheet" href="/assets/site.css">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="site jc-guest" data-csrf="<?= e($app->session()->csrfToken()) ?>">
<header class="site-header">
    <div class="site-container d-flex align-items-center justify-content-between">
        <a href="/" class="site-brand"><span class="site-mark">J</span><?= e(app_name()) ?></a>
        <a href="/signup" class="small text-secondary text-decoration-none">Create workspace</a>
    </div>
</header>
<main class="site-form-wrap">
    <?php foreach ($flash as $type => $message): ?>
        <div class="alert alert-<?= e($type) ?> jc-flash" data-type="<?= e($type) ?>" data-message="<?= e($message) ?>" role="alert"><?= e($message) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
    <p class="text-center text-secondary small mt-4"><i class="bi bi-lock-fill"></i> Authorised judicial personnel only. Access is logged.</p>
</main>
<script src="/assets/vendor/sweetalert2/sweetalert2.min.js"></script>
<script src="/assets/app.js"></script>
<?php foreach ($view->scripts() as $script): ?>
    <script src="<?= e($script) ?>"></script>
<?php endforeach; ?>
</body>
</html>
