/* bulk.js — the bulk bar (shared/bulk-bar.php): "Select this page", the count of ticks, a confirm before a delete. Delegated on the document, so an HTMX swap of the page keeps it.
   Without JavaScript the ticks and the buttons still post the form (every button has its own formaction); only the live count and the confirm are lost. */
(function () {
  'use strict';
  function ticks() { return Array.prototype.slice.call(document.querySelectorAll('input.bulk-tick[form="bulk-bar"]')); }
  function refresh() {
    var out = document.getElementById('bulk-count');
    if (!out) return;
    var n = ticks().filter(function (t) { return t.checked; }).length;
    out.textContent = n + ' selected';
    var all = document.getElementById('bulk-select-all');
    if (all) { var t = ticks(); all.checked = t.length > 0 && n === t.length; all.indeterminate = n > 0 && n < t.length; }
    var scopeAll = document.getElementById('bulk-scope-all');
    var scopeSel = document.getElementById('bulk-scope-selected');
    if (scopeSel && scopeAll && n > 0 && !scopeAll.checked) { scopeSel.checked = true; }
  }
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!t) return;
    if (t.id === 'bulk-select-all') {
      ticks().forEach(function (x) { x.checked = t.checked; });
      var sel = document.getElementById('bulk-scope-selected');
      if (sel && t.checked) { sel.checked = true; }
      refresh();
    } else if (t.classList && t.classList.contains('bulk-tick')) {
      refresh();
    }
  });
  document.addEventListener('click', function (e) {
    var b = e.target && e.target.closest ? e.target.closest('button[data-confirm]') : null;
    if (b && !window.confirm(b.getAttribute('data-confirm'))) { e.preventDefault(); }
  });
  document.addEventListener('htmx:afterSettle', refresh);
  document.addEventListener('DOMContentLoaded', refresh);
})();
