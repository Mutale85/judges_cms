<?php

declare(strict_types=1);

namespace JC\Controller;

use JC\Core\Request;
use JC\Core\Response;
use JC\Service\CaseService;
use JC\Service\HearingService;
use JC\Service\ReminderService;
use JC\Service\TaskService;

final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $auth = $this->auth();
        if ($auth->chambers() === null) {
            if ($auth->isAdmin()) {
                return $this->redirect('/admin');
            }
            return $this->render('dashboard/no_chambers', ['title' => 'Welcome']);
        }
        $tz = app_timezone();
        $todayStart = (new \DateTimeImmutable('today', $tz))->setTimezone(new \DateTimeZone('UTC'));
        $hearings = $this->app->service(HearingService::class);

        $cases = $this->app->service(CaseService::class)->listAccessible();
        $open = array_filter($cases, static fn ($c) => !in_array($c['status'], ['concluded', 'closed'], true));
        $reserved = array_filter($cases, static fn ($c) => $c['status'] === 'judgment_reserved');

        return $this->render('dashboard/index', [
            'title' => 'Dashboard',
            'today' => $hearings->between($todayStart->format('Y-m-d H:i:s'), $todayStart->modify('+1 day')->format('Y-m-d H:i:s')),
            'upcoming' => $hearings->between($todayStart->modify('+1 day')->format('Y-m-d H:i:s'), $todayStart->modify('+15 days')->format('Y-m-d H:i:s')),
            'proposed' => array_filter(
                $hearings->between($todayStart->format('Y-m-d H:i:s'), $todayStart->modify('+365 days')->format('Y-m-d H:i:s')),
                static fn ($h) => $h['status'] === 'proposed'
            ),
            'openCases' => count($open),
            'reserved' => array_values($reserved),
            'recentCases' => array_slice($cases, 0, 6),
            'myTasks' => $this->app->service(TaskService::class)->list($auth->requireChambersId(), $auth->id(), true),
            'dueThisWeek' => $this->app->service(ReminderService::class)->dueSoon(7),
        ]);
    }

    public function switchChambers(Request $request): Response
    {
        $id = (int) $request->input('chambers_id');
        if (!$this->auth()->switchChambers($id)) {
            return $this->redirect('/dashboard', 'You are not a member of those chambers.', 'danger');
        }
        return $this->redirect('/dashboard');
    }
}
