-- 019_client_report_contact_evidence.sql — immutable, client-scoped 8 West ID
-- contact bindings and report-schedule evidence. Additive after 013 and 017.

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET SESSION group_concat_max_len = 8192;

DROP PROCEDURE IF EXISTS safeharbor_migrate_client_report_contacts;
DELIMITER $$
CREATE PROCEDURE safeharbor_migrate_client_report_contacts()
BEGIN
  CREATE TABLE IF NOT EXISTS business_report_id_client_bindings (
    tenant_id          INT UNSIGNED NOT NULL,
    client_id          INT UNSIGNED NOT NULL,
    id_tenant_key      VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    id_tenant_slug     VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_by_user_id INT UNSIGNED NOT NULL,
    reason             VARCHAR(500) NOT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, client_id),
    UNIQUE KEY uq_br_id_client_binding_key (id_tenant_key),
    UNIQUE KEY uq_br_id_client_binding_scope (tenant_id, client_id, id_tenant_key),
    KEY ix_br_id_client_binding_actor (tenant_id, created_by_user_id, created_at),
    CONSTRAINT fk_br_id_client_binding_tenant
      FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT fk_br_id_client_binding_client
      FOREIGN KEY (tenant_id, client_id) REFERENCES clients (tenant_id, id),
    CONSTRAINT fk_br_id_client_binding_actor
      FOREIGN KEY (tenant_id, created_by_user_id) REFERENCES users (tenant_id, id),
    CONSTRAINT ck_br_id_client_binding_key
      CHECK (id_tenant_key REGEXP '^ewid-t[1-9][0-9]{0,9}$'
             AND CAST(SUBSTRING(id_tenant_key, 7) AS UNSIGNED) BETWEEN 1 AND 4294967295),
    CONSTRAINT ck_br_id_client_binding_slug
      CHECK (id_tenant_slug REGEXP '^[a-z0-9][a-z0-9-]{0,63}$')
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

  CREATE TABLE IF NOT EXISTS business_report_id_client_contact_snapshots (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id             INT UNSIGNED NOT NULL,
    client_id             INT UNSIGNED NOT NULL,
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
    UNIQUE KEY uq_br_id_client_snapshot_tenant_id (tenant_id, id),
    UNIQUE KEY uq_br_id_client_snapshot_schedule (tenant_id, schedule_version_id),
    KEY ix_br_id_client_snapshot_version
      (tenant_id, client_id, id_tenant_key, contact_version, id),
    KEY ix_br_id_client_snapshot_actor (tenant_id, created_by_user_id, created_at),
    CONSTRAINT fk_br_id_client_snapshot_tenant
      FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT fk_br_id_client_snapshot_client
      FOREIGN KEY (tenant_id, client_id) REFERENCES clients (tenant_id, id),
    CONSTRAINT fk_br_id_client_snapshot_schedule
      FOREIGN KEY (tenant_id, schedule_version_id)
      REFERENCES business_report_schedule_versions (tenant_id, id),
    CONSTRAINT fk_br_id_client_snapshot_binding
      FOREIGN KEY (tenant_id, client_id, id_tenant_key)
      REFERENCES business_report_id_client_bindings (tenant_id, client_id, id_tenant_key),
    CONSTRAINT fk_br_id_client_snapshot_actor
      FOREIGN KEY (tenant_id, created_by_user_id) REFERENCES users (tenant_id, id),
    CONSTRAINT ck_br_id_client_snapshot_version CHECK (contact_version >= 1),
    CONSTRAINT ck_br_id_client_snapshot_recipient
      CHECK (recipient_email = LOWER(TRIM(recipient_email))),
    CONSTRAINT ck_br_id_client_snapshot_nonce_hash
      CHECK (request_nonce_sha256 REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT ck_br_id_client_snapshot_response_hash
      CHECK (response_sha256 REGEXP '^[0-9a-f]{64}$')
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

  CREATE TABLE IF NOT EXISTS business_report_contact_scope_bindings (
    tenant_id          INT UNSIGNED NOT NULL,
    schedule_key       VARCHAR(64) NOT NULL,
    contact_scope      ENUM('MANUAL','TENANT','CLIENT') NOT NULL,
    created_by_user_id INT UNSIGNED NOT NULL,
    reason             VARCHAR(500) NOT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, schedule_key),
    KEY ix_business_report_contact_scope_actor
      (tenant_id, created_by_user_id, created_at),
    CONSTRAINT fk_business_report_contact_scope_tenant
      FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT fk_business_report_contact_scope_actor
      FOREIGN KEY (tenant_id, created_by_user_id) REFERENCES users (tenant_id, id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

  IF (SELECT COUNT(*) FROM information_schema.triggers
       WHERE trigger_schema = DATABASE()
         AND trigger_name = 'trg_business_report_contact_scope_before_insert') = 0 THEN
    INSERT INTO business_report_contact_scope_bindings
      (tenant_id, schedule_key, contact_scope, created_by_user_id, reason, created_at)
    SELECT first_version.tenant_id,
           first_version.schedule_key,
           CASE
             WHEN EXISTS (
               SELECT 1
                 FROM business_report_id_contact_snapshots tenant_evidence
                 JOIN business_report_schedule_versions tenant_schedule
                   ON tenant_schedule.tenant_id = tenant_evidence.tenant_id
                  AND tenant_schedule.id = tenant_evidence.schedule_version_id
                WHERE tenant_schedule.tenant_id = first_version.tenant_id
                  AND tenant_schedule.schedule_key = first_version.schedule_key
                  AND tenant_schedule.id = first_version.id
             ) THEN 'TENANT'
             WHEN EXISTS (
               SELECT 1
                 FROM business_report_id_client_contact_snapshots client_evidence
                 JOIN business_report_schedule_versions client_schedule
                   ON client_schedule.tenant_id = client_evidence.tenant_id
                  AND client_schedule.id = client_evidence.schedule_version_id
                WHERE client_schedule.tenant_id = first_version.tenant_id
                  AND client_schedule.schedule_key = first_version.schedule_key
                  AND client_schedule.id = first_version.id
             ) THEN 'CLIENT'
             ELSE 'MANUAL'
           END,
           first_version.created_by_user_id,
           'Migration 019 inherited existing report contact scope',
           first_version.created_at
      FROM business_report_schedule_versions first_version
      LEFT JOIN business_report_contact_scope_bindings existing_scope
        ON existing_scope.tenant_id = first_version.tenant_id
       AND existing_scope.schedule_key = first_version.schedule_key
     WHERE first_version.version_no = 1
       AND existing_scope.tenant_id IS NULL;
  END IF;

  IF EXISTS (
       SELECT 1
         FROM business_report_schedule_versions schedule_history
        WHERE EXISTS (
          SELECT 1
            FROM business_report_id_contact_snapshots tenant_evidence
            JOIN business_report_schedule_versions tenant_schedule
              ON tenant_schedule.tenant_id = tenant_evidence.tenant_id
             AND tenant_schedule.id = tenant_evidence.schedule_version_id
           WHERE tenant_schedule.tenant_id = schedule_history.tenant_id
             AND tenant_schedule.schedule_key = schedule_history.schedule_key
        )
          AND EXISTS (
          SELECT 1
            FROM business_report_id_client_contact_snapshots client_evidence
            JOIN business_report_schedule_versions client_schedule
              ON client_schedule.tenant_id = client_evidence.tenant_id
             AND client_schedule.id = client_evidence.schedule_version_id
           WHERE client_schedule.tenant_id = schedule_history.tenant_id
             AND client_schedule.schedule_key = schedule_history.schedule_key
        )
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'report schedule history has mixed contact scopes';
  END IF;

  IF EXISTS (
       SELECT 1
         FROM business_report_contact_scope_bindings scope_binding
         LEFT JOIN business_report_schedule_versions first_version
           ON first_version.tenant_id = scope_binding.tenant_id
          AND first_version.schedule_key = scope_binding.schedule_key
          AND first_version.version_no = 1
        WHERE first_version.id IS NULL
           OR scope_binding.contact_scope <> CASE
             WHEN EXISTS (
               SELECT 1
                 FROM business_report_id_contact_snapshots tenant_evidence
                 JOIN business_report_schedule_versions tenant_schedule
                   ON tenant_schedule.tenant_id = tenant_evidence.tenant_id
                  AND tenant_schedule.id = tenant_evidence.schedule_version_id
                WHERE tenant_schedule.tenant_id = scope_binding.tenant_id
                  AND tenant_schedule.schedule_key = scope_binding.schedule_key
                  AND tenant_schedule.id = first_version.id
             ) THEN 'TENANT'
             WHEN EXISTS (
               SELECT 1
                 FROM business_report_id_client_contact_snapshots client_evidence
                 JOIN business_report_schedule_versions client_schedule
                   ON client_schedule.tenant_id = client_evidence.tenant_id
                  AND client_schedule.id = client_evidence.schedule_version_id
                WHERE client_schedule.tenant_id = scope_binding.tenant_id
                  AND client_schedule.schedule_key = scope_binding.schedule_key
                  AND client_schedule.id = first_version.id
             ) THEN 'CLIENT'
             ELSE 'MANUAL'
           END
           OR (
             scope_binding.contact_scope = 'MANUAL'
             AND (
               EXISTS (
                 SELECT 1
                   FROM business_report_id_contact_snapshots tenant_evidence
                   JOIN business_report_schedule_versions tenant_schedule
                     ON tenant_schedule.tenant_id = tenant_evidence.tenant_id
                    AND tenant_schedule.id = tenant_evidence.schedule_version_id
                  WHERE tenant_schedule.tenant_id = scope_binding.tenant_id
                    AND tenant_schedule.schedule_key = scope_binding.schedule_key
               )
               OR EXISTS (
                 SELECT 1
                   FROM business_report_id_client_contact_snapshots client_evidence
                   JOIN business_report_schedule_versions client_schedule
                     ON client_schedule.tenant_id = client_evidence.tenant_id
                    AND client_schedule.id = client_evidence.schedule_version_id
                  WHERE client_schedule.tenant_id = scope_binding.tenant_id
                    AND client_schedule.schedule_key = scope_binding.schedule_key
               )
             )
           )
  ) OR EXISTS (
       SELECT 1
         FROM business_report_schedule_versions first_version
         LEFT JOIN business_report_contact_scope_bindings scope_binding
           ON scope_binding.tenant_id = first_version.tenant_id
          AND scope_binding.schedule_key = first_version.schedule_key
        WHERE first_version.version_no = 1 AND scope_binding.tenant_id IS NULL
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'report contact-scope registry does not match schedule history';
  END IF;

  IF EXISTS (
       SELECT 1
         FROM business_report_contact_scope_bindings scope_binding
         JOIN business_report_schedule_versions active_schedule
           ON active_schedule.tenant_id = scope_binding.tenant_id
          AND BINARY active_schedule.schedule_key = BINARY scope_binding.schedule_key
        WHERE scope_binding.contact_scope = 'TENANT'
          AND active_schedule.status = 'active'
          AND active_schedule.version_no = (
            SELECT MAX(current_schedule.version_no)
              FROM business_report_schedule_versions current_schedule
             WHERE current_schedule.tenant_id = active_schedule.tenant_id
               AND BINARY current_schedule.schedule_key = BINARY active_schedule.schedule_key
          )
          AND NOT EXISTS (
            SELECT 1
              FROM business_report_id_contact_snapshots evidence
              JOIN business_report_schedule_versions evidence_schedule
                ON evidence_schedule.tenant_id = evidence.tenant_id
               AND evidence_schedule.id = evidence.schedule_version_id
             WHERE evidence_schedule.tenant_id = active_schedule.tenant_id
               AND BINARY evidence_schedule.schedule_key = BINARY active_schedule.schedule_key
               AND evidence_schedule.version_no = (
                 SELECT MAX(latest_schedule.version_no)
                   FROM business_report_id_contact_snapshots latest_evidence
                   JOIN business_report_schedule_versions latest_schedule
                     ON latest_schedule.tenant_id = latest_evidence.tenant_id
                    AND latest_schedule.id = latest_evidence.schedule_version_id
                  WHERE latest_schedule.tenant_id = active_schedule.tenant_id
                    AND BINARY latest_schedule.schedule_key = BINARY active_schedule.schedule_key
               )
               AND BINARY evidence.recipient_email = BINARY active_schedule.recipient_email
          )
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'latest active tenant-ID report recipient does not match newest evidence';
  END IF;

  IF EXISTS (
       SELECT 1
         FROM business_report_contact_scope_bindings scope_binding
         JOIN business_report_schedule_versions active_schedule
           ON active_schedule.tenant_id = scope_binding.tenant_id
          AND BINARY active_schedule.schedule_key = BINARY scope_binding.schedule_key
        WHERE scope_binding.contact_scope = 'CLIENT'
          AND active_schedule.status = 'active'
          AND active_schedule.version_no = (
            SELECT MAX(current_schedule.version_no)
              FROM business_report_schedule_versions current_schedule
             WHERE current_schedule.tenant_id = active_schedule.tenant_id
               AND BINARY current_schedule.schedule_key = BINARY active_schedule.schedule_key
          )
          AND NOT EXISTS (
            SELECT 1
              FROM business_report_id_client_contact_snapshots evidence
              JOIN business_report_schedule_versions evidence_schedule
                ON evidence_schedule.tenant_id = evidence.tenant_id
               AND evidence_schedule.id = evidence.schedule_version_id
             WHERE evidence_schedule.tenant_id = active_schedule.tenant_id
               AND BINARY evidence_schedule.schedule_key = BINARY active_schedule.schedule_key
               AND evidence_schedule.version_no = (
                 SELECT MAX(latest_schedule.version_no)
                   FROM business_report_id_client_contact_snapshots latest_evidence
                   JOIN business_report_schedule_versions latest_schedule
                     ON latest_schedule.tenant_id = latest_evidence.tenant_id
                    AND latest_schedule.id = latest_evidence.schedule_version_id
                  WHERE latest_schedule.tenant_id = active_schedule.tenant_id
                    AND BINARY latest_schedule.schedule_key = BINARY active_schedule.schedule_key
               )
               AND evidence.client_id = active_schedule.client_id
               AND evidence_schedule.client_id = active_schedule.client_id
               AND BINARY evidence.recipient_email = BINARY active_schedule.recipient_email
          )
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'latest active client-ID report recipient and client do not match newest evidence';
  END IF;

  IF (SELECT GROUP_CONCAT(
              CONCAT(column_name, ':', column_type, ':', is_nullable)
              ORDER BY ordinal_position SEPARATOR '|')
        FROM information_schema.columns
       WHERE table_schema = DATABASE()
         AND table_name = 'business_report_id_client_bindings') <>
       'tenant_id:int unsigned:NO|client_id:int unsigned:NO|id_tenant_key:varchar(32):NO|id_tenant_slug:varchar(64):NO|created_by_user_id:int unsigned:NO|reason:varchar(500):NO|created_at:datetime:NO'
     OR (SELECT GROUP_CONCAT(
              CONCAT(column_name, ':', column_type, ':', is_nullable)
              ORDER BY ordinal_position SEPARATOR '|')
        FROM information_schema.columns
       WHERE table_schema = DATABASE()
         AND table_name = 'business_report_id_client_contact_snapshots') <>
       'id:bigint unsigned:NO|tenant_id:int unsigned:NO|client_id:int unsigned:NO|schedule_version_id:int unsigned:NO|id_tenant_key:varchar(32):NO|contact_version:bigint unsigned:NO|recipient_email:varchar(190):NO|response_generated_at:datetime:NO|request_nonce_sha256:char(64):NO|response_sha256:char(64):NO|created_by_user_id:int unsigned:NO|reason:varchar(500):NO|created_at:datetime:NO'
     OR (SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE()
            AND table_name IN (
              'business_report_id_client_bindings',
              'business_report_id_client_contact_snapshots'
            )
            AND table_type = 'BASE TABLE'
            AND engine = 'InnoDB'
            AND table_collation LIKE 'utf8mb4\_%') <> 2
     OR (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name IN (
              'business_report_id_client_bindings',
              'business_report_id_client_contact_snapshots'
            )
            AND column_name IN (
              'id_tenant_key', 'id_tenant_slug', 'recipient_email',
              'request_nonce_sha256', 'response_sha256'
            )
            AND character_set_name = 'ascii'
            AND collation_name = 'ascii_bin') <> 6
     OR (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name = 'business_report_id_client_bindings'
            AND column_name <> 'created_at'
            AND column_default IS NULL
            AND extra = '') <> 6
     OR (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name = 'business_report_id_client_contact_snapshots'
            AND column_name NOT IN ('id', 'created_at')
            AND column_default IS NULL
            AND extra = '') <> 11
     OR (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name IN (
              'business_report_id_client_bindings',
              'business_report_id_client_contact_snapshots'
            )
            AND column_name = 'reason'
            AND character_set_name = 'utf8mb4') <> 2
     OR (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name = 'business_report_id_client_contact_snapshots'
            AND column_name = 'id'
            AND column_default IS NULL
            AND extra = 'auto_increment') <> 1
     OR (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name IN (
              'business_report_id_client_bindings',
              'business_report_id_client_contact_snapshots'
            )
            AND column_name = 'created_at'
            AND UPPER(CAST(column_default AS CHAR)) = 'CURRENT_TIMESTAMP'
            AND UPPER(extra) = 'DEFAULT_GENERATED') <> 2 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'client report-contact tables have unexpected columns';
  END IF;

  IF (SELECT GROUP_CONCAT(
              CONCAT(table_name, ':', index_name, ':', non_unique, ':',
                     seq_in_index, ':', column_name, ':', is_visible)
              ORDER BY BINARY table_name, BINARY index_name, seq_in_index SEPARATOR '|')
        FROM information_schema.statistics
       WHERE table_schema = DATABASE()
         AND table_name IN (
           'business_report_id_client_bindings',
           'business_report_id_client_contact_snapshots'
         )) <>
       'business_report_id_client_bindings:PRIMARY:0:1:tenant_id:YES|business_report_id_client_bindings:PRIMARY:0:2:client_id:YES|business_report_id_client_bindings:ix_br_id_client_binding_actor:1:1:tenant_id:YES|business_report_id_client_bindings:ix_br_id_client_binding_actor:1:2:created_by_user_id:YES|business_report_id_client_bindings:ix_br_id_client_binding_actor:1:3:created_at:YES|business_report_id_client_bindings:uq_br_id_client_binding_key:0:1:id_tenant_key:YES|business_report_id_client_bindings:uq_br_id_client_binding_scope:0:1:tenant_id:YES|business_report_id_client_bindings:uq_br_id_client_binding_scope:0:2:client_id:YES|business_report_id_client_bindings:uq_br_id_client_binding_scope:0:3:id_tenant_key:YES|business_report_id_client_contact_snapshots:PRIMARY:0:1:id:YES|business_report_id_client_contact_snapshots:ix_br_id_client_snapshot_actor:1:1:tenant_id:YES|business_report_id_client_contact_snapshots:ix_br_id_client_snapshot_actor:1:2:created_by_user_id:YES|business_report_id_client_contact_snapshots:ix_br_id_client_snapshot_actor:1:3:created_at:YES|business_report_id_client_contact_snapshots:ix_br_id_client_snapshot_version:1:1:tenant_id:YES|business_report_id_client_contact_snapshots:ix_br_id_client_snapshot_version:1:2:client_id:YES|business_report_id_client_contact_snapshots:ix_br_id_client_snapshot_version:1:3:id_tenant_key:YES|business_report_id_client_contact_snapshots:ix_br_id_client_snapshot_version:1:4:contact_version:YES|business_report_id_client_contact_snapshots:ix_br_id_client_snapshot_version:1:5:id:YES|business_report_id_client_contact_snapshots:uq_br_id_client_snapshot_schedule:0:1:tenant_id:YES|business_report_id_client_contact_snapshots:uq_br_id_client_snapshot_schedule:0:2:schedule_version_id:YES|business_report_id_client_contact_snapshots:uq_br_id_client_snapshot_tenant_id:0:1:tenant_id:YES|business_report_id_client_contact_snapshots:uq_br_id_client_snapshot_tenant_id:0:2:id:YES'
     OR (SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema = DATABASE()
            AND table_name IN (
              'business_report_id_client_bindings',
              'business_report_id_client_contact_snapshots'
            )
            AND (column_name IS NULL OR expression IS NOT NULL
                 OR index_type <> 'BTREE' OR collation <> 'A'
                 OR index_comment <> '')) <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'client report-contact tables have unexpected indexes';
  END IF;

  IF (SELECT GROUP_CONCAT(
              CONCAT(k.constraint_name, ':', k.ordinal_position, ':',
                     k.column_name, ':', k.referenced_table_name, ':',
                     k.referenced_column_name, ':', r.update_rule, ':', r.delete_rule)
              ORDER BY BINARY k.constraint_name, k.ordinal_position SEPARATOR '|')
        FROM information_schema.key_column_usage k
        JOIN information_schema.referential_constraints r
          ON r.constraint_schema = k.constraint_schema
         AND r.constraint_name = k.constraint_name
       WHERE k.constraint_schema = DATABASE()
         AND k.table_name IN (
           'business_report_id_client_bindings',
           'business_report_id_client_contact_snapshots'
         )
         AND k.referenced_table_name IS NOT NULL) <>
       'fk_br_id_client_binding_actor:1:tenant_id:users:tenant_id:NO ACTION:NO ACTION|fk_br_id_client_binding_actor:2:created_by_user_id:users:id:NO ACTION:NO ACTION|fk_br_id_client_binding_client:1:tenant_id:clients:tenant_id:NO ACTION:NO ACTION|fk_br_id_client_binding_client:2:client_id:clients:id:NO ACTION:NO ACTION|fk_br_id_client_binding_tenant:1:tenant_id:tenants:id:NO ACTION:NO ACTION|fk_br_id_client_snapshot_actor:1:tenant_id:users:tenant_id:NO ACTION:NO ACTION|fk_br_id_client_snapshot_actor:2:created_by_user_id:users:id:NO ACTION:NO ACTION|fk_br_id_client_snapshot_binding:1:tenant_id:business_report_id_client_bindings:tenant_id:NO ACTION:NO ACTION|fk_br_id_client_snapshot_binding:2:client_id:business_report_id_client_bindings:client_id:NO ACTION:NO ACTION|fk_br_id_client_snapshot_binding:3:id_tenant_key:business_report_id_client_bindings:id_tenant_key:NO ACTION:NO ACTION|fk_br_id_client_snapshot_client:1:tenant_id:clients:tenant_id:NO ACTION:NO ACTION|fk_br_id_client_snapshot_client:2:client_id:clients:id:NO ACTION:NO ACTION|fk_br_id_client_snapshot_schedule:1:tenant_id:business_report_schedule_versions:tenant_id:NO ACTION:NO ACTION|fk_br_id_client_snapshot_schedule:2:schedule_version_id:business_report_schedule_versions:id:NO ACTION:NO ACTION|fk_br_id_client_snapshot_tenant:1:tenant_id:tenants:id:NO ACTION:NO ACTION'
     OR (SELECT COUNT(*) FROM information_schema.referential_constraints
          WHERE constraint_schema = DATABASE()
            AND table_name IN (
              'business_report_id_client_bindings',
              'business_report_id_client_contact_snapshots'
            )
            AND match_option <> 'NONE') <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'client report-contact tables have unexpected foreign keys';
  END IF;

  IF (SELECT COUNT(*)
        FROM information_schema.table_constraints tc
        JOIN information_schema.check_constraints cc
          ON cc.constraint_schema = tc.constraint_schema
         AND cc.constraint_name = tc.constraint_name
       WHERE tc.constraint_schema = DATABASE()
         AND tc.table_name IN (
           'business_report_id_client_bindings',
           'business_report_id_client_contact_snapshots'
         )
         AND tc.constraint_type = 'CHECK'
         AND tc.enforced = 'YES'
         AND CONCAT(tc.constraint_name, ':', LOWER(REGEXP_REPLACE(
               REPLACE(REPLACE(REPLACE(REPLACE(
                 cc.check_clause, CHAR(96), ''), CHAR(92), ''),
                 '_utf8mb4', ''), '_ascii', ''),
               '[[:space:]]+', ''
             ))) IN (
           'ck_br_id_client_binding_key:(regexp_like(id_tenant_key,''^ewid-t[1-9][0-9]{0,9}$'')and(cast(substr(id_tenant_key,7)asunsigned)between1and4294967295))',
           'ck_br_id_client_binding_slug:regexp_like(id_tenant_slug,''^[a-z0-9][a-z0-9-]{0,63}$'')',
           'ck_br_id_client_snapshot_nonce_hash:regexp_like(request_nonce_sha256,''^[0-9a-f]{64}$'')',
           'ck_br_id_client_snapshot_recipient:(recipient_email=lower(trim(recipient_email)))',
           'ck_br_id_client_snapshot_response_hash:regexp_like(response_sha256,''^[0-9a-f]{64}$'')',
           'ck_br_id_client_snapshot_version:(contact_version>=1)'
         )) <> 6 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'client report-contact tables have unexpected checks';
  END IF;

  IF (SELECT GROUP_CONCAT(
              CONCAT(column_name, ':', column_type, ':', is_nullable)
              ORDER BY ordinal_position SEPARATOR '|')
        FROM information_schema.columns
       WHERE table_schema = DATABASE()
         AND table_name = 'business_report_contact_scope_bindings') <>
       'tenant_id:int unsigned:NO|schedule_key:varchar(64):NO|contact_scope:enum(''MANUAL'',''TENANT'',''CLIENT''):NO|created_by_user_id:int unsigned:NO|reason:varchar(500):NO|created_at:datetime:NO'
     OR (SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE()
            AND table_name = 'business_report_contact_scope_bindings'
            AND table_type = 'BASE TABLE'
            AND engine = 'InnoDB'
            AND table_collation LIKE 'utf8mb4\_%') <> 1
     OR (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name = 'business_report_contact_scope_bindings'
            AND column_name <> 'created_at'
            AND column_default IS NULL
            AND extra = '') <> 5
     OR (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name = 'business_report_contact_scope_bindings'
            AND column_name = 'created_at'
            AND UPPER(CAST(column_default AS CHAR)) = 'CURRENT_TIMESTAMP'
            AND UPPER(extra) = 'DEFAULT_GENERATED') <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'report contact-scope table has unexpected columns';
  END IF;

  IF (SELECT GROUP_CONCAT(
              CONCAT(index_name, ':', non_unique, ':', seq_in_index, ':',
                     column_name, ':', is_visible)
              ORDER BY BINARY index_name, seq_in_index SEPARATOR '|')
        FROM information_schema.statistics
       WHERE table_schema = DATABASE()
         AND table_name = 'business_report_contact_scope_bindings') <>
       'PRIMARY:0:1:tenant_id:YES|PRIMARY:0:2:schedule_key:YES|ix_business_report_contact_scope_actor:1:1:tenant_id:YES|ix_business_report_contact_scope_actor:1:2:created_by_user_id:YES|ix_business_report_contact_scope_actor:1:3:created_at:YES'
     OR (SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema = DATABASE()
            AND table_name = 'business_report_contact_scope_bindings'
            AND (column_name IS NULL OR expression IS NOT NULL
                 OR index_type <> 'BTREE' OR collation <> 'A'
                 OR index_comment <> '')) <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'report contact-scope table has unexpected indexes';
  END IF;

  IF (SELECT GROUP_CONCAT(
              CONCAT(k.constraint_name, ':', k.ordinal_position, ':',
                     k.column_name, ':', k.referenced_table_name, ':',
                     k.referenced_column_name, ':', r.update_rule, ':', r.delete_rule)
              ORDER BY BINARY k.constraint_name, k.ordinal_position SEPARATOR '|')
        FROM information_schema.key_column_usage k
        JOIN information_schema.referential_constraints r
          ON r.constraint_schema = k.constraint_schema
         AND r.constraint_name = k.constraint_name
       WHERE k.constraint_schema = DATABASE()
         AND k.table_name = 'business_report_contact_scope_bindings'
         AND k.referenced_table_name IS NOT NULL) <>
       'fk_business_report_contact_scope_actor:1:tenant_id:users:tenant_id:NO ACTION:NO ACTION|fk_business_report_contact_scope_actor:2:created_by_user_id:users:id:NO ACTION:NO ACTION|fk_business_report_contact_scope_tenant:1:tenant_id:tenants:id:NO ACTION:NO ACTION'
     OR (SELECT COUNT(*) FROM information_schema.referential_constraints
          WHERE constraint_schema = DATABASE()
            AND table_name = 'business_report_contact_scope_bindings'
            AND match_option <> 'NONE') <> 0
     OR (SELECT COUNT(*) FROM information_schema.table_constraints
          WHERE constraint_schema = DATABASE()
            AND table_name = 'business_report_contact_scope_bindings'
            AND constraint_type = 'CHECK') <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'report contact-scope table has unexpected constraints';
  END IF;
END$$
DELIMITER ;

CALL safeharbor_migrate_client_report_contacts();
DROP PROCEDURE safeharbor_migrate_client_report_contacts;

-- Refuse to replace immutable guards unless this connection can create a
-- trigger on all six protected tables. Existing permanent guards are intact.
DROP TRIGGER IF EXISTS trg_br_contact_scope_priv_preflight;
DROP TRIGGER IF EXISTS trg_br_schedule_priv_preflight;
DROP TRIGGER IF EXISTS trg_br_id_tenant_binding_priv_preflight;
DROP TRIGGER IF EXISTS trg_br_id_tenant_snapshot_priv_preflight;
DROP TRIGGER IF EXISTS trg_br_id_client_binding_priv_preflight;
DROP TRIGGER IF EXISTS trg_br_id_client_snapshot_priv_preflight;
CREATE TRIGGER trg_br_contact_scope_priv_preflight
BEFORE INSERT ON business_report_contact_scope_bindings
FOR EACH ROW SET @br_id_client_trigger_preflight = 1;
CREATE TRIGGER trg_br_schedule_priv_preflight
BEFORE INSERT ON business_report_schedule_versions
FOR EACH ROW SET @br_id_client_trigger_preflight = 1;
CREATE TRIGGER trg_br_id_tenant_binding_priv_preflight
BEFORE INSERT ON business_report_id_tenant_bindings
FOR EACH ROW SET @br_id_client_trigger_preflight = 1;
CREATE TRIGGER trg_br_id_tenant_snapshot_priv_preflight
BEFORE INSERT ON business_report_id_contact_snapshots
FOR EACH ROW SET @br_id_client_trigger_preflight = 1;
CREATE TRIGGER trg_br_id_client_binding_priv_preflight
BEFORE INSERT ON business_report_id_client_bindings
FOR EACH ROW SET @br_id_client_trigger_preflight = 1;
CREATE TRIGGER trg_br_id_client_snapshot_priv_preflight
BEFORE INSERT ON business_report_id_client_contact_snapshots
FOR EACH ROW SET @br_id_client_trigger_preflight = 1;
DROP TRIGGER trg_br_contact_scope_priv_preflight;
DROP TRIGGER trg_br_schedule_priv_preflight;
DROP TRIGGER trg_br_id_tenant_binding_priv_preflight;
DROP TRIGGER trg_br_id_tenant_snapshot_priv_preflight;
DROP TRIGGER trg_br_id_client_binding_priv_preflight;
DROP TRIGGER trg_br_id_client_snapshot_priv_preflight;

DELIMITER $$
CREATE TRIGGER IF NOT EXISTS trg_br_contact_scope_swap_insert
BEFORE INSERT ON business_report_contact_scope_bindings
FOR EACH ROW SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Migration 019 client report-contact replay is in progress'$$
CREATE TRIGGER IF NOT EXISTS trg_br_contact_scope_swap_update
BEFORE UPDATE ON business_report_contact_scope_bindings
FOR EACH ROW SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Migration 019 client report-contact replay is in progress'$$
CREATE TRIGGER IF NOT EXISTS trg_br_contact_scope_swap_delete
BEFORE DELETE ON business_report_contact_scope_bindings
FOR EACH ROW SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Migration 019 client report-contact replay is in progress'$$
CREATE TRIGGER IF NOT EXISTS trg_br_schedule_scope_swap_insert
BEFORE INSERT ON business_report_schedule_versions
FOR EACH ROW SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Migration 019 client report-contact replay is in progress'$$
CREATE TRIGGER IF NOT EXISTS trg_br_id_tenant_binding_swap_insert
BEFORE INSERT ON business_report_id_tenant_bindings
FOR EACH ROW SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Migration 019 client report-contact replay is in progress'$$
CREATE TRIGGER IF NOT EXISTS trg_br_id_tenant_snapshot_swap_insert
BEFORE INSERT ON business_report_id_contact_snapshots
FOR EACH ROW SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Migration 019 client report-contact replay is in progress'$$
CREATE TRIGGER IF NOT EXISTS trg_br_id_client_binding_swap_insert
BEFORE INSERT ON business_report_id_client_bindings
FOR EACH ROW SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Migration 019 client report-contact replay is in progress'$$
CREATE TRIGGER IF NOT EXISTS trg_br_id_client_binding_swap_update
BEFORE UPDATE ON business_report_id_client_bindings
FOR EACH ROW SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Migration 019 client report-contact replay is in progress'$$
CREATE TRIGGER IF NOT EXISTS trg_br_id_client_binding_swap_delete
BEFORE DELETE ON business_report_id_client_bindings
FOR EACH ROW SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Migration 019 client report-contact replay is in progress'$$
CREATE TRIGGER IF NOT EXISTS trg_br_id_client_snapshot_swap_insert
BEFORE INSERT ON business_report_id_client_contact_snapshots
FOR EACH ROW SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Migration 019 client report-contact replay is in progress'$$
CREATE TRIGGER IF NOT EXISTS trg_br_id_client_snapshot_swap_update
BEFORE UPDATE ON business_report_id_client_contact_snapshots
FOR EACH ROW SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Migration 019 client report-contact replay is in progress'$$
CREATE TRIGGER IF NOT EXISTS trg_br_id_client_snapshot_swap_delete
BEFORE DELETE ON business_report_id_client_contact_snapshots
FOR EACH ROW SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Migration 019 client report-contact replay is in progress'$$
DELIMITER ;

DROP PROCEDURE IF EXISTS safeharbor_assert_client_report_contact_swap;
DELIMITER $$
CREATE PROCEDURE safeharbor_assert_client_report_contact_swap()
BEGIN
  IF (SELECT COUNT(*) FROM information_schema.triggers
       WHERE trigger_schema = DATABASE()
         AND action_timing = 'BEFORE'
         AND (
           (trigger_name = 'trg_br_contact_scope_swap_insert'
             AND event_object_table = 'business_report_contact_scope_bindings'
             AND event_manipulation = 'INSERT')
           OR (trigger_name = 'trg_br_contact_scope_swap_update'
             AND event_object_table = 'business_report_contact_scope_bindings'
             AND event_manipulation = 'UPDATE')
           OR (trigger_name = 'trg_br_contact_scope_swap_delete'
             AND event_object_table = 'business_report_contact_scope_bindings'
             AND event_manipulation = 'DELETE')
           OR (trigger_name = 'trg_br_schedule_scope_swap_insert'
             AND event_object_table = 'business_report_schedule_versions'
             AND event_manipulation = 'INSERT')
           OR (trigger_name = 'trg_br_id_tenant_binding_swap_insert'
             AND event_object_table = 'business_report_id_tenant_bindings'
             AND event_manipulation = 'INSERT')
           OR (trigger_name = 'trg_br_id_tenant_snapshot_swap_insert'
             AND event_object_table = 'business_report_id_contact_snapshots'
             AND event_manipulation = 'INSERT')
           OR (trigger_name = 'trg_br_id_client_binding_swap_insert'
             AND event_object_table = 'business_report_id_client_bindings'
             AND event_manipulation = 'INSERT')
           OR (trigger_name = 'trg_br_id_client_binding_swap_update'
             AND event_object_table = 'business_report_id_client_bindings'
             AND event_manipulation = 'UPDATE')
           OR (trigger_name = 'trg_br_id_client_binding_swap_delete'
             AND event_object_table = 'business_report_id_client_bindings'
             AND event_manipulation = 'DELETE')
           OR (trigger_name = 'trg_br_id_client_snapshot_swap_insert'
             AND event_object_table = 'business_report_id_client_contact_snapshots'
             AND event_manipulation = 'INSERT')
           OR (trigger_name = 'trg_br_id_client_snapshot_swap_update'
             AND event_object_table = 'business_report_id_client_contact_snapshots'
             AND event_manipulation = 'UPDATE')
           OR (trigger_name = 'trg_br_id_client_snapshot_swap_delete'
             AND event_object_table = 'business_report_id_client_contact_snapshots'
             AND event_manipulation = 'DELETE')
         )
         AND LOWER(action_statement) LIKE '%signal sqlstate%'
         AND LOWER(action_statement) LIKE '%migration 019 client report-contact replay is in progress%') <> 12 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Migration 019 fail-closed swap guards are incomplete';
  END IF;
END$$
DELIMITER ;
CALL safeharbor_assert_client_report_contact_swap();
DROP PROCEDURE safeharbor_assert_client_report_contact_swap;

DROP TRIGGER IF EXISTS trg_business_report_contact_scope_before_insert;
DROP TRIGGER IF EXISTS trg_business_report_contact_scope_no_update;
DROP TRIGGER IF EXISTS trg_business_report_contact_scope_no_delete;
DROP TRIGGER IF EXISTS trg_business_report_schedules_before_insert;
DROP TRIGGER IF EXISTS trg_business_report_id_binding_before_insert;
DROP TRIGGER IF EXISTS trg_business_report_id_snapshot_before_insert;
DROP TRIGGER IF EXISTS trg_br_id_client_binding_before_insert;
DROP TRIGGER IF EXISTS trg_br_id_client_binding_no_update;
DROP TRIGGER IF EXISTS trg_br_id_client_binding_no_delete;
DROP TRIGGER IF EXISTS trg_br_id_client_snapshot_before_insert;
DROP TRIGGER IF EXISTS trg_br_id_client_snapshot_no_update;
DROP TRIGGER IF EXISTS trg_br_id_client_snapshot_no_delete;

DELIMITER $$
CREATE TRIGGER trg_business_report_contact_scope_before_insert
BEFORE INSERT ON business_report_contact_scope_bindings
FOR EACH ROW
BEGIN
  DECLARE actor_is_authorized INT DEFAULT 0;
  DECLARE schedule_history INT DEFAULT 0;

  SELECT COUNT(*) INTO actor_is_authorized
    FROM users
   WHERE tenant_id = NEW.tenant_id
     AND id = NEW.created_by_user_id
     AND is_active = 1
     AND role IN ('owner','admin');
  IF actor_is_authorized <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Business report contact scope actor must be an active owner or admin';
  END IF;

  SELECT COUNT(*) INTO schedule_history
    FROM business_report_schedule_versions
   WHERE tenant_id = NEW.tenant_id AND schedule_key = NEW.schedule_key;
  IF schedule_history <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Business report contact scope must be pinned before its first schedule version';
  END IF;
END$$

CREATE TRIGGER trg_business_report_contact_scope_no_update
BEFORE UPDATE ON business_report_contact_scope_bindings
FOR EACH ROW SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Business report contact scopes are immutable'$$

CREATE TRIGGER trg_business_report_contact_scope_no_delete
BEFORE DELETE ON business_report_contact_scope_bindings
FOR EACH ROW SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Business report contact scopes are immutable'$$

CREATE TRIGGER trg_business_report_schedules_before_insert
BEFORE INSERT ON business_report_schedule_versions
FOR EACH ROW
BEGIN
  DECLARE actor_is_authorized INT DEFAULT 0;
  DECLARE locked_contact_scope VARCHAR(16) DEFAULT NULL;
  DECLARE active_contact_matches INT DEFAULT 0;
  DECLARE latest_version INT DEFAULT 0;
  DECLARE latest_status VARCHAR(16) DEFAULT NULL;
  DECLARE latest_client_id INT UNSIGNED DEFAULT NULL;
  DECLARE latest_definition_id INT UNSIGNED DEFAULT NULL;
  DECLARE latest_timezone VARCHAR(64) DEFAULT NULL;

  SELECT COUNT(*) INTO actor_is_authorized
    FROM users
   WHERE tenant_id = NEW.tenant_id
     AND id = NEW.created_by_user_id
     AND is_active = 1
     AND role IN ('owner','admin');
  IF actor_is_authorized <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Business report schedule actor must be an active owner or admin';
  END IF;

  SELECT contact_scope INTO locked_contact_scope
    FROM business_report_contact_scope_bindings
   WHERE tenant_id = NEW.tenant_id
     AND BINARY schedule_key = BINARY NEW.schedule_key
   FOR UPDATE;
  IF locked_contact_scope IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Business report schedule requires its immutable contact scope';
  END IF;

  SELECT COALESCE(MAX(version_no), 0) INTO latest_version
    FROM business_report_schedule_versions
   WHERE tenant_id = NEW.tenant_id AND schedule_key = NEW.schedule_key;
  IF latest_version > 0 THEN
    SELECT status, client_id, definition_version_id, schedule_timezone
      INTO latest_status, latest_client_id, latest_definition_id, latest_timezone
      FROM business_report_schedule_versions
     WHERE tenant_id = NEW.tenant_id
       AND schedule_key = NEW.schedule_key
       AND version_no = latest_version;
  END IF;
  IF NEW.version_no <> latest_version + 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Business report schedule versions must be sequential';
  END IF;
  IF latest_version = 0 AND NEW.status <> 'disabled' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Business report schedules must start disabled';
  END IF;
  IF latest_version > 0
     AND (NEW.client_id <> latest_client_id
          OR NEW.definition_version_id <> latest_definition_id
          OR BINARY NEW.schedule_timezone <> BINARY latest_timezone) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Business report schedule keys cannot change client, definition, or timezone';
  END IF;
  IF latest_status = 'active' AND NEW.status <> 'disabled' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'An active business report schedule must be explicitly disabled';
  END IF;
  IF NEW.status = 'active' AND BINARY locked_contact_scope = BINARY 'TENANT' THEN
    SELECT COUNT(*) INTO active_contact_matches
      FROM business_report_id_contact_snapshots evidence
      JOIN business_report_schedule_versions evidence_schedule
        ON evidence_schedule.tenant_id = evidence.tenant_id
       AND evidence_schedule.id = evidence.schedule_version_id
     WHERE evidence_schedule.tenant_id = NEW.tenant_id
       AND BINARY evidence_schedule.schedule_key = BINARY NEW.schedule_key
       AND evidence_schedule.version_no = (
         SELECT MAX(latest_schedule.version_no)
           FROM business_report_id_contact_snapshots latest_evidence
           JOIN business_report_schedule_versions latest_schedule
             ON latest_schedule.tenant_id = latest_evidence.tenant_id
            AND latest_schedule.id = latest_evidence.schedule_version_id
          WHERE latest_schedule.tenant_id = NEW.tenant_id
            AND BINARY latest_schedule.schedule_key = BINARY NEW.schedule_key
       )
       AND BINARY evidence.recipient_email = BINARY NEW.recipient_email;
    IF active_contact_matches <> 1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Active tenant-ID report recipient must match latest immutable evidence';
    END IF;
  ELSEIF NEW.status = 'active' AND BINARY locked_contact_scope = BINARY 'CLIENT' THEN
    SELECT COUNT(*) INTO active_contact_matches
      FROM business_report_id_client_contact_snapshots evidence
      JOIN business_report_schedule_versions evidence_schedule
        ON evidence_schedule.tenant_id = evidence.tenant_id
       AND evidence_schedule.id = evidence.schedule_version_id
     WHERE evidence_schedule.tenant_id = NEW.tenant_id
       AND BINARY evidence_schedule.schedule_key = BINARY NEW.schedule_key
       AND evidence_schedule.version_no = (
         SELECT MAX(latest_schedule.version_no)
           FROM business_report_id_client_contact_snapshots latest_evidence
           JOIN business_report_schedule_versions latest_schedule
             ON latest_schedule.tenant_id = latest_evidence.tenant_id
            AND latest_schedule.id = latest_evidence.schedule_version_id
          WHERE latest_schedule.tenant_id = NEW.tenant_id
            AND BINARY latest_schedule.schedule_key = BINARY NEW.schedule_key
       )
       AND evidence.client_id = NEW.client_id
       AND evidence_schedule.client_id = NEW.client_id
       AND BINARY evidence.recipient_email = BINARY NEW.recipient_email;
    IF active_contact_matches <> 1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Active client-ID report recipient and client must match latest immutable evidence';
    END IF;
  END IF;
END$$

CREATE TRIGGER trg_business_report_id_binding_before_insert
BEFORE INSERT ON business_report_id_tenant_bindings
FOR EACH ROW
BEGIN
  DECLARE binding_matches INT DEFAULT 0;
  DECLARE client_scope_key VARCHAR(32) DEFAULT NULL;

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

  SELECT id_tenant_key INTO client_scope_key
    FROM business_report_id_client_bindings
         FORCE INDEX (uq_br_id_client_binding_key)
   WHERE id_tenant_key = NEW.id_tenant_key
   LIMIT 1 FOR UPDATE;
  IF client_scope_key IS NOT NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = '8 West ID report tenant is already bound at client scope';
  END IF;
END$$

CREATE TRIGGER trg_business_report_id_snapshot_before_insert
BEFORE INSERT ON business_report_id_contact_snapshots
FOR EACH ROW
BEGIN
  DECLARE locked_binding_tenant INT UNSIGNED DEFAULT NULL;
  DECLARE snapshot_matches INT DEFAULT 0;
  DECLARE logical_schedule_key VARCHAR(64) DEFAULT NULL;
  DECLARE logical_schedule_version INT UNSIGNED DEFAULT 0;
  DECLARE logical_history_versions INT DEFAULT 0;
  DECLARE locked_contact_scope VARCHAR(16) DEFAULT NULL;
  DECLARE tenant_scope_evidence INT DEFAULT 0;
  DECLARE client_scope_evidence INT DEFAULT 0;
  DECLARE latest_contact_version BIGINT UNSIGNED DEFAULT 0;
  DECLARE conflicting_same_version INT DEFAULT 0;

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

  SELECT schedule_key, version_no
    INTO logical_schedule_key, logical_schedule_version
    FROM business_report_schedule_versions
   WHERE tenant_id = NEW.tenant_id AND id = NEW.schedule_version_id
   FOR UPDATE;
  SELECT contact_scope INTO locked_contact_scope
    FROM business_report_contact_scope_bindings
   WHERE tenant_id = NEW.tenant_id
     AND BINARY schedule_key = BINARY logical_schedule_key
   FOR UPDATE;
  IF locked_contact_scope IS NULL OR BINARY locked_contact_scope <> BINARY 'TENANT' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Report schedule immutable contact scope is not tenant ID';
  END IF;
  SELECT COUNT(*) INTO logical_history_versions
    FROM business_report_schedule_versions
   WHERE tenant_id = NEW.tenant_id
     AND BINARY schedule_key = BINARY logical_schedule_key;
  SELECT COUNT(*) INTO tenant_scope_evidence
    FROM business_report_id_contact_snapshots e
    JOIN business_report_schedule_versions s
      ON s.tenant_id = e.tenant_id AND s.id = e.schedule_version_id
   WHERE e.tenant_id = NEW.tenant_id
     AND BINARY s.schedule_key = BINARY logical_schedule_key;
  SELECT COUNT(*) INTO client_scope_evidence
    FROM business_report_id_client_contact_snapshots e
    JOIN business_report_schedule_versions s
      ON s.tenant_id = e.tenant_id AND s.id = e.schedule_version_id
   WHERE e.tenant_id = NEW.tenant_id
     AND BINARY s.schedule_key = BINARY logical_schedule_key;
  IF client_scope_evidence <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Report schedule contact scope is already client ID';
  END IF;
  IF tenant_scope_evidence = 0
     AND (logical_schedule_version <> 1 OR logical_history_versions <> 1) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Report schedule contact scope is already manual';
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

CREATE TRIGGER trg_br_id_client_binding_before_insert
BEFORE INSERT ON business_report_id_client_bindings
FOR EACH ROW
BEGIN
  DECLARE binding_matches INT DEFAULT 0;
  DECLARE tenant_scope_key VARCHAR(32) DEFAULT NULL;

  SELECT COUNT(*) INTO binding_matches
    FROM clients c
    JOIN users u ON u.tenant_id = c.tenant_id
   WHERE c.tenant_id = NEW.tenant_id
     AND c.id = NEW.client_id
     AND u.id = NEW.created_by_user_id
     AND u.is_active = 1
     AND u.role IN ('owner','admin');
  IF binding_matches <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Client report binding must match its tenant, client, and active actor';
  END IF;

  SELECT id_tenant_key INTO tenant_scope_key
    FROM business_report_id_tenant_bindings
         FORCE INDEX (uq_business_report_id_binding_key)
   WHERE id_tenant_key = NEW.id_tenant_key
   LIMIT 1 FOR UPDATE;
  IF tenant_scope_key IS NOT NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = '8 West ID report tenant is already bound at tenant scope';
  END IF;
END$$

CREATE TRIGGER trg_br_id_client_binding_no_update
BEFORE UPDATE ON business_report_id_client_bindings
FOR EACH ROW SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Client report bindings are immutable'$$

CREATE TRIGGER trg_br_id_client_binding_no_delete
BEFORE DELETE ON business_report_id_client_bindings
FOR EACH ROW SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Client report bindings are immutable'$$

CREATE TRIGGER trg_br_id_client_snapshot_before_insert
BEFORE INSERT ON business_report_id_client_contact_snapshots
FOR EACH ROW
BEGIN
  DECLARE locked_binding_client INT UNSIGNED DEFAULT NULL;
  DECLARE snapshot_matches INT DEFAULT 0;
  DECLARE logical_schedule_key VARCHAR(64) DEFAULT NULL;
  DECLARE logical_schedule_version INT UNSIGNED DEFAULT 0;
  DECLARE logical_history_versions INT DEFAULT 0;
  DECLARE locked_contact_scope VARCHAR(16) DEFAULT NULL;
  DECLARE tenant_scope_evidence INT DEFAULT 0;
  DECLARE client_scope_evidence INT DEFAULT 0;
  DECLARE latest_contact_version BIGINT UNSIGNED DEFAULT 0;
  DECLARE conflicting_same_version INT DEFAULT 0;

  SELECT client_id INTO locked_binding_client
    FROM business_report_id_client_bindings
   WHERE tenant_id = NEW.tenant_id
     AND client_id = NEW.client_id
     AND BINARY id_tenant_key = BINARY NEW.id_tenant_key
   FOR UPDATE;
  IF locked_binding_client IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Client contact snapshot requires its exact client binding';
  END IF;

  SELECT COUNT(*) INTO snapshot_matches
    FROM business_report_schedule_versions s
    JOIN clients c ON c.tenant_id = s.tenant_id AND c.id = s.client_id
    JOIN business_report_id_client_bindings b
      ON b.tenant_id = s.tenant_id
     AND b.client_id = s.client_id
     AND BINARY b.id_tenant_key = BINARY NEW.id_tenant_key
    JOIN users u ON u.tenant_id = s.tenant_id
   WHERE s.tenant_id = NEW.tenant_id
     AND s.client_id = NEW.client_id
     AND s.id = NEW.schedule_version_id
     AND s.status = 'disabled'
     AND BINARY s.recipient_email = BINARY NEW.recipient_email
     AND s.created_by_user_id = NEW.created_by_user_id
     AND u.id = NEW.created_by_user_id
     AND u.is_active = 1
     AND u.role IN ('owner','admin');
  IF snapshot_matches <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Client contact snapshot must match its disabled client schedule and active actor';
  END IF;

  SELECT schedule_key, version_no
    INTO logical_schedule_key, logical_schedule_version
    FROM business_report_schedule_versions
   WHERE tenant_id = NEW.tenant_id AND id = NEW.schedule_version_id
   FOR UPDATE;
  SELECT contact_scope INTO locked_contact_scope
    FROM business_report_contact_scope_bindings
   WHERE tenant_id = NEW.tenant_id
     AND BINARY schedule_key = BINARY logical_schedule_key
   FOR UPDATE;
  IF locked_contact_scope IS NULL OR BINARY locked_contact_scope <> BINARY 'CLIENT' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Report schedule immutable contact scope is not client ID';
  END IF;
  SELECT COUNT(*) INTO logical_history_versions
    FROM business_report_schedule_versions
   WHERE tenant_id = NEW.tenant_id
     AND BINARY schedule_key = BINARY logical_schedule_key;
  SELECT COUNT(*) INTO tenant_scope_evidence
    FROM business_report_id_contact_snapshots e
    JOIN business_report_schedule_versions s
      ON s.tenant_id = e.tenant_id AND s.id = e.schedule_version_id
   WHERE e.tenant_id = NEW.tenant_id
     AND BINARY s.schedule_key = BINARY logical_schedule_key;
  SELECT COUNT(*) INTO client_scope_evidence
    FROM business_report_id_client_contact_snapshots e
    JOIN business_report_schedule_versions s
      ON s.tenant_id = e.tenant_id AND s.id = e.schedule_version_id
   WHERE e.tenant_id = NEW.tenant_id
     AND BINARY s.schedule_key = BINARY logical_schedule_key;
  IF tenant_scope_evidence <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Report schedule contact scope is already tenant ID';
  END IF;
  IF client_scope_evidence = 0
     AND (logical_schedule_version <> 1 OR logical_history_versions <> 1) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Report schedule contact scope is already manual';
  END IF;

  SELECT COALESCE(MAX(contact_version), 0)
    INTO latest_contact_version
    FROM business_report_id_client_contact_snapshots
   WHERE tenant_id = NEW.tenant_id
     AND client_id = NEW.client_id
     AND BINARY id_tenant_key = BINARY NEW.id_tenant_key;
  IF NEW.contact_version < latest_contact_version THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Client contact version cannot move backward';
  END IF;

  SELECT COUNT(*) INTO conflicting_same_version
    FROM business_report_id_client_contact_snapshots
   WHERE tenant_id = NEW.tenant_id
     AND client_id = NEW.client_id
     AND BINARY id_tenant_key = BINARY NEW.id_tenant_key
     AND contact_version = NEW.contact_version
     AND BINARY recipient_email <> BINARY NEW.recipient_email;
  IF conflicting_same_version > 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Client contact version cannot name different recipients';
  END IF;
END$$

CREATE TRIGGER trg_br_id_client_snapshot_no_update
BEFORE UPDATE ON business_report_id_client_contact_snapshots
FOR EACH ROW SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Client contact snapshots are immutable'$$

CREATE TRIGGER trg_br_id_client_snapshot_no_delete
BEFORE DELETE ON business_report_id_client_contact_snapshots
FOR EACH ROW SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Client contact snapshots are immutable'$$
DELIMITER ;

DROP PROCEDURE IF EXISTS safeharbor_assert_client_report_contact_guards;
DELIMITER $$
CREATE PROCEDURE safeharbor_assert_client_report_contact_guards(IN expected_swaps INT)
BEGIN
  IF (SELECT COUNT(*) FROM information_schema.triggers
       WHERE trigger_schema = DATABASE()
         AND trigger_name IN (
           'trg_business_report_contact_scope_before_insert',
           'trg_business_report_contact_scope_no_update',
           'trg_business_report_contact_scope_no_delete',
           'trg_business_report_schedules_before_insert',
           'trg_business_report_id_binding_before_insert',
           'trg_business_report_id_snapshot_before_insert',
           'trg_br_id_client_binding_before_insert',
           'trg_br_id_client_binding_no_update',
           'trg_br_id_client_binding_no_delete',
           'trg_br_id_client_snapshot_before_insert',
           'trg_br_id_client_snapshot_no_update',
           'trg_br_id_client_snapshot_no_delete'
         )) <> 12 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Migration 019 permanent guards are incomplete';
  END IF;
  IF (SELECT COUNT(*) FROM information_schema.triggers
       WHERE trigger_schema = DATABASE()
         AND trigger_name IN (
           'trg_br_contact_scope_swap_insert',
           'trg_br_contact_scope_swap_update',
           'trg_br_contact_scope_swap_delete',
           'trg_br_schedule_scope_swap_insert',
           'trg_br_id_tenant_binding_swap_insert',
           'trg_br_id_tenant_snapshot_swap_insert',
           'trg_br_id_client_binding_swap_insert',
           'trg_br_id_client_binding_swap_update',
           'trg_br_id_client_binding_swap_delete',
           'trg_br_id_client_snapshot_swap_insert',
           'trg_br_id_client_snapshot_swap_update',
           'trg_br_id_client_snapshot_swap_delete'
         )) <> expected_swaps THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Migration 019 swap-guard postflight is incomplete';
  END IF;
  IF (SELECT COUNT(*) FROM information_schema.triggers
       WHERE trigger_schema = DATABASE()
         AND trigger_name IN (
           'trg_br_contact_scope_priv_preflight',
           'trg_br_schedule_priv_preflight',
           'trg_br_id_tenant_binding_priv_preflight',
           'trg_br_id_tenant_snapshot_priv_preflight',
           'trg_br_id_client_binding_priv_preflight',
           'trg_br_id_client_snapshot_priv_preflight'
         )) <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Migration 019 privilege preflight cleanup is incomplete';
  END IF;
END$$
DELIMITER ;

CALL safeharbor_assert_client_report_contact_guards(12);
DROP TRIGGER trg_br_contact_scope_swap_insert;
DROP TRIGGER trg_br_contact_scope_swap_update;
DROP TRIGGER trg_br_contact_scope_swap_delete;
DROP TRIGGER trg_br_schedule_scope_swap_insert;
DROP TRIGGER trg_br_id_tenant_binding_swap_insert;
DROP TRIGGER trg_br_id_tenant_snapshot_swap_insert;
DROP TRIGGER trg_br_id_client_binding_swap_insert;
DROP TRIGGER trg_br_id_client_binding_swap_update;
DROP TRIGGER trg_br_id_client_binding_swap_delete;
DROP TRIGGER trg_br_id_client_snapshot_swap_insert;
DROP TRIGGER trg_br_id_client_snapshot_swap_update;
DROP TRIGGER trg_br_id_client_snapshot_swap_delete;
CALL safeharbor_assert_client_report_contact_guards(0);
DROP PROCEDURE safeharbor_assert_client_report_contact_guards;

SELECT
  (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name IN (
        'business_report_contact_scope_bindings',
        'business_report_id_client_bindings',
        'business_report_id_client_contact_snapshots'
      )) AS migration_019_tables,
  (SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema = DATABASE()
      AND trigger_name IN (
        'trg_business_report_contact_scope_before_insert',
        'trg_business_report_contact_scope_no_update',
        'trg_business_report_contact_scope_no_delete',
        'trg_business_report_schedules_before_insert',
        'trg_business_report_id_binding_before_insert',
        'trg_business_report_id_snapshot_before_insert',
        'trg_br_id_client_binding_before_insert',
        'trg_br_id_client_binding_no_update',
        'trg_br_id_client_binding_no_delete',
        'trg_br_id_client_snapshot_before_insert',
        'trg_br_id_client_snapshot_no_update',
        'trg_br_id_client_snapshot_no_delete'
      )) AS protected_contact_scope_triggers,
  (SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema = DATABASE()
      AND trigger_name LIKE '%_swap_%') AS report_contact_swap_triggers,
  (SELECT COUNT(*) FROM business_report_id_client_bindings) AS client_binding_rows,
  (SELECT COUNT(*) FROM business_report_id_client_contact_snapshots) AS client_snapshot_rows;
