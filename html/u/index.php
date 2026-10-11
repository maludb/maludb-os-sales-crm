<?php
declare(strict_types=1);
/**
 * /u/{token} — the unsubscribe door (a route, never an action; email.md divergence 3): the link in every email the application sends. PUBLIC, no sign-in: the token in the path is the
 * authority — 48 hex, one per contact per send, found by its SHA-256 (the Message-ID of that send). GET shows the address (masked) and ONE button; POST (with the page's own
 * CSRF token) calls crm_contact_opt_out(contact, 'email', 'unsubscribe_link') and logs `contact.opt_out` with the contact — never the token. A wrong or unknown token is the same
 * 404 page whoever asks. The page asks the browser not to cache it or pass the address on.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/email/send.php';
$GLOBALS['__public_door'] = 'portal';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
$pdo = db();
$send = email_by_unsub_token($pdo, (string) ($_GET['token'] ?? ''));
$page = static function (string $state, int $status = 200, string $address = '') {
    http_response_code($status);
    echo view('public/unsubscribe.php', ['state' => $state, 'address' => $address]);
    exit;
};
if ($send === null || $send['contact_id'] === null) { $page('invalid', 404); }
$cid = (int) $send['contact_id'];
$masked = preg_replace('/^(.).*(@.*)$/', '$1•••$2', (string) $send['to_address']) ?? '';
$already = (bool) one_value($pdo, 'SELECT email_opt_out FROM contacts WHERE id = :c', ['c' => $cid]);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { $page($already ? 'done' : 'ask', 200, $masked); }
verify_csrf();
if (!$already) {
    $pdo->beginTransaction();
    $pdo->prepare("SELECT crm_contact_opt_out(:c, 'email', 'unsubscribe_link')")->execute(['c' => $cid]);
    log_activity($pdo, 'contact.opt_out', 'contact', $cid, ['contact_id' => $cid, 'actor_member_id' => null, 'source' => 'portal', 'route' => 'POST /u/{token}',   // the route as a pattern: the token is the authority and is never written down
         'after' => ['channel' => 'email', 'reason' => 'unsubscribe_link']]);
    $pdo->commit();
}
if ($send['campaign_id'] !== null) {                                                  // a campaign's email: that campaign's membership says so too (slice 6)
    $pdo->prepare("UPDATE campaign_members SET status = 'unsubscribed' WHERE campaign_id = :c AND contact_id = :ct AND status IN ('planned', 'sent', 'opened')")->execute(['c' => $send['campaign_id'], 'ct' => $cid]);
}
$page('done', 200, $masked);
