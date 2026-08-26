-- 011_time_entry_approvals.sql — approval-grade technician time.
-- Additive upgrade for MySQL 8 / utf8mb4 / InnoDB.
--
-- Historical rows are deterministically stamped as legacy + pending. They are
-- never inferred to be approved. New rows snapshot tenant/client ownership,
-- carry an idempotency key, and remain immutable apart from one terminal
-- pending -> approved/rejected review transition.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Composite parent keys make every new relationship enforce tenant ownership
-- in the database. Same-named malformed indexes deliberately hit duplicate
-- name errors instead of being mistaken for a safe replay.
SET @time_parent_index_count = (
  SELECT COUNT(DISTINCT index_name)
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'tickets'
     AND index_name = 'uq_tickets_tenant_id'
);
SET @time_parent_index_exact = (
  SELECT COUNT(*) FROM (
    SELECT index_name
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'tickets'
       AND index_name = 'uq_tickets_tenant_id'
     GROUP BY index_name
    HAVING COUNT(*) = 2
       AND MIN(non_unique) = 0 AND MAX(non_unique) = 0
       AND MIN(index_type) = 'BTREE' AND MAX(index_type) = 'BTREE'
       AND SUM(sub_part IS NOT NULL) = 0
       AND GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'tenant_id,id'
  ) exact_index
);
SET @time_ddl = IF(
  @time_parent_index_count = 0 OR @time_parent_index_exact = 0,
  'ALTER TABLE tickets ADD UNIQUE KEY uq_tickets_tenant_id (tenant_id, id)',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_parent_index_count = (
  SELECT COUNT(DISTINCT index_name)
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'clients'
     AND index_name = 'uq_clients_tenant_id'
);
SET @time_parent_index_exact = (
  SELECT COUNT(*) FROM (
    SELECT index_name
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'clients'
       AND index_name = 'uq_clients_tenant_id'
     GROUP BY index_name
    HAVING COUNT(*) = 2
       AND MIN(non_unique) = 0 AND MAX(non_unique) = 0
       AND MIN(index_type) = 'BTREE' AND MAX(index_type) = 'BTREE'
       AND SUM(sub_part IS NOT NULL) = 0
       AND GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'tenant_id,id'
  ) exact_index
);
SET @time_ddl = IF(
  @time_parent_index_count = 0 OR @time_parent_index_exact = 0,
  'ALTER TABLE clients ADD UNIQUE KEY uq_clients_tenant_id (tenant_id, id)',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_parent_index_count = (
  SELECT COUNT(DISTINCT index_name)
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'users'
     AND index_name = 'uq_users_tenant_id'
);
SET @time_parent_index_exact = (
  SELECT COUNT(*) FROM (
    SELECT index_name
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'users'
       AND index_name = 'uq_users_tenant_id'
     GROUP BY index_name
    HAVING COUNT(*) = 2
       AND MIN(non_unique) = 0 AND MAX(non_unique) = 0
       AND MIN(index_type) = 'BTREE' AND MAX(index_type) = 'BTREE'
       AND SUM(sub_part IS NOT NULL) = 0
       AND GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'tenant_id,id'
  ) exact_index
);
SET @time_ddl = IF(
  @time_parent_index_count = 0 OR @time_parent_index_exact = 0,
  'ALTER TABLE users ADD UNIQUE KEY uq_users_tenant_id (tenant_id, id)',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

-- Add each column in a nullable transition shape. A replay accepts only that
-- transition shape or the exact final shape. An incompatible same-named
-- column is intentionally re-added so MySQL raises a visible duplicate error.
SET @time_column_count = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'tenant_id'
);
SET @time_column_shape_ok = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'tenant_id' AND column_type = 'int unsigned'
     AND is_nullable IN ('YES','NO') AND column_default IS NULL AND extra = ''
);
SET @time_ddl = IF(
  @time_column_count = 0 OR @time_column_shape_ok = 0,
  'ALTER TABLE time_entries ADD COLUMN tenant_id INT UNSIGNED NULL AFTER id',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_column_count = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'client_id'
);
SET @time_column_shape_ok = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'client_id' AND column_type = 'int unsigned'
     AND is_nullable IN ('YES','NO') AND column_default IS NULL AND extra = ''
);
SET @time_ddl = IF(
  @time_column_count = 0 OR @time_column_shape_ok = 0,
  'ALTER TABLE time_entries ADD COLUMN client_id INT UNSIGNED NULL AFTER tenant_id',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_column_count = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'entry_key'
);
SET @time_column_shape_ok = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'entry_key' AND column_type = 'varchar(64)'
     AND is_nullable IN ('YES','NO') AND column_default IS NULL AND extra = ''
);
SET @time_ddl = IF(
  @time_column_count = 0 OR @time_column_shape_ok = 0,
  'ALTER TABLE time_entries ADD COLUMN entry_key VARCHAR(64) NULL AFTER client_id',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_column_count = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'source'
);
SET @time_column_shape_ok = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'source'
     AND column_type = 'enum(''timer'',''reply'',''suggestion'',''legacy'')'
     AND is_nullable IN ('YES','NO')
     AND (column_default IS NULL OR column_default = 'legacy') AND extra = ''
);
SET @time_ddl = IF(
  @time_column_count = 0 OR @time_column_shape_ok = 0,
  'ALTER TABLE time_entries ADD COLUMN source ENUM(''timer'',''reply'',''suggestion'',''legacy'') NULL AFTER billable',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_column_count = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'worked_at'
);
SET @time_column_shape_ok = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'worked_at' AND column_type = 'datetime'
     AND is_nullable IN ('YES','NO')
     AND (column_default IS NULL OR UPPER(column_default) = 'CURRENT_TIMESTAMP')
     AND extra IN ('','DEFAULT_GENERATED')
);
SET @time_ddl = IF(
  @time_column_count = 0 OR @time_column_shape_ok = 0,
  'ALTER TABLE time_entries ADD COLUMN worked_at DATETIME NULL AFTER source',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_column_count = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'started_at'
);
SET @time_column_shape_ok = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'started_at' AND column_type = 'datetime'
     AND is_nullable = 'YES' AND column_default IS NULL AND extra = ''
);
SET @time_ddl = IF(
  @time_column_count = 0 OR @time_column_shape_ok = 0,
  'ALTER TABLE time_entries ADD COLUMN started_at DATETIME NULL AFTER worked_at',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_column_count = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'ended_at'
);
SET @time_column_shape_ok = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'ended_at' AND column_type = 'datetime'
     AND is_nullable = 'YES' AND column_default IS NULL AND extra = ''
);
SET @time_ddl = IF(
  @time_column_count = 0 OR @time_column_shape_ok = 0,
  'ALTER TABLE time_entries ADD COLUMN ended_at DATETIME NULL AFTER started_at',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_column_count = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'approval_status'
);
SET @time_column_shape_ok = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'approval_status'
     AND column_type = 'enum(''pending'',''approved'',''rejected'')'
     AND is_nullable IN ('YES','NO')
     AND (column_default IS NULL OR column_default = 'pending') AND extra = ''
);
SET @time_ddl = IF(
  @time_column_count = 0 OR @time_column_shape_ok = 0,
  'ALTER TABLE time_entries ADD COLUMN approval_status ENUM(''pending'',''approved'',''rejected'') NULL AFTER ended_at',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_column_count = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'reviewed_by_user_id'
);
SET @time_column_shape_ok = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'reviewed_by_user_id' AND column_type = 'int unsigned'
     AND is_nullable = 'YES' AND column_default IS NULL AND extra = ''
);
SET @time_ddl = IF(
  @time_column_count = 0 OR @time_column_shape_ok = 0,
  'ALTER TABLE time_entries ADD COLUMN reviewed_by_user_id INT UNSIGNED NULL AFTER approval_status',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_column_count = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'reviewed_at'
);
SET @time_column_shape_ok = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'reviewed_at' AND column_type = 'datetime'
     AND is_nullable = 'YES' AND column_default IS NULL AND extra = ''
);
SET @time_ddl = IF(
  @time_column_count = 0 OR @time_column_shape_ok = 0,
  'ALTER TABLE time_entries ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by_user_id',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_column_count = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'review_note'
);
SET @time_column_shape_ok = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'review_note' AND column_type = 'varchar(500)'
     AND is_nullable IN ('YES','NO')
     AND (column_default IS NULL OR column_default = '') AND extra = ''
);
SET @time_ddl = IF(
  @time_column_count = 0 OR @time_column_shape_ok = 0,
  'ALTER TABLE time_entries ADD COLUMN review_note VARCHAR(500) NULL AFTER reviewed_at',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

-- Trigger privilege preflight comes before any permanent guard is dropped.
-- An app-user replay therefore fails here without weakening audit history.
DROP TRIGGER IF EXISTS trg_time_entry_privilege_preflight;
CREATE TRIGGER trg_time_entry_privilege_preflight
BEFORE INSERT ON time_entries
FOR EACH ROW
SET @time_entry_trigger_privilege_preflight = 1;
DROP TRIGGER trg_time_entry_privilege_preflight;

-- Staging guards cover the entire migration window. This connection carries
-- the only bypass so it can perform the controlled deterministic backfills.
-- Every other connection may keep using the old five-column INSERT, but an
-- old merge-style UPDATE or any DELETE fails closed until the exact permanent
-- immutable guards have been installed. An interrupted migration may leave
-- these guards behind, so an exact existing definition is preserved instead
-- of being dropped and recreated. A malformed same-named trigger fails before
-- Fingerprints are SHA-256 over information_schema.triggers.action_statement
-- with CR removed. They make whitespace and every executable token exact while
-- remaining safe for line-oriented migration runners.
SET @time_staging_insert_expected_sha256 =
  '8278b991e24e8dff877f0d7d27f9770f73224ed33bf36d711ed1f7e5dd3d7d05';
SET @time_staging_update_expected_sha256 =
  'd36ce78d7d14694dd74b27506ae118c92ba517ec79a456d0a24205fca8e492fa';
SET @time_staging_delete_expected_sha256 = @time_staging_update_expected_sha256;

SET @time_staging_insert_count = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name = 'trg_time_entry_insert_compatibility_staging'
);
SET @time_staging_insert_shape_ok = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name = 'trg_time_entry_insert_compatibility_staging'
     AND event_object_table = 'time_entries'
     AND action_timing = 'BEFORE'
     AND event_manipulation = 'INSERT'
     AND action_orientation = 'ROW'
     AND SHA2(REPLACE(action_statement, CHAR(13), ''), 256)
         = @time_staging_insert_expected_sha256
);
SET @time_ddl = IF(
  @time_staging_insert_count = 0 OR @time_staging_insert_shape_ok = 1,
  'DO 0',
  'SELECT * FROM information_schema.migration_011_malformed_insert_staging_trigger'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_staging_update_count = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name = 'trg_time_entry_update_guard_staging'
);
SET @time_staging_update_shape_ok = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name = 'trg_time_entry_update_guard_staging'
     AND event_object_table = 'time_entries'
     AND action_timing = 'BEFORE'
     AND event_manipulation = 'UPDATE'
     AND action_orientation = 'ROW'
     AND SHA2(REPLACE(action_statement, CHAR(13), ''), 256)
         = @time_staging_update_expected_sha256
);
SET @time_ddl = IF(
  @time_staging_update_count = 0 OR @time_staging_update_shape_ok = 1,
  'DO 0',
  'SELECT * FROM information_schema.migration_011_malformed_update_staging_trigger'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_staging_delete_count = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name = 'trg_time_entry_delete_guard_staging'
);
SET @time_staging_delete_shape_ok = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name = 'trg_time_entry_delete_guard_staging'
     AND event_object_table = 'time_entries'
     AND action_timing = 'BEFORE'
     AND event_manipulation = 'DELETE'
     AND action_orientation = 'ROW'
     AND SHA2(REPLACE(action_statement, CHAR(13), ''), 256)
         = @time_staging_delete_expected_sha256
);
SET @time_ddl = IF(
  @time_staging_delete_count = 0 OR @time_staging_delete_shape_ok = 1,
  'DO 0',
  'SELECT * FROM information_schema.migration_011_malformed_delete_staging_trigger'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @safeharbor_time_migration = 1;

DELIMITER $$
CREATE TRIGGER IF NOT EXISTS trg_time_entry_insert_compatibility_staging
BEFORE INSERT ON time_entries
FOR EACH ROW
BEGIN
  DECLARE ticket_tenant_id INT UNSIGNED;
  DECLARE ticket_client_id INT UNSIGNED;

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
END$$
DELIMITER ;

SET @time_staging_insert_installed = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name = 'trg_time_entry_insert_compatibility_staging'
     AND event_object_table = 'time_entries'
     AND action_timing = 'BEFORE'
     AND event_manipulation = 'INSERT'
     AND action_orientation = 'ROW'
     AND SHA2(REPLACE(action_statement, CHAR(13), ''), 256)
         = @time_staging_insert_expected_sha256
);
SET @time_ddl = IF(
  @time_staging_insert_installed = 1,
  'DO 0',
  'SELECT * FROM information_schema.migration_011_invalid_insert_staging_trigger'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

DELIMITER $$
CREATE TRIGGER IF NOT EXISTS trg_time_entry_update_guard_staging
BEFORE UPDATE ON time_entries
FOR EACH ROW
BEGIN
  IF COALESCE(@safeharbor_time_migration, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entries are locked for approval migration';
  END IF;
END$$
DELIMITER ;

SET @time_staging_update_installed = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name = 'trg_time_entry_update_guard_staging'
     AND event_object_table = 'time_entries'
     AND action_timing = 'BEFORE'
     AND event_manipulation = 'UPDATE'
     AND action_orientation = 'ROW'
     AND SHA2(REPLACE(action_statement, CHAR(13), ''), 256)
         = @time_staging_update_expected_sha256
);
SET @time_ddl = IF(
  @time_staging_update_installed = 1,
  'DO 0',
  'SELECT * FROM information_schema.migration_011_invalid_update_staging_trigger'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

-- On a structural replay the permanent guards already exist. Install the
-- staging guard first, then remove the permanent one, so external sessions
-- remain protected without interruption while migration-owned checks run.
DROP TRIGGER IF EXISTS trg_time_entries_before_update;

DELIMITER $$
CREATE TRIGGER IF NOT EXISTS trg_time_entry_delete_guard_staging
BEFORE DELETE ON time_entries
FOR EACH ROW
BEGIN
  IF COALESCE(@safeharbor_time_migration, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entries are locked for approval migration';
  END IF;
END$$
DELIMITER ;

SET @time_staging_delete_installed = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name = 'trg_time_entry_delete_guard_staging'
     AND event_object_table = 'time_entries'
     AND action_timing = 'BEFORE'
     AND event_manipulation = 'DELETE'
     AND action_orientation = 'ROW'
     AND SHA2(REPLACE(action_statement, CHAR(13), ''), 256)
         = @time_staging_delete_expected_sha256
);
SET @time_ddl = IF(
  @time_staging_delete_installed = 1,
  'DO 0',
  'SELECT * FROM information_schema.migration_011_invalid_delete_staging_trigger'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

DROP TRIGGER IF EXISTS trg_time_entries_no_delete;

-- Creation time is the only truthful worked-at fact for historical rows.
-- entry_key is deterministic so a replay can prove the same result.
UPDATE time_entries time_entry
JOIN tickets ticket ON ticket.id = time_entry.ticket_id
   SET time_entry.tenant_id = COALESCE(time_entry.tenant_id, ticket.tenant_id),
       time_entry.client_id = COALESCE(time_entry.client_id, ticket.client_id),
       time_entry.entry_key = COALESCE(
         NULLIF(TRIM(time_entry.entry_key), ''),
         CONCAT('legacy:', time_entry.id)
       ),
       time_entry.source = COALESCE(time_entry.source, 'legacy'),
       time_entry.worked_at = COALESCE(time_entry.worked_at, time_entry.created_at),
       time_entry.approval_status = COALESCE(time_entry.approval_status, 'pending'),
       time_entry.review_note = COALESCE(time_entry.review_note, '')
 WHERE time_entry.tenant_id IS NULL
    OR time_entry.client_id IS NULL
    OR time_entry.entry_key IS NULL
    OR time_entry.entry_key = ''
    OR time_entry.source IS NULL
    OR time_entry.worked_at IS NULL
    OR time_entry.approval_status IS NULL
    OR time_entry.review_note IS NULL;

-- If a prior interrupted attempt added approval columns before completing its
-- backfill, deterministic legacy IDs still cannot become approved by default.
UPDATE time_entries
   SET approval_status = 'pending',
       reviewed_by_user_id = NULL,
       reviewed_at = NULL,
       review_note = ''
 WHERE source = 'legacy'
   AND entry_key = CONCAT('legacy:', id)
   AND reviewed_at IS NULL
   AND (
        approval_status <> 'pending'
        OR reviewed_by_user_id IS NOT NULL
        OR review_note <> ''
   );

-- With the staging compatibility trigger active, make the required snapshot fields
-- exact. The permanent trigger remains after the staging guard is removed.
SET @time_column_exact = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'tenant_id' AND column_type = 'int unsigned'
     AND is_nullable = 'NO' AND column_default IS NULL AND extra = ''
);
SET @time_ddl = IF(
  @time_column_exact = 0,
  'ALTER TABLE time_entries MODIFY COLUMN tenant_id INT UNSIGNED NOT NULL AFTER id',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_column_exact = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'client_id' AND column_type = 'int unsigned'
     AND is_nullable = 'NO' AND column_default IS NULL AND extra = ''
);
SET @time_ddl = IF(
  @time_column_exact = 0,
  'ALTER TABLE time_entries MODIFY COLUMN client_id INT UNSIGNED NOT NULL AFTER tenant_id',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_column_exact = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'entry_key' AND column_type = 'varchar(64)'
     AND is_nullable = 'NO' AND column_default IS NULL AND extra = ''
);
SET @time_ddl = IF(
  @time_column_exact = 0,
  'ALTER TABLE time_entries MODIFY COLUMN entry_key VARCHAR(64) NOT NULL AFTER client_id',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_column_exact = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'source'
     AND column_type = 'enum(''timer'',''reply'',''suggestion'',''legacy'')'
     AND is_nullable = 'NO' AND column_default = 'legacy' AND extra = ''
);
SET @time_ddl = IF(
  @time_column_exact = 0,
  'ALTER TABLE time_entries MODIFY COLUMN source ENUM(''timer'',''reply'',''suggestion'',''legacy'') NOT NULL DEFAULT ''legacy'' AFTER billable',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_column_exact = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'worked_at' AND column_type = 'datetime'
     AND is_nullable = 'NO'
     AND UPPER(column_default) IN ('CURRENT_TIMESTAMP','CURRENT_TIMESTAMP()')
     AND extra = 'DEFAULT_GENERATED'
);
SET @time_ddl = IF(
  @time_column_exact = 0,
  'ALTER TABLE time_entries MODIFY COLUMN worked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER source',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_column_exact = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'approval_status'
     AND column_type = 'enum(''pending'',''approved'',''rejected'')'
     AND is_nullable = 'NO' AND column_default = 'pending' AND extra = ''
);
SET @time_ddl = IF(
  @time_column_exact = 0,
  'ALTER TABLE time_entries MODIFY COLUMN approval_status ENUM(''pending'',''approved'',''rejected'') NOT NULL DEFAULT ''pending'' AFTER ended_at',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_column_exact = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND column_name = 'review_note' AND column_type = 'varchar(500)'
     AND is_nullable = 'NO' AND column_default = '' AND extra = ''
);
SET @time_ddl = IF(
  @time_column_exact = 0,
  'ALTER TABLE time_entries MODIFY COLUMN review_note VARCHAR(500) NOT NULL DEFAULT '''' AFTER reviewed_at',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

-- Minute bounds are a database fact, not an application hint.
SET @time_check_count = (
  SELECT COUNT(*)
    FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND table_name = 'time_entries'
     AND constraint_name = 'ck_time_entries_minutes'
     AND constraint_type = 'CHECK'
);
SET @time_check_exact = (
  SELECT COUNT(*)
    FROM information_schema.check_constraints
   WHERE constraint_schema = DATABASE()
     AND constraint_name = 'ck_time_entries_minutes'
     AND REPLACE(REPLACE(REPLACE(REPLACE(LOWER(check_clause), '`', ''), ' ', ''), '(', ''), ')', '')
         = 'minutesbetween1and1440'
);
SET @time_ddl = IF(
  @time_check_count = 0 OR @time_check_exact = 0,
  'ALTER TABLE time_entries ADD CONSTRAINT ck_time_entries_minutes CHECK (minutes BETWEEN 1 AND 1440)',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_billable_check_count = (
  SELECT COUNT(*)
    FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND table_name = 'time_entries'
     AND constraint_name = 'ck_time_entries_billable'
     AND constraint_type = 'CHECK'
);
SET @time_billable_check_exact = (
  SELECT COUNT(*)
    FROM information_schema.check_constraints
   WHERE constraint_schema = DATABASE()
     AND constraint_name = 'ck_time_entries_billable'
     AND REPLACE(REPLACE(REPLACE(REPLACE(LOWER(check_clause), '`', ''), ' ', ''), '(', ''), ')', '')
         = 'billablein0,1'
);
SET @time_ddl = IF(
  @time_billable_check_count = 0 OR @time_billable_check_exact = 0,
  'ALTER TABLE time_entries ADD CONSTRAINT ck_time_entries_billable CHECK (billable IN (0, 1))',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

-- Exact application indexes. Each same-named incompatible index is rejected
-- by deliberately attempting the duplicate ADD.
SET @time_index_count = (
  SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND index_name = 'uq_time_entries_tenant_key'
);
SET @time_index_exact = (
  SELECT COUNT(*) FROM (
    SELECT index_name FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'time_entries'
       AND index_name = 'uq_time_entries_tenant_key'
     GROUP BY index_name
    HAVING COUNT(*) = 2 AND MIN(non_unique) = 0 AND MAX(non_unique) = 0
       AND MIN(index_type) = 'BTREE' AND MAX(index_type) = 'BTREE'
       AND SUM(sub_part IS NOT NULL) = 0
       AND GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'tenant_id,entry_key'
  ) exact_index
);
SET @time_ddl = IF(
  @time_index_count = 0 OR @time_index_exact = 0,
  'ALTER TABLE time_entries ADD UNIQUE KEY uq_time_entries_tenant_key (tenant_id, entry_key)',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_index_count = (
  SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND index_name = 'uq_time_entries_tenant_id'
);
SET @time_index_exact = (
  SELECT COUNT(*) FROM (
    SELECT index_name FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'time_entries'
       AND index_name = 'uq_time_entries_tenant_id'
     GROUP BY index_name
    HAVING COUNT(*) = 2 AND MIN(non_unique) = 0 AND MAX(non_unique) = 0
       AND MIN(index_type) = 'BTREE' AND MAX(index_type) = 'BTREE'
       AND SUM(sub_part IS NOT NULL) = 0
       AND GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'tenant_id,id'
  ) exact_index
);
SET @time_ddl = IF(
  @time_index_count = 0 OR @time_index_exact = 0,
  'ALTER TABLE time_entries ADD UNIQUE KEY uq_time_entries_tenant_id (tenant_id, id)',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_index_count = (
  SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND index_name = 'ix_time_entries_ticket'
);
SET @time_index_exact = (
  SELECT COUNT(*) FROM (
    SELECT index_name FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'time_entries'
       AND index_name = 'ix_time_entries_ticket'
     GROUP BY index_name
    HAVING COUNT(*) = 2 AND MIN(non_unique) = 1 AND MAX(non_unique) = 1
       AND MIN(index_type) = 'BTREE' AND MAX(index_type) = 'BTREE'
       AND SUM(sub_part IS NOT NULL) = 0
       AND GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'tenant_id,ticket_id'
  ) exact_index
);
SET @time_ddl = IF(
  @time_index_count = 0 OR @time_index_exact = 0,
  'ALTER TABLE time_entries ADD KEY ix_time_entries_ticket (tenant_id, ticket_id)',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_index_count = (
  SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND index_name = 'ix_time_entries_user_worked'
);
SET @time_index_exact = (
  SELECT COUNT(*) FROM (
    SELECT index_name FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'time_entries'
       AND index_name = 'ix_time_entries_user_worked'
     GROUP BY index_name
    HAVING COUNT(*) = 3 AND MIN(non_unique) = 1 AND MAX(non_unique) = 1
       AND MIN(index_type) = 'BTREE' AND MAX(index_type) = 'BTREE'
       AND SUM(sub_part IS NOT NULL) = 0
       AND GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'tenant_id,user_id,worked_at'
  ) exact_index
);
SET @time_ddl = IF(
  @time_index_count = 0 OR @time_index_exact = 0,
  'ALTER TABLE time_entries ADD KEY ix_time_entries_user_worked (tenant_id, user_id, worked_at)',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_index_count = (
  SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND index_name = 'ix_time_entries_client_status'
);
SET @time_index_exact = (
  SELECT COUNT(*) FROM (
    SELECT index_name FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'time_entries'
       AND index_name = 'ix_time_entries_client_status'
     GROUP BY index_name
    HAVING COUNT(*) = 4 AND MIN(non_unique) = 1 AND MAX(non_unique) = 1
       AND MIN(index_type) = 'BTREE' AND MAX(index_type) = 'BTREE'
       AND SUM(sub_part IS NOT NULL) = 0
       AND GROUP_CONCAT(column_name ORDER BY seq_in_index)
           = 'tenant_id,client_id,approval_status,worked_at'
  ) exact_index
);
SET @time_ddl = IF(
  @time_index_count = 0 OR @time_index_exact = 0,
  'ALTER TABLE time_entries ADD KEY ix_time_entries_client_status (tenant_id, client_id, approval_status, worked_at)',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_index_count = (
  SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND index_name = 'ix_time_entries_approval_queue'
);
SET @time_index_exact = (
  SELECT COUNT(*) FROM (
    SELECT index_name FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'time_entries'
       AND index_name = 'ix_time_entries_approval_queue'
     GROUP BY index_name
    HAVING COUNT(*) = 4 AND MIN(non_unique) = 1 AND MAX(non_unique) = 1
       AND MIN(index_type) = 'BTREE' AND MAX(index_type) = 'BTREE'
       AND SUM(sub_part IS NOT NULL) = 0
       AND GROUP_CONCAT(column_name ORDER BY seq_in_index)
           = 'tenant_id,approval_status,worked_at,id'
  ) exact_index
);
SET @time_ddl = IF(
  @time_index_count = 0 OR @time_index_exact = 0,
  'ALTER TABLE time_entries ADD KEY ix_time_entries_approval_queue (tenant_id, approval_status, worked_at, id)',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_index_count = (
  SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
   WHERE table_schema = DATABASE() AND table_name = 'time_entries'
     AND index_name = 'ix_time_entries_reviewer'
);
SET @time_index_exact = (
  SELECT COUNT(*) FROM (
    SELECT index_name FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'time_entries'
       AND index_name = 'ix_time_entries_reviewer'
     GROUP BY index_name
    HAVING COUNT(*) = 3 AND MIN(non_unique) = 1 AND MAX(non_unique) = 1
       AND MIN(index_type) = 'BTREE' AND MAX(index_type) = 'BTREE'
       AND SUM(sub_part IS NOT NULL) = 0
       AND GROUP_CONCAT(column_name ORDER BY seq_in_index)
           = 'tenant_id,reviewed_by_user_id,reviewed_at'
  ) exact_index
);
SET @time_ddl = IF(
  @time_index_count = 0 OR @time_index_exact = 0,
  'ALTER TABLE time_entries ADD KEY ix_time_entries_reviewer (tenant_id, reviewed_by_user_id, reviewed_at)',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;


-- Tenant-scoped foreign keys. The original single-ID ticket/user keys remain
-- for backward-compatible query plans, while these composites enforce the
-- ownership snapshot itself.
SET @time_fk_count = (
  SELECT COUNT(*) FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE() AND table_name = 'time_entries'
     AND constraint_name = 'fk_time_entries_tenant' AND constraint_type = 'FOREIGN KEY'
);
SET @time_fk_exact = (
  SELECT COUNT(*) FROM (
    SELECT constraint_name FROM information_schema.key_column_usage
     WHERE constraint_schema = DATABASE() AND table_name = 'time_entries'
       AND constraint_name = 'fk_time_entries_tenant'
       AND referenced_table_name = 'tenants'
     GROUP BY constraint_name
    HAVING GROUP_CONCAT(CONCAT(column_name, '=', referenced_column_name)
                        ORDER BY ordinal_position) = 'tenant_id=id'
  ) exact_fk
);
SET @time_ddl = IF(
  @time_fk_count = 0 OR @time_fk_exact = 0,
  'ALTER TABLE time_entries ADD CONSTRAINT fk_time_entries_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_fk_count = (
  SELECT COUNT(*) FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE() AND table_name = 'time_entries'
     AND constraint_name = 'fk_time_entries_ticket_tenant' AND constraint_type = 'FOREIGN KEY'
);
SET @time_fk_exact = (
  SELECT COUNT(*) FROM (
    SELECT constraint_name FROM information_schema.key_column_usage
     WHERE constraint_schema = DATABASE() AND table_name = 'time_entries'
       AND constraint_name = 'fk_time_entries_ticket_tenant'
       AND referenced_table_name = 'tickets'
     GROUP BY constraint_name
    HAVING GROUP_CONCAT(CONCAT(column_name, '=', referenced_column_name)
                        ORDER BY ordinal_position) = 'tenant_id=tenant_id,ticket_id=id'
  ) exact_fk
);
SET @time_ddl = IF(
  @time_fk_count = 0 OR @time_fk_exact = 0,
  'ALTER TABLE time_entries ADD CONSTRAINT fk_time_entries_ticket_tenant FOREIGN KEY (tenant_id, ticket_id) REFERENCES tickets (tenant_id, id)',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_fk_count = (
  SELECT COUNT(*) FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE() AND table_name = 'time_entries'
     AND constraint_name = 'fk_time_entries_client_tenant' AND constraint_type = 'FOREIGN KEY'
);
SET @time_fk_exact = (
  SELECT COUNT(*) FROM (
    SELECT constraint_name FROM information_schema.key_column_usage
     WHERE constraint_schema = DATABASE() AND table_name = 'time_entries'
       AND constraint_name = 'fk_time_entries_client_tenant'
       AND referenced_table_name = 'clients'
     GROUP BY constraint_name
    HAVING GROUP_CONCAT(CONCAT(column_name, '=', referenced_column_name)
                        ORDER BY ordinal_position) = 'tenant_id=tenant_id,client_id=id'
  ) exact_fk
);
SET @time_ddl = IF(
  @time_fk_count = 0 OR @time_fk_exact = 0,
  'ALTER TABLE time_entries ADD CONSTRAINT fk_time_entries_client_tenant FOREIGN KEY (tenant_id, client_id) REFERENCES clients (tenant_id, id)',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_fk_count = (
  SELECT COUNT(*) FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE() AND table_name = 'time_entries'
     AND constraint_name = 'fk_time_entries_user_tenant' AND constraint_type = 'FOREIGN KEY'
);
SET @time_fk_exact = (
  SELECT COUNT(*) FROM (
    SELECT constraint_name FROM information_schema.key_column_usage
     WHERE constraint_schema = DATABASE() AND table_name = 'time_entries'
       AND constraint_name = 'fk_time_entries_user_tenant'
       AND referenced_table_name = 'users'
     GROUP BY constraint_name
    HAVING GROUP_CONCAT(CONCAT(column_name, '=', referenced_column_name)
                        ORDER BY ordinal_position) = 'tenant_id=tenant_id,user_id=id'
  ) exact_fk
);
SET @time_ddl = IF(
  @time_fk_count = 0 OR @time_fk_exact = 0,
  'ALTER TABLE time_entries ADD CONSTRAINT fk_time_entries_user_tenant FOREIGN KEY (tenant_id, user_id) REFERENCES users (tenant_id, id)',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

SET @time_fk_count = (
  SELECT COUNT(*) FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE() AND table_name = 'time_entries'
     AND constraint_name = 'fk_time_entries_reviewer_tenant' AND constraint_type = 'FOREIGN KEY'
);
SET @time_fk_exact = (
  SELECT COUNT(*) FROM (
    SELECT constraint_name FROM information_schema.key_column_usage
     WHERE constraint_schema = DATABASE() AND table_name = 'time_entries'
       AND constraint_name = 'fk_time_entries_reviewer_tenant'
       AND referenced_table_name = 'users'
     GROUP BY constraint_name
    HAVING GROUP_CONCAT(CONCAT(column_name, '=', referenced_column_name)
                        ORDER BY ordinal_position) = 'tenant_id=tenant_id,reviewed_by_user_id=id'
  ) exact_fk
);
SET @time_ddl = IF(
  @time_fk_count = 0 OR @time_fk_exact = 0,
  'ALTER TABLE time_entries ADD CONSTRAINT fk_time_entries_reviewer_tenant FOREIGN KEY (tenant_id, reviewed_by_user_id) REFERENCES users (tenant_id, id)',
  'DO 0'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

-- Events are append-only snapshots, one log event and at most one terminal
-- review event of each kind per entry. CREATE TABLE is atomic; if an object
-- with this name exists but any required shape is wrong, the exact signature
-- check below deliberately retries CREATE and fails with table-exists.
CREATE TABLE IF NOT EXISTS time_entry_events (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id     INT UNSIGNED NOT NULL,
  time_entry_id INT UNSIGNED NOT NULL,
  actor_user_id INT UNSIGNED NOT NULL,
  event_kind    ENUM('logged','approved','rejected') NOT NULL,
  from_status   ENUM('pending','approved','rejected') NULL,
  to_status     ENUM('pending','approved','rejected') NOT NULL,
  reason        VARCHAR(500) NOT NULL DEFAULT '',
  snapshot_json JSON NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_time_entry_events_entry_kind (tenant_id, time_entry_id, event_kind),
  KEY ix_time_entry_events_entry (tenant_id, time_entry_id, id),
  KEY ix_time_entry_events_actor_created (tenant_id, actor_user_id, created_at),
  CONSTRAINT fk_time_entry_events_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_time_entry_events_entry FOREIGN KEY (tenant_id, time_entry_id)
    REFERENCES time_entries (tenant_id, id),
  CONSTRAINT fk_time_entry_events_actor FOREIGN KEY (tenant_id, actor_user_id)
    REFERENCES users (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @time_events_columns_exact = (
  SELECT COUNT(*) = 10
     AND SUM(column_name = 'id' AND column_type = 'bigint unsigned'
             AND is_nullable = 'NO' AND column_default IS NULL
             AND extra = 'auto_increment') = 1
     AND SUM(column_name = 'tenant_id' AND column_type = 'int unsigned'
             AND is_nullable = 'NO' AND column_default IS NULL AND extra = '') = 1
     AND SUM(column_name = 'time_entry_id' AND column_type = 'int unsigned'
             AND is_nullable = 'NO' AND column_default IS NULL AND extra = '') = 1
     AND SUM(column_name = 'actor_user_id' AND column_type = 'int unsigned'
             AND is_nullable = 'NO' AND column_default IS NULL AND extra = '') = 1
     AND SUM(column_name = 'event_kind'
             AND column_type = 'enum(''logged'',''approved'',''rejected'')'
             AND is_nullable = 'NO' AND column_default IS NULL AND extra = '') = 1
     AND SUM(column_name = 'from_status'
             AND column_type = 'enum(''pending'',''approved'',''rejected'')'
             AND is_nullable = 'YES' AND column_default IS NULL AND extra = '') = 1
     AND SUM(column_name = 'to_status'
             AND column_type = 'enum(''pending'',''approved'',''rejected'')'
             AND is_nullable = 'NO' AND column_default IS NULL AND extra = '') = 1
     AND SUM(column_name = 'reason' AND column_type = 'varchar(500)'
             AND is_nullable = 'NO' AND column_default = '' AND extra = '') = 1
     AND SUM(column_name = 'snapshot_json' AND column_type = 'json'
             AND is_nullable = 'NO' AND column_default IS NULL AND extra = '') = 1
     AND SUM(column_name = 'created_at' AND column_type = 'datetime'
             AND is_nullable = 'NO'
             AND UPPER(column_default) IN ('CURRENT_TIMESTAMP','CURRENT_TIMESTAMP()')
             AND extra = 'DEFAULT_GENERATED') = 1
    FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'time_entry_events'
);
SET @time_events_indexes_exact = (
  SELECT COUNT(*) = 4
     AND SUM(index_name = 'PRIMARY' AND columns_csv = 'id'
             AND non_unique = 0 AND index_type = 'BTREE') = 1
     AND SUM(index_name = 'uq_time_entry_events_entry_kind'
             AND columns_csv = 'tenant_id,time_entry_id,event_kind'
             AND non_unique = 0 AND index_type = 'BTREE') = 1
     AND SUM(index_name = 'ix_time_entry_events_entry'
             AND columns_csv = 'tenant_id,time_entry_id,id'
             AND non_unique = 1 AND index_type = 'BTREE') = 1
     AND SUM(index_name = 'ix_time_entry_events_actor_created'
             AND columns_csv = 'tenant_id,actor_user_id,created_at'
             AND non_unique = 1 AND index_type = 'BTREE') = 1
    FROM (
      SELECT index_name, MIN(non_unique) AS non_unique,
             MIN(index_type) AS index_type,
             GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_csv,
             SUM(sub_part IS NOT NULL) AS prefix_parts
        FROM information_schema.statistics
       WHERE table_schema = DATABASE() AND table_name = 'time_entry_events'
       GROUP BY index_name
      HAVING prefix_parts = 0
    ) event_indexes
);
SET @time_events_fks_exact = (
  SELECT COUNT(*) = 3
     AND SUM(constraint_name = 'fk_time_entry_events_tenant'
             AND referenced_table_name = 'tenants'
             AND columns_csv = 'tenant_id=id') = 1
     AND SUM(constraint_name = 'fk_time_entry_events_entry'
             AND referenced_table_name = 'time_entries'
             AND columns_csv = 'tenant_id=tenant_id,time_entry_id=id') = 1
     AND SUM(constraint_name = 'fk_time_entry_events_actor'
             AND referenced_table_name = 'users'
             AND columns_csv = 'tenant_id=tenant_id,actor_user_id=id') = 1
    FROM (
      SELECT constraint_name, MIN(referenced_table_name) AS referenced_table_name,
             GROUP_CONCAT(CONCAT(column_name, '=', referenced_column_name)
                          ORDER BY ordinal_position) AS columns_csv
        FROM information_schema.key_column_usage
       WHERE constraint_schema = DATABASE()
         AND table_name = 'time_entry_events'
         AND referenced_table_name IS NOT NULL
       GROUP BY constraint_name
    ) event_fks
);
SET @time_ddl = IF(
  @time_events_columns_exact = 1
    AND @time_events_indexes_exact = 1
    AND @time_events_fks_exact = 1,
  'DO 0',
  'CREATE TABLE time_entry_events (id INT NOT NULL)'
);
PREPARE time_statement FROM @time_ddl;
EXECUTE time_statement;
DEALLOCATE PREPARE time_statement;

-- Historical events preserve the original log time and factual row snapshot.
-- NOT EXISTS keeps a structural replay idempotent without INSERT IGNORE
-- masking any unrelated data or foreign-key error.
INSERT INTO time_entry_events
  (tenant_id, time_entry_id, actor_user_id, event_kind, from_status,
   to_status, reason, snapshot_json, created_at)
SELECT time_entry.tenant_id,
       time_entry.id,
       time_entry.user_id,
       'logged',
       NULL,
       'pending',
       '',
       JSON_OBJECT(
         'id', time_entry.id,
         'tenant_id', time_entry.tenant_id,
         'client_id', time_entry.client_id,
         'entry_key', time_entry.entry_key,
         'ticket_id', time_entry.ticket_id,
         'user_id', time_entry.user_id,
         'minutes', time_entry.minutes,
         'note', time_entry.note,
         'billable', time_entry.billable,
         'source', time_entry.source,
         'worked_at', time_entry.worked_at,
         'started_at', time_entry.started_at,
         'ended_at', time_entry.ended_at,
         'approval_status', time_entry.approval_status,
         'reviewed_by_user_id', time_entry.reviewed_by_user_id,
         'reviewed_at', time_entry.reviewed_at,
         'review_note', time_entry.review_note,
         'created_at', time_entry.created_at
       ),
       time_entry.created_at
  FROM time_entries time_entry
 WHERE time_entry.source = 'legacy'
   AND NOT EXISTS (
         SELECT 1 FROM time_entry_events event
          WHERE event.tenant_id = time_entry.tenant_id
            AND event.time_entry_id = time_entry.id
            AND event.event_kind = 'logged'
       );

DROP TRIGGER IF EXISTS trg_time_entries_before_insert;
DELIMITER $$
CREATE TRIGGER trg_time_entries_before_insert
BEFORE INSERT ON time_entries
FOR EACH ROW FOLLOWS trg_time_entry_insert_compatibility_staging
BEGIN
  DECLARE ticket_tenant_id INT UNSIGNED;
  DECLARE ticket_client_id INT UNSIGNED;

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
     OR (NEW.started_at IS NOT NULL AND NEW.ended_at < NEW.started_at) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry interval is invalid';
  END IF;
  IF NEW.source = 'timer' AND NEW.started_at IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Timer time requires start and end evidence';
  END IF;
  IF NEW.source = 'suggestion' AND NEW.started_at IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Suggested time cannot claim timer evidence';
  END IF;
  IF NEW.started_at IS NOT NULL THEN
    IF TIMESTAMPDIFF(SECOND, NEW.started_at, NEW.ended_at) > 86400 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry interval cannot exceed 24 hours';
    END IF;
    IF NEW.worked_at < NEW.started_at OR NEW.worked_at > NEW.ended_at THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Worked time must fall inside measured interval';
    END IF;
    IF ABS(
         NEW.minutes
         - ROUND(TIMESTAMPDIFF(SECOND, NEW.started_at, NEW.ended_at) / 60.0)
       ) > 1 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Minutes must match measured interval';
    END IF;
  END IF;
END$$
DELIMITER ;


DROP TRIGGER IF EXISTS trg_time_entries_after_insert;
DELIMITER $$
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
       'id', NEW.id,
       'tenant_id', NEW.tenant_id,
       'client_id', NEW.client_id,
       'entry_key', NEW.entry_key,
       'ticket_id', NEW.ticket_id,
       'user_id', NEW.user_id,
       'minutes', NEW.minutes,
       'note', NEW.note,
       'billable', NEW.billable,
       'source', NEW.source,
       'worked_at', NEW.worked_at,
       'started_at', NEW.started_at,
       'ended_at', NEW.ended_at,
       'approval_status', NEW.approval_status,
       'reviewed_by_user_id', NEW.reviewed_by_user_id,
       'reviewed_at', NEW.reviewed_at,
       'review_note', NEW.review_note,
       'created_at', NEW.created_at
     ), UTC_TIMESTAMP());
END$$
DELIMITER ;

-- Close the narrow first-run window between event-table creation and the
-- AFTER INSERT guard. Once this statement starts, every later insert is
-- guarded; NOT EXISTS picks up only rows committed before it did.
INSERT INTO time_entry_events
  (tenant_id, time_entry_id, actor_user_id, event_kind, from_status,
   to_status, reason, snapshot_json, created_at)
SELECT time_entry.tenant_id,
       time_entry.id,
       time_entry.user_id,
       'logged',
       NULL,
       'pending',
       '',
       JSON_OBJECT(
         'id', time_entry.id,
         'tenant_id', time_entry.tenant_id,
         'client_id', time_entry.client_id,
         'entry_key', time_entry.entry_key,
         'ticket_id', time_entry.ticket_id,
         'user_id', time_entry.user_id,
         'minutes', time_entry.minutes,
         'note', time_entry.note,
         'billable', time_entry.billable,
         'source', time_entry.source,
         'worked_at', time_entry.worked_at,
         'started_at', time_entry.started_at,
         'ended_at', time_entry.ended_at,
         'approval_status', time_entry.approval_status,
         'reviewed_by_user_id', time_entry.reviewed_by_user_id,
         'reviewed_at', time_entry.reviewed_at,
         'review_note', time_entry.review_note,
         'created_at', time_entry.created_at
       ),
       time_entry.created_at
  FROM time_entries time_entry
 WHERE NOT EXISTS (
       SELECT 1 FROM time_entry_events event
        WHERE event.tenant_id = time_entry.tenant_id
          AND event.time_entry_id = time_entry.id
          AND event.event_kind = 'logged'
 );

DROP TRIGGER IF EXISTS trg_time_entries_before_update;
DELIMITER $$
CREATE TRIGGER trg_time_entries_before_update
BEFORE UPDATE ON time_entries
FOR EACH ROW
BEGIN
  DECLARE reviewer_is_authorized INT DEFAULT 0;

  IF NOT (
       NEW.tenant_id <=> OLD.tenant_id
   AND NEW.client_id <=> OLD.client_id
   AND NEW.entry_key <=> OLD.entry_key
   AND NEW.ticket_id <=> OLD.ticket_id
   AND NEW.user_id <=> OLD.user_id
   AND NEW.minutes <=> OLD.minutes
   AND NEW.note <=> OLD.note
   AND NEW.billable <=> OLD.billable
   AND NEW.source <=> OLD.source
   AND NEW.worked_at <=> OLD.worked_at
   AND NEW.started_at <=> OLD.started_at
   AND NEW.ended_at <=> OLD.ended_at
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

  SELECT COUNT(*)
    INTO reviewer_is_authorized
    FROM users
   WHERE tenant_id = NEW.tenant_id
     AND id = NEW.reviewed_by_user_id
     AND is_active = 1
     AND role IN ('owner', 'admin');
  IF reviewer_is_authorized <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry reviewer must be an active owner or admin';
  END IF;

  SET NEW.reviewed_at = UTC_TIMESTAMP();
  SET NEW.review_note = TRIM(COALESCE(NEW.review_note, ''));
  IF NEW.approval_status = 'rejected' AND NEW.review_note = '' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rejected time requires a reason';
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_time_entries_after_update;
DELIMITER $$
CREATE TRIGGER trg_time_entries_after_update
AFTER UPDATE ON time_entries
FOR EACH ROW
BEGIN
  INSERT INTO time_entry_events
    (tenant_id, time_entry_id, actor_user_id, event_kind, from_status,
     to_status, reason, snapshot_json, created_at)
  VALUES
    (NEW.tenant_id, NEW.id, NEW.reviewed_by_user_id, NEW.approval_status,
     OLD.approval_status, NEW.approval_status, NEW.review_note,
     JSON_OBJECT(
       'id', NEW.id,
       'tenant_id', NEW.tenant_id,
       'client_id', NEW.client_id,
       'entry_key', NEW.entry_key,
       'ticket_id', NEW.ticket_id,
       'user_id', NEW.user_id,
       'minutes', NEW.minutes,
       'note', NEW.note,
       'billable', NEW.billable,
       'source', NEW.source,
       'worked_at', NEW.worked_at,
       'started_at', NEW.started_at,
       'ended_at', NEW.ended_at,
       'approval_status', NEW.approval_status,
       'reviewed_by_user_id', NEW.reviewed_by_user_id,
       'reviewed_at', NEW.reviewed_at,
       'review_note', NEW.review_note,
       'created_at', NEW.created_at
     ), NEW.reviewed_at);
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_time_entries_no_delete;
DELIMITER $$
CREATE TRIGGER trg_time_entries_no_delete
BEFORE DELETE ON time_entries
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entries cannot be deleted';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_time_entry_events_no_update;
DELIMITER $$
CREATE TRIGGER trg_time_entry_events_no_update
BEFORE UPDATE ON time_entry_events
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry events are immutable';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_time_entry_events_no_delete;
DELIMITER $$
CREATE TRIGGER trg_time_entry_events_no_delete
BEFORE DELETE ON time_entry_events
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry events are immutable';
END$$
DELIMITER ;

-- The permanent insert compatibility guard now covers every old-code write.
DROP TRIGGER trg_time_entry_insert_compatibility_staging;
DROP TRIGGER trg_time_entry_update_guard_staging;
DROP TRIGGER trg_time_entry_delete_guard_staging;
SET @safeharbor_time_migration = NULL;

-- Exact postflight. These booleans are intentionally operator-visible when
-- the migration is streamed through mysql.
SET @time_columns_exact = (
  SELECT COUNT(*) = 11
     AND SUM(column_name = 'tenant_id' AND column_type = 'int unsigned'
             AND is_nullable = 'NO' AND column_default IS NULL) = 1
     AND SUM(column_name = 'client_id' AND column_type = 'int unsigned'
             AND is_nullable = 'NO' AND column_default IS NULL) = 1
     AND SUM(column_name = 'entry_key' AND column_type = 'varchar(64)'
             AND is_nullable = 'NO' AND column_default IS NULL) = 1
     AND SUM(column_name = 'source'
             AND column_type = 'enum(''timer'',''reply'',''suggestion'',''legacy'')'
             AND is_nullable = 'NO' AND column_default = 'legacy') = 1
     AND SUM(column_name = 'worked_at' AND column_type = 'datetime'
             AND is_nullable = 'NO'
             AND UPPER(column_default) IN ('CURRENT_TIMESTAMP','CURRENT_TIMESTAMP()')) = 1
     AND SUM(column_name = 'started_at' AND column_type = 'datetime'
             AND is_nullable = 'YES' AND column_default IS NULL) = 1
     AND SUM(column_name = 'ended_at' AND column_type = 'datetime'
             AND is_nullable = 'YES' AND column_default IS NULL) = 1
     AND SUM(column_name = 'approval_status'
             AND column_type = 'enum(''pending'',''approved'',''rejected'')'
             AND is_nullable = 'NO' AND column_default = 'pending') = 1
     AND SUM(column_name = 'reviewed_by_user_id' AND column_type = 'int unsigned'
             AND is_nullable = 'YES' AND column_default IS NULL) = 1
     AND SUM(column_name = 'reviewed_at' AND column_type = 'datetime'
             AND is_nullable = 'YES' AND column_default IS NULL) = 1
     AND SUM(column_name = 'review_note' AND column_type = 'varchar(500)'
             AND is_nullable = 'NO' AND column_default = '') = 1
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'time_entries'
     AND column_name IN (
       'tenant_id', 'client_id', 'entry_key', 'source', 'worked_at',
       'started_at', 'ended_at', 'approval_status', 'reviewed_by_user_id',
       'reviewed_at', 'review_note'
     )
);
-- Refresh the constraint facts after the additive DDL above. The working
-- variables used to decide whether an ADD was needed describe the pre-DDL
-- state on a first run; operator-visible postflight must describe the schema
-- that now exists.
SET @time_check_exact = (
  SELECT COUNT(*) = 1
    FROM information_schema.check_constraints
   WHERE constraint_schema = DATABASE()
     AND constraint_name = 'ck_time_entries_minutes'
     AND REPLACE(REPLACE(REPLACE(REPLACE(LOWER(check_clause), '`', ''), ' ', ''), '(', ''), ')', '')
         = 'minutesbetween1and1440'
);
SET @time_billable_check_exact = (
  SELECT COUNT(*) = 1
    FROM information_schema.check_constraints
   WHERE constraint_schema = DATABASE()
     AND constraint_name = 'ck_time_entries_billable'
     AND REPLACE(REPLACE(REPLACE(REPLACE(LOWER(check_clause), '`', ''), ' ', ''), '(', ''), ')', '')
         = 'billablein0,1'
);
SET @time_indexes_exact = (
  SELECT COUNT(*) = 7
    FROM (
      SELECT index_name
        FROM information_schema.statistics
       WHERE table_schema = DATABASE()
         AND table_name = 'time_entries'
         AND index_name IN (
           'uq_time_entries_tenant_key',
           'uq_time_entries_tenant_id',
           'ix_time_entries_ticket',
           'ix_time_entries_user_worked',
           'ix_time_entries_client_status',
           'ix_time_entries_approval_queue',
           'ix_time_entries_reviewer'
         )
       GROUP BY index_name
    ) exact_indexes
);
SET @time_fks_exact = (
  SELECT COUNT(*) = 5
    FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND table_name = 'time_entries'
     AND constraint_type = 'FOREIGN KEY'
     AND constraint_name IN (
       'fk_time_entries_tenant',
       'fk_time_entries_ticket_tenant',
       'fk_time_entries_client_tenant',
       'fk_time_entries_user_tenant',
       'fk_time_entries_reviewer_tenant'
     )
);
SET @time_triggers_exact = (
  SELECT COUNT(*) = 7
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'trg_time_entries_before_insert',
       'trg_time_entries_after_insert',
       'trg_time_entries_before_update',
       'trg_time_entries_after_update',
       'trg_time_entries_no_delete',
       'trg_time_entry_events_no_update',
       'trg_time_entry_events_no_delete'
     )
);
SET @time_staging_triggers_absent = (
  SELECT COUNT(*) = 0
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'trg_time_entry_privilege_preflight',
       'trg_time_entry_insert_compatibility_staging',
       'trg_time_entry_update_guard_staging',
       'trg_time_entry_delete_guard_staging'
     )
);
SET @time_migration_bypass_cleared = (@safeharbor_time_migration IS NULL);

SELECT
  @time_columns_exact AS time_columns_ok,
  @time_check_exact AS minute_check_ok,
  @time_billable_check_exact AS billable_check_ok,
  @time_indexes_exact AS time_indexes_ok,
  @time_fks_exact AS time_fks_ok,
  @time_events_columns_exact AS event_columns_ok,
  @time_events_indexes_exact AS event_indexes_ok,
  @time_events_fks_exact AS event_fks_ok,
  @time_triggers_exact AS audit_triggers_ok,
  @time_staging_triggers_absent AS staging_triggers_absent,
  @time_migration_bypass_cleared AS migration_bypass_cleared;
