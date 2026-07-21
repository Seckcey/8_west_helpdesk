/**
 * 8 West ID — suite SSO contract stub (Phase 0).
 *
 * Safeharbor, Milepost, and Coastmark share one identity: 8 West ID.
 * The full contract lives in docs/suite-sso-contract.md. This module is the
 * seam where the real OIDC flow plugs in later — the prototype "flow" just
 * resolves the demo user after a beat, so the login screen exercises the
 * same code path it will use in production.
 */

export const SSO = {
  issuer: "https://id.8westit.com",
  clientId: "safeharbor-web",
  authorizePath: "/oauth2/authorize",
  scopes: ["openid", "profile", "email", "tenant"],
  /** Claim that binds a user to an MSP tenant across the suite. */
  tenantClaim: "8west:tenant",
} as const;

export interface SuiteIdentity {
  subject: string;
  email: string;
  tenantId: string;
  products: Array<"milepost" | "coastmark" | "safeharbor">;
}

/** Stub: in production this redirects to SSO.issuer and returns via PKCE. */
export async function beginSuiteSignIn(): Promise<SuiteIdentity> {
  await new Promise((r) => setTimeout(r, 450)); // simulate the round trip
  return {
    subject: "stub-frankie",
    email: "frankie@8westit.com",
    tenantId: "tnt_8west",
    products: ["milepost", "coastmark", "safeharbor"],
  };
}
