<?php

declare(strict_types=1);

namespace JC\Controller;

use JC\Core\HttpException;
use JC\Core\Request;
use JC\Core\Response;
use JC\Service\CaseService;
use JC\Service\HearingService;
use JC\Service\RecordingService;

final class HearingController extends Controller
{
    private function hearings(): HearingService
    {
        return $this->app->service(HearingService::class);
    }

    /** Week calendar plus a printable daily cause list. */
    public function calendar(Request $request): Response
    {
        $this->auth()->authorize('cases.view');
        $tz = app_timezone();
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->input('date'), $tz) ?: new \DateTimeImmutable('today', $tz);
        $weekStart = $date->modify('monday this week');
        $utc = new \DateTimeZone('UTC');
        $items = $this->hearings()->between(
            $weekStart->setTimezone($utc)->format('Y-m-d H:i:s'),
            $weekStart->modify('+7 days')->setTimezone($utc)->format('Y-m-d H:i:s')
        );
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $days[$weekStart->modify("+{$i} days")->format('Y-m-d')] = [];
        }
        foreach ($items as $h) {
            $days[local_time($h['scheduled_at'], 'Y-m-d')][] = $h;
        }
        return $this->render('hearings/calendar', [
            'title' => 'Judicial calendar',
            'weekStart' => $weekStart,
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
