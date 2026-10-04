<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../lib/bootstrap.php';
// Run once per minute. Expired handoff identities are not a session archive.
db()->exec('DELETE FROM portal_desktop_handoffs WHERE expires_at<=UTC_TIMESTAMP()');
db()->exec('DELETE FROM portal_desktop_bindings WHERE expires_at<=UTC_TIMESTAMP()');
