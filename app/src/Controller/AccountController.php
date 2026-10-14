<?php

declare(strict_types=1);

namespace JC\Controller;

use JC\Core\Request;
use JC\Core\Response;
use JC\Service\AccountService;
use JC\Service\SettingsService;

/** Creating a workspace, confirming the e-mail address and resetting a password. */
final class AccountController extends Controller
{
    private function accounts(): AccountService
    {
        return $this->app->service(AccountService::class);
    }

    public function showSignup(Request $request): Response
    {
        if ($this->auth()->check()) {
            return $this->redirect('/dashboard');
        }
        $settings = $this->app->service(SettingsService::class);
        return $this->render('account/signup', [
            'title' => 'Create your workspace',
            'mode' => $request->input('as') === 'arbitrator' ? 'arbitration' : 'court',
            'open' => $settings->bool('signup_enabled', true),
            'trialDays' => $settings->int('trial_days', 30),
        ], 'layout/site');
    }

    public function signup(Request $request): Response
    {
        return $this->attempt('/signup', function () use ($request): Response {
            // Honeypot: people never see this field; simple bots fill it in.
            if (($request->post['website'] ?? '') !== '') {
                return $this->redirect('/signup/check-email');
            }
            $data = $request->post;
            unset($data['_csrf']);
            $ids = $this->accounts()->register($data);
            $this->app->session()->regenerate();
            $this->app->session()->set('verify_user_id', $ids['user_id']);
            $this->devLink();
            return $this->redirect('/signup/check-email', 'Your workspace is ready. Confirm your e-mail address to sign in.');
        });
    }

    public function checkEmail(Request $request): Response
    {
        return $this->render('account/check_email', [
            'title' => 'Check your e-mail',
            'canResend' => is_int($this->app->session()->get('verify_user_id')),
            'devLink' => $this->takeDevLink(),
        ], 'layout/site');
    }

    public function resend(Request $request): Response
    {
        $userId = $this->app->session()->get('verify_user_id');
        if (is_int($userId)) {
            $this->accounts()->sendVerification($userId, false);
            $this->devLink();
        }
        return $this->redirect('/signup/check-email', 'If your address still needs confirming, a new link is on its way. Links can be re-sent once a minute.', 'info');
    }

    public function verify(Request $request): Response
    {
        $userId = $this->accounts()->verifyEmail((string) $request->input('token'));
        if ($userId === null) {
            return $this->redirect('/login', 'That confirmation link is invalid, already used or expired. Sign in to receive a new one.', 'danger');
        }
        $this->app->session()->forget('verify_user_id');
        return $this->redirect('/login', 'E-mail address confirmed. Sign in to set up your authenticator app.');
    }

    public function showForgot(Request $request): Response
    {
        return $this->render('account/forgot', ['title' => 'Reset your password', 'devLink' => $this->takeDevLink()], 'layout/site');
    }

    public function forgot(Request $request): Response
    {
        $email = (string) $request->input('email');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->redirect('/forgot-password', 'Enter the e-mail address you sign in with.', 'danger');
        }
        $this->accounts()->requestPasswordReset($email);
        $this->devLink();
        return $this->redirect('/forgot-password', 'If an account uses that address, we have sent it a link to reset the password. The link works for one hour.', 'info');
    }

    public function showReset(Request $request): Response
    {
        $token = (string) $request->input('token');
        if (!$this->accounts()->validResetToken($token)) {
            return $this->redirect('/forgot-password', 'That reset link is invalid, already used or expired. Request a new one.', 'danger');
        }
        return $this->render('account/reset', ['title' => 'Choose a new password', 'token' => $token], 'layout/site');
    }

    public function reset(Request $request): Response
    {
        $token = (string) $request->input('token');
        return $this->attempt('/reset-password?token=' . rawurlencode($token), function () use ($request, $token): Response {
            $this->accounts()->resetPassword($token, (string) ($request->post['password'] ?? ''), (string) ($request->post['password_confirmation'] ?? ''));
            $this->auth()->logout();
            return $this->redirect('/login', 'Password changed. Sign in with your new password and your authenticator code.');
        });
    }

    /** Development convenience: keep the last e-mailed link to show on screen (MAIL_DRIVER=log only). */
    private function devLink(): void
    {
        $link = $this->accounts()->lastLink;
        if ($link !== null && AccountService::showLinksOnScreen()) {
            $this->app->session()->set('dev_link', $link);
        }
    }

    private function takeDevLink(): ?string
    {
        $link = $this->app->session()->get('dev_link');
        $this->app->session()->forget('dev_link');
        return is_string($link) && AccountService::showLinksOnScreen() ? $link : null;
    }
}
