<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__.'/../lib/portal_data.php';
require_once __DIR__.'/portal_request_paging_cases.php';
$pdo = portal_paging_sqlite();
$checks = 0;
portal_paging_cases($pdo, static function(string $name, bool $ok) use (&$checks): void {
    if (!$ok) throw new RuntimeException($name);
    echo 'ok '.++$checks.' - '.$name."\n";
});
echo "{$checks} paging checks passed\n";
