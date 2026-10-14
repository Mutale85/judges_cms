<?php
$blank = ['id' => null, 'code' => '', 'name' => '', 'description' => '', 'price_monthly' => '', 'price_annual' => '', 'currency' => $currency, 'max_staff' => '', 'features' => '', 'sort_order' => count($plans) + 1, 'includes_transcription' => 1, 'is_active' => 1, 'is_featured' => 0];
$keys = array_flip(['id', 'code', 'name', 'description', 'price_monthly', 'price_annual', 'currency', 'max_staff', 'features', 'sort_order', 'includes_transcription', 'is_active', 'is_featured']);
$json = static fn (array $p) => json_encode(array_intersect_key($p, $keys), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <p class="text-muted mb-0">Prices shown on the public site and the billing page. Changes apply to payments submitted from now on; confirmed payments keep the price paid.</p>
    <button class="btn btn-dark" type="button" data-bs-toggle="modal" data-bs-target="#plan-modal" data-plan="<?= e($json($blank)) ?>"><i class="bi bi-plus-lg"></i> New plan</button>
</div>
<div class="row g-3">
    <?php if (!$plans): ?><div class="col-12"><div class="card jc-card"><div class="card-body text-muted">No plans yet.</div></div></div><?php endif; ?>
    <?php foreach ($plans as $p): ?>
        <div class="col-md-6 col-xl-4">
            <div class="card jc-card h-100<?= (int) $p['is_active'] === 1 ? '' : ' opacity-75' ?>">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><?= e($p['name']) ?> <span class="text-muted small fw-normal">· <?= e($p['code']) ?></span></span>
                    <span>
                        <?php if ((int) $p['is_featured'] === 1): ?><span class="badge text-bg-dark">Featured</span><?php endif; ?>
                        <span class="badge <?= (int) $p['is_active'] === 1 ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= (int) $p['is_active'] === 1 ? 'Active' : 'Hidden' ?></span>
                    </span>
                </div>
                <div class="card-body">
                    <div class="jc-serif fs-3 fw-bold"><?= e(money($p['price_monthly'], $p['currency'])) ?><span class="fs-6 text-muted fw-normal"> /month</span></div>
                    <div class="text-muted small mb-2"><?= e(money($p['price_annual'], $p['currency'])) ?> /year</div>
                    <?php if ($p['description']): ?><p class="small mb-2"><?= e($p['description']) ?></p><?php endif; ?>
                    <ul class="small mb-2 ps-3">
                        <li><?= $p['max_staff'] === null ? 'Unlimited staff' : 'Up to ' . (int) $p['max_staff'] . ' staff' ?></li>
                        <li><?= (int) $p['includes_transcription'] === 1 ? 'Transcription included' : 'No transcription' ?></li>
                        <?php foreach ($p['feature_list'] as $f): ?><li><?= e($f) ?></li><?php endforeach; ?>
                    </ul>
                    <div class="small text-muted"><?= (int) ($usage[$p['id']] ?? 0) ?> workspace(s) on this plan</div>
                </div>
                <div class="card-footer bg-white d-flex gap-2">
                    <button class="btn btn-sm btn-outline-dark" type="button" data-bs-toggle="modal" data-bs-target="#plan-modal" data-plan="<?= e($json($p)) ?>"><i class="bi bi-pencil"></i> Edit prices &amp; details</button>
                    <form method="post" action="/admin/plans/<?= (int) $p['id'] ?>/delete" data-confirm="Delete the <?= e($p['name']) ?> plan? Plans in use cannot be deleted — hide them instead." data-danger class="ms-auto"><?= $view->csrfField() ?><button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Delete"><i class="bi bi-trash"></i></button></form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="modal fade" id="plan-modal" tabindex="-1" aria-labelledby="plan-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="post" action="/admin/plans" id="plan-form">
            <?= $view->csrfField() ?>
            <div class="modal-header"><h2 class="modal-title h5" id="plan-modal-title">Plan</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label" for="p-name">Name</label><input class="form-control" id="p-name" name="name" required maxlength="120"></div>
                    <div class="col-md-3"><label class="form-label" for="p-code">Code</label><input class="form-control" id="p-code" name="code" required pattern="[a-z0-9][a-z0-9_\-]{1,63}" placeholder="e.g. chambers"></div>
                    <div class="col-md-3"><label class="form-label" for="p-currency">Currency</label><input class="form-control text-uppercase" id="p-currency" name="currency" required maxlength="3" pattern="[A-Za-z]{3}"></div>
                    <div class="col-12"><label class="form-label" for="p-description">Short description</label><input class="form-control" id="p-description" name="description" maxlength="500"></div>
                    <div class="col-md-4"><label class="form-label" for="p-monthly">Monthly price</label><input class="form-control" id="p-monthly" name="price_monthly" required inputmode="decimal" pattern="[0-9 ,]*\.?[0-9]{0,2}"></div>
                    <div class="col-md-4"><label class="form-label" for="p-annual">Annual price</label><input class="form-control" id="p-annual" name="price_annual" required inputmode="decimal" pattern="[0-9 ,]*\.?[0-9]{0,2}"></div>
                    <div class="col-md-4"><label class="form-label" for="p-staff">Staff allowed</label><input class="form-control" type="number" min="0" id="p-staff" name="max_staff" placeholder="Empty = unlimited"></div>
                    <div class="col-12"><label class="form-label" for="p-features">Features (one per line, shown on the pricing page)</label><textarea class="form-control" id="p-features" name="features" rows="4"></textarea></div>
                    <div class="col-md-3"><label class="form-label" for="p-sort">Order</label><input class="form-control" type="number" id="p-sort" name="sort_order"></div>
                    <div class="col-md-9 d-flex flex-wrap align-items-end gap-3">
                        <div class="form-check"><input class="form-check-input" type="checkbox" value="1" id="p-tx" name="includes_transcription"><label class="form-check-label" for="p-tx">Includes transcription</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" value="1" id="p-active" name="is_active"><label class="form-check-label" for="p-active">Available to choose</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" value="1" id="p-featured" name="is_featured"><label class="form-check-label" for="p-featured">Featured</label></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-dark" type="submit">Save plan</button></div>
        </form>
    </div>
</div>
