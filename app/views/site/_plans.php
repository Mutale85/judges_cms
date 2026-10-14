<?php /** @var list<array<string,mixed>> $plans */ ?>
<div class="row g-3 mt-3 justify-content-center">
    <?php foreach ($plans as $p): ?>
        <div class="col-md-6 col-lg-4">
            <div class="site-card site-plan h-100<?= (int) $p['is_featured'] === 1 ? ' site-plan-featured' : '' ?>">
                <?php if ((int) $p['is_featured'] === 1): ?><div class="site-plan-badge">Most chosen</div><?php endif; ?>
                <h3><?= e($p['name']) ?></h3>
                <?php if ($p['description']): ?><p class="mb-3"><?= e($p['description']) ?></p><?php endif; ?>
                <div class="site-price"><span><?= e($p['currency']) ?></span> <?= e(number_format((float) $p['price_monthly'], 0)) ?><small>/month</small></div>
                <div class="small text-secondary mb-3">or <?= e(money($p['price_annual'], $p['currency'])) ?> a year</div>
                <ul class="site-list">
                    <li class="yes"><?= $p['max_staff'] === null ? 'Unlimited staff' : ((int) $p['max_staff'] === 0 ? 'Just you' : 'Up to ' . (int) $p['max_staff'] . ' staff') ?></li>
                    <?php foreach ($p['feature_list'] as $f): ?><li class="yes"><?= e($f) ?></li><?php endforeach; ?>
                    <?php if ((int) $p['includes_transcription'] !== 1): ?><li class="no">Transcription not included</li><?php endif; ?>
                </ul>
                <a href="/signup" class="btn <?= (int) $p['is_featured'] === 1 ? 'btn-dark' : 'btn-outline-dark' ?> w-100 mt-auto site-btn">Start free trial</a>
            </div>
        </div>
    <?php endforeach; ?>
</div>
