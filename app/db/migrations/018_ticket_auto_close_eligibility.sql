-- 018_ticket_auto_close_eligibility.sql — one-use, fail-closed ownership for
-- Milepost telemetry ticket recovery.
--
-- Existing rows begin ineligible. A row is backfilled only when the database
-- can prove it is a never-updated, never-re-fired machine alert with exactly
-- one original Milepost system message and no human/customer/time/merge facts.
-- Replays never backfill again, so a cleared capability can never return.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Prove trigger privilege on every guarded table before changing schema.
-- An application-only account therefore fails before the additive DDL.
DROP TRIGGER IF EXISTS trg_ticket_auto_close_018_preflight_ticket;
CREATE TRIGGER trg_ticket_auto_close_018_preflight_ticket
BEFORE INSERT ON tickets
FOR EACH ROW SET @ticket_auto_close_018_preflight_ticket = 1;
DROP TRIGGER trg_ticket_auto_close_018_preflight_ticket;

DROP TRIGGER IF EXISTS trg_ticket_auto_close_018_preflight_message;
CREATE TRIGGER trg_ticket_auto_close_018_preflight_message
BEFORE INSERT ON messages
FOR EACH ROW SET @ticket_auto_close_018_preflight_message = 1;
DROP TRIGGER trg_ticket_auto_close_018_preflight_message;

DROP TRIGGER IF EXISTS trg_ticket_auto_close_018_preflight_time;
CREATE TRIGGER trg_ticket_auto_close_018_preflight_time
BEFORE INSERT ON time_entries
FOR EACH ROW SET @ticket_auto_close_018_preflight_time = 1;
DROP TRIGGER trg_ticket_auto_close_018_preflight_time;

DROP PROCEDURE IF EXISTS safeharbor_migrate_ticket_auto_close_eligibility;
DELIMITER $$
CREATE PROCEDURE safeharbor_migrate_ticket_auto_close_eligibility()
BEGIN
  DECLARE column_count INT DEFAULT 0;
  DECLARE column_exact INT DEFAULT 0;
  DECLARE check_count INT DEFAULT 0;
  DECLARE check_exact INT DEFAULT 0;

  SELECT COUNT(*),
         COALESCE(SUM(
           column_type = 'tinyint(1)'
           AND is_nullable = 'NO'
           AND CAST(column_default AS CHAR) = '0'
           AND extra = ''
         ), 0)
    INTO column_count, column_exact
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'tickets'
     AND column_name = 'auto_close_eligible';

  IF column_count > 0 AND (column_count <> 1 OR column_exact <> 1) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'tickets.auto_close_eligible has unexpected shape';
  END IF;

  SELECT COUNT(*),
         COALESCE(SUM(
           tc.enforced = 'YES'
           AND REGEXP_REPLACE(
                 REPLACE(LOWER(cc.check_clause), '`', ''),
                 '[[:space:]]+',
                 ''
               ) = '(auto_close_eligiblein(0,1))'
         ), 0)
    INTO check_count, check_exact
    FROM information_schema.table_constraints tc
    JOIN information_schema.check_constraints cc
      ON cc.constraint_schema = tc.constraint_schema
     AND cc.constraint_name = tc.constraint_name
   WHERE tc.constraint_schema = DATABASE()
     AND tc.table_name = 'tickets'
     AND tc.constraint_type = 'CHECK'
     AND tc.constraint_name = 'ck_tickets_auto_close_eligible';

  IF check_count > 0 AND (check_count <> 1 OR check_exact <> 1) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'ck_tickets_auto_close_eligible has unexpected shape';
  END IF;

  IF column_count = 0 THEN
    ALTER TABLE tickets
      ADD COLUMN auto_close_eligible TINYINT(1) NOT NULL DEFAULT 0
        AFTER external_key;
  END IF;

  IF check_count = 0 THEN
    ALTER TABLE tickets
      ADD CONSTRAINT ck_tickets_auto_close_eligible
      CHECK (auto_close_eligible IN (0,1));
  END IF;

  -- This runs only on first creation. updated_at is explicitly preserved so
  -- the eligibility migration does not rewrite operational history.
  IF column_count = 0 THEN
    DROP TEMPORARY TABLE IF EXISTS ticket_auto_close_018_backfill;
    CREATE TEMPORARY TABLE ticket_auto_close_018_backfill (
      ticket_id INT UNSIGNED NOT NULL,
      PRIMARY KEY (ticket_id)
    ) ENGINE=InnoDB;

    INSERT INTO ticket_auto_close_018_backfill (ticket_id)
    SELECT t.id
      FROM tickets t
     WHERE t.status = 'open'
       AND t.channel = 'alert'
       AND t.external_key REGEXP '^alert:[1-9][0-9]{0,19}$'
       AND (
         CHAR_LENGTH(SUBSTRING(t.external_key, 7)) < 20
         OR BINARY SUBSTRING(t.external_key, 7) <= BINARY '18446744073709551615'
       )
       AND t.contact_id IS NULL
       AND t.assignee_id IS NULL
       AND t.resurface_at IS NULL
       AND t.merged_into_id IS NULL
       AND t.resolved_at IS NULL
       AND t.updated_at = t.created_at
       AND (SELECT COUNT(*) FROM messages m WHERE m.ticket_id = t.id) = 1
       AND EXISTS (
         SELECT 1
           FROM messages m
          WHERE m.ticket_id = t.id
            AND m.kind = 'system'
            AND BINARY m.author_name = BINARY 'Milepost'
            AND m.body LIKE 'Alert opened at source at %'
       )
       AND NOT EXISTS (
         SELECT 1 FROM time_entries te WHERE te.ticket_id = t.id
       )
       AND NOT EXISTS (
         SELECT 1
           FROM tickets merged_source
          WHERE merged_source.tenant_id = t.tenant_id
            AND merged_source.merged_into_id = t.id
       );

    UPDATE tickets t
    JOIN ticket_auto_close_018_backfill proven ON proven.ticket_id = t.id
       SET t.auto_close_eligible = 1,
           t.updated_at = t.updated_at;
    DROP TEMPORARY TABLE ticket_auto_close_018_backfill;
  END IF;
END$$
DELIMITER ;

CALL safeharbor_migrate_ticket_auto_close_eligibility();
DROP PROCEDURE safeharbor_migrate_ticket_auto_close_eligibility;

-- Block all affected writes while permanent guards are replaced. Production
-- also freezes the Safeharbor runtime account, but migration safety does not
-- rely on that external step being remembered.
DROP TRIGGER IF EXISTS trg_ticket_auto_close_018_swap_ticket_insert;
CREATE TRIGGER trg_ticket_auto_close_018_swap_ticket_insert
BEFORE INSERT ON tickets
FOR EACH ROW SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Migration 018 ticket guard swap is in progress';

DROP TRIGGER IF EXISTS trg_ticket_auto_close_018_swap_ticket_update;
CREATE TRIGGER trg_ticket_auto_close_018_swap_ticket_update
BEFORE UPDATE ON tickets
FOR EACH ROW SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Migration 018 ticket guard swap is in progress';

DROP TRIGGER IF EXISTS trg_ticket_auto_close_018_swap_message_insert;
CREATE TRIGGER trg_ticket_auto_close_018_swap_message_insert
BEFORE INSERT ON messages
FOR EACH ROW SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Migration 018 ticket guard swap is in progress';

DROP TRIGGER IF EXISTS trg_ticket_auto_close_018_swap_message_update;
CREATE TRIGGER trg_ticket_auto_close_018_swap_message_update
BEFORE UPDATE ON messages
FOR EACH ROW SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Migration 018 ticket guard swap is in progress';

DROP TRIGGER IF EXISTS trg_ticket_auto_close_018_swap_message_delete;
CREATE TRIGGER trg_ticket_auto_close_018_swap_message_delete
BEFORE DELETE ON messages
FOR EACH ROW SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Migration 018 ticket guard swap is in progress';

DROP TRIGGER IF EXISTS trg_ticket_auto_close_018_swap_time_insert;
CREATE TRIGGER trg_ticket_auto_close_018_swap_time_insert
BEFORE INSERT ON time_entries
FOR EACH ROW SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Migration 018 ticket guard swap is in progress';

DROP PROCEDURE IF EXISTS safeharbor_assert_ticket_auto_close_swap;
DELIMITER $$
CREATE PROCEDURE safeharbor_assert_ticket_auto_close_swap()
BEGIN
  IF (SELECT COUNT(*)
        FROM information_schema.triggers
       WHERE trigger_schema = DATABASE()
         AND trigger_name LIKE 'trg_ticket_auto_close_018_swap_%'
         AND action_statement LIKE '%Migration 018 ticket guard swap is in progress%') <> 6 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Migration 018 fail-closed swap guards are incomplete';
  END IF;
END$$
DELIMITER ;
CALL safeharbor_assert_ticket_auto_close_swap();
DROP PROCEDURE safeharbor_assert_ticket_auto_close_swap;

DROP TRIGGER IF EXISTS trg_tickets_auto_close_before_insert;
DROP TRIGGER IF EXISTS trg_tickets_auto_close_before_update;
DROP TRIGGER IF EXISTS trg_messages_auto_close_after_insert;
DROP TRIGGER IF EXISTS trg_messages_auto_close_after_update;
DROP TRIGGER IF EXISTS trg_messages_auto_close_after_delete;
DROP TRIGGER IF EXISTS trg_time_entries_auto_close_after_insert;

DELIMITER $$
CREATE TRIGGER trg_tickets_auto_close_before_insert
BEFORE INSERT ON tickets
FOR EACH ROW
BEGIN
  IF NEW.auto_close_eligible = 1
     AND (
       NEW.status <> 'open'
       OR NEW.channel <> 'alert'
       OR NEW.external_key IS NULL
       OR NEW.external_key NOT REGEXP '^alert:[1-9][0-9]{0,19}$'
       OR (
         CHAR_LENGTH(SUBSTRING(NEW.external_key, 7)) = 20
         AND BINARY SUBSTRING(NEW.external_key, 7) > BINARY '18446744073709551615'
       )
       OR NEW.contact_id IS NOT NULL
       OR NEW.assignee_id IS NOT NULL
       OR NEW.resurface_at IS NOT NULL
       OR NEW.merged_into_id IS NOT NULL
       OR NEW.resolved_at IS NOT NULL
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Automatic close is reserved for untouched Milepost alerts';
  END IF;
END$$

CREATE TRIGGER trg_tickets_auto_close_before_update
BEFORE UPDATE ON tickets
FOR EACH ROW
BEGIN
  IF OLD.auto_close_eligible = 0 AND NEW.auto_close_eligible = 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Automatic close eligibility cannot be restored';
  END IF;

  -- No machine path updates a ticket in place. Re-fire evidence is an
  -- append-only Milepost system message. Every UPDATE is therefore a touch,
  -- and silently consumes the one-use capability unless the statement has
  -- already consumed it (the guarded recovery transition does so explicitly).
  IF OLD.auto_close_eligible = 1 AND NEW.auto_close_eligible = 1 THEN
    SET NEW.auto_close_eligible = 0;
  END IF;
END$$

CREATE TRIGGER trg_messages_auto_close_after_insert
AFTER INSERT ON messages
FOR EACH ROW
BEGIN
  DECLARE ticket_eligibility INT DEFAULT 0;

  -- Keep an emergency rollback to the pre-018 handler safe: that code writes
  -- this line before its unguarded status UPDATE. Refuse the line unless the
  -- ticket still owns the one-use capability, which aborts that legacy path
  -- for every human-touched/default-ineligible ticket.
  IF NEW.kind = 'system'
     AND BINARY NEW.author_name = BINARY 'Milepost'
     AND NEW.body LIKE 'Resolved at source at % UTC. Ticket auto-closed.' THEN
    SELECT auto_close_eligible INTO ticket_eligibility
      FROM tickets
     WHERE id = NEW.ticket_id;
    IF ticket_eligibility <> 1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Ineligible ticket cannot receive an automatic-close line';
    END IF;
  ELSEIF NEW.kind <> 'system' OR BINARY NEW.author_name <> BINARY 'Milepost' THEN
    UPDATE tickets
       SET auto_close_eligible = 0
     WHERE id = NEW.ticket_id
       AND auto_close_eligible = 1;
  END IF;
END$$

CREATE TRIGGER trg_messages_auto_close_after_update
AFTER UPDATE ON messages
FOR EACH ROW
BEGIN
  -- Editing or moving any existing message is a human workflow operation.
  UPDATE tickets
     SET auto_close_eligible = 0
   WHERE id IN (OLD.ticket_id, NEW.ticket_id)
     AND auto_close_eligible = 1;
END$$

CREATE TRIGGER trg_messages_auto_close_after_delete
AFTER DELETE ON messages
FOR EACH ROW
BEGIN
  -- Deleting evidence is itself a human mutation and can never make a ticket
  -- look untouched again.
  UPDATE tickets
     SET auto_close_eligible = 0
   WHERE id = OLD.ticket_id
     AND auto_close_eligible = 1;
END$$

CREATE TRIGGER trg_time_entries_auto_close_after_insert
AFTER INSERT ON time_entries
FOR EACH ROW
BEGIN
  UPDATE tickets
     SET auto_close_eligible = 0
   WHERE id = NEW.ticket_id
     AND tenant_id = NEW.tenant_id
     AND auto_close_eligible = 1;
END$$
DELIMITER ;

DROP PROCEDURE IF EXISTS safeharbor_assert_ticket_auto_close_permanent;
DELIMITER $$
CREATE PROCEDURE safeharbor_assert_ticket_auto_close_permanent(
  IN expected_swap_guards INT
)
BEGIN
  IF (SELECT COUNT(*)
        FROM information_schema.columns
       WHERE table_schema = DATABASE()
         AND table_name = 'tickets'
         AND column_name = 'auto_close_eligible'
         AND column_type = 'tinyint(1)'
         AND is_nullable = 'NO'
         AND CAST(column_default AS CHAR) = '0'
         AND extra = '') <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Migration 018 auto-close column postflight failed';
  END IF;

  IF (SELECT COUNT(*)
        FROM information_schema.table_constraints tc
        JOIN information_schema.check_constraints cc
          ON cc.constraint_schema = tc.constraint_schema
         AND cc.constraint_name = tc.constraint_name
       WHERE tc.constraint_schema = DATABASE()
         AND tc.table_name = 'tickets'
         AND tc.constraint_name = 'ck_tickets_auto_close_eligible'
         AND tc.enforced = 'YES'
         AND REGEXP_REPLACE(
               REPLACE(LOWER(cc.check_clause), '`', ''),
               '[[:space:]]+',
               ''
             ) = '(auto_close_eligiblein(0,1))') <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Migration 018 auto-close check postflight failed';
  END IF;

  IF (SELECT COUNT(*)
        FROM information_schema.triggers
       WHERE trigger_schema = DATABASE()
         AND action_orientation = 'ROW'
         AND action_statement LIKE '%auto_close_eligible%'
         AND (
           (trigger_name = 'trg_tickets_auto_close_before_insert'
             AND event_object_table = 'tickets'
             AND action_timing = 'BEFORE' AND event_manipulation = 'INSERT')
           OR (trigger_name = 'trg_tickets_auto_close_before_update'
             AND event_object_table = 'tickets'
             AND action_timing = 'BEFORE' AND event_manipulation = 'UPDATE')
           OR (trigger_name = 'trg_messages_auto_close_after_insert'
             AND event_object_table = 'messages'
             AND action_timing = 'AFTER' AND event_manipulation = 'INSERT')
           OR (trigger_name = 'trg_messages_auto_close_after_update'
             AND event_object_table = 'messages'
             AND action_timing = 'AFTER' AND event_manipulation = 'UPDATE')
           OR (trigger_name = 'trg_messages_auto_close_after_delete'
             AND event_object_table = 'messages'
             AND action_timing = 'AFTER' AND event_manipulation = 'DELETE')
           OR (trigger_name = 'trg_time_entries_auto_close_after_insert'
             AND event_object_table = 'time_entries'
             AND action_timing = 'AFTER' AND event_manipulation = 'INSERT')
         )) <> 6 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Migration 018 permanent auto-close guards are incomplete';
  END IF;

  IF (SELECT COUNT(*)
        FROM information_schema.triggers
       WHERE trigger_schema = DATABASE()
         AND trigger_name LIKE 'trg_ticket_auto_close_018_swap_%') <> expected_swap_guards
     OR (SELECT COUNT(*)
           FROM information_schema.triggers
          WHERE trigger_schema = DATABASE()
            AND trigger_name LIKE 'trg_ticket_auto_close_018_preflight_%') <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Migration 018 temporary guard state is incomplete';
  END IF;

  IF (SELECT COUNT(*)
        FROM tickets t
       WHERE t.auto_close_eligible = 1
         AND (
           t.status <> 'open'
           OR t.channel <> 'alert'
           OR t.external_key IS NULL
           OR t.external_key NOT REGEXP '^alert:[1-9][0-9]{0,19}$'
           OR (
             CHAR_LENGTH(SUBSTRING(t.external_key, 7)) = 20
             AND BINARY SUBSTRING(t.external_key, 7) > BINARY '18446744073709551615'
           )
           OR t.contact_id IS NOT NULL
           OR t.assignee_id IS NOT NULL
           OR t.resurface_at IS NOT NULL
           OR t.merged_into_id IS NOT NULL
           OR t.resolved_at IS NOT NULL
           OR NOT EXISTS (
             SELECT 1 FROM messages m
              WHERE m.ticket_id = t.id
                AND m.kind = 'system'
                AND BINARY m.author_name = BINARY 'Milepost'
           )
           OR EXISTS (
             SELECT 1 FROM messages m
              WHERE m.ticket_id = t.id
                AND (m.kind <> 'system'
                     OR BINARY m.author_name <> BINARY 'Milepost')
           )
           OR EXISTS (SELECT 1 FROM time_entries te WHERE te.ticket_id = t.id)
           OR EXISTS (
             SELECT 1 FROM tickets merged_source
              WHERE merged_source.tenant_id = t.tenant_id
                AND merged_source.merged_into_id = t.id
           )
         )) <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Migration 018 found an ineligible automatic-close row';
  END IF;
END$$
DELIMITER ;

-- Remove the write freeze only after every permanent guard has passed.
CALL safeharbor_assert_ticket_auto_close_permanent(6);
DROP TRIGGER trg_ticket_auto_close_018_swap_ticket_insert;
DROP TRIGGER trg_ticket_auto_close_018_swap_ticket_update;
DROP TRIGGER trg_ticket_auto_close_018_swap_message_insert;
DROP TRIGGER trg_ticket_auto_close_018_swap_message_update;
DROP TRIGGER trg_ticket_auto_close_018_swap_message_delete;
DROP TRIGGER trg_ticket_auto_close_018_swap_time_insert;
CALL safeharbor_assert_ticket_auto_close_permanent(0);
DROP PROCEDURE safeharbor_assert_ticket_auto_close_permanent;

SELECT
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'tickets'
      AND column_name = 'auto_close_eligible') AS auto_close_columns,
  (SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema = DATABASE()
      AND trigger_name IN (
        'trg_tickets_auto_close_before_insert',
        'trg_tickets_auto_close_before_update',
        'trg_messages_auto_close_after_insert',
        'trg_messages_auto_close_after_update',
        'trg_messages_auto_close_after_delete',
        'trg_time_entries_auto_close_after_insert'
      )) AS auto_close_triggers,
  (SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema = DATABASE()
      AND trigger_name LIKE 'trg_ticket_auto_close_018_swap_%') AS swap_triggers,
  (SELECT COUNT(*) FROM tickets WHERE auto_close_eligible = 1) AS eligible_tickets;
