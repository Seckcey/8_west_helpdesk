(() => {
  'use strict';
  const root = document.getElementById('desktop-handoff');
  if (!root) return;
  const status = document.getElementById('desktop-handoff-status');
  const deadline = Date.now() + 300000;
  // The Windows Companion's portal URL carries its pairing proof in the fragment, which no server or
  // log sees. Only a handoff that sends it receives the 30-day Companion sign-in.
  const proof = /^#companion=([a-f0-9]{64})$/.exec(location.hash);
  const request = proof ? {handoff: root.dataset.handoff, companion_proof: proof[1]} : {handoff: root.dataset.handoff};
  let stopped = false;
  window.addEventListener('pagehide', () => { stopped = true; }, {once: true});
  async function poll() {
    if (stopped) return;
    if (Date.now() > deadline) { status.textContent = 'Connection expired. Reconnect from the Westy icon in your system tray or menu bar.'; return; }
    try {
      const response = await fetch('/portal/desktop.php', {method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: {'Content-Type': 'application/json', 'X-Portal-CSRF': root.dataset.csrf},
        body: JSON.stringify(request), signal: AbortSignal.timeout(8000)});
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error('unavailable');
      if (result.ready) { stopped = true; location.reload(); return; }
      status.textContent = 'Waiting for sign-in to finish.';
    } catch { status.textContent = 'Connection interrupted. Checking again; your computer work has not been retried.'; }
    if (!stopped) setTimeout(poll, 1500);
  }
  poll();
})();
