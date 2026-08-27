-- 015_suite_customer_sync.sql -- Milepost customer registry receipts.
--
-- Default-off source only. This migration creates no customer, binding,
-- service identity, secret, allowlist, or activation state.
SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET SESSION group_concat_max_len = 16384;

CREATE TABLE IF NOT EXISTS suite_customer_sync_bindings (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id           INT UNSIGNED NOT NULL,
  customer_id         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  client_id           INT UNSIGNED NOT NULL,
  source_version      BIGINT UNSIGNED NOT NULL,
  display_name        VARCHAR(128) NOT NULL,
  status              ENUM('active','inactive') NOT NULL,
  last_event_id       CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  last_occurred_at    DATETIME NOT NULL,
  last_request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_suite_customer_sync_customer (customer_id),
  UNIQUE KEY uq_suite_customer_sync_client (tenant_id, client_id),
  UNIQUE KEY uq_suite_customer_sync_binding_scope (tenant_id, id),
  KEY ix_suite_customer_sync_tenant_status (tenant_id, status, customer_id),
  CONSTRAINT fk_suite_customer_sync_binding_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_suite_customer_sync_binding_client
    FOREIGN KEY (tenant_id, client_id) REFERENCES clients (tenant_id, id),
  CONSTRAINT ck_suite_customer_sync_customer_uuid CHECK (
    REGEXP_LIKE(customer_id, _ascii'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')
  ) ENFORCED,
  CONSTRAINT ck_suite_customer_sync_version CHECK (source_version >= 1) ENFORCED,
  CONSTRAINT ck_suite_customer_sync_name CHECK (
    display_name = TRIM(display_name)
    AND CHAR_LENGTH(display_name) BETWEEN 1 AND 128
    AND NOT REGEXP_LIKE(display_name, _utf8mb4'[[:cntrl:]]')
  ) ENFORCED,
  CONSTRAINT ck_suite_customer_sync_event_uuid CHECK (
    REGEXP_LIKE(last_event_id, _ascii'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')
  ) ENFORCED,
  CONSTRAINT ck_suite_customer_sync_request_hash CHECK (
    REGEXP_LIKE(last_request_sha256, _ascii'^[0-9a-f]{64}$')
  ) ENFORCED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS suite_customer_sync_events (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id      INT UNSIGNED NOT NULL,
  event_id       CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  binding_id     BIGINT UNSIGNED NOT NULL,
  customer_id    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  client_id      INT UNSIGNED NOT NULL,
  source_version BIGINT UNSIGNED NOT NULL,
  display_name   VARCHAR(128) NOT NULL,
  status         ENUM('active','inactive') NOT NULL,
  occurred_at    DATETIME NOT NULL,
  request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  received_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_suite_customer_sync_event (event_id),
  UNIQUE KEY uq_suite_customer_sync_source_version (tenant_id, customer_id, source_version),
  UNIQUE KEY uq_suite_customer_sync_event_scope (tenant_id, id),
  KEY ix_suite_customer_sync_event_binding (tenant_id, binding_id, id),
  KEY ix_suite_customer_sync_event_client (tenant_id, client_id, received_at),
  CONSTRAINT fk_suite_customer_sync_event_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_suite_customer_sync_event_binding
    FOREIGN KEY (tenant_id, binding_id)
    REFERENCES suite_customer_sync_bindings (tenant_id, id),
  CONSTRAINT fk_suite_customer_sync_event_client
    FOREIGN KEY (tenant_id, client_id) REFERENCES clients (tenant_id, id),
  CONSTRAINT ck_suite_customer_sync_receipt_event_uuid CHECK (
    REGEXP_LIKE(event_id, _ascii'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')
  ) ENFORCED,
  CONSTRAINT ck_suite_customer_sync_receipt_customer_uuid CHECK (
    REGEXP_LIKE(customer_id, _ascii'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')
  ) ENFORCED,
  CONSTRAINT ck_suite_customer_sync_receipt_version CHECK (source_version >= 1) ENFORCED,
  CONSTRAINT ck_suite_customer_sync_receipt_name CHECK (
    display_name = TRIM(display_name)
    AND CHAR_LENGTH(display_name) BETWEEN 1 AND 128
    AND NOT REGEXP_LIKE(display_name, _utf8mb4'[[:cntrl:]]')
  ) ENFORCED,
  CONSTRAINT ck_suite_customer_sync_receipt_hash CHECK (
    REGEXP_LIKE(request_sha256, _ascii'^[0-9a-f]{64}$')
  ) ENFORCED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- CREATE TABLE IF NOT EXISTS must never turn a replay over drift into silent
-- acceptance. Prove the complete object envelope before replacing any guard.
SET @suite_customer_sync_tables_ok = (
  SELECT COUNT(*) = 2
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name IN ('suite_customer_sync_bindings','suite_customer_sync_events')
     AND engine = 'InnoDB'
);
SET @suite_customer_sync_columns_ok = (
  SELECT COUNT(*) = 2 FROM (
    SELECT table_name, COUNT(*) AS column_count
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name IN ('suite_customer_sync_bindings','suite_customer_sync_events')
     GROUP BY table_name
    HAVING column_count = 12
  ) exact_column_counts
);
SET @suite_customer_sync_critical_columns_ok = (
  SELECT COUNT(*) = 24
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND (
       (table_name = 'suite_customer_sync_bindings' AND column_name = 'id'
        AND column_type = 'bigint unsigned' AND is_nullable = 'NO'
        AND extra = 'auto_increment')
       OR (table_name = 'suite_customer_sync_bindings' AND column_name = 'tenant_id'
        AND column_type = 'int unsigned' AND is_nullable = 'NO')
       OR (table_name = 'suite_customer_sync_bindings' AND column_name = 'customer_id'
        AND column_type = 'char(36)' AND character_set_name = 'ascii'
        AND collation_name = 'ascii_bin' AND is_nullable = 'NO')
       OR (table_name = 'suite_customer_sync_bindings' AND column_name = 'client_id'
        AND column_type = 'int unsigned' AND is_nullable = 'NO')
       OR (table_name = 'suite_customer_sync_bindings' AND column_name = 'source_version'
        AND column_type = 'bigint unsigned' AND is_nullable = 'NO')
       OR (table_name = 'suite_customer_sync_bindings' AND column_name = 'display_name'
        AND column_type = 'varchar(128)' AND is_nullable = 'NO')
       OR (table_name = 'suite_customer_sync_bindings' AND column_name = 'status'
        AND column_type = 'enum(''active'',''inactive'')' AND is_nullable = 'NO')
       OR (table_name = 'suite_customer_sync_bindings' AND column_name = 'last_event_id'
        AND column_type = 'char(36)' AND character_set_name = 'ascii'
        AND collation_name = 'ascii_bin' AND is_nullable = 'NO')
       OR (table_name = 'suite_customer_sync_bindings' AND column_name = 'last_request_sha256'
        AND column_type = 'char(64)' AND character_set_name = 'ascii'
        AND collation_name = 'ascii_bin' AND is_nullable = 'NO')
       OR (table_name = 'suite_customer_sync_bindings' AND column_name = 'last_occurred_at'
        AND column_type = 'datetime' AND is_nullable = 'NO')
       OR (table_name = 'suite_customer_sync_bindings' AND column_name = 'created_at'
        AND column_type = 'datetime' AND is_nullable = 'NO')
       OR (table_name = 'suite_customer_sync_bindings' AND column_name = 'updated_at'
        AND column_type = 'datetime' AND is_nullable = 'NO')
       OR (table_name = 'suite_customer_sync_events' AND column_name = 'id'
        AND column_type = 'bigint unsigned' AND is_nullable = 'NO'
        AND extra = 'auto_increment')
       OR (table_name = 'suite_customer_sync_events' AND column_name = 'tenant_id'
        AND column_type = 'int unsigned' AND is_nullable = 'NO')
       OR (table_name = 'suite_customer_sync_events' AND column_name = 'event_id'
        AND column_type = 'char(36)' AND character_set_name = 'ascii'
        AND collation_name = 'ascii_bin' AND is_nullable = 'NO')
       OR (table_name = 'suite_customer_sync_events' AND column_name = 'binding_id'
        AND column_type = 'bigint unsigned' AND is_nullable = 'NO')
       OR (table_name = 'suite_customer_sync_events' AND column_name = 'customer_id'
        AND column_type = 'char(36)' AND character_set_name = 'ascii'
        AND collation_name = 'ascii_bin' AND is_nullable = 'NO')
       OR (table_name = 'suite_customer_sync_events' AND column_name = 'client_id'
        AND column_type = 'int unsigned' AND is_nullable = 'NO')
       OR (table_name = 'suite_customer_sync_events' AND column_name = 'source_version'
        AND column_type = 'bigint unsigned' AND is_nullable = 'NO')
       OR (table_name = 'suite_customer_sync_events' AND column_name = 'display_name'
        AND column_type = 'varchar(128)' AND is_nullable = 'NO')
       OR (table_name = 'suite_customer_sync_events' AND column_name = 'status'
        AND column_type = 'enum(''active'',''inactive'')' AND is_nullable = 'NO')
       OR (table_name = 'suite_customer_sync_events' AND column_name = 'occurred_at'
        AND column_type = 'datetime' AND is_nullable = 'NO')
       OR (table_name = 'suite_customer_sync_events' AND column_name = 'request_sha256'
        AND column_type = 'char(64)' AND character_set_name = 'ascii'
        AND collation_name = 'ascii_bin' AND is_nullable = 'NO')
       OR (table_name = 'suite_customer_sync_events' AND column_name = 'received_at'
        AND column_type = 'datetime' AND is_nullable = 'NO')
     )
);
-- Hash the complete normalized critical column envelope. The table-default
-- collation marker permits the installation's chosen utf8mb4 table collation,
-- but still refuses a per-column collation change. MySQL's harmless
-- DEFAULT_GENERATED metadata spelling and CURRENT_TIMESTAMP parentheses are
-- normalized; every other type/default/nullability/extra/charset/collation
-- difference changes the fingerprint.
SET @suite_customer_sync_column_fingerprint = (
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
     AND sync_column.table_name IN (
       'suite_customer_sync_bindings','suite_customer_sync_events'
     )
);
SET @suite_customer_sync_column_fingerprint_ok = (
  @suite_customer_sync_column_fingerprint
    = '8f2f3b4f932534cf24489be64ac0caf4156cecb0bfdc377afe349380525d67e2'
);
SET @suite_customer_sync_index_count_ok = (
  SELECT COUNT(DISTINCT CONCAT(table_name, ':', index_name)) = 11
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name IN ('suite_customer_sync_bindings','suite_customer_sync_events')
);
SET @suite_customer_sync_index_shapes_ok = (
  SELECT COUNT(*) = 11 FROM (
    SELECT table_name, index_name
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name IN ('suite_customer_sync_bindings','suite_customer_sync_events')
     GROUP BY table_name, index_name
    HAVING MIN(index_type) = 'BTREE' AND MAX(index_type) = 'BTREE'
       AND SUM(sub_part IS NOT NULL) = 0
       AND (
         (table_name = 'suite_customer_sync_bindings' AND index_name = 'PRIMARY'
          AND MIN(non_unique) = 0 AND GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'id')
         OR (table_name = 'suite_customer_sync_bindings' AND index_name = 'uq_suite_customer_sync_customer'
          AND MIN(non_unique) = 0 AND GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'customer_id')
         OR (table_name = 'suite_customer_sync_bindings' AND index_name = 'uq_suite_customer_sync_client'
          AND MIN(non_unique) = 0 AND GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'tenant_id,client_id')
         OR (table_name = 'suite_customer_sync_bindings' AND index_name = 'uq_suite_customer_sync_binding_scope'
          AND MIN(non_unique) = 0 AND GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'tenant_id,id')
         OR (table_name = 'suite_customer_sync_bindings' AND index_name = 'ix_suite_customer_sync_tenant_status'
          AND MIN(non_unique) = 1 AND GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'tenant_id,status,customer_id')
         OR (table_name = 'suite_customer_sync_events' AND index_name = 'PRIMARY'
          AND MIN(non_unique) = 0 AND GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'id')
         OR (table_name = 'suite_customer_sync_events' AND index_name = 'uq_suite_customer_sync_event'
          AND MIN(non_unique) = 0 AND GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'event_id')
         OR (table_name = 'suite_customer_sync_events' AND index_name = 'uq_suite_customer_sync_source_version'
          AND MIN(non_unique) = 0 AND GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'tenant_id,customer_id,source_version')
         OR (table_name = 'suite_customer_sync_events' AND index_name = 'uq_suite_customer_sync_event_scope'
          AND MIN(non_unique) = 0 AND GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'tenant_id,id')
         OR (table_name = 'suite_customer_sync_events' AND index_name = 'ix_suite_customer_sync_event_binding'
          AND MIN(non_unique) = 1 AND GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'tenant_id,binding_id,id')
         OR (table_name = 'suite_customer_sync_events' AND index_name = 'ix_suite_customer_sync_event_client'
          AND MIN(non_unique) = 1 AND GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'tenant_id,client_id,received_at')
       )
  ) exact_indexes
);
SET @suite_customer_sync_fks_ok = (
  SELECT COUNT(*) = 5 FROM (
    SELECT key_column.table_name, key_column.constraint_name
      FROM information_schema.key_column_usage key_column
      JOIN information_schema.referential_constraints referential_constraint
        ON referential_constraint.constraint_schema = key_column.constraint_schema
       AND referential_constraint.table_name = key_column.table_name
       AND referential_constraint.constraint_name = key_column.constraint_name
     WHERE key_column.constraint_schema = DATABASE()
       AND key_column.table_name IN ('suite_customer_sync_bindings','suite_customer_sync_events')
     GROUP BY key_column.table_name, key_column.constraint_name,
              key_column.referenced_table_name,
              referential_constraint.update_rule, referential_constraint.delete_rule
    HAVING MIN(referential_constraint.update_rule) = 'NO ACTION'
       AND MAX(referential_constraint.update_rule) = 'NO ACTION'
       AND MIN(referential_constraint.delete_rule) = 'NO ACTION'
       AND MAX(referential_constraint.delete_rule) = 'NO ACTION'
       AND (
         (key_column.table_name = 'suite_customer_sync_bindings'
          AND key_column.constraint_name = 'fk_suite_customer_sync_binding_tenant'
          AND key_column.referenced_table_name = 'tenants'
          AND GROUP_CONCAT(CONCAT(key_column.column_name,'=',key_column.referenced_column_name)
                           ORDER BY key_column.ordinal_position) = 'tenant_id=id')
         OR (key_column.table_name = 'suite_customer_sync_bindings'
          AND key_column.constraint_name = 'fk_suite_customer_sync_binding_client'
          AND key_column.referenced_table_name = 'clients'
          AND GROUP_CONCAT(CONCAT(key_column.column_name,'=',key_column.referenced_column_name)
                           ORDER BY key_column.ordinal_position) = 'tenant_id=tenant_id,client_id=id')
         OR (key_column.table_name = 'suite_customer_sync_events'
          AND key_column.constraint_name = 'fk_suite_customer_sync_event_tenant'
          AND key_column.referenced_table_name = 'tenants'
          AND GROUP_CONCAT(CONCAT(key_column.column_name,'=',key_column.referenced_column_name)
                           ORDER BY key_column.ordinal_position) = 'tenant_id=id')
         OR (key_column.table_name = 'suite_customer_sync_events'
          AND key_column.constraint_name = 'fk_suite_customer_sync_event_binding'
          AND key_column.referenced_table_name = 'suite_customer_sync_bindings'
          AND GROUP_CONCAT(CONCAT(key_column.column_name,'=',key_column.referenced_column_name)
                           ORDER BY key_column.ordinal_position) = 'tenant_id=tenant_id,binding_id=id')
         OR (key_column.table_name = 'suite_customer_sync_events'
          AND key_column.constraint_name = 'fk_suite_customer_sync_event_client'
          AND key_column.referenced_table_name = 'clients'
          AND GROUP_CONCAT(CONCAT(key_column.column_name,'=',key_column.referenced_column_name)
                           ORDER BY key_column.ordinal_position) = 'tenant_id=tenant_id,client_id=id')
       )
  ) exact_foreign_keys
);
SET @suite_customer_sync_fk_count_ok = (
  SELECT COUNT(*) = 5
    FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND table_name IN ('suite_customer_sync_bindings','suite_customer_sync_events')
     AND constraint_type = 'FOREIGN KEY'
);
SET @suite_customer_sync_check_count_ok = (
  SELECT COUNT(*) = 10
    FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND table_name IN ('suite_customer_sync_bindings','suite_customer_sync_events')
     AND constraint_type = 'CHECK'
);
SET @suite_customer_sync_checks_ok = (
  SELECT COUNT(*) = 10
    FROM (
      SELECT
        table_constraint.table_name,
        table_constraint.constraint_name,
        table_constraint.enforced,
        REGEXP_REPLACE(
          REPLACE(
            REPLACE(
              REPLACE(
                REPLACE(LOWER(check_constraint.check_clause), '`', ''),
                CHAR(92),
                ''
              ),
              '_ascii',
              ''
            ),
            '_utf8mb4',
            ''
          ),
          '[[:space:]()]',
          ''
        ) AS normalized_clause
        FROM information_schema.table_constraints table_constraint
        JOIN information_schema.check_constraints check_constraint
          ON check_constraint.constraint_schema = table_constraint.constraint_schema
         AND check_constraint.constraint_name = table_constraint.constraint_name
       WHERE table_constraint.constraint_schema = DATABASE()
         AND table_constraint.constraint_type = 'CHECK'
         AND table_constraint.table_name IN (
           'suite_customer_sync_bindings','suite_customer_sync_events'
         )
    ) normalized_check
   WHERE normalized_check.enforced = 'YES'
     AND (
       (normalized_check.table_name = 'suite_customer_sync_bindings'
        AND normalized_check.constraint_name = 'ck_suite_customer_sync_customer_uuid'
        AND normalized_check.normalized_clause = 'regexp_likecustomer_id,''^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$''')
       OR (normalized_check.table_name = 'suite_customer_sync_bindings'
        AND normalized_check.constraint_name = 'ck_suite_customer_sync_version'
        AND normalized_check.normalized_clause = 'source_version>=1')
       OR (normalized_check.table_name = 'suite_customer_sync_bindings'
        AND normalized_check.constraint_name = 'ck_suite_customer_sync_name'
        AND normalized_check.normalized_clause = 'display_name=trimdisplay_nameandchar_lengthdisplay_namebetween1and128andnotregexp_likedisplay_name,''[[:cntrl:]]''')
       OR (normalized_check.table_name = 'suite_customer_sync_bindings'
        AND normalized_check.constraint_name = 'ck_suite_customer_sync_event_uuid'
        AND normalized_check.normalized_clause = 'regexp_likelast_event_id,''^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$''')
       OR (normalized_check.table_name = 'suite_customer_sync_bindings'
        AND normalized_check.constraint_name = 'ck_suite_customer_sync_request_hash'
        AND normalized_check.normalized_clause = 'regexp_likelast_request_sha256,''^[0-9a-f]{64}$''')
       OR (normalized_check.table_name = 'suite_customer_sync_events'
        AND normalized_check.constraint_name = 'ck_suite_customer_sync_receipt_event_uuid'
        AND normalized_check.normalized_clause = 'regexp_likeevent_id,''^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$''')
       OR (normalized_check.table_name = 'suite_customer_sync_events'
        AND normalized_check.constraint_name = 'ck_suite_customer_sync_receipt_customer_uuid'
        AND normalized_check.normalized_clause = 'regexp_likecustomer_id,''^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$''')
       OR (normalized_check.table_name = 'suite_customer_sync_events'
        AND normalized_check.constraint_name = 'ck_suite_customer_sync_receipt_version'
        AND normalized_check.normalized_clause = 'source_version>=1')
       OR (normalized_check.table_name = 'suite_customer_sync_events'
        AND normalized_check.constraint_name = 'ck_suite_customer_sync_receipt_name'
        AND normalized_check.normalized_clause = 'display_name=trimdisplay_nameandchar_lengthdisplay_namebetween1and128andnotregexp_likedisplay_name,''[[:cntrl:]]''')
       OR (normalized_check.table_name = 'suite_customer_sync_events'
        AND normalized_check.constraint_name = 'ck_suite_customer_sync_receipt_hash'
        AND normalized_check.normalized_clause = 'regexp_likerequest_sha256,''^[0-9a-f]{64}$''')
     )
);
SET @suite_customer_sync_existing_trigger_count = (
  SELECT COUNT(*)
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND event_object_table IN (
       'suite_customer_sync_bindings','suite_customer_sync_events'
     )
);
SET @suite_customer_sync_existing_triggers_ok = (
  SELECT COUNT(*) = 8
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND action_orientation = 'ROW'
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
       OR (trigger_name = 'trg_suite_customer_sync_binding_no_delete'
        AND event_object_table = 'suite_customer_sync_bindings'
        AND action_timing = 'BEFORE' AND event_manipulation = 'DELETE'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = '08641adecd96e76139f1a0137efbf9a6488be28f03aa783eddef3d63b59f9956')
       OR (trigger_name = 'trg_suite_customer_sync_event_before_insert'
        AND event_object_table = 'suite_customer_sync_events'
        AND action_timing = 'BEFORE' AND event_manipulation = 'INSERT'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = '7c0d73a6146ead07feb1a811df0aec9f18e05f205a60fe59c959519c93652266')
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
     )
);
SET @suite_customer_sync_preflight_sql = IF(
  @suite_customer_sync_tables_ok = 1
  AND @suite_customer_sync_columns_ok = 1
  AND @suite_customer_sync_critical_columns_ok = 1
  AND @suite_customer_sync_column_fingerprint_ok = 1
  AND @suite_customer_sync_index_count_ok = 1
  AND @suite_customer_sync_index_shapes_ok = 1
  AND @suite_customer_sync_fk_count_ok = 1
  AND @suite_customer_sync_fks_ok = 1
  AND @suite_customer_sync_check_count_ok = 1
  AND @suite_customer_sync_checks_ok = 1
  AND (
    @suite_customer_sync_existing_trigger_count = 0
    OR (
      @suite_customer_sync_existing_trigger_count = 8
      AND @suite_customer_sync_existing_triggers_ok = 1
    )
  ),
  'DO 0',
  'SELECT * FROM information_schema.migration_015_suite_customer_sync_preflight_failed'
);
PREPARE suite_customer_sync_preflight FROM @suite_customer_sync_preflight_sql;
EXECUTE suite_customer_sync_preflight;
DEALLOCATE PREPARE suite_customer_sync_preflight;

-- Prove TRIGGER privilege before replacing any lifecycle/audit guard.
DROP TRIGGER IF EXISTS trg_suite_customer_sync_privilege_preflight;
CREATE TRIGGER trg_suite_customer_sync_privilege_preflight
BEFORE INSERT ON suite_customer_sync_events
FOR EACH ROW
SET @suite_customer_sync_trigger_privilege_preflight = 1;
DROP TRIGGER trg_suite_customer_sync_privilege_preflight;

DROP TRIGGER IF EXISTS trg_suite_customer_sync_binding_before_insert;
DROP TRIGGER IF EXISTS trg_suite_customer_sync_binding_after_insert;
DROP TRIGGER IF EXISTS trg_suite_customer_sync_binding_before_update;
DROP TRIGGER IF EXISTS trg_suite_customer_sync_binding_after_update;
DROP TRIGGER IF EXISTS trg_suite_customer_sync_binding_no_delete;
DROP TRIGGER IF EXISTS trg_suite_customer_sync_event_before_insert;
DROP TRIGGER IF EXISTS trg_suite_customer_sync_events_no_update;
DROP TRIGGER IF EXISTS trg_suite_customer_sync_events_no_delete;

DELIMITER $$
CREATE TRIGGER trg_suite_customer_sync_binding_before_insert
BEFORE INSERT ON suite_customer_sync_bindings
FOR EACH ROW
BEGIN
  IF NEW.source_version <> 1 OR NEW.status <> 'active' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer sync bindings must begin at active version 1';
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
  INSERT INTO suite_customer_sync_events
    (tenant_id, event_id, binding_id, customer_id, client_id,
     source_version, display_name, status, occurred_at, request_sha256,
     received_at)
  VALUES
    (NEW.tenant_id, NEW.last_event_id, NEW.id, NEW.customer_id, NEW.client_id,
     NEW.source_version, NEW.display_name, NEW.status, NEW.last_occurred_at,
     NEW.last_request_sha256, NEW.updated_at);
END$$

CREATE TRIGGER trg_suite_customer_sync_binding_before_update
BEFORE UPDATE ON suite_customer_sync_bindings
FOR EACH ROW
BEGIN
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
  SET NEW.updated_at = UTC_TIMESTAMP();
END$$

CREATE TRIGGER trg_suite_customer_sync_binding_after_update
AFTER UPDATE ON suite_customer_sync_bindings
FOR EACH ROW
BEGIN
  INSERT INTO suite_customer_sync_events
    (tenant_id, event_id, binding_id, customer_id, client_id,
     source_version, display_name, status, occurred_at, request_sha256,
     received_at)
  VALUES
    (NEW.tenant_id, NEW.last_event_id, NEW.id, NEW.customer_id, NEW.client_id,
     NEW.source_version, NEW.display_name, NEW.status, NEW.last_occurred_at,
     NEW.last_request_sha256, NEW.updated_at);
END$$

CREATE TRIGGER trg_suite_customer_sync_binding_no_delete
BEFORE DELETE ON suite_customer_sync_bindings
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer sync bindings cannot be deleted';
END$$

CREATE TRIGGER trg_suite_customer_sync_event_before_insert
BEFORE INSERT ON suite_customer_sync_events
FOR EACH ROW
BEGIN
  DECLARE exact_binding INT DEFAULT 0;
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
     AND BINARY sync_binding.last_request_sha256 = BINARY NEW.request_sha256;
  IF exact_binding <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer sync receipt must match the exact binding and client';
  END IF;
END$$

CREATE TRIGGER trg_suite_customer_sync_events_no_update
BEFORE UPDATE ON suite_customer_sync_events
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer sync event receipts are immutable';
END$$

CREATE TRIGGER trg_suite_customer_sync_events_no_delete
BEFORE DELETE ON suite_customer_sync_events
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer sync event receipts are immutable';
END$$
DELIMITER ;

SET @suite_customer_sync_tables_ok = (
  SELECT COUNT(*) = 2
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name IN ('suite_customer_sync_bindings','suite_customer_sync_events')
     AND engine = 'InnoDB'
);
SET @suite_customer_sync_triggers_ok = (
  SELECT COUNT(*) = 8
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND action_orientation = 'ROW'
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
       OR (trigger_name = 'trg_suite_customer_sync_binding_no_delete'
        AND event_object_table = 'suite_customer_sync_bindings'
        AND action_timing = 'BEFORE' AND event_manipulation = 'DELETE'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = '08641adecd96e76139f1a0137efbf9a6488be28f03aa783eddef3d63b59f9956')
       OR (trigger_name = 'trg_suite_customer_sync_event_before_insert'
        AND event_object_table = 'suite_customer_sync_events'
        AND action_timing = 'BEFORE' AND event_manipulation = 'INSERT'
        AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
            = '7c0d73a6146ead07feb1a811df0aec9f18e05f205a60fe59c959519c93652266')
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
     )
);
SET @suite_customer_sync_trigger_count_ok = (
  SELECT COUNT(*) = 8
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND event_object_table IN (
       'suite_customer_sync_bindings','suite_customer_sync_events'
     )
);
SET @suite_customer_sync_postflight_sql = IF(
  @suite_customer_sync_tables_ok = 1
  AND @suite_customer_sync_columns_ok = 1
  AND @suite_customer_sync_critical_columns_ok = 1
  AND @suite_customer_sync_column_fingerprint_ok = 1
  AND @suite_customer_sync_index_count_ok = 1
  AND @suite_customer_sync_index_shapes_ok = 1
  AND @suite_customer_sync_trigger_count_ok = 1
  AND @suite_customer_sync_triggers_ok = 1
  AND @suite_customer_sync_check_count_ok = 1
  AND @suite_customer_sync_checks_ok = 1
  AND @suite_customer_sync_fk_count_ok = 1
  AND @suite_customer_sync_fks_ok = 1,
  'DO 0',
  'SELECT * FROM information_schema.migration_015_suite_customer_sync_postflight_failed'
);
PREPARE suite_customer_sync_postflight FROM @suite_customer_sync_postflight_sql;
EXECUTE suite_customer_sync_postflight;
DEALLOCATE PREPARE suite_customer_sync_postflight;

SELECT
  @suite_customer_sync_tables_ok AS tables_ok,
  @suite_customer_sync_columns_ok AS columns_ok,
  @suite_customer_sync_critical_columns_ok AS critical_columns_ok,
  @suite_customer_sync_column_fingerprint_ok AS column_fingerprint_ok,
  @suite_customer_sync_index_shapes_ok AS indexes_ok,
  @suite_customer_sync_triggers_ok AS triggers_ok,
  @suite_customer_sync_checks_ok AS checks_ok,
  @suite_customer_sync_fk_count_ok AS foreign_key_count_ok,
  @suite_customer_sync_fks_ok AS foreign_keys_ok;
