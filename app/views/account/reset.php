<div class="site-form-wrap">
    <div class="site-form-card">
        <h1>Choose a new password</h1>
        <form method="post" action="/reset-password">
            <?= $view->csrfField() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <div class="mb-3">
                <label class="form-label" for="password">New password</label>
                <input class="form-control" type="password" id="password" name="password" required minlength="12" autocomplete="new-password" autofocus>
                <div class="form-text">At least 12 characters, mixing three of: lower case, upper case, digits and symbols.</div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="password_confirmation">Confirm new password</label>
                <input class="form-control" type="password" id="password_confirmation" name="password_confirmation" required minlength="12" autocomplete="new-password">
            </div>
            <button class="btn btn-dark w-100 site-btn" type="submit">Save new password</button>
        </form>
    </div>
</div>
