-- Apply migration-first under the Safeharbor release procedure. Code tolerates the table being absent.
SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET SESSION lock_wait_timeout = 60;
CREATE TABLE IF NOT EXISTS portal_guide_votes (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 tenant_id INT UNSIGNED NOT NULL,
 client_id INT UNSIGNED NOT NULL,
 scope_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 article_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 catalog_version VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 helpful TINYINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 expires_at DATETIME NOT NULL,
 PRIMARY KEY(id),
 UNIQUE KEY uq_portal_guide_vote(scope_key,article_id),
 KEY ix_portal_guide_vote_article(tenant_id,article_id,helpful,updated_at),
 KEY ix_portal_guide_vote_expiry(expires_at),
 KEY ix_portal_guide_vote_scope(tenant_id,client_id,scope_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
