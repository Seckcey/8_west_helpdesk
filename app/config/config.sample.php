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

    // Mail — same shape as Milepost's. Outbound: lib/mailer.php + the
    // mail_queue table + cron/mail_dispatch.php (1-min). Leave smtp.host
    // empty to fall back to PHP mail().
    'mail' => [
        'from'      => 'safeharbor@8westit.com',
        'from_name' => 'Safeharbor — 8 West IT',
        'smtp' => [
            'host'   => '',        // e.g. 'mail.8westit.com'
            'port'   => 587,
            'secure' => 'tls',     // 'tls' | 'ssl' | ''
            'user'   => '',
            'pass'   => '',
        ],
        // Inbound email-to-ticket (cron/imap_poll.php, 1-min). Leave host
        // empty to keep intake disabled. Clients email this mailbox; new
        // mail becomes tickets, [#123] replies append to threads, processed
        // mail moves to the folder below (never deleted).
        'imap' => [
            'host'             => '',   // e.g. 'mail.8westit.com' (IMAP 993/SSL)
            'port'             => 993,
            'user'             => '',   // e.g. 'support@8westit.com'
            'pass'             => '',
            'mailbox'          => 'INBOX',
            'processed_folder' => 'Safeharbor/Processed',
        ],
    ],
];
