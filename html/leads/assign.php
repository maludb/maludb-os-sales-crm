<?php
declare(strict_types=1);
/**
 * Action `lead_assign` (log lead.assign, one row per lead): `lead` (or `leads[]`), `owner` (a member — empty sends it back to the inbox). leads.assign gives any lead to
 * anyone; without it a seller may take a lead they may edit (unowned, or their own) for themselves — and only for themselves.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/contacts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/leads/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/leads/write.php';
crm_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$ids = array_values(array_unique(array_map('intval', array_filter(request_list('leads') ?? [], 'ctype_digit'))));
if (($one = request_integer('lead') ?? request_integer('id')) !== null) { array_unshift($ids, $one); $ids = array_values(array_unique($ids)); }
$errors = [];
if ($ids === []) { $errors['lead'] = 'Say which lead to assign.'; }
if (!req_has('owner')) { $errors['owner'] = 'Say who owns it — or leave it empty for the inbox.'; }
$owner = crm_ref($pdo, 'owner', null, 'SELECT 1 FROM mcp_members WHERE member_id = :id', 'owner', $errors);
if (count($ids) > 200) { $errors['leads'] = 'Assign up to 200 leads at once.'; }
if ($errors !== []) { crm_refuse_fields($errors); }
$any = has_right('leads.assign');
if (!$any && !has_right('leads.write')) { require_right('leads.assign'); }
$rows = [];
foreach ($ids as $lid) {
    $row = require_record($pdo, 'lead', $lid);
    if (!$any) {
        require_editable($pdo, 'lead', $row);
        if ($owner !== $me) { refuse(403, 'You may take a lead for yourself; giving it to someone else needs the right to assign leads.'); }
    }
    if ((string) one_value($pdo, 'SELECT converted_at IS NOT NULL FROM leads WHERE id = :id', ['id' => $lid]) === '1') { refuse(422, 'A converted lead is read-only.'); }
    $rows[$lid] = $row;
}
$changed = crm_guard($pdo, static function () use ($pdo, $ids, $owner, $rows): array {
    $pdo->beginTransaction();
    $changed = lead_assign($pdo, $ids, $owner);
    foreach ($changed as $lid) {
        log_activity($pdo, 'lead.assign', 'lead', $lid, ['before' => ['owner_member_id' => $rows[$lid]['owner_member_id'] === null ? null : (int) $rows[$lid]['owner_member_id']], 'after' => ['owner_member_id' => $owner]]);
    }
    $pdo->commit();
    return $changed;
});
$who = $owner === null ? 'the inbox' : (string) member_name($pdo, $owner);
crm_done(count($ids) === 1 ? ($changed ? 'Gave the lead to ' . $who : 'It was already ' . ($owner === null ? 'unowned' : $who . '\'s')) : 'Gave ' . count($changed) . ' of ' . count($ids) . ' leads to ' . $who,
    $ids[0], crm_land(return_path(count($ids) === 1 ? '/leads/' . $ids[0] : '/leads/inbox'), 'assigned'), 'leadChanged', ['changed' => $changed]);
