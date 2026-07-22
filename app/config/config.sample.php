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
    // The session seam is lib/auth.php; real OIDC lands in a later phase.
    'suite' => [
        'issuer'  => 'https://id.8westit.com',
        'product' => 'safeharbor',
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
