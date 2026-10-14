<?php
use JC\Security\Permissions;
use JC\Service\TaskService;
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h1 class="h3 mb-0">Tasks</h1>
    <div class="btn-group btn-group-sm">
        <?php if ($auth->can('tasks.manage')): ?>
            <a class="btn btn-outline-secondary<?= $scope === 'all' ? ' active' : '' ?>" href="/tasks?scope=all&show=<?= e($show) ?>">All chambers tasks</a>
            <a class="btn btn-outline-secondary<?= $scope === 'mine' ? ' active' : '' ?>" href="/tasks?scope=mine&show=<?= e($show) ?>">Mine</a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary<?= $show === 'open' ? ' active' : '' ?>" href="/tasks?scope=<?= e($scope) ?>&show=open">Open</a>
        <a class="btn btn-outline-secondary<?= $show === 'all' ? ' active' : '' ?>" href="/tasks?scope=<?= e($scope) ?>&show=all">Include closed</a>
    </div>
</div>

<div class="row g-4">
    <div class="<?= $auth->can('tasks.manage') ? 'col-lg-8' : 'col-12' ?>">
        <div class="card jc-card">
            <div class="list-group list-group-flush">
                <?php if (!$tasks): ?><div class="list-group-item text-muted">No tasks.</div><?php endif; ?>
                <?php foreach ($tasks as $t):
                    $overdue = $t['due_on'] && $t['due_on'] < date('Y-m-d') && in_array($t['status'], ['open', 'in_progress'], true);
                    $mayUpdate = (int) $t['assigned_to'] === $auth->id() || $auth->can('tasks.manage'); ?>
                    <div class="list-group-item">
                        <div class="d-flex flex-wrap justify-content-between gap-2">
                            <div>
                                <div class="fw-semibold"><?= e($t['title']) ?></div>
                                <div class="small text-muted">
                                    <?php if ($t['case_id']): ?><a href="/cases/<?= (int) $t['case_id'] ?>"><?= e($t['case_number']) ?></a> · <?php endif; ?>
                                    <?= e($t['assignee_name']) ?> · from <?= e($t['assigner_name']) ?>
                                    <?php if ($t['due_on']): ?> · <span class="<?= $overdue ? 'text-danger fw-semibold' : '' ?>">due <?= e($t['due_on']) ?></span><?php endif; ?>
                                </div>
                                <?php if ($t['details']): ?><div class="small mt-1"><?= nl2br(e($t['details'])) ?></div><?php endif; ?>
                            </div>
                            <div>
                                <?php if ($mayUpdate): ?>
                                    <form method="post" action="/tasks/<?= (int) $t['id'] ?>/status" class="d-flex gap-1">
                                        <?= $view->csrfField() ?>
                                        <select name="status" class="form-select form-select-sm" data-autosubmit aria-label="Task status">
                                            <?php foreach (TaskService::STATUSES as $k => $v): ?><option value="<?= e($k) ?>" <?= $t['status'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
                                        </select>
                                    </form>
                                <?php else: ?>
                                    <span class="badge text-bg-light border"><?= e(TaskService::STATUSES[$t['status']] ?? '') ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php if ($auth->can('tasks.manage')): ?>
        <div class="col-lg-4">
            <div class="card jc-card">
                <div class="card-header">Assign a task</div>
                <div class="card-body">
                    <form method="post" action="/tasks">
                        <?= $view->csrfField() ?>
                        <div class="mb-2"><input class="form-control" name="title" placeholder="Task" required></div>
                        <div class="mb-2">
                            <select class="form-select" name="assigned_to" required>
                                <?php foreach ($members as $m): if ($m['status'] !== 'active') continue; ?>
                                    <option value="<?= (int) $m['user_id'] ?>"><?= e($m['full_name']) ?> (<?= e(Permissions::roleLabel($m['role'])) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-2">
                            <select class="form-select" name="case_id">
                                <option value="">No specific case</option>
                                <?php foreach ($cases as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['case_number']) ?> — <?= e(mb_strimwidth($c['title'], 0, 50, '…')) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-2"><input class="form-control" type="date" name="due_on"></div>
                        <div class="mb-2"><textarea class="form-control" name="details" rows="3" placeholder="Details"></textarea></div>
                        <button class="btn btn-primary w-100" type="submit">Assign</button>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
