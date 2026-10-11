<?php
declare(strict_types=1);
/**
 * /admin/agents (screen `admin-agents`; people only, `agents.settings`): the agents working here with what they last did, the dispatches (what woke them, how it went, Cancel for one still waiting), and the
 * Assistant's settings — the morning note and its hour, proposals on or off, how long a lead or an account may sit untouched, and the stages that ask the Assistant when a deal enters them. JSON: the same, whitelisted.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/agents/queries.php';
require_login();
require_human();
require_right('agents.settings');
$pdo = db();
$f = ['status' => isset(DISPATCH_STATUS[$s = (string) ($_GET['status'] ?? '')]) ? $s : null, 'agent' => ctype_digit((string) ($_GET['agent'] ?? '')) ? (int) $_GET['agent'] : null,
      'kind' => isset(DISPATCH_KINDS[$k = (string) ($_GET['kind'] ?? '')]) ? $k : null];
$agents = agents_here($pdo);
$dispatches = find_dispatches($pdo, $f);
$settings = agent_settings($pdo);
log_screen_view($pdo, 'admin-agents');
if (wants_json()) {
    respond_screen(['agents' => array_map(static fn (array $a): array => ['member_id' => (int) $a['member_id'], 'display_name' => $a['display_name'], 'job_title' => $a['job_title'], 'roles' => $a['roles'],
            'last_at' => json_ts($a['last_at']), 'last_kind' => $a['last_kind'], 'last_status' => $a['last_status'], 'waiting' => (int) $a['waiting'], 'answered_week' => (int) $a['answered_week'], 'proposals_week' => (int) $a['proposals_week']], $agents),
        'dispatches' => array_map('present_dispatch', $dispatches), 'filters' => $f, 'settings' => $settings]);
}
$notices = ['saved' => ['success', 'Saved the agents\' settings.'], 'cancelled' => ['success', 'Cancelled that dispatch.']];
render_screen('Agents', view('admin/agents.php', ['agents' => $agents, 'dispatches' => $dispatches, 'settings' => $settings, 'filters' => $f, 'tz' => member_timezone(), 'notice' => $notices[$_GET['notice'] ?? ''] ?? null]),
    ['activeNav' => 'admin', 'screen' => 'admin-agents', 'entity' => 'agent']);
