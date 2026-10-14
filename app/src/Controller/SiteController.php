<?php

declare(strict_types=1);

namespace JC\Controller;

use JC\Core\Request;
use JC\Core\Response;
use JC\Service\SettingsService;
use JC\Service\SubscriptionService;

/** The public website: landing page, pricing and legal pages. */
final class SiteController extends Controller
{
    public function home(Request $request): Response
    {
        if ($this->auth()->check()) {
            return $this->redirect('/dashboard');
        }
        return $this->page('site/home', 'Private case administration for judges and arbitrators', [
            'plans' => $this->app->service(SubscriptionService::class)->plans(),
        ]);
    }

    public function pricing(Request $request): Response
    {
        return $this->page('site/pricing', 'Pricing', [
            'plans' => $this->app->service(SubscriptionService::class)->plans(),
        ]);
    }

    public function terms(Request $request): Response
    {
        return $this->page('site/terms', 'Terms of Use');
    }

    public function privacy(Request $request): Response
    {
        return $this->page('site/privacy', 'Privacy Policy');
    }

    public function cookies(Request $request): Response
    {
        return $this->page('site/cookies', 'Cookie Policy');
    }

    public function support(Request $request): Response
    {
        return $this->page('site/support', 'Support');
    }

    /** @param array<string,mixed> $data */
    private function page(string $template, string $title, array $data = []): Response
    {
        $settings = $this->app->service(SettingsService::class);
        return $this->render($template, $data + [
            'title' => $title,
            'supportEmail' => $settings->get('support_email', 'support@judges.co.zm'),
            'trialDays' => $settings->int('trial_days', 30),
        ], 'layout/site');
    }
}
