<section class="site-hero">
    <div class="site-container">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <div class="site-eyebrow">Case administration for the bench</div>
                <h1 class="site-display">Every chambers, its own <span class="site-underline">private</span> workspace.</h1>
                <p class="site-lead">Track every matter from filing to judgment, keep rulings and status dates in view, capture hearings in text, and work alongside your marshals — all in one secure place that only your chambers can see.</p>
                <div class="d-flex flex-wrap gap-2 mt-4">
                    <a href="/signup" class="btn btn-dark btn-lg site-btn">Create your workspace</a>
                    <a href="#how" class="btn btn-outline-dark btn-lg site-btn site-btn-light">See how it works</a>
                </div>
                <ul class="site-ticks mt-4">
                    <li><i class="bi bi-check2"></i> Set up in minutes</li>
                    <li><i class="bi bi-check2"></i> Authenticator-app sign-in</li>
                    <li><i class="bi bi-check2"></i> Encrypted case data</li>
                </ul>
            </div>
            <div class="col-lg-6">
                <div class="site-window" aria-hidden="true">
                    <div class="site-window-bar"><span></span><span></span><span></span><em><?= e(strtolower(app_name())) ?>/app</em></div>
                    <div class="d-flex">
                        <div class="site-window-nav">
                            <div class="active">Bench Overview</div><div>Case Registry</div><div>Transcription</div><div>Reminders</div><div>Team</div><div>Returns</div>
                        </div>
                        <div class="site-window-body">
                            <div class="site-window-greet">Good morning, Judge</div>
                            <div class="site-window-stats">
                                <div><b>42</b><small>Active matters</small></div>
                                <div><b>5</b><small>Due this week</small></div>
                                <div><b>3</b><small>Judgments pending</small></div>
                            </div>
                            <div class="site-window-row"><div><b>Mwale v. Zambezi Holdings</b><small>2025/HPC/0417 · Hearing Tue 09:00</small></div><span>Trial</span></div>
                            <div class="site-window-row"><div><b>Banda v. Lusaka Freight Ltd</b><small>2026/HP/0112 · Ruling due Fri</small></div><span>Ruling</span></div>
                            <div class="site-window-row"><div><b>Phiri v. Copperbelt Energy</b><small>2026/HPC/0058 · Status conference</small></div><span>Scheduling</span></div>
                            <div class="site-window-row"><div><b>Tembo v. Chanda</b><small>2026/HP/0231 · Bundle filed by marshal</small></div><span>Filed</span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="site-built">
    <div class="site-container d-flex flex-wrap justify-content-center align-items-center gap-4">
        <span class="text-secondary small">Built for</span>
        <b>Judges</b><b>Arbitrators</b><b>Marshals</b><b>Judicial assistants</b><b>Court clerks</b>
    </div>
</section>

<section class="site-section" id="features">
    <div class="site-container">
        <div class="site-eyebrow">Features</div>
        <h2 class="site-h2">Everything a chambers needs,<br>nothing it doesn't.</h2>
        <p class="site-sub">Designed around how judges and their marshals actually move a file — not around a court-wide system built for strangers.</p>
        <div class="row g-3 mt-3">
            <?php foreach ([
                ['file-earmark-text', 'Case registry', 'Every matter with its cause number, parties, stage and papers. Follow each file from filing through status conference, trial and judgment.'],
                ['bell', 'Reminders & deadlines', 'Rulings, judgments and status dates in one list, with priorities, recurring reminders and a bell that shows what\'s due this week.'],
                ['mic', 'Transcription', 'Record a hearing live, or upload a dictation or recording, and get text back to edit, save against the matter and export to Word.'],
                ['people', 'Your team', 'Add your marshals, clerks and assistants. Each gets their own sign-in and exactly the permissions you choose — nothing more.'],
                ['bar-chart', 'Returns & reports', 'Concluded matters, pending output and the shape of your docket at a glance — ready when returns are due.'],
                ['bank', 'Arbitration mode', 'Arbitrators get the same workspace in arbitral language — references, claimants and respondents, tribunal stages and awards.'],
            ] as [$icon, $head, $text]): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="site-card h-100">
                        <div class="site-icon"><i class="bi bi-<?= e($icon) ?>"></i></div>
                        <h3><?= e($head) ?></h3>
                        <p><?= e($text) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="site-section site-section-tint" id="how">
    <div class="site-container">
        <div class="text-center">
            <div class="site-eyebrow">How it works</div>
            <h2 class="site-h2">Your chambers, online in three<br>steps.</h2>
        </div>
        <div class="row g-3 mt-3">
            <?php foreach ([
                ['Create your workspace', 'Sign up as a judge or arbitrator with your name, court and email. Confirm your email, scan a QR code with an authenticator app on your phone, and your private workspace is ready.'],
                ['Add your marshals', 'From Team, add each marshal or assistant: their details, then their role and what they may do. They receive their sign-in details and can only see your workspace.'],
                ['Work together', 'Your marshals open matters and upload bundles; you track stages, set reminders, transcribe and write — each with the access you\'ve granted.'],
            ] as $i => [$head, $text]): ?>
                <div class="col-md-4">
                    <div class="site-step h-100">
                        <span class="site-step-n"><?= $i + 1 ?></span>
                        <h3><?= e($head) ?></h3>
                        <p><?= e($text) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="site-section">
    <div class="site-container">
        <div class="site-eyebrow">Who does what</div>
        <h2 class="site-h2">You decide who sees what.</h2>
        <p class="site-sub">The judge or arbitrator owns the workspace. Everyone else works inside it with the permissions the owner sets, and can be removed at any time.</p>
        <div class="row g-3 mt-3">
            <div class="col-md-4">
                <div class="site-card site-card-strong h-100">
                    <div class="site-tag">Owner</div>
                    <h3>Judge or arbitrator</h3>
                    <ul class="site-list">
                        <li class="yes">Full access to every matter in the workspace</li>
                        <li class="yes">Reminders, transcription and reports</li>
                        <li class="yes">Adds and removes marshals and staff</li>
                        <li class="yes">Sets each person's permissions</li>
                        <li class="yes">Manages the subscription and exports reports</li>
                    </ul>
                </div>
            </div>
            <div class="col-md-4">
                <div class="site-card h-100">
                    <div class="site-tag">Default for marshals</div>
                    <h3>Marshal</h3>
                    <ul class="site-list">
                        <li class="yes">Views the case registry</li>
                        <li class="yes">Opens new matters</li>
                        <li class="yes">Uploads documents and bundles</li>
                        <li class="no">Cannot see sealed matters unless assigned</li>
                        <li class="no">Cannot see other judges' workspaces</li>
                    </ul>
                </div>
            </div>
            <div class="col-md-4">
                <div class="site-card h-100">
                    <div class="site-tag">Tailored by you</div>
                    <h3>Clerks &amp; assistants</h3>
                    <ul class="site-list">
                        <li class="yes">View cases and reminders — if you allow it</li>
                        <li class="yes">Add notes to files — if you allow it</li>
                        <li class="yes">Upload documents — if you allow it</li>
                        <li class="no">No access until you grant it</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="site-section site-section-grey" id="security">
    <div class="site-container">
        <div class="row g-5">
            <div class="col-lg-5">
                <div class="site-eyebrow">Security &amp; confidentiality</div>
                <h2 class="site-h2">Built for material that must stay in chambers.</h2>
                <p class="site-sub">Draft judgments, party details and hearing notes are some of the most sensitive documents there are. Protecting them is built into how the workspace works.</p>
                <p class="small text-secondary">Read how we handle personal data in our <a href="/privacy" class="text-reset">Privacy Policy</a>.</p>
            </div>
            <div class="col-lg-7">
                <?php foreach ([
                    ['grid', 'Walled-off workspaces', 'Every chambers is isolated. Marshals and staff belong to one workspace and can never reach another\'s matters. Platform administrators manage accounts, not case files.'],
                    ['key', 'Authenticator sign-in, every time', 'Each sign-in needs your password and a code from an authenticator app on your phone, set up by scanning a QR code at first sign-in. Passwords are stored only as one-way hashes.'],
                    ['lock', 'Encrypted case data', 'Party names, cause numbers, notes, reminders and transcripts are encrypted with AES-256 before they are stored, and all traffic travels over HTTPS.'],
                    ['shield-check', 'Private documents', 'Uploaded bundles are never publicly addressable — each download checks that you are signed in to the workspace that owns the file.'],
                ] as [$icon, $head, $text]): ?>
                    <div class="site-sec-item">
                        <div class="site-icon site-icon-sm"><i class="bi bi-<?= e($icon) ?>"></i></div>
                        <div><h3><?= e($head) ?></h3><p><?= e($text) ?></p></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<?php if ($plans): ?>
<section class="site-section" id="pricing">
    <div class="site-container">
        <div class="text-center">
            <div class="site-eyebrow">Pricing</div>
            <h2 class="site-h2">Simple plans. <?= (int) $trialDays ?> days free.</h2>
            <p class="site-sub mx-auto">Start with a free trial — no payment details needed. Pay monthly or annually by mobile money or bank transfer.</p>
        </div>
        <?= $view->partial('site/_plans', ['plans' => $plans]) ?>
    </div>
</section>
<?php endif; ?>

<section class="site-section site-section-tint" id="faq">
    <div class="site-container site-narrow">
        <div class="text-center">
            <div class="site-eyebrow">Questions</div>
            <h2 class="site-h2">Frequently asked</h2>
        </div>
        <div class="site-faq mt-4">
            <?php foreach ([
                ['Who can see my cases?', 'Only you and the people you add to your workspace, with the permissions you give them. Sealed matters are visible only to staff you assign to them explicitly. Platform administrators manage accounts and subscriptions; they have no screen that shows case files.'],
                ['How do I add a marshal?', 'Open Team, enter your marshal\'s name and e-mail and choose their role. You receive a one-time password to hand over securely; at first sign-in they choose their own password and set up an authenticator app.'],
                ['Is this an official court record?', 'No. It is your chambers\' private working space. Machine transcripts are drafts until you certify them against the original recording, and the court\'s official record remains with the registry.'],
                ['Can arbitrators use it?', 'Yes. Choose "Arbitrator" when you sign up (or switch later under Workspace settings) and the workspace speaks arbitral language: references, claimants and respondents, procedural orders and awards.'],
                ['What happens to recordings I transcribe?', 'Recordings are encrypted the moment they are stored. When AssemblyAI is the speech engine, the audio is sent to it over an encrypted connection for transcription and the remote copy is deleted as soon as the text comes back. Your recording itself never leaves your workspace\'s encrypted storage.'],
                ['Can I take my data with me?', 'Yes. Transcripts export to Word, reports export to CSV, documents download in their original format, and the cause list and returns print to PDF. If a subscription lapses the workspace becomes read-only — nothing is deleted.'],
            ] as [$q, $a]): ?>
                <details>
                    <summary><?= e($q) ?><i class="bi bi-chevron-down"></i></summary>
                    <p><?= e($a) ?></p>
                </details>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="site-section pt-0 site-section-tint">
    <div class="site-container">
        <div class="site-cta-box">
            <h2>Bring your chambers into one quiet place.</h2>
            <p>Create your workspace today and invite your marshals when you're ready.</p>
            <a href="/signup" class="btn btn-light site-btn">Create your workspace</a>
        </div>
    </div>
</section>
