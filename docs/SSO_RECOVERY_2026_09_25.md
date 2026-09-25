# Safeharbor SSO recovery — September 25, 2026

## Confirmed incident

The signed-in 8 West ID launcher was checked across all visible app tiles.
Milepost's recent production audit records show `suite_authorization_feed_invalid`.
Safeharbor displayed its sign-in page and recorded an invalid authorization inventory.
Cloudline displayed its access-confirmation refusal. Coastmark, Logbook, Control Panel,
and Central reached signed-in dashboards in the existing owner browser.

The live ID authorization feed is fresh, HMAC-valid, and includes the owner as
authorized. One unrelated `subscription_lapsed` record has exact date-only `since`
metadata. Each affected consumer rejected the entire feed because it required a
full timestamp for every record. Read-only, in-memory normalization of that one
field made each deployed parser accept the same inventory. No issuer or stored
feed was changed. A pre-existing local Milepost session masked the broken fresh
SSO path in one browser check; its dashboard alone was not counted as SSO proof.

## Scoped repair

The shared authorization-feed policy now accepts an exact calendar date only for `subscription_lapsed`.
Full timestamps remain required for administrative revocations. Invalid calendar
dates, ambiguous formats, trailing bytes, and non-string values still fail closed.
A listed expired subject stays revoked regardless of the date value. Signature,
freshness, exact session generation, tenant, role and product checks are unchanged.
No schema, key, entitlement, identity algorithm, or issuer change is required.

94 authorization-feed checks and the authentication policy test passed locally. Database admission coverage runs in CI against a disposable database.
Regression coverage includes an authorized user alongside an unrelated expired
subscription, the expired subject itself, malformed dates, and administrative
date-only rejection. Existing signature, version, shape and authorization tests
remain in place.

## Release and acceptance

Implementation is ready for reviewed CI and a serialized production release.
Production acceptance and exact release evidence will be recorded after deployment.
Do not infer recovery from a health endpoint or an existing local login.

Preserve protected runtime and the versioned-feed latch. Use the application's
established backup/release process. A source rollback reintroduces this parser
failure while the date-only feed entry remains; do not clear all caches or alter
roles/subscriptions to work around it.
