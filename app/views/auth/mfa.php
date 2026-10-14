<div class="site-form-card">
    <div>
        <h1 class="h4 mb-1">Two-factor authentication</h1>
        <p class="text-muted small">Enter the 6-digit code from your authenticator app.</p>
        <form method="post" action="/login/mfa" autocomplete="off">
            <?= $view->csrfField() ?>
            <div class="mb-3">
                <input type="text" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" class="form-control form-control-lg text-center jc-otp" name="code" required autofocus autocomplete="one-time-code" aria-label="Authentication code">
            </div>
            <button type="submit" class="btn btn-dark w-100 site-btn">Verify</button>
        </form>
        <form method="post" action="/logout" class="mt-3 text-center"><?= $view->csrfField() ?><button class="btn btn-link btn-sm" type="submit">Cancel</button></form>
    </div>
</div>
