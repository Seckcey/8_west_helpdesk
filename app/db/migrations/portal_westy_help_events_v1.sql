-- Apply migration-first under the Safeharbor release procedure. Code tolerates the tables being absent.
SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET SESSION lock_wait_timeout = 60;
CREATE TABLE IF NOT EXISTS portal_westy_help_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 tenant_id INT UNSIGNED NOT NULL,
 client_id INT UNSIGNED NOT NULL,
 turn_id BIGINT UNSIGNED NULL,
 kind ENUM('cited','gap') NOT NULL,
 article_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 catalog_version VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 surface ENUM('portal','companion') NOT NULL,
 created_at DATETIME NOT NULL,
 expires_at DATETIME NOT NULL,
 PRIMARY KEY(id),
 KEY ix_portal_help_event_client(tenant_id,client_id,created_at),
 KEY ix_portal_help_event_article(tenant_id,article_id),
 KEY ix_portal_help_event_turn(turn_id),
 KEY ix_portal_help_event_expiry(expires_at),
 CONSTRAINT fk_portal_help_event_tenant FOREIGN KEY(tenant_id) REFERENCES tenants(id),
 CONSTRAINT fk_portal_help_event_client FOREIGN KEY(tenant_id,client_id) REFERENCES clients(tenant_id,id),
 CONSTRAINT fk_portal_help_event_turn FOREIGN KEY(turn_id) REFERENCES portal_westy_turns(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS help_gap_questions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 tenant_id INT UNSIGNED NOT NULL,
 client_id INT UNSIGNED NULL,
 surface ENUM('portal','companion','technician') NOT NULL,
 question VARCHAR(200) NOT NULL,
 created_at DATETIME NOT NULL,
 expires_at DATETIME NOT NULL,
 PRIMARY KEY(id),
 KEY ix_help_gap_client(tenant_id,client_id,created_at),
 KEY ix_help_gap_expiry(expires_at),
 CONSTRAINT fk_help_gap_tenant FOREIGN KEY(tenant_id) REFERENCES tenants(id),
 CONSTRAINT fk_help_gap_client FOREIGN KEY(tenant_id,client_id) REFERENCES clients(tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
