<section class="site-section">
    <div class="site-container">
        <div class="text-center">
            <div class="site-eyebrow">Pricing</div>
            <h1 class="site-h2">Plans for every chambers.</h1>
            <p class="site-sub mx-auto">Every workspace starts with a <?= (int) $trialDays ?>-day free trial — no payment details needed. Afterwards, pay monthly or annually by mobile money or bank transfer. Prices include every security feature.</p>
        </div>
        <?php if ($plans): ?>
            <?= $view->partial('site/_plans', ['plans' => $plans]) ?>
        <?php else: ?>
            <p class="text-center text-secondary mt-4">Plans are being updated. Contact <a href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a>.</p>
        <?php endif; ?>
        <p class="text-center small text-secondary mt-4">Need an institutional agreement for a court or arbitral centre? Write to <a href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a>.</p>
    </div>
</section>
