<?php
declare(strict_types=1);

/**
 * Mint an access token for a mirror member (the Tokens screen does the same in Phase 4).
 *   php bin/mint_mcp_token.php --member 1 --label "Claude Desktop" [--scope mcp|api]
 * Prints the raw token ONCE. `mcp` connects a person's own AI to /mcp/records and /mcp/activity;
 * `api` is a KEY for the token API (design §4, D13): it acts as its person and sees what they see, rate-limited (crm_rate_ok).
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
require_once dirname(__DIR__) . '/app/bootstrap.php';

$opts = getopt('', ['member:', 'label:', 'scope:']);
$memberId = (int) ($opts['member'] ?? 0);
$label = trim((string) ($opts['label'] ?? 'Personal token'));
$scope = trim((string) ($opts['scope'] ?? 'mcp'));
if ($memberId < 1 || !in_array($scope, ['mcp', 'api'], true)) {
    fwrite(STDERR, "Usage: php bin/mint_mcp_token.php --member <id> [--label \"…\"] [--scope mcp|api]\n");
    exit(1);
}
$pdo = db();
$member = find_member_by_id($pdo, $memberId);
if ($member === null) { fwrite(STDERR, "No mirror row for member {$memberId} — they must sign in once, or the feed must have run.\n"); exit(1); }
$raw = 'mcp_' . bin2hex(random_bytes(24));
$pdo->prepare('INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope) VALUES (:m, :l, :h, :s)')
    ->execute(['m' => $memberId, 'l' => $label, 'h' => hash('sha256', $raw), 's' => $scope]);
log_activity($pdo, 'token.mint', 'mcp_access_token', null, ['actor_member_id' => $memberId, 'after' => ['label' => $label, 'scope' => $scope]]);
echo "{$scope} access token for {$member['display_name']} (\"{$label}\") — shown once:\n\n  {$raw}\n\n";
echo $scope === 'api' ? "  " . app_url('/api/v1/') . "\n" : "  " . app_url('/mcp/records') . "\n  " . app_url('/mcp/activity') . "\n";
