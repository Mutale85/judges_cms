<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card jc-card">
            <div class="card-body p-4">
                <h1 class="h5">Change password</h1>
                <?php if ($auth->mustChangePassword()): ?>
                    <div class="alert alert-info small">You are using a temporary password. Please choose your own before continuing.</div>
                <?php endif; ?>
                <form method="post" action="/account/password">
                    <?= $view->csrfField() ?>
                    <div class="mb-3">
                        <label class="form-label" for="current_password">Current password</label>
                        <input class="form-control" type="password" id="current_password" name="current_password" required autocomplete="current-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="new_password">New password</label>
                        <input class="form-control" type="password" id="new_password" name="new_password" required minlength="12" autocomplete="new-password">
                        <div class="form-text">At least 12 characters, mixing three of: lower case, upper case, digits, symbols.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="confirm_password">Confirm new password</label>
                        <input class="form-control" type="password" id="confirm_password" name="confirm_password" required minlength="12" autocomplete="new-password">
                    </div>
                    <button class="btn btn-primary" type="submit">Change password</button>
                </form>
            </div>
        </div>
    </div>
</div>
