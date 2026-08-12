# Entitlement and subscription authority — resolved

**Status:** resolved in 8 West ID on 2026-08-10.

8 West ID is the single authority for both tenant product grants and the
subscription state that makes those grants effective. `entitlement_products()`
returns no products for an unverified user, suspended tenant, or tenant whose
subscription is not entitled. Safeharbor trusts only the signed
`8west:products` claim and does not maintain its own billing or subscription
state.

The tenant-wide grant is the only product grant used for suite access. The old
per-user additive override is retired, so a tenant cannot drift into different
app lists for different users.

Operationally:

- change subscription state in 8 West ID, not in a consumer application;
- do not delete individual Safeharbor users to model a lapse;
- retain Safeharbor's local inactive-user and signed revocation checks for
  account security events; and
- treat the asymmetric token migration as a separate cryptographic boundary,
  not as a billing-state mechanism.
