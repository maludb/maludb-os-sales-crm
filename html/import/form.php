<?php
declare(strict_types=1);
/**
 * /import/new?kind=&template= (screen `import-new`): upload a CSV → map its columns (in the browser, from the header row, when JavaScript is on; otherwise the
 * server maps each header by its own words or the chosen template's) → the options (what to do with a match, the owner, the source, a list) → Dry run. People only.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/attachments/store.php';
require_once dirname(__DIR__, 2) . '/app/features/imports/csv.php';
require_once dirname(__DIR__, 2) . '/app/features/imports/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/imports/present.php';
require_once dirname(__DIR__, 2) . '/app/features/imports/write.php';
require_login();
require_right('imports.own');
require_human();
$pdo = db();
$kind = isset(IMPORT_KINDS[$k = request_string('kind')]) ? $k : 'leads_csv';
$template = ($t = request_integer('template')) !== null ? find_template($pdo, $t) : null;
if ($template !== null) { $kind = (string) $template['kind']; }
$custom = $pdo->prepare('SELECT key, label FROM mcp_custom_fields WHERE applies_to = :k AND archived_at IS NULL ORDER BY sort_order, label');
$custom->execute(['k' => IMPORT_RECORD_KIND[$kind]]);
$custom = $custom->fetchAll();
log_screen_view($pdo, 'import-new');
if (wants_json()) {
    respond_screen(['kind' => $kind, 'kinds' => IMPORT_KINDS, 'fields' => array_map(static fn ($f) => $f[0], IMPORT_FIELDS[$kind]), 'custom_fields' => $custom,
        'template' => $template === null ? null : ['template_id' => (int) $template['id'], 'name' => $template['name'], 'mapping' => $template['mapping_list'], 'dedupe_policy' => $template['dedupe_policy']],
        'max_rows' => import_max_rows($pdo)]);
}
render_screen('New import', view('import/form.php', ['kind' => $kind, 'tpl' => $template, 'custom' => $custom, 'sources' => $pdo->query('SELECT source_id, name FROM mcp_lead_sources WHERE active ORDER BY sort_order')->fetchAll(),
    'max_rows' => import_max_rows($pdo), 'me' => (int) current_member_id(), 'may_shared' => has_right('imports.shared')]), ['activeNav' => 'leads', 'screen' => 'import-new', 'entity' => 'import']);
