<?php
/** Westy Companion for Mac is chat-only: Westy is told before it tries a Windows-only tool on a Mac. DB-free. */
declare(strict_types=1);
require_once __DIR__.'/../lib/portal_guide.php';
require_once __DIR__.'/../lib/portal_westy_device_instructions.php';
$count=0;
function mac_check(bool $ok,string $label):void {global $count;$count++;if(!$ok)throw new RuntimeException('FAIL '.$label);}
$rule=portal_westy_mac_instructions();
foreach([[false,false],[true,false],[true,true]] as [$general,$cua]){
    $instructions=portal_westy_device_instructions($general,$cua);
    mac_check(substr_count($instructions,$rule)===1,'every provider path carries the Mac rule exactly once');
    mac_check(strpos($instructions,$rule)<strpos($instructions,'Health checks and temporary-file previews are read-only'),
        'the Mac rule comes before Westy is told how to use Windows tools');
}
foreach(['inspect_computer','run_powershell','exec_command','desktop_open','macOS platform','do not call those tools',
    'RustDesk','Remote support setup','Screen Recording','Accessibility'] as $needle)
    mac_check(str_contains($rule,$needle),'Mac rule names '.$needle);
mac_check(!str_contains(file_get_contents(__DIR__.'/../public/portal/desktop.php'),'tray menu and try again'),
    'the reconnect hint is not Windows-only');
mac_check(str_contains(file_get_contents(__DIR__.'/../public/assets/js/desktop-handoff.js'),'system tray or menu bar'),
    'the expired-connection hint names both platforms');
echo "$count Westy Companion for Mac instruction checks passed\n";
