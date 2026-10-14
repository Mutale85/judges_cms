<?php
use JC\Security\Permissions;

$back = '/admin/users?' . http_build_query(array_filter(['q' => $q, 'filter' => $filter, 'page' => $pageNo > 1 ? $pageNo : null]));
?>
<div class="card jc-card">
    <div class="card-header small text-muted"><?= (int) $total ?> user<?= $total === 1 ? '' : 's' ?></div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>Name</th><th>Workspaces &amp; roles</th><th>Security</th><th>Registered</th><th>Last sign-in</th><th></th></tr></thead>
            <tbody>
            <?php if (!$users): ?><tr><td colspan="6" class="text-center text-muted py-4">No users match.</td></tr><?php endif; ?>
            <?php foreach ($users as $u): $uid = (int) $u['id']; ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= e($u['full_name']) ?><?php if ((int) $u['is_admin'] === 1): ?> <span class="badge text-bg-dark">Admin</span><?php endif; ?></div>
                        <div class="small text-muted"><?= e($u['email']) ?><?= $u['phone'] ? ' · ' . e($u['phone']) : '' ?></div>
                    </td>
                    <td class="small">
                        <?php if (!$u['memberships']): ?><span class="text-muted">—</span><?php endif; ?>
                        <?php foreach ($u['memberships'] as $m): ?>
                            <div><a href="/admin/workspaces/<?= (int) $m['chambers_id'] ?>"><?= e($m['name']) ?></a> · <?= e(Permissions::ROLES[$m['role']] ?? $m['role']) ?><?= $m['status'] !== 'active' ? ' (suspended)' : '' ?></div>
                        <?php endforeach; ?>
                    </td>
                    <td class="small text-nowrap">
                        <div><?= $u['email_verified_at'] ? '<i class="bi bi-envelope-check text-success"></i> Confirmed' : '<i class="bi bi-envelope-exclamation text-warning"></i> Unconfirmed' ?></div>
                        <div><?= (int) $u['mfa_enabled'] === 1 ? '<i class="bi bi-shield-fill-check text-success"></i> 2FA on' : '<i class="bi bi-shield-exclamation text-warning"></i> 2FA not set up' ?></div>
                        <?php if ($u['status'] !== 'active'): ?><span class="badge text-bg-danger">Suspended</span><?php endif; ?>
                    </td>
                    <td class="small text-nowrap"><?= e(local_time($u['created_at'], 'j M Y')) ?></td>
                    <td class="small text-nowrap"><?= $u['last_login_at'] ? e(local_time($u['last_login_at'], 'j M Y H:i')) : '<span class="text-muted">Never</span>' ?></td>
                    <td class="text-end">
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" type="button" aria-expanded="false">Manage</button>
                            <div class="dropdown-menu dropdown-menu-end p-1">
                                <?php if ($u['email_verified_at'] === null): ?>
                                    <form method="post" action="/admin/users/<?= $uid ?>/verify" data-confirm="Mark <?= e($u['email']) ?> as confirmed without the e-mail link?"><?= $view->csrfField() ?><input type="hidden" name="back" value="<?= e($back) ?>"><button class="dropdown-item" type="submit"><i class="bi bi-envelope-check"></i> Mark e-mail confirmed</button></form>
                                <?php endif; ?>
                                <form method="post" action="/admin/users/<?= $uid ?>/reset-mfa" data-confirm="Reset two-factor sign-in for <?= e($u['full_name']) ?>? Only do this after verifying their identity." data-danger><?= $view->csrfField() ?><input type="hidden" name="back" value="<?= e($back) ?>"><button class="dropdown-item" type="submit"><i class="bi bi-phone"></i> Reset two-factor</button></form>
                                <form method="post" action="/admin/users/<?= $uid ?>/reset-password" data-confirm="Issue a temporary password for <?= e($u['full_name']) ?>? Only do this after verifying their identity." data-danger><?= $view->csrfField() ?><input type="hidden" name="back" value="<?= e($back) ?>"><button class="dropdown-item" type="submit"><i class="bi bi-key"></i> Issue temporary password</button></form>
                                <?php if ($uid !== $auth->id()): ?>
                                    <form method="post" action="/admin/users/<?= $uid ?>/status" data-confirm="<?= $u['status'] === 'active' ? 'Suspend ' . e($u['full_name']) . '? They will be signed out and cannot sign in.' : 'Reactivate ' . e($u['full_name']) . '?' ?>" <?= $u['status'] === 'active' ? 'data-danger' : '' ?>>
                                        <?= $view->csrfField() ?><input type="hidden" name="back" value="<?= e($back) ?>">
                                        <input type="hidden" name="status" value="<?= $u['status'] === 'active' ? 'suspended' : 'active' ?>">
                                        <button class="dropdown-item <?= $u['status'] === 'active' ? 'text-danger' : '' ?>" type="submit"><i class="bi bi-<?= $u['status'] === 'active' ? 'slash-circle' : 'check-circle' ?>"></i> <?= $u['status'] === 'active' ? 'Suspend account' : 'Reactivate account' ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?>
        <div class="card-footer d-flex justify-content-between small">
            <span>Page <?= (int) $pageNo ?> of <?= (int) $pages ?></span>
            <span class="d-flex gap-2">
                <?php if ($pageNo > 1): ?><a href="/admin/users?<?= e(http_build_query(['q' => $q, 'filter' => $filter, 'page' => $pageNo - 1])) ?>">Previous</a><?php endif; ?>
                <?php if ($pageNo < $pages): ?><a href="/admin/users?<?= e(http_build_query(['q' => $q, 'filter' => $filter, 'page' => $pageNo + 1])) ?>">Next</a><?php endif; ?>
            </span>
        </div>
    <?php endif; ?>
</div>
