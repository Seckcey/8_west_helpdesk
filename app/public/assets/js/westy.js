/* Westy — the 8 West suite assistant, Safeharbor post. Advise-only chat
 * bubble + first-run onboarding tour. The ONLY network calls this file makes
 * are POST /api/westy_chat.php (advice) and POST /api/westy_onboard.php (the
 * user's own "got it" on the welcome). No secrets, no storage beyond this
 * page's in-memory history (sent back bounded). Vanilla, no dependencies —
 * the 8 West Standard. */
(function () {
  'use strict';
  var root = document.getElementById('westy-root');
  if (!root) return;
  var bubble = document.getElementById('westy-bubble');
  var panel = document.getElementById('westy-panel');
  var closeBtn = document.getElementById('westy-close');
  var log = document.getElementById('westy-log');
  var form = document.getElementById('westy-form');
  var input = document.getElementById('westy-input');
  var sendBtn = document.getElementById('westy-send');
  var history = [];   // [{q,a}] — session-only, capped at 6
  var busy = false;
  var isNew = root.getAttribute('data-onboarded') === '0';
  var firstName = root.getAttribute('data-name') || 'there';

  function addMsg(who, text) {
    var div = document.createElement('div');
    div.className = 'westy-msg westy-' + who;
    div.textContent = text;
    log.appendChild(div);
    log.scrollTop = log.scrollHeight;
    return div;
  }

  function setOpen(open) {
    panel.hidden = !open;
    bubble.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open && !log.children.length) {
      if (isNew) {
        addMsg('westy', 'Welcome aboard, ' + firstName + "! I'm Westy — I know every corner of Safeharbor. " +
          'Want a 60-second tour, or just ask me anything.');
        addChips();
      } else {
        addMsg('westy', "Hi! I'm Westy. Ask me how to use Safeharbor — the queue, tickets, email, the timer, anything.");
      }
    }
    if (open) input.focus();
  }

  /* ── first-run onboarding ─────────────────────────────────────────────── */

  function addChips() {
    var wrap = document.createElement('div');
    wrap.className = 'westy-chips';
    var chips = [
      { label: 'Show me around', ask: 'Show me around — give me the quick tour.' },
      { label: 'How do tickets get here?', ask: 'How do tickets get into Safeharbor?' },
      { label: 'Keyboard shortcuts?', ask: 'What are the keyboard shortcuts?' },
      { label: 'Thanks, got it', ask: null }
    ];
    chips.forEach(function (c) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'westy-chip' + (c.ask ? '' : ' westy-chip-dismiss');
      b.textContent = c.label;
      b.addEventListener('click', function () {
        if (c.ask) {
          send(c.ask);
        } else {
          wrap.remove();
          markOnboarded();
          addMsg('westy', "You're all set. I'm right here in the corner whenever you need me.");
        }
      });
      wrap.appendChild(b);
    });
    log.appendChild(wrap);
    log.scrollTop = log.scrollHeight;
  }

  function markOnboarded() {
    if (!isNew) return;
    isNew = false;
    root.setAttribute('data-onboarded', '1');
    var body = new URLSearchParams();
    body.set('csrf', root.getAttribute('data-csrf') || '');
    fetch('/api/westy_onboard.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
      credentials: 'same-origin'
    }).catch(function () { /* best effort — welcome again next time at worst */ });
  }

  bubble.addEventListener('click', function () { setOpen(panel.hidden); });
  closeBtn.addEventListener('click', function () {
    // Closing the welcome counts as "got it" — never nag twice.
    if (isNew) markOnboarded();
    setOpen(false);
  });

  // First sign-in: open the bubble once, gently, after the page settles.
  if (isNew) {
    setTimeout(function () { if (panel.hidden) setOpen(true); }, 1200);
  }

  /* ── chat send (with ONE quiet retry on a provider hiccup) ────────────── */

  function send(msg) {
    if (busy) return;
    msg = (msg || '').trim();
    if (!msg) return;
    busy = true;
    sendBtn.disabled = true;
    addMsg('me', msg);
    input.value = '';
    // Asking a real question means the tour is underway — count it.
    if (isNew) markOnboarded();
    var thinking = addMsg('westy', 'Westy is thinking…');
    thinking.className += ' westy-thinking';

    var body = new URLSearchParams();
    body.set('csrf', root.getAttribute('data-csrf') || '');
    body.set('message', msg);
    body.set('history', JSON.stringify(history.slice(-6)));

    var attempts = 0;
    function finish() { busy = false; sendBtn.disabled = false; input.focus(); }
    function attempt() {
      attempts++;
      fetch('/api/westy_chat.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
        credentials: 'same-origin'
      }).then(function (r) {
        // A deliberate error answer (e.g. a 502 provider hiccup) is JSON, but
        // an edge/proxy error page is not — non-JSON means "unavailable",
        // NEVER "you are offline".
        return r.json().then(function (j) { return { http: r.status, j: j }; },
          function () { return { http: r.status, j: null }; });
      }).then(function (res) {
        if (res.http === 200 && res.j && res.j.ok && res.j.reply) {
          thinking.remove();
          addMsg('westy', res.j.reply);
          history.push({ q: msg, a: res.j.reply });
          if (history.length > 6) history = history.slice(-6);
          finish();
          return;
        }
        // One quiet retry on a provider hiccup BEFORE showing an error
        // (Milepost field lesson 2026-07-29: a single dropped call read as
        // "not connected" and cost an evening of doubt).
        if ((res.http === 502 || res.j === null) && attempts < 2) {
          thinking.textContent = 'Westy hit a hiccup — trying once more…';
          setTimeout(attempt, 1200);
          return;
        }
        thinking.remove();
        addMsg('westy', (res.j && res.j.error) ? res.j.error : 'Westy is unavailable right now — try again in a minute.');
        finish();
      }).catch(function () {
        if (attempts < 2) { setTimeout(attempt, 1200); return; }
        thinking.remove();
        addMsg('westy', 'Westy could not reach Safeharbor — check your connection and try again.');
        finish();
      });
    }
    attempt();
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    send(input.value);
  });
})();
