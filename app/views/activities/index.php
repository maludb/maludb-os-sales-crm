<?php /** The activities list (activity-list): New · Log a call · Log a meeting; whose, the state, the kinds, the dates, words; saved views as chips; the rows
 * (activities/partials/row.php); paging; Save this view. Data: result, filters, record, views, view, teams, people, tz, back, me, may, notice */
$q = static fn (array $over = []): string => '/activities' . (($s = activity_query($filters, $over)) === '' ? '' : '?' . $s);
$who = $filters['who'];
$recordQuery = $record === null ? '' : $record[0] . '=' . $record[1]; ?>
<?= view('shared/header.php', ['id' => 'activities', 'title' => 'Activities', 'crumbs' => [['Today', '/'], ['Activities', null]],
    'action' => $may['create'] && nav_built('/activities/form.php') ? '<div class="d-flex gap-1">'
        . (nav_built('/log/call.php') ? hx_link('/log/call' . ($recordQuery !== '' ? '?' . $recordQuery : ''), '<i class="feather-phone me-1"></i><span class="d-none d-sm-inline">Log call</span>', 'btn btn-light btn-touch', 'id="activities-log-call-btn" aria-label="Log a call"') : '')
        . (nav_built('/log/meeting.php') ? hx_link('/log/meeting' . ($recordQuery !== '' ? '?' . $recordQuery : ''), '<i class="feather-users me-1"></i><span class="d-none d-sm-inline">Log meeting</span>', 'btn btn-light btn-touch', 'id="activities-log-meeting-btn" aria-label="Log a meeting"') : '')
        . hx_link('/activities/new' . ($recordQuery !== '' ? '?' . $recordQuery : ''), '<i class="feather-plus me-1"></i>New', 'btn btn-primary btn-touch', 'id="activities-new-btn"') . '</div>' : '']) ?>
<div class="main-content" id="activities-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($record !== null): ?><div class="alert alert-light py-2 fs-12 d-flex align-items-center gap-2" id="activities-record">Activities of the <?= e($record[0]) ?> <?= hx_link('/' . $record[0] . 's/' . $record[1], e($record[2]), 'fw-semibold') ?>
        <?= hx_link($q([$record[0] => null, 'who' => 'me']), 'Show mine instead', 'ms-auto') ?></div><?php endif; ?>
    <?php if ($views !== []): ?><div class="d-flex flex-wrap gap-1 mb-2" id="activities-views">
        <?php foreach ($views as $v): ?><?= hx_link('/activities?view=' . $v['saved_view_id'], e($v['name']), 'btn btn-sm ' . ($view !== null && $view['saved_view_id'] === $v['saved_view_id'] ? 'btn-primary' : 'btn-light'), 'id="activities-view-' . $v['saved_view_id'] . '"') ?><?php endforeach; ?></div><?php endif; ?>
    <ul class="nav nav-pills flex-nowrap overflow-auto mb-2" id="activities-states">
        <?php foreach (ACTIVITY_STATES as $k => $label): ?><li class="nav-item"><?= hx_link($q(['state' => $k, 'page' => null]), e($label), 'nav-link text-nowrap' . ($filters['state'] === $k ? ' active' : ''), 'id="activities-state-' . $k . '"') ?></li><?php endforeach; ?>
    </ul>
    <form method="get" action="/activities" class="row g-2 align-items-end mb-3" id="activities-filters" hx-get="/activities" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="true">
        <input type="hidden" name="state" value="<?= e($filters['state']) ?>">
        <?php foreach (['account', 'contact', 'deal', 'lead'] as $k): if ($filters[$k] !== null): ?><input type="hidden" name="<?= $k ?>" value="<?= (int) $filters[$k] ?>"><?php endif; endforeach; ?>
        <div class="col-6 col-md-3"><label class="form-label fs-11 mb-0" for="activities-who">Whose</label>
            <select class="form-select form-select-sm btn-touch" name="who" id="activities-who">
                <option value="me"<?= $who['kind'] === 'me' ? ' selected' : '' ?>>Mine</option><option value="all"<?= $who['kind'] === 'all' ? ' selected' : '' ?>>Everyone's</option>
                <optgroup label="Teams"><?php foreach ($teams as $t): $k = 'team:' . (int) $t['department_id']; ?><option value="<?= e($k) ?>"<?= $who['key'] === $k ? ' selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></optgroup>
                <optgroup label="People"><?php foreach ($people as $p): if ((int) $p['member_id'] === $me) { continue; } ?><option value="<?= (int) $p['member_id'] ?>"<?= $who['key'] === (string) (int) $p['member_id'] ? ' selected' : '' ?>><?= e($p['display_name']) ?></option><?php endforeach; ?></optgroup>
            </select></div>
        <div class="col-6 col-md-2"><label class="form-label fs-11 mb-0" for="activities-kind">Kind</label>
            <select class="form-select form-select-sm btn-touch" name="kind" id="activities-kind"><option value="">Any</option>
                <?php foreach (ACTIVITY_KINDS as $k => $label): ?><option value="<?= e($k) ?>"<?= $filters['kinds'] === [$k] ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label fs-11 mb-0" for="activities-from">From</label><input type="date" class="form-control form-control-sm btn-touch" name="from" id="activities-from" value="<?= e((string) $filters['from']) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label fs-11 mb-0" for="activities-to">To</label><input type="date" class="form-control form-control-sm btn-touch" name="to" id="activities-to" value="<?= e((string) $filters['to']) ?>"></div>
        <div class="col-8 col-md-2"><label class="form-label fs-11 mb-0" for="activities-q">Words</label><input type="search" class="form-control form-control-sm btn-touch" name="q" id="activities-q" maxlength="120" value="<?= e($filters['q']) ?>" placeholder="Subject"></div>
        <div class="col-4 col-md-1"><button type="submit" class="btn btn-sm btn-light btn-touch w-100" id="activities-filter-btn">Show</button></div>
    </form>
    <div class="card" id="activities-list">
        <?php if ($result['rows'] === []): ?>
            <div class="card-body empty-state" id="activities-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-check-square"></i></span>
                <div><div class="fw-semibold"><?= $filters['state'] === 'overdue' ? 'Nothing overdue' : 'No activities here' ?></div><div class="fs-12 text-muted">Schedule the next step on a deal, or log a call you just made.</div></div></div>
        <?php else: ?>
            <div class="card-header py-2 fs-12 text-muted" id="activities-count"><?= (int) $result['total'] ?> <?= $result['total'] === 1 ? 'activity' : 'activities' ?></div>
            <div class="list-group list-group-flush"><?php foreach ($result['rows'] as $r) { echo view('activities/partials/row.php', ['r' => $r, 'tz' => $tz, 'back' => $back, 'me' => $me, 'show_owner' => $who['kind'] !== 'me']); } ?></div>
        <?php endif; ?>
    </div>
    <?php if ($result['pages'] > 1): ?><nav class="d-flex justify-content-between align-items-center mt-2 fs-12" id="activities-pager">
        <?= $result['page'] > 1 ? hx_link($q(['page' => $result['page'] - 1]), 'Previous', 'btn btn-sm btn-light', 'id="activities-prev"') : '<span></span>' ?>
        <span>Page <?= (int) $result['page'] ?> of <?= (int) $result['pages'] ?></span>
        <?= $result['page'] < $result['pages'] ? hx_link($q(['page' => $result['page'] + 1]), 'Next', 'btn btn-sm btn-light', 'id="activities-next"') : '<span></span>' ?></nav><?php endif; ?>
    <details class="mt-3" id="activities-save-view"><summary class="fs-12 fw-semibold">Save this view</summary>
        <form method="post" action="/views/save.php" hx-post="/views/save.php" hx-target="#flash" class="row g-2 align-items-end mt-1" id="activities-save-view-form"><?= csrf_field() ?>
            <input type="hidden" name="record_kind" value="activity"><input type="hidden" name="layout" value="list">
            <input type="hidden" name="filters[owner]" value="<?= e($who['key']) ?>"><input type="hidden" name="filters[when]" value="<?= e($filters['state']) ?>">
            <?php if ($filters['kinds'] !== []): ?><input type="hidden" name="filters[kind]" value="<?= e(implode(',', $filters['kinds'])) ?>"><?php endif; ?>
            <div class="col-md-5"><label class="form-label fs-11 mb-0" for="activities-save-view-name">Name</label><input type="text" class="form-control form-control-sm btn-touch" name="name" id="activities-save-view-name" maxlength="80" required></div>
            <?php if ($may['share_view']): ?><div class="col-md-4"><label class="form-label fs-11 mb-0" for="activities-save-view-scope">Who sees it</label><select class="form-select form-select-sm btn-touch" name="scope" id="activities-save-view-scope"><option value="personal">Only me</option><option value="global">Everyone</option></select></div><?php endif; ?>
            <div class="col-md-3"><button type="submit" class="btn btn-sm btn-primary btn-touch w-100" id="activities-save-view-btn">Save</button></div>
        </form></details>
</div>
