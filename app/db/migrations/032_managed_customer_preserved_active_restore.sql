-- 032_managed_customer_preserved_active_restore.sql
-- Forward replacement of one guard only: the restore-receipt insert validator
-- trg_mc_restore_before_insert (marker ...-restore-receipt-v2). It adds a
-- preserved-active branch for the portal and for the report schedule. An
-- already-active surface with no current containment owner may be recorded as
-- not restored (restored=0, no owner receipt, no transition, prepared, contact
-- or active IDs), and only against its exact latest audit event or schedule
-- version and state hash. Every other check is unchanged from 024.
--
-- Tables, columns, keys, checks and the no-update/no-delete immutability
-- guards are not touched and stay in force throughout. A swap guard refuses
-- every restore-receipt insert from before the old validator is dropped until
-- the new one passes its postflight, so an interrupted run stays closed and an
-- exact replay converges. A DML-only identity fails before any change.
--
-- Apply with the existing deploy path (database first, then files):
--   bash deploy/deploy.sh --migrate app/db/migrations/032_managed_customer_preserved_active_restore.sql
-- Never edit 024, and never replay 024 after this migration: 024 would put the
-- stricter v1 validator back. That is fail-closed, and replaying 032 restores v2.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Accepted starting states: the completed 024 guards (v1 validator) or a
-- completed 032 (v2), each with no swap guard; or an interrupted 032, where the
-- swap guard exists and the validator is v1, v2 or absent. Anything else,
-- including a missing validator without the swap guard, refuses unchanged.
SET @mc_preserve_table_ok=(
  SELECT COUNT(*)=1 FROM information_schema.tables
   WHERE table_schema=DATABASE()
     AND table_name='managed_customer_lifecycle_restore_receipts'
     AND engine='InnoDB'
);
SET @mc_preserve_installed_ok=(
  SELECT COUNT(*)=0 FROM information_schema.table_constraints
   WHERE constraint_schema=DATABASE()
     AND table_name='managed_customer_lifecycle_restore_receipts'
     AND constraint_name='ck_mc_restore_install_lock'
);
SET @mc_preserve_immutable_ok=(
  SELECT COUNT(*)=2 FROM information_schema.triggers
   WHERE trigger_schema=DATABASE()
     AND event_object_table='managed_customer_lifecycle_restore_receipts'
     AND action_timing='BEFORE'
     AND ((trigger_name='trg_mc_restore_no_update' AND event_manipulation='UPDATE')
       OR (trigger_name='trg_mc_restore_no_delete' AND event_manipulation='DELETE'))
     AND action_statement LIKE '%safeharbor-managed-customer-lifecycle-restore-immutable-v1%'
);
SET @mc_preserve_validator_count=(
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema=DATABASE()
     AND event_object_table='managed_customer_lifecycle_restore_receipts'
     AND trigger_name='trg_mc_restore_before_insert'
     AND action_timing='BEFORE' AND event_manipulation='INSERT'
     AND (action_statement LIKE '%safeharbor-managed-customer-lifecycle-restore-receipt-v1%'
       OR action_statement LIKE '%safeharbor-managed-customer-lifecycle-restore-receipt-v2%')
     AND action_statement LIKE '%suite_customer_sync_events%'
     AND action_statement LIKE '%business_report_id_client_contact_snapshots%'
);
SET @mc_preserve_swap_count=(
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema=DATABASE()
     AND event_object_table='managed_customer_lifecycle_restore_receipts'
     AND trigger_name='trg_mc_restore_preserve_swap_insert'
     AND action_timing='BEFORE' AND event_manipulation='INSERT'
     AND action_statement LIKE '%Managed-customer preserved-active restoration migration is incomplete%'
);
SET @mc_preserve_trigger_total=(
  SELECT COUNT(*) FROM information_schema.triggers
   WHERE trigger_schema=DATABASE()
     AND event_object_table='managed_customer_lifecycle_restore_receipts'
);
SET @mc_preserve_preflight_sql=IF(
  @mc_preserve_table_ok=1 AND @mc_preserve_installed_ok=1
  AND @mc_preserve_immutable_ok=1
  AND ((@mc_preserve_swap_count=0 AND @mc_preserve_validator_count=1
        AND @mc_preserve_trigger_total=3)
    OR (@mc_preserve_swap_count=1
        AND @mc_preserve_trigger_total=3+@mc_preserve_validator_count)),
  'DO 0',
  'SELECT * FROM information_schema.migration_032_managed_customer_preserved_restore_preflight_failed'
);
PREPARE mc_preserve_preflight FROM @mc_preserve_preflight_sql;
EXECUTE mc_preserve_preflight;
DEALLOCATE PREPARE mc_preserve_preflight;

DROP TRIGGER IF EXISTS trg_mc_restore_preserve_privilege_preflight;
CREATE TRIGGER trg_mc_restore_preserve_privilege_preflight
BEFORE INSERT ON managed_customer_lifecycle_restore_receipts
FOR EACH ROW SET @mc_restore_preserve_trigger_privilege_preflight=1;
DROP TRIGGER trg_mc_restore_preserve_privilege_preflight;

DELIMITER $$
CREATE TRIGGER IF NOT EXISTS trg_mc_restore_preserve_swap_insert
BEFORE INSERT ON managed_customer_lifecycle_restore_receipts
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT='Managed-customer preserved-active restoration migration is incomplete';
END$$
DELIMITER ;

SET @mc_preserve_swap_ok=(
  SELECT COUNT(*)=1 FROM information_schema.triggers
   WHERE trigger_schema=DATABASE()
     AND event_object_table='managed_customer_lifecycle_restore_receipts'
     AND trigger_name='trg_mc_restore_preserve_swap_insert'
     AND action_timing='BEFORE' AND event_manipulation='INSERT'
     AND action_statement LIKE '%Managed-customer preserved-active restoration migration is incomplete%'
);
SET @mc_preserve_swap_sql=IF(
  @mc_preserve_swap_ok=1,
  'DO 0',
  'SELECT * FROM information_schema.migration_032_managed_customer_preserved_restore_swap_failed'
);
PREPARE mc_preserve_swap_statement FROM @mc_preserve_swap_sql;
EXECUTE mc_preserve_swap_statement;
DEALLOCATE PREPARE mc_preserve_swap_statement;

DROP TRIGGER IF EXISTS trg_mc_restore_before_insert;

DELIMITER $$
CREATE TRIGGER trg_mc_restore_before_insert
BEFORE INSERT ON managed_customer_lifecycle_restore_receipts
FOR EACH ROW
BEGIN
  DECLARE guard_version VARCHAR(64) DEFAULT 'safeharbor-managed-customer-lifecycle-restore-receipt-v2';
  DECLARE actor_matches INT DEFAULT 0;
  DECLARE source_matches INT DEFAULT 0;
  DECLARE inactive_history INT DEFAULT 0;
  DECLARE id_binding_matches INT DEFAULT 0;
  DECLARE portal_owner_matches INT DEFAULT 0;
  DECLARE portal_state_matches INT DEFAULT 0;
  DECLARE schedule_owner_matches INT DEFAULT 0;
  DECLARE schedule_state_matches INT DEFAULT 0;
  DECLARE expected_portal_hash CHAR(64) DEFAULT NULL;
  DECLARE expected_schedule_hash CHAR(64) DEFAULT NULL;
  DECLARE expected_evidence_hash CHAR(64) DEFAULT NULL;

  IF NEW.id_customer_status<>'active' OR NEW.id_lifecycle_version<>1
     OR NEW.id_lifecycle_action<>'restored'
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
  SELECT COUNT(*) INTO id_binding_matches
    FROM business_report_id_client_bindings
   WHERE tenant_id=NEW.tenant_id AND client_id=NEW.client_id
     AND BINARY id_tenant_key=BINARY NEW.id_tenant_key
     AND BINARY id_tenant_slug=BINARY NEW.identity_tenant_slug;
  IF id_binding_matches<>1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT='Restore receipt ID mapping is not exact';
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
  IF BINARY expected_evidence_hash<>BINARY NEW.evidence_sha256 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT='Restore evidence digest does not match exact facts';
  END IF;
  SET NEW.created_at=UTC_TIMESTAMP();
END$$
DELIMITER ;

-- The swap guard stays until the new validator and both immutability guards
-- are verified in place.
SET @mc_preserve_postflight_ok=(
  SELECT COUNT(*)=4 FROM information_schema.triggers
   WHERE trigger_schema=DATABASE()
     AND event_object_table='managed_customer_lifecycle_restore_receipts'
     AND action_timing='BEFORE'
     AND (
       (trigger_name='trg_mc_restore_before_insert' AND event_manipulation='INSERT'
        AND action_statement LIKE '%safeharbor-managed-customer-lifecycle-restore-receipt-v2%'
        AND action_statement LIKE '%suite_customer_sync_events%'
        AND action_statement LIKE '%business_report_id_client_contact_snapshots%'
        AND action_statement LIKE '%NEW.portal_owner_receipt_id IS NULL AND NEW.portal_active_event_id IS NULL%'
        AND action_statement LIKE '%NEW.contact_snapshot_id IS NULL AND NEW.schedule_active_version_id IS NULL%')
       OR (trigger_name='trg_mc_restore_no_update' AND event_manipulation='UPDATE'
        AND action_statement LIKE '%safeharbor-managed-customer-lifecycle-restore-immutable-v1%')
       OR (trigger_name='trg_mc_restore_no_delete' AND event_manipulation='DELETE'
        AND action_statement LIKE '%safeharbor-managed-customer-lifecycle-restore-immutable-v1%')
       OR (trigger_name='trg_mc_restore_preserve_swap_insert' AND event_manipulation='INSERT'
        AND action_statement LIKE '%Managed-customer preserved-active restoration migration is incomplete%')
     )
) AND (
  SELECT COUNT(*)=4 FROM information_schema.triggers
   WHERE trigger_schema=DATABASE()
     AND event_object_table='managed_customer_lifecycle_restore_receipts'
);
SET @mc_preserve_postflight_sql=IF(
  @mc_preserve_postflight_ok=1,
  'DO 0',
  'SELECT * FROM information_schema.migration_032_managed_customer_preserved_restore_postflight_failed'
);
PREPARE mc_preserve_postflight FROM @mc_preserve_postflight_sql;
EXECUTE mc_preserve_postflight;
DEALLOCATE PREPARE mc_preserve_postflight;

DROP TRIGGER trg_mc_restore_preserve_swap_insert;

SET @mc_preserve_final_ok=(
  SELECT COUNT(*)=3 FROM information_schema.triggers
   WHERE trigger_schema=DATABASE()
     AND event_object_table='managed_customer_lifecycle_restore_receipts'
     AND action_timing='BEFORE'
     AND (
       (trigger_name='trg_mc_restore_before_insert' AND event_manipulation='INSERT'
        AND action_statement LIKE '%safeharbor-managed-customer-lifecycle-restore-receipt-v2%')
       OR (trigger_name='trg_mc_restore_no_update' AND event_manipulation='UPDATE'
        AND action_statement LIKE '%safeharbor-managed-customer-lifecycle-restore-immutable-v1%')
       OR (trigger_name='trg_mc_restore_no_delete' AND event_manipulation='DELETE'
        AND action_statement LIKE '%safeharbor-managed-customer-lifecycle-restore-immutable-v1%')
     )
) AND (
  SELECT COUNT(*)=3 FROM information_schema.triggers
   WHERE trigger_schema=DATABASE()
     AND event_object_table='managed_customer_lifecycle_restore_receipts'
);
SET @mc_preserve_final_sql=IF(
  @mc_preserve_final_ok=1,
  'DO 0',
  'SELECT * FROM information_schema.migration_032_managed_customer_preserved_restore_final_failed'
);
PREPARE mc_preserve_final_statement FROM @mc_preserve_final_sql;
EXECUTE mc_preserve_final_statement;
DEALLOCATE PREPARE mc_preserve_final_statement;

SELECT
  (SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema=DATABASE()
      AND event_object_table='managed_customer_lifecycle_restore_receipts') AS restore_trigger_count,
  (SELECT COUNT(*) FROM managed_customer_lifecycle_restore_receipts) AS restore_receipt_count;
