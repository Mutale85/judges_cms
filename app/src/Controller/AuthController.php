<?php

declare(strict_types=1);

namespace JC\Controller;

use JC\Core\Config;
use JC\Core\Request;
use JC\Core\Response;
use JC\Security\Totp;
use JC\Service\AccountService;
use JC\Service\UserService;

final class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        if ($this->auth()->check()) {
            return $this->redirect('/dashboard');
        }
        return $this->render('auth/login', ['title' => 'Sign in'], 'layout/guest');
    }

    public function login(Request $request): Response
    {
        $users = $this->app->service(UserService::class);
        try {
            $user = $users->attempt((string) $request->input('email'), (string) ($request->post['password'] ?? ''));
        } catch (\RuntimeException $e) {
            return $this->redirect('/login', $e->getMessage(), 'danger');
        }
        if ($user === null) {
            return $this->redirect('/login', 'The e-mail address or password is incorrect.', 'danger');
        }
        if ($user['email_verified_at'] === null) {
            // Correct password, unconfirmed address: send a fresh link (rate limited) and explain.
            $this->app->session()->regenerate();
            $this->app->session()->set('verify_user_id', (int) $user['id']);
            $accounts = $this->app->service(AccountService::class);
            $accounts->sendVerification((int) $user['id'], false);
            if (AccountService::showLinksOnScreen() && $accounts->lastLink !== null) {
                $this->app->session()->set('dev_link', $accounts->lastLink);
            }
            return $this->redirect('/signup/check-email', 'Please confirm your e-mail address before signing in. Check your inbox for the link.', 'warning');
        }
        if ((int) $user['mfa_enabled'] === 1) {
            $this->auth()->beginLogin((int) $user['id'], 'mfa');
            return $this->redirect('/login/mfa');
        }
        if ($this->auth()->mfaRequired($user)) {
            $this->auth()->beginLogin((int) $user['id'], 'mfa_setup');
            return $this->redirect('/mfa/setup', 'Your role requires two-factor authentication. Please set it up to continue.', 'info');
        }
        return $this->finish((int) $user['id']);
    }

    public function showMfa(Request $request): Response
    {
        if ($this->auth()->pendingStage() !== 'mfa') {
            return $this->redirect('/login');
        }
        return $this->render('auth/mfa', ['title' => 'Two-factor authentication'], 'layout/guest');
    }

    public function verifyMfa(Request $request): Response
    {
        $userId = $this->auth()->pendingUserId();
        if ($userId === null || $this->auth()->pendingStage() !== 'mfa') {
            return $this->redirect('/login');
        }
        $users = $this->app->service(UserService::class);
        $user = $users->find($userId);
        $attempts = (int) $this->app->session()->get('mfa_attempts', 0) + 1;
        $this->app->session()->set('mfa_attempts', $attempts);
        if ($user === null || $attempts > 5) {
            $this->auth()->logout();
            return $this->redirect('/login', 'Too many incorrect codes. Please sign in again.', 'danger');
        }
        $step = Totp::verify((string) $user['mfa_secret'], (string) $request->input('code'), $user['mfa_last_step'] !== null ? (int) $user['mfa_last_step'] : null);
        if ($step === null) {
            $this->app->audit()->record('auth.mfa_failed', $userId, null, 'user', $userId, [], $request->ip());
            return $this->redirect('/login/mfa', 'That code is not valid. Try the latest code from your authenticator app.', 'danger');
        }
        $users->markMfaStep($userId, $step);
        $this->app->session()->forget('mfa_attempts');
        return $this->finish($userId);
    }

    public function showMfaSetup(Request $request): Response
    {
        $userId = $this->auth()->check() ? $this->auth()->id() : $this->auth()->pendingUserId();
        if ($userId === null) {
            return $this->redirect('/login');
        }
        $user = $this->app->service(UserService::class)->find($userId);
        if ($user === null || (int) $user['mfa_enabled'] === 1) {
            return $this->redirect('/dashboard', 'Two-factor authentication is already enabled. Ask your judge or administrator to reset it if you have lost your device.', 'info');
        }
        $secret = $this->app->session()->get('mfa_setup_secret');
        if (!is_string($secret)) {
            $secret = Totp::generateSecret();
            $this->app->session()->set('mfa_setup_secret', $secret);
        }
        $issuer = Config::get('APP_NAME', 'Judicial Chambers') ?? 'Judicial Chambers';
        return $this->render('auth/mfa_setup', [
            'title' => 'Set up two-factor authentication',
            'secret' => $secret,
            'uri' => Totp::provisioningUri($secret, (string) $user['email'], $issuer),
        ], $this->auth()->check() ? 'layout/main' : 'layout/guest');
    }

    public function completeMfaSetup(Request $request): Response
    {
        $userId = $this->auth()->check() ? $this->auth()->id() : $this->auth()->pendingUserId();
        $secret = $this->app->session()->get('mfa_setup_secret');
        if ($userId === null || !is_string($secret)) {
            return $this->redirect('/login');
        }
        if ((int) ($this->app->service(UserService::class)->find($userId)['mfa_enabled'] ?? 1) === 1) {
            return $this->redirect('/dashboard');
        }
        $step = Totp::verify($secret, (string) $request->input('code'));
        if ($step === null) {
            return $this->redirect('/mfa/setup', 'That code did not match. Check the time on your phone and try again.', 'danger');
        }
        $this->app->service(UserService::class)->enableMfa($userId, $secret, $step);
        $this->app->session()->forget('mfa_setup_secret');
        $this->app->audit()->record('auth.mfa_enrolled', $userId, null, 'user', $userId, [], $request->ip());
        if ($this->auth()->check()) {
            return $this->redirect('/dashboard', 'Two-factor authentication is now enabled.');
        }
        return $this->finish($userId);
    }

    public function logout(Request $request): Response
    {
        if ($this->auth()->check()) {
            $this->app->log('auth.logout', 'user', $this->auth()->id());
        }
        $this->auth()->logout();
        return $this->redirect('/login');
    }

    public function showPassword(Request $request): Response
    {
        return $this->render('auth/password', ['title' => 'Change password']);
    }

    public function changePassword(Request $request): Response
    {
        $user = $this->auth()->user();
        $current = (string) ($request->post['current_password'] ?? '');
        $new = (string) ($request->post['new_password'] ?? '');
        if (!password_verify($current, (string) $user['password_hash'])) {
            return $this->redirect('/account/password', 'Your current password is incorrect.', 'danger');
        }
        if ($new !== (string) ($request->post['confirm_password'] ?? '')) {
            return $this->redirect('/account/password', 'The new passwords do not match.', 'danger');
        }
        if (hash_equals($current, $new)) {
            return $this->redirect('/account/password', 'Choose a password different from the current one.', 'danger');
        }
        return $this->attempt('/account/password', function () use ($user, $new): Response {
            $this->app->service(UserService::class)->changePassword((int) $user['id'], $new);
            $this->app->session()->regenerate();
            $this->app->log('auth.password_changed', 'user', (int) $user['id']);
            return $this->redirect('/dashboard', 'Your password has been changed.');
        });
    }

    private function finish(int $userId): Response
    {
        $this->auth()->completeLogin($userId);
        $this->app->service(UserService::class)->recordLogin($userId);
        $this->app->log('auth.login', 'user', $userId);
        if ($this->auth()->chambers() === null && $this->auth()->isAdmin()) {
            return $this->redirect('/admin');
        }
        return $this->redirect('/dashboard');
    }
}
