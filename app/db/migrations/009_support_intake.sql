-- 009_support_intake.sql — Coastmark → Safeharbor human support intake.
-- Additive only. MySQL 8 / utf8mb4 / InnoDB (no ADD COLUMN IF NOT EXISTS).
-- Run:  sudo mysql safeharbor < db/migrations/009_support_intake.sql
--
-- SCHEMA.SQL DOES NOT CARRY THIS, exactly like 002_svc_intake.sql: every
-- svc_* object lives in a migration. A fresh install needs schema.sql +
-- 002 + 009, in that order.
--
-- Two additions:
--
-- 1. clients.source_key — a stable routing key for client rows that an
--    outside system owns. Coastmark tenant "acme-msp" becomes the client row
--    'coastmark:acme-msp'. Routing follows the KEY, so a tech can rename the
--    client row to anything they like and the next request from that tenant
--    still lands on it. Keying on the display name instead would fork the
--    queue silently the first time somebody renamed a row.
--
-- 2. svc_support_rate — a per-TENANT cap on top of the existing per-identity
--    120/min in svc_rate_buckets. One identity ('coastmark-support') carries
--    every Coastmark tenant, so without this one noisy MSP could spend the
--    whole budget and lock the others out.

ALTER TABLE clients
  ADD COLUMN source_key VARCHAR(64) NULL AFTER domain,
  ADD UNIQUE KEY uq_clients_tenant_source (tenant_id, source_key);

CREATE TABLE IF NOT EXISTS svc_support_rate (
  source      VARCHAR(32) NOT NULL,              -- producing product: 'coastmark'
  tenant_slug VARCHAR(48) NOT NULL,              -- their tenant, not ours
  window_kind ENUM('minute','day') NOT NULL,
  bucket      INT UNSIGNED NOT NULL,             -- minutes (or days) since epoch
  hits        INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (source, tenant_slug, window_kind, bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
