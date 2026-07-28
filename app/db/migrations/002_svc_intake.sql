-- Phase 8.1 (Path B): signed service-to-service alert intake.
-- Additive only. MySQL 8 / utf8mb4 / InnoDB (no ADD COLUMN IF NOT EXISTS).
-- Run:  mysql -u safeharbor -p safeharbor < db/migrations/002_svc_intake.sql

-- Idempotency key for externally-originated tickets (e.g. Milepost alerts).
ALTER TABLE tickets
  ADD COLUMN external_key VARCHAR(64) NULL AFTER channel,
  ADD UNIQUE KEY uq_tickets_tenant_extkey (tenant_id, external_key);

-- Registered service callers. Secrets are NOT stored here — they live only
-- in the server config.php ('svc.secrets'), per house rules.
CREATE TABLE IF NOT EXISTS svc_identities (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id    INT UNSIGNED NOT NULL,
  service      VARCHAR(32)  NOT NULL,
  display_name VARCHAR(64)  NOT NULL,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_svc_tenant_service (tenant_id, service),
  CONSTRAINT fk_svc_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fixed-window rate buckets for api/svc/* (120 requests/min per service).
CREATE TABLE IF NOT EXISTS svc_rate_buckets (
  service       VARCHAR(32) NOT NULL,
  bucket_minute INT UNSIGNED NOT NULL,
  hits          INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (service, bucket_minute)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
