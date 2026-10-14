<div class="site-form-wrap">
    <div class="site-form-card text-center">
        <div class="site-icon mb-3"><i class="bi bi-envelope-check"></i></div>
        <h1>Check your e-mail</h1>
        <p class="text-secondary">We have sent a confirmation link to your e-mail address. Open it to confirm your address, then sign in and set up your authenticator app. The link works for 48 hours.</p>
        <?php if ($devLink): ?>
            <div class="alert alert-secondary text-start small"><strong>Development mode</strong> (MAIL_DRIVER=log): <a href="<?= e($devLink) ?>">open the confirmation link</a>.</div>
        <?php endif; ?>
        <?php if ($canResend): ?>
            <form method="post" action="/signup/resend" class="mt-3">
                <?= $view->csrfField() ?>
                <button class="btn btn-outline-dark site-btn" type="submit">Send the link again</button>
            </form>
        <?php endif; ?>
        <p class="small text-secondary mt-4 mb-0">Nothing arrived? Check spam, or write to support. <a href="/login">Back to sign in</a></p>
    </div>
</div>
