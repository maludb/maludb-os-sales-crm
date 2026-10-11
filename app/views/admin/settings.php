<?php /** The application's settings (screen `admin-settings`; ids settings-form, settings-group-{group}, settings-field-{name}, settings-save-btn). Data: row (settings()), pipelines (live, for the default), notice */
$field = static function (string $name, array $spec, array $row, array $pipelines): string {
    [$group, $kind, $label, $column, $help] = $spec;
    $v = $row[$column] ?? null;
    $id = 'settings-field-' . str_replace('_', '-', $name);
    $parts = explode(':', $kind);
    $help = $help === '' ? '' : '<div class="form-text">' . e($help) . '</div>';
    $lab = '<label class="form-label" for="' . $id . '">' . e($label) . '</label>';
    switch ($parts[0]) {
        case 'text':
            return '<div class="col-md-6">' . $lab . '<input type="text" class="form-control btn-touch" id="' . $id . '" name="' . $name . '" value="' . e((string) $v) . '" maxlength="' . (int) $parts[1] . '">' . $help . '</div>';
        case 'currency':
            return '<div class="col-md-3">' . $lab . '<input type="text" class="form-control btn-touch text-uppercase" id="' . $id . '" name="' . $name . '" value="' . e((string) $v) . '" maxlength="3" pattern="[A-Za-z]{3}" required>' . $help . '</div>';
        case 'month':
            $o = '';
            foreach (MONTH_NAMES as $n => $mn) { $o .= '<option value="' . $n . '"' . ((int) $v === $n ? ' selected' : '') . '>' . e($mn) . '</option>'; }
            return '<div class="col-md-3">' . $lab . '<select class="form-select btn-touch" id="' . $id . '" name="' . $name . '">' . $o . '</select>' . $help . '</div>';
        case 'tz':
            return '<div class="col-md-6">' . $lab . '<input type="text" class="form-control btn-touch" id="' . $id . '" name="' . $name . '" value="' . e((string) $v) . '" list="settings-timezones" maxlength="64" required autocomplete="off">' . $help . '</div>';
        case 'pipeline':
            $o = '';
            foreach ($pipelines as $p) { $o .= '<option value="' . (int) $p['id'] . '"' . ((int) $v === (int) $p['id'] ? ' selected' : '') . '>' . e($p['name']) . '</option>'; }
            return '<div class="col-md-6">' . $lab . '<select class="form-select btn-touch" id="' . $id . '" name="' . $name . '">' . $o . '</select>' . $help . '</div>';
        case 'int':
        case 'int?':
            return '<div class="col-md-6">' . $lab . '<input type="number" class="form-control btn-touch" id="' . $id . '" name="' . $name . '" value="' . e((string) $v) . '" min="' . (int) $parts[1] . '" max="' . (int) $parts[2] . '"' . ($parts[0] === 'int?' ? ' placeholder="off"' : ' required') . '>' . $help . '</div>';
        case 'decimal':
            return '<div class="col-md-6">' . $lab . '<input type="number" class="form-control btn-touch" id="' . $id . '" name="' . $name . '" value="' . e((string) $v) . '" min="' . $parts[1] . '" max="' . $parts[2] . '" step="0.01" required>' . $help . '</div>';
        case 'choice':
            $o = '';
            foreach (settings_choices($name) as $k => $l) { $o .= '<option value="' . e($k) . '"' . ($v === $k ? ' selected' : '') . '>' . e($l) . '</option>'; }
            return '<div class="col-md-6">' . $lab . '<select class="form-select btn-touch" id="' . $id . '" name="' . $name . '">' . $o . '</select>' . $help . '</div>';
        case 'bool':
            return '<div class="col-12"><input type="hidden" name="' . $name . '" value="no"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="' . $name . '" value="yes" id="' . $id . '"' . ($v ? ' checked' : '') . '>'
                . '<label class="form-check-label" for="' . $id . '">' . e($label) . '</label></div>' . $help . '</div>';
        case 'memo':
            return '<div class="col-12">' . $lab . '<textarea class="form-control" id="' . $id . '" name="' . $name . '" rows="3" maxlength="' . (int) $parts[1] . '">' . e((string) $v) . '</textarea>' . $help . '</div>';
    }
    return '';
}; ?>
<?= view('shared/header.php', ['id' => 'admin-settings', 'title' => 'Settings', 'crumbs' => [['Today', '/'], ['Admin', '/admin'], ['Settings', null]]]) ?>
<div class="main-content" id="admin-settings-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="post" action="/admin/settings/save.php" hx-post="/admin/settings/save.php" hx-target="#flash" id="settings-form"><?= csrf_field() ?>
        <datalist id="settings-timezones"><?php foreach (timezone_identifiers_list() as $tz): ?><option value="<?= e($tz) ?>"><?php endforeach; ?></datalist>
        <?php foreach (SETTINGS_GROUPS as $g => $title): ?>
        <div class="card mb-3" id="settings-group-<?= e($g) ?>"><div class="card-header"><h5 class="card-title mb-0"><?= e($title) ?></h5></div><div class="card-body"><div class="row g-3">
            <?php foreach (SETTINGS_FIELDS as $name => $spec) { if ($spec[0] === $g) { echo $field($name, $spec, $row, $pipelines); } } ?>
        </div></div></div>
        <?php endforeach; ?>
        <div class="fs-12 text-muted mb-2">The Sales Assistant's morning note and proposals are set on <?= hx_link('/admin/agents', 'the agents screen', 'fw-semibold') ?>.</div>
        <div class="admin-save-bar d-flex gap-2 mb-3"><button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="settings-save-btn">Save the settings</button>
            <?= hx_link('/admin/settings', 'Cancel', 'btn btn-light btn-touch', 'id="settings-cancel-btn"') ?></div>
    </form>
</div>
