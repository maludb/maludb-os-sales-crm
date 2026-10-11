<?php
declare(strict_types=1);
/** /settings/tokens/ — a person's own access tokens for the two MCP servers, the URLs to connect their AI to, and their API keys (acting as them; slice 8) — screen `settings-tokens`. A holder of tokens.manage also sees everyone else's keys, the lead-capture keys among them, to revoke. People only. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/present.php';
require_once dirname(__DIR__, 3) . '/app/features/keys/queries.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
$rows = find_my_tokens($pdo, $me);
$keys = find_keys($pdo, $me);
$others = has_right('tokens.manage') ? array_values(array_filter(find_keys($pdo, null), static fn (array $k): bool => (int) $k['member_id'] !== $me)) : [];
log_screen_view($pdo, 'settings-tokens');
if (wants_json()) {
    respond_screen(['tokens' => array_map('present_token', $rows), 'keys' => array_map('present_key', $keys), 'other_keys' => array_map('present_key', $others), 'records_url' => app_url('/mcp/records'), 'activity_url' => app_url('/mcp/activity')]);
}
$raw = $_SESSION['minted_token'] ?? null;
unset($_SESSION['minted_token']);
$rawKey = $_SESSION['minted_key'] ?? null;
unset($_SESSION['minted_key']);
render_screen('Tokens', view('settings/tokens.php', ['rows' => $rows, 'raw' => $raw, 'keys' => $keys, 'others' => $others, 'rawKey' => $rawKey, 'api_url' => app_url('/api/v1/'), 'limits' => key_default_limits($pdo),
    'may_raise' => has_right('tokens.manage'), 'tz' => member_timezone()]),
    ['activeNav' => 'tokens', 'screen' => 'settings-tokens', 'entity' => 'mcp_access_token']);
