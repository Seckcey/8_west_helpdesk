<?php
declare(strict_types=1);
function portal_desktop_controls():void
{
?>
<details id="portal-desktop-controls" data-csrf="<?=portal_h(portal_csrf_token())?>" hidden>
<summary>Computer control</summary>
<p id="portal-desktop-status" role="status" aria-live="polite">Checking connected computers.</p>
<fieldset id="portal-desktop-available" hidden disabled>
<legend class="sr-only">Connected computer task</legend>
<label>Connected computer <select id="portal-desktop-session"><option value="">Choose a computer</option></select></label>
<p>This continues your latest request in this chat.</p>
<label>Allowed websites <input id="portal-desktop-origins" placeholder="https://example.com" aria-describedby="portal-desktop-help"></label>
<p id="portal-desktop-help">Separate websites with spaces. Your computer will ask which window Westy may use. Passwords, MFA and administrator prompts stay with you.</p>
<button id="portal-desktop-start" type="button" disabled>Allow a computer task</button>
</fieldset>
<button id="portal-desktop-stop" type="button" hidden>Stop computer control</button>
</details><script src="/assets/js/portal-desktop.js?v=<?= portal_asset_version(__DIR__.'/../public/assets/js/portal-desktop.js') ?>" defer></script>
<?php
}
