<div class="card jc-card">
    <div class="card-body p-4">
        <h2 class="h5 mb-3">Sign in</h2>
        <form method="post" action="/login" autocomplete="on">
            <?= $view->csrfField() ?>
            <div class="mb-3">
                <label for="email" class="form-label">E-mail address</label>
                <input type="email" class="form-control" id="email" name="email" required autofocus autocomplete="username">
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-primary w-100">Continue</button>
        </form>
    </div>
</div>
