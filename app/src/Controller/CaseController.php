<?php

declare(strict_types=1);

namespace JC\Controller;

use JC\Core\Request;
use JC\Core\Response;
use JC\Service\CaseService;
use JC\Service\DocumentService;
use JC\Service\HearingService;
use JC\Service\NoteService;
use JC\Service\RecordingService;
use JC\Service\TaskService;
use JC\Service\UserService;

final class CaseController extends Controller
{
    private function cases(): CaseService
    {
        return $this->app->service(CaseService::class);
    }

    public function index(Request $request): Response
    {
        $this->auth()->authorize('cases.view');
        return $this->render('cases/index', [
            'title' => term('Cases'),
            'cases' => $this->cases()->listAccessible($request->input('q'), $request->input('status'), $request->input('type')),
            'filters' => ['q' => $request->input('q'), 'status' => $request->input('status'), 'type' => $request->input('type')],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->auth()->authorize('cases.manage');
        return $this->render('cases/form', ['title' => 'Register ' . term('case'), 'case' => null]);
    }

    public function store(Request $request): Response
    {
        $this->auth()->authorize('cases.manage');
        return $this->attempt('/cases/new', function () use ($request): Response {
            $data = $request->post;
            // Only the judge can open a case directly as sealed.
            $data['sealed'] = $this->auth()->isJudge() && !empty($request->post['sealed']);
            $id = $this->cases()->create($this->auth()->requireChambersId(), $this->auth()->id(), $data);
            $this->app->log('case.registered', 'case', $id, ['case_type' => $data['case_type'] ?? null, 'sealed' => $data['sealed']]);
            return $this->redirect("/cases/{$id}", 'Case registered.');
        });
    }

    public function show(Request $request): Response
    {
        $case = $this->cases()->requireAccessible((int) $request->param('id'));
        $caseId = (int) $case['id'];
        $auth = $this->auth();
        $this->app->log('case.viewed', 'case', $caseId);

        return $this->render('cases/show', [
            'title' => $case['case_number'],
            'case' => $case,
            'parties' => $this->cases()->parties($caseId),
            'assignees' => $this->cases()->assignees($caseId),
            'documents' => $this->app->service(DocumentService::class)->forCase($caseId),
            'hearings' => $this->app->service(HearingService::class)->forCase($caseId),
            'recordings' => $this->app->service(RecordingService::class)->forCase($caseId),
            'notes' => $auth->can('notes.private') ? $this->app->service(NoteService::class)->forCase($caseId) : [],
            'tasks' => $this->app->service(TaskService::class)->list($auth->requireChambersId(), null, false, $caseId),
            'reminders' => $this->app->service(\JC\Service\ReminderService::class)->list(null, null, null, $caseId),
            'members' => $auth->isJudge() || $auth->can('tasks.manage') ? $this->app->service(UserService::class)->members($auth->requireChambersId()) : [],
            'tab' => $request->input('tab', 'overview'),
        ]);
    }

    public function edit(Request $request): Response
    {
        $this->auth()->authorize('cases.manage');
        $case = $this->cases()->requireAccessible((int) $request->param('id'));
        return $this->render('cases/form', ['title' => 'Edit ' . $case['case_number'], 'case' => $case]);
    }

    public function update(Request $request): Response
    {
        $this->auth()->authorize('cases.manage');
        $case = $this->cases()->requireAccessible((int) $request->param('id'));
        return $this->attempt("/cases/{$case['id']}/edit", function () use ($case, $request): Response {
            $this->cases()->update((int) $case['id'], $request->post);
            $this->app->log('case.updated', 'case', (int) $case['id']);
            return $this->redirect("/cases/{$case['id']}", 'Case updated.');
        });
    }

    public function status(Request $request): Response
    {
        $this->auth()->authorize('cases.manage');
        $case = $this->cases()->requireAccessible((int) $request->param('id'));
        return $this->attempt("/cases/{$case['id']}", function () use ($case, $request): Response {
            $status = (string) $request->input('status');
            $this->cases()->setStatus((int) $case['id'], $status);
            $this->app->log('case.status_changed', 'case', (int) $case['id'], ['from' => $case['status'], 'to' => $status]);
            return $this->redirect("/cases/{$case['id']}", 'Case status updated.');
        });
    }

    public function seal(Request $request): Response
    {
        if (!$this->auth()->isJudge()) {
            return $this->redirect('/cases', 'Only the judge can seal or unseal a case.', 'danger');
        }
        $case = $this->cases()->requireAccessible((int) $request->param('id'));
        $sealed = $request->input('sealed') === '1';
        $this->cases()->setSealed((int) $case['id'], $sealed);
        $this->app->log($sealed ? 'case.sealed' : 'case.unsealed', 'case', (int) $case['id']);
        return $this->redirect("/cases/{$case['id']}", $sealed ? 'Case sealed. Only explicitly assigned staff can now see it.' : 'Case unsealed.');
    }

    public function addParty(Request $request): Response
    {
        $this->auth()->authorize('cases.manage');
        $case = $this->cases()->requireAccessible((int) $request->param('id'));
        return $this->attempt("/cases/{$case['id']}?tab=parties", function () use ($case, $request): Response {
            $id = $this->cases()->addParty((int) $case['id'], $request->post);
            $this->app->log('case.party_added', 'case', (int) $case['id'], ['party_id' => $id, 'role' => $request->input('party_role')]);
            return $this->redirect("/cases/{$case['id']}?tab=parties", 'Party added.');
        });
    }

    public function removeParty(Request $request): Response
    {
        $this->auth()->authorize('cases.manage');
        $case = $this->cases()->requireAccessible((int) $request->param('id'));
        $partyId = (int) $request->param('party');
        $this->cases()->removeParty((int) $case['id'], $partyId);
        $this->app->log('case.party_removed', 'case', (int) $case['id'], ['party_id' => $partyId]);
        return $this->redirect("/cases/{$case['id']}?tab=parties", 'Party removed.');
    }

    public function assign(Request $request): Response
    {
        if (!$this->auth()->can('staff.manage')) {
            return $this->redirect('/cases', 'Only the judge can assign staff to cases.', 'danger');
        }
        $case = $this->cases()->requireAccessible((int) $request->param('id'));
        $userId = (int) $request->input('user_id');
        $member = $this->app->db()->value("SELECT 1 FROM chamber_members WHERE chambers_id = ? AND user_id = ? AND status = 'active'", [(int) $case['chambers_id'], $userId]);
        if (!$member) {
            return $this->redirect("/cases/{$case['id']}?tab=staff", 'Choose a member of these chambers.', 'danger');
        }
        $this->cases()->assign((int) $case['id'], $userId, $this->auth()->id());
        $this->app->log('case.staff_assigned', 'case', (int) $case['id'], ['user_id' => $userId]);
        return $this->redirect("/cases/{$case['id']}?tab=staff", 'Staff member assigned to this case.');
    }

    public function unassign(Request $request): Response
    {
        if (!$this->auth()->can('staff.manage')) {
            return $this->redirect('/cases', 'Only the judge can change case assignments.', 'danger');
        }
        $case = $this->cases()->requireAccessible((int) $request->param('id'));
        $userId = (int) $request->param('user');
        $this->cases()->unassign((int) $case['id'], $userId);
        $this->app->log('case.staff_unassigned', 'case', (int) $case['id'], ['user_id' => $userId]);
        return $this->redirect("/cases/{$case['id']}?tab=staff", 'Assignment removed.');
    }
}
