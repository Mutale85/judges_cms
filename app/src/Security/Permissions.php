<?php

declare(strict_types=1);

namespace JC\Security;

/**
 * Chamber roles and capabilities.
 *
 * Each chamber member has an explicit capability list, initialised from their
 * role's defaults and adjustable by the judge. Some capabilities are reserved
 * to the judge and can never be delegated.
 */
final class Permissions
{
    public const ROLES = [
        'judge' => 'Judge',
        'marshal' => 'Marshal',
        'clerk' => 'Clerk',
        'judicial_assistant' => 'Judicial assistant',
        'researcher' => 'Researcher',
        'court_reporter' => 'Court reporter',
    ];

    public const CAPABILITIES = [
        'cases.view' => 'View assigned cases',
        'cases.all' => 'Access every (unsealed) case in these chambers, not only assigned ones',
        'cases.manage' => 'Register cases, edit case details and parties',
        'documents.upload' => 'Upload case documents',
        'hearings.propose' => 'Prepare the hearing calendar (propose hearings)',
        'hearings.confirm' => 'Confirm, adjourn and complete hearings',
        'recordings.record' => 'Record authorised hearings',
        'transcripts.edit' => 'Correct draft transcripts',
        'orders.draft' => 'Prepare draft orders',
        'notes.private' => 'Access private judicial notes and judgment drafts',
        'tasks.manage' => 'Create and assign chamber tasks',
        'audit.view' => 'View the chambers audit trail',
        'transcripts.certify' => 'Certify transcripts as the official record',
        'judgments.approve' => 'Approve and sign judgments',
        'staff.manage' => 'Manage staff and permissions',
    ];

    /** Capabilities that belong to the judge alone. */
    public const JUDGE_ONLY = ['transcripts.certify', 'judgments.approve', 'staff.manage'];

    public const DEFAULTS = [
        'marshal' => ['cases.view', 'cases.manage', 'documents.upload', 'hearings.propose', 'recordings.record', 'transcripts.edit', 'tasks.manage'],
        'clerk' => ['cases.view', 'documents.upload', 'hearings.propose', 'recordings.record'],
        'judicial_assistant' => ['cases.view', 'documents.upload', 'orders.draft', 'transcripts.edit'],
        'researcher' => ['cases.view'],
        'court_reporter' => ['cases.view', 'recordings.record', 'transcripts.edit'],
    ];

    /** @return list<string> */
    public static function defaultsFor(string $role): array
    {
        if ($role === 'judge') {
            return array_keys(self::CAPABILITIES);
        }
        return self::DEFAULTS[$role] ?? [];
    }

    /** @return array<string,string> */
    public static function delegable(): array
    {
        return array_diff_key(self::CAPABILITIES, array_flip(self::JUDGE_ONLY));
    }

    /**
     * Filters a requested capability list down to what a staff role may hold.
     *
     * @param list<string> $requested
     * @return list<string>
     */
    public static function sanitise(string $role, array $requested): array
    {
        if ($role === 'judge') {
            return self::defaultsFor('judge');
        }
        $allowed = array_keys(self::delegable());
        return array_values(array_intersect($allowed, $requested));
    }
}
