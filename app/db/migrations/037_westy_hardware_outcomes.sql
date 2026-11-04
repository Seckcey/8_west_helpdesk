-- 037: Additive hardware findings and notice intents. After 001, 025 and 036.
-- Apply only through deploy.sh --migrate. Retain all tables on source rollback.
-- No backfill, activation, recipient inference, workflow replay or mail submission.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS westy_hardware_notices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  workflow_id BIGINT UNSIGNED NOT NULL,
  ticket_id INT UNSIGNED NOT NULL,
  device_id BIGINT UNSIGNED NOT NULL,
  device_binding_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  issue_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  decision_revision INT UNSIGNED NOT NULL,
  source_json JSON NOT NULL,
  message_id INT UNSIGNED NOT NULL,
  mail_queue_id INT UNSIGNED NULL,
  state VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  claim_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  claim_until DATETIME NULL,
  verification_attempts INT UNSIGNED NOT NULL DEFAULT 0,
  send_attempts INT UNSIGNED NOT NULL DEFAULT 0,
  detail_code VARCHAR(64) NOT NULL DEFAULT 'recipient_verification_pending',
  contact_json JSON NULL,
  no_contact_message_id INT UNSIGNED NULL,
  next_try_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_westy_hardware_notice_scope (tenant_id,id),
  UNIQUE KEY uq_westy_hardware_notice_decision (tenant_id,workflow_id,device_binding_key,issue_key,decision_revision),
  UNIQUE KEY uq_westy_hardware_notice_mail (mail_queue_id),
  KEY ix_westy_hardware_notice_due (state,next_try_at,id),
  CONSTRAINT fk_westy_hardware_notice_workflow FOREIGN KEY (tenant_id,workflow_id) REFERENCES westy_workflows (tenant_id,id),
  CONSTRAINT fk_westy_hardware_notice_ticket FOREIGN KEY (tenant_id,ticket_id) REFERENCES tickets (tenant_id,id),
  CONSTRAINT fk_westy_hardware_notice_client FOREIGN KEY (tenant_id,client_id) REFERENCES clients (tenant_id,id),
  CONSTRAINT ck_westy_hardware_notice_revision CHECK (decision_revision >= 1),
  CONSTRAINT ck_westy_hardware_notice_state CHECK (state IN ('pending','verifying','verification_failed','no_device_contact','queued','sending','failed','uncertain','provider_accepted','superseded','suppressed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS westy_hardware_findings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  workflow_id BIGINT UNSIGNED NOT NULL,
  notice_id BIGINT UNSIGNED NOT NULL,
  finding_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  finding_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_json JSON NOT NULL,
  received_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_westy_hardware_finding_scope (tenant_id,id),
  UNIQUE KEY uq_westy_hardware_finding_key (tenant_id,finding_key),
  CONSTRAINT fk_westy_hardware_finding_workflow FOREIGN KEY (tenant_id,workflow_id) REFERENCES westy_workflows (tenant_id,id),
  CONSTRAINT fk_westy_hardware_finding_notice FOREIGN KEY (tenant_id,notice_id) REFERENCES westy_hardware_notices (tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS westy_hardware_receipts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  finding_id BIGINT UNSIGNED NOT NULL,
  event_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  response_json JSON NOT NULL,
  received_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_westy_hardware_receipt_key (tenant_id,event_key),
  CONSTRAINT fk_westy_hardware_receipt_finding FOREIGN KEY (tenant_id,finding_id) REFERENCES westy_hardware_findings (tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS westy_hardware_mail_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  notice_id BIGINT UNSIGNED NOT NULL,
  attempt_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  contact_json JSON NOT NULL,
  message_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  outcome VARCHAR(32) NOT NULL DEFAULT 'uncertain',
  transport VARCHAR(16) NOT NULL DEFAULT 'none',
  provider_http SMALLINT UNSIGNED NULL,
  outcome_code VARCHAR(64) NOT NULL DEFAULT 'submission_unknown',
  attempted_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_westy_hardware_attempt_key (tenant_id,attempt_key),
  KEY ix_westy_hardware_attempt_notice (tenant_id,notice_id,id),
  CONSTRAINT fk_westy_hardware_attempt_notice FOREIGN KEY (tenant_id,notice_id) REFERENCES westy_hardware_notices (tenant_id,id),
  CONSTRAINT ck_westy_hardware_attempt_outcome CHECK (outcome IN ('uncertain','failed','provider_accepted'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DELIMITER $$
DROP TRIGGER IF EXISTS trg_westy_hardware_finding_update$$
CREATE TRIGGER trg_westy_hardware_finding_update BEFORE UPDATE ON westy_hardware_findings
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hardware findings are immutable'$$
DROP TRIGGER IF EXISTS trg_westy_hardware_finding_delete$$
CREATE TRIGGER trg_westy_hardware_finding_delete BEFORE DELETE ON westy_hardware_findings
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hardware findings cannot be deleted'$$
DROP TRIGGER IF EXISTS trg_westy_hardware_receipt_update$$
CREATE TRIGGER trg_westy_hardware_receipt_update BEFORE UPDATE ON westy_hardware_receipts
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hardware receipts are immutable'$$
DROP TRIGGER IF EXISTS trg_westy_hardware_receipt_delete$$
CREATE TRIGGER trg_westy_hardware_receipt_delete BEFORE DELETE ON westy_hardware_receipts
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hardware receipts cannot be deleted'$$
DROP TRIGGER IF EXISTS trg_westy_hardware_notice_identity$$
CREATE TRIGGER trg_westy_hardware_notice_identity BEFORE UPDATE ON westy_hardware_notices
FOR EACH ROW BEGIN
  IF NOT (NEW.tenant_id <=> OLD.tenant_id) OR NOT (NEW.client_id <=> OLD.client_id)
    OR NOT (NEW.workflow_id <=> OLD.workflow_id) OR NOT (NEW.ticket_id <=> OLD.ticket_id)
    OR NOT (NEW.device_id <=> OLD.device_id) OR NOT (NEW.device_binding_key <=> OLD.device_binding_key)
    OR NOT (NEW.issue_key <=> OLD.issue_key) OR NOT (NEW.decision_revision <=> OLD.decision_revision)
    OR NOT (NEW.source_json <=> OLD.source_json) OR NOT (NEW.message_id <=> OLD.message_id)
    OR NOT (NEW.created_at <=> OLD.created_at)
    OR (OLD.mail_queue_id IS NOT NULL AND NOT (NEW.mail_queue_id <=> OLD.mail_queue_id))
    OR (OLD.state IN ('provider_accepted','uncertain') AND NEW.state <> OLD.state) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hardware notice identity and accepted or unknown submission are retained';
  END IF;
END$$
DROP TRIGGER IF EXISTS trg_westy_hardware_notice_delete$$
CREATE TRIGGER trg_westy_hardware_notice_delete BEFORE DELETE ON westy_hardware_notices
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hardware notice history cannot be deleted'$$
DROP TRIGGER IF EXISTS trg_westy_hardware_attempt_identity$$
CREATE TRIGGER trg_westy_hardware_attempt_identity BEFORE UPDATE ON westy_hardware_mail_attempts
FOR EACH ROW BEGIN
  IF NOT (NEW.tenant_id <=> OLD.tenant_id) OR NOT (NEW.notice_id <=> OLD.notice_id)
    OR NOT (NEW.attempt_key <=> OLD.attempt_key) OR NOT (NEW.contact_json <=> OLD.contact_json)
    OR NOT (NEW.message_sha256 <=> OLD.message_sha256) OR NOT (NEW.attempted_at <=> OLD.attempted_at)
    OR OLD.completed_at IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hardware mail attempts retain original facts and terminal evidence';
  END IF;
END$$
DROP TRIGGER IF EXISTS trg_westy_hardware_attempt_delete$$
CREATE TRIGGER trg_westy_hardware_attempt_delete BEFORE DELETE ON westy_hardware_mail_attempts
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hardware mail attempts cannot be deleted'$$
DELIMITER ;
