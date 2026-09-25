<?php

declare(strict_types=1);

/*
 * Initial schema. Written once for MySQL 8 (production) and SQLite (development
 * and tests); only the handful of dialect differences are switched on $driver.
 *
 * Columns holding personal or case data are TEXT because they store
 * ciphertext produced by JC\Security\FieldCipher, never plaintext.
 */

return static function (string $driver): array {
    $mysql = $driver === 'mysql';
    $pk = $mysql ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $fk = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
    $enc = $mysql ? 'TEXT' : 'TEXT';
    $bigEnc = $mysql ? 'MEDIUMTEXT' : 'TEXT';
    $opts = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

    $sql = [];

    $sql[] = "CREATE TABLE users (
        id {$pk},
        email {$enc} NOT NULL,
        email_bidx CHAR(64) NOT NULL UNIQUE,
        full_name {$enc} NOT NULL,
        phone {$enc} NULL,
        password_hash VARCHAR(255) NOT NULL,
        is_admin SMALLINT NOT NULL DEFAULT 0,
        status VARCHAR(16) NOT NULL DEFAULT 'active',
        must_change_password SMALLINT NOT NULL DEFAULT 1,
        mfa_secret {$enc} NULL,
        mfa_enabled SMALLINT NOT NULL DEFAULT 0,
        mfa_last_step BIGINT NULL,
        failed_logins INT NOT NULL DEFAULT 0,
        locked_until DATETIME NULL,
        last_login_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ){$opts}";

    $sql[] = "CREATE TABLE chambers (
        id {$pk},
        name VARCHAR(190) NOT NULL,
        court VARCHAR(190) NOT NULL,
        division VARCHAR(190) NULL,
        location VARCHAR(190) NULL,
        judge_user_id {$fk} NOT NULL REFERENCES users(id),
        created_at DATETIME NOT NULL
    ){$opts}";

    $sql[] = "CREATE TABLE chamber_members (
        id {$pk},
        chambers_id {$fk} NOT NULL REFERENCES chambers(id),
        user_id {$fk} NOT NULL REFERENCES users(id),
        role VARCHAR(32) NOT NULL,
        permissions TEXT NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'active',
        invited_by {$fk} NULL REFERENCES users(id),
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE (chambers_id, user_id)
    ){$opts}";

    $sql[] = "CREATE TABLE cases (
        id {$pk},
        chambers_id {$fk} NOT NULL REFERENCES chambers(id),
        case_number {$enc} NOT NULL,
        case_number_bidx CHAR(64) NOT NULL,
        title {$enc} NOT NULL,
        case_type VARCHAR(32) NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'registered',
        sealed SMALLINT NOT NULL DEFAULT 0,
        summary {$bigEnc} NULL,
        filed_on DATE NULL,
        registered_by {$fk} NOT NULL REFERENCES users(id),
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE (chambers_id, case_number_bidx)
    ){$opts}";

    $sql[] = "CREATE TABLE case_parties (
        id {$pk},
        case_id {$fk} NOT NULL REFERENCES cases(id),
        party_role VARCHAR(32) NOT NULL,
        name {$enc} NOT NULL,
        representing {$enc} NULL,
        contact {$enc} NULL,
        created_at DATETIME NOT NULL
    ){$opts}";

    $sql[] = "CREATE TABLE case_assignments (
        id {$pk},
        case_id {$fk} NOT NULL REFERENCES cases(id),
        user_id {$fk} NOT NULL REFERENCES users(id),
        assigned_by {$fk} NOT NULL REFERENCES users(id),
        created_at DATETIME NOT NULL,
        UNIQUE (case_id, user_id)
    ){$opts}";

    $sql[] = "CREATE TABLE documents (
        id {$pk},
        case_id {$fk} NOT NULL REFERENCES cases(id),
        category VARCHAR(32) NOT NULL,
        title {$enc} NOT NULL,
        description {$enc} NULL,
        original_name {$enc} NOT NULL,
        mime_type VARCHAR(128) NOT NULL,
        size_bytes BIGINT NOT NULL,
        storage_key VARCHAR(190) NOT NULL UNIQUE,
        wrapped_key TEXT NOT NULL,
        key_purpose VARCHAR(32) NOT NULL,
        sha256 CHAR(64) NOT NULL,
        confidential SMALLINT NOT NULL DEFAULT 0,
        uploaded_by {$fk} NOT NULL REFERENCES users(id),
        created_at DATETIME NOT NULL
    ){$opts}";

    $sql[] = "CREATE TABLE hearings (
        id {$pk},
        case_id {$fk} NOT NULL REFERENCES cases(id),
        chambers_id {$fk} NOT NULL REFERENCES chambers(id),
        scheduled_at DATETIME NOT NULL,
        duration_minutes INT NOT NULL DEFAULT 60,
        hearing_type VARCHAR(64) NOT NULL,
        courtroom VARCHAR(120) NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'proposed',
        notes {$bigEnc} NULL,
        outcome {$bigEnc} NULL,
        proposed_by {$fk} NOT NULL REFERENCES users(id),
        confirmed_by {$fk} NULL REFERENCES users(id),
        confirmed_at DATETIME NULL,
        adjourned_from_id {$fk} NULL REFERENCES hearings(id),
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ){$opts}";

    $sql[] = "CREATE TABLE tasks (
        id {$pk},
        chambers_id {$fk} NOT NULL REFERENCES chambers(id),
        case_id {$fk} NULL REFERENCES cases(id),
        title {$enc} NOT NULL,
        details {$bigEnc} NULL,
        assigned_to {$fk} NOT NULL REFERENCES users(id),
        assigned_by {$fk} NOT NULL REFERENCES users(id),
        due_on DATE NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'open',
        created_at DATETIME NOT NULL,
        completed_at DATETIME NULL
    ){$opts}";

    $sql[] = "CREATE TABLE judicial_notes (
        id {$pk},
        case_id {$fk} NOT NULL REFERENCES cases(id),
        author_id {$fk} NOT NULL REFERENCES users(id),
        title {$enc} NOT NULL,
        body {$bigEnc} NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ){$opts}";

    $sql[] = "CREATE TABLE recordings (
        id {$pk},
        hearing_id {$fk} NOT NULL REFERENCES hearings(id),
        case_id {$fk} NOT NULL REFERENCES cases(id),
        chambers_id {$fk} NOT NULL REFERENCES chambers(id),
        started_by {$fk} NOT NULL REFERENCES users(id),
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        storage_key VARCHAR(190) NULL UNIQUE,
        wrapped_key TEXT NULL,
        sha256 CHAR(64) NULL,
        chunk_chain CHAR(64) NULL,
        bytes BIGINT NULL,
        duration_ms BIGINT NULL,
        sample_rate INT NOT NULL DEFAULT 16000,
        language VARCHAR(16) NOT NULL DEFAULT 'en',
        started_at DATETIME NULL,
        ended_at DATETIME NULL,
        integrity_verified_at DATETIME NULL,
        transcript_status VARCHAR(16) NOT NULL DEFAULT 'none',
        certified_by {$fk} NULL REFERENCES users(id),
        certified_at DATETIME NULL,
        certification_hash CHAR(64) NULL,
        certification_key_id VARCHAR(16) NULL,
        created_at DATETIME NOT NULL
    ){$opts}";

    $sql[] = "CREATE TABLE transcript_segments (
        id {$pk},
        recording_id {$fk} NOT NULL REFERENCES recordings(id),
        seq INT NOT NULL,
        start_ms BIGINT NOT NULL,
        end_ms BIGINT NOT NULL,
        speaker {$enc} NULL,
        text {$bigEnc} NOT NULL,
        machine_text {$bigEnc} NULL,
        confidence DECIMAL(5,4) NULL,
        source VARCHAR(16) NOT NULL DEFAULT 'machine',
        edited_by {$fk} NULL REFERENCES users(id),
        edited_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        UNIQUE (recording_id, seq)
    ){$opts}";

    $sql[] = "CREATE TABLE recording_bookmarks (
        id {$pk},
        recording_id {$fk} NOT NULL REFERENCES recordings(id),
        at_ms BIGINT NOT NULL,
        label {$enc} NOT NULL,
        created_by {$fk} NULL REFERENCES users(id),
        created_at DATETIME NOT NULL
    ){$opts}";

    $sql[] = "CREATE TABLE audit_log (
        id {$pk},
        occurred_at DATETIME NOT NULL,
        user_id {$fk} NULL,
        chambers_id {$fk} NULL,
        action VARCHAR(64) NOT NULL,
        entity_type VARCHAR(32) NULL,
        entity_id {$fk} NULL,
        details {$bigEnc} NULL,
        ip_address {$enc} NULL,
        prev_hash CHAR(64) NOT NULL,
        entry_hash CHAR(64) NOT NULL,
        mac_key_id VARCHAR(16) NOT NULL
    ){$opts}";

    $sql[] = "CREATE TABLE audit_head (
        id INT NOT NULL PRIMARY KEY,
        last_hash CHAR(64) NOT NULL,
        last_id {$fk} NOT NULL
    ){$opts}";
    $sql[] = "INSERT INTO audit_head (id, last_hash, last_id) VALUES (1, '" . str_repeat('0', 64) . "', 0)";

    $sql[] = "CREATE TABLE service_nonces (
        nonce CHAR(32) NOT NULL PRIMARY KEY,
        expires_at DATETIME NOT NULL
    ){$opts}";

    foreach ([
        'CREATE INDEX idx_members_user ON chamber_members (user_id)',
        'CREATE INDEX idx_cases_chambers ON cases (chambers_id, status)',
        'CREATE INDEX idx_parties_case ON case_parties (case_id)',
        'CREATE INDEX idx_documents_case ON documents (case_id)',
        'CREATE INDEX idx_hearings_chambers_time ON hearings (chambers_id, scheduled_at)',
        'CREATE INDEX idx_hearings_case ON hearings (case_id)',
        'CREATE INDEX idx_tasks_assignee ON tasks (assigned_to, status)',
        'CREATE INDEX idx_tasks_chambers ON tasks (chambers_id, status)',
        'CREATE INDEX idx_notes_case ON judicial_notes (case_id)',
        'CREATE INDEX idx_recordings_hearing ON recordings (hearing_id)',
        'CREATE INDEX idx_bookmarks_recording ON recording_bookmarks (recording_id)',
        'CREATE INDEX idx_audit_chambers ON audit_log (chambers_id, id)',
        'CREATE INDEX idx_nonces_expiry ON service_nonces (expires_at)',
    ] as $index) {
        $sql[] = $index;
    }

    // --- Integrity triggers ---------------------------------------------------
    if ($mysql) {
        $sql[] = "CREATE TRIGGER audit_log_no_update BEFORE UPDATE ON audit_log FOR EACH ROW
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only'";
        $sql[] = "CREATE TRIGGER audit_log_no_delete BEFORE DELETE ON audit_log FOR EACH ROW
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only'";
        $sql[] = "CREATE TRIGGER recordings_immutable BEFORE UPDATE ON recordings FOR EACH ROW
                  BEGIN
                    IF OLD.sha256 IS NOT NULL AND (NOT (NEW.sha256 <=> OLD.sha256) OR NOT (NEW.storage_key <=> OLD.storage_key)
                        OR NOT (NEW.wrapped_key <=> OLD.wrapped_key) OR NOT (NEW.chunk_chain <=> OLD.chunk_chain)) THEN
                      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'finalised recordings are immutable';
                    END IF;
                    IF OLD.transcript_status = 'certified' AND NEW.transcript_status <> 'certified' THEN
                      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'certified transcripts cannot be reopened';
                    END IF;
                  END";
        $sql[] = "CREATE TRIGGER recordings_no_delete BEFORE DELETE ON recordings FOR EACH ROW
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'recordings cannot be deleted from the application database'";
        $sql[] = "CREATE TRIGGER segments_certified_update BEFORE UPDATE ON transcript_segments FOR EACH ROW
                  BEGIN
                    IF (SELECT transcript_status FROM recordings WHERE id = OLD.recording_id) = 'certified' THEN
                      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'certified transcripts are read-only';
                    END IF;
                  END";
        $sql[] = "CREATE TRIGGER segments_certified_delete BEFORE DELETE ON transcript_segments FOR EACH ROW
                  BEGIN
                    IF (SELECT transcript_status FROM recordings WHERE id = OLD.recording_id) = 'certified' THEN
                      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'certified transcripts are read-only';
                    END IF;
                  END";
        $sql[] = "CREATE TRIGGER segments_certified_insert BEFORE INSERT ON transcript_segments FOR EACH ROW
                  BEGIN
                    IF (SELECT transcript_status FROM recordings WHERE id = NEW.recording_id) = 'certified' THEN
                      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'certified transcripts are read-only';
                    END IF;
                  END";
    } else {
        $sql[] = "CREATE TRIGGER audit_log_no_update BEFORE UPDATE ON audit_log
                  BEGIN SELECT RAISE(ABORT, 'audit_log is append-only'); END";
        $sql[] = "CREATE TRIGGER audit_log_no_delete BEFORE DELETE ON audit_log
                  BEGIN SELECT RAISE(ABORT, 'audit_log is append-only'); END";
        $sql[] = "CREATE TRIGGER recordings_immutable BEFORE UPDATE ON recordings
                  WHEN OLD.sha256 IS NOT NULL AND (NEW.sha256 IS NOT OLD.sha256 OR NEW.storage_key IS NOT OLD.storage_key
                       OR NEW.wrapped_key IS NOT OLD.wrapped_key OR NEW.chunk_chain IS NOT OLD.chunk_chain)
                  BEGIN SELECT RAISE(ABORT, 'finalised recordings are immutable'); END";
        $sql[] = "CREATE TRIGGER recordings_certified_status BEFORE UPDATE ON recordings
                  WHEN OLD.transcript_status = 'certified' AND NEW.transcript_status <> 'certified'
                  BEGIN SELECT RAISE(ABORT, 'certified transcripts cannot be reopened'); END";
        $sql[] = "CREATE TRIGGER recordings_no_delete BEFORE DELETE ON recordings
                  BEGIN SELECT RAISE(ABORT, 'recordings cannot be deleted from the application database'); END";
        $sql[] = "CREATE TRIGGER segments_certified_update BEFORE UPDATE ON transcript_segments
                  WHEN (SELECT transcript_status FROM recordings WHERE id = OLD.recording_id) = 'certified'
                  BEGIN SELECT RAISE(ABORT, 'certified transcripts are read-only'); END";
        $sql[] = "CREATE TRIGGER segments_certified_delete BEFORE DELETE ON transcript_segments
                  WHEN (SELECT transcript_status FROM recordings WHERE id = OLD.recording_id) = 'certified'
                  BEGIN SELECT RAISE(ABORT, 'certified transcripts are read-only'); END";
        $sql[] = "CREATE TRIGGER segments_certified_insert BEFORE INSERT ON transcript_segments
                  WHEN (SELECT transcript_status FROM recordings WHERE id = NEW.recording_id) = 'certified'
                  BEGIN SELECT RAISE(ABORT, 'certified transcripts are read-only'); END";
    }

    return $sql;
};
