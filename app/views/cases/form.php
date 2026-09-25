<?php use JC\Service\CaseService; $editing = $case !== null; ?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <h1 class="h3 mb-3"><?= $editing ? 'Edit case' : 'Register a case' ?></h1>
        <div class="card jc-card">
            <div class="card-body">
                <form method="post" action="<?= $editing ? '/cases/' . (int) $case['id'] : '/cases' ?>">
                    <?= $view->csrfField() ?>
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label" for="case_number">Case number</label>
                            <input class="form-control" id="case_number" name="case_number" required maxlength="100" value="<?= e($case['case_number'] ?? '') ?>" placeholder="e.g. 2026/HP/0142">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="case_type">Case type</label>
                            <select class="form-select" id="case_type" name="case_type" required>
                                <?php foreach (CaseService::TYPES as $k => $v): ?>
                                    <option value="<?= e($k) ?>" <?= ($case['case_type'] ?? '') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="filed_on">Date filed</label>
                            <input class="form-control" type="date" id="filed_on" name="filed_on" value="<?= e($case['filed_on'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="title">Title</label>
                            <input class="form-control" id="title" name="title" required maxlength="500" value="<?= e($case['title'] ?? '') ?>" placeholder="e.g. Mwansa Holdings Ltd v Copperbelt Traders Ltd">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="summary">Summary / nature of claim</label>
                            <textarea class="form-control" id="summary" name="summary" rows="5"><?= e($case['summary'] ?? '') ?></textarea>
                        </div>
                        <?php if (!$editing && $auth->isJudge()): ?>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="1" id="sealed" name="sealed">
                                    <label class="form-check-label" for="sealed">Sealed case — visible only to me and staff I explicitly assign; documents are stored in the sealed store under a separate key.</label>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><?= $editing ? 'Save changes' : 'Register case' ?></button>
                        <a href="<?= $editing ? '/cases/' . (int) $case['id'] : '/cases' ?>" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
