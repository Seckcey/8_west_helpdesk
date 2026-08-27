-- 016_time_corrections_overlap.sql — rejected-time lineage and race-safe
-- measured-interval overlap prevention for MySQL 8 / InnoDB.
--
-- The migration is additive. Existing time facts and audit events are never
-- rewritten. A correction points to one rejected predecessor and is itself a
-- new pending row. Persistent UTC-day guard rows serialize overlapping timer
-- inserts before a locking read of the database-owned interval registry.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- One nullable self-reference preserves the rejected row while linking its
-- single append-only replacement.
SET @time_correction_column_count = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'corrects_time_entry_id'
);
SET @time_correction_column_exact = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'corrects_time_entry_id'
     AND column_type = 'int unsigned' AND is_nullable = 'YES'
     AND column_default IS NULL AND extra = ''
);
SET @time_ddl = IF(
  @time_correction_column_count = 0 OR @time_correction_column_exact = 0,
  'ALTER TABLE time_entries ADD COLUMN corrects_time_entry_id INT UNSIGNED NULL AFTER review_note',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_correction_index_count = (
  SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND index_name = 'uq_time_entries_one_correction'
);
SET @time_correction_index_exact = (
  SELECT COUNT(*) FROM (
    SELECT index_name
      FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'time_entries'
       AND index_name = 'uq_time_entries_one_correction'
     GROUP BY index_name
    HAVING COUNT(*) = 2
       AND MIN(non_unique) = 0 AND MAX(non_unique) = 0
       AND MIN(index_type) = 'BTREE' AND MAX(index_type) = 'BTREE'
       AND SUM(sub_part IS NOT NULL) = 0
       AND GROUP_CONCAT(column_name ORDER BY seq_in_index)
           = 'tenant_id,corrects_time_entry_id'
  ) exact_index
);
SET @time_ddl = IF(
  @time_correction_index_count = 0 OR @time_correction_index_exact = 0,
  'ALTER TABLE time_entries ADD UNIQUE KEY uq_time_entries_one_correction (tenant_id, corrects_time_entry_id)',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_correction_fk_count = (
  SELECT COUNT(DISTINCT constraint_name)
    FROM information_schema.key_column_usage
   WHERE constraint_schema = DATABASE() AND table_name = 'time_entries'
     AND constraint_name = 'fk_time_entries_correction_tenant'
);
SET @time_correction_fk_exact = (
  SELECT COUNT(*) FROM (
    SELECT k.constraint_name
      FROM information_schema.key_column_usage k
      JOIN information_schema.referential_constraints r
        ON r.constraint_schema = k.constraint_schema
       AND r.constraint_name = k.constraint_name
       AND r.table_name = k.table_name
     WHERE k.constraint_schema = DATABASE() AND k.table_name = 'time_entries'
       AND k.constraint_name = 'fk_time_entries_correction_tenant'
     GROUP BY k.constraint_name, r.referenced_table_name,
              r.update_rule, r.delete_rule
    HAVING COUNT(*) = 2
       AND r.referenced_table_name = 'time_entries'
       AND GROUP_CONCAT(
             CONCAT(k.column_name, '=', k.referenced_column_name)
             ORDER BY k.ordinal_position
           ) = 'tenant_id=tenant_id,corrects_time_entry_id=id'
       AND r.update_rule IN ('RESTRICT', 'NO ACTION')
       AND r.delete_rule IN ('RESTRICT', 'NO ACTION')
  ) exact_fk
);
SET @time_ddl = IF(
  @time_correction_fk_count = 0 OR @time_correction_fk_exact = 0,
  'ALTER TABLE time_entries ADD CONSTRAINT fk_time_entries_correction_tenant FOREIGN KEY (tenant_id, corrects_time_entry_id) REFERENCES time_entries (tenant_id, id)',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

CREATE TABLE IF NOT EXISTS time_entry_interval_guards (
  tenant_id  INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  guard_date DATE NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id, user_id, guard_date),
  CONSTRAINT fk_time_interval_guards_tenant FOREIGN KEY (tenant_id)
    REFERENCES tenants (id),
  CONSTRAINT fk_time_interval_guards_user FOREIGN KEY (tenant_id, user_id)
    REFERENCES users (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS time_entry_measured_intervals (
  tenant_id       INT UNSIGNED NOT NULL,
  time_entry_id   INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  started_at      DATETIME NOT NULL,
  ended_at        DATETIME NOT NULL,
  approval_status ENUM('pending','approved','rejected') NOT NULL,
  PRIMARY KEY (tenant_id, time_entry_id),
  KEY ix_time_measured_overlap
    (tenant_id, user_id, approval_status, started_at, ended_at, time_entry_id),
  CONSTRAINT ck_time_measured_positive CHECK (ended_at > started_at),
  CONSTRAINT fk_time_measured_entry FOREIGN KEY (tenant_id, time_entry_id)
    REFERENCES time_entries (tenant_id, id),
  CONSTRAINT fk_time_measured_user FOREIGN KEY (tenant_id, user_id)
    REFERENCES users (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Same-named partial tables are not accepted as successful replays.
SET @time_guard_columns_exact = (
  SELECT COUNT(*) = 4
     AND SUM(column_name = 'tenant_id' AND column_type = 'int unsigned'
             AND is_nullable = 'NO') = 1
     AND SUM(column_name = 'user_id' AND column_type = 'int unsigned'
             AND is_nullable = 'NO') = 1
     AND SUM(column_name = 'guard_date' AND column_type = 'date'
             AND is_nullable = 'NO') = 1
     AND SUM(column_name = 'created_at' AND column_type = 'datetime'
             AND is_nullable = 'NO' AND UPPER(column_default) = 'CURRENT_TIMESTAMP') = 1
    FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entry_interval_guards'
);
SET @time_interval_columns_exact = (
  SELECT COUNT(*) = 6
     AND SUM(column_name = 'tenant_id' AND column_type = 'int unsigned'
             AND is_nullable = 'NO') = 1
     AND SUM(column_name = 'time_entry_id' AND column_type = 'int unsigned'
             AND is_nullable = 'NO') = 1
     AND SUM(column_name = 'user_id' AND column_type = 'int unsigned'
             AND is_nullable = 'NO') = 1
     AND SUM(column_name = 'started_at' AND column_type = 'datetime'
             AND is_nullable = 'NO') = 1
     AND SUM(column_name = 'ended_at' AND column_type = 'datetime'
             AND is_nullable = 'NO') = 1
     AND SUM(column_name = 'approval_status'
             AND column_type = 'enum(''pending'',''approved'',''rejected'')'
             AND is_nullable = 'NO') = 1
    FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entry_measured_intervals'
);
SET @time_aux_indexes_exact = (
  SELECT COUNT(*) FROM (
    SELECT table_name, index_name
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND ((table_name = 'time_entry_interval_guards' AND index_name = 'PRIMARY')
         OR (table_name = 'time_entry_measured_intervals'
             AND index_name IN ('PRIMARY', 'ix_time_measured_overlap')))
     GROUP BY table_name, index_name
    HAVING MIN(index_type) = 'BTREE' AND MAX(index_type) = 'BTREE'
       AND SUM(sub_part IS NOT NULL) = 0
       AND (
         (table_name = 'time_entry_interval_guards' AND index_name = 'PRIMARY'
          AND MIN(non_unique) = 0
          AND GROUP_CONCAT(column_name ORDER BY seq_in_index)
              = 'tenant_id,user_id,guard_date')
         OR
         (table_name = 'time_entry_measured_intervals' AND index_name = 'PRIMARY'
          AND MIN(non_unique) = 0
          AND GROUP_CONCAT(column_name ORDER BY seq_in_index)
              = 'tenant_id,time_entry_id')
         OR
         (table_name = 'time_entry_measured_intervals'
          AND index_name = 'ix_time_measured_overlap'
          AND MIN(non_unique) = 1
          AND GROUP_CONCAT(column_name ORDER BY seq_in_index)
              = 'tenant_id,user_id,approval_status,started_at,ended_at,time_entry_id')
       )
  ) exact_indexes
);
SET @time_aux_fks_exact = (
  SELECT COUNT(*) FROM (
    SELECT table_name, constraint_name, referenced_table_name,
           GROUP_CONCAT(
             CONCAT(column_name, '=', referenced_column_name)
             ORDER BY ordinal_position
           ) AS columns_csv
      FROM information_schema.key_column_usage
     WHERE constraint_schema = DATABASE()
       AND referenced_table_name IS NOT NULL
       AND table_name IN ('time_entry_interval_guards', 'time_entry_measured_intervals')
     GROUP BY table_name, constraint_name, referenced_table_name
    HAVING (table_name = 'time_entry_interval_guards'
            AND constraint_name = 'fk_time_interval_guards_tenant'
            AND referenced_table_name = 'tenants' AND columns_csv = 'tenant_id=id')
        OR (table_name = 'time_entry_interval_guards'
            AND constraint_name = 'fk_time_interval_guards_user'
            AND referenced_table_name = 'users'
            AND columns_csv = 'tenant_id=tenant_id,user_id=id')
        OR (table_name = 'time_entry_measured_intervals'
            AND constraint_name = 'fk_time_measured_entry'
            AND referenced_table_name = 'time_entries'
            AND columns_csv = 'tenant_id=tenant_id,time_entry_id=id')
        OR (table_name = 'time_entry_measured_intervals'
            AND constraint_name = 'fk_time_measured_user'
            AND referenced_table_name = 'users'
            AND columns_csv = 'tenant_id=tenant_id,user_id=id')
  ) exact_fks
);
SET @time_positive_check_exact = (
  SELECT COUNT(*) FROM information_schema.check_constraints c
  JOIN information_schema.table_constraints t
    ON t.constraint_schema = c.constraint_schema
   AND t.constraint_name = c.constraint_name
 WHERE c.constraint_schema = DATABASE()
   AND t.table_name = 'time_entry_measured_intervals'
   AND c.constraint_name = 'ck_time_measured_positive'
   AND t.enforced = 'YES'
   AND REPLACE(REPLACE(REPLACE(LOWER(c.check_clause), '`', ''), ' ', ''), '(', '')
       LIKE '%ended_at>started_at%'
);
SET @time_structure_ok =
  @time_guard_columns_exact = 1
  AND @time_interval_columns_exact = 1
  AND @time_aux_indexes_exact = 3
  AND @time_aux_fks_exact = 4
  AND @time_positive_check_exact = 1;
SET @time_ddl = IF(
  @time_structure_ok,
  'DO 0',
  'SELECT * FROM information_schema.migration_016_malformed_time_structure'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

-- Prove trigger privilege before changing any permanent guard.
DROP TRIGGER IF EXISTS trg_time_correction_privilege_preflight;
CREATE TRIGGER trg_time_correction_privilege_preflight
BEFORE INSERT ON time_entries
FOR EACH ROW
SET @time_correction_trigger_privilege_preflight = 1;
DROP TRIGGER trg_time_correction_privilege_preflight;

-- Replays may already have live auxiliary rows and broad runtime INSERT/UPDATE
-- grants. Five fail-closed guards protect those rows before any permanent
-- auxiliary trigger is dropped. Parent time writes that need the registry also
-- roll back cleanly until exact replay completes this short replacement.
CREATE TRIGGER IF NOT EXISTS trg_time_016_guard_update_aux_swap
BEFORE UPDATE ON time_entry_interval_guards
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Time guards are locked for migration 016 auxiliary trigger swap';

CREATE TRIGGER IF NOT EXISTS trg_time_016_guard_delete_aux_swap
BEFORE DELETE ON time_entry_interval_guards
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Time guards are locked for migration 016 auxiliary trigger swap';

CREATE TRIGGER IF NOT EXISTS trg_time_016_measured_insert_aux_swap
BEFORE INSERT ON time_entry_measured_intervals
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Measured time is locked for migration 016 auxiliary trigger swap';

CREATE TRIGGER IF NOT EXISTS trg_time_016_measured_update_aux_swap
BEFORE UPDATE ON time_entry_measured_intervals
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Measured time is locked for migration 016 auxiliary trigger swap';

CREATE TRIGGER IF NOT EXISTS trg_time_016_measured_delete_aux_swap
BEFORE DELETE ON time_entry_measured_intervals
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Measured time is locked for migration 016 auxiliary trigger swap';

SET @time_aux_swap_guard_count = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'trg_time_016_guard_update_aux_swap',
       'trg_time_016_guard_delete_aux_swap',
       'trg_time_016_measured_insert_aux_swap',
       'trg_time_016_measured_update_aux_swap',
       'trg_time_016_measured_delete_aux_swap'
     )
     AND action_statement LIKE '%migration 016 auxiliary trigger swap%'
);
SET @time_ddl = IF(
  @time_aux_swap_guard_count = 5,
  'DO 0',
  'SELECT * FROM information_schema.migration_016_incomplete_aux_swap_guards'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

-- Auxiliary rows are written only by the parent time-entry triggers. Direct
-- mutation cannot weaken the overlap check or rewrite its mirror.
DROP TRIGGER IF EXISTS trg_time_interval_guards_no_update;
DROP TRIGGER IF EXISTS trg_time_interval_guards_no_delete;
DROP TRIGGER IF EXISTS trg_time_measured_before_insert;
DROP TRIGGER IF EXISTS trg_time_measured_before_update;
DROP TRIGGER IF EXISTS trg_time_measured_no_delete;

DELIMITER $$
CREATE TRIGGER trg_time_interval_guards_no_update
BEFORE UPDATE ON time_entry_interval_guards
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time interval guard rows are immutable';
END$$

CREATE TRIGGER trg_time_interval_guards_no_delete
BEFORE DELETE ON time_entry_interval_guards
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time interval guard rows cannot be deleted';
END$$

CREATE TRIGGER trg_time_measured_before_insert
BEFORE INSERT ON time_entry_measured_intervals
FOR EACH ROW
BEGIN
  DECLARE parent_count INT DEFAULT 0;
  DECLARE parent_user_id INT UNSIGNED DEFAULT NULL;
  DECLARE parent_started_at DATETIME DEFAULT NULL;
  DECLARE parent_ended_at DATETIME DEFAULT NULL;
  DECLARE parent_status VARCHAR(16) DEFAULT NULL;

  SELECT COUNT(*), MAX(user_id), MAX(started_at), MAX(ended_at),
         MAX(approval_status)
    INTO parent_count, parent_user_id, parent_started_at, parent_ended_at,
         parent_status
    FROM time_entries
   WHERE tenant_id = NEW.tenant_id
     AND id = NEW.time_entry_id;
  IF parent_count <> 1
     OR NOT (parent_user_id <=> NEW.user_id)
     OR NOT (parent_started_at <=> NEW.started_at)
     OR NOT (parent_ended_at <=> NEW.ended_at)
     OR NOT (BINARY parent_status <=> BINARY NEW.approval_status) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Measured time registry must match its immutable parent';
  END IF;
END$$

CREATE TRIGGER trg_time_measured_before_update
BEFORE UPDATE ON time_entry_measured_intervals
FOR EACH ROW
BEGIN
  DECLARE parent_status VARCHAR(16) DEFAULT NULL;

  IF NOT (
       NEW.tenant_id <=> OLD.tenant_id
   AND NEW.time_entry_id <=> OLD.time_entry_id
   AND NEW.user_id <=> OLD.user_id
   AND NEW.started_at <=> OLD.started_at
   AND NEW.ended_at <=> OLD.ended_at
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Measured time registry facts are immutable';
  END IF;
  SELECT approval_status
    INTO parent_status
    FROM time_entries
   WHERE tenant_id = NEW.tenant_id
     AND id = NEW.time_entry_id;
  IF parent_status IS NULL OR BINARY parent_status <> BINARY NEW.approval_status THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Measured time registry status must match its parent';
  END IF;
  IF OLD.approval_status <> 'pending'
     OR NEW.approval_status NOT IN ('approved', 'rejected') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Measured time registry permits one review transition';
  END IF;
END$$

CREATE TRIGGER trg_time_measured_no_delete
BEFORE DELETE ON time_entry_measured_intervals
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Measured time registry rows cannot be deleted';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_time_016_guard_update_aux_swap;
DROP TRIGGER IF EXISTS trg_time_016_guard_delete_aux_swap;
DROP TRIGGER IF EXISTS trg_time_016_measured_insert_aux_swap;
DROP TRIGGER IF EXISTS trg_time_016_measured_update_aux_swap;
DROP TRIGGER IF EXISTS trg_time_016_measured_delete_aux_swap;

-- Staging triggers close the live-write window while existing intervals are
-- validated and mirrored. IF NOT EXISTS makes an interrupted exact retry keep
-- the already-active guards instead of briefly dropping them.
DELIMITER $$
CREATE TRIGGER IF NOT EXISTS trg_time_overlap_stage_before_insert
BEFORE INSERT ON time_entries
FOR EACH ROW FOLLOWS trg_time_entries_before_insert
BEGIN
  DECLARE correction_count INT DEFAULT 0;
  DECLARE correction_status VARCHAR(16) DEFAULT NULL;
  DECLARE correction_ticket_id INT UNSIGNED DEFAULT NULL;
  DECLARE correction_client_id INT UNSIGNED DEFAULT NULL;
  DECLARE correction_user_id INT UNSIGNED DEFAULT NULL;
  DECLARE correction_source VARCHAR(16) DEFAULT NULL;
  DECLARE locked_guard_date DATE DEFAULT NULL;
  DECLARE conflicting_time_entry_id INT UNSIGNED DEFAULT NULL;
  DECLARE historical_conflict_id INT UNSIGNED DEFAULT NULL;

  SET @safeharbor_time_stage_016 = 1;
  IF NEW.corrects_time_entry_id IS NOT NULL THEN
    SELECT COUNT(*), MAX(approval_status), MAX(ticket_id), MAX(client_id),
           MAX(user_id), MAX(source)
      INTO correction_count, correction_status, correction_ticket_id,
           correction_client_id, correction_user_id, correction_source
      FROM time_entries
     WHERE tenant_id = NEW.tenant_id
       AND id = NEW.corrects_time_entry_id;
    IF correction_count <> 1 OR BINARY correction_status <> BINARY 'rejected' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only rejected time entries may be corrected';
    END IF;
    IF correction_user_id <> NEW.user_id THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry correction technician must match';
    END IF;
    IF correction_ticket_id <> NEW.ticket_id OR correction_client_id <> NEW.client_id THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry correction ticket and client must match';
    END IF;
    IF BINARY correction_source <> BINARY NEW.source THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry correction source must match';
    END IF;
  END IF;

  IF NEW.started_at IS NOT NULL THEN
    IF NEW.ended_at IS NULL OR NEW.ended_at <= NEW.started_at THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry interval is invalid';
    END IF;
    INSERT IGNORE INTO time_entry_interval_guards
      (tenant_id, user_id, guard_date)
    VALUES (NEW.tenant_id, NEW.user_id, DATE(NEW.started_at));
    IF DATE(DATE_SUB(NEW.ended_at, INTERVAL 1 SECOND)) <> DATE(NEW.started_at) THEN
      INSERT IGNORE INTO time_entry_interval_guards
        (tenant_id, user_id, guard_date)
      VALUES (NEW.tenant_id, NEW.user_id,
              DATE(DATE_SUB(NEW.ended_at, INTERVAL 1 SECOND)));
    END IF;
    SELECT guard_date INTO locked_guard_date
      FROM time_entry_interval_guards
     WHERE tenant_id = NEW.tenant_id AND user_id = NEW.user_id
       AND guard_date = DATE(NEW.started_at)
     FOR UPDATE;
    IF DATE(DATE_SUB(NEW.ended_at, INTERVAL 1 SECOND)) <> DATE(NEW.started_at) THEN
      SELECT guard_date INTO locked_guard_date
        FROM time_entry_interval_guards
       WHERE tenant_id = NEW.tenant_id AND user_id = NEW.user_id
         AND guard_date = DATE(DATE_SUB(NEW.ended_at, INTERVAL 1 SECOND))
       FOR UPDATE;
    END IF;

    SET conflicting_time_entry_id = NULL;
    SELECT time_entry_id INTO conflicting_time_entry_id
      FROM time_entry_measured_intervals
     WHERE tenant_id = NEW.tenant_id AND user_id = NEW.user_id
       AND approval_status IN ('pending', 'approved')
       AND started_at < NEW.ended_at AND ended_at > NEW.started_at
     ORDER BY started_at, time_entry_id LIMIT 1 FOR UPDATE;
    IF conflicting_time_entry_id IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Measured time overlaps existing pending or approved time';
    END IF;

    SET historical_conflict_id = NULL;
    SELECT id INTO historical_conflict_id
      FROM time_entries
     WHERE tenant_id = NEW.tenant_id AND user_id = NEW.user_id
       AND approval_status IN ('pending', 'approved')
       AND started_at IS NOT NULL
       AND started_at < NEW.ended_at AND ended_at > NEW.started_at
     ORDER BY started_at, id LIMIT 1;
    IF historical_conflict_id IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Measured time overlaps existing pending or approved time';
    END IF;
  END IF;
END$$

CREATE TRIGGER IF NOT EXISTS trg_time_overlap_stage_after_insert
AFTER INSERT ON time_entries
FOR EACH ROW FOLLOWS trg_time_entries_after_insert
BEGIN
  SET @safeharbor_time_stage_016 = 1;
  IF NEW.started_at IS NOT NULL THEN
    INSERT IGNORE INTO time_entry_measured_intervals
      (tenant_id, time_entry_id, user_id, started_at, ended_at, approval_status)
    VALUES
      (NEW.tenant_id, NEW.id, NEW.user_id, NEW.started_at, NEW.ended_at,
       NEW.approval_status);
  END IF;
END$$

CREATE TRIGGER IF NOT EXISTS trg_time_overlap_stage_after_update
AFTER UPDATE ON time_entries
FOR EACH ROW FOLLOWS trg_time_entries_after_update
BEGIN
  SET @safeharbor_time_stage_016 = 1;
  IF NEW.started_at IS NOT NULL THEN
    UPDATE time_entry_measured_intervals
       SET approval_status = NEW.approval_status
     WHERE tenant_id = NEW.tenant_id AND time_entry_id = NEW.id
       AND approval_status <> NEW.approval_status;
  END IF;
END$$
DELIMITER ;

SET @time_stage_trigger_count = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'trg_time_overlap_stage_before_insert',
       'trg_time_overlap_stage_after_insert',
       'trg_time_overlap_stage_after_update'
     )
     AND action_statement LIKE '%safeharbor_time_stage_016%'
);
SET @time_ddl = IF(
  @time_stage_trigger_count = 3,
  'DO 0',
  'SELECT * FROM information_schema.migration_016_invalid_staging_guards'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

-- Refuse ambiguous existing facts instead of choosing which overlapping row
-- should win. Production currently has no measured rows, but upgrade safety is
-- part of the contract rather than a production-data assumption.
SET @time_invalid_interval_count = (
  SELECT COUNT(*) FROM time_entries
   WHERE (started_at IS NULL) <> (ended_at IS NULL)
      OR (started_at IS NOT NULL AND ended_at <= started_at)
);
SET @time_existing_overlap_count = (
  SELECT COUNT(*)
    FROM time_entries a
    JOIN time_entries b
      ON b.tenant_id = a.tenant_id
     AND b.user_id = a.user_id
     AND b.id > a.id
     AND b.approval_status IN ('pending', 'approved')
     AND b.started_at IS NOT NULL
     AND b.started_at < a.ended_at
     AND b.ended_at > a.started_at
   WHERE a.approval_status IN ('pending', 'approved')
     AND a.started_at IS NOT NULL
);
SET @time_invalid_correction_count = (
  SELECT COUNT(*)
    FROM time_entries replacement
    LEFT JOIN time_entries rejected
      ON rejected.tenant_id = replacement.tenant_id
     AND rejected.id = replacement.corrects_time_entry_id
   WHERE replacement.corrects_time_entry_id IS NOT NULL
     AND (rejected.id IS NULL OR rejected.approval_status <> 'rejected'
       OR rejected.user_id <> replacement.user_id
       OR rejected.ticket_id <> replacement.ticket_id
       OR rejected.client_id <> replacement.client_id
       OR rejected.source <> replacement.source)
);
SET @time_ddl = IF(
  @time_invalid_interval_count = 0
  AND @time_existing_overlap_count = 0
  AND @time_invalid_correction_count = 0,
  'DO 0',
  'SELECT * FROM information_schema.migration_016_ambiguous_existing_time'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

INSERT IGNORE INTO time_entry_interval_guards
  (tenant_id, user_id, guard_date)
SELECT tenant_id, user_id, DATE(started_at)
  FROM time_entries
 WHERE started_at IS NOT NULL;

INSERT IGNORE INTO time_entry_interval_guards
  (tenant_id, user_id, guard_date)
SELECT tenant_id, user_id, DATE(DATE_SUB(ended_at, INTERVAL 1 SECOND))
  FROM time_entries
 WHERE started_at IS NOT NULL
   AND DATE(DATE_SUB(ended_at, INTERVAL 1 SECOND)) <> DATE(started_at);

INSERT IGNORE INTO time_entry_measured_intervals
  (tenant_id, time_entry_id, user_id, started_at, ended_at, approval_status)
SELECT tenant_id, id, user_id, started_at, ended_at, approval_status
  FROM time_entries
 WHERE started_at IS NOT NULL;

SET @time_measured_parent_count = (
  SELECT COUNT(*) FROM time_entries WHERE started_at IS NOT NULL
);
SET @time_measured_registry_count = (
  SELECT COUNT(*) FROM time_entry_measured_intervals
);
SET @time_measured_mismatch_count = (
  SELECT COUNT(*)
    FROM time_entry_measured_intervals measured
    LEFT JOIN time_entries parent
      ON parent.tenant_id = measured.tenant_id
     AND parent.id = measured.time_entry_id
   WHERE parent.id IS NULL
      OR parent.user_id <> measured.user_id
      OR parent.started_at <> measured.started_at
      OR parent.ended_at <> measured.ended_at
      OR parent.approval_status <> measured.approval_status
);
SET @time_ddl = IF(
  @time_measured_parent_count = @time_measured_registry_count
  AND @time_measured_mismatch_count = 0,
  'DO 0',
  'SELECT * FROM information_schema.migration_016_incomplete_measured_registry'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

-- The overlap staging triggers preserve ordinary writes while the backfill is
-- running. Immediately before replacing the seven approval/audit guards, add
-- five fail-closed swap guards. If DDL is interrupted after this point, every
-- time insert/update/delete and every audit-event rewrite/delete remains
-- blocked until an exact migration replay finishes the replacement.
CREATE TRIGGER IF NOT EXISTS trg_time_016_insert_swap_guard
BEFORE INSERT ON time_entries
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Time writes are locked for migration 016 trigger swap';

CREATE TRIGGER IF NOT EXISTS trg_time_016_update_swap_guard
BEFORE UPDATE ON time_entries
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Time writes are locked for migration 016 trigger swap';

CREATE TRIGGER IF NOT EXISTS trg_time_016_delete_swap_guard
BEFORE DELETE ON time_entries
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Time writes are locked for migration 016 trigger swap';

CREATE TRIGGER IF NOT EXISTS trg_time_016_event_update_swap_guard
BEFORE UPDATE ON time_entry_events
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Time audit writes are locked for migration 016 trigger swap';

CREATE TRIGGER IF NOT EXISTS trg_time_016_event_delete_swap_guard
BEFORE DELETE ON time_entry_events
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Time audit writes are locked for migration 016 trigger swap';

SET @time_swap_guard_count = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'trg_time_016_insert_swap_guard',
       'trg_time_016_update_swap_guard',
       'trg_time_016_delete_swap_guard',
       'trg_time_016_event_update_swap_guard',
       'trg_time_016_event_delete_swap_guard'
     )
     AND action_statement LIKE '%migration 016 trigger swap%'
);
SET @time_ddl = IF(
  @time_swap_guard_count = 5,
  'DO 0',
  'SELECT * FROM information_schema.migration_016_incomplete_swap_guards'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

-- Replace the seven original guards only while the five swap guards deny all
-- competing writes. The new guards are installed before those blockers are
-- removed.
DROP TRIGGER IF EXISTS trg_time_entries_before_insert;
DROP TRIGGER IF EXISTS trg_time_entries_after_insert;
DROP TRIGGER IF EXISTS trg_time_entries_before_update;
DROP TRIGGER IF EXISTS trg_time_entries_after_update;
DROP TRIGGER IF EXISTS trg_time_entries_no_delete;
DROP TRIGGER IF EXISTS trg_time_entry_events_no_update;
DROP TRIGGER IF EXISTS trg_time_entry_events_no_delete;

DELIMITER $$
CREATE TRIGGER trg_time_entries_before_insert
BEFORE INSERT ON time_entries
FOR EACH ROW
BEGIN
  DECLARE ticket_tenant_id INT UNSIGNED;
  DECLARE ticket_client_id INT UNSIGNED;
  DECLARE correction_count INT DEFAULT 0;
  DECLARE correction_status VARCHAR(16) DEFAULT NULL;
  DECLARE correction_ticket_id INT UNSIGNED DEFAULT NULL;
  DECLARE correction_client_id INT UNSIGNED DEFAULT NULL;
  DECLARE correction_user_id INT UNSIGNED DEFAULT NULL;
  DECLARE correction_source VARCHAR(16) DEFAULT NULL;
  DECLARE locked_guard_date DATE DEFAULT NULL;
  DECLARE conflicting_time_entry_id INT UNSIGNED DEFAULT NULL;

  SELECT tenant_id, client_id
    INTO ticket_tenant_id, ticket_client_id
    FROM tickets
   WHERE id = NEW.ticket_id;
  IF ticket_tenant_id IS NULL OR ticket_client_id IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry ticket does not exist';
  END IF;
  IF NEW.tenant_id IS NOT NULL AND NEW.tenant_id <> ticket_tenant_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry tenant must match ticket';
  END IF;
  IF NEW.client_id IS NOT NULL AND NEW.client_id <> ticket_client_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry client must match ticket';
  END IF;
  SET NEW.tenant_id = ticket_tenant_id;
  SET NEW.client_id = ticket_client_id;
  SET NEW.entry_key = COALESCE(NULLIF(TRIM(NEW.entry_key), ''), CONCAT('legacy:', UUID()));
  SET NEW.source = COALESCE(NEW.source, 'legacy');
  SET NEW.worked_at = COALESCE(NEW.worked_at, UTC_TIMESTAMP());
  SET NEW.approval_status = COALESCE(NEW.approval_status, 'pending');
  SET NEW.review_note = COALESCE(NEW.review_note, '');

  IF NEW.approval_status <> 'pending' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'New time entries must be pending';
  END IF;
  IF NEW.reviewed_by_user_id IS NOT NULL OR NEW.reviewed_at IS NOT NULL
     OR NEW.review_note <> '' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'New time entries cannot be pre-reviewed';
  END IF;
  IF (NEW.started_at IS NULL) <> (NEW.ended_at IS NULL)
     OR (NEW.started_at IS NOT NULL AND NEW.ended_at <= NEW.started_at) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry interval is invalid';
  END IF;
  IF NEW.source = 'timer' AND NEW.started_at IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Timer time requires start and end evidence';
  END IF;
  IF NEW.source = 'suggestion' AND NEW.started_at IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Suggested time cannot claim timer evidence';
  END IF;

  IF NEW.corrects_time_entry_id IS NOT NULL THEN
    SELECT COUNT(*), MAX(approval_status), MAX(ticket_id), MAX(client_id),
           MAX(user_id), MAX(source)
      INTO correction_count, correction_status, correction_ticket_id,
           correction_client_id, correction_user_id, correction_source
      FROM time_entries
     WHERE tenant_id = NEW.tenant_id AND id = NEW.corrects_time_entry_id;
    IF correction_count <> 1 OR BINARY correction_status <> BINARY 'rejected' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only rejected time entries may be corrected';
    END IF;
    IF correction_user_id <> NEW.user_id THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry correction technician must match';
    END IF;
    IF correction_ticket_id <> NEW.ticket_id OR correction_client_id <> NEW.client_id THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry correction ticket and client must match';
    END IF;
    IF BINARY correction_source <> BINARY NEW.source THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry correction source must match';
    END IF;
  END IF;

  IF NEW.started_at IS NOT NULL THEN
    IF TIMESTAMPDIFF(SECOND, NEW.started_at, NEW.ended_at) > 86400 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry interval cannot exceed 24 hours';
    END IF;
    IF NEW.worked_at < NEW.started_at OR NEW.worked_at > NEW.ended_at THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Worked time must fall inside measured interval';
    END IF;
    IF ABS(NEW.minutes - ROUND(TIMESTAMPDIFF(SECOND, NEW.started_at, NEW.ended_at) / 60.0)) > 1 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Minutes must match measured interval';
    END IF;
    INSERT IGNORE INTO time_entry_interval_guards
      (tenant_id, user_id, guard_date)
    VALUES (NEW.tenant_id, NEW.user_id, DATE(NEW.started_at));
    IF DATE(DATE_SUB(NEW.ended_at, INTERVAL 1 SECOND)) <> DATE(NEW.started_at) THEN
      INSERT IGNORE INTO time_entry_interval_guards
        (tenant_id, user_id, guard_date)
      VALUES (NEW.tenant_id, NEW.user_id,
              DATE(DATE_SUB(NEW.ended_at, INTERVAL 1 SECOND)));
    END IF;
    SELECT guard_date INTO locked_guard_date
      FROM time_entry_interval_guards
     WHERE tenant_id = NEW.tenant_id AND user_id = NEW.user_id
       AND guard_date = DATE(NEW.started_at)
     FOR UPDATE;
    IF DATE(DATE_SUB(NEW.ended_at, INTERVAL 1 SECOND)) <> DATE(NEW.started_at) THEN
      SELECT guard_date INTO locked_guard_date
        FROM time_entry_interval_guards
       WHERE tenant_id = NEW.tenant_id AND user_id = NEW.user_id
         AND guard_date = DATE(DATE_SUB(NEW.ended_at, INTERVAL 1 SECOND))
       FOR UPDATE;
    END IF;
    SET conflicting_time_entry_id = NULL;
    SELECT time_entry_id INTO conflicting_time_entry_id
      FROM time_entry_measured_intervals
     WHERE tenant_id = NEW.tenant_id AND user_id = NEW.user_id
       AND approval_status IN ('pending', 'approved')
       AND started_at < NEW.ended_at AND ended_at > NEW.started_at
     ORDER BY started_at, time_entry_id LIMIT 1 FOR UPDATE;
    IF conflicting_time_entry_id IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Measured time overlaps existing pending or approved time';
    END IF;
  END IF;
END$$

CREATE TRIGGER trg_time_entries_after_insert
AFTER INSERT ON time_entries
FOR EACH ROW
BEGIN
  INSERT INTO time_entry_events
    (tenant_id, time_entry_id, actor_user_id, event_kind, from_status,
     to_status, reason, snapshot_json, created_at)
  VALUES
    (NEW.tenant_id, NEW.id, NEW.user_id, 'logged', NULL, 'pending', '',
     JSON_OBJECT(
       'id', NEW.id, 'tenant_id', NEW.tenant_id, 'client_id', NEW.client_id,
       'entry_key', NEW.entry_key, 'ticket_id', NEW.ticket_id,
       'user_id', NEW.user_id, 'minutes', NEW.minutes, 'note', NEW.note,
       'billable', NEW.billable, 'source', NEW.source,
       'worked_at', NEW.worked_at, 'started_at', NEW.started_at,
       'ended_at', NEW.ended_at, 'approval_status', NEW.approval_status,
       'reviewed_by_user_id', NEW.reviewed_by_user_id,
       'reviewed_at', NEW.reviewed_at, 'review_note', NEW.review_note,
       'corrects_time_entry_id', NEW.corrects_time_entry_id,
       'created_at', NEW.created_at
     ), UTC_TIMESTAMP());
  IF NEW.started_at IS NOT NULL THEN
    INSERT IGNORE INTO time_entry_measured_intervals
      (tenant_id, time_entry_id, user_id, started_at, ended_at, approval_status)
    VALUES
      (NEW.tenant_id, NEW.id, NEW.user_id, NEW.started_at, NEW.ended_at,
       NEW.approval_status);
  END IF;
END$$

CREATE TRIGGER trg_time_entries_before_update
BEFORE UPDATE ON time_entries
FOR EACH ROW
BEGIN
  DECLARE reviewer_is_authorized INT DEFAULT 0;
  IF NOT (
       NEW.tenant_id <=> OLD.tenant_id AND NEW.client_id <=> OLD.client_id
   AND NEW.entry_key <=> OLD.entry_key AND NEW.ticket_id <=> OLD.ticket_id
   AND NEW.user_id <=> OLD.user_id AND NEW.minutes <=> OLD.minutes
   AND NEW.note <=> OLD.note AND NEW.billable <=> OLD.billable
   AND NEW.source <=> OLD.source AND NEW.worked_at <=> OLD.worked_at
   AND NEW.started_at <=> OLD.started_at AND NEW.ended_at <=> OLD.ended_at
   AND NEW.corrects_time_entry_id <=> OLD.corrects_time_entry_id
   AND NEW.created_at <=> OLD.created_at
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry facts are immutable';
  END IF;
  IF OLD.approval_status <> 'pending'
     OR NEW.approval_status NOT IN ('approved', 'rejected') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only pending time entries may be reviewed';
  END IF;
  IF NEW.reviewed_by_user_id IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry review requires a reviewer';
  END IF;
  SELECT COUNT(*) INTO reviewer_is_authorized
    FROM users
   WHERE tenant_id = NEW.tenant_id AND id = NEW.reviewed_by_user_id
     AND is_active = 1 AND role IN ('owner', 'admin');
  IF reviewer_is_authorized <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry reviewer must be an active owner or admin';
  END IF;
  SET NEW.reviewed_at = UTC_TIMESTAMP();
  SET NEW.review_note = TRIM(COALESCE(NEW.review_note, ''));
  IF NEW.approval_status = 'rejected' AND NEW.review_note = '' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rejected time requires a reason';
  END IF;
END$$

CREATE TRIGGER trg_time_entries_after_update
AFTER UPDATE ON time_entries
FOR EACH ROW
BEGIN
  DECLARE measured_registry_count INT DEFAULT 0;
  DECLARE measured_registry_status VARCHAR(16) DEFAULT NULL;
  IF NEW.started_at IS NOT NULL THEN
    UPDATE time_entry_measured_intervals
       SET approval_status = NEW.approval_status
     WHERE tenant_id = NEW.tenant_id AND time_entry_id = NEW.id
       AND approval_status <> NEW.approval_status;
    SELECT COUNT(*), MAX(approval_status)
      INTO measured_registry_count, measured_registry_status
      FROM time_entry_measured_intervals
     WHERE tenant_id = NEW.tenant_id AND time_entry_id = NEW.id;
    IF measured_registry_count <> 1
       OR BINARY measured_registry_status <> BINARY NEW.approval_status THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Measured time registry is incomplete';
    END IF;
  END IF;
  INSERT INTO time_entry_events
    (tenant_id, time_entry_id, actor_user_id, event_kind, from_status,
     to_status, reason, snapshot_json, created_at)
  VALUES
    (NEW.tenant_id, NEW.id, NEW.reviewed_by_user_id, NEW.approval_status,
     OLD.approval_status, NEW.approval_status, NEW.review_note,
     JSON_OBJECT(
       'id', NEW.id, 'tenant_id', NEW.tenant_id, 'client_id', NEW.client_id,
       'entry_key', NEW.entry_key, 'ticket_id', NEW.ticket_id,
       'user_id', NEW.user_id, 'minutes', NEW.minutes, 'note', NEW.note,
       'billable', NEW.billable, 'source', NEW.source,
       'worked_at', NEW.worked_at, 'started_at', NEW.started_at,
       'ended_at', NEW.ended_at, 'approval_status', NEW.approval_status,
       'reviewed_by_user_id', NEW.reviewed_by_user_id,
       'reviewed_at', NEW.reviewed_at, 'review_note', NEW.review_note,
       'corrects_time_entry_id', NEW.corrects_time_entry_id,
       'created_at', NEW.created_at
     ), NEW.reviewed_at);
END$$

CREATE TRIGGER trg_time_entries_no_delete
BEFORE DELETE ON time_entries
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entries cannot be deleted';
END$$

CREATE TRIGGER trg_time_entry_events_no_update
BEFORE UPDATE ON time_entry_events
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry events are immutable';
END$$

CREATE TRIGGER trg_time_entry_events_no_delete
BEFORE DELETE ON time_entry_events
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry events are immutable';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_time_overlap_stage_before_insert;
DROP TRIGGER IF EXISTS trg_time_overlap_stage_after_insert;
DROP TRIGGER IF EXISTS trg_time_overlap_stage_after_update;
DROP TRIGGER IF EXISTS trg_time_016_insert_swap_guard;
DROP TRIGGER IF EXISTS trg_time_016_update_swap_guard;
DROP TRIGGER IF EXISTS trg_time_016_delete_swap_guard;
DROP TRIGGER IF EXISTS trg_time_016_event_update_swap_guard;
DROP TRIGGER IF EXISTS trg_time_016_event_delete_swap_guard;

-- Re-query objects after first-run DDL. The variables populated before ALTER
-- intentionally described the pre-migration state and cannot be used as the
-- operator's postflight evidence.
SET @time_correction_column_exact = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'corrects_time_entry_id'
     AND column_type = 'int unsigned' AND is_nullable = 'YES'
     AND column_default IS NULL AND extra = ''
);
SET @time_correction_index_exact = (
  SELECT COUNT(*) FROM (
    SELECT index_name
      FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'time_entries'
       AND index_name = 'uq_time_entries_one_correction'
     GROUP BY index_name
    HAVING COUNT(*) = 2
       AND MIN(non_unique) = 0 AND MAX(non_unique) = 0
       AND MIN(index_type) = 'BTREE' AND MAX(index_type) = 'BTREE'
       AND SUM(sub_part IS NOT NULL) = 0
       AND GROUP_CONCAT(column_name ORDER BY seq_in_index)
           = 'tenant_id,corrects_time_entry_id'
  ) exact_index
);
SET @time_correction_fk_exact = (
  SELECT COUNT(*) FROM (
    SELECT k.constraint_name
      FROM information_schema.key_column_usage k
      JOIN information_schema.referential_constraints r
        ON r.constraint_schema = k.constraint_schema
       AND r.constraint_name = k.constraint_name
       AND r.table_name = k.table_name
     WHERE k.constraint_schema = DATABASE() AND k.table_name = 'time_entries'
       AND k.constraint_name = 'fk_time_entries_correction_tenant'
     GROUP BY k.constraint_name, r.referenced_table_name,
              r.update_rule, r.delete_rule
    HAVING COUNT(*) = 2
       AND r.referenced_table_name = 'time_entries'
       AND GROUP_CONCAT(
             CONCAT(k.column_name, '=', k.referenced_column_name)
             ORDER BY k.ordinal_position
           ) = 'tenant_id=tenant_id,corrects_time_entry_id=id'
       AND r.update_rule IN ('RESTRICT', 'NO ACTION')
       AND r.delete_rule IN ('RESTRICT', 'NO ACTION')
  ) exact_fk
);

SET @time_permanent_trigger_count = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'trg_time_entries_before_insert', 'trg_time_entries_after_insert',
       'trg_time_entries_before_update', 'trg_time_entries_after_update',
       'trg_time_entries_no_delete', 'trg_time_entry_events_no_update',
       'trg_time_entry_events_no_delete',
       'trg_time_interval_guards_no_update',
       'trg_time_interval_guards_no_delete',
       'trg_time_measured_before_insert',
       'trg_time_measured_before_update', 'trg_time_measured_no_delete'
     )
);
SET @time_stage_trigger_count = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'trg_time_overlap_stage_before_insert',
       'trg_time_overlap_stage_after_insert',
       'trg_time_overlap_stage_after_update',
       'trg_time_016_insert_swap_guard',
       'trg_time_016_update_swap_guard',
       'trg_time_016_delete_swap_guard',
       'trg_time_016_event_update_swap_guard',
       'trg_time_016_event_delete_swap_guard',
       'trg_time_016_guard_update_aux_swap',
       'trg_time_016_guard_delete_aux_swap',
       'trg_time_016_measured_insert_aux_swap',
       'trg_time_016_measured_update_aux_swap',
       'trg_time_016_measured_delete_aux_swap'
     )
);
SET @time_ddl = IF(
  @time_permanent_trigger_count = 12 AND @time_stage_trigger_count = 0,
  'DO 0',
  'SELECT * FROM information_schema.migration_016_incomplete_permanent_guards'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SELECT
  @time_correction_column_exact AS correction_column_exact,
  @time_correction_index_exact AS correction_index_exact,
  @time_correction_fk_exact AS correction_fk_exact,
  @time_structure_ok AS auxiliary_structure_exact,
  @time_permanent_trigger_count AS permanent_trigger_count,
  @time_stage_trigger_count AS staging_trigger_count,
  @time_measured_parent_count AS measured_parent_count,
  @time_measured_registry_count AS measured_registry_count;
