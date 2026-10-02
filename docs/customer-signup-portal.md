# Automatic portal access for new business signups

ID's verified business signup creates a Milepost client under `8west`; the existing signed directory receiver creates its Safeharbor client. A root-run local adapter connects that exact client to its ID customer tenant. This is portal-only signup: no report schedule/contact snapshot, billing or technician access is created, and legacy activation canaries stay unchanged.

`app/cron/customer_signup_portal.php` accepts only a signup ID on stdin and has no HTTP route. It independently reads immutable ID signup/admission records and the current active projection through SELECT-only `ewid_signup_portal_reader`. ID's helper validates receipt history, owner, customer grants and current lifecycle. The adapter pins `8west`, compares UUID/version/event/name with the signed directory, and requires the initial source event to follow email proof.

`safeharbor_signup_worker` prepares and activates one binding atomically using existing portal triggers and a configured active `8west` owner/admin actor. Two append-only events carry the signup ID and immutable evidence digest. Replay requires precisely those two events and the unchanged active binding. Historic, conflicting, staff-disabled or subsequently restored bindings are never automatically adopted/reactivated. Existing portal middleware rejects source suspension on login and every request.

## Configuration and permissions

Protected `customer_signup_portal` config contains `enabled`, a verified existing active `actor_user_id`, `database_config_path`, `identity_database_config_path`, and `identity_root` (normally `/srv/8west/apps/ewid/current`). Both credential files must be root-owned 0600 and outside the application. They return exact ordered `host,port,name,charset,user,pass` keys; targets match the corresponding app config and charset is utf8mb4. Keep values out of logs/source/chat.

Safeharbor writer grants:

- SELECT on tenants, clients, suite_customer_sync_bindings, suite_customer_sync_events, customer_portal_bindings, customer_portal_binding_events, managed_customer_lifecycle_restore_receipts and suite_managed_providers.
- SELECT only users(id,tenant_id,is_active,role).
- INSERT/UPDATE only customer_portal_bindings and database LOCK TABLES for locking reads. Existing trigger definers create immutable events.

No client creation, password reads, user changes, report scheduling, DELETE or DDL. ID's business-signup runbook lists reader grants.

No Safeharbor migration or new scheduled worker is required. ID invokes this adapter and waits for its exact ready result before password setup. Use the established Safeharbor backup/restore and source-release lock/procedure, preserving existing report runner hashes/activation records. Deploy before enabling ID signup.

Rollback disables this adapter/new signup and restores prior app/config while retaining bindings/events. Page rollback does not revoke access; use the existing explicit hold procedure when access must actually be suspended.

`app/tests/customer_signup_portal_mysql_test.php` covers rollback, replay, restricted privileges, wrong actor/provider/evidence, historic clients, staff holds, source suspension and absent report scheduling. ID's cross-repository test invokes the actual CLI and independent reader. Production acceptance requires actual customer sign-in and only that business's portal data.
