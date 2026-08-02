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
  UNIQUE KEY uq_users_tenant_email (tenant_id, email),
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
  KEY ix_clients_tenant (tenant_id),
  CONSTRAINT fk_clients_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
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
  sla_due_at  DATETIME NOT NULL,
  resurface_at DATETIME NULL,   -- waiting auto-resurface (housekeeping reopens)
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_tickets_tenant_status (tenant_id, status),
  KEY ix_tickets_client (client_id),
  KEY ix_tickets_assignee (assignee_id),
  CONSTRAINT fk_tickets_tenant   FOREIGN KEY (tenant_id)   REFERENCES tenants (id),
  CONSTRAINT fk_tickets_client   FOREIGN KEY (client_id)   REFERENCES clients (id),
  CONSTRAINT fk_tickets_contact  FOREIGN KEY (contact_id)  REFERENCES contacts (id),
  CONSTRAINT fk_tickets_assignee FOREIGN KEY (assignee_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
  CONSTRAINT fk_messages_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id)
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
-- Time entries (billable work; approved entries flow to Coastmark in Phase 2)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS time_entries (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id  INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  minutes    INT UNSIGNED NOT NULL,
  note       VARCHAR(255) NOT NULL DEFAULT '',
  billable   TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_time_ticket (ticket_id),
  KEY ix_time_user_created (user_id, created_at),
  CONSTRAINT fk_time_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id),
  CONSTRAINT fk_time_user   FOREIGN KEY (user_id)   REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
