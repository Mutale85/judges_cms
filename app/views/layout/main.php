<?php
/** @var JC\Core\App $app */
/** @var JC\Service\Auth $auth */
/** @var string $content */
use JC\Security\Permissions;
use JC\Service\SubscriptionService;

$user = $auth->user();
$chambers = $auth->chambers();
$flash = $app->session()->takeFlash();
$path = $app->request()?->path ?? '/dashboard';
$nav = static fn (string $prefix) => str_starts_with($path, $prefix) ? ' active' : '';
$notice = $chambers ? $app->service(SubscriptionService::class)->notice($chambers) : null;
$hour = (int) (new DateTimeImmutable('now', app_timezone()))->format('G');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? app_name()) ?> · <?= e(app_name()) ?></title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/vendor/sweetalert2/sweetalert2.min.css">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="jc-app" data-csrf="<?= e($app->session()->csrfToken()) ?>">
<aside class="jc-sidebar" aria-label="Workspace navigation">
    <a class="jc-brand" href="/dashboard"><span class="jc-mark">J</span><?= e(app_name()) ?></a>
    <?php if ($chambers): ?>
        <div class="jc-workspace">
            <div class="fw-semibold text-truncate" title="<?= e($chambers['name']) ?>"><?= e($chambers['name']) ?></div>
            <div class="small text-secondary text-truncate"><?= e($chambers['court']) ?></div>
        </div>
        <nav class="jc-nav">
            <a class="<?= $nav('/dashboard') ?>" href="/dashboard"><i class="bi bi-grid-1x2"></i> <?= e(term('Chambers') === 'Tribunal' ? 'Tribunal overview' : 'Bench overview') ?></a>
            <?php if ($auth->can('cases.view')): ?>
                <a class="<?= $nav('/cases') ?>" href="/cases"><i class="bi bi-folder2-open"></i> <?= e(term('Case')) ?> registry</a>
                <a class="<?= $nav('/calendar') ?>" href="/calendar"><i class="bi bi-calendar3"></i> Calendar</a>
                <a class="<?= $nav('/transcription') . $nav('/recordings') ?>" href="/transcription"><i class="bi bi-mic"></i> Transcription</a>
            <?php endif; ?>
            <a class="<?= $nav('/reminders') ?>" href="/reminders"><i class="bi bi-bell"></i> Reminders</a>
            <a class="<?= $nav('/tasks') ?>" href="/tasks"><i class="bi bi-check2-square"></i> Tasks</a>
            <?php if ($auth->can('staff.manage')): ?>
                <a class="<?= $nav('/staff') ?>" href="/staff"><i class="bi bi-people"></i> Team</a>
            <?php endif; ?>
            <?php if ($auth->can('reports.view')): ?>
                <a class="<?= $nav('/reports') ?>" href="/reports"><i class="bi bi-bar-chart"></i> Returns</a>
            <?php endif; ?>
            <?php if ($auth->can('audit.view')): ?>
                <a class="<?= $nav('/audit') ?>" href="/audit"><i class="bi bi-shield-check"></i> Audit trail</a>
            <?php endif; ?>
            <?php if ($auth->isJudge()): ?>
                <a class="<?= $nav('/billing') ?>" href="/billing"><i class="bi bi-credit-card"></i> Workspace &amp; billing</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
    <?php if ($auth->isAdmin()): ?>
        <nav class="jc-nav mt-auto"><a href="/admin"><i class="bi bi-speedometer"></i> Platform admin</a></nav>
    <?php endif; ?>
</aside>
<div class="jc-shade" data-sidebar-toggle></div>

<div class="jc-shell">
    <header class="jc-topbar">
        <button class="btn btn-sm btn-light d-lg-none" type="button" data-sidebar-toggle aria-label="Menu"><i class="bi bi-list"></i></button>
        <div class="jc-greeting d-none d-md-block"><?= $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening') ?>, <?= e($user['full_name']) ?></div>
        <div class="ms-auto d-flex align-items-center gap-2">
            <?php $memberships = $auth->memberships(); if (count($memberships) > 1): ?>
                <form method="post" action="/chambers/switch" class="d-none d-sm-block">
                    <?= $view->csrfField() ?>
                    <select name="chambers_id" class="form-select form-select-sm" data-autosubmit aria-label="Switch workspace">
                        <?php foreach ($memberships as $m): ?>
                            <option value="<?= (int) $m['chambers_id'] ?>" <?= $m['chambers_id'] === $auth->chambersId() ? 'selected' : '' ?>><?= e($m['chambers_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
            <?php if ($chambers): ?>
                <div class="dropdown">
                    <button class="btn btn-light btn-sm position-relative jc-bell" id="jc-bell" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Reminders due">
                        <i class="bi bi-bell"></i>
                        <span class="jc-bell-count badge rounded-pill d-none">0</span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end jc-bell-menu p-0">
                        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                            <span class="fw-semibold small">Due this week</span>
                            <a class="small" href="/reminders">All reminders</a>
                        </div>
                        <div id="jc-bell-list"><div class="px-3 py-3 text-muted small">Loading…</div></div>
                    </div>
                </div>
            <?php endif; ?>
            <div class="dropdown">
                <button class="btn btn-light btn-sm dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="jc-avatar"><?= e(mb_strtoupper(mb_substr(preg_replace('/^(Hon\.?|Mr|Mrs|Ms|Dr|Justice|Judge)\s+/i', '', trim($user['full_name'])) ?: 'U', 0, 1))) ?></span>
                    <span class="d-none d-sm-inline text-truncate jc-user-name"><?= e($user['full_name']) ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><span class="dropdown-item-text small text-muted"><?= e($user['email']) ?><?php if ($auth->role()): ?><br><?= e(Permissions::roleLabel($auth->role())) ?><?php endif; ?></span></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="/account/password"><i class="bi bi-key"></i> Change password</a></li>
                    <?php if ((int) $user['mfa_enabled'] !== 1): ?><li><a class="dropdown-item" href="/mfa/setup"><i class="bi bi-phone"></i> Enable two-factor</a></li><?php endif; ?>
                    <?php if ($auth->isJudge()): ?><li><a class="dropdown-item" href="/billing"><i class="bi bi-credit-card"></i> Workspace &amp; billing</a></li><?php endif; ?>
                    <li><a class="dropdown-item" href="/support"><i class="bi bi-life-preserver"></i> Support</a></li>
                    <li><form method="post" action="/logout"><?= $view->csrfField() ?><button class="dropdown-item" type="submit"><i class="bi bi-box-arrow-right"></i> Sign out</button></form></li>
                </ul>
            </div>
        </div>
    </header>

    <?php if ($notice): ?>
        <div class="jc-banner jc-banner-<?= e($notice[0]) ?>">
            <i class="bi bi-<?= $notice[0] === 'danger' ? 'lock' : 'hourglass-split' ?>"></i>
            <span><?= e($notice[1]) ?></span>
            <?php if ($auth->isJudge()): ?><a href="/billing" class="ms-2 fw-semibold">Renew or choose a plan</a><?php else: ?><span class="ms-1">Ask the workspace owner to renew.</span><?php endif; ?>
        </div>
    <?php endif; ?>

    <main class="jc-main">
        <?php foreach ($flash as $type => $message): ?>
            <div class="alert alert-<?= e($type) ?> alert-dismissible fade show jc-flash" data-type="<?= e($type) ?>" data-message="<?= e($message) ?>" role="alert">
                <?= e($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endforeach; ?>
        <?= $content ?>
    </main>
    <footer class="jc-footer">
        <span><i class="bi bi-lock-fill"></i> Encrypted at rest (AES-256-GCM) and in transit (TLS). Access is recorded in a tamper-evident audit trail.</span>
        <span>AI assists; the <?= e(term('judge')) ?> decides.</span>
    </footer>
</div>
<script src="/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="/assets/vendor/sweetalert2/sweetalert2.min.js"></script>
<script src="/assets/app.js"></script>
<?php foreach ($view->scripts() as $script): ?>
    <script src="<?= e($script) ?>"></script>
<?php endforeach; ?>
</body>
</html>
