-- 017_id_report_contact_evidence.sql — immutable 8 West ID contact bindings
-- and report-schedule snapshot evidence. Additive after migrations 013 and 016.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

DROP PROCEDURE IF EXISTS safeharbor_migrate_id_report_contact_evidence;
DELIMITER $$
CREATE PROCEDURE safeharbor_migrate_id_report_contact_evidence()
BEGIN
  CREATE TABLE IF NOT EXISTS business_report_id_tenant_bindings (
    tenant_id          INT UNSIGNED NOT NULL,
    id_tenant_key      VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    id_tenant_slug     VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_by_user_id INT UNSIGNED NOT NULL,
    reason             VARCHAR(500) NOT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id),
    UNIQUE KEY uq_business_report_id_binding_key (id_tenant_key),
    UNIQUE KEY uq_business_report_id_binding_tenant_key (tenant_id, id_tenant_key),
    KEY ix_business_report_id_binding_actor (tenant_id, created_by_user_id, created_at),
    CONSTRAINT fk_business_report_id_binding_tenant
      FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT fk_business_report_id_binding_actor
      FOREIGN KEY (tenant_id, created_by_user_id) REFERENCES users (tenant_id, id),
    CONSTRAINT ck_business_report_id_binding_key
      CHECK (id_tenant_key REGEXP '^ewid-t[1-9][0-9]{0,9}$'
             AND CAST(SUBSTRING(id_tenant_key, 7) AS UNSIGNED) BETWEEN 1 AND 4294967295),
    CONSTRAINT ck_business_report_id_binding_slug
      CHECK (id_tenant_slug REGEXP '^[a-z0-9][a-z0-9-]{0,63}$')
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

  CREATE TABLE IF NOT EXISTS business_report_id_contact_snapshots (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id             INT UNSIGNED NOT NULL,
    schedule_version_id   INT UNSIGNED NOT NULL,
    id_tenant_key         VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    contact_version       BIGINT UNSIGNED NOT NULL,
    recipient_email       VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    response_generated_at DATETIME NOT NULL,
    request_nonce_sha256  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    response_sha256       CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_by_user_id    INT UNSIGNED NOT NULL,
    reason                VARCHAR(500) NOT NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_business_report_id_snapshot_tenant_id (tenant_id, id),
    UNIQUE KEY uq_business_report_id_snapshot_schedule (tenant_id, schedule_version_id),
    KEY ix_business_report_id_snapshot_version (tenant_id, id_tenant_key, contact_version, id),
    KEY ix_business_report_id_snapshot_actor (tenant_id, created_by_user_id, created_at),
    CONSTRAINT fk_business_report_id_snapshot_tenant
      FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT fk_business_report_id_snapshot_schedule
      FOREIGN KEY (tenant_id, schedule_version_id)
      REFERENCES business_report_schedule_versions (tenant_id, id),
    CONSTRAINT fk_business_report_id_snapshot_binding
      FOREIGN KEY (tenant_id, id_tenant_key)
      REFERENCES business_report_id_tenant_bindings (tenant_id, id_tenant_key),
    CONSTRAINT fk_business_report_id_snapshot_actor
      FOREIGN KEY (tenant_id, created_by_user_id) REFERENCES users (tenant_id, id),
    CONSTRAINT ck_business_report_id_snapshot_version CHECK (contact_version >= 1),
    CONSTRAINT ck_business_report_id_snapshot_recipient
      CHECK (recipient_email = LOWER(TRIM(recipient_email))),
    CONSTRAINT ck_business_report_id_snapshot_nonce_hash
      CHECK (request_nonce_sha256 REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT ck_business_report_id_snapshot_response_hash
      CHECK (response_sha256 REGEXP '^[0-9a-f]{64}$')
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

  IF (SELECT GROUP_CONCAT(
              CONCAT(column_name, ':', column_type, ':', is_nullable)
              ORDER BY ordinal_position SEPARATOR '|')
        FROM information_schema.columns
       WHERE table_schema = DATABASE()
         AND table_name = 'business_report_id_tenant_bindings') <>
       'tenant_id:int unsigned:NO|id_tenant_key:varchar(32):NO|id_tenant_slug:varchar(64):NO|created_by_user_id:int unsigned:NO|reason:varchar(500):NO|created_at:datetime:NO'
     OR (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name = 'business_report_id_tenant_bindings'
            AND column_default IS NULL
            AND extra = ''
            AND column_name IN (
              'tenant_id', 'id_tenant_key', 'id_tenant_slug',
              'created_by_user_id', 'reason'
            )) <> 5
     OR (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name = 'business_report_id_tenant_bindings'
            AND column_name = 'created_at'
            AND UPPER(CAST(column_default AS CHAR)) = 'CURRENT_TIMESTAMP'
            AND UPPER(extra) = 'DEFAULT_GENERATED') <> 1
     OR (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name = 'business_report_id_tenant_bindings'
            AND column_name IN ('id_tenant_key', 'id_tenant_slug')
            AND character_set_name = 'ascii'
            AND collation_name = 'ascii_bin') <> 2
     OR (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name = 'business_report_id_tenant_bindings'
            AND column_name = 'reason'
            AND character_set_name = 'utf8mb4') <> 1
     OR (SELECT GROUP_CONCAT(
              CONCAT(index_name, ':', non_unique, ':', seq_in_index, ':',
                     column_name, ':', COALESCE(sub_part, 0), ':',
                     index_type, ':', collation)
              ORDER BY BINARY index_name, seq_in_index SEPARATOR '|')
           FROM information_schema.statistics
          WHERE table_schema = DATABASE()
             AND table_name = 'business_report_id_tenant_bindings') <>
       'PRIMARY:0:1:tenant_id:0:BTREE:A|ix_business_report_id_binding_actor:1:1:tenant_id:0:BTREE:A|ix_business_report_id_binding_actor:1:2:created_by_user_id:0:BTREE:A|ix_business_report_id_binding_actor:1:3:created_at:0:BTREE:A|uq_business_report_id_binding_key:0:1:id_tenant_key:0:BTREE:A|uq_business_report_id_binding_tenant_key:0:1:tenant_id:0:BTREE:A|uq_business_report_id_binding_tenant_key:0:2:id_tenant_key:0:BTREE:A'
     OR (SELECT COUNT(*)
           FROM information_schema.statistics
          WHERE table_schema = DATABASE()
            AND table_name = 'business_report_id_tenant_bindings'
            AND (column_name IS NULL OR expression IS NOT NULL
                 OR is_visible <> 'YES' OR index_comment <> '')) <> 0
     OR (SELECT GROUP_CONCAT(
              CONCAT(k.constraint_name, ':', k.ordinal_position, ':',
                     k.column_name, ':', k.referenced_table_name, ':',
                     k.referenced_column_name, ':', r.update_rule, ':', r.delete_rule)
              ORDER BY BINARY k.constraint_name, k.ordinal_position SEPARATOR '|')
           FROM information_schema.key_column_usage k
           JOIN information_schema.referential_constraints r
             ON r.constraint_schema = k.constraint_schema
            AND r.constraint_name = k.constraint_name
          WHERE k.constraint_schema = DATABASE()
             AND k.table_name = 'business_report_id_tenant_bindings'
             AND k.referenced_table_name IS NOT NULL) <>
       'fk_business_report_id_binding_actor:1:tenant_id:users:tenant_id:NO ACTION:NO ACTION|fk_business_report_id_binding_actor:2:created_by_user_id:users:id:NO ACTION:NO ACTION|fk_business_report_id_binding_tenant:1:tenant_id:tenants:id:NO ACTION:NO ACTION'
     OR (SELECT COUNT(*)
           FROM information_schema.referential_constraints
          WHERE constraint_schema = DATABASE()
            AND table_name = 'business_report_id_tenant_bindings'
            AND match_option <> 'NONE') <> 0
     OR (SELECT GROUP_CONCAT(
              CONCAT(tc.constraint_name, ':', tc.enforced, ':',
                     LOWER(REGEXP_REPLACE(
                       REPLACE(REPLACE(REPLACE(REPLACE(
                         cc.check_clause, CHAR(96), ''), CHAR(92), ''),
                         '_utf8mb4', ''), '_ascii', ''),
                       '[[:space:]]+',
                       ''
                     )))
              ORDER BY BINARY tc.constraint_name SEPARATOR '|')
           FROM information_schema.table_constraints tc
           JOIN information_schema.check_constraints cc
             ON cc.constraint_schema = tc.constraint_schema
            AND cc.constraint_name = tc.constraint_name
          WHERE tc.constraint_schema = DATABASE()
            AND tc.table_name = 'business_report_id_tenant_bindings'
            AND tc.constraint_type = 'CHECK') <>
       'ck_business_report_id_binding_key:YES:(regexp_like(id_tenant_key,''^ewid-t[1-9][0-9]{0,9}$'')and(cast(substr(id_tenant_key,7)asunsigned)between1and4294967295))|ck_business_report_id_binding_slug:YES:regexp_like(id_tenant_slug,''^[a-z0-9][a-z0-9-]{0,63}$'')'
     OR (SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE()
            AND table_name = 'business_report_id_tenant_bindings'
            AND table_type = 'BASE TABLE'
            AND engine = 'InnoDB'
            AND table_collation LIKE 'utf8mb4\_%') <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'business_report_id_tenant_bindings has unexpected shape';
  END IF;

  IF (SELECT GROUP_CONCAT(
              CONCAT(column_name, ':', column_type, ':', is_nullable)
              ORDER BY ordinal_position SEPARATOR '|')
        FROM information_schema.columns
       WHERE table_schema = DATABASE()
         AND table_name = 'business_report_id_contact_snapshots') <>
       'id:bigint unsigned:NO|tenant_id:int unsigned:NO|schedule_version_id:int unsigned:NO|id_tenant_key:varchar(32):NO|contact_version:bigint unsigned:NO|recipient_email:varchar(190):NO|response_generated_at:datetime:NO|request_nonce_sha256:char(64):NO|response_sha256:char(64):NO|created_by_user_id:int unsigned:NO|reason:varchar(500):NO|created_at:datetime:NO'
     OR (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name = 'business_report_id_contact_snapshots'
            AND column_default IS NULL
            AND extra = ''
            AND column_name IN (
              'tenant_id', 'schedule_version_id', 'id_tenant_key',
              'contact_version', 'recipient_email', 'response_generated_at',
              'request_nonce_sha256', 'response_sha256',
              'created_by_user_id', 'reason'
            )) <> 10
     OR (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name = 'business_report_id_contact_snapshots'
            AND column_name = 'id'
            AND column_default IS NULL
            AND extra = 'auto_increment') <> 1
     OR (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name = 'business_report_id_contact_snapshots'
            AND column_name = 'created_at'
            AND UPPER(CAST(column_default AS CHAR)) = 'CURRENT_TIMESTAMP'
            AND UPPER(extra) = 'DEFAULT_GENERATED') <> 1
     OR (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name = 'business_report_id_contact_snapshots'
            AND column_name IN (
              'id_tenant_key', 'recipient_email',
              'request_nonce_sha256', 'response_sha256'
            )
            AND character_set_name = 'ascii'
            AND collation_name = 'ascii_bin') <> 4
     OR (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name = 'business_report_id_contact_snapshots'
            AND column_name = 'reason'
            AND character_set_name = 'utf8mb4') <> 1
     OR (SELECT GROUP_CONCAT(
              CONCAT(index_name, ':', non_unique, ':', seq_in_index, ':',
                     column_name, ':', COALESCE(sub_part, 0), ':',
                     index_type, ':', collation)
              ORDER BY BINARY index_name, seq_in_index SEPARATOR '|')
           FROM information_schema.statistics
          WHERE table_schema = DATABASE()
             AND table_name = 'business_report_id_contact_snapshots') <>
       'PRIMARY:0:1:id:0:BTREE:A|ix_business_report_id_snapshot_actor:1:1:tenant_id:0:BTREE:A|ix_business_report_id_snapshot_actor:1:2:created_by_user_id:0:BTREE:A|ix_business_report_id_snapshot_actor:1:3:created_at:0:BTREE:A|ix_business_report_id_snapshot_version:1:1:tenant_id:0:BTREE:A|ix_business_report_id_snapshot_version:1:2:id_tenant_key:0:BTREE:A|ix_business_report_id_snapshot_version:1:3:contact_version:0:BTREE:A|ix_business_report_id_snapshot_version:1:4:id:0:BTREE:A|uq_business_report_id_snapshot_schedule:0:1:tenant_id:0:BTREE:A|uq_business_report_id_snapshot_schedule:0:2:schedule_version_id:0:BTREE:A|uq_business_report_id_snapshot_tenant_id:0:1:tenant_id:0:BTREE:A|uq_business_report_id_snapshot_tenant_id:0:2:id:0:BTREE:A'
     OR (SELECT COUNT(*)
           FROM information_schema.statistics
          WHERE table_schema = DATABASE()
            AND table_name = 'business_report_id_contact_snapshots'
            AND (column_name IS NULL OR expression IS NOT NULL
                 OR is_visible <> 'YES' OR index_comment <> '')) <> 0
     OR (SELECT GROUP_CONCAT(
              CONCAT(k.constraint_name, ':', k.ordinal_position, ':',
                     k.column_name, ':', k.referenced_table_name, ':',
                     k.referenced_column_name, ':', r.update_rule, ':', r.delete_rule)
              ORDER BY BINARY k.constraint_name, k.ordinal_position SEPARATOR '|')
           FROM information_schema.key_column_usage k
           JOIN information_schema.referential_constraints r
             ON r.constraint_schema = k.constraint_schema
            AND r.constraint_name = k.constraint_name
          WHERE k.constraint_schema = DATABASE()
             AND k.table_name = 'business_report_id_contact_snapshots'
             AND k.referenced_table_name IS NOT NULL) <>
       'fk_business_report_id_snapshot_actor:1:tenant_id:users:tenant_id:NO ACTION:NO ACTION|fk_business_report_id_snapshot_actor:2:created_by_user_id:users:id:NO ACTION:NO ACTION|fk_business_report_id_snapshot_binding:1:tenant_id:business_report_id_tenant_bindings:tenant_id:NO ACTION:NO ACTION|fk_business_report_id_snapshot_binding:2:id_tenant_key:business_report_id_tenant_bindings:id_tenant_key:NO ACTION:NO ACTION|fk_business_report_id_snapshot_schedule:1:tenant_id:business_report_schedule_versions:tenant_id:NO ACTION:NO ACTION|fk_business_report_id_snapshot_schedule:2:schedule_version_id:business_report_schedule_versions:id:NO ACTION:NO ACTION|fk_business_report_id_snapshot_tenant:1:tenant_id:tenants:id:NO ACTION:NO ACTION'
     OR (SELECT COUNT(*)
           FROM information_schema.referential_constraints
          WHERE constraint_schema = DATABASE()
            AND table_name = 'business_report_id_contact_snapshots'
            AND match_option <> 'NONE') <> 0
     OR (SELECT GROUP_CONCAT(
              CONCAT(tc.constraint_name, ':', tc.enforced, ':',
                     LOWER(REGEXP_REPLACE(
                       REPLACE(REPLACE(REPLACE(REPLACE(
                         cc.check_clause, CHAR(96), ''), CHAR(92), ''),
                         '_utf8mb4', ''), '_ascii', ''),
                       '[[:space:]]+',
                       ''
                     )))
              ORDER BY BINARY tc.constraint_name SEPARATOR '|')
           FROM information_schema.table_constraints tc
           JOIN information_schema.check_constraints cc
             ON cc.constraint_schema = tc.constraint_schema
            AND cc.constraint_name = tc.constraint_name
          WHERE tc.constraint_schema = DATABASE()
            AND tc.table_name = 'business_report_id_contact_snapshots'
            AND tc.constraint_type = 'CHECK') <>
       'ck_business_report_id_snapshot_nonce_hash:YES:regexp_like(request_nonce_sha256,''^[0-9a-f]{64}$'')|ck_business_report_id_snapshot_recipient:YES:(recipient_email=lower(trim(recipient_email)))|ck_business_report_id_snapshot_response_hash:YES:regexp_like(response_sha256,''^[0-9a-f]{64}$'')|ck_business_report_id_snapshot_version:YES:(contact_version>=1)'
     OR (SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE()
            AND table_name = 'business_report_id_contact_snapshots'
            AND table_type = 'BASE TABLE'
            AND engine = 'InnoDB'
            AND table_collation LIKE 'utf8mb4\_%') <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'business_report_id_contact_snapshots has unexpected shape';
  END IF;
END$$
DELIMITER ;

CALL safeharbor_migrate_id_report_contact_evidence();
DROP PROCEDURE IF EXISTS safeharbor_migrate_id_report_contact_evidence;

-- Refuse to replace immutable guards unless this connection can create a
-- trigger on both protected tables. Permanent guards are still intact here.
DROP TRIGGER IF EXISTS trg_business_report_id_binding_privilege_preflight;
DROP TRIGGER IF EXISTS trg_business_report_id_snapshot_privilege_preflight;
CREATE TRIGGER trg_business_report_id_binding_privilege_preflight
BEFORE INSERT ON business_report_id_tenant_bindings
FOR EACH ROW
SET @business_report_id_contact_trigger_preflight = 1;
CREATE TRIGGER trg_business_report_id_snapshot_privilege_preflight
BEFORE INSERT ON business_report_id_contact_snapshots
FOR EACH ROW
SET @business_report_id_contact_trigger_preflight = 1;
DROP TRIGGER trg_business_report_id_binding_privilege_preflight;
DROP TRIGGER trg_business_report_id_snapshot_privilege_preflight;

-- Install fail-closed swap guards before replacing any permanent trigger.
-- IF NOT EXISTS makes an interrupted replay recoverable: an exact retry fills
-- any missing swap guard while preserving guards left by the failed run.
DELIMITER $$
CREATE TRIGGER IF NOT EXISTS trg_business_report_id_binding_swap_insert
BEFORE INSERT ON business_report_id_tenant_bindings
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Migration 017 report-contact replay is in progress'$$

CREATE TRIGGER IF NOT EXISTS trg_business_report_id_binding_swap_update
BEFORE UPDATE ON business_report_id_tenant_bindings
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Migration 017 report-contact replay is in progress'$$

CREATE TRIGGER IF NOT EXISTS trg_business_report_id_binding_swap_delete
BEFORE DELETE ON business_report_id_tenant_bindings
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Migration 017 report-contact replay is in progress'$$

CREATE TRIGGER IF NOT EXISTS trg_business_report_id_snapshot_swap_insert
BEFORE INSERT ON business_report_id_contact_snapshots
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Migration 017 report-contact replay is in progress'$$

CREATE TRIGGER IF NOT EXISTS trg_business_report_id_snapshot_swap_update
BEFORE UPDATE ON business_report_id_contact_snapshots
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Migration 017 report-contact replay is in progress'$$

CREATE TRIGGER IF NOT EXISTS trg_business_report_id_snapshot_swap_delete
BEFORE DELETE ON business_report_id_contact_snapshots
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Migration 017 report-contact replay is in progress'$$
DELIMITER ;

DROP PROCEDURE IF EXISTS safeharbor_assert_id_report_contact_swap_guards;
DELIMITER $$
CREATE PROCEDURE safeharbor_assert_id_report_contact_swap_guards()
BEGIN
  IF (SELECT COUNT(*)
        FROM information_schema.triggers
       WHERE trigger_schema = DATABASE()
         AND action_timing = 'BEFORE'
         AND LOWER(action_statement) LIKE '%signal sqlstate%'
         AND LOWER(action_statement) LIKE '%migration 017 report-contact replay is in progress%'
         AND (
           (trigger_name = 'trg_business_report_id_binding_swap_insert'
             AND event_object_table = 'business_report_id_tenant_bindings'
             AND event_manipulation = 'INSERT')
           OR (trigger_name = 'trg_business_report_id_binding_swap_update'
             AND event_object_table = 'business_report_id_tenant_bindings'
             AND event_manipulation = 'UPDATE')
           OR (trigger_name = 'trg_business_report_id_binding_swap_delete'
             AND event_object_table = 'business_report_id_tenant_bindings'
             AND event_manipulation = 'DELETE')
           OR (trigger_name = 'trg_business_report_id_snapshot_swap_insert'
             AND event_object_table = 'business_report_id_contact_snapshots'
             AND event_manipulation = 'INSERT')
           OR (trigger_name = 'trg_business_report_id_snapshot_swap_update'
             AND event_object_table = 'business_report_id_contact_snapshots'
             AND event_manipulation = 'UPDATE')
           OR (trigger_name = 'trg_business_report_id_snapshot_swap_delete'
             AND event_object_table = 'business_report_id_contact_snapshots'
             AND event_manipulation = 'DELETE')
         )) <> 6 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Migration 017 fail-closed swap guards are incomplete';
  END IF;
END$$
DELIMITER ;

CALL safeharbor_assert_id_report_contact_swap_guards();
DROP PROCEDURE safeharbor_assert_id_report_contact_swap_guards;

DROP TRIGGER IF EXISTS trg_business_report_id_binding_before_insert;
DROP TRIGGER IF EXISTS trg_business_report_id_binding_no_update;
DROP TRIGGER IF EXISTS trg_business_report_id_binding_no_delete;
DROP TRIGGER IF EXISTS trg_business_report_id_snapshot_before_insert;
DROP TRIGGER IF EXISTS trg_business_report_id_snapshot_no_update;
DROP TRIGGER IF EXISTS trg_business_report_id_snapshot_no_delete;

DELIMITER $$
CREATE TRIGGER trg_business_report_id_binding_before_insert
BEFORE INSERT ON business_report_id_tenant_bindings
FOR EACH ROW
BEGIN
  DECLARE binding_matches INT DEFAULT 0;
  SELECT COUNT(*) INTO binding_matches
    FROM tenants t
    JOIN users u ON u.tenant_id = t.id
   WHERE t.id = NEW.tenant_id
     AND BINARY t.slug = BINARY NEW.id_tenant_slug
     AND u.id = NEW.created_by_user_id
     AND u.is_active = 1
     AND u.role IN ('owner','admin');
  IF binding_matches <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = '8 West ID report binding must match its tenant and active actor';
  END IF;
END$$

CREATE TRIGGER trg_business_report_id_binding_no_update
BEFORE UPDATE ON business_report_id_tenant_bindings
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = '8 West ID report bindings are immutable'$$

CREATE TRIGGER trg_business_report_id_binding_no_delete
BEFORE DELETE ON business_report_id_tenant_bindings
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = '8 West ID report bindings are immutable'$$

CREATE TRIGGER trg_business_report_id_snapshot_before_insert
BEFORE INSERT ON business_report_id_contact_snapshots
FOR EACH ROW
BEGIN
  DECLARE locked_binding_tenant INT UNSIGNED DEFAULT NULL;
  DECLARE snapshot_matches INT DEFAULT 0;
  DECLARE latest_contact_version BIGINT UNSIGNED DEFAULT 0;
  DECLARE conflicting_same_version INT DEFAULT 0;

  -- Every snapshot writer, including direct DML through the runtime account,
  -- serializes on the immutable binding row before reading contact history.
  -- This makes rollback and same-version conflict checks current under two
  -- concurrent transactions instead of relying on snapshot reads alone.
  SELECT tenant_id INTO locked_binding_tenant
    FROM business_report_id_tenant_bindings
   WHERE tenant_id = NEW.tenant_id
     AND BINARY id_tenant_key = BINARY NEW.id_tenant_key
   FOR UPDATE;
  IF locked_binding_tenant IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = '8 West ID contact snapshot requires its exact tenant binding';
  END IF;

  SELECT COUNT(*) INTO snapshot_matches
    FROM business_report_schedule_versions s
    JOIN business_report_id_tenant_bindings b
      ON b.tenant_id = s.tenant_id
     AND BINARY b.id_tenant_key = BINARY NEW.id_tenant_key
    JOIN users u ON u.tenant_id = s.tenant_id
   WHERE s.tenant_id = NEW.tenant_id
     AND s.id = NEW.schedule_version_id
     AND s.status = 'disabled'
     AND BINARY s.recipient_email = BINARY NEW.recipient_email
     AND s.created_by_user_id = NEW.created_by_user_id
     AND u.id = NEW.created_by_user_id
     AND u.is_active = 1
     AND u.role IN ('owner','admin');
  IF snapshot_matches <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = '8 West ID contact snapshot must match its disabled schedule and active actor';
  END IF;

  SELECT COALESCE(MAX(contact_version), 0)
    INTO latest_contact_version
    FROM business_report_id_contact_snapshots
   WHERE tenant_id = NEW.tenant_id
     AND BINARY id_tenant_key = BINARY NEW.id_tenant_key;
  IF NEW.contact_version < latest_contact_version THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = '8 West ID contact version cannot move backward';
  END IF;

  SELECT COUNT(*) INTO conflicting_same_version
    FROM business_report_id_contact_snapshots
   WHERE tenant_id = NEW.tenant_id
     AND BINARY id_tenant_key = BINARY NEW.id_tenant_key
     AND contact_version = NEW.contact_version
     AND BINARY recipient_email <> BINARY NEW.recipient_email;
  IF conflicting_same_version > 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = '8 West ID contact version cannot name different recipients';
  END IF;
END$$

CREATE TRIGGER trg_business_report_id_snapshot_no_update
BEFORE UPDATE ON business_report_id_contact_snapshots
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = '8 West ID contact snapshots are immutable'$$

CREATE TRIGGER trg_business_report_id_snapshot_no_delete
BEFORE DELETE ON business_report_id_contact_snapshots
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = '8 West ID contact snapshots are immutable'$$
DELIMITER ;

-- Verify all permanent guards while the swap guards still block every write.
DROP PROCEDURE IF EXISTS safeharbor_assert_id_report_contact_permanent_guards;
DELIMITER $$
CREATE PROCEDURE safeharbor_assert_id_report_contact_permanent_guards(
  IN expected_swap_guards INT
)
BEGIN
  IF (SELECT COUNT(*)
        FROM information_schema.triggers
       WHERE trigger_schema = DATABASE()
         AND action_timing = 'BEFORE'
         AND (
           (trigger_name = 'trg_business_report_id_binding_before_insert'
             AND event_object_table = 'business_report_id_tenant_bindings'
             AND event_manipulation = 'INSERT')
           OR (trigger_name = 'trg_business_report_id_binding_no_update'
             AND event_object_table = 'business_report_id_tenant_bindings'
             AND event_manipulation = 'UPDATE')
           OR (trigger_name = 'trg_business_report_id_binding_no_delete'
             AND event_object_table = 'business_report_id_tenant_bindings'
             AND event_manipulation = 'DELETE')
           OR (trigger_name = 'trg_business_report_id_snapshot_before_insert'
             AND event_object_table = 'business_report_id_contact_snapshots'
             AND event_manipulation = 'INSERT')
           OR (trigger_name = 'trg_business_report_id_snapshot_no_update'
             AND event_object_table = 'business_report_id_contact_snapshots'
             AND event_manipulation = 'UPDATE')
           OR (trigger_name = 'trg_business_report_id_snapshot_no_delete'
             AND event_object_table = 'business_report_id_contact_snapshots'
             AND event_manipulation = 'DELETE')
         )) <> 6 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Migration 017 permanent report-contact guards are incomplete';
  END IF;

  IF (SELECT COUNT(*)
        FROM information_schema.triggers
       WHERE trigger_schema = DATABASE()
         AND trigger_name IN (
           'trg_business_report_id_binding_privilege_preflight',
           'trg_business_report_id_snapshot_privilege_preflight'
         )) <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Migration 017 privilege preflight cleanup is incomplete';
  END IF;

  IF (SELECT COUNT(*)
        FROM information_schema.triggers
       WHERE trigger_schema = DATABASE()
         AND trigger_name IN (
           'trg_business_report_id_binding_swap_insert',
           'trg_business_report_id_binding_swap_update',
           'trg_business_report_id_binding_swap_delete',
           'trg_business_report_id_snapshot_swap_insert',
           'trg_business_report_id_snapshot_swap_update',
           'trg_business_report_id_snapshot_swap_delete'
         )) <> expected_swap_guards THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Migration 017 swap-guard postflight is incomplete';
  END IF;
END$$
DELIMITER ;

CALL safeharbor_assert_id_report_contact_permanent_guards(6);

DROP TRIGGER trg_business_report_id_binding_swap_insert;
DROP TRIGGER trg_business_report_id_binding_swap_update;
DROP TRIGGER trg_business_report_id_binding_swap_delete;
DROP TRIGGER trg_business_report_id_snapshot_swap_insert;
DROP TRIGGER trg_business_report_id_snapshot_swap_update;
DROP TRIGGER trg_business_report_id_snapshot_swap_delete;

CALL safeharbor_assert_id_report_contact_permanent_guards(0);
DROP PROCEDURE safeharbor_assert_id_report_contact_permanent_guards;

SELECT
  (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name IN (
        'business_report_id_tenant_bindings',
        'business_report_id_contact_snapshots'
      )) AS report_contact_tables,
  (SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema = DATABASE()
      AND trigger_name IN (
        'trg_business_report_id_binding_before_insert',
        'trg_business_report_id_binding_no_update',
        'trg_business_report_id_binding_no_delete',
        'trg_business_report_id_snapshot_before_insert',
        'trg_business_report_id_snapshot_no_update',
        'trg_business_report_id_snapshot_no_delete'
      )) AS report_contact_triggers,
  (SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema = DATABASE()
      AND trigger_name LIKE 'trg_business_report_id_%_swap_%') AS report_contact_swap_triggers,
  (SELECT COUNT(*) FROM business_report_id_tenant_bindings) AS binding_rows,
  (SELECT COUNT(*) FROM business_report_id_contact_snapshots) AS snapshot_rows;
