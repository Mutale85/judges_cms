<?php

declare(strict_types=1);

/*
 * Self-service workspaces, subscriptions, the platform admin panel, reminders
 * and returns.
 *
 *  - chambers gain a mode (court | arbitration) and subscription state;
 *  - users gain e-mail verification, backed by single-use hashed tokens;
 *  - plans, payments and platform_settings drive billing, managed from /admin;
 *  - reminders hold deadlines, ruling and judgment dates (encrypted text);
 *  - cases record when their status last changed and when they concluded,
 *    which the returns report needs;
 *  - recordings can be uploaded audio files as well as live captures.
 */

return static function (string $driver): array {
    $mysql = $driver === 'mysql';
    $pk = $mysql ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $fk = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
    $bigEnc = $mysql ? 'MEDIUMTEXT' : 'TEXT';
    $opts = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

    $sql = [];

    // --- Users: e-mail verification -------------------------------------------
    $sql[] = 'ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL';
    // Accounts created before self-service sign-up were provisioned by an administrator or a judge.
    $sql[] = 'UPDATE users SET email_verified_at = created_at';

    $sql[] = "CREATE TABLE user_tokens (
        id {$pk},
        user_id {$fk} NOT NULL REFERENCES users(id),
        purpose VARCHAR(32) NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at DATETIME NOT NULL
    ){$opts}";

    // --- Plans & settings -------------------------------------------------------
    $sql[] = "CREATE TABLE plans (
        id {$pk},
        code VARCHAR(64) NOT NULL UNIQUE,
        name VARCHAR(120) NOT NULL,
        description VARCHAR(500) NULL,
        price_monthly DECIMAL(12,2) NOT NULL DEFAULT 0,
        price_annual DECIMAL(12,2) NOT NULL DEFAULT 0,
        currency VARCHAR(3) NOT NULL DEFAULT 'ZMW',
        max_staff INT NULL,
        includes_transcription SMALLINT NOT NULL DEFAULT 1,
        features TEXT NULL,
        is_active SMALLINT NOT NULL DEFAULT 1,
        is_featured SMALLINT NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ){$opts}";

    $sql[] = "CREATE TABLE platform_settings (
        name VARCHAR(64) NOT NULL PRIMARY KEY,
        value TEXT NULL,
        updated_at DATETIME NOT NULL
    ){$opts}";

    // --- Chambers: mode and subscription -------------------------------------------
    $sql[] = "ALTER TABLE chambers ADD COLUMN mode VARCHAR(16) NOT NULL DEFAULT 'court'";
    $sql[] = "ALTER TABLE chambers ADD COLUMN plan_id {$fk} NULL REFERENCES plans(id)";
    $sql[] = "ALTER TABLE chambers ADD COLUMN subscription_status VARCHAR(16) NOT NULL DEFAULT 'active'";
    $sql[] = "ALTER TABLE chambers ADD COLUMN billing_cycle VARCHAR(16) NOT NULL DEFAULT 'monthly'";
    $sql[] = 'ALTER TABLE chambers ADD COLUMN trial_ends_at DATETIME NULL';
    $sql[] = 'ALTER TABLE chambers ADD COLUMN current_period_end DATETIME NULL';
    $sql[] = 'ALTER TABLE chambers ADD COLUMN self_registered SMALLINT NOT NULL DEFAULT 0';

    $sql[] = "CREATE TABLE payments (
        id {$pk},
        chambers_id {$fk} NOT NULL REFERENCES chambers(id),
        plan_id {$fk} NOT NULL REFERENCES plans(id),
        billing_cycle VARCHAR(16) NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        currency VARCHAR(3) NOT NULL,
        method VARCHAR(32) NOT NULL,
        reference VARCHAR(190) NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        period_start DATETIME NULL,
        period_end DATETIME NULL,
        notes VARCHAR(1000) NULL,
        submitted_by {$fk} NULL REFERENCES users(id),
        reviewed_by {$fk} NULL REFERENCES users(id),
        reviewed_at DATETIME NULL,
        created_at DATETIME NOT NULL
    ){$opts}";

    // --- Reminders & deadlines --------------------------------------------------------
    $sql[] = "CREATE TABLE reminders (
        id {$pk},
        chambers_id {$fk} NOT NULL REFERENCES chambers(id),
        case_id {$fk} NULL REFERENCES cases(id),
        kind VARCHAR(16) NOT NULL DEFAULT 'reminder',
        title TEXT NOT NULL,
        details {$bigEnc} NULL,
        due_at DATETIME NOT NULL,
        priority VARCHAR(16) NOT NULL DEFAULT 'normal',
        recurrence VARCHAR(16) NOT NULL DEFAULT 'none',
        status VARCHAR(16) NOT NULL DEFAULT 'open',
        created_by {$fk} NOT NULL REFERENCES users(id),
        completed_by {$fk} NULL REFERENCES users(id),
        completed_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ){$opts}";

    // --- Recordings: uploaded audio files (dictation, recordings made elsewhere) ------
    $sql[] = "ALTER TABLE recordings ADD COLUMN source VARCHAR(16) NOT NULL DEFAULT 'live'";
    $sql[] = 'ALTER TABLE recordings ADD COLUMN mime_type VARCHAR(128) NULL';
    $sql[] = 'ALTER TABLE recordings ADD COLUMN original_name TEXT NULL';

    // --- Cases: dates the returns report needs ------------------------------------
    $sql[] = 'ALTER TABLE cases ADD COLUMN status_changed_at DATETIME NULL';
    $sql[] = 'ALTER TABLE cases ADD COLUMN concluded_at DATETIME NULL';
    $sql[] = 'UPDATE cases SET status_changed_at = updated_at';
    $sql[] = "UPDATE cases SET concluded_at = updated_at WHERE status IN ('concluded', 'closed')";

    foreach ([
        'CREATE INDEX idx_tokens_user ON user_tokens (user_id, purpose)',
        'CREATE INDEX idx_payments_chambers ON payments (chambers_id, status)',
        'CREATE INDEX idx_payments_status ON payments (status, created_at)',
        'CREATE INDEX idx_reminders_chambers_due ON reminders (chambers_id, status, due_at)',
        'CREATE INDEX idx_reminders_case ON reminders (case_id)',
        'CREATE INDEX idx_chambers_subscription ON chambers (subscription_status, current_period_end)',
    ] as $index) {
        $sql[] = $index;
    }

    // --- Seed data: starter plans and settings (editable from /admin) ---------------
    $now = gmdate('Y-m-d H:i:s');
    $plans = [
        ['solo', 'Solo', 'One judge or arbitrator working alone.', '450.00', '4500.00', 1, 1, "Case registry and calendar\nReminders and deadlines\nTranscription of hearings and dictation\nReturns and reports", 0, 1],
        ['chambers', 'Chambers', 'A judge with marshals, clerks and assistants.', '950.00', '9500.00', 6, 1, "Everything in Solo\nUp to 6 staff with their own sign-in\nPer-person permissions\nTamper-evident audit trail", 1, 2],
        ['institution', 'Institution', 'Larger chambers, tribunals and arbitral bodies.', '2400.00', '24000.00', null, 1, "Everything in Chambers\nUnlimited staff\nPriority support\nOnboarding for your team", 0, 3],
    ];
    foreach ($plans as [$code, $name, $desc, $monthly, $annual, $staff, $tx, $features, $featured, $sort]) {
        $sql[] = sprintf(
            "INSERT INTO plans (code, name, description, price_monthly, price_annual, currency, max_staff, includes_transcription, features, is_active, is_featured, sort_order, created_at, updated_at)
             VALUES ('%s', '%s', '%s', %s, %s, 'ZMW', %s, %d, '%s', 1, %d, %d, '%s', '%s')",
            $code, $name, $desc, $monthly, $annual, $staff === null ? 'NULL' : (string) $staff, $tx, $features, $featured, $sort, $now, $now
        );
    }
    foreach ([
        'signup_enabled' => '1',
        'trial_days' => '30',
        'trial_plan' => 'chambers',
        'currency' => 'ZMW',
        'grace_days' => '7',
        'support_email' => 'support@judges.co.zm',
        'payment_instructions' => "Pay by mobile money (Airtel Money / MTN MoMo) or bank transfer, then submit the transaction reference below.\nYour subscription is activated as soon as the payment is confirmed.",
    ] as $name => $value) {
        $sql[] = sprintf("INSERT INTO platform_settings (name, value, updated_at) VALUES ('%s', '%s', '%s')", $name, str_replace("'", "''", $value), $now);
    }

    return $sql;
};
