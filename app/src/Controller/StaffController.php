<?php

declare(strict_types=1);

namespace JC\Controller;

use JC\Core\HttpException;
use JC\Core\Request;
use JC\Core\Response;
use JC\Service\UserService;

/**
 * The judge's staff management: invite marshals, clerks and assistants into
 * chambers and decide exactly what each of them can do.
 */
final class StaffController extends Controller
{
    private function users(): UserService
    {
        return $this->app->service(UserService::class);
    }

    public function index(Request $request): Response
    {
        $this->auth()->authorize('staff.manage');
        return $this->render('staff/index', [
            'title' => 'Team',
            'members' => $this->users()->members($this->auth()->requireChambersId()),
        ]);
    }

    public function store(Request $request): Response
    {
        $this->auth()->authorize('staff.manage');
        $chambersId = $this->auth()->requireChambersId();
        return $this->attempt('/staff', function () use ($request, $chambersId): Response {
            $role = (string) $request->input('role');
            if ($role === 'judge') {
                throw new \InvalidArgumentException('Judges are appointed by the institution administrator.');
            }
            $chambers = $this->auth()->chambers() ?? [];
            $subs = $this->app->service(\JC\Service\SubscriptionService::class);
            if (!$subs->canAddStaff($chambers)) {
                throw new \InvalidArgumentException('Your plan allows ' . $subs->staffLimit($chambers) . ' staff. Suspend someone or upgrade the plan under Billing to add more.');
            }
            $email = (string) $request->input('email');
            $existing = $this->users()->findByEmail($email);
            $temp = null;
            if ($existing === null) {
                $temp = UserService::temporaryPassword();
                $userId = $this->users()->create($email, (string) $request->input('full_name'), $temp, false, $request->input('phone') ?: null);
            } else {
                $userId = (int) $existing['id'];
                if ($this->app->db()->value('SELECT 1 FROM chamber_members WHERE chambers_id = ? AND user_id = ?', [$chambersId, $userId])) {
                    throw new \InvalidArgumentException('That person is already a member of these chambers.');
                }
            }
            $memberId = $this->users()->addMember($chambersId, $userId, $role, $this->auth()->id());
            $this->app->log('staff.added', 'member', $memberId, ['user_id' => $userId, 'role' => $role]);
            $message = 'Staff member added.';
            if ($temp !== null) {
                $message .= " Give them this one-time password through a secure channel: {$temp} — they must change it and set up two-factor authentication at first sign-in.";
            }
            return $this->redirect('/staff', $message);
        });
    }

    public function edit(Request $request): Response
    {
        $this->auth()->authorize('staff.manage');
        $member = $this->member($request);
        return $this->render('staff/edit', ['title' => 'Staff permissions', 'member' => $member]);
    }

    public function update(Request $request): Response
    {
        $this->auth()->authorize('staff.manage');
        $member = $this->member($request);
        return $this->attempt("/staff/{$member['id']}", function () use ($request, $member): Response {
            $role = (string) $request->input('role');
            // Changing role resets permissions to that role's defaults unless the judge customised them.
            $perms = $role !== $member['role'] && $request->input('reset_defaults') === '1'
                ? \JC\Security\Permissions::defaultsFor($role)
                : $request->inputList('permissions');
            $this->users()->updateMember((int) $member['id'], $role, $perms, (string) $request->input('status', 'active'));
            $this->app->log('staff.permissions_changed', 'member', (int) $member['id'], [
                'user_id' => (int) $member['user_id'],
                'role' => $role,
                'permissions' => $perms,
                'status' => $request->input('status'),
            ]);
            return $this->redirect('/staff', 'Permissions updated.');
        });
    }

    public function resetPassword(Request $request): Response
    {
        $this->auth()->authorize('staff.manage');
        $member = $this->member($request);
        $temp = UserService::temporaryPassword();
        $this->users()->resetPassword((int) $member['user_id'], $temp);
        $this->app->log('staff.password_reset', 'member', (int) $member['id'], ['user_id' => (int) $member['user_id']]);
        return $this->redirect('/staff', "Temporary password for {$member['full_name']}: {$temp} — share it securely; it must be changed at next sign-in.");
    }

    public function resetMfa(Request $request): Response
    {
        $this->auth()->authorize('staff.manage');
        $member = $this->member($request);
        $this->users()->resetMfa((int) $member['user_id']);
        $this->app->log('staff.mfa_reset', 'member', (int) $member['id'], ['user_id' => (int) $member['user_id']]);
        return $this->redirect('/staff', "Two-factor authentication reset for {$member['full_name']}. They will enrol again at next sign-in.");
    }

    /** @return array<string,mixed> */
    private function member(Request $request): array
    {
        $member = $this->users()->member($this->auth()->requireChambersId(), (int) $request->param('id'));
        if ($member === null || $member['role'] === 'judge') {
            throw new HttpException(404);
        }
        return $member;
    }
}
