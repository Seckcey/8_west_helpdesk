-- 001_mail_queue.sql — outbound mail queue (Milepost mailer pattern).
-- The 1-min dispatch cron sends from this table; producers only INSERT.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS mail_queue (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  to_addr      VARCHAR(190) NOT NULL,
  subject      VARCHAR(255) NOT NULL,
  body_text    TEXT NOT NULL,
  ticket_id    INT UNSIGNED NULL,
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
  last_error   VARCHAR(255) NOT NULL DEFAULT '',
  next_try_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at      DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_mail_due (sent_at, next_try_at),
  CONSTRAINT fk_mail_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
