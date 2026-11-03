-- 036: Assignee intent and one-use continuation, after 025. ARTIFACT ONLY until approved.
-- Apply through deploy.sh --migrate with its database backup/write freeze.
-- Additive replay: no backfill, history rewrite, new business trigger or runtime grant.
-- Roll application code back with this table/enum/receipts RETAINED. Do not shrink
-- the enum or drop grants after use; neither would undo an accepted continuation.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE westy_workflow_receipts
  MODIFY action ENUM('claim','progress','escalate','resolve','resume') NOT NULL;

CREATE TABLE IF NOT EXISTS westy_workflow_grants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  workflow_id BIGINT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  customer_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ticket_id INT UNSIGNED NOT NULL,
  workflow_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  alert_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  grant_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  assignee_id INT UNSIGNED NOT NULL,
  expected_version INT UNSIGNED NOT NULL,
  create_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  create_response JSON NOT NULL,
  created_at DATETIME NOT NULL,
  cancelled_by INT UNSIGNED NULL,
  cancelled_at DATETIME NULL,
  cancel_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  cancel_response JSON NULL,
  consumed_event_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  continuation_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  consumed_at DATETIME NULL,
  pending_workflow_id BIGINT UNSIGNED GENERATED ALWAYS AS
    (CASE WHEN cancelled_at IS NULL AND consumed_at IS NULL THEN workflow_id ELSE NULL END) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_westy_grant_key (tenant_id,grant_key),
  UNIQUE KEY uq_westy_grant_pending (tenant_id,pending_workflow_id),
  UNIQUE KEY uq_westy_grant_event (tenant_id,consumed_event_key),
  UNIQUE KEY uq_westy_grant_continuation (tenant_id,continuation_key),
  KEY ix_westy_grant_history (tenant_id,workflow_id,id),
  CONSTRAINT fk_westy_grant_workflow FOREIGN KEY (tenant_id,workflow_id) REFERENCES westy_workflows (tenant_id,id),
  CONSTRAINT fk_westy_grant_receipt FOREIGN KEY (tenant_id,consumed_event_key) REFERENCES westy_workflow_receipts (tenant_id,event_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
