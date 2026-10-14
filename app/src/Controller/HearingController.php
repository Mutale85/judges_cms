<?php

declare(strict_types=1);

namespace JC\Controller;

use JC\Core\HttpException;
use JC\Core\Request;
use JC\Core\Response;
use JC\Service\CaseService;
use JC\Service\HearingService;
use JC\Service\RecordingService;
use JC\Service\ReminderService;

final class HearingController extends Controller
{
    private function hearings(): HearingService
    {
        return $this->app->service(HearingService::class);
    }

    /** Week or month calendar of hearings and reminders, plus a printable daily cause list. */
    public function calendar(Request $request): Response
    {
        $this->auth()->authorize('cases.view');
        $tz = app_timezone();
        $utc = new \DateTimeZone('UTC');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->input('date'), $tz) ?: new \DateTimeImmutable('today', $tz);
        $month = $request->input('view') === 'month';
        if ($month) {
            $first = $date->modify('first day of this month');
            $start = $first->modify('monday this week');
            $end = $date->modify('last day of this month')->modify('sunday this week')->modify('+1 day');
        } else {
            $start = $date->modify('monday this week');
            $end = $start->modify('+7 days');
        }
        $fromUtc = $start->setTimezone($utc)->format('Y-m-d H:i:s');
        $toUtc = $end->setTimezone($utc)->format('Y-m-d H:i:s');

        $days = [];
        for ($d = $start; $d < $end; $d = $d->modify('+1 day')) {
            $days[$d->format('Y-m-d')] = ['hearings' => [], 'reminders' => []];
        }
        foreach ($this->hearings()->between($fromUtc, $toUtc) as $h) {
            $days[local_time($h['scheduled_at'], 'Y-m-d')]['hearings'][] = $h;
        }
        foreach ($this->app->service(ReminderService::class)->list($fromUtc, $toUtc, null) as $r) {
            $days[local_time($r['due_at'], 'Y-m-d')]['reminders'][] = $r;
        }
        return $this->render('hearings/calendar', [
            'title' => 'Calendar',
            'calView' => $month ? 'month' : 'week',
            'start' => $start,
            'anchor' => $month ? $date->modify('first day of this month') : $start,
            'days' => $days,
            'selected' => $date->format('Y-m-d'),
        ]);
    }

    public function causeList(Request $request): Response
    {
        $this->auth()->authorize('cases.view');
        $tz = app_timezone();
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->input('date'), $tz) ?: new \DateTimeImmutable('today', $tz);
        $utc = new \DateTimeZone('UTC');
        $items = array_filter(
            $this->hearings()->between($date->setTimezone($utc)->format('Y-m-d H:i:s'), $date->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s')),
            static fn ($h) => !in_array($h['status'], ['cancelled', 'proposed'], true)
        );
        return $this->render('hearings/cause_list', [
            'title' => 'Cause list',
            'date' => $date,
            'items' => array_values($items),
            'chambers' => $this->auth()->chambers(),
        ], 'layout/print');
    }

    public function store(Request $request): Response
    {
        $this->auth()->authorize('hearings.propose');
        $case = $this->app->service(CaseService::class)->requireAccessible((int) $request->param('id'));
        $back = "/cases/{$case['id']}?tab=hearings";
        return $this->attempt($back, function () use ($case, $request, $back): Response {
            $confirm = $this->auth()->can('hearings.confirm') && $request->input('confirm') === '1';
            $id = $this->hearings()->propose($case, $request->post, $this->auth()->id(), $confirm);
            $this->app->log($confirm ? 'hearing.scheduled' : 'hearing.proposed', 'hearing', $id, ['case_id' => (int) $case['id']]);
            return $this->redirect($back, $confirm ? 'Hearing scheduled.' : 'Hearing proposed. It will appear as confirmed once the judge approves it.');
        });
    }

    public function show(Request $request): Response
    {
        [$hearing, $case] = $this->load((int) $request->param('id'));
        return $this->render('hearings/show', [
            'title' => 'Hearing',
            'hearing' => $hearing,
            'case' => $case,
            'recordings' => $this->app->service(RecordingService::class)->forHearing((int) $hearing['id']),
        ]);
    }

    public function confirm(Request $request): Response
    {
        $this->auth()->authorize('hearings.confirm');
        [$hearing] = $this->load((int) $request->param('id'));
        $this->hearings()->confirm((int) $hearing['id'], $this->auth()->id());
        $this->app->log('hearing.confirmed', 'hearing', (int) $hearing['id']);
        return $this->redirect("/hearings/{$hearing['id']}", 'Hearing confirmed.');
    }

    public function adjourn(Request $request): Response
    {
        $this->auth()->authorize('hearings.confirm');
        [$hearing, $case] = $this->load((int) $request->param('id'));
        return $this->attempt("/hearings/{$hearing['id']}", function () use ($hearing, $case, $request): Response {
            $newId = $this->hearings()->adjourn($hearing, $case, (string) $request->input('scheduled_at'), (string) $request->input('reason'), $this->auth()->id());
            $this->app->log('hearing.adjourned', 'hearing', (int) $hearing['id'], ['new_hearing_id' => $newId]);
            return $this->redirect("/hearings/{$newId}", 'Hearing adjourned and the new date scheduled.');
        });
    }

    public function complete(Request $request): Response
    {
        $this->auth()->authorize('hearings.confirm');
        [$hearing] = $this->load((int) $request->param('id'));
        $status = $request->input('status') === 'cancelled' ? 'cancelled' : 'completed';
        $this->hearings()->setStatus((int) $hearing['id'], $status, (string) $request->input('outcome'));
        $this->app->log('hearing.' . $status, 'hearing', (int) $hearing['id']);
        return $this->redirect("/hearings/{$hearing['id']}", 'Hearing marked as ' . $status . '.');
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function load(int $id): array
    {
        $hearing = $this->hearings()->find($id) ?? throw new HttpException(404);
        $case = $this->app->service(CaseService::class)->requireAccessible((int) $hearing['case_id']);
        return [$hearing, $case];
    }
}
