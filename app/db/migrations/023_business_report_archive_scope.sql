-- Bind immutable report archives to their exact schedule timezone.
-- Replay-safe: a temporary BEFORE INSERT blocker remains installed while the
-- permanent archive trigger is replaced and is removed only after verification.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET @br_archive_scope_tables_ok = (
  SELECT COUNT(*) = 5
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name IN (
       'tenants',
       'clients',
       'business_report_definition_versions',
       'business_report_schedule_versions',
       'business_report_archives'
     )
     AND engine = 'InnoDB'
);
SET @br_archive_scope_ddl = IF(
  @br_archive_scope_tables_ok = 1,
  'DO 0',
  'SELECT * FROM information_schema.migration_023_archive_scope_missing_tables'
);
PREPARE br_archive_scope_statement FROM @br_archive_scope_ddl;
EXECUTE br_archive_scope_statement;
DEALLOCATE PREPARE br_archive_scope_statement;

SET @br_archive_scope_unrecognized = (
  SELECT COUNT(*)
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND event_object_table = 'business_report_archives'
     AND trigger_name NOT IN (
       'trg_business_report_archives_before_insert',
       'trg_business_report_archives_no_update',
       'trg_business_report_archives_no_delete',
       'trg_br_archive_scope_priv_preflight',
       'trg_br_archive_scope_swap_insert'
     )
);
SET @br_archive_scope_misbound = (
  SELECT COUNT(*)
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'trg_business_report_archives_before_insert',
       'trg_business_report_archives_no_update',
       'trg_business_report_archives_no_delete',
       'trg_br_archive_scope_priv_preflight',
       'trg_br_archive_scope_swap_insert'
     )
     AND event_object_table <> 'business_report_archives'
);
SET @br_archive_scope_ddl = IF(
  @br_archive_scope_unrecognized = 0 AND @br_archive_scope_misbound = 0,
  'DO 0',
  'SELECT * FROM information_schema.migration_023_archive_scope_trigger_drift'
);
PREPARE br_archive_scope_statement FROM @br_archive_scope_ddl;
EXECUTE br_archive_scope_statement;
DEALLOCATE PREPARE br_archive_scope_statement;

-- Prove trigger DDL authority before touching the permanent guard.
DROP TRIGGER IF EXISTS trg_br_archive_scope_priv_preflight;
CREATE TRIGGER trg_br_archive_scope_priv_preflight
BEFORE INSERT ON business_report_archives
FOR EACH ROW SET @br_archive_scope_privilege_preflight = 1;
DROP TRIGGER trg_br_archive_scope_priv_preflight;

CREATE TRIGGER IF NOT EXISTS trg_br_archive_scope_swap_insert
BEFORE INSERT ON business_report_archives
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Business report archives are locked for migration 023 trigger swap';

SET @br_archive_scope_swap_ok = (
  SELECT COUNT(*) = 1
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name = 'trg_br_archive_scope_swap_insert'
     AND event_object_table = 'business_report_archives'
     AND event_manipulation = 'INSERT'
     AND action_timing = 'BEFORE'
     AND action_orientation = 'ROW'
     AND REPLACE(REPLACE(REPLACE(REPLACE(
           LOWER(action_statement), ' ', ''), CHAR(9), ''), CHAR(10), ''), CHAR(13), '')
         = 'signalsqlstate''45000''setmessage_text=''businessreportarchivesarelockedformigration023triggerswap'''
);
SET @br_archive_scope_ddl = IF(
  @br_archive_scope_swap_ok = 1,
  'DO 0',
  'SELECT * FROM information_schema.migration_023_archive_scope_swap_failed'
);
PREPARE br_archive_scope_statement FROM @br_archive_scope_ddl;
EXECUTE br_archive_scope_statement;
DEALLOCATE PREPARE br_archive_scope_statement;

DROP TRIGGER IF EXISTS trg_business_report_archives_before_insert;
DELIMITER $$
CREATE TRIGGER trg_business_report_archives_before_insert
BEFORE INSERT ON business_report_archives
FOR EACH ROW
BEGIN
  DECLARE schedule_matches INT DEFAULT 0;

  SELECT COUNT(*)
    INTO schedule_matches
    FROM business_report_schedule_versions s
    JOIN tenants t ON t.id = s.tenant_id
    JOIN clients c ON c.tenant_id = s.tenant_id AND c.id = s.client_id
    JOIN business_report_definition_versions d
      ON d.tenant_id = s.tenant_id AND d.id = s.definition_version_id
   WHERE s.tenant_id = NEW.tenant_id
     AND s.id = NEW.schedule_version_id
     AND s.schedule_key = NEW.schedule_key
     AND s.client_id = NEW.client_id
     AND s.definition_version_id = NEW.definition_version_id
     AND s.status = 'active'
     AND s.version_no = (
       SELECT MAX(latest.version_no)
         FROM business_report_schedule_versions latest
        WHERE latest.tenant_id = NEW.tenant_id
          AND latest.schedule_key = NEW.schedule_key
     )
     AND JSON_EXTRACT(NEW.metrics_json, '$.schema_version') = 1
     AND BINARY JSON_UNQUOTE(JSON_EXTRACT(NEW.metrics_json, '$.report_type')) = BINARY d.report_type
     AND BINARY JSON_UNQUOTE(JSON_EXTRACT(NEW.metrics_json, '$.definition.key')) = BINARY d.definition_key
     AND JSON_EXTRACT(NEW.metrics_json, '$.definition.version') = d.version_no
     AND JSON_UNQUOTE(JSON_EXTRACT(NEW.metrics_json, '$.definition.sha256')) = d.contract_sha256
     AND BINARY JSON_UNQUOTE(JSON_EXTRACT(NEW.metrics_json, '$.source.tenant_key')) = BINARY t.slug
     AND JSON_UNQUOTE(JSON_EXTRACT(NEW.metrics_json, '$.source.client_key'))
         = CONCAT('safeharbor-client:', NEW.client_id)
     AND BINARY JSON_UNQUOTE(JSON_EXTRACT(NEW.metrics_json, '$.source.client_name')) = BINARY c.name
     AND JSON_UNQUOTE(JSON_EXTRACT(NEW.metrics_json, '$.period.start_utc'))
         = CONCAT(DATE_FORMAT(NEW.period_start, '%Y-%m-%dT%H:%i:%s'), 'Z')
     AND JSON_UNQUOTE(JSON_EXTRACT(NEW.metrics_json, '$.period.end_utc_exclusive'))
         = CONCAT(DATE_FORMAT(NEW.period_end, '%Y-%m-%dT%H:%i:%s'), 'Z')
     AND BINARY JSON_UNQUOTE(JSON_EXTRACT(NEW.metrics_json, '$.period.schedule_timezone'))
         = BINARY s.schedule_timezone
     AND JSON_UNQUOTE(JSON_EXTRACT(NEW.metrics_json, '$.generated_at'))
         = CONCAT(DATE_FORMAT(NEW.generated_at, '%Y-%m-%dT%H:%i:%s'), 'Z');
  IF schedule_matches <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Business report archive must match an active schedule snapshot';
  END IF;
  IF SHA2(CONCAT(NEW.metrics_json, CHAR(10), NEW.report_text), 256) <> NEW.content_sha256 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Business report archive hash must match exact content bytes';
  END IF;
END$$
DELIMITER ;

SET @br_archive_scope_permanent_ok = (
  SELECT COUNT(*) = 1
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name = 'trg_business_report_archives_before_insert'
     AND event_object_table = 'business_report_archives'
     AND event_manipulation = 'INSERT'
     AND action_timing = 'BEFORE'
     AND action_orientation = 'ROW'
     AND action_statement LIKE '%$.period.start_utc%'
     AND action_statement LIKE '%$.period.end_utc_exclusive%'
     AND action_statement LIKE '%$.period.schedule_timezone%'
     AND action_statement LIKE '%s.schedule_timezone%'
     AND action_statement LIKE '%$.generated_at%'
     AND action_statement LIKE '%Business report archive must match an active schedule snapshot%'
     AND action_statement LIKE '%Business report archive hash must match exact content bytes%'
);
SET @br_archive_scope_ddl = IF(
  @br_archive_scope_permanent_ok = 1,
  'DO 0',
  'SELECT * FROM information_schema.migration_023_archive_scope_permanent_failed'
);
PREPARE br_archive_scope_statement FROM @br_archive_scope_ddl;
EXECUTE br_archive_scope_statement;
DEALLOCATE PREPARE br_archive_scope_statement;

DROP TRIGGER trg_br_archive_scope_swap_insert;

SET @br_archive_scope_final_ok = (
  SELECT COUNT(*) = 3
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND event_object_table = 'business_report_archives'
     AND trigger_name IN (
       'trg_business_report_archives_before_insert',
       'trg_business_report_archives_no_update',
       'trg_business_report_archives_no_delete'
     )
);
SET @br_archive_scope_stage_count = (
  SELECT COUNT(*)
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'trg_br_archive_scope_priv_preflight',
       'trg_br_archive_scope_swap_insert'
     )
);
SET @br_archive_scope_ddl = IF(
  @br_archive_scope_final_ok = 1
  AND @br_archive_scope_stage_count = 0,
  'DO 0',
  'SELECT * FROM information_schema.migration_023_archive_scope_postflight_failed'
);
PREPARE br_archive_scope_statement FROM @br_archive_scope_ddl;
EXECUTE br_archive_scope_statement;
DEALLOCATE PREPARE br_archive_scope_statement;

SELECT
  @br_archive_scope_permanent_ok AS archive_scope_guard_exact,
  @br_archive_scope_final_ok AS permanent_archive_guard_count_exact,
  @br_archive_scope_stage_count AS staging_guard_count;
