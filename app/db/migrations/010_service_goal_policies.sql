-- 010_service_goal_policies.sql — immutable, versioned service-goal targets.
-- Additive only. MySQL 8 / utf8mb4 / InnoDB (no ADD COLUMN IF NOT EXISTS).
-- Run manually before deploying code that writes service_goal_target_id.
--
-- Existing tickets deliberately remain NULL: their creation-time client tier
-- cannot be reconstructed safely. New tickets snapshot one exact target row.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS service_goal_policy_versions (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id      INT UNSIGNED NOT NULL,
  policy_key     VARCHAR(32) NOT NULL,
  version_no     SMALLINT UNSIGNED NOT NULL,
  display_name   VARCHAR(80) NOT NULL,
  effective_from DATETIME NOT NULL,
  clock_mode     ENUM('elapsed','business_hours') NOT NULL DEFAULT 'elapsed',
  time_zone      VARCHAR(64) NOT NULL DEFAULT 'UTC',
  pause_mode     ENUM('none','waiting') NOT NULL DEFAULT 'none',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_goal_policy_version (tenant_id, policy_key, version_no),
  UNIQUE KEY uq_goal_policy_tenant_id (tenant_id, id),
  KEY ix_goal_policy_effective (tenant_id, policy_key, effective_from, version_no),
  CONSTRAINT fk_goal_policy_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS service_goal_policy_targets (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id              INT UNSIGNED NOT NULL,
  policy_version_id      INT UNSIGNED NOT NULL,
  priority               ENUM('low','normal','high','urgent') NOT NULL,
  first_response_minutes INT UNSIGNED NOT NULL,
  resolution_minutes     INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_goal_target_priority (tenant_id, policy_version_id, priority),
  UNIQUE KEY uq_goal_target_tenant_id (tenant_id, id),
  KEY ix_goal_target_policy (tenant_id, policy_version_id),
  CONSTRAINT fk_goal_target_policy FOREIGN KEY (tenant_id, policy_version_id)
    REFERENCES service_goal_policy_versions (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Baseline policies preserve today's elapsed targets. The epoch effective
-- date makes v1 available to every future ticket without claiming history.
INSERT INTO service_goal_policy_versions
  (tenant_id, policy_key, version_no, display_name, effective_from, clock_mode, time_zone, pause_mode)
SELECT tenant.id, 'standard', 1, 'Standard', '1970-01-01 00:00:00', 'elapsed', 'UTC', 'none'
  FROM tenants tenant
 WHERE NOT EXISTS (
       SELECT 1
         FROM service_goal_policy_versions existing
        WHERE existing.tenant_id = tenant.id
          AND existing.policy_key = 'standard'
          AND existing.version_no = 1
 );

INSERT INTO service_goal_policy_versions
  (tenant_id, policy_key, version_no, display_name, effective_from, clock_mode, time_zone, pause_mode)
SELECT tenant.id, 'premium', 1, 'Premium', '1970-01-01 00:00:00', 'elapsed', 'UTC', 'none'
  FROM tenants tenant
 WHERE NOT EXISTS (
       SELECT 1
         FROM service_goal_policy_versions existing
        WHERE existing.tenant_id = tenant.id
          AND existing.policy_key = 'premium'
          AND existing.version_no = 1
 );

INSERT INTO service_goal_policy_targets
  (tenant_id, policy_version_id, priority, first_response_minutes, resolution_minutes)
SELECT policy.tenant_id,
       policy.id,
       priorities.priority,
       CASE policy.policy_key WHEN 'premium' THEN 120 ELSE 480 END,
       NULL
  FROM service_goal_policy_versions policy
  CROSS JOIN (
       SELECT 'low' AS priority
       UNION ALL SELECT 'normal'
       UNION ALL SELECT 'high'
       UNION ALL SELECT 'urgent'
  ) priorities
  LEFT JOIN service_goal_policy_targets existing
    ON existing.tenant_id = policy.tenant_id
   AND existing.policy_version_id = policy.id
   AND existing.priority = priorities.priority
 WHERE policy.version_no = 1
   AND policy.policy_key IN ('standard', 'premium')
   AND existing.id IS NULL;

-- Guard each ticket object independently so a verified migration can be
-- replayed after an interrupted run. A same-named but incompatible object is
-- deliberately not hidden: the attempted ADD fails on the duplicate name.
SET @goal_ticket_column_count = (
  SELECT COUNT(*)
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'tickets'
     AND column_name = 'service_goal_target_id'
);
SET @goal_ticket_column_exact = (
  SELECT COUNT(*)
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'tickets'
     AND column_name = 'service_goal_target_id'
     AND column_type = 'int unsigned'
     AND is_nullable = 'YES'
     AND column_default IS NULL
     AND extra = ''
);
SET @goal_ddl = IF(
  @goal_ticket_column_count = 0 OR @goal_ticket_column_exact = 0,
  'ALTER TABLE tickets ADD COLUMN service_goal_target_id INT UNSIGNED NULL AFTER sla_due_at',
  'DO 0'
);
PREPARE goal_statement FROM @goal_ddl;
EXECUTE goal_statement;
DEALLOCATE PREPARE goal_statement;

SET @goal_ticket_index_count = (
  SELECT COUNT(DISTINCT index_name)
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'tickets'
     AND index_name = 'ix_tickets_service_goal_target'
);
SET @goal_ticket_index_exact = (
  SELECT COUNT(*)
    FROM (
      SELECT index_name
        FROM information_schema.statistics
       WHERE table_schema = DATABASE()
         AND table_name = 'tickets'
         AND index_name = 'ix_tickets_service_goal_target'
       GROUP BY index_name
      HAVING COUNT(*) = 2
         AND MIN(non_unique) = 1
         AND MAX(non_unique) = 1
         AND MIN(index_type) = 'BTREE'
         AND MAX(index_type) = 'BTREE'
         AND SUM(sub_part IS NOT NULL) = 0
         AND GROUP_CONCAT(column_name ORDER BY seq_in_index)
             = 'tenant_id,service_goal_target_id'
    ) exact_goal_index
);
SET @goal_ddl = IF(
  @goal_ticket_index_count = 0 OR @goal_ticket_index_exact = 0,
  'ALTER TABLE tickets ADD KEY ix_tickets_service_goal_target (tenant_id, service_goal_target_id)',
  'DO 0'
);
PREPARE goal_statement FROM @goal_ddl;
EXECUTE goal_statement;
DEALLOCATE PREPARE goal_statement;

SET @goal_ticket_fk_count = (
  SELECT COUNT(*)
    FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND table_name = 'tickets'
     AND constraint_name = 'fk_tickets_service_goal_target'
     AND constraint_type = 'FOREIGN KEY'
);
SET @goal_ticket_fk_exact = (
  SELECT COUNT(*)
    FROM (
      SELECT constraint_name
        FROM information_schema.key_column_usage
       WHERE constraint_schema = DATABASE()
         AND table_name = 'tickets'
         AND constraint_name = 'fk_tickets_service_goal_target'
         AND referenced_table_name = 'service_goal_policy_targets'
       GROUP BY constraint_name
      HAVING GROUP_CONCAT(
               CONCAT(column_name, '=', referenced_column_name)
               ORDER BY ordinal_position
             ) = 'tenant_id=tenant_id,service_goal_target_id=id'
    ) exact_goal_fk
);
SET @goal_ddl = IF(
  @goal_ticket_fk_count = 0 OR @goal_ticket_fk_exact = 0,
  'ALTER TABLE tickets ADD CONSTRAINT fk_tickets_service_goal_target FOREIGN KEY (tenant_id, service_goal_target_id) REFERENCES service_goal_policy_targets (tenant_id, id)',
  'DO 0'
);
PREPARE goal_statement FROM @goal_ddl;
EXECUTE goal_statement;
DEALLOCATE PREPARE goal_statement;

-- MySQL cannot prepare CREATE TRIGGER. Dropping and immediately recreating
-- these exact guards keeps replay deterministic; application code has no
-- policy mutation path. The migration still runs only through an operator
-- gate, before code that writes ticket snapshots.
-- First prove the connection can CREATE one: an accidental app-user replay
-- must fail here without dropping any existing immutable guard.
DROP TRIGGER IF EXISTS trg_goal_policy_privilege_preflight;
CREATE TRIGGER trg_goal_policy_privilege_preflight
BEFORE INSERT ON service_goal_policy_versions
FOR EACH ROW
SET @goal_trigger_privilege_preflight = 1;
DROP TRIGGER trg_goal_policy_privilege_preflight;

DROP TRIGGER IF EXISTS trg_goal_policy_versions_no_update;
CREATE TRIGGER trg_goal_policy_versions_no_update
BEFORE UPDATE ON service_goal_policy_versions
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Service-goal policy versions are immutable';

DROP TRIGGER IF EXISTS trg_goal_policy_versions_no_delete;
CREATE TRIGGER trg_goal_policy_versions_no_delete
BEFORE DELETE ON service_goal_policy_versions
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Service-goal policy versions are immutable';

DROP TRIGGER IF EXISTS trg_goal_policy_targets_no_update;
CREATE TRIGGER trg_goal_policy_targets_no_update
BEFORE UPDATE ON service_goal_policy_targets
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Service-goal policy targets are immutable';

DROP TRIGGER IF EXISTS trg_goal_policy_targets_no_delete;
CREATE TRIGGER trg_goal_policy_targets_no_delete
BEFORE DELETE ON service_goal_policy_targets
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Service-goal policy targets are immutable';

SET @goal_ticket_column_exact = (
  SELECT COUNT(*)
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'tickets'
     AND column_name = 'service_goal_target_id'
     AND column_type = 'int unsigned'
     AND is_nullable = 'YES'
     AND column_default IS NULL
     AND extra = ''
);
SET @goal_ticket_index_exact = (
  SELECT COUNT(*)
    FROM (
      SELECT index_name
        FROM information_schema.statistics
       WHERE table_schema = DATABASE()
         AND table_name = 'tickets'
         AND index_name = 'ix_tickets_service_goal_target'
       GROUP BY index_name
      HAVING COUNT(*) = 2
         AND MIN(non_unique) = 1
         AND MAX(non_unique) = 1
         AND MIN(index_type) = 'BTREE'
         AND MAX(index_type) = 'BTREE'
         AND SUM(sub_part IS NOT NULL) = 0
         AND GROUP_CONCAT(column_name ORDER BY seq_in_index)
             = 'tenant_id,service_goal_target_id'
    ) exact_goal_index
);
SET @goal_ticket_fk_exact = (
  SELECT COUNT(*)
    FROM (
      SELECT constraint_name
        FROM information_schema.key_column_usage
       WHERE constraint_schema = DATABASE()
         AND table_name = 'tickets'
         AND constraint_name = 'fk_tickets_service_goal_target'
         AND referenced_table_name = 'service_goal_policy_targets'
       GROUP BY constraint_name
      HAVING GROUP_CONCAT(
               CONCAT(column_name, '=', referenced_column_name)
               ORDER BY ordinal_position
             ) = 'tenant_id=tenant_id,service_goal_target_id=id'
    ) exact_goal_fk
);

SELECT
  @goal_ticket_column_exact AS ticket_target_column_ok,
  @goal_ticket_index_exact AS ticket_target_index_ok,
  @goal_ticket_fk_exact AS ticket_target_fk_ok,
  (
    SELECT COUNT(*) = 4
      FROM information_schema.triggers
     WHERE trigger_schema = DATABASE()
       AND trigger_name IN (
         'trg_goal_policy_versions_no_update',
         'trg_goal_policy_versions_no_delete',
         'trg_goal_policy_targets_no_update',
         'trg_goal_policy_targets_no_delete'
       )
  ) AS immutable_triggers_ok;
