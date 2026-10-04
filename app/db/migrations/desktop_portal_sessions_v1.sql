-- Additive candidate; protected migration and canonical schema integration pending.
CREATE TABLE portal_desktop_handoffs (
 handoff_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 pairing_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 browser_session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 state VARCHAR(16) NOT NULL,
 identity_json JSON NULL,
 created_at DATETIME NOT NULL,
 expires_at DATETIME NOT NULL,
 UNIQUE KEY desktop_handoff_pair(pairing_id),
 KEY desktop_handoff_expiry(expires_at),
 CONSTRAINT desktop_handoff_state CHECK(state IN ('pending','approved','consumed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE portal_desktop_bindings (
 session_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 tenant_id INT UNSIGNED NOT NULL,
 client_id INT UNSIGNED NOT NULL,
 subject VARCHAR(64) NOT NULL,
 scope_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 conversation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 operation_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 origin_channel VARCHAR(16) NULL,
 origin_session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 task_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 expires_at DATETIME NOT NULL,
 KEY desktop_private_binding(tenant_id,client_id,scope_key,conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
