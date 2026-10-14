<div class="site-prose">
    <div class="site-eyebrow">Legal</div>
    <h1>Privacy Policy</h1>
    <p class="text-secondary small">Last updated <?= e(date('j F Y', strtotime('2026-10-01'))) ?>. Template — review against the Data Protection Act, 2021 (Zambia) with counsel before publishing.</p>
    <h2>Who we are</h2>
    <p><?= e(JC\Core\Config::get('APP_COMPANY', 'Linksys Zambia Limited')) ?> operates <?= e(app_name()) ?>. For account data we are the controller; for the case material you place in your workspace we act on your instructions as processor.</p>
    <h2>What we hold</h2>
    <ul>
        <li><strong>Account data</strong>: name, e-mail, phone (optional), court or institution, password hash, authenticator secret, sign-in times.</li>
        <li><strong>Workspace content</strong>: matters, parties, documents, notes, reminders, recordings and transcripts that you and your staff add.</li>
        <li><strong>Billing data</strong>: plan, payment method and the transaction reference you submit. We do not hold card numbers.</li>
        <li><strong>Security records</strong>: a tamper-evident audit trail of access and changes, including IP addresses.</li>
    </ul>
    <h2>How it is protected</h2>
    <p>Personal and case data is encrypted with AES-256-GCM before it is stored; documents and recordings each have their own key; all traffic uses TLS. Workspaces are isolated from each other. Platform administrators can manage accounts and subscriptions but have no screen that shows case content.</p>
    <h2>Who else processes it</h2>
    <p>When your workspace's speech engine is AssemblyAI, hearing audio is sent to AssemblyAI over TLS to produce the draft transcript, and the remote copy is deleted once the text is returned. E-mail is sent through our mail provider (account messages only — never case content).</p>
    <h2>How long we keep it</h2>
    <p>Workspace content is kept for as long as the workspace exists, including while it is read-only after a lapsed subscription. Owners can ask us to delete a workspace; the audit trail is kept as long as the law requires.</p>
    <h2>Your rights</h2>
    <p>You may ask for access to, correction of or deletion of your personal data by writing to <a href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a>.</p>
</div>
