/* The calendar's drag (docs/build-specs/activities-calendar.md §1), board.js's shape: an enhancement over each activity's Reschedule form. A chip dropped
 * on another day (the month — its time of day kept), another hour (the week and the day — its minutes kept) or the all-day strip POSTs
 * activity_reschedule; the answer is the calendar's region re-rendered by the server. A refusal (422/403) shows the flash and the region re-renders, so
 * the chip snaps back. Also: a phone that opened the calendar with no view chosen switches to the agenda; the activity form shows the fields of the kind
 * chosen (a due time for a task or a deadline, a start and an end for a call, a meeting or a lunch; the repeat for a task). Nothing here runs without
 * SortableJS and HTMX; the links and the forms stand alone. */
(function () {
  function region() { return document.getElementById('calendar-grid'); }
  function refresh() { if (window.htmx) { htmx.trigger(document.body, 'activityChanged'); } }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  window.bindCalendar = function () {
    var r = region();
    if (r && window.Sortable) {
      r.querySelectorAll('[data-cal-drop]').forEach(function (cell) {
        if (cell.__calSortable) return;
        cell.__calSortable = Sortable.create(cell, {
          group: 'calendar', animation: 120, draggable: '.cal-chip[data-draggable]', ghostClass: 'cal-chip-ghost',
          filter: '.cal-new, .cal-more', preventOnFilter: false, delay: 150, delayOnTouchOnly: true,
          onStart: function () { document.body.setAttribute('data-dragging', '1'); },
          onEnd: function (evt) {
            document.body.removeAttribute('data-dragging');
            var chip = evt.item, to = evt.to, from = evt.from;
            if (!to || to === from) return;
            var at = to.dataset.date, allDay = null;
            if (to.dataset.allDay) { allDay = 'yes'; }
            else if (to.dataset.hour) { at += 'T' + pad(parseInt(to.dataset.hour, 10)) + ':' + (chip.dataset.time || '00:00').split(':')[1]; if (chip.dataset.allDay) { allDay = 'no'; } }
            else if (!chip.dataset.allDay) { at += 'T' + (chip.dataset.time || '09:00'); }
            var rr = region();
            if (!window.htmx || !rr) return;
            var values = { activity: chip.dataset.activityId, at: at, on: 'calendar', calendar_query: rr.dataset.query || '' };
            if (allDay !== null) { values.all_day = allDay; }
            htmx.ajax('POST', '/activities/' + chip.dataset.activityId + '/reschedule', { target: '#calendar-grid', swap: 'outerHTML', values: values });
          }
        });
      });
    }
    var content = document.getElementById('calendar-content');
    if (content && content.dataset.autoView === '1' && r && r.dataset.view === 'month' && window.innerWidth < 768 && window.htmx && !content.__autoSwitched) {
      content.__autoSwitched = true;
      var q = new URLSearchParams(r.dataset.query || ''); q.set('view', 'agenda'); q.set('auto', '1');
      htmx.ajax('GET', '/calendar/grid?' + q.toString(), { target: '#calendar-grid', swap: 'outerHTML' });
    }
    var filters = document.getElementById('calendar-filters');
    if (filters && r) {                                                  // the filter form follows the region's view and day
      var v = filters.querySelector('input[name=view]'), a = filters.querySelector('input[name=at]');
      if (v && r.dataset.view) v.value = r.dataset.view;
      if (a && r.dataset.at) a.value = r.dataset.at;
    }
    var form = document.getElementById('activity-form');
    if (form && !form.__kindBound) {
      form.__kindBound = true;
      var show = function () {
        var k = (form.querySelector('input[name=kind]:checked') || {}).value || form.dataset.kind || 'call';
        var timed = ['call', 'meeting', 'lunch'].indexOf(k) >= 0;
        form.querySelectorAll('[data-kind-when]').forEach(function (el) {
          var w = el.dataset.kindWhen;
          el.hidden = w === 'timed' ? !timed : (w === 'due' ? timed : k !== 'task');
        });
      };
      form.addEventListener('change', function (e) { if (e.target && e.target.name === 'kind') { show(); } });
      show();
    }
  };
  if (window.__calendarListeners) return;
  window.__calendarListeners = true;
  document.addEventListener('DOMContentLoaded', window.bindCalendar);
  window.addEventListener('load', window.bindCalendar);
  document.body.addEventListener('htmx:load', function () { if (window.bindCalendar) { window.bindCalendar(); } });
  document.body.addEventListener('htmx:afterSwap', function () { if (window.bindCalendar) { window.bindCalendar(); } });
  document.body.addEventListener('htmx:afterRequest', function (e) {
    var xhr = e.detail && e.detail.xhr, path = e.detail && e.detail.pathInfo ? (e.detail.pathInfo.requestPath || '') : '';
    if (xhr && xhr.status >= 400 && /\/activities\/\d+\/reschedule/.test(path) && region()) { refresh(); }   // refused: the chip snaps back
  });
})();
