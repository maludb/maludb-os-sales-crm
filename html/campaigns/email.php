<?php
declare(strict_types=1);
/**
 * Action `campaign_email_send` (log campaign.email_send — campaigns.manage AND email.send; PAUSES FOR AN AGENT — external_send; confirm = yes): `campaign`, `template`, `members` (planned — the default — or all).
 * It QUEUES one message per contact among the members who may be emailed — the template filled for each, the caller the sender — and sends nothing: the worker's `outbox` pass sends them (the
 * footer, an unsubscribe link and a Message-ID each). Contacts who opted out, have no address, cannot be filled or already wait are skipped and counted. The log row holds the counts, never a body.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/campaigns/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/campaigns/write.php';
crm_handler_begin();
require_right('campaigns.manage');
require_right('email.send');
$pdo = db();
$me = (int) current_member_id();
$c = request_integer('campaign') ?? request_integer('id');
$camp = $c === null ? null : find_campaign($pdo, $c);
if ($camp === null) { refuse(404, 'Campaign not found.'); }
$errors = [];
if (req_val('confirm') !== 'yes') { $errors['confirm'] = 'Confirm it: this queues an email to every one of them (confirm = yes).'; }
$tid = request_integer('template');
if ($tid === null) { $errors['template'] = 'Choose the template.'; }
$members = crm_choice('members', 'planned', ['planned', 'all'], 'Who', $errors, false) ?? 'planned';
if ($errors !== []) { crm_refuse_fields($errors); }
$queued = crm_guard($pdo, static function () use ($pdo, $c, $tid, $members, $me): int {
    $pdo->beginTransaction();
    $n = campaign_email_send($pdo, $c, $tid, $members, $me);
    $r = campaign_email_last();
    log_activity($pdo, 'campaign.email_send', 'campaign', $c, ['after' => ['template_id' => $tid, 'members' => $members] + $r]);
    $pdo->commit();
    return $n;
});
$r = campaign_email_last();
$skipped = $r['skipped_opted_out'] + $r['skipped_no_address'] + $r['skipped_unfilled'] + $r['skipped_hidden'] + $r['skipped_already_queued'];
crm_done($queued === 0 ? 'Nothing was queued' . ($skipped > 0 ? ' — ' . $skipped . ' skipped' : '') : 'Queued ' . $queued . ' email' . ($queued === 1 ? '' : 's') . ($skipped > 0 ? '; skipped ' . $skipped : '') . ' — the worker sends them',
    $c, crm_land(return_path('/campaigns/' . $c), 'email_queued'), 'campaignChanged', ['queued' => $queued] + array_diff_key($r, ['queued' => 1]));
