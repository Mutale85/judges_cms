<div class="site-form-wrap site-form-wide">
    <div class="site-form-card">
        <div class="site-eyebrow">Create your workspace</div>
        <h1 class="mb-1">Your chambers, online.</h1>
        <p class="text-secondary small mb-4"><?= (int) $trialDays ?> days free, no payment details needed. Already registered? <a href="/login">Sign in</a>.</p>
        <?php if (!$open): ?>
            <div class="alert alert-warning">New sign-ups are paused at the moment. Please <a href="/support">contact support</a>.</div>
        <?php else: ?>
        <form method="post" action="/signup" id="signup-form" autocomplete="on">
            <?= $view->csrfField() ?>
            <div class="mb-3">
                <div class="form-label">I am signing up as</div>
                <div class="site-choice">
                    <div><input type="radio" name="mode" value="court" id="mode-court" <?= $mode === 'court' ? 'checked' : '' ?>><label for="mode-court"><b>Judge</b>Courts and tribunals</label></div>
                    <div><input type="radio" name="mode" value="arbitration" id="mode-arb" <?= $mode === 'arbitration' ? 'checked' : '' ?>><label for="mode-arb"><b>Arbitrator</b>References and awards</label></div>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="full_name">Full name and title</label>
                <input class="form-control" id="full_name" name="full_name" required maxlength="150" autocomplete="name" placeholder="e.g. Hon. Mrs Justice M. Mwansa">
            </div>
            <div class="row g-3 mb-3">
                <div class="col-sm-7">
                    <label class="form-label" for="court" data-court="Court" data-arb="Arbitral institution or practice">Court</label>
                    <input class="form-control" id="court" name="court" required maxlength="190" placeholder="e.g. High Court for Zambia">
                </div>
                <div class="col-sm-5">
                    <label class="form-label" for="division" data-court="Division (optional)" data-arb="Panel / seat (optional)">Division (optional)</label>
                    <input class="form-control" id="division" name="division" maxlength="190">
                </div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-sm-7">
                    <label class="form-label" for="email">E-mail</label>
                    <input class="form-control" type="email" id="email" name="email" required autocomplete="email">
                </div>
                <div class="col-sm-5">
                    <label class="form-label" for="phone">Phone (optional)</label>
                    <input class="form-control" type="tel" id="phone" name="phone" autocomplete="tel" placeholder="+260 …">
                </div>
            </div>
            <div class="row g-3 mb-1">
                <div class="col-sm-6">
                    <label class="form-label" for="password">Password</label>
                    <input class="form-control" type="password" id="password" name="password" required minlength="12" autocomplete="new-password">
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="password_confirmation">Confirm password</label>
                    <input class="form-control" type="password" id="password_confirmation" name="password_confirmation" required minlength="12" autocomplete="new-password">
                </div>
            </div>
            <div class="form-text mb-3">At least 12 characters, mixing three of: lower case, upper case, digits and symbols.</div>
            <div class="site-hp" aria-hidden="true"><label for="website">Website</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
            <div class="form-check mb-4">
                <input class="form-check-input" type="checkbox" value="1" id="accept_terms" name="accept_terms" required>
                <label class="form-check-label small" for="accept_terms">I accept the <a href="/terms" target="_blank" rel="noopener">Terms of Use</a> and <a href="/privacy" target="_blank" rel="noopener">Privacy Policy</a>.</label>
            </div>
            <button class="btn btn-dark w-100 site-btn" type="submit">Create workspace</button>
            <p class="small text-secondary mt-3 mb-0"><i class="bi bi-shield-lock"></i> Next: confirm your e-mail, then scan a QR code with an authenticator app (Microsoft Authenticator, Google Authenticator…).</p>
        </form>
        <?php endif; ?>
    </div>
</div>
