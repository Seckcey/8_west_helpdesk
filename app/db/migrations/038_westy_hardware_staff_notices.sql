-- 038: Independent staff fallback receipts for terminal hardware notices.
-- Apply after 037 through the stock publisher. No backfill or mail is sent by SQL.
-- Keep both tables and held mail_queue rows on source rollback.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS westy_hardware_staff_notices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  notice_id BIGINT UNSIGNED NOT NULL,
  recipient_user_id INT UNSIGNED NULL,
  recipient_role ENUM('owner','admin','tech') NULL,
  recipient_email VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NULL,
  mail_queue_id INT UNSIGNED NULL,
  state ENUM('sending','provider_accepted','failed','uncertain','no_recipient','suppressed') NOT NULL,
  detail_code VARCHAR(64) NOT NULL,
  send_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_try_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hardware_staff_scope (tenant_id,id),
  UNIQUE KEY uq_hardware_staff_notice (tenant_id,notice_id),
  UNIQUE KEY uq_hardware_staff_queue (mail_queue_id),
  KEY ix_hardware_staff_due (state,next_try_at,id),
  CONSTRAINT fk_hardware_staff_source FOREIGN KEY (tenant_id,notice_id) REFERENCES westy_hardware_notices (tenant_id,id),
  CONSTRAINT ck_hardware_staff_recipient CHECK (
    (recipient_user_id IS NULL AND recipient_role IS NULL AND recipient_email IS NULL AND mail_queue_id IS NULL
      AND send_attempts=0 AND state IN ('no_recipient','suppressed'))
    OR (recipient_user_id IS NOT NULL AND recipient_role IS NOT NULL AND recipient_email IS NOT NULL AND mail_queue_id IS NOT NULL
      AND send_attempts BETWEEN 1 AND 5 AND state<>'no_recipient')
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS westy_hardware_staff_attempts (
  tenant_id INT UNSIGNED NOT NULL,
  staff_notice_id BIGINT UNSIGNED NOT NULL,
  attempt_number TINYINT UNSIGNED NOT NULL,
  outcome ENUM('provider_accepted','failed','uncertain') NULL,
  transport ENUM('none','graph','smtp','php_mail') NULL,
  provider_http SMALLINT UNSIGNED NULL,
  outcome_code VARCHAR(64) NULL,
  attempted_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  PRIMARY KEY (tenant_id,staff_notice_id,attempt_number),
  CONSTRAINT fk_hardware_staff_attempt FOREIGN KEY (tenant_id,staff_notice_id) REFERENCES westy_hardware_staff_notices (tenant_id,id),
  CONSTRAINT ck_hardware_staff_attempt CHECK (attempt_number BETWEEN 1 AND 5 AND (
    (completed_at IS NULL AND outcome IS NULL AND transport IS NULL AND provider_http IS NULL AND outcome_code IS NULL)
    OR (completed_at IS NOT NULL AND outcome IS NOT NULL AND transport IS NOT NULL AND outcome_code IS NOT NULL)
  ))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DELIMITER $$
DROP TRIGGER IF EXISTS trg_hardware_staff_notice_identity$$
CREATE TRIGGER trg_hardware_staff_notice_identity BEFORE UPDATE ON westy_hardware_staff_notices
FOR EACH ROW BEGIN
  IF NOT (NEW.id <=> OLD.id) OR NOT (NEW.tenant_id <=> OLD.tenant_id) OR NOT (NEW.notice_id <=> OLD.notice_id)
    OR NOT (NEW.recipient_user_id <=> OLD.recipient_user_id) OR NOT (NEW.recipient_role <=> OLD.recipient_role)
    OR NOT (NEW.recipient_email <=> OLD.recipient_email) OR NOT (NEW.mail_queue_id <=> OLD.mail_queue_id)
    OR NOT (NEW.created_at <=> OLD.created_at)
    OR OLD.state IN ('provider_accepted','uncertain','no_recipient','suppressed') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hardware staff identity and terminal outcomes are immutable';
  END IF;
END$$
DROP TRIGGER IF EXISTS trg_hardware_staff_notice_delete$$
CREATE TRIGGER trg_hardware_staff_notice_delete BEFORE DELETE ON westy_hardware_staff_notices
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hardware staff notices cannot be deleted'$$
DROP TRIGGER IF EXISTS trg_hardware_staff_attempt_identity$$
CREATE TRIGGER trg_hardware_staff_attempt_identity BEFORE UPDATE ON westy_hardware_staff_attempts
FOR EACH ROW BEGIN
  IF NOT (NEW.tenant_id <=> OLD.tenant_id) OR NOT (NEW.staff_notice_id <=> OLD.staff_notice_id)
    OR NOT (NEW.attempt_number <=> OLD.attempt_number) OR NOT (NEW.attempted_at <=> OLD.attempted_at)
    OR OLD.completed_at IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hardware staff attempt identity and final evidence are immutable';
  END IF;
END$$
DROP TRIGGER IF EXISTS trg_hardware_staff_attempt_delete$$
CREATE TRIGGER trg_hardware_staff_attempt_delete BEFORE DELETE ON westy_hardware_staff_attempts
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hardware staff attempts cannot be deleted'$$
DELIMITER ;
