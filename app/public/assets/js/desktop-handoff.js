(() => {
  'use strict';
  const root = document.getElementById('desktop-handoff');
  if (!root) return;
  const status = document.getElementById('desktop-handoff-status');
  const deadline = Date.now() + 300000;
  async function poll() {
    if (Date.now() > deadline) { status.textContent = 'Connection expired. Reconnect from the Westy tray menu.'; return; }
    try {
      const response = await fetch('/portal/desktop.php', {method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: {'Content-Type': 'application/json', 'X-Portal-CSRF': root.dataset.csrf},
        body: JSON.stringify({handoff: root.dataset.handoff}), signal: AbortSignal.timeout(8000)});
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error('unavailable');
      if (result.ready) { location.reload(); return; }
      setTimeout(poll, 1500);
    } catch { status.textContent = 'Connection interrupted. Reconnect from the Westy tray menu.'; }
  }
  poll();
})();
