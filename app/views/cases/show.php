<?php
use JC\Security\Permissions;
use JC\Service\CaseService;
use JC\Service\DocumentService;
use JC\Service\HearingService;
use JC\Service\TaskService;

$caseId = (int) $case['id'];
$tabs = ['overview' => 'Overview', 'parties' => 'Parties (' . count($parties) . ')', 'documents' => 'Bundle (' . count($documents) . ')', 'hearings' => 'Hearings (' . count($hearings) . ')', 'tasks' => 'Tasks (' . count($tasks) . ')'];
if ($auth->can('notes.private')) {
    $tabs['notes'] = 'Private notes';
}
if ($auth->can('staff.manage')) {
    $tabs['staff'] = 'Staff access';
}
$tab = isset($tabs[$tab]) ? $tab : 'overview';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="/cases">Cases</a></li><li class="breadcrumb-item active"><?= e($case['case_number']) ?></li></ol></nav>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h3 mb-1">
            <?php if ((int) $case['sealed'] === 1): ?><span class="badge text-bg-danger align-middle me-1"><i class="bi bi-lock-fill"></i> Sealed</span><?php endif; ?>
            <?= e($case['case_number']) ?>
        </h1>
        <div class="lead"><?= e($case['title']) ?></div>
        <div class="text-muted small"><?= e(CaseService::TYPES[$case['case_type']] ?? '') ?> · <?= e(CaseService::STATUSES[$case['status']] ?? '') ?><?= $case['filed_on'] ? ' · Filed ' . e($case['filed_on']) : '' ?></div>
    </div>
    <div class="d-flex gap-2">
        <?php if ($auth->can('cases.manage')): ?><a class="btn btn-outline-secondary btn-sm" href="/cases/<?= $caseId ?>/edit"><i class="bi bi-pencil"></i> Edit</a><?php endif; ?>
    </div>
</div>

<ul class="nav nav-tabs mb-3" role="tablist">
    <?php foreach ($tabs as $key => $labelText): ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link<?= $tab === $key ? ' active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-<?= $key ?>" type="button" role="tab"><?= e($labelText) ?></button>
        </li>
    <?php endforeach; ?>
</ul>

<div class="tab-content">
    <!-- Overview -->
    <div class="tab-pane fade<?= $tab === 'overview' ? ' show active' : '' ?>" id="tab-overview" role="tabpanel">
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card jc-card">
                    <div class="card-header">Summary</div>
                    <div class="card-body"><?= $case['summary'] ? nl2br(e($case['summary'])) : '<span class="text-muted">No summary recorded.</span>' ?></div>
                </div>
                <div class="card jc-card mt-4">
                    <div class="card-header">Recordings &amp; transcripts</div>
                    <div class="list-group list-group-flush">
                        <?php if (!$recordings): ?><div class="list-group-item text-muted">No recordings yet.</div><?php endif; ?>
                        <?php foreach ($recordings as $r): ?>
                            <a href="/recordings/<?= (int) $r['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between">
                                <span><i class="bi bi-mic"></i> <?= e(local_time($r['started_at'] ?? $r['created_at'])) ?> · <?= e(format_duration_ms($r['duration_ms'])) ?></span>
                                <span><span class="badge text-bg-light border"><?= e(label($r['status'])) ?></span> <span class="badge <?= $r['transcript_status'] === 'certified' ? 'text-bg-success' : 'text-bg-warning' ?>"><?= e(label($r['transcript_status'])) ?></span></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <?php if ($auth->can('cases.manage')): ?>
                    <div class="card jc-card mb-3">
                        <div class="card-header">Case status</div>
                        <div class="card-body">
                            <form method="post" action="/cases/<?= $caseId ?>/status" class="d-flex gap-2">
                                <?= $view->csrfField() ?>
                                <select class="form-select" name="status">
                                    <?php foreach (CaseService::STATUSES as $k => $v): ?><option value="<?= e($k) ?>" <?= $case['status'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
                                </select>
                                <button class="btn btn-outline-primary" type="submit">Update</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($auth->isJudge()): ?>
                    <div class="card jc-card mb-3">
                        <div class="card-header">Confidentiality</div>
                        <div class="card-body">
                            <form method="post" action="/cases/<?= $caseId ?>/seal" data-confirm="<?= (int) $case['sealed'] === 1 ? 'Unseal this case? Staff with access to all cases will be able to see it.' : 'Seal this case? Only you and explicitly assigned staff will see it.' ?>">
                                <?= $view->csrfField() ?>
                                <input type="hidden" name="sealed" value="<?= (int) $case['sealed'] === 1 ? '0' : '1' ?>">
                                <button class="btn <?= (int) $case['sealed'] === 1 ? 'btn-outline-secondary' : 'btn-outline-danger' ?> w-100" type="submit">
                                    <i class="bi <?= (int) $case['sealed'] === 1 ? 'bi-unlock' : 'bi-lock' ?>"></i> <?= (int) $case['sealed'] === 1 ? 'Unseal case' : 'Seal case' ?>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="card jc-card">
                    <div class="card-header">Staff on this case</div>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($assignees as $a): ?><li class="list-group-item"><?= e($a['full_name']) ?> <span class="text-muted small"><?= e(Permissions::ROLES[$a['role'] ?? ''] ?? '') ?></span></li><?php endforeach; ?>
                        <?php if (!$assignees): ?><li class="list-group-item text-muted">No staff assigned.</li><?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Parties -->
    <div class="tab-pane fade<?= $tab === 'parties' ? ' show active' : '' ?>" id="tab-parties" role="tabpanel">
        <div class="card jc-card mb-4">
            <div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <thead><tr><th>Role</th><th>Name</th><th>Representing / firm</th><th>Contact</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$parties): ?><tr><td colspan="5" class="text-muted">No parties recorded.</td></tr><?php endif; ?>
                    <?php foreach ($parties as $p): ?>
                        <tr>
                            <td><?= e(CaseService::PARTY_ROLES[$p['party_role']] ?? $p['party_role']) ?></td>
                            <td class="fw-semibold"><?= e($p['name']) ?></td>
                            <td><?= e($p['representing'] ?? '') ?></td>
                            <td><?= e($p['contact'] ?? '') ?></td>
                            <td class="text-end">
                                <?php if ($auth->can('cases.manage')): ?>
                                    <form method="post" action="/cases/<?= $caseId ?>/parties/<?= (int) $p['id'] ?>/delete" data-confirm="Remove this party?">
                                        <?= $view->csrfField() ?><button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-x"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if ($auth->can('cases.manage')): ?>
            <div class="card jc-card">
                <div class="card-header">Add party or advocate</div>
                <div class="card-body">
                    <form method="post" action="/cases/<?= $caseId ?>/parties" class="row g-2">
                        <?= $view->csrfField() ?>
                        <div class="col-md-2">
                            <select class="form-select" name="party_role" required>
                                <?php foreach (CaseService::PARTY_ROLES as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3"><input class="form-control" name="name" placeholder="Full name" required></div>
                        <div class="col-md-3"><input class="form-control" name="representing" placeholder="Representing / law firm"></div>
                        <div class="col-md-3"><input class="form-control" name="contact" placeholder="Phone / e-mail / address"></div>
                        <div class="col-md-1 d-grid"><button class="btn btn-primary" type="submit">Add</button></div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Documents -->
    <div class="tab-pane fade<?= $tab === 'documents' ? ' show active' : '' ?>" id="tab-documents" role="tabpanel">
        <div class="card jc-card mb-4">
            <div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <thead><tr><th>Category</th><th>Title</th><th>Size</th><th>Uploaded</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    <?php if (!$documents): ?><tr><td colspan="5" class="text-muted">The case bundle is empty.</td></tr><?php endif; ?>
                    <?php foreach ($documents as $d): ?>
                        <tr>
                            <td class="text-nowrap"><?= e(DocumentService::CATEGORIES[$d['category']] ?? $d['category']) ?><?php if ((int) $d['confidential'] === 1): ?> <span class="badge text-bg-dark">Private</span><?php endif; ?></td>
                            <td>
                                <div class="fw-semibold"><?= e($d['title']) ?></div>
                                <div class="small text-muted"><?= e($d['original_name']) ?><?= $d['description'] ? ' · ' . e($d['description']) : '' ?></div>
                            </td>
                            <td class="text-nowrap small"><?= e(format_bytes($d['size_bytes'])) ?></td>
                            <td class="small text-nowrap"><?= e(local_time($d['created_at'], 'j M Y H:i')) ?><br><span class="text-muted"><?= e($d['uploader']) ?></span></td>
                            <td class="text-end text-nowrap">
                                <?php if (in_array($d['mime_type'], ['application/pdf', 'image/png', 'image/jpeg', 'text/plain'], true)): ?>
                                    <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="/documents/<?= (int) $d['id'] ?>/download?inline=1" title="View"><i class="bi bi-eye"></i></a>
                                <?php endif; ?>
                                <a class="btn btn-sm btn-outline-secondary" href="/documents/<?= (int) $d['id'] ?>/download" title="Download"><i class="bi bi-download"></i></a>
                                <form method="post" action="/documents/<?= (int) $d['id'] ?>/verify" class="d-inline"><?= $view->csrfField() ?><button class="btn btn-sm btn-outline-secondary" title="Verify integrity (SHA-256 <?= e(substr($d['sha256'], 0, 12)) ?>…)"><i class="bi bi-patch-check"></i></button></form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if ($auth->can('documents.upload')): ?>
            <div class="card jc-card">
                <div class="card-header"><i class="bi bi-cloud-arrow-up"></i> Add to the case bundle</div>
                <div class="card-body">
                    <form method="post" action="/cases/<?= $caseId ?>/documents" enctype="multipart/form-data" class="row g-2">
                        <?= $view->csrfField() ?>
                        <div class="col-md-3">
                            <select class="form-select" name="category" required>
                                <?php foreach (DocumentService::CATEGORIES as $k => $v):
                                    if ($k === 'draft_order' && !$auth->can('orders.draft')) continue;
                                    if ($k === 'judgment_draft' && !$auth->can('notes.private')) continue; ?>
                                    <option value="<?= e($k) ?>"><?= e($v) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4"><input class="form-control" name="title" placeholder="Title (defaults to file name)"></div>
                        <div class="col-md-5"><input class="form-control" type="file" name="document" required></div>
                        <div class="col-md-9"><input class="form-control" name="description" placeholder="Description (optional)"></div>
                        <div class="col-md-3 d-grid"><button class="btn btn-primary" type="submit">Encrypt &amp; upload</button></div>
                        <?php if ($auth->can('notes.private')): ?>
                            <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="confidential" value="1" id="confidential"><label class="form-check-label small" for="confidential">Private judicial material (hidden from staff without the private-notes permission)</label></div></div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Hearings -->
    <div class="tab-pane fade<?= $tab === 'hearings' ? ' show active' : '' ?>" id="tab-hearings" role="tabpanel">
        <div class="card jc-card mb-4">
            <div class="list-group list-group-flush">
                <?php if (!$hearings): ?><div class="list-group-item text-muted">No hearings yet.</div><?php endif; ?>
                <?php foreach ($hearings as $h): ?>
                    <a href="/hearings/<?= (int) $h['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between">
                        <span><span class="fw-semibold"><?= e(local_time($h['scheduled_at'])) ?></span> · <?= e(HearingService::TYPES[$h['hearing_type']] ?? '') ?><?= $h['courtroom'] ? ' · ' . e($h['courtroom']) : '' ?></span>
                        <span class="badge text-bg-light border"><?= e(HearingService::STATUSES[$h['status']] ?? '') ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($auth->can('hearings.propose')): ?>
            <div class="card jc-card">
                <div class="card-header"><?= $auth->can('hearings.confirm') ? 'Schedule a hearing' : 'Propose a hearing date' ?></div>
                <div class="card-body">
                    <form method="post" action="/cases/<?= $caseId ?>/hearings" class="row g-2">
                        <?= $view->csrfField() ?>
                        <div class="col-md-3"><label class="form-label small">Date &amp; time</label><input class="form-control" type="datetime-local" name="scheduled_at" required></div>
                        <div class="col-md-3"><label class="form-label small">Type</label>
                            <select class="form-select" name="hearing_type"><?php foreach (HearingService::TYPES as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select>
                        </div>
                        <div class="col-md-2"><label class="form-label small">Minutes</label><input class="form-control" type="number" name="duration_minutes" value="60" min="5" max="600"></div>
                        <div class="col-md-4"><label class="form-label small">Courtroom</label><input class="form-control" name="courtroom" placeholder="e.g. Court 4, Lusaka"></div>
                        <div class="col-md-9"><input class="form-control" name="notes" placeholder="Notes for the judge (optional)"></div>
                        <div class="col-md-3 d-grid">
                            <?php if ($auth->can('hearings.confirm')): ?><input type="hidden" name="confirm" value="1"><?php endif; ?>
                            <button class="btn btn-primary" type="submit"><?= $auth->can('hearings.confirm') ? 'Schedule' : 'Propose' ?></button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Tasks -->
    <div class="tab-pane fade<?= $tab === 'tasks' ? ' show active' : '' ?>" id="tab-tasks" role="tabpanel">
        <div class="card jc-card mb-4">
            <div class="list-group list-group-flush">
                <?php if (!$tasks): ?><div class="list-group-item text-muted">No tasks for this case.</div><?php endif; ?>
                <?php foreach ($tasks as $t): ?>
                    <div class="list-group-item d-flex justify-content-between">
                        <span><span class="fw-semibold"><?= e($t['title']) ?></span> <span class="text-muted small">→ <?= e($t['assignee_name']) ?><?= $t['due_on'] ? ' · due ' . e($t['due_on']) : '' ?></span></span>
                        <span class="badge text-bg-light border"><?= e(TaskService::STATUSES[$t['status']] ?? '') ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($auth->can('tasks.manage') && $members): ?>
            <div class="card jc-card">
                <div class="card-header">Assign a task on this case</div>
                <div class="card-body">
                    <form method="post" action="/tasks" class="row g-2">
                        <?= $view->csrfField() ?>
                        <input type="hidden" name="case_id" value="<?= $caseId ?>">
                        <input type="hidden" name="back" value="/cases/<?= $caseId ?>?tab=tasks">
                        <div class="col-md-5"><input class="form-control" name="title" placeholder="e.g. Prepare hearing bundle" required></div>
                        <div class="col-md-3"><select class="form-select" name="assigned_to" required><?php foreach ($members as $m): if ($m['status'] !== 'active') continue; ?><option value="<?= (int) $m['user_id'] ?>"><?= e($m['full_name']) ?> (<?= e(Permissions::ROLES[$m['role']] ?? '') ?>)</option><?php endforeach; ?></select></div>
                        <div class="col-md-2"><input class="form-control" type="date" name="due_on"></div>
                        <div class="col-md-2 d-grid"><button class="btn btn-primary" type="submit">Assign</button></div>
                        <div class="col-12"><textarea class="form-control" name="details" rows="2" placeholder="Details (optional)"></textarea></div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($auth->can('notes.private')): ?>
    <!-- Private notes -->
    <div class="tab-pane fade<?= $tab === 'notes' ? ' show active' : '' ?>" id="tab-notes" role="tabpanel">
        <div class="alert alert-dark small"><i class="bi bi-shield-lock"></i> Private judicial notes are encrypted under a separate key and are visible only to the judge and staff explicitly granted access. They are never shown to parties.</div>
        <?php foreach ($notes as $n): ?>
            <div class="card jc-card mb-3">
                <div class="card-body">
                    <form method="post" action="/notes/<?= (int) $n['id'] ?>">
                        <?= $view->csrfField() ?>
                        <input class="form-control fw-semibold mb-2" name="title" value="<?= e($n['title']) ?>" required>
                        <textarea class="form-control mb-2" name="body" rows="6" required><?= e($n['body']) ?></textarea>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="small text-muted"><?= e($n['author_name']) ?> · updated <?= e(local_time($n['updated_at'])) ?></span>
                            <button class="btn btn-sm btn-outline-primary" type="submit">Save</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
        <div class="card jc-card">
            <div class="card-header">New note</div>
            <div class="card-body">
                <form method="post" action="/cases/<?= $caseId ?>/notes">
                    <?= $view->csrfField() ?>
                    <input class="form-control mb-2" name="title" placeholder="e.g. Issues for determination" required>
                    <textarea class="form-control mb-2" name="body" rows="6" required></textarea>
                    <button class="btn btn-primary" type="submit">Save note</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($auth->can('staff.manage')): ?>
    <!-- Staff access -->
    <div class="tab-pane fade<?= $tab === 'staff' ? ' show active' : '' ?>" id="tab-staff" role="tabpanel">
        <p class="text-muted small">Staff see a case only when assigned to it<?= (int) $case['sealed'] === 1 ? '' : ', or when they hold the "all cases" permission' ?>. <?= (int) $case['sealed'] === 1 ? 'This case is sealed: only the staff listed here can see it.' : '' ?></p>
        <div class="card jc-card mb-3">
            <ul class="list-group list-group-flush">
                <?php foreach ($assignees as $a): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><?= e($a['full_name']) ?> <span class="text-muted small"><?= e(Permissions::ROLES[$a['role'] ?? ''] ?? '') ?></span></span>
                        <form method="post" action="/cases/<?= $caseId ?>/assign/<?= (int) $a['user_id'] ?>/delete"><?= $view->csrfField() ?><button class="btn btn-sm btn-outline-danger" type="submit">Remove</button></form>
                    </li>
                <?php endforeach; ?>
                <?php if (!$assignees): ?><li class="list-group-item text-muted">No staff assigned.</li><?php endif; ?>
            </ul>
        </div>
        <form method="post" action="/cases/<?= $caseId ?>/assign" class="d-flex gap-2">
            <?= $view->csrfField() ?>
            <select class="form-select" name="user_id">
                <?php foreach ($members as $m): if ($m['role'] === 'judge' || $m['status'] !== 'active') continue; ?>
                    <option value="<?= (int) $m['user_id'] ?>"><?= e($m['full_name']) ?> (<?= e(Permissions::ROLES[$m['role']] ?? '') ?>)</option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary text-nowrap" type="submit">Assign to case</button>
        </form>
    </div>
    <?php endif; ?>
</div>
