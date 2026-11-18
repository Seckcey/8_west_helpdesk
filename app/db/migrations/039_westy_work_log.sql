-- 039: Additive ticket work log and v4 QA receipts, after 038.
-- No pre-039 trigger or function is dropped. v1-v3 behave the same before,
-- during and after 039, including with the runtime's existing EXECUTE grant.
-- Apply through deploy.sh --migrate in a quiet period. No write freeze is required.
-- Verify backup and scratch restore first. Retain schema/history on code rollback.
-- Rerun to finish a partial apply. After each apply grant the runtime EXECUTE
-- on westy_work_log_schema_health only. A missing function or grant disables v4.
SET NAMES utf8mb4;
SET time_zone = '+00:00';
DROP TRIGGER IF EXISTS trg_westy_039_preflight;
CREATE TRIGGER trg_westy_039_preflight BEFORE INSERT ON tickets
FOR EACH ROW SET @westy_039_preflight = 1;
DROP TRIGGER trg_westy_039_preflight;
-- Disable even a previously installed v4 capability before a migration rerun.
DROP FUNCTION IF EXISTS westy_work_log_schema_health;

CREATE TABLE IF NOT EXISTS ticket_work_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  ticket_id INT UNSIGNED NOT NULL,
  workflow_id BIGINT UNSIGNED NULL,
  source ENUM('milepost','staff') NOT NULL,
  source_event_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  seq INT UNSIGNED NULL,
  kind ENUM('detect','triage','hypothesis','probe','finding','decision','action','verify','rollback','escalate','handoff','note','qa') NOT NULL,
  actor_type ENUM('user','ai_staff','system') NOT NULL,
  actor_key VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  summary VARCHAR(1000) NOT NULL,
  cause_code VARCHAR(64) NOT NULL DEFAULT 'unknown',
  evidence_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  milepost_ref VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
  customer_visible TINYINT(1) NOT NULL DEFAULT 0,
  entry_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  occurred_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_westy_work_log_event (tenant_id,source_event_key),
  UNIQUE KEY uq_westy_work_log_seq (tenant_id,workflow_id,seq),
  KEY ix_westy_work_log_ticket (tenant_id,ticket_id,occurred_at,id),
  CONSTRAINT fk_westy_work_log_ticket FOREIGN KEY (tenant_id,ticket_id) REFERENCES tickets (tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS westy_workflow_v4_receipts (
  tenant_id INT UNSIGNED NOT NULL,
  workflow_id BIGINT UNSIGNED NOT NULL,
  event_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  action ENUM('log','qa') NOT NULL,
  response_json JSON NOT NULL,
  received_at DATETIME NOT NULL,
  UNIQUE KEY uq_westy_v4_receipt_key (tenant_id,event_key),
  CONSTRAINT fk_westy_v4_receipt_workflow FOREIGN KEY (tenant_id,workflow_id) REFERENCES westy_workflows (tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @westy_039_ddl = IF(EXISTS (SELECT 1 FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='westy_workflows' AND column_name='log_sha256'),
  'DO 0', 'ALTER TABLE westy_workflows ADD COLUMN log_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL');
PREPARE westy_039_stmt FROM @westy_039_ddl;
EXECUTE westy_039_stmt;
DEALLOCATE PREPARE westy_039_stmt;
SET @westy_039_ddl = IF(EXISTS (SELECT 1 FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='westy_workflows' AND column_name='log_sealed_seq'),
  'DO 0', 'ALTER TABLE westy_workflows ADD COLUMN log_sealed_seq INT UNSIGNED NULL');
PREPARE westy_039_stmt FROM @westy_039_ddl;
EXECUTE westy_039_stmt;
DEALLOCATE PREPARE westy_039_stmt;
SET @westy_039_ddl = IF(EXISTS (SELECT 1 FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='westy_workflows' AND column_name='qa_state'),
  'DO 0', 'ALTER TABLE westy_workflows ADD COLUMN qa_state ENUM(''pending'',''passed'',''fault_zero'',''void'') NULL');
PREPARE westy_039_stmt FROM @westy_039_ddl;
EXECUTE westy_039_stmt;
DEALLOCATE PREPARE westy_039_stmt;
SET @westy_039_ddl = IF(EXISTS (SELECT 1 FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='westy_workflows' AND column_name='qa_reason'),
  'DO 0', 'ALTER TABLE westy_workflows ADD COLUMN qa_reason VARCHAR(64) NULL');
PREPARE westy_039_stmt FROM @westy_039_ddl;
EXECUTE westy_039_stmt;
DEALLOCATE PREPARE westy_039_stmt;

ALTER TABLE westy_billing_outbox
  MODIFY state ENUM('waiting_for_time','ready','sending','uncertain','accepted','blocked','qa_pending') NOT NULL DEFAULT 'waiting_for_time';
-- UPDATE guards cannot cover INSERT. This check also forbids an initial pending-QA payload.
SET @westy_039_ddl = IF(EXISTS (SELECT 1 FROM information_schema.table_constraints
  WHERE constraint_schema=DATABASE() AND table_name='westy_billing_outbox' AND constraint_name='ck_westy_billing_qa_payload'),
  'DO 0', 'ALTER TABLE westy_billing_outbox ADD CONSTRAINT ck_westy_billing_qa_payload CHECK (state <> ''qa_pending'' OR (payload_json IS NULL AND payload_sha256 IS NULL))');
PREPARE westy_039_stmt FROM @westy_039_ddl;
EXECUTE westy_039_stmt;
DEALLOCATE PREPARE westy_039_stmt;

DELIMITER $$
DROP TRIGGER IF EXISTS trg_westy_work_log_immutable_update$$
CREATE TRIGGER trg_westy_work_log_immutable_update BEFORE UPDATE ON ticket_work_log
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Westy work log is immutable'$$
DROP TRIGGER IF EXISTS trg_westy_work_log_immutable_delete$$
CREATE TRIGGER trg_westy_work_log_immutable_delete BEFORE DELETE ON ticket_work_log
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Westy work log is immutable'$$
DROP TRIGGER IF EXISTS trg_westy_work_log_insert_shape$$
CREATE TRIGGER trg_westy_work_log_insert_shape BEFORE INSERT ON ticket_work_log
FOR EACH ROW BEGIN
  IF (NEW.source = 'staff' AND (NEW.actor_type <> 'user' OR NEW.seq IS NOT NULL
      OR NEW.workflow_id IS NOT NULL OR NEW.entry_sha256 IS NOT NULL))
    OR (NEW.source = 'milepost' AND (NEW.seq IS NULL OR NEW.workflow_id IS NULL OR NEW.entry_sha256 IS NULL)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Westy work log row shape is invalid';
  END IF;
END$$
DROP TRIGGER IF EXISTS trg_westy_work_log_insert_takeover$$
CREATE TRIGGER trg_westy_work_log_insert_takeover AFTER INSERT ON ticket_work_log
FOR EACH ROW BEGIN
  IF NEW.source = 'staff' THEN
    UPDATE westy_workflows SET state = 'human_owned',version = version + 1,
      updated_at = UTC_TIMESTAMP() WHERE tenant_id = NEW.tenant_id AND ticket_id = NEW.ticket_id AND state <> 'human_owned';
  END IF;
END$$
DROP TRIGGER IF EXISTS trg_westy_v4_receipt_immutable_update$$
CREATE TRIGGER trg_westy_v4_receipt_immutable_update BEFORE UPDATE ON westy_workflow_v4_receipts
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Westy v4 receipts are immutable'$$
DROP TRIGGER IF EXISTS trg_westy_v4_receipt_immutable_delete$$
CREATE TRIGGER trg_westy_v4_receipt_immutable_delete BEFORE DELETE ON westy_workflow_v4_receipts
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Westy v4 receipts are immutable'$$
DROP TRIGGER IF EXISTS trg_westy_workflow_log_guard$$
CREATE TRIGGER trg_westy_workflow_log_guard BEFORE UPDATE ON westy_workflows
FOR EACH ROW BEGIN
  IF (NEW.log_sha256 IS NULL) <> (NEW.log_sealed_seq IS NULL)
    OR (OLD.log_sealed_seq IS NOT NULL AND (NEW.log_sealed_seq IS NULL OR NEW.log_sealed_seq < OLD.log_sealed_seq))
    OR (OLD.log_sealed_seq IS NOT NULL AND (NEW.log_sealed_seq <=> OLD.log_sealed_seq)
      AND NOT (NEW.log_sha256 <=> OLD.log_sha256))
    OR (((OLD.qa_state <=> 'passed') OR (OLD.qa_state <=> 'fault_zero') OR (OLD.qa_state <=> 'void'))
      AND NOT ((OLD.qa_state <=> 'passed') AND (NEW.qa_state <=> 'void'))
      AND (NOT (NEW.qa_state <=> OLD.qa_state)
        OR NOT (CAST(NEW.qa_reason AS BINARY) <=> CAST(OLD.qa_reason AS BINARY)))) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Westy work log seal and QA are forward-only';
  END IF;
END$$
DROP TRIGGER IF EXISTS trg_westy_billing_qa_guard$$
CREATE TRIGGER trg_westy_billing_qa_guard BEFORE UPDATE ON westy_billing_outbox
FOR EACH ROW BEGIN
  IF ((OLD.state <=> 'qa_pending')
      AND NOT ((NEW.state <=> 'qa_pending') OR (NEW.state <=> 'waiting_for_time') OR (NEW.state <=> 'blocked')))
    OR (((OLD.state <=> 'qa_pending') OR (NEW.state <=> 'qa_pending'))
      AND (NEW.payload_json IS NOT NULL OR NEW.payload_sha256 IS NOT NULL)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Westy billing QA hold is restricted';
  END IF;
END$$

-- Last statement: never advertise v4 until every required object is installed.
CREATE FUNCTION westy_work_log_schema_health() RETURNS TINYINT
READS SQL DATA SQL SECURITY DEFINER
BEGIN
RETURN CASE WHEN westy_workflow_schema_health() = 1
  AND (SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema=DATABASE() AND (
      (trigger_name IN ('trg_westy_work_log_immutable_update','trg_westy_work_log_immutable_delete')
        AND action_statement LIKE '%Westy work log is immutable%')
      OR (trigger_name='trg_westy_work_log_insert_shape' AND action_statement LIKE '%Westy work log row shape is invalid%')
      OR (trigger_name='trg_westy_work_log_insert_takeover' AND action_statement LIKE '%human_owned%' AND action_statement LIKE '%NEW.source%')
      OR (trigger_name IN ('trg_westy_v4_receipt_immutable_update','trg_westy_v4_receipt_immutable_delete')
        AND action_statement LIKE '%Westy v4 receipts are immutable%')
      OR (trigger_name='trg_westy_workflow_log_guard' AND action_statement LIKE '%Westy work log seal and QA are forward-only%')
      OR (trigger_name='trg_westy_billing_qa_guard' AND action_statement LIKE '%Westy billing QA hold is restricted%')
    )) = 8
  AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()
    AND table_name IN ('ticket_work_log','westy_workflow_v4_receipts') AND table_type='BASE TABLE') = 2
  AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='westy_workflows'
    AND column_name IN ('log_sha256','log_sealed_seq','qa_state','qa_reason')) = 4
  AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ticket_work_log'
    AND column_name IN ('id','tenant_id','ticket_id','workflow_id','source','source_event_key','seq','kind','actor_type','actor_key','summary','cause_code','evidence_sha256','milepost_ref','customer_visible','entry_sha256','occurred_at','created_at')) = 18
  AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='westy_workflow_v4_receipts'
    AND column_name IN ('tenant_id','workflow_id','event_key','request_sha256','action','response_json','received_at')) = 7
  AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='westy_billing_outbox'
    AND column_name='state' AND column_type LIKE '%''qa_pending''%')
  AND EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='westy_billing_outbox'
    AND constraint_name='ck_westy_billing_qa_payload' AND enforced='YES')
THEN 1 ELSE 0 END;
END$$
DELIMITER ;
