<?php
/** @var JC\Core\App $app */
/** @var JC\Service\Auth $auth */
/** @var string $content */
$user = $auth->user();
$chambers = $auth->chambers();
$flash = $app->session()->takeFlash();
$path = $app->request()?->path ?? '/';
$nav = static fn (string $prefix) => ($prefix === '/' ? $path === '/' : str_starts_with($path, $prefix)) ? ' active' : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Judicial Chambers') ?> · Judicial Chambers</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body data-csrf="<?= e($app->session()->csrfToken()) ?>">
<nav class="navbar navbar-expand-lg jc-navbar" data-bs-theme="dark">
    <div class="container-fluid">
        <a class="navbar-brand d-flex align-items-center gap-2" href="/">
            <i class="bi bi-bank2"></i>
            <span>Judicial Chambers</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto">
                <?php if ($chambers): ?>
                    <li class="nav-item"><a class="nav-link<?= $nav('/') ?>" href="/"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
                    <?php if ($auth->can('cases.view')): ?>
                        <li class="nav-item"><a class="nav-link<?= $nav('/cases') ?>" href="/cases"><i class="bi bi-folder2-open"></i> Cases</a></li>
                        <li class="nav-item"><a class="nav-link<?= $nav('/calendar') ?>" href="/calendar"><i class="bi bi-calendar3"></i> Calendar</a></li>
                    <?php endif; ?>
                    <li class="nav-item"><a class="nav-link<?= $nav('/tasks') ?>" href="/tasks"><i class="bi bi-check2-square"></i> Tasks</a></li>
                    <?php if ($auth->can('staff.manage')): ?>
                        <li class="nav-item"><a class="nav-link<?= $nav('/staff') ?>" href="/staff"><i class="bi bi-people"></i> Staff</a></li>
                    <?php endif; ?>
                    <?php if ($auth->can('audit.view')): ?>
                        <li class="nav-item"><a class="nav-link<?= $nav('/audit') ?>" href="/audit"><i class="bi bi-shield-check"></i> Audit</a></li>
                    <?php endif; ?>
                <?php endif; ?>
            </ul>
            <ul class="navbar-nav align-items-lg-center">
                <?php $memberships = $auth->memberships(); if (count($memberships) > 1): ?>
                    <li class="nav-item me-2">
                        <form method="post" action="/chambers/switch" class="d-flex">
                            <?= $view->csrfField() ?>
                            <select name="chambers_id" class="form-select form-select-sm" data-autosubmit aria-label="Switch chambers">
                                <?php foreach ($memberships as $m): ?>
                                    <option value="<?= (int) $m['chambers_id'] ?>" <?= $m['chambers_id'] === $auth->chambersId() ? 'selected' : '' ?>><?= e($m['chambers_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </li>
                <?php endif; ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-person-circle"></i> <?= e($user['full_name']) ?>
                        <?php if ($auth->role()): ?><span class="badge text-bg-light ms-1"><?= e(JC\Security\Permissions::ROLES[$auth->role()] ?? $auth->role()) ?></span><?php endif; ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <?php if ($chambers): ?><li><span class="dropdown-item-text small text-muted"><?= e($chambers['name']) ?><br><?= e($chambers['court']) ?></span></li><li><hr class="dropdown-divider"></li><?php endif; ?>
                        <li><a class="dropdown-item" href="/account/password"><i class="bi bi-key"></i> Change password</a></li>
                        <?php if ((int) $user['mfa_enabled'] !== 1): ?><li><a class="dropdown-item" href="/mfa/setup"><i class="bi bi-phone"></i> Enable two-factor</a></li><?php endif; ?>
                        <li>
                            <form method="post" action="/logout"><?= $view->csrfField() ?><button class="dropdown-item" type="submit"><i class="bi bi-box-arrow-right"></i> Sign out</button></form>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
<main class="container-fluid jc-main py-4">
    <?php foreach ($flash as $type => $message): ?>
        <div class="alert alert-<?= e($type) ?> alert-dismissible fade show" role="alert">
            <?= e($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endforeach; ?>
    <?= $content ?>
</main>
<footer class="jc-footer text-muted small py-3">
    <div class="container-fluid d-flex flex-wrap justify-content-between gap-2">
        <span><i class="bi bi-lock-fill"></i> Encrypted at rest (AES-256-GCM) and in transit (TLS). All access is recorded in a tamper-evident audit trail.</span>
        <span>AI assists; the judge decides.</span>
    </div>
</footer>
<script src="/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="/assets/app.js"></script>
<?php foreach ($view->scripts() as $script): ?>
    <script src="<?= e($script) ?>"></script>
<?php endforeach; ?>
</body>
</html>
