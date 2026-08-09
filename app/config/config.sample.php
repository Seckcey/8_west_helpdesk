<?php
/**
 * Safeharbor configuration — copy to config.php on each host and fill in.
 * config.php is server-specific and is NEVER committed (see .gitignore).
 */
return [
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

    // Suite identity (8 West ID contract: docs/suite-sso-contract.md).
    // Phase 1 live: lib/auth.php suite_sso_attempt() trusts the signed
    // suite cookie from id.8westit.com; sso_secret must match the 8 West
    // ID config on the server (server-only, never committed).
    'suite' => [
        'issuer'      => 'https://id.8westit.com',
        'product'     => 'safeharbor',
        'sso_secret'  => 'CHANGE_ME',
        'cookie_name' => 'ewid_token',
        // off | report | enforce. Keep report until the suite-wide rollout gate.
        'mfa_policy_mode' => 'report',
        'mfa_max_age' => 2592000,
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

    // File storage OUTSIDE the deploy tree (deploy.sh re-chmods current/ on
    // every release; shared/ survives untouched). The web user needs write.
    'storage' => [
        'attachments_dir' => '/srv/8west/apps/safeharbor/shared/attachments',
    ],

    // Westy — the suite AI helper (advise-only chat bubble + onboarding).
    // Milepost's ai-layer pattern: keys live ONLY here on the server, never
    // in git or the browser. Unconfigured = Westy renders nothing (fails
    // closed). provider: 'anthropic' or 'openai' (the suite currently runs
    // OpenAI — sync provider/model/key from Milepost's config). 'stub' is
    // DEV-ONLY and inert unless allow_stub is set.
    'ai' => [
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
        'from'      => 'missioncontrol@8westit.com',
        'from_name' => 'Safeharbor — 8 West IT',
        'graph' => [
            'tenant_id'     => '',
            'client_id'     => '',
            'client_secret' => '',
            'sender'        => 'missioncontrol@8westit.com',
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
