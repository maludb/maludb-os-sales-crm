<?php
declare(strict_types=1);
/** /settings/tokens/ — a person's own access tokens for the two MCP servers, and the URLs to connect their AI to (screen `settings-tokens`). API keys (acting as the person) join this screen with slice 8. People only. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/present.php';
require_login();
require_human();
$pdo = db();
$rows = find_my_tokens($pdo, (int) current_member_id());
log_screen_view($pdo, 'settings-tokens');
if (wants_json()) {
    respond_screen(['tokens' => array_map('present_token', $rows), 'records_url' => app_url('/mcp/records'), 'activity_url' => app_url('/mcp/activity')]);
}
$raw = $_SESSION['minted_token'] ?? null;
unset($_SESSION['minted_token']);
render_screen('Tokens', view('settings/tokens.php', ['rows' => $rows, 'raw' => $raw, 'tz' => member_timezone()]),
    ['activeNav' => 'tokens', 'screen' => 'settings-tokens', 'entity' => 'mcp_access_token']);
