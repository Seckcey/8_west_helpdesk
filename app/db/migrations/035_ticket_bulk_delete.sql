-- 035_ticket_bulk_delete.sql — owner/admin bulk ticket delete.
--
-- Adds one insert-only row per deleted ticket (who deleted it, when, in which run, and the ticket's
-- non-content facts: the thread itself is gone with the ticket), plus an index that lets the delete
-- find merge partners without scanning the whole tenant. Additive: the previous code runs unchanged.
--
-- Apply only with:
--   bash deploy/deploy.sh --migrate app/db/migrations/035_ticket_bulk_delete.sql
--
-- The runtime account already holds SELECT, INSERT and UPDATE on the whole database. The delete also
-- needs DELETE on attachments and csat (docs/ticket-bulk-delete.md); until those two grants exist,
-- tickets with an attachment or a satisfaction survey are kept and the preview says why.

SET NAMES utf8mb4;

-- No foreign key to the ticket (it is gone) or to the client (an emptied client must stay
-- deletable). UNIQUE(tenant_id, ticket_id) is a deliberate tripwire: ticket ids are never reused,
-- so a second row for the same ticket can only mean something is badly wrong, and the batch that
-- tried to write it rolls back.
CREATE TABLE IF NOT EXISTS ticket_deletions (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id          INT UNSIGNED NOT NULL,
  run_key            CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ticket_id          INT UNSIGNED NOT NULL,
  client_id          INT UNSIGNED NOT NULL,
  channel            ENUM('email','portal','alert','phone') NOT NULL,
  status             ENUM('open','in_progress','waiting','resolved') NOT NULL,
  external_key       VARCHAR(64) NULL,
  merged_into_id     INT UNSIGNED NULL,
  ticket_created_at  DATETIME NOT NULL,
  ticket_resolved_at DATETIME NULL,
  message_count      INT UNSIGNED NOT NULL,
  attachment_count   INT UNSIGNED NOT NULL,
  csat_score         TINYINT UNSIGNED NULL,
  mail_cancelled     INT UNSIGNED NOT NULL,
  deleted_by_user_id INT UNSIGNED NOT NULL,
  deleted_at         DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ticket_deletions_ticket (tenant_id, ticket_id),
  KEY ix_ticket_deletions_run (tenant_id, run_key),
  CONSTRAINT fk_ticket_deletions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_ticket_deletions_actor FOREIGN KEY (tenant_id, deleted_by_user_id) REFERENCES users (tenant_id, id),
  CONSTRAINT ck_ticket_deletions_run_key CHECK (REGEXP_LIKE(run_key, _ascii'^[0-9a-f]{32}$'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DELIMITER $$

DROP TRIGGER IF EXISTS trg_ticket_deletions_no_update$$
CREATE TRIGGER trg_ticket_deletions_no_update
BEFORE UPDATE ON ticket_deletions
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ticket deletion records are immutable';
END$$

DROP TRIGGER IF EXISTS trg_ticket_deletions_no_delete$$
CREATE TRIGGER trg_ticket_deletions_no_delete
BEFORE DELETE ON ticket_deletions
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ticket deletion records are immutable';
END$$

DELIMITER ;

-- Merge partners are found by merged_into_id under row locks; without this index that locking read
-- would scan, and lock, every ticket in the tenant. Same guarded shape check as migration 010: a
-- missing index is added, the exact index is left alone, and a same-named index of any other shape
-- makes the ADD fail loudly instead of being silently accepted.
SET @ticket_merge_index_count = (
  SELECT COUNT(DISTINCT index_name)
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'tickets'
     AND index_name = 'ix_tickets_merged_into'
);
SET @ticket_merge_index_exact = (
  SELECT COUNT(*)
    FROM (
      SELECT index_name
        FROM information_schema.statistics
       WHERE table_schema = DATABASE()
         AND table_name = 'tickets'
         AND index_name = 'ix_tickets_merged_into'
       GROUP BY index_name
      HAVING COUNT(*) = 2
         AND MIN(non_unique) = 1
         AND MAX(non_unique) = 1
         AND MIN(index_type) = 'BTREE'
         AND MAX(index_type) = 'BTREE'
         AND SUM(sub_part IS NOT NULL) = 0
         AND GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'tenant_id,merged_into_id'
    ) exact_merge_index
);
SET @ticket_merge_ddl = IF(
  @ticket_merge_index_count = 0 OR @ticket_merge_index_exact = 0,
  'ALTER TABLE tickets ADD KEY ix_tickets_merged_into (tenant_id, merged_into_id)',
  'DO 0'
);
PREPARE ticket_merge_statement FROM @ticket_merge_ddl;
EXECUTE ticket_merge_statement;
DEALLOCATE PREPARE ticket_merge_statement;
