/* tag-editor.js — the tag editor's suggestions (slice 1; shared/tag-editor.php). The field is a plain input with a <datalist>: typing asks the
   record picker's `tag` source (GET /pick/?source=tag&q=…, the same endpoint the picker modal uses — nothing logged) and fills the datalist
   with the labels it answers. Without JavaScript the field still takes any label (a new one makes a tag). Delegated, so an HTMX swap keeps it. */
(function () {
  'use strict';
  var timer = null;
  var last = null;
  function fill(input) {
    var list = document.getElementById(input.getAttribute('list'));
    if (!list) return;
    var q = input.value.trim();
    if (q === last) return;
    last = q;
    fetch('/pick/?source=tag&q=' + encodeURIComponent(q), { headers: { 'HX-Request': 'true' }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.text() : ''; })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        list.innerHTML = '';
        doc.querySelectorAll('[data-label]').forEach(function (row) {
          var o = document.createElement('option');
          o.value = row.getAttribute('data-label');
          list.appendChild(o);
        });
      })
      .catch(function () { /* suggestions are a convenience; the field works without them */ });
  }
  document.addEventListener('input', function (e) {
    var t = e.target;
    if (!t || t.id !== 'tag-editor-field-tag') return;
    clearTimeout(timer);
    timer = setTimeout(function () { fill(t); }, 250);
  });
  document.addEventListener('focusin', function (e) {
    if (e.target && e.target.id === 'tag-editor-field-tag') { last = null; fill(e.target); }
  });
})();
