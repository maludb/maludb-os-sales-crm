<?php /** The calendar (screen `calendar`): the header (New, whose calendar, the kinds), then the region #calendar-grid (nav, the view, the legend).
 * Data: cal, teams, people, auto (no view asked or remembered: calendar.js may switch a phone to the agenda), may, notice */
$who = $cal['who']; ?>
<?= view('shared/header.php', ['id' => 'calendar', 'title' => 'Calendar', 'crumbs' => [['Today', '/'], ['Calendar', null]],
    'action' => $may['create'] && nav_built('/activities/form.php') ? hx_link('/activities/new?at=' . rawurlencode($cal['at'] === $cal['today'] ? '' : $cal['at']), '<i class="feather-plus me-1"></i>New', 'btn btn-primary btn-touch', 'id="calendar-new-btn"') : '']) ?>
<div class="main-content" id="calendar-content" data-auto-view="<?= $auto ? '1' : '0' ?>">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/calendar" class="d-flex flex-wrap gap-2 align-items-end mb-2" id="calendar-filters" hx-get="/calendar/grid" hx-target="#calendar-grid" hx-swap="outerHTML" hx-trigger="change" hx-push-url="false">
        <input type="hidden" name="view" value="<?= e($cal['view']) ?>"><input type="hidden" name="at" value="<?= e($cal['at']) ?>">
        <?php if ($teams !== [] || $people !== []): ?>
        <div><label class="form-label fs-11 mb-0" for="calendar-who">Whose</label>
            <select class="form-select form-select-sm btn-touch" name="who" id="calendar-who">
                <option value="me"<?= $who['kind'] === 'me' ? ' selected' : '' ?>>Mine</option>
                <?php if ($teams !== []): ?><optgroup label="Teams"><?php foreach ($teams as $t): $k = 'team:' . (int) $t['department_id']; ?><option value="<?= e($k) ?>"<?= $who['key'] === $k ? ' selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
                <?php if ($people !== []): ?><optgroup label="People"><?php foreach ($people as $p): ?><option value="<?= (int) $p['member_id'] ?>"<?= $who['key'] === (string) (int) $p['member_id'] ? ' selected' : '' ?>><?= e($p['display_name']) ?><?= $p['member_kind'] === 'agent' ? ' (agent)' : '' ?></option><?php endforeach; ?></optgroup><?php endif; ?>
            </select></div>
        <?php endif; ?>
        <fieldset class="d-flex flex-wrap gap-1 align-items-center" id="calendar-kinds"><legend class="visually-hidden">Kinds</legend>
            <?php foreach (ACTIVITY_KINDS as $k => $label): ?>
                <input type="checkbox" class="btn-check" name="kinds[]" value="<?= e($k) ?>" id="calendar-kind-<?= e($k) ?>" autocomplete="off"<?= in_array($k, $cal['kinds'], true) ? ' checked' : '' ?>>
                <label class="btn btn-sm btn-outline-secondary cal-kind-toggle cal-kind-<?= e($k) ?>" for="calendar-kind-<?= e($k) ?>"><i class="<?= e(activity_kind_icon($k)) ?> me-1"></i><?= e($label) ?></label>
            <?php endforeach; ?>
        </fieldset>
        <noscript><button type="submit" class="btn btn-sm btn-light">Show</button></noscript>
    </form>
    <?= calendar_region_html($cal) ?>
    <?php if ($may['feed']): ?><div class="fs-11 text-muted mt-2" id="calendar-feed-hint"><i class="feather-link me-1"></i>Your activities in Google, Outlook or Apple Calendar: <?= hx_link('/settings/#settings-feed', 'your calendar link') ?>.</div><?php endif; ?>
</div>
