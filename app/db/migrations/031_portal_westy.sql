-- Additive, migration FIRST under the Safeharbor-only write freeze.
-- Enable no feature and create no customer/provider record.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS portal_westy_accounts (
 scope_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 tenant_id INT UNSIGNED NOT NULL,
 client_id INT UNSIGNED NOT NULL,
 binding_id INT UNSIGNED NOT NULL,
 conversation_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at DATETIME NOT NULL,
 PRIMARY KEY(scope_key),
 UNIQUE KEY uq_portal_westy_account_scope(tenant_id,client_id,scope_key),
 CONSTRAINT fk_portal_westy_account_binding FOREIGN KEY(tenant_id,client_id,binding_id)
 REFERENCES customer_portal_bindings(tenant_id,client_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS portal_westy_turns (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 tenant_id INT UNSIGNED NOT NULL,
 client_id INT UNSIGNED NOT NULL,
 scope_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 conversation_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 operation_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 state ENUM('pending','complete','unavailable') NOT NULL,
 input_text TEXT NULL,
 reply_json JSON NULL,
 reason_code VARCHAR(40) CHARACTER SET ascii NOT NULL DEFAULT '',
 model_name VARCHAR(64) CHARACTER SET ascii NOT NULL,
 reserve_microusd BIGINT UNSIGNED NOT NULL,
 charged_microusd BIGINT UNSIGNED NOT NULL,
 input_tokens INT UNSIGNED NULL,
 output_tokens INT UNSIGNED NULL,
 created_at DATETIME NOT NULL,
 expires_at DATETIME NOT NULL,
 finished_at DATETIME NULL,
 PRIMARY KEY(id),
 UNIQUE KEY uq_portal_westy_turn_operation(scope_key,operation_key),
 KEY ix_portal_westy_turn_history(scope_key,conversation_key,id),
 KEY ix_portal_westy_turn_business(tenant_id,client_id,created_at),
 KEY ix_portal_westy_turn_expiry(expires_at),
 CONSTRAINT fk_portal_westy_turn_account FOREIGN KEY(tenant_id,client_id,scope_key)
 REFERENCES portal_westy_accounts(tenant_id,client_id,scope_key),
 CONSTRAINT ck_portal_westy_turn_charge CHECK(charged_microusd <= reserve_microusd)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS portal_westy_budgets (
 tenant_id INT UNSIGNED NOT NULL,
 client_id INT UNSIGNED NOT NULL,
 month_key CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 charged_microusd BIGINT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(tenant_id,client_id,month_key),
 CONSTRAINT fk_portal_westy_budget_client FOREIGN KEY(tenant_id,client_id) REFERENCES clients(tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS portal_westy_drafts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 draft_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 tenant_id INT UNSIGNED NOT NULL,
 client_id INT UNSIGNED NOT NULL,
 scope_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 conversation_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 state ENUM('draft','sent','expired') NOT NULL DEFAULT 'draft',
 subject VARCHAR(190) NULL,
 body TEXT NULL,
 priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
 ticket_id INT UNSIGNED NULL,
 created_at DATETIME NOT NULL,
 expires_at DATETIME NOT NULL,
 sent_at DATETIME NULL,
 PRIMARY KEY(draft_key),
 UNIQUE KEY uq_portal_westy_draft_id(id),
 KEY ix_portal_westy_draft_history(scope_key,conversation_key,created_at),
 CONSTRAINT fk_portal_westy_draft_account FOREIGN KEY(tenant_id,client_id,scope_key)
 REFERENCES portal_westy_accounts(tenant_id,client_id,scope_key),
 CONSTRAINT fk_portal_westy_draft_ticket FOREIGN KEY(ticket_id) REFERENCES tickets(id),
 CONSTRAINT ck_portal_westy_draft_receipt CHECK(
  (state='sent' AND ticket_id IS NOT NULL AND sent_at IS NOT NULL AND subject IS NULL AND body IS NULL)
  OR (state='draft' AND ticket_id IS NULL AND sent_at IS NULL AND subject IS NOT NULL AND body IS NOT NULL)
  OR (state='expired' AND ticket_id IS NULL AND sent_at IS NULL AND subject IS NULL AND body IS NULL)
 )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;



-- BEGIN EXACT SHAPE GUARD
-- Refuse incompatible existing tables or changed guards; never overwrite them.
SET @portal_westy_shape_ok =
 (SELECT COUNT(*)=6 AND SUM(column_name='scope_key' AND column_type='char(64)' AND is_nullable='NO' AND collation_name='ascii_bin')=1 AND SUM(column_name='tenant_id' AND column_type='int unsigned' AND is_nullable='NO')=1 AND SUM(column_name='client_id' AND column_type='int unsigned' AND is_nullable='NO')=1 AND SUM(column_name='binding_id' AND column_type='int unsigned' AND is_nullable='NO')=1 AND SUM(column_name='conversation_key' AND column_type='char(32)' AND is_nullable='NO' AND collation_name='ascii_bin')=1 AND SUM(column_name='created_at' AND column_type='datetime' AND is_nullable='NO')=1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='portal_westy_accounts')
 AND (SELECT COUNT(*)=1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='portal_westy_accounts' AND engine='InnoDB' AND table_collation='utf8mb4_bin')
 AND (SELECT COUNT(*)=3 AND SUM(column_name='tenant_id' AND referenced_column_name='tenant_id' AND ordinal_position=1)=1 AND SUM(column_name='client_id' AND referenced_column_name='client_id' AND ordinal_position=2)=1 AND SUM(column_name='binding_id' AND referenced_column_name='id' AND ordinal_position=3)=1 FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND table_name='portal_westy_accounts' AND constraint_name='fk_portal_westy_account_binding' AND referenced_table_name='customer_portal_bindings')
 AND (SELECT COUNT(*)=18 AND SUM(column_name='id' AND column_type='bigint unsigned' AND is_nullable='NO' AND extra='auto_increment')=1 AND SUM(column_name='tenant_id' AND column_type='int unsigned' AND is_nullable='NO')=1 AND SUM(column_name='client_id' AND column_type='int unsigned' AND is_nullable='NO')=1 AND SUM(column_name='scope_key' AND column_type='char(64)' AND is_nullable='NO' AND collation_name='ascii_bin')=1 AND SUM(column_name='conversation_key' AND column_type='char(32)' AND is_nullable='NO' AND collation_name='ascii_bin')=1 AND SUM(column_name='operation_key' AND column_type='char(32)' AND is_nullable='NO' AND collation_name='ascii_bin')=1 AND SUM(column_name='state' AND column_type='enum(''pending'',''complete'',''unavailable'')' AND is_nullable='NO')=1 AND SUM(column_name='input_text' AND column_type='text' AND is_nullable='YES')=1 AND SUM(column_name='reply_json' AND column_type='json' AND is_nullable='YES')=1 AND SUM(column_name='reason_code' AND column_type='varchar(40)' AND is_nullable='NO')=1 AND SUM(column_name='model_name' AND column_type='varchar(64)' AND is_nullable='NO')=1 AND SUM(column_name='reserve_microusd' AND column_type='bigint unsigned' AND is_nullable='NO')=1 AND SUM(column_name='charged_microusd' AND column_type='bigint unsigned' AND is_nullable='NO')=1 AND SUM(column_name='input_tokens' AND column_type='int unsigned' AND is_nullable='YES')=1 AND SUM(column_name='output_tokens' AND column_type='int unsigned' AND is_nullable='YES')=1 AND SUM(column_name='created_at' AND column_type='datetime' AND is_nullable='NO')=1 AND SUM(column_name='expires_at' AND column_type='datetime' AND is_nullable='NO')=1 AND SUM(column_name='finished_at' AND column_type='datetime' AND is_nullable='YES')=1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='portal_westy_turns')
 AND (SELECT COUNT(*)=1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='portal_westy_turns' AND engine='InnoDB' AND table_collation='utf8mb4_bin')
 AND (SELECT COUNT(*)=3 AND SUM(column_name='tenant_id' AND referenced_column_name='tenant_id' AND ordinal_position=1)=1 AND SUM(column_name='client_id' AND referenced_column_name='client_id' AND ordinal_position=2)=1 AND SUM(column_name='scope_key' AND referenced_column_name='scope_key' AND ordinal_position=3)=1 FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND table_name='portal_westy_turns' AND constraint_name='fk_portal_westy_turn_account' AND referenced_table_name='portal_westy_accounts')
 AND (SELECT COUNT(*)=4 AND SUM(column_name='tenant_id' AND column_type='int unsigned' AND is_nullable='NO')=1 AND SUM(column_name='client_id' AND column_type='int unsigned' AND is_nullable='NO')=1 AND SUM(column_name='month_key' AND column_type='char(7)' AND is_nullable='NO' AND collation_name='ascii_bin')=1 AND SUM(column_name='charged_microusd' AND column_type='bigint unsigned' AND is_nullable='NO')=1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='portal_westy_budgets')
 AND (SELECT COUNT(*)=1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='portal_westy_budgets' AND engine='InnoDB' AND table_collation='utf8mb4_bin')
 AND (SELECT COUNT(*)=2 AND SUM(column_name='tenant_id' AND referenced_column_name='tenant_id' AND ordinal_position=1)=1 AND SUM(column_name='client_id' AND referenced_column_name='id' AND ordinal_position=2)=1 FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND table_name='portal_westy_budgets' AND constraint_name='fk_portal_westy_budget_client' AND referenced_table_name='clients')
 AND (SELECT COUNT(*)=15 AND SUM(column_name='id' AND column_type='bigint unsigned' AND is_nullable='NO' AND extra='auto_increment')=1 AND SUM(column_name='draft_key' AND column_type='char(32)' AND is_nullable='NO' AND collation_name='ascii_bin')=1 AND SUM(column_name='tenant_id' AND column_type='int unsigned' AND is_nullable='NO')=1 AND SUM(column_name='client_id' AND column_type='int unsigned' AND is_nullable='NO')=1 AND SUM(column_name='scope_key' AND column_type='char(64)' AND is_nullable='NO' AND collation_name='ascii_bin')=1 AND SUM(column_name='conversation_key' AND column_type='char(32)' AND is_nullable='NO' AND collation_name='ascii_bin')=1 AND SUM(column_name='revision' AND column_type='int unsigned' AND is_nullable='NO')=1 AND SUM(column_name='state' AND column_type='enum(''draft'',''sent'',''expired'')' AND is_nullable='NO')=1 AND SUM(column_name='subject' AND column_type='varchar(190)' AND is_nullable='YES')=1 AND SUM(column_name='body' AND column_type='text' AND is_nullable='YES')=1 AND SUM(column_name='priority' AND column_type='enum(''low'',''normal'',''high'',''urgent'')' AND is_nullable='NO')=1 AND SUM(column_name='ticket_id' AND column_type='int unsigned' AND is_nullable='YES')=1 AND SUM(column_name='created_at' AND column_type='datetime' AND is_nullable='NO')=1 AND SUM(column_name='expires_at' AND column_type='datetime' AND is_nullable='NO')=1 AND SUM(column_name='sent_at' AND column_type='datetime' AND is_nullable='YES')=1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='portal_westy_drafts')
 AND (SELECT COUNT(*)=1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='portal_westy_drafts' AND engine='InnoDB' AND table_collation='utf8mb4_bin')
 AND (SELECT COUNT(*)=3 AND SUM(column_name='tenant_id' AND referenced_column_name='tenant_id' AND ordinal_position=1)=1 AND SUM(column_name='client_id' AND referenced_column_name='client_id' AND ordinal_position=2)=1 AND SUM(column_name='scope_key' AND referenced_column_name='scope_key' AND ordinal_position=3)=1 FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND table_name='portal_westy_drafts' AND constraint_name='fk_portal_westy_draft_account' AND referenced_table_name='portal_westy_accounts')
 AND (SELECT COUNT(*)=1 AND SUM(column_name='ticket_id' AND referenced_column_name='id' AND ordinal_position=1)=1 FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND table_name='portal_westy_drafts' AND constraint_name='fk_portal_westy_draft_ticket' AND referenced_table_name='tickets')
 AND (SELECT COUNT(*)=1 AND SUM(non_unique)=0 AND SUM(sub_part IS NOT NULL)=0 AND SUM(column_name='scope_key' AND seq_in_index=1)=1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='portal_westy_accounts' AND index_name='PRIMARY')
 AND (SELECT COUNT(*)=3 AND SUM(non_unique)=0 AND SUM(sub_part IS NOT NULL)=0 AND SUM(column_name='tenant_id' AND seq_in_index=1)=1 AND SUM(column_name='client_id' AND seq_in_index=2)=1 AND SUM(column_name='scope_key' AND seq_in_index=3)=1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='portal_westy_accounts' AND index_name='uq_portal_westy_account_scope')
 AND (SELECT COUNT(*)=1 AND SUM(non_unique)=0 AND SUM(sub_part IS NOT NULL)=0 AND SUM(column_name='id' AND seq_in_index=1)=1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='portal_westy_turns' AND index_name='PRIMARY')
 AND (SELECT COUNT(*)=2 AND SUM(non_unique)=0 AND SUM(sub_part IS NOT NULL)=0 AND SUM(column_name='scope_key' AND seq_in_index=1)=1 AND SUM(column_name='operation_key' AND seq_in_index=2)=1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='portal_westy_turns' AND index_name='uq_portal_westy_turn_operation')
 AND (SELECT COUNT(*)=3 AND SUM(non_unique)=0 AND SUM(sub_part IS NOT NULL)=0 AND SUM(column_name='tenant_id' AND seq_in_index=1)=1 AND SUM(column_name='client_id' AND seq_in_index=2)=1 AND SUM(column_name='month_key' AND seq_in_index=3)=1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='portal_westy_budgets' AND index_name='PRIMARY')
 AND (SELECT COUNT(*)=1 AND SUM(non_unique)=0 AND SUM(sub_part IS NOT NULL)=0 AND SUM(column_name='draft_key' AND seq_in_index=1)=1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='portal_westy_drafts' AND index_name='PRIMARY')
 AND (SELECT COUNT(*)=1 AND SUM(non_unique)=0 AND SUM(column_name='id' AND seq_in_index=1)=1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='portal_westy_drafts' AND index_name='uq_portal_westy_draft_id')
 AND (SELECT COUNT(*)=1 FROM information_schema.table_constraints t JOIN information_schema.check_constraints c ON c.constraint_schema=t.constraint_schema AND c.constraint_name=t.constraint_name WHERE t.table_schema=DATABASE() AND t.table_name='portal_westy_turns' AND t.constraint_name='ck_portal_westy_turn_charge' AND t.enforced='YES' AND SHA2(c.check_clause,256)='7ac589fc228a24628b57f221599873bd37fd70d9e41a3f681fc38fc99263159a')
 AND (SELECT COUNT(*)=1 FROM information_schema.table_constraints t JOIN information_schema.check_constraints c ON c.constraint_schema=t.constraint_schema AND c.constraint_name=t.constraint_name WHERE t.table_schema=DATABASE() AND t.table_name='portal_westy_drafts' AND t.constraint_name='ck_portal_westy_draft_receipt' AND t.enforced='YES' AND SHA2(c.check_clause,256)='b1dbe6bb8b336938039f99fcd8216ea7e1d05b1d98d23b0c85618b3658fd695f')
 AND (SELECT COUNT(*)=0 OR (COUNT(*)=1 AND SUM(event_object_table='portal_westy_drafts' AND action_timing='BEFORE' AND event_manipulation='INSERT' AND SHA2(REGEXP_REPLACE(action_statement,'[[:space:]]',''),256)='1b7e39583ef49efa454c2d3eed733a41cc8ada5562bd6cb6d7d98840e24e9c0b')=1) FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name='portal_westy_draft_insert')
 AND (SELECT COUNT(*)=0 OR (COUNT(*)=1 AND SUM(event_object_table='portal_westy_accounts' AND action_timing='BEFORE' AND event_manipulation='UPDATE' AND SHA2(REGEXP_REPLACE(action_statement,'[[:space:]]',''),256)='a889367bf2ab6ad786b8a167dbc6c104f8dacf3c05c6d9b1c3f961e6a0c9af00')=1) FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name='portal_westy_account_immutable')
 AND (SELECT COUNT(*)=0 OR (COUNT(*)=1 AND SUM(event_object_table='portal_westy_drafts' AND action_timing='BEFORE' AND event_manipulation='UPDATE' AND SHA2(REGEXP_REPLACE(action_statement,'[[:space:]]',''),256)='c56d7e995136b6c683e39ace0179289104d9202f4376d1e750d3a84650a7d63e')=1) FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name='portal_westy_draft_guard')
 AND (SELECT COUNT(*)=0 OR (COUNT(*)=1 AND SUM(event_object_table='portal_westy_drafts' AND action_timing='BEFORE' AND event_manipulation='DELETE' AND SHA2(REGEXP_REPLACE(action_statement,'[[:space:]]',''),256)='3217e578643b704205543822f34e0744a4c9113f8e3a09fffd82667264976353')=1) FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name='portal_westy_receipt_no_delete');
SET @portal_westy_guard_sql=IF(COALESCE(@portal_westy_shape_ok,0)=1,'DO 0','SELECT * FROM PORTAL_WESTY_SCHEMA_MISMATCH_STOP');
PREPARE portal_westy_guard FROM @portal_westy_guard_sql;
EXECUTE portal_westy_guard;
-- END EXACT SHAPE GUARD
DEALLOCATE PREPARE portal_westy_guard;

DELIMITER $$
CREATE TRIGGER IF NOT EXISTS portal_westy_draft_insert BEFORE INSERT ON portal_westy_drafts FOR EACH ROW
BEGIN
 IF NEW.state<>'draft' OR NEW.revision<>1 OR NEW.ticket_id IS NOT NULL OR NEW.sent_at IS NOT NULL THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Portal drafts must start unsent'; END IF;
END$$
CREATE TRIGGER IF NOT EXISTS portal_westy_account_immutable BEFORE UPDATE ON portal_westy_accounts FOR EACH ROW
BEGIN
 IF NOT(NEW.scope_key <=> OLD.scope_key) OR NOT(NEW.tenant_id <=> OLD.tenant_id)
 OR NOT(NEW.client_id <=> OLD.client_id) OR NOT(NEW.binding_id <=> OLD.binding_id)
 OR NOT(NEW.created_at <=> OLD.created_at) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Portal conversation ownership is immutable'; END IF;
END$$
CREATE TRIGGER IF NOT EXISTS portal_westy_draft_guard BEFORE UPDATE ON portal_westy_drafts FOR EACH ROW
BEGIN
 IF OLD.state='sent' OR OLD.state='expired'
 OR NOT(NEW.id <=> OLD.id) OR NOT(NEW.draft_key <=> OLD.draft_key) OR NOT(NEW.scope_key <=> OLD.scope_key)
 OR NOT(NEW.tenant_id <=> OLD.tenant_id) OR NOT(NEW.client_id <=> OLD.client_id)
 OR NOT(NEW.conversation_key <=> OLD.conversation_key) OR NOT(NEW.created_at <=> OLD.created_at)
 OR NOT(NEW.expires_at <=> OLD.expires_at) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Portal draft ownership and receipt are immutable'; END IF;
 IF NEW.state='sent' AND NOT EXISTS(SELECT 1 FROM tickets WHERE id=NEW.ticket_id
 AND tenant_id=NEW.tenant_id AND client_id=NEW.client_id AND channel='portal') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Portal receipt ticket scope mismatch'; END IF;
END$$
CREATE TRIGGER IF NOT EXISTS portal_westy_receipt_no_delete BEFORE DELETE ON portal_westy_drafts FOR EACH ROW
BEGIN
 IF OLD.state='sent' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Portal ticket receipts cannot be deleted'; END IF;
END$$
DELIMITER ;
