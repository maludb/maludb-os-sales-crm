<?php
declare(strict_types=1);
/** Action `lead_accept_proposed` (log lead.accept — leads.write): a proposed lead (from the mailbox or the form) into the book — the owner given, else the rules', else unowned. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/contacts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/leads/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/leads/write.php';
crm_handler_begin();
require_right('leads.write');
$pdo = db();
$id = request_integer('lead') ?? request_integer('id');
$row = require_record($pdo, 'lead', $id);
if (!(bool) one_value($pdo, 'SELECT is_proposed FROM leads WHERE id = :id', ['id' => $id])) { refuse(422, 'That lead is already in the book.'); }
$errors = [];
$owner = crm_ref($pdo, 'owner', null, 'SELECT 1 FROM mcp_members WHERE member_id = :id', 'owner', $errors);
if ($errors !== []) { crm_refuse_fields($errors); }
$who = crm_guard($pdo, static function () use ($pdo, $id, $owner): ?int {
    $pdo->beginTransaction();
    $who = lead_accept($pdo, $id, $owner);
    log_activity($pdo, 'lead.accept', 'lead', $id, ['before' => ['is_proposed' => true], 'after' => ['is_proposed' => false, 'owner_member_id' => $who, 'owner_by_rules' => $owner === null]]);
    $pdo->commit();
    return $who;
});
crm_done('Accepted the lead ' . $row['name'] . ' — ' . ($who === null ? 'unowned, in the inbox' : 'it is ' . member_name($pdo, $who) . '\'s'), $id, crm_land(return_path('/leads/inbox'), 'accepted'), 'leadChanged', ['owner_member_id' => $who]);
