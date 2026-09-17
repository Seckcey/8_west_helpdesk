-- Migration first, exact archived bytes, Safeharbor-only write freeze and tested backup.
-- Two additive tables; no existing ticket/contact/mail/report/financial rows change.
SET NAMES utf8mb4;
SET time_zone = '+00:00';
CREATE TABLE IF NOT EXISTS westy_client_poc (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 tenant_id INT UNSIGNED NOT NULL,
 client_id INT UNSIGNED NOT NULL,
 contact_id INT UNSIGNED NULL,
 actor_id INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY ix_westy_client_poc (tenant_id,client_id,id),
 CONSTRAINT fk_we_poc_client FOREIGN KEY (tenant_id,client_id) REFERENCES clients(tenant_id,id),
 CONSTRAINT fk_we_poc_actor FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS westy_email_drafts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 tenant_id INT UNSIGNED NOT NULL,
 ticket_id INT UNSIGNED NOT NULL,
 client_id INT UNSIGNED NOT NULL,
 contact_id INT UNSIGNED NOT NULL,
 recipient VARCHAR(190) NOT NULL,
 subject VARCHAR(190) NOT NULL,
 body_text TEXT NOT NULL,
 context_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 state ENUM('draft','revoked','uncertain','submitted') NOT NULL DEFAULT 'draft',
 approved_by INT UNSIGNED NULL,
 approved_at DATETIME NULL,
 attempted_at DATETIME NULL,
 provider_http SMALLINT UNSIGNED NULL,
 detail VARCHAR(64) NOT NULL DEFAULT 'human_review_required',
 UNIQUE KEY uq_westy_email_request (tenant_id,request_key),
 KEY ix_westy_email_ticket (tenant_id,ticket_id,id),
 CONSTRAINT fk_we_ticket FOREIGN KEY (tenant_id,ticket_id) REFERENCES tickets(tenant_id,id),
 CONSTRAINT fk_we_client FOREIGN KEY (tenant_id,client_id) REFERENCES clients(tenant_id,id),
 CONSTRAINT fk_we_author FOREIGN KEY (created_by) REFERENCES users(id),
 CONSTRAINT fk_we_approver FOREIGN KEY (approved_by) REFERENCES users(id),
 CONSTRAINT ck_we_attempt CHECK ((attempted_at IS NULL AND approved_by IS NULL AND approved_at IS NULL AND state IN ('draft','revoked')) OR (attempted_at IS NOT NULL AND approved_by IS NOT NULL AND approved_at IS NOT NULL AND state IN ('uncertain','submitted'))),
 CONSTRAINT ck_we_submission CHECK (state<>'submitted' OR (provider_http IS NOT NULL AND provider_http=202))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DELIMITER $$
DROP TRIGGER IF EXISTS trg_we_poc_update$$
CREATE TRIGGER trg_we_poc_update BEFORE UPDATE ON westy_client_poc FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='POC history is immutable'$$
DROP TRIGGER IF EXISTS trg_we_poc_delete$$
CREATE TRIGGER trg_we_poc_delete BEFORE DELETE ON westy_client_poc FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='POC history is immutable'$$
DROP TRIGGER IF EXISTS trg_we_draft_insert$$
CREATE TRIGGER trg_we_draft_insert BEFORE INSERT ON westy_email_drafts FOR EACH ROW BEGIN
 IF NEW.state<>'draft' OR NEW.attempted_at IS NOT NULL OR NEW.approved_by IS NOT NULL OR NEW.approved_at IS NOT NULL THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Emails start as unapproved drafts'; END IF;
END$$
DROP TRIGGER IF EXISTS trg_we_draft_update$$
CREATE TRIGGER trg_we_draft_update BEFORE UPDATE ON westy_email_drafts FOR EACH ROW BEGIN
 IF NOT(NEW.tenant_id<=>OLD.tenant_id) OR NOT(NEW.ticket_id<=>OLD.ticket_id) OR NOT(NEW.client_id<=>OLD.client_id)
 OR NOT(NEW.contact_id<=>OLD.contact_id) OR NOT(BINARY NEW.recipient<=>BINARY OLD.recipient)
 OR NOT(BINARY NEW.subject<=>BINARY OLD.subject) OR NOT(BINARY NEW.body_text<=>BINARY OLD.body_text)
 OR NOT(NEW.context_sha256<=>OLD.context_sha256) OR NOT(NEW.request_key<=>OLD.request_key)
 OR NOT(NEW.created_by<=>OLD.created_by) OR NOT(NEW.created_at<=>OLD.created_at)
 OR (OLD.state='revoked' AND NEW.state<>'revoked')
 OR (OLD.attempted_at IS NOT NULL AND (NOT(NEW.attempted_at<=>OLD.attempted_at) OR NOT(NEW.approved_by<=>OLD.approved_by) OR NOT(NEW.approved_at<=>OLD.approved_at)))
 OR (OLD.state='submitted' AND (NEW.state<>'submitted' OR NOT(NEW.provider_http<=>OLD.provider_http))) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reviewed message and send history cannot change'; END IF;
END$$
DROP TRIGGER IF EXISTS trg_we_draft_delete$$
CREATE TRIGGER trg_we_draft_delete BEFORE DELETE ON westy_email_drafts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Email history cannot be deleted'$$
DROP TRIGGER IF EXISTS trg_we_ticket_changed$$
CREATE TRIGGER trg_we_ticket_changed AFTER UPDATE ON tickets FOR EACH ROW
 UPDATE westy_email_drafts SET state='revoked',detail='case_changed' WHERE ticket_id=OLD.id AND tenant_id=OLD.tenant_id AND state='draft'$$
DROP TRIGGER IF EXISTS trg_we_message_added$$
CREATE TRIGGER trg_we_message_added AFTER INSERT ON messages FOR EACH ROW
 UPDATE westy_email_drafts SET state='revoked',detail='case_evidence_changed' WHERE ticket_id=NEW.ticket_id AND state='draft'$$
DROP TRIGGER IF EXISTS trg_we_contact_changed$$
CREATE TRIGGER trg_we_contact_changed AFTER UPDATE ON contacts FOR EACH ROW
 UPDATE westy_email_drafts SET state='revoked',detail='contact_changed' WHERE contact_id=OLD.id AND state='draft'$$
DROP TRIGGER IF EXISTS trg_we_user_changed$$
CREATE TRIGGER trg_we_user_changed AFTER UPDATE ON users FOR EACH ROW BEGIN
 IF NOT(NEW.role<=>OLD.role) OR NOT(NEW.is_active<=>OLD.is_active) OR NOT(NEW.tenant_id<=>OLD.tenant_id) OR NOT(NEW.suite_subject<=>OLD.suite_subject) THEN
 UPDATE westy_email_drafts SET state='revoked',detail='author_authority_changed' WHERE created_by=OLD.id AND state='draft'; END IF;
END$$
DROP TRIGGER IF EXISTS trg_we_client_poc_added$$
CREATE TRIGGER trg_we_client_poc_added AFTER INSERT ON westy_client_poc FOR EACH ROW
 UPDATE westy_email_drafts SET state='revoked',detail='client_contact_changed' WHERE tenant_id=NEW.tenant_id AND client_id=NEW.client_id AND state='draft'$$
DROP FUNCTION IF EXISTS westy_email_schema_health$$
CREATE FUNCTION westy_email_schema_health() RETURNS INT READS SQL DATA SQL SECURITY DEFINER
BEGIN
 RETURN (SELECT COUNT(*)=10 FROM information_schema.triggers WHERE trigger_schema=DATABASE()
 AND trigger_name IN ('trg_we_poc_update','trg_we_poc_delete','trg_we_draft_insert','trg_we_draft_update','trg_we_draft_delete','trg_we_ticket_changed','trg_we_message_added','trg_we_contact_changed','trg_we_user_changed','trg_we_client_poc_added'));
END$$
DELIMITER ;
