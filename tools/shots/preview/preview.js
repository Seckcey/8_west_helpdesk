// Preview-only helper (never loaded by the app): pick the theme with ?theme=dark|light|system
// and show a small switch in the corner. The choice is kept in the address, not in storage.
(function () {
  var params = new URLSearchParams(location.search);
  var theme = params.get('theme') || 'dark';
  document.documentElement.dataset.theme = theme;
  document.addEventListener('DOMContentLoaded', function () {
    var bar = document.createElement('div');
    bar.className = 'preview-switch';
    ['dark', 'light', 'system'].forEach(function (name) {
      var link = document.createElement('a');
      var next = new URLSearchParams(location.search);
      next.set('theme', name);
      link.href = '?' + next.toString();
      link.textContent = name;
      if (name === theme) link.setAttribute('aria-current', 'true');
      bar.appendChild(link);
    });
    document.body.appendChild(bar);
    var style = document.createElement('link');
    style.rel = 'stylesheet';
    style.href = 'preview.css';
    document.head.appendChild(style);
  });
})();
