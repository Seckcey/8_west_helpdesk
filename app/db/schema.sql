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
-- Milepost customer registry bindings + immutable receipts (migration 015)
-- --------------------------------------------------------
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
  ),
  CONSTRAINT ck_suite_customer_sync_version CHECK (source_version >= 1),
  CONSTRAINT ck_suite_customer_sync_name CHECK (
    display_name = TRIM(display_name)
    AND CHAR_LENGTH(display_name) BETWEEN 1 AND 128
    AND NOT REGEXP_LIKE(display_name, _utf8mb4'[[:cntrl:]]')
  ),
  CONSTRAINT ck_suite_customer_sync_event_uuid CHECK (
    REGEXP_LIKE(last_event_id, _ascii'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')
  ),
  CONSTRAINT ck_suite_customer_sync_request_hash CHECK (
    REGEXP_LIKE(last_request_sha256, _ascii'^[0-9a-f]{64}$')
  )
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
  ),
  CONSTRAINT ck_suite_customer_sync_receipt_customer_uuid CHECK (
    REGEXP_LIKE(customer_id, _ascii'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')
  ),
  CONSTRAINT ck_suite_customer_sync_receipt_version CHECK (source_version >= 1),
  CONSTRAINT ck_suite_customer_sync_receipt_name CHECK (
    display_name = TRIM(display_name)
    AND CHAR_LENGTH(display_name) BETWEEN 1 AND 128
    AND NOT REGEXP_LIKE(display_name, _utf8mb4'[[:cntrl:]]')
  ),
  CONSTRAINT ck_suite_customer_sync_receipt_hash CHECK (
    REGEXP_LIKE(request_sha256, _ascii'^[0-9a-f]{64}$')
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A DML-only deployment identity must fail before existing guards are dropped.
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
  CONSTRAINT fk_business_report_id_binding_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_business_report_id_binding_actor FOREIGN KEY (tenant_id, created_by_user_id)
    REFERENCES users (tenant_id, id),
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
  CONSTRAINT fk_business_report_id_snapshot_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_business_report_id_snapshot_schedule FOREIGN KEY (tenant_id, schedule_version_id)
    REFERENCES business_report_schedule_versions (tenant_id, id),
  CONSTRAINT fk_business_report_id_snapshot_binding FOREIGN KEY (tenant_id, id_tenant_key)
    REFERENCES business_report_id_tenant_bindings (tenant_id, id_tenant_key),
  CONSTRAINT fk_business_report_id_snapshot_actor FOREIGN KEY (tenant_id, created_by_user_id)
    REFERENCES users (tenant_id, id),
  CONSTRAINT ck_business_report_id_snapshot_version CHECK (contact_version >= 1),
  CONSTRAINT ck_business_report_id_snapshot_recipient
    CHECK (recipient_email = LOWER(TRIM(recipient_email))),
  CONSTRAINT ck_business_report_id_snapshot_nonce_hash
    CHECK (request_nonce_sha256 REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT ck_business_report_id_snapshot_response_hash
    CHECK (response_sha256 REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
  CONSTRAINT fk_br_id_client_binding_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_br_id_client_binding_client FOREIGN KEY (tenant_id, client_id)
    REFERENCES clients (tenant_id, id),
  CONSTRAINT fk_br_id_client_binding_actor FOREIGN KEY (tenant_id, created_by_user_id)
    REFERENCES users (tenant_id, id),
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
  CONSTRAINT fk_br_id_client_snapshot_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_br_id_client_snapshot_client FOREIGN KEY (tenant_id, client_id)
    REFERENCES clients (tenant_id, id),
  CONSTRAINT fk_br_id_client_snapshot_schedule FOREIGN KEY (tenant_id, schedule_version_id)
    REFERENCES business_report_schedule_versions (tenant_id, id),
  CONSTRAINT fk_br_id_client_snapshot_binding FOREIGN KEY (tenant_id, client_id, id_tenant_key)
    REFERENCES business_report_id_client_bindings (tenant_id, client_id, id_tenant_key),
  CONSTRAINT fk_br_id_client_snapshot_actor FOREIGN KEY (tenant_id, created_by_user_id)
    REFERENCES users (tenant_id, id),
  CONSTRAINT ck_br_id_client_snapshot_version CHECK (contact_version >= 1),
  CONSTRAINT ck_br_id_client_snapshot_recipient
    CHECK (recipient_email = LOWER(TRIM(recipient_email))),
  CONSTRAINT ck_br_id_client_snapshot_nonce_hash
    CHECK (request_nonce_sha256 REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT ck_br_id_client_snapshot_response_hash
    CHECK (response_sha256 REGEXP '^[0-9a-f]{64}$')
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
DROP TRIGGER IF EXISTS trg_business_report_contact_scope_before_insert;
DROP TRIGGER IF EXISTS trg_business_report_contact_scope_no_update;
DROP TRIGGER IF EXISTS trg_business_report_contact_scope_no_delete;
DROP TRIGGER IF EXISTS trg_business_report_schedules_before_insert;
DROP TRIGGER IF EXISTS trg_business_report_schedules_no_update;
DROP TRIGGER IF EXISTS trg_business_report_schedules_no_delete;
DROP TRIGGER IF EXISTS trg_business_report_id_binding_before_insert;
DROP TRIGGER IF EXISTS trg_business_report_id_binding_no_update;
DROP TRIGGER IF EXISTS trg_business_report_id_binding_no_delete;
DROP TRIGGER IF EXISTS trg_business_report_id_snapshot_before_insert;
DROP TRIGGER IF EXISTS trg_business_report_id_snapshot_no_update;
DROP TRIGGER IF EXISTS trg_business_report_id_snapshot_no_delete;
DROP TRIGGER IF EXISTS trg_br_id_client_binding_before_insert;
DROP TRIGGER IF EXISTS trg_br_id_client_binding_no_update;
DROP TRIGGER IF EXISTS trg_br_id_client_binding_no_delete;
DROP TRIGGER IF EXISTS trg_br_id_client_snapshot_before_insert;
DROP TRIGGER IF EXISTS trg_br_id_client_snapshot_no_update;
DROP TRIGGER IF EXISTS trg_br_id_client_snapshot_no_delete;
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

CREATE TRIGGER trg_business_report_contact_scope_before_insert
BEFORE INSERT ON business_report_contact_scope_bindings
FOR EACH ROW
BEGIN
  DECLARE actor_is_authorized INT DEFAULT 0;
  DECLARE schedule_history INT DEFAULT 0;
  SELECT COUNT(*) INTO actor_is_authorized
    FROM users
   WHERE tenant_id = NEW.tenant_id AND id = NEW.created_by_user_id
     AND is_active = 1 AND role IN ('owner','admin');
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
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report contact scopes are immutable'$$

CREATE TRIGGER trg_business_report_contact_scope_no_delete
BEFORE DELETE ON business_report_contact_scope_bindings
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report contact scopes are immutable'$$

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
  SELECT COUNT(*) INTO actor_is_authorized FROM users
   WHERE tenant_id = NEW.tenant_id AND id = NEW.created_by_user_id
     AND is_active = 1 AND role IN ('owner','admin');
  IF actor_is_authorized <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report schedule actor must be an active owner or admin';
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

CREATE TRIGGER trg_business_report_schedules_no_update
BEFORE UPDATE ON business_report_schedule_versions
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report schedules are immutable'$$

CREATE TRIGGER trg_business_report_schedules_no_delete
BEFORE DELETE ON business_report_schedule_versions
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business report schedules are immutable'$$

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

CREATE TRIGGER trg_business_report_id_binding_no_update
BEFORE UPDATE ON business_report_id_tenant_bindings
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '8 West ID report bindings are immutable'$$

CREATE TRIGGER trg_business_report_id_binding_no_delete
BEFORE DELETE ON business_report_id_tenant_bindings
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '8 West ID report bindings are immutable'$$

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
  SELECT COALESCE(MAX(contact_version), 0) INTO latest_contact_version
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
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '8 West ID contact snapshots are immutable'$$

CREATE TRIGGER trg_business_report_id_snapshot_no_delete
BEFORE DELETE ON business_report_id_contact_snapshots
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '8 West ID contact snapshots are immutable'$$

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
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Client report bindings are immutable'$$

CREATE TRIGGER trg_br_id_client_binding_no_delete
BEFORE DELETE ON business_report_id_client_bindings
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Client report bindings are immutable'$$

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
  SELECT COALESCE(MAX(contact_version), 0) INTO latest_contact_version
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
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Client contact snapshots are immutable'$$

CREATE TRIGGER trg_br_id_client_snapshot_no_delete
BEFORE DELETE ON business_report_id_client_contact_snapshots
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Client contact snapshots are immutable'$$

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
     AND BINARY JSON_UNQUOTE(JSON_EXTRACT(NEW.metrics_json, '$.period.schedule_timezone'))
         = BINARY s.schedule_timezone
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
-- Atomic managed-customer portal/report activation receipts (migration 022)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS managed_customer_activation_receipts (
  id                           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id                    INT UNSIGNED NOT NULL,
  client_id                    INT UNSIGNED NOT NULL,
  source_binding_id            BIGINT UNSIGNED NOT NULL,
  customer_id                  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_version               BIGINT UNSIGNED NOT NULL,
  customer_receipt_id          CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  id_tenant_key                VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  identity_tenant_slug         VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  contact_version              BIGINT UNSIGNED NOT NULL,
  portal_binding_id            INT UNSIGNED NOT NULL,
  schedule_key                 VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  prepared_schedule_version_id INT UNSIGNED NOT NULL,
  active_schedule_version_id   INT UNSIGNED NOT NULL,
  actor_user_id                INT UNSIGNED NOT NULL,
  id_response_sha256           CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  recipient_sha256             CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  evidence_sha256              CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at                   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mc_activation_customer (customer_id),
  UNIQUE KEY uq_mc_activation_client (tenant_id, client_id),
  UNIQUE KEY uq_mc_activation_schedule (tenant_id, schedule_key),
  UNIQUE KEY uq_mc_activation_tenant_id (tenant_id, id),
  KEY ix_mc_activation_source (tenant_id, source_binding_id),
  KEY ix_mc_activation_actor (tenant_id, actor_user_id, created_at),
  KEY ix_mc_activation_id_binding (tenant_id, client_id, id_tenant_key),
  KEY ix_mc_activation_portal (tenant_id, client_id, portal_binding_id),
  KEY ix_mc_activation_prepared_schedule (tenant_id, prepared_schedule_version_id),
  KEY ix_mc_activation_active_schedule (tenant_id, active_schedule_version_id),
  CONSTRAINT fk_mc_activation_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_mc_activation_client
    FOREIGN KEY (tenant_id, client_id) REFERENCES clients (tenant_id, id),
  CONSTRAINT fk_mc_activation_source
    FOREIGN KEY (tenant_id, source_binding_id)
    REFERENCES suite_customer_sync_bindings (tenant_id, id),
  CONSTRAINT fk_mc_activation_id_binding
    FOREIGN KEY (tenant_id, client_id, id_tenant_key)
    REFERENCES business_report_id_client_bindings (tenant_id, client_id, id_tenant_key),
  CONSTRAINT fk_mc_activation_portal
    FOREIGN KEY (tenant_id, client_id, portal_binding_id)
    REFERENCES customer_portal_bindings (tenant_id, client_id, id),
  CONSTRAINT fk_mc_activation_prepared_schedule
    FOREIGN KEY (tenant_id, prepared_schedule_version_id)
    REFERENCES business_report_schedule_versions (tenant_id, id),
  CONSTRAINT fk_mc_activation_active_schedule
    FOREIGN KEY (tenant_id, active_schedule_version_id)
    REFERENCES business_report_schedule_versions (tenant_id, id),
  CONSTRAINT fk_mc_activation_actor
    FOREIGN KEY (tenant_id, actor_user_id) REFERENCES users (tenant_id, id),
  CONSTRAINT ck_mc_activation_customer CHECK (
    REGEXP_LIKE(customer_id, _ascii'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')
    AND customer_id <> _ascii'4ebaeefa-b101-47f8-ac76-e49ab309d272'
  ) ENFORCED,
  CONSTRAINT ck_mc_activation_source_version CHECK (source_version >= 1) ENFORCED,
  CONSTRAINT ck_mc_activation_customer_receipt CHECK (
    REGEXP_LIKE(customer_receipt_id, _ascii'^[0-9a-f]{64}$')
  ) ENFORCED,
  CONSTRAINT ck_mc_activation_contact_version CHECK (contact_version >= 1) ENFORCED,
  CONSTRAINT ck_mc_activation_schedule_key CHECK (
    BINARY schedule_key = BINARY CONCAT(_ascii'managed-weekly-v3:', customer_id)
  ) ENFORCED,
  CONSTRAINT ck_mc_activation_id_response_hash CHECK (
    REGEXP_LIKE(id_response_sha256, _ascii'^[0-9a-f]{64}$')
  ) ENFORCED,
  CONSTRAINT ck_mc_activation_recipient_hash CHECK (
    REGEXP_LIKE(recipient_sha256, _ascii'^[0-9a-f]{64}$')
  ) ENFORCED,
  CONSTRAINT ck_mc_activation_evidence_hash CHECK (
    REGEXP_LIKE(evidence_sha256, _ascii'^[0-9a-f]{64}$')
  ) ENFORCED,
  CONSTRAINT ck_mc_activation_install_lock CHECK (0 = 1) ENFORCED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TRIGGER IF EXISTS trg_mc_activation_privilege_preflight;
CREATE TRIGGER trg_mc_activation_privilege_preflight
BEFORE INSERT ON managed_customer_activation_receipts
FOR EACH ROW SET @mc_activation_trigger_privilege_preflight = 1;
DROP TRIGGER trg_mc_activation_privilege_preflight;

DROP TRIGGER IF EXISTS trg_mc_activation_before_insert;
DROP TRIGGER IF EXISTS trg_mc_activation_no_update;
DROP TRIGGER IF EXISTS trg_mc_activation_no_delete;

DELIMITER $$
CREATE TRIGGER trg_mc_activation_before_insert
BEFORE INSERT ON managed_customer_activation_receipts
FOR EACH ROW
BEGIN
  DECLARE source_ok INT DEFAULT 0;
  DECLARE actor_ok INT DEFAULT 0;
  DECLARE id_binding_ok INT DEFAULT 0;
  DECLARE portal_ok INT DEFAULT 0;
  DECLARE schedule_ok INT DEFAULT 0;
  DECLARE evidence_ok INT DEFAULT 0;
  DECLARE guard_version VARCHAR(64) DEFAULT 'safeharbor-managed-customer-activation-receipt-v1';

  SELECT COUNT(*) INTO source_ok
    FROM suite_customer_sync_bindings binding
    JOIN clients client
      ON client.tenant_id = binding.tenant_id
     AND client.id = binding.client_id
   WHERE binding.tenant_id = NEW.tenant_id
     AND binding.id = NEW.source_binding_id
     AND binding.client_id = NEW.client_id
     AND BINARY binding.customer_id = BINARY NEW.customer_id
     AND binding.source_version = NEW.source_version
     AND binding.status = 'active';
  IF source_ok <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activation receipt requires the exact active Milepost binding';
  END IF;

  SELECT COUNT(*) INTO actor_ok
    FROM users actor
   WHERE actor.tenant_id = NEW.tenant_id
     AND actor.id = NEW.actor_user_id
     AND actor.is_active = 1
     AND actor.role IN ('owner','admin');
  IF actor_ok <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activation receipt actor must be an active owner or admin';
  END IF;

  SELECT COUNT(*) INTO id_binding_ok
    FROM business_report_id_client_bindings id_binding
   WHERE id_binding.tenant_id = NEW.tenant_id
     AND id_binding.client_id = NEW.client_id
     AND BINARY id_binding.id_tenant_key = BINARY NEW.id_tenant_key
     AND BINARY id_binding.id_tenant_slug = BINARY NEW.identity_tenant_slug;
  IF id_binding_ok <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activation receipt ID binding does not match';
  END IF;

  SELECT COUNT(*) INTO portal_ok
    FROM customer_portal_bindings portal
   WHERE portal.tenant_id = NEW.tenant_id
     AND portal.client_id = NEW.client_id
     AND portal.id = NEW.portal_binding_id
     AND BINARY portal.identity_tenant_slug = BINARY NEW.identity_tenant_slug
     AND portal.status = 'active';
  IF portal_ok <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activation receipt portal binding is not exact and active';
  END IF;

  SELECT COUNT(*) INTO schedule_ok
    FROM business_report_schedule_versions prepared
    JOIN business_report_schedule_versions active
      ON active.tenant_id = prepared.tenant_id
     AND BINARY active.schedule_key = BINARY prepared.schedule_key
     AND active.version_no = prepared.version_no + 1
     AND active.definition_version_id = prepared.definition_version_id
     AND active.client_id = prepared.client_id
     AND BINARY active.recipient_email = BINARY prepared.recipient_email
     AND BINARY active.schedule_timezone = BINARY prepared.schedule_timezone
     AND active.delivery_weekday = prepared.delivery_weekday
     AND active.delivery_local_time = prepared.delivery_local_time
     AND active.canary = prepared.canary
    JOIN business_report_definition_versions definition
      ON definition.tenant_id = prepared.tenant_id
     AND definition.id = prepared.definition_version_id
   WHERE prepared.tenant_id = NEW.tenant_id
     AND prepared.id = NEW.prepared_schedule_version_id
     AND active.id = NEW.active_schedule_version_id
     AND prepared.client_id = NEW.client_id
     AND BINARY prepared.schedule_key = BINARY NEW.schedule_key
     AND prepared.status = 'disabled'
     AND active.status = 'active'
     AND prepared.canary = 1
     AND definition.definition_key = 'weekly-client-service-summary'
     AND definition.version_no = 3;
  IF schedule_ok <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activation receipt schedule pair is not exact version 3';
  END IF;

  SELECT COUNT(*) INTO evidence_ok
    FROM business_report_id_client_contact_snapshots contact
    JOIN business_report_schedule_versions prepared
      ON prepared.tenant_id = contact.tenant_id
     AND prepared.id = contact.schedule_version_id
   WHERE contact.tenant_id = NEW.tenant_id
     AND contact.client_id = NEW.client_id
     AND contact.schedule_version_id = NEW.prepared_schedule_version_id
     AND BINARY contact.id_tenant_key = BINARY NEW.id_tenant_key
     AND contact.contact_version = NEW.contact_version
     AND BINARY contact.response_sha256 = BINARY NEW.id_response_sha256
     AND BINARY SHA2(contact.recipient_email, 256) = BINARY NEW.recipient_sha256
     AND BINARY prepared.schedule_key = BINARY NEW.schedule_key;
  IF evidence_ok <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activation receipt contact evidence is not exact';
  END IF;

  IF BINARY NEW.evidence_sha256 <> BINARY SHA2(CONCAT_WS('\n',
       'safeharbor-managed-customer-activation-v1',
       NEW.tenant_id, NEW.client_id, NEW.source_binding_id, NEW.customer_id,
       NEW.source_version, NEW.customer_receipt_id, NEW.id_tenant_key,
       NEW.identity_tenant_slug, NEW.contact_version, NEW.portal_binding_id,
       NEW.schedule_key, NEW.prepared_schedule_version_id,
       NEW.active_schedule_version_id, NEW.actor_user_id,
       NEW.id_response_sha256, NEW.recipient_sha256), 256) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activation receipt digest is invalid';
  END IF;
  SET NEW.created_at = UTC_TIMESTAMP();
END$$

CREATE TRIGGER trg_mc_activation_no_update
BEFORE UPDATE ON managed_customer_activation_receipts
FOR EACH ROW
BEGIN
  DECLARE guard_version VARCHAR(64) DEFAULT 'safeharbor-managed-customer-activation-immutable-v1';
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'managed customer activation receipts are immutable';
END$$

CREATE TRIGGER trg_mc_activation_no_delete
BEFORE DELETE ON managed_customer_activation_receipts
FOR EACH ROW
BEGIN
  DECLARE guard_version VARCHAR(64) DEFAULT 'safeharbor-managed-customer-activation-immutable-v1';
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'managed customer activation receipts are immutable';
END$$
DELIMITER ;

SET @mc_activation_install_lock_ddl = IF(
  (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE()
      AND table_name = 'managed_customer_activation_receipts'
      AND constraint_name = 'ck_mc_activation_install_lock'
      AND constraint_type = 'CHECK') = 1,
  'ALTER TABLE managed_customer_activation_receipts DROP CHECK ck_mc_activation_install_lock',
  'DO 0'
);
PREPARE mc_activation_statement FROM @mc_activation_install_lock_ddl;
EXECUTE mc_activation_statement;
DEALLOCATE PREPARE mc_activation_statement;

-- --------------------------------------------------------
-- Managed-customer lifecycle containment receipts (migration 024)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS managed_customer_lifecycle_receipts (
  id                           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id                    INT UNSIGNED NOT NULL,
  client_id                    INT UNSIGNED NOT NULL,
  source_binding_id            BIGINT UNSIGNED NOT NULL,
  customer_id                  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_event_receipt_id      BIGINT UNSIGNED NOT NULL,
  source_event_id              CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_version               BIGINT UNSIGNED NOT NULL,
  source_status                ENUM('active','inactive') NOT NULL,
  action                       ENUM('contained','reactivation_blocked') NOT NULL,
  portal_binding_id            INT UNSIGNED NULL,
  portal_was_active            TINYINT(1) NOT NULL,
  portal_before_event_id       BIGINT UNSIGNED NULL,
  portal_state_event_id        BIGINT UNSIGNED NULL,
  portal_disabled_event_id     BIGINT UNSIGNED NULL,
  portal_state_sha256          CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  schedule_key                 VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  schedule_was_active          TINYINT(1) NOT NULL,
  schedule_active_version_id   INT UNSIGNED NULL,
  schedule_state_version_id    INT UNSIGNED NULL,
  schedule_disabled_version_id INT UNSIGNED NULL,
  schedule_state_sha256        CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  actor_user_id                INT UNSIGNED NOT NULL,
  source_request_sha256        CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  evidence_sha256              CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at                   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mc_lifecycle_customer_version_action (customer_id, source_version, action),
  UNIQUE KEY uq_mc_lifecycle_source_event_action (source_event_receipt_id, action),
  KEY ix_mc_lifecycle_scope (tenant_id, client_id, id),
  KEY ix_mc_lifecycle_source (tenant_id, source_binding_id, source_version),
  KEY ix_mc_lifecycle_source_event (tenant_id, source_event_receipt_id),
  KEY ix_mc_lifecycle_actor (tenant_id, actor_user_id, created_at),
  KEY ix_mc_lifecycle_portal_binding (tenant_id, client_id, portal_binding_id),
  KEY ix_mc_lifecycle_portal_before (portal_before_event_id),
  KEY ix_mc_lifecycle_portal_state (portal_state_event_id),
  KEY ix_mc_lifecycle_portal_disabled (portal_disabled_event_id),
  KEY ix_mc_lifecycle_schedule_active (tenant_id, schedule_active_version_id),
  KEY ix_mc_lifecycle_schedule_state (tenant_id, schedule_state_version_id),
  KEY ix_mc_lifecycle_schedule_disabled (tenant_id, schedule_disabled_version_id),
  CONSTRAINT fk_mc_lifecycle_client FOREIGN KEY (tenant_id, client_id)
    REFERENCES clients (tenant_id, id),
  CONSTRAINT fk_mc_lifecycle_source_binding FOREIGN KEY (tenant_id, source_binding_id)
    REFERENCES suite_customer_sync_bindings (tenant_id, id),
  CONSTRAINT fk_mc_lifecycle_source_event FOREIGN KEY (tenant_id, source_event_receipt_id)
    REFERENCES suite_customer_sync_events (tenant_id, id),
  CONSTRAINT fk_mc_lifecycle_portal_binding FOREIGN KEY (tenant_id, client_id, portal_binding_id)
    REFERENCES customer_portal_bindings (tenant_id, client_id, id),
  CONSTRAINT fk_mc_lifecycle_portal_before FOREIGN KEY (portal_before_event_id)
    REFERENCES customer_portal_binding_events (id),
  CONSTRAINT fk_mc_lifecycle_portal_state FOREIGN KEY (portal_state_event_id)
    REFERENCES customer_portal_binding_events (id),
  CONSTRAINT fk_mc_lifecycle_portal_disabled FOREIGN KEY (portal_disabled_event_id)
    REFERENCES customer_portal_binding_events (id),
  CONSTRAINT fk_mc_lifecycle_schedule_active FOREIGN KEY (tenant_id, schedule_active_version_id)
    REFERENCES business_report_schedule_versions (tenant_id, id),
  CONSTRAINT fk_mc_lifecycle_schedule_state FOREIGN KEY (tenant_id, schedule_state_version_id)
    REFERENCES business_report_schedule_versions (tenant_id, id),
  CONSTRAINT fk_mc_lifecycle_schedule_disabled FOREIGN KEY (tenant_id, schedule_disabled_version_id)
    REFERENCES business_report_schedule_versions (tenant_id, id),
  CONSTRAINT fk_mc_lifecycle_actor FOREIGN KEY (tenant_id, actor_user_id)
    REFERENCES users (tenant_id, id),
  CONSTRAINT ck_mc_lifecycle_customer CHECK (
    REGEXP_LIKE(customer_id, _ascii'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')
    AND BINARY customer_id <> BINARY _ascii'4ebaeefa-b101-47f8-ac76-e49ab309d272'
  ) ENFORCED,
  CONSTRAINT ck_mc_lifecycle_version CHECK (source_version >= 1) ENFORCED,
  CONSTRAINT ck_mc_lifecycle_portal_flag CHECK (portal_was_active IN (0,1)) ENFORCED,
  CONSTRAINT ck_mc_lifecycle_schedule_flag CHECK (schedule_was_active IN (0,1)) ENFORCED,
  CONSTRAINT ck_mc_lifecycle_schedule_key CHECK (
    BINARY schedule_key = BINARY CONCAT(_ascii'managed-weekly-v3:', customer_id)
  ) ENFORCED,
  CONSTRAINT ck_mc_lifecycle_source_hash CHECK (
    REGEXP_LIKE(source_request_sha256, _ascii'^[0-9a-f]{64}$')
  ) ENFORCED,
  CONSTRAINT ck_mc_lifecycle_portal_hash CHECK (
    portal_state_sha256 IS NULL
    OR REGEXP_LIKE(portal_state_sha256, _ascii'^[0-9a-f]{64}$')
  ) ENFORCED,
  CONSTRAINT ck_mc_lifecycle_schedule_hash CHECK (
    schedule_state_sha256 IS NULL
    OR REGEXP_LIKE(schedule_state_sha256, _ascii'^[0-9a-f]{64}$')
  ) ENFORCED,
  CONSTRAINT ck_mc_lifecycle_evidence_hash CHECK (
    REGEXP_LIKE(evidence_sha256, _ascii'^[0-9a-f]{64}$')
  ) ENFORCED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS managed_customer_lifecycle_restore_receipts (
  id                            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id                     INT UNSIGNED NOT NULL,
  client_id                     INT UNSIGNED NOT NULL,
  source_binding_id             BIGINT UNSIGNED NOT NULL,
  customer_id                   CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_event_receipt_id       BIGINT UNSIGNED NOT NULL,
  source_event_id               CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_version                BIGINT UNSIGNED NOT NULL,
  source_request_sha256         CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  id_customer_receipt_id        CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  id_customer_status            ENUM('active') NOT NULL,
  id_lifecycle_version          SMALLINT UNSIGNED NOT NULL,
  id_lifecycle_transition_id    BIGINT UNSIGNED NOT NULL,
  id_lifecycle_action           ENUM('restored') NOT NULL,
  id_lifecycle_evidence_sha256  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  id_identity_tenant_status     ENUM('active') NOT NULL,
  id_oauth_session_version      BIGINT UNSIGNED NOT NULL,
  id_lifecycle_owned            TINYINT(1) NOT NULL,
  id_tenant_key                 VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  identity_tenant_slug          VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  contact_version               BIGINT UNSIGNED NOT NULL,
  recipient_sha256              CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  id_response_generated_at      DATETIME NOT NULL,
  id_request_nonce_sha256       CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  id_response_sha256            CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  portal_owner_receipt_id       BIGINT UNSIGNED NULL,
  portal_binding_id             INT UNSIGNED NULL,
  portal_before_event_id        BIGINT UNSIGNED NULL,
  portal_active_event_id        BIGINT UNSIGNED NULL,
  portal_restored               TINYINT(1) NOT NULL,
  portal_state_sha256           CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  schedule_owner_receipt_id     BIGINT UNSIGNED NULL,
  schedule_key                  VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  schedule_before_version_id    INT UNSIGNED NULL,
  schedule_prepared_version_id  INT UNSIGNED NULL,
  contact_snapshot_id           BIGINT UNSIGNED NULL,
  schedule_active_version_id    INT UNSIGNED NULL,
  schedule_restored             TINYINT(1) NOT NULL,
  schedule_state_sha256         CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  actor_user_id                 INT UNSIGNED NOT NULL,
  evidence_sha256               CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at                    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mc_restore_customer_version (customer_id, source_version),
  UNIQUE KEY uq_mc_restore_source_event (source_event_receipt_id),
  UNIQUE KEY uq_mc_restore_tenant_id (tenant_id, id),
  KEY ix_mc_restore_scope (tenant_id, client_id, id),
  KEY ix_mc_restore_source (tenant_id, source_binding_id, source_version),
  KEY ix_mc_restore_source_event (tenant_id, source_event_receipt_id),
  KEY ix_mc_restore_id_binding (tenant_id, client_id, id_tenant_key),
  KEY ix_mc_restore_actor (tenant_id, actor_user_id, created_at),
  KEY ix_mc_restore_portal_owner (portal_owner_receipt_id),
  KEY ix_mc_restore_portal_binding (tenant_id, client_id, portal_binding_id),
  KEY ix_mc_restore_portal_before (portal_before_event_id),
  KEY ix_mc_restore_portal_active (portal_active_event_id),
  KEY ix_mc_restore_schedule_owner (schedule_owner_receipt_id),
  KEY ix_mc_restore_schedule_before (tenant_id, schedule_before_version_id),
  KEY ix_mc_restore_schedule_prepared (tenant_id, schedule_prepared_version_id),
  KEY ix_mc_restore_contact_snapshot (tenant_id, contact_snapshot_id),
  KEY ix_mc_restore_schedule_active (tenant_id, schedule_active_version_id),
  CONSTRAINT fk_mc_restore_client FOREIGN KEY (tenant_id, client_id)
    REFERENCES clients (tenant_id, id),
  CONSTRAINT fk_mc_restore_source_binding FOREIGN KEY (tenant_id, source_binding_id)
    REFERENCES suite_customer_sync_bindings (tenant_id, id),
  CONSTRAINT fk_mc_restore_source_event FOREIGN KEY (tenant_id, source_event_receipt_id)
    REFERENCES suite_customer_sync_events (tenant_id, id),
  CONSTRAINT fk_mc_restore_id_binding FOREIGN KEY (tenant_id, client_id, id_tenant_key)
    REFERENCES business_report_id_client_bindings (tenant_id, client_id, id_tenant_key),
  CONSTRAINT fk_mc_restore_portal_owner FOREIGN KEY (portal_owner_receipt_id)
    REFERENCES managed_customer_lifecycle_receipts (id),
  CONSTRAINT fk_mc_restore_portal_binding FOREIGN KEY (tenant_id, client_id, portal_binding_id)
    REFERENCES customer_portal_bindings (tenant_id, client_id, id),
  CONSTRAINT fk_mc_restore_portal_before FOREIGN KEY (portal_before_event_id)
    REFERENCES customer_portal_binding_events (id),
  CONSTRAINT fk_mc_restore_portal_active FOREIGN KEY (portal_active_event_id)
    REFERENCES customer_portal_binding_events (id),
  CONSTRAINT fk_mc_restore_schedule_owner FOREIGN KEY (schedule_owner_receipt_id)
    REFERENCES managed_customer_lifecycle_receipts (id),
  CONSTRAINT fk_mc_restore_schedule_before FOREIGN KEY (tenant_id, schedule_before_version_id)
    REFERENCES business_report_schedule_versions (tenant_id, id),
  CONSTRAINT fk_mc_restore_schedule_prepared FOREIGN KEY (tenant_id, schedule_prepared_version_id)
    REFERENCES business_report_schedule_versions (tenant_id, id),
  CONSTRAINT fk_mc_restore_contact_snapshot FOREIGN KEY (tenant_id, contact_snapshot_id)
    REFERENCES business_report_id_client_contact_snapshots (tenant_id, id),
  CONSTRAINT fk_mc_restore_schedule_active FOREIGN KEY (tenant_id, schedule_active_version_id)
    REFERENCES business_report_schedule_versions (tenant_id, id),
  CONSTRAINT fk_mc_restore_actor FOREIGN KEY (tenant_id, actor_user_id)
    REFERENCES users (tenant_id, id),
  CONSTRAINT ck_mc_restore_customer CHECK (
    REGEXP_LIKE(customer_id, _ascii'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')
    AND BINARY customer_id <> BINARY _ascii'4ebaeefa-b101-47f8-ac76-e49ab309d272'
  ) ENFORCED,
  CONSTRAINT ck_mc_restore_source_event CHECK (
    REGEXP_LIKE(source_event_id, _ascii'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')
  ) ENFORCED,
  CONSTRAINT ck_mc_restore_source_version CHECK (source_version>=1) ENFORCED,
  CONSTRAINT ck_mc_restore_source_hash CHECK (
    REGEXP_LIKE(source_request_sha256, _ascii'^[0-9a-f]{64}$')
  ) ENFORCED,
  CONSTRAINT ck_mc_restore_id_receipt CHECK (
    REGEXP_LIKE(id_customer_receipt_id, _ascii'^[0-9a-f]{64}$')
  ) ENFORCED,
  CONSTRAINT ck_mc_restore_lifecycle CHECK (
    id_lifecycle_version=1 AND id_lifecycle_transition_id>=1 AND id_lifecycle_owned=0
  ) ENFORCED,
  CONSTRAINT ck_mc_restore_lifecycle_hash CHECK (
    REGEXP_LIKE(id_lifecycle_evidence_sha256, _ascii'^[0-9a-f]{64}$')
  ) ENFORCED,
  CONSTRAINT ck_mc_restore_oauth_version CHECK (id_oauth_session_version>=1) ENFORCED,
  CONSTRAINT ck_mc_restore_tenant_key CHECK (
    REGEXP_LIKE(id_tenant_key, _ascii'^ewid-t[1-9][0-9]{0,9}$')
  ) ENFORCED,
  CONSTRAINT ck_mc_restore_tenant_slug CHECK (
    REGEXP_LIKE(identity_tenant_slug, _ascii'^[a-z0-9][a-z0-9-]{0,63}$')
  ) ENFORCED,
  CONSTRAINT ck_mc_restore_contact_version CHECK (contact_version>=1) ENFORCED,
  CONSTRAINT ck_mc_restore_transport_hashes CHECK (
    REGEXP_LIKE(recipient_sha256, _ascii'^[0-9a-f]{64}$')
    AND REGEXP_LIKE(id_request_nonce_sha256, _ascii'^[0-9a-f]{64}$')
    AND REGEXP_LIKE(id_response_sha256, _ascii'^[0-9a-f]{64}$')
  ) ENFORCED,
  CONSTRAINT ck_mc_restore_portal_shape CHECK (
    portal_restored IN (0,1)
    AND ((portal_binding_id IS NULL AND portal_owner_receipt_id IS NULL
          AND portal_before_event_id IS NULL AND portal_active_event_id IS NULL
          AND portal_restored=0 AND portal_state_sha256 IS NULL)
      OR (portal_binding_id IS NOT NULL AND portal_before_event_id IS NOT NULL
          AND portal_state_sha256 IS NOT NULL
          AND ((portal_restored=0 AND portal_active_event_id IS NULL)
            OR (portal_restored=1 AND portal_owner_receipt_id IS NOT NULL
                AND portal_active_event_id IS NOT NULL))))
  ) ENFORCED,
  CONSTRAINT ck_mc_restore_portal_hash CHECK (
    portal_state_sha256 IS NULL
    OR REGEXP_LIKE(portal_state_sha256, _ascii'^[0-9a-f]{64}$')
  ) ENFORCED,
  CONSTRAINT ck_mc_restore_schedule_key CHECK (
    BINARY schedule_key=BINARY CONCAT(_ascii'managed-weekly-v3:',customer_id)
  ) ENFORCED,
  CONSTRAINT ck_mc_restore_schedule_shape CHECK (
    schedule_restored IN (0,1)
    AND ((schedule_before_version_id IS NULL AND schedule_owner_receipt_id IS NULL
          AND schedule_prepared_version_id IS NULL AND contact_snapshot_id IS NULL
          AND schedule_active_version_id IS NULL AND schedule_restored=0
          AND schedule_state_sha256 IS NULL)
      OR (schedule_before_version_id IS NOT NULL AND schedule_state_sha256 IS NOT NULL
          AND ((schedule_restored=0 AND schedule_prepared_version_id IS NULL
                AND contact_snapshot_id IS NULL AND schedule_active_version_id IS NULL)
            OR (schedule_restored=1 AND schedule_owner_receipt_id IS NOT NULL
                AND schedule_prepared_version_id IS NOT NULL
                AND contact_snapshot_id IS NOT NULL
                AND schedule_active_version_id IS NOT NULL))))
  ) ENFORCED,
  CONSTRAINT ck_mc_restore_schedule_hash CHECK (
    schedule_state_sha256 IS NULL
    OR REGEXP_LIKE(schedule_state_sha256, _ascii'^[0-9a-f]{64}$')
  ) ENFORCED,
  CONSTRAINT ck_mc_restore_evidence_hash CHECK (
    REGEXP_LIKE(evidence_sha256, _ascii'^[0-9a-f]{64}$')
  ) ENFORCED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TRIGGER IF EXISTS trg_mc_lifecycle_before_insert;
DROP TRIGGER IF EXISTS trg_mc_lifecycle_no_update;
DROP TRIGGER IF EXISTS trg_mc_lifecycle_no_delete;
DROP TRIGGER IF EXISTS trg_mc_restore_before_insert;
DROP TRIGGER IF EXISTS trg_mc_restore_no_update;
DROP TRIGGER IF EXISTS trg_mc_restore_no_delete;

DELIMITER $$
CREATE TRIGGER trg_mc_lifecycle_before_insert
BEFORE INSERT ON managed_customer_lifecycle_receipts
FOR EACH ROW
BEGIN
  DECLARE guard_version VARCHAR(64) DEFAULT 'safeharbor-managed-customer-lifecycle-receipt-v1';
  DECLARE actor_matches INT DEFAULT 0;
  DECLARE source_matches INT DEFAULT 0;
  DECLARE inactive_history INT DEFAULT 0;
  DECLARE portal_matches INT DEFAULT 0;
  DECLARE portal_history INT DEFAULT 0;
  DECLARE schedule_matches INT DEFAULT 0;
  DECLARE schedule_history INT DEFAULT 0;
  DECLARE expected_portal_hash CHAR(64) DEFAULT NULL;
  DECLARE expected_schedule_hash CHAR(64) DEFAULT NULL;
  DECLARE expected_evidence_hash CHAR(64) DEFAULT NULL;

  IF NOT REGEXP_LIKE(
       NEW.customer_id,
       _ascii'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
     )
     OR BINARY NEW.customer_id=BINARY _ascii'4ebaeefa-b101-47f8-ac76-e49ab309d272'
     OR BINARY NEW.schedule_key<>BINARY CONCAT(_ascii'managed-weekly-v3:',NEW.customer_id) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Lifecycle receipt customer or schedule key is invalid';
  END IF;

  SELECT COUNT(*) INTO actor_matches
    FROM users
   WHERE tenant_id=NEW.tenant_id AND id=NEW.actor_user_id
     AND is_active=1 AND role IN ('owner','admin');
  IF actor_matches <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Lifecycle actor must be an active owner or admin';
  END IF;
  SELECT COUNT(*) INTO source_matches
    FROM suite_customer_sync_bindings binding
    JOIN suite_customer_sync_events receipt
      ON receipt.tenant_id=binding.tenant_id
     AND receipt.id=NEW.source_event_receipt_id
     AND receipt.binding_id=binding.id
   WHERE binding.tenant_id=NEW.tenant_id
     AND binding.id=NEW.source_binding_id
     AND binding.client_id=NEW.client_id
     AND BINARY binding.customer_id=BINARY NEW.customer_id
     AND binding.source_version=NEW.source_version
     AND binding.status=NEW.source_status
     AND BINARY binding.last_event_id=BINARY NEW.source_event_id
     AND BINARY binding.last_request_sha256=BINARY NEW.source_request_sha256
     AND BINARY receipt.event_id=BINARY NEW.source_event_id
     AND receipt.source_version=NEW.source_version
     AND receipt.status=NEW.source_status
     AND BINARY receipt.request_sha256=BINARY NEW.source_request_sha256;
  IF source_matches <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Lifecycle receipt must match the exact current source event';
  END IF;
  SELECT COUNT(*) INTO inactive_history
    FROM suite_customer_sync_events
   WHERE tenant_id=NEW.tenant_id AND binding_id=NEW.source_binding_id
     AND status='inactive';
  IF NOT (
       (NEW.action='contained' AND NEW.source_status='inactive')
    OR (NEW.action='reactivation_blocked' AND NEW.source_status='active' AND inactive_history>0)
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Lifecycle action does not match immutable source history';
  END IF;

  IF NEW.portal_binding_id IS NULL THEN
    SELECT COUNT(*) INTO portal_history
      FROM customer_portal_bindings
     WHERE tenant_id=NEW.tenant_id AND client_id=NEW.client_id;
    IF portal_history<>0 OR NEW.portal_was_active<>0
       OR NEW.portal_before_event_id IS NOT NULL
       OR NEW.portal_state_event_id IS NOT NULL
       OR NEW.portal_disabled_event_id IS NOT NULL
       OR NEW.portal_state_sha256 IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Lifecycle portal-absence evidence is inconsistent';
    END IF;
  ELSE
    SELECT COUNT(*), MAX(SHA2(CAST(state_event.snapshot_json AS CHAR),256))
      INTO portal_matches, expected_portal_hash
      FROM customer_portal_bindings portal
      JOIN customer_portal_binding_events state_event
        ON state_event.id=NEW.portal_state_event_id
       AND state_event.tenant_id=portal.tenant_id
       AND state_event.client_id=portal.client_id
       AND state_event.binding_id=portal.id
     WHERE portal.tenant_id=NEW.tenant_id
       AND portal.client_id=NEW.client_id
       AND portal.id=NEW.portal_binding_id
       AND portal.status='disabled'
       AND state_event.id=(
         SELECT MAX(latest_event.id)
           FROM customer_portal_binding_events latest_event
          WHERE latest_event.tenant_id=portal.tenant_id
            AND latest_event.client_id=portal.client_id
            AND latest_event.binding_id=portal.id
       );
    IF portal_matches<>1 OR NEW.portal_before_event_id IS NULL
       OR NEW.portal_state_event_id IS NULL
       OR NEW.portal_state_sha256 IS NULL
       OR BINARY expected_portal_hash<>BINARY NEW.portal_state_sha256 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Lifecycle portal state evidence is inconsistent';
    END IF;
    IF NEW.portal_was_active=1 THEN
      SELECT COUNT(*) INTO portal_matches
        FROM customer_portal_binding_events disabled_event
       WHERE disabled_event.id=NEW.portal_disabled_event_id
         AND disabled_event.id=NEW.portal_state_event_id
         AND disabled_event.event_kind='disabled'
         AND disabled_event.from_status='active'
         AND disabled_event.to_status='disabled'
         AND NEW.portal_before_event_id=(
           SELECT MAX(before_event.id)
             FROM customer_portal_binding_events before_event
            WHERE before_event.tenant_id=disabled_event.tenant_id
              AND before_event.client_id=disabled_event.client_id
              AND before_event.binding_id=disabled_event.binding_id
              AND before_event.id<disabled_event.id
         );
      IF portal_matches<>1 THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Lifecycle portal transition evidence is inconsistent';
      END IF;
    ELSEIF NEW.portal_disabled_event_id IS NOT NULL
       OR NEW.portal_before_event_id<>NEW.portal_state_event_id THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Lifecycle receipt claims an unperformed portal transition';
    END IF;
  END IF;

  IF NEW.schedule_state_version_id IS NULL THEN
    SELECT COUNT(*) INTO schedule_history
      FROM business_report_schedule_versions
     WHERE tenant_id=NEW.tenant_id AND BINARY schedule_key=BINARY NEW.schedule_key;
    IF schedule_history<>0 OR NEW.schedule_was_active<>0
       OR NEW.schedule_active_version_id IS NOT NULL
       OR NEW.schedule_disabled_version_id IS NOT NULL
       OR NEW.schedule_state_sha256 IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Lifecycle report-schedule absence evidence is inconsistent';
    END IF;
  ELSE
    SELECT COUNT(*), MAX(SHA2(CONCAT(
             'safeharbor-managed-customer-schedule-state-v1','\n',schedule.id,'\n',schedule.tenant_id,
             '\n',schedule.schedule_key,'\n',schedule.version_no,'\n',schedule.definition_version_id,
             '\n',schedule.client_id,'\n',schedule.recipient_email,'\n',schedule.schedule_timezone,
             '\n',schedule.delivery_weekday,'\n',schedule.delivery_local_time,'\n',schedule.canary,
             '\n',schedule.status,'\n',schedule.created_by_user_id,'\n',schedule.reason
           ),256))
      INTO schedule_matches, expected_schedule_hash
      FROM business_report_schedule_versions schedule
     WHERE schedule.tenant_id=NEW.tenant_id
       AND schedule.id=NEW.schedule_state_version_id
       AND schedule.client_id=NEW.client_id
       AND BINARY schedule.schedule_key=BINARY NEW.schedule_key
       AND schedule.status='disabled'
       AND schedule.version_no=(
         SELECT MAX(latest.version_no)
           FROM business_report_schedule_versions latest
          WHERE latest.tenant_id=schedule.tenant_id
            AND BINARY latest.schedule_key=BINARY schedule.schedule_key
       );
    IF schedule_matches<>1 OR NEW.schedule_state_sha256 IS NULL
       OR BINARY expected_schedule_hash<>BINARY NEW.schedule_state_sha256 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Lifecycle report-schedule state evidence is inconsistent';
    END IF;
    IF NEW.schedule_was_active=1 THEN
      SELECT COUNT(*) INTO schedule_matches
        FROM business_report_schedule_versions disabled_schedule
        JOIN business_report_schedule_versions active_schedule
          ON active_schedule.tenant_id=disabled_schedule.tenant_id
         AND active_schedule.id=NEW.schedule_active_version_id
         AND active_schedule.schedule_key=disabled_schedule.schedule_key
         AND active_schedule.version_no=disabled_schedule.version_no-1
         AND active_schedule.status='active'
       WHERE disabled_schedule.tenant_id=NEW.tenant_id
         AND disabled_schedule.id=NEW.schedule_disabled_version_id
         AND disabled_schedule.id=NEW.schedule_state_version_id
         AND disabled_schedule.status='disabled';
      IF schedule_matches<>1 THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Lifecycle report-schedule transition evidence is inconsistent';
      END IF;
    ELSEIF NEW.schedule_active_version_id IS NOT NULL
       OR NEW.schedule_disabled_version_id IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Lifecycle receipt claims an unperformed report transition';
    END IF;
  END IF;

  SET expected_evidence_hash=SHA2(CONCAT(
    'safeharbor-managed-customer-lifecycle-v1','\n',NEW.tenant_id,'\n',NEW.client_id,
    '\n',NEW.source_binding_id,'\n',NEW.customer_id,'\n',NEW.source_event_receipt_id,
    '\n',NEW.source_event_id,'\n',NEW.source_version,'\n',NEW.source_status,'\n',NEW.action,
    '\n',COALESCE(CAST(NEW.portal_binding_id AS CHAR),'-'),'\n',NEW.portal_was_active,
    '\n',COALESCE(CAST(NEW.portal_before_event_id AS CHAR),'-'),
    '\n',COALESCE(CAST(NEW.portal_state_event_id AS CHAR),'-'),
    '\n',COALESCE(CAST(NEW.portal_disabled_event_id AS CHAR),'-'),
    '\n',COALESCE(NEW.portal_state_sha256,'-'),'\n',NEW.schedule_key,
    '\n',NEW.schedule_was_active,
    '\n',COALESCE(CAST(NEW.schedule_active_version_id AS CHAR),'-'),
    '\n',COALESCE(CAST(NEW.schedule_state_version_id AS CHAR),'-'),
    '\n',COALESCE(CAST(NEW.schedule_disabled_version_id AS CHAR),'-'),
    '\n',COALESCE(NEW.schedule_state_sha256,'-'),'\n',NEW.actor_user_id,
    '\n',NEW.source_request_sha256
  ),256);
  IF BINARY expected_evidence_hash<>BINARY NEW.evidence_sha256 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Lifecycle evidence digest does not match exact facts';
  END IF;
  SET NEW.created_at=UTC_TIMESTAMP();
END$$

CREATE TRIGGER trg_mc_lifecycle_no_update
BEFORE UPDATE ON managed_customer_lifecycle_receipts
FOR EACH ROW
BEGIN
  DECLARE guard_version VARCHAR(64) DEFAULT 'safeharbor-managed-customer-lifecycle-immutable-v1';
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Managed-customer lifecycle receipts are immutable';
END$$

CREATE TRIGGER trg_mc_lifecycle_no_delete
BEFORE DELETE ON managed_customer_lifecycle_receipts
FOR EACH ROW
BEGIN
  DECLARE guard_version VARCHAR(64) DEFAULT 'safeharbor-managed-customer-lifecycle-immutable-v1';
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Managed-customer lifecycle receipts are immutable';
END$$

CREATE TRIGGER trg_mc_restore_before_insert
BEFORE INSERT ON managed_customer_lifecycle_restore_receipts
FOR EACH ROW
BEGIN
  DECLARE guard_version VARCHAR(64) DEFAULT 'safeharbor-managed-customer-lifecycle-restore-receipt-v1';
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

CREATE TRIGGER trg_mc_restore_no_update
BEFORE UPDATE ON managed_customer_lifecycle_restore_receipts
FOR EACH ROW
BEGIN
  DECLARE guard_version VARCHAR(64) DEFAULT 'safeharbor-managed-customer-lifecycle-restore-immutable-v1';
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT='Managed-customer lifecycle restore receipts are immutable';
END$$

CREATE TRIGGER trg_mc_restore_no_delete
BEFORE DELETE ON managed_customer_lifecycle_restore_receipts
FOR EACH ROW
BEGIN
  DECLARE guard_version VARCHAR(64) DEFAULT 'safeharbor-managed-customer-lifecycle-restore-immutable-v1';
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT='Managed-customer lifecycle restore receipts are immutable';
END$$
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
  corrects_time_entry_id INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_time_entries_tenant_key (tenant_id, entry_key),
  UNIQUE KEY uq_time_entries_tenant_id (tenant_id, id),
  UNIQUE KEY uq_time_entries_one_correction (tenant_id, corrects_time_entry_id),
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
    REFERENCES users (tenant_id, id),
  CONSTRAINT fk_time_entries_correction_tenant FOREIGN KEY (tenant_id, corrects_time_entry_id)
    REFERENCES time_entries (tenant_id, id)
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

-- Persistent UTC-day rows provide a small transaction lock target. Every
-- measured insert locks its one or two covered UTC days before consulting the
-- interval registry, so concurrent requests for the same technician cannot
-- both pass an overlap check against an old snapshot.
CREATE TABLE IF NOT EXISTS time_entry_interval_guards (
  tenant_id  INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  guard_date DATE NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id, user_id, guard_date),
  CONSTRAINT fk_time_interval_guards_tenant FOREIGN KEY (tenant_id)
    REFERENCES tenants (id),
  CONSTRAINT fk_time_interval_guards_user FOREIGN KEY (tenant_id, user_id)
    REFERENCES users (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Database-owned mirror of measured facts. The parent time row remains the
-- immutable source of truth; this narrow table exists only so the BEFORE
-- INSERT trigger can take a current locking read without reading the table
-- that invoked it.
CREATE TABLE IF NOT EXISTS time_entry_measured_intervals (
  tenant_id       INT UNSIGNED NOT NULL,
  time_entry_id   INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  started_at      DATETIME NOT NULL,
  ended_at        DATETIME NOT NULL,
  approval_status ENUM('pending','approved','rejected') NOT NULL,
  PRIMARY KEY (tenant_id, time_entry_id),
  KEY ix_time_measured_overlap
    (tenant_id, user_id, approval_status, started_at, ended_at, time_entry_id),
  CONSTRAINT ck_time_measured_positive CHECK (ended_at > started_at),
  CONSTRAINT fk_time_measured_entry FOREIGN KEY (tenant_id, time_entry_id)
    REFERENCES time_entries (tenant_id, id),
  CONSTRAINT fk_time_measured_user FOREIGN KEY (tenant_id, user_id)
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

DROP TRIGGER IF EXISTS trg_time_entries_before_insert;
DROP TRIGGER IF EXISTS trg_time_entries_after_insert;
DROP TRIGGER IF EXISTS trg_time_entries_before_update;
DROP TRIGGER IF EXISTS trg_time_entries_after_update;
DROP TRIGGER IF EXISTS trg_time_entries_no_delete;
DROP TRIGGER IF EXISTS trg_time_entry_events_no_update;
DROP TRIGGER IF EXISTS trg_time_entry_events_no_delete;
DROP TRIGGER IF EXISTS trg_time_interval_guards_no_update;
DROP TRIGGER IF EXISTS trg_time_interval_guards_no_delete;
DROP TRIGGER IF EXISTS trg_time_measured_before_insert;
DROP TRIGGER IF EXISTS trg_time_measured_before_update;
DROP TRIGGER IF EXISTS trg_time_measured_no_delete;

DELIMITER $$
CREATE TRIGGER trg_time_entries_before_insert
BEFORE INSERT ON time_entries
FOR EACH ROW
BEGIN
  DECLARE ticket_tenant_id INT UNSIGNED;
  DECLARE ticket_client_id INT UNSIGNED;
  DECLARE correction_count INT DEFAULT 0;
  DECLARE correction_status VARCHAR(16) DEFAULT NULL;
  DECLARE correction_ticket_id INT UNSIGNED DEFAULT NULL;
  DECLARE correction_client_id INT UNSIGNED DEFAULT NULL;
  DECLARE correction_user_id INT UNSIGNED DEFAULT NULL;
  DECLARE correction_source VARCHAR(16) DEFAULT NULL;
  DECLARE locked_guard_date DATE DEFAULT NULL;
  DECLARE conflicting_time_entry_id INT UNSIGNED DEFAULT NULL;

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
     OR (NEW.started_at IS NOT NULL AND NEW.ended_at <= NEW.started_at) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry interval is invalid';
  END IF;
  IF NEW.source = 'timer' AND NEW.started_at IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Timer time requires start and end evidence';
  END IF;
  IF NEW.source = 'suggestion' AND NEW.started_at IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Suggested time cannot claim timer evidence';
  END IF;

  IF NEW.corrects_time_entry_id IS NOT NULL THEN
    SELECT COUNT(*), MAX(approval_status), MAX(ticket_id), MAX(client_id),
           MAX(user_id), MAX(source)
      INTO correction_count, correction_status, correction_ticket_id,
           correction_client_id, correction_user_id, correction_source
      FROM time_entries
     WHERE tenant_id = NEW.tenant_id
       AND id = NEW.corrects_time_entry_id;
    IF correction_count <> 1 OR BINARY correction_status <> BINARY 'rejected' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only rejected time entries may be corrected';
    END IF;
    IF correction_user_id <> NEW.user_id THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry correction technician must match';
    END IF;
    IF correction_ticket_id <> NEW.ticket_id OR correction_client_id <> NEW.client_id THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry correction ticket and client must match';
    END IF;
    IF BINARY correction_source <> BINARY NEW.source THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time entry correction source must match';
    END IF;
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

    INSERT IGNORE INTO time_entry_interval_guards
      (tenant_id, user_id, guard_date)
    VALUES
      (NEW.tenant_id, NEW.user_id, DATE(NEW.started_at));
    IF DATE(DATE_SUB(NEW.ended_at, INTERVAL 1 SECOND)) <> DATE(NEW.started_at) THEN
      INSERT IGNORE INTO time_entry_interval_guards
        (tenant_id, user_id, guard_date)
      VALUES
        (NEW.tenant_id, NEW.user_id,
         DATE(DATE_SUB(NEW.ended_at, INTERVAL 1 SECOND)));
    END IF;

    SELECT guard_date
      INTO locked_guard_date
      FROM time_entry_interval_guards
     WHERE tenant_id = NEW.tenant_id
       AND user_id = NEW.user_id
       AND guard_date = DATE(NEW.started_at)
     FOR UPDATE;
    IF DATE(DATE_SUB(NEW.ended_at, INTERVAL 1 SECOND)) <> DATE(NEW.started_at) THEN
      SELECT guard_date
        INTO locked_guard_date
        FROM time_entry_interval_guards
       WHERE tenant_id = NEW.tenant_id
         AND user_id = NEW.user_id
         AND guard_date = DATE(DATE_SUB(NEW.ended_at, INTERVAL 1 SECOND))
       FOR UPDATE;
    END IF;

    SET conflicting_time_entry_id = NULL;
    SELECT time_entry_id
      INTO conflicting_time_entry_id
      FROM time_entry_measured_intervals
     WHERE tenant_id = NEW.tenant_id
       AND user_id = NEW.user_id
       AND approval_status IN ('pending', 'approved')
       AND started_at < NEW.ended_at
       AND ended_at > NEW.started_at
     ORDER BY started_at, time_entry_id
     LIMIT 1
     FOR UPDATE;
    IF conflicting_time_entry_id IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Measured time overlaps existing pending or approved time';
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
       'corrects_time_entry_id', NEW.corrects_time_entry_id,
       'created_at', NEW.created_at
     ), UTC_TIMESTAMP());

  IF NEW.started_at IS NOT NULL THEN
    INSERT IGNORE INTO time_entry_measured_intervals
      (tenant_id, time_entry_id, user_id, started_at, ended_at, approval_status)
    VALUES
      (NEW.tenant_id, NEW.id, NEW.user_id, NEW.started_at, NEW.ended_at,
       NEW.approval_status);
  END IF;
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
   AND NEW.corrects_time_entry_id <=> OLD.corrects_time_entry_id
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
  DECLARE measured_registry_count INT DEFAULT 0;
  DECLARE measured_registry_status VARCHAR(16) DEFAULT NULL;

  IF NEW.started_at IS NOT NULL THEN
    UPDATE time_entry_measured_intervals
       SET approval_status = NEW.approval_status
     WHERE tenant_id = NEW.tenant_id
       AND time_entry_id = NEW.id
       AND approval_status <> NEW.approval_status;
    SELECT COUNT(*), MAX(approval_status)
      INTO measured_registry_count, measured_registry_status
      FROM time_entry_measured_intervals
     WHERE tenant_id = NEW.tenant_id
       AND time_entry_id = NEW.id;
    IF measured_registry_count <> 1
       OR BINARY measured_registry_status <> BINARY NEW.approval_status THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Measured time registry is incomplete';
    END IF;
  END IF;

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
       'corrects_time_entry_id', NEW.corrects_time_entry_id,
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

CREATE TRIGGER trg_time_interval_guards_no_update
BEFORE UPDATE ON time_entry_interval_guards
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time interval guard rows are immutable';
END$$

CREATE TRIGGER trg_time_interval_guards_no_delete
BEFORE DELETE ON time_entry_interval_guards
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time interval guard rows cannot be deleted';
END$$

CREATE TRIGGER trg_time_measured_before_insert
BEFORE INSERT ON time_entry_measured_intervals
FOR EACH ROW
BEGIN
  DECLARE parent_count INT DEFAULT 0;
  DECLARE parent_user_id INT UNSIGNED DEFAULT NULL;
  DECLARE parent_started_at DATETIME DEFAULT NULL;
  DECLARE parent_ended_at DATETIME DEFAULT NULL;
  DECLARE parent_status VARCHAR(16) DEFAULT NULL;

  SELECT COUNT(*), MAX(user_id), MAX(started_at), MAX(ended_at),
         MAX(approval_status)
    INTO parent_count, parent_user_id, parent_started_at, parent_ended_at,
         parent_status
    FROM time_entries
   WHERE tenant_id = NEW.tenant_id
     AND id = NEW.time_entry_id;
  IF parent_count <> 1
     OR NOT (parent_user_id <=> NEW.user_id)
     OR NOT (parent_started_at <=> NEW.started_at)
     OR NOT (parent_ended_at <=> NEW.ended_at)
     OR NOT (BINARY parent_status <=> BINARY NEW.approval_status) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Measured time registry must match its immutable parent';
  END IF;
END$$

CREATE TRIGGER trg_time_measured_before_update
BEFORE UPDATE ON time_entry_measured_intervals
FOR EACH ROW
BEGIN
  DECLARE parent_status VARCHAR(16) DEFAULT NULL;

  IF NOT (
       NEW.tenant_id <=> OLD.tenant_id
   AND NEW.time_entry_id <=> OLD.time_entry_id
   AND NEW.user_id <=> OLD.user_id
   AND NEW.started_at <=> OLD.started_at
   AND NEW.ended_at <=> OLD.ended_at
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Measured time registry facts are immutable';
  END IF;

  SELECT approval_status
    INTO parent_status
    FROM time_entries
   WHERE tenant_id = NEW.tenant_id
     AND id = NEW.time_entry_id;
  IF parent_status IS NULL OR BINARY parent_status <> BINARY NEW.approval_status THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Measured time registry status must match its parent';
  END IF;
  IF OLD.approval_status <> 'pending'
     OR NEW.approval_status NOT IN ('approved', 'rejected') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Measured time registry permits one review transition';
  END IF;
END$$

CREATE TRIGGER trg_time_measured_no_delete
BEFORE DELETE ON time_entry_measured_intervals
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Measured time registry rows cannot be deleted';
END$$
DELIMITER ;

-- --------------------------------------------------------
-- Append-only effective corrections to approved time. The approved parent
-- remains unchanged; each version records what reports and draft exports may
-- treat as effective after an owner/admin correction.
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS time_entry_approval_adjustments (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id          INT UNSIGNED NOT NULL,
  time_entry_id      INT UNSIGNED NOT NULL,
  adjustment_key     VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  version_no         INT UNSIGNED NOT NULL,
  effective_minutes  INT UNSIGNED NOT NULL,
  effective_billable TINYINT(1) NOT NULL,
  reason             VARCHAR(500) NOT NULL,
  actor_user_id      INT UNSIGNED NOT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_time_adjustment_tenant_key (tenant_id, adjustment_key),
  UNIQUE KEY uq_time_adjustment_entry_version (tenant_id, time_entry_id, version_no),
  KEY ix_time_adjustment_entry_created (tenant_id, time_entry_id, created_at, id),
  KEY ix_time_adjustment_actor_created (tenant_id, actor_user_id, created_at, id),
  CONSTRAINT ck_time_adjustment_version CHECK (version_no >= 1),
  CONSTRAINT ck_time_adjustment_minutes CHECK (effective_minutes BETWEEN 0 AND 1440),
  CONSTRAINT ck_time_adjustment_billable CHECK (effective_billable IN (0, 1)),
  CONSTRAINT ck_time_adjustment_zero_nonbillable
    CHECK (effective_minutes <> 0 OR effective_billable = 0),
  CONSTRAINT ck_time_adjustment_reason
    CHECK (CHAR_LENGTH(TRIM(reason)) BETWEEN 1 AND 500),
  -- Removed after the permanent triggers below are created. Until then, a
  -- fresh schema interrupted after CREATE TABLE cannot accept any row.
  CONSTRAINT ck_time_adjustment_install_lock CHECK (0 = 1),
  CONSTRAINT fk_time_adjustment_tenant FOREIGN KEY (tenant_id)
    REFERENCES tenants (id),
  CONSTRAINT fk_time_adjustment_entry FOREIGN KEY (tenant_id, time_entry_id)
    REFERENCES time_entries (tenant_id, id),
  CONSTRAINT fk_time_adjustment_actor FOREIGN KEY (tenant_id, actor_user_id)
    REFERENCES users (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TRIGGER IF EXISTS trg_time_adjustment_privilege_preflight;
CREATE TRIGGER trg_time_adjustment_privilege_preflight
BEFORE INSERT ON time_entry_approval_adjustments
FOR EACH ROW
SET @time_adjustment_trigger_privilege_preflight = 1;
DROP TRIGGER trg_time_adjustment_privilege_preflight;

DROP TRIGGER IF EXISTS trg_time_adjustments_before_insert;
DROP TRIGGER IF EXISTS trg_time_adjustments_no_update;
DROP TRIGGER IF EXISTS trg_time_adjustments_no_delete;

DELIMITER $$
CREATE TRIGGER trg_time_adjustments_before_insert
BEFORE INSERT ON time_entry_approval_adjustments
FOR EACH ROW
BEGIN
  DECLARE tenant_found INT DEFAULT 0;
  DECLARE parent_found INT DEFAULT 0;
  DECLARE parent_minutes INT UNSIGNED DEFAULT NULL;
  DECLARE parent_billable TINYINT DEFAULT NULL;
  DECLARE parent_status VARCHAR(16) DEFAULT NULL;
  DECLARE parent_reviewer_id INT UNSIGNED DEFAULT NULL;
  DECLARE parent_reviewed_at DATETIME DEFAULT NULL;
  DECLARE actor_found INT DEFAULT 0;
  DECLARE actor_role VARCHAR(32) DEFAULT NULL;
  DECLARE actor_active TINYINT DEFAULT NULL;
  DECLARE latest_version INT UNSIGNED DEFAULT 0;

  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET tenant_found = 0;
    SELECT 1
      INTO tenant_found
      FROM tenants
     WHERE id = NEW.tenant_id
     FOR UPDATE;
  END;
  IF tenant_found <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Adjustment tenant does not exist';
  END IF;

  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET parent_found = 0;
    SELECT 1, minutes, billable, approval_status,
           reviewed_by_user_id, reviewed_at
      INTO parent_found, parent_minutes, parent_billable, parent_status,
           parent_reviewer_id, parent_reviewed_at
      FROM time_entries
     WHERE tenant_id = NEW.tenant_id
       AND id = NEW.time_entry_id
     FOR UPDATE;
  END;
  IF parent_found <> 1 OR BINARY parent_status <> BINARY 'approved'
     OR parent_reviewer_id IS NULL OR parent_reviewed_at IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Adjustments require approved time with review evidence';
  END IF;

  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET actor_found = 0;
    SELECT 1, role, is_active
      INTO actor_found, actor_role, actor_active
      FROM users
     WHERE tenant_id = NEW.tenant_id
       AND id = NEW.actor_user_id
     FOR UPDATE;
  END;
  IF actor_found <> 1 OR actor_active <> 1
     OR (BINARY actor_role <> BINARY 'owner'
         AND BINARY actor_role <> BINARY 'admin') THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Adjustment actor must be an active owner or admin';
  END IF;

  IF NEW.adjustment_key IS NULL
     OR OCTET_LENGTH(NEW.adjustment_key) NOT BETWEEN 16 AND 64
     OR NOT (BINARY NEW.adjustment_key REGEXP BINARY '^[A-Za-z0-9][A-Za-z0-9._:-]*$') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Adjustment key is not conservative';
  END IF;
  -- Match PHP trim() exactly at the database boundary: space, tab, LF, VT,
  -- CR, and NUL are removed from both ends in any mixture.
  SET NEW.reason = COALESCE(NEW.reason, '');
  time_adjustment_reason_left: WHILE CHAR_LENGTH(NEW.reason) > 0 DO
    IF ASCII(LEFT(NEW.reason, 1)) IN (0, 9, 10, 11, 13, 32) THEN
      SET NEW.reason = SUBSTRING(NEW.reason, 2);
    ELSE
      LEAVE time_adjustment_reason_left;
    END IF;
  END WHILE time_adjustment_reason_left;
  time_adjustment_reason_right: WHILE CHAR_LENGTH(NEW.reason) > 0 DO
    IF ASCII(RIGHT(NEW.reason, 1)) IN (0, 9, 10, 11, 13, 32) THEN
      SET NEW.reason = LEFT(NEW.reason, CHAR_LENGTH(NEW.reason) - 1);
    ELSE
      LEAVE time_adjustment_reason_right;
    END IF;
  END WHILE time_adjustment_reason_right;
  IF CHAR_LENGTH(NEW.reason) NOT BETWEEN 1 AND 500 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Adjustment reason is required';
  END IF;
  IF NEW.effective_minutes > parent_minutes THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Effective minutes exceed original approved time';
  END IF;
  IF parent_billable = 0 AND NEW.effective_billable <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Originally internal time cannot become billable';
  END IF;
  IF NEW.effective_minutes = 0 AND NEW.effective_billable <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zero effective minutes must be nonbillable';
  END IF;

  BEGIN
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET latest_version = 0;
    SELECT version_no
      INTO latest_version
      FROM time_entry_approval_adjustments
     WHERE tenant_id = NEW.tenant_id
       AND time_entry_id = NEW.time_entry_id
     ORDER BY version_no DESC
     LIMIT 1;
  END;
  IF NEW.version_no <> latest_version + 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Adjustment does not follow current adjustment version';
  END IF;

  SET NEW.created_at = UTC_TIMESTAMP();
END$$

CREATE TRIGGER trg_time_adjustments_no_update
BEFORE UPDATE ON time_entry_approval_adjustments
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Approved-time adjustments are immutable';
END$$

CREATE TRIGGER trg_time_adjustments_no_delete
BEFORE DELETE ON time_entry_approval_adjustments
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Approved-time adjustments cannot be deleted';
END$$
DELIMITER ;

SET @time_adjustment_schema_install_lock_ddl = IF(
  (SELECT COUNT(*)
     FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE()
      AND table_name = 'time_entry_approval_adjustments'
      AND constraint_type = 'CHECK'
      AND constraint_name = 'ck_time_adjustment_install_lock') = 1,
  'ALTER TABLE time_entry_approval_adjustments DROP CHECK ck_time_adjustment_install_lock',
  'DO 0'
);
PREPARE time_adjustment_schema_statement FROM @time_adjustment_schema_install_lock_ddl;
EXECUTE time_adjustment_schema_statement;
DEALLOCATE PREPARE time_adjustment_schema_statement;

-- --------------------------------------------------------
-- Receipt-backed Coastmark v3 approved-time export.
-- --------------------------------------------------------
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
  ('safeharbor_m021_reference_receipts',12,'detail_code','varchar(64)','NO',NULL,'','','ascii','ascii_bin'),
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
  failure_code      VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  install_lock      TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (table_name,constraint_name)
) ENGINE=MEMORY;
INSERT INTO safeharbor_m021_source_checks VALUES
  ('safeharbor_m021_reference_claims','rc21_claim_event_key',
   'regexp_like(event_key,''^safeharbor-time:[0-9a-f]{32}$'')',
   'migration_021_refcheck_claim_event_key_failed',0),
  ('safeharbor_m021_reference_claims','rc21_claim_hash',
   'regexp_like(payload_sha256,''^[0-9a-f]{64}$'')',
   'migration_021_refcheck_claim_hash_failed',0),
  ('safeharbor_m021_reference_claims','rc21_claim_payload','json_valid(payload_json)',
   'migration_021_refcheck_claim_payload_failed',0),
  ('safeharbor_m021_reference_claims','rc21_claim_predecessor_shape',
   'source_version=0andpredecessor_claim_idisnullorsource_version>0andpredecessor_claim_idisnotnull',
   'migration_021_refcheck_claim_predecessor_failed',0),
  ('safeharbor_m021_reference_claims','rc21_claim_install_lock','0=1',
   'migration_021_refcheck_claim_install_lock_failed',1),
  ('safeharbor_m021_reference_receipts','rc21_receipt_operation_key',
   'regexp_like(operation_key,''^safeharbor-op:[0-9a-f]{32}$'')',
   'migration_021_refcheck_receipt_operation_key_failed',0),
  ('safeharbor_m021_reference_receipts','rc21_receipt_response_status',
   'response_statusisnullorresponse_statusbetween100and599',
   'migration_021_refcheck_receipt_status_failed',0),
  ('safeharbor_m021_reference_receipts','rc21_receipt_response_hash',
   'response_sha256isnullorregexp_like(response_sha256,''^[0-9a-f]{64}$'')',
   'migration_021_refcheck_receipt_hash_failed',0),
  ('safeharbor_m021_reference_receipts','rc21_receipt_detail',
   'regexp_like(detail_code,''^[a-z][a-z0-9_]{2,63}$'')',
   'migration_021_refcheck_receipt_detail_failed',0),
  ('safeharbor_m021_reference_receipts','rc21_receipt_ack_shape',
   'outcome=''accepted''andcoastmark_event_idisnotnullandinvoice_idisnotnullandinvoice_line_idisnotnulloroutcome=''replayed''andcoastmark_event_idisnotnullandinvoice_idisnotnulloroutcome=''manual_exception''andcoastmark_event_idisnotnullandinvoice_idisnotnullandinvoice_line_idisnulloroutcomenotin(''accepted'',''replayed'',''manual_exception'')andcoastmark_event_idisnullandinvoice_idisnullandinvoice_line_idisnull',
   'migration_021_refcheck_receipt_ack_failed',0),
  ('safeharbor_m021_reference_receipts','rc21_receipt_install_lock','0=1',
   'migration_021_refcheck_receipt_install_lock_failed',1);

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
SET @cm_reference_source_check_definition_failure = (
  SELECT MIN(COALESCE(expected.failure_code,
                      'migration_021_refcheck_unexpected_failed'))
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
              <> CAST(REGEXP_REPLACE(expected.normalized_clause,
                                     '[[:space:]()]','') AS BINARY))
);
SET @cm_reference_source_check_definitions_ok = (
  @cm_reference_source_check_definition_failure IS NULL
);
SET @cm_reference_source_required_check_failure = (
  SELECT MIN(expected.failure_code)
     FROM safeharbor_m021_source_checks expected
     LEFT JOIN information_schema.table_constraints live_constraint
       ON live_constraint.constraint_schema=DATABASE()
      AND live_constraint.table_name=expected.table_name
      AND live_constraint.constraint_name=expected.constraint_name
      AND live_constraint.constraint_type='CHECK'
      AND live_constraint.enforced='YES'
    WHERE expected.install_lock=0
      AND live_constraint.constraint_name IS NULL
);
SET @cm_reference_source_required_checks_ok = (
  @cm_reference_source_required_check_failure IS NULL
);
SET @cm_reference_source_claim_install_lock_count = (
  SELECT COUNT(*) FROM information_schema.table_constraints
   WHERE constraint_schema=DATABASE()
     AND table_name='safeharbor_m021_reference_claims'
     AND constraint_type='CHECK'
     AND constraint_name='rc21_claim_install_lock'
);
SET @cm_reference_source_receipt_install_lock_count = (
  SELECT COUNT(*) FROM information_schema.table_constraints
   WHERE constraint_schema=DATABASE()
     AND table_name='safeharbor_m021_reference_receipts'
     AND constraint_type='CHECK'
     AND constraint_name='rc21_receipt_install_lock'
);
SET @cm_reference_source_checks_ok = (
  @cm_reference_source_check_definitions_ok=1
  AND @cm_reference_source_required_checks_ok=1
  AND @cm_reference_source_claim_install_lock_count<=1
  AND @cm_reference_source_receipt_install_lock_count<=1
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
  WHEN NOT (@cm_reference_source_tables_ok <=> 1) THEN 'migration_021_reference_source_tables_failed'
  WHEN NOT (@cm_reference_source_columns_ok <=> 1) THEN 'migration_021_reference_source_columns_failed'
  WHEN NOT (@cm_reference_source_indexes_ok <=> 1) THEN 'migration_021_reference_source_indexes_failed'
  WHEN NOT (@cm_reference_source_fks_ok <=> 1) THEN 'migration_021_reference_source_fks_failed'
  WHEN @cm_reference_source_check_definition_failure IS NOT NULL
    THEN @cm_reference_source_check_definition_failure
  WHEN @cm_reference_source_required_check_failure IS NOT NULL
    THEN @cm_reference_source_required_check_failure
  WHEN NOT (@cm_reference_source_claim_install_lock_count<=1)
    THEN 'migration_021_refcheck_claim_install_lifecycle_failed'
  WHEN NOT (@cm_reference_source_receipt_install_lock_count<=1)
    THEN 'migration_021_refcheck_receipt_install_lifecycle_failed'
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
SET @cm_reference_cleanup_source_check_definitions_ok = (
  SELECT COUNT(*)=0
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
              <> CAST(REGEXP_REPLACE(expected.normalized_clause,
                                     '[[:space:]()]','') AS BINARY))
);
SET @cm_reference_cleanup_source_required_checks_ok = (
  SELECT COUNT(*)=9
     FROM safeharbor_m021_source_checks expected
     JOIN information_schema.table_constraints live_constraint
       ON live_constraint.constraint_schema=DATABASE()
      AND live_constraint.table_name=expected.table_name
      AND live_constraint.constraint_name=expected.constraint_name
      AND live_constraint.constraint_type='CHECK'
      AND live_constraint.enforced='YES'
    WHERE expected.install_lock=0
);
SET @cm_reference_cleanup_source_checks_ok = (
  @cm_reference_cleanup_source_check_definitions_ok=1
  AND @cm_reference_cleanup_source_required_checks_ok=1
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
SET @cm_reference_claim_source_check_definitions_ok = (
  SELECT COUNT(*)=0
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
              <> CAST(REGEXP_REPLACE(expected.normalized_clause,
                                     '[[:space:]()]','') AS BINARY))
);
SET @cm_reference_claim_source_required_checks_ok = (
  SELECT COUNT(*)=4
     FROM safeharbor_m021_source_checks expected
     JOIN information_schema.table_constraints live_constraint
       ON live_constraint.constraint_schema=DATABASE()
      AND live_constraint.table_name=expected.table_name
      AND live_constraint.constraint_name=expected.constraint_name
      AND live_constraint.constraint_type='CHECK'
      AND live_constraint.enforced='YES'
    WHERE expected.table_name='safeharbor_m021_reference_claims'
      AND expected.install_lock=0
);
SET @cm_reference_claim_source_checks_ok = (
  @cm_reference_claim_source_check_definitions_ok=1
  AND @cm_reference_claim_source_required_checks_ok=1
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
