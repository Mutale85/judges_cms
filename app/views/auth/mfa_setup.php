<?php $view->addScript('/assets/vendor/qrcode/qrcode.js'); $view->addScript('/assets/mfa.js'); ?>
<div class="card jc-card">
    <div class="card-body p-4">
        <h2 class="h5 mb-1">Set up two-factor authentication</h2>
        <p class="text-muted small">Scan this code with an authenticator app (Microsoft Authenticator, Google Authenticator, FreeOTP…), then enter the 6-digit code it shows.</p>
        <div class="text-center my-3">
            <div id="mfa-qr" class="jc-qr d-inline-block" data-uri="<?= e($uri) ?>"></div>
        </div>
        <p class="small mb-1">Or enter this key manually:</p>
        <p><code class="jc-secret user-select-all"><?= e(trim(chunk_split($secret, 4, ' '))) ?></code></p>
        <form method="post" action="/mfa/setup" autocomplete="off">
            <?= $view->csrfField() ?>
            <div class="mb-3">
                <input type="text" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" class="form-control form-control-lg text-center jc-otp" name="code" required autocomplete="one-time-code" aria-label="Authentication code">
            </div>
            <button type="submit" class="btn btn-primary w-100">Enable two-factor authentication</button>
        </form>
    </div>
</div>
