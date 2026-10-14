<div class="row">
    <div class="col-xl-8">
        <div class="card jc-card">
            <div class="card-body">
                <form method="post" action="/admin/settings">
                    <?= $view->csrfField() ?>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" value="1" id="signup_enabled" name="signup_enabled" <?= ($settings['signup_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="signup_enabled">Allow new workspaces to sign up</label>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4"><label class="form-label" for="trial_days">Free trial (days)</label><input class="form-control" type="number" min="0" max="365" id="trial_days" name="trial_days" value="<?= e($settings['trial_days'] ?? '30') ?>" required></div>
                        <div class="col-md-4">
                            <label class="form-label" for="trial_plan">Plan during the trial</label>
                            <select class="form-select" id="trial_plan" name="trial_plan">
                                <?php foreach ($plans as $p): ?><option value="<?= e($p['code']) ?>" <?= ($settings['trial_plan'] ?? '') === $p['code'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4"><label class="form-label" for="grace_days">Grace after expiry (days)</label><input class="form-control" type="number" min="0" max="365" id="grace_days" name="grace_days" value="<?= e($settings['grace_days'] ?? '7') ?>" required></div>
                        <div class="col-md-4"><label class="form-label" for="currency">Default currency</label><input class="form-control text-uppercase" id="currency" name="currency" maxlength="3" value="<?= e($settings['currency'] ?? 'ZMW') ?>" required></div>
                        <div class="col-md-8"><label class="form-label" for="support_email">Support e-mail</label><input class="form-control" type="email" id="support_email" name="support_email" value="<?= e($settings['support_email'] ?? '') ?>" required></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="payment_instructions">Payment instructions (shown to workspace owners on the billing page)</label>
                        <textarea class="form-control" id="payment_instructions" name="payment_instructions" rows="6" placeholder="Mobile money number, bank name, account number, branch, SWIFT…"><?= e($settings['payment_instructions'] ?? '') ?></textarea>
                    </div>
                    <button class="btn btn-dark" type="submit">Save settings</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-xl-4 mt-4 mt-xl-0">
        <div class="card jc-card"><div class="card-body small text-muted">
            <p><strong>Trial</strong>: new workspaces start on the chosen plan until the trial ends; then they become read-only until a payment is confirmed.</p>
            <p><strong>Grace</strong>: paid workspaces stay editable this many days after their paid-up date, with a reminder banner.</p>
            <p class="mb-0">Administrators are created from the server with <code>php bin/jc admin:create</code>.</p>
        </div></div>
    </div>
</div>
