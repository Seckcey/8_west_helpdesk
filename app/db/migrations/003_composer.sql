-- 003_composer.sql — Sprint 2: canned responses (saved replies with merge
-- fields, inserted via "/" in the composer). Fresh installs get this from
-- db/schema.sql; this migration is for existing installs.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS canned_responses (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  INT UNSIGNED NOT NULL,
  title      VARCHAR(80)  NOT NULL,
  body       TEXT NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_canned_tenant (tenant_id),
  CONSTRAINT fk_canned_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_canned_user   FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
