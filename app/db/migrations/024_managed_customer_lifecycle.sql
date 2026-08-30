-- 024_managed_customer_lifecycle.sql
-- Immutable evidence for physical containment of Milepost-managed customers.
-- Runtime access/report boundaries are read-only; this table records only the
-- exact portal/report surfaces disabled by the bounded lifecycle worker.

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET SESSION group_concat_max_len = 16384;

CREATE TABLE IF NOT EXISTS managed_customer_lifecycle_receipts (
  id                           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id                    INT UNSIGNED NOT NULL,
  client_id                    INT UNSIGNED NOT NULL,
  source_binding_id            BIGINT UNSIGNED NOT NULL,
  customer_id                  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_event_receipt_id      BIGINT UNSIGNED NOT NULL,
  source_event_id              CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_version               BIGINT UNSIGNED NOT NULL,
  source_status                ENUM('active','inactive') NOT NULL,
  action                       ENUM('contained','reactivation_blocked') NOT NULL,
  portal_binding_id            INT UNSIGNED NULL,
  portal_was_active            TINYINT(1) NOT NULL,
  portal_before_event_id       BIGINT UNSIGNED NULL,
  portal_state_event_id        BIGINT UNSIGNED NULL,
  portal_disabled_event_id     BIGINT UNSIGNED NULL,
  portal_state_sha256          CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  schedule_key                 VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  schedule_was_active          TINYINT(1) NOT NULL,
  schedule_active_version_id   INT UNSIGNED NULL,
  schedule_state_version_id    INT UNSIGNED NULL,
  schedule_disabled_version_id INT UNSIGNED NULL,
  schedule_state_sha256        CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  actor_user_id                INT UNSIGNED NOT NULL,
  source_request_sha256        CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  evidence_sha256              CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at                   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mc_lifecycle_customer_version_action (customer_id, source_version, action),
  UNIQUE KEY uq_mc_lifecycle_source_event_action (source_event_receipt_id, action),
  KEY ix_mc_lifecycle_scope (tenant_id, client_id, id),
  KEY ix_mc_lifecycle_source (tenant_id, source_binding_id, source_version),
  KEY ix_mc_lifecycle_source_event (tenant_id, source_event_receipt_id),
  KEY ix_mc_lifecycle_actor (tenant_id, actor_user_id, created_at),
  KEY ix_mc_lifecycle_portal_binding (tenant_id, client_id, portal_binding_id),
  KEY ix_mc_lifecycle_portal_before (portal_before_event_id),
  KEY ix_mc_lifecycle_portal_state (portal_state_event_id),
  KEY ix_mc_lifecycle_portal_disabled (portal_disabled_event_id),
  KEY ix_mc_lifecycle_schedule_active (tenant_id, schedule_active_version_id),
  KEY ix_mc_lifecycle_schedule_state (tenant_id, schedule_state_version_id),
  KEY ix_mc_lifecycle_schedule_disabled (tenant_id, schedule_disabled_version_id),
  CONSTRAINT fk_mc_lifecycle_client FOREIGN KEY (tenant_id, client_id)
    REFERENCES clients (tenant_id, id),
  CONSTRAINT fk_mc_lifecycle_source_binding FOREIGN KEY (tenant_id, source_binding_id)
    REFERENCES suite_customer_sync_bindings (tenant_id, id),
  CONSTRAINT fk_mc_lifecycle_source_event FOREIGN KEY (tenant_id, source_event_receipt_id)
    REFERENCES suite_customer_sync_events (tenant_id, id),
  CONSTRAINT fk_mc_lifecycle_portal_binding FOREIGN KEY (tenant_id, client_id, portal_binding_id)
    REFERENCES customer_portal_bindings (tenant_id, client_id, id),
  CONSTRAINT fk_mc_lifecycle_portal_before FOREIGN KEY (portal_before_event_id)
    REFERENCES customer_portal_binding_events (id),
  CONSTRAINT fk_mc_lifecycle_portal_state FOREIGN KEY (portal_state_event_id)
    REFERENCES customer_portal_binding_events (id),
  CONSTRAINT fk_mc_lifecycle_portal_disabled FOREIGN KEY (portal_disabled_event_id)
    REFERENCES customer_portal_binding_events (id),
  CONSTRAINT fk_mc_lifecycle_schedule_active FOREIGN KEY (tenant_id, schedule_active_version_id)
    REFERENCES business_report_schedule_versions (tenant_id, id),
  CONSTRAINT fk_mc_lifecycle_schedule_state FOREIGN KEY (tenant_id, schedule_state_version_id)
    REFERENCES business_report_schedule_versions (tenant_id, id),
  CONSTRAINT fk_mc_lifecycle_schedule_disabled FOREIGN KEY (tenant_id, schedule_disabled_version_id)
    REFERENCES business_report_schedule_versions (tenant_id, id),
  CONSTRAINT fk_mc_lifecycle_actor FOREIGN KEY (tenant_id, actor_user_id)
    REFERENCES users (tenant_id, id),
  CONSTRAINT ck_mc_lifecycle_customer CHECK (
    REGEXP_LIKE(customer_id, _ascii'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')
    AND BINARY customer_id <> BINARY _ascii'4ebaeefa-b101-47f8-ac76-e49ab309d272'
  ) ENFORCED,
  CONSTRAINT ck_mc_lifecycle_version CHECK (source_version >= 1) ENFORCED,
  CONSTRAINT ck_mc_lifecycle_portal_flag CHECK (portal_was_active IN (0,1)) ENFORCED,
  CONSTRAINT ck_mc_lifecycle_schedule_flag CHECK (schedule_was_active IN (0,1)) ENFORCED,
  CONSTRAINT ck_mc_lifecycle_schedule_key CHECK (
    BINARY schedule_key = BINARY CONCAT(_ascii'managed-weekly-v3:', customer_id)
  ) ENFORCED,
  CONSTRAINT ck_mc_lifecycle_source_hash CHECK (
    REGEXP_LIKE(source_request_sha256, _ascii'^[0-9a-f]{64}$')
  ) ENFORCED,
  CONSTRAINT ck_mc_lifecycle_portal_hash CHECK (
    portal_state_sha256 IS NULL
    OR REGEXP_LIKE(portal_state_sha256, _ascii'^[0-9a-f]{64}$')
  ) ENFORCED,
  CONSTRAINT ck_mc_lifecycle_schedule_hash CHECK (
    schedule_state_sha256 IS NULL
    OR REGEXP_LIKE(schedule_state_sha256, _ascii'^[0-9a-f]{64}$')
  ) ENFORCED,
  CONSTRAINT ck_mc_lifecycle_evidence_hash CHECK (
    REGEXP_LIKE(evidence_sha256, _ascii'^[0-9a-f]{64}$')
  ) ENFORCED,
  -- A fresh table remains unwritable across every implicit-commit DDL step.
  CONSTRAINT ck_mc_lifecycle_install_lock CHECK (0 = 1) ENFORCED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @mc_lifecycle_table_ok = (
  SELECT COUNT(*) = 1
    FROM information_schema.tables
   WHERE table_schema=DATABASE()
     AND table_name='managed_customer_lifecycle_receipts'
     AND engine='InnoDB'
);
SET @mc_lifecycle_columns_ok = (
  SELECT COUNT(*) = 26
     AND SUM(column_name='id' AND column_type='bigint unsigned'
             AND is_nullable='NO' AND extra='auto_increment') = 1
     AND SUM(column_name IN ('tenant_id','client_id','actor_user_id')
             AND column_type='int unsigned' AND is_nullable='NO') = 3
     AND SUM(column_name IN ('source_binding_id','source_event_receipt_id','source_version')
             AND column_type='bigint unsigned' AND is_nullable='NO') = 3
     AND SUM(column_name IN ('customer_id','source_event_id')
             AND column_type='char(36)' AND character_set_name='ascii'
             AND collation_name='ascii_bin' AND is_nullable='NO') = 2
     AND SUM(column_name='source_status' AND column_type="enum('active','inactive')"
             AND is_nullable='NO') = 1
     AND SUM(column_name='action'
             AND column_type="enum('contained','reactivation_blocked')"
             AND is_nullable='NO') = 1
     AND SUM(column_name='portal_binding_id' AND column_type='int unsigned'
             AND is_nullable='YES') = 1
     AND SUM(column_name IN ('portal_was_active','schedule_was_active')
             AND column_type='tinyint(1)' AND is_nullable='NO') = 2
     AND SUM(column_name IN ('portal_before_event_id','portal_state_event_id',
                             'portal_disabled_event_id')
             AND column_type='bigint unsigned' AND is_nullable='YES') = 3
     AND SUM(column_name IN ('portal_state_sha256','schedule_state_sha256')
             AND column_type='char(64)' AND character_set_name='ascii'
             AND collation_name='ascii_bin' AND is_nullable='YES') = 2
     AND SUM(column_name='schedule_key' AND column_type='varchar(64)'
             AND character_set_name='ascii' AND collation_name='ascii_bin'
             AND is_nullable='NO') = 1
     AND SUM(column_name IN ('schedule_active_version_id','schedule_state_version_id',
                             'schedule_disabled_version_id')
             AND column_type='int unsigned' AND is_nullable='YES') = 3
     AND SUM(column_name IN ('source_request_sha256','evidence_sha256')
             AND column_type='char(64)' AND character_set_name='ascii'
             AND collation_name='ascii_bin' AND is_nullable='NO') = 2
     AND SUM(column_name='created_at' AND column_type='datetime'
             AND is_nullable='NO') = 1
    FROM information_schema.columns
   WHERE table_schema=DATABASE() AND table_name='managed_customer_lifecycle_receipts'
);
SET @mc_lifecycle_indexes_ok = (
  SELECT COUNT(*) = 14
     AND SUM(index_name='PRIMARY' AND non_unique=0 AND columns_csv='id') = 1
     AND SUM(index_name='uq_mc_lifecycle_customer_version_action' AND non_unique=0
             AND columns_csv='customer_id,source_version,action') = 1
     AND SUM(index_name='uq_mc_lifecycle_source_event_action' AND non_unique=0
             AND columns_csv='source_event_receipt_id,action') = 1
     AND SUM(index_name='ix_mc_lifecycle_scope' AND non_unique=1
             AND columns_csv='tenant_id,client_id,id') = 1
     AND SUM(index_name='ix_mc_lifecycle_source' AND non_unique=1
             AND columns_csv='tenant_id,source_binding_id,source_version') = 1
     AND SUM(index_name='ix_mc_lifecycle_source_event' AND non_unique=1
             AND columns_csv='tenant_id,source_event_receipt_id') = 1
     AND SUM(index_name='ix_mc_lifecycle_actor' AND non_unique=1
             AND columns_csv='tenant_id,actor_user_id,created_at') = 1
     AND SUM(index_name='ix_mc_lifecycle_portal_binding' AND non_unique=1
             AND columns_csv='tenant_id,client_id,portal_binding_id') = 1
     AND SUM(index_name='ix_mc_lifecycle_portal_before' AND non_unique=1
             AND columns_csv='portal_before_event_id') = 1
     AND SUM(index_name='ix_mc_lifecycle_portal_state' AND non_unique=1
             AND columns_csv='portal_state_event_id') = 1
     AND SUM(index_name='ix_mc_lifecycle_portal_disabled' AND non_unique=1
             AND columns_csv='portal_disabled_event_id') = 1
     AND SUM(index_name='ix_mc_lifecycle_schedule_active' AND non_unique=1
             AND columns_csv='tenant_id,schedule_active_version_id') = 1
     AND SUM(index_name='ix_mc_lifecycle_schedule_state' AND non_unique=1
             AND columns_csv='tenant_id,schedule_state_version_id') = 1
     AND SUM(index_name='ix_mc_lifecycle_schedule_disabled' AND non_unique=1
             AND columns_csv='tenant_id,schedule_disabled_version_id') = 1
    FROM (
      SELECT index_name, MIN(non_unique) AS non_unique,
             GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_csv
        FROM information_schema.statistics
       WHERE table_schema=DATABASE()
         AND table_name='managed_customer_lifecycle_receipts'
       GROUP BY index_name
    ) exact_indexes
);
SET @mc_lifecycle_fks_ok = (
  SELECT COUNT(*) = 11
     AND SUM(update_rule='RESTRICT' AND delete_rule='RESTRICT') = 11
     AND SUM(constraint_name='fk_mc_lifecycle_client'
             AND referenced_table_name='clients'
             AND columns_csv='tenant_id=tenant_id,client_id=id') = 1
     AND SUM(constraint_name='fk_mc_lifecycle_source_binding'
             AND referenced_table_name='suite_customer_sync_bindings'
             AND columns_csv='tenant_id=tenant_id,source_binding_id=id') = 1
     AND SUM(constraint_name='fk_mc_lifecycle_source_event'
             AND referenced_table_name='suite_customer_sync_events'
             AND columns_csv='tenant_id=tenant_id,source_event_receipt_id=id') = 1
     AND SUM(constraint_name='fk_mc_lifecycle_portal_binding'
             AND referenced_table_name='customer_portal_bindings'
             AND columns_csv='tenant_id=tenant_id,client_id=client_id,portal_binding_id=id') = 1
     AND SUM(constraint_name='fk_mc_lifecycle_portal_before'
             AND referenced_table_name='customer_portal_binding_events'
             AND columns_csv='portal_before_event_id=id') = 1
     AND SUM(constraint_name='fk_mc_lifecycle_portal_state'
             AND referenced_table_name='customer_portal_binding_events'
             AND columns_csv='portal_state_event_id=id') = 1
     AND SUM(constraint_name='fk_mc_lifecycle_portal_disabled'
             AND referenced_table_name='customer_portal_binding_events'
             AND columns_csv='portal_disabled_event_id=id') = 1
     AND SUM(constraint_name='fk_mc_lifecycle_schedule_active'
             AND referenced_table_name='business_report_schedule_versions'
             AND columns_csv='tenant_id=tenant_id,schedule_active_version_id=id') = 1
     AND SUM(constraint_name='fk_mc_lifecycle_schedule_state'
             AND referenced_table_name='business_report_schedule_versions'
             AND columns_csv='tenant_id=tenant_id,schedule_state_version_id=id') = 1
     AND SUM(constraint_name='fk_mc_lifecycle_schedule_disabled'
             AND referenced_table_name='business_report_schedule_versions'
             AND columns_csv='tenant_id=tenant_id,schedule_disabled_version_id=id') = 1
     AND SUM(constraint_name='fk_mc_lifecycle_actor'
             AND referenced_table_name='users'
             AND columns_csv='tenant_id=tenant_id,actor_user_id=id') = 1
    FROM (
      SELECT key_column.constraint_name,
             MIN(key_column.referenced_table_name) AS referenced_table_name,
             MIN(referential.update_rule) AS update_rule,
             MIN(referential.delete_rule) AS delete_rule,
             GROUP_CONCAT(CONCAT(column_name,'=',referenced_column_name)
                          ORDER BY ordinal_position) AS columns_csv
        FROM information_schema.key_column_usage key_column
        JOIN information_schema.referential_constraints referential
          ON referential.constraint_schema=key_column.constraint_schema
         AND referential.constraint_name=key_column.constraint_name
       WHERE key_column.table_schema=DATABASE()
         AND key_column.table_name='managed_customer_lifecycle_receipts'
         AND key_column.referenced_table_name IS NOT NULL
       GROUP BY key_column.constraint_name
    ) exact_fks
);
SET @mc_lifecycle_checks_ok = (
  SELECT COUNT(*) IN (9,10)
     AND SUM(constraints_table.enforced='YES')=COUNT(*)
     AND SUM(constraints_table.constraint_name IN (
       'ck_mc_lifecycle_customer','ck_mc_lifecycle_version',
       'ck_mc_lifecycle_portal_flag','ck_mc_lifecycle_schedule_flag',
       'ck_mc_lifecycle_schedule_key','ck_mc_lifecycle_source_hash',
       'ck_mc_lifecycle_portal_hash','ck_mc_lifecycle_schedule_hash',
       'ck_mc_lifecycle_evidence_hash'
     )) = 9
     AND SUM(constraints_table.constraint_name='ck_mc_lifecycle_install_lock')=COUNT(*)-9
    FROM information_schema.check_constraints checks_table
    JOIN information_schema.table_constraints constraints_table
      ON constraints_table.constraint_schema=checks_table.constraint_schema
     AND constraints_table.constraint_name=checks_table.constraint_name
   WHERE constraints_table.constraint_schema=DATABASE()
     AND constraints_table.table_name='managed_customer_lifecycle_receipts'
     AND constraints_table.constraint_type='CHECK'
);
SET @mc_lifecycle_preflight_sql = IF(
  @mc_lifecycle_table_ok=1
  AND @mc_lifecycle_columns_ok=1
  AND @mc_lifecycle_indexes_ok=1
  AND @mc_lifecycle_fks_ok=1
  AND @mc_lifecycle_checks_ok=1,
  'DO 0',
  'SELECT * FROM information_schema.migration_024_managed_customer_lifecycle_preflight_failed'
);
PREPARE mc_lifecycle_preflight FROM @mc_lifecycle_preflight_sql;
EXECUTE mc_lifecycle_preflight;
DEALLOCATE PREPARE mc_lifecycle_preflight;

-- Prove trigger privilege, then install blockers for all three write classes.
-- Any interrupted replay remains fail-closed until the exact migration resumes.
DROP TRIGGER IF EXISTS trg_mc_lifecycle_privilege_preflight;
CREATE TRIGGER trg_mc_lifecycle_privilege_preflight
BEFORE INSERT ON managed_customer_lifecycle_receipts
FOR EACH ROW SET @mc_lifecycle_trigger_privilege_preflight=1;
DROP TRIGGER trg_mc_lifecycle_privilege_preflight;

DELIMITER $$
CREATE TRIGGER IF NOT EXISTS trg_mc_lifecycle_swap_insert
BEFORE INSERT ON managed_customer_lifecycle_receipts
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Managed-customer lifecycle migration is incomplete';
END$$
CREATE TRIGGER IF NOT EXISTS trg_mc_lifecycle_swap_update
BEFORE UPDATE ON managed_customer_lifecycle_receipts
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Managed-customer lifecycle migration is incomplete';
END$$
CREATE TRIGGER IF NOT EXISTS trg_mc_lifecycle_swap_delete
BEFORE DELETE ON managed_customer_lifecycle_receipts
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Managed-customer lifecycle migration is incomplete';
END$$
DELIMITER ;

SET @mc_lifecycle_swaps_ok = (
  SELECT COUNT(*)=3
    FROM information_schema.triggers
   WHERE trigger_schema=DATABASE()
     AND event_object_table='managed_customer_lifecycle_receipts'
     AND action_timing='BEFORE'
     AND trigger_name IN (
       'trg_mc_lifecycle_swap_insert','trg_mc_lifecycle_swap_update',
       'trg_mc_lifecycle_swap_delete'
     )
     AND action_statement LIKE '%Managed-customer lifecycle migration is incomplete%'
);
SET @mc_lifecycle_swap_sql = IF(
  @mc_lifecycle_swaps_ok=1,
  'DO 0',
  'SELECT * FROM information_schema.migration_024_managed_customer_lifecycle_swap_failed'
);
PREPARE mc_lifecycle_swap_statement FROM @mc_lifecycle_swap_sql;
EXECUTE mc_lifecycle_swap_statement;
DEALLOCATE PREPARE mc_lifecycle_swap_statement;

DROP TRIGGER IF EXISTS trg_mc_lifecycle_before_insert;
DROP TRIGGER IF EXISTS trg_mc_lifecycle_no_update;
DROP TRIGGER IF EXISTS trg_mc_lifecycle_no_delete;

DELIMITER $$
CREATE TRIGGER trg_mc_lifecycle_before_insert
BEFORE INSERT ON managed_customer_lifecycle_receipts
FOR EACH ROW
BEGIN
  DECLARE guard_version VARCHAR(64) DEFAULT 'safeharbor-managed-customer-lifecycle-receipt-v1';
  DECLARE actor_matches INT DEFAULT 0;
  DECLARE source_matches INT DEFAULT 0;
  DECLARE inactive_history INT DEFAULT 0;
  DECLARE portal_matches INT DEFAULT 0;
  DECLARE portal_history INT DEFAULT 0;
  DECLARE schedule_matches INT DEFAULT 0;
  DECLARE schedule_history INT DEFAULT 0;
  DECLARE expected_portal_hash CHAR(64) DEFAULT NULL;
  DECLARE expected_schedule_hash CHAR(64) DEFAULT NULL;
  DECLARE expected_evidence_hash CHAR(64) DEFAULT NULL;

  IF NOT REGEXP_LIKE(
       NEW.customer_id,
       _ascii'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
     )
     OR BINARY NEW.customer_id=BINARY _ascii'4ebaeefa-b101-47f8-ac76-e49ab309d272'
     OR BINARY NEW.schedule_key<>BINARY CONCAT(_ascii'managed-weekly-v3:',NEW.customer_id) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Lifecycle receipt customer or schedule key is invalid';
  END IF;

  SELECT COUNT(*) INTO actor_matches
    FROM users
   WHERE tenant_id=NEW.tenant_id AND id=NEW.actor_user_id
     AND is_active=1 AND role IN ('owner','admin');
  IF actor_matches <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Lifecycle actor must be an active owner or admin';
  END IF;

  SELECT COUNT(*) INTO source_matches
    FROM suite_customer_sync_bindings binding
    JOIN suite_customer_sync_events receipt
      ON receipt.tenant_id=binding.tenant_id
     AND receipt.id=NEW.source_event_receipt_id
     AND receipt.binding_id=binding.id
   WHERE binding.tenant_id=NEW.tenant_id
     AND binding.id=NEW.source_binding_id
     AND binding.client_id=NEW.client_id
     AND BINARY binding.customer_id=BINARY NEW.customer_id
     AND binding.source_version=NEW.source_version
     AND binding.status=NEW.source_status
     AND BINARY binding.last_event_id=BINARY NEW.source_event_id
     AND BINARY binding.last_request_sha256=BINARY NEW.source_request_sha256
     AND BINARY receipt.event_id=BINARY NEW.source_event_id
     AND receipt.source_version=NEW.source_version
     AND receipt.status=NEW.source_status
     AND BINARY receipt.request_sha256=BINARY NEW.source_request_sha256;
  IF source_matches <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Lifecycle receipt must match the exact current source event';
  END IF;
  SELECT COUNT(*) INTO inactive_history
    FROM suite_customer_sync_events
   WHERE tenant_id=NEW.tenant_id AND binding_id=NEW.source_binding_id
     AND status='inactive';
  IF NOT (
       (NEW.action='contained' AND NEW.source_status='inactive')
    OR (NEW.action='reactivation_blocked' AND NEW.source_status='active' AND inactive_history>0)
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Lifecycle action does not match immutable source history';
  END IF;

  IF NEW.portal_binding_id IS NULL THEN
    SELECT COUNT(*) INTO portal_history
      FROM customer_portal_bindings
     WHERE tenant_id=NEW.tenant_id AND client_id=NEW.client_id;
    IF portal_history<>0 OR NEW.portal_was_active<>0
       OR NEW.portal_before_event_id IS NOT NULL
       OR NEW.portal_state_event_id IS NOT NULL
       OR NEW.portal_disabled_event_id IS NOT NULL
       OR NEW.portal_state_sha256 IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Lifecycle portal-absence evidence is inconsistent';
    END IF;
  ELSE
    SELECT COUNT(*), MAX(SHA2(CAST(state_event.snapshot_json AS CHAR),256))
      INTO portal_matches, expected_portal_hash
      FROM customer_portal_bindings portal
      JOIN customer_portal_binding_events state_event
        ON state_event.id=NEW.portal_state_event_id
       AND state_event.tenant_id=portal.tenant_id
       AND state_event.client_id=portal.client_id
       AND state_event.binding_id=portal.id
     WHERE portal.tenant_id=NEW.tenant_id
       AND portal.client_id=NEW.client_id
       AND portal.id=NEW.portal_binding_id
       AND portal.status='disabled'
       AND state_event.id=(
         SELECT MAX(latest_event.id)
           FROM customer_portal_binding_events latest_event
          WHERE latest_event.tenant_id=portal.tenant_id
            AND latest_event.client_id=portal.client_id
            AND latest_event.binding_id=portal.id
       );
    IF portal_matches<>1 OR NEW.portal_before_event_id IS NULL
       OR NEW.portal_state_event_id IS NULL
       OR NEW.portal_state_sha256 IS NULL
       OR BINARY expected_portal_hash<>BINARY NEW.portal_state_sha256 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Lifecycle portal state evidence is inconsistent';
    END IF;
    IF NEW.portal_was_active=1 THEN
      SELECT COUNT(*) INTO portal_matches
        FROM customer_portal_binding_events disabled_event
       WHERE disabled_event.id=NEW.portal_disabled_event_id
         AND disabled_event.id=NEW.portal_state_event_id
         AND disabled_event.event_kind='disabled'
         AND disabled_event.from_status='active'
         AND disabled_event.to_status='disabled'
         AND NEW.portal_before_event_id=(
           SELECT MAX(before_event.id)
             FROM customer_portal_binding_events before_event
            WHERE before_event.tenant_id=disabled_event.tenant_id
              AND before_event.client_id=disabled_event.client_id
              AND before_event.binding_id=disabled_event.binding_id
              AND before_event.id<disabled_event.id
         );
      IF portal_matches<>1 THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Lifecycle portal transition evidence is inconsistent';
      END IF;
    ELSEIF NEW.portal_disabled_event_id IS NOT NULL
       OR NEW.portal_before_event_id<>NEW.portal_state_event_id THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Lifecycle receipt claims an unperformed portal transition';
    END IF;
  END IF;

  IF NEW.schedule_state_version_id IS NULL THEN
    SELECT COUNT(*) INTO schedule_history
      FROM business_report_schedule_versions
     WHERE tenant_id=NEW.tenant_id AND BINARY schedule_key=BINARY NEW.schedule_key;
    IF schedule_history<>0 OR NEW.schedule_was_active<>0
       OR NEW.schedule_active_version_id IS NOT NULL
       OR NEW.schedule_disabled_version_id IS NOT NULL
       OR NEW.schedule_state_sha256 IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Lifecycle report-schedule absence evidence is inconsistent';
    END IF;
  ELSE
    SELECT COUNT(*), MAX(SHA2(CONCAT(
             'safeharbor-managed-customer-schedule-state-v1','\n',schedule.id,'\n',schedule.tenant_id,
             '\n',schedule.schedule_key,'\n',schedule.version_no,'\n',schedule.definition_version_id,
             '\n',schedule.client_id,'\n',schedule.recipient_email,'\n',schedule.schedule_timezone,
             '\n',schedule.delivery_weekday,'\n',schedule.delivery_local_time,'\n',schedule.canary,
             '\n',schedule.status,'\n',schedule.created_by_user_id,'\n',schedule.reason
           ),256))
      INTO schedule_matches, expected_schedule_hash
      FROM business_report_schedule_versions schedule
     WHERE schedule.tenant_id=NEW.tenant_id
       AND schedule.id=NEW.schedule_state_version_id
       AND schedule.client_id=NEW.client_id
       AND BINARY schedule.schedule_key=BINARY NEW.schedule_key
       AND schedule.status='disabled'
       AND schedule.version_no=(
         SELECT MAX(latest.version_no)
           FROM business_report_schedule_versions latest
          WHERE latest.tenant_id=schedule.tenant_id
            AND BINARY latest.schedule_key=BINARY schedule.schedule_key
       );
    IF schedule_matches<>1 OR NEW.schedule_state_sha256 IS NULL
       OR BINARY expected_schedule_hash<>BINARY NEW.schedule_state_sha256 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Lifecycle report-schedule state evidence is inconsistent';
    END IF;
    IF NEW.schedule_was_active=1 THEN
      SELECT COUNT(*) INTO schedule_matches
        FROM business_report_schedule_versions disabled_schedule
        JOIN business_report_schedule_versions active_schedule
          ON active_schedule.tenant_id=disabled_schedule.tenant_id
         AND active_schedule.id=NEW.schedule_active_version_id
         AND active_schedule.schedule_key=disabled_schedule.schedule_key
         AND active_schedule.version_no=disabled_schedule.version_no-1
         AND active_schedule.status='active'
       WHERE disabled_schedule.tenant_id=NEW.tenant_id
         AND disabled_schedule.id=NEW.schedule_disabled_version_id
         AND disabled_schedule.id=NEW.schedule_state_version_id
         AND disabled_schedule.status='disabled';
      IF schedule_matches<>1 THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Lifecycle report-schedule transition evidence is inconsistent';
      END IF;
    ELSEIF NEW.schedule_active_version_id IS NOT NULL
       OR NEW.schedule_disabled_version_id IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Lifecycle receipt claims an unperformed report transition';
    END IF;
  END IF;

  SET expected_evidence_hash=SHA2(CONCAT(
    'safeharbor-managed-customer-lifecycle-v1','\n',NEW.tenant_id,'\n',NEW.client_id,
    '\n',NEW.source_binding_id,'\n',NEW.customer_id,'\n',NEW.source_event_receipt_id,
    '\n',NEW.source_event_id,'\n',NEW.source_version,'\n',NEW.source_status,'\n',NEW.action,
    '\n',COALESCE(CAST(NEW.portal_binding_id AS CHAR),'-'),'\n',NEW.portal_was_active,
    '\n',COALESCE(CAST(NEW.portal_before_event_id AS CHAR),'-'),
    '\n',COALESCE(CAST(NEW.portal_state_event_id AS CHAR),'-'),
    '\n',COALESCE(CAST(NEW.portal_disabled_event_id AS CHAR),'-'),
    '\n',COALESCE(NEW.portal_state_sha256,'-'),'\n',NEW.schedule_key,
    '\n',NEW.schedule_was_active,
    '\n',COALESCE(CAST(NEW.schedule_active_version_id AS CHAR),'-'),
    '\n',COALESCE(CAST(NEW.schedule_state_version_id AS CHAR),'-'),
    '\n',COALESCE(CAST(NEW.schedule_disabled_version_id AS CHAR),'-'),
    '\n',COALESCE(NEW.schedule_state_sha256,'-'),'\n',NEW.actor_user_id,
    '\n',NEW.source_request_sha256
  ),256);
  IF BINARY expected_evidence_hash<>BINARY NEW.evidence_sha256 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Lifecycle evidence digest does not match exact facts';
  END IF;
  SET NEW.created_at=UTC_TIMESTAMP();
END$$

CREATE TRIGGER trg_mc_lifecycle_no_update
BEFORE UPDATE ON managed_customer_lifecycle_receipts
FOR EACH ROW
BEGIN
  DECLARE guard_version VARCHAR(64) DEFAULT 'safeharbor-managed-customer-lifecycle-immutable-v1';
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Managed-customer lifecycle receipts are immutable';
END$$

CREATE TRIGGER trg_mc_lifecycle_no_delete
BEFORE DELETE ON managed_customer_lifecycle_receipts
FOR EACH ROW
BEGIN
  DECLARE guard_version VARCHAR(64) DEFAULT 'safeharbor-managed-customer-lifecycle-immutable-v1';
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Managed-customer lifecycle receipts are immutable';
END$$
DELIMITER ;

SET @mc_lifecycle_trigger_ok = (
  SELECT COUNT(*)=3
    FROM information_schema.triggers
   WHERE trigger_schema=DATABASE()
     AND event_object_table='managed_customer_lifecycle_receipts'
     AND action_timing='BEFORE'
     AND (
       (trigger_name='trg_mc_lifecycle_before_insert'
        AND event_manipulation='INSERT'
        AND action_statement LIKE '%safeharbor-managed-customer-lifecycle-receipt-v1%'
        AND action_statement LIKE '%suite_customer_sync_events%'
        AND action_statement LIKE '%business_report_schedule_versions%')
       OR (trigger_name='trg_mc_lifecycle_no_update'
        AND event_manipulation='UPDATE'
        AND action_statement LIKE '%safeharbor-managed-customer-lifecycle-immutable-v1%')
       OR (trigger_name='trg_mc_lifecycle_no_delete'
        AND event_manipulation='DELETE'
        AND action_statement LIKE '%safeharbor-managed-customer-lifecycle-immutable-v1%')
     )
) AND (
  SELECT COUNT(*)=6
    FROM information_schema.triggers
   WHERE trigger_schema=DATABASE()
     AND event_object_table='managed_customer_lifecycle_receipts'
     AND action_timing='BEFORE'
);
SET @mc_lifecycle_postflight_sql = IF(
  @mc_lifecycle_trigger_ok=1,
  'SELECT 1',
  'SELECT * FROM information_schema.migration_024_managed_customer_lifecycle_postflight_failed'
);
PREPARE mc_lifecycle_postflight FROM @mc_lifecycle_postflight_sql;
EXECUTE mc_lifecycle_postflight;
DEALLOCATE PREPARE mc_lifecycle_postflight;

DROP TRIGGER trg_mc_lifecycle_swap_insert;
DROP TRIGGER trg_mc_lifecycle_swap_update;
DROP TRIGGER trg_mc_lifecycle_swap_delete;

SET @mc_lifecycle_install_lock_ddl = IF(
  (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema=DATABASE()
      AND table_name='managed_customer_lifecycle_receipts'
      AND constraint_name='ck_mc_lifecycle_install_lock'
      AND constraint_type='CHECK')=1,
  'ALTER TABLE managed_customer_lifecycle_receipts DROP CHECK ck_mc_lifecycle_install_lock',
  'DO 0'
);
PREPARE mc_lifecycle_install_statement FROM @mc_lifecycle_install_lock_ddl;
EXECUTE mc_lifecycle_install_statement;
DEALLOCATE PREPARE mc_lifecycle_install_statement;

SET @mc_lifecycle_final_ok = (
  (SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema=DATABASE()
      AND event_object_table='managed_customer_lifecycle_receipts')=3
  AND
  (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema=DATABASE()
      AND table_name='managed_customer_lifecycle_receipts'
      AND constraint_name='ck_mc_lifecycle_install_lock')=0
);
SET @mc_lifecycle_final_sql = IF(
  @mc_lifecycle_final_ok=1,
  'DO 0',
  'SELECT * FROM information_schema.migration_024_managed_customer_lifecycle_final_failed'
);
PREPARE mc_lifecycle_final_statement FROM @mc_lifecycle_final_sql;
EXECUTE mc_lifecycle_final_statement;
DEALLOCATE PREPARE mc_lifecycle_final_statement;

SELECT
  (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema=DATABASE() AND table_name='managed_customer_lifecycle_receipts') AS lifecycle_table_count,
  (SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema=DATABASE()
      AND event_object_table='managed_customer_lifecycle_receipts') AS lifecycle_trigger_count,
  (SELECT COUNT(*) FROM managed_customer_lifecycle_receipts) AS lifecycle_receipt_count;
