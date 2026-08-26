/* ==========================================================================
   Safeharbor — keyboard model, ⌘K palette, timer, optimistic updates.
   No dependencies. The 8 West Standard: instant, keyboard-first, calm.
   ========================================================================== */
(() => {
  "use strict";

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const page = document.body.dataset.active || "";

  /* ------------------------------------------------------------------ */
  /* Theme (dark / light / system)                                       */
  /* ------------------------------------------------------------------ */
  const THEME_KEY = "safeharbor.theme";
  const centralPreferences = document.documentElement.dataset.preferencesSource === "8west-id";
  const currentTheme = () => centralPreferences
    ? (document.documentElement.dataset.theme || "system")
    : (localStorage.getItem(THEME_KEY) || "system");

  function paintThemeSwitch() {
    $$(".theme-btn").forEach((b) =>
      b.classList.toggle("theme-on", b.dataset.themeOpt === currentTheme()));
  }

  function setTheme(t) {
    if (centralPreferences) {
      window.location.href = "https://id.8westit.com/settings.php";
      return;
    }
    localStorage.setItem(THEME_KEY, t);
    document.documentElement.dataset.theme = t;
    paintThemeSwitch();
  }

  $$(".theme-btn").forEach((b) =>
    b.addEventListener("click", () => setTheme(b.dataset.themeOpt)));

  window
    .matchMedia("(prefers-color-scheme: light)")
    .addEventListener("change", () => { if (currentTheme() === "system") paintThemeSwitch(); });

  paintThemeSwitch();

  /* ------------------------------------------------------------------ */
  /* User menu (lower-left identity + preferences)                       */
  /* ------------------------------------------------------------------ */
  (() => {
    const btn = $("#usermenu-btn");
    const menu = $("#usermenu");
    if (!btn || !menu) return;
    const open = () => { menu.hidden = false; btn.setAttribute("aria-expanded", "true"); };
    const close = () => { menu.hidden = true; btn.setAttribute("aria-expanded", "false"); };
    btn.addEventListener("click", (e) => {
      e.stopPropagation();
      menu.hidden ? open() : close();
    });
    document.addEventListener("click", (e) => {
      if (!menu.hidden && !e.target.closest(".usermenu-wrap")) close();
    });
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && !menu.hidden) { e.stopPropagation(); close(); btn.focus(); }
    }, true);
  })();

  /* ------------------------------------------------------------------ */
  /* Toasts                                                              */
  /* ------------------------------------------------------------------ */
  function toast(text) {
    const box = $("#toasts");
    if (!box) return;
    const el = document.createElement("div");
    el.className = "toast";
    el.textContent = text;
    box.appendChild(el);
    setTimeout(() => el.remove(), 2600);
  }

  /* ------------------------------------------------------------------ */
  /* API                                                                 */
  /* ------------------------------------------------------------------ */
  async function api(url, body) {
    const res = await fetch(url, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF": document.body.dataset.csrf || "",
      },
      body: JSON.stringify(body),
    });
    if (!res.ok) throw new Error("API " + res.status);
    return res.json();
  }

  const STATUS_ORDER = ["open", "in_progress", "waiting", "resolved"];
  const PRIORITY_ORDER = ["low", "normal", "high", "urgent"];
  const cycle = (order, value, dir = 1) =>
    order[(order.indexOf(value) + dir + order.length) % order.length];

  async function ticketAction(id, field, value, contextEl) {
    try {
      const r = await api("/api/ticket_action.php", { id, field, value });
      if (!r.ok) return;
      toast(r.toast);
      // In-place repaint where the fragments exist
      const scope = contextEl || document;
      $$("[data-chip]", scope).forEach((el) => { el.innerHTML = r.chip; });
      $$("[data-pri]", scope).forEach((el) => { el.innerHTML = r.pri; });
      $$("[data-assignee]", scope).forEach((el) => { el.innerHTML = r.assignee_html; });
      $$("[data-sla]", scope).forEach((el) => { el.innerHTML = r.sla; });
      // Queue rows: repaint chip/glyph/avatar/sla per row
      const row = document.querySelector(`.trow[data-ticket-id="${id}"]`);
      if (row) {
        const status = row.querySelector(".trow-status");
        if (status) status.innerHTML = r.chip;
        const pri = row.querySelector(".trow-pri");
        if (pri) pri.innerHTML = r.pri.replace(/<span class="pri-label">.*?<\/span>/, "");
        const tech = row.querySelector(".trow-tech");
        if (tech) tech.innerHTML = r.assignee_avatar;
        const sla = row.querySelector(".trow-sla");
        if (sla) sla.innerHTML = r.sla;
        row.dataset.status = r.status;
        row.dataset.priority = r.priority;
      }
    } catch (e) {
      toast("Couldn't save — check your connection.");
    }
  }

  /* ------------------------------------------------------------------ */
  /* Timer (persisted like the design: localStorage, synced widget)      */
  /* ------------------------------------------------------------------ */
  const TIMER_KEY = "safeharbor.timer.v1";
  const readTimer = () => {
    try { return JSON.parse(localStorage.getItem(TIMER_KEY)); } catch { return null; }
  };
  const writeTimer = (t) =>
    t ? localStorage.setItem(TIMER_KEY, JSON.stringify(t)) : localStorage.removeItem(TIMER_KEY);

  function timerElapsed(t) { return Math.max(0, Math.floor((Date.now() - t.startedAt) / 1000)); }
  function fmt(total) {
    const m = Math.floor(total / 60), s = total % 60, h = Math.floor(m / 60);
    return h > 0
      ? `${h}:${String(m % 60).padStart(2, "0")}:${String(s).padStart(2, "0")}`
      : `${String(m).padStart(2, "0")}:${String(s).padStart(2, "0")}`;
  }

  function paintTimer() {
    const t = readTimer();
    const widget = $("#timer-widget");
    if (widget) {
      if (t) {
        widget.classList.add("timer-on");
        widget.classList.remove("timer-idle");
        widget.innerHTML = `<span class="tw-dot pulse"></span><span>${fmt(timerElapsed(t))}</span><span style="opacity:.7">#${t.ticketId}</span>`;
      } else {
        widget.classList.remove("timer-on");
        widget.textContent = "No timer running";
      }
    }
    // Time page card
    const card = $("#timer-card");
    if (card) {
      const on = !!t;
      card.dataset.state = on ? "on" : "idle";
      if (on) {
        $("#timer-title").innerHTML = `Timing <a class="link" href="/ticket.php?id=${t.ticketId}">#${t.ticketId} ${t.subject ? escapeHtml(t.subject) : ""}</a>`;
        $("#timer-sub").textContent = "Started from the ticket view — one click, as promised";
        $("#timer-clock").textContent = fmt(timerElapsed(t));
        $("#timer-stop").hidden = false;
      }
    }
    // Ticket page button
    const btn = $("#timer-btn");
    if (btn) {
      const on = t && String(t.ticketId) === btn.dataset.id;
      btn.classList.toggle("timer-on", !!on);
      btn.textContent = on ? "■ Stop & log time  (E)" : "▶ Start timer  (E)";
    }
  }

  function escapeHtml(s) {
    const d = document.createElement("div");
    d.textContent = s;
    return d.innerHTML;
  }

  function startTimer(ticketId, subject) {
    writeTimer({ ticketId: Number(ticketId), subject: subject || "", startedAt: Date.now() });
    toast(`Timer started · #${ticketId}`);
    paintTimer();
  }

  async function stopTimer() {
    const t = readTimer();
    if (!t) return;
    const minutes = Math.max(1, Math.round(timerElapsed(t) / 60));
    writeTimer(null);
    paintTimer();
    try {
      const r = await api("/api/timer.php", { ticket_id: t.ticketId, minutes });
      toast(r.toast || `Time logged · ${minutes}m`);
      if (page === "time") location.reload(); // refresh entry list
    } catch {
      toast("Timer stopped — entry will sync when you're back online.");
    }
  }

  setInterval(() => {
    const t = readTimer();
    if (!t) return;
    const clock = $("#timer-clock");
    if (clock) clock.textContent = fmt(timerElapsed(t));
    const widget = $("#timer-widget");
    if (widget && widget.classList.contains("timer-on")) {
      const span = widget.querySelector("span:nth-child(2)");
      if (span) span.textContent = fmt(timerElapsed(t));
    }
  }, 1000);

  /* ------------------------------------------------------------------ */
  /* Row selection (queue + clients)                                     */
  /* ------------------------------------------------------------------ */
  const rows = page === "queue" ? $$(".trow:not(.trow-head)") : page === "clients" ? $$(".crow") : [];
  let sel = 0;
  function paintSel() {
    rows.forEach((r, i) => r.classList.toggle(page === "queue" ? "trow-sel" : "crow-sel", i === sel));
    if (rows[sel]) rows[sel].scrollIntoView({ block: "nearest" });
  }
  if (rows.length) paintSel();

  const currentRow = () => rows[sel];

  /* ------------------------------------------------------------------ */
  /* Palette                                                             */
  /* ------------------------------------------------------------------ */
  let paletteOpen = false;
  let palIndex = 0;
  let palItems = [];

  function paletteData() {
    const el = $("#palette-data");
    if (!el) return { tickets: [], clients: [] };
    try { return JSON.parse(el.textContent); } catch { return { tickets: [], clients: [] }; }
  }

  function fuzzy(query, text) {
    const q = query.toLowerCase(), t = text.toLowerCase();
    if (!q) return 0;
    let ti = 0, score = 0;
    for (const ch of q) {
      const found = t.indexOf(ch, ti);
      if (found === -1) return -1;
      score += found - ti;
      ti = found + 1;
    }
    return score + (t.startsWith(q) ? -10 : 0);
  }

  let serverResults = [];
  let serverTimer = null;
  function scheduleServerSearch(q) {
    if (serverTimer) clearTimeout(serverTimer);
    q = q.trim();
    if (q.length < 3) { serverResults = []; return; }
    serverTimer = setTimeout(async () => {
      try {
        const res = await fetch("/api/search.php?q=" + encodeURIComponent(q), { credentials: "same-origin" });
        const j = await res.json();
        if (j.ok) {
          serverResults = j.results;
          const input = document.querySelector(".palette-input");
          if (paletteOpen && input && input.value.trim() === q) renderPaletteList(input.value);
        }
      } catch { /* search degrades to local */ }
    }, 250);
  }

  function buildPaletteItems(query) {
    const data = paletteData();
    const actions = [
      { group: "Actions", title: "New ticket", hint: "file one", run: () => nav("/ticket_new.php") },
      { group: "Actions", title: "Go to Queue", hint: "g q", run: () => nav("/") },
      { group: "Actions", title: "Go to Time", hint: "g t", run: () => nav("/time.php") },
      { group: "Actions", title: "Go to Clients", hint: "g c", run: () => nav("/clients.php") },
      { group: "Actions", title: "Go to Team", run: () => nav("/users.php") },
      { group: "Actions", title: "Saved replies", hint: "manage / snippets", run: () => nav("/snippets.php") },
      { group: "Actions", title: "Sign out", run: () => nav("/logout.php") },
    ];
    const tickets = data.tickets.map((t) => ({
      group: "Tickets",
      title: `#${t.id} ${t.subject}`,
      hint: t.client,
      run: () => nav(`/ticket.php?id=${t.id}`),
    }));
    const clients = data.clients.map((c) => ({
      group: "Clients",
      title: c.name,
      hint: c.domain,
      run: () => nav(`/client.php?id=${c.id}`),
    }));
    const all = [...actions, ...tickets, ...clients];
    if (!query.trim()) return all;
    const filtered = all
      .map((item) => ({ item, score: fuzzy(query, `${item.title} ${item.hint || ""}`) }))
      .filter((x) => x.score >= 0)
      .sort((a, b) => a.score - b.score)
      .map((x) => x.item);
    // Server results (bodies + resolved) ride below — already matched server-side
    const localIds = new Set(data.tickets.map((t) => t.id));
    const deep = serverResults
      .filter((r) => !localIds.has(r.id))
      .map((r) => ({
        group: "All tickets (deep search)",
        title: `#${r.id} ${r.subject}`,
        hint: `${r.client} · ${r.status.replace("_", " ")}`,
        run: () => nav(`/ticket.php?id=${r.id}`),
      }));
    return [...filtered, ...deep];
  }

  function nav(url) {
    closePalette();
    window.location.href = url;
  }

  function renderPaletteList(query) {
    palItems = buildPaletteItems(query);
    palIndex = Math.min(palIndex, Math.max(0, palItems.length - 1));
    const list = $("#palette-list");
    if (!list) return;
    list.innerHTML = "";
    if (!palItems.length) {
      list.innerHTML = `<div class="palette-empty">Nothing matches “${escapeHtml(query)}”. The harbor is calm.</div>`;
      return;
    }
    let lastGroup = "";
    palItems.forEach((item, i) => {
      if (item.group !== lastGroup) {
        lastGroup = item.group;
        const g = document.createElement("div");
        g.className = "palette-group";
        g.textContent = item.group;
        list.appendChild(g);
      }
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "palette-item" + (i === palIndex ? " sel" : "");
      const dotColor = item.group === "Actions" ? "var(--cyan)" : item.group === "Clients" ? "var(--gold)" : "var(--accent)";
      btn.innerHTML = `<span class="palette-dot" style="background:${dotColor}"></span>
        <span class="palette-item-main">${escapeHtml(item.title)}</span>
        ${item.hint ? `<span class="palette-item-hint">${escapeHtml(item.hint)}</span>` : ""}`;
      btn.addEventListener("click", () => item.run());
      btn.addEventListener("mousemove", () => { palIndex = i; paintPaletteSel(); });
      list.appendChild(btn);
    });
  }

  function paintPaletteSel() {
    $$(".palette-item").forEach((el, i) => el.classList.toggle("sel", i === palIndex));
    const selEl = $$(".palette-item")[palIndex];
    if (selEl) selEl.scrollIntoView({ block: "nearest" });
  }

  function openPalette() {
    if (paletteOpen) return;
    paletteOpen = true;
    palIndex = 0;
    const root = $("#palette-root");
    root.innerHTML = `
      <div class="palette-overlay">
        <div class="palette" role="dialog" aria-label="Command palette">
          <div class="palette-input-row">
            <svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="7" cy="7" r="4.5"/><path d="m10.5 10.5 3 3" stroke-linecap="round"/></svg>
            <input class="palette-input" placeholder="Search tickets, clients, actions…" autocomplete="off">
            <kbd class="kbd">esc</kbd>
          </div>
          <div class="palette-list" id="palette-list"></div>
          <div class="palette-foot">
            <span><kbd class="kbd">↑↓</kbd> navigate</span><span><kbd class="kbd">↵</kbd> open</span>
            <span class="right">Queue keys: <kbd class="kbd">j</kbd><kbd class="kbd">k</kbd> · <kbd class="kbd">s</kbd> · <kbd class="kbd">p</kbd> · <kbd class="kbd">a</kbd> · <kbd class="kbd">e</kbd></span>
          </div>
        </div>
      </div>`;
    const overlay = $(".palette-overlay", root);
    const input = $(".palette-input", root);
    overlay.addEventListener("click", (e) => { if (e.target === overlay) closePalette(); });
    input.addEventListener("input", () => { palIndex = 0; scheduleServerSearch(input.value); renderPaletteList(input.value); });
    input.focus();
    renderPaletteList("");
  }

  function closePalette() {
    paletteOpen = false;
    const root = $("#palette-root");
    if (root) root.innerHTML = "";
  }

  const trigger = $("#palette-trigger");
  if (trigger) trigger.addEventListener("click", openPalette);

  /* ------------------------------------------------------------------ */
  /* Keyboard model                                                      */
  /* ------------------------------------------------------------------ */
  const isTyping = (el) =>
    el && (el.tagName === "INPUT" || el.tagName === "TEXTAREA" || el.tagName === "SELECT" || el.isContentEditable);

  let pendingG = false;

  document.addEventListener("keydown", (e) => {
    const key = e.key.toLowerCase();
    const mod = e.ctrlKey || e.metaKey;

    // palette-local keys
    if (paletteOpen) {
      if (key === "escape") { e.preventDefault(); closePalette(); }
      else if (key === "arrowdown") { e.preventDefault(); palIndex = Math.min(palIndex + 1, palItems.length - 1); paintPaletteSel(); }
      else if (key === "arrowup") { e.preventDefault(); palIndex = Math.max(palIndex - 1, 0); paintPaletteSel(); }
      else if (key === "enter") { e.preventDefault(); if (palItems[palIndex]) palItems[palIndex].run(); }
      return;
    }

    if (helpOpen && key === "escape") { e.preventDefault(); toggleHelp(); return; }

    if (mod && key === "k") { e.preventDefault(); paletteOpen ? closePalette() : openPalette(); return; }

    // Ctrl/Cmd+Enter sends the reply (works on any page where the box exists)
    if (mod && key === "enter") {
      const form = $("#reply-form");
      if (form && isTyping(e.target)) { e.preventDefault(); form.requestSubmit(); }
      return;
    }

    if (mod || e.altKey || isTyping(e.target)) return;

    // "g" sequences: g q / g t / g c
    if (pendingG) {
      pendingG = false;
      if (key === "q") { e.preventDefault(); nav("/"); }
      else if (key === "t") { e.preventDefault(); nav("/time.php"); }
      else if (key === "c") { e.preventDefault(); nav("/clients.php"); }
      return;
    }
    if (key === "g") { pendingG = true; setTimeout(() => (pendingG = false), 900); return; }

    const row = currentRow();
    const rowTicketId = row && row.dataset.ticketId;

    switch (key) {
      case "j":
      case "arrowdown":
        if (rows.length) { e.preventDefault(); sel = Math.min(sel + 1, rows.length - 1); paintSel(); }
        break;
      case "k":
      case "arrowup":
        if (rows.length) { e.preventDefault(); sel = Math.max(sel - 1, 0); paintSel(); }
        break;
      case "enter":
      case "o":
        if (row) { e.preventDefault(); row.click(); }
        break;
      case "escape":
        if (page === "queue" && window.location.pathname !== "/") { e.preventDefault(); nav("/"); }
        break;
      case "s":
      case "p":
      case "a":
      case "e": {
        // ticket page context
        const railBtn = $(`.rail-btn[data-action]`);
        const onTicketPage = !!railBtn && page === "queue" && !row;
        const targetId = onTicketPage ? railBtn.dataset.id : rowTicketId;
        const ticketPageId = $("#thread") ? $("#thread").dataset.ticketId : null;
        const id = ticketPageId || targetId;
        if (!id) break;
        e.preventDefault();
        if (key === "e") {
          const t = readTimer();
          if (t && String(t.ticketId) === String(id)) stopTimer();
          else startTimer(id);
          break;
        }
        if (key === "s" || key === "p") {
          const field = key === "s" ? "status" : "priority";
          const order = field === "status" ? STATUS_ORDER : PRIORITY_ORDER;
          let current;
          if (ticketPageId) {
            const scope = field === "status" ? $("[data-chip]") : $("[data-pri]");
            current = scope ? scope.dataset.value : null;
          }
          // derive current from DOM data attributes stored per page
          current = currentFieldValue(field, id);
          if (!current) break;
          ticketAction(id, field, cycle(order, current));
        } else {
          ticketAction(id, "assignee", "me");
        }
        break;
      }
      case "r": {
        if (composer) { e.preventDefault(); composer.focus("reply"); }
        break;
      }
      case "n": {
        if (composer) { e.preventDefault(); composer.focus("note"); }
        break;
      }
      case "1": case "2": case "3": case "4": case "5": {
        if (page !== "queue") break;
        const pill = $$(".filter-pill")[Number(key) - 1];
        if (pill) { e.preventDefault(); nav(pill.getAttribute("href")); }
        break;
      }
      case "?": {
        e.preventDefault();
        toggleHelp();
        break;
      }
    }
  });

  /* current field value from data we stamped at render (or row dataset) */
  function currentFieldValue(field, id) {
    const thread = $("#thread");
    if (thread && String(thread.dataset.ticketId) === String(id)) {
      return thread.dataset[field];
    }
    const row = document.querySelector(`.trow[data-ticket-id="${id}"]`);
    return row ? row.dataset[field] : null;
  }

  /* stamp current values onto the ticket page root for the keyboard model */
  (() => {
    const thread = $("#thread");
    if (!thread) return;
    const chip = $("[data-chip] .chip-text-blue, [data-chip] [class^='chip-text-']");
    // status + priority read from rail buttons' data instead: attach at init from rendered meta
    const statusMap = { Open: "open", "In Progress": "in_progress", Waiting: "waiting", Resolved: "resolved" };
    const priMap = { Low: "low", Normal: "normal", High: "high", Urgent: "urgent" };
    const chipText = $("[data-chip] span[class*='chip-text-']");
    const priText = $("[data-pri] .pri-label");
    if (chipText) thread.dataset.status = statusMap[chipText.textContent.trim()] || "open";
    if (priText) thread.dataset.priority = priMap[priText.textContent.trim()] || "normal";
  })();

  /* rail buttons (ticket page) */
  $$(".rail-btn[data-action]").forEach((btn) => {
    btn.addEventListener("click", () => {
      const action = btn.dataset.action;
      const id = btn.dataset.id;
      if (action === "assignee") ticketAction(id, "assignee", "me");
      else {
        const field = action;
        const order = field === "status" ? STATUS_ORDER : PRIORITY_ORDER;
        const current = currentFieldValue(field, id);
        if (current) ticketAction(id, field, cycle(order, current));
      }
    });
  });

  /* timer buttons */
  const timerBtn = $("#timer-btn");
  if (timerBtn) timerBtn.addEventListener("click", () => {
    const t = readTimer();
    if (t && String(t.ticketId) === timerBtn.dataset.id) stopTimer();
    else startTimer(timerBtn.dataset.id);
  });

  const stopBtn = $("#timer-stop");
  if (stopBtn) stopBtn.addEventListener("click", stopTimer);

  /* suggestion accept/dismiss (time page) */
  $$(".sugg-row").forEach((rowEl) => {
    const accept = $(".sugg-accept", rowEl);
    const dismiss = $(".sugg-dismiss", rowEl);
    if (accept) accept.addEventListener("click", async () => {
      try {
        const r = await api("/api/timer.php", {
          ticket_id: Number(rowEl.dataset.ticketId),
          minutes: Number(rowEl.dataset.minutes),
          note: "Suggested entry · #" + rowEl.dataset.ticketId,
          billable: 1,
        });
        toast(r.toast || "Logged");
        rowEl.remove();
        setTimeout(() => location.reload(), 700);
      } catch { toast("Couldn't log that — try again."); }
    });
    if (dismiss) dismiss.addEventListener("click", () => rowEl.remove());
  });

  /* ------------------------------------------------------------------ */
  /* Composer (ticket page): reply/note tabs, / saved replies, time chip  */
  /* ------------------------------------------------------------------ */
  const composer = (() => {
    const form = $("#reply-form");
    const box = $("#reply-box");
    const modeInput = $("#composer-mode");
    if (!form || !box || !modeInput) return null;

    const hint = $("#composer-hint");
    const send = $("#composer-send");
    const tabs = $$(".composer-tab");
    const HINTS = {
      reply: "Replying emails the client and moves Open → In Progress",
      note: "Internal note — the client never sees this",
    };
    const PLACEHOLDERS = {
      reply: "Reply to client…  (⌘Enter to send · / for saved replies)",
      note: "Internal note…  (⌘Enter to save · / for saved replies)",
    };

    function setMode(mode) {
      modeInput.value = mode;
      tabs.forEach((t) => t.classList.toggle("tab-on", t.dataset.mode === mode));
      form.classList.toggle("composer-note", mode === "note");
      box.placeholder = PLACEHOLDERS[mode];
      if (hint) hint.textContent = HINTS[mode];
      if (send) send.textContent = mode === "note" ? "Save note" : "Send";
    }
    tabs.forEach((t) => t.addEventListener("click", () => { setMode(t.dataset.mode); box.focus(); }));

    /* saved replies: type "/" at the start of the box */
    const pop = $("#canned-pop");
    const dataEl = $("#canned-data");
    let canned = { snippets: [], merge: {} };
    try { canned = JSON.parse(dataEl ? dataEl.textContent : "{}"); } catch { /* island optional */ }
    let popIndex = 0;
    let popItems = [];

    const mergeResolve = (text) =>
      text.replace(/\{([a-z_.]+)\}/g, (m, key) => (canned.merge && canned.merge[key] !== undefined ? canned.merge[key] : m));

    function popRender(query) {
      if (!pop) return;
      const q = query.toLowerCase();
      popItems = canned.snippets.filter((s) => !q || s.title.toLowerCase().includes(q) || s.body.toLowerCase().includes(q));
      popIndex = Math.min(popIndex, Math.max(0, popItems.length - 1));
      pop.innerHTML = "";
      if (!canned.snippets.length) {
        pop.innerHTML = '<div class="canned-empty">No saved replies yet — <a class="link" href="/snippets.php">create your first</a></div>';
        pop.hidden = false;
        return;
      }
      if (!popItems.length) {
        pop.innerHTML = '<div class="canned-empty">Nothing matches. <a class="link" href="/snippets.php">Manage saved replies</a></div>';
        pop.hidden = false;
        return;
      }
      popItems.forEach((s, i) => {
        const b = document.createElement("button");
        b.type = "button";
        b.className = "canned-item" + (i === popIndex ? " sel" : "");
        b.innerHTML = `<span class="canned-title">${escapeHtml(s.title)}</span><span class="canned-preview">${escapeHtml(s.body.slice(0, 60))}</span>`;
        b.addEventListener("click", () => popInsert(s));
        b.addEventListener("mousemove", () => { popIndex = i; popPaint(); });
        pop.appendChild(b);
      });
      pop.hidden = false;
    }
    function popPaint() {
      $$(".canned-item", pop).forEach((el, i) => el.classList.toggle("sel", i === popIndex));
    }
    function popClose() { if (pop) { pop.hidden = true; pop.innerHTML = ""; } }
    function popOpen() { return pop && !pop.hidden; }
    function popInsert(s) {
      box.value = mergeResolve(s.body);
      popClose();
      box.focus();
      box.setSelectionRange(box.value.length, box.value.length);
    }

    box.addEventListener("input", () => {
      if (box.value.startsWith("/")) { popRender(box.value.slice(1)); }
      else popClose();
    });
    box.addEventListener("keydown", (e) => {
      if (!popOpen()) return;
      if (e.key === "ArrowDown") { e.preventDefault(); popIndex = Math.min(popIndex + 1, popItems.length - 1); popPaint(); }
      else if (e.key === "ArrowUp") { e.preventDefault(); popIndex = Math.max(popIndex - 1, 0); popPaint(); }
      else if (e.key === "Enter" && !e.ctrlKey && !e.metaKey) { e.preventDefault(); if (popItems[popIndex]) popInsert(popItems[popIndex]); }
      else if (e.key === "Escape") { e.stopPropagation(); popClose(); }
    });
    box.addEventListener("blur", () => setTimeout(popClose, 200));

    /* time-at-reply: the running timer rides the Send click */
    const chip = $("#timer-log-chip");
    const chipText = $("#timer-log-text");
    const minutesInput = $("#f-timer-minutes");
    const ticketId = $("#thread") ? $("#thread").dataset.ticketId : null;

    function paintChip() {
      if (!chip || !ticketId) return;
      const t = readTimer();
      const on = t && String(t.ticketId) === String(ticketId);
      chip.hidden = !on;
      if (on && chipText) chipText.textContent = "log " + Math.max(1, Math.round(timerElapsed(t) / 60)) + "m";
    }
    setInterval(paintChip, 1000);
    paintChip();

    form.addEventListener("submit", () => {
      const t = readTimer();
      if (t && String(t.ticketId) === String(ticketId) && minutesInput) {
        minutesInput.value = String(Math.max(1, Math.round(timerElapsed(t) / 60)));
        writeTimer(null); // sending logs it — timer's job is done
      }
    });

    /* attachment picker count */
    const filesInput = $("#f-files");
    const attCount = $("#att-count");
    if (filesInput && attCount) {
      filesInput.addEventListener("change", () => {
        const n = filesInput.files.length;
        attCount.textContent = n ? (n + (n === 1 ? " file" : " files")) : "";
      });
    }

    /* stale-send recovery: restore the note tab + focus the preserved draft */
    if (box.dataset.staleMode === "note") setMode("note");
    if (box.value.trim()) { box.focus(); box.setSelectionRange(box.value.length, box.value.length); }

    /* collision detection: heartbeat every 20s, paint who else is here */
    let lastTyped = 0;
    box.addEventListener("input", () => { lastTyped = Date.now(); });
    box.addEventListener("focus", () => { lastTyped = Date.now(); });
    const presenceEl = $("#presence");
    async function heartbeat() {
      if (!ticketId || !presenceEl) return;
      try {
        const mode = Date.now() - lastTyped < 12000 ? "typing" : "viewing";
        const r = await api("/api/presence.php", { ticket_id: Number(ticketId), mode });
        if (!r.ok) return;
        presenceEl.innerHTML = r.others.map((o) =>
          `<span class="presence-chip${o.mode === "typing" ? " presence-typing" : ""}" title="${escapeHtml(o.name)} is ${o.mode}">` +
          `<span class="avatar" style="width:18px;height:18px;background:${escapeHtml(o.color)};font-size:9px">${escapeHtml(o.initials)}</span>` +
          `${escapeHtml(o.name)} ${o.mode === "typing" ? "typing…" : "viewing"}</span>`
        ).join("");
      } catch { /* presence is decoration — never noisy */ }
    }
    heartbeat();
    setInterval(heartbeat, 20000);

    return { setMode, focus: (mode) => { setMode(mode); box.focus(); } };
  })();

  /* ------------------------------------------------------------------ */
  /* Merge tickets                                                       */
  /* ------------------------------------------------------------------ */
  async function mergeTickets(src, dst) {
    try {
      const r = await api("/api/ticket_merge.php", { source_id: src, target_id: dst });
      if (r.ok) { toast(r.toast); setTimeout(() => nav("/ticket.php?id=" + r.target_id), 600); }
      else toast(r.error || "Merge failed.");
    } catch { toast("Merge failed — check the ticket number."); }
  }
  const mergeBtn = $("#merge-btn");
  if (mergeBtn) mergeBtn.addEventListener("click", () => {
    const raw = prompt("Merge #" + mergeBtn.dataset.id + " into which ticket? (number)");
    const dst = parseInt((raw || "").replace(/[^0-9]/g, ""), 10);
    if (dst) mergeTickets(Number(mergeBtn.dataset.id), dst);
  });
  const mergeDupeBtn = $("#merge-dupe-btn");
  if (mergeDupeBtn) mergeDupeBtn.addEventListener("click", () => {
    if (confirm("Merge this ticket into #" + mergeDupeBtn.dataset.dst + "?")) {
      mergeTickets(Number(mergeDupeBtn.dataset.src), Number(mergeDupeBtn.dataset.dst));
    }
  });

  /* ------------------------------------------------------------------ */
  /* "?" shortcut overlay — the UI teaches itself                        */
  /* ------------------------------------------------------------------ */
  let helpOpen = false;
  function toggleHelp() {
    const existing = $("#help-overlay");
    if (existing) { existing.remove(); helpOpen = false; return; }
    helpOpen = true;
    const div = document.createElement("div");
    div.id = "help-overlay";
    div.className = "palette-overlay";
    div.innerHTML = `<div class="palette help-panel" role="dialog" aria-label="Keyboard shortcuts">
      <div class="help-head"><b>Keyboard shortcuts</b><span class="help-close"><kbd class="kbd">esc</kbd></span></div>
      <div class="help-grid">
        <div><h4>Queue</h4>
          <p><kbd class="kbd">j</kbd>/<kbd class="kbd">k</kbd> move · <kbd class="kbd">↵</kbd> open</p>
          <p><kbd class="kbd">1</kbd>–<kbd class="kbd">5</kbd> filters</p></div>
        <div><h4>Any ticket</h4>
          <p><kbd class="kbd">s</kbd> status · <kbd class="kbd">p</kbd> priority · <kbd class="kbd">a</kbd> assign me</p>
          <p><kbd class="kbd">e</kbd> timer · <kbd class="kbd">r</kbd> reply · <kbd class="kbd">n</kbd> note</p></div>
        <div><h4>Composer</h4>
          <p><kbd class="kbd">/</kbd> saved replies · <kbd class="kbd">⌘↵</kbd> send</p>
          <p>timer minutes log themselves on Send</p></div>
        <div><h4>Everywhere</h4>
          <p><kbd class="kbd">⌘K</kbd> palette (search reaches every message)</p>
          <p><kbd class="kbd">g</kbd> then <kbd class="kbd">q</kbd>/<kbd class="kbd">t</kbd>/<kbd class="kbd">c</kbd> navigate · <kbd class="kbd">?</kbd> this card</p></div>
      </div></div>`;
    div.addEventListener("click", (e) => { if (e.target === div) toggleHelp(); });
    document.body.appendChild(div);
  }

  /* new-ticket: filter contacts to the chosen client */
  (() => {
    const clientSel = $("#f-client");
    const contactSel = $("#f-contact");
    if (!clientSel || !contactSel) return;
    const options = $$("option[data-client]", contactSel);
    const sync = () => {
      const cid = clientSel.value;
      let first = null;
      options.forEach((o) => {
        const show = o.dataset.client === cid;
        o.hidden = !show;
        if (show && !first) first = o;
      });
      if (contactSel.selectedOptions[0]?.hidden) contactSel.value = first ? first.value : "";
    };
    clientSel.addEventListener("change", sync);
    sync();
  })();

  /* boot */
  paintTimer();
})();
