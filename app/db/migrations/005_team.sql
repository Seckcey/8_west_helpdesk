-- 005_team.sql — Sprint 4: collision detection, merge tickets, full search.
-- Fresh installs get all of this from db/schema.sql.

SET NAMES utf8mb4;

-- Who's on a ticket right now (heartbeat rows; stale after ~40s)
CREATE TABLE IF NOT EXISTS ticket_presence (
  ticket_id INT UNSIGNED NOT NULL,
  user_id   INT UNSIGNED NOT NULL,
  mode      ENUM('viewing','typing') NOT NULL DEFAULT 'viewing',
  last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (ticket_id, user_id),
  CONSTRAINT fk_presence_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE,
  CONSTRAINT fk_presence_user   FOREIGN KEY (user_id)   REFERENCES users (id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Merge: a merged ticket keeps a stub pointing at its survivor
ALTER TABLE tickets ADD COLUMN merged_into_id INT UNSIGNED NULL AFTER resurface_at;

-- Full search reaches message bodies and resolved tickets
ALTER TABLE messages ADD FULLTEXT ft_messages_body (body);
ALTER TABLE tickets  ADD FULLTEXT ft_tickets_subject (subject);
