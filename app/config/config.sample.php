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
];
