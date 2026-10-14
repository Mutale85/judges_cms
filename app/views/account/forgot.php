<div class="site-form-wrap">
    <div class="site-form-card">
        <h1>Reset your password</h1>
        <p class="text-secondary small">Enter the e-mail address you sign in with. We will send a link that works for one hour. You will still need your authenticator app to sign in.</p>
        <?php if ($devLink): ?>
            <div class="alert alert-secondary small"><strong>Development mode</strong> (MAIL_DRIVER=log): <a href="<?= e($devLink) ?>">open the reset link</a>.</div>
        <?php endif; ?>
        <form method="post" action="/forgot-password">
            <?= $view->csrfField() ?>
            <div class="mb-3">
                <label class="form-label" for="email">E-mail</label>
                <input class="form-control" type="email" id="email" name="email" required autofocus autocomplete="username">
            </div>
            <button class="btn btn-dark w-100 site-btn" type="submit">Send reset link</button>
        </form>
        <p class="small text-secondary mt-3 mb-0"><a href="/login">Back to sign in</a></p>
    </div>
</div>
