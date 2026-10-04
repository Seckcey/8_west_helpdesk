<?php
declare(strict_types=1);
function portal_desktop_controls():void
{
?>
<details id="portal-desktop-controls" data-csrf="<?=portal_h(portal_csrf_token())?>">
<summary>Computer control</summary>
<p id="portal-desktop-status" role="status">Open Westy from your computer's tray and connect it to this account.</p>
<label>Connected computer <select id="portal-desktop-session"><option value="">Choose a computer</option></select></label>
<p>This continues your latest request in this chat.</p>
<label>Allowed websites <input id="portal-desktop-origins" placeholder="https://example.com" aria-describedby="portal-desktop-help"></label>
<p id="portal-desktop-help">Separate websites with spaces. Your computer will ask which window Westy may use. Passwords, MFA and administrator prompts stay with you.</p>
<button id="portal-desktop-start" type="button">Allow a computer task</button>
<button id="portal-desktop-stop" type="button" hidden>Stop computer control</button>
</details><script src="/assets/js/portal-desktop.js?v=1" defer></script>
<?php
}
