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
    // milepost           — alert lifecycle → api/svc/alerts.php
    // milepost-westy     — Westy failures  → api/svc/westy.php
    // controlpanel-westy — Westy failures  → api/svc/westy.php
    //
    // The Westy identities are DELIBERATELY separate from 'milepost': one
    // rate-limit budget per identity, so a Westy failure storm can never
    // starve real alert intake. Add them only when those emitters ship.
    'svc' => [
        'enabled' => false,
        'secrets' => [
            'milepost'           => 'EXAMPLE_SVC_HMAC_SECRET',
            'milepost-westy'     => 'EXAMPLE_SVC_HMAC_SECRET',
            'controlpanel-westy' => 'EXAMPLE_SVC_HMAC_SECRET',
        ],
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
