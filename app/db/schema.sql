-- Safeharbor — MySQL schema (MySQL 8 / MariaDB 10.4+, utf8mb4, InnoDB)
-- Create the database + user first (see deploy/README.md), then:
--   mysql -u safeharbor -p safeharbor < db/schema.sql

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- --------------------------------------------------------
-- Tenants (one MSP = one tenant; every record is tenant-scoped)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS tenants (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(128) NOT NULL,
  slug       VARCHAR(64)  NOT NULL,
  plan       ENUM('suite') NOT NULL DEFAULT 'suite',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tenants_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Users (techs who log in; portal users of the MSP)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id     INT UNSIGNED NOT NULL,
  email         VARCHAR(190) NOT NULL,
  -- 8 West ID's immutable subject. The suite identifies a person by this,
  -- never by email: an address changes, `sub` does not. NULL until the
  -- account first arrives through 8 West ID.
  suite_subject VARCHAR(64) NULL DEFAULT NULL,
  password_hash VARCHAR(255) NOT NULL,
  full_name     VARCHAR(128) NOT NULL,
  initials      VARCHAR(4)   NOT NULL DEFAULT '',
  color         CHAR(7)      NOT NULL DEFAULT '#2D8CFF',
  role          ENUM('owner','admin','tech') NOT NULL DEFAULT 'tech',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME NULL,
  onboarded_at  DATETIME NULL,   -- NULL = Westy still owes them the welcome tour
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_suite_subject (suite_subject),
  UNIQUE KEY uq_users_tenant_email (tenant_id, email),
  UNIQUE KEY uq_users_tenant_id (tenant_id, id),
  KEY ix_users_tenant (tenant_id),
  CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Clients (the businesses the MSP supports)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS clients (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   INT UNSIGNED NOT NULL,
  name        VARCHAR(128) NOT NULL,
  domain      VARCHAR(190) NOT NULL DEFAULT '',
  sla_tier    ENUM('standard','premium') NOT NULL DEFAULT 'standard',
  health      ENUM('good','watch') NOT NULL DEFAULT 'good',
  notes       TEXT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_clients_tenant_id (tenant_id, id),
  KEY ix_clients_tenant (tenant_id),
  CONSTRAINT fk_clients_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Explicit customer-portal mapping and immutable lifecycle audit (migration 012)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS customer_portal_bindings (
  id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  identity_tenant_slug     VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  tenant_id                INT UNSIGNED NOT NULL,
  client_id                INT UNSIGNED NOT NULL,
  status                   ENUM('disabled','active') NOT NULL DEFAULT 'disabled',
  prepared_by_user_id      INT UNSIGNED NOT NULL,
  prepared_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_changed_by_user_id  INT UNSIGNED NOT NULL,
  status_changed_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status_reason            VARCHAR(500) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customer_portal_identity_slug (identity_tenant_slug),
  UNIQUE KEY uq_customer_portal_client (tenant_id, client_id),
  UNIQUE KEY uq_customer_portal_binding_scope (tenant_id, client_id, id),
  KEY ix_customer_portal_prepared_by (tenant_id, prepared_by_user_id),
  KEY ix_customer_portal_changed_by (tenant_id, last_changed_by_user_id),
  CONSTRAINT ck_customer_portal_slug CHECK (
    identity_tenant_slug REGEXP '^[a-z0-9][a-z0-9-]{0,63}$'
    AND identity_tenant_slug NOT IN ('8west','internal')
  ),
  CONSTRAINT fk_customer_portal_tenant FOREIGN KEY (tenant_id)
    REFERENCES tenants (id),
  CONSTRAINT fk_customer_portal_client FOREIGN KEY (tenant_id, client_id)
    REFERENCES clients (tenant_id, id),
  CONSTRAINT fk_customer_portal_prepared_by FOREIGN KEY (tenant_id, prepared_by_user_id)
    REFERENCES users (tenant_id, id),
  CONSTRAINT fk_customer_portal_changed_by FOREIGN KEY (tenant_id, last_changed_by_user_id)
    REFERENCES users (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS customer_portal_binding_events (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id      INT UNSIGNED NOT NULL,
  client_id      INT UNSIGNED NOT NULL,
  binding_id     INT UNSIGNED NOT NULL,
  actor_user_id  INT UNSIGNED NOT NULL,
  event_kind     ENUM('prepared','enabled','disabled') NOT NULL,
  from_status    ENUM('disabled','active') NULL,
  to_status      ENUM('disabled','active') NOT NULL,
  reason         VARCHAR(500) NOT NULL,
  snapshot_json  JSON NOT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_customer_portal_events_binding (tenant_id, client_id, binding_id, id),
  KEY ix_customer_portal_events_actor (tenant_id, actor_user_id, created_at),
  CONSTRAINT fk_customer_portal_event_tenant FOREIGN KEY (tenant_id)
    REFERENCES tenants (id),
  CONSTRAINT fk_customer_portal_event_binding FOREIGN KEY (tenant_id, client_id, binding_id)
    REFERENCES customer_portal_bindings (tenant_id, client_id, id),
  CONSTRAINT fk_customer_portal_event_actor FOREIGN KEY (tenant_id, actor_user_id)
    REFERENCES users (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Prove TRIGGER privilege before replacing any lifecycle/audit guard. A
-- replay by the DML-only web identity therefore fails before a guard drops.
DROP TRIGGER IF EXISTS trg_customer_portal_privilege_preflight;
CREATE TRIGGER trg_customer_portal_privilege_preflight
BEFORE INSERT ON customer_portal_binding_events
FOR EACH ROW
SET @customer_portal_trigger_privilege_preflight = 1;
DROP TRIGGER trg_customer_portal_privilege_preflight;

DROP TRIGGER IF EXISTS trg_customer_portal_binding_before_insert;
DROP TRIGGER IF EXISTS trg_customer_portal_binding_after_insert;
DROP TRIGGER IF EXISTS trg_customer_portal_binding_before_update;
DROP TRIGGER IF EXISTS trg_customer_portal_binding_after_update;
DROP TRIGGER IF EXISTS trg_customer_portal_binding_no_delete;
DROP TRIGGER IF EXISTS trg_customer_portal_events_no_update;
DROP TRIGGER IF EXISTS trg_customer_portal_events_no_delete;

DELIMITER $$
CREATE TRIGGER trg_customer_portal_binding_before_insert
BEFORE INSERT ON customer_portal_bindings
FOR EACH ROW
BEGIN
  DECLARE actor_is_authorized INT DEFAULT 0;

  IF NEW.identity_tenant_slug NOT REGEXP '^[a-z0-9][a-z0-9-]{0,63}$'
     OR NEW.identity_tenant_slug IN ('8west','internal') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer portal identity tenant slug is invalid or reserved';
  END IF;
  IF NEW.status <> 'disabled' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer portal bindings must be prepared disabled';
  END IF;
  IF NEW.prepared_by_user_id <> NEW.last_changed_by_user_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Prepared binding actor evidence is inconsistent';
  END IF;
  SET NEW.status_reason = TRIM(NEW.status_reason);
  IF NEW.status_reason = '' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Prepared binding requires a reason';
  END IF;

  SELECT COUNT(*) INTO actor_is_authorized
    FROM users u
    JOIN clients c ON c.tenant_id = u.tenant_id AND c.id = NEW.client_id
   WHERE u.tenant_id = NEW.tenant_id
     AND u.id = NEW.prepared_by_user_id
     AND u.is_active = 1
     AND u.role IN ('owner','admin');
  IF actor_is_authorized <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer portal binding actor must be an active owner or admin';
  END IF;

  SET NEW.prepared_at = UTC_TIMESTAMP();
  SET NEW.status_changed_at = NEW.prepared_at;
END$$

CREATE TRIGGER trg_customer_portal_binding_after_insert
AFTER INSERT ON customer_portal_bindings
FOR EACH ROW
BEGIN
  INSERT INTO customer_portal_binding_events
    (tenant_id, client_id, binding_id, actor_user_id, event_kind,
     from_status, to_status, reason, snapshot_json, created_at)
  VALUES
    (NEW.tenant_id, NEW.client_id, NEW.id, NEW.prepared_by_user_id, 'prepared',
     NULL, 'disabled', NEW.status_reason,
     JSON_OBJECT(
       'binding_id', NEW.id,
       'identity_tenant_slug', NEW.identity_tenant_slug,
       'tenant_id', NEW.tenant_id,
       'client_id', NEW.client_id,
       'status', NEW.status,
       'prepared_by_user_id', NEW.prepared_by_user_id,
       'prepared_at', NEW.prepared_at,
       'last_changed_by_user_id', NEW.last_changed_by_user_id,
       'status_changed_at', NEW.status_changed_at,
       'status_reason', NEW.status_reason
     ), NEW.status_changed_at);
END$$

CREATE TRIGGER trg_customer_portal_binding_before_update
BEFORE UPDATE ON customer_portal_bindings
FOR EACH ROW
BEGIN
  DECLARE actor_is_authorized INT DEFAULT 0;

  IF NOT (
       NEW.id <=> OLD.id
   AND NEW.identity_tenant_slug <=> OLD.identity_tenant_slug
   AND NEW.tenant_id <=> OLD.tenant_id
   AND NEW.client_id <=> OLD.client_id
   AND NEW.prepared_by_user_id <=> OLD.prepared_by_user_id
   AND NEW.prepared_at <=> OLD.prepared_at
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer portal binding facts are immutable';
  END IF;
  IF NEW.status = OLD.status OR NEW.status NOT IN ('disabled','active') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer portal binding status must make one explicit transition';
  END IF;
  SET NEW.status_reason = TRIM(NEW.status_reason);
  IF NEW.status_reason = '' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer portal binding transition requires a reason';
  END IF;

  SELECT COUNT(*) INTO actor_is_authorized
    FROM users u
    JOIN clients c ON c.tenant_id = u.tenant_id AND c.id = NEW.client_id
   WHERE u.tenant_id = NEW.tenant_id
     AND u.id = NEW.last_changed_by_user_id
     AND u.is_active = 1
     AND u.role IN ('owner','admin');
  IF actor_is_authorized <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer portal binding actor must be an active owner or admin';
  END IF;

  SET NEW.status_changed_at = UTC_TIMESTAMP();
END$$

CREATE TRIGGER trg_customer_portal_binding_after_update
AFTER UPDATE ON customer_portal_bindings
FOR EACH ROW
BEGIN
  INSERT INTO customer_portal_binding_events
    (tenant_id, client_id, binding_id, actor_user_id, event_kind,
     from_status, to_status, reason, snapshot_json, created_at)
  VALUES
    (NEW.tenant_id, NEW.client_id, NEW.id, NEW.last_changed_by_user_id,
     IF(NEW.status = 'active', 'enabled', 'disabled'),
     OLD.status, NEW.status, NEW.status_reason,
     JSON_OBJECT(
       'binding_id', NEW.id,
       'identity_tenant_slug', NEW.identity_tenant_slug,
       'tenant_id', NEW.tenant_id,
       'client_id', NEW.client_id,
       'status', NEW.status,
       'prepared_by_user_id', NEW.prepared_by_user_id,
       'prepared_at', NEW.prepared_at,
       'last_changed_by_user_id', NEW.last_changed_by_user_id,
       'status_changed_at', NEW.status_changed_at,
       'status_reason', NEW.status_reason
     ), NEW.status_changed_at);
END$$

CREATE TRIGGER trg_customer_portal_binding_no_delete
BEFORE DELETE ON customer_portal_bindings
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer portal bindings cannot be deleted';
END$$

CREATE TRIGGER trg_customer_portal_events_no_update
BEFORE UPDATE ON customer_portal_binding_events
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer portal binding events are immutable';
END$$

CREATE TRIGGER trg_customer_portal_events_no_delete
BEFORE DELETE ON customer_portal_binding_events
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer portal binding events are immutable';
END$$
DELIMITER ;

-- --------------------------------------------------------
-- Versioned, archived, delivery-tracked client business reports
-- --------------------------------------------------------
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
  CONSTRAINT fk_business_report_definition_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_business_report_definition_actor FOREIGN KEY (tenant_id, created_by_user_id)
    REFERENCES users (tenant_id, id),
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
  CONSTRAINT fk_business_report_schedule_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_business_report_schedule_definition FOREIGN KEY (tenant_id, definition_version_id)
    REFERENCES business_report_definition_versions (tenant_id, id),
  CONSTRAINT fk_business_report_schedule_client FOREIGN KEY (tenant_id, client_id)
    REFERENCES clients (tenant_id, id),
  CONSTRAINT fk_business_report_schedule_actor FOREIGN KEY (tenant_id, created_by_user_id)
    REFERENCES users (tenant_id, id),
  CONSTRAINT ck_business_report_schedule_version CHECK (version_no >= 1),
  CONSTRAINT ck_business_report_schedule_weekday CHECK (delivery_weekday BETWEEN 1 AND 7),
  CONSTRAINT ck_business_report_schedule_canary CHECK (canary IN (0, 1)),
  CONSTRAINT ck_business_report_schedule_recipient CHECK (recipient_email = LOWER(TRIM(recipient_email)))
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
  CONSTRAINT fk_business_report_archive_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_business_report_archive_client FOREIGN KEY (tenant_id, client_id)
    REFERENCES clients (tenant_id, id),
  CONSTRAINT fk_business_report_archive_schedule FOREIGN KEY (tenant_id, schedule_version_id)
    REFERENCES business_report_schedule_versions (tenant_id, id),
  CONSTRAINT fk_business_report_archive_definition FOREIGN KEY (tenant_id, definition_version_id)
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
  CONSTRAINT fk_business_report_delivery_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_business_report_delivery_archive FOREIGN KEY (tenant_id, archive_id)
    REFERENCES business_report_archives (tenant_id, id),
  CONSTRAINT fk_business_report_delivery_schedule FOREIGN KEY (tenant_id, schedule_version_id)
    REFERENCES business_report_schedule_versions (tenant_id, id),
  CONSTRAINT ck_business_report_delivery_recipient CHECK (recipient_email = LOWER(TRIM(recipient_email))),
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
  CONSTRAINT fk_business_report_attempt_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_business_report_attempt_delivery FOREIGN KEY (tenant_id, delivery_id)
    REFERENCES business_report_deliveries (tenant_id, id),
  CONSTRAINT ck_business_report_attempt_key CHECK (attempt_key REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT ck_business_report_attempt_http CHECK (provider_http IS NULL OR provider_http BETWEEN 100 AND 599)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fresh installs get the same trigger privilege preflight as migration 013.
DROP TRIGGER IF EXISTS trg_business_report_privilege_preflight;
CREATE TRIGGER trg_business_report_privilege_preflight
BEFORE INSERT ON business_report_definition_versions
FOR EACH ROW
SET @business_report_trigger_privilege_preflight = 1;
DROP TRIGGER trg_business_report_privilege_preflight;

DROP TRIGGER IF EXISTS trg_business_report_definitions_before_insert;
DROP TRIGGER IF EXISTS trg_business_report_definitions_no_update;
DROP TRIGGER IF EXISTS trg_business_report_definitions_no_delete;
DROP TRIGGER IF EXISTS trg_business_report_schedules_before_insert;
DROP TRIGGER IF EXISTS trg_business_report_schedules_no_update;
DROP TRIGGER IF EXISTS trg_business_report_schedules_no_delete;
DROP TRIGGER IF EXISTS trg_business_report_archives_before_insert;
DROP TRIGGER IF EXISTS trg_business_report_archives_no_update;
DROP TRIGGER IF EXISTS trg_business_report_archives_no_delete;
DROP TRIGGER IF EXISTS trg_business_report_deliveries_before_insert;
DROP TRIGGER IF EXISTS trg_business_report_deliveries_before_update;
DROP TRIGGER IF EXISTS trg_business_report_deliveries_no_delete;
DROP TRIGGER IF EXISTS trg_business_report_attempts_before_insert;
DROP TRIGGER IF EXISTS trg_business_report_attempts_before_update;
DROP TRIGGER IF EXISTS trg_business_report_attempts_no_delete;

DELIMITER $$
CREATE TRIGGER trg_business_report_definitions_before_insert
BEFORE INSERT ON business_report_definition_versions
FOR EACH ROW
BEGIN
  DECLARE actor_is_authorized INT DEFAULT 0;
  DECLARE latest_version INT DEFAULT 0;
  SELECT COUNT(*) INTO actor_is_authorized FROM users
   WHERE tenant_id = NEW.tenant_id AND id = NEW.created_by_user_id
     AND is_active = 1 AND role IN ('owner','admin');
  IF actor_is_authorized <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report definition actor must be an active owner or admin';
  END IF;
  IF SHA2(NEW.contract_json, 256) <> NEW.contract_sha256 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report definition hash must match exact contract bytes';
  END IF;
  SELECT COALESCE(MAX(version_no), 0) INTO latest_version
    FROM business_report_definition_versions
   WHERE tenant_id = NEW.tenant_id AND definition_key = NEW.definition_key;
  IF NEW.version_no <> latest_version + 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report definition versions must be sequential';
  END IF;
END$$

CREATE TRIGGER trg_business_report_definitions_no_update
BEFORE UPDATE ON business_report_definition_versions
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report definitions are immutable'$$

CREATE TRIGGER trg_business_report_definitions_no_delete
BEFORE DELETE ON business_report_definition_versions
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report definitions are immutable'$$

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
  SELECT COUNT(*) INTO actor_is_authorized FROM users
   WHERE tenant_id = NEW.tenant_id AND id = NEW.created_by_user_id
     AND is_active = 1 AND role IN ('owner','admin');
  IF actor_is_authorized <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report schedule actor must be an active owner or admin';
  END IF;
  SELECT COALESCE(MAX(version_no), 0) INTO latest_version
    FROM business_report_schedule_versions
   WHERE tenant_id = NEW.tenant_id AND schedule_key = NEW.schedule_key;
  IF latest_version > 0 THEN
    SELECT status, client_id, definition_version_id, schedule_timezone
      INTO latest_status, latest_client_id, latest_definition_id, latest_timezone
      FROM business_report_schedule_versions
     WHERE tenant_id = NEW.tenant_id AND schedule_key = NEW.schedule_key
       AND version_no = latest_version;
  END IF;
  IF NEW.version_no <> latest_version + 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report schedule versions must be sequential';
  END IF;
  IF latest_version = 0 AND NEW.status <> 'disabled' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report schedules must start disabled';
  END IF;
  IF latest_version > 0
     AND (NEW.client_id <> latest_client_id OR NEW.definition_version_id <> latest_definition_id
          OR BINARY NEW.schedule_timezone <> BINARY latest_timezone) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report schedule keys cannot change client, definition, or timezone';
  END IF;
  IF latest_status = 'active' AND NEW.status <> 'disabled' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An active business report schedule must be explicitly disabled';
  END IF;
END$$

CREATE TRIGGER trg_business_report_schedules_no_update
BEFORE UPDATE ON business_report_schedule_versions
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report schedules are immutable'$$

CREATE TRIGGER trg_business_report_schedules_no_delete
BEFORE DELETE ON business_report_schedule_versions
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report schedules are immutable'$$

CREATE TRIGGER trg_business_report_archives_before_insert
BEFORE INSERT ON business_report_archives
FOR EACH ROW
BEGIN
  DECLARE schedule_matches INT DEFAULT 0;
  SELECT COUNT(*) INTO schedule_matches
    FROM business_report_schedule_versions s
    JOIN tenants t ON t.id = s.tenant_id
    JOIN clients c ON c.tenant_id = s.tenant_id AND c.id = s.client_id
    JOIN business_report_definition_versions d
      ON d.tenant_id = s.tenant_id AND d.id = s.definition_version_id
   WHERE s.tenant_id = NEW.tenant_id AND s.id = NEW.schedule_version_id
     AND s.schedule_key = NEW.schedule_key AND s.client_id = NEW.client_id
     AND s.definition_version_id = NEW.definition_version_id AND s.status = 'active'
     AND s.version_no = (
       SELECT MAX(latest.version_no) FROM business_report_schedule_versions latest
        WHERE latest.tenant_id = NEW.tenant_id AND latest.schedule_key = NEW.schedule_key
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
     AND JSON_UNQUOTE(JSON_EXTRACT(NEW.metrics_json, '$.generated_at'))
         = CONCAT(DATE_FORMAT(NEW.generated_at, '%Y-%m-%dT%H:%i:%s'), 'Z');
  IF schedule_matches <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report archive must match an active schedule snapshot';
  END IF;
  IF SHA2(CONCAT(NEW.metrics_json, CHAR(10), NEW.report_text), 256) <> NEW.content_sha256 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report archive hash must match exact content bytes';
  END IF;
END$$

CREATE TRIGGER trg_business_report_archives_no_update
BEFORE UPDATE ON business_report_archives
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report archives are immutable'$$

CREATE TRIGGER trg_business_report_archives_no_delete
BEFORE DELETE ON business_report_archives
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report archives are immutable'$$

CREATE TRIGGER trg_business_report_deliveries_before_insert
BEFORE INSERT ON business_report_deliveries
FOR EACH ROW
BEGIN
  DECLARE archive_matches INT DEFAULT 0;
  IF NEW.status <> 'pending' OR NEW.lease_token_hash IS NOT NULL
     OR NEW.lease_expires_at IS NOT NULL OR NEW.last_attempt_at IS NOT NULL
     OR NEW.submitted_at IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report deliveries must start pending';
  END IF;
  SELECT COUNT(*) INTO archive_matches
    FROM business_report_archives a
    JOIN business_report_schedule_versions s
      ON s.tenant_id = a.tenant_id AND s.id = a.schedule_version_id
   WHERE a.tenant_id = NEW.tenant_id AND a.id = NEW.archive_id
     AND a.schedule_version_id = NEW.schedule_version_id
     AND s.recipient_email = NEW.recipient_email
     AND s.status = 'active'
     AND s.version_no = (
       SELECT MAX(latest.version_no) FROM business_report_schedule_versions latest
        WHERE latest.tenant_id = s.tenant_id AND latest.schedule_key = s.schedule_key
     );
  IF archive_matches <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report delivery must match its archived schedule recipient';
  END IF;
END$$

CREATE TRIGGER trg_business_report_deliveries_before_update
BEFORE UPDATE ON business_report_deliveries
FOR EACH ROW
BEGIN
  DECLARE matching_attempts INT DEFAULT 0;
  IF NOT (OLD.tenant_id <=> NEW.tenant_id) OR NOT (OLD.archive_id <=> NEW.archive_id)
     OR NOT (OLD.schedule_version_id <=> NEW.schedule_version_id)
     OR NOT (OLD.recipient_email <=> NEW.recipient_email)
     OR NOT (OLD.created_at <=> NEW.created_at) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report delivery facts are immutable';
  END IF;
  IF OLD.status = 'pending' AND NEW.status = 'sending' THEN
    IF NEW.lease_token_hash IS NULL OR NEW.lease_expires_at IS NULL
       OR NEW.last_attempt_at IS NULL OR NEW.submitted_at IS NOT NULL
       OR NEW.lease_expires_at <= NEW.last_attempt_at THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report send lease is invalid';
    END IF;
  ELSEIF OLD.status = 'sending' AND NEW.status = 'submitted' THEN
    IF NEW.lease_token_hash IS NOT NULL OR NEW.lease_expires_at IS NOT NULL
       OR NEW.submitted_at IS NULL OR NEW.submitted_at < NEW.last_attempt_at
       OR NOT (OLD.last_attempt_at <=> NEW.last_attempt_at) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report submitted state is invalid';
    END IF;
    SELECT COUNT(*) INTO matching_attempts FROM business_report_delivery_attempts
     WHERE tenant_id = NEW.tenant_id AND delivery_id = NEW.id AND status = 'submitted';
    IF matching_attempts <> 1 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report submission requires matching attempt evidence';
    END IF;
  ELSEIF OLD.status = 'sending' AND NEW.status = 'uncertain' THEN
    IF NEW.lease_token_hash IS NOT NULL OR NEW.lease_expires_at IS NOT NULL
       OR NEW.submitted_at IS NOT NULL
       OR NOT (OLD.last_attempt_at <=> NEW.last_attempt_at) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report uncertain state is invalid';
    END IF;
    SELECT COUNT(*) INTO matching_attempts FROM business_report_delivery_attempts
     WHERE tenant_id = NEW.tenant_id AND delivery_id = NEW.id AND status = 'uncertain';
    IF matching_attempts <> 1 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report uncertainty requires matching attempt evidence';
    END IF;
  ELSE
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report delivery transition is forbidden';
  END IF;
END$$

CREATE TRIGGER trg_business_report_deliveries_no_delete
BEFORE DELETE ON business_report_deliveries
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report deliveries cannot be deleted'$$

CREATE TRIGGER trg_business_report_attempts_before_insert
BEFORE INSERT ON business_report_delivery_attempts
FOR EACH ROW
BEGIN
  DECLARE delivery_matches INT DEFAULT 0;
  IF NEW.provider <> 'microsoft_graph' OR NEW.status <> 'started'
     OR NEW.completed_at IS NOT NULL OR NEW.provider_http IS NOT NULL
     OR NEW.outcome_code IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report attempts must start at the send boundary';
  END IF;
  SELECT COUNT(*) INTO delivery_matches FROM business_report_deliveries
   WHERE tenant_id = NEW.tenant_id AND id = NEW.delivery_id AND status = 'sending';
  IF delivery_matches <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report attempt requires a sending delivery';
  END IF;
END$$

CREATE TRIGGER trg_business_report_attempts_before_update
BEFORE UPDATE ON business_report_delivery_attempts
FOR EACH ROW
BEGIN
  IF NOT (OLD.tenant_id <=> NEW.tenant_id) OR NOT (OLD.delivery_id <=> NEW.delivery_id)
     OR NOT (OLD.attempt_key <=> NEW.attempt_key) OR NOT (OLD.provider <=> NEW.provider)
     OR NOT (OLD.started_at <=> NEW.started_at) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report attempt facts are immutable';
  END IF;
  IF OLD.status <> 'started' OR NEW.status NOT IN ('submitted','uncertain')
     OR NEW.completed_at IS NULL OR NEW.completed_at < NEW.started_at
     OR NEW.outcome_code IS NULL OR NEW.outcome_code NOT REGEXP '^[a-z0-9_]{1,64}$' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report attempt transition is invalid';
  END IF;
  IF NEW.status = 'submitted'
     AND (NEW.provider_http <> 202 OR NEW.outcome_code <> 'graph_accepted') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report submission evidence must be exact';
  END IF;
END$$

CREATE TRIGGER trg_business_report_attempts_no_delete
BEFORE DELETE ON business_report_delivery_attempts
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report attempts cannot be deleted'$$
DELIMITER ;

-- --------------------------------------------------------
-- Versioned service-goal policies and immutable per-priority targets
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS service_goal_policy_versions (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id      INT UNSIGNED NOT NULL,
  policy_key     VARCHAR(32) NOT NULL,
  version_no     SMALLINT UNSIGNED NOT NULL,
  display_name   VARCHAR(80) NOT NULL,
  effective_from DATETIME NOT NULL,
  clock_mode     ENUM('elapsed','business_hours') NOT NULL DEFAULT 'elapsed',
  time_zone      VARCHAR(64) NOT NULL DEFAULT 'UTC',
  pause_mode     ENUM('none','waiting') NOT NULL DEFAULT 'none',
  created_by_user_id INT UNSIGNED NULL,
  reason         VARCHAR(500) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_goal_policy_version (tenant_id, policy_key, version_no),
  UNIQUE KEY uq_goal_policy_tenant_id (tenant_id, id),
  KEY ix_goal_policy_effective (tenant_id, policy_key, effective_from, version_no),
  KEY ix_goal_policy_actor (tenant_id, created_by_user_id),
  CONSTRAINT fk_goal_policy_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_goal_policy_actor FOREIGN KEY (tenant_id, created_by_user_id)
    REFERENCES users (tenant_id, id) ON UPDATE NO ACTION ON DELETE NO ACTION,
  CONSTRAINT ck_goal_policy_version_positive CHECK (version_no >= 1) ENFORCED,
  CONSTRAINT ck_goal_policy_attribution_pair CHECK (
    (created_by_user_id IS NULL AND reason IS NULL)
    OR (created_by_user_id IS NOT NULL AND reason IS NOT NULL
        AND CHAR_LENGTH(TRIM(reason)) BETWEEN 1 AND 500)
  ) ENFORCED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS service_goal_policy_targets (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id              INT UNSIGNED NOT NULL,
  policy_version_id      INT UNSIGNED NOT NULL,
  priority               ENUM('low','normal','high','urgent') NOT NULL,
  first_response_minutes INT UNSIGNED NOT NULL,
  resolution_minutes     INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_goal_target_priority (tenant_id, policy_version_id, priority),
  UNIQUE KEY uq_goal_target_tenant_id (tenant_id, id),
  KEY ix_goal_target_policy (tenant_id, policy_version_id),
  CONSTRAINT fk_goal_target_policy FOREIGN KEY (tenant_id, policy_version_id)
    REFERENCES service_goal_policy_versions (tenant_id, id),
  CONSTRAINT ck_goal_target_response_range
    CHECK (first_response_minutes BETWEEN 1 AND 525600) ENFORCED,
  CONSTRAINT ck_goal_target_resolution_null CHECK (resolution_minutes IS NULL) ENFORCED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Contacts (people at a client who open tickets)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS contacts (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id  INT UNSIGNED NOT NULL,
  name       VARCHAR(128) NOT NULL,
  email      VARCHAR(190) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_contacts_client (client_id),
  CONSTRAINT fk_contacts_client FOREIGN KEY (client_id) REFERENCES clients (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Tickets
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS tickets (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   INT UNSIGNED NOT NULL,
  client_id   INT UNSIGNED NOT NULL,
  contact_id  INT UNSIGNED NULL,
  subject     VARCHAR(190) NOT NULL,
  status      ENUM('open','in_progress','waiting','resolved') NOT NULL DEFAULT 'open',
  priority    ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  assignee_id INT UNSIGNED NULL,
  channel     ENUM('email','portal','alert','phone') NOT NULL DEFAULT 'email',
  sla_due_at  DATETIME NOT NULL,  -- elapsed-time first-response deadline (not resolution)
  service_goal_target_id INT UNSIGNED NULL, -- exact policy target captured when opened
  resurface_at DATETIME NULL,   -- waiting auto-resurface (housekeeping reopens)
  merged_into_id INT UNSIGNED NULL,   -- merged tickets keep a stub to the survivor
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tickets_tenant_id (tenant_id, id),
  KEY ix_tickets_tenant_status (tenant_id, status),
  KEY ix_tickets_client (client_id),
  KEY ix_tickets_assignee (assignee_id),
  KEY ix_tickets_service_goal_target (tenant_id, service_goal_target_id),
  FULLTEXT ft_tickets_subject (subject),
  CONSTRAINT fk_tickets_tenant   FOREIGN KEY (tenant_id)   REFERENCES tenants (id),
  CONSTRAINT fk_tickets_client   FOREIGN KEY (client_id)   REFERENCES clients (id),
  CONSTRAINT fk_tickets_contact  FOREIGN KEY (contact_id)  REFERENCES contacts (id),
  CONSTRAINT fk_tickets_assignee FOREIGN KEY (assignee_id) REFERENCES users (id),
  CONSTRAINT fk_tickets_service_goal_target
    FOREIGN KEY (tenant_id, service_goal_target_id)
    REFERENCES service_goal_policy_targets (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ticket snapshots remain truthful only while their referenced policy rows
-- are insert-only. Publish a new version; never rewrite or remove history.
-- Prove this connection can create triggers before replacing any existing
-- guard; an under-privileged replay must fail without weakening history.
DROP TRIGGER IF EXISTS trg_goal_policy_privilege_preflight;
CREATE TRIGGER trg_goal_policy_privilege_preflight
BEFORE INSERT ON service_goal_policy_versions
FOR EACH ROW
SET @goal_trigger_privilege_preflight = 1;
DROP TRIGGER trg_goal_policy_privilege_preflight;

DROP TRIGGER IF EXISTS trg_goal_policy_versions_before_insert;
DROP TRIGGER IF EXISTS trg_goal_policy_targets_before_insert;

DELIMITER $$
CREATE TRIGGER trg_goal_policy_versions_before_insert
BEFORE INSERT ON service_goal_policy_versions
FOR EACH ROW
BEGIN
  DECLARE latest_version_no INT DEFAULT 0;
  DECLARE latest_effective_from DATETIME DEFAULT NULL;
  DECLARE actor_is_authorized INT DEFAULT 0;

  IF NOT (
       (BINARY NEW.policy_key = BINARY 'standard' AND BINARY NEW.display_name = BINARY 'Standard')
    OR (BINARY NEW.policy_key = BINARY 'premium' AND BINARY NEW.display_name = BINARY 'Premium')
  )
     OR NEW.clock_mode <> 'elapsed'
     OR BINARY NEW.time_zone <> BINARY 'UTC'
     OR NEW.pause_mode <> 'none' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Service-goal policy shape is unsupported';
  END IF;

  SELECT version_no, effective_from
    INTO latest_version_no, latest_effective_from
    FROM service_goal_policy_versions
   WHERE tenant_id = NEW.tenant_id
     AND policy_key = NEW.policy_key
   ORDER BY version_no DESC
   LIMIT 1;

  IF NEW.version_no = 1 THEN
    IF latest_version_no <> 0
       OR NEW.effective_from <> '1970-01-01 00:00:00'
       OR NEW.created_by_user_id IS NOT NULL
       OR NEW.reason IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Service-goal v1 is reserved for the exact lazy baseline';
    END IF;
  ELSE
    SET NEW.reason = TRIM(NEW.reason);
    IF NEW.version_no <> latest_version_no + 1 OR latest_version_no < 1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Service-goal policy versions must be sequential';
    END IF;
    IF NEW.effective_from <= UTC_TIMESTAMP()
       OR NEW.effective_from <= latest_effective_from THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Service-goal policy effective time must move forward in the future';
    END IF;
    IF NEW.created_by_user_id IS NULL OR NEW.reason IS NULL OR NEW.reason = '' THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Service-goal publication requires actor and reason';
    END IF;
    SELECT COUNT(*) INTO actor_is_authorized
      FROM users
     WHERE tenant_id = NEW.tenant_id
       AND id = NEW.created_by_user_id
       AND is_active = 1
       AND role IN ('owner','admin');
    IF actor_is_authorized <> 1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Service-goal actor must be an active owner or admin';
    END IF;
  END IF;

  SET NEW.created_at = UTC_TIMESTAMP();
END$$

CREATE TRIGGER trg_goal_policy_targets_before_insert
BEFORE INSERT ON service_goal_policy_targets
FOR EACH ROW
BEGIN
  DECLARE parent_count INT DEFAULT 0;
  DECLARE parent_policy_key VARCHAR(32) DEFAULT NULL;
  DECLARE parent_version_no INT DEFAULT 0;
  DECLARE parent_effective_from DATETIME DEFAULT NULL;

  SELECT COUNT(*), MAX(policy_key), MAX(version_no), MAX(effective_from)
    INTO parent_count, parent_policy_key, parent_version_no, parent_effective_from
    FROM service_goal_policy_versions
   WHERE tenant_id = NEW.tenant_id
     AND id = NEW.policy_version_id;
  IF parent_count <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Service-goal target parent must belong to the exact tenant';
  END IF;
  IF NEW.first_response_minutes < 1
     OR NEW.first_response_minutes > 525600
     OR NEW.resolution_minutes IS NOT NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Service-goal targets require a positive response and no resolution goal';
  END IF;
  IF parent_version_no = 1
     AND NEW.first_response_minutes <> CASE parent_policy_key
          WHEN 'standard' THEN 480 WHEN 'premium' THEN 120 ELSE 0 END THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Service-goal v1 target must match the exact lazy baseline';
  END IF;
  IF parent_version_no > 1 AND parent_effective_from <= UTC_TIMESTAMP() THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Service-goal targets must be completed before the version becomes effective';
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_goal_policy_versions_no_update;
CREATE TRIGGER trg_goal_policy_versions_no_update
BEFORE UPDATE ON service_goal_policy_versions
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Service-goal policy versions are immutable';

DROP TRIGGER IF EXISTS trg_goal_policy_versions_no_delete;
CREATE TRIGGER trg_goal_policy_versions_no_delete
BEFORE DELETE ON service_goal_policy_versions
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Service-goal policy versions are immutable';

DROP TRIGGER IF EXISTS trg_goal_policy_targets_no_update;
CREATE TRIGGER trg_goal_policy_targets_no_update
BEFORE UPDATE ON service_goal_policy_targets
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Service-goal policy targets are immutable';

DROP TRIGGER IF EXISTS trg_goal_policy_targets_no_delete;
CREATE TRIGGER trg_goal_policy_targets_no_delete
BEFORE DELETE ON service_goal_policy_targets
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Service-goal policy targets are immutable';

-- --------------------------------------------------------
-- Messages (conversation thread + internal notes + system lines)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS messages (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id   INT UNSIGNED NOT NULL,
  author_name VARCHAR(128) NOT NULL,
  kind        ENUM('client','tech','note','system') NOT NULL DEFAULT 'tech',
  body        TEXT NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_messages_ticket (ticket_id),
  FULLTEXT ft_messages_body (body),
  CONSTRAINT fk_messages_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Ticket presence (collision detection heartbeats; stale after ~40s)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS ticket_presence (
  ticket_id INT UNSIGNED NOT NULL,
  user_id   INT UNSIGNED NOT NULL,
  mode      ENUM('viewing','typing') NOT NULL DEFAULT 'viewing',
  last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (ticket_id, user_id),
  CONSTRAINT fk_presence_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE,
  CONSTRAINT fk_presence_user   FOREIGN KEY (user_id)   REFERENCES users (id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Attachments (uploads + inbound email files; bytes outside the deploy tree)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS attachments (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id   INT UNSIGNED NOT NULL,
  message_id  INT UNSIGNED NULL,
  filename    VARCHAR(190) NOT NULL,
  mime        VARCHAR(100) NOT NULL DEFAULT 'application/octet-stream',
  size_bytes  INT UNSIGNED NOT NULL DEFAULT 0,
  stored_name CHAR(40) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_att_ticket (ticket_id),
  KEY ix_att_message (message_id),
  CONSTRAINT fk_att_ticket  FOREIGN KEY (ticket_id)  REFERENCES tickets (id),
  CONSTRAINT fk_att_message FOREIGN KEY (message_id) REFERENCES messages (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Email conversation threading (Graph conversationId → ticket) + dedupe
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_threads (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id       INT UNSIGNED NOT NULL,
  conversation_id VARCHAR(190) NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_thread_conv (conversation_id),
  KEY ix_thread_ticket (ticket_id),
  CONSTRAINT fk_thread_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS processed_mail (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  internet_message_id VARCHAR(255) NOT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pm_id (internet_message_id(190))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Canned responses (saved replies; merge fields resolve at insert)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS canned_responses (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  INT UNSIGNED NOT NULL,
  title      VARCHAR(80)  NOT NULL,
  body       TEXT NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_canned_tenant (tenant_id),
  CONSTRAINT fk_canned_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_canned_user   FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Westy usage/audit (lengths + outcomes only, never message content;
-- drives the per-user rate limit in api/westy_chat.php)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS assistant_log (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  action     VARCHAR(32)  NOT NULL,
  meta       VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_assistant_user_action_created (user_id, action, created_at),
  CONSTRAINT fk_assistant_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- CSAT (one-click resolution surveys; token is the whole auth)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS csat (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id    INT UNSIGNED NOT NULL,
  token        CHAR(40) NOT NULL,
  score        TINYINT UNSIGNED NULL,          -- 1 rough · 2 okay · 3 great
  comment      VARCHAR(500) NOT NULL DEFAULT '',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  responded_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_csat_ticket (ticket_id),
  UNIQUE KEY uq_csat_token (token),
  CONSTRAINT fk_csat_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Approval-grade technician time. Facts are immutable after logging; a
-- separate review transition decides whether a billable row may leave
-- Safeharbor for a downstream draft-invoice seam.
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS time_entries (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id           INT UNSIGNED NOT NULL,
  client_id           INT UNSIGNED NOT NULL,
  entry_key           VARCHAR(64) NOT NULL,
  ticket_id           INT UNSIGNED NOT NULL,
  user_id             INT UNSIGNED NOT NULL,
  minutes             INT UNSIGNED NOT NULL,
  note                VARCHAR(255) NOT NULL DEFAULT '',
  billable            TINYINT(1) NOT NULL DEFAULT 1,
  source              ENUM('timer','reply','suggestion','legacy') NOT NULL DEFAULT 'legacy',
  worked_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at          DATETIME NULL,
  ended_at            DATETIME NULL,
  approval_status     ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reviewed_by_user_id INT UNSIGNED NULL,
  reviewed_at         DATETIME NULL,
  review_note         VARCHAR(500) NOT NULL DEFAULT '',
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_time_entries_tenant_key (tenant_id, entry_key),
  UNIQUE KEY uq_time_entries_tenant_id (tenant_id, id),
  KEY ix_time_ticket (ticket_id),
  KEY ix_time_user_created (user_id, created_at),
  KEY ix_time_entries_ticket (tenant_id, ticket_id),
  KEY ix_time_entries_user_worked (tenant_id, user_id, worked_at),
  KEY ix_time_entries_client_status (tenant_id, client_id, approval_status, worked_at),
  KEY ix_time_entries_approval_queue (tenant_id, approval_status, worked_at, id),
  KEY ix_time_entries_reviewer (tenant_id, reviewed_by_user_id, reviewed_at),
  CONSTRAINT ck_time_entries_minutes CHECK (minutes BETWEEN 1 AND 1440),
  CONSTRAINT ck_time_entries_billable CHECK (billable IN (0, 1)),
  CONSTRAINT fk_time_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id),
  CONSTRAINT fk_time_user   FOREIGN KEY (user_id)   REFERENCES users (id),
  CONSTRAINT fk_time_entries_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_time_entries_ticket_tenant FOREIGN KEY (tenant_id, ticket_id)
    REFERENCES tickets (tenant_id, id),
  CONSTRAINT fk_time_entries_client_tenant FOREIGN KEY (tenant_id, client_id)
    REFERENCES clients (tenant_id, id),
  CONSTRAINT fk_time_entries_user_tenant FOREIGN KEY (tenant_id, user_id)
    REFERENCES users (tenant_id, id),
  CONSTRAINT fk_time_entries_reviewer_tenant FOREIGN KEY (tenant_id, reviewed_by_user_id)
    REFERENCES users (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS time_entry_events (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id     INT UNSIGNED NOT NULL,
  time_entry_id INT UNSIGNED NOT NULL,
  actor_user_id INT UNSIGNED NOT NULL,
  event_kind    ENUM('logged','approved','rejected') NOT NULL,
  from_status   ENUM('pending','approved','rejected') NULL,
  to_status     ENUM('pending','approved','rejected') NOT NULL,
  reason        VARCHAR(500) NOT NULL DEFAULT '',
  snapshot_json JSON NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_time_entry_events_entry_kind (tenant_id, time_entry_id, event_kind),
  KEY ix_time_entry_events_entry (tenant_id, time_entry_id, id),
  KEY ix_time_entry_events_actor_created (tenant_id, actor_user_id, created_at),
  CONSTRAINT fk_time_entry_events_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_time_entry_events_entry FOREIGN KEY (tenant_id, time_entry_id)
    REFERENCES time_entries (tenant_id, id),
  CONSTRAINT fk_time_entry_events_actor FOREIGN KEY (tenant_id, actor_user_id)
    REFERENCES users (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Prove TRIGGER privilege before replacing any audit guard. The migration
-- uses the same preflight before installing these canonical fresh-schema
-- definitions.
DROP TRIGGER IF EXISTS trg_time_entry_privilege_preflight;
CREATE TRIGGER trg_time_entry_privilege_preflight
BEFORE INSERT ON time_entries
FOR EACH ROW
SET @time_entry_trigger_privilege_preflight = 1;
DROP TRIGGER trg_time_entry_privilege_preflight;

DELIMITER $$
CREATE TRIGGER trg_time_entries_before_insert
BEFORE INSERT ON time_entries
FOR EACH ROW
BEGIN
  DECLARE ticket_tenant_id INT UNSIGNED;
  DECLARE ticket_client_id INT UNSIGNED;

  SELECT tenant_id, client_id
    INTO ticket_tenant_id, ticket_client_id
    FROM tickets
   WHERE id = NEW.ticket_id;

  IF ticket_tenant_id IS NULL OR ticket_client_id IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry ticket does not exist';
  END IF;
  IF NEW.tenant_id IS NOT NULL AND NEW.tenant_id <> ticket_tenant_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry tenant must match ticket';
  END IF;
  IF NEW.client_id IS NOT NULL AND NEW.client_id <> ticket_client_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry client must match ticket';
  END IF;

  SET NEW.tenant_id = ticket_tenant_id;
  SET NEW.client_id = ticket_client_id;
  SET NEW.entry_key = COALESCE(NULLIF(TRIM(NEW.entry_key), ''), CONCAT('legacy:', UUID()));
  SET NEW.source = COALESCE(NEW.source, 'legacy');
  SET NEW.worked_at = COALESCE(NEW.worked_at, UTC_TIMESTAMP());
  SET NEW.approval_status = COALESCE(NEW.approval_status, 'pending');
  SET NEW.review_note = COALESCE(NEW.review_note, '');

  IF NEW.approval_status <> 'pending' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'New time entries must be pending';
  END IF;
  IF NEW.reviewed_by_user_id IS NOT NULL OR NEW.reviewed_at IS NOT NULL
     OR NEW.review_note <> '' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'New time entries cannot be pre-reviewed';
  END IF;
  IF (NEW.started_at IS NULL) <> (NEW.ended_at IS NULL)
     OR (NEW.started_at IS NOT NULL AND NEW.ended_at < NEW.started_at) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry interval is invalid';
  END IF;
  IF NEW.source = 'timer' AND NEW.started_at IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Timer time requires start and end evidence';
  END IF;
  IF NEW.source = 'suggestion' AND NEW.started_at IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Suggested time cannot claim timer evidence';
  END IF;
  IF NEW.started_at IS NOT NULL THEN
    IF TIMESTAMPDIFF(SECOND, NEW.started_at, NEW.ended_at) > 86400 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry interval cannot exceed 24 hours';
    END IF;
    IF NEW.worked_at < NEW.started_at OR NEW.worked_at > NEW.ended_at THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Worked time must fall inside measured interval';
    END IF;
    IF ABS(
         NEW.minutes
         - ROUND(TIMESTAMPDIFF(SECOND, NEW.started_at, NEW.ended_at) / 60.0)
       ) > 1 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Minutes must match measured interval';
    END IF;
  END IF;
END$$

CREATE TRIGGER trg_time_entries_after_insert
AFTER INSERT ON time_entries
FOR EACH ROW
BEGIN
  INSERT INTO time_entry_events
    (tenant_id, time_entry_id, actor_user_id, event_kind, from_status,
     to_status, reason, snapshot_json, created_at)
  VALUES
    (NEW.tenant_id, NEW.id, NEW.user_id, 'logged', NULL, 'pending', '',
     JSON_OBJECT(
       'id', NEW.id,
       'tenant_id', NEW.tenant_id,
       'client_id', NEW.client_id,
       'entry_key', NEW.entry_key,
       'ticket_id', NEW.ticket_id,
       'user_id', NEW.user_id,
       'minutes', NEW.minutes,
       'note', NEW.note,
       'billable', NEW.billable,
       'source', NEW.source,
       'worked_at', NEW.worked_at,
       'started_at', NEW.started_at,
       'ended_at', NEW.ended_at,
       'approval_status', NEW.approval_status,
       'reviewed_by_user_id', NEW.reviewed_by_user_id,
       'reviewed_at', NEW.reviewed_at,
       'review_note', NEW.review_note,
       'created_at', NEW.created_at
     ), UTC_TIMESTAMP());
END$$

CREATE TRIGGER trg_time_entries_before_update
BEFORE UPDATE ON time_entries
FOR EACH ROW
BEGIN
  DECLARE reviewer_is_authorized INT DEFAULT 0;

  IF NOT (
       NEW.tenant_id <=> OLD.tenant_id
   AND NEW.client_id <=> OLD.client_id
   AND NEW.entry_key <=> OLD.entry_key
   AND NEW.ticket_id <=> OLD.ticket_id
   AND NEW.user_id <=> OLD.user_id
   AND NEW.minutes <=> OLD.minutes
   AND NEW.note <=> OLD.note
   AND NEW.billable <=> OLD.billable
   AND NEW.source <=> OLD.source
   AND NEW.worked_at <=> OLD.worked_at
   AND NEW.started_at <=> OLD.started_at
   AND NEW.ended_at <=> OLD.ended_at
   AND NEW.created_at <=> OLD.created_at
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry facts are immutable';
  END IF;

  IF OLD.approval_status <> 'pending'
     OR NEW.approval_status NOT IN ('approved', 'rejected') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only pending time entries may be reviewed';
  END IF;
  IF NEW.reviewed_by_user_id IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry review requires a reviewer';
  END IF;

  SELECT COUNT(*)
    INTO reviewer_is_authorized
    FROM users
   WHERE tenant_id = NEW.tenant_id
     AND id = NEW.reviewed_by_user_id
     AND is_active = 1
     AND role IN ('owner', 'admin');
  IF reviewer_is_authorized <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry reviewer must be an active owner or admin';
  END IF;

  SET NEW.reviewed_at = UTC_TIMESTAMP();
  SET NEW.review_note = TRIM(COALESCE(NEW.review_note, ''));
  IF NEW.approval_status = 'rejected' AND NEW.review_note = '' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rejected time requires a reason';
  END IF;
END$$

CREATE TRIGGER trg_time_entries_after_update
AFTER UPDATE ON time_entries
FOR EACH ROW
BEGIN
  INSERT INTO time_entry_events
    (tenant_id, time_entry_id, actor_user_id, event_kind, from_status,
     to_status, reason, snapshot_json, created_at)
  VALUES
    (NEW.tenant_id, NEW.id, NEW.reviewed_by_user_id, NEW.approval_status,
     OLD.approval_status, NEW.approval_status, NEW.review_note,
     JSON_OBJECT(
       'id', NEW.id,
       'tenant_id', NEW.tenant_id,
       'client_id', NEW.client_id,
       'entry_key', NEW.entry_key,
       'ticket_id', NEW.ticket_id,
       'user_id', NEW.user_id,
       'minutes', NEW.minutes,
       'note', NEW.note,
       'billable', NEW.billable,
       'source', NEW.source,
       'worked_at', NEW.worked_at,
       'started_at', NEW.started_at,
       'ended_at', NEW.ended_at,
       'approval_status', NEW.approval_status,
       'reviewed_by_user_id', NEW.reviewed_by_user_id,
       'reviewed_at', NEW.reviewed_at,
       'review_note', NEW.review_note,
       'created_at', NEW.created_at
     ), NEW.reviewed_at);
END$$

CREATE TRIGGER trg_time_entries_no_delete
BEFORE DELETE ON time_entries
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entries cannot be deleted';
END$$

CREATE TRIGGER trg_time_entry_events_no_update
BEFORE UPDATE ON time_entry_events
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry events are immutable';
END$$

CREATE TRIGGER trg_time_entry_events_no_delete
BEFORE DELETE ON time_entry_events
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry events are immutable';
END$$
DELIMITER ;

-- --------------------------------------------------------
-- Westy reports (failure + flagged-answer intake; migration 008)
-- One row per PROBLEM, not per occurrence — see db/migrations/008_westy_reports.sql
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS westy_reports (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id         INT UNSIGNED NOT NULL,
  fingerprint       VARCHAR(48)  NOT NULL,
  external_key      VARCHAR(64)  NOT NULL,
  app               VARCHAR(32)  NOT NULL,
  kind              ENUM('fail','flag') NOT NULL,
  generation        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  ticket_id         INT UNSIGNED NULL,
  system_message_id INT UNSIGNED NULL,
  occurrences       INT UNSIGNED NOT NULL DEFAULT 1,
  first_seen_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  detail_json       TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_westy_reports_key (tenant_id, external_key),
  KEY idx_westy_reports_fp (tenant_id, fingerprint, generation),
  KEY idx_westy_reports_ticket (ticket_id),
  CONSTRAINT fk_westy_reports_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
