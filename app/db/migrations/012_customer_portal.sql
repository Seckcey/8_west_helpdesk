-- 012_customer_portal.sql — dark, explicitly mapped customer portal boundary.
-- Additive upgrade for the reviewed production target: MySQL 8 / utf8mb4 / InnoDB.
--
-- This migration creates no mapping and enables no portal. Operator mappings
-- are prepared disabled, inspected, and only then explicitly enabled through
-- db/manage_portal_client.php. Identity tenant slugs never infer a provider
-- tenant/client from email, domain, name, source_key, or numeric ID claims.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS customer_portal_bindings (
  id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  identity_tenant_slug     VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  tenant_id                INT UNSIGNED NOT NULL,
  client_id                INT UNSIGNED NOT NULL,
  status                   ENUM('disabled','active') NOT NULL DEFAULT 'disabled',
  prepared_by_user_id      INT UNSIGNED NOT NULL,
  prepared_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_changed_by_user_id  INT UNSIGNED NOT NULL,
  status_changed_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status_reason            VARCHAR(500) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customer_portal_identity_slug (identity_tenant_slug),
  UNIQUE KEY uq_customer_portal_client (tenant_id, client_id),
  UNIQUE KEY uq_customer_portal_binding_scope (tenant_id, client_id, id),
  KEY ix_customer_portal_prepared_by (tenant_id, prepared_by_user_id),
  KEY ix_customer_portal_changed_by (tenant_id, last_changed_by_user_id),
  CONSTRAINT ck_customer_portal_slug CHECK (
    identity_tenant_slug REGEXP '^[a-z0-9][a-z0-9-]{0,63}$'
    AND identity_tenant_slug NOT IN ('8west','internal')
  ),
  CONSTRAINT fk_customer_portal_tenant FOREIGN KEY (tenant_id)
    REFERENCES tenants (id),
  CONSTRAINT fk_customer_portal_client FOREIGN KEY (tenant_id, client_id)
    REFERENCES clients (tenant_id, id),
  CONSTRAINT fk_customer_portal_prepared_by FOREIGN KEY (tenant_id, prepared_by_user_id)
    REFERENCES users (tenant_id, id),
  CONSTRAINT fk_customer_portal_changed_by FOREIGN KEY (tenant_id, last_changed_by_user_id)
    REFERENCES users (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS customer_portal_binding_events (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id      INT UNSIGNED NOT NULL,
  client_id      INT UNSIGNED NOT NULL,
  binding_id     INT UNSIGNED NOT NULL,
  actor_user_id  INT UNSIGNED NOT NULL,
  event_kind     ENUM('prepared','enabled','disabled') NOT NULL,
  from_status    ENUM('disabled','active') NULL,
  to_status      ENUM('disabled','active') NOT NULL,
  reason         VARCHAR(500) NOT NULL,
  snapshot_json  JSON NOT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_customer_portal_events_binding (tenant_id, client_id, binding_id, id),
  KEY ix_customer_portal_events_actor (tenant_id, actor_user_id, created_at),
  CONSTRAINT fk_customer_portal_event_tenant FOREIGN KEY (tenant_id)
    REFERENCES tenants (id),
  CONSTRAINT fk_customer_portal_event_binding FOREIGN KEY (tenant_id, client_id, binding_id)
    REFERENCES customer_portal_bindings (tenant_id, client_id, id),
  CONSTRAINT fk_customer_portal_event_actor FOREIGN KEY (tenant_id, actor_user_id)
    REFERENCES users (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Refuse to treat a same-named, incompatible table as a safe replay.
SET @portal_binding_shape_ok = (
  SELECT COUNT(*) = 10
     AND SUM(column_name = 'id' AND column_type = 'int unsigned' AND column_key = 'PRI') = 1
     AND SUM(column_name = 'identity_tenant_slug' AND column_type = 'varchar(64)'
             AND character_set_name = 'ascii' AND collation_name = 'ascii_bin') = 1
     AND SUM(column_name = 'tenant_id' AND column_type = 'int unsigned') = 1
     AND SUM(column_name = 'client_id' AND column_type = 'int unsigned') = 1
     AND SUM(column_name = 'status' AND column_type = 'enum(''disabled'',''active'')') = 1
     AND SUM(column_name = 'prepared_by_user_id' AND column_type = 'int unsigned') = 1
     AND SUM(column_name = 'prepared_at' AND column_type = 'datetime') = 1
     AND SUM(column_name = 'last_changed_by_user_id' AND column_type = 'int unsigned') = 1
     AND SUM(column_name = 'status_changed_at' AND column_type = 'datetime') = 1
     AND SUM(column_name = 'status_reason' AND column_type = 'varchar(500)') = 1
    FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'customer_portal_bindings'
);
SET @portal_event_shape_ok = (
  SELECT COUNT(*) = 11
     AND SUM(column_name = 'id' AND column_type = 'bigint unsigned' AND column_key = 'PRI') = 1
     AND SUM(column_name = 'tenant_id' AND column_type = 'int unsigned') = 1
     AND SUM(column_name = 'client_id' AND column_type = 'int unsigned') = 1
     AND SUM(column_name = 'binding_id' AND column_type = 'int unsigned') = 1
     AND SUM(column_name = 'actor_user_id' AND column_type = 'int unsigned') = 1
     AND SUM(column_name = 'event_kind' AND column_type = 'enum(''prepared'',''enabled'',''disabled'')') = 1
     AND SUM(column_name = 'from_status' AND column_type = 'enum(''disabled'',''active'')') = 1
     AND SUM(column_name = 'to_status' AND column_type = 'enum(''disabled'',''active'')') = 1
     AND SUM(column_name = 'reason' AND column_type = 'varchar(500)') = 1
     AND SUM(column_name = 'snapshot_json' AND data_type = 'json') = 1
     AND SUM(column_name = 'created_at' AND column_type = 'datetime') = 1
    FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'customer_portal_binding_events'
);
SET @portal_binding_indexes_ok = (
  SELECT COUNT(*) = 6
     AND SUM(prefix_parts) = 0
     AND SUM(index_name = 'PRIMARY' AND non_unique = 0
             AND index_type = 'BTREE' AND columns_csv = 'id') = 1
     AND SUM(index_name = 'uq_customer_portal_identity_slug' AND non_unique = 0
             AND index_type = 'BTREE' AND columns_csv = 'identity_tenant_slug') = 1
     AND SUM(index_name = 'uq_customer_portal_client' AND non_unique = 0
             AND index_type = 'BTREE' AND columns_csv = 'tenant_id,client_id') = 1
     AND SUM(index_name = 'uq_customer_portal_binding_scope' AND non_unique = 0
             AND index_type = 'BTREE' AND columns_csv = 'tenant_id,client_id,id') = 1
     AND SUM(index_name = 'ix_customer_portal_prepared_by' AND non_unique = 1
             AND index_type = 'BTREE' AND columns_csv = 'tenant_id,prepared_by_user_id') = 1
     AND SUM(index_name = 'ix_customer_portal_changed_by' AND non_unique = 1
             AND index_type = 'BTREE' AND columns_csv = 'tenant_id,last_changed_by_user_id') = 1
    FROM (
    SELECT index_name, MIN(non_unique) AS non_unique,
           MIN(index_type) AS index_type,
           GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_csv,
           SUM(sub_part IS NOT NULL) AS prefix_parts
      FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'customer_portal_bindings'
     GROUP BY index_name
  ) exact_indexes
);
SET @portal_event_indexes_ok = (
  SELECT COUNT(*) = 3
     AND SUM(prefix_parts) = 0
     AND SUM(index_name = 'PRIMARY' AND non_unique = 0
             AND index_type = 'BTREE' AND columns_csv = 'id') = 1
     AND SUM(index_name = 'ix_customer_portal_events_binding' AND non_unique = 1
             AND index_type = 'BTREE' AND columns_csv = 'tenant_id,client_id,binding_id,id') = 1
     AND SUM(index_name = 'ix_customer_portal_events_actor' AND non_unique = 1
             AND index_type = 'BTREE' AND columns_csv = 'tenant_id,actor_user_id,created_at') = 1
    FROM (
    SELECT index_name, MIN(non_unique) AS non_unique,
           MIN(index_type) AS index_type,
           GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_csv,
           SUM(sub_part IS NOT NULL) AS prefix_parts
      FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'customer_portal_binding_events'
     GROUP BY index_name
  ) exact_indexes
);
SET @portal_binding_fks_ok = (
  SELECT COUNT(*) = 4
     AND SUM(constraint_name = 'fk_customer_portal_tenant'
             AND referenced_table_name = 'tenants'
             AND columns_csv = 'tenant_id=id') = 1
     AND SUM(constraint_name = 'fk_customer_portal_client'
             AND referenced_table_name = 'clients'
             AND columns_csv = 'tenant_id=tenant_id,client_id=id') = 1
     AND SUM(constraint_name = 'fk_customer_portal_prepared_by'
             AND referenced_table_name = 'users'
             AND columns_csv = 'tenant_id=tenant_id,prepared_by_user_id=id') = 1
     AND SUM(constraint_name = 'fk_customer_portal_changed_by'
             AND referenced_table_name = 'users'
             AND columns_csv = 'tenant_id=tenant_id,last_changed_by_user_id=id') = 1
    FROM (
    SELECT constraint_name, MIN(referenced_table_name) AS referenced_table_name,
           GROUP_CONCAT(CONCAT(column_name, '=', referenced_column_name)
                        ORDER BY ordinal_position) AS columns_csv
      FROM information_schema.key_column_usage
     WHERE table_schema = DATABASE()
       AND table_name = 'customer_portal_bindings'
       AND referenced_table_name IS NOT NULL
     GROUP BY constraint_name
  ) exact_fks
);
SET @portal_event_fks_ok = (
  SELECT COUNT(*) = 3
     AND SUM(constraint_name = 'fk_customer_portal_event_tenant'
             AND referenced_table_name = 'tenants'
             AND columns_csv = 'tenant_id=id') = 1
     AND SUM(constraint_name = 'fk_customer_portal_event_binding'
             AND referenced_table_name = 'customer_portal_bindings'
             AND columns_csv = 'tenant_id=tenant_id,client_id=client_id,binding_id=id') = 1
     AND SUM(constraint_name = 'fk_customer_portal_event_actor'
             AND referenced_table_name = 'users'
             AND columns_csv = 'tenant_id=tenant_id,actor_user_id=id') = 1
    FROM (
    SELECT constraint_name, MIN(referenced_table_name) AS referenced_table_name,
           GROUP_CONCAT(CONCAT(column_name, '=', referenced_column_name)
                        ORDER BY ordinal_position) AS columns_csv
      FROM information_schema.key_column_usage
     WHERE table_schema = DATABASE()
       AND table_name = 'customer_portal_binding_events'
       AND referenced_table_name IS NOT NULL
     GROUP BY constraint_name
  ) exact_fks
);
SET @portal_ddl = IF(
  @portal_binding_shape_ok = 1 AND @portal_event_shape_ok = 1
  AND @portal_binding_indexes_ok = 1 AND @portal_event_indexes_ok = 1
  AND @portal_binding_fks_ok = 1 AND @portal_event_fks_ok = 1,
  'DO 0',
  'SELECT * FROM information_schema.migration_012_incompatible_portal_tables'
);
PREPARE portal_statement FROM @portal_ddl;
EXECUTE portal_statement;
DEALLOCATE PREPARE portal_statement;

-- Prove TRIGGER privilege before replacing any lifecycle/audit guard. A
-- replay by the DML-only web identity therefore fails before a guard drops.
DROP TRIGGER IF EXISTS trg_customer_portal_privilege_preflight;
CREATE TRIGGER trg_customer_portal_privilege_preflight
BEFORE INSERT ON customer_portal_binding_events
FOR EACH ROW
SET @customer_portal_trigger_privilege_preflight = 1;
DROP TRIGGER trg_customer_portal_privilege_preflight;

DROP TRIGGER IF EXISTS trg_customer_portal_binding_before_insert;
DROP TRIGGER IF EXISTS trg_customer_portal_binding_after_insert;
DROP TRIGGER IF EXISTS trg_customer_portal_binding_before_update;
DROP TRIGGER IF EXISTS trg_customer_portal_binding_after_update;
DROP TRIGGER IF EXISTS trg_customer_portal_binding_no_delete;
DROP TRIGGER IF EXISTS trg_customer_portal_events_no_update;
DROP TRIGGER IF EXISTS trg_customer_portal_events_no_delete;

DELIMITER $$
CREATE TRIGGER trg_customer_portal_binding_before_insert
BEFORE INSERT ON customer_portal_bindings
FOR EACH ROW
BEGIN
  DECLARE actor_is_authorized INT DEFAULT 0;

  IF NEW.identity_tenant_slug NOT REGEXP '^[a-z0-9][a-z0-9-]{0,63}$'
     OR NEW.identity_tenant_slug IN ('8west','internal') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer portal identity tenant slug is invalid or reserved';
  END IF;
  IF NEW.status <> 'disabled' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer portal bindings must be prepared disabled';
  END IF;
  IF NEW.prepared_by_user_id <> NEW.last_changed_by_user_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Prepared binding actor evidence is inconsistent';
  END IF;
  SET NEW.status_reason = TRIM(NEW.status_reason);
  IF NEW.status_reason = '' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Prepared binding requires a reason';
  END IF;

  SELECT COUNT(*) INTO actor_is_authorized
    FROM users u
    JOIN clients c ON c.tenant_id = u.tenant_id AND c.id = NEW.client_id
   WHERE u.tenant_id = NEW.tenant_id
     AND u.id = NEW.prepared_by_user_id
     AND u.is_active = 1
     AND u.role IN ('owner','admin');
  IF actor_is_authorized <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer portal binding actor must be an active owner or admin';
  END IF;

  SET NEW.prepared_at = UTC_TIMESTAMP();
  SET NEW.status_changed_at = NEW.prepared_at;
END$$

CREATE TRIGGER trg_customer_portal_binding_after_insert
AFTER INSERT ON customer_portal_bindings
FOR EACH ROW
BEGIN
  INSERT INTO customer_portal_binding_events
    (tenant_id, client_id, binding_id, actor_user_id, event_kind,
     from_status, to_status, reason, snapshot_json, created_at)
  VALUES
    (NEW.tenant_id, NEW.client_id, NEW.id, NEW.prepared_by_user_id, 'prepared',
     NULL, 'disabled', NEW.status_reason,
     JSON_OBJECT(
       'binding_id', NEW.id,
       'identity_tenant_slug', NEW.identity_tenant_slug,
       'tenant_id', NEW.tenant_id,
       'client_id', NEW.client_id,
       'status', NEW.status,
       'prepared_by_user_id', NEW.prepared_by_user_id,
       'prepared_at', NEW.prepared_at,
       'last_changed_by_user_id', NEW.last_changed_by_user_id,
       'status_changed_at', NEW.status_changed_at,
       'status_reason', NEW.status_reason
     ), NEW.status_changed_at);
END$$

CREATE TRIGGER trg_customer_portal_binding_before_update
BEFORE UPDATE ON customer_portal_bindings
FOR EACH ROW
BEGIN
  DECLARE actor_is_authorized INT DEFAULT 0;

  IF NOT (
       NEW.id <=> OLD.id
   AND NEW.identity_tenant_slug <=> OLD.identity_tenant_slug
   AND NEW.tenant_id <=> OLD.tenant_id
   AND NEW.client_id <=> OLD.client_id
   AND NEW.prepared_by_user_id <=> OLD.prepared_by_user_id
   AND NEW.prepared_at <=> OLD.prepared_at
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer portal binding facts are immutable';
  END IF;
  IF NEW.status = OLD.status OR NEW.status NOT IN ('disabled','active') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer portal binding status must make one explicit transition';
  END IF;
  SET NEW.status_reason = TRIM(NEW.status_reason);
  IF NEW.status_reason = '' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer portal binding transition requires a reason';
  END IF;

  SELECT COUNT(*) INTO actor_is_authorized
    FROM users u
    JOIN clients c ON c.tenant_id = u.tenant_id AND c.id = NEW.client_id
   WHERE u.tenant_id = NEW.tenant_id
     AND u.id = NEW.last_changed_by_user_id
     AND u.is_active = 1
     AND u.role IN ('owner','admin');
  IF actor_is_authorized <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer portal binding actor must be an active owner or admin';
  END IF;

  SET NEW.status_changed_at = UTC_TIMESTAMP();
END$$

CREATE TRIGGER trg_customer_portal_binding_after_update
AFTER UPDATE ON customer_portal_bindings
FOR EACH ROW
BEGIN
  INSERT INTO customer_portal_binding_events
    (tenant_id, client_id, binding_id, actor_user_id, event_kind,
     from_status, to_status, reason, snapshot_json, created_at)
  VALUES
    (NEW.tenant_id, NEW.client_id, NEW.id, NEW.last_changed_by_user_id,
     IF(NEW.status = 'active', 'enabled', 'disabled'),
     OLD.status, NEW.status, NEW.status_reason,
     JSON_OBJECT(
       'binding_id', NEW.id,
       'identity_tenant_slug', NEW.identity_tenant_slug,
       'tenant_id', NEW.tenant_id,
       'client_id', NEW.client_id,
       'status', NEW.status,
       'prepared_by_user_id', NEW.prepared_by_user_id,
       'prepared_at', NEW.prepared_at,
       'last_changed_by_user_id', NEW.last_changed_by_user_id,
       'status_changed_at', NEW.status_changed_at,
       'status_reason', NEW.status_reason
     ), NEW.status_changed_at);
END$$

CREATE TRIGGER trg_customer_portal_binding_no_delete
BEFORE DELETE ON customer_portal_bindings
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer portal bindings cannot be deleted';
END$$

CREATE TRIGGER trg_customer_portal_events_no_update
BEFORE UPDATE ON customer_portal_binding_events
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer portal binding events are immutable';
END$$

CREATE TRIGGER trg_customer_portal_events_no_delete
BEFORE DELETE ON customer_portal_binding_events
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer portal binding events are immutable';
END$$
DELIMITER ;
