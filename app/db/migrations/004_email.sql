-- 004_email.sql — Sprint 3: attachments, real email threading + dedupe,
-- waiting auto-resurface. Fresh installs get all of this from db/schema.sql.

SET NAMES utf8mb4;

-- Attachments (uploads on replies/notes + inbound email attachments).
-- Bytes live OUTSIDE the deploy tree (cfg storage.attachments_dir,
-- default /srv/8west/apps/safeharbor/shared/attachments) under a random
-- stored_name; the row is the only map back to the real filename.
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

-- Email conversation threading: Graph conversationId → ticket. Replies in
-- the same mail conversation land on the same ticket even when the subject
-- token is lost.
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

-- Inbound dedupe: one row per processed internetMessageId — a re-delivered
-- or double-polled mail can never create a second ticket/message.
CREATE TABLE IF NOT EXISTS processed_mail (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  internet_message_id VARCHAR(255) NOT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pm_id (internet_message_id(190))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Waiting auto-resurface: set when a ticket enters Waiting; housekeeping
-- reopens it when the date passes with no client reply.
ALTER TABLE tickets ADD COLUMN resurface_at DATETIME NULL AFTER sla_due_at;
