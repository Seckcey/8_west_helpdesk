-- Migration 021: durable Coastmark v3 export claims and append-only receipts.
-- Shared lock order: tenant -> time entry -> actor -> claim/adjustment rows.

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

-- CREATE TABLE IF NOT EXISTS is only a convenience for a fresh install. It
-- must never bless a same-named object whose columns, constraints, or storage
-- engine have drifted. Prove the exact durable shape before replacing any
-- write guard.
DROP TABLE IF EXISTS safeharbor_m021_reference_receipts;
DROP TABLE IF EXISTS safeharbor_m021_reference_claims;

CREATE TABLE safeharbor_m021_reference_claims (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE safeharbor_m021_reference_receipts (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
  @cm_claim_install_lock_present=0,
  'ALTER TABLE safeharbor_m021_reference_claims DROP CHECK rc21_claim_install_lock',
  'DO 0'
);
PREPARE cm_export_statement FROM @cm_claim_reference_lock_ddl;
EXECUTE cm_export_statement;
DEALLOCATE PREPARE cm_export_statement;
SET @cm_receipt_reference_lock_ddl=IF(
  @cm_receipt_install_lock_present=0,
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
  trigger_name       VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  guard_kind         VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_object_table VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_manipulation VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  action_sha256      CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (trigger_name)
) ENGINE=MEMORY;
INSERT INTO safeharbor_m021_trigger_manifest
  (trigger_name,guard_kind,event_object_table,event_manipulation,action_sha256)
VALUES
  ('trg_cm_claim_021_insert_swap','swap','coastmark_time_export_claims','INSERT',
   '771f82e83a8f9798b7bdcff7dad368d19fd4d9fd8423d297ce1ee5349b891960'),
  ('trg_cm_claim_021_update_swap','swap','coastmark_time_export_claims','UPDATE',
   '771f82e83a8f9798b7bdcff7dad368d19fd4d9fd8423d297ce1ee5349b891960'),
  ('trg_cm_claim_021_delete_swap','swap','coastmark_time_export_claims','DELETE',
   '771f82e83a8f9798b7bdcff7dad368d19fd4d9fd8423d297ce1ee5349b891960'),
  ('trg_cm_receipt_021_insert_swap','swap','coastmark_time_export_receipts','INSERT',
   'c02699492864023cf38744261d28a4289b7fe108132811fedb74ea19e346dc77'),
  ('trg_cm_receipt_021_update_swap','swap','coastmark_time_export_receipts','UPDATE',
   'c02699492864023cf38744261d28a4289b7fe108132811fedb74ea19e346dc77'),
  ('trg_cm_receipt_021_delete_swap','swap','coastmark_time_export_receipts','DELETE',
   'c02699492864023cf38744261d28a4289b7fe108132811fedb74ea19e346dc77'),
  ('trg_cm_export_claim_before_insert','permanent','coastmark_time_export_claims','INSERT',
   'b85037f0cd62efd9818a75dfb560a835b0750e0f64bd8de22e58220153532f0b'),
  ('trg_cm_export_claim_no_update','permanent','coastmark_time_export_claims','UPDATE',
   'e92501875314cc34e52c99d8b9b4f1fa65def4284b57446671439c70e21c652f'),
  ('trg_cm_export_claim_no_delete','permanent','coastmark_time_export_claims','DELETE',
   '41ddc492f29424d957e011195914afef774902c4e601247d536d2d50510e2f68'),
  ('trg_cm_export_receipt_before_insert','permanent','coastmark_time_export_receipts','INSERT',
   'ebc2332991ab471edba54017bb86ea889cddf33659dc56879d2cee63b8f4549a'),
  ('trg_cm_export_receipt_no_update','permanent','coastmark_time_export_receipts','UPDATE',
   'f102251a23b13f5d11306afbce62d6ce7e33a57899cf555841b48ae1bfce5862'),
  ('trg_cm_export_receipt_no_delete','permanent','coastmark_time_export_receipts','DELETE',
   'cab0fd81313682ad6a8ebbfb160b895c9f770883931126194fff174dd10b64a8');

SET @cm_initial_trigger_set_ok = (
  SELECT COALESCE(SUM(
           expected.trigger_name IS NOT NULL
       AND live.action_timing='BEFORE'
       AND live.action_orientation='ROW'
       AND live.action_condition IS NULL
       AND live.event_object_table=expected.event_object_table
       AND live.event_manipulation=expected.event_manipulation
       AND SHA2(LOWER(REGEXP_REPLACE(REPLACE(live.action_statement,'`',''),
                                    '[[:space:]]+','')),256)=expected.action_sha256
     ),0)=COUNT(*)
    FROM information_schema.triggers live
    LEFT JOIN safeharbor_m021_trigger_manifest expected
      ON expected.trigger_name=live.trigger_name
   WHERE live.trigger_schema=DATABASE()
     AND live.event_object_table IN
         ('coastmark_time_export_claims','coastmark_time_export_receipts')
);
SET @cm_initial_guard_coverage_ok = (
     (@cm_claim_install_lock_present=1 OR
      (SELECT COUNT(*) FROM information_schema.triggers
        WHERE trigger_schema=DATABASE()
          AND trigger_name IN ('trg_cm_export_claim_before_insert',
                               'trg_cm_claim_021_insert_swap')) > 0)
 AND (@cm_claim_install_lock_present=1 OR
      (SELECT COUNT(*) FROM information_schema.triggers
        WHERE trigger_schema=DATABASE()
          AND trigger_name IN ('trg_cm_export_claim_no_update',
                               'trg_cm_claim_021_update_swap')) > 0)
 AND (@cm_claim_install_lock_present=1 OR
      (SELECT COUNT(*) FROM information_schema.triggers
        WHERE trigger_schema=DATABASE()
          AND trigger_name IN ('trg_cm_export_claim_no_delete',
                               'trg_cm_claim_021_delete_swap')) > 0)
 AND (@cm_receipt_install_lock_present=1 OR
      (SELECT COUNT(*) FROM information_schema.triggers
        WHERE trigger_schema=DATABASE()
          AND trigger_name IN ('trg_cm_export_receipt_before_insert',
                               'trg_cm_receipt_021_insert_swap')) > 0)
 AND (@cm_receipt_install_lock_present=1 OR
      (SELECT COUNT(*) FROM information_schema.triggers
        WHERE trigger_schema=DATABASE()
          AND trigger_name IN ('trg_cm_export_receipt_no_update',
                               'trg_cm_receipt_021_update_swap')) > 0)
 AND (@cm_receipt_install_lock_present=1 OR
      (SELECT COUNT(*) FROM information_schema.triggers
        WHERE trigger_schema=DATABASE()
          AND trigger_name IN ('trg_cm_export_receipt_no_delete',
                               'trg_cm_receipt_021_delete_swap')) > 0)
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

DROP TABLE safeharbor_m021_reference_receipts;
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

SET @cm_after_swap_trigger_set_ok = (
  SELECT COALESCE(SUM(
           expected.trigger_name IS NOT NULL
       AND live.action_timing='BEFORE'
       AND live.action_orientation='ROW'
       AND live.action_condition IS NULL
       AND live.event_object_table=expected.event_object_table
       AND live.event_manipulation=expected.event_manipulation
       AND SHA2(LOWER(REGEXP_REPLACE(REPLACE(live.action_statement,'`',''),
                                    '[[:space:]]+','')),256)=expected.action_sha256
     ),0)=COUNT(*)
    FROM information_schema.triggers live
    LEFT JOIN safeharbor_m021_trigger_manifest expected
      ON expected.trigger_name=live.trigger_name
   WHERE live.trigger_schema=DATABASE()
     AND live.event_object_table IN
         ('coastmark_time_export_claims','coastmark_time_export_receipts')
);
SET @cm_swaps_ready = (
  SELECT COUNT(*)=6
     AND COALESCE(SUM(
           live.trigger_name IS NOT NULL
       AND live.action_timing='BEFORE'
       AND live.action_orientation='ROW'
       AND live.action_condition IS NULL
       AND live.event_object_table=expected.event_object_table
       AND live.event_manipulation=expected.event_manipulation
       AND SHA2(LOWER(REGEXP_REPLACE(REPLACE(live.action_statement,'`',''),
                                    '[[:space:]]+','')),256)=expected.action_sha256
     ),0)=6
    FROM safeharbor_m021_trigger_manifest expected
    LEFT JOIN information_schema.triggers live
      ON live.trigger_schema=DATABASE()
     AND live.trigger_name=expected.trigger_name
   WHERE expected.guard_kind='swap'
);
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

SET @cm_permanent_guards_ok = (
  SELECT COUNT(*)=6
     AND COALESCE(SUM(
           live.trigger_name IS NOT NULL
       AND live.action_timing='BEFORE'
       AND live.action_orientation='ROW'
       AND live.action_condition IS NULL
       AND live.event_object_table=expected.event_object_table
       AND live.event_manipulation=expected.event_manipulation
       AND SHA2(LOWER(REGEXP_REPLACE(REPLACE(live.action_statement,'`',''),
                                    '[[:space:]]+','')),256)=expected.action_sha256
     ),0)=6
    FROM safeharbor_m021_trigger_manifest expected
    LEFT JOIN information_schema.triggers live
      ON live.trigger_schema=DATABASE()
     AND live.trigger_name=expected.trigger_name
   WHERE expected.guard_kind='permanent'
);
SET @cm_all_guard_triggers_ok = (
  SELECT COUNT(*)=12
     AND COALESCE(SUM(
           expected.trigger_name IS NOT NULL
       AND live.action_timing='BEFORE'
       AND live.action_orientation='ROW'
       AND live.action_condition IS NULL
       AND live.event_object_table=expected.event_object_table
       AND live.event_manipulation=expected.event_manipulation
       AND SHA2(LOWER(REGEXP_REPLACE(REPLACE(live.action_statement,'`',''),
                                    '[[:space:]]+','')),256)=expected.action_sha256
     ),0)=12
    FROM information_schema.triggers live
    LEFT JOIN safeharbor_m021_trigger_manifest expected
      ON expected.trigger_name=live.trigger_name
   WHERE live.trigger_schema=DATABASE()
     AND live.event_object_table IN
         ('coastmark_time_export_claims','coastmark_time_export_receipts')
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

SET @cm_export_final_guards_ok = (
  SELECT COUNT(*)=6
     AND COALESCE(SUM(
           expected.trigger_name IS NOT NULL
       AND expected.guard_kind='permanent'
       AND live.action_timing='BEFORE'
       AND live.action_orientation='ROW'
       AND live.action_condition IS NULL
       AND live.event_object_table=expected.event_object_table
       AND live.event_manipulation=expected.event_manipulation
       AND SHA2(LOWER(REGEXP_REPLACE(REPLACE(live.action_statement,'`',''),
                                    '[[:space:]]+','')),256)=expected.action_sha256
     ),0)=6
    FROM information_schema.triggers live
    LEFT JOIN safeharbor_m021_trigger_manifest expected
      ON expected.trigger_name=live.trigger_name
   WHERE live.trigger_schema=DATABASE()
     AND live.event_object_table IN
         ('coastmark_time_export_claims','coastmark_time_export_receipts')
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

DROP TEMPORARY TABLE safeharbor_m021_trigger_manifest;

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
