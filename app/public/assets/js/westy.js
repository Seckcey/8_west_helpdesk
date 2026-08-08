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
  var history = [];   // [{q,a}] — capped at 6; mirrored to sessionStorage so it survives navigation
  /* Per-tab, per-user. sessionStorage and NOT localStorage: the transcript dies
   * with the tab rather than outliving a shift on a shared technician
   * workstation. data-uid is not an authorization input — every endpoint still
   * authorizes from the session; it only stops a second sign-in in the same tab
   * inheriting the previous tech's conversation. */
  var STORE = 'sh.westy.' + (root.getAttribute('data-uid') || '0');
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
    if (open) placePanel();
    if (open && !log.children.length) {
      if (isNew) {
        addMsg('westy', 'Welcome aboard, ' + firstName + "! I'm Westy — I know every corner of Safeharbor. " +
          'Want a 60-second tour, or just ask me anything.');
        addChips();
      } else {
        addMsg('westy', "Hi! I'm Westy. Ask me how to use Safeharbor — the queue, tickets, email, the timer, anything.");
      }
    }
    /* preventScroll: restoring an open panel on page load must never move the
     * page. The panel is position:fixed, but a focus() without this hint can
     * still yank the document on some browsers. */
    if (open) { try { input.focus({ preventScroll: true }); } catch (e) { input.focus(); } }
    save();
  }

  /* ── the conversation survives clicking a link ────────────────────────────
   * The bubble re-renders on every page, so without this, following any link
   * wiped the chat mid-troubleshoot and the tech had to re-establish context.
   * Only the bounded {q,a} pairs the page already holds — and already sends
   * back as context — are stored, so nothing new is retained. */

  function save() {
    try {
      sessionStorage.setItem(STORE, JSON.stringify({ open: !panel.hidden, h: history.slice(-6) }));
    } catch (e) { /* private mode or full quota — the chat simply will not persist */ }
  }

  function restore() {
    var raw = null;
    try { raw = sessionStorage.getItem(STORE); } catch (e) { return; }
    if (!raw) return;
    var st = null;
    try { st = JSON.parse(raw); } catch (e) { return; }
    if (!st || !Array.isArray(st.h)) return;
    history = st.h.filter(function (p) {
      return p && typeof p.q === 'string' && typeof p.a === 'string';
    }).slice(-6);
    for (var i = 0; i < history.length; i++) {
      addMsg('me', history[i].q);
      addMsg('westy', history[i].a);
    }
    /* The onboarding chips are deliberately NOT restored. They are Safeharbor's
     * equivalent of Milepost's un-restored draft cards: a "Show me around" chip
     * reappearing after the welcome was dismissed is stale UI inviting a stale
     * action. Restoring the transcript is enough. */
    if (st.open) setOpen(true);
  }

  /* ── drag: put Westy wherever he is out of the way ────────────────────────
   * Grab the bubble (or the panel's header) and move the whole widget. The
   * spot is remembered per browser. Pointer events + setPointerCapture, the
   * same idiom as Milepost's sidebar resize handle, so mouse, pen, and touch
   * all work from one code path. */

  var POS_KEY = 'safeharbor.westy-pos';
  var EDGE = 8;        // never let Westy touch the viewport edge
  var SLOP = 4;        // movement under this is a click, not a drag
  var head = panel.querySelector('.westy-head');
  var pos = null;      // {x,y} of the root's top-left; null = default corner
  var drag = null;
  var swallowClick = false;

  function applyPos() {
    if (!pos) {
      root.style.left = root.style.top = root.style.right = root.style.bottom = '';
      return;
    }
    root.style.left = pos.x + 'px';
    root.style.top = pos.y + 'px';
    root.style.right = 'auto';
    root.style.bottom = 'auto';
  }

  /* Keep the whole bubble on screen — a Westy dragged off the edge (or onto a
   * screen smaller than the one he was parked on) would be as stuck as the
   * bug this replaces. */
  function setPos(x, y) {
    var w = root.offsetWidth || 56, h = root.offsetHeight || 56;
    pos = {
      x: Math.max(EDGE, Math.min(x, window.innerWidth - w - EDGE)),
      y: Math.max(EDGE, Math.min(y, window.innerHeight - h - EDGE))
    };
    applyPos();
    if (!panel.hidden) placePanel();
  }

  /* The panel hangs off the bubble, so once the bubble moves it has to open
   * toward whatever space is actually left — upward by default, downward near
   * the top; right-anchored by default, left-anchored near the left edge. */
  function placePanel() {
    var r = root.getBoundingClientRect();
    var pw = panel.offsetWidth, ph = panel.offsetHeight;
    if (!pw || !ph) return;
    root.setAttribute('data-westy-flip', (r.top - 12 - ph >= EDGE) ? 'up' : 'down');
    root.setAttribute('data-westy-side', (r.right - pw >= EDGE) ? 'right' : 'left');
  }

  function savePos() {
    try {
      if (pos) localStorage.setItem(POS_KEY, JSON.stringify(pos));
      else localStorage.removeItem(POS_KEY);
    } catch (e) { /* private mode / full quota — Westy just forgets his spot */ }
  }

  function resetPos() { pos = null; applyPos(); savePos(); if (!panel.hidden) placePanel(); }

  (function restorePos() {
    var raw = null;
    try { raw = localStorage.getItem(POS_KEY); } catch (e) { return; }
    if (!raw) return;
    var st = null;
    try { st = JSON.parse(raw); } catch (e) { return; }
    if (!st || typeof st.x !== 'number' || typeof st.y !== 'number') return;
    setPos(st.x, st.y);   // re-clamped, so a spot saved on a wider screen still lands here
  })();

  function dragStart(ev) {
    if (ev.button) return;                          // left button (or touch/pen) only
    if (ev.target.closest('#westy-close')) return;  // the X is a button, not a handle
    var r = root.getBoundingClientRect();
    drag = {
      id: ev.pointerId, el: ev.currentTarget, moved: false,
      dx: ev.clientX - r.left, dy: ev.clientY - r.top,
      x0: ev.clientX, y0: ev.clientY
    };
    if (drag.el.setPointerCapture) { try { drag.el.setPointerCapture(ev.pointerId); } catch (e) {} }
  }

  function dragMove(ev) {
    if (!drag || ev.pointerId !== drag.id) return;
    if (!drag.moved) {
      if (Math.abs(ev.clientX - drag.x0) < SLOP && Math.abs(ev.clientY - drag.y0) < SLOP) return;
      drag.moved = true;
      root.classList.add('westy-dragging');
    }
    ev.preventDefault();
    setPos(ev.clientX - drag.dx, ev.clientY - drag.dy);
  }

  function dragEnd(ev) {
    if (!drag || (ev.pointerId != null && ev.pointerId !== drag.id)) return;
    var moved = drag.moved, el = drag.el, id = drag.id;
    drag = null;
    if (el.releasePointerCapture) { try { el.releasePointerCapture(id); } catch (e) {} }
    root.classList.remove('westy-dragging');
    if (!moved) return;
    savePos();
    // The click that follows this pointerup would toggle the chat — a drag is
    // not a click. Cleared on the next tick so a later real click still lands.
    swallowClick = true;
    setTimeout(function () { swallowClick = false; }, 0);
  }

  // Keyboard equivalent: nudge Westy with the arrows, Home puts him back.
  function dragKey(ev) {
    var step = ev.shiftKey ? 48 : 16, r;
    if (ev.key === 'Home') { ev.preventDefault(); resetPos(); return; }
    if (ev.key.indexOf('Arrow') !== 0) return;
    ev.preventDefault();
    r = root.getBoundingClientRect();
    setPos(r.left + (ev.key === 'ArrowLeft' ? -step : ev.key === 'ArrowRight' ? step : 0),
           r.top + (ev.key === 'ArrowUp' ? -step : ev.key === 'ArrowDown' ? step : 0));
    savePos();
  }

  [bubble, head].forEach(function (el) {
    if (!el) return;
    el.addEventListener('pointerdown', dragStart);
    el.addEventListener('pointermove', dragMove);
    el.addEventListener('pointerup', dragEnd);
    el.addEventListener('pointercancel', dragEnd);
    el.addEventListener('keydown', dragKey);
    el.addEventListener('dblclick', function (ev) { ev.preventDefault(); resetPos(); });
  });
  if (head) {
    head.tabIndex = 0;
    head.setAttribute('aria-label', 'Move Westy — drag, or use the arrow keys; Home returns him to the corner');
  }
  bubble.title = 'Ask Westy — Safeharbor helper (drag to move · double-click to reset)';

  root.addEventListener('click', function (ev) {
    if (!swallowClick) return;
    swallowClick = false;
    ev.stopPropagation();
    ev.preventDefault();
  }, true);

  window.addEventListener('resize', function () {
    if (pos) setPos(pos.x, pos.y);
    if (!panel.hidden) placePanel();
  });

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

  /* Rebuild any conversation from this tab BEFORE the first-run auto-open, so
   * a restored chat never gets a second "Hi! I'm Westy" stacked on top of it. */
  restore();

  /* Signing out must not leave a transcript for the next person at this
   * machine. Capture phase, so it still runs when the click lands on a child
   * of the link and before the navigation. */
  document.addEventListener('click', function (ev) {
    var t = ev.target;
    if (t && t.closest && t.closest('a[href*="logout"]')) {
      try { sessionStorage.removeItem(STORE); } catch (e) {}
    }
  }, true);

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
          save();
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
