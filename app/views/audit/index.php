<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Audit trail</h1>
    <form method="post" action="/audit/verify"><?= $view->csrfField() ?><button class="btn btn-outline-primary" type="submit"><i class="bi bi-link-45deg"></i> Verify hash chain</button></form>
</div>
<p class="text-muted small">Every access and change is recorded in an append-only log. Each entry is sealed with a keyed hash of the previous one, so deleted, altered or re-ordered entries are detected.</p>
<div class="card jc-card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>#</th><th>When</th><th>Who</th><th>Action</th><th>Object</th><th>Details</th><th>IP</th></tr></thead>
            <tbody>
            <?php if (!$entries): ?><tr><td colspan="7" class="text-muted">No entries.</td></tr><?php endif; ?>
            <?php foreach ($entries as $a): ?>
                <tr>
                    <td class="small text-muted"><?= (int) $a['id'] ?></td>
                    <td class="small text-nowrap"><?= e(local_time($a['occurred_at'], 'j M Y H:i:s')) ?></td>
                    <td class="small"><?= e($a['user_name'] ?? 'system') ?></td>
                    <td><code><?= e($a['action']) ?></code></td>
                    <td class="small"><?= $a['entity_type'] ? e($a['entity_type']) . ' #' . (int) $a['entity_id'] : '' ?></td>
                    <td class="small jc-audit-details"><?= $a['details'] ? e(json_encode($a['details'], JSON_UNESCAPED_SLASHES)) : '' ?></td>
                    <td class="small text-muted"><?= e($a['ip_address'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<div class="d-flex justify-content-between mt-3">
    <?php if ($page > 1): ?><a class="btn btn-sm btn-outline-secondary" href="/audit?page=<?= $page - 1 ?>">Newer</a><?php else: ?><span></span><?php endif; ?>
    <?php if (count($entries) === 100): ?><a class="btn btn-sm btn-outline-secondary" href="/audit?page=<?= $page + 1 ?>">Older</a><?php endif; ?>
</div>
