-- 020_time_approval_adjustments.sql
-- Append-only effective corrections for immutable approved technician time.
-- Apply with a privileged operator before deploying matching application code.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Refuse the wrong base. The composite parent and actor keys are the tenant
-- boundary used by this additive table.
SET @time_adjustment_prerequisites = (
  SELECT
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = 'time_entries'
        AND table_type = 'BASE TABLE') = 1
    AND
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = 'users'
        AND table_type = 'BASE TABLE') = 1
    AND
    (SELECT COUNT(*) FROM information_schema.statistics
      WHERE table_schema = DATABASE() AND table_name = 'time_entries'
        AND index_name = 'uq_time_entries_tenant_id') = 2
    AND
    (SELECT COUNT(*) FROM information_schema.statistics
      WHERE table_schema = DATABASE() AND table_name = 'users'
        AND index_name = 'uq_users_tenant_id') = 2
);
SET @time_adjustment_ddl = IF(
  @time_adjustment_prerequisites,
  'DO 0',
  'SELECT * FROM information_schema.migration_020_missing_time_prerequisites'
);
PREPARE time_adjustment_statement FROM @time_adjustment_ddl;
EXECUTE time_adjustment_statement;
DEALLOCATE PREPARE time_adjustment_statement;

CREATE TABLE IF NOT EXISTS time_entry_approval_adjustments (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id          INT UNSIGNED NOT NULL,
  time_entry_id      INT UNSIGNED NOT NULL,
  adjustment_key     VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  version_no         INT UNSIGNED NOT NULL,
  effective_minutes  INT UNSIGNED NOT NULL,
  effective_billable TINYINT(1) NOT NULL,
  reason             VARCHAR(500) NOT NULL,
  actor_user_id      INT UNSIGNED NOT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_time_adjustment_tenant_key (tenant_id, adjustment_key),
  UNIQUE KEY uq_time_adjustment_entry_version
    (tenant_id, time_entry_id, version_no),
  KEY ix_time_adjustment_entry_created
    (tenant_id, time_entry_id, created_at, id),
  KEY ix_time_adjustment_actor_created
    (tenant_id, actor_user_id, created_at, id),
  CONSTRAINT ck_time_adjustment_version CHECK (version_no >= 1),
  CONSTRAINT ck_time_adjustment_minutes CHECK (effective_minutes BETWEEN 0 AND 1440),
  CONSTRAINT ck_time_adjustment_billable CHECK (effective_billable IN (0, 1)),
  CONSTRAINT ck_time_adjustment_zero_nonbillable
    CHECK (effective_minutes <> 0 OR effective_billable = 0),
  CONSTRAINT ck_time_adjustment_reason
    CHECK (CHAR_LENGTH(TRIM(reason)) BETWEEN 1 AND 500),
  -- This temporary enforced check makes the newly created empty table reject
  -- every insert if first application stops before any trigger can be added.
  -- It is removed only after the permanent guards have been created.
  CONSTRAINT ck_time_adjustment_install_lock CHECK (0 = 1),
  CONSTRAINT fk_time_adjustment_tenant FOREIGN KEY (tenant_id)
    REFERENCES tenants (id),
  CONSTRAINT fk_time_adjustment_entry FOREIGN KEY (tenant_id, time_entry_id)
    REFERENCES time_entries (tenant_id, id),
  CONSTRAINT fk_time_adjustment_actor FOREIGN KEY (tenant_id, actor_user_id)
    REFERENCES users (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Refuse a same-name substitute rather than blessing a malformed table.
SET @time_adjustment_columns_exact = (
  SELECT COUNT(*) FROM (
    SELECT table_name
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'time_entry_approval_adjustments'
     GROUP BY table_name
    HAVING COUNT(*) = 10
       AND SUM(column_name = 'id' AND column_type = 'bigint unsigned'
               AND is_nullable = 'NO' AND extra = 'auto_increment') = 1
       AND SUM(column_name = 'tenant_id' AND column_type = 'int unsigned'
               AND is_nullable = 'NO' AND extra = '') = 1
       AND SUM(column_name = 'time_entry_id' AND column_type = 'int unsigned'
               AND is_nullable = 'NO' AND extra = '') = 1
       AND SUM(column_name = 'adjustment_key' AND column_type = 'varchar(64)'
               AND is_nullable = 'NO' AND collation_name = 'utf8mb4_bin') = 1
       AND SUM(column_name = 'version_no' AND column_type = 'int unsigned'
               AND is_nullable = 'NO' AND extra = '') = 1
       AND SUM(column_name = 'effective_minutes' AND column_type = 'int unsigned'
               AND is_nullable = 'NO' AND extra = '') = 1
       AND SUM(column_name = 'effective_billable' AND column_type = 'tinyint(1)'
               AND is_nullable = 'NO' AND extra = '') = 1
       AND SUM(column_name = 'reason' AND column_type = 'varchar(500)'
               AND is_nullable = 'NO'
               AND character_set_name = 'utf8mb4') = 1
       AND SUM(column_name = 'actor_user_id' AND column_type = 'int unsigned'
               AND is_nullable = 'NO' AND extra = '') = 1
       AND SUM(column_name = 'created_at' AND column_type = 'datetime'
               AND is_nullable = 'NO'
               AND UPPER(CAST(column_default AS CHAR)) IN
                   ('CURRENT_TIMESTAMP', 'CURRENT_TIMESTAMP()')
               AND UPPER(extra) = 'DEFAULT_GENERATED') = 1
  ) exact_columns
);

SET @time_adjustment_indexes_exact = (
  SELECT COUNT(*) FROM (
    SELECT index_name
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'time_entry_approval_adjustments'
     GROUP BY index_name
    HAVING MIN(index_type) = 'BTREE'
      AND MAX(index_type) = 'BTREE'
      AND SUM(sub_part IS NOT NULL) = 0
      AND (
      (index_name = 'PRIMARY' AND MIN(non_unique) = 0
       AND GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'id')
      OR
      (index_name = 'uq_time_adjustment_tenant_key' AND MIN(non_unique) = 0
       AND GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'tenant_id,adjustment_key')
      OR
      (index_name = 'uq_time_adjustment_entry_version' AND MIN(non_unique) = 0
       AND GROUP_CONCAT(column_name ORDER BY seq_in_index)
           = 'tenant_id,time_entry_id,version_no')
      OR
      (index_name = 'ix_time_adjustment_entry_created' AND MIN(non_unique) = 1
       AND GROUP_CONCAT(column_name ORDER BY seq_in_index)
           = 'tenant_id,time_entry_id,created_at,id')
      OR
      (index_name = 'ix_time_adjustment_actor_created' AND MIN(non_unique) = 1
       AND GROUP_CONCAT(column_name ORDER BY seq_in_index)
           = 'tenant_id,actor_user_id,created_at,id')
      )
  ) exact_indexes
);
SET @time_adjustment_index_total = (
  SELECT COUNT(DISTINCT index_name)
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'time_entry_approval_adjustments'
);

SET @time_adjustment_fks_exact = (
  SELECT COUNT(*) FROM (
    SELECT k.constraint_name
      FROM information_schema.key_column_usage k
      JOIN information_schema.referential_constraints r
        ON r.constraint_schema = k.constraint_schema
       AND r.constraint_name = k.constraint_name
       AND r.table_name = k.table_name
     WHERE k.constraint_schema = DATABASE()
       AND k.table_name = 'time_entry_approval_adjustments'
       AND k.referenced_table_name IS NOT NULL
     GROUP BY k.constraint_name, r.referenced_table_name,
              r.update_rule, r.delete_rule
    HAVING (
      (
        k.constraint_name = 'fk_time_adjustment_tenant'
        AND r.referenced_table_name = 'tenants'
        AND GROUP_CONCAT(CONCAT(k.column_name, '=', k.referenced_column_name)
                         ORDER BY k.ordinal_position) = 'tenant_id=id'
      )
      OR
      (
        k.constraint_name = 'fk_time_adjustment_entry'
        AND r.referenced_table_name = 'time_entries'
        AND GROUP_CONCAT(CONCAT(k.column_name, '=', k.referenced_column_name)
                         ORDER BY k.ordinal_position)
            = 'tenant_id=tenant_id,time_entry_id=id'
      )
      OR
      (
        k.constraint_name = 'fk_time_adjustment_actor'
        AND r.referenced_table_name = 'users'
        AND GROUP_CONCAT(CONCAT(k.column_name, '=', k.referenced_column_name)
                         ORDER BY k.ordinal_position)
            = 'tenant_id=tenant_id,actor_user_id=id'
      )
      )
      AND r.update_rule IN ('RESTRICT', 'NO ACTION')
      AND r.delete_rule IN ('RESTRICT', 'NO ACTION')
  ) exact_fks
);
SET @time_adjustment_fk_total = (
  SELECT COUNT(*)
    FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND table_name = 'time_entry_approval_adjustments'
     AND constraint_type = 'FOREIGN KEY'
);

SET @time_adjustment_checks_exact = (
  SELECT COUNT(*) FROM (
    SELECT constraints_table.constraint_name,
           REPLACE(REPLACE(REPLACE(REPLACE(
             LOWER(checks_table.check_clause), '`', ''), ' ', ''), '(', ''), ')', '') AS normalized_clause
      FROM information_schema.table_constraints constraints_table
      JOIN information_schema.check_constraints checks_table
        ON checks_table.constraint_schema = constraints_table.constraint_schema
       AND checks_table.constraint_name = constraints_table.constraint_name
     WHERE constraints_table.constraint_schema = DATABASE()
       AND constraints_table.table_name = 'time_entry_approval_adjustments'
       AND constraints_table.constraint_type = 'CHECK'
       AND constraints_table.enforced = 'YES'
  ) checks_exact
  WHERE
    (constraint_name = 'ck_time_adjustment_version'
      AND normalized_clause = 'version_no>=1')
    OR
    (constraint_name = 'ck_time_adjustment_minutes'
      AND normalized_clause = 'effective_minutesbetween0and1440')
    OR
    (constraint_name = 'ck_time_adjustment_billable'
      AND normalized_clause IN ('effective_billablein0,1', 'effective_billablein1,0'))
    OR
    (constraint_name = 'ck_time_adjustment_zero_nonbillable'
      AND normalized_clause IN (
        'effective_minutes<>0oreffective_billable=0',
        'effective_billable=0oreffective_minutes<>0'
      ))
    OR
    (constraint_name = 'ck_time_adjustment_reason'
      AND normalized_clause = 'char_lengthtrimreasonbetween1and500')
);
SET @time_adjustment_check_total = (
  SELECT COUNT(*)
    FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND table_name = 'time_entry_approval_adjustments'
     AND constraint_type = 'CHECK'
);
SET @time_adjustment_install_lock_exact = (
  SELECT COUNT(*)
    FROM information_schema.table_constraints constraints_table
    JOIN information_schema.check_constraints checks_table
      ON checks_table.constraint_schema = constraints_table.constraint_schema
     AND checks_table.constraint_name = constraints_table.constraint_name
   WHERE constraints_table.constraint_schema = DATABASE()
     AND constraints_table.table_name = 'time_entry_approval_adjustments'
     AND constraints_table.constraint_type = 'CHECK'
     AND constraints_table.constraint_name = 'ck_time_adjustment_install_lock'
     AND constraints_table.enforced = 'YES'
     AND REPLACE(REPLACE(REPLACE(REPLACE(
           LOWER(checks_table.check_clause), '`', ''), ' ', ''), '(', ''), ')', '') = '0=1'
);

SET @time_adjustment_table_exact = (
  SELECT COUNT(*)
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name = 'time_entry_approval_adjustments'
     AND table_type = 'BASE TABLE'
     AND engine = 'InnoDB'
     AND table_collation LIKE 'utf8mb4%'
);

SET @time_adjustment_structure_ok =
  @time_adjustment_table_exact = 1
  AND @time_adjustment_columns_exact = 1
  AND @time_adjustment_indexes_exact = 5
  AND @time_adjustment_index_total = 5
  AND @time_adjustment_fks_exact = 3
  AND @time_adjustment_fk_total = 3
  AND @time_adjustment_checks_exact = 5
  AND (
    (@time_adjustment_check_total = 5 AND @time_adjustment_install_lock_exact = 0)
    OR
    (@time_adjustment_check_total = 6 AND @time_adjustment_install_lock_exact = 1)
  );
SET @time_adjustment_ddl = IF(
  @time_adjustment_structure_ok,
  'DO 0',
  'SELECT * FROM information_schema.migration_020_malformed_adjustment_structure'
);
PREPARE time_adjustment_statement FROM @time_adjustment_ddl;
EXECUTE time_adjustment_statement;
DEALLOCATE PREPARE time_adjustment_statement;

-- Known permanent and interrupted-replay names are the complete allowlist.
-- Refuse an extra trigger before changing any guard definition.
SET @time_adjustment_unrecognized_trigger_count = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND event_object_table = 'time_entry_approval_adjustments'
     AND trigger_name NOT IN (
       'trg_time_adjustments_before_insert',
       'trg_time_adjustments_no_update',
       'trg_time_adjustments_no_delete',
       'trg_time_adjustment_privilege_preflight',
       'trg_time_020_adjustment_insert_swap',
       'trg_time_020_adjustment_update_swap',
       'trg_time_020_adjustment_delete_swap'
     )
);
SET @time_adjustment_misbound_trigger_count = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'trg_time_adjustments_before_insert',
       'trg_time_adjustments_no_update',
       'trg_time_adjustments_no_delete',
       'trg_time_adjustment_privilege_preflight',
       'trg_time_020_adjustment_insert_swap',
       'trg_time_020_adjustment_update_swap',
       'trg_time_020_adjustment_delete_swap'
     )
     AND event_object_table <> 'time_entry_approval_adjustments'
);
SET @time_adjustment_ddl = IF(
  @time_adjustment_unrecognized_trigger_count = 0
  AND @time_adjustment_misbound_trigger_count = 0,
  'DO 0',
  'SELECT * FROM information_schema.migration_020_unrecognized_adjustment_trigger'
);
PREPARE time_adjustment_statement FROM @time_adjustment_ddl;
EXECUTE time_adjustment_statement;
DEALLOCATE PREPARE time_adjustment_statement;

-- Prove TRIGGER privilege before touching any permanent guard.
DROP TRIGGER IF EXISTS trg_time_adjustment_privilege_preflight;
CREATE TRIGGER trg_time_adjustment_privilege_preflight
BEFORE INSERT ON time_entry_approval_adjustments
FOR EACH ROW
SET @time_adjustment_trigger_privilege_preflight = 1;
DROP TRIGGER trg_time_adjustment_privilege_preflight;

-- If DDL is interrupted after permanent guards are removed, these three
-- blockers keep every write class closed until an exact migration replay.
CREATE TRIGGER IF NOT EXISTS trg_time_020_adjustment_insert_swap
BEFORE INSERT ON time_entry_approval_adjustments
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Approved-time adjustments are locked for migration 020 trigger swap';

CREATE TRIGGER IF NOT EXISTS trg_time_020_adjustment_update_swap
BEFORE UPDATE ON time_entry_approval_adjustments
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Approved-time adjustments are locked for migration 020 trigger swap';

CREATE TRIGGER IF NOT EXISTS trg_time_020_adjustment_delete_swap
BEFORE DELETE ON time_entry_approval_adjustments
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Approved-time adjustments are locked for migration 020 trigger swap';

SET @time_adjustment_swap_count = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND event_object_table = 'time_entry_approval_adjustments'
     AND action_timing = 'BEFORE'
     AND action_orientation = 'ROW'
     AND (
       (trigger_name = 'trg_time_020_adjustment_insert_swap'
         AND event_manipulation = 'INSERT')
       OR
       (trigger_name = 'trg_time_020_adjustment_update_swap'
         AND event_manipulation = 'UPDATE')
       OR
       (trigger_name = 'trg_time_020_adjustment_delete_swap'
         AND event_manipulation = 'DELETE')
     )
     AND REPLACE(REPLACE(REPLACE(REPLACE(
           LOWER(action_statement), ' ', ''), CHAR(9), ''), CHAR(10), ''), CHAR(13), '')
         = 'signalsqlstate''45000''setmessage_text=''approved-timeadjustmentsarelockedformigration020triggerswap'''
);
SET @time_adjustment_ddl = IF(
  @time_adjustment_swap_count = 3,
  'DO 0',
  'SELECT * FROM information_schema.migration_020_incomplete_swap_guards'
);
PREPARE time_adjustment_statement FROM @time_adjustment_ddl;
EXECUTE time_adjustment_statement;
DEALLOCATE PREPARE time_adjustment_statement;

DROP TRIGGER IF EXISTS trg_time_adjustments_before_insert;
DROP TRIGGER IF EXISTS trg_time_adjustments_no_update;
DROP TRIGGER IF EXISTS trg_time_adjustments_no_delete;

DELIMITER $$
CREATE TRIGGER trg_time_adjustments_before_insert
BEFORE INSERT ON time_entry_approval_adjustments
FOR EACH ROW
BEGIN
  DECLARE tenant_found INT DEFAULT 0;
  DECLARE parent_found INT DEFAULT 0;
  DECLARE parent_minutes INT UNSIGNED DEFAULT NULL;
  DECLARE parent_billable TINYINT DEFAULT NULL;
  DECLARE parent_status VARCHAR(16) DEFAULT NULL;
  DECLARE parent_reviewer_id INT UNSIGNED DEFAULT NULL;
  DECLARE parent_reviewed_at DATETIME DEFAULT NULL;
  DECLARE actor_found INT DEFAULT 0;
  DECLARE actor_role VARCHAR(32) DEFAULT NULL;
  DECLARE actor_active TINYINT DEFAULT NULL;
  DECLARE latest_version INT UNSIGNED DEFAULT 0;

  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET tenant_found = 0;
    SELECT 1
      INTO tenant_found
      FROM tenants
     WHERE id = NEW.tenant_id
     FOR UPDATE;
  END;
  IF tenant_found <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Adjustment tenant does not exist';
  END IF;

  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET parent_found = 0;
    SELECT 1, minutes, billable, approval_status,
           reviewed_by_user_id, reviewed_at
      INTO parent_found, parent_minutes, parent_billable, parent_status,
           parent_reviewer_id, parent_reviewed_at
      FROM time_entries
     WHERE tenant_id = NEW.tenant_id
       AND id = NEW.time_entry_id
     FOR UPDATE;
  END;
  IF parent_found <> 1 OR BINARY parent_status <> BINARY 'approved'
     OR parent_reviewer_id IS NULL OR parent_reviewed_at IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Adjustments require approved time with review evidence';
  END IF;

  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET actor_found = 0;
    SELECT 1, role, is_active
      INTO actor_found, actor_role, actor_active
      FROM users
     WHERE tenant_id = NEW.tenant_id
       AND id = NEW.actor_user_id
     FOR UPDATE;
  END;
  IF actor_found <> 1 OR actor_active <> 1
     OR (BINARY actor_role <> BINARY 'owner'
         AND BINARY actor_role <> BINARY 'admin') THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Adjustment actor must be an active owner or admin';
  END IF;

  IF NEW.adjustment_key IS NULL
     OR OCTET_LENGTH(NEW.adjustment_key) NOT BETWEEN 16 AND 64
     OR NOT (BINARY NEW.adjustment_key REGEXP BINARY '^[A-Za-z0-9][A-Za-z0-9._:-]*$') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Adjustment key is not conservative';
  END IF;
  -- Match PHP trim() exactly at the database boundary: space, tab, LF, VT,
  -- CR, and NUL are removed from both ends in any mixture.
  SET NEW.reason = COALESCE(NEW.reason, '');
  time_adjustment_reason_left: WHILE CHAR_LENGTH(NEW.reason) > 0 DO
    IF ASCII(LEFT(NEW.reason, 1)) IN (0, 9, 10, 11, 13, 32) THEN
      SET NEW.reason = SUBSTRING(NEW.reason, 2);
    ELSE
      LEAVE time_adjustment_reason_left;
    END IF;
  END WHILE time_adjustment_reason_left;
  time_adjustment_reason_right: WHILE CHAR_LENGTH(NEW.reason) > 0 DO
    IF ASCII(RIGHT(NEW.reason, 1)) IN (0, 9, 10, 11, 13, 32) THEN
      SET NEW.reason = LEFT(NEW.reason, CHAR_LENGTH(NEW.reason) - 1);
    ELSE
      LEAVE time_adjustment_reason_right;
    END IF;
  END WHILE time_adjustment_reason_right;
  IF CHAR_LENGTH(NEW.reason) NOT BETWEEN 1 AND 500 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Adjustment reason is required';
  END IF;
  IF NEW.effective_minutes > parent_minutes THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Effective minutes exceed original approved time';
  END IF;
  IF parent_billable = 0 AND NEW.effective_billable <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Originally internal time cannot become billable';
  END IF;
  IF NEW.effective_minutes = 0 AND NEW.effective_billable <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zero effective minutes must be nonbillable';
  END IF;

  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET latest_version = 0;
    SELECT version_no
      INTO latest_version
      FROM time_entry_approval_adjustments
     WHERE tenant_id = NEW.tenant_id
       AND time_entry_id = NEW.time_entry_id
     ORDER BY version_no DESC
     LIMIT 1;
  END;
  IF NEW.version_no <> latest_version + 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Adjustment does not follow current adjustment version';
  END IF;

  SET NEW.created_at = UTC_TIMESTAMP();
END$$

CREATE TRIGGER trg_time_adjustments_no_update
BEFORE UPDATE ON time_entry_approval_adjustments
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Approved-time adjustments are immutable';
END$$

CREATE TRIGGER trg_time_adjustments_no_delete
BEFORE DELETE ON time_entry_approval_adjustments
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Approved-time adjustments cannot be deleted';
END$$
DELIMITER ;

-- Permanent INSERT/UPDATE/DELETE guards now exist. Remove the temporary
-- table-level lock while the three swap blockers still fail closed.
SET @time_adjustment_install_lock_ddl = IF(
  (SELECT COUNT(*)
     FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE()
      AND table_name = 'time_entry_approval_adjustments'
      AND constraint_type = 'CHECK'
      AND constraint_name = 'ck_time_adjustment_install_lock') = 1,
  'ALTER TABLE time_entry_approval_adjustments DROP CHECK ck_time_adjustment_install_lock',
  'DO 0'
);
PREPARE time_adjustment_statement FROM @time_adjustment_install_lock_ddl;
EXECUTE time_adjustment_statement;
DEALLOCATE PREPARE time_adjustment_statement;

DROP TRIGGER IF EXISTS trg_time_020_adjustment_insert_swap;
DROP TRIGGER IF EXISTS trg_time_020_adjustment_update_swap;
DROP TRIGGER IF EXISTS trg_time_020_adjustment_delete_swap;

SET @time_adjustment_permanent_count = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'trg_time_adjustments_before_insert',
       'trg_time_adjustments_no_update',
       'trg_time_adjustments_no_delete'
     )
);
SET @time_adjustment_stage_count = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'trg_time_adjustment_privilege_preflight',
       'trg_time_020_adjustment_insert_swap',
       'trg_time_020_adjustment_update_swap',
       'trg_time_020_adjustment_delete_swap'
     )
);
SET @time_adjustment_trigger_total = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND event_object_table = 'time_entry_approval_adjustments'
);
SET @time_adjustment_check_total = (
  SELECT COUNT(*)
    FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND table_name = 'time_entry_approval_adjustments'
     AND constraint_type = 'CHECK'
);
SET @time_adjustment_install_lock_remaining = (
  SELECT COUNT(*)
    FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND table_name = 'time_entry_approval_adjustments'
     AND constraint_type = 'CHECK'
     AND constraint_name = 'ck_time_adjustment_install_lock'
);
SET @time_adjustment_ddl = IF(
  @time_adjustment_permanent_count = 3
  AND @time_adjustment_stage_count = 0
  AND @time_adjustment_trigger_total = 3
  AND @time_adjustment_check_total = 5
  AND @time_adjustment_install_lock_remaining = 0,
  'DO 0',
  'SELECT * FROM information_schema.migration_020_incomplete_permanent_guards'
);
PREPARE time_adjustment_statement FROM @time_adjustment_ddl;
EXECUTE time_adjustment_statement;
DEALLOCATE PREPARE time_adjustment_statement;

SELECT
  @time_adjustment_columns_exact AS columns_exact,
  @time_adjustment_indexes_exact AS indexes_exact,
  @time_adjustment_fks_exact AS foreign_keys_exact,
  @time_adjustment_checks_exact AS checks_exact,
  @time_adjustment_permanent_count AS permanent_trigger_count,
  @time_adjustment_stage_count AS staging_trigger_count,
  @time_adjustment_trigger_total AS trigger_total;
