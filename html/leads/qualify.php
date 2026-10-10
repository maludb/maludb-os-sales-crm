<?php
declare(strict_types=1);
/** Action `lead_qualify` (log lead.qualify — the edit rule): a score (0 to 100) and a status (working — the default — or new). Not the conversion (that is lead_convert). */
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
if ($cur['status'] === 'unqualified') { refuse(422, 'The lead is unqualified — requalify it first.'); }
$errors = [];
$score = crm_int('score', null, 0, 100, 'The score', $errors, true);
$status = crm_choice('status', 'working', ['new', 'working'], 'The status', $errors, false) ?? 'working';
if ($errors !== []) { crm_refuse_fields($errors); }
crm_guard($pdo, static function () use ($pdo, $id, $score, $status, $cur): void {
    $pdo->beginTransaction();
    lead_qualify($pdo, $id, $score, $status);
    log_activity($pdo, 'lead.qualify', 'lead', $id, ['before' => ['status' => $cur['status'], 'score' => $cur['score'] === null ? null : (int) $cur['score']], 'after' => ['status' => $status, 'score' => $score ?? ($cur['score'] === null ? null : (int) $cur['score'])]]);
    $pdo->commit();
});
crm_done('The lead is ' . (LEAD_STATUSES[$status] ?? $status) . ($score !== null ? ', scored ' . $score : ''), $id, crm_land(return_path('/leads/' . $id), 'qualified'), 'leadChanged');
