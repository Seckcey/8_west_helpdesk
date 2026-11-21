-- 040: Reviewed customer summaries. Retain history on rollback. After each apply
-- grant the application role EXECUTE on customer_summary_schema_health and
-- SELECT, INSERT, UPDATE on ticket_customer_summaries, as for existing review tables.
SET SESSION lock_wait_timeout=60;
SET NAMES utf8mb4;
SET time_zone = '+00:00';
DROP FUNCTION IF EXISTS customer_summary_schema_health;

SET @summary_ddl = IF(EXISTS (SELECT 1 FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='clients' AND column_name='work_summary_delivery'),
  'DO 0', 'ALTER TABLE clients ADD COLUMN work_summary_delivery ENUM(''none'',''portal'',''email'') NOT NULL DEFAULT ''portal''');
PREPARE summary_stmt FROM @summary_ddl;
EXECUTE summary_stmt;
DEALLOCATE PREPARE summary_stmt;

CREATE TABLE IF NOT EXISTS ticket_customer_summaries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  ticket_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  body VARCHAR(2000) NOT NULL,
  state ENUM('draft','approved','revoked') NOT NULL DEFAULT 'draft',
  source_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  last_log_id BIGINT UNSIGNED NOT NULL,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  emailed_at DATETIME NULL,
  email_result ENUM('claimed','submitted','unconfirmed') NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_ticket_id INT UNSIGNED GENERATED ALWAYS AS (IF(state='approved',ticket_id,NULL)) STORED,
  emailed_ticket_id INT UNSIGNED GENERATED ALWAYS AS (IF(emailed_at IS NOT NULL,ticket_id,NULL)) STORED,
  UNIQUE KEY uq_summary_approved (tenant_id,approved_ticket_id),
  UNIQUE KEY uq_summary_emailed (tenant_id,emailed_ticket_id),
  KEY ix_summary_history (tenant_id,ticket_id,id),
  CONSTRAINT fk_summary_ticket FOREIGN KEY (tenant_id,ticket_id) REFERENCES tickets (tenant_id,id),
  CONSTRAINT fk_summary_client FOREIGN KEY (tenant_id,client_id) REFERENCES clients (tenant_id,id),
  CONSTRAINT fk_summary_reviewer FOREIGN KEY (tenant_id,approved_by) REFERENCES users (tenant_id,id),
  CONSTRAINT ck_summary_review CHECK (
    (state='draft' AND approved_by IS NULL AND approved_at IS NULL AND emailed_at IS NULL)
    OR (state IN ('approved','revoked') AND approved_by IS NOT NULL AND approved_at IS NOT NULL)),
  CONSTRAINT ck_summary_mail CHECK ((emailed_at IS NULL) = (email_result IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DELIMITER $$
DROP TRIGGER IF EXISTS trg_customer_summary_insert$$
CREATE TRIGGER trg_customer_summary_insert BEFORE INSERT ON ticket_customer_summaries
FOR EACH ROW BEGIN
  IF NEW.state <> 'draft' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer summaries start as drafts';
  END IF;
END$$
DROP TRIGGER IF EXISTS trg_customer_summary_update$$
CREATE TRIGGER trg_customer_summary_update BEFORE UPDATE ON ticket_customer_summaries
FOR EACH ROW BEGIN
  IF NEW.id <> OLD.id OR NEW.tenant_id <> OLD.tenant_id OR NEW.ticket_id <> OLD.ticket_id
    OR NEW.client_id <> OLD.client_id OR NEW.last_log_id <> OLD.last_log_id
    OR BINARY NEW.source_sha256 <> BINARY OLD.source_sha256 OR NEW.created_at <> OLD.created_at
    OR (OLD.state <> 'draft' AND (BINARY NEW.body <> BINARY OLD.body
      OR NOT (NEW.approved_by <=> OLD.approved_by) OR NOT (NEW.approved_at <=> OLD.approved_at)
      OR NOT (NEW.emailed_at <=> OLD.emailed_at) OR NEW.state = 'draft'))
    OR (OLD.state = 'revoked' AND NEW.state <> 'revoked')
    OR (OLD.state = 'draft' AND (NEW.state = 'revoked' OR
        (NEW.email_result IS NOT NULL AND NEW.email_result <> 'claimed')))
    OR (OLD.state <> 'draft' AND NOT (OLD.email_result <=> NEW.email_result)
        AND NOT ((OLD.email_result <=> 'claimed') AND NEW.email_result IN ('submitted','unconfirmed'))) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reviewed customer summary is immutable';
  END IF;
END$$
DROP TRIGGER IF EXISTS trg_customer_summary_delete$$
CREATE TRIGGER trg_customer_summary_delete BEFORE DELETE ON ticket_customer_summaries
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer summary history is immutable'$$

CREATE FUNCTION customer_summary_schema_health() RETURNS TINYINT
READS SQL DATA SQL SECURITY DEFINER
BEGIN
  RETURN EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE()
      AND table_name='clients' AND column_name='work_summary_delivery'
      AND column_type='enum(''none'',''portal'',''email'')' AND column_default='portal' AND is_nullable='NO')
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE()
      AND table_name='westy_email_drafts' AND column_name IN ('tenant_id','client_id','ticket_id','state')) = 4
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE()
      AND table_name='ticket_customer_summaries' AND column_name IN
      ('id','tenant_id','ticket_id','client_id','body','state','source_sha256','last_log_id','approved_by',
       'approved_at','emailed_at','email_result','created_at','approved_ticket_id','emailed_ticket_id')) = 15
    AND (SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE()
      AND table_name='ticket_customer_summaries' AND constraint_type='UNIQUE'
      AND constraint_name IN ('uq_summary_approved','uq_summary_emailed')) = 2
    AND (SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE()
      AND table_name='ticket_customer_summaries' AND enforced='YES'
      AND constraint_name IN ('ck_summary_review','ck_summary_mail')) = 2
    AND (SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE()
      AND event_object_table='ticket_customer_summaries' AND action_timing='BEFORE' AND
      ((trigger_name='trg_customer_summary_insert' AND action_statement LIKE '%Customer summaries start as drafts%')
       OR (trigger_name='trg_customer_summary_update' AND action_statement LIKE '%Reviewed customer summary is immutable%')
       OR (trigger_name='trg_customer_summary_delete' AND action_statement LIKE '%Customer summary history is immutable%'))) = 3;
END$$
DELIMITER ;
