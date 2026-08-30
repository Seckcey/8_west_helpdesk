-- Migration 021: durable Coastmark v3 export claims and append-only receipts.
-- Shared lock order: tenant -> time entry -> actor -> claim/adjustment rows.

CREATE TABLE IF NOT EXISTS coastmark_time_export_claims (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id            INT UNSIGNED NOT NULL,
  time_entry_id        INT UNSIGNED NOT NULL,
  source_version       INT UNSIGNED NOT NULL,
  event_key            VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  predecessor_claim_id BIGINT UNSIGNED NULL,
  payload_sha256       CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  -- Preserve the exact canonical bytes whose SHA-256 is claimed. MySQL's
  -- binary JSON representation may reorder object keys when read back.
  payload_json         LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  created_by_user_id   INT UNSIGNED NOT NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cm_export_claim_tenant_id (tenant_id, id),
  UNIQUE KEY uq_cm_export_claim_event (tenant_id, event_key),
  UNIQUE KEY uq_cm_export_claim_version (tenant_id, time_entry_id, source_version),
  UNIQUE KEY uq_cm_export_claim_predecessor (tenant_id, predecessor_claim_id),
  KEY ix_cm_export_claim_actor (tenant_id, created_by_user_id, created_at, id),
  CONSTRAINT fk_cm_export_claim_tenant FOREIGN KEY (tenant_id)
    REFERENCES tenants (id),
  CONSTRAINT fk_cm_export_claim_entry FOREIGN KEY (tenant_id, time_entry_id)
    REFERENCES time_entries (tenant_id, id),
  CONSTRAINT fk_cm_export_claim_actor FOREIGN KEY (tenant_id, created_by_user_id)
    REFERENCES users (tenant_id, id),
  CONSTRAINT fk_cm_export_claim_predecessor FOREIGN KEY (tenant_id, predecessor_claim_id)
    REFERENCES coastmark_time_export_claims (tenant_id, id),
  CONSTRAINT ck_cm_export_claim_event_key
    CHECK (event_key REGEXP '^safeharbor-time:[0-9a-f]{32}$'),
  CONSTRAINT ck_cm_export_claim_hash
    CHECK (payload_sha256 REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT ck_cm_export_claim_payload CHECK (JSON_VALID(payload_json)),
  CONSTRAINT ck_cm_export_claim_predecessor_shape
    CHECK ((source_version = 0 AND predecessor_claim_id IS NULL)
        OR (source_version > 0 AND predecessor_claim_id IS NOT NULL)),
  -- Removed only after all three permanent guards are installed.
  CONSTRAINT ck_cm_export_claim_install_lock CHECK (0 = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS coastmark_time_export_receipts (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id          INT UNSIGNED NOT NULL,
  claim_id           BIGINT UNSIGNED NOT NULL,
  operation_key      VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  operation_kind     ENUM('dispatch_started','dispatch_result','status_started','status_result') NOT NULL,
  outcome            ENUM('dispatching','checking','accepted','replayed','absent','ambiguous','conflict','manual_exception') NOT NULL,
  response_status    SMALLINT UNSIGNED NULL,
  response_sha256    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  coastmark_event_id BIGINT UNSIGNED NULL,
  invoice_id         BIGINT UNSIGNED NULL,
  invoice_line_id    BIGINT UNSIGNED NULL,
  detail_code        VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cm_export_receipt_operation (tenant_id, operation_key),
  KEY ix_cm_export_receipt_claim (tenant_id, claim_id, id),
  KEY ix_cm_export_receipt_outcome (tenant_id, outcome, created_at, id),
  CONSTRAINT fk_cm_export_receipt_tenant FOREIGN KEY (tenant_id)
    REFERENCES tenants (id),
  CONSTRAINT fk_cm_export_receipt_claim FOREIGN KEY (tenant_id, claim_id)
    REFERENCES coastmark_time_export_claims (tenant_id, id),
  CONSTRAINT ck_cm_export_receipt_operation_key
    CHECK (operation_key REGEXP '^safeharbor-op:[0-9a-f]{32}$'),
  CONSTRAINT ck_cm_export_receipt_response_status
    CHECK (response_status IS NULL OR response_status BETWEEN 100 AND 599),
  CONSTRAINT ck_cm_export_receipt_response_hash
    CHECK (response_sha256 IS NULL OR response_sha256 REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT ck_cm_export_receipt_detail
    CHECK (detail_code REGEXP '^[a-z][a-z0-9_]{2,63}$'),
  CONSTRAINT ck_cm_export_receipt_ack_shape CHECK (
       (outcome = 'accepted'
        AND coastmark_event_id IS NOT NULL AND invoice_id IS NOT NULL AND invoice_line_id IS NOT NULL)
    OR (outcome = 'replayed'
        AND coastmark_event_id IS NOT NULL AND invoice_id IS NOT NULL)
    OR (outcome = 'manual_exception'
        AND coastmark_event_id IS NOT NULL AND invoice_id IS NOT NULL AND invoice_line_id IS NULL)
    OR (outcome NOT IN ('accepted','replayed','manual_exception')
        AND coastmark_event_id IS NULL AND invoice_id IS NULL AND invoice_line_id IS NULL)
  ),
  CONSTRAINT ck_cm_export_receipt_install_lock CHECK (0 = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fail closed while permanent triggers are installed or replaced on replay.
DROP TRIGGER IF EXISTS trg_cm_claim_021_insert_swap;
DROP TRIGGER IF EXISTS trg_cm_claim_021_update_swap;
DROP TRIGGER IF EXISTS trg_cm_claim_021_delete_swap;
DROP TRIGGER IF EXISTS trg_cm_receipt_021_insert_swap;
DROP TRIGGER IF EXISTS trg_cm_receipt_021_update_swap;
DROP TRIGGER IF EXISTS trg_cm_receipt_021_delete_swap;

DELIMITER $$
CREATE TRIGGER trg_cm_claim_021_insert_swap
BEFORE INSERT ON coastmark_time_export_claims FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'migration 021 claim trigger swap';
END$$
CREATE TRIGGER trg_cm_claim_021_update_swap
BEFORE UPDATE ON coastmark_time_export_claims FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'migration 021 claim trigger swap';
END$$
CREATE TRIGGER trg_cm_claim_021_delete_swap
BEFORE DELETE ON coastmark_time_export_claims FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'migration 021 claim trigger swap';
END$$
CREATE TRIGGER trg_cm_receipt_021_insert_swap
BEFORE INSERT ON coastmark_time_export_receipts FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'migration 021 receipt trigger swap';
END$$
CREATE TRIGGER trg_cm_receipt_021_update_swap
BEFORE UPDATE ON coastmark_time_export_receipts FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'migration 021 receipt trigger swap';
END$$
CREATE TRIGGER trg_cm_receipt_021_delete_swap
BEFORE DELETE ON coastmark_time_export_receipts FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'migration 021 receipt trigger swap';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_cm_export_claim_before_insert;
DROP TRIGGER IF EXISTS trg_cm_export_claim_no_update;
DROP TRIGGER IF EXISTS trg_cm_export_claim_no_delete;
DROP TRIGGER IF EXISTS trg_cm_export_receipt_before_insert;
DROP TRIGGER IF EXISTS trg_cm_export_receipt_no_update;
DROP TRIGGER IF EXISTS trg_cm_export_receipt_no_delete;

DELIMITER $$
CREATE TRIGGER trg_cm_export_claim_before_insert
BEFORE INSERT ON coastmark_time_export_claims
FOR EACH ROW
BEGIN
  DECLARE tenant_found INT DEFAULT 0;
  DECLARE parent_found INT DEFAULT 0;
  DECLARE parent_status VARCHAR(16) DEFAULT NULL;
  DECLARE parent_billable TINYINT DEFAULT NULL;
  DECLARE parent_reviewer INT UNSIGNED DEFAULT NULL;
  DECLARE parent_reviewed DATETIME DEFAULT NULL;
  DECLARE parent_client_id INT UNSIGNED DEFAULT NULL;
  DECLARE binding_found INT DEFAULT 0;
  DECLARE binding_customer_id CHAR(36) DEFAULT NULL;
  DECLARE binding_status VARCHAR(16) DEFAULT NULL;
  DECLARE payload_client_key VARCHAR(128) DEFAULT NULL;
  DECLARE actor_found INT DEFAULT 0;
  DECLARE actor_role VARCHAR(32) DEFAULT NULL;
  DECLARE actor_active TINYINT DEFAULT NULL;
  DECLARE latest_version INT DEFAULT -1;
  DECLARE latest_claim_id BIGINT UNSIGNED DEFAULT NULL;

  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET tenant_found = 0;
    SELECT 1 INTO tenant_found FROM tenants WHERE id=NEW.tenant_id FOR UPDATE;
  END;
  IF tenant_found <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export claim tenant does not exist';
  END IF;

  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET parent_found = 0;
    SELECT 1,approval_status,billable,reviewed_by_user_id,reviewed_at,client_id
      INTO parent_found,parent_status,parent_billable,parent_reviewer,parent_reviewed,
           parent_client_id
      FROM time_entries
     WHERE tenant_id=NEW.tenant_id AND id=NEW.time_entry_id
     FOR UPDATE;
  END;
  IF parent_found <> 1 OR BINARY parent_status <> BINARY 'approved' OR parent_billable <> 1
     OR parent_reviewer IS NULL OR parent_reviewed IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export claims require approved billable reviewed time';
  END IF;

  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND
      BEGIN
        SET binding_found=0;
        SET binding_customer_id=NULL;
        SET binding_status=NULL;
      END;
    SELECT 1,customer_id,status
      INTO binding_found,binding_customer_id,binding_status
      FROM suite_customer_sync_bindings
     WHERE tenant_id=NEW.tenant_id AND client_id=parent_client_id
     FOR UPDATE;
  END;
  SET payload_client_key=JSON_UNQUOTE(JSON_EXTRACT(NEW.payload_json,'$.client_key'));
  IF binding_found <> 1 OR BINARY binding_status <> BINARY 'active'
     OR NOT (BINARY payload_client_key <=>
             BINARY CONCAT('milepost-customer:',binding_customer_id)) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Export claims require an active matching customer binding';
  END IF;

  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET actor_found = 0;
    SELECT 1,role,is_active INTO actor_found,actor_role,actor_active
      FROM users
     WHERE tenant_id=NEW.tenant_id AND id=NEW.created_by_user_id
     FOR UPDATE;
  END;
  IF actor_found <> 1 OR actor_active <> 1
     OR (BINARY actor_role <> BINARY 'owner'
         AND BINARY actor_role <> BINARY 'admin') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export claim actor is not authorized';
  END IF;

  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND
      BEGIN SET latest_version=-1; SET latest_claim_id=NULL; END;
    SELECT source_version,id INTO latest_version,latest_claim_id
      FROM coastmark_time_export_claims
     WHERE tenant_id=NEW.tenant_id AND time_entry_id=NEW.time_entry_id
     ORDER BY source_version DESC LIMIT 1;
  END;
  IF NEW.source_version <> latest_version + 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export claim does not follow current source version';
  END IF;
  IF NOT (NEW.predecessor_claim_id <=> latest_claim_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export claim predecessor is not current';
  END IF;
  SET NEW.created_at=UTC_TIMESTAMP();
END$$

CREATE TRIGGER trg_cm_export_claim_no_update
BEFORE UPDATE ON coastmark_time_export_claims FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Coastmark export claims are immutable';
END$$
CREATE TRIGGER trg_cm_export_claim_no_delete
BEFORE DELETE ON coastmark_time_export_claims FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Coastmark export claims cannot be deleted';
END$$

CREATE TRIGGER trg_cm_export_receipt_before_insert
BEFORE INSERT ON coastmark_time_export_receipts
FOR EACH ROW
BEGIN
  DECLARE tenant_found INT DEFAULT 0;
  DECLARE claim_found INT DEFAULT 0;
  DECLARE latest_found INT DEFAULT 0;
  DECLARE latest_kind VARCHAR(32) DEFAULT NULL;
  DECLARE latest_outcome VARCHAR(32) DEFAULT NULL;
  DECLARE latest_created DATETIME DEFAULT NULL;
  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET tenant_found=0;
    SELECT 1 INTO tenant_found FROM tenants WHERE id=NEW.tenant_id FOR UPDATE;
  END;
  IF tenant_found <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export receipt tenant does not exist';
  END IF;
  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET claim_found=0;
    SELECT 1 INTO claim_found
      FROM coastmark_time_export_claims
     WHERE tenant_id=NEW.tenant_id AND id=NEW.claim_id
     FOR UPDATE;
  END;
  IF claim_found <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export receipt claim does not exist';
  END IF;
  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND
      BEGIN
        SET latest_found=0;
        SET latest_kind=NULL;
        SET latest_outcome=NULL;
        SET latest_created=NULL;
      END;
    SELECT 1,operation_kind,outcome,created_at
      INTO latest_found,latest_kind,latest_outcome,latest_created
      FROM coastmark_time_export_receipts
     WHERE tenant_id=NEW.tenant_id AND claim_id=NEW.claim_id
     ORDER BY id DESC LIMIT 1;
  END;
  IF NEW.operation_kind = 'dispatch_started' THEN
    IF NEW.outcome <> 'dispatching'
       OR (latest_found=1 AND latest_outcome <> 'absent') THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export dispatch transition is not permitted';
    END IF;
  ELSEIF NEW.operation_kind = 'status_started' THEN
    IF NEW.outcome <> 'checking'
       OR NOT (IS_USED_LOCK(CONCAT('safeharbor:cm-status:',NEW.claim_id))
               <=> CONNECTION_ID())
       OR (latest_found=1 AND latest_outcome IN
           ('accepted','replayed','manual_exception','conflict'))
       OR (latest_found=1
           AND latest_outcome IN ('dispatching','checking')
           AND latest_created > DATE_SUB(UTC_TIMESTAMP(),INTERVAL 35 SECOND)) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export status transition is not permitted';
    END IF;
  ELSEIF NEW.operation_kind = 'dispatch_result' THEN
    IF latest_found<>1 OR latest_kind <> 'dispatch_started' OR latest_outcome <> 'dispatching'
       OR NEW.outcome NOT IN ('accepted','replayed','ambiguous','conflict','manual_exception') THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export dispatch result transition is not permitted';
    END IF;
  ELSEIF NEW.operation_kind = 'status_result' THEN
    IF latest_found<>1 OR latest_kind <> 'status_started' OR latest_outcome <> 'checking'
       OR NEW.outcome NOT IN ('accepted','replayed','absent','ambiguous','conflict','manual_exception') THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export status result transition is not permitted';
    END IF;
  ELSE
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export receipt operation is not permitted';
  END IF;
  SET NEW.created_at=UTC_TIMESTAMP();
END$$
CREATE TRIGGER trg_cm_export_receipt_no_update
BEFORE UPDATE ON coastmark_time_export_receipts FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Coastmark export receipts are immutable';
END$$
CREATE TRIGGER trg_cm_export_receipt_no_delete
BEFORE DELETE ON coastmark_time_export_receipts FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Coastmark export receipts cannot be deleted';
END$$
DELIMITER ;

DROP TRIGGER trg_cm_claim_021_insert_swap;
DROP TRIGGER trg_cm_claim_021_update_swap;
DROP TRIGGER trg_cm_claim_021_delete_swap;
DROP TRIGGER trg_cm_receipt_021_insert_swap;
DROP TRIGGER trg_cm_receipt_021_update_swap;
DROP TRIGGER trg_cm_receipt_021_delete_swap;

SET @cm_claim_lock_ddl=IF(
  (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema=DATABASE() AND table_name='coastmark_time_export_claims'
      AND constraint_name='ck_cm_export_claim_install_lock')=1,
  'ALTER TABLE coastmark_time_export_claims DROP CHECK ck_cm_export_claim_install_lock','DO 0');
PREPARE cm_export_statement FROM @cm_claim_lock_ddl;
EXECUTE cm_export_statement;
DEALLOCATE PREPARE cm_export_statement;

SET @cm_receipt_lock_ddl=IF(
  (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema=DATABASE() AND table_name='coastmark_time_export_receipts'
      AND constraint_name='ck_cm_export_receipt_install_lock')=1,
  'ALTER TABLE coastmark_time_export_receipts DROP CHECK ck_cm_export_receipt_install_lock','DO 0');
PREPARE cm_export_statement FROM @cm_receipt_lock_ddl;
EXECUTE cm_export_statement;
DEALLOCATE PREPARE cm_export_statement;

SELECT
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='coastmark_time_export_claims') AS claim_columns,
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='coastmark_time_export_receipts') AS receipt_columns,
  (SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'trg_cm_export_claim_%') AS claim_triggers,
  (SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'trg_cm_export_receipt_%') AS receipt_triggers;
