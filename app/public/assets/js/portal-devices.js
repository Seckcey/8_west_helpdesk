/* Your devices, while a setup file is waiting to be used: copy its link, and watch for the new
   computer. Loaded only in that state.

   The watch asks one small question, "which computers does this business have?", through
   /portal/devices.php?watch=1 (one device-service read, covering every page of the list, never the
   setup links). It announces a computer only when it is missing from a list that loaded completely:
   a failed or partial read decides nothing. It posts nothing and changes nothing on the server. */
(() => {
    'use strict';

    document.querySelectorAll('[data-copy-text]').forEach(button => {
        const status = document.getElementById(button.dataset.copyStatus || '');
        const say = text => { if (status) status.textContent = text; };
        button.addEventListener('click', async () => {
            const text = button.dataset.copyText;
            try {
                await navigator.clipboard.writeText(text);
                say('Link copied. Open it on the computer you want to add.');
            } catch {
                // No clipboard permission: put the link on the page so it can be selected and copied by hand.
                say('Copy this link by hand: ' + text);
            }
        });
    });

    const wait = document.querySelector('[data-device-wait]');
    const list = document.getElementById('portal-device-list');
    if (!wait || !list) return;

    const EVERY_MS = 15000;
    const FOR_MS = 5 * 60 * 1000;
    const withoutScript = wait.textContent; // true with or without this script; restored when watching ends early
    const started = Date.now();
    let timer = null;
    let busy = false;
    // The computers already there. Known from the page only when it holds the whole list, loaded successfully;
    // otherwise it is taken from the first complete read, and nothing is announced before then.
    let known = list.hasAttribute('data-complete')
        ? new Set([...list.querySelectorAll('[data-device]')].map(card => card.dataset.device))
        : null;

    const stop = text => {
        if (timer !== null) clearInterval(timer);
        timer = null;
        wait.textContent = text;
        wait.removeAttribute('data-device-wait');
    };

    /** Every computer, or 'ended' when the sign-in is over, or null when this read cannot be trusted. */
    const read = async () => {
        let response;
        try {
            response = await fetch('/portal/devices.php?watch=1', { credentials: 'same-origin', cache: 'no-store', redirect: 'manual', headers: { Accept: 'application/json' } });
        } catch {
            return null;
        }
        if (response.type === 'opaqueredirect' || response.status === 401 || response.status === 403) return 'ended';
        if (!response.ok) return null;
        let answer;
        try { answer = await response.json(); } catch { return null; }
        if (!answer || answer.ok !== true || !Array.isArray(answer.computers)) return null;
        if (answer.complete !== true) return 'too many';
        return answer.computers.filter(computer => computer && typeof computer.reference === 'string' && typeof computer.label === 'string');
    };

    /** Step 2 and step 3 are both finished once the computer is here. */
    const finishSteps = () => {
        const check = document.querySelector('.portal-add-computer .setup-step.is-done .setup-mark svg');
        document.querySelectorAll('.portal-add-computer .setup-step:not(.is-done)').forEach(step => {
            step.classList.remove('is-current', 'is-later');
            step.classList.add('is-done');
            const mark = step.querySelector('.setup-mark');
            if (mark && check) mark.replaceChildren(check.cloneNode(true));
            const title = step.querySelector('.setup-text > strong, .setup-text > h3');
            if (title) {
                const done = document.createElement('span');
                done.className = 'sr-only';
                done.textContent = ' (done)';
                title.after(done);
            }
        });
    };

    /** Show the list as it is now. If this read fails the announcement still stands; Refresh status shows the rest. */
    const showList = async () => {
        try {
            const response = await fetch('/portal/devices.php', { credentials: 'same-origin', cache: 'no-store', redirect: 'manual' });
            if (!response.ok) return;
            const fresh = new DOMParser().parseFromString(await response.text(), 'text/html').getElementById('portal-device-list');
            if (fresh && fresh.hasAttribute('data-loaded')) list.replaceChildren(...[...fresh.childNodes].map(node => document.importNode(node, true)));
        } catch { /* keep the list that is on screen */ }
    };

    const check = async () => {
        if (busy || document.hidden) return;
        if (Date.now() - started > FOR_MS) return stop(wait.dataset.stoppedText);
        busy = true;
        try {
            const computers = await read();
            if (computers === 'ended' || computers === 'too many') return stop(withoutScript);
            if (computers === null) return;
            if (known === null) { known = new Set(computers.map(computer => computer.reference)); return; }
            const added = computers.filter(computer => !known.has(computer.reference));
            if (added.length === 0) return;
            stop(added[0].label + ' ' + wait.dataset.foundText);
            finishSteps();
            await showList();
        } finally {
            busy = false;
        }
    };

    wait.textContent = wait.dataset.watchingText;
    timer = setInterval(check, EVERY_MS);
    if (known === null) check();
})();
