(() => {
  'use strict';
  const main = document.querySelector('main[data-csrf]');
  if (!main) return;
  const token = main.dataset.csrf;
  for (const group of main.querySelectorAll('.portal-guide-vote')) {
    const article = group.dataset.article;
    const buttons = group.querySelectorAll('button[data-helpful]');
    const status = group.querySelector('[role="status"]');
    for (const button of buttons) {
      button.addEventListener('click', async () => {
        const helpful = button.dataset.helpful === '1';
        for (const choice of buttons) choice.disabled = true;
        status.textContent = '';
        try {
          const response = await fetch('/portal/guide_vote.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type':'application/json', 'X-Portal-CSRF':token},
            body: JSON.stringify({article, helpful}),
          });
          if (!response.ok) throw new Error();
          const result = await response.json();
          if (result.ok !== true || result.vote?.article !== article || result.vote.helpful !== helpful) throw new Error();
          for (const choice of buttons) choice.setAttribute('aria-pressed', String(choice === button));
          status.textContent = 'Thanks for telling us.';
        } catch {
          status.textContent = "We couldn't save that. Try again.";
        } finally {
          for (const choice of buttons) choice.disabled = false;
        }
      });
    }
  }
})();
