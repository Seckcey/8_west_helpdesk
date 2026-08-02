-- 006_reports_csat.sql — Sprint 5: one-click CSAT. Fresh installs get this
-- from db/schema.sql.

SET NAMES utf8mb4;

-- One survey per resolved ticket; the token is the whole auth (no login).
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
