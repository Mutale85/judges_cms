<?php

declare(strict_types=1);

namespace JC\Controller;

use JC\Core\HttpException;
use JC\Core\Request;
use JC\Core\Response;
use JC\Service\CaseService;
use JC\Service\TaskService;
use JC\Service\UserService;

final class TaskController extends Controller
{
    public function index(Request $request): Response
    {
        $auth = $this->auth();
        $chambersId = $auth->requireChambersId();
        $all = $auth->can('tasks.manage') && $request->input('scope') !== 'mine';
        return $this->render('tasks/index', [
            'title' => 'Tasks',
            'tasks' => $this->app->service(TaskService::class)->list($chambersId, $all ? null : $auth->id(), $request->input('show') !== 'all'),
            'members' => $auth->can('tasks.manage') ? $this->app->service(UserService::class)->members($chambersId) : [],
            'cases' => $auth->can('tasks.manage') ? $this->app->service(CaseService::class)->listAccessible() : [],
            'scope' => $all ? 'all' : 'mine',
            'show' => $request->input('show') === 'all' ? 'all' : 'open',
        ]);
    }

    public function store(Request $request): Response
    {
        $this->auth()->authorize('tasks.manage');
        $back = (string) ($request->input('back') ?: '/tasks');
        if (!str_starts_with($back, '/') || str_starts_with($back, '//')) {
            $back = '/tasks';
        }
        return $this->attempt($back, function () use ($request, $back): Response {
            if ($caseId = $request->int('case_id')) {
                $this->app->service(CaseService::class)->requireAccessible($caseId);
            }
            $id = $this->app->service(TaskService::class)->create($this->auth()->requireChambersId(), $this->auth()->id(), $request->post);
            $this->app->log('task.created', 'task', $id, ['assigned_to' => $request->int('assigned_to'), 'case_id' => $request->int('case_id')]);
            return $this->redirect($back, 'Task assigned.');
        });
    }

    public function status(Request $request): Response
    {
        $tasks = $this->app->service(TaskService::class);
        $task = $tasks->find((int) $request->param('id')) ?? throw new HttpException(404);
        $auth = $this->auth();
        if ((int) $task['chambers_id'] !== $auth->chambersId()
            || ((int) $task['assigned_to'] !== $auth->id() && !$auth->can('tasks.manage'))) {
            throw new HttpException(403);
        }
        return $this->attempt('/tasks', function () use ($tasks, $task, $request): Response {
            $status = (string) $request->input('status');
            $tasks->setStatus((int) $task['id'], $status);
            $this->app->log('task.status_changed', 'task', (int) $task['id'], ['to' => $status]);
            return $this->redirect('/tasks', 'Task updated.');
        });
    }
}
