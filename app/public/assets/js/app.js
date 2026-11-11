/* ==========================================================================
   Safeharbor — keyboard model, ⌘K palette, timer, optimistic updates.
   No dependencies. The 8 West Standard: instant, keyboard-first, calm.
   ========================================================================== */
(() => {
  "use strict";

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const page = document.body.dataset.active || "";
  // Shortcut hints name this computer's own keys.
  const IS_MAC = /Mac|iPhone|iPad/.test(navigator.platform || "");
  const MOD_KEY = IS_MAC ? "⌘" : "Ctrl";

  const navToggle = $("#mobile-nav-toggle");
  if (navToggle) {
    const setNavigation = open => {
      document.body.classList.toggle("sidebar-open", open);
      navToggle.setAttribute("aria-expanded", String(open));
    };
    navToggle.addEventListener("click", () => setNavigation(!document.body.classList.contains("sidebar-open")));
    $$(".mobile-nav-close, .mobile-nav-backdrop, #suite-sidebar a").forEach(control =>
      control.addEventListener("click", () => setNavigation(false)));
    document.addEventListener("keydown", event => {
      if (event.key === "Escape" && document.body.classList.contains("sidebar-open")) {
        setNavigation(false); navToggle.focus();
      }
    });
  }

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

  /* The lower-left user menu lived here. Identity, suite settings and Sign out moved to the
     account menu inside the shared suite cluster at the right end of the topbar, and the vendored
     launcher (/assets/w365/w365-launcher.js) owns opening and closing both of its panels — click,
     Escape, outside click, focus return and the arrow keys. Nothing to re-implement here. */

  /* ------------------------------------------------------------------ */
  /* Confirm before removing                                            */
  /* ------------------------------------------------------------------ */
  /* A form with data-confirm="…" posts only after OK. The question is an attribute, never script,
     so no name (an apostrophe, or a sender name that looks like code) can skip the prompt or run.
     Capture phase, so the answer comes before any other submit handler sees the form. */
  document.addEventListener("submit", (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.dataset.confirm) return;
    if (!confirm(form.dataset.confirm)) {
      event.preventDefault();
      event.stopImmediatePropagation();
    }
  }, true);

  /* ------------------------------------------------------------------ */
  /* Toasts                                                              */
  /* ------------------------------------------------------------------ */
  // A toast can carry one Undo. Only the newest one answers the Z key, and only while it shows.
  const UNDO_MS = 6000;
  let undoable = null;
  function toast(text, options = {}) {
    const box = $("#toasts");
    if (!box) return;
    const el = document.createElement("div");
    el.className = "toast";
    const label = document.createElement("span");
    label.textContent = text;
    el.appendChild(label);
    const canUndo = typeof options.undo === "function";
    const dismiss = () => {
      clearTimeout(timer);
      el.remove();
      if (undoable && undoable.el === el) undoable = null;
    };
    if (canUndo) {
      const run = () => { dismiss(); options.undo(); };
      const button = document.createElement("button");
      button.type = "button";
      button.className = "toast-undo";
      button.append("Undo");
      const key = document.createElement("kbd");
      key.className = "kbd";
      key.textContent = "Z";
      key.setAttribute("aria-hidden", "true");
      button.appendChild(key);
      button.addEventListener("click", run);
      el.classList.add("toast-action");
      el.appendChild(button);
      undoable = { el, run };
    }
    box.appendChild(el);
    const timer = setTimeout(dismiss, canUndo ? UNDO_MS : 2600);
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
      // The server answered (with its own JSON), as opposed to a dropped connection or a gateway.
      error.fromServer = payload !== null && typeof payload === "object";
      throw error;
    }
    if (!payload || typeof payload !== "object") throw new Error("Invalid server response");
    return payload;
  }

  /* The current status, priority and owner of a ticket, as the page was rendered and as every
     change since has answered: the ticket page keeps them on #thread, the queue on each row. */
  // The owner is kept as data-owner: data-assignee is the hook of the element that shows the owner.
  const TICKET_FIELDS = { status: "status", priority: "priority", assignee: "owner" };
  function ticketFieldHolder(id) {
    const thread = $("#thread");
    if (thread && String(thread.dataset.ticketId) === String(id)) return thread;
    return document.querySelector(`.trow[data-ticket-id="${Number(id)}"]`);
  }
  function currentFieldValue(field, id) {
    const holder = ticketFieldHolder(id);
    return holder && TICKET_FIELDS[field] ? holder.dataset[TICKET_FIELDS[field]] : undefined;
  }

  /* One field change. `chosen` tells the server a person picked this exact status, which is the
     only way a resolve may ask the customer for a rating. An Undo says `undo` instead: it puts a
     value back, asks nobody for a rating and emails nobody. Every change can be taken back from
     its toast unless `undoable` is false. */
  async function ticketAction(id, field, value, options = {}) {
    const before = options.undoable === false ? undefined : currentFieldValue(field, id);
    try {
      const body = { id, field, value };
      if (options.chosen === true && field === "status") body.chosen = true;
      if (options.undo === true) body.undo = true;
      const r = await api("/api/ticket_action.php", body);
      if (!r.ok) return;
      const holder = ticketFieldHolder(id);
      if (holder) {
        if (typeof r.status === "string") holder.dataset.status = r.status;
        if (typeof r.priority === "string") holder.dataset.priority = r.priority;
        if (r.assignee_id !== undefined) holder.dataset.owner = r.assignee_id === null ? "" : String(r.assignee_id);
      }
      const after = currentFieldValue(field, id);
      const undoValue = field === "assignee" && before === "" ? "none" : before;
      toast(r.toast, before !== undefined && after !== undefined && before !== after
        ? { undo: () => ticketAction(id, field, undoValue, { undoable: false, undo: true }) } : {});
      // In-place repaint where the fragments exist
      const scope = document;
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
      }
      if (typeof r.status === "string" && $("#thread")) paintTicketStatus(r.status);
      const workflow = $(".westy-workflow-card");
      if (workflow) {
        workflow.dataset.workflowState = "human_owned";
        $("#westy-workflow-title", workflow).textContent = "A technician owns this ticket";
        $("p", workflow).textContent = "The ticket was changed by a technician. Westy cannot close it automatically.";
        $(".westy-workflow-actions [data-action]", workflow)?.remove();
        $("[data-workflow-grant='create']", workflow)?.remove();
      }
      return true;
    } catch (e) {
      toast("Couldn't save — check your connection.");
      return false;
    }
  }

  /* The ticket page follows its status: Resolve or Reopen (whichever applies) and the composer's
     sentence about what Send does. */
  function paintTicketStatus(status) {
    const resolve = $("#resolve-btn");
    const reopen = $("#reopen-btn");
    if (resolve && reopen) {
      const resolved = status === "resolved";
      const hadFocus = document.activeElement === resolve || document.activeElement === reopen;
      resolve.hidden = resolved;
      reopen.hidden = !resolved;
      if (hadFocus) (resolved ? reopen : resolve).focus();
    }
    if (composer) composer.paintHint();
  }

  document.addEventListener("click", async (event) => {
    const btn = event.target.closest("[data-set-status]");
    if (!btn || btn.disabled) return;
    btn.disabled = true;
    await ticketAction(btn.dataset.id, "status", btn.dataset.setStatus, { chosen: true });
    btn.disabled = false;
  });

  /* ------------------------------------------------------------------ */
  /* Menus: status, priority and owner                                   */
  /* ------------------------------------------------------------------ */
  /* One menu at a time, opened from its button on the ticket page or with S, P or T on a ticket
     or a highlighted queue row. It owns the keyboard while open: arrows and Enter, a number for
     one of the first nine choices, Escape to close. The owner menu adds a box to find a teammate.
     Choices and their tags come rendered from the server (the page's data island). */
  const MENU_LABELS = { status: "Status", priority: "Priority", assignee: "Assign to" };
  const MENU_CHECK = '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m3 8.5 3.2 3.2L13 4.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  let menu = null;

  function menuChoices(field) {
    let data = null;
    const island = $("#palette-data");
    if (island) { try { data = JSON.parse(island.textContent); } catch { /* no menus to offer */ } }
    const menus = data && data.menus && typeof data.menus === "object" ? data.menus : null;
    if (!menus) return [];
    if (field !== "assignee") {
      return (Array.isArray(menus[field]) ? menus[field] : [])
        .map((c) => ({ value: String(c.value), label: String(c.label), html: String(c.html || "") }));
    }
    if (!Array.isArray(menus.team)) return [];
    const me = String(document.body.dataset.userId || "");
    const mine = menus.team.find((m) => String(m.id) === me) || null;
    return [
      { value: me, label: "Me", search: "me " + (mine ? mine.name : ""), person: mine, me: true },
      { value: "none", label: "Unassigned", search: "unassigned nobody", person: null },
      ...menus.team.filter((m) => String(m.id) !== me)
        .map((m) => ({ value: String(m.id), label: String(m.name), search: String(m.name), person: m })),
    ];
  }

  function personAvatar(person) {
    const avatar = make("span", "avatar menu-avatar" + (person ? "" : " avatar-none"), person ? String(person.initials || "") : "—");
    avatar.setAttribute("aria-hidden", "true");
    if (person && /^#[0-9a-f]{6}$/i.test(String(person.color || ""))) avatar.style.background = person.color;
    return avatar;
  }

  function renderMenu(query) {
    const q = query.trim().toLowerCase();
    menu.shown = menu.choices.filter((c) => !q || (c.search || c.label).toLowerCase().includes(q));
    menu.list.textContent = "";
    menu.shown.forEach((choice, i) => {
      const item = make("button", "menu-item");
      item.type = "button";
      item.id = menu.list.id + "-" + i;
      item.tabIndex = -1;
      item.setAttribute("role", "option");
      item.setAttribute("aria-selected", String(choice.value === menu.current));
      const check = make("span", "menu-check");
      check.innerHTML = MENU_CHECK;
      const main = make("span", "menu-item-main");
      if (choice.html) main.innerHTML = choice.html;   // a tag the server rendered for this page
      else {
        main.appendChild(personAvatar(choice.person));
        main.appendChild(make("span", "menu-item-name", choice.label));
        if (choice.me && choice.person) main.appendChild(make("span", "menu-item-note", String(choice.person.name)));
      }
      item.append(check, main);
      if (i < 9 && !q) {
        const key = make("kbd", "kbd", String(i + 1));
        key.setAttribute("aria-hidden", "true");
        item.appendChild(key);
      }
      item.addEventListener("click", () => pickMenu(i));
      item.addEventListener("mousemove", () => { if (menu && menu.index !== i) { menu.index = i; paintMenu(); } });
      menu.list.appendChild(item);
    });
    if (!menu.shown.length) menu.list.appendChild(make("div", "menu-empty", "No teammate matches."));
  }

  function paintMenu() {
    const items = $$(".menu-item", menu.list);
    items.forEach((el, i) => el.classList.toggle("sel", i === menu.index));
    const active = items[menu.index];
    const owner = menu.input || menu.list;
    if (active) { owner.setAttribute("aria-activedescendant", active.id); active.scrollIntoView({ block: "nearest" }); }
    else owner.removeAttribute("aria-activedescendant");
  }

  // Under its anchor, or above it when there is no room; always inside the window. Under a button
  // the right edges line up (the buttons sit at the right of the page); under a queue cell the left.
  function placeMenu() {
    if (!menu) return;
    const at = menu.anchor.getBoundingClientRect();
    const width = Math.min(Math.max(at.width, 248), window.innerWidth - 16);
    menu.pop.style.width = width + "px";
    const height = menu.pop.offsetHeight;
    let top = at.bottom + 6;
    if (top + height > window.innerHeight - 8 && at.top - 6 - height >= 8) top = at.top - 6 - height;
    const left = menu.trigger ? at.right - width : at.left;
    menu.pop.style.top = Math.max(8, Math.min(top, window.innerHeight - height - 8)) + "px";
    menu.pop.style.left = Math.max(8, Math.min(left, window.innerWidth - width - 8)) + "px";
  }

  // S, P and T (and the palette's "Change status", "Set priority", "Assign to") open a menu: under
  // its button on a ticket, under its cell on a queue row.
  function openFieldMenu(field, id, row) {
    const button = row ? null : $(`.menu-btn[data-action="${field}"]`);
    const cell = row ? row.querySelector(field === "status" ? ".trow-status" : field === "priority" ? ".trow-pri" : ".trow-tech") : null;
    return openTicketMenu(field, id, button || cell, button);
  }

  function openTicketMenu(field, id, anchor, trigger) {
    const choices = menuChoices(field);
    if (!choices.length || !anchor) return false;
    closeMenu(false);
    const raw = currentFieldValue(field, id);
    const current = field === "assignee" && raw === "" ? "none" : raw;
    const pop = make("div", "menu-pop");
    pop.dataset.menu = field;
    const list = make("div", "menu-list");
    list.id = "ticket-menu-" + field;
    list.setAttribute("role", "listbox");
    list.setAttribute("aria-label", MENU_LABELS[field]);
    let input = null;
    if (field === "assignee") {
      input = make("input", "menu-filter");
      input.type = "text";
      input.placeholder = "Find a teammate";
      input.autocomplete = "off";
      input.setAttribute("role", "combobox");
      input.setAttribute("aria-expanded", "true");
      input.setAttribute("aria-controls", list.id);
      input.setAttribute("aria-autocomplete", "list");
      input.setAttribute("aria-label", MENU_LABELS[field]);
      pop.appendChild(input);
    } else {
      list.tabIndex = -1;
    }
    pop.appendChild(list);
    (anchor.closest('dialog[open]') || document.body).appendChild(pop);
    menu = { field, id, pop, list, input, anchor, trigger: trigger || null, choices, current, shown: [], index: 0, restore: document.activeElement };
    renderMenu("");
    menu.index = Math.max(0, menu.shown.findIndex((c) => c.value === current));
    if (input) input.addEventListener("input", () => { renderMenu(input.value); menu.index = 0; paintMenu(); placeMenu(); });
    if (menu.trigger) menu.trigger.setAttribute("aria-expanded", "true");
    placeMenu();
    paintMenu();
    (input || list).focus({ preventScroll: true });
    return true;
  }

  function closeMenu(returnFocus = true) {
    if (!menu) return;
    const { pop, trigger, restore } = menu;
    menu = null;
    pop.remove();
    if (trigger) trigger.setAttribute("aria-expanded", "false");
    if (returnFocus && restore && restore !== document.body && document.contains(restore)) restore.focus({ preventScroll: true });
  }

  function pickMenu(index) {
    if (!menu) return;
    const choice = menu.shown[index];
    const { field, id, current } = menu;
    closeMenu();
    if (!choice || choice.value === current) return;
    ticketAction(id, field, choice.value, { chosen: true });
  }

  function menuKeydown(e) {
    const key = e.key;
    const plain = !e.ctrlKey && !e.metaKey && !e.altKey;
    const last = menu.shown.length - 1;
    const move = (to) => { e.preventDefault(); menu.index = Math.max(0, Math.min(to, last)); paintMenu(); };
    if (key === "Escape") { e.preventDefault(); closeMenu(); }
    else if (key === "Tab") closeMenu();   // focus returns to the button, then moves on
    else if (key === "ArrowDown" || (!menu.input && plain && key === "j")) move(menu.index + 1);
    else if (key === "ArrowUp" || (!menu.input && plain && key === "k")) move(menu.index - 1);
    else if (!menu.input && key === "Home") move(0);
    else if (!menu.input && key === "End") move(last);
    else if (key === "Enter" || (!menu.input && key === " ")) { e.preventDefault(); pickMenu(menu.index); }
    else if (plain && /^[1-9]$/.test(key) && (!menu.input || menu.input.value === "")) {
      e.preventDefault();
      if (Number(key) - 1 <= last) pickMenu(Number(key) - 1);
    }
    // Any other key belongs to the filter box when there is one, and to nothing otherwise.
    else if (!menu.input && plain && key.length === 1) e.preventDefault();
  }

  document.addEventListener("pointerdown", (event) => {
    if (menu && !menu.pop.contains(event.target) && !(menu.trigger && menu.trigger.contains(event.target))) closeMenu(false);
  }, true);
  window.addEventListener("resize", placeMenu);
  window.addEventListener("scroll", (event) => { if (menu && !menu.pop.contains(event.target)) placeMenu(); }, true);

  // Grant intent has its own endpoint and repaint. It never implies a ticket transfer.
  async function refreshWorkflowCard(ticketId) {
    const res = await fetch("/ticket.php?id=" + encodeURIComponent(ticketId), { cache: "no-store" });
    if (!res.ok || res.redirected) throw new Error("Could not refresh workflow");
    const fresh = new DOMParser().parseFromString(await res.text(), "text/html");
    const card = fresh.querySelector(".westy-workflow-card");
    const current = $(".westy-workflow-card");
    if (!card || !current || fresh.querySelector("#thread")?.dataset.ticketId !== String(ticketId)) throw new Error("Workflow unavailable");
    current.replaceWith(card);
  }

  document.addEventListener("click", async (event) => {
    const btn = event.target.closest("[data-workflow-grant]");
    if (!btn || btn.disabled) return;
    const card = btn.closest(".westy-workflow-card");
    const notice = card?.querySelector("[data-workflow-grant-notice]");
    const action = btn.dataset.workflowGrant;
    if (!card || !["create", "cancel"].includes(action)) return;
    btn.disabled = true;
    try {
      // Retain this exact key on an uncertain response. A reload discovers durable intent.
      if (!btn.dataset.grantKey) {
        const bytes = crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 15) | 64;
        bytes[8] = (bytes[8] & 63) | 128;
        const hex = Array.from(bytes, b => b.toString(16).padStart(2, "0")).join("");
        btn.dataset.grantKey = [hex.slice(0,8),hex.slice(8,12),hex.slice(12,16),hex.slice(16,20),hex.slice(20)].join("-");
      }
      const body = { action, ticket_id: Number(btn.dataset.ticketId), workflow_key: btn.dataset.workflowKey,
        expected_version: Number(btn.dataset.version), grant_key: btn.dataset.grantKey };
      const r = await api("/api/westy_workflow_grant.php", body);
      if (r.ok !== true || r.action !== action || r.ticket_id !== body.ticket_id || r.workflow_key !== body.workflow_key
          || r.grant_key !== body.grant_key || r.expected_version !== body.expected_version
          || r.grant_state !== (action === "create" ? "pending" : "cancelled")) throw new Error("Unconfirmed grant response");
      if (notice) notice.textContent = action === "create" ? "Return request recorded. Refreshing its current state…" : "Return request cancelled. Refreshing…";
      // Fetch only fresh card markup; never reload or replace the technician's draft.
      try { await refreshWorkflowCard(body.ticket_id); }
      catch { if (notice) notice.textContent = "Request recorded. Refresh the ticket to see its current state. Your draft is unchanged."; }
    } catch (error) {
      if (notice) notice.textContent = error.status === 409
        ? "The request no longer matches this workflow. Refresh the ticket to review its current state."
        : "The request could not be confirmed. Retry to check the same request. Your draft is unchanged.";
    } finally { btn.disabled = false; }
  });

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

  function newAdjustmentKey() {
    const cryptoApi = window.crypto;
    if (!cryptoApi || typeof cryptoApi.getRandomValues !== "function") return null;
    const bytes = cryptoApi.getRandomValues(new Uint8Array(16));
    // UUID v4 formatting, using only cryptographically random browser bytes.
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (value) => value.toString(16).padStart(2, "0"));
    return "adjustment:" + [
      hex.slice(0, 4).join(""),
      hex.slice(4, 6).join(""),
      hex.slice(6, 8).join(""),
      hex.slice(8, 10).join(""),
      hex.slice(10, 16).join(""),
    ].join("-");
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
        widget.innerHTML = `<span class="tw-dot${t.state === "active" ? " pulse" : ""}"></span><span>${fmt(timerElapsed(t))}</span><span class="tw-ticket">#${t.ticketId}</span>${t.state === "stopped" ? '<span class="tw-retry">· retry</span>' : ""}`;
      } else {
        widget.classList.remove("timer-on");
        widget.classList.remove("timer-stopped");
        widget.classList.add("timer-idle");
        widget.replaceChildren(make("span", "tw-idle-label", "No timer running"));
      }
      // Presentation only: the same Time link and timer state, with a phone-sized clock icon.
      const clock = make("span", "tw-icon");
      clock.setAttribute("aria-hidden", "true");
      clock.innerHTML = '<svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="10" cy="10" r="7.5"/><path d="M10 5.5V10l3 2" stroke-linecap="round"/></svg>';
      widget.prepend(clock);
      widget.setAttribute("aria-label", t ? `Time: ${fmt(timerElapsed(t))}${t.state === "stopped" ? ", not logged yet; retry" : ", running"}` : "Time: no timer running");
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
          stop.textContent = t.state === "active" ? "Stop and log" : (timerSubmissionInFlight ? "Logging" : "Retry logging");
        }
        if (discard) {
          discard.hidden = false;
          discard.disabled = timerSubmissionInFlight;
        }
      } else {
        $("#timer-title").textContent = "No timer running";
        $("#timer-sub").innerHTML = "Press <kbd class=\"kbd\">E</kbd> on a ticket in the queue to start one, or open a ticket and press Start timer.";
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
      const words = !same ? (t ? `Timer is on #${t.ticketId}` : "Start timer")
        : (t.state === "active" ? "Stop and log time" : "Retry logging time");
      const hint = make("kbd", "kbd", "E");
      hint.setAttribute("aria-hidden", "true");
      btn.replaceChildren(document.createTextNode(words), hint);
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
  // The highlight is a keyboard cursor. It is not drawn when the page opens, because a row that
  // looks chosen before anybody chose it reads as a property of that ticket or client. The first
  // row key (j, k, an arrow, x, Enter, o, or a key that acts on a row) shows it and does nothing
  // else, except x, whose tick is itself visible. A key acts on a row only once that row has been
  // seen highlighted: a timer never starts on a ticket nobody chose. A ticked checkbox shows it too.
  // At phone width rows are tapped, so the highlight is never drawn there, and because it cannot
  // be seen the keys that act on the highlighted row (Enter, o, a, s, p, t, e) do nothing. j, k and
  // x still work for a keyboard on a narrow window: the tick shows which row x reached.
  let selShown = false;
  const phoneWidth = window.matchMedia("(max-width: 700px)");
  const selVisible = () => selShown && !phoneWidth.matches;
  function paintSel() {
    const shown = selVisible();
    rows.forEach((r, i) => r.classList.toggle(page === "queue" ? "trow-sel" : "crow-sel", shown && i === sel));
    if (selShown && rows[sel]) rows[sel].scrollIntoView({ block: "nearest" });
  }
  // Shows the highlight where it stands; true when this call is what showed it.
  function showSel() {
    if (selShown || !rows.length) return false;
    selShown = true;
    paintSel();
    return true;
  }
  // True when a key may act on the highlighted row: the person can see which row that is. The
  // press that first shows the highlight is not one of those.
  function rowChosen() {
    if (phoneWidth.matches) return false;
    return !showSel();
  }
  // Resizing a window across the phone width: the highlight goes, or comes back where it was.
  phoneWidth.addEventListener("change", paintSel);

  const currentRow = () => rows[sel];

  /* ------------------------------------------------------------------ */
  /* Bulk delete (queue, owners and admins; docs/ticket-bulk-delete.md)  */
  /* ------------------------------------------------------------------ */
  // A delete always names its tickets: the ticked rows, or with "select all" every row this page
  // shows, never a live filter. The preview answers with the ids it can delete, and the run sends
  // exactly that list, so it never reaches a ticket nobody saw or goes past the confirmed count.
  const BULK_URL = "/api/ticket_bulk_delete.php";
  const BULK_MAX_IDS = 10000;
  let bulkDialogOpen = false;
  let mergeDialogOpen = false;
  let formDialogOpen = false;
  // How long a dialog ignores presses outside it after it opens.
  const DIALOG_SETTLE_MS = 300;
  const bulkBar = page === "queue" ? $("#bulk-bar") : null;

  function make(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }
  const plural = (n, one, many) => `${n.toLocaleString()} ${n === 1 ? one : many}`;

  const bulk = bulkBar ? (() => {
    const picks = $$(".bulk-pick");
    const allBox = $("#bulk-all");
    const counter = $(".bulk-count", bulkBar);
    const list = $(".card.list");
    const filterLabel = bulkBar.dataset.filterLabel || "All";
    // The checkboxes are out of sight until somebody asks to select: the Select button, or X.
    const modeButton = $("#bulk-toggle");
    const inMode = () => !!list && list.classList.contains("bulk-mode");
    function setMode(on) {
      if (list) list.classList.toggle("bulk-mode", on);
      if (modeButton) modeButton.setAttribute("aria-pressed", String(on));
      if (!on) clear();
    }

    const picked = () => picks.filter((box) => box.checked);
    function paint() {
      const n = picked().length;
      picks.forEach((box) => {
        const wrap = box.closest(".trow-pick");
        if (wrap) wrap.classList.toggle("picked", box.checked);
      });
      if (allBox) {
        allBox.checked = n > 0 && n === picks.length;
        allBox.indeterminate = n > 0 && n < picks.length;
      }
      bulkBar.hidden = n === 0;
      if (list) list.classList.toggle("bulk-on", n > 0);
      counter.textContent = n > 0 && n === picks.length
        ? `All ${plural(n, "ticket", "tickets")} in ${filterLabel} are selected.`
        : `${n.toLocaleString()} selected`;
    }
    function clear() {
      picks.forEach((box) => { box.checked = false; });
      paint();
    }
    function openForSelection() {
      const ids = picked().map((box) => Number(box.value));
      if (!ids.length) return false;
      if (ids.length > BULK_MAX_IDS) { toast(`Select up to ${BULK_MAX_IDS.toLocaleString()} tickets at a time.`); return true; }
      openDeleteDialog(ids);
      return true;
    }
    picks.forEach((box) => box.addEventListener("change", paint));
    // The highlight follows the checkbox just used, so x and Enter act on that row, not an older one.
    // Focus counts only when it arrives from another element: the window regaining focus refocuses
    // the old checkbox and must not pull the highlight back to it. Safari does not focus a clicked
    // checkbox, so change covers clicks there.
    picks.forEach((box) => ["focus", "change"].forEach((type) => box.addEventListener(type, (event) => {
      if (type === "focus" && !event.relatedTarget) return;
      const wrap = box.closest(".trow-pick");
      const index = wrap ? rows.indexOf($(".trow", wrap)) : -1;
      if (index >= 0) { sel = index; selShown = true; paintSel(); }
    })));
    if (allBox) allBox.addEventListener("change", () => {
      picks.forEach((box) => { box.checked = allBox.checked; });
      paint();
    });
    $("#bulk-clear").addEventListener("click", clear);
    if (modeButton) modeButton.addEventListener("click", () => setMode(!inMode()));
    $("#bulk-delete").addEventListener("click", openForSelection);
    // Start empty, whatever the browser restores after a reload or Back: the rows may have shifted.
    clear();
    window.addEventListener("pageshow", (event) => { if (event.persisted) clear(); });
    return {
      toggle(row) {
        const wrap = row && row.closest(".trow-pick");
        const box = wrap && $(".bulk-pick", wrap);
        if (!box) return false;
        setMode(true);
        box.checked = !box.checked;
        box.dispatchEvent(new Event("change"));
        return true;
      },
      clear,
      openForSelection,
      hasSelection: () => picked().length > 0,
      inMode,
      leaveMode: () => setMode(false),
    };
  })() : null;

  function openDeleteDialog(ids) {
    if (bulkDialogOpen) return;
    bulkDialogOpen = true;
    const opener = $("#bulk-delete");
    const overlay = make("div", "palette-overlay");
    overlay.id = "delete-overlay";
    const panel = make("div", "palette delete-panel");
    panel.setAttribute("role", "dialog");
    panel.setAttribute("aria-modal", "true");
    panel.setAttribute("aria-labelledby", "delete-title");
    panel.tabIndex = -1;
    overlay.appendChild(panel);
    document.body.appendChild(overlay);

    let preview = null;
    let running = false;
    let stopRequested = false;
    let afterId = 0;
    let deleted = 0;
    let filesFailed = 0;
    let typed = "";
    const keptLater = new Map();
    const subjects = new Map();

    const onUnload = (e) => { if (running) { e.preventDefault(); e.returnValue = ""; } };
    const onKey = (e) => {
      if (e.key === "Escape") {
        e.preventDefault();
        if (!running) close(deleted > 0);
        return;
      }
      if (e.key !== "Tab") return;
      const focusable = $$("a[href], button:not([disabled]), input:not([disabled])", panel);
      if (!focusable.length || !panel.contains(document.activeElement)) {
        e.preventDefault();
        (focusable[0] || panel).focus();
        return;
      }
      const first = focusable[0], last = focusable[focusable.length - 1];
      if (e.shiftKey && (document.activeElement === first || document.activeElement === panel)) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    };
    window.addEventListener("beforeunload", onUnload);
    document.addEventListener("keydown", onKey);

    function close(reload) {
      window.removeEventListener("beforeunload", onUnload);
      document.removeEventListener("keydown", onKey);
      overlay.remove();
      bulkDialogOpen = false;
      if (reload) {
        if (bulk) bulk.clear();
        window.location.reload();
        return;
      }
      if (opener) opener.focus();
    }
    function screen(title, sub) {
      panel.replaceChildren();
      const h = make("h2", "", title);
      h.id = "delete-title";
      panel.appendChild(h);
      if (sub) panel.appendChild(make("p", "delete-sub", sub));
      panel.focus();
    }
    function actions(...buttons) {
      const row = make("div", "delete-actions");
      buttons.forEach((b) => row.appendChild(b));
      panel.appendChild(row);
      return row;
    }
    function button(label, className, onClick) {
      const b = make("button", className, label);
      b.type = "button";
      b.addEventListener("click", onClick);
      return b;
    }
    function reasonLabel(code) {
      return (preview && preview.reasons && preview.reasons[code]) || code;
    }
    // entries: [id, reason] pairs to list (first 50 shown); tally: {reason: count} for the whole set.
    function keptList(heading, entries, totalKept, tally) {
      const box = make("div", "delete-kept");
      box.appendChild(make("h3", "", heading));
      const byReason = make("ul");
      Object.entries(tally).forEach(([reason, n]) =>
        byReason.appendChild(make("li", "", `${Number(n).toLocaleString()} · ${reasonLabel(reason)}`)));
      box.appendChild(byReason);
      const items = make("ul");
      entries.slice(0, 50).forEach(([id, reason]) => {
        const li = make("li");
        const link = make("a", "", `#${id}`);
        link.href = `/ticket.php?id=${encodeURIComponent(id)}`;
        li.appendChild(link);
        const subject = subjects.get(id);
        li.appendChild(document.createTextNode(` ${subject ? subject + " " : ""}— ${reasonLabel(reason)}`));
        items.appendChild(li);
      });
      box.appendChild(items);
      if (totalKept > Math.min(entries.length, 50)) {
        box.appendChild(make("p", "delete-note", `and ${(totalKept - Math.min(entries.length, 50)).toLocaleString()} more`));
      }
      if (tally.grant_attachments || tally.grant_csat) {
        box.appendChild(make("p", "delete-note", "An administrator has to grant a database permission before tickets with attachments or surveys can be deleted."));
      }
      if (tally.merge_unselected) {
        box.appendChild(make("p", "delete-note", "To delete merged tickets, select every ticket in the merge."));
      }
      panel.appendChild(box);
    }

    function showConfirm() {
      const d = preview.deletable, s = preview.total, k = preview.kept;
      (preview.kept_sample || []).forEach((t) => subjects.set(t.id, t.subject));
      const sample = (preview.kept_sample || []).map((t) => [t.id, t.reason]);
      if (d === 0) {
        screen("Nothing here can be deleted");
        const found = s - preview.not_found;
        panel.appendChild(make("p", "", found === 0
          ? "The selected tickets no longer exist."
          : found === 1 ? "The selected ticket can't be deleted; the reason is below."
          : `None of the ${found.toLocaleString()} selected tickets can be deleted; the reasons are below.`));
        if (k > 0) keptList("Kept — will not be deleted:", sample, k, preview.kept_by_reason || {});
        const done = button("Close", "btn-link", () => close(false));
        actions(done);
        done.focus();
        return;
      }
      screen(`Permanently delete ${plural(d, "ticket", "tickets")}?`,
        k > 0 ? `${s.toLocaleString()} selected · ${k.toLocaleString()} will be kept` : `${s.toLocaleString()} selected`);
      panel.appendChild(make("p", "", "This removes each ticket with its replies, internal notes, attachments and satisfaction survey, and cancels any email still waiting to go out. Ticket numbers are never reused. Safeharbor cannot undo this."));
      if (k > 0) keptList("Kept — will not be deleted:", sample, k, preview.kept_by_reason || {});
      if (preview.not_found > 0) panel.appendChild(make("p", "delete-note", `${plural(preview.not_found, "selected ticket no longer exists", "selected tickets no longer exist")}.`));
      const fx = preview.effects || {};
      if (fx.attachments > 0) panel.appendChild(make("p", "delete-note", `${plural(fx.attachments, "attachment file", "attachment files")} will be removed from storage.`));
      if (fx.mail_unsent > 0) panel.appendChild(make("p", "delete-note", `${plural(fx.mail_unsent, "queued email", "queued emails")} will be cancelled.`));
      if (fx.open_elsewhere > 0) panel.appendChild(make("p", "delete-error", `${plural(fx.open_elsewhere, "of these tickets is", "of these tickets are")} open for someone else right now. Their unsaved notes and running timers there will be lost.`));
      panel.appendChild(make("p", "delete-note", "If Milepost raises an alert again later, it opens a new ticket. A customer who replies to an old email about a deleted ticket starts a new ticket."));
      const timer = readTimer();
      if (timer && (preview.deletable_ids || []).includes(Number(timer.ticketId))) {
        panel.appendChild(make("p", "delete-error", `Your timer on #${timer.ticketId} hasn't been logged yet. Once #${timer.ticketId} is deleted that time can't be logged, so stop and log it first.`));
      }
      const label = make("label", "delete-confirm");
      label.appendChild(document.createTextNode(`Type ${preview.phrase} to confirm`));
      const input = make("input");
      input.type = "text";
      input.autocomplete = "off";
      input.spellcheck = false;
      input.setAttribute("autocapitalize", "characters");
      label.appendChild(input);
      panel.appendChild(label);
      const go = button(`Delete ${plural(d, "ticket", "tickets")}`, "btn-danger", () => {
        if (input.value.trim() !== preview.phrase) return;
        typed = input.value.trim();
        run();
      });
      go.disabled = true;
      input.addEventListener("input", () => { go.disabled = input.value.trim() !== preview.phrase; });
      input.addEventListener("keydown", (e) => { if (e.key === "Enter" && !go.disabled) { e.preventDefault(); go.click(); } });
      actions(button("Cancel", "btn-link", () => close(false)), go);
      input.focus();
    }

    function showRunning() {
      screen("Deleting tickets…", "Keep this tab open until it finishes.");
      const status = make("p", "", `${deleted.toLocaleString()} of ${preview.deletable.toLocaleString()} deleted`);
      status.setAttribute("aria-live", "polite");
      const bar = make("progress");
      bar.max = Math.max(1, preview.deletable);
      bar.value = deleted;
      bar.setAttribute("aria-label", "Delete progress");
      panel.appendChild(bar);
      panel.appendChild(status);
      const stop = button("Stop after this batch", "btn-link", () => {
        if (stopRequested) return;
        stopRequested = true;
        stop.setAttribute("aria-disabled", "true");
        stop.textContent = "Stopping after this batch…";
      });
      actions(stop);
      stop.focus();
      return { status, bar };
    }

    function showResult(stopped) {
      screen(stopped
        ? `Stopped. Deleted ${deleted.toLocaleString()} of ${preview.deletable.toLocaleString()}; the rest were not touched.`
        : `Deleted ${plural(deleted, "ticket", "tickets")}.`);
      // Kept: what the preview already kept, plus anything that picked up protected history mid-run.
      const entries = (preview.kept_sample || []).map((t) => [t.id, t.reason]);
      const tally = Object.assign({}, preview.kept_by_reason || {});
      Array.from(keptLater.entries()).sort((a, b) => a[0] - b[0]).forEach(([id, reason]) => {
        entries.push([id, reason]);
        tally[reason] = (tally[reason] || 0) + 1;
      });
      const totalKept = preview.kept + keptLater.size;
      if (totalKept > 0) keptList(`Kept ${totalKept.toLocaleString()}:`, entries, totalKept, tally);
      if (filesFailed > 0) panel.appendChild(make("p", "delete-error", `${plural(filesFailed, "attachment file", "attachment files")} could not be removed from the server; their names are in the server log.`));
      const done = button("Done", "btn-primary btn-sm", () => close(true));
      actions(done);
      done.focus();
    }

    function showError(error, canRetry) {
      let message;
      if (error && error.status === 419) { message = "Your session expired. Reload the page and try again."; canRetry = false; }
      else if (error && error.code === "preview_expired") { message = "This confirmation expired. Close and press Delete… again."; canRetry = false; }
      else if (error && error.fromServer) message = error.message;
      else {
        // No answer from the server: the batch may or may not have committed. Trying again is safe,
        // because the run carries on from the last confirmed batch and skips tickets already gone.
        message = "The connection dropped before the last batch answered, so it may or may not have finished. Try again carries on safely.";
      }
      screen("Delete stopped");
      panel.appendChild(make("p", "delete-error", message));
      if (deleted > 0) panel.appendChild(make("p", "", `${plural(deleted, "ticket was", "tickets were")} deleted before this.`));
      const buttons = [button("Close", "btn-link", () => close(deleted > 0))];
      if (canRetry) buttons.push(button("Try again", "btn-danger", () => run()));
      actions(...buttons);
      buttons[buttons.length - 1].focus();
    }

    async function run() {
      running = true;
      stopRequested = false;
      const view = showRunning();
      try {
        for (;;) {
          const r = await api(BULK_URL, {
            action: "delete", scope: { ids: preview.deletable_ids },
            after_id: afterId, run_key: preview.run_key, confirm: typed,
          });
          // The server counts the run from its own records, so a batch whose answer was lost still counts.
          deleted = r.run_deleted == null ? deleted + (Number(r.deleted) || 0) : Number(r.run_deleted) || 0;
          filesFailed += Number(r.files_failed) || 0;
          (r.kept || []).forEach((k) => keptLater.set(Number(k.id), String(k.reason)));
          if (!r.done && !(Number(r.next_after_id) > afterId)) throw new Error("The delete stopped making progress.");
          afterId = Number(r.next_after_id);
          view.bar.value = deleted;
          view.status.textContent = `${deleted.toLocaleString()} of ${preview.deletable.toLocaleString()} deleted`;
          if (r.done) { running = false; showResult(false); return; }
          if (stopRequested) { running = false; showResult(true); return; }
        }
      } catch (error) {
        running = false;
        showError(error, true);
      }
    }

    screen("Checking what can be deleted…");
    api(BULK_URL, { action: "preview", scope: { ids } })
      .then((p) => { preview = p; showConfirm(); })
      .catch((error) => showError(error, false));
  }

  /* ------------------------------------------------------------------ */
  /* Palette                                                             */
  /* ------------------------------------------------------------------ */
  let paletteOpen = false;
  let palIndex = 0;
  let palItems = [];

  // A page can leave out either list, or hand over nothing usable: the rest of the palette still works.
  function paletteData() {
    const el = $("#palette-data");
    let data = null;
    if (el) { try { data = JSON.parse(el.textContent); } catch { /* no lists to offer */ } }
    return {
      tickets: Array.isArray(data?.tickets) ? data.tickets : [],
      clients: Array.isArray(data?.clients) ? data.clients : [],
    };
  }

  // Lower scores rank first and null means no match: the prefix bonus takes a real match below zero.
  function fuzzy(query, text) {
    const q = query.toLowerCase(), t = text.toLowerCase();
    if (!q) return 0;
    let ti = 0, score = 0;
    for (const ch of q) {
      const found = t.indexOf(ch, ti);
      if (found === -1) return null;
      score += found - ti;
      ti = found + 1;
    }
    return score + (t.startsWith(q) ? -10 : 0);
  }

  let serverResults = [];
  let serverTimer = null;
  function scheduleServerSearch(q) {
    if (serverTimer) clearTimeout(serverTimer);
    // Rows belong to the query that found them: "#700"'s must not open on "#7002".
    serverResults = [];
    q = q.trim();
    // Text needs 3 characters; a ticket's number can be shorter.
    if (q.length < 3 && !/^#?[1-9]\d*$/.test(q)) return;
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

  // The ticket the "This ticket" rows act on: the one that is open, or the queue row the keyboard
  // has highlighted (shown, so the person can see which). Null anywhere else.
  function paletteTicket() {
    const thread = $("#thread");
    if (thread && thread.dataset.ticketId) {
      return { id: thread.dataset.ticketId, row: null, group: "This ticket", status: thread.dataset.status || "" };
    }
    const row = page === "queue" && selVisible() ? rows[sel] : null;
    if (row && row.dataset.ticketId) {
      return { id: row.dataset.ticketId, row, group: `Ticket #${row.dataset.ticketId}, highlighted`, status: row.dataset.status || "" };
    }
    return null;
  }

  // What can be done to that ticket. Each row does what its key does: the three "change" rows open
  // the same menus as S, T and P.
  function ticketPaletteItems(ticket) {
    const { id, row, group } = ticket;
    const act = (title, keys, run, extra = {}) => ({ group, kind: "action", title, keys, ...extra, run: () => { closePalette(); run(); } });
    const items = [];
    if (row) items.push(act("Open", ["↵"], () => nav(row.getAttribute("href"))));
    items.push(
      act("Change status", ["s"], () => openFieldMenu("status", id, row)),
      act("Assign to", ["t"], () => openFieldMenu("assignee", id, row), { find: ["Assign to", "owner teammate give"] }),
      act("Set priority", ["p"], () => openFieldMenu("priority", id, row)),
    );
    const timer = readTimer();
    if (timer && String(timer.ticketId) === String(id)) {
      items.push(act(timer.state === "active" ? "Stop timer and log time" : "Retry logging time", ["e"], stopTimer));
    } else {
      items.push(act("Start timer", ["e"], () => startTimer(id)));
    }
    if (!row && composer) {
      items.push(act("Reply", ["r"], () => composer.focus("reply")), act("Add internal note", ["n"], () => composer.focus("note")));
      // "Send and set the next status" is here as well as in the Send menu.
      composer.sendChoices().slice().reverse().forEach((choice) => items.push(act(choice.title, [], () => composer.send(choice.after), { hint: choice.detail })));
    }
    const merge = row ? null : $("#merge-btn");
    if (merge) items.push(act("Merge into another ticket", [], () => merge.click()));
    // Resolve is the Resolve button's own act: a person chose it, so the same survey rule applies.
    if (row || $("#resolve-btn")) {
      const resolved = ticket.status === "resolved";
      items.push(act(resolved ? "Reopen" : "Resolve", [], () => ticketAction(id, "status", resolved ? "open" : "resolved", { chosen: true })));
    }
    items.push(act("Copy link", [], () => copyText(`${window.location.origin}/ticket.php?id=${id}`, "Link copied.")));
    return items;
  }

  // Create and Go to: the same places and keys on every page.
  function staticPaletteItems() {
    // A place is found by its name ("Reports") and by "Go to Reports".
    const go = (title, href, keys, ...more) => ({ group: "Go to", kind: "action", title, keys, find: [title, `Go to ${title}`, ...more], run: () => nav(href) });
    const items = [
      { group: "Create", kind: "action", title: "New ticket", keys: ["c"], run: () => nav("/ticket_new.php") },
      { group: "Create", kind: "action", title: "New client", keys: [], run: () => nav("/client_new.php") },
      { group: "Create", kind: "action", title: "New saved reply", keys: [], run: () => nav("/snippets.php") },
      go("Queue", "/", ["g", "q"]),
      go("Time", "/time.php", ["g", "t"]),
      go("Clients", "/clients.php", ["g", "c"]),
      go("Service goals", "/service_goals.php", ["g", "s"]),
      go("Reports", "/reports.php", ["g", "r"]),
      go("Team", "/users.php", ["g", "u"]),
      go("Saved replies", "/snippets.php", [], "manage snippets"),
    ];
    // My settings lives where the account menu says it does (8 West ID for a suite account).
    const settings = $('a[aria-label="My settings"]');
    if (settings) items.push(go("My settings", settings.getAttribute("href"), []));
    items.push({ group: "Go to", kind: "action", title: "Keyboard shortcuts", keys: ["?"], run: () => { closePalette(); toggleHelp(); } });
    items.push({ group: "Account", kind: "action", title: "Sign out", keys: [], run: signOut });
    return items;
  }

  // A status as a person reads it ("In progress"), from the labels the menus use.
  function statusWord(status) {
    const known = menuChoices("status").find((choice) => choice.value === status);
    return known ? known.label : String(status).replace(/_/g, " ").replace(/^./, (letter) => letter.toUpperCase());
  }

  function buildPaletteItems(query) {
    const data = paletteData();
    const ticket = paletteTicket();
    const actions = [...(ticket ? ticketPaletteItems(ticket) : []), ...staticPaletteItems()];
    const tickets = data.tickets.map((t) => ({
      group: "Tickets",
      kind: "ticket",
      ticketId: t.id,
      title: `#${t.id} ${t.subject}`,
      hint: t.client,
      run: () => nav(`/ticket.php?id=${t.id}`),
    }));
    const clients = data.clients.map((c) => ({
      group: "Clients",
      kind: "client",
      client: c,
      title: c.name,
      hint: c.domain,
      run: () => nav(`/client.php?id=${c.id}`),
    }));
    const all = [...actions, ...tickets, ...clients];
    if (!query.trim()) return all;
    // A row is found by its words, or by any of the other words it answers to; its best match counts.
    const best = (item) => (item.find || [`${item.title} ${item.hint || ""}`])
      .map((text) => fuzzy(query, text)).filter((score) => score !== null).reduce((low, score) => (low === null || score < low ? score : low), null);
    const filtered = all
      .map((item) => ({ item, score: best(item) }))
      .filter((x) => x.score !== null)
      .sort((a, b) => a.score - b.score)
      .map((x) => x.item);
    // A client that was typed: a ticket for them is the row right under their name.
    const found = filtered.findIndex((item) => item.kind === "client");
    if (found !== -1) {
      const client = filtered[found].client;
      filtered.splice(found + 1, 0, {
        group: "Clients", kind: "client", title: `New ticket for ${client.name}`, keys: [],
        run: () => nav(`/ticket_new.php?client=${client.id}`),
      });
    }
    // Server results (bodies + resolved) ride below, already matched server-side. Only tickets the local filter
    // showed are left out: a local ticket found by a word in its messages has no other row.
    const shownIds = new Set(filtered.filter((item) => item.ticketId !== undefined).map((item) => item.ticketId));
    const deep = serverResults
      .filter((r) => !shownIds.has(r.id))
      .map((r) => ({
        group: "More tickets",
        kind: "ticket",
        title: `#${r.id} ${r.subject}`,
        hint: `${r.client}, ${statusWord(r.status)}`,
        run: () => nav(`/ticket.php?id=${r.id}`),
      }));
    return [...filtered, ...deep];
  }

  function nav(url) {
    closePalette();
    window.location.href = url;
  }

  /* Signing out takes a post with the page's token, like the account menu's button. requestSubmit
     fires the submit event, which is how Westy knows to clear its transcript. */
  function signOut() {
    closePalette();
    const form = document.createElement("form");
    form.method = "post";
    form.action = "/logout.php";
    form.hidden = true;
    const token = document.createElement("input");
    token.type = "hidden";
    token.name = "csrf";
    token.value = document.body.dataset.csrf || "";
    form.appendChild(token);
    document.body.appendChild(form);
    form.requestSubmit();
  }

  function renderPaletteList(query) {
    palItems = buildPaletteItems(query);
    palIndex = Math.min(palIndex, Math.max(0, palItems.length - 1));
    const list = $("#palette-list");
    if (!list) return;
    list.replaceChildren();
    if (!palItems.length) {
      list.append(make("div", "palette-empty", `No ticket, client or action matches “${query.trim()}”.`));
      return;
    }
    let lastGroup = "";
    palItems.forEach((item, i) => {
      if (item.group !== lastGroup) {
        lastGroup = item.group;
        list.append(make("div", "palette-group", item.group));
      }
      const btn = make("button", "palette-item" + (i === palIndex ? " sel" : ""));
      btn.type = "button";
      btn.append(make("span", `palette-dot palette-dot-${item.kind || "action"}`), make("span", "palette-item-main", item.title));
      if (item.hint) btn.append(make("span", "palette-item-hint", item.hint));
      // The key that does the same thing without the palette, where there is one.
      if (item.keys && item.keys.length) {
        const keys = make("span", "palette-item-keys");
        item.keys.forEach((name) => keys.append(make("kbd", "kbd", name)));
        btn.append(keys);
      }
      btn.addEventListener("click", () => item.run());
      btn.addEventListener("mousemove", () => { palIndex = i; paintPaletteSel(); });
      list.append(btn);
    });
  }

  function paintPaletteSel() {
    $$(".palette-item").forEach((el, i) => el.classList.toggle("sel", i === palIndex));
    const selEl = $$(".palette-item")[palIndex];
    if (selEl) selEl.scrollIntoView({ block: "nearest" });
  }

  // A page without a palette (the sign-in page) never counts one as open: an open palette owns Enter and the arrows.
  const paletteRoot = () => $("#palette-root");

  function openPalette() {
    if (paletteOpen) return;
    const root = paletteRoot();
    if (!root) return;
    paletteOpen = true;
    palIndex = 0;
    root.innerHTML = `
      <div class="palette-overlay">
        <div class="palette" role="dialog" aria-label="Command palette">
          <div class="palette-input-row">
            <svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="7" cy="7" r="4.5"/><path d="m10.5 10.5 3 3" stroke-linecap="round"/></svg>
            <input class="palette-input" placeholder="Search tickets, clients and actions" aria-label="Search tickets, clients and actions" autocomplete="off">
            <kbd class="kbd">esc</kbd>
          </div>
          <div class="palette-list" id="palette-list"></div>
          <div class="palette-foot">
            <span><kbd class="kbd">↑</kbd><kbd class="kbd">↓</kbd> move</span><span><kbd class="kbd">↵</kbd> choose</span>
            <span class="right"><kbd class="kbd">?</kbd> every shortcut</span>
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
  const shortcuts = $("#shortcuts-open");
  if (shortcuts) shortcuts.addEventListener("click", () => toggleHelp());

  /* ------------------------------------------------------------------ */
  /* Keyboard model                                                      */
  /* ------------------------------------------------------------------ */
  // A focused bulk-select checkbox is not typing: j/k/s/p/a/e keep working after a click on one.
  const isTyping = (el) =>
    el && !(el.classList && (el.classList.contains("bulk-pick") || el.id === "bulk-all"))
    && (el.tagName === "INPUT" || el.tagName === "TEXTAREA" || el.tagName === "SELECT" || el.isContentEditable);

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

    // The bulk delete dialog owns the keyboard while it is open (its own Escape and Tab handling).
    // So does the merge dialog.
    if (bulkDialogOpen || mergeDialogOpen || formDialogOpen) return;

    // An open status, priority or owner menu owns the keyboard too; the palette shortcut closes it.
    if (menu) {
      if (mod && key === "k") closeMenu(false);
      else { menuKeydown(e); return; }
    }

    // Native Details sheet owns Tab and Escape; shortcuts must not act on the page behind it.
    if ($(".phone-details[open]")) return;

    if (helpOpen && key === "escape") { e.preventDefault(); toggleHelp(); return; }

    // Where there is no palette the shortcut is left to the browser.
    if (mod && key === "k") { if (!paletteRoot()) return; e.preventDefault(); paletteOpen ? closePalette() : openPalette(); return; }

    // Ctrl/Cmd+Enter sends the reply (works on any page where the box exists). It is a plain send
    // whatever else is held: setting a status with the send is a choice made in the Send menu or
    // the palette, never a chord (Ctrl+Alt is the AltGr key on many keyboards).
    if (mod && key === "enter") {
      const form = $("#reply-form");
      if (form && isTyping(e.target)) { e.preventDefault(); form.requestSubmit(); return; }
      // On the New ticket form it creates the ticket, from any of its fields.
      const create = $("#ticket-new-form");
      if (create && create.contains(e.target)) { e.preventDefault(); create.requestSubmit($("#call-create", create)); }
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
      else if (key === "r") { e.preventDefault(); nav("/reports.php"); }
      else if (key === "u") { e.preventDefault(); nav("/users.php"); }
      return;
    }
    if (key === "g") { pendingG = true; setTimeout(() => (pendingG = false), 900); return; }

    const row = currentRow();
    const rowTicketId = row && row.dataset.ticketId;

    switch (key) {
      case "j":
      case "arrowdown":
        // The first press shows the highlight on the first row; after that it moves.
        if (rows.length) { e.preventDefault(); if (!showSel()) { sel = Math.min(sel + 1, rows.length - 1); paintSel(); } }
        break;
      case "k":
      case "arrowup":
        if (rows.length) { e.preventDefault(); if (!showSel()) { sel = Math.max(sel - 1, 0); paintSel(); } }
        break;
      case "enter":
      case "o":
        // Enter on a focused link, button or form control does what that control does; on a row's
        // checkbox it opens that row, which the highlight now follows.
        if (key === "enter" && e.target !== document.body && e.target.closest
          && !(e.target.classList && e.target.classList.contains("bulk-pick"))
          && e.target.closest("a[href], button, input, select, textarea, summary, label, [role='button']")) break;
        // The first press shows which row would open; the next one opens it.
        if (row) { e.preventDefault(); if (rowChosen()) row.click(); }
        break;
      case "escape":
        if (bulk && bulk.hasSelection()) { e.preventDefault(); bulk.clear(); }
        else if (bulk && bulk.inMode()) { e.preventDefault(); bulk.leaveMode(); }
        else if (page === "queue" && window.location.pathname !== "/") { e.preventDefault(); nav("/"); }
        break;
      case "x":
        if (bulk && row && bulk.toggle(row)) { e.preventDefault(); showSel(); }
        break;
      case "d":
        if (bulk && bulk.openForSelection()) e.preventDefault();
        break;
      case "s":
      case "p":
      case "a":
      case "t":
      case "e": {
        // The open ticket, or the highlighted queue row.
        const ticketPageId = $("#thread") ? $("#thread").dataset.ticketId : null;
        const id = ticketPageId || rowTicketId;
        if (!id) break;
        e.preventDefault();
        // On a list the first press only shows which row the key is about; the next press acts.
        if (!ticketPageId && !rowChosen()) break;
        if (key === "e") {
          const t = readTimer();
          if (t && String(t.ticketId) === String(id)) stopTimer();
          else startTimer(id);
          break;
        }
        // A assigns the ticket to me (again to unassign), in one key, as it always has.
        if (key === "a") { ticketAction(id, "assignee", "me"); break; }
        // S, P and T open a menu: status, priority, and who to give the ticket to. It opens under
        // its button on a ticket and under its cell on a queue row.
        openFieldMenu(key === "s" ? "status" : key === "p" ? "priority" : "assignee", id, ticketPageId ? null : row);
        break;
      }
      case "z": {
        if (undoable) { e.preventDefault(); undoable.run(); }
        break;
      }
      case "/": {
        // Search, from anywhere outside a text box (in the reply box, / is saved replies).
        if (paletteRoot()) { e.preventDefault(); openPalette(); }
        break;
      }
      case "u": {
        // Up: back to the list this page came from (a ticket's queue, a client's Clients).
        const back = $(".backlink");
        if (back) { e.preventDefault(); nav(back.getAttribute("href")); }
        break;
      }
      case "c": {
        // New ticket, from any signed-in page and only outside a text field (typing was ruled out
        // above). On a client's page it is a ticket for that client.
        if (!document.body.dataset.userId) break;
        const forClient = $("a[data-new-ticket]");
        e.preventDefault();
        nav(forClient ? forClient.getAttribute("href") : "/ticket_new.php");
        break;
      }
      case "[":
      case "]": {
        // The previous or next ticket in the list this one was opened from. Without a list, or at
        // either end of it, there is no such link and the key does nothing.
        const step = $(`a.ticket-step[data-step="${key === "[" ? "prev" : "next"}"]`);
        if (step) { e.preventDefault(); nav(step.getAttribute("href")); }
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
      case "1": case "2": case "3": case "4": case "5": case "6": {
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

  /* Details buttons (ticket page) open their menu; Westy's "Take over ticket" assigns to you. */
  document.addEventListener("click", async (event) => {
      const btn = event.target.closest(".rail-btn[data-action], .westy-workflow-actions [data-action]");
      if (!btn || btn.disabled) return;
      const action = btn.dataset.action;
      const id = btn.dataset.id;
      if (btn.classList.contains("menu-btn")) {
        if (menu && menu.trigger === btn) closeMenu();
        else openTicketMenu(action, id, btn, btn);
      } else if (action === "assignee") {
        // A takeover moves the ticket from Westy to a person for good, so it offers no Undo.
        btn.disabled = true;
        await ticketAction(id, "assignee", "me", { undoable: false });
        btn.disabled = false;
      }
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

  /* A small form in a dialog, for the Time page's Reject, Change and Correct. It takes the place
     of a chain of browser prompts: `build` adds labelled fields, `check` reads them and answers
     { value } or { error, field }. Resolves with that value, or null when it is cancelled. What
     is wrong is said inside the dialog, which stays open with everything typed. */
  function askForm(form) {
    const { opener, title, sub, submit } = form;
    return new Promise((resolve) => {
      if (formDialogOpen) { resolve(null); return; }
      formDialogOpen = true;
      const overlay = make("div", "palette-overlay");
      overlay.id = "form-overlay";
      const panel = make("form", "palette form-dialog");
      panel.setAttribute("role", "dialog");
      panel.setAttribute("aria-modal", "true");
      panel.setAttribute("aria-labelledby", "form-dialog-title");
      panel.noValidate = true;
      const heading = make("h2", "", title);
      heading.id = "form-dialog-title";
      panel.append(heading);
      if (sub) panel.append(make("p", "form-dialog-sub", sub));
      const fields = make("div", "form-dialog-fields");
      panel.append(fields);
      const problem = make("p", "form-dialog-error");
      problem.setAttribute("role", "alert");
      problem.hidden = true;
      const cancel = make("button", "btn-secondary", "Cancel");
      cancel.type = "button";
      const go = make("button", "btn-primary", submit);
      go.type = "submit";
      const actions = make("div", "delete-actions");
      actions.append(cancel, go);
      panel.append(problem, actions);
      overlay.append(panel);

      const done = (value) => {
        document.removeEventListener("keydown", onKey, true);
        overlay.remove();
        formDialogOpen = false;
        if (opener && opener.isConnected) opener.focus();
        resolve(value);
      };
      function onKey(e) {
        if (e.key === "Escape") { e.preventDefault(); e.stopPropagation(); done(null); return; }
        if (e.key === "Enter" && (e.ctrlKey || e.metaKey)) { e.preventDefault(); e.stopPropagation(); panel.requestSubmit(); return; }
        if (e.key !== "Tab") return;
        const focusable = $$("button:not([disabled]), input:not([disabled]), textarea:not([disabled])", panel);
        if (!focusable.length || !panel.contains(document.activeElement)) { e.preventDefault(); (focusable[0] || panel).focus(); return; }
        const first = focusable[0], last = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
      panel.addEventListener("submit", (e) => {
        e.preventDefault();
        const answer = form.check();
        if (answer && answer.error) {
          problem.textContent = answer.error;
          problem.hidden = false;
          if (answer.field) answer.field.focus();
          return;
        }
        done(answer.value);
      });
      cancel.addEventListener("click", () => done(null));
      // A press outside the dialog closes it, but not in its first moments: the second press of a
      // double click on the button that opened it lands out there.
      const openedAt = Date.now();
      overlay.addEventListener("mousedown", (e) => { if (e.target === overlay && Date.now() - openedAt > DIALOG_SETTLE_MS) done(null); });
      document.addEventListener("keydown", onKey, true);
      const first = form.build(fields);
      document.body.append(overlay);
      (first || cancel).focus();
      if (first && typeof first.select === "function") first.select();
    });
  }
  // One labelled field for such a dialog: the label, the control, and a line of help under it.
  function formField(fields, id, label, control, help) {
    const wrap = make("div", "field");
    const name = make("label", "", label);
    name.htmlFor = id;
    control.id = id;
    wrap.append(name, control);
    if (help) {
      const line = make("p", "field-help", help);
      line.id = id + "-help";
      control.setAttribute("aria-describedby", line.id);
      wrap.append(line);
    }
    fields.append(wrap);
    return control;
  }
  // Billable or not, as two choices; returns a function that says which is chosen.
  function formBillable(fields, name, billable) {
    const group = make("fieldset", "seg-field");
    group.append(make("legend", "", "Billable"));
    const row = make("div", "seg");
    [["1", "Billable"], ["0", "Not billable"]].forEach(([value, words]) => {
      const item = make("label", "seg-item");
      const radio = make("input");
      radio.type = "radio";
      radio.name = name;
      radio.value = value;
      radio.checked = (value === "1") === billable;
      item.append(radio, make("span", "", words));
      row.append(item);
    });
    group.append(row);
    fields.append(group);
    return () => $(`input[name="${name}"]:checked`, group).value === "1";
  }
  // The Safeharbor time-zone setting this page was drawn with: History's times are in it, so the
  // pickers are too. The computer's own zone is used only when the page names none the browser knows.
  function deskTimeZone() {
    const named = document.documentElement.dataset.timeZone || "";
    try {
      if (named) { new Intl.DateTimeFormat("en-US", { timeZone: named }); return named; }
    } catch { /* not a zone this browser knows */ }
    return Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC";
  }
  // What a zone's clocks showed at an instant, as a picker writes it: 2026-11-10T09:10:00.
  function zoneWall(milliseconds, zone) {
    const parts = {};
    new Intl.DateTimeFormat("en-US", { timeZone: zone, hourCycle: "h23", year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit", second: "2-digit" })
      .formatToParts(new Date(milliseconds)).forEach((part) => { parts[part.type] = part.value; });
    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}:${parts.second}`;
  }
  function utcToZoneInput(text, zone) {
    const parsed = parseCorrectionUtc(text);
    return parsed ? zoneWall(parsed.milliseconds, zone) : "";
  }
  // Every instant at which a zone's clocks showed a picked time, earliest first: one normally, two
  // in the hour that repeats when the clocks go back, none in the hour they skip going forward.
  // Null when nothing usable was picked.
  function zoneInstants(value, zone) {
    const picked = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(value || "");
    if (!picked) return null;
    const wall = `${picked[1]}-${picked[2]}-${picked[3]}T${picked[4]}:${picked[5]}:${picked[6] || "00"}`;
    const asUtc = Date.parse(wall + "Z");
    if (!Number.isFinite(asUtc)) return null;
    const found = new Set();
    // The zone's offset a day before, then, and a day after covers both sides of any clock change.
    [asUtc - 86400000, asUtc, asUtc + 86400000].forEach((near) => {
      const offset = Date.parse(zoneWall(near, zone) + "Z") - near;
      if (zoneWall(asUtc - offset, zone) === wall) found.add(asUtc - offset);
    });
    return [...found].sort((a, b) => a - b)
      .map((milliseconds) => parseCorrectionUtc(new Date(milliseconds).toISOString().replace(/\.\d{3}Z$/, "Z")))
      .filter(Boolean);
  }

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
        const facts = await askForm({
          opener: button,
          title: "Correct and resubmit",
          sub: "The rejected entry stays as it is. This sends one new entry in its place, for review.",
          submit: "Send corrected entry",
          build(fields) {
            const controls = {};
            if (measured) {
              const pair = make("div", "form-dialog-pair");
              fields.append(pair);
              const zone = deskTimeZone();
              controls.zone = zone;
              const picker = (value) => { const input = make("input"); input.type = "datetime-local"; input.step = "1"; input.value = value; return input; };
              controls.start = formField(pair, "correct-start", "Start", picker(utcToZoneInput(button.dataset.startedAt || "", zone)));
              controls.end = formField(pair, "correct-end", "End", picker(utcToZoneInput(button.dataset.endedAt || "", zone)));
              fields.append(make("p", "field-help", `Times are in ${zone.replace(/_/g, " ")} time, as History shows them. The minutes are worked out from the start and the end.`));
              // On the night the clocks go back an hour happens twice. A time picked in that hour
              // is asked about here, never guessed: the two are an hour of work apart.
              controls.twice = {};
              [["start", "start"], ["end", "end"]].forEach(([which, word]) => {
                const group = make("fieldset", "seg-field");
                group.id = `correct-${which}-twice`;
                group.hidden = true;
                group.append(make("legend", "", `That ${word} time happened twice. Which one?`));
                const row = make("div", "seg");
                [["first", "The first, before the clocks went back"], ["second", "The second, after they went back"]].forEach(([value, words]) => {
                  const item = make("label", "seg-item");
                  const radio = make("input");
                  radio.type = "radio";
                  radio.name = `correct-${which}-twice`;
                  radio.value = value;
                  item.append(radio, make("span", "", words));
                  row.append(item);
                });
                group.append(row);
                fields.append(group);
                controls.twice[which] = group;
              });
            } else {
              const minutes = make("input");
              minutes.type = "text";
              minutes.inputMode = "numeric";
              minutes.autocomplete = "off";
              minutes.value = button.dataset.minutes || "";
              controls.minutes = formField(fields, "correct-minutes", "Minutes", minutes, "A whole number from 1 to 1440.");
            }
            const note = make("input");
            note.type = "text";
            note.autocomplete = "off";
            note.value = button.dataset.note || "";
            controls.note = formField(fields, "correct-note", "What the work was", note);
            controls.billable = formBillable(fields, "correct-billable", button.dataset.billable === "1");
            this.controls = controls;
            return controls.start || controls.minutes;
          },
          check() {
            const controls = this.controls;
            const out = { minutes: Number(button.dataset.minutes), workedAt: button.dataset.workedAt, startedAt: button.dataset.startedAt || null, endedAt: button.dataset.endedAt || null };
            if (measured) {
              // One picked time as an instant, or what to say when it is not exactly one.
              const instantOf = (which) => {
                const control = controls[which];
                const group = controls.twice[which];
                const instants = zoneInstants(control.value, controls.zone);
                if (!instants || instants.length !== 2) {
                  group.hidden = true;
                  $$("input", group).forEach((radio) => { radio.checked = false; });
                }
                if (!instants) return { error: `Pick the ${which} date and time.`, field: control };
                if (instants.length === 0) {
                  return { error: `That ${which} time does not exist: the clocks went forward that night and skipped it. Pick another time.`, field: control };
                }
                if (instants.length === 1) return { instant: instants[0] };
                group.hidden = false;
                const chosen = $("input:checked", group);
                if (!chosen) {
                  return { error: `That ${which} time happened twice, because the clocks went back that night. Say which one you mean.`, field: $("input", group) };
                }
                return { instant: instants[chosen.value === "second" ? 1 : 0] };
              };
              const startAnswer = instantOf("start");
              const endAnswer = instantOf("end");
              if (startAnswer.error) return startAnswer;
              if (endAnswer.error) return endAnswer;
              const correctedStart = startAnswer.instant;
              const correctedEnd = endAnswer.instant;
              const duration = correctedEnd.milliseconds - correctedStart.milliseconds;
              if (duration <= 0 || duration > 86400000) {
                return { error: "The end has to be after the start, and no more than 24 hours after it.", field: controls.end };
              }
              // The time the work is dated at stays as it was while it is still inside the corrected
              // start and end; otherwise it is the corrected end.
              const worked = parseCorrectionUtc(out.workedAt);
              out.startedAt = correctedStart.text;
              out.endedAt = correctedEnd.text;
              out.workedAt = worked && worked.milliseconds >= correctedStart.milliseconds && worked.milliseconds <= correctedEnd.milliseconds
                ? worked.text : correctedEnd.text;
              out.minutes = Math.max(1, Math.round(duration / 60000));
            } else {
              // Digits only, as in the Change dialog: Number() alone would read "1e2" as 100 and "0x1e" as 30.
              const typed = controls.minutes.value.trim();
              out.minutes = /^[1-9][0-9]*$/.test(typed) ? Number(typed) : NaN;
              if (!Number.isInteger(out.minutes) || out.minutes < 1 || out.minutes > 1440) {
                return { error: "Minutes must be a whole number from 1 to 1440.", field: controls.minutes };
              }
            }
            out.note = controls.note.value;
            if (Array.from(out.note).length > 255) return { error: "Keep the description to 255 characters or fewer.", field: controls.note };
            out.billable = controls.billable();
            return { value: out };
          },
        });
        if (facts === null) return;
        const { minutes, workedAt, startedAt, endedAt, note } = facts;
        const billingChoice = facts.billable ? "B" : "I";
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
  function reviewAckMatches(response, payload) {
    const ack = response && response.review_ack && typeof response.review_ack === "object"
      && !Array.isArray(response.review_ack)
      ? response.review_ack
      : null;
    if (response?.ok !== true || !ack || typeof ack.replayed !== "boolean") return false;
    if (!Number.isInteger(ack.entry_id) || ack.entry_id !== payload.entry_id) return false;
    if (typeof ack.decision !== "string" || ack.decision !== payload.decision) return false;
    if (typeof ack.note !== "string" || ack.note !== payload.note) return false;
    return Number.isInteger(ack.reviewer_user_id)
      && ack.reviewer_user_id === Number(timerUserId);
  }

  $$(".review-row").forEach((row) => {
    const controls = $$(".time-review", row);
    let frozenPayload = null;
    controls.forEach((button) => {
      button.addEventListener("click", async () => {
        const selectedDecision = String(button.dataset.decision || "");
        if (frozenPayload && selectedDecision !== frozenPayload.decision) {
          toast("Retry the same review decision, or refresh to reconcile it.");
          return;
        }

        if (!frozenPayload) {
          let note = "";
          if (selectedDecision === "rejected") {
            const answer = await askForm({
              opener: button,
              title: "Reject this time entry",
              sub: "The entry is kept as rejected, and the technician can send a corrected one.",
              submit: "Reject entry",
              build(fields) {
                const area = make("textarea");
                area.rows = 3;
                this.area = formField(fields, "reject-note", "Why? The technician sees this.", area);
                return this.area;
              },
              check() {
                const text = this.area.value.trim();
                if (!text) return { error: "Say why, so the technician can correct it.", field: this.area };
                if (Array.from(text).length > 500) return { error: "Keep the note to 500 characters or fewer.", field: this.area };
                return { value: text };
              },
            });
            if (answer === null) return;
            note = answer;
          }
          // Once sent, uncertain failures must replay these exact normalized
          // facts. A different decision is unsafe until the server reconciles.
          frozenPayload = Object.freeze({
            entry_id: Number(row.dataset.timeEntryId),
            decision: selectedDecision,
            note,
          });
        }

        controls.forEach((control) => { control.disabled = true; });
        try {
          const r = await api("/api/time_entry_review.php", frozenPayload);
          if (!reviewAckMatches(r, frozenPayload)) {
            throw new Error("Review acknowledgement did not match.");
          }
          toast(r.toast || (frozenPayload.decision === "approved" ? "Time approved." : "Time rejected."));
          row.remove();
          const queue = $("#time-review-queue");
          if (queue && !$(".review-row", queue)) {
            queue.innerHTML = '<div class="empty"><p>No time is waiting for review.</p></div>';
          }
        } catch (error) {
          const status = error instanceof Error ? Number(error.status || 0) : 0;
          if (status === 409) {
            toast("Review state changed on the server — refreshing.");
            setTimeout(() => location.reload(), 250);
            return;
          }
          if (status === 400 || status === 422) {
            const message = error instanceof Error && error.message
              ? error.message
              : "Review was not saved.";
            frozenPayload = null;
            controls.forEach((control) => { control.disabled = false; });
            toast(message + " Review was not saved; edit and try again.");
            return;
          }
          if (status === 401 || status === 403 || status === 404) {
            const message = error instanceof Error && error.message
              ? error.message
              : "Review access or entry changed.";
            toast(message + " Refreshing to reconcile.");
            setTimeout(() => location.reload(), 250);
            return;
          }
          toast("Review was not confirmed — retry the same decision, or refresh.");
          controls.forEach((control) => {
            control.disabled = control.dataset.decision !== frozenPayload.decision;
          });
        }
      });
    });
  });

  /* approved entries stay immutable; owner/admin changes append a version */
  function adjustmentAckMatches(response, payload) {
    const ack = response && response.adjustment_ack && typeof response.adjustment_ack === "object"
      && !Array.isArray(response.adjustment_ack)
      ? response.adjustment_ack
      : null;
    if (response?.ok !== true || !ack || typeof ack.replayed !== "boolean") return false;
    if (typeof ack.adjustment_key !== "string" || ack.adjustment_key !== payload.adjustment_key) return false;
    if (!Number.isInteger(ack.entry_id) || ack.entry_id !== payload.entry_id) return false;
    if (!Number.isInteger(ack.version_no) || ack.version_no !== payload.expected_version + 1) return false;
    if (!Number.isInteger(ack.effective_minutes) || ack.effective_minutes !== payload.effective_minutes) return false;
    if (typeof ack.effective_billable !== "boolean"
        || ack.effective_billable !== payload.effective_billable) return false;
    if (!Number.isInteger(ack.adjusted_by_user_id)
        || ack.adjusted_by_user_id !== Number(timerUserId)) return false;
    if (typeof ack.reason !== "string" || ack.reason !== payload.reason) return false;
    return true;
  }

  $$(".time-adjust").forEach((button) => {
    let frozenPayload = null;
    button.addEventListener("click", async () => {
      if (!frozenPayload) {
        const entryId = Number(button.dataset.entryId);
        const originalMinutes = Number(button.dataset.originalMinutes);
        const expectedVersion = Number(button.dataset.version);
        const currentMinutes = Number(button.dataset.effectiveMinutes);
        const originalBillable = button.dataset.originalBillable === "1";
        const currentBillable = button.dataset.effectiveBillable === "1";
        if (!Number.isInteger(entryId) || entryId < 1
            || !Number.isInteger(originalMinutes) || originalMinutes < 1 || originalMinutes > 1440
            || !Number.isInteger(expectedVersion) || expectedVersion < 0
            || !Number.isInteger(currentMinutes) || currentMinutes < 0 || currentMinutes > originalMinutes) {
          button.disabled = true;
          toast("Approved-time evidence changed unexpectedly. Refresh to reconcile it.");
          return;
        }

        const change = await askForm({
          opener: button,
          title: "Change approved time",
          sub: "The original approval is kept. This adds a change on top of it, with your reason.",
          submit: "Save change",
          build(fields) {
            const minutes = make("input");
            minutes.type = "text";
            minutes.inputMode = "numeric";
            minutes.autocomplete = "off";
            minutes.value = String(currentMinutes);
            this.minutes = formField(fields, "change-minutes", "Minutes", minutes,
              `From 0 to ${originalMinutes}, the minutes that were approved. 0 cancels this approval. To add time, log a new entry.`);
            if (originalBillable) this.billable = formBillable(fields, "change-billable", currentBillable);
            else fields.append(make("p", "field-help", "This time was approved as not billable, so it stays not billable."));
            const reason = make("textarea");
            reason.rows = 3;
            this.reason = formField(fields, "change-reason", "Why is it changing?", reason);
            return this.minutes;
          },
          check() {
            const normalizedMinutes = this.minutes.value.trim();
            if (!/^(?:0|[1-9][0-9]*)$/.test(normalizedMinutes)) {
              return { error: `Minutes must be a whole number from 0 to ${originalMinutes}.`, field: this.minutes };
            }
            const effectiveMinutes = Number(normalizedMinutes);
            if (!Number.isInteger(effectiveMinutes) || effectiveMinutes > originalMinutes) {
              return { error: "A change cannot add time. Log extra work as a new entry.", field: this.minutes };
            }
            // Billable is a choice only for time that was approved as billable and still has minutes.
            const effectiveBillable = effectiveMinutes > 0 && originalBillable ? this.billable() : false;
            const reason = this.reason.value.trim();
            if (!reason) return { error: "Say why it is changing.", field: this.reason };
            if (Array.from(reason).length > 500) return { error: "Keep the reason to 500 characters or fewer.", field: this.reason };
            return { value: { effectiveMinutes, effectiveBillable, reason } };
          },
        });
        if (change === null) return;
        const { effectiveMinutes, effectiveBillable, reason } = change;

        const adjustmentKey = newAdjustmentKey();
        if (!adjustmentKey) {
          toast("This browser cannot safely create an adjustment key. Refresh or use a current browser.");
          return;
        }
        frozenPayload = Object.freeze({
          entry_id: entryId,
          adjustment_key: adjustmentKey,
          expected_version: expectedVersion,
          effective_minutes: effectiveMinutes,
          effective_billable: effectiveBillable,
          reason,
        });
        // Freeze the exact cryptographic key, prior version, and effective
        // facts before sending. A lost success can only retry this payload.
        button.dataset.adjustmentPayload = JSON.stringify(frozenPayload);
      }

      button.disabled = true;
      try {
        const response = await api("/api/time_entry_adjustment.php", frozenPayload);
        if (!adjustmentAckMatches(response, frozenPayload)) {
          throw new Error("Adjustment acknowledgement did not match.");
        }
        toast(response.toast || "Approved time adjustment saved.");
        setTimeout(() => location.reload(), 600);
      } catch (error) {
        const status = error instanceof Error ? Number(error.status || 0) : 0;
        const message = error instanceof Error && error.message
          ? error.message
          : "Approved time adjustment was not saved.";
        if (status === 400 || status === 422) {
          frozenPayload = null;
          button.dataset.adjustmentPayload = "";
          button.disabled = false;
          toast(message + " Original approval is unchanged; edit and try again.");
          return;
        }
        if (status === 401 || status === 403 || status === 404 || status === 409) {
          toast(message + " Refreshing to reconcile the approved entry.");
          setTimeout(() => location.reload(), 250);
          return;
        }
        button.disabled = false;
        toast("Adjustment was not confirmed — retry this exact adjustment, or refresh.");
      }
    });
  });

  /* ------------------------------------------------------------------ */
  /* The existing approved-time connection, operated from the Time page. */
  $$(".time-billing").forEach(button => {
    button.addEventListener("click", async () => {
      const originalLabel = button.textContent;
      button.disabled = true;
      button.textContent = button.dataset.billingAction === "status" ? "Checking…" : "Sending…";
      try {
        const result = await api("/api/time_entry_billing.php", {
          entry_id: Number(button.dataset.entryId),
          action: button.dataset.billingAction,
        });
        toast(result.toast || "Billing updated.");
        location.reload();
      } catch (error) {
        toast(error.message || "Billing could not be confirmed. Refresh to check its status.");
        button.textContent = originalLabel;
        button.disabled = false;
        // Reload after any failed request so an interrupted delivery offers status,
        // rather than a second send based on the previous page state.
        setTimeout(() => location.reload(), 1500);
      }
    });
  });

  /* Composer (ticket page): reply/note tabs, / saved replies, time chip  */
  /* ------------------------------------------------------------------ */
  const composer = (() => {
    const form = $("#reply-form");
    const box = $("#reply-box");
    const modeInput = $("#composer-mode");
    if (!form || !box || !modeInput) return null;

    const hint = $("#composer-hint");
    const send = $("#composer-send");
    const sendMore = $("#composer-send-more");
    const sendMenu = $("#send-menu");
    const tabs = $$(".composer-tab");
    const ticketId = $("#thread") ? $("#thread").dataset.ticketId : null;
    const replyHint = hint?.textContent || "Replies are saved on this ticket.";
    const sendLabel = send?.textContent || "Send";
    const PLACEHOLDERS = {
      reply: box.dataset.placeholderReply || box.placeholder,
      note: box.dataset.placeholderNote || "Write an internal note",
    };

    /* The sentence beside Send: who gets a reply and how (rendered by the server for this ticket),
       plus what Send does to the status as it stands now. A note says who can read it. */
    function paintHint() {
      if (!hint) return;
      if (modeInput.value === "note") { hint.textContent = hint.dataset.note || "Only your team sees an internal note."; return; }
      if (hint.dataset.audience === undefined) { hint.textContent = replyHint; return; }
      const moves = currentFieldValue("status", ticketId) === "open" && hint.dataset.openMove;
      hint.textContent = hint.dataset.audience + (moves ? " " + hint.dataset.openMove : "");
    }

    function setMode(mode) {
      modeInput.value = mode;
      tabs.forEach((t) => {
        t.classList.toggle("tab-on", t.dataset.mode === mode);
        if (t.getAttribute("role") === "tab") t.setAttribute("aria-selected", String(t.dataset.mode === mode));
      });
      form.classList.toggle("composer-note", mode === "note");
      box.placeholder = PLACEHOLDERS[mode];
      box.setAttribute("aria-label", mode === "note" ? "Internal note" : "Reply");
      paintHint();
      if (send) send.textContent = mode === "note" ? "Save note" : sendLabel;
      // "Send and ..." is for replies: a note has one way to be saved.
      if (sendMore) { sendMore.hidden = mode === "note"; showSendMenu(false); }
    }
    tabs.forEach((t) => t.addEventListener("click", () => { setMode(t.dataset.mode); box.focus(); }));

    /* Send and set the next status in one act. The choices are real submit buttons carrying
       after_status; this only opens their menu and moves between them. */
    function showSendMenu(open) {
      if (!sendMenu || !sendMore) return;
      sendMenu.hidden = !open;
      sendMore.setAttribute("aria-expanded", String(open));
      if (open) $(".send-choice", sendMenu)?.focus();
    }
    if (sendMore && sendMenu) {
      sendMore.addEventListener("click", () => showSendMenu(sendMenu.hidden));
      sendMenu.addEventListener("keydown", (e) => {
        const choices = $$(".send-choice", sendMenu);
        const at = choices.indexOf(document.activeElement);
        if (e.key === "ArrowDown" || e.key === "ArrowUp") {
          e.preventDefault(); e.stopPropagation();
          choices[(at + (e.key === "ArrowDown" ? 1 : choices.length - 1)) % choices.length]?.focus();
        } else if (e.key === "Escape") {
          e.preventDefault(); e.stopPropagation();
          showSendMenu(false); sendMore.focus();
        } else if (e.key === "Tab") showSendMenu(false);
      });
      document.addEventListener("pointerdown", (event) => {
        if (!sendMenu.hidden && !event.target.closest("#send-split")) showSendMenu(false);
      });
    }

    // The choices the Send menu offers, in its order, for the palette.
    const sendChoices = () => $$('button[name="after_status"]', form).reverse().map((button) => ({
      after: button.value,
      title: $(".send-choice-title", button)?.textContent || button.textContent.trim(),
      detail: $(".send-choice-detail", button)?.textContent || "",
    }));

    function sendWith(after) {
      if (!after) { form.requestSubmit(); return; }
      if (modeInput.value === "note") { toast("An internal note is saved as it is. Switch to Reply to send and set a status."); return; }
      const choice = form.querySelector(`button[name="after_status"][value="${after}"]`);
      if (choice) form.requestSubmit(choice);
    }

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
    // The list opens two ways: typing "/" first in the box (the pick replaces that), or the Saved
    // replies button (the pick goes in at the cursor, so a draft is kept).
    const cannedOpen = $("#canned-open");
    let popFromButton = false;
    function popClose() {
      if (pop) { pop.hidden = true; pop.innerHTML = ""; }
      popFromButton = false;
      if (cannedOpen) cannedOpen.setAttribute("aria-expanded", "false");
    }
    function popOpen() { return pop && !pop.hidden; }
    function popInsert(s) {
      const text = mergeResolve(s.body);
      let caret = text.length;
      if (popFromButton) {
        const start = box.selectionStart ?? box.value.length;
        const end = box.selectionEnd ?? start;
        box.value = box.value.slice(0, start) + text + box.value.slice(end);
        caret = start + text.length;
      } else {
        box.value = text;
      }
      popClose();
      box.focus();
      box.setSelectionRange(caret, caret);
    }
    if (cannedOpen && pop) cannedOpen.addEventListener("click", () => {
      if (popOpen()) { popClose(); box.focus(); return; }
      box.focus();
      popIndex = 0;
      popRender("");
      popFromButton = true;
      cannedOpen.setAttribute("aria-expanded", "true");
    });

    box.addEventListener("input", () => {
      if (box.value.startsWith("/")) { popFromButton = false; popRender(box.value.slice(1)); }
      else popClose();
    });
    box.addEventListener("keydown", (e) => {
      if (!popOpen()) return;
      if (e.key === "ArrowDown") { e.preventDefault(); popIndex = Math.min(popIndex + 1, popItems.length - 1); popPaint(); }
      else if (e.key === "ArrowUp") { e.preventDefault(); popIndex = Math.max(popIndex - 1, 0); popPaint(); }
      else if (e.key === "Enter" && !e.ctrlKey && !e.metaKey) { e.preventDefault(); if (popItems[popIndex]) popInsert(popItems[popIndex]); }
      else if (e.key === "Escape") { e.stopPropagation(); popClose(); }
    });
    // Leaving the box closes the list, unless focus came straight back (the Saved replies button).
    box.addEventListener("blur", () => setTimeout(() => { if (document.activeElement !== box) popClose(); }, 200));

    /* time-at-reply: the running timer rides the Send click */
    const chip = $("#timer-log-chip");
    const chipText = $("#timer-log-text");
    const minutesInput = $("#f-timer-minutes");
    const entryKeyInput = $("#f-timer-entry-key");
    const startedAtInput = $("#f-timer-started-at");
    const endedAtInput = $("#f-timer-ended-at");
    const workedAtInput = $("#f-timer-worked-at");

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
      if (!box.value.trim() && !(upload && upload.files && upload.files.length)) {
        // "Send and resolve" with nothing to send would change nothing: say so instead.
        if (event.submitter && event.submitter.name === "after_status") {
          event.preventDefault();
          showSendMenu(false);
          box.focus();
          toast("Write your reply first.");
        }
        return;
      }
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

    return { setMode, paintHint, send: sendWith, sendChoices, focus: (mode) => { setMode(mode); box.focus(); } };
  })();

  /* After "Send and resolve" (or "... wait") the page comes back saying what happened, with Undo. */
  (() => {
    const flash = $("#ticket-flash");
    const thread = $("#thread");
    if (!flash || !thread) return;
    if (flash.dataset.toast) {
      const back = flash.dataset.undoStatus;
      toast(flash.dataset.toast, back
        ? { undo: () => ticketAction(thread.dataset.ticketId, "status", back, { undoable: false, undo: true }) } : {});
    }
    // "Start my timer" on the New ticket form: the timer lives in this browser, so it starts here,
    // on the new ticket's page. If one is already running, startTimer says so and starts nothing.
    if (flash.dataset.startTimer === "1") {
      const title = $(".ticket-subject");
      startTimer(thread.dataset.ticketId, title ? title.textContent.trim() : "");
    }
  })();

  if (IS_MAC) {
    $$("[data-mod-key]").forEach((el) => { el.textContent = MOD_KEY; });
  }

  /* ------------------------------------------------------------------ */
  /* Merge tickets                                                       */
  /* ------------------------------------------------------------------ */
  // Returns "" when the merge went through (the page then moves to the surviving ticket), or the
  // sentence to show when it did not.
  async function mergeTickets(src, dst) {
    try {
      const r = await api("/api/ticket_merge.php", { source_id: src, target_id: dst });
      if (!r.ok) return r.error || "The merge did not go through. Nothing was changed.";
      toast(r.toast);
      setTimeout(() => nav("/ticket.php?id=" + r.target_id), 600);
      return "";
    } catch (error) {
      return error && error.fromServer ? error.message : "The merge did not go through. Check your connection and try again.";
    }
  }

  /* The Merge dialog: pick one of this client's other open tickets (the page lists them in
     #merge-data), read one line about it, then confirm. Built with textContent only: titles and
     names are customer text. `preselect` is the ticket the duplicate warning offers. */
  function openMergeDialog(opener, preselect) {
    const source = $("#merge-data");
    if (!source || mergeDialogOpen) return;
    let data;
    try { data = JSON.parse(source.textContent); } catch { return; }
    const choices = Array.isArray(data.choices) ? data.choices.slice() : [];
    if (preselect && !choices.some((c) => c.id === preselect.id)) choices.unshift({ id: preselect.id, subject: preselect.subject || "", summary: "" });
    mergeDialogOpen = true;

    const overlay = make("div", "palette-overlay");
    overlay.id = "merge-overlay";
    const panel = make("div", "palette merge-panel");
    panel.setAttribute("role", "dialog");
    panel.setAttribute("aria-modal", "true");
    panel.setAttribute("aria-labelledby", "merge-title");
    panel.tabIndex = -1;
    overlay.appendChild(panel);

    const title = make("h2", "", `Merge #${data.ticket.id} into another ticket`);
    title.id = "merge-title";
    panel.appendChild(title);
    panel.appendChild(make("p", "merge-sub",
      "This ticket's messages and files move to the ticket you pick. This one closes and points to it. A merge cannot be undone."));

    let chosen = null;
    let active = -1;
    let shown = [];
    let busy = false;

    const cancel = make("button", "btn-secondary", choices.length ? "Cancel" : "Close");
    cancel.type = "button";
    const confirmBtn = make("button", "btn-primary", "Merge");
    confirmBtn.type = "button";
    confirmBtn.disabled = true;
    const summary = make("p", "merge-summary");
    summary.setAttribute("aria-live", "polite");
    const problem = make("p", "merge-error");
    problem.setAttribute("role", "alert");
    problem.hidden = true;

    function close() {
      if (busy) return;
      document.removeEventListener("keydown", onKey, true);
      overlay.remove();
      mergeDialogOpen = false;
      if (opener && opener.isConnected) opener.focus();
    }

    const search = make("input", "merge-search");
    const list = make("ul", "merge-list");
    function choose(choice, focusConfirm) {
      chosen = choice;
      confirmBtn.disabled = false;
      confirmBtn.textContent = `Merge into #${choice.id}`;
      summary.textContent = `#${choice.id} ${choice.subject}` + (choice.summary ? `: ${choice.summary}` : "");
      paint();
      if (focusConfirm) confirmBtn.focus();
    }
    function paint() {
      const words = search.value.trim().toLowerCase().replace(/^#/, "");
      shown = choices.filter((c) => words === "" || String(c.id).includes(words) || c.subject.toLowerCase().includes(words));
      if (active >= shown.length) active = shown.length - 1;
      if (active < 0 && shown.length) active = 0;
      list.replaceChildren();
      shown.forEach((choice, index) => {
        const item = make("li", "merge-option" + (index === active ? " merge-option-on" : ""));
        item.id = `merge-option-${choice.id}`;
        item.setAttribute("role", "option");
        item.setAttribute("aria-selected", chosen && chosen.id === choice.id ? "true" : "false");
        const name = make("span", "merge-option-name");
        name.appendChild(make("span", "merge-option-num", `#${choice.id}`));
        name.appendChild(document.createTextNode(" " + choice.subject));
        item.appendChild(name);
        if (choice.summary) item.appendChild(make("span", "merge-option-sub", choice.summary));
        item.addEventListener("click", () => { active = index; choose(choice, true); });
        list.appendChild(item);
      });
      if (!shown.length) list.appendChild(make("li", "merge-none", "No open ticket matches. Try its number or a word from its title."));
      if (shown[active]) search.setAttribute("aria-activedescendant", `merge-option-${shown[active].id}`);
      else search.removeAttribute("aria-activedescendant");
      const on = $(".merge-option-on", list);
      if (on) on.scrollIntoView({ block: "nearest" });
    }

    async function run() {
      if (!chosen || busy) return;
      busy = true;
      problem.hidden = true;
      confirmBtn.disabled = cancel.disabled = search.disabled = true;
      confirmBtn.textContent = "Merging";
      const failed = await mergeTickets(Number(data.ticket.id), chosen.id);
      if (failed === "") return;                 // the page is on its way to the surviving ticket
      busy = false;
      confirmBtn.disabled = cancel.disabled = search.disabled = false;
      confirmBtn.textContent = `Merge into #${chosen.id}`;
      problem.textContent = failed;
      problem.hidden = false;
      confirmBtn.focus();
    }

    function onKey(e) {
      if (e.key === "Escape") { e.preventDefault(); e.stopPropagation(); close(); return; }
      if (e.target === search && shown.length && (e.key === "ArrowDown" || e.key === "ArrowUp")) {
        e.preventDefault();
        active = e.key === "ArrowDown" ? Math.min(active + 1, shown.length - 1) : Math.max(active - 1, 0);
        paint();
        return;
      }
      if (e.target === search && e.key === "Enter") {
        e.preventDefault();
        if (shown[active]) choose(shown[active], true);
        return;
      }
      if (e.key !== "Tab") return;
      const focusable = $$("button:not([disabled]), input:not([disabled])", panel);
      if (!focusable.length || !panel.contains(document.activeElement)) { e.preventDefault(); (focusable[0] || panel).focus(); return; }
      const first = focusable[0], last = focusable[focusable.length - 1];
      if (e.shiftKey && (document.activeElement === first || document.activeElement === panel)) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }

    if (choices.length) {
      const label = make("label", "merge-label", `Find an open ticket for ${data.client}`);
      label.htmlFor = "merge-search";
      search.id = "merge-search";
      search.type = "search";
      search.autocomplete = "off";
      search.placeholder = "Ticket number or title";
      search.setAttribute("role", "combobox");
      search.setAttribute("aria-expanded", "true");
      search.setAttribute("aria-controls", "merge-list");
      search.setAttribute("aria-autocomplete", "list");
      list.id = "merge-list";
      list.setAttribute("role", "listbox");
      list.setAttribute("aria-label", `Open tickets for ${data.client}`);
      search.addEventListener("input", () => { active = 0; paint(); });
      panel.append(label, search, list);
      if (data.more) panel.appendChild(make("p", "merge-note", `Only the ${choices.length} most pressing are listed.`));
      panel.append(summary, problem);
    } else {
      panel.appendChild(make("p", "merge-empty", `${data.client} has no other open ticket to merge this one into.`));
      confirmBtn.hidden = true;
    }
    const actions = make("div", "delete-actions");
    actions.append(cancel, confirmBtn);
    panel.appendChild(actions);

    cancel.addEventListener("click", close);
    confirmBtn.addEventListener("click", run);
    const openedAt = Date.now();   // as in askForm: a double click on Merge must not close what it opened
    overlay.addEventListener("mousedown", (e) => { if (e.target === overlay && Date.now() - openedAt > DIALOG_SETTLE_MS) close(); });
    document.addEventListener("keydown", onKey, true);
    document.body.appendChild(overlay);

    if (!choices.length) { cancel.focus(); return; }
    const first = preselect ? choices.find((c) => c.id === preselect.id) : null;
    if (first) { active = choices.indexOf(first); choose(first, true); }
    else { paint(); search.focus(); }
  }
  const mergeBtn = $("#merge-btn");
  if (mergeBtn) mergeBtn.addEventListener("click", () => openMergeDialog(mergeBtn, null));
  const mergeDupeBtn = $("#merge-dupe-btn");
  if (mergeDupeBtn) mergeDupeBtn.addEventListener("click", () =>
    openMergeDialog(mergeDupeBtn, { id: Number(mergeDupeBtn.dataset.dst), subject: mergeDupeBtn.dataset.subject || "" }));

  /* Put some text on the clipboard and say so; say what to do instead when the browser will not. */
  function copyText(text, done) {
    const failed = () => toast("Could not copy. Select the text and copy it yourself.");
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(() => toast(done), failed);
    else failed();
  }

  /* Copy: a small button beside a value (the contact's email address on a ticket). */
  document.addEventListener("click", (e) => {
    const button = e.target.closest && e.target.closest("[data-copy]");
    if (!button) return;
    copyText(button.dataset.copy, "Copied.");
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
          <p><kbd class="kbd">j</kbd>/<kbd class="kbd">k</kbd> move · <kbd class="kbd">↵</kbd> open · <kbd class="kbd">u</kbd> back to the list</p>
          <p><kbd class="kbd">1</kbd>–<kbd class="kbd">6</kbd> views</p>${bulk ? `
          <p><kbd class="kbd">x</kbd> select · <kbd class="kbd">d</kbd> delete selected</p>` : ""}</div>
        <div><h4>Any ticket</h4>
          <p><kbd class="kbd">a</kbd> assign to me · <kbd class="kbd">z</kbd> undo</p>
          <p><kbd class="kbd">s</kbd> status · <kbd class="kbd">p</kbd> priority · <kbd class="kbd">t</kbd> give to a teammate</p>
          <p>these three open a menu: <kbd class="kbd">↑↓</kbd> and <kbd class="kbd">↵</kbd>, or a number</p>
          <p><kbd class="kbd">e</kbd> timer · <kbd class="kbd">r</kbd> reply · <kbd class="kbd">n</kbd> note</p>
          <p><kbd class="kbd">[</kbd> previous · <kbd class="kbd">]</kbd> next, in the list it was opened from</p></div>
        <div><h4>Composer</h4>
          <p><kbd class="kbd">/</kbd> saved replies · <kbd class="kbd">${MOD_KEY}</kbd><kbd class="kbd">↵</kbd> send</p>
          <p>send and wait, send and resolve: the arrow beside Send, or <kbd class="kbd">${MOD_KEY}</kbd><kbd class="kbd">K</kbd></p>
          <p>matching timer logs only after server-confirmed Send</p></div>
        <div><h4>Everywhere</h4>
          <p><kbd class="kbd">c</kbd> new ticket (on a client's page, for that client)</p>
          <p><kbd class="kbd">${MOD_KEY}</kbd><kbd class="kbd">K</kbd> or <kbd class="kbd">/</kbd> search and actions (search reaches every message)</p>
          <p><kbd class="kbd">g</kbd> then <kbd class="kbd">q</kbd> Queue · <kbd class="kbd">t</kbd> Time · <kbd class="kbd">c</kbd> Clients · <kbd class="kbd">s</kbd> Service goals · <kbd class="kbd">r</kbd> Reports · <kbd class="kbd">u</kbd> Team</p>
          <p><kbd class="kbd">?</kbd> this card</p></div>
      </div></div>`;
    div.addEventListener("click", (e) => { if (e.target === div) toggleHelp(); });
    document.body.appendChild(div);
  }

  /* Queue filters: a change applies at once, and a choice left at its default stays out of the
     address, so a filtered queue has a short link worth keeping. */
  (() => {
    const form = $("#queue-filters");
    if (!form) return;
    const apply = $("#queue-apply", form);
    if (apply) apply.hidden = true;
    const selects = $$("select", form);
    selects.forEach((select) => select.addEventListener("change", () => form.requestSubmit()));
    form.addEventListener("submit", (event) => {
      selects.forEach((select) => { if (select.value === (select.dataset.default || "")) select.disabled = true; });
      // Nothing left to say: go to the plain queue rather than to an address that ends in "?".
      if (new FormData(form).entries().next().done) {
        event.preventDefault();
        window.location.href = form.getAttribute("action") || "/";
      }
    });
    // Back restores the page as it was left: its selects must answer again.
    window.addEventListener("pageshow", () => selects.forEach((select) => { select.disabled = false; }));
  })();

  /* New ticket ("log a call"). The form works as plain HTML; this puts a type-to-find box in
     front of the client list, narrows "Who asked" to the chosen client, and keeps a new contact's
     fields out of the request unless they are open. Client and contact names are set as text. */
  (() => {
    const clientSel = $("#f-client");
    const contactSel = $("#f-contact");
    if (!clientSel || !contactSel) return;
    const form = clientSel.form;
    const options = $$("option[data-client]", contactSel);
    const sync = () => {
      const cid = clientSel.value;
      options.forEach((o) => {
        const show = cid !== "" && o.dataset.client === cid;
        o.hidden = !show;
        o.disabled = !show;       // Safari lists a hidden option; a disabled one cannot be picked
      });
      if (contactSel.selectedOptions[0] && contactSel.selectedOptions[0].disabled) contactSel.value = "";
    };
    clientSel.addEventListener("change", sync);
    sync();

    // A new contact: while its fields are open they are what "Who asked" means, so the list rests.
    const fresh = $("#contact-new");
    const freshFields = fresh ? $$("input", fresh) : [];
    const paintFresh = (focus) => {
      if (!fresh) return;
      freshFields.forEach((input) => { input.disabled = !fresh.open; });
      contactSel.disabled = fresh.open;
      if (fresh.open) contactSel.value = "";
      if (fresh.open && focus && freshFields[0]) freshFields[0].focus();
    };
    // A disclosure that is open when the page loads fires "toggle" too: only a change the person
    // made moves the cursor into its first field.
    let freshWasOpen = fresh ? fresh.open : false;
    if (fresh) fresh.addEventListener("toggle", () => {
      const changed = fresh.open !== freshWasOpen;
      freshWasOpen = fresh.open;
      paintFresh(changed);
    });
    paintFresh(false);

    // The client box.
    const field = $("#call-client");
    const label = field ? $("label", field) : null;
    if (!field || !label) return;
    const clients = $$("option", clientSel).filter((o) => o.value !== "")
      .map((o) => ({ id: o.value, name: o.textContent, plan: o.dataset.plan || "", find: o.textContent.toLowerCase() }));
    const box = make("input", "call-client-box");
    box.type = "text";
    box.id = "f-client-box";
    box.autocomplete = "off";
    box.placeholder = "Type a client's name";
    box.setAttribute("role", "combobox");
    box.setAttribute("aria-expanded", "false");
    box.setAttribute("aria-controls", "f-client-list");
    box.setAttribute("aria-autocomplete", "list");
    const list = make("ul", "call-client-list");
    list.id = "f-client-list";
    list.setAttribute("role", "listbox");
    list.setAttribute("aria-label", "Clients");
    list.hidden = true;
    const picked = make("p", "call-client-picked");
    picked.setAttribute("aria-live", "polite");
    const wrap = make("div", "call-client-wrap");
    wrap.append(box, list);
    clientSel.before(wrap, picked);
    label.htmlFor = box.id;
    clientSel.hidden = true;
    clientSel.required = false;

    let shown = [];
    let active = 0;
    const current = () => clients.find((c) => c.id === clientSel.value) || null;
    // Names that start with what was typed come first, then names with a word that does, then the rest.
    const rank = (c, words) => (c.find.startsWith(words) ? 0 : c.find.includes(" " + words) ? 1 : 2);
    const matches = (words) => clients.filter((c) => c.find.includes(words))
      .sort((a, b) => rank(a, words) - rank(b, words) || a.find.localeCompare(b.find));
    function paintPicked() {
      const client = current();
      picked.replaceChildren();
      if (!client) return;
      picked.append(make("span", "call-client-name", client.name));
      if (client.plan) picked.append(make("span", "tier" + (client.plan === "Premium" ? " tier-premium" : ""), client.plan));
    }
    function close() {
      list.hidden = true;
      box.setAttribute("aria-expanded", "false");
      box.removeAttribute("aria-activedescendant");
    }
    function open() {
      shown = matches(box.value.trim().toLowerCase());
      active = Math.max(0, Math.min(active, shown.length - 1));
      list.replaceChildren();
      shown.forEach((client, index) => {
        const item = make("li", "call-client-option" + (index === active ? " call-client-on" : ""));
        item.id = `f-client-option-${client.id}`;
        item.setAttribute("role", "option");
        item.setAttribute("aria-selected", index === active ? "true" : "false");
        item.append(make("span", "call-client-name", client.name));
        if (client.plan) item.append(make("span", "tier" + (client.plan === "Premium" ? " tier-premium" : ""), client.plan));
        // mousedown, so the box keeps focus and the list does not close before the click lands.
        item.addEventListener("mousedown", (e) => { e.preventDefault(); pick(client, true); });
        list.append(item);
      });
      if (!shown.length) list.append(make("li", "call-client-none", "No client matches. Check the spelling, or add the client first."));
      list.hidden = false;
      box.setAttribute("aria-expanded", "true");
      if (shown[active]) box.setAttribute("aria-activedescendant", `f-client-option-${shown[active].id}`);
      else box.removeAttribute("aria-activedescendant");
      const on = $(".call-client-on", list);
      if (on) on.scrollIntoView({ block: "nearest" });
    }
    function choose(id) {
      clientSel.value = id;
      clientSel.dispatchEvent(new Event("change"));
      paintPicked();
      box.setCustomValidity("");
    }
    function pick(client, moveOn) {
      box.value = client.name;
      choose(client.id);
      close();
      if (moveOn) (fresh && fresh.open && freshFields[0] ? freshFields[0] : contactSel).focus();
    }
    box.addEventListener("input", () => {
      // Typing over a chosen client un-chooses it: the box and the choice never disagree.
      const client = current();
      if (client && box.value !== client.name) choose("");
      box.setCustomValidity("");
      active = 0;
      open();
    });
    box.addEventListener("keydown", (e) => {
      if (e.ctrlKey || e.metaKey) return;
      if (e.key === "ArrowDown" || e.key === "ArrowUp") {
        e.preventDefault();
        if (list.hidden) active = 0;
        else active = e.key === "ArrowDown" ? Math.min(active + 1, shown.length - 1) : Math.max(active - 1, 0);
        open();
      } else if (e.key === "Enter") {
        // Enter picks the first match (or the highlighted one). It never sends a half-filled form.
        e.preventDefault();
        if (!list.hidden && shown[active]) pick(shown[active], true);
      } else if (e.key === "Escape" && !list.hidden) {
        e.preventDefault();
        e.stopPropagation();
        close();
      } else if (e.key === "Tab" && !list.hidden && shown[active] && !current() && box.value.trim() !== "") {
        pick(shown[active], false);   // "Bay", Tab: the first match, and focus moves on by itself
      }
    });
    box.addEventListener("blur", () => {
      close();
      // Typed out in full without picking: that is a choice too.
      const exact = clients.filter((c) => c.find === box.value.trim().toLowerCase());
      if (!current() && exact.length === 1) pick(exact[0], false);
    });
    // One ticket per form. A second press (a double click, Ctrl+Enter twice) is dropped, and both
    // buttons rest until the page is shown again. A disabled button is not part of what a form
    // sends, so the pressed button's choice ("Create and resolve") rides in a hidden field.
    const buttons = [$("#call-create", form), $("#call-resolve", form)].filter(Boolean);
    let sending = false;
    form.addEventListener("submit", (e) => {
      if (sending) { e.preventDefault(); return; }
      if (clientSel.value === "") {
        e.preventDefault();
        box.setCustomValidity("Choose a client from the list.");
        box.reportValidity();
        return;
      }
      sending = true;
      const pressed = e.submitter;
      if (pressed && pressed.name) {
        const carry = make("input");
        carry.type = "hidden";
        carry.name = pressed.name;
        carry.value = pressed.value;
        carry.dataset.carried = "1";
        form.append(carry);
      }
      buttons.forEach((button) => { button.disabled = true; });
    });
    // Back to this page (the browser may restore it as it was left): it can be used again.
    window.addEventListener("pageshow", () => {
      sending = false;
      buttons.forEach((button) => { button.disabled = false; });
      $$("input[data-carried]", form).forEach((input) => input.remove());
    });

    const start = current();
    if (start) { box.value = start.name; paintPicked(); }
    // Focus starts where the next thing to say is: the client, or who asked once the client is known.
    if (!$(".form-error")) (start ? contactSel : box).focus();
  })();

  /* Phone ticket controls: reuse the one Details column, its menus and the existing composer.
     With no JavaScript, the column stays in the document as before. No action is submitted here. */
  (() => {
    const ticketPage = $(".page-ticket");
    const rail = ticketPage && $(".ticket-grid > .rail", ticketPage);
    const title = ticketPage && $(".ticket-subject", ticketPage);
    if (!rail || !title || typeof HTMLDialogElement === "undefined") return;
    const phone = window.matchMedia("(max-width: 700px)");
    const home = document.createComment("Details column position");
    rail.before(home);
    const summary = make("button", "phone-ticket-summary");
    summary.type = "button";
    summary.setAttribute("aria-haspopup", "dialog");
    summary.setAttribute("aria-controls", "phone-ticket-details");
    summary.setAttribute("aria-expanded", "false");
    const words = make("span", "phone-summary-words");
    const more = make("span", "phone-summary-more", "Details ›");
    summary.append(words, more);
    title.after(summary);
    const sheet = make("dialog", "phone-details");
    sheet.id = "phone-ticket-details";
    sheet.setAttribute("aria-label", "Ticket details");
    const close = make("button", "btn-secondary phone-details-close", "Close details");
    close.type = "button";
    sheet.append(close);
    document.body.append(sheet);
    let returnTo = summary;
    const paint = () => {
      const labels = ["[data-chip]", "[data-pri]", "[data-assignee] .assignee-name", "[data-sla]"]
        .map(selector => ($(selector, rail)?.textContent || "").trim()).filter(Boolean);
      words.textContent = labels.join(", ");
      summary.title = labels.join(", ");
      summary.setAttribute("aria-label", labels.join(", ") + ". Open ticket details");
    };
    paint();
    new MutationObserver(paint).observe(rail, { childList: true, subtree: true, characterData: true });
    function openDetails(trigger = summary, field = null) {
      if (!phone.matches) return;
      returnTo = trigger;
      closeMenu(false);
      sheet.append(rail);
      if (!sheet.open) sheet.showModal();
      summary.setAttribute("aria-expanded", "true");
      if (field) {
        const existing = $(`.menu-btn[data-action="${field}"]`, rail);
        if (existing) { existing.scrollIntoView({ block: "nearest" }); existing.focus(); existing.click(); }
      } else close.focus();
    }
    summary.addEventListener("click", () => openDetails());
    close.addEventListener("click", () => sheet.close());
    sheet.addEventListener("close", () => {
      closeMenu(false);
      home.after(rail);
      summary.setAttribute("aria-expanded", "false");
      if (phone.matches && returnTo?.isConnected) returnTo.focus({ preventScroll: true });
    });
    sheet.addEventListener("click", event => {
      if (event.target !== sheet) return;
      const rect = sheet.getBoundingClientRect();
      if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) sheet.close();
    });
    phone.addEventListener("change", () => { if (!phone.matches && sheet.open) sheet.close(); });
    if (composer) {
      const bar = make("nav", "phone-ticket-actions");
      bar.setAttribute("aria-label", "Ticket actions");
      for (const [label, action] of [["Reply", "reply"], ["Note", "note"], ["Status", "status"], ["Assign", "assignee"]]) {
        const button = make("button", "btn-secondary", label);
        button.type = "button";
        button.dataset.phoneAction = action;
        button.addEventListener("click", () => {
          if (action === "reply" || action === "note") {
            if (sheet.open) sheet.close();
            composer.focus(action);
          } else openDetails(button, action);
        });
        bar.append(button);
      }
      ticketPage.append(bar);
    }
    ticketPage.classList.add("phone-details-ready");
  })();

  /* boot */
  paintTimer();
  paintLegacyTimer();
})();
