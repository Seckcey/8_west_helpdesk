# Existing ID accounts in a customer Westy workspace

An explicit Milepost subject permission can admit an existing provider or staff
ID account to a customer workspace without changing its original ID role, tenant,
products or session. Safeharbor still requires the Safeharbor product entitlement
and current ID revocation check. Existing client-role logins retain their original
customer-binding path and do not depend on membership discovery.

The customer-portal bridge signs the verified subject, session version and original
tenant/role. Milepost returns exact current permission reference/generation,
effective customer role, provider and suite customer UUID. Safeharbor maps the UUID
and customer identity slug to its own active local binding; it never treats
Milepost's numeric client ID as its own.

A single membership enters that customer context. Multiple memberships open
/portal/clients.php and require "Use Westy for this client." Selection checks CSRF,
rechecks the exact reference/generation, rotates only the portal session and keeps
the original identity fields. The normal portal shell offers another client choice;
an active companion context does not switch its paired computer implicitly.
Missing/revoked access never falls back to a different customer.

The effective customer role controls existing ticket/device permissions. Existing
tenant capabilities, exact action approval and commercial consent are unchanged.
Customer device diagnostics, repairs, enrollment and native control still require
client_owner or client_admin. Individual employee subjects are supported by the
ledger, but client_staff and client_viewer do not gain those capabilities.
Chat scope contains the subject plus grant reference/generation, so renewed access
cannot inherit earlier conversations, pending tool intent or desktop tasks.
Companion handoffs copy the server-approved identity and recheck access before use.
This ledger supports individual employee subjects; invitations and customer-admin
permission screens are future work.

## Release dependency and rollback

Deploy the reviewed Milepost migration and bridge implementation first, then this
Safeharbor change. The executable schema packet, exact initial Ventures grant plan
and rollback procedure are in Milepost docs/customer-subject-access.md. There is no
Safeharbor schema migration. Do not modify ID roles or revoke other application
sessions to enable this feature.

No customer permission is implicitly installed. The release owner applies only the
exact reviewed subject/customer grant. For rollback, revoke that permission with a
new generation, restore the verified previous applications and retain Milepost's
additive schema/audit. Existing regular customer sessions remain compatible.

## Verification

app/tests/portal_access_mysql_test.php uses actual MySQL binding, session, chat and
companion-handoff code with a synthetic signed-service transport. It covers no
implicit Owner access, product gates, principal preservation, differing numeric
IDs, explicit selection, stale generation/role/UUID rejection, mid-call revocation,
private chat separation, handoff replay and legacy customer sign-in.

Visual QA uses the actual chooser renderer with synthetic companies at 1280x900
and 390x844, including POST selection and the no-access state. This checks rendering
and controls; production OIDC and installed-companion acceptance remain separate
release-owner checks. No passwords, ID tokens or production chat data are fixtures.
