<?php use JC\Service\CaseService; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Cases</h1>
    <?php if ($auth->can('cases.manage')): ?><a href="/cases/new" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Register case</a><?php endif; ?>
</div>

<form class="row g-2 mb-3" method="get" action="/cases">
    <div class="col-md-5"><input type="search" class="form-control" name="q" value="<?= e($filters['q']) ?>" placeholder="Search case number or title"></div>
    <div class="col-md-3">
        <select name="status" class="form-select">
            <option value="">All statuses</option>
            <?php foreach (CaseService::STATUSES as $k => $v): ?><option value="<?= e($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <select name="type" class="form-select">
            <option value="">All case types</option>
            <?php foreach (CaseService::TYPES as $k => $v): ?><option value="<?= e($k) ?>" <?= $filters['type'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-1 d-grid"><button class="btn btn-outline-secondary" type="submit">Filter</button></div>
</form>

<div class="card jc-card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Case number</th><th>Title</th><th>Type</th><th>Status</th><th>Filed</th><th>Updated</th></tr></thead>
            <tbody>
            <?php if (!$cases): ?><tr><td colspan="6" class="text-muted text-center py-4">No cases found.</td></tr><?php endif; ?>
            <?php foreach ($cases as $c): ?>
                <tr>
                    <td class="text-nowrap">
                        <?php if ((int) $c['sealed'] === 1): ?><i class="bi bi-lock-fill text-danger" title="Sealed"></i><?php endif; ?>
                        <a href="/cases/<?= (int) $c['id'] ?>" class="fw-semibold"><?= e($c['case_number']) ?></a>
                    </td>
                    <td><?= e($c['title']) ?></td>
                    <td><?= e(CaseService::TYPES[$c['case_type']] ?? $c['case_type']) ?></td>
                    <td><span class="badge text-bg-light border"><?= e(CaseService::STATUSES[$c['status']] ?? $c['status']) ?></span></td>
                    <td class="text-nowrap"><?= e($c['filed_on'] ?? '') ?></td>
                    <td class="text-nowrap small text-muted"><?= e(local_time($c['updated_at'], 'j M Y')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
