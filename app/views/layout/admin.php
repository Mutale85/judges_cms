<?php
/** @var JC\Core\App $app */
/** @var string $content */
$user = $auth->user();
$flash = $app->session()->takeFlash();
$path = $app->request()?->path ?? '/admin';
$nav = static fn (string $p) => ($p === '/admin' ? $path === '/admin' : str_starts_with($path, $p)) ? ' active' : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Admin') ?> · Admin · <?= e(app_name()) ?></title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/vendor/sweetalert2/sweetalert2.min.css">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="jc-app jc-admin" data-csrf="<?= e($app->session()->csrfToken()) ?>">
<aside class="jc-sidebar" aria-label="Administration">
    <a class="jc-brand" href="/admin"><span class="jc-mark">J</span><?= e(app_name()) ?></a>
    <div class="jc-workspace"><div class="fw-semibold">Platform administration</div><div class="small opacity-75">Accounts &amp; subscriptions</div></div>
    <nav class="jc-nav">
        <a class="<?= $nav('/admin') ?>" href="/admin"><i class="bi bi-speedometer"></i> Overview</a>
        <a class="<?= $nav('/admin/users') ?>" href="/admin/users"><i class="bi bi-people"></i> Registered users</a>
        <a class="<?= $nav('/admin/workspaces') ?>" href="/admin/workspaces"><i class="bi bi-building"></i> Workspaces</a>
        <a class="<?= $nav('/admin/payments') ?>" href="/admin/payments"><i class="bi bi-receipt"></i> Payments<?php if (!empty($pendingPayments)): ?> <span class="badge text-bg-warning ms-auto"><?= (int) $pendingPayments ?></span><?php endif; ?></a>
        <a class="<?= $nav('/admin/plans') ?>" href="/admin/plans"><i class="bi bi-tags"></i> Plans &amp; prices</a>
        <a class="<?= $nav('/admin/settings') ?>" href="/admin/settings"><i class="bi bi-gear"></i> Settings</a>
    </nav>
    <nav class="jc-nav mt-auto">
        <?php if ($auth->chambers()): ?><a href="/dashboard"><i class="bi bi-arrow-left"></i> Back to my workspace</a><?php endif; ?>
        <a href="/" ><i class="bi bi-globe"></i> Public site</a>
    </nav>
</aside>
<div class="jc-shade" data-sidebar-toggle></div>
<div class="jc-shell">
    <header class="jc-topbar">
        <button class="btn btn-sm btn-light d-lg-none" type="button" data-sidebar-toggle aria-label="Menu"><i class="bi bi-list"></i></button>
        <div class="jc-greeting"><?= e($title ?? 'Admin') ?></div>
        <div class="ms-auto dropdown">
            <button class="btn btn-light btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-person-badge"></i> <?= e($user['full_name']) ?></button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="/account/password"><i class="bi bi-key"></i> Change password</a></li>
                <li><form method="post" action="/logout"><?= $view->csrfField() ?><button class="dropdown-item" type="submit"><i class="bi bi-box-arrow-right"></i> Sign out</button></form></li>
            </ul>
        </div>
    </header>
    <main class="jc-main">
        <?php foreach ($flash as $type => $message): ?>
            <div class="alert alert-<?= e($type) ?> jc-flash" data-type="<?= e($type) ?>" data-message="<?= e($message) ?>" role="alert"><?= e($message) ?></div>
        <?php endforeach; ?>
        <?= $content ?>
    </main>
    <footer class="jc-footer"><span><i class="bi bi-shield-lock"></i> Administrators manage accounts and subscriptions — never case content. Every action here is recorded in the audit trail.</span></footer>
</div>
<script src="/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="/assets/vendor/sweetalert2/sweetalert2.min.js"></script>
<script src="/assets/app.js"></script>
</body>
</html>
