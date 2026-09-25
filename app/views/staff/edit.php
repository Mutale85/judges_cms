<?php use JC\Security\Permissions; $mid = (int) $member['id']; ?>
<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="/staff">Staff</a></li><li class="breadcrumb-item active"><?= e($member['full_name']) ?></li></ol></nav>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card jc-card">
            <div class="card-header"><?= e($member['full_name']) ?> · <?= e($member['email']) ?></div>
            <div class="card-body">
                <form method="post" action="/staff/<?= $mid ?>">
                    <?= $view->csrfField() ?>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Role</label>
                            <select class="form-select" name="role">
                                <?php foreach (Permissions::ROLES as $k => $v): if ($k === 'judge') continue; ?><option value="<?= e($k) ?>" <?= $member['role'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
                            </select>
                            <div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="reset_defaults" value="1" id="reset_defaults"><label class="form-check-label small" for="reset_defaults">When changing role, reset permissions to the new role's defaults</label></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="active" <?= $member['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="suspended" <?= $member['status'] === 'suspended' ? 'selected' : '' ?>>Suspended (no access)</option>
                            </select>
                        </div>
                    </div>
                    <label class="form-label">Permissions</label>
                    <div class="row">
                        <?php foreach (Permissions::delegable() as $cap => $desc): ?>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="permissions[]" value="<?= e($cap) ?>" id="cap-<?= e($cap) ?>" <?= in_array($cap, $member['permissions'], true) ? 'checked' : '' ?>>
                                    <label class="form-check-label small" for="cap-<?= e($cap) ?>"><?= e($desc) ?></label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="small text-muted mt-2 mb-3">Reserved to the judge and never delegable: <?= e(implode(', ', array_map(static fn ($c) => Permissions::CAPABILITIES[$c], Permissions::JUDGE_ONLY))) ?>.</p>
                    <button class="btn btn-primary" type="submit">Save</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card jc-card">
            <div class="card-header">Account recovery</div>
            <div class="card-body d-grid gap-2">
                <form method="post" action="/staff/<?= $mid ?>/reset-password" data-confirm="Issue a new temporary password?"><?= $view->csrfField() ?><button class="btn btn-outline-secondary w-100" type="submit">Reset password</button></form>
                <form method="post" action="/staff/<?= $mid ?>/reset-mfa" data-confirm="Reset two-factor authentication? They will need to enrol again."><?= $view->csrfField() ?><button class="btn btn-outline-secondary w-100" type="submit">Reset two-factor</button></form>
                <div class="small text-muted">Last sign-in: <?= e(local_time($member['last_login_at']) ?: 'never') ?></div>
            </div>
        </div>
    </div>
</div>
