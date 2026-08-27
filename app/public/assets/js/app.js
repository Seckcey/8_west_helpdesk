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
    let payload = null;
    try { payload = await res.json(); } catch { /* keep the HTTP fallback */ }
    if (!res.ok) {
      const error = new Error(payload && typeof payload.error === "string" ? payload.error : "API " + res.status);
      error.status = res.status;
      error.code = payload && typeof payload.code === "string" ? payload.code : "";
      throw error;
    }
    if (!payload || typeof payload !== "object") throw new Error("Invalid server response");
    return payload;
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
  /* Timer (local until the server acknowledges this exact entry key)    */
  /* ------------------------------------------------------------------ */
  const timerTenantId = String(document.body.dataset.tenantId || "");
  const timerUserId = String(document.body.dataset.userId || "");
  const timerScope = /^\d+$/.test(timerTenantId) && /^\d+$/.test(timerUserId)
    ? timerTenantId + ":" + timerUserId
    : "";
  const TIMER_KEY = timerScope ? "safeharbor.timer.v2." + timerScope : null;
  const LEGACY_TIMER_KEY = "safeharbor.timer.v1";
  let timerSubmissionInFlight = false;

  function newEntryKey() {
    if (window.crypto && typeof window.crypto.randomUUID === "function") {
      return "timer:" + window.crypto.randomUUID();
    }
    return "timer:" + Date.now().toString(36) + ":" + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
  }

  function newCorrectionKey() {
    if (window.crypto && typeof window.crypto.randomUUID === "function") {
      return "correction:" + window.crypto.randomUUID();
    }
    return "correction:" + Date.now().toString(36) + ":" + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
  }

  function parseCorrectionUtc(value) {
    const text = typeof value === "string" ? value.trim() : "";
    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(text)) return null;
    const milliseconds = Date.parse(text);
    if (!Number.isFinite(milliseconds)) return null;
    const canonical = new Date(milliseconds).toISOString().replace(".000Z", "Z");
    return canonical === text ? { text, milliseconds } : null;
  }

  const readTimer = () => {
    try {
      if (!TIMER_KEY) return null;
      const t = JSON.parse(localStorage.getItem(TIMER_KEY));
      if (!t || !Number.isFinite(Number(t.ticketId)) || !Number.isFinite(Number(t.startedAt))) return null;
      if (String(t.tenantId) !== timerTenantId || String(t.userId) !== timerUserId) return null;
      let upgraded = false;
      if (!t.entryKey) { t.entryKey = newEntryKey(); upgraded = true; }
      if (!t.state) { t.state = t.endedAt ? "stopped" : "active"; upgraded = true; }
      if (t.state !== "active" && t.state !== "stopped") return null;
      if (t.state === "stopped"
          && (!t.submission || !["timer", "reply"].includes(t.submission.source))) {
        t.submission = { source: "timer", note: "Work on #" + t.ticketId, billable: 1 };
        upgraded = true;
      }
      if (upgraded) localStorage.setItem(TIMER_KEY, JSON.stringify(t));
      return t;
    } catch { return null; }
  };
  const writeTimer = (t) => {
    if (!TIMER_KEY) return;
    if (t) localStorage.setItem(TIMER_KEY, JSON.stringify(t));
    else localStorage.removeItem(TIMER_KEY);
  };

  const readLegacyTimer = () => {
    try {
      const t = JSON.parse(localStorage.getItem(LEGACY_TIMER_KEY));
      return t && Number.isFinite(Number(t.ticketId)) && Number.isFinite(Number(t.startedAt)) ? t : null;
    } catch { return null; }
  };

  function timerElapsed(t) {
    const end = t.endedAt || Date.now();
    return Math.max(0, Math.floor((end - t.startedAt) / 1000));
  }
  const timerMinutes = (t) => Math.max(1, Math.round(timerElapsed(t) / 60));
  const timerIso = (ms) => new Date(Number(ms)).toISOString();
  const responseEntryKey = (r) => String(r?.entry?.entry_key || r?.entry_key || "");

  function timerSubmission(t) {
    const custom = t.submission && ["timer", "reply"].includes(t.submission.source)
      ? t.submission
      : null;
    return {
      source: custom ? custom.source : "timer",
      note: custom ? String(custom.note || "") : "Work on #" + t.ticketId,
      billable: custom ? (custom.billable ? 1 : 0) : 1,
    };
  }

  function clearMatchingTimer(entryKey) {
    const current = readTimer();
    if (!current || current.entryKey !== entryKey) return false;
    writeTimer(null);
    return true;
  }
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
        widget.classList.toggle("timer-on", t.state === "active");
        widget.classList.toggle("timer-stopped", t.state === "stopped");
        widget.classList.remove("timer-idle");
        widget.innerHTML = `<span class="tw-dot${t.state === "active" ? " pulse" : ""}"></span><span>${fmt(timerElapsed(t))}</span><span style="opacity:.7">#${t.ticketId}${t.state === "stopped" ? " · retry" : ""}</span>`;
      } else {
        widget.classList.remove("timer-on");
        widget.classList.remove("timer-stopped");
        widget.classList.add("timer-idle");
        widget.textContent = "No timer running";
      }
    }
    // Time page card
    const card = $("#timer-card");
    if (card) {
      card.dataset.state = t ? t.state : "idle";
      const stop = $("#timer-stop");
      const discard = $("#timer-discard");
      if (t) {
        $("#timer-title").innerHTML = `${t.state === "active" ? "Timing" : "Stopped locally"} <a class="link" href="/ticket.php?id=${t.ticketId}">#${t.ticketId} ${t.subject ? escapeHtml(t.subject) : ""}</a>`;
        $("#timer-sub").textContent = t.state === "active"
          ? "Running locally — it is not logged until the server confirms."
          : "Not logged yet — retry safely when your connection is ready.";
        $("#timer-clock").textContent = fmt(timerElapsed(t));
        if (stop) {
          stop.hidden = false;
          stop.disabled = timerSubmissionInFlight;
          stop.textContent = t.state === "active" ? "Stop & log" : (timerSubmissionInFlight ? "Logging…" : "Retry logging");
        }
        if (discard) {
          discard.hidden = false;
          discard.disabled = timerSubmissionInFlight;
        }
      } else {
        $("#timer-title").textContent = "No timer running";
        $("#timer-sub").innerHTML = "Press <kbd class=\"kbd\">E</kbd> on any ticket in the queue to start one — or open a ticket and hit Start timer.";
        $("#timer-clock").textContent = "";
        if (stop) stop.hidden = true;
        if (discard) discard.hidden = true;
      }
    }
    // Ticket page button
    const btn = $("#timer-btn");
    if (btn) {
      const same = t && String(t.ticketId) === btn.dataset.id;
      btn.classList.toggle("timer-on", !!same);
      if (!same) btn.textContent = t ? `Timer already on #${t.ticketId}` : "▶ Start timer  (E)";
      else btn.textContent = t.state === "active" ? "■ Stop & log time  (E)" : "↻ Retry logging time  (E)";
    }
  }

  function escapeHtml(s) {
    const d = document.createElement("div");
    d.textContent = s;
    return d.innerHTML;
  }

  function startTimer(ticketId, subject) {
    const current = readTimer();
    if (current) {
      toast(current.state === "active"
        ? `Timer already running on #${current.ticketId}. Stop it before starting another.`
        : `Time for #${current.ticketId} is saved locally. Retry or discard it first.`);
      return;
    }
    writeTimer({
      ticketId: Number(ticketId),
      subject: subject || "",
      tenantId: timerTenantId,
      userId: timerUserId,
      entryKey: newEntryKey(),
      state: "active",
      startedAt: Date.now(),
      endedAt: null,
    });
    toast(`Timer started · #${ticketId}`);
    paintTimer();
  }

  async function submitStoppedTimer(t) {
    if (timerSubmissionInFlight) return;
    if (!t.submission) {
      t = { ...t, submission: timerSubmission(t) };
      writeTimer(t);
    }
    timerSubmissionInFlight = true;
    paintTimer();
    try {
      let r = null;
      let standaloneFallback = false;
      for (let attempt = 0; attempt < 2; attempt += 1) {
        const submission = timerSubmission(t);
        try {
          r = await api("/api/timer.php", {
            ticket_id: t.ticketId,
            entry_key: t.entryKey,
            source: submission.source,
            minutes: timerMinutes(t),
            note: submission.note,
            billable: submission.billable,
            started_at: timerIso(t.startedAt),
            ended_at: timerIso(t.endedAt),
            worked_at: timerIso(t.endedAt),
          });
          break;
        } catch (error) {
          // A 403 for reply provenance means the server proved this actor has
          // neither the reply grant nor an existing row. Preserve the measured
          // work under the same key as a truthful standalone timer instead of
          // losing it or inventing a message association.
          if (attempt === 0 && submission.source === "reply"
              && error?.status === 403 && error?.code === "reply_retry_unconfirmed") {
            const current = readTimer();
            if (!current || current.entryKey !== t.entryKey) throw error;
            t = {
              ...current,
              submission: {
                source: "timer",
                note: "Work on #" + current.ticketId,
                billable: submission.billable,
              },
            };
            writeTimer(t);
            standaloneFallback = true;
            continue;
          }
          throw error;
        }
      }
      if (!r) throw new Error("Time submission did not complete");
      if (responseEntryKey(r) !== t.entryKey || !clearMatchingTimer(t.entryKey)) {
        throw new Error("timer acknowledgement mismatch");
      }
      toast(standaloneFallback
        ? "Message was not confirmed; measured time was submitted as a standalone timer."
        : (r.toast || `Time logged · ${timerMinutes(t)}m`));
      paintTimer();
      if (page === "time") setTimeout(() => location.reload(), 500);
    } catch (error) {
      const detail = error instanceof Error && error.message && !error.message.startsWith("API ")
        ? " " + error.message + " Retry or discard it."
        : " Retry logging when you're online, or discard it.";
      toast("Time is stopped and saved locally —" + detail);
    } finally {
      timerSubmissionInFlight = false;
      paintTimer();
    }
  }

  async function stopTimer() {
    let t = readTimer();
    if (!t) return;
    if (t.state === "active") {
      t = { ...t, state: "stopped", endedAt: Date.now() };
      writeTimer(t);
      paintTimer();
    }
    await submitStoppedTimer(t);
  }

  function discardTimer() {
    const t = readTimer();
    if (!t) return;
    if (!confirm(`Discard the local timer for #${t.ticketId}? This time has not been logged.`)) return;
    writeTimer(null);
    paintTimer();
    toast("Local timer discarded.");
  }

  function paintLegacyTimer() {
    const panel = $("#legacy-timer-quarantine");
    if (!panel) return;
    const legacy = readLegacyTimer();
    panel.hidden = !legacy;
    if (!legacy) return;
    const message = $("#legacy-timer-message");
    if (message) {
      message.textContent = `Older unassigned timer: #${Number(legacy.ticketId)} · ${fmt(timerElapsed(legacy))}. Claim it only if you started it.`;
    }
    const claim = $("#legacy-timer-claim");
    if (claim) claim.disabled = !!readTimer();
  }

  function claimLegacyTimer() {
    const legacy = readLegacyTimer();
    if (!legacy) { paintLegacyTimer(); return; }
    if (readTimer()) {
      toast("Finish or discard your current timer before claiming the older one.");
      return;
    }
    if (!confirm(`Claim the older timer for #${Number(legacy.ticketId)} as your own technician time?`)) return;
    writeTimer({
      ticketId: Number(legacy.ticketId),
      subject: String(legacy.subject || ""),
      tenantId: timerTenantId,
      userId: timerUserId,
      entryKey: newEntryKey(),
      state: legacy.endedAt ? "stopped" : "active",
      startedAt: Number(legacy.startedAt),
      endedAt: legacy.endedAt ? Number(legacy.endedAt) : null,
    });
    localStorage.removeItem(LEGACY_TIMER_KEY);
    paintLegacyTimer();
    paintTimer();
    toast("Older timer claimed for this signed-in technician.");
  }

  function discardLegacyTimer() {
    const legacy = readLegacyTimer();
    if (!legacy) { paintLegacyTimer(); return; }
    if (!confirm(`Discard the older unassigned timer for #${Number(legacy.ticketId)}?`)) return;
    localStorage.removeItem(LEGACY_TIMER_KEY);
    paintLegacyTimer();
    toast("Older unassigned timer discarded.");
  }

  setInterval(() => {
    const t = readTimer();
    if (!t || t.state !== "active") return;
    const clock = $("#timer-clock");
    if (clock) clock.textContent = fmt(timerElapsed(t));
    const widget = $("#timer-widget");
    if (widget && widget.classList.contains("timer-on")) {
      const span = widget.querySelector("span:nth-child(2)");
      if (span) span.textContent = fmt(timerElapsed(t));
    }
  }, 1000);

  window.addEventListener("storage", (event) => {
    if (event.key === TIMER_KEY) paintTimer();
    if (event.key === LEGACY_TIMER_KEY) paintLegacyTimer();
  });

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
      { group: "Actions", title: "Go to Service goals", hint: "g s", run: () => nav("/service_goals.php") },
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
      else if (key === "s") { e.preventDefault(); nav("/service_goals.php"); }
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

  const discardBtn = $("#timer-discard");
  if (discardBtn) discardBtn.addEventListener("click", discardTimer);

  const legacyClaimBtn = $("#legacy-timer-claim");
  if (legacyClaimBtn) legacyClaimBtn.addEventListener("click", claimLegacyTimer);
  const legacyDiscardBtn = $("#legacy-timer-discard");
  if (legacyDiscardBtn) legacyDiscardBtn.addEventListener("click", discardLegacyTimer);

  const serverTimerAck = $("#time-entry-ack");
  if (serverTimerAck && clearMatchingTimer(serverTimerAck.dataset.entryKey || "")) {
    toast("Message saved and matching time entry logged.");
  }

  /* suggestion accept/dismiss (time page) */
  $$(".sugg-row").forEach((rowEl) => {
    const accept = $(".sugg-accept", rowEl);
    const dismiss = $(".sugg-dismiss", rowEl);
    if (accept) accept.addEventListener("click", async () => {
      accept.disabled = true;
      try {
        const r = await api("/api/timer.php", {
          ticket_id: Number(rowEl.dataset.ticketId),
          entry_key: rowEl.dataset.entryKey,
          source: "suggestion",
          minutes: Number(rowEl.dataset.minutes),
          note: "Suggested entry · #" + rowEl.dataset.ticketId,
          billable: 1,
          worked_at: rowEl.dataset.workedAt,
        });
        if (responseEntryKey(r) !== rowEl.dataset.entryKey) throw new Error("suggestion acknowledgement mismatch");
        toast(r.toast || "Logged");
        rowEl.remove();
        setTimeout(() => location.reload(), 700);
      } catch {
        accept.disabled = false;
        toast("Couldn't log that — the suggestion is still here to retry.");
      }
    });
    if (dismiss) dismiss.addEventListener("click", () => rowEl.remove());
  });

  /* rejected entries are never edited; one new pending replacement links back */
  $$(".time-correct").forEach((button) => {
    button.addEventListener("click", async () => {
      let payload = null;
      try {
        payload = button.dataset.correctionPayload
          ? JSON.parse(button.dataset.correctionPayload)
          : null;
      } catch {
        button.dataset.correctionPayload = "";
      }

      if (!payload) {
        const measured = button.dataset.measured === "1";
        let minutes = Number(button.dataset.minutes);
        let workedAt = button.dataset.workedAt;
        let startedAt = button.dataset.startedAt || null;
        let endedAt = button.dataset.endedAt || null;
        if (measured) {
          const startedAnswer = prompt(
            "Corrected start (UTC, YYYY-MM-DDTHH:MM:SSZ)",
            startedAt || ""
          );
          if (startedAnswer === null) return;
          const endedAnswer = prompt(
            "Corrected end (UTC, YYYY-MM-DDTHH:MM:SSZ)",
            endedAt || ""
          );
          if (endedAnswer === null) return;
          const workedAnswer = prompt(
            "Corrected worked-at time (UTC, inside that interval)",
            workedAt || ""
          );
          if (workedAnswer === null) return;

          const correctedStart = parseCorrectionUtc(startedAnswer);
          const correctedEnd = parseCorrectionUtc(endedAnswer);
          const correctedWorked = parseCorrectionUtc(workedAnswer);
          if (!correctedStart || !correctedEnd || !correctedWorked) {
            toast("Use real UTC times like 2026-08-25T10:30:00Z.");
            return;
          }
          const duration = correctedEnd.milliseconds - correctedStart.milliseconds;
          if (duration <= 0 || duration > 86400000) {
            toast("Corrected measured time must be longer than zero and no more than 24 hours.");
            return;
          }
          if (correctedWorked.milliseconds < correctedStart.milliseconds
              || correctedWorked.milliseconds > correctedEnd.milliseconds) {
            toast("Worked-at time must stay inside the corrected interval.");
            return;
          }
          startedAt = correctedStart.text;
          endedAt = correctedEnd.text;
          workedAt = correctedWorked.text;
          minutes = Math.max(1, Math.round(duration / 60000));
        } else {
          const answer = prompt("How many corrected minutes should be submitted?", String(minutes));
          if (answer === null) return;
          minutes = Number(answer.trim());
          if (!Number.isInteger(minutes) || minutes < 1 || minutes > 1440) {
            toast("Minutes must be a whole number from 1 to 1440.");
            return;
          }
        }

        const note = prompt("What should the corrected work note say?", button.dataset.note || "");
        if (note === null) return;
        if (Array.from(note).length > 255) {
          toast("The corrected work note cannot exceed 255 characters.");
          return;
        }
        const billingAnswer = prompt(
          "Billing for corrected time: type B for billable or I for internal. Cancel leaves it unchanged.",
          button.dataset.billable === "1" ? "B" : "I"
        );
        if (billingAnswer === null) return;
        const billingChoice = billingAnswer.trim().toUpperCase();
        if (billingChoice !== "B" && billingChoice !== "I") {
          toast("Type B for billable or I for internal.");
          return;
        }
        payload = {
          rejected_entry_id: Number(button.dataset.rejectedEntryId),
          entry_key: newCorrectionKey(),
          worked_at: workedAt,
          started_at: startedAt,
          ended_at: endedAt,
          minutes,
          note,
          billable: billingChoice === "B" ? 1 : 0,
        };
        // Freeze the exact key and facts before the request. A lost response
        // therefore retries the same correction instead of making a sibling.
        button.dataset.correctionPayload = JSON.stringify(payload);
      }

      button.disabled = true;
      try {
        const result = await api("/api/time_entry_correction.php", payload);
        if (responseEntryKey(result) !== payload.entry_key) {
          throw new Error("Correction acknowledgement did not match.");
        }
        toast(result.toast || "Correction submitted for approval.");
        setTimeout(() => location.reload(), 600);
      } catch (error) {
        button.disabled = false;
        const definitiveStatus = error instanceof Error ? Number(error.status || 0) : 0;
        const canEdit = [400, 404, 409, 422].includes(definitiveStatus);
        if (canEdit) button.dataset.correctionPayload = "";
        const message = error instanceof Error && error.message
          ? error.message
          : "Correction was not submitted.";
        toast(message + (canEdit
          ? " Your rejected entry is unchanged; try again to edit the correction."
          : " Your rejected entry is still unchanged."));
      }
    });
  });

  /* owner/admin review queue (time page) */
  $$(".time-review").forEach((button) => {
    button.addEventListener("click", async () => {
      const row = button.closest(".review-row");
      if (!row) return;
      const decision = button.dataset.decision;
      let note = "";
      if (decision === "rejected") {
        const answer = prompt("Why is this time entry being rejected? The technician will see this note.");
        if (answer === null) return;
        note = answer.trim();
        if (!note) { toast("A rejection note is required."); return; }
      }
      const controls = $$(".time-review", row);
      controls.forEach((control) => { control.disabled = true; });
      try {
        const r = await api("/api/time_entry_review.php", {
          entry_id: Number(row.dataset.timeEntryId),
          decision,
          note,
        });
        if (!r.ok) throw new Error("review rejected");
        toast(r.toast || (decision === "approved" ? "Time approved." : "Time rejected."));
        row.remove();
        const queue = $("#time-review-queue");
        if (queue && !$(".review-row", queue)) {
          queue.innerHTML = '<div class="empty"><p>No technician time is waiting for review.</p></div>';
        }
      } catch {
        toast("Review was not saved — refresh and try again.");
        controls.forEach((control) => { control.disabled = false; });
      }
    });
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
    const entryKeyInput = $("#f-timer-entry-key");
    const startedAtInput = $("#f-timer-started-at");
    const endedAtInput = $("#f-timer-ended-at");
    const workedAtInput = $("#f-timer-worked-at");
    const ticketId = $("#thread") ? $("#thread").dataset.ticketId : null;

    function paintChip() {
      if (!chip || !ticketId) return;
      const t = readTimer();
      const on = t && String(t.ticketId) === String(ticketId);
      chip.hidden = !on;
      if (on && chipText) chipText.textContent = (t.state === "stopped" ? "log saved " : "log ") + timerMinutes(t) + "m";
    }
    setInterval(paintChip, 1000);
    paintChip();

    form.addEventListener("submit", (event) => {
      const upload = $("#f-files");
      if (!box.value.trim() && !(upload && upload.files && upload.files.length)) return;
      let t = readTimer();
      if (!t || String(t.ticketId) !== String(ticketId) || !minutesInput || !entryKeyInput || !startedAtInput || !endedAtInput || !workedAtInput) return;
      if (t.state === "stopped" && t.submission) {
        event.preventDefault();
        toast("Retry or discard the saved time before sending another message.");
        return;
      }
      if (t.state === "active") {
        t = { ...t, state: "stopped", endedAt: Date.now() };
      }
      const replyIsNote = modeInput && modeInput.value === "note";
      const billableInput = $("#f-billable");
      t = {
        ...t,
        submission: {
          source: "reply",
          note: (replyIsNote ? "Noted on #" : "Replied on #") + ticketId,
          billable: billableInput && billableInput.checked ? 1 : 0,
        },
      };
      writeTimer(t);
      minutesInput.value = String(timerMinutes(t));
      entryKeyInput.value = t.entryKey;
      startedAtInput.value = timerIso(t.startedAt);
      endedAtInput.value = timerIso(t.endedAt);
      workedAtInput.value = timerIso(t.endedAt);
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
          <p>matching timer logs only after server-confirmed Send</p></div>
        <div><h4>Everywhere</h4>
          <p><kbd class="kbd">⌘K</kbd> palette (search reaches every message)</p>
          <p><kbd class="kbd">g</kbd> then <kbd class="kbd">q</kbd>/<kbd class="kbd">t</kbd>/<kbd class="kbd">c</kbd>/<kbd class="kbd">s</kbd> navigate · <kbd class="kbd">?</kbd> this card</p></div>
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
  paintLegacyTimer();
})();
