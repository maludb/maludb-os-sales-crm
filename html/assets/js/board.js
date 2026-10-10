/* The board's drag (docs/build-specs/pipeline-board.md §2), Projects' board.js adapted: an enhancement over each card's "Move to…" select.
 * A drop on an open stage's column (or a new place in the same one) POSTs deal_move with the column's stage and the card's index; the answer is the
 * columns region re-rendered — the server's truth. A refusal (422 — the stage's required fields) shows the flash naming them and the region re-renders, so
 * the card snaps back. A drop on Won or Lost opens that form as a full page, never closing a deal silently. While a drag is in progress the body carries
 * data-dragging and the 30 s poll waits. Nothing here runs without SortableJS; the selects stand alone. */
(function () {
  function region() { return document.getElementById('board-columns'); }
  function refresh() { if (window.htmx) { htmx.trigger(document.body, 'dealChanged'); } }
  window.bindBoard = function () {
    if (!window.Sortable) return;
    document.querySelectorAll('#board-columns [data-board-droppable]').forEach(function (col) {
      if (col.__boardSortable) return;
      col.__boardSortable = Sortable.create(col, {
        group: 'board',
        animation: 120,
        draggable: '.board-card',
        ghostClass: 'board-card-ghost',
        filter: 'select, option, a, summary, button, input',
        preventOnFilter: false,
        delay: 120, delayOnTouchOnly: true,
        onStart: function () { document.body.setAttribute('data-dragging', '1'); },
        onEnd: function (evt) {
          document.body.removeAttribute('data-dragging');
          var card = evt.item, to = evt.to, from = evt.from;
          if (!to || (to === from && evt.oldIndex === evt.newIndex)) return;
          var id = card.dataset.dealId;
          if (to.dataset.closed) {                                        // Won or Lost: the form, never a silent close
            from.insertBefore(card, from.children[evt.oldIndex] || null);
            window.location.assign('/deals/' + id + '/' + to.dataset.closed);
            return;
          }
          var position = Array.prototype.indexOf.call(to.querySelectorAll('.board-card'), card);
          var r = region();
          if (!window.htmx || !r) return;
          htmx.ajax('POST', '/deals/' + id + '/move', { target: '#board-columns', swap: 'outerHTML',
            values: { deal: id, to_stage: to.dataset.stageId, position: position < 0 ? 0 : position, on: 'board', board_query: r.dataset.query || '' } });
        }
      });
    });
  };
  if (window.__boardListeners) return;
  window.__boardListeners = true;
  document.addEventListener('DOMContentLoaded', window.bindBoard);
  window.addEventListener('load', window.bindBoard);
  document.body.addEventListener('htmx:load', function () { if (window.bindBoard) { window.bindBoard(); } });
  document.body.addEventListener('htmx:afterSwap', function () { if (window.bindBoard) { window.bindBoard(); } });
  document.body.addEventListener('htmx:afterRequest', function (e) {
    var xhr = e.detail && e.detail.xhr, path = e.detail && e.detail.pathInfo ? (e.detail.pathInfo.requestPath || '') : '';
    if (xhr && xhr.status === 422 && /\/deals\/\d+\/move/.test(path) && region()) { refresh(); }   // refused: the card snaps back
  });
})();
