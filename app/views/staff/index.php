<?php use JC\Security\Permissions; ?>
<h1 class="h3 mb-3">Team</h1>
<div class="row g-4">
    <div class="col-xl-8">
        <div class="card jc-card">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Name</th><th>Role</th><th>Permissions</th><th>2FA</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($members as $m): ?>
                        <tr>
                            <td><div class="fw-semibold"><?= e($m['full_name']) ?></div><div class="small text-muted"><?= e($m['email']) ?></div></td>
                            <td><?= e(Permissions::roleLabel($m['role'])) ?></td>
                            <td class="small"><?= $m['role'] === 'judge' ? 'All' : count($m['permissions']) . ' granted' ?></td>
                            <td><?= (int) $m['mfa_enabled'] === 1 ? '<i class="bi bi-shield-fill-check text-success" title="Enabled"></i>' : '<i class="bi bi-shield-exclamation text-warning" title="Not yet enrolled"></i>' ?></td>
                            <td><span class="badge <?= $m['status'] === 'active' ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= e(label($m['status'])) ?></span></td>
                            <td class="text-end"><?php if ($m['role'] !== 'judge'): ?><a class="btn btn-sm btn-outline-secondary" href="/staff/<?= (int) $m['id'] ?>">Manage</a><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card jc-card">
            <div class="card-header"><i class="bi bi-person-plus"></i> Invite a helper</div>
            <div class="card-body">
                <form method="post" action="/staff">
                    <?= $view->csrfField() ?>
                    <div class="mb-2"><input class="form-control" name="full_name" placeholder="Full name" required></div>
                    <div class="mb-2"><input class="form-control" type="email" name="email" placeholder="Official e-mail" required></div>
                    <div class="mb-2"><input class="form-control" name="phone" placeholder="Phone (optional)"></div>
                    <div class="mb-3">
                        <select class="form-select" name="role" required>
                            <?php foreach (Permissions::ROLES as $k => $v): if ($k === 'judge') continue; ?><option value="<?= e($k) ?>"><?= e(Permissions::roleLabel($k)) ?></option><?php endforeach; ?>
                        </select>
                        <div class="form-text">Default permissions follow the role; adjust them afterwards. New staff see only cases you assign to them.</div>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Add to <?= e(term('chambers')) ?></button>
                </form>
            </div>
        </div>
    </div>
</div>
