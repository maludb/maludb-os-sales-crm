<?php
declare(strict_types=1);
/** Action `lead_unqualify` (log lead.unqualify — the edit rule): `reason` (a lost reason of kind lead) is required, `note` up to 500 characters (never logged). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/contacts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/leads/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/leads/write.php';
crm_handler_begin();
$pdo = db();
$id = request_integer('lead') ?? request_integer('id');
$row = require_record($pdo, 'lead', $id);
require_right('leads.write');
require_editable($pdo, 'lead', $row);
$cur = lead_current($pdo, $id);
if ($cur['converted_at'] !== null) { refuse(422, 'A converted lead is read-only.'); }
$errors = [];
$reason = crm_ref($pdo, 'reason', null, "SELECT 1 FROM mcp_lost_reasons WHERE lost_reason_id = :id AND kind = 'lead'", 'reason', $errors, false);
if ($reason === null && !isset($errors['reason'])) { $errors['reason'] = 'Say why the lead is unqualified.'; }
$note = crm_text('note', null, 500, 'The note', $errors);
if ($errors !== []) { crm_refuse_fields($errors); }
crm_guard($pdo, static function () use ($pdo, $id, $reason, $note, $cur): void {
    $pdo->beginTransaction();
    lead_unqualify($pdo, $id, $reason, $note);
    log_activity($pdo, 'lead.unqualify', 'lead', $id, ['before' => ['status' => $cur['status']], 'after' => ['status' => 'unqualified', 'reason_id' => $reason,
        'reason' => (string) one_value($pdo, 'SELECT label FROM lost_reasons WHERE id = :r', ['r' => $reason]), 'note_given' => $note !== null]]);
    $pdo->commit();
});
crm_done('Unqualified the lead ' . $row['name'], $id, crm_land(return_path('/leads/' . $id), 'unqualified'), 'leadChanged', ['undo' => 'lead_requalify']);
