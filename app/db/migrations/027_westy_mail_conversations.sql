-- 027: dedicated Westy mail conversations.  This is deliberately separate
-- from the retrying helpdesk queue and from repair, reporting, and billing.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET @wm_has_receipt_column := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='messages' AND column_name='westy_mail_receipt_id');
SET @wm_receipt_column_sql := IF(@wm_has_receipt_column=0,'ALTER TABLE messages ADD COLUMN westy_mail_receipt_id BIGINT UNSIGNED NULL','SELECT 1');
PREPARE wm_receipt_column_stmt FROM @wm_receipt_column_sql;
EXECUTE wm_receipt_column_stmt;
DEALLOCATE PREPARE wm_receipt_column_stmt;
SET @wm_has_receipt_index := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='messages' AND index_name='uq_messages_westy_mail_receipt');
SET @wm_receipt_index_sql := IF(@wm_has_receipt_index=0,'ALTER TABLE messages ADD UNIQUE INDEX uq_messages_westy_mail_receipt (westy_mail_receipt_id)','SELECT 1');
PREPARE wm_receipt_index_stmt FROM @wm_receipt_index_sql;
EXECUTE wm_receipt_index_stmt;
DEALLOCATE PREPARE wm_receipt_index_stmt;

-- A replay after an interrupted early 027 deployment must upgrade the
-- already-created table as well as a newly-created one.
SET @wm_has_authority_column := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='westy_mail_conversations' AND column_name='authority_sha256');
SET @wm_authority_column_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='westy_mail_conversations')=1 AND @wm_has_authority_column=0,'ALTER TABLE westy_mail_conversations ADD COLUMN authority_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER context_sha256','SELECT 1');
PREPARE wm_authority_column_stmt FROM @wm_authority_column_sql;
EXECUTE wm_authority_column_stmt;
DEALLOCATE PREPARE wm_authority_column_stmt;
SET @wm_has_approval_session_column := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='westy_mail_conversations' AND column_name='approval_session_version');
SET @wm_approval_session_column_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='westy_mail_conversations')=1 AND @wm_has_approval_session_column=0,'ALTER TABLE westy_mail_conversations ADD COLUMN approval_session_version VARCHAR(41) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER approved_at','SELECT 1');
PREPARE wm_approval_session_column_stmt FROM @wm_approval_session_column_sql;
EXECUTE wm_approval_session_column_stmt;
DEALLOCATE PREPARE wm_approval_session_column_stmt;

CREATE TABLE IF NOT EXISTS westy_mail_reconciliations (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 old_draft_id BIGINT UNSIGNED NOT NULL,
 actor_id INT UNSIGNED NOT NULL,
 evidence_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 outcome ENUM('provider_rejected') NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_wm_reconciliation_old (old_draft_id),
 CONSTRAINT fk_wmr_old_draft FOREIGN KEY (old_draft_id) REFERENCES westy_email_drafts(id),
 CONSTRAINT fk_wmr_actor FOREIGN KEY (actor_id) REFERENCES users(id),
 CONSTRAINT ck_wmr_evidence CHECK (evidence_sha256 REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS westy_mail_conversations (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 tenant_id INT UNSIGNED NOT NULL,
 ticket_id INT UNSIGNED NOT NULL,
 client_id INT UNSIGNED NOT NULL,
 customer_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 contact_id INT UNSIGNED NOT NULL,
 recipient VARCHAR(190) NOT NULL,
 recipient_alias_json JSON NULL,
 sender VARCHAR(190) NOT NULL,
 mail_identity_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 subject VARCHAR(190) NOT NULL,
 body_text TEXT NOT NULL,
 message_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 context_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 authority_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 reply_token_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 replacement_of_draft_id BIGINT UNSIGNED NULL,
 reconciliation_id BIGINT UNSIGNED NULL,
 created_by INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 approved_by INT UNSIGNED NULL,
 approved_at DATETIME NULL,
 approval_session_version VARCHAR(41) CHARACTER SET ascii COLLATE ascii_bin NULL,
 attempted_at DATETIME NULL,
 state ENUM('draft','approved','claimed','submitted','rejected','unknown','revoked') NOT NULL DEFAULT 'draft',
 provider_http SMALLINT UNSIGNED NULL,
 provider_request_id VARCHAR(190) NULL,
 outcome_code VARCHAR(64) NULL,
 message_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 UNIQUE KEY uq_wm_conversation_ticket (tenant_id,ticket_id),
 UNIQUE KEY uq_wm_conversation_old_draft (replacement_of_draft_id),
 UNIQUE KEY uq_wm_conversation_request (tenant_id,request_key),
 KEY ix_wm_conversation_contact (tenant_id,client_id,contact_id),
 CONSTRAINT fk_wmc_ticket FOREIGN KEY (tenant_id,ticket_id) REFERENCES tickets(tenant_id,id),
 CONSTRAINT fk_wmc_client FOREIGN KEY (tenant_id,client_id) REFERENCES clients(tenant_id,id),
 CONSTRAINT fk_wmc_contact FOREIGN KEY (contact_id) REFERENCES contacts(id),
 CONSTRAINT fk_wmc_old_draft FOREIGN KEY (replacement_of_draft_id) REFERENCES westy_email_drafts(id),
 CONSTRAINT fk_wmc_reconciliation FOREIGN KEY (reconciliation_id) REFERENCES westy_mail_reconciliations(id),
 CONSTRAINT fk_wmc_creator FOREIGN KEY (created_by) REFERENCES users(id),
 CONSTRAINT fk_wmc_approver FOREIGN KEY (approved_by) REFERENCES users(id),
 CONSTRAINT ck_wmc_hashes CHECK (mail_identity_sha256 REGEXP '^[0-9a-f]{64}$' AND message_sha256 REGEXP '^[0-9a-f]{64}$' AND context_sha256 REGEXP '^[0-9a-f]{64}$' AND authority_sha256 REGEXP '^[0-9a-f]{64}$' AND reply_token_sha256 REGEXP '^[0-9a-f]{64}$'),
 CONSTRAINT ck_wmc_approval_session CHECK (approval_session_version IS NULL OR approval_session_version REGEXP '^[0-9]{1,20}\.[0-9]{1,20}$'),
 CONSTRAINT ck_wmc_attempt CHECK ((attempted_at IS NULL AND state IN ('draft','approved','revoked')) OR (attempted_at IS NOT NULL AND state IN ('claimed','submitted','rejected','unknown')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS westy_mail_delegations (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 conversation_id BIGINT UNSIGNED NOT NULL,
 template_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 template_version SMALLINT UNSIGNED NOT NULL,
 template_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 expires_at DATETIME NOT NULL,
 approved_by INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_wm_delegation_conversation (conversation_id),
 CONSTRAINT fk_wmd_conversation FOREIGN KEY (conversation_id) REFERENCES westy_mail_conversations(id),
 CONSTRAINT fk_wmd_approver FOREIGN KEY (approved_by) REFERENCES users(id),
 CONSTRAINT ck_wmd_template CHECK (template_sha256 REGEXP '^[0-9a-f]{64}$' AND expires_at > created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS westy_mail_delegation_revocations (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 delegation_id BIGINT UNSIGNED NOT NULL,
 reason VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_wm_delegation_revoked (delegation_id),
 CONSTRAINT fk_wmdr_delegation FOREIGN KEY (delegation_id) REFERENCES westy_mail_delegations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- State alone says a draft was revoked but not why.  This insert-only ledger
-- preserves the first staff/source change that made an unsent conversation
-- permanently ineligible, including change-and-revert sequences.
CREATE TABLE IF NOT EXISTS westy_mail_conversation_revocations (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 conversation_id BIGINT UNSIGNED NOT NULL,
 reason VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_wm_conversation_revoked (conversation_id),
 CONSTRAINT fk_wmcr_conversation FOREIGN KEY (conversation_id) REFERENCES westy_mail_conversations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS westy_mail_inbound_receipts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 conversation_id BIGINT UNSIGNED NOT NULL,
 provider_message_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 internet_message_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 sender_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 reply_to_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 body_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 auth_verified TINYINT(1) NOT NULL,
 received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 disposition ENUM('accepted','held') NOT NULL,
 UNIQUE KEY uq_wmir_provider (provider_message_sha256),
 UNIQUE KEY uq_wmir_internet (internet_message_sha256),
 KEY ix_wmir_conversation (conversation_id,id),
 CONSTRAINT fk_wmir_conversation FOREIGN KEY (conversation_id) REFERENCES westy_mail_conversations(id),
 CONSTRAINT ck_wmir_hashes CHECK (provider_message_sha256 REGEXP '^[0-9a-f]{64}$' AND sender_sha256 REGEXP '^[0-9a-f]{64}$' AND reply_to_sha256 REGEXP '^[0-9a-f]{64}$' AND body_sha256 REGEXP '^[0-9a-f]{64}$' AND auth_verified=1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS westy_mail_inbound_holds (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 tenant_id INT UNSIGNED NOT NULL,
 provider_message_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 internet_message_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 sender_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 reply_to_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 reason VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 auth_verified TINYINT(1) NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_wmih_provider (tenant_id,provider_message_sha256),
 UNIQUE KEY uq_wmih_internet (internet_message_sha256),
 CONSTRAINT fk_wmih_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS westy_mail_auto_attempts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 delegation_id BIGINT UNSIGNED NOT NULL,
 receipt_id BIGINT UNSIGNED NOT NULL,
 state ENUM('claimed','submitted','rejected','unknown') NOT NULL DEFAULT 'claimed',
 attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 provider_http SMALLINT UNSIGNED NULL,
 provider_request_id VARCHAR(190) NULL,
 outcome_code VARCHAR(64) NULL,
 message_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 UNIQUE KEY uq_wmaa_delegation (delegation_id),
 UNIQUE KEY uq_wmaa_receipt (receipt_id),
 CONSTRAINT fk_wmaa_delegation FOREIGN KEY (delegation_id) REFERENCES westy_mail_delegations(id),
 CONSTRAINT fk_wmaa_receipt FOREIGN KEY (receipt_id) REFERENCES westy_mail_inbound_receipts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DELIMITER $$
DROP TRIGGER IF EXISTS trg_wmr_immutable_update$$
CREATE TRIGGER trg_wmr_immutable_update BEFORE UPDATE ON westy_mail_reconciliations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Westy mail reconciliation is immutable'$$
DROP TRIGGER IF EXISTS trg_wmr_immutable_delete$$
CREATE TRIGGER trg_wmr_immutable_delete BEFORE DELETE ON westy_mail_reconciliations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Westy mail reconciliation cannot be deleted'$$
DROP TRIGGER IF EXISTS trg_wmc_insert$$
CREATE TRIGGER trg_wmc_insert BEFORE INSERT ON westy_mail_conversations FOR EACH ROW BEGIN
 IF NEW.state<>'draft' OR NEW.approved_by IS NOT NULL OR NEW.approved_at IS NOT NULL OR NEW.approval_session_version IS NOT NULL OR NEW.attempted_at IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Westy mail starts as an unapproved draft'; END IF;
END$$
DROP TRIGGER IF EXISTS trg_wmc_update$$
CREATE TRIGGER trg_wmc_update BEFORE UPDATE ON westy_mail_conversations FOR EACH ROW BEGIN
 IF NOT(NEW.tenant_id<=>OLD.tenant_id) OR NOT(NEW.ticket_id<=>OLD.ticket_id) OR NOT(NEW.client_id<=>OLD.client_id) OR NOT(BINARY NEW.customer_id<=>BINARY OLD.customer_id) OR NOT(NEW.contact_id<=>OLD.contact_id) OR NOT(BINARY NEW.recipient<=>BINARY OLD.recipient) OR NOT(BINARY NEW.recipient_alias_json<=>BINARY OLD.recipient_alias_json) OR NOT(BINARY NEW.sender<=>BINARY OLD.sender) OR NOT(BINARY NEW.mail_identity_sha256<=>BINARY OLD.mail_identity_sha256) OR NOT(BINARY NEW.subject<=>BINARY OLD.subject) OR NOT(BINARY NEW.body_text<=>BINARY OLD.body_text) OR NOT(BINARY NEW.message_sha256<=>BINARY OLD.message_sha256) OR NOT(BINARY NEW.context_sha256<=>BINARY OLD.context_sha256) OR NOT(BINARY NEW.authority_sha256<=>BINARY OLD.authority_sha256) OR NOT(BINARY NEW.reply_token_sha256<=>BINARY OLD.reply_token_sha256) OR NOT(BINARY NEW.request_key<=>BINARY OLD.request_key) OR NOT(BINARY NEW.message_key<=>BINARY OLD.message_key) OR NOT(NEW.replacement_of_draft_id<=>OLD.replacement_of_draft_id) OR NOT(NEW.reconciliation_id<=>OLD.reconciliation_id) OR NOT(NEW.created_by<=>OLD.created_by) OR NOT(NEW.created_at<=>OLD.created_at) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Westy mail draft identity is immutable'; END IF;
 IF (OLD.state='draft' AND NEW.state NOT IN ('approved','revoked')) OR (OLD.state='approved' AND NEW.state NOT IN ('claimed','revoked')) OR (OLD.state='claimed' AND NEW.state NOT IN ('submitted','rejected','unknown')) OR (OLD.state IN ('submitted','rejected','unknown','revoked') AND NEW.state<>OLD.state) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Westy mail state cannot be rewound'; END IF;
 IF OLD.attempted_at IS NOT NULL AND NOT(NEW.attempted_at<=>OLD.attempted_at) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Westy mail attempt cannot change'; END IF;
 IF OLD.approval_session_version IS NOT NULL AND NOT(BINARY NEW.approval_session_version<=>BINARY OLD.approval_session_version) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Westy mail approval session is immutable'; END IF;
 IF OLD.state='draft' AND NEW.state='approved' AND (NEW.approval_session_version IS NULL OR NEW.approval_session_version NOT REGEXP '^[0-9]{1,20}\\.[0-9]{1,20}$') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Westy mail approval requires a signed session version'; END IF;
 IF NOT (OLD.state='draft' AND NEW.state='approved') AND OLD.approval_session_version IS NULL AND NEW.approval_session_version IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Westy mail approval session can only be set on approval'; END IF;
END$$
DROP TRIGGER IF EXISTS trg_wmc_delete$$
CREATE TRIGGER trg_wmc_delete BEFORE DELETE ON westy_mail_conversations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Westy mail history cannot be deleted'$$
DROP TRIGGER IF EXISTS trg_wmd_immutable_update$$
CREATE TRIGGER trg_wmd_immutable_update BEFORE UPDATE ON westy_mail_delegations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Westy mail delegation is immutable'$$
DROP TRIGGER IF EXISTS trg_wmd_immutable_delete$$
CREATE TRIGGER trg_wmd_immutable_delete BEFORE DELETE ON westy_mail_delegations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Westy mail delegation cannot be deleted'$$
DROP TRIGGER IF EXISTS trg_wmdr_immutable_update$$
CREATE TRIGGER trg_wmdr_immutable_update BEFORE UPDATE ON westy_mail_delegation_revocations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Westy mail revocation is immutable'$$
DROP TRIGGER IF EXISTS trg_wmdr_immutable_delete$$
CREATE TRIGGER trg_wmdr_immutable_delete BEFORE DELETE ON westy_mail_delegation_revocations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Westy mail revocation cannot be deleted'$$
DROP TRIGGER IF EXISTS trg_wmcr_immutable_update$$
CREATE TRIGGER trg_wmcr_immutable_update BEFORE UPDATE ON westy_mail_conversation_revocations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Westy mail conversation revocation is immutable'$$
DROP TRIGGER IF EXISTS trg_wmcr_immutable_delete$$
CREATE TRIGGER trg_wmcr_immutable_delete BEFORE DELETE ON westy_mail_conversation_revocations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Westy mail conversation revocation cannot be deleted'$$
DROP TRIGGER IF EXISTS trg_wmir_immutable_update$$
CREATE TRIGGER trg_wmir_immutable_update BEFORE UPDATE ON westy_mail_inbound_receipts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Westy mail inbound receipt is immutable'$$
DROP TRIGGER IF EXISTS trg_wmir_immutable_delete$$
CREATE TRIGGER trg_wmir_immutable_delete BEFORE DELETE ON westy_mail_inbound_receipts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Westy mail inbound receipt cannot be deleted'$$
DROP TRIGGER IF EXISTS trg_wmih_immutable_update$$
CREATE TRIGGER trg_wmih_immutable_update BEFORE UPDATE ON westy_mail_inbound_holds FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Westy mail inbound hold is immutable'$$
DROP TRIGGER IF EXISTS trg_wmih_immutable_delete$$
CREATE TRIGGER trg_wmih_immutable_delete BEFORE DELETE ON westy_mail_inbound_holds FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Westy mail inbound hold cannot be deleted'$$
DROP TRIGGER IF EXISTS trg_wmaa_update$$
CREATE TRIGGER trg_wmaa_update BEFORE UPDATE ON westy_mail_auto_attempts FOR EACH ROW BEGIN
 IF NOT(NEW.delegation_id<=>OLD.delegation_id) OR NOT(NEW.receipt_id<=>OLD.receipt_id) OR NOT(BINARY NEW.message_key<=>BINARY OLD.message_key) OR NOT(NEW.attempted_at<=>OLD.attempted_at) OR (OLD.state='claimed' AND NEW.state NOT IN ('submitted','rejected','unknown')) OR (OLD.state<>'claimed' AND NEW.state<>OLD.state) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Westy mail automatic attempt cannot be rewound'; END IF;
END$$
DROP TRIGGER IF EXISTS trg_wmaa_delete$$
CREATE TRIGGER trg_wmaa_delete BEFORE DELETE ON westy_mail_auto_attempts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Westy mail automatic attempt cannot be deleted'$$

DROP PROCEDURE IF EXISTS westy_mail_revoke_ticket$$
CREATE PROCEDURE westy_mail_revoke_ticket(IN p_ticket BIGINT UNSIGNED, IN p_reason VARCHAR(64))
BEGIN
 INSERT IGNORE INTO westy_mail_conversation_revocations(conversation_id,reason)
 SELECT c.id,p_reason FROM westy_mail_conversations c WHERE c.ticket_id=p_ticket;
 INSERT IGNORE INTO westy_mail_delegation_revocations(delegation_id,reason)
 SELECT d.id,p_reason FROM westy_mail_delegations d JOIN westy_mail_conversations c ON c.id=d.conversation_id WHERE c.ticket_id=p_ticket;
 UPDATE westy_mail_conversations SET state='revoked' WHERE ticket_id=p_ticket AND state IN ('draft','approved');
END$$
DROP TRIGGER IF EXISTS trg_wm_ticket_revoke$$
CREATE TRIGGER trg_wm_ticket_revoke AFTER UPDATE ON tickets FOR EACH ROW CALL westy_mail_revoke_ticket(OLD.id,'case_changed')$$
DROP TRIGGER IF EXISTS trg_wm_message_insert_revoke$$
CREATE TRIGGER trg_wm_message_insert_revoke BEFORE INSERT ON messages FOR EACH ROW BEGIN
 IF NEW.westy_mail_receipt_id IS NOT NULL AND NOT EXISTS (
   SELECT 1 FROM westy_mail_inbound_receipts r
   JOIN westy_mail_conversations c ON c.id=r.conversation_id
   WHERE r.id=NEW.westy_mail_receipt_id AND c.ticket_id=NEW.ticket_id
     AND BINARY r.body_sha256=BINARY SHA2(NEW.body,256)
 ) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Westy mail receipt must bind exact ticket and body'; END IF;
 IF NEW.kind<>'system' AND NEW.westy_mail_receipt_id IS NULL THEN CALL westy_mail_revoke_ticket(NEW.ticket_id,'human_message'); END IF;
END$$
DROP TRIGGER IF EXISTS trg_wm_message_update_revoke$$
CREATE TRIGGER trg_wm_message_update_revoke AFTER UPDATE ON messages FOR EACH ROW BEGIN
 CALL westy_mail_revoke_ticket(OLD.ticket_id,'message_changed');
 IF NEW.ticket_id<>OLD.ticket_id THEN CALL westy_mail_revoke_ticket(NEW.ticket_id,'message_changed'); END IF;
END$$
DROP TRIGGER IF EXISTS trg_wm_message_delete_revoke$$
CREATE TRIGGER trg_wm_message_delete_revoke AFTER DELETE ON messages FOR EACH ROW CALL westy_mail_revoke_ticket(OLD.ticket_id,'message_deleted')$$
DROP TRIGGER IF EXISTS trg_wm_contact_revoke$$
CREATE TRIGGER trg_wm_contact_revoke AFTER UPDATE ON contacts FOR EACH ROW BEGIN
 INSERT IGNORE INTO westy_mail_conversation_revocations(conversation_id,reason) SELECT c.id,'contact_changed' FROM westy_mail_conversations c WHERE c.contact_id=OLD.id;
 INSERT IGNORE INTO westy_mail_delegation_revocations(delegation_id,reason) SELECT d.id,'contact_changed' FROM westy_mail_delegations d JOIN westy_mail_conversations c ON c.id=d.conversation_id WHERE c.contact_id=OLD.id;
 UPDATE westy_mail_conversations SET state='revoked' WHERE contact_id=OLD.id AND state IN ('draft','approved');
END$$
DROP TRIGGER IF EXISTS trg_wm_binding_revoke$$
CREATE TRIGGER trg_wm_binding_revoke AFTER UPDATE ON suite_customer_sync_bindings FOR EACH ROW BEGIN
 INSERT IGNORE INTO westy_mail_conversation_revocations(conversation_id,reason) SELECT c.id,'customer_binding_changed' FROM westy_mail_conversations c WHERE c.tenant_id=OLD.tenant_id AND c.client_id=OLD.client_id AND BINARY c.customer_id=BINARY OLD.customer_id;
 INSERT IGNORE INTO westy_mail_delegation_revocations(delegation_id,reason) SELECT d.id,'customer_binding_changed' FROM westy_mail_delegations d JOIN westy_mail_conversations c ON c.id=d.conversation_id WHERE c.tenant_id=OLD.tenant_id AND c.client_id=OLD.client_id AND BINARY c.customer_id=BINARY OLD.customer_id;
 UPDATE westy_mail_conversations SET state='revoked' WHERE tenant_id=OLD.tenant_id AND client_id=OLD.client_id AND BINARY customer_id=BINARY OLD.customer_id AND state IN ('draft','approved');
END$$
DROP TRIGGER IF EXISTS trg_wm_poc_revoke$$
CREATE TRIGGER trg_wm_poc_revoke AFTER INSERT ON westy_client_poc FOR EACH ROW BEGIN
 INSERT IGNORE INTO westy_mail_conversation_revocations(conversation_id,reason) SELECT c.id,'poc_changed' FROM westy_mail_conversations c WHERE c.tenant_id=NEW.tenant_id AND c.client_id=NEW.client_id;
 INSERT IGNORE INTO westy_mail_delegation_revocations(delegation_id,reason) SELECT d.id,'poc_changed' FROM westy_mail_delegations d JOIN westy_mail_conversations c ON c.id=d.conversation_id WHERE c.tenant_id=NEW.tenant_id AND c.client_id=NEW.client_id;
 UPDATE westy_mail_conversations SET state='revoked' WHERE tenant_id=NEW.tenant_id AND client_id=NEW.client_id AND state IN ('draft','approved');
END$$
DROP TRIGGER IF EXISTS trg_wm_user_revoke$$
CREATE TRIGGER trg_wm_user_revoke AFTER UPDATE ON users FOR EACH ROW BEGIN
 IF NOT(NEW.role<=>OLD.role) OR NOT(NEW.is_active<=>OLD.is_active) OR NOT(NEW.tenant_id<=>OLD.tenant_id) OR NOT(NEW.suite_subject<=>OLD.suite_subject) THEN
   INSERT IGNORE INTO westy_mail_conversation_revocations(conversation_id,reason) SELECT id,'identity_changed' FROM westy_mail_conversations WHERE created_by=OLD.id OR approved_by=OLD.id;
   INSERT IGNORE INTO westy_mail_delegation_revocations(delegation_id,reason) SELECT id,'identity_changed' FROM westy_mail_delegations WHERE approved_by=OLD.id;
   UPDATE westy_mail_conversations SET state='revoked' WHERE (created_by=OLD.id OR approved_by=OLD.id) AND state IN ('draft','approved');
 END IF;
END$$
DROP TRIGGER IF EXISTS trg_wm_workflow_revoke$$
CREATE TRIGGER trg_wm_workflow_revoke AFTER UPDATE ON westy_workflows FOR EACH ROW CALL westy_mail_revoke_ticket(OLD.ticket_id,'workflow_changed')$$
DROP TRIGGER IF EXISTS trg_wm_time_insert_revoke$$
CREATE TRIGGER trg_wm_time_insert_revoke AFTER INSERT ON time_entries FOR EACH ROW CALL westy_mail_revoke_ticket(NEW.ticket_id,'time_changed')$$
DROP TRIGGER IF EXISTS trg_wm_time_update_revoke$$
CREATE TRIGGER trg_wm_time_update_revoke AFTER UPDATE ON time_entries FOR EACH ROW BEGIN
 CALL westy_mail_revoke_ticket(OLD.ticket_id,'time_changed');
 IF NEW.ticket_id<>OLD.ticket_id THEN CALL westy_mail_revoke_ticket(NEW.ticket_id,'time_changed'); END IF;
END$$
DROP TRIGGER IF EXISTS trg_wm_time_delete_revoke$$
CREATE TRIGGER trg_wm_time_delete_revoke AFTER DELETE ON time_entries FOR EACH ROW CALL westy_mail_revoke_ticket(OLD.ticket_id,'time_changed')$$
DROP FUNCTION IF EXISTS westy_mail_schema_health$$
CREATE FUNCTION westy_mail_schema_health() RETURNS TINYINT READS SQL DATA SQL SECURITY DEFINER
BEGIN
 RETURN (SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name IN ('trg_wmr_immutable_update','trg_wmr_immutable_delete','trg_wmc_insert','trg_wmc_update','trg_wmc_delete','trg_wmd_immutable_update','trg_wmd_immutable_delete','trg_wmdr_immutable_update','trg_wmdr_immutable_delete','trg_wmcr_immutable_update','trg_wmcr_immutable_delete','trg_wmir_immutable_update','trg_wmir_immutable_delete','trg_wmih_immutable_update','trg_wmih_immutable_delete','trg_wmaa_update','trg_wmaa_delete','trg_wm_ticket_revoke','trg_wm_message_insert_revoke','trg_wm_message_update_revoke','trg_wm_message_delete_revoke','trg_wm_contact_revoke','trg_wm_binding_revoke','trg_wm_poc_revoke','trg_wm_user_revoke','trg_wm_workflow_revoke','trg_wm_time_insert_revoke','trg_wm_time_update_revoke','trg_wm_time_delete_revoke'))=29
 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='westy_mail_conversations' AND column_name IN ('authority_sha256','approval_session_version'))=2
 AND (SELECT action_statement FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name='trg_wm_message_insert_revoke') LIKE '%body_sha256%'
 AND (SELECT action_statement FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name='trg_wmc_update') LIKE '%approval session is immutable%'
 AND (SELECT action_statement FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name='trg_wm_ticket_revoke') LIKE '%westy_mail_revoke_ticket%'
 AND (SELECT routine_definition FROM information_schema.routines WHERE routine_schema=DATABASE() AND routine_name='westy_mail_revoke_ticket' AND routine_type='PROCEDURE') LIKE '%westy_mail_conversation_revocations%';
END$$
DELIMITER ;
