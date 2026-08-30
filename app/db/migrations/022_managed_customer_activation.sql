-- 022_managed_customer_activation.sql -- immutable, redacted completion proof
-- for atomic Safeharbor managed-customer portal/report activation.
--
-- Additive and inert: creates no activation row, portal binding, report
-- definition/schedule, identity binding, email, ticket, time, or financial fact.
SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET SESSION group_concat_max_len = 16384;

CREATE TABLE IF NOT EXISTS managed_customer_activation_receipts (
  id                           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id                    INT UNSIGNED NOT NULL,
  client_id                    INT UNSIGNED NOT NULL,
  source_binding_id            BIGINT UNSIGNED NOT NULL,
  customer_id                  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_version               BIGINT UNSIGNED NOT NULL,
  customer_receipt_id          CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  id_tenant_key                VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  identity_tenant_slug         VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  contact_version              BIGINT UNSIGNED NOT NULL,
  portal_binding_id            INT UNSIGNED NOT NULL,
  schedule_key                 VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  prepared_schedule_version_id INT UNSIGNED NOT NULL,
  active_schedule_version_id   INT UNSIGNED NOT NULL,
  actor_user_id                INT UNSIGNED NOT NULL,
  id_response_sha256           CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  recipient_sha256             CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  evidence_sha256              CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at                   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mc_activation_customer (customer_id),
  UNIQUE KEY uq_mc_activation_client (tenant_id, client_id),
  UNIQUE KEY uq_mc_activation_schedule (tenant_id, schedule_key),
  UNIQUE KEY uq_mc_activation_tenant_id (tenant_id, id),
  KEY ix_mc_activation_source (tenant_id, source_binding_id),
  KEY ix_mc_activation_actor (tenant_id, actor_user_id, created_at),
  KEY ix_mc_activation_id_binding (tenant_id, client_id, id_tenant_key),
  KEY ix_mc_activation_portal (tenant_id, client_id, portal_binding_id),
  KEY ix_mc_activation_prepared_schedule (tenant_id, prepared_schedule_version_id),
  KEY ix_mc_activation_active_schedule (tenant_id, active_schedule_version_id),
  CONSTRAINT fk_mc_activation_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_mc_activation_client
    FOREIGN KEY (tenant_id, client_id) REFERENCES clients (tenant_id, id),
  CONSTRAINT fk_mc_activation_source
    FOREIGN KEY (tenant_id, source_binding_id)
    REFERENCES suite_customer_sync_bindings (tenant_id, id),
  CONSTRAINT fk_mc_activation_id_binding
    FOREIGN KEY (tenant_id, client_id, id_tenant_key)
    REFERENCES business_report_id_client_bindings (tenant_id, client_id, id_tenant_key),
  CONSTRAINT fk_mc_activation_portal
    FOREIGN KEY (tenant_id, client_id, portal_binding_id)
    REFERENCES customer_portal_bindings (tenant_id, client_id, id),
  CONSTRAINT fk_mc_activation_prepared_schedule
    FOREIGN KEY (tenant_id, prepared_schedule_version_id)
    REFERENCES business_report_schedule_versions (tenant_id, id),
  CONSTRAINT fk_mc_activation_active_schedule
    FOREIGN KEY (tenant_id, active_schedule_version_id)
    REFERENCES business_report_schedule_versions (tenant_id, id),
  CONSTRAINT fk_mc_activation_actor
    FOREIGN KEY (tenant_id, actor_user_id) REFERENCES users (tenant_id, id),
  CONSTRAINT ck_mc_activation_customer CHECK (
    REGEXP_LIKE(customer_id, _ascii'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')
    AND customer_id <> _ascii'4ebaeefa-b101-47f8-ac76-e49ab309d272'
  ) ENFORCED,
  CONSTRAINT ck_mc_activation_source_version CHECK (source_version >= 1) ENFORCED,
  CONSTRAINT ck_mc_activation_customer_receipt CHECK (
    REGEXP_LIKE(customer_receipt_id, _ascii'^[0-9a-f]{64}$')
  ) ENFORCED,
  CONSTRAINT ck_mc_activation_contact_version CHECK (contact_version >= 1) ENFORCED,
  CONSTRAINT ck_mc_activation_schedule_key CHECK (
    BINARY schedule_key = BINARY CONCAT(_ascii'managed-weekly:', customer_id)
  ) ENFORCED,
  CONSTRAINT ck_mc_activation_id_response_hash CHECK (
    REGEXP_LIKE(id_response_sha256, _ascii'^[0-9a-f]{64}$')
  ) ENFORCED,
  CONSTRAINT ck_mc_activation_recipient_hash CHECK (
    REGEXP_LIKE(recipient_sha256, _ascii'^[0-9a-f]{64}$')
  ) ENFORCED,
  CONSTRAINT ck_mc_activation_evidence_hash CHECK (
    REGEXP_LIKE(evidence_sha256, _ascii'^[0-9a-f]{64}$')
  ) ENFORCED,
  -- Fresh installs cannot accept a receipt in the DDL implicit-commit window.
  -- Removed only after all three permanent receipt guards verify exactly.
  CONSTRAINT ck_mc_activation_install_lock CHECK (0 = 1) ENFORCED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- CREATE TABLE IF NOT EXISTS must not accept a same-named drifted object.
SET @mc_activation_table_ok = (
  SELECT COUNT(*) = 1
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name = 'managed_customer_activation_receipts'
     AND engine = 'InnoDB'
);
SET @mc_activation_columns_ok = (
  SELECT COUNT(*) = 19
     AND SUM(column_name = 'id' AND column_type = 'bigint unsigned'
             AND is_nullable = 'NO' AND extra = 'auto_increment') = 1
     AND SUM(column_name = 'tenant_id' AND column_type = 'int unsigned'
             AND is_nullable = 'NO') = 1
     AND SUM(column_name = 'client_id' AND column_type = 'int unsigned'
             AND is_nullable = 'NO') = 1
     AND SUM(column_name = 'source_binding_id' AND column_type = 'bigint unsigned'
             AND is_nullable = 'NO') = 1
     AND SUM(column_name = 'customer_id' AND column_type = 'char(36)'
             AND character_set_name = 'ascii' AND collation_name = 'ascii_bin'
             AND is_nullable = 'NO') = 1
      AND SUM(column_name = 'source_version' AND column_type = 'bigint unsigned'
              AND is_nullable = 'NO') = 1
      AND SUM(column_name = 'customer_receipt_id' AND column_type = 'char(64)'
              AND character_set_name = 'ascii' AND collation_name = 'ascii_bin'
              AND is_nullable = 'NO') = 1
      AND SUM(column_name = 'id_tenant_key' AND column_type = 'varchar(32)'
             AND character_set_name = 'ascii' AND collation_name = 'ascii_bin'
             AND is_nullable = 'NO') = 1
     AND SUM(column_name = 'identity_tenant_slug' AND column_type = 'varchar(64)'
             AND character_set_name = 'ascii' AND collation_name = 'ascii_bin'
             AND is_nullable = 'NO') = 1
      AND SUM(column_name = 'contact_version'
              AND column_type = 'bigint unsigned' AND is_nullable = 'NO') = 1
     AND SUM(column_name IN ('portal_binding_id','prepared_schedule_version_id',
                             'active_schedule_version_id','actor_user_id')
             AND column_type = 'int unsigned' AND is_nullable = 'NO') = 4
     AND SUM(column_name = 'schedule_key' AND column_type = 'varchar(64)'
             AND character_set_name = 'ascii' AND collation_name = 'ascii_bin'
             AND is_nullable = 'NO') = 1
      AND SUM(column_name IN ('customer_receipt_id','id_response_sha256',
                              'recipient_sha256','evidence_sha256')
              AND column_type = 'char(64)' AND character_set_name = 'ascii'
              AND collation_name = 'ascii_bin' AND is_nullable = 'NO') = 4
     AND SUM(column_name = 'created_at' AND column_type = 'datetime'
             AND is_nullable = 'NO') = 1
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'managed_customer_activation_receipts'
);
SET @mc_activation_indexes_ok = (
  SELECT COUNT(*) = 11
     AND SUM(index_name = 'PRIMARY' AND non_unique = 0 AND columns_csv = 'id') = 1
     AND SUM(index_name = 'uq_mc_activation_customer' AND non_unique = 0
             AND columns_csv = 'customer_id') = 1
     AND SUM(index_name = 'uq_mc_activation_client' AND non_unique = 0
             AND columns_csv = 'tenant_id,client_id') = 1
     AND SUM(index_name = 'uq_mc_activation_schedule' AND non_unique = 0
             AND columns_csv = 'tenant_id,schedule_key') = 1
     AND SUM(index_name = 'uq_mc_activation_tenant_id' AND non_unique = 0
             AND columns_csv = 'tenant_id,id') = 1
     AND SUM(index_name = 'ix_mc_activation_source' AND non_unique = 1
             AND columns_csv = 'tenant_id,source_binding_id') = 1
     AND SUM(index_name = 'ix_mc_activation_actor' AND non_unique = 1
             AND columns_csv = 'tenant_id,actor_user_id,created_at') = 1
     AND SUM(index_name = 'ix_mc_activation_id_binding' AND non_unique = 1
             AND columns_csv = 'tenant_id,client_id,id_tenant_key') = 1
     AND SUM(index_name = 'ix_mc_activation_portal' AND non_unique = 1
             AND columns_csv = 'tenant_id,client_id,portal_binding_id') = 1
     AND SUM(index_name = 'ix_mc_activation_prepared_schedule' AND non_unique = 1
             AND columns_csv = 'tenant_id,prepared_schedule_version_id') = 1
     AND SUM(index_name = 'ix_mc_activation_active_schedule' AND non_unique = 1
             AND columns_csv = 'tenant_id,active_schedule_version_id') = 1
    FROM (
      SELECT index_name, MIN(non_unique) AS non_unique,
             GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_csv
        FROM information_schema.statistics
       WHERE table_schema = DATABASE()
         AND table_name = 'managed_customer_activation_receipts'
       GROUP BY index_name
    ) exact_indexes
);
SET @mc_activation_fks_ok = (
  SELECT COUNT(*) = 8
     AND SUM(constraint_name = 'fk_mc_activation_tenant'
             AND referenced_table_name = 'tenants'
             AND columns_csv = 'tenant_id=id') = 1
     AND SUM(constraint_name = 'fk_mc_activation_client'
             AND referenced_table_name = 'clients'
             AND columns_csv = 'tenant_id=tenant_id,client_id=id') = 1
     AND SUM(constraint_name = 'fk_mc_activation_source'
             AND referenced_table_name = 'suite_customer_sync_bindings'
             AND columns_csv = 'tenant_id=tenant_id,source_binding_id=id') = 1
     AND SUM(constraint_name = 'fk_mc_activation_id_binding'
             AND referenced_table_name = 'business_report_id_client_bindings'
             AND columns_csv = 'tenant_id=tenant_id,client_id=client_id,id_tenant_key=id_tenant_key') = 1
     AND SUM(constraint_name = 'fk_mc_activation_portal'
             AND referenced_table_name = 'customer_portal_bindings'
             AND columns_csv = 'tenant_id=tenant_id,client_id=client_id,portal_binding_id=id') = 1
     AND SUM(constraint_name = 'fk_mc_activation_prepared_schedule'
             AND referenced_table_name = 'business_report_schedule_versions'
             AND columns_csv = 'tenant_id=tenant_id,prepared_schedule_version_id=id') = 1
     AND SUM(constraint_name = 'fk_mc_activation_active_schedule'
             AND referenced_table_name = 'business_report_schedule_versions'
             AND columns_csv = 'tenant_id=tenant_id,active_schedule_version_id=id') = 1
     AND SUM(constraint_name = 'fk_mc_activation_actor'
             AND referenced_table_name = 'users'
             AND columns_csv = 'tenant_id=tenant_id,actor_user_id=id') = 1
    FROM (
      SELECT constraint_name, MIN(referenced_table_name) AS referenced_table_name,
             GROUP_CONCAT(CONCAT(column_name, '=', referenced_column_name)
                          ORDER BY ordinal_position) AS columns_csv
        FROM information_schema.key_column_usage
       WHERE table_schema = DATABASE()
         AND table_name = 'managed_customer_activation_receipts'
         AND referenced_table_name IS NOT NULL
       GROUP BY constraint_name
    ) exact_fks
);
SET @mc_activation_checks_ok = (
  SELECT COUNT(*) IN (8, 9)
     AND SUM(constraints_table.enforced = 'YES') = COUNT(*)
     AND SUM(constraints_table.constraint_name IN (
       'ck_mc_activation_customer','ck_mc_activation_source_version',
       'ck_mc_activation_customer_receipt','ck_mc_activation_contact_version',
       'ck_mc_activation_schedule_key','ck_mc_activation_id_response_hash',
       'ck_mc_activation_recipient_hash','ck_mc_activation_evidence_hash'
     )) = 8
     AND SUM(constraints_table.constraint_name = 'ck_mc_activation_install_lock')
         = COUNT(*) - 8
    FROM information_schema.check_constraints checks_table
    JOIN information_schema.table_constraints constraints_table
      ON constraints_table.constraint_schema = checks_table.constraint_schema
     AND constraints_table.constraint_name = checks_table.constraint_name
   WHERE constraints_table.constraint_schema = DATABASE()
      AND constraints_table.table_name = 'managed_customer_activation_receipts'
      AND constraints_table.constraint_type = 'CHECK'
);
SET @mc_activation_preflight_sql = IF(
  @mc_activation_table_ok = 1
  AND @mc_activation_columns_ok = 1
  AND @mc_activation_indexes_ok = 1
  AND @mc_activation_fks_ok = 1
  AND @mc_activation_checks_ok = 1,
  'DO 0',
  'SELECT * FROM information_schema.migration_022_managed_customer_activation_preflight_failed'
);
PREPARE mc_activation_statement FROM @mc_activation_preflight_sql;
EXECUTE mc_activation_statement;
DEALLOCATE PREPARE mc_activation_statement;

-- Prove trigger privilege, then put all three write classes behind temporary
-- fail-closed blockers before replacing any permanent receipt guard.
DROP TRIGGER IF EXISTS trg_mc_activation_privilege_preflight;
CREATE TRIGGER trg_mc_activation_privilege_preflight
BEFORE INSERT ON managed_customer_activation_receipts
FOR EACH ROW SET @mc_activation_trigger_privilege_preflight = 1;
DROP TRIGGER trg_mc_activation_privilege_preflight;

DELIMITER $$
CREATE TRIGGER IF NOT EXISTS trg_mc_activation_swap_insert
BEFORE INSERT ON managed_customer_activation_receipts
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'managed customer activation migration is incomplete';
END$$
CREATE TRIGGER IF NOT EXISTS trg_mc_activation_swap_update
BEFORE UPDATE ON managed_customer_activation_receipts
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'managed customer activation migration is incomplete';
END$$
CREATE TRIGGER IF NOT EXISTS trg_mc_activation_swap_delete
BEFORE DELETE ON managed_customer_activation_receipts
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'managed customer activation migration is incomplete';
END$$
DELIMITER ;

SET @mc_activation_swaps_ok = (
  SELECT COUNT(*) = 3
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND action_timing = 'BEFORE'
     AND event_object_table = 'managed_customer_activation_receipts'
     AND trigger_name IN ('trg_mc_activation_swap_insert','trg_mc_activation_swap_update',
                          'trg_mc_activation_swap_delete')
     AND action_statement LIKE '%managed customer activation migration is incomplete%'
);
SET @mc_activation_swap_sql = IF(
  @mc_activation_swaps_ok = 1,
  'DO 0',
  'SELECT * FROM information_schema.migration_022_activation_swap_guard_failed'
);
PREPARE mc_activation_statement FROM @mc_activation_swap_sql;
EXECUTE mc_activation_statement;
DEALLOCATE PREPARE mc_activation_statement;

DROP TRIGGER IF EXISTS trg_mc_activation_before_insert;
DROP TRIGGER IF EXISTS trg_mc_activation_no_update;
DROP TRIGGER IF EXISTS trg_mc_activation_no_delete;

DELIMITER $$
CREATE TRIGGER trg_mc_activation_before_insert
BEFORE INSERT ON managed_customer_activation_receipts
FOR EACH ROW
BEGIN
  DECLARE source_ok INT DEFAULT 0;
  DECLARE actor_ok INT DEFAULT 0;
  DECLARE id_binding_ok INT DEFAULT 0;
  DECLARE portal_ok INT DEFAULT 0;
  DECLARE schedule_ok INT DEFAULT 0;
  DECLARE evidence_ok INT DEFAULT 0;
  DECLARE guard_version VARCHAR(64) DEFAULT 'safeharbor-managed-customer-activation-receipt-v1';

  SELECT COUNT(*) INTO source_ok
    FROM suite_customer_sync_bindings binding
    JOIN clients client
      ON client.tenant_id = binding.tenant_id
     AND client.id = binding.client_id
   WHERE binding.tenant_id = NEW.tenant_id
     AND binding.id = NEW.source_binding_id
     AND binding.client_id = NEW.client_id
     AND BINARY binding.customer_id = BINARY NEW.customer_id
     AND binding.source_version = NEW.source_version
     AND binding.status = 'active';
  IF source_ok <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activation receipt requires the exact active Milepost binding';
  END IF;

  SELECT COUNT(*) INTO actor_ok
    FROM users actor
   WHERE actor.tenant_id = NEW.tenant_id
     AND actor.id = NEW.actor_user_id
     AND actor.is_active = 1
     AND actor.role IN ('owner','admin');
  IF actor_ok <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activation receipt actor must be an active owner or admin';
  END IF;

  SELECT COUNT(*) INTO id_binding_ok
    FROM business_report_id_client_bindings id_binding
   WHERE id_binding.tenant_id = NEW.tenant_id
     AND id_binding.client_id = NEW.client_id
     AND BINARY id_binding.id_tenant_key = BINARY NEW.id_tenant_key
     AND BINARY id_binding.id_tenant_slug = BINARY NEW.identity_tenant_slug;
  IF id_binding_ok <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activation receipt ID binding does not match';
  END IF;

  SELECT COUNT(*) INTO portal_ok
    FROM customer_portal_bindings portal
   WHERE portal.tenant_id = NEW.tenant_id
     AND portal.client_id = NEW.client_id
     AND portal.id = NEW.portal_binding_id
     AND BINARY portal.identity_tenant_slug = BINARY NEW.identity_tenant_slug
     AND portal.status = 'active';
  IF portal_ok <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activation receipt portal binding is not exact and active';
  END IF;

  SELECT COUNT(*) INTO schedule_ok
    FROM business_report_schedule_versions prepared
    JOIN business_report_schedule_versions active
      ON active.tenant_id = prepared.tenant_id
     AND BINARY active.schedule_key = BINARY prepared.schedule_key
     AND active.version_no = prepared.version_no + 1
     AND active.definition_version_id = prepared.definition_version_id
     AND active.client_id = prepared.client_id
     AND BINARY active.recipient_email = BINARY prepared.recipient_email
     AND BINARY active.schedule_timezone = BINARY prepared.schedule_timezone
     AND active.delivery_weekday = prepared.delivery_weekday
     AND active.delivery_local_time = prepared.delivery_local_time
     AND active.canary = prepared.canary
    JOIN business_report_definition_versions definition
      ON definition.tenant_id = prepared.tenant_id
     AND definition.id = prepared.definition_version_id
   WHERE prepared.tenant_id = NEW.tenant_id
     AND prepared.id = NEW.prepared_schedule_version_id
     AND active.id = NEW.active_schedule_version_id
     AND prepared.client_id = NEW.client_id
     AND BINARY prepared.schedule_key = BINARY NEW.schedule_key
     AND prepared.status = 'disabled'
     AND active.status = 'active'
     AND prepared.canary = 1
     AND definition.definition_key = 'weekly-client-service-summary'
     AND definition.version_no = 2;
  IF schedule_ok <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activation receipt schedule pair is not exact version 2';
  END IF;

  SELECT COUNT(*) INTO evidence_ok
    FROM business_report_id_client_contact_snapshots contact
    JOIN business_report_schedule_versions prepared
      ON prepared.tenant_id = contact.tenant_id
     AND prepared.id = contact.schedule_version_id
   WHERE contact.tenant_id = NEW.tenant_id
     AND contact.client_id = NEW.client_id
     AND contact.schedule_version_id = NEW.prepared_schedule_version_id
     AND BINARY contact.id_tenant_key = BINARY NEW.id_tenant_key
     AND contact.contact_version = NEW.contact_version
     AND BINARY contact.response_sha256 = BINARY NEW.id_response_sha256
     AND BINARY SHA2(contact.recipient_email, 256) = BINARY NEW.recipient_sha256
     AND BINARY prepared.schedule_key = BINARY NEW.schedule_key;
  IF evidence_ok <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activation receipt contact evidence is not exact';
  END IF;

  IF BINARY NEW.evidence_sha256 <> BINARY SHA2(CONCAT_WS('\n',
       'safeharbor-managed-customer-activation-v1',
       NEW.tenant_id, NEW.client_id, NEW.source_binding_id, NEW.customer_id,
       NEW.source_version, NEW.customer_receipt_id, NEW.id_tenant_key,
       NEW.identity_tenant_slug, NEW.contact_version, NEW.portal_binding_id,
       NEW.schedule_key, NEW.prepared_schedule_version_id,
       NEW.active_schedule_version_id, NEW.actor_user_id,
       NEW.id_response_sha256, NEW.recipient_sha256), 256) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activation receipt digest is invalid';
  END IF;
  SET NEW.created_at = UTC_TIMESTAMP();
END$$

CREATE TRIGGER trg_mc_activation_no_update
BEFORE UPDATE ON managed_customer_activation_receipts
FOR EACH ROW
BEGIN
  DECLARE guard_version VARCHAR(64) DEFAULT 'safeharbor-managed-customer-activation-immutable-v1';
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'managed customer activation receipts are immutable';
END$$

CREATE TRIGGER trg_mc_activation_no_delete
BEFORE DELETE ON managed_customer_activation_receipts
FOR EACH ROW
BEGIN
  DECLARE guard_version VARCHAR(64) DEFAULT 'safeharbor-managed-customer-activation-immutable-v1';
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'managed customer activation receipts are immutable';
END$$
DELIMITER ;

SET @mc_activation_permanent_ok = (
  SELECT COUNT(*) = 3
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND event_object_table = 'managed_customer_activation_receipts'
     AND action_timing = 'BEFORE'
     AND (
       (trigger_name = 'trg_mc_activation_before_insert'
        AND event_manipulation = 'INSERT'
        AND action_statement LIKE '%safeharbor-managed-customer-activation-receipt-v1%'
        AND action_statement LIKE '%suite_customer_sync_bindings%'
        AND action_statement LIKE '%business_report_id_client_contact_snapshots%')
       OR (trigger_name = 'trg_mc_activation_no_update'
        AND event_manipulation = 'UPDATE'
        AND action_statement LIKE '%safeharbor-managed-customer-activation-immutable-v1%')
       OR (trigger_name = 'trg_mc_activation_no_delete'
        AND event_manipulation = 'DELETE'
        AND action_statement LIKE '%safeharbor-managed-customer-activation-immutable-v1%')
      )
) AND (
  SELECT COUNT(*) = 6
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND event_object_table = 'managed_customer_activation_receipts'
     AND action_timing = 'BEFORE'
);
SET @mc_activation_permanent_sql = IF(
  @mc_activation_permanent_ok = 1,
  'DO 0',
  'SELECT * FROM information_schema.migration_022_activation_permanent_guard_failed'
);
PREPARE mc_activation_statement FROM @mc_activation_permanent_sql;
EXECUTE mc_activation_statement;
DEALLOCATE PREPARE mc_activation_statement;

DROP TRIGGER trg_mc_activation_swap_insert;
DROP TRIGGER trg_mc_activation_swap_update;
DROP TRIGGER trg_mc_activation_swap_delete;

SET @mc_activation_install_lock_ddl = IF(
  (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE()
      AND table_name = 'managed_customer_activation_receipts'
      AND constraint_name = 'ck_mc_activation_install_lock'
      AND constraint_type = 'CHECK') = 1,
  'ALTER TABLE managed_customer_activation_receipts DROP CHECK ck_mc_activation_install_lock',
  'DO 0'
);
PREPARE mc_activation_statement FROM @mc_activation_install_lock_ddl;
EXECUTE mc_activation_statement;
DEALLOCATE PREPARE mc_activation_statement;

SELECT
  (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'managed_customer_activation_receipts') AS receipt_table_count,
  (SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema = DATABASE()
      AND event_object_table = 'managed_customer_activation_receipts'
      AND trigger_name IN ('trg_mc_activation_before_insert',
                           'trg_mc_activation_no_update',
                           'trg_mc_activation_no_delete')) AS permanent_guard_count,
  (SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema = DATABASE()
      AND trigger_name LIKE 'trg_mc_activation_swap_%') AS temporary_guard_count,
  (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE()
      AND table_name = 'managed_customer_activation_receipts'
      AND constraint_name = 'ck_mc_activation_install_lock') AS install_lock_count,
  (SELECT COUNT(*) FROM managed_customer_activation_receipts) AS activation_receipt_count;
