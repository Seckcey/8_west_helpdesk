<?php
/**
 * Safeharbor configuration — copy to config.php on each host and fill in.
 * config.php is server-specific and is NEVER committed (see .gitignore).
 */
return [
    // Root-run automatic portal binding for verified new ID business signups only.
    'customer_signup_portal' => [
        'enabled' => false, 'actor_user_id' => 0,
        'database_config_path' => null, 'identity_database_config_path' => null,
        'identity_root' => '/srv/8west/apps/ewid/current',
    ],
    // Private Central history; independent of technician tickets and mail.
    'central_issues' => [
        'enabled' => false, 'tenant_id' => 0, 'provider_tenant_id' => 0,
        'secret' => '', 'digest_key' => '',
        // Separate future AI writer; blank disables assistant turns.
        'assistant_secret' => '',
    ],
    // Platform-owned support intake. Enable only after a delivery/recipient canary.
    'support_addresses' => [
        'enabled' => false,
        'mailbox' => '',
        'transport_verified' => false,
        'state_directory' => '/srv/8west/apps/safeharbor/shared/support-mail',
        'tenant_ids' => [],
        'new_tenants_after' => null,
        'client_ids_by_tenant' => [],
    ],
    // Human-reviewed advice email. No background sends or permission from chat.
    'westy_email' => [
        'enabled' => false, 'tenant_ids' => [], 'customer_ids' => [],
        // Dedicated Westy application. Never replace mail.graph or reports' sender.
        'graph' => [
            'enabled' => false,
            'tenant_id' => '', 'client_id' => '',
            'mailbox' => 'westy@8westit.com', 'sender' => 'westy@8westit.com',
            'certificate_path' => '', 'private_key_path' => '',
        ],
    ],
    // Reviewed conversations and one explicitly approved acknowledgment.
    'westy_mail' => [
        'enabled' => false, 'tenant_ids' => [], 'customer_ids' => [],
        'poll' => [
            'enabled' => false, 'tenant_id' => 0,
            'connector_audited' => false, 'connector_audited_at' => '',
            // Freeze activation time; do not reset it to skip an interrupted inbox scan.
            'activated_at' => '',
            // Set only after a retained real Exchange header fixture is reviewed.
            'auth_results_authority' => '', 'auto_ack_enabled' => false,
            'internal_senders' => [],
        ],
    ],
    // Logbook's read-only solved-ticket export; dedicated key and existing svc identity.
    // Register service 'logbook-export' under tenant_id; customer_ids are exact Milepost UUIDs.
    'logbook_export' => [
        'enabled' => false,
        'secret' => '',
        'tenant_id' => 0,
        'suite_tenant_id' => 0,
        'customer_ids' => [],
    ],
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'safeharbor',
        'user'    => 'safeharbor',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],

    // Redirect all HTTP traffic to HTTPS (certbot already does this at the
    // vhost level on the EC2 host; keep as a second net for other hosts).
    'force_https' => false,

    // 'production' hides PHP errors; 'dev' shows them.
    'app_env' => 'production',

    // Is this host a public sandbox carrying seeded, fictional data?
    //
    // Two things hang off this, and both fail closed when it is absent:
    //
    //  1. The login page advertises the demo password. That is correct for a
    //     sandbox and indefensible for a desk holding real client tickets — and
    //     the difference between those two is a decision somebody makes months
    //     after the page was written, with nothing to remind them.
    //
    //  2. db/seed.php refuses to run. It TRUNCATEs tenants, users, clients,
    //     contacts, tickets, messages and time_entries; its only previous guard
    //     was "CLI only", and it ships to production with every deploy. One
    //     mistyped command on the wrong host destroys the desk.
    //
    // Leave this false on any host that holds, or will ever hold, real work.
    'demo_mode' => false,

    // Suite identity (8 West ID contract: docs/suite-sso-contract.md).
    // Phase 1 live: lib/auth.php suite_sso_attempt() trusts the signed
    // suite cookie from id.8westit.com; sso_secret must match the 8 West
    // ID config on the server (server-only, never committed).
    'suite' => [
        'issuer'      => 'https://id.8westit.com',
        'product'     => 'safeharbor',
        'sso_secret'  => 'CHANGE_ME',
        'cookie_name' => 'ewid_token',
        // Production is RS256-only. Retain sso_secret for the independently
        // HMAC-signed revocation feed until that mechanism is migrated; it is
        // not an accepted suite-cookie algorithm.
        'token_algorithms' => ['RS256'],
        'jwks_url' => 'https://id.8westit.com/.well-known/jwks.json',
        // Pre-provision 0700 for the isolated Safeharbor process UID; cache files are 0600.
        'jwks_cache_path' => '/var/cache/8west/safeharbor/jwks.json',
        // Private, release-independent cache. The directory must be owned by
        // the web user and mode 0700; the cache file is written mode 0600.
        'revocation_cache_path' => '/var/cache/8west/safeharbor/suite-revocations-v3.json',
        // compat during consumer-first rollout; strict after the ID v2 feed
        // is accepted. Unknown values fail closed.
        'session_version_mode' => 'compat',
        // off | report | enforce. Keep report until the suite-wide rollout gate.
        'mfa_policy_mode' => 'report',
        'mfa_max_age' => 2592000,
    ],

    // Safeharbor customer portal (docs/customer-portal-contract.md).
    // This is a separate OIDC/session surface from the technician suite cookie.
    // It ships DARK: code, a registered confidential OIDC client, and even an
    // active CLI-reviewed tenant/client binding remain unavailable until this
    // exact switch is deliberately enabled on the host.
    //
    // Do not invent or commit client values. Register an exact HTTPS callback
    // with 8 West ID, then place the issued values only in server config.
    // Customer-private Westy. Independent of staff AI. Once enabled, every
    // authenticated active customer binding is eligible, including new signups.
    // Migration 031 and reviewed retention/expiry operation are prerequisites.
    'tenant_ai' => [
        'enabled'=>false, 'service_secret'=>'',
        'internal_tenant_id'=>null, 'internal_local_tenant_key'=>null,
    ],
    'portal_westy' => [
        'enabled' => false,
        'ai_enabled' => false,
        'tools_enabled' => true,
        'api_key' => '', // Dedicated server-only OpenAI project credential.
        'retention_days' => 30,
        'hourly_limit' => 30,
        'daily_limit' => 500,
        'monthly_microusd' => 5000000, // $5, reserved before provider I/O.
    ],

    'portal' => [
        'enabled'       => false,
        'issuer'        => 'https://id.8westit.com',
        'client_id'     => '',
        'client_secret' => '',
        'redirect_uri'  => '', // e.g. https://safeharbor.example/portal/callback.php
        'required_product' => 'safeharbor',
        // Maintained oidc_v1 rollout default. Authentication never depends on
        // theme/avatar/preferences, and the portal itself remains dark-themed.
        'mfa_policy_mode' => 'report',
        'mfa_max_age_seconds' => 2592000,
        // App-owned absolute session lifetime; code refuses values over 8h.
        'session_lifetime_seconds' => 28800,
        // Must stay true in production. False exists only for disposable local
        // rendered fixtures whose app_env is dev and which carry no live data.
        'cookie_secure' => true,
        // Private persistent state outside the deploy tree, shared by workers.
        // oidc_v1 reuses a fresh feed for 60s, permits at most 5m stale during
        // an outage, then fails closed with no ticket data.
        'revocation_cache_dir' => '/var/cache/8west/safeharbor/portal-revocations',
        // The fixed reserved set is 8west + internal. This additive list is
        // only for future centrally established reservations.
        'reserved_identity_tenant_slugs' => [],
    ],

    // Service-to-service intake (Phase 8.1, Path B — signed alert events
    // from Milepost land as channel='alert' tickets via api/svc/alerts.php;
    // design: docs/sprint-8.1-svc-alert-intake.md). Ships DARK: enabled
    // stays false until the Milepost emitter side (Sprint 8.1.2) is ready.
    // Secrets are server-only, never committed; one per calling service.
    // NOTE: 'enabled' is TRUE in production as of 2026-07-29 — Milepost's
    // emitter shipped and alert tickets have been arriving since. This sample
    // stays false so a fresh install starts closed.
    //
    // milepost           — alert lifecycle  → api/svc/alerts.php
    // milepost-westy     — Westy failures   → api/svc/westy.php
    // controlpanel-westy — Westy failures   → api/svc/westy.php
    // coastmark-support  — human help asks  → api/svc/support.php
    // waypoint-support   — human help asks  → api/svc/support.php
    //
    // The Westy identities are DELIBERATELY separate from 'milepost': one
    // rate-limit budget per identity, so a Westy failure storm can never
    // starve real alert intake. Add them only when those emitters ship.
    //
    // Coastmark and Waypoint likewise get one identity and one secret EACH,
    // never a shared key. Waypoint is a standalone product outside the suite
    // with its own customers; a common secret would make either app a way to
    // post as the other, and revoking one would revoke both.
    //
    // 'support_enabled' is a SECOND switch on top of 'enabled', because
    // 'enabled' is already true in production: without it, deploying
    // api/svc/support.php would open the door the moment the code landed.
    // Both must be true for support intake to answer.
    'svc' => [
        'enabled'         => false,
        'support_enabled' => false,
        'secrets' => [
            'milepost'           => 'EXAMPLE_SVC_HMAC_SECRET',
            'milepost-westy'     => 'EXAMPLE_SVC_HMAC_SECRET',
            'controlpanel-westy' => 'EXAMPLE_SVC_HMAC_SECRET',
            'coastmark-support'  => 'EXAMPLE_SVC_HMAC_SECRET',
            'waypoint-support'   => 'EXAMPLE_SVC_HMAC_SECRET',
        ],
    ],

    // Milepost customer registry -> Safeharbor client provisioning.
    // This is NOT alert/support intake and does not reuse any svc.secrets key.
    // The exact caller identity is fixed in code as `milepost-customers`; its
    // active svc_identities row must exist under every allowlisted destination
    // tenant. The HMAC context names this Safeharbor receiver and v1 contract.
    // Fresh installs stay closed until the sender outbox, exact tenant list,
    // dedicated destination secret, migration 015, and rollback evidence have
    // all been reviewed. Never commit the real secret.
    'suite_customer_sync' => [
        'enabled' => false,
        'tenant_slugs' => [],
        'hmac_secret' => '',
    ],

    // Partner support intake (docs/coastmark-support-intake-contract.md).
    // Requests raised inside Coastmark or Waypoint open tickets in 8 WEST IT'S
    // OWN tenant, resolved explicitly by slug — same rule as westy_report below.
    //
    // ADDING THE NEXT PRODUCT is three things and no code change:
    //   1. a row in svc_identities for its identity (e.g. 'ledger-support')
    //   2. its secret in svc.secrets above
    //   3. a line in 'sources' below
    // 'source' is the routing prefix for that product's client rows
    // (clients.source_key = '{source}:{their tenant slug}', 64 chars total, so
    // keep 'source' short); 'label' is what techs see in the ticket. Remove a
    // product from this list and its requests stop being accepted — the
    // identity and the secret can stay.
    //
    // Leave 'sources' OUT entirely and the built-in defaults apply, which is
    // how production runs today. An empty list means nobody, not "defaults".
    //
    // The per-tenant caps are ours to enforce: svc_auth's 120/min is per
    // service IDENTITY, and one identity carries every tenant of that product,
    // so without these one noisy customer could spend the whole budget. The
    // budget is per product AND per tenant, so a busy Coastmark customer cannot
    // throttle the same company's Waypoint requests. Set either to 0 to
    // disable that window.
    'support_intake' => [
        'tenant_slug'        => '8west',
        'sources' => [
            'coastmark-support' => ['source' => 'coastmark', 'label' => 'Coastmark'],
            'waypoint-support'  => ['source' => 'waypoint',  'label' => 'Waypoint'],
        ],
        'per_tenant_per_min' => 20,
        'per_tenant_per_day' => 100,
        // The "we've got it, ticket #N" mail. It is also what carries the
        // [#N] token that lets the requester's reply thread back onto the
        // ticket — turning it off costs threading, not just politeness.
        'ack_email'          => true,
    ],

    // Westy defect reporting (docs/westy-failure-reporting-contract.md).
    // Tickets open in 8 WEST IT'S OWN tenant, resolved explicitly by slug —
    // never via tenant_id()'s no-session fallback to 1, which would misroute
    // internal tickets into a customer's queue the day a second tenant exists.
    'westy_report' => [
        'tenant_slug' => '8west',
    ],

    // Approved Safeharbor time -> Coastmark v3 draft-only seam.
    // Both gates default off. `claim_enabled` permits only a human-run, local,
    // one-entry immutable claim; it makes no network request. `enabled` permits
    // one explicit send/status call for a claim. There is no batch, scheduler,
    // queue, or automatic retry. An ambiguous send must be resolved through the
    // signed status endpoint before the operator can explicitly resend it.
    // Client keys come only from active Milepost customer bindings as
    // `milepost-customer:<uuid>`. Local Safeharbor row ids are never billing
    // identities, and the reserved 8 West IT master customer is hard-blocked.
    // Coastmark owns rates, taxes, cents, invoice numbers, approval, posting,
    // sending, Checkout, payments, and ledger behavior. Never put those facts
    // in this config or sender payload.
    'coastmark_time_export' => [
        'claim_enabled' => false,
        'enabled' => false,
        // Dedicated local MySQL identity. It is not the web/cron runtime user.
        // Grant source tables SELECT and claim/receipt tables SELECT,INSERT
        // only; never UPDATE, DELETE, DDL, TRIGGER, or GRANT OPTION.
        'database_user' => '',
        'database_password' => '',
        'endpoint' => 'https://coastmark.8westit.com/api/integrations/safeharbor/time-entries',
        'status_endpoint' => 'https://coastmark.8westit.com/api/integrations/safeharbor/time-events/status',
        'service' => 'safeharbor-time',
        'secret' => '',
        'tenant_slugs' => [],
        'client_keys' => [],
        'timeout_seconds' => 15,
    ],

    // Human-run weekly-report onboarding lookup from 8 West ID. This secret
    // and identity are dedicated to this one read-only service and never reuse
    // suite SSO, revocation, svc intake, mail, or customer-sync credentials.
    // The endpoint is deliberately fixed to the exact production HTTPS host
    // and path; redirects, alternate ports, and other hosts are refused.
    // A tenant binding maps one immutable 8 West ID tenant key to the exact
    // local Safeharbor tenant slug. `client_bindings` retains a refresh-only
    // local-id compatibility lane for existing schedule histories. New MSP
    // customer onboarding uses
    // `customer_bindings`, keyed by Milepost's immutable
    // `milepost-customer:<uuid>` identity. The managed-customer activation
    // worker's isolated schema-2 lookup does not trust or require those local
    // tenant-key mappings: it requests the allowlisted permanent UUID and
    // authenticates the returned projection/tenant/contact evidence. Never
    // infer any mapping from a company name, domain, or email address. The
    // reserved 8 West IT master customer UUID is refused in both paths; its
    // weekly report uses the tenant lane.
    //
    // Default-off means no network request. The explicit operator commands
    // `prepare-from-id`, `prepare-client-from-id`, and
    // `prepare-customer-from-id`, plus the separately default-off managed
    // activation worker, read this block. Report generation/delivery do not.
    'id_report_contacts' => [
        'enabled' => false,
        'endpoint' => 'https://id.8westit.com/api/svc/report-contact.php',
        'hmac_secret' => '',
        'tenant_bindings' => [],
        'client_bindings' => [],
        'customer_bindings' => [],
        'timeout_seconds' => 10,
    ],

    // Default-off managed-customer activation. This worker reads only exact
    // active Milepost bindings in customer_ids, authenticates one ID schema-2
    // projection/contact snapshot by permanent UUID, and atomically reconciles
    // the portal plus a canary v3 weekly report schedule. The business_reports
    // schedule/tenant/client/recipient allowlists are an additional required
    // gate. It never generates or sends a report and has no ticket, time,
    // billing, endpoint-control, mail, or AI write path.
    'managed_customer_activation' => [
        'enabled' => false,
        'canary_only' => true,
        'customer_ids' => [],
        // Exact provider tenant slug => active Safeharbor owner/admin user id.
        'tenant_actors' => [],
        'batch_size' => 5,
        'schedule_timezone' => 'America/Los_Angeles',
        'delivery_weekday' => 3,       // ISO Wednesday
        'delivery_local_time' => '09:00:00',
    ],

    // Immediate fail-closed containment for exact Milepost-managed customers.
    // The read boundary blocks portal access and report generation/delivery as
    // soon as an inactive source event is visible. This default-off worker
    // additionally disables the portal binding and appends a disabled report
    // schedule version with immutable evidence. Restoration has a separate
    // default-off gate. It accepts only a fresh nonce-bound signed schema-2
    // `restored` document whose customer UUID, source version, and Milepost
    // event UUID match Safeharbor's exact current immutable source receipt.
    // Only surfaces owned by the exact containment receipt can be reopened;
    // pre-existing or later human disables/holds remain unchanged.
    'managed_customer_lifecycle' => [
        'enabled' => false,
        'restoration_enabled' => false,
        'customer_ids' => [],
        // Exact provider tenant slug => active Safeharbor owner/admin user id.
        'tenant_actors' => [],
        'batch_size' => 5,
    ],

    // Versioned weekly client service summaries. Definitions, schedules,
    // exact report bytes, delivery leases, and provider outcomes are archived
    // in Safeharbor. This path does not use mail_queue: a Microsoft Graph 202
    // is recorded as submitted to the provider, never as recipient-delivered,
    // and an ambiguous outcome is terminal with no automatic retry.
    //
    // Fresh installs and production deploys stay inert. A canary requires all
    // three exact allowlists plus the corresponding database schedule. Client
    // keys use Safeharbor's stable internal form, not names or domains.
    'business_reports' => [
        'generation_enabled' => false,
        'delivery_enabled' => false,
        'canary_only' => true,
        // Required for delivery. This report-only mailbox never changes the
        // mail.graph.sender used by ticket mail and inbound Graph polling.
        'graph_sender' => '',      // normalized lowercase exact address
        'schedule_keys' => [],     // e.g. client-weekly-canary-v1
        'tenant_slugs' => [],
        'client_keys' => [],       // e.g. safeharbor-client:123
        'recipient_emails' => [],  // normalized lowercase exact addresses
        'lease_seconds' => 120,
    ],

    // File storage OUTSIDE the deploy tree (deploy.sh re-chmods current/ on
    // every release; shared/ survives untouched). The web user needs write.
    'storage' => [
        'attachments_dir' => '/srv/8west/apps/safeharbor/shared/attachments',
    ],

    // Separate signed Milepost controller workflow. Keep disabled until
    // migration 025, exact tenant/customer canary and dedicated identity/key
    // are reviewed. The chat bubble cannot call or authorize this service.
    'westy_workflow' => [
        // Global opt-in for currently registered independent MSPs; existing internal scope stays explicit.
        'managed_providers_enabled' => false,
        'enabled' => false,
        'hmac_secret' => '',
        'tenant_slugs' => [],
        'customer_ids' => [],
    ],
    // Closure outbox sends existing approved-time references for Coastmark
    // invoice REVIEW only. It cannot send an invoice email. Install the
    // dedicated CLI schedule only after an exact configured-customer canary.
    'westy_billing_handoff' => [
        'enabled' => false,
        'endpoint' => 'https://coastmark.8westit.com/api/integrations/safeharbor/billing-handoffs',
        'service' => 'safeharbor-billing',
        'secret' => '',
        'tenant_slugs' => [],
        'customer_ids' => [],
        // Exact tenant/customer coverage references; Coastmark independently verifies its agreement policy.
        'included_service' => ['enabled' => false, 'policies' => []],
    ],

    // Westy — the suite AI helper (advise-only chat bubble + onboarding).
    // Milepost's ai-layer pattern: keys live ONLY here on the server, never
    // in git or the browser. Unconfigured = Westy renders nothing (fails
    // closed). provider: 'anthropic' or 'openai' (the suite currently runs
    // OpenAI — sync provider/model/key from Milepost's config). 'stub' is
    // DEV-ONLY and inert unless allow_stub is set.
    'ai' => [
        // See docs/anthropic-federation.md before enabling this mode.
        'auth_mode' => 'api_key',
        'credential_file' => '/run/8west-westy/safeharbor/credential.json',
        'credential_app' => 'safeharbor',
        'credential_environment' => 'production',
        'provider'        => 'anthropic',
        'api_key'         => '',
        'model'           => 'claude-opus-5',
        'max_tokens'      => 8000,   // hard cap on thinking + reply together
        'timeout'         => 60,
        'rate_limit'      => 20,   // Westy chats per user per window (<=0 disables)
        'rate_window_min' => 60,
        'allow_stub'      => false,
    ],

    // Mail — same shape as Milepost's. Transport order: Graph → SMTP →
    // PHP mail(). Outbound = lib/mailer.php + mail_queue +
    // cron/mail_dispatch.php; inbound = cron/graph_poll.php (O365) or
    // cron/imap_poll.php (fallback), both 1-min.
    //
    // Microsoft Entra (current): one app registration serves the suite —
    // APPLICATION permissions Mail.Send (outbound) + Mail.Read (intake),
    // admin-consented. No mailbox password anywhere; works with Entra
    // security defaults ON. Synced server-side from Milepost's config.
    'mail' => [
        'from'      => '',
        'from_name' => 'Safeharbor — 8 West IT',
        'graph' => [
            'tenant_id'     => '',
            'client_id'     => '',
            'client_secret' => '',
            'sender'        => '',
        ],
        // Provider-agnostic SMTP fallback (used only when graph is unset).
        'smtp' => [
            'host'   => '',
            'port'   => 587,
            'secure' => 'tls',
            'user'   => '',
            'pass'   => '',
        ],
        // IMAP fallback intake (used only when graph is unset / non-M365).
        'imap' => [
            'host'             => '',
            'port'             => 993,
            'user'             => '',
            'pass'             => '',
            'mailbox'          => 'INBOX',
            'processed_folder' => 'Safeharbor/Processed',
        ],
    ],
];
