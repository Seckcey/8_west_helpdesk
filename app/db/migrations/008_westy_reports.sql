-- 008_westy_reports.sql — Westy failure + flagged-answer reporting.
-- Additive only. MySQL 8 / utf8mb4 / InnoDB.
-- Run:  sudo mysql safeharbor < db/migrations/008_westy_reports.sql
--
-- One row per PROBLEM, not per occurrence. The fingerprint is derived from
-- the normalised error class (or the normalised question), never from a
-- timestamp or an occurrence id — the mistake visible in the alert intake,
-- where keys like alert:231 / alert:232 opened six tickets for one warning.
--
-- Deliberately its own table rather than columns on `tickets`: count,
-- last-seen and generation are Westy-specific state, and `tickets` is shared
-- by email intake, the portal and alert intake.

CREATE TABLE IF NOT EXISTS westy_reports (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id         INT UNSIGNED NOT NULL,
  fingerprint       VARCHAR(48)  NOT NULL,   -- base key, no generation suffix
  external_key      VARCHAR(64)  NOT NULL,   -- fingerprint [+ ':g' + generation]
  app               VARCHAR(32)  NOT NULL,   -- safeharbor | milepost | controlpanel
  kind              ENUM('fail','flag') NOT NULL,
  generation        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  ticket_id         INT UNSIGNED NULL,
  system_message_id INT UNSIGNED NULL,       -- the one line rewritten in place
  occurrences       INT UNSIGNED NOT NULL DEFAULT 1,
  first_seen_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  detail_json       TEXT NULL,               -- scrubbed envelope, latest occurrence
  PRIMARY KEY (id),
  UNIQUE KEY uq_westy_reports_key (tenant_id, external_key),
  KEY idx_westy_reports_fp (tenant_id, fingerprint, generation),
  KEY idx_westy_reports_ticket (ticket_id),
  CONSTRAINT fk_westy_reports_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
