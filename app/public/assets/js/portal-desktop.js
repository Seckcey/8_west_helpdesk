(() => {
  'use strict';
  const root = document.getElementById('portal-desktop-controls');
  if (!root) return;
  const status = document.getElementById('portal-desktop-status');
  const select = document.getElementById('portal-desktop-session');
  const start = document.getElementById('portal-desktop-start');
  const stop = document.getElementById('portal-desktop-stop');
  const available = document.getElementById('portal-desktop-available');
  let conversation = null, operation = null, task = null, loading = false, starting = false, taskOperation = null, resumedTask = null;
  let seenCompanion = false, nextListAt = 0, revision = 0, stopping = false, selectedSession = null;
  const taskActive = () => ['active', 'consent_pending'].includes(task?.state);
  const updateStart = () => {
    root.setAttribute('aria-busy', String(loading || starting || stopping));
    available.disabled = starting || stopping || taskActive() || available.hidden;
    start.disabled = loading || available.disabled || !select.value || !conversation || !operation;
    start.hidden = taskActive();
  };
  const isConnected = item => item && item.connected === true && /^[a-f0-9]{32}$/.test(item.session_id)
    && Number.isInteger(item.expires_at) && item.expires_at * 1000 > Date.now()
    && (['paired', 'stopped'].includes(item.state) || (taskActive() && item.session_id === task.session_id
      && item.task_id === task.task_id && item.conversation_id === task.conversation_id));
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
    if (taskActive()) { root.hidden = false; root.open = true; }
    updateStart();
    stop.hidden = !['active', 'consent_pending'].includes(state.state);
    status.textContent = taskActive() && state.connected !== true ? 'Connection status is unavailable. Use Stop or the local Stop button to take over.'
      : state.state === 'active' ? 'Westy can use the window you approved. Move the mouse, press a key, or choose Stop to take over.'
      : state.state === 'consent_pending' ? 'Choose a window and allow this task on your computer.'
      : 'Computer control is off.';
    if (state.state === 'active' && state.connected === true && state.task_id !== resumedTask && taskOperation && state.conversation_id === conversation) {
      resumedTask = state.task_id;
      window.dispatchEvent(new CustomEvent('westy-desktop-resume', {detail: {operation: taskOperation, conversation}}));
    }
  }
  window.addEventListener('westy-conversation', event => {
    conversation = event.detail?.conversation || null;
    operation = event.detail?.operation || null;
    updateStart();
  });
  select.addEventListener('change', () => { selectedSession = select.value || null; updateStart(); });
  function setAvailability(items) {
    const previous = selectedSession;
    const connected = items.filter(isConnected);
    select.replaceChildren(new Option('Choose a computer', ''));
    for (const item of connected) select.add(new Option(item.device_name || 'Connected computer', item.session_id));
    if (connected.some(item => item.session_id === previous)) select.value = previous;
    // Never silently switch to another computer when the chosen one disconnects.
    else if (!previous && connected.length === 1) { select.selectedIndex = 1; selectedSession = select.value; }
    const hadFocus = available.contains(document.activeElement);
    available.hidden = connected.length === 0;
    if (connected.length) seenCompanion = true;
    root.hidden = !seenCompanion && !taskActive();
    if (available.hidden && hadFocus) root.querySelector('summary').focus();
    if (!taskActive()) status.textContent = connected.length
      ? 'Choose a connected computer to continue your latest request. Your computer will ask for permission.'
      : 'No connected computer is available. You can continue chatting or contact support.';
    updateStart();
  }
  async function refresh() {
    if (document.hidden || root.parentElement.closest('[hidden]') || loading || starting || stopping) return;
    if (!taskActive() && Date.now() < nextListAt) return;
    loading = true; updateStart(); const currentRevision = revision;
    try {
      if (taskActive()) {
        const state = await request('state', {session_id: task.session_id});
        if (currentRevision !== revision) return;
        show(state);
      }
      if (Date.now() >= nextListAt) {
        nextListAt = Date.now() + 5000;
        const state = await request('list');
        if (currentRevision !== revision) return;
        if (!Array.isArray(state?.items)) throw new Error('desktop_unavailable');
        setAvailability(state.items);
      }
    } catch {
      if (currentRevision !== revision) return;
      setAvailability([]);
      status.textContent = taskActive() ? 'Connection status is unavailable. Use Stop or the local Stop button to take over.'
        : 'Computer control is unavailable. You can continue chatting or contact support.';
      nextListAt = Date.now() + 5000;
    } finally { loading = false; updateStart(); }
  }
  root.addEventListener('toggle', () => { if (root.open) { nextListAt = 0; void refresh(); } });
  start.addEventListener('click', async () => {
    if (start.disabled || starting || taskActive()) return;
    if (!conversation || !operation || !select.value) { status.textContent = 'Choose a connected computer after Westy finishes responding to your request.'; return; }
    starting = true; revision++; updateStart();
    taskOperation = operation;
    try {
      show(await request('start', {session_id: select.value, conversation_id: conversation, operation_key: operation, request_key: id(),
        allowed_origins: document.getElementById('portal-desktop-origins').value.trim().split(/\s+/).filter(Boolean)}));
    } catch { status.textContent = 'The task could not start. Check the connected computer and allowed website addresses.'; }
    finally { starting = false; updateStart(); }
  });
  stop.addEventListener('click', async () => {
    if (!task || stopping) return;
    stopping = true; revision++; stop.disabled = true; updateStart();
    try { show(await request('stop', {session_id: task.session_id, task_id: task.task_id})); }
    catch { status.textContent = 'Stop could not reach your computer. Use the local Stop button or Ctrl+Alt+F12.'; }
    finally { stopping = false; stop.disabled = false; nextListAt = 0; updateStart(); if (!taskActive()) void refresh(); }
  });
  const timer = setInterval(refresh, 1500);
  document.addEventListener('visibilitychange', () => { nextListAt = 0; void refresh(); });
  window.addEventListener('focus', () => { nextListAt = 0; void refresh(); });
  void refresh();
  window.addEventListener('pagehide', () => clearInterval(timer), {once: true});
})();
