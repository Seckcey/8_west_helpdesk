/* One server-authorized conversation, one input, two places to continue it. */
(() => {
  'use strict';
  const root = document.getElementById('portal-chat-root');
  if (!root) return;
  const panel = document.getElementById('portal-chat-panel');
  const home = document.getElementById('portal-chat-home-slot');
  const bubble = document.getElementById('portal-chat-bubble');
  const form = document.getElementById('portal-chat-form');
  const input = document.getElementById('portal-chat-input');
  const send = document.getElementById('portal-chat-send');
  const status = document.getElementById('portal-chat-status');
  const log = document.getElementById('portal-chat-messages');
  const draftBox = document.getElementById('portal-chat-draft');
  let state = null, busy = false, editing = false, pending = null, focusBefore = null, poll = null;
  const labels = {
    requests: 'Open a support request', updates: 'Follow a request', response: 'Response goals',
    summaries: 'Service summaries', privacy: 'Westy and privacy'
  };
  const errors = {
    ai_unavailable: 'Westy is unavailable. You can still write a request directly to support.',
    provider_unavailable: 'Westy could not finish that reply. Your message is saved; no support request was sent.',
    provider_rate_limit: 'Westy is busy right now. You can still write a request directly.',
    provider_refused: 'Westy could not answer that. You can write to the support team directly.',
    provider_invalid: 'Westy could not give a usable answer. Please use the direct support form.',
    interrupted: 'That reply was interrupted. No support request was sent. You can ask again or write to support.',
    hourly_limit: 'You have reached the hourly Westy limit. Direct support requests are still available.',
    daily_limit: 'Your business has reached today’s Westy limit. Direct support requests are still available.',
    cost_limit: 'Your business has reached its Westy budget. Direct support requests are still available.',
    busy: 'Westy is still answering your previous message. Check the conversation in a moment.',
    sign_in: 'Your sign-in has ended or access changed. Sign in again to continue.',
    identity_unavailable: 'We cannot verify access right now. Your private chat is hidden until access can be verified.',
    read_only: 'Your viewer role cannot send support requests.',
    draft_changed: 'This draft changed, expired or was already sent. Check the saved version before continuing.',
    conversation_changed: 'Your conversation changed in another tab. Check the current conversation before continuing.',
    sensitive_text: 'Please remove passwords, secret keys or verification codes before asking Westy.',
    invalid_message: 'Enter a message of up to 2,000 characters.',
    invalid_request: 'Check the required fields and try again.',
    unavailable: 'We could not confirm that action. Check the saved conversation before trying again.'
  };
  const key = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('');
  const element = (tag, text, className) => { const e = document.createElement(tag); if (text !== undefined) e.textContent = text; if (className) e.className = className; return e; };
  const button = (text, action, cls = 'btn-ghost') => { const b = element('button', text, cls); b.type = 'button'; b.addEventListener('click', action); return b; };
  const say = text => { status.textContent = text; };

  async function api(payload, receiptKey = null) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 25000);
    try {
      const response = await fetch('/portal/westy.php' + (receiptKey ? '?receipt=' + encodeURIComponent(receiptKey) : ''), {
        method: payload ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store',
        headers: payload ? { 'Content-Type': 'application/json', 'X-Portal-CSRF': root.dataset.csrf } : {},
        body: payload ? JSON.stringify(payload) : undefined, signal: controller.signal
      });
      const result = await response.json();
      if (!response.ok || !result.ok) {
        if (result.reason === 'sign_in' || result.reason === 'identity_unavailable') clearPrivate();
        throw Object.assign(new Error(result.reason || 'unavailable'), { reason: result.reason });
      }
      return result.state;
    } finally { clearTimeout(timeout); }
  }

  function clearPrivate() {
    state = null; log.replaceChildren(); draftBox.replaceChildren(); draftBox.hidden = true;
    input.value = ''; editing = false; pending = null;
    send.disabled = true; input.disabled = true;
  }

  async function refresh(quiet = false) {
    if (busy) return;
    try { render(await api()); if (!quiet && state.ai_available) say(''); }
    catch (error) {
      // Cached DOM is not a substitute for fresh identity validation.
      clearPrivate(); say(errors[error.reason] || 'Your chat could not be loaded. Direct support is still available.');
    }
  }

  function render(next) {
    if (state && (state.conversation !== next.conversation || (state.can_write && !next.can_write))) clearPrivate();
    state = next;
    send.disabled = busy || !state.ai_available; input.disabled = !state.ai_available;
    log.replaceChildren();
    panel.classList.toggle('has-history', state.turns.length > 0);
    for (const turn of state.turns) {
      const question = element('article', undefined, 'portal-chat-message portal-chat-message-user');
      question.append(element('strong', 'You'), element('p', turn.input_text)); log.append(question);
      const answer = element('article', undefined, 'portal-chat-message');
      answer.append(element('strong', 'Westy'));
      if (turn.reply) {
        answer.append(element('p', turn.reply.reply));
        const sources = element('nav'); sources.setAttribute('aria-label', 'Sources for this answer');
        for (const id of turn.reply.sources || []) {
          if (!labels[id]) continue;
          const link = element('a', labels[id]); link.href = '/portal/guide.php#' + id; sources.append(link);
        }
        answer.append(sources);
        if (state.can_write && turn.reply.draft_subject && turn.reply.draft_body) {
          answer.append(button('Edit suggested request', () => editDraft({ subject: turn.reply.draft_subject, body: turn.reply.draft_body, priority: 'normal' })));
        }
      } else answer.append(element('p', turn.state === 'pending' ? 'Thinking… You can keep browsing.' : errors[turn.reason_code] || errors.provider_unavailable));
      log.append(answer);
    }
    if (!editing) renderDraft(state.receipt || state.draft);
    if (!state.ai_available) say(errors.ai_unavailable);
    if (poll) clearTimeout(poll);
    if (state.turns.some(t => t.state === 'pending')) poll = setTimeout(() => refresh(true), 2500);
  }

  function renderDraft(draft) {
    draftBox.replaceChildren(); draftBox.hidden = !draft;
    if (!draft) return;
    if (draft.state === 'sent') {
      const receipt = element('div', undefined, 'portal-chat-message'); receipt.setAttribute('role', 'status');
      receipt.append(element('strong', 'Request #' + draft.ticket_id + ' received', 'receipt'), element('p', 'Your reviewed request is saved for your business and the support team. This confirms the ticket exists; it does not mean a technician has read it.'));
      if (draft.ticket_url) { const link = element('a', 'View support request #' + draft.ticket_id); link.href = draft.ticket_url; receipt.append(link); }
      else receipt.append(element('p', 'This ticket is no longer available in the portal. Contact support for help.'));
      draftBox.append(receipt);
    } else if (state.can_write) {
      draftBox.append(element('h3', 'Your saved draft'), element('p', draft.subject));
      draftBox.append(button('Edit & review request', () => editDraft(draft)));
    }
  }

  function editDraft(draft) {
    if (!state?.can_write) return;
    editing = true; draftBox.hidden = false; draftBox.replaceChildren();
    const draftKey = draft.draft_key || key();
    const revision = Number(draft.revision || 0);
    const editor = element('form', undefined, 'portal-chat-draft-fields');
    editor.append(element('h3', 'Edit your support request'));
    const fields = {};
    for (const [name, label, tag, max] of [['subject', 'Short summary', 'input', 190], ['body', 'What happened and who is affected?', 'textarea', 8000]]) {
      const wrap = element('label', label); const control = document.createElement(tag);
      control.name = name; control.maxLength = max; control.required = true; control.value = draft[name] || '';
      wrap.append(control); editor.append(wrap); fields[name] = control;
    }
    const label = element('label', 'Priority'); const priority = document.createElement('select');
    for (const item of ['low', 'normal', 'high', 'urgent']) { const option = element('option', item[0].toUpperCase() + item.slice(1)); option.value = item; priority.append(option); }
    priority.value = draft.priority || 'normal'; label.append(priority); editor.append(label);
    editor.append(element('p', 'Only the text above will be sent. Your private chat stays private.', 'portal-hint'));
    const actions = element('div', undefined, 'portal-chat-draft-actions');
    const review = element('button', 'Save & review request', 'btn-primary'); review.type = 'submit'; actions.append(review);
    actions.append(button('Cancel edits', () => { editing = false; renderDraft(state.draft); })); editor.append(actions);
    editor.addEventListener('submit', async event => {
      event.preventDefault(); if (busy) return;
      const request = { action: 'save_draft', draft_key: draftKey, revision, conversation: state.conversation, subject: fields.subject.value, body: fields.body.value, priority: priority.value };
      busy = true; review.disabled = true; say('Saving your draft for review…');
      try { const next = await api(request); editing = false; render(next); reviewDraft(state.draft); say('Review the exact text and audience before sending.'); }
      catch (error) { say(errors[error.reason] || 'The draft save was not confirmed. Check saved chat before retrying.'); }
      finally { busy = false; review.disabled = false; send.disabled = !state?.ai_available; }
    });
    draftBox.append(editor); fields.subject.focus();
  }

  function reviewDraft(draft) {
    if (!draft || draft.state !== 'draft' || !state.can_write) return;
    editing = false; draftBox.replaceChildren(); draftBox.hidden = false;
    draftBox.append(element('h3', 'Review before sending'), element('strong', draft.subject), element('p', draft.body, 'portal-chat-draft-review'), element('p', 'Priority: ' + draft.priority, 'portal-hint'));
    const label = element('label', undefined, 'portal-chat-review-audience'); const check = document.createElement('input'); check.type = 'checkbox';
    label.append(check, element('span', 'I understand this request will be visible to my business and the support team. Only this reviewed text is shared.'));
    draftBox.append(label);
    const actions = element('div', undefined, 'portal-chat-draft-actions');
    const submit = button('Send request', async () => {
      if (!check.checked || busy) return;
      busy = true; submit.disabled = true; say('Sending your reviewed request…');
      try { render(await api({ action: 'handoff', draft_key: draft.draft_key, revision: Number(draft.revision), reviewed: true })); say('Request saved. The receipt below is your confirmation.'); }
      catch (error) {
        say('Checking whether your request was saved…');
        try {
          render(await api(null, draft.draft_key));
          if (state.receipt?.draft_key === draft.draft_key || (state.draft?.draft_key === draft.draft_key && state.draft.state === 'sent')) say('Request saved. No duplicate was sent.');
          else { say(errors[error.reason] || 'No receipt was found yet. Reopen this saved draft to review and safely send the same request.'); }
        } catch {
          draftBox.replaceChildren(element('p', 'We cannot confirm the result yet. Check the saved receipt before sending anything else.'));
          draftBox.append(button('Check saved receipt', async () => {
            try { render(await api(null, draft.draft_key)); say(state.receipt ? 'Request saved. No duplicate was sent.' : 'No receipt was found. Check the saved draft before continuing.'); }
            catch (retryError) { say(errors[retryError.reason] || 'We cannot check the receipt yet. Try again when the connection returns.'); }
          }));
          say('Connection lost. The request may already be saved.');
        }
      } finally { busy = false; send.disabled = !state?.ai_available; }
    }, 'btn-primary');
    submit.disabled = true; check.addEventListener('change', () => { submit.disabled = !check.checked || busy; });
    actions.append(submit, button('Back to editing', () => editDraft(draft))); draftBox.append(actions); check.focus();
  }

  const mobileWidth = matchMedia('(max-width:760px)');
  function syncPanelMode() {
    const modal = panel.parentElement === root && !panel.hidden && mobileWidth.matches;
    if (modal) { panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-modal', 'true'); }
    else { panel.removeAttribute('role'); panel.removeAttribute('aria-modal'); }
    for (const item of document.querySelectorAll('.portal-content,.portal-sidebar,.portal-top')) item.inert = modal;
  }
  mobileWidth.addEventListener('change', syncPanelMode);
  function openPanel() {
    focusBefore = document.activeElement; root.append(panel); panel.hidden = false;
    bubble.setAttribute('aria-expanded', 'true'); input.focus();
    if (!busy) refresh(true);
    syncPanelMode();
  }
  function closePanel() {
    panel.removeAttribute('role'); panel.removeAttribute('aria-modal');
    for (const item of document.querySelectorAll('.portal-content,.portal-sidebar,.portal-top')) item.inert = false;
    if (home) { home.append(panel); panel.hidden = false; } else panel.hidden = true;
    bubble.setAttribute('aria-expanded', 'false'); (focusBefore?.isConnected ? focusBefore : bubble).focus();
  }
  bubble.addEventListener('click', () => bubble.getAttribute('aria-expanded') === 'true' ? closePanel() : openPanel());
  document.getElementById('portal-chat-close').addEventListener('click', closePanel);
  panel.addEventListener('keydown', event => {
    if (event.key === 'Escape' && panel.parentElement === root) closePanel();
    if (event.key === 'Tab' && panel.getAttribute('aria-modal') === 'true') {
      const items = [...panel.querySelectorAll('a,button,input,select,textarea')].filter(e => !e.disabled && e.getClientRects().length);
      if (event.shiftKey && document.activeElement === items[0]) { event.preventDefault(); items.at(-1)?.focus(); }
      else if (!event.shiftKey && document.activeElement === items.at(-1)) { event.preventDefault(); items[0]?.focus(); }
    }
  });
  form.addEventListener('submit', async event => {
    event.preventDefault(); if (busy || !state?.ai_available || !input.value.trim()) return;
    const text = input.value.trim();
    if (!pending || pending.text !== text) pending = { operation: key(), text };
    const request = { action: 'message', operation: pending.operation, message: text, conversation: state.conversation };
    busy = true; send.disabled = true; say('Westy is thinking…');
    try { render(await api(request)); input.value = ''; pending = null; say(''); log.scrollTop = log.scrollHeight; }
    catch (error) {
      say(errors[error.reason] || 'The reply was not confirmed. Checking saved chat…');
      if (error.reason === 'sign_in' || error.reason === 'identity_unavailable') clearPrivate();
      else if (!error.reason || error.reason === 'conversation_changed' || error.reason === 'busy') {
        try { render(await api()); if (state.turns.some(t => t.operation_key === request.operation)) { input.value = ''; pending = null; say('Your message is saved. No support request was sent.'); } }
        catch { say('We cannot check your chat yet. Your unsent text remains here. Use the direct request form if you need support.'); }
      }
    } finally { busy = false; send.disabled = !state?.ai_available; }
  });
  input.addEventListener('keydown', event => { if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') { event.preventDefault(); form.requestSubmit(); } });
  document.getElementById('portal-chat-new').addEventListener('click', async () => {
    if (busy || !state?.conversation) return;
    if (!confirm('Start a new private chat? The current conversation will leave this view. Saved support requests remain unchanged.')) return;
    busy = true;
    try { editing = false; render(await api({ action: 'new_chat', conversation: state.conversation, next_conversation: key() })); input.value = ''; pending = null; say('New private chat.'); }
    catch (error) { say(errors[error.reason] || errors.unavailable); }
    finally { busy = false; send.disabled = !state?.ai_available; }
  });
  document.querySelectorAll('[data-portal-chat-prompt]').forEach(b => b.addEventListener('click', () => { if (panel.hidden) openPanel(); input.value = b.dataset.portalChatPrompt; input.focus(); }));
  const menu = document.querySelector('.portal-menu');
  menu.addEventListener('click', () => { const open = menu.getAttribute('aria-expanded') !== 'true'; menu.setAttribute('aria-expanded', String(open)); document.getElementById('portal-nav').classList.toggle('is-open', open); });
  window.addEventListener('beforeunload', event => { if (editing || input.value.trim()) { event.preventDefault(); event.returnValue = ''; } });
  // No transcript, draft text, identity or ticket data is stored in browser storage.
  window.addEventListener('pagehide', clearPrivate);
  window.addEventListener('pageshow', event => { if (event.persisted) refresh(); });
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && !busy) refresh(true); });
  // Content-free cross-tab sign-out signal; never put chat or identity in it.
  if ('BroadcastChannel' in window) {
    const access = new BroadcastChannel('safeharbor-portal-access');
    document.querySelector('form[action="/portal/logout.php"]')?.addEventListener('submit', () => access.postMessage('signed-out'));
    access.addEventListener('message', event => { if (event.data === 'signed-out') { clearPrivate(); say(errors.sign_in); } });
  }
  if (home) { home.append(panel); panel.hidden = false; }
  send.disabled = true; input.disabled = true;
  say('Loading your private conversation…'); refresh();
})();
