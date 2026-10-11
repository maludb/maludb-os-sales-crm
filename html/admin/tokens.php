<?php
declare(strict_types=1);
/**
 * /admin/tokens (screen `admin-tokens`; tokens.manage): every key and token — whose, its label, what it is (a person's API key, a lead-capture key, an MCP token), its limits, the calls today, when it was last used, whether it is live — never a hash.
 * Revoke stops a key (key_revoke); the form mints a lead-capture key for the website's form: a label and the source its leads carry, shown ONCE on the next screen. JSON: the rows (and, for a mint just made, nothing — the value is in the mint's answer).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/present.php';
require_login();
require_right('tokens.manage');
$pdo = db();
$rows = tokens_all($pdo);
log_screen_view($pdo, 'admin-tokens');
if (wants_json()) {
    respond_screen(['tokens' => array_map('present_token_row', $rows), 'defaults' => ['capture_rate_per_day' => (int) one_value($pdo, 'SELECT capture_rate_per_day FROM crm_settings WHERE id = 1')], 'leads_url' => app_url('/api/v1/leads')]);
}
$minted = $_SESSION['minted_key'] ?? null;                      // a capture key just minted: shown once, here
unset($_SESSION['minted_key']);
render_screen('Keys and tokens', view('admin/tokens.php', ['rows' => $rows, 'minted' => $minted, 'capture_default' => (int) one_value($pdo, 'SELECT capture_rate_per_day FROM crm_settings WHERE id = 1'),
    'leads_url' => app_url('/api/v1/leads'), 'tz' => member_timezone(), 'notice' => crm_notice($_GET['notice'] ?? null, ['revoked' => ['success', 'Revoked.']])]),
    ['activeNav' => 'admin', 'screen' => 'admin-tokens', 'entity' => 'mcp_access_token']);
