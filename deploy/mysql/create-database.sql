-- Creates the database and a least-privilege application account that can only
-- connect over TLS. Run as an administrator, then: php app/bin/jc migrate
--
-- The migration creates triggers, so run migrations with an account that has
-- TRIGGER privilege (e.g. jc_migrate below); the day-to-day application account
-- does not need it.

CREATE DATABASE IF NOT EXISTS judicial_chambers
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    ENCRYPTION = 'Y';

CREATE USER IF NOT EXISTS 'jc_app'@'10.0.0.%' IDENTIFIED BY 'change-me-long-random'
    REQUIRE SSL;
GRANT SELECT, INSERT, UPDATE, DELETE ON judicial_chambers.* TO 'jc_app'@'10.0.0.%';

CREATE USER IF NOT EXISTS 'jc_migrate'@'10.0.0.%' IDENTIFIED BY 'change-me-other-long-random'
    REQUIRE SSL;
GRANT ALL PRIVILEGES ON judicial_chambers.* TO 'jc_migrate'@'10.0.0.%';

FLUSH PRIVILEGES;
