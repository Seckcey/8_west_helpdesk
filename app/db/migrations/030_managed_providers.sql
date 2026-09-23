SET NAMES utf8mb4;
SET time_zone = '+00:00';
-- Explicit provider ownership; root coordinator renews a bounded eligibility lease.
CREATE TABLE IF NOT EXISTS suite_managed_providers (
  tenant_id INT UNSIGNED NOT NULL,
  issuer_tenant_id INT UNSIGNED NOT NULL,
  provider_slug VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  owner_user_id INT UNSIGNED NOT NULL,
  owner_subject VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  lease_until DATETIME NOT NULL,
  PRIMARY KEY (tenant_id),
  UNIQUE KEY uq_suite_managed_provider_issuer (issuer_tenant_id),
  UNIQUE KEY uq_suite_managed_provider_slug (provider_slug),
  CONSTRAINT fk_suite_managed_provider_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_suite_managed_provider_owner FOREIGN KEY (owner_user_id) REFERENCES users(id),
  CONSTRAINT ck_suite_managed_provider_external CHECK (
    provider_slug REGEXP '^[a-z0-9][a-z0-9-]{1,62}$'
    AND provider_slug NOT IN ('8west','internal') AND issuer_tenant_id > 0
    AND owner_subject REGEXP '^t[1-9][0-9]*u[1-9][0-9]*$'
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
DELIMITER $$
CREATE TRIGGER IF NOT EXISTS suite_managed_provider_immutable
BEFORE UPDATE ON suite_managed_providers FOR EACH ROW
BEGIN
  IF NOT (NEW.tenant_id <=> OLD.tenant_id) OR NOT (NEW.issuer_tenant_id <=> OLD.issuer_tenant_id)
    OR NOT (NEW.provider_slug <=> OLD.provider_slug) OR NOT (NEW.owner_user_id <=> OLD.owner_user_id)
    OR NOT (NEW.owner_subject <=> OLD.owner_subject) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'provider ownership is immutable';
  END IF;
END$$
CREATE TRIGGER IF NOT EXISTS suite_managed_provider_no_delete
BEFORE DELETE ON suite_managed_providers FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'provider ownership cannot be deleted'$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER IF NOT EXISTS suite_managed_provider_insert_guard
BEFORE INSERT ON suite_managed_providers FOR EACH ROW
BEGIN
  IF NEW.lease_until>UTC_TIMESTAMP()+INTERVAL 300 SECOND OR NOT EXISTS (
    SELECT 1 FROM tenants t JOIN users u ON u.tenant_id=t.id
     WHERE t.id=NEW.tenant_id AND BINARY t.slug=BINARY NEW.provider_slug
       AND u.id=NEW.owner_user_id AND u.is_active=1 AND u.role IN ('owner','admin')
       AND BINARY u.suite_subject=BINARY NEW.owner_subject
  ) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='provider ownership or lease invalid'; END IF;
END$$
CREATE TRIGGER IF NOT EXISTS suite_managed_provider_lease_guard
BEFORE UPDATE ON suite_managed_providers FOR EACH ROW
BEGIN
  IF NEW.lease_until>UTC_TIMESTAMP()+INTERVAL 300 SECOND THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='provider lease exceeds authorization window';
  END IF;
END$$
DELIMITER ;
