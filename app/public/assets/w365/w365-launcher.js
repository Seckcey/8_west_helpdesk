/*! w365-launcher.js — 8 West IT 365 suite chrome behaviour, version 0.2.0.
 *  VENDORED copy published from Seckcey/8_west_suite_ui; do not edit inside a product repo.
 *
 *  What it does, and only this:
 *    - opens and closes the app drawer and the account menu (click, Escape, outside click,
 *      focus return, a Tab cycle inside the open panel, Arrow keys inside the account menu);
 *    - swaps a drawer tile's image for a letter mark when the image fails to load.
 *
 *  What it never does: inject markup, fetch anything, read or write storage, touch the theme, or run
 *  inline. Idempotent: init() may be called any number of times (Livewire navigation calls
 *  it again) and binds each element once. Null-guarded: pages without a header are a no-op.
 */
(function (global, document) {
  'use strict';

  var VERSION = '0.2.0';
  var BOUND = 'data-w365-bound';
  var openPopover = null;

  function all(root, selector) {
    return Array.prototype.slice.call(root.querySelectorAll(selector));
  }

  function focusables(panel) {
    return all(panel, 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')
      .filter(function (el) { return el.getClientRects().length > 0; });
  }

  function setExpanded(button, open) {
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  function closeOpen(returnFocus) {
    if (!openPopover) return;
    var p = openPopover;
    openPopover = null;
    p.panel.hidden = true;
    setExpanded(p.button, false);
    if (returnFocus) p.button.focus();
  }

  function bindPopover(root, buttonSelector, panelSelector, options) {
    var button = root.querySelector(buttonSelector);
    var panel = root.querySelector(panelSelector);
    if (!button || !panel || button.hasAttribute(BOUND)) return;
    button.setAttribute(BOUND, '1');

    button.addEventListener('click', function () {
      var isOpen = !!openPopover && openPopover.panel === panel;
      closeOpen(false);
      if (isOpen) return;
      panel.hidden = false;
      setExpanded(button, true);
      openPopover = { button: button, panel: panel };
      var first = panel.querySelector(options.focusSelector);
      if (first) first.focus();
    });

    panel.addEventListener('keydown', function (event) {
      var items;
      if (event.key === 'Tab') {
        items = focusables(panel);
        if (items.length === 0) return;
        var first = items[0];
        var last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      } else if (options.arrows && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
        items = focusables(panel);
        if (items.length === 0) return;
        var index = items.indexOf(document.activeElement);
        if (index < 0) index = 0;
        else index = event.key === 'ArrowDown' ? (index + 1) % items.length : (index - 1 + items.length) % items.length;
        items[index].focus();
        event.preventDefault();
      }
    });
  }

  function bindTileFallbacks(root) {
    all(root, '.w365-tile-mark img').forEach(function (img) {
      if (img.hasAttribute(BOUND)) return;
      img.setAttribute(BOUND, '1');
      var swap = function () {
        var mark = img.parentNode;
        if (!mark) return;
        var tile = mark.parentNode;
        var nameEl = tile ? tile.querySelector('.w365-tile-name') : null;
        var name = img.getAttribute('data-initial') || (nameEl ? nameEl.textContent : '') || '';
        mark.textContent = (name.trim().charAt(0) || '?').toUpperCase();
      };
      if (img.complete && img.naturalWidth === 0) swap();
      else img.addEventListener('error', swap);
    });
  }

  function init(root) {
    root = root || document;
    all(root, '[data-w365-drawer]').forEach(function (r) {
      bindPopover(r, '[data-w365-drawer-button]', '[data-w365-drawer-panel]', { focusSelector: '.w365-tile, a[href], button', arrows: false });
    });
    all(root, '[data-w365-account]').forEach(function (r) {
      bindPopover(r, '[data-w365-account-button]', '[data-w365-account-menu]', { focusSelector: '[role="menuitem"]', arrows: true });
    });
    bindTileFallbacks(root);
    all(root, '.w365-cluster').forEach(function (c) { c.setAttribute('data-w365-version', VERSION); });
  }

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && openPopover) closeOpen(true);
  });
  document.addEventListener('click', function (event) {
    if (!openPopover) return;
    var t = event.target;
    if (openPopover.panel.contains(t) || openPopover.button.contains(t)) return;
    closeOpen(false);
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { init(); });
  } else {
    init();
  }
  document.addEventListener('livewire:navigated', function () { init(); });

  global.W365 = { version: VERSION, init: init, close: function () { closeOpen(false); } };
})(window, document);
