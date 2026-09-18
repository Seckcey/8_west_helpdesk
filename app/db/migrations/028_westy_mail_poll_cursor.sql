-- Dedicated Westy Graph poll progression.  This is not a generic mail cursor.
CREATE TABLE IF NOT EXISTS westy_mail_poll_cursors (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 tenant_id INT UNSIGNED NOT NULL,
 mail_identity_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 window_start_at DATETIME NOT NULL,
 window_end_at DATETIME NOT NULL,
 next_path VARCHAR(4096) CHARACTER SET ascii COLLATE ascii_bin NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_wmpc_identity (tenant_id,mail_identity_sha256),
 CONSTRAINT ck_wmpc_identity CHECK (mail_identity_sha256 REGEXP '^[0-9a-f]{64}$'),
 CONSTRAINT ck_wmpc_window CHECK (window_end_at >= window_start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS westy_mail_sent_reconciliations (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 owner_kind ENUM('conversation','auto') NOT NULL,
 owner_id BIGINT UNSIGNED NOT NULL,
 message_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 graph_message_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 recipient_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 subject_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_wmpsr_owner (owner_kind,owner_id),
 UNIQUE KEY uq_wmpsr_graph (graph_message_sha256),
 CONSTRAINT ck_wmpsr_hashes CHECK (message_key REGEXP '^[0-9a-f]{32}$' AND graph_message_sha256 REGEXP '^[0-9a-f]{64}$' AND recipient_sha256 REGEXP '^[0-9a-f]{64}$' AND subject_sha256 REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TRIGGER IF EXISTS trg_wmpsr_immutable_update;
CREATE TRIGGER trg_wmpsr_immutable_update BEFORE UPDATE ON westy_mail_sent_reconciliations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Westy mail reconciliation is immutable';
DROP TRIGGER IF EXISTS trg_wmpsr_immutable_delete;
CREATE TRIGGER trg_wmpsr_immutable_delete BEFORE DELETE ON westy_mail_sent_reconciliations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Westy mail reconciliation cannot be deleted';

CREATE TABLE IF NOT EXISTS westy_mail_attention_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 conversation_id BIGINT UNSIGNED NOT NULL,
 receipt_id BIGINT UNSIGNED NOT NULL,
 reason VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_wmpae_once (conversation_id,receipt_id,reason),
 CONSTRAINT fk_wmpae_conversation FOREIGN KEY (conversation_id) REFERENCES westy_mail_conversations(id),
 CONSTRAINT fk_wmpae_receipt FOREIGN KEY (receipt_id) REFERENCES westy_mail_inbound_receipts(id),
 CONSTRAINT ck_wmpae_reason CHECK (reason REGEXP '^[a-z0-9_]{3,64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TRIGGER IF EXISTS trg_wmpae_immutable_update;
CREATE TRIGGER trg_wmpae_immutable_update BEFORE UPDATE ON westy_mail_attention_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Westy mail attention is immutable';
DROP TRIGGER IF EXISTS trg_wmpae_immutable_delete;
CREATE TRIGGER trg_wmpae_immutable_delete BEFORE DELETE ON westy_mail_attention_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Westy mail attention cannot be deleted';

DROP FUNCTION IF EXISTS westy_mail_poll_schema_health;
CREATE FUNCTION westy_mail_poll_schema_health() RETURNS TINYINT READS SQL DATA SQL SECURITY DEFINER
RETURN (SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE()
 AND trigger_name IN ('trg_wmpsr_immutable_update','trg_wmpsr_immutable_delete','trg_wmpae_immutable_update','trg_wmpae_immutable_delete')
 AND action_statement LIKE '%SIGNAL SQLSTATE%')=4;
