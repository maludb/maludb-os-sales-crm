<?php
/**
 * Proof — the settings (admin-settings.md "Proof"): every setting of the catalogue saved and read back (the row, the JSON, the page), a field left out staying as it was, every kind of bad value refused with the FIELD named and nothing saved,
 * the default pipeline moving as two facts, the schema's CHECKs still the last word, the rights, and the log (the changed fields; the footer's text never in it).
 */
require __DIR__ . '/lib.php';
$x = run_tag();
$since = last_activity_id();
$snap = q('SELECT * FROM crm_settings WHERE id = 1')[0];
$restore = function () use ($snap): void {
    $set = [];
    foreach ($snap as $k => $v) { if ($k === 'id' || $k === 'updated_at') { continue; } $set[] = "$k = " . ($v === null ? 'NULL' : "'" . str_replace("'", "''", is_bool($v) ? ($v ? 't' : 'f') : (string) $v) . "'"); }
    pg_run('UPDATE crm_settings SET ' . implode(', ', $set) . ' WHERE id = 1');
    pg_run('UPDATE pipelines SET is_default = false WHERE is_default; UPDATE pipelines SET is_default = true WHERE id = ' . (int) $snap['default_pipeline_id']);
};

echo "1. The catalogue, every setting saved and read back\n";
$page = screen(1, '/admin/settings');
$names = array_keys($page['catalogue']);
ok(count($names) === 25 && count($page['groups']) === 7 && $page['groups'] === ['business' => 'The business', 'pipeline' => 'The pipeline', 'duplicates' => 'Duplicates', 'reminders' => 'Reminders and the next step', 'imports' => 'Imports and bulk changes', 'api' => 'The API', 'records' => 'Records'], '25 settings in seven groups: the business, the pipeline, duplicates, reminders and the next step, imports and bulk, the API, records');
$html = html_as(1, '/admin/settings')['body'];
$missing = array_filter($names, static fn (string $n): bool => !str_contains($html, 'name="' . $n . '"'));
ok($missing === [] && str_contains($html, 'id="settings-form"') && str_contains($html, 'id="settings-save-btn"') && str_contains($html, 'id="settings-group-api"'), 'the page has a field for every one, the seven groups and the Save');
$new = ['business_name' => "Acme Widgets $x", 'currency' => 'eur', 'fiscal_year_start_month' => 4, 'timezone' => 'America/Chicago', 'default_pipeline' => 2, 'rotting_days' => 21, 'weighted_method' => 'override_or_stage',
    'forecast_months' => 9, 'duplicates_policy' => 'block_exact_email', 'duplicates_name_similarity' => '0.75', 'default_remind_minutes' => 60, 'next_step_prompt' => 'no', 'lead_untouched_days' => 5, 'account_untouched_days' => 45,
    'import_max_rows' => 20000, 'import_rollback_hours' => 48, 'bulk_sync_rows' => 500, 'key_rate_per_minute' => 30, 'key_rate_per_day' => 2500, 'capture_rate_per_day' => 300, 'tombstone_days' => 14, 'allow_restricted_records' => 'no',
    'ics_days_ahead' => 60, 'ics_days_back' => 10, 'email_footer' => "Acme Widgets — 1 Main St.\nSecret-FOOTER-$x"];
ok(array_keys($new) === $names, 'the proof sets every one of them');
$r = adm('/admin/settings/save.php', $new);
$row = q('SELECT * FROM crm_settings WHERE id = 1')[0];
ok($r['code'] === 200 && $row['business_name'] === "Acme Widgets $x" && $row['currency'] === 'EUR' && (int) $row['fiscal_year_start_month'] === 4 && $row['timezone'] === 'America/Chicago' && (int) $row['default_pipeline_id'] === 2 && (int) $row['rotting_days'] === 21
    && $row['weighted_method'] === 'override_or_stage' && (int) $row['forecast_months'] === 9 && $row['duplicates_policy'] === 'block_exact_email' && (float) $row['duplicates_name_similarity'] === 0.75 && (int) $row['default_remind_minutes'] === 60
    && $row['next_step_prompt'] === false && (int) $row['lead_untouched_days'] === 5 && (int) $row['account_untouched_days'] === 45 && (int) $row['import_max_rows'] === 20000 && (int) $row['import_rollback_hours'] === 48 && (int) $row['bulk_sync_rows'] === 500
    && (int) $row['key_rate_per_minute'] === 30 && (int) $row['key_rate_per_day'] === 2500 && (int) $row['capture_rate_per_day'] === 300 && (int) $row['tombstone_days'] === 14 && $row['allow_restricted_records'] === false
    && (int) $row['ics_days_ahead'] === 60 && (int) $row['ics_days_back'] === 10 && $row['email_footer'] === "Acme Widgets — 1 Main St.\nSecret-FOOTER-$x", 'all 25 are stored: ' . ($r['json']['did'] ?? $r['body']));
$back = screen(1, '/admin/settings')['settings'];
ok($back['currency'] === 'EUR' && $back['default_pipeline'] === 2 && $back['rotting_days'] === 21 && $back['next_step_prompt'] === false && $back['duplicates_name_similarity'] == 0.75 && $back['email_footer'] === $new['email_footer'] && $back['timezone'] === 'America/Chicago', 'JSON reads them back by their form names');
$html = html_as(1, '/admin/settings')['body'];
ok(str_contains($html, 'value="Acme Widgets ' . $x . '"') && str_contains($html, 'value="America/Chicago"') && preg_match('/name="next_step_prompt" value="yes"[^>]*id="settings-field-next-step-prompt"(?! checked)/', $html) === 1 && str_contains($html, '<option value="2" selected>'), 'and so does the form');
ok((int) one('SELECT count(*) FROM pipelines WHERE is_default') === 1 && one('SELECT is_default FROM pipelines WHERE id = 2') === true, 'the default pipeline moved as two facts: pipelines.is_default and crm_settings.default_pipeline_id');
ok(str_contains(html_as(26, '/')['body'], '/pipeline/renewals/board'), 'the menu\'s Pipeline link follows it (renewals)');
$ev = q("SELECT before::text AS b, after::text AS a FROM activity_log WHERE action = 'settings.save' AND id > :s ORDER BY id DESC LIMIT 1", ['s' => $since])[0];
$ja = json_decode($ev['a'], true);
ok(count($ja) === 25 && $ja['currency'] === 'EUR' && $ja['email_footer'] === '(changed)' && !str_contains($ev['a'] . $ev['b'], "Secret-FOOTER-$x"), 'settings.save logs the 25 changed fields, before and after — and "(changed)" for the footer, never its text');
$restore();

echo "2. A field left out stays; the same values change nothing\n";
$r = adm('/admin/settings/save.php', ['forecast_months' => 12]);
$row = q('SELECT * FROM crm_settings WHERE id = 1')[0];
ok($r['code'] === 200 && (int) $row['forecast_months'] === 12 && $row['currency'] === $snap['currency'] && (int) $row['tombstone_days'] === (int) $snap['tombstone_days'], 'one field posted: only it changes');
$n = last_activity_id();
$r = adm('/admin/settings/save.php', ['forecast_months' => 12, 'currency' => $snap['currency']]);
ok($r['code'] === 200 && ($r['json']['did'] ?? '') === 'Nothing to change' && last_activity_id() === $n, 'the same values: "Nothing to change", nothing logged');
$r = adm('/admin/settings/save.php', ['rotting_days' => '']);
ok($r['code'] === 200 && one('SELECT rotting_days FROM crm_settings WHERE id = 1') === null, 'a blank rotting days turns rotting off (NULL)');
$r = adm('/admin/settings/save.php', ['business_name' => '']);
ok($r['code'] === 200 && one('SELECT business_name FROM crm_settings WHERE id = 1') === null, 'a business name can be cleared');
$restore();

echo "3. A bad value is refused with its field named, and nothing is saved\n";
$bad = ['business_name' => str_repeat('x', 121), 'currency' => 'EURO', 'fiscal_year_start_month' => 13, 'timezone' => 'Mars/Phobos', 'default_pipeline' => 999999, 'rotting_days' => 400, 'weighted_method' => 'guess', 'forecast_months' => 0,
    'duplicates_policy' => 'shrug', 'duplicates_name_similarity' => '0.2', 'default_remind_minutes' => 20000, 'lead_untouched_days' => 0, 'account_untouched_days' => 5000, 'import_max_rows' => 50, 'import_rollback_hours' => 200,
    'bulk_sync_rows' => 5, 'key_rate_per_minute' => 0, 'key_rate_per_day' => 'many', 'capture_rate_per_day' => 99999999, 'tombstone_days' => 0, 'ics_days_ahead' => 3, 'ics_days_back' => 400, 'email_footer' => str_repeat('f', 2001)];
$before = q('SELECT * FROM crm_settings WHERE id = 1')[0];
$all = true; $failed = [];
foreach ($bad as $k => $v) {
    $r = adm('/admin/settings/save.php', [$k => $v]);
    $fine = $r['code'] === 422 && field_of($r) === $k;
    if (!$fine) { $all = false; $failed[] = "$k=" . substr((string) $v, 0, 12) . ' → ' . $r['code'] . ' ' . field_of($r); }
}
ok($all, count($bad) . ' bad values, each a 422 naming its own field' . ($failed ? ': ' . implode('; ', $failed) : ''));
$after = q('SELECT * FROM crm_settings WHERE id = 1')[0];
unset($before['updated_at'], $after['updated_at']);
ok($before === $after, 'and the row is as it was');
$r = adm('/admin/settings/save.php', ['currency' => 'EURO', 'forecast_months' => 0, 'timezone' => 'Nope/Nope']);
ok($r['code'] === 422 && count($r['json']['error']['fields'] ?? []) === 3, 'several bad fields at once: all three named in one answer');
foreach (['next_step_prompt', 'allow_restricted_records'] as $k) {
    $r = adm('/admin/settings/save.php', [$k => 'maybe']);
    ok($r['code'] === 422 && str_contains(says($r), str_replace('_', ' ', $k)), "$k = maybe: 422 naming it");
}
$arch = (int) pipeline_made("S9 archived $x")[0];
adm('/admin/pipelines/save.php', ['pipeline' => $arch, 'archived' => 'yes']);
$r = adm('/admin/settings/save.php', ['default_pipeline' => $arch]);
ok($r['code'] === 422 && field_of($r) === 'default_pipeline', 'an archived pipeline cannot be the default');
$r = adm('/admin/settings/save.php', ['default_pipeline' => '']);
ok($r['code'] === 422 && field_of($r) === 'default_pipeline', 'nor none');
// the schema's CHECKs are the last word, whatever the form says
$rc = 0; exec('sudo -n -u postgres psql -q -v ON_ERROR_STOP=1 -d ' . escapeshellarg(need('DB_NAME')) . ' -c ' . escapeshellarg('UPDATE crm_settings SET tombstone_days = 0') . ' 2>&1', $o, $rc);
ok($rc !== 0 && str_contains(implode(' ', $o), 'violates check constraint'), 'the database itself refuses tombstone_days = 0 (the CHECK is the last word)');

echo "4. Rights, the log\n";
ok(get_as(26, '/admin/settings')['code'] === 403 && post_as(26, '/admin/settings/save.php', ['forecast_months' => 3])['code'] === 403 && get_as(27, '/admin/settings')['code'] === 403 && post_as(27, '/admin/settings/save.php', ['forecast_months' => 3])['code'] === 403, 'a Rep and the Sales Manager may not (settings.manage)');
$mkt = role_actor(31, 'marketing');
ok(get_role($mkt, '/admin/settings')['code'] === 403, 'nor Marketing');
ok(agent_post(40, 9601, '/admin/settings/save.php', ['forecast_months' => 3])['code'] === 403, 'nor an agent without the right');
ok((int) one('SELECT forecast_months FROM crm_settings WHERE id = 1') === (int) $snap['forecast_months'], 'nothing changed by any of them');
$restore();
finish();
