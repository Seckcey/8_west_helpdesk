-- 025: Controller-owned Westy workflow, immutable receipts, human takeover.
-- Migration FIRST under Safeharbor-only write freeze; no config activation.
-- Replay preserves every row. Verify backup + scratch restore before apply.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Refuse DML-only application identities before additive DDL.
DROP TRIGGER IF EXISTS trg_westy_025_preflight;
CREATE TRIGGER trg_westy_025_preflight BEFORE INSERT ON tickets
FOR EACH ROW SET @westy_025_preflight = 1;
DROP TRIGGER trg_westy_025_preflight;

CREATE TABLE IF NOT EXISTS westy_workflows (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  customer_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ticket_id INT UNSIGNED NOT NULL,
  workflow_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  alert_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  state ENUM('working','needs_human','resolved','human_owned') NOT NULL,
  version INT UNSIGNED NOT NULL,
  summary TEXT NOT NULL,
  evidence_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  job_id BIGINT UNSIGNED NULL,
  job_completed_at DATETIME NULL,
  alert_resolved_at DATETIME NULL,
  closed_at DATETIME NULL,
  started_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_westy_workflow_scope (tenant_id,id),
  UNIQUE KEY uq_westy_workflow_key (tenant_id,workflow_key),
  UNIQUE KEY uq_westy_workflow_ticket (tenant_id,ticket_id),
  CONSTRAINT fk_westy_workflow_client FOREIGN KEY (tenant_id,client_id) REFERENCES clients (tenant_id,id),
  CONSTRAINT fk_westy_workflow_ticket FOREIGN KEY (tenant_id,ticket_id) REFERENCES tickets (tenant_id,id),
  CONSTRAINT ck_westy_workflow_version CHECK (version >= 1),
  CONSTRAINT ck_westy_workflow_resolution CHECK (
    state <> 'resolved' OR (evidence_sha256 IS NOT NULL AND job_id IS NOT NULL
      AND job_completed_at IS NOT NULL AND alert_resolved_at IS NOT NULL AND closed_at IS NOT NULL
      AND job_completed_at >= started_at AND alert_resolved_at >= job_completed_at)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS westy_workflow_receipts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  workflow_id BIGINT UNSIGNED NOT NULL,
  event_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  action ENUM('claim','progress','escalate','resolve') NOT NULL,
  version INT UNSIGNED NOT NULL,
  response_json JSON NOT NULL,
  received_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_westy_receipt_key (tenant_id,event_key),
  UNIQUE KEY uq_westy_receipt_version (tenant_id,workflow_id,version),
  CONSTRAINT fk_westy_receipt_workflow FOREIGN KEY (tenant_id,workflow_id) REFERENCES westy_workflows (tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS westy_billing_outbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  workflow_id BIGINT UNSIGNED NOT NULL,
  event_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  state ENUM('waiting_for_time','ready','sending','uncertain','accepted','blocked') NOT NULL DEFAULT 'waiting_for_time',
  payload_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  detail_code VARCHAR(64) NOT NULL DEFAULT 'approved_time_required',
  response_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  next_attempt_at DATETIME NULL,
  last_attempt_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_westy_billing_workflow (tenant_id,workflow_id),
  UNIQUE KEY uq_westy_billing_event (tenant_id,event_key),
  KEY ix_westy_billing_due (state,next_attempt_at,id),
  CONSTRAINT fk_westy_billing_workflow FOREIGN KEY (tenant_id,workflow_id) REFERENCES westy_workflows (tenant_id,id),
  CONSTRAINT ck_westy_billing_payload CHECK ((payload_json IS NULL AND payload_sha256 IS NULL)
    OR (JSON_VALID(payload_json) AND payload_sha256 REGEXP '^[0-9a-f]{64}$'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DELIMITER $$
DROP TRIGGER IF EXISTS trg_westy_receipt_immutable_update$$
CREATE TRIGGER trg_westy_receipt_immutable_update BEFORE UPDATE ON westy_workflow_receipts
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Westy receipts are immutable'$$
DROP TRIGGER IF EXISTS trg_westy_receipt_immutable_delete$$
CREATE TRIGGER trg_westy_receipt_immutable_delete BEFORE DELETE ON westy_workflow_receipts
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Westy receipts are immutable'$$
DROP TRIGGER IF EXISTS trg_westy_workflow_identity_update$$
CREATE TRIGGER trg_westy_workflow_identity_update BEFORE UPDATE ON westy_workflows
FOR EACH ROW BEGIN
  IF NOT (NEW.tenant_id <=> OLD.tenant_id) OR NOT (NEW.client_id <=> OLD.client_id)
    OR NOT (NEW.customer_id <=> OLD.customer_id) OR NOT (NEW.ticket_id <=> OLD.ticket_id)
    OR NOT (NEW.workflow_key <=> OLD.workflow_key) OR NOT (NEW.alert_key <=> OLD.alert_key)
    OR NOT (NEW.started_at <=> OLD.started_at) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Westy workflow identity is immutable';
  END IF;
END$$
DROP TRIGGER IF EXISTS trg_westy_workflow_immutable_delete$$
CREATE TRIGGER trg_westy_workflow_immutable_delete BEFORE DELETE ON westy_workflows
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Westy workflow history cannot be deleted'$$
DROP TRIGGER IF EXISTS trg_westy_billing_identity_update$$
CREATE TRIGGER trg_westy_billing_identity_update BEFORE UPDATE ON westy_billing_outbox
FOR EACH ROW BEGIN
  IF NOT (NEW.tenant_id <=> OLD.tenant_id) OR NOT (NEW.workflow_id <=> OLD.workflow_id)
    OR NOT (NEW.event_key <=> OLD.event_key) OR NOT (NEW.created_at <=> OLD.created_at)
    OR (OLD.payload_json IS NOT NULL AND (NOT (NEW.payload_json <=> OLD.payload_json)
      OR NOT (NEW.payload_sha256 <=> OLD.payload_sha256)))
    OR (OLD.state = 'accepted' AND (NEW.state <> 'accepted' OR NOT (NEW.response_json <=> OLD.response_json))) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Westy billing handoff facts are immutable';
  END IF;
END$$
DROP TRIGGER IF EXISTS trg_westy_billing_immutable_delete$$
CREATE TRIGGER trg_westy_billing_immutable_delete BEFORE DELETE ON westy_billing_outbox
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Westy billing handoff history cannot be deleted'$$

-- Every ticket write transfers ownership, including change-and-revert. The
-- controller holds the ticket lock and writes its own final run state in the
-- same transaction AFTER its own ticket update. Concurrent staff always win
-- before the controller's next operation. No user variable can bypass guards.
DROP TRIGGER IF EXISTS trg_westy_ticket_takeover$$
CREATE TRIGGER trg_westy_ticket_takeover AFTER UPDATE ON tickets
FOR EACH ROW UPDATE westy_workflows SET state = 'human_owned',version = version + 1,
  updated_at = UTC_TIMESTAMP() WHERE tenant_id = OLD.tenant_id AND ticket_id = OLD.id AND state <> 'human_owned'$$
DROP TRIGGER IF EXISTS trg_westy_message_insert_takeover$$
CREATE TRIGGER trg_westy_message_insert_takeover AFTER INSERT ON messages
FOR EACH ROW BEGIN
  IF NEW.kind <> 'system' THEN
    UPDATE westy_workflows SET state = 'human_owned',version = version + 1,
      updated_at = UTC_TIMESTAMP() WHERE ticket_id = NEW.ticket_id AND state <> 'human_owned';
  END IF;
END$$
DROP TRIGGER IF EXISTS trg_westy_message_update_takeover$$
CREATE TRIGGER trg_westy_message_update_takeover AFTER UPDATE ON messages
FOR EACH ROW BEGIN
  IF OLD.kind <> 'system' OR NEW.kind <> 'system' THEN
    UPDATE westy_workflows SET state = 'human_owned',version = version + 1,
      updated_at = UTC_TIMESTAMP() WHERE ticket_id IN (OLD.ticket_id,NEW.ticket_id) AND state <> 'human_owned';
  END IF;
END$$
DROP TRIGGER IF EXISTS trg_westy_message_delete_takeover$$
CREATE TRIGGER trg_westy_message_delete_takeover AFTER DELETE ON messages
FOR EACH ROW BEGIN
  IF OLD.kind <> 'system' THEN
    UPDATE westy_workflows SET state = 'human_owned',version = version + 1,
      updated_at = UTC_TIMESTAMP() WHERE ticket_id = OLD.ticket_id AND state <> 'human_owned';
  END IF;
END$$
DROP TRIGGER IF EXISTS trg_westy_time_insert_takeover$$
CREATE TRIGGER trg_westy_time_insert_takeover AFTER INSERT ON time_entries
FOR EACH ROW UPDATE westy_workflows SET state = 'human_owned',version = version + 1,
  updated_at = UTC_TIMESTAMP() WHERE tenant_id = NEW.tenant_id AND ticket_id = NEW.ticket_id AND state <> 'human_owned'$$
DROP TRIGGER IF EXISTS trg_westy_time_update_takeover$$
CREATE TRIGGER trg_westy_time_update_takeover AFTER UPDATE ON time_entries
FOR EACH ROW UPDATE westy_workflows SET state = 'human_owned',version = version + 1,
  updated_at = UTC_TIMESTAMP() WHERE (tenant_id = OLD.tenant_id AND ticket_id = OLD.ticket_id
    OR tenant_id = NEW.tenant_id AND ticket_id = NEW.ticket_id) AND state <> 'human_owned'$$
DROP TRIGGER IF EXISTS trg_westy_time_delete_takeover$$
CREATE TRIGGER trg_westy_time_delete_takeover AFTER DELETE ON time_entries
FOR EACH ROW UPDATE westy_workflows SET state = 'human_owned',version = version + 1,
  updated_at = UTC_TIMESTAMP() WHERE tenant_id = OLD.tenant_id AND ticket_id = OLD.ticket_id AND state <> 'human_owned'$$
DELIMITER ;

-- Runtime receives EXECUTE only on this read-only definer function.
-- Direct trigger metadata inspection would require dangerous TRIGGER rights.
DELIMITER $$
DROP FUNCTION IF EXISTS westy_workflow_schema_health$$
CREATE FUNCTION westy_workflow_schema_health() RETURNS TINYINT
READS SQL DATA SQL SECURITY DEFINER
BEGIN
RETURN CASE WHEN
  (SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE() AND (
     (trigger_name IN ('trg_westy_ticket_takeover','trg_westy_message_insert_takeover',
       'trg_westy_message_update_takeover','trg_westy_message_delete_takeover',
       'trg_westy_time_insert_takeover','trg_westy_time_update_takeover','trg_westy_time_delete_takeover')
      AND action_statement LIKE '%human_owned%')
     OR (trigger_name IN ('trg_westy_receipt_immutable_update','trg_westy_receipt_immutable_delete')
      AND action_statement LIKE '%Westy receipts are immutable%')
     OR (trigger_name = 'trg_westy_workflow_identity_update'
      AND action_statement LIKE '%Westy workflow identity is immutable%')
     OR (trigger_name = 'trg_westy_workflow_immutable_delete'
      AND action_statement LIKE '%Westy workflow history cannot be deleted%')
     OR (trigger_name = 'trg_westy_billing_identity_update'
      AND action_statement LIKE '%Westy billing handoff facts are immutable%')
     OR (trigger_name = 'trg_westy_billing_immutable_delete'
      AND action_statement LIKE '%Westy billing handoff history cannot be deleted%')
   )) = 13
  AND (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE() AND table_name = 'westy_workflows'
      AND constraint_name IN ('ck_westy_workflow_version','ck_westy_workflow_resolution')
      AND enforced = 'YES') = 2
THEN 1 ELSE 0 END;
END$$
DELIMITER ;

SELECT COUNT(*) AS westy_workflow_tables FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name IN ('westy_workflows','westy_workflow_receipts','westy_billing_outbox');
SELECT COUNT(*) AS westy_workflow_guards FROM information_schema.triggers
WHERE trigger_schema = DATABASE() AND trigger_name LIKE 'trg_westy_%' AND trigger_name NOT LIKE '%preflight%';
