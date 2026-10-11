/* The stage order's drag (docs/build-specs/admin-settings.md), board.js's pattern: an enhancement over each row's Move up / Move down buttons.
 * Dropping a row POSTs stage_reorder with the pipeline and the open stages' ids in the new order; the answer is that pipeline's stage list re-rendered — the server's truth, swapped in.
 * A refusal (422) shows the flash and the page region is fetched again, so a row that was refused snaps back. Won, Lost and archived rows have no handle and stay put.
 * Nothing here runs without SortableJS; the buttons stand alone. */
(function () {
  function bind() {
    if (!window.Sortable) return;
    document.querySelectorAll('[data-stage-sortable]').forEach(function (list) {
      if (list.__stageSortable) return;
      list.__stageSortable = Sortable.create(list, {
        animation: 120,
        handle: '.stage-handle',
        draggable: '.stage-row',
        ghostClass: 'stage-row-ghost',
        filter: 'a, button, input, select',
        preventOnFilter: false,
        delay: 120, delayOnTouchOnly: true,
        onEnd: function (evt) {
          if (evt.oldIndex === evt.newIndex || !window.htmx) return;
          var ids = [];
          list.querySelectorAll('.stage-row').forEach(function (row) {
            if (row.querySelector('.stage-handle')) ids.push(row.dataset.stageId);
          });
          htmx.ajax('POST', '/admin/stages/reorder.php', { target: '#' + list.id, swap: 'outerHTML',
            values: { pipeline: list.dataset.pipeline, stages: ids.join(','), on: 'list' } });
        }
      });
    });
  }
  if (window.__stageOrderListeners) return;
  window.__stageOrderListeners = true;
  document.addEventListener('DOMContentLoaded', bind);
  window.addEventListener('load', bind);
  document.body.addEventListener('htmx:load', bind);
  document.body.addEventListener('htmx:afterSwap', bind);
  document.body.addEventListener('htmx:afterRequest', function (e) {
    var xhr = e.detail && e.detail.xhr, path = e.detail && e.detail.pathInfo ? (e.detail.pathInfo.requestPath || '') : '';
    if (xhr && xhr.status >= 400 && /\/admin\/stages\/reorder/.test(path) && window.htmx) {      // refused: fetch the region again, the row snaps back
      htmx.ajax('GET', window.location.pathname + window.location.search, { target: '#page-content', swap: 'innerHTML' });
    }
  });
})();
