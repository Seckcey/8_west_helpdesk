-- Dedicated Central history. No tickets, notifications, time or billing writes.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS central_issue_accounts (
  account_ref CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  customer_binding_id BIGINT UNSIGNED NOT NULL,
  customer_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  provider_tenant_id BIGINT UNSIGNED NOT NULL,
  owner_sub VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  id_tenant_id VARCHAR(19) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  binding_version INT UNSIGNED NOT NULL DEFAULT 1,
  enabled TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_central_issue_account_scope (tenant_id, account_ref),
  UNIQUE KEY uq_central_issue_owner (tenant_id, owner_sub, id_tenant_id),
  UNIQUE KEY uq_central_issue_customer (tenant_id, customer_id),
  CONSTRAINT fk_central_issue_registry FOREIGN KEY (tenant_id, customer_binding_id)
    REFERENCES suite_customer_sync_bindings (tenant_id, id),
  CONSTRAINT ck_central_issue_account_enabled CHECK (enabled IN (0,1)),
  CONSTRAINT ck_central_issue_binding_version CHECK (binding_version >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS central_issues (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  account_ref CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  issue_ref CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  title VARCHAR(140) NOT NULL,
  state ENUM('open','unresolved','resolved','cancelled') NOT NULL DEFAULT 'open',
  resolution_source ENUM('none','customer_reported') NOT NULL DEFAULT 'none',
  version INT UNSIGNED NOT NULL DEFAULT 1,
  erased TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_central_issue_ref (issue_ref),
  UNIQUE KEY uq_central_issue_scope (tenant_id, account_ref, issue_ref),
  KEY ix_central_issue_list (tenant_id, account_ref, erased, id),
  CONSTRAINT fk_central_issue_account FOREIGN KEY (tenant_id, account_ref)
    REFERENCES central_issue_accounts (tenant_id, account_ref),
  CONSTRAINT ck_central_issue_version CHECK (version >= 1),
  CONSTRAINT ck_central_issue_erased CHECK (erased IN (0,1)),
  CONSTRAINT ck_central_issue_title CHECK ((erased=1 AND title='') OR (erased=0 AND CHAR_LENGTH(title) BETWEEN 1 AND 140)),
  CONSTRAINT ck_central_issue_resolution CHECK ((state='resolved' AND resolution_source='customer_reported') OR (state<>'resolved' AND resolution_source='none'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS central_issue_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  account_ref CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  issue_ref CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  operation_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  version INT UNSIGNED NOT NULL,
  actor ENUM('customer','assistant') NOT NULL,
  kind ENUM('opened','message','state','erased') NOT NULL,
  body TEXT NULL,
  state_after ENUM('open','unresolved','resolved','cancelled') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_central_operation (tenant_id, account_ref, operation_key),
  UNIQUE KEY uq_central_event_version (tenant_id, account_ref, issue_ref, version),
  KEY ix_central_event_time (tenant_id, account_ref, created_at),
  CONSTRAINT fk_central_event_issue FOREIGN KEY (tenant_id, account_ref, issue_ref)
    REFERENCES central_issues (tenant_id, account_ref, issue_ref),
  CONSTRAINT ck_central_event_version CHECK (version >= 1),
  CONSTRAINT ck_central_event_body CHECK (body IS NULL OR (CHAR_LENGTH(body) BETWEEN 1 AND 4000 AND OCTET_LENGTH(body)<=8000)),
  CONSTRAINT ck_central_event_actor CHECK (actor='customer' OR kind='message')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- DML-only callers must stop before any existing guard is removed.
DROP TRIGGER IF EXISTS trg_ci_privilege_preflight;
CREATE TRIGGER trg_ci_privilege_preflight BEFORE INSERT ON central_issue_events
FOR EACH ROW SET @central_issue_privilege_preflight=1;
DROP TRIGGER trg_ci_privilege_preflight;

DROP FUNCTION IF EXISTS central_issue_schema_health;
DROP TRIGGER IF EXISTS trg_ci_account_insert;
DROP TRIGGER IF EXISTS trg_ci_account_update;
DROP TRIGGER IF EXISTS trg_ci_account_delete;
DROP TRIGGER IF EXISTS trg_ci_issue_insert;
DROP TRIGGER IF EXISTS trg_ci_issue_update;
DROP TRIGGER IF EXISTS trg_ci_issue_delete;
DROP TRIGGER IF EXISTS trg_ci_event_insert;
DROP TRIGGER IF EXISTS trg_ci_event_update;
DROP TRIGGER IF EXISTS trg_ci_event_delete;
DELIMITER $$
CREATE TRIGGER trg_ci_account_insert BEFORE INSERT ON central_issue_accounts FOR EACH ROW
BEGIN
  IF NEW.binding_version<>1 OR NEW.enabled<>0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Central accounts must begin disabled at version 1';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM suite_customer_sync_bindings b WHERE b.tenant_id=NEW.tenant_id
      AND b.id=NEW.customer_binding_id AND BINARY b.customer_id=BINARY NEW.customer_id AND b.status='active') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Central customer registry binding is not active';
  END IF;
END$$
CREATE TRIGGER trg_ci_account_update BEFORE UPDATE ON central_issue_accounts FOR EACH ROW
BEGIN
  IF NOT (NEW.account_ref<=>OLD.account_ref AND NEW.tenant_id<=>OLD.tenant_id
      AND NEW.customer_binding_id<=>OLD.customer_binding_id AND NEW.customer_id<=>OLD.customer_id
      AND NEW.provider_tenant_id<=>OLD.provider_tenant_id AND NEW.owner_sub<=>OLD.owner_sub
      AND NEW.id_tenant_id<=>OLD.id_tenant_id AND NEW.created_at<=>OLD.created_at)
      OR NEW.binding_version<OLD.binding_version OR NEW.binding_version>OLD.binding_version+1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Central ownership is immutable and versions cannot regress';
  END IF;
END$$
CREATE TRIGGER trg_ci_account_delete BEFORE DELETE ON central_issue_accounts FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Retain Central account audit bindings'$$
CREATE TRIGGER trg_ci_issue_insert BEFORE INSERT ON central_issues FOR EACH ROW
BEGIN
  IF NEW.version<>1 OR NEW.state<>'open' OR NEW.resolution_source<>'none' OR NEW.erased<>0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Central issues must begin open at version 1';
  END IF;
  SET NEW.created_at=UTC_TIMESTAMP(); SET NEW.updated_at=NEW.created_at;
END$$
CREATE TRIGGER trg_ci_issue_update BEFORE UPDATE ON central_issues FOR EACH ROW
BEGIN
  IF NOT (NEW.id<=>OLD.id AND NEW.tenant_id<=>OLD.tenant_id AND NEW.account_ref<=>OLD.account_ref
      AND NEW.issue_ref<=>OLD.issue_ref AND NEW.created_at<=>OLD.created_at)
      OR OLD.erased=1 OR NEW.version<>OLD.version+1
      OR (NEW.erased=0 AND NOT (NEW.title<=>OLD.title)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Central issue identity and progression are protected';
  END IF;
  SET NEW.updated_at=UTC_TIMESTAMP();
END$$
CREATE TRIGGER trg_ci_issue_delete BEFORE DELETE ON central_issues FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Erase content while retaining the Central tombstone'$$
CREATE TRIGGER trg_ci_event_insert BEFORE INSERT ON central_issue_events FOR EACH ROW
BEGIN
  IF NOT EXISTS (SELECT 1 FROM central_issues i WHERE i.tenant_id=NEW.tenant_id
      AND i.account_ref=NEW.account_ref AND i.issue_ref=NEW.issue_ref
      AND i.version=NEW.version AND i.state=NEW.state_after
      AND ((i.erased=0 AND NEW.kind<>'erased' AND NEW.body IS NOT NULL)
        OR (i.erased=1 AND NEW.kind='erased' AND NEW.body IS NULL AND NEW.actor='customer'))) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Central event must match the current issue version';
  END IF;
  SET NEW.created_at=UTC_TIMESTAMP();
END$$
CREATE TRIGGER trg_ci_event_update BEFORE UPDATE ON central_issue_events FOR EACH ROW
BEGIN
  IF NOT (NEW.id<=>OLD.id AND NEW.tenant_id<=>OLD.tenant_id AND NEW.account_ref<=>OLD.account_ref
      AND NEW.issue_ref<=>OLD.issue_ref AND NEW.operation_key<=>OLD.operation_key
      AND NEW.request_digest<=>OLD.request_digest AND NEW.version<=>OLD.version
      AND NEW.actor<=>OLD.actor AND NEW.kind<=>OLD.kind AND NEW.state_after<=>OLD.state_after
      AND NEW.created_at<=>OLD.created_at) OR NEW.body IS NOT NULL
      OR NOT EXISTS (SELECT 1 FROM central_issues i WHERE i.tenant_id=OLD.tenant_id
        AND i.account_ref=OLD.account_ref AND i.issue_ref=OLD.issue_ref AND i.erased=1) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Central events are immutable except for owner erasure';
  END IF;
END$$
CREATE TRIGGER trg_ci_event_delete BEFORE DELETE ON central_issue_events FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Retain content-free Central event receipts'$$
CREATE FUNCTION central_issue_schema_health() RETURNS TINYINT READS SQL DATA SQL SECURITY DEFINER
BEGIN
  RETURN (SELECT COUNT(*)=9 FROM information_schema.triggers WHERE trigger_schema=DATABASE()
    AND trigger_name IN ('trg_ci_account_insert','trg_ci_account_update','trg_ci_account_delete',
      'trg_ci_issue_insert','trg_ci_issue_update','trg_ci_issue_delete',
      'trg_ci_event_insert','trg_ci_event_update','trg_ci_event_delete'))
    AND (SELECT COUNT(*)=9 FROM information_schema.table_constraints WHERE constraint_schema=DATABASE()
      AND constraint_type='CHECK' AND enforced='YES' AND constraint_name IN (
        'ck_central_issue_account_enabled','ck_central_issue_binding_version','ck_central_issue_version',
        'ck_central_issue_erased','ck_central_issue_title','ck_central_issue_resolution',
        'ck_central_event_version','ck_central_event_body','ck_central_event_actor'))
    AND (SELECT COUNT(*)=3 FROM information_schema.referential_constraints WHERE constraint_schema=DATABASE()
      AND constraint_name IN ('fk_central_issue_registry','fk_central_issue_account','fk_central_event_issue'));
END$$
DELIMITER ;
