-- 034_managed_customer_identity_lifecycle_restore.sql
-- Identity-only restoration receipts (A88/A95/A97), beside the unchanged legacy contact restore.
--
-- One atomic ALTER of managed_customer_lifecycle_restore_receipts, with no backfill and no old row
-- rewritten. It adds the signed identity-proof metadata: id_proof_scope ('identity_lifecycle_only' or
-- NULL), id_initial_receipt_id, id_provider_tenant_slug, id_issuer_suite_tenant_id,
-- id_projection_binding_sha256 and id_projection_tenant_key. It adds the five restoration-anchor
-- columns, and extends id_lifecycle_action to 'observed_active'. It makes id_tenant_key,
-- contact_version and recipient_sha256 nullable. The new CHECK ck_mc_restore_proof_scope keeps every
-- legacy (NULL-scope) row exactly as before: restored only, a non-NULL report-map key, contact version
-- and recipient hash, and every new column NULL. An identity-only row has a NULL report-map carrier,
-- contact version and recipient hash, writes no schedule/contact (schedule_restored 0), and has all
-- metadata and all five anchor facts type-valid. fk_mc_restore_id_binding is preserved and simply
-- does not apply to a NULL carrier.
--
-- The restore-receipt validator becomes v3. Its legacy branch is v2 unchanged. Its identity-only
-- branch requires an existing report-ID map, when present, to match the signed key and slug. It
-- requires the exact provider tenant, a current managed-provider issuer, and the anchor as this
-- binding's active version directly after an inactive one with no later inactive version; a current
-- `restored` action must be its own anchor. The identity-only digest is a new closed domain.
-- The legacy domain and digests are unchanged. The new scope test, the CHECK's identity branch and
-- the final digest comparison are NULL-safe (A136): a NULL there refuses, never passes as UNKNOWN.
-- Validator pins are over the stored ACTION_STATEMENT as MySQL keeps it (A154): its '\n' string
-- literals are real newlines there, which the whitespace normalization removes.
--
-- A swap guard refuses every restore-receipt insert from before the first change until the
-- postflight passes. Accepted starting states: the completed 032 validator, an interrupted 034 (the
-- swap guard exact), or a completed 034. Never replay 024 or 032 after this migration. Apply from a
-- canonical LF checkout with the existing deploy path (database first, then files):
--   bash deploy/deploy.sh --migrate app/db/migrations/034_managed_customer_identity_lifecycle_restore.sql

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET SESSION group_concat_max_len = 16384;

SET @mc_identity_swap_body = CONCAT(
  'BEGIN', CHAR(10),
  '  SIGNAL SQLSTATE ''45000''', CHAR(10),
  '    SET MESSAGE_TEXT=''Managed-customer identity-only restoration migration is incomplete'';', CHAR(10),
  'END'
);

SET @mc_identity_table_ok = (
  SELECT COUNT(*) = 1 FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name = 'managed_customer_lifecycle_restore_receipts' AND engine = 'InnoDB'
);
SET @mc_identity_install_ok = (
  SELECT COUNT(*) = 0 FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND table_name = 'managed_customer_lifecycle_restore_receipts'
     AND constraint_name = 'ck_mc_restore_install_lock'
);
SET @mc_identity_immutable_ok = (
  SELECT COUNT(*) = 2 FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND event_object_table = 'managed_customer_lifecycle_restore_receipts'
     AND action_timing = 'BEFORE'
     AND ((trigger_name = 'trg_mc_restore_no_update' AND event_manipulation = 'UPDATE')
       OR (trigger_name = 'trg_mc_restore_no_delete' AND event_manipulation = 'DELETE'))
     AND action_statement LIKE '%safeharbor-managed-customer-lifecycle-restore-immutable-v1%'
);
SET @mc_identity_v2_count = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND event_object_table = 'managed_customer_lifecycle_restore_receipts'
     AND trigger_name = 'trg_mc_restore_before_insert'
     AND action_timing = 'BEFORE' AND event_manipulation = 'INSERT' AND action_orientation = 'ROW'
     AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
         = 'fa6a72200aefca50168859957da2015337bec81c8de4b46d0670c4110defb940'
);
SET @mc_identity_v3_count = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND event_object_table = 'managed_customer_lifecycle_restore_receipts'
     AND trigger_name = 'trg_mc_restore_before_insert'
     AND action_timing = 'BEFORE' AND event_manipulation = 'INSERT' AND action_orientation = 'ROW'
     AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
         = 'eb49a8cb57ace84034414bac400f9690cc81a87968293624b859f9d8b488ff93'
);
SET @mc_identity_validator_names = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE() AND trigger_name = 'trg_mc_restore_before_insert'
);
SET @mc_identity_swap_count = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND event_object_table = 'managed_customer_lifecycle_restore_receipts'
     AND trigger_name = 'trg_mc_restore_identity_swap_insert'
     AND action_timing = 'BEFORE' AND event_manipulation = 'INSERT' AND action_orientation = 'ROW'
     AND BINARY action_statement = BINARY @mc_identity_swap_body
);
SET @mc_identity_swap_names = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE() AND trigger_name = 'trg_mc_restore_identity_swap_insert'
);
SET @mc_identity_trigger_total = (
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND event_object_table = 'managed_customer_lifecycle_restore_receipts'
);
SET @mc_identity_column_total = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'managed_customer_lifecycle_restore_receipts'
);
SET @mc_identity_original_columns = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'managed_customer_lifecycle_restore_receipts'
     AND (
       (column_name = 'id_lifecycle_action' AND column_type = 'enum(''restored'')' AND is_nullable = 'NO')
       OR (column_name = 'id_tenant_key' AND column_type = 'varchar(32)' AND is_nullable = 'NO'
         AND character_set_name = 'ascii' AND collation_name = 'ascii_bin')
       OR (column_name = 'contact_version' AND column_type = 'bigint unsigned' AND is_nullable = 'NO')
       OR (column_name = 'recipient_sha256' AND column_type = 'char(64)' AND is_nullable = 'NO'
         AND character_set_name = 'ascii' AND collation_name = 'ascii_bin')
     )
);
SET @mc_identity_completed_columns = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'managed_customer_lifecycle_restore_receipts'
     AND column_default IS NULL AND extra = ''
     AND (
       (column_name = 'id_lifecycle_action' AND column_type = 'enum(''restored'',''observed_active'')'
         AND is_nullable = 'NO')
       OR (column_name = 'id_tenant_key' AND column_type = 'varchar(32)' AND is_nullable = 'YES'
         AND character_set_name = 'ascii' AND collation_name = 'ascii_bin')
       OR (column_name = 'contact_version' AND column_type = 'bigint unsigned' AND is_nullable = 'YES')
       OR (column_name = 'recipient_sha256' AND column_type = 'char(64)' AND is_nullable = 'YES'
         AND character_set_name = 'ascii' AND collation_name = 'ascii_bin')
       OR (column_name = 'id_proof_scope' AND ordinal_position = 43
         AND column_type = 'enum(''identity_lifecycle_only'')' AND is_nullable = 'YES'
         AND character_set_name = 'ascii' AND collation_name = 'ascii_bin')
       OR (column_name = 'id_initial_receipt_id' AND ordinal_position = 44 AND column_type = 'char(64)'
         AND is_nullable = 'YES' AND character_set_name = 'ascii' AND collation_name = 'ascii_bin')
       OR (column_name = 'id_provider_tenant_slug' AND ordinal_position = 45 AND column_type = 'varchar(64)'
         AND is_nullable = 'YES' AND character_set_name = 'ascii' AND collation_name = 'ascii_bin')
       OR (column_name = 'id_issuer_suite_tenant_id' AND ordinal_position = 46
         AND column_type = 'bigint unsigned' AND is_nullable = 'YES')
       OR (column_name = 'id_projection_binding_sha256' AND ordinal_position = 47 AND column_type = 'char(64)'
         AND is_nullable = 'YES' AND character_set_name = 'ascii' AND collation_name = 'ascii_bin')
       OR (column_name = 'id_projection_tenant_key' AND ordinal_position = 48 AND column_type = 'varchar(32)'
         AND is_nullable = 'YES' AND character_set_name = 'ascii' AND collation_name = 'ascii_bin')
       OR (column_name = 'id_restore_anchor_source_version' AND ordinal_position = 49
         AND column_type = 'bigint unsigned' AND is_nullable = 'YES')
       OR (column_name = 'id_restore_anchor_receipt_id' AND ordinal_position = 50 AND column_type = 'char(64)'
         AND is_nullable = 'YES' AND character_set_name = 'ascii' AND collation_name = 'ascii_bin')
       OR (column_name = 'id_restore_anchor_event_id' AND ordinal_position = 51 AND column_type = 'char(36)'
         AND is_nullable = 'YES' AND character_set_name = 'ascii' AND collation_name = 'ascii_bin')
       OR (column_name = 'id_restore_anchor_transition_id' AND ordinal_position = 52
         AND column_type = 'bigint unsigned' AND is_nullable = 'YES')
       OR (column_name = 'id_restore_anchor_evidence_sha256' AND ordinal_position = 53 AND column_type = 'char(64)'
         AND is_nullable = 'YES' AND character_set_name = 'ascii' AND collation_name = 'ascii_bin')
     )
);
SET @mc_identity_new_present = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'managed_customer_lifecycle_restore_receipts'
     AND column_name IN (
       'id_proof_scope','id_initial_receipt_id','id_provider_tenant_slug','id_issuer_suite_tenant_id',
       'id_projection_binding_sha256','id_projection_tenant_key','id_restore_anchor_source_version',
       'id_restore_anchor_receipt_id','id_restore_anchor_event_id','id_restore_anchor_transition_id',
       'id_restore_anchor_evidence_sha256'
     )
);
SET @mc_identity_check_present = (
  SELECT COUNT(*) FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND table_name = 'managed_customer_lifecycle_restore_receipts'
     AND constraint_name = 'ck_mc_restore_proof_scope'
);
-- The scope CHECK, normalized exactly as migration 015 normalizes its checks.
SET @mc_identity_check_exact = (
  SELECT COUNT(*) FROM information_schema.table_constraints scope_constraint
    JOIN information_schema.check_constraints scope_check
      ON scope_check.constraint_schema = scope_constraint.constraint_schema
     AND scope_check.constraint_name = scope_constraint.constraint_name
   WHERE scope_constraint.constraint_schema = DATABASE()
     AND scope_constraint.table_name = 'managed_customer_lifecycle_restore_receipts'
     AND scope_constraint.constraint_name = 'ck_mc_restore_proof_scope'
     AND scope_constraint.enforced = 'YES'
     AND SHA2(REGEXP_REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(scope_check.check_clause),
           '`', ''), CHAR(92), ''), '_ascii', ''), '_utf8mb4', ''), '[[:space:]()]', ''), 256)
         = '5e63b58a0d459dfea99ca34b3c0bc6a4d5a67091b00d543ac148971538fcbc25'
);
SET @mc_identity_original_shape = (
  @mc_identity_column_total = 42 AND @mc_identity_original_columns = 4
  AND @mc_identity_new_present = 0 AND @mc_identity_check_present = 0
);
SET @mc_identity_completed_shape = (
  @mc_identity_column_total = 53 AND @mc_identity_completed_columns = 15
  AND @mc_identity_check_present = 1 AND @mc_identity_check_exact = 1
);
SET @mc_identity_preflight_sql = IF(
  @mc_identity_table_ok = 1 AND @mc_identity_install_ok = 1 AND @mc_identity_immutable_ok = 1
  AND @mc_identity_swap_names = @mc_identity_swap_count
  AND @mc_identity_validator_names = @mc_identity_v2_count + @mc_identity_v3_count
  AND @mc_identity_trigger_total = 2 + @mc_identity_validator_names + @mc_identity_swap_count
  AND (@mc_identity_original_shape = 1 OR @mc_identity_completed_shape = 1)
  AND ((@mc_identity_swap_count = 0 AND @mc_identity_v2_count = 1 AND @mc_identity_original_shape = 1)
    OR (@mc_identity_swap_count = 0 AND @mc_identity_v3_count = 1 AND @mc_identity_completed_shape = 1)
    OR (@mc_identity_swap_count = 1 AND (@mc_identity_v3_count = 0 OR @mc_identity_completed_shape = 1))),
  'DO 0',
  'SELECT * FROM information_schema.migration_034_managed_customer_identity_restore_preflight_failed'
);
PREPARE mc_identity_preflight FROM @mc_identity_preflight_sql;
EXECUTE mc_identity_preflight;
DEALLOCATE PREPARE mc_identity_preflight;

DROP TRIGGER IF EXISTS trg_mc_restore_identity_privilege_preflight;
CREATE TRIGGER trg_mc_restore_identity_privilege_preflight
BEFORE INSERT ON managed_customer_lifecycle_restore_receipts
FOR EACH ROW SET @mc_restore_identity_trigger_privilege_preflight = 1;
DROP TRIGGER trg_mc_restore_identity_privilege_preflight;

DELIMITER $$
CREATE TRIGGER IF NOT EXISTS trg_mc_restore_identity_swap_insert
BEFORE INSERT ON managed_customer_lifecycle_restore_receipts
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT='Managed-customer identity-only restoration migration is incomplete';
END$$
DELIMITER ;

SET @mc_identity_swap_sql = IF(
  (SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema = DATABASE()
      AND event_object_table = 'managed_customer_lifecycle_restore_receipts'
      AND trigger_name = 'trg_mc_restore_identity_swap_insert'
      AND action_timing = 'BEFORE' AND event_manipulation = 'INSERT' AND action_orientation = 'ROW'
      AND BINARY action_statement = BINARY @mc_identity_swap_body) = 1
  AND (SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema = DATABASE() AND trigger_name = 'trg_mc_restore_identity_swap_insert') = 1,
  'DO 0',
  'SELECT * FROM information_schema.migration_034_managed_customer_identity_restore_swap_failed'
);
PREPARE mc_identity_swap_statement FROM @mc_identity_swap_sql;
EXECUTE mc_identity_swap_statement;
DEALLOCATE PREPARE mc_identity_swap_statement;

SET @mc_identity_alter_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'managed_customer_lifecycle_restore_receipts'
      AND column_name = 'id_proof_scope') = 0,
  CONCAT(
    'ALTER TABLE managed_customer_lifecycle_restore_receipts',
    ' MODIFY id_lifecycle_action ENUM(''restored'',''observed_active'') NOT NULL,',
    ' MODIFY id_tenant_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,',
    ' MODIFY contact_version BIGINT UNSIGNED NULL,',
    ' MODIFY recipient_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,',
    ' ADD COLUMN id_proof_scope ENUM(''identity_lifecycle_only'') CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,',
    ' ADD COLUMN id_initial_receipt_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,',
    ' ADD COLUMN id_provider_tenant_slug VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,',
    ' ADD COLUMN id_issuer_suite_tenant_id BIGINT UNSIGNED NULL,',
    ' ADD COLUMN id_projection_binding_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,',
    ' ADD COLUMN id_projection_tenant_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,',
    ' ADD COLUMN id_restore_anchor_source_version BIGINT UNSIGNED NULL,',
    ' ADD COLUMN id_restore_anchor_receipt_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,',
    ' ADD COLUMN id_restore_anchor_event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,',
    ' ADD COLUMN id_restore_anchor_transition_id BIGINT UNSIGNED NULL,',
    ' ADD COLUMN id_restore_anchor_evidence_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,',
    ' ADD CONSTRAINT ck_mc_restore_proof_scope CHECK (',
    '(id_proof_scope IS NULL',
    ' AND id_lifecycle_action = ''restored''',
    ' AND id_tenant_key IS NOT NULL AND contact_version IS NOT NULL AND recipient_sha256 IS NOT NULL',
    ' AND id_initial_receipt_id IS NULL AND id_provider_tenant_slug IS NULL',
    ' AND id_issuer_suite_tenant_id IS NULL AND id_projection_binding_sha256 IS NULL',
    ' AND id_projection_tenant_key IS NULL',
    ' AND id_restore_anchor_source_version IS NULL AND id_restore_anchor_receipt_id IS NULL',
    ' AND id_restore_anchor_event_id IS NULL AND id_restore_anchor_transition_id IS NULL',
    ' AND id_restore_anchor_evidence_sha256 IS NULL)',
    ' OR (id_proof_scope IS NOT NULL AND id_proof_scope = ''identity_lifecycle_only''',
    ' AND id_lifecycle_action IN (''restored'',''observed_active'')',
    ' AND id_tenant_key IS NULL AND contact_version IS NULL AND recipient_sha256 IS NULL',
    ' AND schedule_restored = 0 AND schedule_owner_receipt_id IS NULL',
    ' AND schedule_prepared_version_id IS NULL AND contact_snapshot_id IS NULL',
    ' AND schedule_active_version_id IS NULL',
    ' AND id_initial_receipt_id IS NOT NULL AND REGEXP_LIKE(id_initial_receipt_id, ''^[0-9a-f]{64}$'')',
    ' AND id_provider_tenant_slug IS NOT NULL AND REGEXP_LIKE(id_provider_tenant_slug, ''^[a-z0-9][a-z0-9-]{0,63}$'')',
    ' AND id_issuer_suite_tenant_id IS NOT NULL AND id_issuer_suite_tenant_id BETWEEN 1 AND 4294967295',
    ' AND id_projection_binding_sha256 IS NOT NULL AND REGEXP_LIKE(id_projection_binding_sha256, ''^[0-9a-f]{64}$'')',
    ' AND id_projection_tenant_key IS NOT NULL AND REGEXP_LIKE(id_projection_tenant_key, ''^ewid-t[1-9][0-9]{0,9}$'')',
    ' AND id_restore_anchor_source_version IS NOT NULL AND id_restore_anchor_source_version >= 2',
    ' AND id_restore_anchor_source_version <= source_version',
    ' AND id_restore_anchor_receipt_id IS NOT NULL AND REGEXP_LIKE(id_restore_anchor_receipt_id, ''^[0-9a-f]{64}$'')',
    ' AND id_restore_anchor_event_id IS NOT NULL',
    ' AND REGEXP_LIKE(id_restore_anchor_event_id, ''^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'')',
    ' AND id_restore_anchor_transition_id IS NOT NULL AND id_restore_anchor_transition_id >= 1',
    ' AND id_restore_anchor_evidence_sha256 IS NOT NULL AND REGEXP_LIKE(id_restore_anchor_evidence_sha256, ''^[0-9a-f]{64}$''))',
    ') ENFORCED'
  ),
  'DO 0'
);
PREPARE mc_identity_alter FROM @mc_identity_alter_sql;
EXECUTE mc_identity_alter;
DEALLOCATE PREPARE mc_identity_alter;

DROP TRIGGER IF EXISTS trg_mc_restore_before_insert;

DELIMITER $$
CREATE TRIGGER trg_mc_restore_before_insert
BEFORE INSERT ON managed_customer_lifecycle_restore_receipts
FOR EACH ROW
BEGIN
  DECLARE guard_version VARCHAR(64) DEFAULT 'safeharbor-managed-customer-lifecycle-restore-receipt-v3';
  DECLARE actor_matches INT DEFAULT 0;
  DECLARE source_matches INT DEFAULT 0;
  DECLARE inactive_history INT DEFAULT 0;
  DECLARE id_binding_matches INT DEFAULT 0;
  DECLARE id_binding_present INT DEFAULT 0;
  DECLARE provider_matches INT DEFAULT 0;
  DECLARE anchor_matches INT DEFAULT 0;
  DECLARE later_inactive INT DEFAULT 0;
  DECLARE portal_owner_matches INT DEFAULT 0;
  DECLARE portal_state_matches INT DEFAULT 0;
  DECLARE schedule_owner_matches INT DEFAULT 0;
  DECLARE schedule_state_matches INT DEFAULT 0;
  DECLARE expected_portal_hash CHAR(64) DEFAULT NULL;
  DECLARE expected_schedule_hash CHAR(64) DEFAULT NULL;
  DECLARE expected_evidence_hash CHAR(64) DEFAULT NULL;

  IF NEW.id_customer_status<>'active' OR NEW.id_lifecycle_version<>1
     OR NOT ((NEW.id_proof_scope IS NULL AND NEW.id_lifecycle_action='restored')
          OR (NEW.id_proof_scope IS NOT NULL AND NEW.id_proof_scope='identity_lifecycle_only'
              AND NEW.id_lifecycle_action IN ('restored','observed_active')))
     OR NEW.id_identity_tenant_status<>'active' OR NEW.id_lifecycle_owned<>0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT='Restore receipt requires exact active restored-only ID evidence';
  END IF;
  SELECT COUNT(*) INTO actor_matches
    FROM users
   WHERE tenant_id=NEW.tenant_id AND id=NEW.actor_user_id
     AND is_active=1 AND role IN ('owner','admin');
  IF actor_matches<>1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT='Restore actor must be an active owner or admin';
  END IF;
  SELECT COUNT(*) INTO source_matches
    FROM suite_customer_sync_bindings binding
    JOIN suite_customer_sync_events receipt
      ON receipt.tenant_id=binding.tenant_id
     AND receipt.id=NEW.source_event_receipt_id
     AND receipt.binding_id=binding.id
   WHERE binding.tenant_id=NEW.tenant_id AND binding.id=NEW.source_binding_id
     AND binding.client_id=NEW.client_id
     AND BINARY binding.customer_id=BINARY NEW.customer_id
     AND binding.source_version=NEW.source_version AND binding.status='active'
     AND BINARY binding.last_event_id=BINARY NEW.source_event_id
     AND BINARY binding.last_request_sha256=BINARY NEW.source_request_sha256
     AND BINARY receipt.event_id=BINARY NEW.source_event_id
     AND receipt.source_version=NEW.source_version AND receipt.status='active'
     AND BINARY receipt.request_sha256=BINARY NEW.source_request_sha256;
  SELECT COUNT(*) INTO inactive_history
    FROM suite_customer_sync_events
   WHERE tenant_id=NEW.tenant_id AND binding_id=NEW.source_binding_id
     AND status='inactive';
  IF source_matches<>1 OR inactive_history<1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT='Restore receipt must match the current active source after inactive history';
  END IF;
  IF NEW.id_proof_scope IS NULL THEN
    SELECT COUNT(*) INTO id_binding_matches
      FROM business_report_id_client_bindings
     WHERE tenant_id=NEW.tenant_id AND client_id=NEW.client_id
       AND BINARY id_tenant_key=BINARY NEW.id_tenant_key
       AND BINARY id_tenant_slug=BINARY NEW.identity_tenant_slug;
    IF id_binding_matches<>1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT='Restore receipt ID mapping is not exact';
    END IF;
  ELSE
    SELECT COUNT(*) INTO id_binding_present
      FROM business_report_id_client_bindings
     WHERE tenant_id=NEW.tenant_id AND client_id=NEW.client_id;
    SELECT COUNT(*) INTO id_binding_matches
      FROM business_report_id_client_bindings
     WHERE tenant_id=NEW.tenant_id AND client_id=NEW.client_id
       AND BINARY id_tenant_key=BINARY NEW.id_projection_tenant_key
       AND BINARY id_tenant_slug=BINARY NEW.identity_tenant_slug;
    IF id_binding_present<>id_binding_matches THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT='Restore receipt ID mapping is not exact';
    END IF;
    SELECT COUNT(*) INTO provider_matches
      FROM tenants
     WHERE id=NEW.tenant_id AND BINARY slug=BINARY NEW.id_provider_tenant_slug;
    IF provider_matches<>1
       OR (BINARY NEW.id_provider_tenant_slug<>BINARY '8west' AND NOT EXISTS (
         SELECT 1 FROM suite_managed_providers provider
          WHERE provider.tenant_id=NEW.tenant_id
            AND BINARY provider.provider_slug=BINARY NEW.id_provider_tenant_slug
            AND provider.issuer_tenant_id=NEW.id_issuer_suite_tenant_id)) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT='Restore receipt provider or issuer is not exact';
    END IF;
    SELECT COUNT(*) INTO anchor_matches
      FROM suite_customer_sync_events anchor_event
      JOIN suite_customer_sync_events before_anchor
        ON before_anchor.tenant_id=anchor_event.tenant_id
       AND before_anchor.binding_id=anchor_event.binding_id
       AND before_anchor.source_version=anchor_event.source_version-1
       AND before_anchor.status='inactive'
     WHERE anchor_event.tenant_id=NEW.tenant_id AND anchor_event.binding_id=NEW.source_binding_id
       AND BINARY anchor_event.event_id=BINARY NEW.id_restore_anchor_event_id
       AND anchor_event.source_version=NEW.id_restore_anchor_source_version
       AND anchor_event.status='active';
    SELECT COUNT(*) INTO later_inactive
      FROM suite_customer_sync_events
     WHERE tenant_id=NEW.tenant_id AND binding_id=NEW.source_binding_id
       AND source_version>NEW.id_restore_anchor_source_version AND status='inactive';
    IF anchor_matches<>1 OR later_inactive<>0
       OR NEW.id_restore_anchor_source_version>NEW.source_version
       OR (NEW.id_lifecycle_action='restored'
           AND NOT (NEW.id_restore_anchor_source_version<=>NEW.source_version
             AND NEW.id_restore_anchor_receipt_id<=>NEW.id_customer_receipt_id
             AND NEW.id_restore_anchor_event_id<=>NEW.source_event_id
             AND NEW.id_restore_anchor_transition_id<=>NEW.id_lifecycle_transition_id
             AND NEW.id_restore_anchor_evidence_sha256<=>NEW.id_lifecycle_evidence_sha256))
       OR (NEW.id_lifecycle_action='observed_active'
           AND COALESCE(NEW.id_restore_anchor_source_version>=NEW.source_version
             OR NEW.id_restore_anchor_transition_id>=NEW.id_lifecycle_transition_id, TRUE)) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT='Restore receipt anchor does not match local history';
    END IF;
  END IF;

  IF NEW.portal_owner_receipt_id IS NOT NULL THEN
    SELECT COUNT(*) INTO portal_owner_matches
      FROM managed_customer_lifecycle_receipts owner_receipt
     WHERE owner_receipt.id=NEW.portal_owner_receipt_id
       AND owner_receipt.tenant_id=NEW.tenant_id
       AND owner_receipt.client_id=NEW.client_id
       AND owner_receipt.source_binding_id=NEW.source_binding_id
       AND BINARY owner_receipt.customer_id=BINARY NEW.customer_id
       AND owner_receipt.portal_was_active=1
       AND owner_receipt.portal_binding_id=NEW.portal_binding_id;
    IF portal_owner_matches<>1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT='Restore portal ownership evidence is not exact';
    END IF;
  END IF;
  IF NEW.portal_binding_id IS NULL THEN
    SELECT COUNT(*) INTO portal_state_matches
      FROM customer_portal_bindings
     WHERE tenant_id=NEW.tenant_id AND client_id=NEW.client_id;
    IF portal_state_matches<>0 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT='Restore portal absence is not exact';
    END IF;
  ELSE
    SELECT COUNT(*),MAX(SHA2(CAST(before_event.snapshot_json AS CHAR),256))
      INTO portal_state_matches,expected_portal_hash
      FROM customer_portal_bindings portal
      JOIN customer_portal_binding_events before_event
        ON before_event.id=NEW.portal_before_event_id
       AND before_event.tenant_id=portal.tenant_id
       AND before_event.client_id=portal.client_id
       AND before_event.binding_id=portal.id
     WHERE portal.tenant_id=NEW.tenant_id AND portal.client_id=NEW.client_id
       AND portal.id=NEW.portal_binding_id
       AND BINARY portal.identity_tenant_slug=BINARY NEW.identity_tenant_slug;
    IF portal_state_matches<>1
       OR BINARY expected_portal_hash<>BINARY NEW.portal_state_sha256 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT='Restore portal state evidence is not exact';
    END IF;
    IF NEW.portal_restored=1 THEN
      SELECT COUNT(*) INTO portal_state_matches
        FROM customer_portal_bindings portal
        JOIN customer_portal_binding_events active_event
          ON active_event.id=NEW.portal_active_event_id
         AND active_event.tenant_id=portal.tenant_id
         AND active_event.client_id=portal.client_id
         AND active_event.binding_id=portal.id
       WHERE portal.tenant_id=NEW.tenant_id AND portal.client_id=NEW.client_id
         AND portal.id=NEW.portal_binding_id AND portal.status='active'
         AND active_event.event_kind='enabled'
         AND active_event.from_status='disabled' AND active_event.to_status='active'
         AND active_event.actor_user_id=NEW.actor_user_id
         AND active_event.id=(SELECT MAX(latest.id)
                                FROM customer_portal_binding_events latest
                               WHERE latest.tenant_id=portal.tenant_id
                                 AND latest.client_id=portal.client_id
                                 AND latest.binding_id=portal.id)
         AND EXISTS (
           SELECT 1 FROM managed_customer_lifecycle_receipts owner_receipt
            WHERE owner_receipt.id=NEW.portal_owner_receipt_id
              AND owner_receipt.portal_state_event_id=NEW.portal_before_event_id
              AND BINARY owner_receipt.portal_state_sha256=BINARY NEW.portal_state_sha256
         );
    ELSE
      SELECT COUNT(*) INTO portal_state_matches
        FROM customer_portal_bindings portal
       WHERE portal.tenant_id=NEW.tenant_id AND portal.client_id=NEW.client_id
         AND portal.id=NEW.portal_binding_id AND portal.status='disabled'
         AND NEW.portal_before_event_id=(SELECT MAX(latest.id)
                                          FROM customer_portal_binding_events latest
                                         WHERE latest.tenant_id=portal.tenant_id
                                           AND latest.client_id=portal.client_id
                                           AND latest.binding_id=portal.id);
      IF portal_state_matches=0 THEN
        SELECT COUNT(*) INTO portal_state_matches
          FROM customer_portal_bindings portal
         WHERE portal.tenant_id=NEW.tenant_id AND portal.client_id=NEW.client_id
           AND portal.id=NEW.portal_binding_id AND portal.status='active'
           AND NEW.portal_owner_receipt_id IS NULL AND NEW.portal_active_event_id IS NULL
           AND NEW.portal_before_event_id=(SELECT MAX(latest.id)
                                            FROM customer_portal_binding_events latest
                                           WHERE latest.tenant_id=portal.tenant_id
                                             AND latest.client_id=portal.client_id
                                             AND latest.binding_id=portal.id)
           AND NOT EXISTS (
             SELECT 1 FROM managed_customer_lifecycle_receipts owner_receipt
              WHERE owner_receipt.tenant_id=NEW.tenant_id
                AND owner_receipt.client_id=NEW.client_id
                AND owner_receipt.source_binding_id=NEW.source_binding_id
                AND BINARY owner_receipt.customer_id=BINARY NEW.customer_id
                AND owner_receipt.portal_was_active=1
                AND owner_receipt.portal_binding_id=portal.id
                AND owner_receipt.portal_state_event_id=NEW.portal_before_event_id
                AND BINARY owner_receipt.portal_state_sha256=BINARY NEW.portal_state_sha256
           );
      END IF;
    END IF;
    IF portal_state_matches<>1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT='Restore portal transition or preserved hold is not exact';
    END IF;
  END IF;

  IF NEW.schedule_owner_receipt_id IS NOT NULL THEN
    SELECT COUNT(*) INTO schedule_owner_matches
      FROM managed_customer_lifecycle_receipts owner_receipt
     WHERE owner_receipt.id=NEW.schedule_owner_receipt_id
       AND owner_receipt.tenant_id=NEW.tenant_id
       AND owner_receipt.client_id=NEW.client_id
       AND owner_receipt.source_binding_id=NEW.source_binding_id
       AND BINARY owner_receipt.customer_id=BINARY NEW.customer_id
       AND owner_receipt.schedule_was_active=1
       AND BINARY owner_receipt.schedule_key=BINARY NEW.schedule_key;
    IF schedule_owner_matches<>1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT='Restore schedule ownership evidence is not exact';
    END IF;
  END IF;
  IF NEW.schedule_before_version_id IS NULL THEN
    SELECT COUNT(*) INTO schedule_state_matches
      FROM business_report_schedule_versions
     WHERE tenant_id=NEW.tenant_id
       AND BINARY schedule_key=BINARY NEW.schedule_key;
    IF schedule_state_matches<>0 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT='Restore schedule absence is not exact';
    END IF;
  ELSE
    SELECT COUNT(*),MAX(SHA2(CONCAT(
             'safeharbor-managed-customer-schedule-state-v1','\n',schedule.id,
             '\n',schedule.tenant_id,'\n',schedule.schedule_key,'\n',schedule.version_no,
             '\n',schedule.definition_version_id,'\n',schedule.client_id,
             '\n',schedule.recipient_email,'\n',schedule.schedule_timezone,
             '\n',schedule.delivery_weekday,'\n',schedule.delivery_local_time,
             '\n',schedule.canary,'\n',schedule.status,'\n',schedule.created_by_user_id,
             '\n',schedule.reason
           ),256))
      INTO schedule_state_matches,expected_schedule_hash
      FROM business_report_schedule_versions schedule
     WHERE schedule.tenant_id=NEW.tenant_id AND schedule.id=NEW.schedule_before_version_id
       AND schedule.client_id=NEW.client_id
       AND BINARY schedule.schedule_key=BINARY NEW.schedule_key
       AND schedule.status='disabled';
    IF schedule_state_matches=0 AND NEW.schedule_restored=0 THEN
      SELECT COUNT(*),MAX(SHA2(CONCAT(
               'safeharbor-managed-customer-schedule-state-v1','\n',schedule.id,
               '\n',schedule.tenant_id,'\n',schedule.schedule_key,'\n',schedule.version_no,
               '\n',schedule.definition_version_id,'\n',schedule.client_id,
               '\n',schedule.recipient_email,'\n',schedule.schedule_timezone,
               '\n',schedule.delivery_weekday,'\n',schedule.delivery_local_time,
               '\n',schedule.canary,'\n',schedule.status,'\n',schedule.created_by_user_id,
               '\n',schedule.reason
             ),256))
        INTO schedule_state_matches,expected_schedule_hash
        FROM business_report_schedule_versions schedule
       WHERE schedule.tenant_id=NEW.tenant_id AND schedule.id=NEW.schedule_before_version_id
         AND schedule.client_id=NEW.client_id
         AND BINARY schedule.schedule_key=BINARY NEW.schedule_key
         AND schedule.status='active';
    END IF;
    IF schedule_state_matches<>1
       OR BINARY expected_schedule_hash<>BINARY NEW.schedule_state_sha256 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT='Restore schedule state evidence is not exact';
    END IF;
    IF NEW.schedule_restored=1 THEN
      SELECT COUNT(*) INTO schedule_state_matches
        FROM business_report_schedule_versions before_schedule
        JOIN business_report_schedule_versions prepared
          ON prepared.tenant_id=before_schedule.tenant_id
         AND prepared.id=NEW.schedule_prepared_version_id
         AND BINARY prepared.schedule_key=BINARY before_schedule.schedule_key
         AND prepared.version_no=before_schedule.version_no+1
         AND prepared.definition_version_id=before_schedule.definition_version_id
         AND prepared.client_id=before_schedule.client_id
         AND prepared.schedule_timezone=before_schedule.schedule_timezone
         AND prepared.delivery_weekday=before_schedule.delivery_weekday
         AND prepared.delivery_local_time=before_schedule.delivery_local_time
         AND prepared.canary=before_schedule.canary AND prepared.status='disabled'
         AND prepared.created_by_user_id=NEW.actor_user_id
        JOIN business_report_id_client_contact_snapshots contact
          ON contact.tenant_id=prepared.tenant_id
         AND contact.id=NEW.contact_snapshot_id
         AND contact.schedule_version_id=prepared.id
         AND contact.client_id=NEW.client_id
         AND BINARY contact.id_tenant_key=BINARY NEW.id_tenant_key
         AND contact.contact_version=NEW.contact_version
         AND SHA2(contact.recipient_email,256)=NEW.recipient_sha256
         AND contact.response_generated_at=NEW.id_response_generated_at
         AND BINARY contact.request_nonce_sha256=BINARY NEW.id_request_nonce_sha256
         AND BINARY contact.response_sha256=BINARY NEW.id_response_sha256
         AND contact.created_by_user_id=NEW.actor_user_id
        JOIN business_report_schedule_versions active_schedule
          ON active_schedule.tenant_id=prepared.tenant_id
         AND active_schedule.id=NEW.schedule_active_version_id
         AND BINARY active_schedule.schedule_key=BINARY prepared.schedule_key
         AND active_schedule.version_no=prepared.version_no+1
         AND active_schedule.definition_version_id=prepared.definition_version_id
         AND active_schedule.client_id=prepared.client_id
         AND BINARY active_schedule.recipient_email=BINARY prepared.recipient_email
         AND active_schedule.schedule_timezone=prepared.schedule_timezone
         AND active_schedule.delivery_weekday=prepared.delivery_weekday
         AND active_schedule.delivery_local_time=prepared.delivery_local_time
         AND active_schedule.canary=prepared.canary AND active_schedule.status='active'
         AND active_schedule.created_by_user_id=NEW.actor_user_id
       WHERE before_schedule.tenant_id=NEW.tenant_id
         AND before_schedule.id=NEW.schedule_before_version_id
         AND before_schedule.status='disabled'
         AND active_schedule.version_no=(SELECT MAX(latest.version_no)
                                           FROM business_report_schedule_versions latest
                                          WHERE latest.tenant_id=NEW.tenant_id
                                            AND BINARY latest.schedule_key=BINARY NEW.schedule_key)
         AND EXISTS (
           SELECT 1 FROM managed_customer_lifecycle_receipts owner_receipt
            WHERE owner_receipt.id=NEW.schedule_owner_receipt_id
              AND owner_receipt.schedule_state_version_id=NEW.schedule_before_version_id
              AND BINARY owner_receipt.schedule_state_sha256=BINARY NEW.schedule_state_sha256
         );
    ELSE
      SELECT COUNT(*) INTO schedule_state_matches
        FROM business_report_schedule_versions schedule
       WHERE schedule.tenant_id=NEW.tenant_id
         AND schedule.id=NEW.schedule_before_version_id
         AND schedule.status='disabled'
         AND schedule.version_no=(SELECT MAX(latest.version_no)
                                    FROM business_report_schedule_versions latest
                                   WHERE latest.tenant_id=NEW.tenant_id
                                     AND BINARY latest.schedule_key=BINARY NEW.schedule_key);
      IF schedule_state_matches=0 THEN
        SELECT COUNT(*) INTO schedule_state_matches
          FROM business_report_schedule_versions schedule
         WHERE schedule.tenant_id=NEW.tenant_id
           AND schedule.id=NEW.schedule_before_version_id
           AND schedule.client_id=NEW.client_id
           AND BINARY schedule.schedule_key=BINARY NEW.schedule_key
           AND schedule.status='active'
           AND NEW.schedule_owner_receipt_id IS NULL AND NEW.schedule_prepared_version_id IS NULL
           AND NEW.contact_snapshot_id IS NULL AND NEW.schedule_active_version_id IS NULL
           AND schedule.version_no=(SELECT MAX(latest.version_no)
                                      FROM business_report_schedule_versions latest
                                     WHERE latest.tenant_id=NEW.tenant_id
                                       AND BINARY latest.schedule_key=BINARY NEW.schedule_key)
           AND NOT EXISTS (
             SELECT 1 FROM managed_customer_lifecycle_receipts owner_receipt
              WHERE owner_receipt.tenant_id=NEW.tenant_id
                AND owner_receipt.client_id=NEW.client_id
                AND owner_receipt.source_binding_id=NEW.source_binding_id
                AND BINARY owner_receipt.customer_id=BINARY NEW.customer_id
                AND owner_receipt.schedule_was_active=1
                AND BINARY owner_receipt.schedule_key=BINARY NEW.schedule_key
                AND owner_receipt.schedule_state_version_id=NEW.schedule_before_version_id
                AND BINARY owner_receipt.schedule_state_sha256=BINARY NEW.schedule_state_sha256
           );
      END IF;
    END IF;
    IF schedule_state_matches<>1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT='Restore schedule transition or preserved hold is not exact';
    END IF;
  END IF;

  IF NEW.id_proof_scope IS NULL THEN
    SET expected_evidence_hash=SHA2(CONCAT(
      'safeharbor-managed-customer-lifecycle-restore-v1','\n',NEW.tenant_id,
      '\n',NEW.client_id,'\n',NEW.source_binding_id,'\n',NEW.customer_id,
      '\n',NEW.source_event_receipt_id,'\n',NEW.source_event_id,'\n',NEW.source_version,
      '\n',NEW.source_request_sha256,'\n',NEW.id_customer_receipt_id,
      '\n',NEW.id_customer_status,'\n',NEW.id_lifecycle_version,
      '\n',NEW.id_lifecycle_transition_id,'\n',NEW.id_lifecycle_action,
      '\n',NEW.id_lifecycle_evidence_sha256,'\n',NEW.id_identity_tenant_status,
      '\n',NEW.id_oauth_session_version,'\n',NEW.id_lifecycle_owned,
      '\n',NEW.id_tenant_key,'\n',NEW.identity_tenant_slug,'\n',NEW.contact_version,
      '\n',NEW.recipient_sha256,'\n',CAST(NEW.id_response_generated_at AS CHAR),
      '\n',NEW.id_request_nonce_sha256,'\n',NEW.id_response_sha256,
      '\n',COALESCE(CAST(NEW.portal_owner_receipt_id AS CHAR),'-'),
      '\n',COALESCE(CAST(NEW.portal_binding_id AS CHAR),'-'),
      '\n',COALESCE(CAST(NEW.portal_before_event_id AS CHAR),'-'),
      '\n',COALESCE(CAST(NEW.portal_active_event_id AS CHAR),'-'),
      '\n',NEW.portal_restored,'\n',COALESCE(NEW.portal_state_sha256,'-'),
      '\n',COALESCE(CAST(NEW.schedule_owner_receipt_id AS CHAR),'-'),
      '\n',NEW.schedule_key,
      '\n',COALESCE(CAST(NEW.schedule_before_version_id AS CHAR),'-'),
      '\n',COALESCE(CAST(NEW.schedule_prepared_version_id AS CHAR),'-'),
      '\n',COALESCE(CAST(NEW.contact_snapshot_id AS CHAR),'-'),
      '\n',COALESCE(CAST(NEW.schedule_active_version_id AS CHAR),'-'),
      '\n',NEW.schedule_restored,'\n',COALESCE(NEW.schedule_state_sha256,'-'),
      '\n',NEW.actor_user_id
    ),256);
  ELSE
    SET expected_evidence_hash=SHA2(CONCAT(
      'safeharbor-managed-customer-lifecycle-identity-restore-v1','\n',NEW.tenant_id,
      '\n',NEW.client_id,'\n',NEW.source_binding_id,'\n',NEW.customer_id,
      '\n',NEW.source_event_receipt_id,'\n',NEW.source_event_id,'\n',NEW.source_version,
      '\n',NEW.source_request_sha256,'\n',NEW.id_proof_scope,
      '\n',NEW.id_customer_receipt_id,'\n',NEW.id_initial_receipt_id,
      '\n',NEW.id_customer_status,'\n',NEW.id_lifecycle_version,
      '\n',NEW.id_lifecycle_transition_id,'\n',NEW.id_lifecycle_action,
      '\n',NEW.id_lifecycle_evidence_sha256,'\n',NEW.id_identity_tenant_status,
      '\n',NEW.id_oauth_session_version,'\n',NEW.id_lifecycle_owned,
      '\n',NEW.id_projection_tenant_key,'\n',NEW.identity_tenant_slug,
      '\n',NEW.id_provider_tenant_slug,'\n',NEW.id_issuer_suite_tenant_id,
      '\n',NEW.id_projection_binding_sha256,
      '\n',NEW.id_restore_anchor_source_version,'\n',NEW.id_restore_anchor_receipt_id,
      '\n',NEW.id_restore_anchor_event_id,'\n',NEW.id_restore_anchor_transition_id,
      '\n',NEW.id_restore_anchor_evidence_sha256,
      '\n',CAST(NEW.id_response_generated_at AS CHAR),
      '\n',NEW.id_request_nonce_sha256,'\n',NEW.id_response_sha256,
      '\n',COALESCE(CAST(NEW.portal_owner_receipt_id AS CHAR),'-'),
      '\n',COALESCE(CAST(NEW.portal_binding_id AS CHAR),'-'),
      '\n',COALESCE(CAST(NEW.portal_before_event_id AS CHAR),'-'),
      '\n',COALESCE(CAST(NEW.portal_active_event_id AS CHAR),'-'),
      '\n',NEW.portal_restored,'\n',COALESCE(NEW.portal_state_sha256,'-'),
      '\n',NEW.schedule_key,
      '\n',COALESCE(CAST(NEW.schedule_before_version_id AS CHAR),'-'),
      '\n',NEW.schedule_restored,'\n',COALESCE(NEW.schedule_state_sha256,'-'),
      '\n',NEW.actor_user_id
    ),256);
  END IF;
  IF expected_evidence_hash IS NULL OR NEW.evidence_sha256 IS NULL
     OR BINARY expected_evidence_hash<>BINARY NEW.evidence_sha256 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT='Restore evidence digest does not match exact facts';
  END IF;
  SET NEW.created_at=UTC_TIMESTAMP();
END$$
DELIMITER ;

-- The swap guard stays until v3, both immutability guards, every column and the CHECK are exact.
SET @mc_identity_postflight_sql = IF(
  (SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema = DATABASE()
      AND event_object_table = 'managed_customer_lifecycle_restore_receipts'
      AND trigger_name = 'trg_mc_restore_before_insert'
      AND action_timing = 'BEFORE' AND event_manipulation = 'INSERT' AND action_orientation = 'ROW'
      AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
          = 'eb49a8cb57ace84034414bac400f9690cc81a87968293624b859f9d8b488ff93') = 1
  AND (SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema = DATABASE()
      AND event_object_table = 'managed_customer_lifecycle_restore_receipts'
      AND action_timing = 'BEFORE'
      AND ((trigger_name = 'trg_mc_restore_no_update' AND event_manipulation = 'UPDATE')
        OR (trigger_name = 'trg_mc_restore_no_delete' AND event_manipulation = 'DELETE'))
      AND action_statement LIKE '%safeharbor-managed-customer-lifecycle-restore-immutable-v1%') = 2
  AND (SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema = DATABASE()
      AND event_object_table = 'managed_customer_lifecycle_restore_receipts'
      AND trigger_name = 'trg_mc_restore_identity_swap_insert'
      AND BINARY action_statement = BINARY @mc_identity_swap_body) = 1
  AND (SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema = DATABASE()
      AND event_object_table = 'managed_customer_lifecycle_restore_receipts') = 4
  AND (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'managed_customer_lifecycle_restore_receipts') = 53
  AND (SELECT COUNT(*) FROM information_schema.table_constraints scope_constraint
      JOIN information_schema.check_constraints scope_check
        ON scope_check.constraint_schema = scope_constraint.constraint_schema
       AND scope_check.constraint_name = scope_constraint.constraint_name
    WHERE scope_constraint.constraint_schema = DATABASE()
      AND scope_constraint.table_name = 'managed_customer_lifecycle_restore_receipts'
      AND scope_constraint.constraint_name = 'ck_mc_restore_proof_scope'
      AND scope_constraint.enforced = 'YES'
      AND SHA2(REGEXP_REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(scope_check.check_clause),
            '`', ''), CHAR(92), ''), '_ascii', ''), '_utf8mb4', ''), '[[:space:]()]', ''), 256)
          = '5e63b58a0d459dfea99ca34b3c0bc6a4d5a67091b00d543ac148971538fcbc25') = 1,
  'DO 0',
  'SELECT * FROM information_schema.migration_034_managed_customer_identity_restore_postflight_failed'
);
PREPARE mc_identity_postflight FROM @mc_identity_postflight_sql;
EXECUTE mc_identity_postflight;
DEALLOCATE PREPARE mc_identity_postflight;

DROP TRIGGER trg_mc_restore_identity_swap_insert;

SET @mc_identity_final_sql = IF(
  (SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema = DATABASE()
      AND event_object_table = 'managed_customer_lifecycle_restore_receipts') = 3
  AND (SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema = DATABASE() AND trigger_name = 'trg_mc_restore_before_insert'
      AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
          = 'eb49a8cb57ace84034414bac400f9690cc81a87968293624b859f9d8b488ff93') = 1,
  'DO 0',
  'SELECT * FROM information_schema.migration_034_managed_customer_identity_restore_final_failed'
);
PREPARE mc_identity_final FROM @mc_identity_final_sql;
EXECUTE mc_identity_final;
DEALLOCATE PREPARE mc_identity_final;

SELECT
  (SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema = DATABASE()
      AND event_object_table = 'managed_customer_lifecycle_restore_receipts') AS restore_trigger_count,
  (SELECT COUNT(*) FROM managed_customer_lifecycle_restore_receipts) AS restore_receipt_count;
