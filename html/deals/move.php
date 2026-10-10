<?php
declare(strict_types=1);
/**
 * Action `deal_move` (POST /deals/{deal}/move; log deal.move {from_stage, to_stage, stage_name, seconds_in_stage, position} — the edit rule): `to_stage` (an id
 * or a stage name within the deal's pipeline), `position` (0 = the top). crm_deal_move() shifts the column; the database refuses another pipeline's stage,
 * an archived one, Lost without a reason, and a stage whose required fields the deal lacks (422 naming them — the drag snaps back). From the board
 * (`on` = board, over HTMX) the answer is the columns region re-rendered with the board's filters (`board_query`) — the server's truth.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/deals/write.php';
crm_handler_begin();
$pdo = db();
$id = request_integer('deal') ?? request_integer('id');
$row = require_record($pdo, 'deal', $id);
require_editable($pdo, 'deal', $row);
$cur = deal_current($pdo, $id);
$ref = (string) (req_val('to_stage') ?? '');
if ($ref === '') { crm_refuse_fields(['to_stage' => 'Say which stage.']); }
$stage = stage_resolve($pdo, (int) $cur['pipeline_id'], $ref);
if ($stage === null || (int) $stage['pipeline_id'] !== (int) $cur['pipeline_id']) { crm_refuse_fields(['to_stage' => 'Choose a stage of the deal\'s pipeline.']); }
$errors = [];
$position = crm_int('position', null, 0, 100000, 'The position', $errors, true);
if ($errors !== []) { crm_refuse_fields($errors); }
$moved = deal_guard($pdo, static function () use ($pdo, $id, $stage, $position, $cur): array {
    $pdo->beginTransaction();
    $m = deal_move($pdo, $id, (int) $stage['stage_id'], $position);
    log_activity($pdo, 'deal.move', 'deal', $id, ['deal_id' => $id, 'account_id' => $cur['account_id'], 'contact_id' => $cur['primary_contact_id'], 'after' => $m]);
    $pdo->commit();
    return $m;
});
$did = (int) $stage['stage_id'] === (int) $cur['stage_id'] ? 'Reordered ' . $cur['title'] : 'Moved ' . $cur['title'] . ' to ' . $moved['stage_name'];
if (req_val('on') === 'board' && is_htmx_request() && !wants_json()) {
    require_once dirname(__DIR__, 2) . '/app/features/pipeline/present.php';
    emit_action_status(true, ['did' => $did, 'record_id' => $id]);
    parse_str((string) (req_val('board_query') ?? ''), $src);
    $pipeline = pipeline_from_request($pdo, (string) $cur['pipeline_id']);
    $filters = board_filters_from_request($pdo, $src);
    echo view('pipeline/partials/columns.php', ['pipeline' => $pipeline, 'board' => board_columns($pdo, $pipeline['pipeline_id'], $filters), 'filters' => $filters,
        'back' => pipeline_url($pipeline, 'board', $filters)]);
    exit;
}
crm_done($did, $id, crm_land(return_path('/deals/' . $id), 'moved'), 'dealChanged');
