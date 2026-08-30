-- Migration 021: durable Coastmark v3 export claims and append-only receipts.
-- Shared lock order: tenant -> time entry -> actor -> claim/adjustment rows.

-- CREATE TABLE IF NOT EXISTS is only a convenience for a fresh install. It
-- must never bless a same-named object whose columns, constraints, or storage
-- engine have drifted. Serialize the complete migration before its first DDL.
-- A failed statement intentionally retains this one connection-scoped lock;
-- the migration runner must use a dedicated connection and close it on abort.
-- A retry on the same connection recognizes (and never re-enters) its lock.
SET @cm_m021_lock_name = CONCAT(
  'safeharbor:m021:',
  LEFT(SHA2(COALESCE(DATABASE(),''),256),48)
);
SET @cm_m021_lock_owner_before = IS_USED_LOCK(@cm_m021_lock_name);
SET @cm_m021_lock_acquired = NULL;
SET @cm_m021_lock_acquire_sql = IF(
  @cm_m021_lock_owner_before <=> CONNECTION_ID(),
  'SET @cm_m021_lock_acquired=1',
  'SET @cm_m021_lock_acquired=GET_LOCK(@cm_m021_lock_name,0)'
);
PREPARE cm_export_statement FROM @cm_m021_lock_acquire_sql;
EXECUTE cm_export_statement;
DEALLOCATE PREPARE cm_export_statement;
SET @cm_m021_lock_owned = (
  DATABASE() IS NOT NULL
  AND @cm_m021_lock_acquired=1
  AND (IS_USED_LOCK(@cm_m021_lock_name) <=> CONNECTION_ID())
);
SET @cm_m021_lock_sql = IF(
  @cm_m021_lock_owned=1,
  'DO 0',
  'SELECT * FROM information_schema.migration_021_advisory_lock_failed'
);
PREPARE cm_export_statement FROM @cm_m021_lock_sql;
EXECUTE cm_export_statement;
DEALLOCATE PREPARE cm_export_statement;

CREATE TABLE IF NOT EXISTS coastmark_time_export_claims (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id            INT UNSIGNED NOT NULL,
  time_entry_id        INT UNSIGNED NOT NULL,
  source_version       INT UNSIGNED NOT NULL,
  event_key            VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  predecessor_claim_id BIGINT UNSIGNED NULL,
  payload_sha256       CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  -- Preserve the exact canonical bytes whose SHA-256 is claimed. MySQL's
  -- binary JSON representation may reorder object keys when read back.
  payload_json         LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  created_by_user_id   INT UNSIGNED NOT NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cm_export_claim_tenant_id (tenant_id, id),
  UNIQUE KEY uq_cm_export_claim_event (tenant_id, event_key),
  UNIQUE KEY uq_cm_export_claim_version (tenant_id, time_entry_id, source_version),
  UNIQUE KEY uq_cm_export_claim_predecessor (tenant_id, predecessor_claim_id),
  KEY ix_cm_export_claim_actor (tenant_id, created_by_user_id, created_at, id),
  CONSTRAINT fk_cm_export_claim_tenant FOREIGN KEY (tenant_id)
    REFERENCES tenants (id),
  CONSTRAINT fk_cm_export_claim_entry FOREIGN KEY (tenant_id, time_entry_id)
    REFERENCES time_entries (tenant_id, id),
  CONSTRAINT fk_cm_export_claim_actor FOREIGN KEY (tenant_id, created_by_user_id)
    REFERENCES users (tenant_id, id),
  CONSTRAINT fk_cm_export_claim_predecessor FOREIGN KEY (tenant_id, predecessor_claim_id)
    REFERENCES coastmark_time_export_claims (tenant_id, id),
  CONSTRAINT ck_cm_export_claim_event_key
    CHECK (event_key REGEXP '^safeharbor-time:[0-9a-f]{32}$'),
  CONSTRAINT ck_cm_export_claim_hash
    CHECK (payload_sha256 REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT ck_cm_export_claim_payload CHECK (JSON_VALID(payload_json)),
  CONSTRAINT ck_cm_export_claim_predecessor_shape
    CHECK ((source_version = 0 AND predecessor_claim_id IS NULL)
        OR (source_version > 0 AND predecessor_claim_id IS NOT NULL)),
  -- Removed only after all three permanent guards are installed.
  CONSTRAINT ck_cm_export_claim_install_lock CHECK (0 = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS coastmark_time_export_receipts (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id          INT UNSIGNED NOT NULL,
  claim_id           BIGINT UNSIGNED NOT NULL,
  operation_key      VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  operation_kind     ENUM('dispatch_started','dispatch_result','status_started','status_result') NOT NULL,
  outcome            ENUM('dispatching','checking','accepted','replayed','absent','ambiguous','conflict','manual_exception') NOT NULL,
  response_status    SMALLINT UNSIGNED NULL,
  response_sha256    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  coastmark_event_id BIGINT UNSIGNED NULL,
  invoice_id         BIGINT UNSIGNED NULL,
  invoice_line_id    BIGINT UNSIGNED NULL,
  detail_code        VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cm_export_receipt_operation (tenant_id, operation_key),
  KEY ix_cm_export_receipt_claim (tenant_id, claim_id, id),
  KEY ix_cm_export_receipt_outcome (tenant_id, outcome, created_at, id),
  CONSTRAINT fk_cm_export_receipt_tenant FOREIGN KEY (tenant_id)
    REFERENCES tenants (id),
  CONSTRAINT fk_cm_export_receipt_claim FOREIGN KEY (tenant_id, claim_id)
    REFERENCES coastmark_time_export_claims (tenant_id, id),
  CONSTRAINT ck_cm_export_receipt_operation_key
    CHECK (operation_key REGEXP '^safeharbor-op:[0-9a-f]{32}$'),
  CONSTRAINT ck_cm_export_receipt_response_status
    CHECK (response_status IS NULL OR response_status BETWEEN 100 AND 599),
  CONSTRAINT ck_cm_export_receipt_response_hash
    CHECK (response_sha256 IS NULL OR response_sha256 REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT ck_cm_export_receipt_detail
    CHECK (detail_code REGEXP '^[a-z][a-z0-9_]{2,63}$'),
  CONSTRAINT ck_cm_export_receipt_ack_shape CHECK (
       (outcome = 'accepted'
        AND coastmark_event_id IS NOT NULL AND invoice_id IS NOT NULL AND invoice_line_id IS NOT NULL)
    OR (outcome = 'replayed'
        AND coastmark_event_id IS NOT NULL AND invoice_id IS NOT NULL)
    OR (outcome = 'manual_exception'
        AND coastmark_event_id IS NOT NULL AND invoice_id IS NOT NULL AND invoice_line_id IS NULL)
    OR (outcome NOT IN ('accepted','replayed','manual_exception')
        AND coastmark_event_id IS NULL AND invoice_id IS NULL AND invoice_line_id IS NULL)
  ),
  CONSTRAINT ck_cm_export_receipt_install_lock CHECK (0 = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reference objects are durable only so an interrupted statement-by-statement
-- migration can be inspected and safely resumed. Their exact owner marker,
-- empty state, dependencies, shape, and triggers are re-proved before either
-- object is ever dropped.
CREATE TABLE IF NOT EXISTS safeharbor_m021_reference_claims (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id            INT UNSIGNED NOT NULL,
  time_entry_id        INT UNSIGNED NOT NULL,
  source_version       INT UNSIGNED NOT NULL,
  event_key            VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  predecessor_claim_id BIGINT UNSIGNED NULL,
  payload_sha256       CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  payload_json         LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  created_by_user_id   INT UNSIGNED NOT NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cm_export_claim_tenant_id (tenant_id, id),
  UNIQUE KEY uq_cm_export_claim_event (tenant_id, event_key),
  UNIQUE KEY uq_cm_export_claim_version (tenant_id, time_entry_id, source_version),
  UNIQUE KEY uq_cm_export_claim_predecessor (tenant_id, predecessor_claim_id),
  KEY ix_cm_export_claim_actor (tenant_id, created_by_user_id, created_at, id),
  CONSTRAINT rf21_claim_tenant FOREIGN KEY (tenant_id)
    REFERENCES tenants (id),
  CONSTRAINT rf21_claim_entry FOREIGN KEY (tenant_id, time_entry_id)
    REFERENCES time_entries (tenant_id, id),
  CONSTRAINT rf21_claim_actor FOREIGN KEY (tenant_id, created_by_user_id)
    REFERENCES users (tenant_id, id),
  CONSTRAINT rf21_claim_predecessor FOREIGN KEY (tenant_id, predecessor_claim_id)
    REFERENCES safeharbor_m021_reference_claims (tenant_id, id),
  CONSTRAINT rc21_claim_event_key
    CHECK (event_key REGEXP '^safeharbor-time:[0-9a-f]{32}$'),
  CONSTRAINT rc21_claim_hash
    CHECK (payload_sha256 REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT rc21_claim_payload CHECK (JSON_VALID(payload_json)),
  CONSTRAINT rc21_claim_predecessor_shape
    CHECK ((source_version = 0 AND predecessor_claim_id IS NULL)
        OR (source_version > 0 AND predecessor_claim_id IS NOT NULL)),
  CONSTRAINT rc21_claim_install_lock CHECK (0 = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='safeharbor:migration:021:reference:claims:v1';

CREATE TABLE IF NOT EXISTS safeharbor_m021_reference_receipts (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id          INT UNSIGNED NOT NULL,
  claim_id           BIGINT UNSIGNED NOT NULL,
  operation_key      VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  operation_kind     ENUM('dispatch_started','dispatch_result','status_started','status_result') NOT NULL,
  outcome            ENUM('dispatching','checking','accepted','replayed','absent','ambiguous','conflict','manual_exception') NOT NULL,
  response_status    SMALLINT UNSIGNED NULL,
  response_sha256    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  coastmark_event_id BIGINT UNSIGNED NULL,
  invoice_id         BIGINT UNSIGNED NULL,
  invoice_line_id    BIGINT UNSIGNED NULL,
  detail_code        VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cm_export_receipt_operation (tenant_id, operation_key),
  KEY ix_cm_export_receipt_claim (tenant_id, claim_id, id),
  KEY ix_cm_export_receipt_outcome (tenant_id, outcome, created_at, id),
  CONSTRAINT rf21_receipt_tenant FOREIGN KEY (tenant_id)
    REFERENCES tenants (id),
  CONSTRAINT rf21_receipt_claim FOREIGN KEY (tenant_id, claim_id)
    REFERENCES safeharbor_m021_reference_claims (tenant_id, id),
  CONSTRAINT rc21_receipt_operation_key
    CHECK (operation_key REGEXP '^safeharbor-op:[0-9a-f]{32}$'),
  CONSTRAINT rc21_receipt_response_status
    CHECK (response_status IS NULL OR response_status BETWEEN 100 AND 599),
  CONSTRAINT rc21_receipt_response_hash
    CHECK (response_sha256 IS NULL OR response_sha256 REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT rc21_receipt_detail
    CHECK (detail_code REGEXP '^[a-z][a-z0-9_]{2,63}$'),
  CONSTRAINT rc21_receipt_ack_shape CHECK (
       (outcome = 'accepted'
        AND coastmark_event_id IS NOT NULL AND invoice_id IS NOT NULL AND invoice_line_id IS NOT NULL)
    OR (outcome = 'replayed'
        AND coastmark_event_id IS NOT NULL AND invoice_id IS NOT NULL)
    OR (outcome = 'manual_exception'
        AND coastmark_event_id IS NOT NULL AND invoice_id IS NOT NULL AND invoice_line_id IS NULL)
    OR (outcome NOT IN ('accepted','replayed','manual_exception')
        AND coastmark_event_id IS NULL AND invoice_id IS NULL AND invoice_line_id IS NULL)
  ),
  CONSTRAINT rc21_receipt_install_lock CHECK (0 = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='safeharbor:migration:021:reference:receipts:v1';

-- These connection-local manifests are the source-owned answer key. Durable
-- reference objects may survive a failed run, so neither their owner comment
-- nor agreement with the live tables is enough to make their shape canonical.
DROP TEMPORARY TABLE IF EXISTS safeharbor_m021_source_columns;
CREATE TEMPORARY TABLE safeharbor_m021_source_columns (
  table_name            VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ordinal_position      SMALLINT UNSIGNED NOT NULL,
  column_name           VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  column_type           VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  is_nullable           VARCHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  column_default        VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  extra                 VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  generation_expression VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  character_set_name    VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  collation_name        VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  PRIMARY KEY (table_name,ordinal_position),
  UNIQUE KEY uq_m021_source_column (table_name,column_name)
) ENGINE=MEMORY;
INSERT INTO safeharbor_m021_source_columns VALUES
  ('safeharbor_m021_reference_claims',1,'id','bigint unsigned','NO',NULL,'auto_increment','',NULL,NULL),
  ('safeharbor_m021_reference_claims',2,'tenant_id','int unsigned','NO',NULL,'','',NULL,NULL),
  ('safeharbor_m021_reference_claims',3,'time_entry_id','int unsigned','NO',NULL,'','',NULL,NULL),
  ('safeharbor_m021_reference_claims',4,'source_version','int unsigned','NO',NULL,'','',NULL,NULL),
  ('safeharbor_m021_reference_claims',5,'event_key','varchar(64)','NO',NULL,'','','ascii','ascii_bin'),
  ('safeharbor_m021_reference_claims',6,'predecessor_claim_id','bigint unsigned','YES',NULL,'','',NULL,NULL),
  ('safeharbor_m021_reference_claims',7,'payload_sha256','char(64)','NO',NULL,'','','ascii','ascii_bin'),
  ('safeharbor_m021_reference_claims',8,'payload_json','longtext','NO',NULL,'','','utf8mb4','utf8mb4_bin'),
  ('safeharbor_m021_reference_claims',9,'created_by_user_id','int unsigned','NO',NULL,'','',NULL,NULL),
  ('safeharbor_m021_reference_claims',10,'created_at','datetime','NO','CURRENT_TIMESTAMP','DEFAULT_GENERATED','',NULL,NULL),
  ('safeharbor_m021_reference_receipts',1,'id','bigint unsigned','NO',NULL,'auto_increment','',NULL,NULL),
  ('safeharbor_m021_reference_receipts',2,'tenant_id','int unsigned','NO',NULL,'','',NULL,NULL),
  ('safeharbor_m021_reference_receipts',3,'claim_id','bigint unsigned','NO',NULL,'','',NULL,NULL),
  ('safeharbor_m021_reference_receipts',4,'operation_key','varchar(64)','NO',NULL,'','','ascii','ascii_bin'),
  ('safeharbor_m021_reference_receipts',5,'operation_kind',
   'enum(''dispatch_started'',''dispatch_result'',''status_started'',''status_result'')',
   'NO',NULL,'','','utf8mb4','@table'),
  ('safeharbor_m021_reference_receipts',6,'outcome',
   'enum(''dispatching'',''checking'',''accepted'',''replayed'',''absent'',''ambiguous'',''conflict'',''manual_exception'')',
   'NO',NULL,'','','utf8mb4','@table'),
  ('safeharbor_m021_reference_receipts',7,'response_status','smallint unsigned','YES',NULL,'','',NULL,NULL),
  ('safeharbor_m021_reference_receipts',8,'response_sha256','char(64)','YES',NULL,'','','ascii','ascii_bin'),
  ('safeharbor_m021_reference_receipts',9,'coastmark_event_id','bigint unsigned','YES',NULL,'','',NULL,NULL),
  ('safeharbor_m021_reference_receipts',10,'invoice_id','bigint unsigned','YES',NULL,'','',NULL,NULL),
  ('safeharbor_m021_reference_receipts',11,'invoice_line_id','bigint unsigned','YES',NULL,'','',NULL,NULL),
  ('safeharbor_m021_reference_receipts',12,'detail_code','varchar(64)','NO',NULL,'','','utf8mb4','@table'),
  ('safeharbor_m021_reference_receipts',13,'created_at','datetime','NO','CURRENT_TIMESTAMP','DEFAULT_GENERATED','',NULL,NULL);

DROP TEMPORARY TABLE IF EXISTS safeharbor_m021_source_indexes;
CREATE TEMPORARY TABLE safeharbor_m021_source_indexes (
  table_name       VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  index_name       VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  non_unique       TINYINT UNSIGNED NOT NULL,
  seq_in_index     SMALLINT UNSIGNED NOT NULL,
  column_name      VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (table_name,index_name,seq_in_index)
) ENGINE=MEMORY;
INSERT INTO safeharbor_m021_source_indexes VALUES
  ('safeharbor_m021_reference_claims','PRIMARY',0,1,'id'),
  ('safeharbor_m021_reference_claims','uq_cm_export_claim_tenant_id',0,1,'tenant_id'),
  ('safeharbor_m021_reference_claims','uq_cm_export_claim_tenant_id',0,2,'id'),
  ('safeharbor_m021_reference_claims','uq_cm_export_claim_event',0,1,'tenant_id'),
  ('safeharbor_m021_reference_claims','uq_cm_export_claim_event',0,2,'event_key'),
  ('safeharbor_m021_reference_claims','uq_cm_export_claim_version',0,1,'tenant_id'),
  ('safeharbor_m021_reference_claims','uq_cm_export_claim_version',0,2,'time_entry_id'),
  ('safeharbor_m021_reference_claims','uq_cm_export_claim_version',0,3,'source_version'),
  ('safeharbor_m021_reference_claims','uq_cm_export_claim_predecessor',0,1,'tenant_id'),
  ('safeharbor_m021_reference_claims','uq_cm_export_claim_predecessor',0,2,'predecessor_claim_id'),
  ('safeharbor_m021_reference_claims','ix_cm_export_claim_actor',1,1,'tenant_id'),
  ('safeharbor_m021_reference_claims','ix_cm_export_claim_actor',1,2,'created_by_user_id'),
  ('safeharbor_m021_reference_claims','ix_cm_export_claim_actor',1,3,'created_at'),
  ('safeharbor_m021_reference_claims','ix_cm_export_claim_actor',1,4,'id'),
  ('safeharbor_m021_reference_receipts','PRIMARY',0,1,'id'),
  ('safeharbor_m021_reference_receipts','uq_cm_export_receipt_operation',0,1,'tenant_id'),
  ('safeharbor_m021_reference_receipts','uq_cm_export_receipt_operation',0,2,'operation_key'),
  ('safeharbor_m021_reference_receipts','ix_cm_export_receipt_claim',1,1,'tenant_id'),
  ('safeharbor_m021_reference_receipts','ix_cm_export_receipt_claim',1,2,'claim_id'),
  ('safeharbor_m021_reference_receipts','ix_cm_export_receipt_claim',1,3,'id'),
  ('safeharbor_m021_reference_receipts','ix_cm_export_receipt_outcome',1,1,'tenant_id'),
  ('safeharbor_m021_reference_receipts','ix_cm_export_receipt_outcome',1,2,'outcome'),
  ('safeharbor_m021_reference_receipts','ix_cm_export_receipt_outcome',1,3,'created_at'),
  ('safeharbor_m021_reference_receipts','ix_cm_export_receipt_outcome',1,4,'id');

DROP TEMPORARY TABLE IF EXISTS safeharbor_m021_source_fks;
CREATE TEMPORARY TABLE safeharbor_m021_source_fks (
  table_name             VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  constraint_name        VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ordinal_position       SMALLINT UNSIGNED NOT NULL,
  unique_position        SMALLINT UNSIGNED NOT NULL,
  column_name            VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  referenced_table_name  VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  referenced_column_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  unique_constraint_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (table_name,constraint_name,ordinal_position)
) ENGINE=MEMORY;
INSERT INTO safeharbor_m021_source_fks VALUES
  ('safeharbor_m021_reference_claims','rf21_claim_tenant',1,1,'tenant_id','tenants','id','PRIMARY'),
  ('safeharbor_m021_reference_claims','rf21_claim_entry',1,1,'tenant_id','time_entries','tenant_id','uq_time_entries_tenant_id'),
  ('safeharbor_m021_reference_claims','rf21_claim_entry',2,2,'time_entry_id','time_entries','id','uq_time_entries_tenant_id'),
  ('safeharbor_m021_reference_claims','rf21_claim_actor',1,1,'tenant_id','users','tenant_id','uq_users_tenant_id'),
  ('safeharbor_m021_reference_claims','rf21_claim_actor',2,2,'created_by_user_id','users','id','uq_users_tenant_id'),
  ('safeharbor_m021_reference_claims','rf21_claim_predecessor',1,1,'tenant_id','safeharbor_m021_reference_claims','tenant_id','uq_cm_export_claim_tenant_id'),
  ('safeharbor_m021_reference_claims','rf21_claim_predecessor',2,2,'predecessor_claim_id','safeharbor_m021_reference_claims','id','uq_cm_export_claim_tenant_id'),
  ('safeharbor_m021_reference_receipts','rf21_receipt_tenant',1,1,'tenant_id','tenants','id','PRIMARY'),
  ('safeharbor_m021_reference_receipts','rf21_receipt_claim',1,1,'tenant_id','safeharbor_m021_reference_claims','tenant_id','uq_cm_export_claim_tenant_id'),
  ('safeharbor_m021_reference_receipts','rf21_receipt_claim',2,2,'claim_id','safeharbor_m021_reference_claims','id','uq_cm_export_claim_tenant_id');

DROP TEMPORARY TABLE IF EXISTS safeharbor_m021_source_checks;
CREATE TEMPORARY TABLE safeharbor_m021_source_checks (
  table_name        VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  constraint_name   VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  normalized_clause VARCHAR(1000) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  install_lock      TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (table_name,constraint_name)
) ENGINE=MEMORY;
INSERT INTO safeharbor_m021_source_checks VALUES
  ('safeharbor_m021_reference_claims','rc21_claim_event_key',
   'regexp_like(event_key,''^safeharbor-time:[0-9a-f]{32}$'')',0),
  ('safeharbor_m021_reference_claims','rc21_claim_hash',
   'regexp_like(payload_sha256,''^[0-9a-f]{64}$'')',0),
  ('safeharbor_m021_reference_claims','rc21_claim_payload','json_valid(payload_json)',0),
  ('safeharbor_m021_reference_claims','rc21_claim_predecessor_shape',
   'source_version=0andpredecessor_claim_idisnullorsource_version>0andpredecessor_claim_idisnotnull',0),
  ('safeharbor_m021_reference_claims','rc21_claim_install_lock','0=1',1),
  ('safeharbor_m021_reference_receipts','rc21_receipt_operation_key',
   'regexp_like(operation_key,''^safeharbor-op:[0-9a-f]{32}$'')',0),
  ('safeharbor_m021_reference_receipts','rc21_receipt_response_status',
   'response_statusisnullorresponse_statusbetween100and599',0),
  ('safeharbor_m021_reference_receipts','rc21_receipt_response_hash',
   'response_sha256isnullorregexp_like(response_sha256,''^[0-9a-f]{64}$'')',0),
  ('safeharbor_m021_reference_receipts','rc21_receipt_detail',
   'regexp_like(detail_code,''^[a-z][a-z0-9_]{2,63}$'')',0),
  ('safeharbor_m021_reference_receipts','rc21_receipt_ack_shape',
   'outcome=''accepted''andcoastmark_event_idisnotnullandinvoice_idisnotnullandinvoice_line_idisnotnulloroutcome=''replayed''andcoastmark_event_idisnotnullandinvoice_idisnotnulloroutcome=''manual_exception''andcoastmark_event_idisnotnullandinvoice_idisnotnullandinvoice_line_idisnulloroutcomenotin(''accepted'',''replayed'',''manual_exception'')andcoastmark_event_idisnullandinvoice_idisnullandinvoice_line_idisnull',0),
  ('safeharbor_m021_reference_receipts','rc21_receipt_install_lock','0=1',1);

DROP TEMPORARY TABLE IF EXISTS safeharbor_m021_reference_trigger_allowlist;
CREATE TEMPORARY TABLE safeharbor_m021_reference_trigger_allowlist (
  trigger_name       VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_object_table VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_manipulation VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (trigger_name)
) ENGINE=MEMORY;
INSERT INTO safeharbor_m021_reference_trigger_allowlist
  (trigger_name,event_object_table,event_manipulation)
VALUES
  ('trg_cm_ref_021_claim_insert_swap','safeharbor_m021_reference_claims','INSERT'),
  ('trg_cm_ref_021_claim_update_swap','safeharbor_m021_reference_claims','UPDATE'),
  ('trg_cm_ref_021_claim_delete_swap','safeharbor_m021_reference_claims','DELETE'),
  ('trg_cm_ref_021_receipt_insert_swap','safeharbor_m021_reference_receipts','INSERT'),
  ('trg_cm_ref_021_receipt_update_swap','safeharbor_m021_reference_receipts','UPDATE'),
  ('trg_cm_ref_021_receipt_delete_swap','safeharbor_m021_reference_receipts','DELETE'),
  ('trg_cm_ref_021_claim_before_insert','safeharbor_m021_reference_claims','INSERT'),
  ('trg_cm_ref_021_claim_no_update','safeharbor_m021_reference_claims','UPDATE'),
  ('trg_cm_ref_021_claim_no_delete','safeharbor_m021_reference_claims','DELETE'),
  ('trg_cm_ref_021_receipt_before_insert','safeharbor_m021_reference_receipts','INSERT'),
  ('trg_cm_ref_021_receipt_no_update','safeharbor_m021_reference_receipts','UPDATE'),
  ('trg_cm_ref_021_receipt_no_delete','safeharbor_m021_reference_receipts','DELETE');

SET @cm_reference_source_tables_ok = (
  SELECT COUNT(*)=2
     AND COALESCE(SUM(
       live.engine='InnoDB'
       AND charset_map.character_set_name='utf8mb4'
       AND CAST(live.table_comment AS BINARY)=CAST(
         IF(live.table_name='safeharbor_m021_reference_claims',
            'safeharbor:migration:021:reference:claims:v1',
            'safeharbor:migration:021:reference:receipts:v1') AS BINARY)
     ),0)=2
    FROM information_schema.tables live
    JOIN information_schema.collation_character_set_applicability charset_map
      ON charset_map.collation_name=live.table_collation
   WHERE live.table_schema=DATABASE()
     AND live.table_type='BASE TABLE'
     AND live.table_name IN
         ('safeharbor_m021_reference_claims','safeharbor_m021_reference_receipts')
);
SET @cm_reference_source_columns_ok = (
  SELECT (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema=DATABASE()
             AND table_name IN
                 ('safeharbor_m021_reference_claims','safeharbor_m021_reference_receipts'))
           = COUNT(*)
     AND COALESCE(SUM(
           live.column_name <=> expected.column_name
       AND CAST(live.column_type AS BINARY) <=> CAST(expected.column_type AS BINARY)
       AND live.is_nullable <=> expected.is_nullable
       AND CAST(live.column_default AS BINARY) <=> CAST(expected.column_default AS BINARY)
       AND CAST(live.extra AS BINARY) <=> CAST(expected.extra AS BINARY)
       AND CAST(live.generation_expression AS BINARY)
             <=> CAST(expected.generation_expression AS BINARY)
       AND live.character_set_name <=> expected.character_set_name
       AND live.collation_name <=> IF(expected.collation_name='@table',
             reference_table.table_collation,expected.collation_name)
     ),0)=COUNT(*)
    FROM safeharbor_m021_source_columns expected
    JOIN information_schema.tables reference_table
      ON reference_table.table_schema=DATABASE()
     AND reference_table.table_name=expected.table_name
    LEFT JOIN information_schema.columns live
      ON live.table_schema=DATABASE()
     AND live.table_name=expected.table_name
     AND live.ordinal_position=expected.ordinal_position
);
SET @cm_reference_source_indexes_ok = (
  SELECT (SELECT COUNT(*) FROM information_schema.statistics
           WHERE table_schema=DATABASE()
             AND table_name IN
                 ('safeharbor_m021_reference_claims','safeharbor_m021_reference_receipts'))
           = COUNT(*)
     AND COALESCE(SUM(
           live.index_name <=> expected.index_name
       AND live.non_unique <=> expected.non_unique
       AND live.seq_in_index <=> expected.seq_in_index
       AND live.column_name <=> expected.column_name
       AND live.collation='A'
       AND live.sub_part IS NULL
       AND live.packed IS NULL
       AND live.nullable <=> IF(source_column.is_nullable='YES','YES','')
       AND live.index_type='BTREE'
       AND live.comment=''
       AND live.index_comment=''
       AND live.is_visible='YES'
       AND live.expression IS NULL
     ),0)=COUNT(*)
    FROM safeharbor_m021_source_indexes expected
    JOIN safeharbor_m021_source_columns source_column
      ON source_column.table_name=expected.table_name
     AND source_column.column_name=expected.column_name
    LEFT JOIN information_schema.statistics live
      ON live.table_schema=DATABASE()
     AND live.table_name=expected.table_name
     AND live.index_name=expected.index_name
     AND live.seq_in_index=expected.seq_in_index
);
SET @cm_reference_source_fks_ok = (
  SELECT (SELECT COUNT(*) FROM information_schema.key_column_usage
           WHERE table_schema=DATABASE()
             AND table_name IN
                 ('safeharbor_m021_reference_claims','safeharbor_m021_reference_receipts')
             AND referenced_table_name IS NOT NULL)=COUNT(*)
     AND COALESCE(SUM(
           live.constraint_name <=> expected.constraint_name
       AND live.ordinal_position <=> expected.ordinal_position
       AND live.position_in_unique_constraint <=> expected.unique_position
       AND live.column_name <=> expected.column_name
       AND live.referenced_table_schema=DATABASE()
       AND live.referenced_table_name <=> expected.referenced_table_name
       AND live.referenced_column_name <=> expected.referenced_column_name
       AND rule.unique_constraint_schema=DATABASE()
       AND rule.unique_constraint_name <=> expected.unique_constraint_name
       AND rule.match_option='NONE'
       AND rule.update_rule='NO ACTION'
       AND rule.delete_rule='NO ACTION'
     ),0)=COUNT(*)
    FROM safeharbor_m021_source_fks expected
    LEFT JOIN information_schema.key_column_usage live
      ON live.table_schema=DATABASE()
     AND live.table_name=expected.table_name
     AND live.constraint_name=expected.constraint_name
     AND live.ordinal_position=expected.ordinal_position
    LEFT JOIN information_schema.referential_constraints rule
      ON rule.constraint_schema=live.constraint_schema
     AND rule.table_name=live.table_name
     AND rule.constraint_name=live.constraint_name
);
SET @cm_reference_source_checks_ok = (
  (SELECT COUNT(*)=0
     FROM information_schema.table_constraints live_constraint
     JOIN information_schema.check_constraints live_check
       ON live_check.constraint_schema=live_constraint.constraint_schema
      AND live_check.constraint_name=live_constraint.constraint_name
     LEFT JOIN safeharbor_m021_source_checks expected
       ON expected.table_name=live_constraint.table_name
      AND expected.constraint_name=live_constraint.constraint_name
    WHERE live_constraint.constraint_schema=DATABASE()
      AND live_constraint.table_name IN
          ('safeharbor_m021_reference_claims','safeharbor_m021_reference_receipts')
      AND live_constraint.constraint_type='CHECK'
      AND (expected.constraint_name IS NULL
           OR live_constraint.enforced<>'YES'
           OR CAST(REGEXP_REPLACE(
                REPLACE(REPLACE(REPLACE(live_check.check_clause,'`',''),
                                '_utf8mb4',''),'_ascii',''),
                '[[:space:]()]','') AS BINARY)
              <> CAST(expected.normalized_clause AS BINARY)))
  AND
  (SELECT COUNT(*)=9
     FROM safeharbor_m021_source_checks expected
     JOIN information_schema.table_constraints live_constraint
       ON live_constraint.constraint_schema=DATABASE()
      AND live_constraint.table_name=expected.table_name
      AND live_constraint.constraint_name=expected.constraint_name
      AND live_constraint.constraint_type='CHECK'
      AND live_constraint.enforced='YES'
    WHERE expected.install_lock=0)
  AND
  (SELECT COUNT(*)<=1 FROM information_schema.table_constraints
    WHERE constraint_schema=DATABASE()
      AND table_name='safeharbor_m021_reference_claims'
      AND constraint_type='CHECK'
      AND constraint_name='rc21_claim_install_lock')
  AND
  (SELECT COUNT(*)<=1 FROM information_schema.table_constraints
    WHERE constraint_schema=DATABASE()
      AND table_name='safeharbor_m021_reference_receipts'
      AND constraint_type='CHECK'
      AND constraint_name='rc21_receipt_install_lock')
);
SET @cm_reference_entry_source_shape_ok = (
  @cm_reference_source_tables_ok=1
  AND @cm_reference_source_columns_ok=1
  AND @cm_reference_source_indexes_ok=1
  AND @cm_reference_source_fks_ok=1
  AND @cm_reference_source_checks_ok=1
);

SET @cm_reference_entry_owned = (
  SELECT COUNT(*)=2
     AND COALESCE(SUM(
       table_name='safeharbor_m021_reference_claims'
       AND CAST(table_comment AS BINARY)=
           CAST('safeharbor:migration:021:reference:claims:v1' AS BINARY)
     ),0)=1
     AND COALESCE(SUM(
       table_name='safeharbor_m021_reference_receipts'
       AND CAST(table_comment AS BINARY)=
           CAST('safeharbor:migration:021:reference:receipts:v1' AS BINARY)
     ),0)=1
    FROM information_schema.tables
   WHERE table_schema=DATABASE()
     AND table_type='BASE TABLE'
     AND table_name IN
         ('safeharbor_m021_reference_claims','safeharbor_m021_reference_receipts')
);
SET @cm_reference_entry_empty = (
  (SELECT COUNT(*) FROM safeharbor_m021_reference_claims)=0
  AND (SELECT COUNT(*) FROM safeharbor_m021_reference_receipts)=0
);
SET @cm_reference_entry_dependencies_ok = (
  (SELECT COUNT(*)
     FROM information_schema.key_column_usage dependent
    WHERE dependent.referenced_table_schema=DATABASE()
      AND dependent.referenced_table_name IN
          ('safeharbor_m021_reference_claims','safeharbor_m021_reference_receipts')
      AND NOT (
        dependent.table_schema=DATABASE()
        AND (
          (dependent.table_name='safeharbor_m021_reference_claims'
           AND dependent.constraint_name='rf21_claim_predecessor'
           AND ((dependent.ordinal_position=1
                 AND dependent.column_name='tenant_id'
                 AND dependent.referenced_column_name='tenant_id')
             OR (dependent.ordinal_position=2
                 AND dependent.column_name='predecessor_claim_id'
                 AND dependent.referenced_column_name='id')))
          OR
          (dependent.table_name='safeharbor_m021_reference_receipts'
           AND dependent.constraint_name='rf21_receipt_claim'
           AND ((dependent.ordinal_position=1
                 AND dependent.column_name='tenant_id'
                 AND dependent.referenced_column_name='tenant_id')
             OR (dependent.ordinal_position=2
                 AND dependent.column_name='claim_id'
                 AND dependent.referenced_column_name='id')))
        )
      ))=0
  AND (SELECT COUNT(*)
         FROM information_schema.view_table_usage
        WHERE table_schema=DATABASE()
          AND table_name IN
              ('safeharbor_m021_reference_claims','safeharbor_m021_reference_receipts'))=0
  AND (SELECT COUNT(*)
         FROM information_schema.routines
        WHERE routine_schema=DATABASE()
          AND (routine_definition IS NULL
               OR LOCATE('safeharbor_m021_reference_claims',
                         LOWER(routine_definition))>0
               OR LOCATE('safeharbor_m021_reference_receipts',
                         LOWER(routine_definition))>0))=0
);
-- MySQL cannot reopen one temporary table twice in a statement. Validate
-- reference-table attachments and schema-reserved names in separate exact
-- statements, then combine the two fail-closed answers.
SET @cm_reference_entry_object_triggers_ok = (
  SELECT COUNT(*)=0
    FROM information_schema.triggers live
    LEFT JOIN safeharbor_m021_reference_trigger_allowlist allowed
      ON CAST(allowed.trigger_name AS BINARY)=CAST(live.trigger_name AS BINARY)
     AND CAST(allowed.event_object_table AS BINARY)=CAST(live.event_object_table AS BINARY)
     AND CAST(allowed.event_manipulation AS BINARY)=CAST(live.event_manipulation AS BINARY)
   WHERE live.trigger_schema=DATABASE()
     AND live.event_object_table IN
         ('safeharbor_m021_reference_claims','safeharbor_m021_reference_receipts')
     AND (allowed.trigger_name IS NULL
          OR live.action_timing<>'BEFORE'
          OR live.action_orientation<>'ROW'
          OR live.action_condition IS NOT NULL)
);
SET @cm_reference_entry_reserved_triggers_ok = (
  SELECT COUNT(*)=0
    FROM information_schema.triggers live
    JOIN safeharbor_m021_reference_trigger_allowlist reserved
      ON LOWER(reserved.trigger_name)=LOWER(live.trigger_name)
   WHERE live.trigger_schema=DATABASE()
     AND (CAST(reserved.trigger_name AS BINARY)<>CAST(live.trigger_name AS BINARY)
          OR CAST(reserved.event_object_table AS BINARY)<>
             CAST(live.event_object_table AS BINARY)
          OR CAST(reserved.event_manipulation AS BINARY)<>
             CAST(live.event_manipulation AS BINARY)
          OR live.action_timing<>'BEFORE'
          OR live.action_orientation<>'ROW'
          OR live.action_condition IS NOT NULL)
);
SET @cm_reference_entry_triggers_ok = (
  @cm_reference_entry_object_triggers_ok=1
  AND @cm_reference_entry_reserved_triggers_ok=1
);
SET @cm_reference_entry_failure = CASE
  WHEN NOT (@cm_m021_lock_owned <=> 1) THEN 'migration_021_advisory_lock_lost'
  WHEN NOT (@cm_reference_entry_owned <=> 1) THEN 'migration_021_reference_owner_failed'
  WHEN NOT (@cm_reference_entry_empty <=> 1) THEN 'migration_021_reference_rows_not_empty'
  WHEN NOT (@cm_reference_entry_dependencies_ok <=> 1) THEN 'migration_021_reference_dependency_failed'
  WHEN NOT (@cm_reference_entry_source_shape_ok <=> 1) THEN 'migration_021_reference_source_shape_failed'
  WHEN NOT (@cm_reference_entry_triggers_ok <=> 1) THEN 'migration_021_reference_trigger_state_failed'
  ELSE NULL
END;
SET @cm_reference_entry_sql = IF(
  @cm_reference_entry_failure IS NULL,
  'DO 0',
  CONCAT('SELECT * FROM information_schema.',@cm_reference_entry_failure)
);
PREPARE cm_export_statement FROM @cm_reference_entry_sql;
EXECUTE cm_export_statement;
DEALLOCATE PREPARE cm_export_statement;

DROP TRIGGER IF EXISTS trg_cm_ref_021_claim_insert_swap;
DROP TRIGGER IF EXISTS trg_cm_ref_021_claim_update_swap;
DROP TRIGGER IF EXISTS trg_cm_ref_021_claim_delete_swap;
DROP TRIGGER IF EXISTS trg_cm_ref_021_receipt_insert_swap;
DROP TRIGGER IF EXISTS trg_cm_ref_021_receipt_update_swap;
DROP TRIGGER IF EXISTS trg_cm_ref_021_receipt_delete_swap;
DROP TRIGGER IF EXISTS trg_cm_ref_021_claim_before_insert;
DROP TRIGGER IF EXISTS trg_cm_ref_021_claim_no_update;
DROP TRIGGER IF EXISTS trg_cm_ref_021_claim_no_delete;
DROP TRIGGER IF EXISTS trg_cm_ref_021_receipt_before_insert;
DROP TRIGGER IF EXISTS trg_cm_ref_021_receipt_no_update;
DROP TRIGGER IF EXISTS trg_cm_ref_021_receipt_no_delete;

DELIMITER $$
-- Build the exact trigger answer key on migration-owned reference tables.
-- MySQL serializes both the reference and live bodies on this same server;
-- binary ACTION_STATEMENT hashes therefore preserve every quoted byte
-- without depending on formatting differences between MySQL versions.
CREATE TRIGGER trg_cm_ref_021_claim_insert_swap
BEFORE INSERT ON safeharbor_m021_reference_claims FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'migration 021 claim trigger swap';
END$$
CREATE TRIGGER trg_cm_ref_021_claim_update_swap
BEFORE UPDATE ON safeharbor_m021_reference_claims FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'migration 021 claim trigger swap';
END$$
CREATE TRIGGER trg_cm_ref_021_claim_delete_swap
BEFORE DELETE ON safeharbor_m021_reference_claims FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'migration 021 claim trigger swap';
END$$
CREATE TRIGGER trg_cm_ref_021_receipt_insert_swap
BEFORE INSERT ON safeharbor_m021_reference_receipts FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'migration 021 receipt trigger swap';
END$$
CREATE TRIGGER trg_cm_ref_021_receipt_update_swap
BEFORE UPDATE ON safeharbor_m021_reference_receipts FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'migration 021 receipt trigger swap';
END$$
CREATE TRIGGER trg_cm_ref_021_receipt_delete_swap
BEFORE DELETE ON safeharbor_m021_reference_receipts FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'migration 021 receipt trigger swap';
END$$
CREATE TRIGGER trg_cm_ref_021_claim_before_insert
BEFORE INSERT ON safeharbor_m021_reference_claims
FOR EACH ROW
BEGIN
  DECLARE tenant_found INT DEFAULT 0;
  DECLARE parent_found INT DEFAULT 0;
  DECLARE parent_status VARCHAR(16) DEFAULT NULL;
  DECLARE parent_billable TINYINT DEFAULT NULL;
  DECLARE parent_reviewer INT UNSIGNED DEFAULT NULL;
  DECLARE parent_reviewed DATETIME DEFAULT NULL;
  DECLARE parent_client_id INT UNSIGNED DEFAULT NULL;
  DECLARE binding_found INT DEFAULT 0;
  DECLARE binding_customer_id CHAR(36) DEFAULT NULL;
  DECLARE binding_status VARCHAR(16) DEFAULT NULL;
  DECLARE payload_client_key VARCHAR(128) DEFAULT NULL;
  DECLARE actor_found INT DEFAULT 0;
  DECLARE actor_role VARCHAR(32) DEFAULT NULL;
  DECLARE actor_active TINYINT DEFAULT NULL;
  DECLARE latest_version INT DEFAULT -1;
  DECLARE latest_claim_id BIGINT UNSIGNED DEFAULT NULL;

  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET tenant_found = 0;
    SELECT 1 INTO tenant_found FROM tenants WHERE id=NEW.tenant_id FOR UPDATE;
  END;
  IF tenant_found <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export claim tenant does not exist';
  END IF;

  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET parent_found = 0;
    SELECT 1,approval_status,billable,reviewed_by_user_id,reviewed_at,client_id
      INTO parent_found,parent_status,parent_billable,parent_reviewer,parent_reviewed,
           parent_client_id
      FROM time_entries
     WHERE tenant_id=NEW.tenant_id AND id=NEW.time_entry_id
     FOR UPDATE;
  END;
  IF parent_found <> 1 OR BINARY parent_status <> BINARY 'approved' OR parent_billable <> 1
     OR parent_reviewer IS NULL OR parent_reviewed IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export claims require approved billable reviewed time';
  END IF;

  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND
      BEGIN
        SET binding_found=0;
        SET binding_customer_id=NULL;
        SET binding_status=NULL;
      END;
    SELECT 1,customer_id,status
      INTO binding_found,binding_customer_id,binding_status
      FROM suite_customer_sync_bindings
     WHERE tenant_id=NEW.tenant_id AND client_id=parent_client_id
     FOR UPDATE;
  END;
  SET payload_client_key=JSON_UNQUOTE(JSON_EXTRACT(NEW.payload_json,'$.client_key'));
  IF binding_found <> 1 OR BINARY binding_status <> BINARY 'active'
     OR NOT (BINARY payload_client_key <=>
             BINARY CONCAT('milepost-customer:',binding_customer_id)) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Export claims require an active matching customer binding';
  END IF;

  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET actor_found = 0;
    SELECT 1,role,is_active INTO actor_found,actor_role,actor_active
      FROM users
     WHERE tenant_id=NEW.tenant_id AND id=NEW.created_by_user_id
     FOR UPDATE;
  END;
  IF actor_found <> 1 OR actor_active <> 1
     OR (BINARY actor_role <> BINARY 'owner'
         AND BINARY actor_role <> BINARY 'admin') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export claim actor is not authorized';
  END IF;

  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND
      BEGIN SET latest_version=-1; SET latest_claim_id=NULL; END;
    SELECT source_version,id INTO latest_version,latest_claim_id
      FROM coastmark_time_export_claims
     WHERE tenant_id=NEW.tenant_id AND time_entry_id=NEW.time_entry_id
     ORDER BY source_version DESC LIMIT 1;
  END;
  IF NEW.source_version <> latest_version + 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export claim does not follow current source version';
  END IF;
  IF NOT (NEW.predecessor_claim_id <=> latest_claim_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export claim predecessor is not current';
  END IF;
  SET NEW.created_at=UTC_TIMESTAMP();
END$$
CREATE TRIGGER trg_cm_ref_021_claim_no_update
BEFORE UPDATE ON safeharbor_m021_reference_claims FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Coastmark export claims are immutable';
END$$
CREATE TRIGGER trg_cm_ref_021_claim_no_delete
BEFORE DELETE ON safeharbor_m021_reference_claims FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Coastmark export claims cannot be deleted';
END$$
CREATE TRIGGER trg_cm_ref_021_receipt_before_insert
BEFORE INSERT ON safeharbor_m021_reference_receipts
FOR EACH ROW
BEGIN
  DECLARE tenant_found INT DEFAULT 0;
  DECLARE claim_found INT DEFAULT 0;
  DECLARE latest_found INT DEFAULT 0;
  DECLARE latest_kind VARCHAR(32) DEFAULT NULL;
  DECLARE latest_outcome VARCHAR(32) DEFAULT NULL;
  DECLARE latest_created DATETIME DEFAULT NULL;
  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET tenant_found=0;
    SELECT 1 INTO tenant_found FROM tenants WHERE id=NEW.tenant_id FOR UPDATE;
  END;
  IF tenant_found <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export receipt tenant does not exist';
  END IF;
  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET claim_found=0;
    SELECT 1 INTO claim_found
      FROM coastmark_time_export_claims
     WHERE tenant_id=NEW.tenant_id AND id=NEW.claim_id
     FOR UPDATE;
  END;
  IF claim_found <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export receipt claim does not exist';
  END IF;
  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND
      BEGIN
        SET latest_found=0;
        SET latest_kind=NULL;
        SET latest_outcome=NULL;
        SET latest_created=NULL;
      END;
    SELECT 1,operation_kind,outcome,created_at
      INTO latest_found,latest_kind,latest_outcome,latest_created
      FROM coastmark_time_export_receipts
     WHERE tenant_id=NEW.tenant_id AND claim_id=NEW.claim_id
     ORDER BY id DESC LIMIT 1;
  END;
  IF NEW.operation_kind = 'dispatch_started' THEN
    IF NEW.outcome <> 'dispatching'
       OR (latest_found=1 AND latest_outcome <> 'absent') THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export dispatch transition is not permitted';
    END IF;
  ELSEIF NEW.operation_kind = 'status_started' THEN
    IF NEW.outcome <> 'checking'
       OR NOT (IS_USED_LOCK(CONCAT('safeharbor:cm-status:',NEW.claim_id))
               <=> CONNECTION_ID())
       OR (latest_found=1 AND latest_outcome IN
           ('accepted','replayed','manual_exception','conflict'))
       OR (latest_found=1
           AND latest_outcome IN ('dispatching','checking')
           AND latest_created > DATE_SUB(UTC_TIMESTAMP(),INTERVAL 35 SECOND)) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export status transition is not permitted';
    END IF;
  ELSEIF NEW.operation_kind = 'dispatch_result' THEN
    IF latest_found<>1 OR latest_kind <> 'dispatch_started' OR latest_outcome <> 'dispatching'
       OR NEW.outcome NOT IN ('accepted','replayed','ambiguous','conflict','manual_exception') THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export dispatch result transition is not permitted';
    END IF;
  ELSEIF NEW.operation_kind = 'status_result' THEN
    IF latest_found<>1 OR latest_kind <> 'status_started' OR latest_outcome <> 'checking'
       OR NEW.outcome NOT IN ('accepted','replayed','absent','ambiguous','conflict','manual_exception') THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export status result transition is not permitted';
    END IF;
  ELSE
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export receipt operation is not permitted';
  END IF;
  SET NEW.created_at=UTC_TIMESTAMP();
END$$
CREATE TRIGGER trg_cm_ref_021_receipt_no_update
BEFORE UPDATE ON safeharbor_m021_reference_receipts FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Coastmark export receipts are immutable';
END$$
CREATE TRIGGER trg_cm_ref_021_receipt_no_delete
BEFORE DELETE ON safeharbor_m021_reference_receipts FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Coastmark export receipts cannot be deleted';
END$$
DELIMITER ;


SET @cm_claim_table_ok = (
  SELECT COUNT(*) = 2
     AND COUNT(DISTINCT engine) = 1
     AND COUNT(DISTINCT table_collation) = 1
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name IN
         ('coastmark_time_export_claims','safeharbor_m021_reference_claims')
);
SET @cm_receipt_table_ok = (
  SELECT COUNT(*) = 2
     AND COUNT(DISTINCT engine) = 1
     AND COUNT(DISTINCT table_collation) = 1
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name IN
         ('coastmark_time_export_receipts','safeharbor_m021_reference_receipts')
);
SET @cm_claim_columns_ok = (
  SELECT (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema=DATABASE()
             AND table_name='coastmark_time_export_claims') = COUNT(*)
     AND COALESCE(SUM(
           live_column.column_name <=> canonical_column.column_name
       AND CAST(live_column.column_type AS BINARY)
             <=> CAST(canonical_column.column_type AS BINARY)
       AND live_column.is_nullable <=> canonical_column.is_nullable
       AND CAST(live_column.column_default AS BINARY)
             <=> CAST(canonical_column.column_default AS BINARY)
       AND CAST(live_column.extra AS BINARY)
             <=> CAST(canonical_column.extra AS BINARY)
       AND CAST(live_column.generation_expression AS BINARY)
             <=> CAST(canonical_column.generation_expression AS BINARY)
       AND live_column.character_set_name <=> canonical_column.character_set_name
       AND live_column.collation_name <=> canonical_column.collation_name
     ),0) = COUNT(*)
    FROM information_schema.columns canonical_column
    LEFT JOIN information_schema.columns live_column
      ON live_column.table_schema=canonical_column.table_schema
     AND live_column.table_name='coastmark_time_export_claims'
     AND live_column.ordinal_position=canonical_column.ordinal_position
   WHERE canonical_column.table_schema=DATABASE()
     AND canonical_column.table_name='safeharbor_m021_reference_claims'
);
SET @cm_receipt_columns_ok = (
  SELECT (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema=DATABASE()
             AND table_name='coastmark_time_export_receipts') = COUNT(*)
     AND COALESCE(SUM(
           live_column.column_name <=> canonical_column.column_name
       AND CAST(live_column.column_type AS BINARY)
             <=> CAST(canonical_column.column_type AS BINARY)
       AND live_column.is_nullable <=> canonical_column.is_nullable
       AND CAST(live_column.column_default AS BINARY)
             <=> CAST(canonical_column.column_default AS BINARY)
       AND CAST(live_column.extra AS BINARY)
             <=> CAST(canonical_column.extra AS BINARY)
       AND CAST(live_column.generation_expression AS BINARY)
             <=> CAST(canonical_column.generation_expression AS BINARY)
       AND live_column.character_set_name <=> canonical_column.character_set_name
       AND live_column.collation_name <=> canonical_column.collation_name
     ),0) = COUNT(*)
    FROM information_schema.columns canonical_column
    LEFT JOIN information_schema.columns live_column
      ON live_column.table_schema=canonical_column.table_schema
     AND live_column.table_name='coastmark_time_export_receipts'
     AND live_column.ordinal_position=canonical_column.ordinal_position
   WHERE canonical_column.table_schema=DATABASE()
     AND canonical_column.table_name='safeharbor_m021_reference_receipts'
);
SET @cm_claim_indexes_ok = (
  SELECT (SELECT COUNT(*) FROM information_schema.statistics
           WHERE table_schema=DATABASE()
             AND table_name='coastmark_time_export_claims') = COUNT(*)
     AND COALESCE(SUM(
           live_index.index_name <=> canonical_index.index_name
       AND live_index.non_unique <=> canonical_index.non_unique
       AND live_index.seq_in_index <=> canonical_index.seq_in_index
       AND live_index.column_name <=> canonical_index.column_name
       AND live_index.collation <=> canonical_index.collation
       AND live_index.sub_part <=> canonical_index.sub_part
       AND live_index.packed <=> canonical_index.packed
       AND live_index.nullable <=> canonical_index.nullable
       AND live_index.index_type <=> canonical_index.index_type
       AND live_index.comment <=> canonical_index.comment
       AND live_index.index_comment <=> canonical_index.index_comment
       AND live_index.is_visible <=> canonical_index.is_visible
       AND CAST(live_index.expression AS BINARY)
             <=> CAST(canonical_index.expression AS BINARY)
     ),0) = COUNT(*)
    FROM information_schema.statistics canonical_index
    LEFT JOIN information_schema.statistics live_index
      ON live_index.table_schema=canonical_index.table_schema
     AND live_index.table_name='coastmark_time_export_claims'
     AND live_index.index_name=canonical_index.index_name
     AND live_index.seq_in_index=canonical_index.seq_in_index
   WHERE canonical_index.table_schema=DATABASE()
     AND canonical_index.table_name='safeharbor_m021_reference_claims'
);
SET @cm_receipt_indexes_ok = (
  SELECT (SELECT COUNT(*) FROM information_schema.statistics
           WHERE table_schema=DATABASE()
             AND table_name='coastmark_time_export_receipts') = COUNT(*)
     AND COALESCE(SUM(
           live_index.index_name <=> canonical_index.index_name
       AND live_index.non_unique <=> canonical_index.non_unique
       AND live_index.seq_in_index <=> canonical_index.seq_in_index
       AND live_index.column_name <=> canonical_index.column_name
       AND live_index.collation <=> canonical_index.collation
       AND live_index.sub_part <=> canonical_index.sub_part
       AND live_index.packed <=> canonical_index.packed
       AND live_index.nullable <=> canonical_index.nullable
       AND live_index.index_type <=> canonical_index.index_type
       AND live_index.comment <=> canonical_index.comment
       AND live_index.index_comment <=> canonical_index.index_comment
       AND live_index.is_visible <=> canonical_index.is_visible
       AND CAST(live_index.expression AS BINARY)
             <=> CAST(canonical_index.expression AS BINARY)
     ),0) = COUNT(*)
    FROM information_schema.statistics canonical_index
    LEFT JOIN information_schema.statistics live_index
      ON live_index.table_schema=canonical_index.table_schema
     AND live_index.table_name='coastmark_time_export_receipts'
     AND live_index.index_name=canonical_index.index_name
     AND live_index.seq_in_index=canonical_index.seq_in_index
   WHERE canonical_index.table_schema=DATABASE()
     AND canonical_index.table_name='safeharbor_m021_reference_receipts'
);
SET @cm_claim_fks_ok = (
  SELECT (SELECT COUNT(*) FROM information_schema.key_column_usage
           WHERE table_schema=DATABASE()
             AND table_name='coastmark_time_export_claims'
             AND referenced_table_name IS NOT NULL) = COUNT(*)
     AND COALESCE(SUM(
           live_key.constraint_name
             <=> CONCAT('fk_cm_export_',SUBSTRING(canonical_key.constraint_name,6))
       AND live_key.constraint_schema <=> canonical_key.constraint_schema
       AND live_key.table_schema <=> canonical_key.table_schema
       AND live_key.ordinal_position <=> canonical_key.ordinal_position
       AND live_key.position_in_unique_constraint
             <=> canonical_key.position_in_unique_constraint
       AND live_key.column_name <=> canonical_key.column_name
       AND live_key.referenced_table_schema <=> canonical_key.referenced_table_schema
       AND live_key.referenced_table_name <=>
             IF(canonical_key.referenced_table_name='safeharbor_m021_reference_claims',
                'coastmark_time_export_claims',canonical_key.referenced_table_name)
       AND live_key.referenced_column_name <=> canonical_key.referenced_column_name
       AND live_rule.unique_constraint_schema <=> canonical_rule.unique_constraint_schema
       AND live_rule.unique_constraint_name <=> canonical_rule.unique_constraint_name
       AND live_rule.match_option <=> canonical_rule.match_option
       AND live_rule.update_rule <=> canonical_rule.update_rule
       AND live_rule.delete_rule <=> canonical_rule.delete_rule
     ),0) = COUNT(*)
    FROM information_schema.key_column_usage canonical_key
    JOIN information_schema.referential_constraints canonical_rule
      ON canonical_rule.constraint_schema=canonical_key.constraint_schema
     AND canonical_rule.table_name=canonical_key.table_name
     AND canonical_rule.constraint_name=canonical_key.constraint_name
    LEFT JOIN information_schema.key_column_usage live_key
      ON live_key.table_schema=canonical_key.table_schema
     AND live_key.table_name='coastmark_time_export_claims'
     AND live_key.constraint_name=
           CONCAT('fk_cm_export_',SUBSTRING(canonical_key.constraint_name,6))
     AND live_key.ordinal_position=canonical_key.ordinal_position
    LEFT JOIN information_schema.referential_constraints live_rule
      ON live_rule.constraint_schema=live_key.constraint_schema
     AND live_rule.table_name=live_key.table_name
     AND live_rule.constraint_name=live_key.constraint_name
   WHERE canonical_key.table_schema=DATABASE()
     AND canonical_key.table_name='safeharbor_m021_reference_claims'
     AND canonical_key.referenced_table_name IS NOT NULL
);
SET @cm_receipt_fks_ok = (
  SELECT (SELECT COUNT(*) FROM information_schema.key_column_usage
           WHERE table_schema=DATABASE()
             AND table_name='coastmark_time_export_receipts'
             AND referenced_table_name IS NOT NULL) = COUNT(*)
     AND COALESCE(SUM(
           live_key.constraint_name
             <=> CONCAT('fk_cm_export_',SUBSTRING(canonical_key.constraint_name,6))
       AND live_key.constraint_schema <=> canonical_key.constraint_schema
       AND live_key.table_schema <=> canonical_key.table_schema
       AND live_key.ordinal_position <=> canonical_key.ordinal_position
       AND live_key.position_in_unique_constraint
             <=> canonical_key.position_in_unique_constraint
       AND live_key.column_name <=> canonical_key.column_name
       AND live_key.referenced_table_schema <=> canonical_key.referenced_table_schema
       AND live_key.referenced_table_name <=>
             IF(canonical_key.referenced_table_name='safeharbor_m021_reference_claims',
                'coastmark_time_export_claims',canonical_key.referenced_table_name)
       AND live_key.referenced_column_name <=> canonical_key.referenced_column_name
       AND live_rule.unique_constraint_schema <=> canonical_rule.unique_constraint_schema
       AND live_rule.unique_constraint_name <=> canonical_rule.unique_constraint_name
       AND live_rule.match_option <=> canonical_rule.match_option
       AND live_rule.update_rule <=> canonical_rule.update_rule
       AND live_rule.delete_rule <=> canonical_rule.delete_rule
     ),0) = COUNT(*)
    FROM information_schema.key_column_usage canonical_key
    JOIN information_schema.referential_constraints canonical_rule
      ON canonical_rule.constraint_schema=canonical_key.constraint_schema
     AND canonical_rule.table_name=canonical_key.table_name
     AND canonical_rule.constraint_name=canonical_key.constraint_name
    LEFT JOIN information_schema.key_column_usage live_key
      ON live_key.table_schema=canonical_key.table_schema
     AND live_key.table_name='coastmark_time_export_receipts'
     AND live_key.constraint_name=
           CONCAT('fk_cm_export_',SUBSTRING(canonical_key.constraint_name,6))
     AND live_key.ordinal_position=canonical_key.ordinal_position
    LEFT JOIN information_schema.referential_constraints live_rule
      ON live_rule.constraint_schema=live_key.constraint_schema
     AND live_rule.table_name=live_key.table_name
     AND live_rule.constraint_name=live_key.constraint_name
   WHERE canonical_key.table_schema=DATABASE()
     AND canonical_key.table_name='safeharbor_m021_reference_receipts'
     AND canonical_key.referenced_table_name IS NOT NULL
);
SET @cm_claim_install_lock_present = (
  SELECT COALESCE(SUM(
           live_constraint.constraint_name='ck_cm_export_claim_install_lock'
       AND live_constraint.enforced=canonical_constraint.enforced
       AND CAST(live_check.check_clause AS BINARY)
             <=> CAST(canonical_check.check_clause AS BINARY)
     ),0)=1
    FROM information_schema.table_constraints canonical_constraint
    JOIN information_schema.check_constraints canonical_check
      ON canonical_check.constraint_schema=canonical_constraint.constraint_schema
     AND canonical_check.constraint_name=canonical_constraint.constraint_name
    LEFT JOIN information_schema.table_constraints live_constraint
      ON live_constraint.constraint_schema=canonical_constraint.constraint_schema
     AND live_constraint.table_name='coastmark_time_export_claims'
     AND live_constraint.constraint_name='ck_cm_export_claim_install_lock'
     AND live_constraint.constraint_type='CHECK'
    LEFT JOIN information_schema.check_constraints live_check
      ON live_check.constraint_schema=live_constraint.constraint_schema
     AND live_check.constraint_name=live_constraint.constraint_name
   WHERE canonical_constraint.constraint_schema=DATABASE()
     AND canonical_constraint.table_name='safeharbor_m021_reference_claims'
     AND canonical_constraint.constraint_name='rc21_claim_install_lock'
     AND canonical_constraint.constraint_type='CHECK'
);
SET @cm_receipt_install_lock_present = (
  SELECT COALESCE(SUM(
           live_constraint.constraint_name='ck_cm_export_receipt_install_lock'
       AND live_constraint.enforced=canonical_constraint.enforced
       AND CAST(live_check.check_clause AS BINARY)
             <=> CAST(canonical_check.check_clause AS BINARY)
     ),0)=1
    FROM information_schema.table_constraints canonical_constraint
    JOIN information_schema.check_constraints canonical_check
      ON canonical_check.constraint_schema=canonical_constraint.constraint_schema
     AND canonical_check.constraint_name=canonical_constraint.constraint_name
    LEFT JOIN information_schema.table_constraints live_constraint
      ON live_constraint.constraint_schema=canonical_constraint.constraint_schema
     AND live_constraint.table_name='coastmark_time_export_receipts'
     AND live_constraint.constraint_name='ck_cm_export_receipt_install_lock'
     AND live_constraint.constraint_type='CHECK'
    LEFT JOIN information_schema.check_constraints live_check
      ON live_check.constraint_schema=live_constraint.constraint_schema
     AND live_check.constraint_name=live_constraint.constraint_name
   WHERE canonical_constraint.constraint_schema=DATABASE()
     AND canonical_constraint.table_name='safeharbor_m021_reference_receipts'
     AND canonical_constraint.constraint_name='rc21_receipt_install_lock'
      AND canonical_constraint.constraint_type='CHECK'
);
-- Removing an install lock causes MySQL to reserialize the surviving check
-- clauses. Put the trusted reference through that same lifecycle only when
-- the live table has no exact install lock, then keep the clause comparison
-- binary-exact for the live lifecycle state.
SET @cm_claim_reference_lock_ddl=IF(
  @cm_claim_install_lock_present=0
  AND (SELECT COUNT(*) FROM information_schema.table_constraints
        WHERE constraint_schema=DATABASE()
          AND table_name='safeharbor_m021_reference_claims'
          AND constraint_type='CHECK'
          AND constraint_name='rc21_claim_install_lock')=1,
  'ALTER TABLE safeharbor_m021_reference_claims DROP CHECK rc21_claim_install_lock',
  'DO 0'
);
PREPARE cm_export_statement FROM @cm_claim_reference_lock_ddl;
EXECUTE cm_export_statement;
DEALLOCATE PREPARE cm_export_statement;
SET @cm_receipt_reference_lock_ddl=IF(
  @cm_receipt_install_lock_present=0
  AND (SELECT COUNT(*) FROM information_schema.table_constraints
        WHERE constraint_schema=DATABASE()
          AND table_name='safeharbor_m021_reference_receipts'
          AND constraint_type='CHECK'
          AND constraint_name='rc21_receipt_install_lock')=1,
  'ALTER TABLE safeharbor_m021_reference_receipts DROP CHECK rc21_receipt_install_lock',
  'DO 0'
);
PREPARE cm_export_statement FROM @cm_receipt_reference_lock_ddl;
EXECUTE cm_export_statement;
DEALLOCATE PREPARE cm_export_statement;
SET @cm_claim_checks_ok = (
  SELECT (SELECT COUNT(*) FROM information_schema.table_constraints
           WHERE constraint_schema=DATABASE()
             AND table_name='coastmark_time_export_claims'
             AND constraint_type='CHECK') = COUNT(*) + @cm_claim_install_lock_present
     AND COALESCE(SUM(
           live_constraint.constraint_name
             <=> CONCAT('ck_cm_export_',SUBSTRING(canonical_constraint.constraint_name,6))
       AND live_constraint.enforced <=> canonical_constraint.enforced
       AND CAST(live_check.check_clause AS BINARY)
             <=> CAST(canonical_check.check_clause AS BINARY)
     ),0) = COUNT(*)
    FROM information_schema.table_constraints canonical_constraint
    JOIN information_schema.check_constraints canonical_check
      ON canonical_check.constraint_schema=canonical_constraint.constraint_schema
     AND canonical_check.constraint_name=canonical_constraint.constraint_name
    LEFT JOIN information_schema.table_constraints live_constraint
      ON live_constraint.constraint_schema=canonical_constraint.constraint_schema
     AND live_constraint.table_name='coastmark_time_export_claims'
     AND live_constraint.constraint_name=
           CONCAT('ck_cm_export_',SUBSTRING(canonical_constraint.constraint_name,6))
     AND live_constraint.constraint_type='CHECK'
    LEFT JOIN information_schema.check_constraints live_check
      ON live_check.constraint_schema=live_constraint.constraint_schema
     AND live_check.constraint_name=live_constraint.constraint_name
   WHERE canonical_constraint.constraint_schema=DATABASE()
     AND canonical_constraint.table_name='safeharbor_m021_reference_claims'
     AND canonical_constraint.constraint_type='CHECK'
     AND canonical_constraint.constraint_name<>'rc21_claim_install_lock'
);
SET @cm_receipt_checks_ok = (
  SELECT (SELECT COUNT(*) FROM information_schema.table_constraints
           WHERE constraint_schema=DATABASE()
             AND table_name='coastmark_time_export_receipts'
             AND constraint_type='CHECK') = COUNT(*) + @cm_receipt_install_lock_present
     AND COALESCE(SUM(
           live_constraint.constraint_name
             <=> CONCAT('ck_cm_export_',SUBSTRING(canonical_constraint.constraint_name,6))
       AND live_constraint.enforced <=> canonical_constraint.enforced
       AND CAST(live_check.check_clause AS BINARY)
             <=> CAST(canonical_check.check_clause AS BINARY)
     ),0) = COUNT(*)
    FROM information_schema.table_constraints canonical_constraint
    JOIN information_schema.check_constraints canonical_check
      ON canonical_check.constraint_schema=canonical_constraint.constraint_schema
     AND canonical_check.constraint_name=canonical_constraint.constraint_name
    LEFT JOIN information_schema.table_constraints live_constraint
      ON live_constraint.constraint_schema=canonical_constraint.constraint_schema
     AND live_constraint.table_name='coastmark_time_export_receipts'
     AND live_constraint.constraint_name=
           CONCAT('ck_cm_export_',SUBSTRING(canonical_constraint.constraint_name,6))
     AND live_constraint.constraint_type='CHECK'
    LEFT JOIN information_schema.check_constraints live_check
      ON live_check.constraint_schema=live_constraint.constraint_schema
     AND live_check.constraint_name=live_constraint.constraint_name
   WHERE canonical_constraint.constraint_schema=DATABASE()
     AND canonical_constraint.table_name='safeharbor_m021_reference_receipts'
     AND canonical_constraint.constraint_type='CHECK'
     AND canonical_constraint.constraint_name<>'rc21_receipt_install_lock'
);

DROP TEMPORARY TABLE IF EXISTS safeharbor_m021_trigger_manifest;
CREATE TEMPORARY TABLE safeharbor_m021_trigger_manifest (
  trigger_name          VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  reference_trigger_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  guard_kind            VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_object_table    VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  reference_object_table VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_manipulation    VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  action_sha256         CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  PRIMARY KEY (trigger_name),
  UNIQUE KEY uq_m021_reference_trigger (reference_trigger_name)
) ENGINE=MEMORY;
INSERT INTO safeharbor_m021_trigger_manifest
  (trigger_name,reference_trigger_name,guard_kind,event_object_table,
   reference_object_table,event_manipulation)
VALUES
  ('trg_cm_claim_021_insert_swap','trg_cm_ref_021_claim_insert_swap','swap',
   'coastmark_time_export_claims','safeharbor_m021_reference_claims','INSERT'),
  ('trg_cm_claim_021_update_swap','trg_cm_ref_021_claim_update_swap','swap',
   'coastmark_time_export_claims','safeharbor_m021_reference_claims','UPDATE'),
  ('trg_cm_claim_021_delete_swap','trg_cm_ref_021_claim_delete_swap','swap',
   'coastmark_time_export_claims','safeharbor_m021_reference_claims','DELETE'),
  ('trg_cm_receipt_021_insert_swap','trg_cm_ref_021_receipt_insert_swap','swap',
   'coastmark_time_export_receipts','safeharbor_m021_reference_receipts','INSERT'),
  ('trg_cm_receipt_021_update_swap','trg_cm_ref_021_receipt_update_swap','swap',
   'coastmark_time_export_receipts','safeharbor_m021_reference_receipts','UPDATE'),
  ('trg_cm_receipt_021_delete_swap','trg_cm_ref_021_receipt_delete_swap','swap',
   'coastmark_time_export_receipts','safeharbor_m021_reference_receipts','DELETE'),
  ('trg_cm_export_claim_before_insert','trg_cm_ref_021_claim_before_insert','permanent',
   'coastmark_time_export_claims','safeharbor_m021_reference_claims','INSERT'),
  ('trg_cm_export_claim_no_update','trg_cm_ref_021_claim_no_update','permanent',
   'coastmark_time_export_claims','safeharbor_m021_reference_claims','UPDATE'),
  ('trg_cm_export_claim_no_delete','trg_cm_ref_021_claim_no_delete','permanent',
   'coastmark_time_export_claims','safeharbor_m021_reference_claims','DELETE'),
  ('trg_cm_export_receipt_before_insert','trg_cm_ref_021_receipt_before_insert','permanent',
   'coastmark_time_export_receipts','safeharbor_m021_reference_receipts','INSERT'),
  ('trg_cm_export_receipt_no_update','trg_cm_ref_021_receipt_no_update','permanent',
   'coastmark_time_export_receipts','safeharbor_m021_reference_receipts','UPDATE'),
  ('trg_cm_export_receipt_no_delete','trg_cm_ref_021_receipt_no_delete','permanent',
   'coastmark_time_export_receipts','safeharbor_m021_reference_receipts','DELETE');

UPDATE safeharbor_m021_trigger_manifest expected
JOIN information_schema.triggers reference
  ON reference.trigger_schema=DATABASE()
 AND CAST(reference.trigger_name AS BINARY)=CAST(expected.reference_trigger_name AS BINARY)
SET expected.action_sha256=SHA2(CAST(reference.action_statement AS BINARY),256)
WHERE reference.action_timing='BEFORE'
  AND reference.action_orientation='ROW'
  AND reference.action_condition IS NULL
  AND CAST(reference.event_object_table AS BINARY)=CAST(expected.reference_object_table AS BINARY)
  AND CAST(reference.event_manipulation AS BINARY)=CAST(expected.event_manipulation AS BINARY);

SET @cm_reference_triggers_ok = (
  SELECT COUNT(*)=12 AND COUNT(action_sha256)=12
    FROM safeharbor_m021_trigger_manifest
);

DROP TEMPORARY TABLE IF EXISTS safeharbor_m021_validated_triggers;
CREATE TEMPORARY TABLE safeharbor_m021_validated_triggers (
  trigger_name       VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  guard_kind         VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_object_table VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_manipulation VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (trigger_name)
) ENGINE=MEMORY;
INSERT INTO safeharbor_m021_validated_triggers
  (trigger_name,guard_kind,event_object_table,event_manipulation)
SELECT expected.trigger_name,expected.guard_kind,expected.event_object_table,
       expected.event_manipulation
  FROM safeharbor_m021_trigger_manifest expected
  JOIN information_schema.triggers live
    ON live.trigger_schema=DATABASE()
   AND CAST(live.trigger_name AS BINARY)=CAST(expected.trigger_name AS BINARY)
 WHERE live.action_timing='BEFORE'
   AND live.action_orientation='ROW'
   AND live.action_condition IS NULL
   AND CAST(live.event_object_table AS BINARY)=CAST(expected.event_object_table AS BINARY)
   AND CAST(live.event_manipulation AS BINARY)=CAST(expected.event_manipulation AS BINARY)
   AND SHA2(CAST(live.action_statement AS BINARY),256)=expected.action_sha256;

SET @cm_validated_trigger_count =
  (SELECT COUNT(*) FROM safeharbor_m021_validated_triggers);

SET @cm_initial_trigger_set_ok = (
  SELECT COUNT(*)=@cm_validated_trigger_count
    FROM information_schema.triggers live
   WHERE live.trigger_schema=DATABASE()
     AND (live.event_object_table IN
             ('coastmark_time_export_claims','coastmark_time_export_receipts')
          OR EXISTS (
               SELECT 1 FROM safeharbor_m021_trigger_manifest reserved
                WHERE LOWER(reserved.trigger_name)=LOWER(live.trigger_name)
          ))
);
SET @cm_initial_guard_coverage_ok = (
  SELECT
       (@cm_claim_install_lock_present=1 OR
        COALESCE(SUM(trigger_name IN ('trg_cm_export_claim_before_insert',
                                     'trg_cm_claim_021_insert_swap')),0)>0)
   AND (@cm_claim_install_lock_present=1 OR
        COALESCE(SUM(trigger_name IN ('trg_cm_export_claim_no_update',
                                     'trg_cm_claim_021_update_swap')),0)>0)
   AND (@cm_claim_install_lock_present=1 OR
        COALESCE(SUM(trigger_name IN ('trg_cm_export_claim_no_delete',
                                     'trg_cm_claim_021_delete_swap')),0)>0)
   AND (@cm_receipt_install_lock_present=1 OR
        COALESCE(SUM(trigger_name IN ('trg_cm_export_receipt_before_insert',
                                     'trg_cm_receipt_021_insert_swap')),0)>0)
   AND (@cm_receipt_install_lock_present=1 OR
        COALESCE(SUM(trigger_name IN ('trg_cm_export_receipt_no_update',
                                     'trg_cm_receipt_021_update_swap')),0)>0)
   AND (@cm_receipt_install_lock_present=1 OR
        COALESCE(SUM(trigger_name IN ('trg_cm_export_receipt_no_delete',
                                     'trg_cm_receipt_021_delete_swap')),0)>0)
    FROM safeharbor_m021_validated_triggers
);
SET @cm_export_preflight_failure = CASE
  WHEN NOT (@cm_claim_table_ok <=> 1) THEN 'migration_021_claim_table_failed'
  WHEN NOT (@cm_receipt_table_ok <=> 1) THEN 'migration_021_receipt_table_failed'
  WHEN NOT (@cm_claim_columns_ok <=> 1) THEN 'migration_021_claim_columns_failed'
  WHEN NOT (@cm_receipt_columns_ok <=> 1) THEN 'migration_021_receipt_columns_failed'
  WHEN NOT (@cm_claim_indexes_ok <=> 1) THEN 'migration_021_claim_indexes_failed'
  WHEN NOT (@cm_receipt_indexes_ok <=> 1) THEN 'migration_021_receipt_indexes_failed'
  WHEN NOT (@cm_claim_fks_ok <=> 1) THEN 'migration_021_claim_fks_failed'
  WHEN NOT (@cm_receipt_fks_ok <=> 1) THEN 'migration_021_receipt_fks_failed'
  WHEN NOT (@cm_claim_checks_ok <=> 1) THEN 'migration_021_claim_checks_failed'
  WHEN NOT (@cm_receipt_checks_ok <=> 1) THEN 'migration_021_receipt_checks_failed'
  WHEN NOT (@cm_reference_triggers_ok <=> 1) THEN 'migration_021_reference_triggers_failed'
  WHEN NOT (@cm_initial_trigger_set_ok <=> 1) THEN 'migration_021_initial_triggers_failed'
  WHEN NOT (@cm_initial_guard_coverage_ok <=> 1) THEN 'migration_021_guard_coverage_failed'
  ELSE NULL
END;
SET @cm_export_preflight_sql = IF(
  @cm_export_preflight_failure IS NULL,
  'DO 0',
  CONCAT('SELECT * FROM information_schema.', @cm_export_preflight_failure)
);
PREPARE cm_export_statement FROM @cm_export_preflight_sql;
EXECUTE cm_export_statement;
DEALLOCATE PREPARE cm_export_statement;

-- Prove the receipt reference is individually disposable while the migration
-- lock is still owned. This repeats the ownership/zero/dependency evidence at
-- the destructive boundary instead of trusting an earlier observation.
SET @cm_reference_cleanup_locked =
  (IS_USED_LOCK(@cm_m021_lock_name) <=> CONNECTION_ID());
SET @cm_reference_cleanup_owned = (
  SELECT COUNT(*)=2
     AND COALESCE(SUM(
       table_name='safeharbor_m021_reference_claims'
       AND CAST(table_comment AS BINARY)=
           CAST('safeharbor:migration:021:reference:claims:v1' AS BINARY)
     ),0)=1
     AND COALESCE(SUM(
       table_name='safeharbor_m021_reference_receipts'
       AND CAST(table_comment AS BINARY)=
           CAST('safeharbor:migration:021:reference:receipts:v1' AS BINARY)
     ),0)=1
    FROM information_schema.tables
   WHERE table_schema=DATABASE()
     AND table_type='BASE TABLE'
     AND table_name IN
         ('safeharbor_m021_reference_claims','safeharbor_m021_reference_receipts')
);
SET @cm_reference_cleanup_empty = (
  (SELECT COUNT(*) FROM safeharbor_m021_reference_claims)=0
  AND (SELECT COUNT(*) FROM safeharbor_m021_reference_receipts)=0
);
SET @cm_reference_cleanup_shape = (
  @cm_claim_table_ok=1
  AND @cm_receipt_table_ok=1
  AND @cm_claim_columns_ok=1
  AND @cm_receipt_columns_ok=1
  AND @cm_claim_indexes_ok=1
  AND @cm_receipt_indexes_ok=1
  AND @cm_claim_fks_ok=1
  AND @cm_receipt_fks_ok=1
  AND @cm_claim_checks_ok=1
  AND @cm_receipt_checks_ok=1
);
SET @cm_reference_cleanup_triggers = (
  (SELECT COUNT(*)=12
       AND COALESCE(SUM(
         reference.action_timing='BEFORE'
         AND reference.action_orientation='ROW'
         AND reference.action_condition IS NULL
         AND CAST(reference.event_object_table AS BINARY)=
             CAST(expected.reference_object_table AS BINARY)
         AND CAST(reference.event_manipulation AS BINARY)=
             CAST(expected.event_manipulation AS BINARY)
         AND SHA2(CAST(reference.action_statement AS BINARY),256)=expected.action_sha256
       ),0)=12
     FROM safeharbor_m021_trigger_manifest expected
     LEFT JOIN information_schema.triggers reference
       ON reference.trigger_schema=DATABASE()
      AND CAST(reference.trigger_name AS BINARY)=
          CAST(expected.reference_trigger_name AS BINARY))
  AND (SELECT COUNT(*)
         FROM information_schema.triggers live
        WHERE live.trigger_schema=DATABASE()
          AND (live.event_object_table IN
                  ('safeharbor_m021_reference_claims','safeharbor_m021_reference_receipts')
               OR EXISTS (
                    SELECT 1
                      FROM safeharbor_m021_reference_trigger_allowlist reserved
                     WHERE LOWER(reserved.trigger_name)=LOWER(live.trigger_name)
               )))=12
);
SET @cm_reference_cleanup_dependencies = (
  (SELECT COUNT(*)
     FROM information_schema.key_column_usage dependent
    WHERE dependent.referenced_table_schema=DATABASE()
      AND dependent.referenced_table_name IN
          ('safeharbor_m021_reference_claims','safeharbor_m021_reference_receipts')
      AND NOT (
        dependent.table_schema=DATABASE()
        AND (
          (dependent.table_name='safeharbor_m021_reference_claims'
           AND dependent.constraint_name='rf21_claim_predecessor'
           AND ((dependent.ordinal_position=1
                 AND dependent.column_name='tenant_id'
                 AND dependent.referenced_column_name='tenant_id')
             OR (dependent.ordinal_position=2
                 AND dependent.column_name='predecessor_claim_id'
                 AND dependent.referenced_column_name='id')))
          OR
          (dependent.table_name='safeharbor_m021_reference_receipts'
           AND dependent.constraint_name='rf21_receipt_claim'
           AND ((dependent.ordinal_position=1
                 AND dependent.column_name='tenant_id'
                 AND dependent.referenced_column_name='tenant_id')
             OR (dependent.ordinal_position=2
                 AND dependent.column_name='claim_id'
                 AND dependent.referenced_column_name='id')))
        )
      ))=0
  AND (SELECT COUNT(*)
         FROM information_schema.view_table_usage
        WHERE table_schema=DATABASE()
          AND table_name IN
              ('safeharbor_m021_reference_claims','safeharbor_m021_reference_receipts'))=0
  AND (SELECT COUNT(*)
         FROM information_schema.routines
        WHERE routine_schema=DATABASE()
          AND (routine_definition IS NULL
               OR LOCATE('safeharbor_m021_reference_claims',
                         LOWER(routine_definition))>0
               OR LOCATE('safeharbor_m021_reference_receipts',
                          LOWER(routine_definition))>0))=0
);
-- Re-read every source-anchored shape at the destructive boundary. The named
-- advisory lock serializes migration runners, but it does not stop an
-- unrelated DDL-capable connection from changing these evidence objects.
SET @cm_reference_cleanup_source_tables_ok = (
  SELECT COUNT(*)=2
     AND COALESCE(SUM(
       live.engine='InnoDB'
       AND charset_map.character_set_name='utf8mb4'
       AND CAST(live.table_comment AS BINARY)=CAST(
         IF(live.table_name='safeharbor_m021_reference_claims',
            'safeharbor:migration:021:reference:claims:v1',
            'safeharbor:migration:021:reference:receipts:v1') AS BINARY)
     ),0)=2
    FROM information_schema.tables live
    JOIN information_schema.collation_character_set_applicability charset_map
      ON charset_map.collation_name=live.table_collation
   WHERE live.table_schema=DATABASE()
     AND live.table_type='BASE TABLE'
     AND live.table_name IN
         ('safeharbor_m021_reference_claims','safeharbor_m021_reference_receipts')
);
SET @cm_reference_cleanup_source_columns_ok = (
  SELECT (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema=DATABASE()
             AND table_name IN
                 ('safeharbor_m021_reference_claims','safeharbor_m021_reference_receipts'))
           = COUNT(*)
     AND COALESCE(SUM(
           live.column_name <=> expected.column_name
       AND CAST(live.column_type AS BINARY) <=> CAST(expected.column_type AS BINARY)
       AND live.is_nullable <=> expected.is_nullable
       AND CAST(live.column_default AS BINARY) <=> CAST(expected.column_default AS BINARY)
       AND CAST(live.extra AS BINARY) <=> CAST(expected.extra AS BINARY)
       AND CAST(live.generation_expression AS BINARY)
             <=> CAST(expected.generation_expression AS BINARY)
       AND live.character_set_name <=> expected.character_set_name
       AND live.collation_name <=> IF(expected.collation_name='@table',
             reference_table.table_collation,expected.collation_name)
     ),0)=COUNT(*)
    FROM safeharbor_m021_source_columns expected
    JOIN information_schema.tables reference_table
      ON reference_table.table_schema=DATABASE()
     AND reference_table.table_name=expected.table_name
    LEFT JOIN information_schema.columns live
      ON live.table_schema=DATABASE()
     AND live.table_name=expected.table_name
     AND live.ordinal_position=expected.ordinal_position
);
SET @cm_reference_cleanup_source_indexes_ok = (
  SELECT (SELECT COUNT(*) FROM information_schema.statistics
           WHERE table_schema=DATABASE()
             AND table_name IN
                 ('safeharbor_m021_reference_claims','safeharbor_m021_reference_receipts'))
           = COUNT(*)
     AND COALESCE(SUM(
           live.index_name <=> expected.index_name
       AND live.non_unique <=> expected.non_unique
       AND live.seq_in_index <=> expected.seq_in_index
       AND live.column_name <=> expected.column_name
       AND live.collation='A'
       AND live.sub_part IS NULL
       AND live.packed IS NULL
       AND live.nullable <=> IF(source_column.is_nullable='YES','YES','')
       AND live.index_type='BTREE'
       AND live.comment=''
       AND live.index_comment=''
       AND live.is_visible='YES'
       AND live.expression IS NULL
     ),0)=COUNT(*)
    FROM safeharbor_m021_source_indexes expected
    JOIN safeharbor_m021_source_columns source_column
      ON source_column.table_name=expected.table_name
     AND source_column.column_name=expected.column_name
    LEFT JOIN information_schema.statistics live
      ON live.table_schema=DATABASE()
     AND live.table_name=expected.table_name
     AND live.index_name=expected.index_name
     AND live.seq_in_index=expected.seq_in_index
);
SET @cm_reference_cleanup_source_fks_ok = (
  SELECT (SELECT COUNT(*) FROM information_schema.key_column_usage
           WHERE table_schema=DATABASE()
             AND table_name IN
                 ('safeharbor_m021_reference_claims','safeharbor_m021_reference_receipts')
             AND referenced_table_name IS NOT NULL)=COUNT(*)
     AND COALESCE(SUM(
           live.constraint_name <=> expected.constraint_name
       AND live.ordinal_position <=> expected.ordinal_position
       AND live.position_in_unique_constraint <=> expected.unique_position
       AND live.column_name <=> expected.column_name
       AND live.referenced_table_schema=DATABASE()
       AND live.referenced_table_name <=> expected.referenced_table_name
       AND live.referenced_column_name <=> expected.referenced_column_name
       AND rule.unique_constraint_schema=DATABASE()
       AND rule.unique_constraint_name <=> expected.unique_constraint_name
       AND rule.match_option='NONE'
       AND rule.update_rule='NO ACTION'
       AND rule.delete_rule='NO ACTION'
     ),0)=COUNT(*)
    FROM safeharbor_m021_source_fks expected
    LEFT JOIN information_schema.key_column_usage live
      ON live.table_schema=DATABASE()
     AND live.table_name=expected.table_name
     AND live.constraint_name=expected.constraint_name
     AND live.ordinal_position=expected.ordinal_position
    LEFT JOIN information_schema.referential_constraints rule
      ON rule.constraint_schema=live.constraint_schema
     AND rule.table_name=live.table_name
     AND rule.constraint_name=live.constraint_name
);
SET @cm_reference_cleanup_source_checks_ok = (
  (SELECT COUNT(*)=0
     FROM information_schema.table_constraints live_constraint
     JOIN information_schema.check_constraints live_check
       ON live_check.constraint_schema=live_constraint.constraint_schema
      AND live_check.constraint_name=live_constraint.constraint_name
     LEFT JOIN safeharbor_m021_source_checks expected
       ON expected.table_name=live_constraint.table_name
      AND expected.constraint_name=live_constraint.constraint_name
    WHERE live_constraint.constraint_schema=DATABASE()
      AND live_constraint.table_name IN
          ('safeharbor_m021_reference_claims','safeharbor_m021_reference_receipts')
      AND live_constraint.constraint_type='CHECK'
      AND (expected.constraint_name IS NULL
           OR live_constraint.enforced<>'YES'
           OR CAST(REGEXP_REPLACE(
                REPLACE(REPLACE(REPLACE(live_check.check_clause,'`',''),
                                '_utf8mb4',''),'_ascii',''),
                '[[:space:]()]','') AS BINARY)
              <> CAST(expected.normalized_clause AS BINARY)))
  AND
  (SELECT COUNT(*)=9
     FROM safeharbor_m021_source_checks expected
     JOIN information_schema.table_constraints live_constraint
       ON live_constraint.constraint_schema=DATABASE()
      AND live_constraint.table_name=expected.table_name
      AND live_constraint.constraint_name=expected.constraint_name
      AND live_constraint.constraint_type='CHECK'
      AND live_constraint.enforced='YES'
    WHERE expected.install_lock=0)
);
SET @cm_reference_cleanup_source_shape_ok = (
  @cm_reference_cleanup_source_tables_ok=1
  AND @cm_reference_cleanup_source_columns_ok=1
  AND @cm_reference_cleanup_source_indexes_ok=1
  AND @cm_reference_cleanup_source_fks_ok=1
  AND @cm_reference_cleanup_source_checks_ok=1
);
SET @cm_reference_receipt_disposable = (
  @cm_reference_cleanup_locked=1
  AND @cm_reference_cleanup_owned=1
  AND @cm_reference_cleanup_empty=1
  AND @cm_reference_cleanup_shape=1
  AND @cm_reference_cleanup_source_shape_ok=1
  AND @cm_reference_cleanup_triggers=1
  AND @cm_reference_cleanup_dependencies=1
);
SET @cm_reference_receipt_cleanup_sql = IF(
  @cm_reference_receipt_disposable=1,
  'DO 0',
  'SELECT * FROM information_schema.migration_021_reference_receipt_cleanup_refused'
);
PREPARE cm_export_statement FROM @cm_reference_receipt_cleanup_sql;
EXECUTE cm_export_statement;
DEALLOCATE PREPARE cm_export_statement;

DROP TABLE safeharbor_m021_reference_receipts;

-- Receipt removal eliminates the only allowed cross-table dependency. Re-prove
-- the claim reference on its own before deleting the final evidence object.
SET @cm_reference_claim_cleanup_owned = (
  SELECT COUNT(*)=1
     AND COALESCE(SUM(
       CAST(table_comment AS BINARY)=
         CAST('safeharbor:migration:021:reference:claims:v1' AS BINARY)
     ),0)=1
    FROM information_schema.tables
   WHERE table_schema=DATABASE()
     AND table_type='BASE TABLE'
     AND table_name='safeharbor_m021_reference_claims'
);
SET @cm_reference_claim_cleanup_empty =
  ((SELECT COUNT(*) FROM safeharbor_m021_reference_claims)=0);
SET @cm_reference_claim_cleanup_triggers = (
  (SELECT COUNT(*)=6
       AND COALESCE(SUM(
         reference.action_timing='BEFORE'
         AND reference.action_orientation='ROW'
         AND reference.action_condition IS NULL
         AND CAST(reference.event_object_table AS BINARY)=
             CAST(expected.reference_object_table AS BINARY)
         AND CAST(reference.event_manipulation AS BINARY)=
             CAST(expected.event_manipulation AS BINARY)
         AND SHA2(CAST(reference.action_statement AS BINARY),256)=expected.action_sha256
       ),0)=6
     FROM safeharbor_m021_trigger_manifest expected
     LEFT JOIN information_schema.triggers reference
       ON reference.trigger_schema=DATABASE()
      AND CAST(reference.trigger_name AS BINARY)=
          CAST(expected.reference_trigger_name AS BINARY)
    WHERE expected.reference_object_table='safeharbor_m021_reference_claims')
  AND (SELECT COUNT(*)
         FROM information_schema.triggers live
        WHERE live.trigger_schema=DATABASE()
          AND (live.event_object_table='safeharbor_m021_reference_claims'
               OR EXISTS (
                    SELECT 1
                      FROM safeharbor_m021_reference_trigger_allowlist reserved
                     WHERE reserved.event_object_table='safeharbor_m021_reference_claims'
                       AND LOWER(reserved.trigger_name)=LOWER(live.trigger_name)
               )))=6
);
SET @cm_reference_claim_cleanup_dependencies = (
  (SELECT COUNT(*)
     FROM information_schema.key_column_usage dependent
    WHERE dependent.referenced_table_schema=DATABASE()
      AND dependent.referenced_table_name='safeharbor_m021_reference_claims'
      AND NOT (
        dependent.table_schema=DATABASE()
        AND dependent.table_name='safeharbor_m021_reference_claims'
        AND dependent.constraint_name='rf21_claim_predecessor'
        AND ((dependent.ordinal_position=1
              AND dependent.column_name='tenant_id'
              AND dependent.referenced_column_name='tenant_id')
          OR (dependent.ordinal_position=2
              AND dependent.column_name='predecessor_claim_id'
              AND dependent.referenced_column_name='id'))
      ))=0
  AND (SELECT COUNT(*)
         FROM information_schema.view_table_usage
        WHERE table_schema=DATABASE()
          AND table_name='safeharbor_m021_reference_claims')=0
  AND (SELECT COUNT(*)
         FROM information_schema.routines
        WHERE routine_schema=DATABASE()
          AND (routine_definition IS NULL
               OR LOCATE('safeharbor_m021_reference_claims',
                          LOWER(routine_definition))>0))=0
);
SET @cm_reference_claim_source_table_ok = (
  SELECT COUNT(*)=1
     AND COALESCE(SUM(
       live.engine='InnoDB'
       AND charset_map.character_set_name='utf8mb4'
       AND CAST(live.table_comment AS BINARY)=
           CAST('safeharbor:migration:021:reference:claims:v1' AS BINARY)
     ),0)=1
    FROM information_schema.tables live
    JOIN information_schema.collation_character_set_applicability charset_map
      ON charset_map.collation_name=live.table_collation
   WHERE live.table_schema=DATABASE()
     AND live.table_type='BASE TABLE'
     AND live.table_name='safeharbor_m021_reference_claims'
);
SET @cm_reference_claim_source_columns_ok = (
  SELECT (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema=DATABASE()
             AND table_name='safeharbor_m021_reference_claims')=COUNT(*)
     AND COALESCE(SUM(
           live.column_name <=> expected.column_name
       AND CAST(live.column_type AS BINARY) <=> CAST(expected.column_type AS BINARY)
       AND live.is_nullable <=> expected.is_nullable
       AND CAST(live.column_default AS BINARY) <=> CAST(expected.column_default AS BINARY)
       AND CAST(live.extra AS BINARY) <=> CAST(expected.extra AS BINARY)
       AND CAST(live.generation_expression AS BINARY)
             <=> CAST(expected.generation_expression AS BINARY)
       AND live.character_set_name <=> expected.character_set_name
       AND live.collation_name <=> IF(expected.collation_name='@table',
             reference_table.table_collation,expected.collation_name)
     ),0)=COUNT(*)
    FROM safeharbor_m021_source_columns expected
    JOIN information_schema.tables reference_table
      ON reference_table.table_schema=DATABASE()
     AND reference_table.table_name=expected.table_name
    LEFT JOIN information_schema.columns live
      ON live.table_schema=DATABASE()
     AND live.table_name=expected.table_name
     AND live.ordinal_position=expected.ordinal_position
   WHERE expected.table_name='safeharbor_m021_reference_claims'
);
SET @cm_reference_claim_source_indexes_ok = (
  SELECT (SELECT COUNT(*) FROM information_schema.statistics
           WHERE table_schema=DATABASE()
             AND table_name='safeharbor_m021_reference_claims')=COUNT(*)
     AND COALESCE(SUM(
           live.index_name <=> expected.index_name
       AND live.non_unique <=> expected.non_unique
       AND live.seq_in_index <=> expected.seq_in_index
       AND live.column_name <=> expected.column_name
       AND live.collation='A'
       AND live.sub_part IS NULL
       AND live.packed IS NULL
       AND live.nullable <=> IF(source_column.is_nullable='YES','YES','')
       AND live.index_type='BTREE'
       AND live.comment=''
       AND live.index_comment=''
       AND live.is_visible='YES'
       AND live.expression IS NULL
     ),0)=COUNT(*)
    FROM safeharbor_m021_source_indexes expected
    JOIN safeharbor_m021_source_columns source_column
      ON source_column.table_name=expected.table_name
     AND source_column.column_name=expected.column_name
    LEFT JOIN information_schema.statistics live
      ON live.table_schema=DATABASE()
     AND live.table_name=expected.table_name
     AND live.index_name=expected.index_name
     AND live.seq_in_index=expected.seq_in_index
   WHERE expected.table_name='safeharbor_m021_reference_claims'
);
SET @cm_reference_claim_source_fks_ok = (
  SELECT (SELECT COUNT(*) FROM information_schema.key_column_usage
           WHERE table_schema=DATABASE()
             AND table_name='safeharbor_m021_reference_claims'
             AND referenced_table_name IS NOT NULL)=COUNT(*)
     AND COALESCE(SUM(
           live.constraint_name <=> expected.constraint_name
       AND live.ordinal_position <=> expected.ordinal_position
       AND live.position_in_unique_constraint <=> expected.unique_position
       AND live.column_name <=> expected.column_name
       AND live.referenced_table_schema=DATABASE()
       AND live.referenced_table_name <=> expected.referenced_table_name
       AND live.referenced_column_name <=> expected.referenced_column_name
       AND rule.unique_constraint_schema=DATABASE()
       AND rule.unique_constraint_name <=> expected.unique_constraint_name
       AND rule.match_option='NONE'
       AND rule.update_rule='NO ACTION'
       AND rule.delete_rule='NO ACTION'
     ),0)=COUNT(*)
    FROM safeharbor_m021_source_fks expected
    LEFT JOIN information_schema.key_column_usage live
      ON live.table_schema=DATABASE()
     AND live.table_name=expected.table_name
     AND live.constraint_name=expected.constraint_name
     AND live.ordinal_position=expected.ordinal_position
    LEFT JOIN information_schema.referential_constraints rule
      ON rule.constraint_schema=live.constraint_schema
     AND rule.table_name=live.table_name
     AND rule.constraint_name=live.constraint_name
   WHERE expected.table_name='safeharbor_m021_reference_claims'
);
SET @cm_reference_claim_source_checks_ok = (
  (SELECT COUNT(*)=0
     FROM information_schema.table_constraints live_constraint
     JOIN information_schema.check_constraints live_check
       ON live_check.constraint_schema=live_constraint.constraint_schema
      AND live_check.constraint_name=live_constraint.constraint_name
     LEFT JOIN safeharbor_m021_source_checks expected
       ON expected.table_name=live_constraint.table_name
      AND expected.constraint_name=live_constraint.constraint_name
    WHERE live_constraint.constraint_schema=DATABASE()
      AND live_constraint.table_name='safeharbor_m021_reference_claims'
      AND live_constraint.constraint_type='CHECK'
      AND (expected.constraint_name IS NULL
           OR live_constraint.enforced<>'YES'
           OR CAST(REGEXP_REPLACE(
                REPLACE(REPLACE(REPLACE(live_check.check_clause,'`',''),
                                '_utf8mb4',''),'_ascii',''),
                '[[:space:]()]','') AS BINARY)
              <> CAST(expected.normalized_clause AS BINARY)))
  AND
  (SELECT COUNT(*)=4
     FROM safeharbor_m021_source_checks expected
     JOIN information_schema.table_constraints live_constraint
       ON live_constraint.constraint_schema=DATABASE()
      AND live_constraint.table_name=expected.table_name
      AND live_constraint.constraint_name=expected.constraint_name
      AND live_constraint.constraint_type='CHECK'
      AND live_constraint.enforced='YES'
    WHERE expected.table_name='safeharbor_m021_reference_claims'
      AND expected.install_lock=0)
);
SET @cm_reference_claim_source_shape_ok = (
  @cm_reference_claim_source_table_ok=1
  AND @cm_reference_claim_source_columns_ok=1
  AND @cm_reference_claim_source_indexes_ok=1
  AND @cm_reference_claim_source_fks_ok=1
  AND @cm_reference_claim_source_checks_ok=1
);
SET @cm_reference_claim_disposable = (
  (IS_USED_LOCK(@cm_m021_lock_name) <=> CONNECTION_ID())
  AND @cm_reference_claim_cleanup_owned=1
  AND @cm_reference_claim_cleanup_empty=1
  AND @cm_claim_table_ok=1
  AND @cm_claim_columns_ok=1
  AND @cm_claim_indexes_ok=1
  AND @cm_claim_fks_ok=1
  AND @cm_claim_checks_ok=1
  AND @cm_reference_claim_source_shape_ok=1
  AND @cm_reference_claim_cleanup_triggers=1
  AND @cm_reference_claim_cleanup_dependencies=1
);
SET @cm_reference_claim_cleanup_sql = IF(
  @cm_reference_claim_disposable=1,
  'DO 0',
  'SELECT * FROM information_schema.migration_021_reference_claim_cleanup_refused'
);
PREPARE cm_export_statement FROM @cm_reference_claim_cleanup_sql;
EXECUTE cm_export_statement;
DEALLOCATE PREPARE cm_export_statement;

DROP TABLE safeharbor_m021_reference_claims;

DELIMITER $$
-- Fail closed while permanent triggers are installed or replaced on replay.
CREATE TRIGGER IF NOT EXISTS trg_cm_claim_021_insert_swap
BEFORE INSERT ON coastmark_time_export_claims FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'migration 021 claim trigger swap';
END$$
CREATE TRIGGER IF NOT EXISTS trg_cm_claim_021_update_swap
BEFORE UPDATE ON coastmark_time_export_claims FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'migration 021 claim trigger swap';
END$$
CREATE TRIGGER IF NOT EXISTS trg_cm_claim_021_delete_swap
BEFORE DELETE ON coastmark_time_export_claims FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'migration 021 claim trigger swap';
END$$
CREATE TRIGGER IF NOT EXISTS trg_cm_receipt_021_insert_swap
BEFORE INSERT ON coastmark_time_export_receipts FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'migration 021 receipt trigger swap';
END$$
CREATE TRIGGER IF NOT EXISTS trg_cm_receipt_021_update_swap
BEFORE UPDATE ON coastmark_time_export_receipts FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'migration 021 receipt trigger swap';
END$$
CREATE TRIGGER IF NOT EXISTS trg_cm_receipt_021_delete_swap
BEFORE DELETE ON coastmark_time_export_receipts FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'migration 021 receipt trigger swap';
END$$
DELIMITER ;

DELETE FROM safeharbor_m021_validated_triggers;
INSERT INTO safeharbor_m021_validated_triggers
  (trigger_name,guard_kind,event_object_table,event_manipulation)
SELECT expected.trigger_name,expected.guard_kind,expected.event_object_table,
       expected.event_manipulation
  FROM safeharbor_m021_trigger_manifest expected
  JOIN information_schema.triggers live
    ON live.trigger_schema=DATABASE()
   AND CAST(live.trigger_name AS BINARY)=CAST(expected.trigger_name AS BINARY)
 WHERE live.action_timing='BEFORE'
   AND live.action_orientation='ROW'
   AND live.action_condition IS NULL
   AND CAST(live.event_object_table AS BINARY)=CAST(expected.event_object_table AS BINARY)
   AND CAST(live.event_manipulation AS BINARY)=CAST(expected.event_manipulation AS BINARY)
   AND SHA2(CAST(live.action_statement AS BINARY),256)=expected.action_sha256;

SET @cm_validated_trigger_count =
  (SELECT COUNT(*) FROM safeharbor_m021_validated_triggers);
SET @cm_validated_swap_count =
  (SELECT COUNT(*) FROM safeharbor_m021_validated_triggers WHERE guard_kind='swap');

SET @cm_after_swap_trigger_set_ok = (
  SELECT COUNT(*)=@cm_validated_trigger_count
    FROM information_schema.triggers live
   WHERE live.trigger_schema=DATABASE()
     AND (live.event_object_table IN
             ('coastmark_time_export_claims','coastmark_time_export_receipts')
          OR EXISTS (
               SELECT 1 FROM safeharbor_m021_trigger_manifest reserved
                WHERE LOWER(reserved.trigger_name)=LOWER(live.trigger_name)
          ))
);
SET @cm_swaps_ready = (@cm_validated_swap_count=6);
SET @cm_swap_precondition_sql=IF(
  @cm_after_swap_trigger_set_ok=1 AND @cm_swaps_ready=1,
  'DO 0',
  'SELECT * FROM information_schema.migration_021_swap_guard_precondition_failed'
);
PREPARE cm_export_statement FROM @cm_swap_precondition_sql;
EXECUTE cm_export_statement;
DEALLOCATE PREPARE cm_export_statement;

DROP TRIGGER IF EXISTS trg_cm_export_claim_before_insert;
DROP TRIGGER IF EXISTS trg_cm_export_claim_no_update;
DROP TRIGGER IF EXISTS trg_cm_export_claim_no_delete;
DROP TRIGGER IF EXISTS trg_cm_export_receipt_before_insert;
DROP TRIGGER IF EXISTS trg_cm_export_receipt_no_update;
DROP TRIGGER IF EXISTS trg_cm_export_receipt_no_delete;

DELIMITER $$
CREATE TRIGGER trg_cm_export_claim_before_insert
BEFORE INSERT ON coastmark_time_export_claims
FOR EACH ROW
BEGIN
  DECLARE tenant_found INT DEFAULT 0;
  DECLARE parent_found INT DEFAULT 0;
  DECLARE parent_status VARCHAR(16) DEFAULT NULL;
  DECLARE parent_billable TINYINT DEFAULT NULL;
  DECLARE parent_reviewer INT UNSIGNED DEFAULT NULL;
  DECLARE parent_reviewed DATETIME DEFAULT NULL;
  DECLARE parent_client_id INT UNSIGNED DEFAULT NULL;
  DECLARE binding_found INT DEFAULT 0;
  DECLARE binding_customer_id CHAR(36) DEFAULT NULL;
  DECLARE binding_status VARCHAR(16) DEFAULT NULL;
  DECLARE payload_client_key VARCHAR(128) DEFAULT NULL;
  DECLARE actor_found INT DEFAULT 0;
  DECLARE actor_role VARCHAR(32) DEFAULT NULL;
  DECLARE actor_active TINYINT DEFAULT NULL;
  DECLARE latest_version INT DEFAULT -1;
  DECLARE latest_claim_id BIGINT UNSIGNED DEFAULT NULL;

  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET tenant_found = 0;
    SELECT 1 INTO tenant_found FROM tenants WHERE id=NEW.tenant_id FOR UPDATE;
  END;
  IF tenant_found <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export claim tenant does not exist';
  END IF;

  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET parent_found = 0;
    SELECT 1,approval_status,billable,reviewed_by_user_id,reviewed_at,client_id
      INTO parent_found,parent_status,parent_billable,parent_reviewer,parent_reviewed,
           parent_client_id
      FROM time_entries
     WHERE tenant_id=NEW.tenant_id AND id=NEW.time_entry_id
     FOR UPDATE;
  END;
  IF parent_found <> 1 OR BINARY parent_status <> BINARY 'approved' OR parent_billable <> 1
     OR parent_reviewer IS NULL OR parent_reviewed IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export claims require approved billable reviewed time';
  END IF;

  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND
      BEGIN
        SET binding_found=0;
        SET binding_customer_id=NULL;
        SET binding_status=NULL;
      END;
    SELECT 1,customer_id,status
      INTO binding_found,binding_customer_id,binding_status
      FROM suite_customer_sync_bindings
     WHERE tenant_id=NEW.tenant_id AND client_id=parent_client_id
     FOR UPDATE;
  END;
  SET payload_client_key=JSON_UNQUOTE(JSON_EXTRACT(NEW.payload_json,'$.client_key'));
  IF binding_found <> 1 OR BINARY binding_status <> BINARY 'active'
     OR NOT (BINARY payload_client_key <=>
             BINARY CONCAT('milepost-customer:',binding_customer_id)) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Export claims require an active matching customer binding';
  END IF;

  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET actor_found = 0;
    SELECT 1,role,is_active INTO actor_found,actor_role,actor_active
      FROM users
     WHERE tenant_id=NEW.tenant_id AND id=NEW.created_by_user_id
     FOR UPDATE;
  END;
  IF actor_found <> 1 OR actor_active <> 1
     OR (BINARY actor_role <> BINARY 'owner'
         AND BINARY actor_role <> BINARY 'admin') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export claim actor is not authorized';
  END IF;

  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND
      BEGIN SET latest_version=-1; SET latest_claim_id=NULL; END;
    SELECT source_version,id INTO latest_version,latest_claim_id
      FROM coastmark_time_export_claims
     WHERE tenant_id=NEW.tenant_id AND time_entry_id=NEW.time_entry_id
     ORDER BY source_version DESC LIMIT 1;
  END;
  IF NEW.source_version <> latest_version + 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export claim does not follow current source version';
  END IF;
  IF NOT (NEW.predecessor_claim_id <=> latest_claim_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export claim predecessor is not current';
  END IF;
  SET NEW.created_at=UTC_TIMESTAMP();
END$$

CREATE TRIGGER trg_cm_export_claim_no_update
BEFORE UPDATE ON coastmark_time_export_claims FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Coastmark export claims are immutable';
END$$
CREATE TRIGGER trg_cm_export_claim_no_delete
BEFORE DELETE ON coastmark_time_export_claims FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Coastmark export claims cannot be deleted';
END$$

CREATE TRIGGER trg_cm_export_receipt_before_insert
BEFORE INSERT ON coastmark_time_export_receipts
FOR EACH ROW
BEGIN
  DECLARE tenant_found INT DEFAULT 0;
  DECLARE claim_found INT DEFAULT 0;
  DECLARE latest_found INT DEFAULT 0;
  DECLARE latest_kind VARCHAR(32) DEFAULT NULL;
  DECLARE latest_outcome VARCHAR(32) DEFAULT NULL;
  DECLARE latest_created DATETIME DEFAULT NULL;
  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET tenant_found=0;
    SELECT 1 INTO tenant_found FROM tenants WHERE id=NEW.tenant_id FOR UPDATE;
  END;
  IF tenant_found <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export receipt tenant does not exist';
  END IF;
  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET claim_found=0;
    SELECT 1 INTO claim_found
      FROM coastmark_time_export_claims
     WHERE tenant_id=NEW.tenant_id AND id=NEW.claim_id
     FOR UPDATE;
  END;
  IF claim_found <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export receipt claim does not exist';
  END IF;
  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND
      BEGIN
        SET latest_found=0;
        SET latest_kind=NULL;
        SET latest_outcome=NULL;
        SET latest_created=NULL;
      END;
    SELECT 1,operation_kind,outcome,created_at
      INTO latest_found,latest_kind,latest_outcome,latest_created
      FROM coastmark_time_export_receipts
     WHERE tenant_id=NEW.tenant_id AND claim_id=NEW.claim_id
     ORDER BY id DESC LIMIT 1;
  END;
  IF NEW.operation_kind = 'dispatch_started' THEN
    IF NEW.outcome <> 'dispatching'
       OR (latest_found=1 AND latest_outcome <> 'absent') THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export dispatch transition is not permitted';
    END IF;
  ELSEIF NEW.operation_kind = 'status_started' THEN
    IF NEW.outcome <> 'checking'
       OR NOT (IS_USED_LOCK(CONCAT('safeharbor:cm-status:',NEW.claim_id))
               <=> CONNECTION_ID())
       OR (latest_found=1 AND latest_outcome IN
           ('accepted','replayed','manual_exception','conflict'))
       OR (latest_found=1
           AND latest_outcome IN ('dispatching','checking')
           AND latest_created > DATE_SUB(UTC_TIMESTAMP(),INTERVAL 35 SECOND)) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export status transition is not permitted';
    END IF;
  ELSEIF NEW.operation_kind = 'dispatch_result' THEN
    IF latest_found<>1 OR latest_kind <> 'dispatch_started' OR latest_outcome <> 'dispatching'
       OR NEW.outcome NOT IN ('accepted','replayed','ambiguous','conflict','manual_exception') THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export dispatch result transition is not permitted';
    END IF;
  ELSEIF NEW.operation_kind = 'status_result' THEN
    IF latest_found<>1 OR latest_kind <> 'status_started' OR latest_outcome <> 'checking'
       OR NEW.outcome NOT IN ('accepted','replayed','absent','ambiguous','conflict','manual_exception') THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export status result transition is not permitted';
    END IF;
  ELSE
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Export receipt operation is not permitted';
  END IF;
  SET NEW.created_at=UTC_TIMESTAMP();
END$$
CREATE TRIGGER trg_cm_export_receipt_no_update
BEFORE UPDATE ON coastmark_time_export_receipts FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Coastmark export receipts are immutable';
END$$
CREATE TRIGGER trg_cm_export_receipt_no_delete
BEFORE DELETE ON coastmark_time_export_receipts FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Coastmark export receipts cannot be deleted';
END$$
DELIMITER ;

DELETE FROM safeharbor_m021_validated_triggers;
INSERT INTO safeharbor_m021_validated_triggers
  (trigger_name,guard_kind,event_object_table,event_manipulation)
SELECT expected.trigger_name,expected.guard_kind,expected.event_object_table,
       expected.event_manipulation
  FROM safeharbor_m021_trigger_manifest expected
  JOIN information_schema.triggers live
    ON live.trigger_schema=DATABASE()
   AND CAST(live.trigger_name AS BINARY)=CAST(expected.trigger_name AS BINARY)
 WHERE live.action_timing='BEFORE'
   AND live.action_orientation='ROW'
   AND live.action_condition IS NULL
   AND CAST(live.event_object_table AS BINARY)=CAST(expected.event_object_table AS BINARY)
   AND CAST(live.event_manipulation AS BINARY)=CAST(expected.event_manipulation AS BINARY)
   AND SHA2(CAST(live.action_statement AS BINARY),256)=expected.action_sha256;

SET @cm_validated_trigger_count =
  (SELECT COUNT(*) FROM safeharbor_m021_validated_triggers);
SET @cm_validated_permanent_count =
  (SELECT COUNT(*) FROM safeharbor_m021_validated_triggers WHERE guard_kind='permanent');

SET @cm_permanent_guards_ok = (@cm_validated_permanent_count=6);
SET @cm_all_guard_triggers_ok = (
  SELECT COUNT(*)=12
     AND COUNT(*)=@cm_validated_trigger_count
    FROM information_schema.triggers live
   WHERE live.trigger_schema=DATABASE()
     AND (live.event_object_table IN
             ('coastmark_time_export_claims','coastmark_time_export_receipts')
          OR EXISTS (
               SELECT 1 FROM safeharbor_m021_trigger_manifest reserved
                WHERE LOWER(reserved.trigger_name)=LOWER(live.trigger_name)
          ))
);
SET @cm_guard_removal_precondition_sql=IF(
  @cm_swaps_ready=1 AND @cm_permanent_guards_ok=1 AND @cm_all_guard_triggers_ok=1,
  'DO 0',
  'SELECT * FROM information_schema.migration_021_guard_removal_precondition_failed'
);
PREPARE cm_export_statement FROM @cm_guard_removal_precondition_sql;
EXECUTE cm_export_statement;
DEALLOCATE PREPARE cm_export_statement;

DROP TRIGGER trg_cm_claim_021_insert_swap;
DROP TRIGGER trg_cm_claim_021_update_swap;
DROP TRIGGER trg_cm_claim_021_delete_swap;
DROP TRIGGER trg_cm_receipt_021_insert_swap;
DROP TRIGGER trg_cm_receipt_021_update_swap;
DROP TRIGGER trg_cm_receipt_021_delete_swap;

SET @cm_claim_lock_ddl=IF(
  (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema=DATABASE() AND table_name='coastmark_time_export_claims'
      AND constraint_name='ck_cm_export_claim_install_lock')=1,
  'ALTER TABLE coastmark_time_export_claims DROP CHECK ck_cm_export_claim_install_lock','DO 0');
PREPARE cm_export_statement FROM @cm_claim_lock_ddl;
EXECUTE cm_export_statement;
DEALLOCATE PREPARE cm_export_statement;

SET @cm_receipt_lock_ddl=IF(
  (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema=DATABASE() AND table_name='coastmark_time_export_receipts'
      AND constraint_name='ck_cm_export_receipt_install_lock')=1,
  'ALTER TABLE coastmark_time_export_receipts DROP CHECK ck_cm_export_receipt_install_lock','DO 0');
PREPARE cm_export_statement FROM @cm_receipt_lock_ddl;
EXECUTE cm_export_statement;
DEALLOCATE PREPARE cm_export_statement;

DELETE FROM safeharbor_m021_validated_triggers;
INSERT INTO safeharbor_m021_validated_triggers
  (trigger_name,guard_kind,event_object_table,event_manipulation)
SELECT expected.trigger_name,expected.guard_kind,expected.event_object_table,
       expected.event_manipulation
  FROM safeharbor_m021_trigger_manifest expected
  JOIN information_schema.triggers live
    ON live.trigger_schema=DATABASE()
   AND CAST(live.trigger_name AS BINARY)=CAST(expected.trigger_name AS BINARY)
 WHERE live.action_timing='BEFORE'
   AND live.action_orientation='ROW'
   AND live.action_condition IS NULL
   AND CAST(live.event_object_table AS BINARY)=CAST(expected.event_object_table AS BINARY)
   AND CAST(live.event_manipulation AS BINARY)=CAST(expected.event_manipulation AS BINARY)
   AND SHA2(CAST(live.action_statement AS BINARY),256)=expected.action_sha256;

SET @cm_validated_trigger_count =
  (SELECT COUNT(*) FROM safeharbor_m021_validated_triggers);
SET @cm_validated_permanent_count =
  (SELECT COUNT(*) FROM safeharbor_m021_validated_triggers WHERE guard_kind='permanent');

SET @cm_export_final_guards_ok = (
  SELECT COUNT(*)=6
     AND COUNT(*)=@cm_validated_trigger_count
     AND @cm_validated_permanent_count=6
    FROM information_schema.triggers live
   WHERE live.trigger_schema=DATABASE()
     AND (live.event_object_table IN
             ('coastmark_time_export_claims','coastmark_time_export_receipts')
          OR EXISTS (
               SELECT 1 FROM safeharbor_m021_trigger_manifest reserved
                WHERE LOWER(reserved.trigger_name)=LOWER(live.trigger_name)
          ))
);
SET @cm_export_install_locks_gone = (
  SELECT COUNT(*) = 0
    FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND table_name IN ('coastmark_time_export_claims','coastmark_time_export_receipts')
     AND constraint_name IN ('ck_cm_export_claim_install_lock','ck_cm_export_receipt_install_lock')
);
SET @cm_export_final_sql = IF(
  @cm_export_final_guards_ok = 1 AND @cm_export_install_locks_gone = 1,
  'DO 0',
  'SELECT * FROM information_schema.migration_021_coastmark_export_postcondition_failed'
);
PREPARE cm_export_statement FROM @cm_export_final_sql;
EXECUTE cm_export_statement;
DEALLOCATE PREPARE cm_export_statement;

DROP TEMPORARY TABLE safeharbor_m021_validated_triggers;
DROP TEMPORARY TABLE safeharbor_m021_trigger_manifest;
DROP TEMPORARY TABLE safeharbor_m021_reference_trigger_allowlist;
DROP TEMPORARY TABLE safeharbor_m021_source_checks;
DROP TEMPORARY TABLE safeharbor_m021_source_fks;
DROP TEMPORARY TABLE safeharbor_m021_source_indexes;
DROP TEMPORARY TABLE safeharbor_m021_source_columns;

-- Release only after every durable-table, trigger, install-lock, and cleanup
-- postcondition has passed. If any earlier statement aborts, the dedicated
-- runner connection retains the single lock until exact retry or close.
SET @cm_m021_lock_release_owned =
  (IS_USED_LOCK(@cm_m021_lock_name) <=> CONNECTION_ID());
SET @cm_m021_lock_release_guard_sql = IF(
  @cm_m021_lock_release_owned=1,
  'DO 0',
  'SELECT * FROM information_schema.migration_021_advisory_lock_release_owner_failed'
);
PREPARE cm_export_statement FROM @cm_m021_lock_release_guard_sql;
EXECUTE cm_export_statement;
DEALLOCATE PREPARE cm_export_statement;
SET @cm_m021_lock_release_result = RELEASE_LOCK(@cm_m021_lock_name);
SET @cm_m021_lock_released = (
  @cm_m021_lock_release_result=1
  AND NOT (IS_USED_LOCK(@cm_m021_lock_name) <=> CONNECTION_ID())
);
SET @cm_m021_lock_release_sql = IF(
  @cm_m021_lock_released=1,
  'DO 0',
  'SELECT * FROM information_schema.migration_021_advisory_lock_release_failed'
);
PREPARE cm_export_statement FROM @cm_m021_lock_release_sql;
EXECUTE cm_export_statement;
DEALLOCATE PREPARE cm_export_statement;

-- Migration-only postflight result; canonical schema stops before this marker.
SELECT
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='coastmark_time_export_claims') AS claim_columns,
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='coastmark_time_export_receipts') AS receipt_columns,
  (SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'trg_cm_export_claim_%') AS claim_triggers,
  (SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'trg_cm_export_receipt_%') AS receipt_triggers;
