<?php
/** @var JC\Core\App $app */
/** @var string $content */
$flash = $app->session()->takeFlash();
$signedIn = $auth->check();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? app_name()) ?> · <?= e(app_name()) ?></title>
    <meta name="description" content="Private case administration for judges, arbitrators and their marshals: case registry, reminders, hearing transcription and returns, encrypted and walled off per chambers.">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/vendor/sweetalert2/sweetalert2.min.css">
    <link rel="stylesheet" href="/assets/site.css">
</head>
<body class="site" data-csrf="<?= e($app->session()->csrfToken()) ?>">
<header class="site-header">
    <div class="site-container d-flex align-items-center justify-content-between gap-3">
        <a href="/" class="site-brand"><span class="site-mark">J</span><?= e(app_name()) ?></a>
        <input type="checkbox" id="site-nav-toggle" class="site-nav-toggle" aria-label="Menu">
        <label for="site-nav-toggle" class="site-nav-burger d-md-none" aria-hidden="true"><i class="bi bi-list"></i></label>
        <nav class="site-nav" aria-label="Main">
            <a href="/#features">Features</a>
            <a href="/#how">How it works</a>
            <a href="/#security">Security</a>
            <a href="/pricing">Pricing</a>
            <a href="/#faq">FAQ</a>
            <?php if ($signedIn): ?>
                <a href="/dashboard" class="btn btn-dark btn-sm site-cta">Open workspace</a>
            <?php else: ?>
                <a href="/login">Sign in</a>
                <a href="/signup" class="btn btn-dark btn-sm site-cta">Create workspace</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<?php foreach ($flash as $type => $message): ?>
    <div class="site-container mt-3 jc-flash" data-type="<?= e($type) ?>" data-message="<?= e($message) ?>">
        <div class="alert alert-<?= e($type) ?> mb-0" role="alert"><?= e($message) ?></div>
    </div>
<?php endforeach; ?>

<main>
    <?= $content ?>
</main>

<footer class="site-footer">
    <div class="site-container">
        <div class="row g-4">
            <div class="col-md-4">
                <a href="/" class="site-brand"><span class="site-mark">J</span><?= e(app_name()) ?></a>
                <p class="small text-secondary mt-2 mb-0">Private case administration for judges,<br>arbitrators and their marshals.</p>
            </div>
            <div class="col-6 col-md-2 offset-md-2">
                <div class="site-footer-head">Product</div>
                <a href="/#features">Features</a><a href="/#how">How it works</a><a href="/#security">Security</a><a href="/pricing">Pricing</a><a href="/#faq">FAQ</a>
            </div>
            <div class="col-6 col-md-2">
                <div class="site-footer-head">Account</div>
                <a href="/login">Sign in</a><a href="/signup">Create workspace</a><a href="/forgot-password">Reset password</a><a href="/support">Support</a>
            </div>
            <div class="col-6 col-md-2">
                <div class="site-footer-head">Legal</div>
                <a href="/terms">Terms of Use</a><a href="/privacy">Privacy Policy</a><a href="/cookies">Cookie Policy</a>
            </div>
        </div>
        <div class="site-footer-bottom d-flex flex-wrap justify-content-between gap-2">
            <span>© <?= e(date('Y')) ?> <?= e(JC\Core\Config::get('APP_COMPANY', 'Linksys Zambia Limited')) ?>. All rights reserved.</span>
            <span>Made in Zambia</span>
        </div>
    </div>
</footer>
<script src="/assets/vendor/sweetalert2/sweetalert2.min.js"></script>
<script src="/assets/app.js"></script>
<?php foreach ($view->scripts() as $script): ?>
    <script src="<?= e($script) ?>"></script>
<?php endforeach; ?>
</body>
</html>
