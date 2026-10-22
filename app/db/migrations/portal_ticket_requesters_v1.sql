-- Additive; no historical requester inference. Apply only through the approved
-- protected deploy --migrate procedure with customer portal traffic held.
CREATE TABLE IF NOT EXISTS portal_ticket_requesters (
  ticket_id INT UNSIGNED NOT NULL,
  tenant_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  subject VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  identity_tenant_slug VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (ticket_id),
  KEY ix_portal_requester (tenant_id, client_id, subject, identity_tenant_slug),
  CONSTRAINT fk_portal_requester_ticket FOREIGN KEY (tenant_id, ticket_id) REFERENCES tickets (tenant_id, id),
  CONSTRAINT fk_portal_requester_client FOREIGN KEY (tenant_id, client_id) REFERENCES clients (tenant_id, id),
  CONSTRAINT ck_portal_requester_subject CHECK (REGEXP_LIKE(subject, _ascii'^t[1-9][0-9]*u[1-9][0-9]*$')),
  CONSTRAINT ck_portal_requester_slug CHECK (REGEXP_LIKE(identity_tenant_slug, _ascii'^[a-z0-9][a-z0-9-]{0,63}$'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Refuse an incompatible existing table before replacing any immutable guards.
SET @portal_requester_shape_ok = (
  SELECT COUNT(*) = 6 AND SUM(is_nullable = 'NO') = 6
    AND SUM(column_name = 'ticket_id' AND column_type = 'int unsigned' AND column_key = 'PRI') = 1
    AND SUM(column_name = 'tenant_id' AND column_type = 'int unsigned') = 1
    AND SUM(column_name = 'client_id' AND column_type = 'int unsigned') = 1
    AND SUM(column_name = 'subject' AND column_type = 'varchar(64)' AND collation_name = 'ascii_bin') = 1
    AND SUM(column_name = 'identity_tenant_slug' AND column_type = 'varchar(64)' AND collation_name = 'ascii_bin') = 1
    AND SUM(column_name = 'created_at' AND column_type = 'datetime') = 1
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'portal_ticket_requesters'
) AND (
  SELECT COUNT(*) = 1 FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'portal_ticket_requesters' AND engine = 'InnoDB'
) AND (
  SELECT COUNT(*) = 1 FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'portal_ticket_requesters'
    AND index_name = 'PRIMARY' AND column_name = 'ticket_id' AND non_unique = 0 AND sub_part IS NULL
) AND (
  SELECT COUNT(*) = 4
    AND SUM(constraint_name = 'fk_portal_requester_ticket' AND referenced_table_name = 'tickets'
      AND ((column_name = 'tenant_id' AND referenced_column_name = 'tenant_id')
        OR (column_name = 'ticket_id' AND referenced_column_name = 'id'))) = 2
    AND SUM(constraint_name = 'fk_portal_requester_client' AND referenced_table_name = 'clients'
      AND ((column_name = 'tenant_id' AND referenced_column_name = 'tenant_id')
        OR (column_name = 'client_id' AND referenced_column_name = 'id'))) = 2
  FROM information_schema.key_column_usage
  WHERE table_schema = DATABASE() AND table_name = 'portal_ticket_requesters' AND referenced_table_name IS NOT NULL
);
SET @portal_requester_guard_sql = IF(COALESCE(@portal_requester_shape_ok, 0) = 1,
  'DO 0', 'SELECT * FROM information_schema.portal_requester_schema_mismatch_stop');
PREPARE portal_requester_guard FROM @portal_requester_guard_sql;
EXECUTE portal_requester_guard;
DEALLOCATE PREPARE portal_requester_guard;

-- Prove trigger privilege before replacing guards on replay.
DROP TRIGGER IF EXISTS trg_portal_requester_preflight;
CREATE TRIGGER trg_portal_requester_preflight BEFORE INSERT ON portal_ticket_requesters
FOR EACH ROW SET @safeharbor_requester_preflight = 1;
DROP TRIGGER trg_portal_requester_preflight;
DELIMITER $$
DROP TRIGGER IF EXISTS trg_portal_requester_insert$$
CREATE TRIGGER trg_portal_requester_insert BEFORE INSERT ON portal_ticket_requesters
FOR EACH ROW
BEGIN
  IF NOT EXISTS (SELECT 1 FROM tickets WHERE id = NEW.ticket_id
      AND tenant_id = NEW.tenant_id AND client_id = NEW.client_id AND channel = 'portal') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Requester must match the portal ticket boundary';
  END IF;
END$$
DROP TRIGGER IF EXISTS trg_portal_requester_update$$
CREATE TRIGGER trg_portal_requester_update BEFORE UPDATE ON portal_ticket_requesters
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ticket requester attribution is immutable'$$
DROP TRIGGER IF EXISTS trg_portal_requester_delete$$
CREATE TRIGGER trg_portal_requester_delete BEFORE DELETE ON portal_ticket_requesters
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ticket requester attribution is immutable'$$
DELIMITER ;
