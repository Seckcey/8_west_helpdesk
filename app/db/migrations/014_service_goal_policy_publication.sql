-- 014_service_goal_policy_publication.sql -- approval-grade policy publishing.
-- Additive MySQL 8 migration. It publishes no policy and changes no ticket.
-- Apply through the trigger-capable operator path before deploying the CLI.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Fail before any table mutation if this connection cannot maintain the
-- database guards. The DML-only runtime identity must not run migrations.
DROP TRIGGER IF EXISTS trg_goal_publication_privilege_preflight;
CREATE TRIGGER trg_goal_publication_privilege_preflight
BEFORE INSERT ON service_goal_policy_versions
FOR EACH ROW
SET @goal_publication_trigger_privilege_preflight = 1;
DROP TRIGGER trg_goal_publication_privilege_preflight;

-- Migration 010's immutable-history guards are a prerequisite, not merely
-- four names that may point at arbitrary trigger bodies. Refuse drift before
-- changing the publication schema. An untouched migration-010 database has
-- no INSERT guards yet; a replayed migration-014 database must have both in
-- their exact final form.
SET @goal_immutable_guards_ok = (
  SELECT COUNT(*) = 4
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND action_timing = 'BEFORE'
     AND action_orientation = 'ROW'
     AND (
       (trigger_name = 'trg_goal_policy_versions_no_update'
        AND event_object_table = 'service_goal_policy_versions'
        AND event_manipulation = 'UPDATE'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = '0551525ab9fe377a68f2c8e5d84ea4661f667e7a677889678caa840d4b5dbfa0')
       OR
       (trigger_name = 'trg_goal_policy_versions_no_delete'
        AND event_object_table = 'service_goal_policy_versions'
        AND event_manipulation = 'DELETE'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = '0551525ab9fe377a68f2c8e5d84ea4661f667e7a677889678caa840d4b5dbfa0')
       OR
       (trigger_name = 'trg_goal_policy_targets_no_update'
        AND event_object_table = 'service_goal_policy_targets'
        AND event_manipulation = 'UPDATE'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = '77beb4a9887c08e97bd12be06351a937b009c6dee5b425fd57d7f3508a3cecf1')
       OR
       (trigger_name = 'trg_goal_policy_targets_no_delete'
        AND event_object_table = 'service_goal_policy_targets'
        AND event_manipulation = 'DELETE'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = '77beb4a9887c08e97bd12be06351a937b009c6dee5b425fd57d7f3508a3cecf1')
     )
);
SET @goal_ddl = IF(
  @goal_immutable_guards_ok = 1,
  'DO 0',
  'SELECT * FROM information_schema.migration_014_bad_immutable_guards'
);
PREPARE goal_statement FROM @goal_ddl;
EXECUTE goal_statement;
DEALLOCATE PREPARE goal_statement;

SET @goal_existing_insert_guard_count = (
  SELECT COUNT(*)
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'trg_goal_policy_versions_before_insert',
       'trg_goal_policy_targets_before_insert'
     )
);
SET @goal_existing_insert_guards_ok = (
  SELECT COUNT(*) = 2
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND action_timing = 'BEFORE'
     AND action_orientation = 'ROW'
     AND event_manipulation = 'INSERT'
     AND (
       (trigger_name = 'trg_goal_policy_versions_before_insert'
        AND event_object_table = 'service_goal_policy_versions'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = 'e30f260752bba425fe598b373a77e51a09512009ff51e2544f8c792ff8447765')
       OR
       (trigger_name = 'trg_goal_policy_targets_before_insert'
        AND event_object_table = 'service_goal_policy_targets'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = '4d1b65eacf962c59c155ba0b753761bbe106bc43389c985bc2e562417e243eff')
     )
);
SET @goal_ddl = IF(
  @goal_existing_insert_guard_count = 0 OR @goal_existing_insert_guards_ok = 1,
  'DO 0',
  'SELECT * FROM information_schema.migration_014_bad_insert_guards'
);
PREPARE goal_statement FROM @goal_ddl;
EXECUTE goal_statement;
DEALLOCATE PREPARE goal_statement;

-- Legacy v1 rows remain truthfully unattributed. Every later insert is
-- required by the permanent trigger to carry an active owner/admin actor and
-- a nonblank immutable reason.
SET @goal_column_count = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'service_goal_policy_versions'
     AND column_name = 'created_by_user_id'
);
SET @goal_column_exact = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'service_goal_policy_versions'
     AND column_name = 'created_by_user_id'
     AND column_type = 'int unsigned'
     AND is_nullable = 'YES'
     AND column_default IS NULL
     AND extra = ''
);
SET @goal_ddl = IF(
  @goal_column_count = 0,
  'ALTER TABLE service_goal_policy_versions ADD COLUMN created_by_user_id INT UNSIGNED NULL AFTER pause_mode',
  IF(@goal_column_exact = 1, 'DO 0',
     'SELECT * FROM information_schema.migration_014_bad_created_by_user_id')
);
PREPARE goal_statement FROM @goal_ddl;
EXECUTE goal_statement;
DEALLOCATE PREPARE goal_statement;

SET @goal_column_count = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'service_goal_policy_versions'
     AND column_name = 'reason'
);
SET @goal_column_exact = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'service_goal_policy_versions'
     AND column_name = 'reason'
     AND column_type = 'varchar(500)'
     AND is_nullable = 'YES'
     AND column_default IS NULL
     AND extra = ''
);
SET @goal_ddl = IF(
  @goal_column_count = 0,
  'ALTER TABLE service_goal_policy_versions ADD COLUMN reason VARCHAR(500) NULL AFTER created_by_user_id',
  IF(@goal_column_exact = 1, 'DO 0',
     'SELECT * FROM information_schema.migration_014_bad_reason')
);
PREPARE goal_statement FROM @goal_ddl;
EXECUTE goal_statement;
DEALLOCATE PREPARE goal_statement;

SET @goal_index_count = (
  SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'service_goal_policy_versions'
     AND index_name = 'ix_goal_policy_actor'
);
SET @goal_index_exact = (
  SELECT COUNT(*) FROM (
    SELECT index_name
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'service_goal_policy_versions'
       AND index_name = 'ix_goal_policy_actor'
     GROUP BY index_name
    HAVING COUNT(*) = 2
       AND MIN(non_unique) = 1 AND MAX(non_unique) = 1
       AND MIN(index_type) = 'BTREE' AND MAX(index_type) = 'BTREE'
       AND SUM(sub_part IS NOT NULL) = 0
       AND GROUP_CONCAT(column_name ORDER BY seq_in_index)
           = 'tenant_id,created_by_user_id'
  ) exact_index
);
SET @goal_ddl = IF(
  @goal_index_count = 0,
  'ALTER TABLE service_goal_policy_versions ADD KEY ix_goal_policy_actor (tenant_id, created_by_user_id)',
  IF(@goal_index_exact = 1, 'DO 0',
     'SELECT * FROM information_schema.migration_014_bad_actor_index')
);
PREPARE goal_statement FROM @goal_ddl;
EXECUTE goal_statement;
DEALLOCATE PREPARE goal_statement;

SET @goal_fk_count = (
  SELECT COUNT(*) FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND table_name = 'service_goal_policy_versions'
     AND constraint_name = 'fk_goal_policy_actor'
     AND constraint_type = 'FOREIGN KEY'
);
SET @goal_fk_exact = (
  SELECT COUNT(*) FROM (
    SELECT key_column.constraint_name
      FROM information_schema.key_column_usage key_column
      JOIN information_schema.referential_constraints referential_constraint
        ON referential_constraint.constraint_schema = key_column.constraint_schema
       AND referential_constraint.constraint_name = key_column.constraint_name
       AND referential_constraint.table_name = key_column.table_name
     WHERE key_column.constraint_schema = DATABASE()
       AND key_column.table_name = 'service_goal_policy_versions'
       AND key_column.constraint_name = 'fk_goal_policy_actor'
       AND key_column.referenced_table_name = 'users'
       AND referential_constraint.referenced_table_name = 'users'
       AND referential_constraint.unique_constraint_schema = DATABASE()
     GROUP BY key_column.constraint_name
    HAVING GROUP_CONCAT(
             CONCAT(key_column.column_name, '=', key_column.referenced_column_name)
             ORDER BY key_column.ordinal_position
           ) = 'tenant_id=tenant_id,created_by_user_id=id'
       AND MIN(referential_constraint.match_option) = 'NONE'
       AND MAX(referential_constraint.match_option) = 'NONE'
       AND MIN(referential_constraint.update_rule) = 'NO ACTION'
       AND MAX(referential_constraint.update_rule) = 'NO ACTION'
       AND MIN(referential_constraint.delete_rule) = 'NO ACTION'
       AND MAX(referential_constraint.delete_rule) = 'NO ACTION'
  ) exact_fk
);
SET @goal_ddl = IF(
  @goal_fk_count = 0,
  'ALTER TABLE service_goal_policy_versions ADD CONSTRAINT fk_goal_policy_actor FOREIGN KEY (tenant_id, created_by_user_id) REFERENCES users (tenant_id, id) ON UPDATE NO ACTION ON DELETE NO ACTION',
  IF(@goal_fk_exact = 1, 'DO 0',
     'SELECT * FROM information_schema.migration_014_bad_actor_fk')
);
PREPARE goal_statement FROM @goal_ddl;
EXECUTE goal_statement;
DEALLOCATE PREPARE goal_statement;

-- Named checks are additive defense in depth. Insert triggers below also
-- enforce these facts and provide clear refusal messages.
SET @goal_check_count = (
  SELECT COUNT(*) FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND table_name = 'service_goal_policy_versions'
     AND constraint_name = 'ck_goal_policy_version_positive'
     AND constraint_type = 'CHECK'
);
SET @goal_check_exact = (
  SELECT COUNT(*)
    FROM information_schema.table_constraints table_constraint
    JOIN information_schema.check_constraints check_constraint
      ON check_constraint.constraint_schema = table_constraint.constraint_schema
     AND check_constraint.constraint_name = table_constraint.constraint_name
   WHERE table_constraint.constraint_schema = DATABASE()
     AND table_constraint.table_name = 'service_goal_policy_versions'
     AND table_constraint.constraint_name = 'ck_goal_policy_version_positive'
     AND table_constraint.constraint_type = 'CHECK'
     AND table_constraint.enforced = 'YES'
     AND REGEXP_REPLACE(
           REPLACE(LOWER(check_constraint.check_clause), '`', ''),
           '[[:space:]()]',
           ''
         ) = 'version_no>=1'
);
SET @goal_ddl = IF(
  @goal_check_count = 0,
  'ALTER TABLE service_goal_policy_versions ADD CONSTRAINT ck_goal_policy_version_positive CHECK (version_no >= 1) ENFORCED',
  IF(@goal_check_exact = 1, 'DO 0',
     'SELECT * FROM information_schema.migration_014_bad_version_check')
);
PREPARE goal_statement FROM @goal_ddl;
EXECUTE goal_statement;
DEALLOCATE PREPARE goal_statement;

SET @goal_check_count = (
  SELECT COUNT(*) FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND table_name = 'service_goal_policy_versions'
     AND constraint_name = 'ck_goal_policy_attribution_pair'
     AND constraint_type = 'CHECK'
);
SET @goal_check_exact = (
  SELECT COUNT(*)
    FROM information_schema.table_constraints table_constraint
    JOIN information_schema.check_constraints check_constraint
      ON check_constraint.constraint_schema = table_constraint.constraint_schema
     AND check_constraint.constraint_name = table_constraint.constraint_name
   WHERE table_constraint.constraint_schema = DATABASE()
     AND table_constraint.table_name = 'service_goal_policy_versions'
     AND table_constraint.constraint_name = 'ck_goal_policy_attribution_pair'
     AND table_constraint.constraint_type = 'CHECK'
     AND table_constraint.enforced = 'YES'
     AND REGEXP_REPLACE(
           REPLACE(LOWER(check_constraint.check_clause), '`', ''),
           '[[:space:]()]',
           ''
         ) = 'created_by_user_idisnullandreasonisnullorcreated_by_user_idisnotnullandreasonisnotnullandchar_lengthtrimreasonbetween1and500'
);
SET @goal_ddl = IF(
  @goal_check_count = 0,
  'ALTER TABLE service_goal_policy_versions ADD CONSTRAINT ck_goal_policy_attribution_pair CHECK ((created_by_user_id IS NULL AND reason IS NULL) OR (created_by_user_id IS NOT NULL AND reason IS NOT NULL AND CHAR_LENGTH(TRIM(reason)) BETWEEN 1 AND 500)) ENFORCED',
  IF(@goal_check_exact = 1, 'DO 0',
     'SELECT * FROM information_schema.migration_014_bad_attribution_check')
);
PREPARE goal_statement FROM @goal_ddl;
EXECUTE goal_statement;
DEALLOCATE PREPARE goal_statement;

SET @goal_check_count = (
  SELECT COUNT(*) FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND table_name = 'service_goal_policy_targets'
     AND constraint_name = 'ck_goal_target_response_range'
     AND constraint_type = 'CHECK'
);
SET @goal_check_exact = (
  SELECT COUNT(*)
    FROM information_schema.table_constraints table_constraint
    JOIN information_schema.check_constraints check_constraint
      ON check_constraint.constraint_schema = table_constraint.constraint_schema
     AND check_constraint.constraint_name = table_constraint.constraint_name
   WHERE table_constraint.constraint_schema = DATABASE()
     AND table_constraint.table_name = 'service_goal_policy_targets'
     AND table_constraint.constraint_name = 'ck_goal_target_response_range'
     AND table_constraint.constraint_type = 'CHECK'
     AND table_constraint.enforced = 'YES'
     AND REGEXP_REPLACE(
           REPLACE(LOWER(check_constraint.check_clause), '`', ''),
           '[[:space:]()]',
           ''
         ) = 'first_response_minutesbetween1and525600'
);
SET @goal_ddl = IF(
  @goal_check_count = 0,
  'ALTER TABLE service_goal_policy_targets ADD CONSTRAINT ck_goal_target_response_range CHECK (first_response_minutes BETWEEN 1 AND 525600) ENFORCED',
  IF(@goal_check_exact = 1, 'DO 0',
     'SELECT * FROM information_schema.migration_014_bad_response_check')
);
PREPARE goal_statement FROM @goal_ddl;
EXECUTE goal_statement;
DEALLOCATE PREPARE goal_statement;

SET @goal_check_count = (
  SELECT COUNT(*) FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND table_name = 'service_goal_policy_targets'
     AND constraint_name = 'ck_goal_target_resolution_null'
     AND constraint_type = 'CHECK'
);
SET @goal_check_exact = (
  SELECT COUNT(*)
    FROM information_schema.table_constraints table_constraint
    JOIN information_schema.check_constraints check_constraint
      ON check_constraint.constraint_schema = table_constraint.constraint_schema
     AND check_constraint.constraint_name = table_constraint.constraint_name
   WHERE table_constraint.constraint_schema = DATABASE()
     AND table_constraint.table_name = 'service_goal_policy_targets'
     AND table_constraint.constraint_name = 'ck_goal_target_resolution_null'
     AND table_constraint.constraint_type = 'CHECK'
     AND table_constraint.enforced = 'YES'
     AND REGEXP_REPLACE(
           REPLACE(LOWER(check_constraint.check_clause), '`', ''),
           '[[:space:]()]',
           ''
         ) = 'resolution_minutesisnull'
);
SET @goal_ddl = IF(
  @goal_check_count = 0,
  'ALTER TABLE service_goal_policy_targets ADD CONSTRAINT ck_goal_target_resolution_null CHECK (resolution_minutes IS NULL) ENFORCED',
  IF(@goal_check_exact = 1, 'DO 0',
     'SELECT * FROM information_schema.migration_014_bad_resolution_check')
);
PREPARE goal_statement FROM @goal_ddl;
EXECUTE goal_statement;
DEALLOCATE PREPARE goal_statement;

DROP TRIGGER IF EXISTS trg_goal_policy_versions_before_insert;
DROP TRIGGER IF EXISTS trg_goal_policy_targets_before_insert;

DELIMITER $$
CREATE TRIGGER trg_goal_policy_versions_before_insert
BEFORE INSERT ON service_goal_policy_versions
FOR EACH ROW
BEGIN
  DECLARE latest_version_no INT DEFAULT 0;
  DECLARE latest_effective_from DATETIME DEFAULT NULL;
  DECLARE actor_is_authorized INT DEFAULT 0;

  IF NOT (
       (BINARY NEW.policy_key = BINARY 'standard' AND BINARY NEW.display_name = BINARY 'Standard')
    OR (BINARY NEW.policy_key = BINARY 'premium' AND BINARY NEW.display_name = BINARY 'Premium')
  )
     OR NEW.clock_mode <> 'elapsed'
     OR BINARY NEW.time_zone <> BINARY 'UTC'
     OR NEW.pause_mode <> 'none' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Service-goal policy shape is unsupported';
  END IF;

  SELECT version_no, effective_from
    INTO latest_version_no, latest_effective_from
    FROM service_goal_policy_versions
   WHERE tenant_id = NEW.tenant_id
     AND policy_key = NEW.policy_key
   ORDER BY version_no DESC
   LIMIT 1;

  IF NEW.version_no = 1 THEN
    IF latest_version_no <> 0
       OR NEW.effective_from <> '1970-01-01 00:00:00'
       OR NEW.created_by_user_id IS NOT NULL
       OR NEW.reason IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Service-goal v1 is reserved for the exact lazy baseline';
    END IF;
  ELSE
    SET NEW.reason = TRIM(NEW.reason);
    IF NEW.version_no <> latest_version_no + 1 OR latest_version_no < 1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Service-goal policy versions must be sequential';
    END IF;
    IF NEW.effective_from <= UTC_TIMESTAMP()
       OR NEW.effective_from <= latest_effective_from THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Service-goal policy effective time must move forward in the future';
    END IF;
    IF NEW.created_by_user_id IS NULL OR NEW.reason IS NULL OR NEW.reason = '' THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Service-goal publication requires actor and reason';
    END IF;
    SELECT COUNT(*) INTO actor_is_authorized
      FROM users
     WHERE tenant_id = NEW.tenant_id
       AND id = NEW.created_by_user_id
       AND is_active = 1
       AND role IN ('owner','admin');
    IF actor_is_authorized <> 1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Service-goal actor must be an active owner or admin';
    END IF;
  END IF;

  SET NEW.created_at = UTC_TIMESTAMP();
END$$

CREATE TRIGGER trg_goal_policy_targets_before_insert
BEFORE INSERT ON service_goal_policy_targets
FOR EACH ROW
BEGIN
  DECLARE parent_count INT DEFAULT 0;
  DECLARE parent_policy_key VARCHAR(32) DEFAULT NULL;
  DECLARE parent_version_no INT DEFAULT 0;
  DECLARE parent_effective_from DATETIME DEFAULT NULL;

  SELECT COUNT(*), MAX(policy_key), MAX(version_no), MAX(effective_from)
    INTO parent_count, parent_policy_key, parent_version_no, parent_effective_from
    FROM service_goal_policy_versions
   WHERE tenant_id = NEW.tenant_id
     AND id = NEW.policy_version_id;
  IF parent_count <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Service-goal target parent must belong to the exact tenant';
  END IF;
  IF NEW.first_response_minutes < 1
     OR NEW.first_response_minutes > 525600
     OR NEW.resolution_minutes IS NOT NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Service-goal targets require a positive response and no resolution goal';
  END IF;
  IF parent_version_no = 1
     AND NEW.first_response_minutes <> CASE parent_policy_key
          WHEN 'standard' THEN 480 WHEN 'premium' THEN 120 ELSE 0 END THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Service-goal v1 target must match the exact lazy baseline';
  END IF;
  IF parent_version_no > 1 AND parent_effective_from <= UTC_TIMESTAMP() THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Service-goal targets must be completed before the version becomes effective';
  END IF;
END$$
DELIMITER ;

-- Existing immutable UPDATE/DELETE guards from migration 010 are retained.
-- Verify the exact additive shape and all six permanent guards.
SET @goal_publication_columns_ok = (
  SELECT COUNT(*) = 2 FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'service_goal_policy_versions'
     AND ((column_name = 'created_by_user_id' AND column_type = 'int unsigned'
           AND is_nullable = 'YES' AND column_default IS NULL AND extra = '')
       OR (column_name = 'reason' AND column_type = 'varchar(500)'
           AND is_nullable = 'YES' AND column_default IS NULL AND extra = ''))
);
SET @goal_publication_checks_ok = (
  SELECT COUNT(*) = 4
    FROM information_schema.table_constraints table_constraint
    JOIN information_schema.check_constraints check_constraint
      ON check_constraint.constraint_schema = table_constraint.constraint_schema
     AND check_constraint.constraint_name = table_constraint.constraint_name
   WHERE table_constraint.constraint_schema = DATABASE()
     AND table_constraint.constraint_type = 'CHECK'
     AND table_constraint.enforced = 'YES'
     AND (
       (table_constraint.table_name = 'service_goal_policy_versions'
        AND table_constraint.constraint_name = 'ck_goal_policy_version_positive'
        AND REGEXP_REPLACE(REPLACE(LOWER(check_constraint.check_clause), '`', ''), '[[:space:]()]', '')
            = 'version_no>=1')
       OR
       (table_constraint.table_name = 'service_goal_policy_versions'
        AND table_constraint.constraint_name = 'ck_goal_policy_attribution_pair'
        AND REGEXP_REPLACE(REPLACE(LOWER(check_constraint.check_clause), '`', ''), '[[:space:]()]', '')
            = 'created_by_user_idisnullandreasonisnullorcreated_by_user_idisnotnullandreasonisnotnullandchar_lengthtrimreasonbetween1and500')
       OR
       (table_constraint.table_name = 'service_goal_policy_targets'
        AND table_constraint.constraint_name = 'ck_goal_target_response_range'
        AND REGEXP_REPLACE(REPLACE(LOWER(check_constraint.check_clause), '`', ''), '[[:space:]()]', '')
            = 'first_response_minutesbetween1and525600')
       OR
       (table_constraint.table_name = 'service_goal_policy_targets'
        AND table_constraint.constraint_name = 'ck_goal_target_resolution_null'
        AND REGEXP_REPLACE(REPLACE(LOWER(check_constraint.check_clause), '`', ''), '[[:space:]()]', '')
            = 'resolution_minutesisnull')
     )
);
SET @goal_publication_actor_index_ok = (
  SELECT COUNT(*) FROM (
    SELECT index_name
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'service_goal_policy_versions'
       AND index_name = 'ix_goal_policy_actor'
     GROUP BY index_name
    HAVING COUNT(*) = 2
       AND MIN(non_unique) = 1 AND MAX(non_unique) = 1
       AND MIN(index_type) = 'BTREE' AND MAX(index_type) = 'BTREE'
       AND SUM(sub_part IS NOT NULL) = 0
       AND GROUP_CONCAT(column_name ORDER BY seq_in_index)
           = 'tenant_id,created_by_user_id'
  ) exact_index
);
SET @goal_publication_actor_fk_ok = (
  SELECT COUNT(*) FROM (
    SELECT key_column.constraint_name
      FROM information_schema.key_column_usage key_column
      JOIN information_schema.referential_constraints referential_constraint
        ON referential_constraint.constraint_schema = key_column.constraint_schema
       AND referential_constraint.constraint_name = key_column.constraint_name
       AND referential_constraint.table_name = key_column.table_name
     WHERE key_column.constraint_schema = DATABASE()
       AND key_column.table_name = 'service_goal_policy_versions'
       AND key_column.constraint_name = 'fk_goal_policy_actor'
       AND key_column.referenced_table_name = 'users'
       AND referential_constraint.referenced_table_name = 'users'
       AND referential_constraint.unique_constraint_schema = DATABASE()
     GROUP BY key_column.constraint_name
    HAVING GROUP_CONCAT(
             CONCAT(key_column.column_name, '=', key_column.referenced_column_name)
             ORDER BY key_column.ordinal_position
           ) = 'tenant_id=tenant_id,created_by_user_id=id'
       AND MIN(referential_constraint.match_option) = 'NONE'
       AND MAX(referential_constraint.match_option) = 'NONE'
       AND MIN(referential_constraint.update_rule) = 'NO ACTION'
       AND MAX(referential_constraint.update_rule) = 'NO ACTION'
       AND MIN(referential_constraint.delete_rule) = 'NO ACTION'
       AND MAX(referential_constraint.delete_rule) = 'NO ACTION'
  ) exact_fk
);
SET @goal_publication_triggers_ok = (
  SELECT COUNT(*) = 6
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND action_timing = 'BEFORE'
     AND action_orientation = 'ROW'
     AND (
       (trigger_name = 'trg_goal_policy_versions_before_insert'
        AND event_object_table = 'service_goal_policy_versions'
        AND event_manipulation = 'INSERT'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = 'e30f260752bba425fe598b373a77e51a09512009ff51e2544f8c792ff8447765')
       OR
       (trigger_name = 'trg_goal_policy_targets_before_insert'
        AND event_object_table = 'service_goal_policy_targets'
        AND event_manipulation = 'INSERT'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = '4d1b65eacf962c59c155ba0b753761bbe106bc43389c985bc2e562417e243eff')
       OR
       (trigger_name = 'trg_goal_policy_versions_no_update'
        AND event_object_table = 'service_goal_policy_versions'
        AND event_manipulation = 'UPDATE'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = '0551525ab9fe377a68f2c8e5d84ea4661f667e7a677889678caa840d4b5dbfa0')
       OR
       (trigger_name = 'trg_goal_policy_versions_no_delete'
        AND event_object_table = 'service_goal_policy_versions'
        AND event_manipulation = 'DELETE'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = '0551525ab9fe377a68f2c8e5d84ea4661f667e7a677889678caa840d4b5dbfa0')
       OR
       (trigger_name = 'trg_goal_policy_targets_no_update'
        AND event_object_table = 'service_goal_policy_targets'
        AND event_manipulation = 'UPDATE'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = '77beb4a9887c08e97bd12be06351a937b009c6dee5b425fd57d7f3508a3cecf1')
       OR
       (trigger_name = 'trg_goal_policy_targets_no_delete'
        AND event_object_table = 'service_goal_policy_targets'
        AND event_manipulation = 'DELETE'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = '77beb4a9887c08e97bd12be06351a937b009c6dee5b425fd57d7f3508a3cecf1')
     )
);

SET @goal_publication_postflight_sql = IF(
  @goal_publication_columns_ok = 1
  AND @goal_publication_actor_index_ok = 1
  AND @goal_publication_actor_fk_ok = 1
  AND @goal_publication_checks_ok = 1
  AND @goal_publication_triggers_ok = 1,
  'DO 0',
  'SELECT * FROM information_schema.migration_014_publication_postflight_failed'
);
PREPARE goal_publication_postflight FROM @goal_publication_postflight_sql;
EXECUTE goal_publication_postflight;
DEALLOCATE PREPARE goal_publication_postflight;

SELECT
  @goal_publication_columns_ok AS attribution_columns_ok,
  @goal_publication_actor_index_ok AS actor_index_ok,
  @goal_publication_actor_fk_ok AS actor_fk_ok,
  @goal_publication_checks_ok AS publication_checks_ok,
  @goal_publication_triggers_ok AS publication_triggers_ok;
