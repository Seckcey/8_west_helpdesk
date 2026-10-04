<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/portal_desktop_sessions.php';
// Run once per minute. Expired handoff identities are not a session archive.
try {
    $counts=portal_desktop_prune(db());
    if(max($counts)>=100)throw new RuntimeException('cleanup batch full');
} catch(Throwable) {
    fwrite(STDERR,"desktop expiry cleanup incomplete\n");exit(1);
}
