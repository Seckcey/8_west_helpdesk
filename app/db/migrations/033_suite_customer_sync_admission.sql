-- 033_suite_customer_sync_admission.sql
-- Milepost schema-2 customer events (A58) and the lifecycle scheduling cursor (A82).
--
-- Adds two nullable columns with no backfill: suite_customer_sync_bindings.last_admission, the
-- trigger-owned carrier for the version being written, and the immutable
-- suite_customer_sync_events.admission. Every existing row stays NULL. Five 015 guards are replaced
-- so the carrier is propagated into the receipt and checked against the exact current tuple and
-- request digest: binding before/after insert and update, and the receipt validator. `created` is
-- accepted only on active version 1; `restored` only on an active version 3 or later whose
-- immediately previous receipt is inactive. The carrier changes only with a new source version and
-- never enriches an old receipt; an unchanged carrier is reset to NULL, so a version written without
-- a new tag (including by an older receiver) carries none. Every 015 identity, version, time, digest
-- and immutability check is
-- unchanged, as are the three no-update/no-delete guards and 027's binding revocation trigger.
--
-- Adds the singleton managed_customer_lifecycle_progress row (id 1, last_binding_id 0): round-robin
-- scheduling metadata only, never business approval, status or completion evidence.
--
-- Three swap guards refuse every binding insert/update and receipt insert from before the first
-- change until the postflight passes, so an interrupted run stays closed and an exact replay
-- converges. A DML-only identity fails before any change. Accepted starting states: the completed
-- 015 guards, an interrupted 033 (all three swap guards exact), or a completed 033. Never replay 015
-- after this migration; its preflight refuses the added columns. Apply from a canonical LF checkout
-- with the existing deploy path (database first, then files):
--   bash deploy/deploy.sh --migrate app/db/migrations/033_suite_customer_sync_admission.sql

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET SESSION group_concat_max_len = 16384;

-- A swap guard is recognized only by its exact fail-closed body on its exact table and event.
SET @sync_admission_swap_body = CONCAT(
  'BEGIN', CHAR(10),
  '  SIGNAL SQLSTATE ''45000''', CHAR(10),
  '    SET MESSAGE_TEXT=''Customer sync admission migration is incomplete'';', CHAR(10),
  'END'
);

SET @sync_admission_tables_ok = (
  SELECT COUNT(*) = 2 FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name IN ('suite_customer_sync_bindings','suite_customer_sync_events')
     AND engine = 'InnoDB'
);
-- The original 015 column envelope, hashed exactly as 015 does, excluding only the two columns
-- this migration adds.
SET @sync_admission_column_fingerprint = (
  SELECT SHA2(
    GROUP_CONCAT(
      CONCAT_WS('|',
        sync_column.table_name,
        LPAD(sync_column.ordinal_position, 2, '0'),
        sync_column.column_name,
        LOWER(sync_column.column_type),
        sync_column.is_nullable,
        CASE
          WHEN sync_column.column_name IN ('created_at','updated_at','received_at')
          THEN REGEXP_REPLACE(
            LOWER(COALESCE(CAST(sync_column.column_default AS CHAR), '')),
            '[()]',
            ''
          )
          ELSE COALESCE(LOWER(CAST(sync_column.column_default AS CHAR)), '<null>')
        END,
        CASE
          WHEN sync_column.column_name = 'id' THEN LOWER(sync_column.extra)
          WHEN LOWER(sync_column.extra) IN ('','default_generated') THEN '-'
          ELSE LOWER(sync_column.extra)
        END,
        COALESCE(sync_column.character_set_name, '-'),
        CASE
          WHEN sync_column.character_set_name = 'utf8mb4'
            AND sync_column.collation_name = sync_table.table_collation
          THEN 'table_default'
          ELSE COALESCE(sync_column.collation_name, '-')
        END
      )
      ORDER BY sync_column.table_name, sync_column.ordinal_position
      SEPARATOR ';'
    ),
    256
  )
    FROM information_schema.columns sync_column
    JOIN information_schema.tables sync_table
      ON sync_table.table_schema = sync_column.table_schema
     AND sync_table.table_name = sync_column.table_name
   WHERE sync_column.table_schema = DATABASE()
     AND sync_column.table_name IN ('suite_customer_sync_bindings','suite_customer_sync_events')
     AND NOT (
       (sync_column.table_name = 'suite_customer_sync_bindings' AND sync_column.column_name = 'last_admission')
       OR (sync_column.table_name = 'suite_customer_sync_events' AND sync_column.column_name = 'admission')
     )
);
SET @sync_admission_binding_column_count = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'suite_customer_sync_bindings'
     AND column_name = 'last_admission'
);
SET @sync_admission_binding_column_exact = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'suite_customer_sync_bindings'
     AND column_name = 'last_admission' AND ordinal_position = 13
     AND column_type = 'enum(''created'',''restored'')' AND is_nullable = 'YES'
     AND column_default IS NULL AND extra = ''
     AND character_set_name = 'ascii' AND collation_name = 'ascii_bin'
);
SET @sync_admission_event_column_count = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'suite_customer_sync_events'
     AND column_name = 'admission'
);
SET @sync_admission_event_column_exact = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'suite_customer_sync_events'
     AND column_name = 'admission' AND ordinal_position = 13
     AND column_type = 'enum(''created'',''restored'')' AND is_nullable = 'YES'
     AND column_default IS NULL AND extra = ''
     AND character_set_name = 'ascii' AND collation_name = 'ascii_bin'
);
SET @sync_admission_column_totals_ok = (
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'suite_customer_sync_bindings')
    = 12 + @sync_admission_binding_column_count
  AND (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'suite_customer_sync_events')
    = 12 + @sync_admission_event_column_count
);
SET @sync_admission_envelope_ok = (
  (SELECT COUNT(DISTINCT CONCAT(table_name, ':', index_name)) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name IN ('suite_customer_sync_bindings','suite_customer_sync_events')) = 11
  AND (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE()
      AND table_name IN ('suite_customer_sync_bindings','suite_customer_sync_events')
      AND constraint_type = 'FOREIGN KEY') = 5
  AND (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE()
      AND table_name IN ('suite_customer_sync_bindings','suite_customer_sync_events')
      AND constraint_type = 'CHECK') = 10
  AND (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE()
      AND table_name IN ('suite_customer_sync_bindings','suite_customer_sync_events')
      AND constraint_type = 'CHECK' AND enforced = 'YES') = 10
);
-- Four guards this migration never touches: the three immutability guards and 027's revocation.
SET @sync_admission_fixed_ok = (
  SELECT COUNT(*) = 4 FROM information_schema.triggers
   WHERE trigger_schema = DATABASE() AND action_orientation = 'ROW'
     AND (
       (trigger_name = 'trg_suite_customer_sync_binding_no_delete'
        AND event_object_table = 'suite_customer_sync_bindings'
        AND action_timing = 'BEFORE' AND event_manipulation = 'DELETE'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = '08641adecd96e76139f1a0137efbf9a6488be28f03aa783eddef3d63b59f9956')
       OR (trigger_name = 'trg_suite_customer_sync_events_no_update'
        AND event_object_table = 'suite_customer_sync_events'
        AND action_timing = 'BEFORE' AND event_manipulation = 'UPDATE'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = '4b42bdd7850f953ab63813a72c2943eee8fc83b2f951cc064f87717ddcbf8013')
       OR (trigger_name = 'trg_suite_customer_sync_events_no_delete'
        AND event_object_table = 'suite_customer_sync_events'
        AND action_timing = 'BEFORE' AND event_manipulation = 'DELETE'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = '4b42bdd7850f953ab63813a72c2943eee8fc83b2f951cc064f87717ddcbf8013')
       OR (trigger_name = 'trg_wm_binding_revoke'
        AND event_object_table = 'suite_customer_sync_bindings'
        AND action_timing = 'AFTER' AND event_manipulation = 'UPDATE'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = '3ed3be73cf38941a79dbcf148f38e0c8133e52f21caf63d51409c8a969852ca6')
     )
);
-- The five replaced guards, each exactly the 015 body or exactly the 033 body.
SET @sync_admission_old_count = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE() AND action_orientation = 'ROW'
     AND (
       (trigger_name = 'trg_suite_customer_sync_binding_before_insert'
        AND event_object_table = 'suite_customer_sync_bindings'
        AND action_timing = 'BEFORE' AND event_manipulation = 'INSERT'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = 'e93e150a312a423c697992d05ed66aedb0a9367f5a5bf4c0551633cd02742c11')
       OR (trigger_name = 'trg_suite_customer_sync_binding_after_insert'
        AND event_object_table = 'suite_customer_sync_bindings'
        AND action_timing = 'AFTER' AND event_manipulation = 'INSERT'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = 'd7beb89b42e72a3d812ceeb041bf7db74cc4ac9eeece0891e21a7ba2455bee58')
       OR (trigger_name = 'trg_suite_customer_sync_binding_before_update'
        AND event_object_table = 'suite_customer_sync_bindings'
        AND action_timing = 'BEFORE' AND event_manipulation = 'UPDATE'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = 'a85adf370b47eb04eac035dbb87dbc8b944eff7697b28dc5783c21ad0e146727')
       OR (trigger_name = 'trg_suite_customer_sync_binding_after_update'
        AND event_object_table = 'suite_customer_sync_bindings'
        AND action_timing = 'AFTER' AND event_manipulation = 'UPDATE'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = 'd7beb89b42e72a3d812ceeb041bf7db74cc4ac9eeece0891e21a7ba2455bee58')
       OR (trigger_name = 'trg_suite_customer_sync_event_before_insert'
        AND event_object_table = 'suite_customer_sync_events'
        AND action_timing = 'BEFORE' AND event_manipulation = 'INSERT'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = '7c0d73a6146ead07feb1a811df0aec9f18e05f205a60fe59c959519c93652266')
     )
);
SET @sync_admission_new_count = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE() AND action_orientation = 'ROW'
     AND (
       (trigger_name = 'trg_suite_customer_sync_binding_before_insert'
        AND event_object_table = 'suite_customer_sync_bindings'
        AND action_timing = 'BEFORE' AND event_manipulation = 'INSERT'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = '6c2527f49d5df19ba416a765ea7befc7ebf623dc6b52ee4923e75147f768df55')
       OR (trigger_name = 'trg_suite_customer_sync_binding_after_insert'
        AND event_object_table = 'suite_customer_sync_bindings'
        AND action_timing = 'AFTER' AND event_manipulation = 'INSERT'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = '0c6e4fdecbbb7c8cac3927f9b0881b6def1dbcbb02d94068769d77acd43f3924')
       OR (trigger_name = 'trg_suite_customer_sync_binding_before_update'
        AND event_object_table = 'suite_customer_sync_bindings'
        AND action_timing = 'BEFORE' AND event_manipulation = 'UPDATE'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = '1c5e496301c7fcb9267e4704b54068bf0b6683a181c0bdeb24d30f986d5570ae')
       OR (trigger_name = 'trg_suite_customer_sync_binding_after_update'
        AND event_object_table = 'suite_customer_sync_bindings'
        AND action_timing = 'AFTER' AND event_manipulation = 'UPDATE'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = '0c6e4fdecbbb7c8cac3927f9b0881b6def1dbcbb02d94068769d77acd43f3924')
       OR (trigger_name = 'trg_suite_customer_sync_event_before_insert'
        AND event_object_table = 'suite_customer_sync_events'
        AND action_timing = 'BEFORE' AND event_manipulation = 'INSERT'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = 'aa795eb0a7a0b848532f99bebc2d066d77825e6e58d57db08e42c19bd05f74f8')
     )
);
SET @sync_admission_replaced_names = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'trg_suite_customer_sync_binding_before_insert','trg_suite_customer_sync_binding_after_insert',
       'trg_suite_customer_sync_binding_before_update','trg_suite_customer_sync_binding_after_update',
       'trg_suite_customer_sync_event_before_insert'
     )
);
SET @sync_admission_swap_count = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE() AND action_orientation = 'ROW' AND action_timing = 'BEFORE'
     AND BINARY action_statement = BINARY @sync_admission_swap_body
     AND (
       (trigger_name = 'trg_suite_customer_sync_admission_swap_binding_insert'
        AND event_object_table = 'suite_customer_sync_bindings' AND event_manipulation = 'INSERT')
       OR (trigger_name = 'trg_suite_customer_sync_admission_swap_binding_update'
        AND event_object_table = 'suite_customer_sync_bindings' AND event_manipulation = 'UPDATE')
       OR (trigger_name = 'trg_suite_customer_sync_admission_swap_event_insert'
        AND event_object_table = 'suite_customer_sync_events' AND event_manipulation = 'INSERT')
     )
);
SET @sync_admission_swap_names = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'trg_suite_customer_sync_admission_swap_binding_insert',
       'trg_suite_customer_sync_admission_swap_binding_update',
       'trg_suite_customer_sync_admission_swap_event_insert'
     )
);
SET @sync_admission_trigger_total = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND event_object_table IN ('suite_customer_sync_bindings','suite_customer_sync_events')
);
SET @sync_admission_progress_present = (
  SELECT COUNT(*) FROM information_schema.tables
   WHERE table_schema = DATABASE() AND table_name = 'managed_customer_lifecycle_progress'
);
SET @sync_admission_progress_shape = (
  (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'managed_customer_lifecycle_progress'
      AND engine = 'InnoDB') = 1
  AND (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'managed_customer_lifecycle_progress') = 3
  AND (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'managed_customer_lifecycle_progress'
      AND (
        (column_name = 'id' AND ordinal_position = 1 AND column_type = 'tinyint unsigned'
         AND is_nullable = 'NO' AND column_default IS NULL AND extra = '')
        OR (column_name = 'last_binding_id' AND ordinal_position = 2 AND column_type = 'bigint unsigned'
         AND is_nullable = 'NO' AND column_default = '0' AND extra = '')
        OR (column_name = 'updated_at' AND ordinal_position = 3 AND column_type = 'datetime(6)'
         AND is_nullable = 'NO' AND column_default IS NULL AND extra = '')
      )) = 3
  AND (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'managed_customer_lifecycle_progress') = 1
  AND (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'managed_customer_lifecycle_progress'
      AND index_name = 'PRIMARY' AND non_unique = 0 AND seq_in_index = 1
      AND column_name = 'id' AND sub_part IS NULL) = 1
  AND (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE() AND table_name = 'managed_customer_lifecycle_progress'
      AND constraint_type IN ('CHECK','FOREIGN KEY','UNIQUE')) = 1
  AND (SELECT COUNT(*) FROM information_schema.table_constraints progress_constraint
      JOIN information_schema.check_constraints progress_check
        ON progress_check.constraint_schema = progress_constraint.constraint_schema
       AND progress_check.constraint_name = progress_constraint.constraint_name
    WHERE progress_constraint.constraint_schema = DATABASE()
      AND progress_constraint.table_name = 'managed_customer_lifecycle_progress'
      AND progress_constraint.constraint_name = 'ck_mc_lifecycle_progress_singleton'
      AND progress_constraint.enforced = 'YES'
      AND REGEXP_REPLACE(REPLACE(LOWER(progress_check.check_clause), '`', ''), '[[:space:]()]', '') = 'id=1') = 1
  AND (SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema = DATABASE() AND event_object_table = 'managed_customer_lifecycle_progress') = 0
);
SET @sync_admission_preflight_sql = IF(
  @sync_admission_tables_ok = 1
  AND @sync_admission_column_fingerprint = '8f2f3b4f932534cf24489be64ac0caf4156cecb0bfdc377afe349380525d67e2'
  AND @sync_admission_column_totals_ok = 1
  AND @sync_admission_binding_column_count = @sync_admission_binding_column_exact
  AND @sync_admission_event_column_count = @sync_admission_event_column_exact
  AND @sync_admission_envelope_ok = 1
  AND @sync_admission_fixed_ok = 1
  AND @sync_admission_swap_names = @sync_admission_swap_count
  AND @sync_admission_replaced_names = @sync_admission_old_count + @sync_admission_new_count
  AND @sync_admission_trigger_total = 4 + @sync_admission_replaced_names + @sync_admission_swap_count
  AND (@sync_admission_progress_present = 0 OR @sync_admission_progress_shape = 1)
  AND (
    (@sync_admission_swap_count = 0 AND @sync_admission_old_count = 5
     AND @sync_admission_binding_column_count = 0 AND @sync_admission_event_column_count = 0
     AND @sync_admission_progress_present = 0)
    OR (@sync_admission_swap_count = 0 AND @sync_admission_new_count = 5
     AND @sync_admission_binding_column_count = 1 AND @sync_admission_event_column_count = 1
     AND @sync_admission_progress_present = 1)
    OR @sync_admission_swap_count = 3
  ),
  'DO 0',
  'SELECT * FROM information_schema.migration_033_suite_customer_sync_admission_preflight_failed'
);
PREPARE sync_admission_preflight FROM @sync_admission_preflight_sql;
EXECUTE sync_admission_preflight;
DEALLOCATE PREPARE sync_admission_preflight;

-- Prove TRIGGER privilege before any change.
DROP TRIGGER IF EXISTS trg_suite_customer_sync_admission_privilege_preflight;
CREATE TRIGGER trg_suite_customer_sync_admission_privilege_preflight
BEFORE INSERT ON suite_customer_sync_events
FOR EACH ROW SET @suite_customer_sync_admission_trigger_privilege_preflight = 1;
DROP TRIGGER trg_suite_customer_sync_admission_privilege_preflight;

DELIMITER $$
CREATE TRIGGER IF NOT EXISTS trg_suite_customer_sync_admission_swap_binding_insert
BEFORE INSERT ON suite_customer_sync_bindings
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT='Customer sync admission migration is incomplete';
END$$
CREATE TRIGGER IF NOT EXISTS trg_suite_customer_sync_admission_swap_binding_update
BEFORE UPDATE ON suite_customer_sync_bindings
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT='Customer sync admission migration is incomplete';
END$$
CREATE TRIGGER IF NOT EXISTS trg_suite_customer_sync_admission_swap_event_insert
BEFORE INSERT ON suite_customer_sync_events
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT='Customer sync admission migration is incomplete';
END$$
DELIMITER ;

SET @sync_admission_swap_ok = (
  SELECT COUNT(*) = 3 FROM information_schema.triggers
   WHERE trigger_schema = DATABASE() AND action_orientation = 'ROW' AND action_timing = 'BEFORE'
     AND BINARY action_statement = BINARY @sync_admission_swap_body
     AND (
       (trigger_name = 'trg_suite_customer_sync_admission_swap_binding_insert'
        AND event_object_table = 'suite_customer_sync_bindings' AND event_manipulation = 'INSERT')
       OR (trigger_name = 'trg_suite_customer_sync_admission_swap_binding_update'
        AND event_object_table = 'suite_customer_sync_bindings' AND event_manipulation = 'UPDATE')
       OR (trigger_name = 'trg_suite_customer_sync_admission_swap_event_insert'
        AND event_object_table = 'suite_customer_sync_events' AND event_manipulation = 'INSERT')
     )
) AND (
  SELECT COUNT(*) = 3 FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'trg_suite_customer_sync_admission_swap_binding_insert',
       'trg_suite_customer_sync_admission_swap_binding_update',
       'trg_suite_customer_sync_admission_swap_event_insert'
     )
);
SET @sync_admission_swap_sql = IF(
  @sync_admission_swap_ok = 1,
  'DO 0',
  'SELECT * FROM information_schema.migration_033_suite_customer_sync_admission_swap_failed'
);
PREPARE sync_admission_swap_statement FROM @sync_admission_swap_sql;
EXECUTE sync_admission_swap_statement;
DEALLOCATE PREPARE sync_admission_swap_statement;

SET @sync_admission_binding_alter = IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'suite_customer_sync_bindings'
      AND column_name = 'last_admission') = 0,
  'ALTER TABLE suite_customer_sync_bindings ADD COLUMN last_admission ENUM(''created'',''restored'') CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL',
  'DO 0'
);
PREPARE sync_admission_binding_alter FROM @sync_admission_binding_alter;
EXECUTE sync_admission_binding_alter;
DEALLOCATE PREPARE sync_admission_binding_alter;
SET @sync_admission_event_alter = IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'suite_customer_sync_events'
      AND column_name = 'admission') = 0,
  'ALTER TABLE suite_customer_sync_events ADD COLUMN admission ENUM(''created'',''restored'') CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL',
  'DO 0'
);
PREPARE sync_admission_event_alter FROM @sync_admission_event_alter;
EXECUTE sync_admission_event_alter;
DEALLOCATE PREPARE sync_admission_event_alter;

DROP TRIGGER IF EXISTS trg_suite_customer_sync_binding_before_insert;
DROP TRIGGER IF EXISTS trg_suite_customer_sync_binding_after_insert;
DROP TRIGGER IF EXISTS trg_suite_customer_sync_binding_before_update;
DROP TRIGGER IF EXISTS trg_suite_customer_sync_binding_after_update;
DROP TRIGGER IF EXISTS trg_suite_customer_sync_event_before_insert;

DELIMITER $$
CREATE TRIGGER trg_suite_customer_sync_binding_before_insert
BEFORE INSERT ON suite_customer_sync_bindings
FOR EACH ROW
BEGIN
  DECLARE guard_version VARCHAR(64) DEFAULT 'safeharbor-suite-customer-sync-admission-v1';
  IF NEW.source_version <> 1 OR NEW.status <> 'active' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer sync bindings must begin at active version 1';
  END IF;
  IF NEW.last_admission IS NOT NULL AND NEW.last_admission <> 'created' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer sync admission at version 1 must be created';
  END IF;
  IF NEW.last_occurred_at > DATE_ADD(UTC_TIMESTAMP(), INTERVAL 300 SECOND) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer sync event time is too far in the future';
  END IF;
  SET NEW.created_at = UTC_TIMESTAMP();
  SET NEW.updated_at = NEW.created_at;
END$$

CREATE TRIGGER trg_suite_customer_sync_binding_after_insert
AFTER INSERT ON suite_customer_sync_bindings
FOR EACH ROW
BEGIN
  DECLARE guard_version VARCHAR(64) DEFAULT 'safeharbor-suite-customer-sync-admission-v1';
  INSERT INTO suite_customer_sync_events
    (tenant_id, event_id, binding_id, customer_id, client_id,
     source_version, display_name, status, occurred_at, request_sha256,
     received_at, admission)
  VALUES
    (NEW.tenant_id, NEW.last_event_id, NEW.id, NEW.customer_id, NEW.client_id,
     NEW.source_version, NEW.display_name, NEW.status, NEW.last_occurred_at,
     NEW.last_request_sha256, NEW.updated_at, NEW.last_admission);
END$$

CREATE TRIGGER trg_suite_customer_sync_binding_before_update
BEFORE UPDATE ON suite_customer_sync_bindings
FOR EACH ROW
BEGIN
  DECLARE guard_version VARCHAR(64) DEFAULT 'safeharbor-suite-customer-sync-admission-v1';
  IF NOT (
       NEW.id <=> OLD.id
   AND NEW.tenant_id <=> OLD.tenant_id
   AND NEW.customer_id <=> OLD.customer_id
   AND NEW.client_id <=> OLD.client_id
   AND NEW.created_at <=> OLD.created_at
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer sync binding identity is immutable';
  END IF;
  IF NEW.source_version <> OLD.source_version + 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer sync source versions must be sequential';
  END IF;
  IF NEW.last_event_id = OLD.last_event_id
     OR NEW.last_request_sha256 = OLD.last_request_sha256 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer sync advances require a new event and request digest';
  END IF;
  IF NEW.last_occurred_at < OLD.last_occurred_at
     OR NEW.last_occurred_at > DATE_ADD(UTC_TIMESTAMP(), INTERVAL 300 SECOND) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer sync event times must be monotonic and not future dated';
  END IF;
  IF NEW.last_admission <=> OLD.last_admission THEN
    SET NEW.last_admission = NULL;
  END IF;
  IF NEW.last_admission IS NOT NULL
     AND NOT (NEW.last_admission = 'restored' AND OLD.status = 'inactive'
              AND NEW.status = 'active' AND NEW.source_version >= 3) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer sync restored admission must directly follow an inactive version';
  END IF;
  SET NEW.updated_at = UTC_TIMESTAMP();
END$$

CREATE TRIGGER trg_suite_customer_sync_binding_after_update
AFTER UPDATE ON suite_customer_sync_bindings
FOR EACH ROW
BEGIN
  DECLARE guard_version VARCHAR(64) DEFAULT 'safeharbor-suite-customer-sync-admission-v1';
  INSERT INTO suite_customer_sync_events
    (tenant_id, event_id, binding_id, customer_id, client_id,
     source_version, display_name, status, occurred_at, request_sha256,
     received_at, admission)
  VALUES
    (NEW.tenant_id, NEW.last_event_id, NEW.id, NEW.customer_id, NEW.client_id,
     NEW.source_version, NEW.display_name, NEW.status, NEW.last_occurred_at,
     NEW.last_request_sha256, NEW.updated_at, NEW.last_admission);
END$$

CREATE TRIGGER trg_suite_customer_sync_event_before_insert
BEFORE INSERT ON suite_customer_sync_events
FOR EACH ROW
BEGIN
  DECLARE guard_version VARCHAR(64) DEFAULT 'safeharbor-suite-customer-sync-admission-v1';
  DECLARE exact_binding INT DEFAULT 0;
  DECLARE inactive_predecessor INT DEFAULT 0;
  SELECT COUNT(*) INTO exact_binding
    FROM suite_customer_sync_bindings sync_binding
    JOIN clients sync_client
      ON sync_client.tenant_id = sync_binding.tenant_id
     AND sync_client.id = sync_binding.client_id
   WHERE sync_binding.tenant_id = NEW.tenant_id
     AND sync_binding.id = NEW.binding_id
     AND BINARY sync_binding.customer_id = BINARY NEW.customer_id
     AND sync_binding.client_id = NEW.client_id
     AND sync_binding.source_version = NEW.source_version
     AND BINARY sync_binding.display_name = BINARY NEW.display_name
     AND (
       NEW.status = 'inactive'
       OR BINARY sync_client.name = BINARY NEW.display_name
     )
     AND sync_binding.status = NEW.status
     AND BINARY sync_binding.last_event_id = BINARY NEW.event_id
     AND sync_binding.last_occurred_at = NEW.occurred_at
     AND BINARY sync_binding.last_request_sha256 = BINARY NEW.request_sha256
     AND sync_binding.last_admission <=> NEW.admission;
  IF exact_binding <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer sync receipt must match the exact binding and client';
  END IF;
  IF NEW.admission = 'created' AND (NEW.source_version <> 1 OR NEW.status <> 'active') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer sync receipt admission is not at its exact position';
  END IF;
  IF NEW.admission = 'restored' THEN
    SELECT COUNT(*) INTO inactive_predecessor
      FROM suite_customer_sync_events previous_receipt
     WHERE previous_receipt.tenant_id = NEW.tenant_id
       AND previous_receipt.binding_id = NEW.binding_id
       AND previous_receipt.source_version = NEW.source_version - 1
       AND previous_receipt.status = 'inactive';
    IF NEW.status <> 'active' OR NEW.source_version < 3 OR inactive_predecessor <> 1 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer sync receipt admission is not at its exact position';
    END IF;
  END IF;
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS managed_customer_lifecycle_progress (
  id              TINYINT UNSIGNED NOT NULL,
  last_binding_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at      DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  CONSTRAINT ck_mc_lifecycle_progress_singleton CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SET @sync_admission_progress_sql = IF(
  (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'managed_customer_lifecycle_progress'
      AND engine = 'InnoDB') = 1
  AND (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'managed_customer_lifecycle_progress'
      AND (
        (column_name = 'id' AND ordinal_position = 1 AND column_type = 'tinyint unsigned'
         AND is_nullable = 'NO' AND column_default IS NULL AND extra = '')
        OR (column_name = 'last_binding_id' AND ordinal_position = 2 AND column_type = 'bigint unsigned'
         AND is_nullable = 'NO' AND column_default = '0' AND extra = '')
        OR (column_name = 'updated_at' AND ordinal_position = 3 AND column_type = 'datetime(6)'
         AND is_nullable = 'NO' AND column_default IS NULL AND extra = '')
      )) = 3
  AND (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'managed_customer_lifecycle_progress') = 3,
  'DO 0',
  'SELECT * FROM information_schema.migration_033_lifecycle_progress_shape_failed'
);
PREPARE sync_admission_progress_statement FROM @sync_admission_progress_sql;
EXECUTE sync_admission_progress_statement;
DEALLOCATE PREPARE sync_admission_progress_statement;
INSERT INTO managed_customer_lifecycle_progress (id, last_binding_id, updated_at)
SELECT 1, 0, UTC_TIMESTAMP(6) FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM managed_customer_lifecycle_progress WHERE id = 1);

-- The swap guards stay until every replaced guard, both columns and the cursor are exact.
SET @sync_admission_new_count = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE() AND action_orientation = 'ROW'
     AND (
       (trigger_name = 'trg_suite_customer_sync_binding_before_insert'
        AND event_object_table = 'suite_customer_sync_bindings'
        AND action_timing = 'BEFORE' AND event_manipulation = 'INSERT'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = '6c2527f49d5df19ba416a765ea7befc7ebf623dc6b52ee4923e75147f768df55')
       OR (trigger_name = 'trg_suite_customer_sync_binding_after_insert'
        AND event_object_table = 'suite_customer_sync_bindings'
        AND action_timing = 'AFTER' AND event_manipulation = 'INSERT'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = '0c6e4fdecbbb7c8cac3927f9b0881b6def1dbcbb02d94068769d77acd43f3924')
       OR (trigger_name = 'trg_suite_customer_sync_binding_before_update'
        AND event_object_table = 'suite_customer_sync_bindings'
        AND action_timing = 'BEFORE' AND event_manipulation = 'UPDATE'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = '1c5e496301c7fcb9267e4704b54068bf0b6683a181c0bdeb24d30f986d5570ae')
       OR (trigger_name = 'trg_suite_customer_sync_binding_after_update'
        AND event_object_table = 'suite_customer_sync_bindings'
        AND action_timing = 'AFTER' AND event_manipulation = 'UPDATE'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = '0c6e4fdecbbb7c8cac3927f9b0881b6def1dbcbb02d94068769d77acd43f3924')
       OR (trigger_name = 'trg_suite_customer_sync_event_before_insert'
        AND event_object_table = 'suite_customer_sync_events'
        AND action_timing = 'BEFORE' AND event_manipulation = 'INSERT'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = 'aa795eb0a7a0b848532f99bebc2d066d77825e6e58d57db08e42c19bd05f74f8')
     )
);
SET @sync_admission_postflight_sql = IF(
  @sync_admission_new_count = 5
  AND @sync_admission_fixed_ok = 1
  AND (SELECT COUNT(*) FROM information_schema.triggers
        WHERE trigger_schema = DATABASE() AND action_orientation = 'ROW' AND action_timing = 'BEFORE'
          AND BINARY action_statement = BINARY @sync_admission_swap_body
          AND trigger_name IN (
            'trg_suite_customer_sync_admission_swap_binding_insert',
            'trg_suite_customer_sync_admission_swap_binding_update',
            'trg_suite_customer_sync_admission_swap_event_insert'
          )) = 3
  AND (SELECT COUNT(*) FROM information_schema.triggers
        WHERE trigger_schema = DATABASE()
          AND event_object_table IN ('suite_customer_sync_bindings','suite_customer_sync_events')) = 12
  AND (SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'suite_customer_sync_bindings'
          AND column_name = 'last_admission' AND ordinal_position = 13
          AND column_type = 'enum(''created'',''restored'')' AND is_nullable = 'YES'
          AND column_default IS NULL AND character_set_name = 'ascii' AND collation_name = 'ascii_bin') = 1
  AND (SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'suite_customer_sync_events'
          AND column_name = 'admission' AND ordinal_position = 13
          AND column_type = 'enum(''created'',''restored'')' AND is_nullable = 'YES'
          AND column_default IS NULL AND character_set_name = 'ascii' AND collation_name = 'ascii_bin') = 1
  AND (SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name IN ('suite_customer_sync_bindings','suite_customer_sync_events')) = 26
  AND (SELECT COUNT(*) FROM managed_customer_lifecycle_progress) = 1
  AND (SELECT COUNT(*) FROM managed_customer_lifecycle_progress WHERE id = 1) = 1,
  'DO 0',
  'SELECT * FROM information_schema.migration_033_suite_customer_sync_admission_postflight_failed'
);
PREPARE sync_admission_postflight FROM @sync_admission_postflight_sql;
EXECUTE sync_admission_postflight;
DEALLOCATE PREPARE sync_admission_postflight;

DROP TRIGGER trg_suite_customer_sync_admission_swap_event_insert;
DROP TRIGGER trg_suite_customer_sync_admission_swap_binding_update;
DROP TRIGGER trg_suite_customer_sync_admission_swap_binding_insert;

SET @sync_admission_final_sql = IF(
  (SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema = DATABASE()
      AND event_object_table IN ('suite_customer_sync_bindings','suite_customer_sync_events')) = 9
  AND (SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema = DATABASE()
      AND trigger_name LIKE 'trg\_suite\_customer\_sync\_admission\_%') = 0,
  'DO 0',
  'SELECT * FROM information_schema.migration_033_suite_customer_sync_admission_final_failed'
);
PREPARE sync_admission_final FROM @sync_admission_final_sql;
EXECUTE sync_admission_final;
DEALLOCATE PREPARE sync_admission_final;

SELECT
  (SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema = DATABASE()
      AND event_object_table IN ('suite_customer_sync_bindings','suite_customer_sync_events')) AS sync_trigger_count,
  (SELECT COUNT(*) FROM suite_customer_sync_events WHERE admission IS NOT NULL) AS admitted_receipt_count,
  (SELECT last_binding_id FROM managed_customer_lifecycle_progress WHERE id = 1) AS lifecycle_cursor;
