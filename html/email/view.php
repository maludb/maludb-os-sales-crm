<?php
declare(strict_types=1);
/**
 * /email/{in|out}/{id} (screen `email-view`; the `get_email` tool): one email — who and when, how it was matched, the body when the caller may read it (a private email is
 * its sender's and the managers'; to anyone else it is the same 404 as a missing one, and so is a draft that is not theirs), the files, the contacts and the deal it is on,
 * the activities it made and the conversation. Reply · Mark private · Attach to contact · Make lead · Ignore, each by the caller's right. A draft of mine opens in compose.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/email/present.php';
require_once dirname(__DIR__, 2) . '/app/features/email/inbound.php';
require_login();
require_right('records.read');
$pdo = db();
$dir = (string) ($_GET['direction'] ?? '');
$id = request_integer('id');
$e = in_array($dir, ['in', 'out'], true) && $id !== null ? find_email($pdo, $dir, $id) : null;
if ($e === null) { refuse(404, 'Email not found.'); }
$may = email_may($e);
$contacts = email_contacts($pdo, pg_int_array((string) $e['matched_contact_ids']));
$files = email_attachments($pdo, $dir, $id);
$activities = email_activities($pdo, $dir, $id);
$thread = email_thread($pdo, $e);
$deal = $e['deal_id'] === null ? null : one_row($pdo, 'SELECT deal_id, title FROM mcp_deals WHERE deal_id = :d', ['d' => (int) $e['deal_id']]);
$lead = $dir === 'in' && $e['match_status'] === 'proposed_lead' ? email_proposed_lead($pdo, $id) : null;
log_screen_view($pdo, 'email-view');
if (wants_json()) { respond_screen(present_email($e, $files, $activities, $thread, $may)); }
$tz = member_timezone();
$back = safe_local_path($_GET['back'] ?? null);
render_screen((string) ($e['subject'] ?: '(no subject)'), view('email/view.php', ['e' => $e, 'may' => $may, 'contacts' => $contacts, 'files' => $files, 'activities' => $activities, 'thread' => $thread, 'deal' => $deal,
    'lead' => $lead, 'tz' => $tz, 'back' => $back, 'me' => (int) current_member_id(),
    'notice' => crm_notice($_GET['notice'] ?? null, ['private' => ['warning', 'Private: only its sender and the managers see it now.'], 'public' => ['success', 'Public again.'], 'sent' => ['success', 'Sent.'], 'draft' => ['success', 'Draft saved.']])]),
    ['activeNav' => 'email', 'screen' => 'email-view', 'entity' => 'email', 'recordId' => $dir . '-' . $id]);
