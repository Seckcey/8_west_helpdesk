-- BEGIN EXACT TENANT AI GUARD
-- Refuse drift, unrecognised partial order, or populated incomplete guards before DDL.
SET @tai_previous_group_concat=@@SESSION.group_concat_max_len;
SET SESSION group_concat_max_len=1048576;
SET @tai_shape_ok=
 (NOT EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='portal_westy_ai_attempts') OR ((SELECT COUNT(*)=19 AND SHA2(COALESCE(GROUP_CONCAT(JSON_ARRAY(COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,GENERATION_EXPRESSION) ORDER BY ORDINAL_POSITION SEPARATOR '|'),''),256)='0386e88ed4a5f643446664f232bb3e96fbbcf69801088f3b80b6dd307ced74e6' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='portal_westy_ai_attempts') AND (SELECT COUNT(*)=4 AND SHA2(COALESCE(GROUP_CONCAT(JSON_ARRAY(INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,COLLATION,SUB_PART,NULLABLE,INDEX_TYPE,EXPRESSION,IS_VISIBLE) ORDER BY INDEX_NAME,SEQ_IN_INDEX SEPARATOR '|'),''),256)='1efe1f5fcd41c2c1960cd1db8ddcfa3a21c60492b7d3717b333bd6eec96c02bb' FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='portal_westy_ai_attempts') AND (SELECT COUNT(*)=1 AND SHA2(COALESCE(GROUP_CONCAT(JSON_ARRAY(CONSTRAINT_NAME,COLUMN_NAME,ORDINAL_POSITION,POSITION_IN_UNIQUE_CONSTRAINT,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME) ORDER BY CONSTRAINT_NAME,ORDINAL_POSITION SEPARATOR '|'),''),256)='5436deb051ebdb956d3eee1a2192a4b4070355c4a72750aae3de1821b89e121f' FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='portal_westy_ai_attempts' AND REFERENCED_TABLE_NAME IS NOT NULL) AND (SELECT COUNT(*)=1 AND SHA2(COALESCE(GROUP_CONCAT(JSON_ARRAY(CONSTRAINT_NAME,UNIQUE_CONSTRAINT_NAME,MATCH_OPTION,UPDATE_RULE,DELETE_RULE,REFERENCED_TABLE_NAME) ORDER BY CONSTRAINT_NAME SEPARATOR '|'),''),256)='e58b2426a924cf62957a7db63244d75fe9be7e39051471f32911d018ee82f680' FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='portal_westy_ai_attempts') AND (SELECT COUNT(*)=1 AND SHA2(COALESCE(GROUP_CONCAT(JSON_ARRAY(ENGINE,TABLE_COLLATION,ROW_FORMAT,CREATE_OPTIONS) ORDER BY TABLE_NAME SEPARATOR '|'),''),256)='6072f8f4c21c155d166cdf9998bcfa298a32aa02a1426ed3178280659b5fa701' FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='portal_westy_ai_attempts') AND (SELECT COUNT(*)=1 AND SHA2(COALESCE(GROUP_CONCAT(JSON_ARRAY(t.CONSTRAINT_NAME,t.ENFORCED,c.CHECK_CLAUSE) ORDER BY t.CONSTRAINT_NAME SEPARATOR '|'),''),256)='5bf141ac7cd1dc9b64ce6dc1b1c0b5e2fa2957891c827f0515260910208a2974' FROM information_schema.TABLE_CONSTRAINTS t JOIN information_schema.CHECK_CONSTRAINTS c ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME='portal_westy_ai_attempts' AND t.CONSTRAINT_TYPE='CHECK')))
 AND (SELECT COUNT(*)=0 OR (COUNT(*)=1 AND SUM(EVENT_OBJECT_TABLE='portal_westy_ai_attempts' AND EVENT_MANIPULATION='DELETE' AND ACTION_TIMING='BEFORE' AND SHA2(ACTION_STATEMENT,256)='c6335162a84b7d1e5f5d2931615fa4712a9262aba1e50f95d7ae6460fc9439a6')=1) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='portal_ai_attempt_expiry')
 AND (SELECT COUNT(*)=0 OR (COUNT(*)=1 AND SUM(EVENT_OBJECT_TABLE='portal_westy_ai_attempts' AND EVENT_MANIPULATION='UPDATE' AND ACTION_TIMING='BEFORE' AND SHA2(ACTION_STATEMENT,256)='7b8614d44c7250368bb680ad1fe54fb0547a82a237603a25d67f9c9d0c1766e5')=1) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='portal_ai_attempt_immutable')
 AND (SELECT COUNT(*)=0 OR (COUNT(*)=1 AND SUM(EVENT_OBJECT_TABLE='portal_westy_ai_attempts' AND EVENT_MANIPULATION='INSERT' AND ACTION_TIMING='BEFORE' AND SHA2(ACTION_STATEMENT,256)='40051c617ccf9801183e3ea845256bdb3c8c89c703574f0ebf5e50005eb7941d')=1) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='portal_ai_attempt_scope')
 AND NOT EXISTS(SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='portal_westy_ai_attempts' AND TRIGGER_NAME NOT IN ('portal_ai_attempt_expiry','portal_ai_attempt_immutable','portal_ai_attempt_scope'));
SET @tai_schema_mask=IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='portal_westy_ai_attempts'),1,0) + IF(EXISTS(SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='portal_ai_attempt_expiry'),2,0) + IF(EXISTS(SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='portal_ai_attempt_immutable'),4,0) + IF(EXISTS(SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='portal_ai_attempt_scope'),8,0);
SET @tai_guard_sql=IF(COALESCE(@tai_shape_ok,0)=1 AND @tai_schema_mask IN (0,1,9,13,15),'DO 0','SELECT * FROM TENANT_AI_SCHEMA_MISMATCH_STOP');
PREPARE tai_guard FROM @tai_guard_sql;
EXECUTE tai_guard;
DEALLOCATE PREPARE tai_guard;
SET @tai_existing_rows=0;
SET @tai_guard_sql=IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='portal_westy_ai_attempts'),'SELECT @tai_existing_rows+COUNT(*) INTO @tai_existing_rows FROM portal_westy_ai_attempts','DO 0');
PREPARE tai_guard FROM @tai_guard_sql;
EXECUTE tai_guard;
DEALLOCATE PREPARE tai_guard;
SET @tai_guard_sql=IF(@tai_schema_mask=15 OR @tai_existing_rows=0,'DO 0','SELECT * FROM TENANT_AI_POPULATED_PARTIAL_STOP');
PREPARE tai_guard FROM @tai_guard_sql;
EXECUTE tai_guard;
DEALLOCATE PREPARE tai_guard;
SET SESSION group_concat_max_len=@tai_previous_group_concat;
-- END EXACT TENANT AI GUARD
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
   AND state<>'pending' AND created_at<UTC_TIMESTAMP()-INTERVAL 90 DAY-INTERVAL 60 SECOND AND expires_at<=UTC_TIMESTAMP()
   AND input_text IS NULL AND reply_json IS NULL) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AI attempt receipt is within its retention window'; END IF;
END$$
DELIMITER ;
