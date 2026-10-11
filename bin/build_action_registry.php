<?php
declare(strict_types=1);

/**
 * Build mcp/action_registry.json from docs/sales-crm-action-manifest.md (Spaces' builder, copied 2026-10-05).
 *
 * The manifest is the contract between the voice surface and the app: every screen the
 * assistant can reach and every action it can perform. This script turns it into the
 * registry the kernel's actions server loads (the installer copies it into the kernel's
 * mcp/registries/sales_crm.json with deploy/kernel-registry-sales_crm.json's resolve block),
 * so the tool list and the document can never drift — a screen or action missing from the
 * manifest is unreachable by voice, and one added to the manifest is reachable as soon as its
 * endpoint exists.
 *
 * It never guesses. An endpoint it cannot resolve, or a params cell it cannot parse, is
 * reported (a parse warning) and marked unresolved rather than approximated.
 *
 * Sales CRM's manifest has two columns beyond Spaces': "Agent" (yes | people — whether the
 * endpoint admits an agent's token) and "record_id" (what the JSON answer's record_id names).
 * The "Agent approval" column is maludb-os.json approvals[]: --approvals rewrites that block,
 * --check fails when either the registry or the approvals are stale.
 *
 *   php bin/build_action_registry.php              # write the registry, print a summary
 *   php bin/build_action_registry.php --check      # verify only; non-zero exit if stale or warned
 *   php bin/build_action_registry.php --approvals  # also rewrite maludb-os.json approvals[]
 */

const MANIFEST = __DIR__ . '/../docs/sales-crm-action-manifest.md';
const REGISTRY = __DIR__ . '/../mcp/action_registry.json';
const APP_MANIFEST = __DIR__ . '/../maludb-os.json';
const WEB_ROOT = __DIR__ . '/../html';
const CATEGORIES = ['money_out', 'external_send', 'deletion', 'other'];

$checkOnly = in_array('--check', $argv, true);
$writeApprovals = in_array('--approvals', $argv, true);
$warnings = [];

$lines = file(MANIFEST, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "Cannot read the manifest.\n");
    exit(1);
}

$screens = [];
$actions = [];
$problems = [];

$section = '';
$sectionBases = [];
$mode = '';                     // 'screens' | 'actions' | ''

foreach ($lines as $no => $line) {
    $lineNo = $no + 1;

    if (str_starts_with($line, '## ')) {
        $section = trim(substr($line, 3));
        $sectionBases = [];
        $mode = '';
        continue;
    }
    if (preg_match('/^Screens:/', $line)) {
        $mode = 'screens';
        continue;
    }
    if (preg_match('/^Actions\b(.*)$/', $line, $m)) {
        $mode = 'actions';
        // "Actions (base `/contacts/`, deals under `/deals/`)" — collect every path it names.
        preg_match_all('/`(\/[^`]*)`/', $m[1], $bases);
        $sectionBases = $bases[1] ?? [];
        continue;
    }
    if ($line === '' || $line[0] !== '|') {
        continue;
    }

    $cells = array_map('trim', explode('|', trim($line, "| \t")));
    if ($cells === [] || str_starts_with($cells[0], '---') || $cells[0] === 'Screen id' || $cells[0] === 'Action'
        || $cells[0] === 'Tool' || $cells[0] === 'Work item' || $cells[0] === '#') {
        continue;
    }

    if ($mode === 'screens' && count($cells) >= 3) {
        $id = trim($cells[0], '`');
        if (!preg_match('/^[a-z0-9-]+$/', $id)) {
            continue;                                   // not a screen row (a prose table)
        }
        if (isset($screens[$id])) {
            $warnings[] = "line {$lineNo}: screen {$id} is listed twice";
        }
        $url = trim($cells[1], '`');
        [$description, $params] = split_screen_description($cells[2]);
        $controller = screen_controller($url);
        $screens[$id] = [
            'screen' => $id,
            'url' => $url,
            'section' => $section,
            'when' => $description,
            'params' => $params,
            'built' => $controller !== null && !is_stub($controller),
            'stub' => $controller !== null && is_stub($controller),
        ];
        continue;
    }

    if ($mode === 'actions' && count($cells) >= 8) {
        $name = trim($cells[0], '`');
        if (!preg_match('/^[a-z0-9_]+$/', $name)) {
            continue;
        }
        $file = trim($cells[1], '`');
        $endpoint = resolve_endpoint($file, $sectionBases);
        if ($endpoint === null) {
            $problems[] = "line {$lineNo}: {$name} — cannot resolve endpoint '{$file}'"
                . ($sectionBases === [] ? ' (the section names no base path)'
                                        : ' among bases ' . implode(', ', $sectionBases));
        }
        if (isset($actions[$name])) {
            $warnings[] = "line {$lineNo}: action {$name} is listed twice";
        }
        $approval = $cells[5] === '' ? null : $cells[5];
        if ($approval !== null && !in_array($approval, CATEGORIES, true)) {
            $warnings[] = "line {$lineNo}: {$name} — unknown approval category '{$approval}'";
        }
        $logEvent = trim($cells[6], '`');
        if (!preg_match('/^[a-z_]+\.[a-z_]+$/', $logEvent)) {
            $warnings[] = "line {$lineNo}: {$name} — log event '{$logEvent}' is not entity.verb";
        }
        $agent = trim($cells[8] ?? 'yes');
        if (!in_array($agent, ['yes', 'people'], true)) {
            $warnings[] = "line {$lineNo}: {$name} — Agent must be yes or people, not '{$agent}'";
        }
        $actions[$name] = [
            'action' => $name,
            'section' => $section,
            'endpoint' => $endpoint,
            'params' => parse_params($cells[2]),
            'undo' => $cells[3] === '—' ? null : $cells[3],
            'confirm' => str_contains($cells[4], '✔'),
            'approval' => $approval,
            'log_event' => $logEvent,
            'who' => $cells[7],
            'agent' => $agent === 'yes',                    // false: people only (require_human() in the handler)
            'record_id' => trim($cells[9] ?? ''),
            'built' => $endpoint !== null && is_file(WEB_ROOT . $endpoint) && !is_stub(WEB_ROOT . $endpoint),   // a placeholder screen's file is not a built action
        ];
    }
}

/*
 * "**organization**, any field of organization_create" — the manifest's way of saying an update
 * takes the record and whichever fields change. parse_params() keeps that phrase as a note; here
 * it becomes what it means: the named action's fields, every one optional, and the action marked
 * `partial` so the tool tells PHP to keep what it was not sent (app/partial_update.php).
 * Found by the 2026-09-19 action smoke: without this the ten update tools took a record and
 * nothing else, and every save handler refused the empty form.
 */
foreach ($actions as $name => $action) {
    foreach ($action['params'] as $i => $p) {
        if (($p['name'] ?? null) !== null || !preg_match('/^any field of ([a-z0-9_]+)$/', (string) ($p['note'] ?? ''), $m)) {
            continue;
        }
        $source = $actions[$m[1]] ?? null;
        if ($source === null) {
            $problems[] = "{$name}: 'any field of {$m[1]}' names no action";
            continue;
        }
        $have = array_column(array_filter($action['params'], static fn (array $q): bool => ($q['name'] ?? null) !== null), 'name');
        $extra = [];
        foreach ($source['params'] as $q) {
            if (($q['name'] ?? null) !== null && !in_array($q['name'], $have, true)) {
                $extra[] = ['required' => false] + $q;
            }
        }
        array_splice($actions[$name]['params'], $i, 1, $extra);
        $actions[$name]['partial'] = true;
    }
}

/*
 * Parse warnings: a params piece that is not a field (a phantom parameter — a comma or a
 * semicolon inside a hint, or a note after the last parameter), an undo naming no action, and
 * a log event shared by an action that pauses and one that does not (the kernel's policy
 * matches the log event, so the free one would pause too).
 */
foreach ($actions as $name => $action) {
    foreach ($action['params'] as $p) {
        if (($p['name'] ?? null) === null) {
            $warnings[] = "{$name}: a params piece is not a field: '" . ($p['note'] ?? '') . "'";
        }
    }
    $undo = $action['undo'];
    if ($undo !== null && $undo !== 'restore prior' && !isset($actions[$undo])) {
        $warnings[] = "{$name}: undo '{$undo}' names no action";
    }
}
$byEvent = [];
foreach ($actions as $name => $action) {
    $byEvent[$action['log_event']][$action['approval'] === null ? 'free' : 'paused'][] = $name;
}
foreach ($byEvent as $event => $sides) {
    if (isset($sides['free'], $sides['paused'])) {
        $warnings[] = "log event {$event} is shared by paused " . implode(', ', $sides['paused'])
            . ' and free ' . implode(', ', $sides['free']);
    }
}

/** "to browse quotes (params: `status`, `organization`)" → description + param names. */
function split_screen_description(string $cell): array
{
    $params = [];
    if (preg_match('/\(params:\s*(.+?)\)\s*$/', $cell, $m)) {
        preg_match_all('/`([a-z0-9_]+)`/', $m[1], $found);
        $params = $found[1] ?? [];
        $cell = trim(preg_replace('/\(params:.*?\)\s*$/', '', $cell));
    }
    return [trim($cell), $params];
}

/** True when the controller is a module placeholder rather than the real screen. */
function is_stub(string $controllerPath): bool
{
    $source = @file_get_contents($controllerPath);
    return $source !== false && (str_contains($source, 'render_module_stub(') || str_contains($source, 'render_nav_stub('));       // the placeholder helpers are gone (slice 7); a controller that named one would be a stub
}

/** The controller answering a screen's canonical URL, or null when nothing does. */
function screen_controller(string $url): ?string
{
    $path = parse_url($url, PHP_URL_PATH) ?: $url;
    $path = '/' . ltrim($path, '/');
    if (preg_match('#^/email/\{direction\}/\{id\}$#', $path)) {                  // the routing form (phase1-consistency decision 11): an email by direction → email/view.php
        return is_file(WEB_ROOT . '/email/view.php') ? WEB_ROOT . '/email/view.php' : null;
    }
    $path = preg_replace('/\{[a-z_]+\}/', '1', $path);          // /pages/{id} → /pages/1
    if (str_ends_with($path, '/')) {
        return is_file(WEB_ROOT . $path . 'index.php') ? WEB_ROOT . $path . 'index.php' : null;
    }
    if (is_file(WEB_ROOT . $path . '.php')) {
        return WEB_ROOT . $path . '.php';                       // /my-work → my-work.php
    }
    if (is_file(WEB_ROOT . $path . '/index.php')) {
        return WEB_ROOT . $path . '/index.php';                 // /team/departments → …/index.php
    }
    // The canonical-URL rewrites: /{module}/new → form.php, /{module}/{id} → view.php.
    $parts = explode('/', trim($path, '/'));
    $last = array_pop($parts);
    $dir = '/' . implode('/', $parts);
    if ($last === 'new') {
        return is_file(WEB_ROOT . $dir . '/form.php') ? WEB_ROOT . $dir . '/form.php' : null;
    }
    if ($last === 'edit') {
        array_pop($parts);                                      // drop the {id} segment
        $form = WEB_ROOT . '/' . implode('/', $parts) . '/form.php';
        return is_file($form) ? $form : null;
    }
    if (ctype_digit($last)) {
        return is_file(WEB_ROOT . $dir . '/view.php') ? WEB_ROOT . $dir . '/view.php' : null;
    }
    // /channels/{id}/members → channels/members.php (a page of the record, rewritten by the vhost)
    if (preg_match('/^[a-z_-]+$/', $last) && $parts !== [] && ctype_digit((string) end($parts))) {
        array_pop($parts);
        $page = WEB_ROOT . '/' . implode('/', $parts) . '/' . $last . '.php';
        return is_file($page) ? $page : null;
    }
    return null;
}

/** Absolute file → itself; otherwise the one section base that yields an existing file. */
function resolve_endpoint(string $file, array $bases): ?string
{
    if ($file === '' || $file === '—') {
        return null;
    }
    if (str_starts_with($file, '/')) {
        return $file;
    }
    if (count($bases) === 1) {
        return rtrim($bases[0], '/') . '/' . ltrim($file, '/');
    }
    $hits = [];
    foreach ($bases as $base) {
        $candidate = rtrim($base, '/') . '/' . ltrim($file, '/');
        if (is_file(WEB_ROOT . $candidate)) {
            $hits[] = $candidate;
        }
    }
    return count($hits) === 1 ? $hits[0] : null;                // ambiguous → unresolved
}

/**
 * "**name**, relationship_types[], email" → typed param list. Bold is required, a `[]`
 * suffix is a repeated field, and anything in parentheses is a hint for the tool description.
 */
function parse_params(string $cell): array
{
    $params = [];
    foreach (explode(',', $cell) as $piece) {
        $piece = trim($piece);
        if ($piece === '' || $piece === '—') {
            continue;
        }
        $required = str_contains($piece, '**');
        $hint = '';
        if (preg_match('/\((.+?)\)/', $piece, $m)) {
            $hint = trim($m[1]);
            $piece = trim(preg_replace('/\(.+?\)/', '', $piece));
        }
        $piece = trim(str_replace(['**', '`'], '', $piece));

        // "any field of deal_create" and "organization_id or contact_id or deal_id" are
        // prose, not a field: keep them as guidance on the tool, not as a schema field.
        if (str_contains($piece, ' or ')) {
            foreach (explode(' or ', $piece) as $alternative) {
                $alternative = trim($alternative);
                if (preg_match('/^[a-z0-9_]+(\[\])?$/', $alternative)) {
                    $params[] = param($alternative, false, 'one of these is required');
                }
            }
            continue;
        }
        if (!preg_match('/^[a-z0-9_]+(\[\])?$/', $piece)) {
            $params[] = ['name' => null, 'note' => $piece];
            continue;
        }
        $params[] = param($piece, $required, $hint);
    }
    return $params;
}

/** maludb-os.json as the repository keeps it: two-space indent, non-ASCII escaped, slashes not. */
function app_manifest_json(array $data): string
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return preg_replace_callback('/^( {4})+/m', static fn (array $m): string => str_repeat('  ', intdiv(strlen($m[0]), 4)), $json) . "\n";
}

function param(string $piece, bool $required, string $hint): array
{
    $isArray = str_ends_with($piece, '[]');
    $name = $isArray ? substr($piece, 0, -2) : $piece;
    return ['name' => $name, 'required' => $required, 'repeated' => $isArray, 'hint' => $hint];
}

$registry = [
    'generated_from' => 'docs/sales-crm-action-manifest.md',
    'generated_at' => date('c'),
    'screens' => $screens,
    'actions' => $actions,
];
$json = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

// maludb-os.json approvals[] = the manifest's "Agent approval" column, sorted by action.
$approvals = [];
foreach ($actions as $name => $action) {
    if ($action['approval'] !== null) {
        $approvals[$name] = $action['approval'];
    }
}
ksort($approvals);
$approvalList = [];
foreach ($approvals as $action => $category) {
    $approvalList[] = ['action' => $action, 'category' => $category];
}
$appJson = json_decode((string) file_get_contents(APP_MANIFEST), true, 512, JSON_THROW_ON_ERROR);
$approvalsStale = ($appJson['approvals'] ?? []) !== $approvalList;

$builtScreens = count(array_filter($screens, static fn ($s) => $s['built']));
$builtActions = count(array_filter($actions, static fn ($a) => $a['built']));

foreach ($warnings as $warning) {
    fwrite(STDERR, "  warning: {$warning}\n");
}

if ($checkOnly) {
    $current = is_file(REGISTRY) ? file_get_contents(REGISTRY) : '';
    $stale = preg_replace('/"generated_at": "[^"]*",\n/', '', $current)
          !== preg_replace('/"generated_at": "[^"]*",\n/', '', $json);
    echo $stale ? "STALE: the registry does not match the manifest. Run bin/build_action_registry.php\n"
                : "OK: registry matches the manifest.\n";
    echo $approvalsStale ? "STALE: maludb-os.json approvals[] does not match the manifest. Run bin/build_action_registry.php --approvals\n"
                         : 'OK: ' . count($approvalList) . " approvals in maludb-os.json match the manifest.\n";
    echo count($warnings) . " parse warning(s)\n";
    exit($stale || $approvalsStale || $warnings !== [] ? 1 : 0);
}

file_put_contents(REGISTRY, $json);
if ($writeApprovals && $approvalsStale) {
    $appJson['approvals'] = $approvalList;
    file_put_contents(APP_MANIFEST, app_manifest_json($appJson));
    echo 'wrote ' . count($approvalList) . " approvals to maludb-os.json\n";
} elseif ($approvalsStale) {
    fwrite(STDERR, "  maludb-os.json approvals[] is stale — run with --approvals\n");
}

printf("%d screens (%d built), %d actions (%d built, %d paused for agents, %d people only), %d parse warning(s) → mcp/action_registry.json\n",
    count($screens), $builtScreens, count($actions), $builtActions, count($approvalList),
    count(array_filter($actions, static fn ($a) => !$a['agent'])), count($warnings));
foreach ($problems as $problem) {
    fwrite(STDERR, "  unresolved: {$problem}\n");
}
if ($problems !== []) {
    fwrite(STDERR, sprintf("  %d unresolved endpoint(s) — registered but never exposed as tools.\n", count($problems)));
}
