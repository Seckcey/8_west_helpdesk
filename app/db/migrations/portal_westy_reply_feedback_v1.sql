-- Next-release candidate only. Apply migration-first under the protected
-- Safeharbor release procedure; this file is not permission to migrate live.
-- Requires 031_portal_westy.sql and 20261004_tenant_ai.sql.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS portal_westy_reply_feedback (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 turn_id BIGINT UNSIGNED NOT NULL,
 tenant_id INT UNSIGNED NOT NULL,
 client_id INT UNSIGNED NOT NULL,
 binding_id INT UNSIGNED NOT NULL,
 scope_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor_subject VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 conversation_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 operation_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 response_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 revision INT UNSIGNED NOT NULL,
 reaction ENUM('up','down','none') NOT NULL,
 context_json JSON NOT NULL,
 created_at DATETIME NOT NULL,
 expires_at DATETIME NOT NULL,
 PRIMARY KEY(id),
 UNIQUE KEY uq_portal_feedback_request(scope_key,request_key),
 UNIQUE KEY uq_portal_feedback_revision(turn_id,response_key,revision),
 KEY ix_portal_feedback_review(tenant_id,client_id,scope_key,reaction,created_at),
 KEY ix_portal_feedback_expiry(expires_at),
 CONSTRAINT fk_portal_feedback_turn FOREIGN KEY(turn_id) REFERENCES portal_westy_turns(id),
 CONSTRAINT fk_portal_feedback_account FOREIGN KEY(tenant_id,client_id,scope_key)
  REFERENCES portal_westy_accounts(tenant_id,client_id,scope_key),
 CONSTRAINT ck_portal_feedback_revision CHECK(revision>0),
 CONSTRAINT ck_portal_feedback_expiry CHECK(expires_at>created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

DELIMITER $$
CREATE TRIGGER IF NOT EXISTS portal_feedback_scope BEFORE INSERT ON portal_westy_reply_feedback FOR EACH ROW
BEGIN
 IF NOT EXISTS(SELECT 1 FROM portal_westy_turns t JOIN portal_westy_accounts a ON a.scope_key=t.scope_key
   WHERE t.id=NEW.turn_id AND t.tenant_id=NEW.tenant_id AND t.client_id=NEW.client_id
   AND t.scope_key=NEW.scope_key AND a.binding_id=NEW.binding_id
   AND t.conversation_key=NEW.conversation_key AND t.operation_key=NEW.operation_key
   AND t.state IN ('complete','unavailable') AND t.input_text IS NOT NULL AND t.reply_json IS NOT NULL
   AND t.expires_at=NEW.expires_at AND t.expires_at>UTC_TIMESTAMP()
   AND JSON_UNQUOTE(JSON_EXTRACT(NEW.context_json,'$.prompt'))=t.input_text
   AND JSON_UNQUOTE(JSON_EXTRACT(NEW.context_json,'$.response'))=JSON_UNQUOTE(JSON_EXTRACT(t.reply_json,'$.reply')))
 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Feedback requires the owned saved response'; END IF;
END$$
CREATE TRIGGER IF NOT EXISTS portal_feedback_immutable BEFORE UPDATE ON portal_westy_reply_feedback FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Feedback evidence is append only';
END$$
CREATE TRIGGER IF NOT EXISTS portal_feedback_erasure BEFORE DELETE ON portal_westy_reply_feedback FOR EACH ROW
BEGIN
 IF OLD.expires_at>UTC_TIMESTAMP() AND NOT EXISTS(SELECT 1 FROM portal_westy_turns
   WHERE id=OLD.turn_id AND input_text IS NULL AND reply_json IS NULL)
 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Feedback follows conversation retention or exact erasure'; END IF;
END$$
DELIMITER ;

-- Exact structural postflight also rejects drift on a replay; no trigger is replaced.
SET SESSION group_concat_max_len=1048576;
DROP TEMPORARY TABLE IF EXISTS portal_feedback_schema_assert;
CREATE TEMPORARY TABLE portal_feedback_schema_assert(ok TINYINT NOT NULL CHECK(ok=1));
INSERT INTO portal_feedback_schema_assert(ok) SELECT IF(
 (SELECT COUNT(*)=16 AND SHA2(COALESCE(GROUP_CONCAT(JSON_ARRAY(COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,GENERATION_EXPRESSION) ORDER BY ORDINAL_POSITION SEPARATOR '|'),''),256)='ac99015a89694a3603f1102cd009ca9fdd50d69c92bb9fde5e61db7186e95410' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='portal_westy_reply_feedback')
 AND (SELECT COUNT(*)=12 AND SHA2(COALESCE(GROUP_CONCAT(JSON_ARRAY(INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,COLLATION,SUB_PART,NULLABLE,INDEX_TYPE,EXPRESSION,IS_VISIBLE) ORDER BY INDEX_NAME,SEQ_IN_INDEX SEPARATOR '|'),''),256)='17624c1480716059edacee3eb5bab24ffb52cff08d4552fb185c66bf6420787d' FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='portal_westy_reply_feedback')
 AND (SELECT COUNT(*)=4 AND SHA2(COALESCE(GROUP_CONCAT(JSON_ARRAY(CONSTRAINT_NAME,COLUMN_NAME,ORDINAL_POSITION,POSITION_IN_UNIQUE_CONSTRAINT,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME) ORDER BY CONSTRAINT_NAME,ORDINAL_POSITION SEPARATOR '|'),''),256)='26fdc7df6ced1003654d1fb8d0caa87ef77361a1af15e49cb1ed62a39d13d849' FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='portal_westy_reply_feedback' AND REFERENCED_TABLE_NAME IS NOT NULL)
 AND (SELECT COUNT(*)=2 AND SHA2(COALESCE(GROUP_CONCAT(JSON_ARRAY(CONSTRAINT_NAME,UNIQUE_CONSTRAINT_NAME,MATCH_OPTION,UPDATE_RULE,DELETE_RULE,REFERENCED_TABLE_NAME) ORDER BY CONSTRAINT_NAME SEPARATOR '|'),''),256)='46f5cb7b407cf2ae686b25d814260de8af1052993a2b964bff30f9bb5d6ead11' FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='portal_westy_reply_feedback')
 AND (SELECT COUNT(*)=1 AND SHA2(COALESCE(GROUP_CONCAT(JSON_ARRAY(ENGINE,TABLE_COLLATION,ROW_FORMAT,CREATE_OPTIONS) ORDER BY TABLE_NAME SEPARATOR '|'),''),256)='6072f8f4c21c155d166cdf9998bcfa298a32aa02a1426ed3178280659b5fa701' FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='portal_westy_reply_feedback')
 AND (SELECT COUNT(*)=2 AND SHA2(COALESCE(GROUP_CONCAT(JSON_ARRAY(t.CONSTRAINT_NAME,t.ENFORCED,c.CHECK_CLAUSE) ORDER BY t.CONSTRAINT_NAME SEPARATOR '|'),''),256)='27c3e063cf468ea06bb08f01c9a96e4598655ba71662ccfde716732b588a24d8' FROM information_schema.TABLE_CONSTRAINTS t JOIN information_schema.CHECK_CONSTRAINTS c ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME='portal_westy_reply_feedback' AND t.CONSTRAINT_TYPE='CHECK')
 AND (SELECT COUNT(*)=3 AND SUM(TRIGGER_NAME='portal_feedback_erasure' AND EVENT_MANIPULATION='DELETE' AND ACTION_TIMING='BEFORE' AND SHA2(ACTION_STATEMENT,256)='e3646b5dba02cb5571dcfd94326756ea60e64450244db92936f5b416ffed4a0e')=1 AND SUM(TRIGGER_NAME='portal_feedback_immutable' AND EVENT_MANIPULATION='UPDATE' AND ACTION_TIMING='BEFORE' AND SHA2(ACTION_STATEMENT,256)='9a582febdcba5a6b8275531fbbbc9903985fb03c84cd703994d37dfca1bd3cf2')=1 AND SUM(TRIGGER_NAME='portal_feedback_scope' AND EVENT_MANIPULATION='INSERT' AND ACTION_TIMING='BEFORE' AND SHA2(ACTION_STATEMENT,256)='435797c33a0ae0ca43df9ef705f1c2c580459148926571ff370d9a8ba88564ab')=1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='portal_westy_reply_feedback'),1,0);
DROP TEMPORARY TABLE portal_feedback_schema_assert;
