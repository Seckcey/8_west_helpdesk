-- Additive tenant AI receipts. No credentials, prompts, reasoning or screenshots.
CREATE TABLE IF NOT EXISTS portal_westy_ai_attempts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 turn_id BIGINT UNSIGNED NOT NULL,
 sequence INT UNSIGNED NOT NULL,
 tenant_id INT UNSIGNED NOT NULL,
 client_id INT UNSIGNED NOT NULL,
 scope_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 provider VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 model_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 catalog_version VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 ai_revision BIGINT UNSIGNED NOT NULL,
 credential_version BIGINT UNSIGNED NOT NULL,
 desktop_task_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 request_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 state ENUM('pending','complete','unavailable') NOT NULL,
 reserve_microusd BIGINT UNSIGNED NOT NULL,
 charged_microusd BIGINT UNSIGNED NOT NULL,
 usage_json JSON NULL,
 created_at DATETIME NOT NULL,
 finished_at DATETIME NULL,
 PRIMARY KEY(id),
 UNIQUE KEY uq_portal_ai_sequence(turn_id,sequence),
 UNIQUE KEY uq_portal_ai_desktop_task(desktop_task_id),
 CONSTRAINT fk_portal_ai_turn FOREIGN KEY(turn_id) REFERENCES portal_westy_turns(id),
 CONSTRAINT ck_portal_ai_charge CHECK(charged_microusd <= reserve_microusd)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
DELIMITER $$
CREATE TRIGGER IF NOT EXISTS portal_ai_attempt_scope BEFORE INSERT ON portal_westy_ai_attempts FOR EACH ROW
BEGIN
 IF NOT EXISTS(SELECT 1 FROM portal_westy_turns WHERE id=NEW.turn_id AND tenant_id=NEW.tenant_id
  AND client_id=NEW.client_id AND scope_key=NEW.scope_key) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AI attempt scope mismatch'; END IF;
END$$
CREATE TRIGGER IF NOT EXISTS portal_ai_attempt_immutable BEFORE UPDATE ON portal_westy_ai_attempts FOR EACH ROW
BEGIN
 IF OLD.state<>'pending' OR NOT(NEW.id <=> OLD.id) OR NOT(NEW.turn_id <=> OLD.turn_id)
  OR NOT(NEW.sequence <=> OLD.sequence) OR NOT(NEW.tenant_id <=> OLD.tenant_id)
  OR NOT(NEW.client_id <=> OLD.client_id) OR NOT(NEW.scope_key <=> OLD.scope_key)
  OR NOT(NEW.provider <=> OLD.provider) OR NOT(NEW.model_name <=> OLD.model_name)
  OR NOT(NEW.catalog_version <=> OLD.catalog_version) OR NOT(NEW.ai_revision <=> OLD.ai_revision)
  OR NOT(NEW.credential_version <=> OLD.credential_version) OR NOT(NEW.desktop_task_id <=> OLD.desktop_task_id)
  OR NOT(NEW.request_fingerprint <=> OLD.request_fingerprint)
  OR NOT(NEW.reserve_microusd <=> OLD.reserve_microusd) OR NOT(NEW.created_at <=> OLD.created_at) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AI attempt identity and completed receipts are immutable'; END IF;
END$$
CREATE TRIGGER IF NOT EXISTS portal_ai_attempt_expiry BEFORE DELETE ON portal_westy_ai_attempts FOR EACH ROW
BEGIN
 IF OLD.state='pending' OR NOT EXISTS(SELECT 1 FROM portal_westy_turns WHERE id=OLD.turn_id
   AND state<>'pending' AND created_at<UTC_TIMESTAMP()-INTERVAL 90 DAY AND expires_at<=UTC_TIMESTAMP()
   AND input_text IS NULL AND reply_json IS NULL) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AI attempt receipt is within its retention window'; END IF;
END$$
DELIMITER ;
