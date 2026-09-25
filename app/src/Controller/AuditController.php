<?php

declare(strict_types=1);

namespace JC\Controller;

use JC\Core\Request;
use JC\Core\Response;

final class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $this->auth()->authorize('audit.view');
        $page = max(1, (int) ($request->input('page') ?? 1));
        return $this->render('audit/index', [
            'title' => 'Audit trail',
            'entries' => $this->app->audit()->forChambers($this->auth()->requireChambersId(), 100, ($page - 1) * 100),
            'page' => $page,
        ]);
    }

    public function verify(Request $request): Response
    {
        $this->auth()->authorize('audit.view');
        $result = $this->app->audit()->verify();
        $this->app->log('audit.verified', null, null, ['ok' => $result['ok'], 'count' => $result['count']]);
        return $this->redirect(
            '/audit',
            $result['ok'] ? "Audit chain intact: {$result['count']} entries verified." : 'AUDIT CHAIN BROKEN: ' . $result['error'] . ($result['failed_id'] ? " (entry #{$result['failed_id']})" : ''),
            $result['ok'] ? 'success' : 'danger'
        );
    }
}
