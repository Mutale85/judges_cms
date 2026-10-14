<form class="row g-2 mb-3" method="get" action="/admin/users" data-live-search="#users-table">
    <div class="col-md-6"><input type="search" class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search name, e-mail or workspace" aria-label="Search" autofocus></div>
    <div class="col-md-3">
        <select class="form-select" name="filter" aria-label="Filter">
            <?php foreach (['' => 'Everyone', 'owners' => 'Workspace owners', 'staff' => 'Staff', 'unverified' => 'E-mail not confirmed', 'suspended' => 'Suspended', 'admins' => 'Administrators'] as $k => $v): ?>
                <option value="<?= e($k) ?>" <?= $filter === $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</form>
<div id="users-table"><?= $view->partial('admin/_users_table', compact('users', 'total', 'pageNo', 'pages', 'q', 'filter')) ?></div>
