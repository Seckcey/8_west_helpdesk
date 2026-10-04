<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/portal_auth.php';
$path='/portal/desktop_authorize.php?handoff='.str_repeat('a',32);
if(portal_safe_return_path($path)!==$path)throw new RuntimeException('handoff return rejected');
$cases=['https://evil.test'.$path,'//evil.test'.$path,'%2f%2fevil.test/portal/desktop_authorize.php',
 '/portal/desktop_authorize.php#outside','/portal/desktop_authorize.php%23outside',
 '/portal/desktop_authorize.php?handoff=x%0aLocation:evil',
 '/portal/desktop_authorize.php?handoff=x\\evil','/portal/desktop_authorize.php?handoff=x%255cevil'];
foreach($cases as $bad)if(portal_safe_return_path($bad)!=='/portal/')throw new RuntimeException('unsafe return accepted');
echo 'PASS '.(count($cases)+1)." desktop return path assertions\n";
