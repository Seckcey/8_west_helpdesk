/* Your devices, while a setup file is waiting to be used: copy its link, and watch for the new
   computer so the list updates by itself. Loaded only in that state. It reads this same page and
   changes nothing on the server. */
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
    const names = root => [...root.querySelectorAll('.portal-device-card h3')].map(heading => heading.textContent.trim());
    const known = names(list);
    const started = Date.now();
    let timer = null;

    const stop = text => {
        if (timer !== null) clearInterval(timer);
        timer = null;
        wait.textContent = text;
        wait.removeAttribute('data-device-wait');
    };
    const found = () => {
        const step = wait.closest('.setup-step');
        if (step) { step.classList.remove('is-later'); step.classList.add('is-done'); }
        stop(wait.dataset.foundText);
    };

    const check = async () => {
        if (document.hidden) return;
        if (Date.now() - started > FOR_MS) return stop(wait.dataset.stoppedText);
        let page;
        try {
            const response = await fetch('/portal/devices.php', { credentials: 'same-origin', cache: 'no-store', redirect: 'manual' });
            // A redirect means the sign-in has ended: stop at once instead of asking again every few seconds.
            if (response.type === 'opaqueredirect' || response.status === 401 || response.status === 403) return stop(wait.dataset.stoppedText);
            if (!response.ok) return;
            page = new DOMParser().parseFromString(await response.text(), 'text/html');
        } catch {
            return; // a missed check is not an error worth showing; the next one tries again
        }
        const fresh = page.getElementById('portal-device-list');
        if (!fresh) return stop(wait.dataset.stoppedText); // signed out, or the page changed shape: stop quietly
        const now = names(fresh);
        if (now.length > known.length || now.some(name => !known.includes(name))) {
            list.replaceChildren(...[...fresh.childNodes].map(node => document.importNode(node, true)));
            found();
        }
    };

    timer = setInterval(check, EVERY_MS);
})();
