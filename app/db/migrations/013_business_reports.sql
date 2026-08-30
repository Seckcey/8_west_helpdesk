-- 013_business_reports.sql — versioned, archived, delivery-tracked reports.
-- Additive MySQL 8 migration. Apply with the trigger-capable operator before
-- deploying code that reads these tables. The runtime identity receives only
-- the narrow DML grants documented in deploy/README.md.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS business_report_definition_versions (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id          INT UNSIGNED NOT NULL,
  definition_key     VARCHAR(64) NOT NULL,
  version_no         SMALLINT UNSIGNED NOT NULL,
  report_type        VARCHAR(64) NOT NULL,
  contract_json      LONGTEXT NOT NULL,
  contract_sha256    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_by_user_id INT UNSIGNED NOT NULL,
  reason             VARCHAR(500) NOT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_business_report_definition_version (tenant_id, definition_key, version_no),
  UNIQUE KEY uq_business_report_definition_tenant_id (tenant_id, id),
  KEY ix_business_report_definition_actor (tenant_id, created_by_user_id, created_at),
  CONSTRAINT fk_business_report_definition_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_business_report_definition_actor
    FOREIGN KEY (tenant_id, created_by_user_id) REFERENCES users (tenant_id, id),
  CONSTRAINT ck_business_report_definition_version CHECK (version_no >= 1),
  CONSTRAINT ck_business_report_definition_json CHECK (JSON_VALID(contract_json)),
  CONSTRAINT ck_business_report_definition_hash CHECK (contract_sha256 REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS business_report_schedule_versions (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id             INT UNSIGNED NOT NULL,
  schedule_key          VARCHAR(64) NOT NULL,
  version_no            INT UNSIGNED NOT NULL,
  definition_version_id INT UNSIGNED NOT NULL,
  client_id             INT UNSIGNED NOT NULL,
  recipient_email       VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  schedule_timezone     VARCHAR(64) NOT NULL,
  delivery_weekday      TINYINT UNSIGNED NOT NULL,
  delivery_local_time   TIME NOT NULL,
  canary                TINYINT(1) NOT NULL DEFAULT 1,
  status                ENUM('disabled','active') NOT NULL DEFAULT 'disabled',
  created_by_user_id    INT UNSIGNED NOT NULL,
  reason                VARCHAR(500) NOT NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_business_report_schedule_version (tenant_id, schedule_key, version_no),
  UNIQUE KEY uq_business_report_schedule_tenant_id (tenant_id, id),
  KEY ix_business_report_schedule_latest (tenant_id, schedule_key, version_no),
  KEY ix_business_report_schedule_client (tenant_id, client_id, created_at),
  KEY ix_business_report_schedule_definition (tenant_id, definition_version_id),
  KEY ix_business_report_schedule_actor (tenant_id, created_by_user_id, created_at),
  CONSTRAINT fk_business_report_schedule_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_business_report_schedule_definition
    FOREIGN KEY (tenant_id, definition_version_id)
    REFERENCES business_report_definition_versions (tenant_id, id),
  CONSTRAINT fk_business_report_schedule_client
    FOREIGN KEY (tenant_id, client_id) REFERENCES clients (tenant_id, id),
  CONSTRAINT fk_business_report_schedule_actor
    FOREIGN KEY (tenant_id, created_by_user_id) REFERENCES users (tenant_id, id),
  CONSTRAINT ck_business_report_schedule_version CHECK (version_no >= 1),
  CONSTRAINT ck_business_report_schedule_weekday CHECK (delivery_weekday BETWEEN 1 AND 7),
  CONSTRAINT ck_business_report_schedule_canary CHECK (canary IN (0, 1)),
  CONSTRAINT ck_business_report_schedule_recipient
    CHECK (recipient_email = LOWER(TRIM(recipient_email)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS business_report_archives (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id             INT UNSIGNED NOT NULL,
  client_id             INT UNSIGNED NOT NULL,
  schedule_key          VARCHAR(64) NOT NULL,
  schedule_version_id   INT UNSIGNED NOT NULL,
  definition_version_id INT UNSIGNED NOT NULL,
  period_start          DATETIME NOT NULL,
  period_end            DATETIME NOT NULL,
  generated_at          DATETIME NOT NULL,
  metrics_json          LONGTEXT NOT NULL,
  report_text           MEDIUMTEXT NOT NULL,
  content_sha256        CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_business_report_archive_tenant_id (tenant_id, id),
  UNIQUE KEY uq_business_report_archive_period (tenant_id, schedule_key, period_start, period_end),
  KEY ix_business_report_archive_client_period (tenant_id, client_id, period_start, period_end),
  KEY ix_business_report_archive_schedule (tenant_id, schedule_version_id),
  KEY ix_business_report_archive_definition (tenant_id, definition_version_id),
  CONSTRAINT fk_business_report_archive_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_business_report_archive_client
    FOREIGN KEY (tenant_id, client_id) REFERENCES clients (tenant_id, id),
  CONSTRAINT fk_business_report_archive_schedule
    FOREIGN KEY (tenant_id, schedule_version_id)
    REFERENCES business_report_schedule_versions (tenant_id, id),
  CONSTRAINT fk_business_report_archive_definition
    FOREIGN KEY (tenant_id, definition_version_id)
    REFERENCES business_report_definition_versions (tenant_id, id),
  CONSTRAINT ck_business_report_archive_window CHECK (period_start < period_end),
  CONSTRAINT ck_business_report_archive_generated CHECK (period_end <= generated_at),
  CONSTRAINT ck_business_report_archive_json CHECK (JSON_VALID(metrics_json)),
  CONSTRAINT ck_business_report_archive_hash CHECK (content_sha256 REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS business_report_deliveries (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id           INT UNSIGNED NOT NULL,
  archive_id          BIGINT UNSIGNED NOT NULL,
  schedule_version_id INT UNSIGNED NOT NULL,
  recipient_email     VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status              ENUM('pending','sending','submitted','uncertain') NOT NULL DEFAULT 'pending',
  lease_token_hash    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  lease_expires_at    DATETIME NULL,
  last_attempt_at     DATETIME NULL,
  submitted_at        DATETIME NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_business_report_delivery_tenant_id (tenant_id, id),
  UNIQUE KEY uq_business_report_delivery_archive (tenant_id, archive_id),
  KEY ix_business_report_delivery_status (status, lease_expires_at, id),
  KEY ix_business_report_delivery_schedule (tenant_id, schedule_version_id),
  CONSTRAINT fk_business_report_delivery_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_business_report_delivery_archive
    FOREIGN KEY (tenant_id, archive_id) REFERENCES business_report_archives (tenant_id, id),
  CONSTRAINT fk_business_report_delivery_schedule
    FOREIGN KEY (tenant_id, schedule_version_id)
    REFERENCES business_report_schedule_versions (tenant_id, id),
  CONSTRAINT ck_business_report_delivery_recipient
    CHECK (recipient_email = LOWER(TRIM(recipient_email))),
  CONSTRAINT ck_business_report_delivery_lease_hash
    CHECK (lease_token_hash IS NULL OR lease_token_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS business_report_delivery_attempts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id     INT UNSIGNED NOT NULL,
  delivery_id   BIGINT UNSIGNED NOT NULL,
  attempt_key   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  provider      ENUM('microsoft_graph') NOT NULL,
  status        ENUM('started','submitted','uncertain') NOT NULL,
  started_at    DATETIME NOT NULL,
  completed_at  DATETIME NULL,
  provider_http SMALLINT UNSIGNED NULL,
  outcome_code  VARCHAR(64) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_business_report_attempt_tenant_id (tenant_id, id),
  UNIQUE KEY uq_business_report_attempt_key (tenant_id, attempt_key),
  UNIQUE KEY uq_business_report_attempt_delivery (tenant_id, delivery_id),
  KEY ix_business_report_attempt_started (status, started_at, id),
  CONSTRAINT fk_business_report_attempt_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_business_report_attempt_delivery
    FOREIGN KEY (tenant_id, delivery_id) REFERENCES business_report_deliveries (tenant_id, id),
  CONSTRAINT ck_business_report_attempt_key CHECK (attempt_key REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT ck_business_report_attempt_http
    CHECK (provider_http IS NULL OR provider_http BETWEEN 100 AND 599)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Refuse to replace guards unless the operator can create triggers now.
DROP TRIGGER IF EXISTS trg_business_report_privilege_preflight;
CREATE TRIGGER trg_business_report_privilege_preflight
BEFORE INSERT ON business_report_definition_versions
FOR EACH ROW
SET @business_report_trigger_privilege_preflight = 1;
DROP TRIGGER trg_business_report_privilege_preflight;

DROP TRIGGER IF EXISTS trg_business_report_definitions_before_insert;
DELIMITER $$
CREATE TRIGGER trg_business_report_definitions_before_insert
BEFORE INSERT ON business_report_definition_versions
FOR EACH ROW
BEGIN
  DECLARE actor_is_authorized INT DEFAULT 0;
  DECLARE latest_version INT DEFAULT 0;

  SELECT COUNT(*)
    INTO actor_is_authorized
    FROM users
   WHERE tenant_id = NEW.tenant_id
     AND id = NEW.created_by_user_id
     AND is_active = 1
     AND role IN ('owner','admin');
  IF actor_is_authorized <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Business report definition actor must be an active owner or admin';
  END IF;
  IF SHA2(NEW.contract_json, 256) <> NEW.contract_sha256 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Business report definition hash must match exact contract bytes';
  END IF;
  SELECT COALESCE(MAX(version_no), 0)
    INTO latest_version
    FROM business_report_definition_versions
   WHERE tenant_id = NEW.tenant_id
     AND definition_key = NEW.definition_key;
  IF NEW.version_no <> latest_version + 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Business report definition versions must be sequential';
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_business_report_definitions_no_update;
CREATE TRIGGER trg_business_report_definitions_no_update
BEFORE UPDATE ON business_report_definition_versions
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Business report definitions are immutable';

DROP TRIGGER IF EXISTS trg_business_report_definitions_no_delete;
CREATE TRIGGER trg_business_report_definitions_no_delete
BEFORE DELETE ON business_report_definition_versions
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Business report definitions are immutable';

DROP TRIGGER IF EXISTS trg_business_report_schedules_no_update;
DROP TRIGGER IF EXISTS trg_business_report_schedules_before_insert;
DELIMITER $$
CREATE TRIGGER trg_business_report_schedules_before_insert
BEFORE INSERT ON business_report_schedule_versions
FOR EACH ROW
BEGIN
  DECLARE actor_is_authorized INT DEFAULT 0;
  DECLARE latest_version INT DEFAULT 0;
  DECLARE latest_status VARCHAR(16) DEFAULT NULL;
  DECLARE latest_client_id INT UNSIGNED DEFAULT NULL;
  DECLARE latest_definition_id INT UNSIGNED DEFAULT NULL;
  DECLARE latest_timezone VARCHAR(64) DEFAULT NULL;

  SELECT COUNT(*)
    INTO actor_is_authorized
    FROM users
   WHERE tenant_id = NEW.tenant_id
     AND id = NEW.created_by_user_id
     AND is_active = 1
     AND role IN ('owner','admin');
  IF actor_is_authorized <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Business report schedule actor must be an active owner or admin';
  END IF;

  SELECT COALESCE(MAX(version_no), 0)
    INTO latest_version
    FROM business_report_schedule_versions
   WHERE tenant_id = NEW.tenant_id
     AND schedule_key = NEW.schedule_key;

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
END$$
DELIMITER ;

CREATE TRIGGER trg_business_report_schedules_no_update
BEFORE UPDATE ON business_report_schedule_versions
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Business report schedules are immutable';

DROP TRIGGER IF EXISTS trg_business_report_schedules_no_delete;
CREATE TRIGGER trg_business_report_schedules_no_delete
BEFORE DELETE ON business_report_schedule_versions
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Business report schedules are immutable';

DROP TRIGGER IF EXISTS trg_business_report_archives_no_update;
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

CREATE TRIGGER trg_business_report_archives_no_update
BEFORE UPDATE ON business_report_archives
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Business report archives are immutable';

DROP TRIGGER IF EXISTS trg_business_report_archives_no_delete;
CREATE TRIGGER trg_business_report_archives_no_delete
BEFORE DELETE ON business_report_archives
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Business report archives are immutable';

DROP TRIGGER IF EXISTS trg_business_report_deliveries_before_insert;
DELIMITER $$
CREATE TRIGGER trg_business_report_deliveries_before_insert
BEFORE INSERT ON business_report_deliveries
FOR EACH ROW
BEGIN
  DECLARE archive_matches INT DEFAULT 0;

  IF NEW.status <> 'pending'
     OR NEW.lease_token_hash IS NOT NULL
     OR NEW.lease_expires_at IS NOT NULL
     OR NEW.last_attempt_at IS NOT NULL
     OR NEW.submitted_at IS NOT NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Business report deliveries must start pending';
  END IF;
  SELECT COUNT(*)
    INTO archive_matches
    FROM business_report_archives a
    JOIN business_report_schedule_versions s
      ON s.tenant_id = a.tenant_id
     AND s.id = a.schedule_version_id
   WHERE a.tenant_id = NEW.tenant_id
     AND a.id = NEW.archive_id
     AND a.schedule_version_id = NEW.schedule_version_id
     AND s.recipient_email = NEW.recipient_email
     AND s.status = 'active'
     AND s.version_no = (
       SELECT MAX(latest.version_no)
         FROM business_report_schedule_versions latest
        WHERE latest.tenant_id = s.tenant_id
          AND latest.schedule_key = s.schedule_key
     );
  IF archive_matches <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Business report delivery must match its archived schedule recipient';
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_business_report_deliveries_before_update;
DELIMITER $$
CREATE TRIGGER trg_business_report_deliveries_before_update
BEFORE UPDATE ON business_report_deliveries
FOR EACH ROW
BEGIN
  DECLARE matching_attempts INT DEFAULT 0;

  IF NOT (OLD.tenant_id <=> NEW.tenant_id)
     OR NOT (OLD.archive_id <=> NEW.archive_id)
     OR NOT (OLD.schedule_version_id <=> NEW.schedule_version_id)
     OR NOT (OLD.recipient_email <=> NEW.recipient_email)
     OR NOT (OLD.created_at <=> NEW.created_at) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Business report delivery facts are immutable';
  END IF;

  IF OLD.status = 'pending' AND NEW.status = 'sending' THEN
    IF NEW.lease_token_hash IS NULL
       OR NEW.lease_expires_at IS NULL
       OR NEW.last_attempt_at IS NULL
       OR NEW.submitted_at IS NOT NULL
       OR NEW.lease_expires_at <= NEW.last_attempt_at THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Business report send lease is invalid';
    END IF;
  ELSEIF OLD.status = 'sending' AND NEW.status = 'submitted' THEN
    IF NEW.lease_token_hash IS NOT NULL
       OR NEW.lease_expires_at IS NOT NULL
       OR NEW.submitted_at IS NULL
       OR NEW.submitted_at < NEW.last_attempt_at
       OR NOT (OLD.last_attempt_at <=> NEW.last_attempt_at) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Business report submitted state is invalid';
    END IF;
    SELECT COUNT(*)
      INTO matching_attempts
      FROM business_report_delivery_attempts
     WHERE tenant_id = NEW.tenant_id
       AND delivery_id = NEW.id
       AND status = 'submitted';
    IF matching_attempts <> 1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Business report submission requires matching attempt evidence';
    END IF;
  ELSEIF OLD.status = 'sending' AND NEW.status = 'uncertain' THEN
    IF NEW.lease_token_hash IS NOT NULL
       OR NEW.lease_expires_at IS NOT NULL
       OR NEW.submitted_at IS NOT NULL
       OR NOT (OLD.last_attempt_at <=> NEW.last_attempt_at) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Business report uncertain state is invalid';
    END IF;
    SELECT COUNT(*)
      INTO matching_attempts
      FROM business_report_delivery_attempts
     WHERE tenant_id = NEW.tenant_id
       AND delivery_id = NEW.id
       AND status = 'uncertain';
    IF matching_attempts <> 1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Business report uncertainty requires matching attempt evidence';
    END IF;
  ELSE
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Business report delivery transition is forbidden';
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_business_report_deliveries_no_delete;
CREATE TRIGGER trg_business_report_deliveries_no_delete
BEFORE DELETE ON business_report_deliveries
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Business report deliveries cannot be deleted';

DROP TRIGGER IF EXISTS trg_business_report_attempts_before_insert;
DELIMITER $$
CREATE TRIGGER trg_business_report_attempts_before_insert
BEFORE INSERT ON business_report_delivery_attempts
FOR EACH ROW
BEGIN
  DECLARE delivery_matches INT DEFAULT 0;

  IF NEW.provider <> 'microsoft_graph'
     OR NEW.status <> 'started'
     OR NEW.completed_at IS NOT NULL
     OR NEW.provider_http IS NOT NULL
     OR NEW.outcome_code IS NOT NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Business report attempts must start at the send boundary';
  END IF;
  SELECT COUNT(*)
    INTO delivery_matches
    FROM business_report_deliveries
   WHERE tenant_id = NEW.tenant_id
     AND id = NEW.delivery_id
     AND status = 'sending';
  IF delivery_matches <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Business report attempt requires a sending delivery';
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_business_report_attempts_before_update;
DELIMITER $$
CREATE TRIGGER trg_business_report_attempts_before_update
BEFORE UPDATE ON business_report_delivery_attempts
FOR EACH ROW
BEGIN
  IF NOT (OLD.tenant_id <=> NEW.tenant_id)
     OR NOT (OLD.delivery_id <=> NEW.delivery_id)
     OR NOT (OLD.attempt_key <=> NEW.attempt_key)
     OR NOT (OLD.provider <=> NEW.provider)
     OR NOT (OLD.started_at <=> NEW.started_at) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Business report attempt facts are immutable';
  END IF;

  IF OLD.status <> 'started'
     OR NEW.status NOT IN ('submitted','uncertain')
     OR NEW.completed_at IS NULL
     OR NEW.completed_at < NEW.started_at
     OR NEW.outcome_code IS NULL
     OR NEW.outcome_code NOT REGEXP '^[a-z0-9_]{1,64}$' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Business report attempt transition is invalid';
  END IF;

  IF NEW.status = 'submitted'
     AND (NEW.provider_http <> 202 OR NEW.outcome_code <> 'graph_accepted') THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Business report submission evidence must be exact';
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_business_report_attempts_no_delete;
CREATE TRIGGER trg_business_report_attempts_no_delete
BEFORE DELETE ON business_report_delivery_attempts
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Business report attempts cannot be deleted';

-- Fail the migration, rather than merely printing false, if any expected
-- object or guard is missing. The MySQL tests inspect the detailed shapes.
SET @business_report_tables_ok = (
  SELECT COUNT(*) = 5
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name IN (
       'business_report_definition_versions',
       'business_report_schedule_versions',
       'business_report_archives',
       'business_report_deliveries',
       'business_report_delivery_attempts'
     )
     AND engine = 'InnoDB'
);
SET @business_report_columns_ok = (
  SELECT COUNT(*) = 5 FROM (
    SELECT table_name, COUNT(*) AS column_count
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name IN (
         'business_report_definition_versions',
         'business_report_schedule_versions',
         'business_report_archives',
         'business_report_deliveries',
         'business_report_delivery_attempts'
       )
     GROUP BY table_name
    HAVING column_count = CASE table_name
      WHEN 'business_report_definition_versions' THEN 10
      WHEN 'business_report_schedule_versions' THEN 15
      WHEN 'business_report_archives' THEN 13
      WHEN 'business_report_deliveries' THEN 12
      WHEN 'business_report_delivery_attempts' THEN 10
      ELSE -1 END
  ) exact_column_counts
);
SET @business_report_critical_columns_ok = (
  SELECT COUNT(*) = 9
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND (
       (table_name = 'business_report_definition_versions' AND column_name = 'contract_json'
        AND column_type = 'longtext' AND is_nullable = 'NO')
       OR (table_name = 'business_report_definition_versions' AND column_name = 'contract_sha256'
        AND column_type = 'char(64)' AND character_set_name = 'ascii' AND is_nullable = 'NO')
       OR (table_name = 'business_report_schedule_versions' AND column_name = 'status'
        AND column_type = 'enum(''disabled'',''active'')' AND is_nullable = 'NO')
       OR (table_name = 'business_report_schedule_versions' AND column_name = 'recipient_email'
        AND column_type = 'varchar(190)' AND character_set_name = 'ascii' AND is_nullable = 'NO')
       OR (table_name = 'business_report_archives' AND column_name = 'metrics_json'
        AND column_type = 'longtext' AND is_nullable = 'NO')
       OR (table_name = 'business_report_archives' AND column_name = 'content_sha256'
        AND column_type = 'char(64)' AND character_set_name = 'ascii' AND is_nullable = 'NO')
       OR (table_name = 'business_report_deliveries' AND column_name = 'status'
        AND column_type = 'enum(''pending'',''sending'',''submitted'',''uncertain'')' AND is_nullable = 'NO')
       OR (table_name = 'business_report_deliveries' AND column_name = 'recipient_email'
        AND column_type = 'varchar(190)' AND character_set_name = 'ascii' AND is_nullable = 'NO')
       OR (table_name = 'business_report_delivery_attempts' AND column_name = 'status'
        AND column_type = 'enum(''started'',''submitted'',''uncertain'')' AND is_nullable = 'NO')
     )
);
SET @business_report_indexes_ok = (
  SELECT COUNT(DISTINCT CONCAT(table_name, ':', index_name)) = 27
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name IN (
       'business_report_definition_versions',
       'business_report_schedule_versions',
       'business_report_archives',
       'business_report_deliveries',
       'business_report_delivery_attempts'
     )
);
SET @business_report_fks_ok = (
  SELECT COUNT(*) = 15
    FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND constraint_type = 'FOREIGN KEY'
     AND table_name IN (
       'business_report_definition_versions',
       'business_report_schedule_versions',
       'business_report_archives',
       'business_report_deliveries',
       'business_report_delivery_attempts'
     )
);
SET @business_report_checks_ok = (
  SELECT COUNT(*) = 15
    FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND constraint_type = 'CHECK'
     AND table_name IN (
       'business_report_definition_versions',
       'business_report_schedule_versions',
       'business_report_archives',
       'business_report_deliveries',
       'business_report_delivery_attempts'
     )
);
SET @business_report_triggers_ok = (
  SELECT COUNT(*) = 14
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'trg_business_report_definitions_before_insert',
       'trg_business_report_definitions_no_update',
       'trg_business_report_definitions_no_delete',
       'trg_business_report_schedules_before_insert',
       'trg_business_report_schedules_no_update',
       'trg_business_report_schedules_no_delete',
       'trg_business_report_archives_before_insert',
       'trg_business_report_archives_no_update',
       'trg_business_report_archives_no_delete',
       'trg_business_report_deliveries_before_insert',
       'trg_business_report_deliveries_before_update',
       'trg_business_report_deliveries_no_delete',
       'trg_business_report_attempts_before_insert',
       'trg_business_report_attempts_before_update'
     )
);
-- Include the attempt delete guard separately so an accidental list edit
-- cannot turn fourteen-of-fifteen into a misleading pass.
SET @business_report_attempt_delete_ok = (
  SELECT COUNT(*) = 1
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name = 'trg_business_report_attempts_no_delete'
);
SET @business_report_postflight_sql = IF(
  @business_report_tables_ok = 1
  AND @business_report_columns_ok = 1
  AND @business_report_critical_columns_ok = 1
  AND @business_report_indexes_ok = 1
  AND @business_report_fks_ok = 1
  AND @business_report_checks_ok = 1
  AND @business_report_triggers_ok = 1
  AND @business_report_attempt_delete_ok = 1,
  'DO 0',
  'SELECT * FROM safeharbor_business_report_schema_postflight_failed'
);
PREPARE business_report_postflight FROM @business_report_postflight_sql;
EXECUTE business_report_postflight;
DEALLOCATE PREPARE business_report_postflight;

SELECT
  @business_report_tables_ok AS report_tables_ok,
  @business_report_columns_ok AS report_columns_ok,
  @business_report_critical_columns_ok AS report_critical_columns_ok,
  @business_report_indexes_ok AS report_indexes_ok,
  @business_report_fks_ok AS report_fks_ok,
  @business_report_checks_ok AS report_checks_ok,
  @business_report_triggers_ok AS report_transition_triggers_ok,
  @business_report_attempt_delete_ok AS report_attempt_delete_guard_ok;
