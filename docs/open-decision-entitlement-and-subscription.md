# Open architecture decision: 8 West ID should hold both the grant and the subscription

**Status:** open, agreed in principle by the founder 2026-08-02. Not urgent — no
paying 365 customers exist yet. **Urgent before the first one cancels.**

## The gap, plainly

8 West ID hands out product grants. `8west:products` is the org grant UNION
per-user overrides, and after the suite-wide change every customer tenant gets
all four products. That part works.

Nothing takes a grant back.

The revocation design says: on subscription lapse in Mission Control, delete the
`tenant_products` row. But Mission Control has **no subscription state of any
kind** — verified 2026-08-02 against `Seckcey/mission_control` at `main`:

- no Stripe, no invoices, no payment integration anywhere in `config/`,
  `.env.example` or `app/Models`
- no `plan`, `plan_status`, `subscription` or `lapse` concept in any migration
- `businesses` is exactly `id, name, tenant_slug, timestamps`
- the whole audit vocabulary is four actions: `email_processing_terms_accepted`,
  `suite_sso_denied`, `suite_sso_login`, `suite_sso_reconciled`

So the trigger for revocation does not exist. Not "exists but is manual" —
does not exist. A customer who cancels keeps working access to all four
products indefinitely, and no report, alert or audit row would show it.

Combined with the additive-UNION model (once the org grant carries a product,
removing a `user_products` row withholds nothing), the effective policy today is
**all four products, every tenant, permanently.**

## The recommendation

**Put subscription status in 8 West ID, beside the grants.**

Entitlement then becomes *derived* rather than copied: `8west:products` is a
function of subscription state instead of a snapshot that silently drifts from
it. Revocation stops being a runbook step someone has to remember, because
there is nothing to remember — the grant simply stops resolving.

The alternative, billing in a consumer app, means every other app depends on
that app for money truth. The accounting product would need to ask the email
product whether a customer is current. That is backwards, and it makes the
consumer a single point of failure for the whole suite's authorization.

Coastmark **standalone** keeps its own Stripe. It is deliberately outside 8 West
IT 365 and is not part of this.

## What exists in the meantime

`php artisan suite:tenant-report [--csv]` in Mission Control lists every
business with its 8 West ID tenant slug, mapped client folders, user count and
last suite sign-in. Mission Control is the only place that knows which business
answers to which slug, and the runbook's `DELETE` runs by slug.

It is a reconciliation report, not a lapse report, and says so when it runs. It
answers "who exists and what is their slug", never "who stopped paying".

## Sequence when this is picked up

1. Decide where subscription state lives (this note recommends 8 West ID).
2. Add status to tenants there; make `entitlement_products()` read it.
3. Decide the lapse semantics: grace period, partial vs full revocation, and
   what a user sees at the moment access stops.
4. Only then is a lapse report meaningful — and it should be generated where the
   state lives, not in a consumer.

## Related

- `Seckcey/8_west_id` PR #4 — the suite-wide grant, its UNION caveat, and the
  §3.4 revocation discussion.
- `CLAIMS_CONTRACT_V1` §3.4 (lapse revocation), §7 (no server-to-server write
  API is authorized, which is why revocation is manual today).
- Separately open: the HS256 shared secret is symmetric, so every app holding it
  can mint suite-wide identities rather than merely verify them. Four consumers
  now hold it. The asymmetric OIDC upgrade is issuer-side and gets more
  expensive with each integration.
