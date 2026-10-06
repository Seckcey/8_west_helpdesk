-- Source-only additive migration. Apply only through the reviewed release procedure.
CREATE TABLE portal_westy_tool_runs (
 turn_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 tenant_id INT UNSIGNED NOT NULL,
 client_id INT UNSIGNED NOT NULL,
 scope_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 conversation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 operation_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 origin_channel VARCHAR(16) NOT NULL,
 origin_session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 companion_session CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 state VARCHAR(16) NOT NULL,
 sequence INT UNSIGNED NOT NULL DEFAULT 0,
 pending_json JSON NULL,
 replay_json JSON NULL,
 created_at DATETIME NOT NULL,
 expires_at DATETIME NOT NULL,
 UNIQUE KEY tool_run_operation(scope_key,operation_key),
 KEY tool_run_expiry(expires_at),
 CONSTRAINT tool_run_turn FOREIGN KEY(turn_id) REFERENCES portal_westy_turns(id),
 CONSTRAINT tool_run_state CHECK(state IN ('running','waiting','stopped','complete')),
 CONSTRAINT tool_run_origin CHECK(origin_channel IN ('portal','companion')),
 CONSTRAINT tool_run_sequence CHECK(sequence<=20)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

DELIMITER $$
CREATE TRIGGER portal_westy_tool_run_scope_insert BEFORE INSERT ON portal_westy_tool_runs FOR EACH ROW
BEGIN
 IF NOT EXISTS(SELECT 1 FROM portal_westy_turns t WHERE t.id=NEW.turn_id AND t.tenant_id=NEW.tenant_id
  AND t.client_id=NEW.client_id AND t.scope_key=NEW.scope_key AND t.conversation_key=NEW.conversation_id AND t.operation_key=NEW.operation_key)
  OR (NEW.origin_channel='companion' AND NEW.companion_session IS NULL)
  OR (NEW.origin_channel='portal' AND NEW.companion_session IS NOT NULL) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tool run scope mismatch';
 END IF;
END$$
CREATE TRIGGER portal_westy_tool_run_identity_update BEFORE UPDATE ON portal_westy_tool_runs FOR EACH ROW
BEGIN
 IF NOT(NEW.turn_id<=>OLD.turn_id) OR NOT(NEW.tenant_id<=>OLD.tenant_id) OR NOT(NEW.client_id<=>OLD.client_id)
  OR NOT(NEW.scope_key<=>OLD.scope_key) OR NOT(NEW.conversation_id<=>OLD.conversation_id) OR NOT(NEW.operation_key<=>OLD.operation_key)
  OR NOT(NEW.origin_channel<=>OLD.origin_channel) OR NOT(NEW.origin_session_hash<=>OLD.origin_session_hash)
  OR NOT(NEW.companion_session<=>OLD.companion_session) OR NOT(NEW.created_at<=>OLD.created_at) OR NOT(NEW.expires_at<=>OLD.expires_at)
  OR NEW.sequence<OLD.sequence THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tool run identity is immutable';
 END IF;
END$$
DELIMITER ;
