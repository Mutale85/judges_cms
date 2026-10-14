<?php

declare(strict_types=1);

namespace JC\Controller;

use JC\Core\HttpException;
use JC\Core\Request;
use JC\Core\Response;
use JC\Core\Terminology;
use JC\Service\SettingsService;
use JC\Service\SubscriptionService;

/** The workspace owner's subscription, payments and workspace details. */
final class BillingController extends Controller
{
    private function subscriptions(): SubscriptionService
    {
        return $this->app->service(SubscriptionService::class);
    }

    /** @return array<string,mixed> */
    private function ownerChambers(): array
    {
        if (!$this->auth()->isJudge()) {
            throw new HttpException(403, 'Only the workspace owner manages billing and workspace settings.');
        }
        return $this->auth()->chambers() ?? throw new HttpException(403);
    }

    public function index(Request $request): Response
    {
        $chambers = $this->ownerChambers();
        $subs = $this->subscriptions();
        return $this->render('billing/index', [
            'title' => 'Workspace & billing',
            'chambers' => $chambers,
            'state' => $subs->state($chambers),
            'daysLeft' => $subs->daysLeft($chambers),
            'plan' => $subs->plan(isset($chambers['plan_id']) ? (int) $chambers['plan_id'] : null),
            'plans' => $subs->plans(),
            'payments' => $subs->payments((int) $chambers['id']),
            'instructions' => $this->app->service(SettingsService::class)->get('payment_instructions', ''),
            'staffLimit' => $subs->staffLimit($chambers),
        ]);
    }

    public function pay(Request $request): Response
    {
        $chambers = $this->ownerChambers();
        return $this->attempt('/billing', function () use ($request, $chambers): Response {
            $id = $this->subscriptions()->submitPayment(
                (int) $chambers['id'],
                $this->auth()->id(),
                (int) $request->input('plan_id'),
                (string) $request->input('billing_cycle'),
                (string) $request->input('method'),
                (string) $request->input('reference'),
                $request->input('notes'),
            );
            $this->app->log('billing.payment_submitted', 'payment', $id, ['plan_id' => $request->int('plan_id'), 'cycle' => $request->input('billing_cycle')]);
            return $this->redirect('/billing', 'Thank you. Your payment reference has been sent for confirmation; your subscription is extended as soon as it is matched.');
        });
    }

    public function workspace(Request $request): Response
    {
        $chambers = $this->ownerChambers();
        return $this->attempt('/billing', function () use ($request, $chambers): Response {
            $name = trim((string) $request->input('name'));
            $court = trim((string) $request->input('court'));
            if ($name === '' || $court === '') {
                throw new \InvalidArgumentException('The workspace needs a name and a court or institution.');
            }
            $mode = $request->input('mode') === 'arbitration' ? 'arbitration' : 'court';
            $this->app->db()->update('chambers', [
                'name' => mb_substr($name, 0, 190),
                'court' => mb_substr($court, 0, 190),
                'division' => mb_substr(trim((string) $request->input('division')), 0, 190) ?: null,
                'location' => mb_substr(trim((string) $request->input('location')), 0, 190) ?: null,
                'mode' => $mode,
            ], ['id' => (int) $chambers['id']]);
            $this->app->log('chambers.updated', 'chambers', (int) $chambers['id'], ['mode' => $mode]);
            Terminology::setMode($mode);
            return $this->redirect('/billing', 'Workspace details saved.');
        });
    }
}
