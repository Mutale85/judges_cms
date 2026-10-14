<div class="site-form-card">
    <h1 class="mb-1">Sign in</h1>
    <p class="text-secondary small mb-4">To your chambers workspace.</p>
    <form method="post" action="/login" autocomplete="on">
        <?= $view->csrfField() ?>
        <div class="mb-3">
            <label for="email" class="form-label">E-mail address</label>
            <input type="email" class="form-control" id="email" name="email" required autofocus autocomplete="username">
        </div>
        <div class="mb-2">
            <label for="password" class="form-label">Password</label>
            <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password">
        </div>
        <div class="text-end mb-3"><a href="/forgot-password" class="small text-secondary">Forgot your password?</a></div>
        <button type="submit" class="btn btn-dark w-100 site-btn">Continue</button>
    </form>
    <p class="small text-secondary mt-4 mb-0 text-center">New here? <a href="/signup">Create your workspace</a></p>
</div>
