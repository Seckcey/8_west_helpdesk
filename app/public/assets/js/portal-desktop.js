(() => {
  'use strict';
  const root = document.getElementById('portal-desktop-controls');
  if (!root) return;
  const status = document.getElementById('portal-desktop-status');
  const select = document.getElementById('portal-desktop-session');
  const start = document.getElementById('portal-desktop-start');
  const stop = document.getElementById('portal-desktop-stop');
  let conversation = null, operation = null, task = null, loading = false, starting = false, taskOperation = null, resumedTask = null;
  const taskActive = () => ['active', 'consent_pending'].includes(task?.state);
  const updateStart = () => { start.disabled = starting || taskActive() || !conversation || !operation; };
  const id = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), n => n.toString(16).padStart(2, '0')).join('');
  async function request(action, input = {}) {
    const response = await fetch('/portal/desktop_sessions.php', {method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: {'Content-Type': 'application/json', 'X-Portal-CSRF': root.dataset.csrf},
      body: JSON.stringify({action, ...input}), signal: AbortSignal.timeout(10000)});
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error(result.reason || 'desktop_unavailable');
    return result.result;
  }
  function show(state) {
    task = state;
    updateStart();
    stop.hidden = !['active', 'consent_pending'].includes(state.state);
    status.textContent = state.state === 'active' ? 'Westy can use the window you approved. Move the mouse, press a key, or choose Stop to take over.'
      : state.state === 'consent_pending' ? 'Choose a window and allow this task on your computer.'
      : 'Computer control is off.';
    if (state.state === 'active' && state.task_id !== resumedTask && taskOperation && state.conversation_id === conversation) {
      resumedTask = state.task_id;
      window.dispatchEvent(new CustomEvent('westy-desktop-resume', {detail: {operation: taskOperation, conversation}}));
    }
  }
  window.addEventListener('westy-conversation', event => {
    conversation = event.detail?.conversation || null;
    operation = event.detail?.operation || null;
    updateStart();
  });
  start.disabled = true;
  root.addEventListener('toggle', async () => {
    if (!root.open || loading) return; loading = true;
    try {
      const state = await request('list'); const previous = select.value;
      select.replaceChildren(new Option('Choose a computer', ''));
      for (const item of state.items) if (item.connected) select.add(new Option(item.device_name || 'Connected computer', item.session_id));
      if (Array.from(select.options).some(o => o.value === previous)) select.value = previous;
      if (select.options.length === 2) select.selectedIndex = 1;
      if (select.options.length === 1) status.textContent = 'No connected computer is available. Open Westy from the tray and connect it to this account.';
    } catch { status.textContent = 'Computer control is unavailable. You can continue chatting or contact support.'; }
    finally { loading = false; }
  });
  start.addEventListener('click', async () => {
    if (starting || taskActive()) return;
    if (!conversation || !operation || !select.value) { status.textContent = 'Choose a connected computer after Westy finishes responding to your request.'; return; }
    starting = true; updateStart();
    taskOperation = operation;
    try {
      show(await request('start', {session_id: select.value, conversation_id: conversation, operation_key: operation, request_key: id(),
        allowed_origins: document.getElementById('portal-desktop-origins').value.trim().split(/\s+/).filter(Boolean)}));
    } catch { status.textContent = 'The task could not start. Check the connected computer and allowed website addresses.'; }
    finally { starting = false; updateStart(); }
  });
  stop.addEventListener('click', async () => {
    if (!task) return;
    stop.disabled = true;
    try { show(await request('stop', {session_id: task.session_id, task_id: task.task_id})); }
    catch { status.textContent = 'Stop could not reach your computer. Use the local Stop button or Ctrl+Alt+F12.'; }
    finally { stop.disabled = false; }
  });
  const timer = setInterval(async () => {
    if (!task || stop.hidden || document.hidden || loading) return; loading = true;
    try { show(await request('state', {session_id: task.session_id})); }
    catch { status.textContent = 'Connection status is unavailable. Use local Stop to take over.'; }
    finally { loading = false; }
  }, 1500);
  window.addEventListener('pagehide', () => clearInterval(timer), {once: true});
})();
