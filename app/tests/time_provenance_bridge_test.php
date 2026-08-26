<?php
/** Static release gate for the schema-compatible pre-011 bridge. */
declare(strict_types=1);

$checks = 0;
$failures = 0;
$check = static function (string $name, bool $condition) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$name}\n");
    }
};

$auth = file_get_contents(__DIR__ . '/../lib/auth.php');
$merge = file_get_contents(__DIR__ . '/../public/api/ticket_merge.php');

$check('authentication stamps tenant from the database user row',
    is_string($auth) && substr_count($auth, '$_SESSION[\'tenant_id\'] =') >= 3);
$check('ticket merge never rewrites historical technician time',
    is_string($merge) && preg_match('/\bUPDATE\s+`?time_entries`?\b/i', $merge) !== 1);

fwrite(STDOUT, "time_provenance_bridge_test: {$checks} checks, {$failures} failures\n");
exit($failures === 0 ? 0 : 1);
