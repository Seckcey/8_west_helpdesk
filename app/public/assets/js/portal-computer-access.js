(() => {
    const code = document.getElementById('computer-access-code');
    const entry = document.getElementById('computer-access-code-entry');

    function readLink() {
        if (!window.location.hash) return;
        const match = /^#token=([a-f0-9]{64})$/.exec(window.location.hash);
        code.value = match ? match[1] : '';
        code.type = match ? 'hidden' : 'password';
        entry.hidden = Boolean(match);
        if (match) {
            window.history.replaceState(window.history.state, '', window.location.pathname + window.location.search);
        }
    }

    readLink();
    window.addEventListener('hashchange', readLink);
})();
