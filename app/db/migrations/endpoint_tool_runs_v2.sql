-- Additive continuation ownership. Apply through the protected release procedure.
-- Opaque handles belong to the immutable scope/tenant/client/conversation/origin
-- and operation of this existing run row. No credentials or command text here.
ALTER TABLE portal_westy_tool_runs ADD COLUMN processes_json JSON NULL;
