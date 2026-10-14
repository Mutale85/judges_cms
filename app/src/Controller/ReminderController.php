<?php

declare(strict_types=1);

namespace JC\Controller;

use JC\Core\Request;
use JC\Core\Response;
use JC\Service\CaseService;
use JC\Service\ReminderService;

final class ReminderController extends Controller
{
    private function reminders(): ReminderService
    {
        return $this->app->service(ReminderService::class);
    }

    public function index(Request $request): Response
    {
        $this->auth()->requireChambersId();
        $show = in_array($request->input('show'), ['open', 'week', 'overdue', 'done'], true) ? $request->input('show') : 'open';
        $list = match ($show) {
            'week' => $this->reminders()->list(null, now_utc(7 * 86400), 'open'),
            'overdue' => $this->reminders()->list(null, now_utc(), 'open'),
            'done' => array_reverse($this->reminders()->list(null, null, 'done')),
            default => $this->reminders()->list(null, null, 'open'),
        };
        $editing = $request->int('edit') ? $this->reminders()->requireAccessible((int) $request->int('edit')) : null;
        return $this->render('reminders/index', [
            'title' => 'Reminders & deadlines',
            'reminders' => $list,
            'show' => $show,
            'editing' => $editing,
            'cases' => $this->auth()->can('reminders.manage') ? $this->app->service(CaseService::class)->listAccessible() : [],
            'preselectCase' => $request->int('case_id'),
        ]);
    }

    /** JSON for the notification bell: overdue and due within seven days. */
    public function feed(Request $request): Response
    {
        if ($this->auth()->chambersId() === null) {
            return $this->json(['count' => 0, 'items' => []]);
        }
        $items = array_map(static fn (array $r) => [
            'id' => (int) $r['id'],
            'title' => $r['title'],
            'kind' => ReminderService::kinds()[$r['kind']] ?? $r['kind'],
            'priority' => $r['priority'],
            'due' => local_time($r['due_at'], 'D j M, H:i'),
            'overdue' => $r['overdue'],
            'case' => $r['case_number'],
            'url' => $r['case_id'] ? '/cases/' . (int) $r['case_id'] . '?tab=reminders' : '/reminders',
        ], $this->reminders()->dueSoon(7));
        return $this->json([
            'count' => count($items),
            'overdue' => count(array_filter($items, static fn ($i) => $i['overdue'])),
            'items' => array_slice($items, 0, 12),
            'can_complete' => $this->auth()->can('reminders.manage'),
        ]);
    }

    public function store(Request $request): Response
    {
        $this->auth()->authorize('reminders.manage');
        $back = $this->back($request);
        return $this->attempt($back, function () use ($request, $back): Response {
            $id = $this->reminders()->create($this->auth()->requireChambersId(), $this->auth()->id(), $request->post);
            $this->app->log('reminder.created', 'reminder', $id, ['case_id' => $request->int('case_id'), 'kind' => $request->input('kind')]);
            return $this->redirect($back, 'Reminder saved.');
        });
    }

    public function update(Request $request): Response
    {
        $this->auth()->authorize('reminders.manage');
        $reminder = $this->reminders()->requireAccessible((int) $request->param('id'));
        return $this->attempt('/reminders?edit=' . (int) $reminder['id'], function () use ($request, $reminder): Response {
            $this->reminders()->update((int) $reminder['id'], $request->post);
            $this->app->log('reminder.updated', 'reminder', (int) $reminder['id']);
            return $this->redirect('/reminders', 'Reminder updated.');
        });
    }

    public function complete(Request $request): Response
    {
        $this->auth()->authorize('reminders.manage');
        $reminder = $this->reminders()->requireAccessible((int) $request->param('id'));
        $next = $this->reminders()->complete($reminder, $this->auth()->id());
        $this->app->log('reminder.completed', 'reminder', (int) $reminder['id'], ['next' => $next]);
        $message = $next !== null ? 'Done. Next occurrence: ' . local_time($next) . '.' : 'Marked as done.';
        if ($request->wantsJson() && $request->input('inline') === '1') {
            return $this->json(['ok' => true, 'message' => $message]);
        }
        return $this->redirect($this->back($request), $message);
    }

    public function reopen(Request $request): Response
    {
        $this->auth()->authorize('reminders.manage');
        $reminder = $this->reminders()->requireAccessible((int) $request->param('id'));
        $this->reminders()->reopen((int) $reminder['id']);
        $this->app->log('reminder.reopened', 'reminder', (int) $reminder['id']);
        return $this->redirect($this->back($request), 'Reminder reopened.');
    }

    public function delete(Request $request): Response
    {
        $this->auth()->authorize('reminders.manage');
        $reminder = $this->reminders()->requireAccessible((int) $request->param('id'));
        $this->reminders()->delete((int) $reminder['id']);
        $this->app->log('reminder.deleted', 'reminder', (int) $reminder['id']);
        return $this->redirect($this->back($request), 'Reminder deleted.');
    }

    private function back(Request $request): string
    {
        $back = (string) ($request->input('back') ?: '/reminders');
        return str_starts_with($back, '/') && !str_starts_with($back, '//') ? $back : '/reminders';
    }
}
