<?php
declare(strict_types=1);

/**
 * The shell's menu: ONE table — the sidebar and the phone's tab bar read it, so a menu item and the right that opens its screen can
 * never disagree (sso-shell.md; design §9). An item shows only when the person holds its right (several joined by "|": any one).
 * Today · Leads · Accounts · Contacts · Pipeline · Calendar · Activities · Email · Campaigns · Lists · Reports, then Me, then Admin
 * (any admin area's right). A record's own screens hang under their list, not here.
 *   [id, url, icon, label, right]
 */
const ADMIN_RIGHTS = 'settings.manage|pipelines.manage|sources.manage|assignment.rules|fields.manage|tags.manage|mailbox.manage|quotas.manage|tokens.manage|records.purge';

function nav_groups(): array
{
    return [
        'Sales CRM' => [
            ['home',       '/',                 'feather-sun',            'Today',      'records.read'],
            ['leads',      '/leads',            'feather-user-plus',      'Leads',      'records.read'],
            ['accounts',   '/accounts',         'feather-briefcase',      'Accounts',   'records.read'],
            ['contacts',   '/contacts',         'feather-users',          'Contacts',   'records.read'],
            ['pipeline',   pipeline_board_url(), 'feather-columns',       'Pipeline',   'records.read'],
            ['calendar',   '/calendar',         'feather-calendar',       'Calendar',   'calendar.read'],
            ['activities', '/activities',       'feather-check-square',   'Activities', 'records.read'],
            ['email',      '/email/unmatched',  'feather-mail',           'Email',      'email.log'],
            ['campaigns',  '/campaigns',        'feather-flag',           'Campaigns',  'records.read'],
            ['lists',      '/lists',            'feather-list',           'Lists',      'lists.own'],
            ['reports',    '/reports',          'feather-bar-chart-2',    'Reports',    'records.read'],
        ],
        'Me' => [
            ['my-settings',   '/settings/',        'feather-settings', 'My settings',   'records.read'],
            ['notifications', '/notifications',    'feather-bell',     'Notifications', 'records.read'],
            ['proposals',     '/proposals',        'feather-zap',      'Proposals',     'records.read'],
            ['tokens',        '/settings/tokens/', 'feather-key',      'Tokens',        'records.read'],
            ['activity',      '/activity',         'feather-clock',    'My activity',   'records.read'],
        ],
        'Admin' => [
            ['admin', '/admin', 'feather-sliders', 'Admin', ADMIN_RIGHTS],
        ],
    ];
}

/**
 * The phone's tabs (sso-shell.md): Today · Pipeline · Calendar · Log · More. Log is a sheet of the quick-log links (a dropup —
 * never a modal); More opens the sidebar. [id, url, icon, label, right, kind] — kind is 'link', 'sheet' or 'menu'.
 */
function nav_tabs(): array
{
    return [
        ['home',     '/',                  'feather-sun',          'Today',    'records.read',  'link'],
        ['pipeline', pipeline_board_url(), 'feather-columns',      'Pipeline', 'records.read',  'link'],
        ['calendar', '/calendar',          'feather-calendar',     'Calendar', 'calendar.read', 'link'],
        ['log',      '/activities',        'feather-plus-circle',  'Log',      'records.read',  'sheet'],
        ['more',     '/settings/',         'feather-menu',         'More',     'records.read',  'menu'],
    ];
}

/**
 * The quick-log sheet: what a seller logs from a phone between meetings (design §6.2). An entry whose controller its slice has not
 * built yet is shown greyed with the slice's name instead of a link that would answer 404. [id, url, icon, label, right, file, slice]
 */
function nav_log_sheet(): array
{
    return [
        ['log-call',     '/log/call',        'feather-phone',      'Log a call',       'activities.write', '/log/call.php',        'slice 4'],
        ['log-meeting',  '/log/meeting',     'feather-users',      'Log a meeting',    'activities.write', '/log/meeting.php',     'slice 4'],
        ['log-email',    '/log/email',       'feather-mail',       'Log an email',     'email.log',        '/log/email.php',       'slice 5'],
        ['schedule',     '/activities/new',  'feather-clock',      'Schedule',         'activities.write', '/activities/form.php', 'slice 4'],
        ['new-lead',     '/leads/new',       'feather-user-plus',  'New lead',         'leads.write',      '/leads/form.php',      'slice 2'],
    ];
}

/** Whether a controller exists (the slice has shipped it). */
function nav_built(string $file): bool
{
    return is_file(dirname(__DIR__, 3) . '/html' . $file);
}

/** The default pipeline's board — by key (the routing form: a pipeline may be named by its key). One query per request. */
function pipeline_board_url(): string
{
    static $url = null;
    if ($url === null) {
        $key = null;
        try {
            $key = one_value(db(), 'SELECT p.key FROM crm_settings s JOIN pipelines p ON p.id = s.default_pipeline_id');
        } catch (Throwable) {
            $key = null;
        }
        $url = '/pipeline/' . rawurlencode((string) ($key ?: 'default')) . '/board';
    }
    return $url;
}

function nav_item(string $id): ?array
{
    foreach (nav_groups() as $items) {
        foreach ($items as $i) {
            if ($i[0] === $id) {
                return $i;
            }
        }
    }
    return null;
}

/** A menu right may name several joined by "|": any one of them opens the item. */
function nav_has_right(string $spec): bool
{
    foreach (explode('|', $spec) as $right) {
        if (has_right($right)) {
            return true;
        }
    }
    return false;
}

/** The highest Sales CRM role the member holds, in words — the header's badge (crm_member_roles(), db/004). */
function role_badge(): string
{
    $m = current_member();
    if ($m === null) {
        return '';
    }
    if (($m['business_role'] ?? '') === 'super_admin' && ($m['member_kind'] ?? 'human') === 'human') {
        return 'Super-admin';
    }
    $held = pg_text_array((string) (one_value(db(), 'SELECT crm_member_roles(:id)::text', ['id' => (int) $m['id']]) ?: '{}'));
    foreach (['admin' => 'CRM admin', 'manager' => 'Sales Manager', 'marketing' => 'Marketing', 'user' => 'Sales Rep', 'viewer' => 'Viewer'] as $key => $label) {
        if (in_array($key, $held, true)) {
            return $label;
        }
    }
    return '';
}

/** A PostgreSQL text[] literal ({a,b}) as a PHP list; quoted elements are unquoted. */
function pg_text_array(string $literal): array
{
    $literal = trim($literal);
    if ($literal === '' || $literal === '{}') {
        return [];
    }
    $out = [];
    foreach (str_getcsv(substr($literal, 1, -1), ',', '"', '\\') as $v) {
        if ($v !== null && $v !== '') {
            $out[] = $v;
        }
    }
    return $out;
}

/** A link that navigates by HTMX into #page-content and still works as a plain link (progressive enhancement). $html is already escaped. */
function hx_link(string $url, string $html, string $class = '', string $extra = ''): string
{
    return '<a href="' . e($url) . '"' . ($class !== '' ? ' class="' . e($class) . '"' : '') . ($extra !== '' ? ' ' . $extra : '')
        . ' hx-get="' . e($url) . '" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="' . e($url) . '">' . $html . '</a>';
}

/** "Back to …" from the ?back= a link carried (click-around rule): [url, label] or null. Later slices add their screens here. */
function back_link(): ?array
{
    $back = safe_local_path($_GET['back'] ?? null);
    if ($back === null) {
        return null;
    }
    $path = parse_url($back, PHP_URL_PATH) ?: '/';
    $labels = ['/' => 'Today', '/search' => 'Search', '/notifications' => 'Notifications', '/activity' => 'My activity', '/settings/' => 'My settings',
               '/settings/tokens/' => 'Tokens', '/leads/inbox' => 'the lead inbox', '/email/unmatched' => 'Email', '/email/templates' => 'the templates', '/log/email' => 'the email log', '/duplicates' => 'Duplicates', '/proposals' => 'Proposals', '/import' => 'Imports', '/views' => 'Saved views'];
    if (isset($labels[$path])) {
        return [$back, $labels[$path]];
    }
    foreach (nav_groups() as $items) {
        foreach ($items as $i) {
            if (rtrim($i[1], '/') === rtrim($path, '/')) {
                return [$back, $i[3]];
            }
        }
    }
    if (preg_match('#^/pipeline/[a-z0-9_-]+/(board|list|forecast)$#', $path, $m)) {
        return [$back, ['board' => 'the board', 'list' => 'the deal list', 'forecast' => 'the forecast'][$m[1]]];
    }
    foreach (['leads' => 'the lead', 'accounts' => 'the account', 'contacts' => 'the contact', 'deals' => 'the deal', 'activities' => 'the activity',
              'campaigns' => 'the campaign', 'lists' => 'the list', 'import' => 'the import'] as $seg => $label) {
        if (preg_match('#^/' . $seg . '/\d+$#', $path)) {
            return [$back, $label];
        }
    }
    if (str_starts_with($path, '/reports')) {
        return [$back, 'the report'];
    }
    return null;
}

/** The URL of this same page, for a ?back= (path and query as requested). */
function here_url(): string
{
    return (string) ($_SERVER['REQUEST_URI'] ?? '/');
}

function with_back(string $url, string $here): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . 'back=' . rawurlencode($here);
}

/** The slice that builds each not-yet-built menu item, in words — the placeholders and the registry read it. */
const NAV_SLICES = ['accounts' => 'slice 1', 'contacts' => 'slice 1', 'leads' => 'slice 2', 'pipeline' => 'slice 3', 'calendar' => 'slice 4', 'activities' => 'slice 4',
    'campaigns' => 'slice 6', 'lists' => 'slice 6', 'admin' => 'slice 9'];

/**
 * A screen of the manifest that its slice has not built yet (Phase 2): the shell, the page header, one card saying which slice
 * builds it — 200 after the right has been checked. bin/build_action_registry.php reads a controller that calls this as unbuilt,
 * so the voice surface never offers the screen. Removing the call is the slice's first step.
 */
function render_nav_stub(string $navId, string $slice, string $right): void
{
    $item = nav_item($navId);
    $title = $item[3] ?? ucfirst(str_replace('-', ' ', $navId));
    require_login();
    if (!nav_has_right($right)) {
        require_right(explode('|', $right)[0]);        // refuses in that right's sentence
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        emit_action_status(false, ['error' => 'This is built by ' . $slice . '.']);
        if (wants_json()) {
            json_error('not_built', 'This is built by ' . $slice . '.', 501);
        }
        http_response_code(501);
        echo view('shared/message.php', ['title' => 'Not built yet', 'message' => 'This is built by ' . $slice . '.']);
        exit;
    }
    log_screen_view(db(), $navId);
    if (wants_json()) {
        json_error('not_built', 'This screen is built by ' . $slice . '.', 501);
    }
    $html = view('shared/header.php', ['id' => $navId, 'title' => $title, 'crumbs' => [['Today', '/'], [$title, null]]])
        . '<div class="main-content" id="' . e($navId) . '-content"><div class="card coming-card" id="' . e($navId) . '-coming"><div class="card-body">'
        . '<div class="empty-state"><span class="avatar-text avatar-lg rounded"><i class="' . e($item[2] ?? 'feather-box') . '"></i></span>'
        . '<div><div class="fw-semibold">' . e($title) . ' is not built yet</div><div class="fs-12 text-muted">' . e($slice) . ' builds this screen. Nothing is lost: what belongs here will appear when it ships.</div></div></div>'
        . '</div></div></div>';
    render_screen($title, $html, ['activeNav' => $navId, 'screen' => $navId]);
}
