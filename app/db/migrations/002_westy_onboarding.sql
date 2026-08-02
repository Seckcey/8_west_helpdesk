-- 002_westy_onboarding.sql — Westy (suite assistant) + onboarding, Sprint 1.
-- For EXISTING installs; a fresh install gets both from db/schema.sql
-- (skip the ALTER there — MySQL 8 has no ADD COLUMN IF NOT EXISTS).

SET NAMES utf8mb4;

-- First-run onboarding: NULL = Westy still owes this user the welcome tour.
ALTER TABLE users ADD COLUMN onboarded_at DATETIME NULL AFTER last_login_at;

-- Westy usage/audit rows — lengths and outcomes only, never message content.
-- Drives the per-user sliding-window rate limit in api/westy_chat.php.
CREATE TABLE IF NOT EXISTS assistant_log (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  action     VARCHAR(32)  NOT NULL,
  meta       VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_assistant_user_action_created (user_id, action, created_at),
  CONSTRAINT fk_assistant_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
