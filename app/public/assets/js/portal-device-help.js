// Read-only status refresh; only explicit POST forms can authorize device work.
(() => {
  if (!document.querySelector('[data-operation-pending="true"]')) return;
  const refresh = () => {
    if (document.visibilityState === 'visible' && !document.querySelector('#portal-chat-panel:not([hidden])')
      && !document.querySelector('.portal-device-help input:checked') && !document.activeElement?.closest('form')) location.reload();
    else setTimeout(refresh, 10000);
  };
  setTimeout(refresh, 10000);
})();
