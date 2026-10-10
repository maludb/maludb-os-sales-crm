<?php
declare(strict_types=1);
/**
 * GET /deals/{id}/lost — screen `deal-lost`: the reason (a deal's) and a note (a drop on the board's Lost column lands here).
 * POST — action `deal_lost` (log deal.lost {value, currency, close_date, reason} — the edit rule): `reason` (required, a lost reason of kind deal), `note` (up to
 * 500 characters). The reason set, then crm_deal_move to the pipeline's Lost stage.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/deals/write.php';
require_login();
$pdo = db();
$id = request_integer('deal') ?? request_integer('id');
$row = require_record($pdo, 'deal', $id);
$deal = find_deal($pdo, $id);
$reasons = find_deal_reasons($pdo);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    require_editable($pdo, 'deal', $row);
    if ($deal['status'] === 'lost') { redirect('/deals/' . $id . '?notice=lost'); }
    log_screen_view($pdo, 'deal-lost');
    if (wants_json()) {
        respond_screen(['deal_id' => $id, 'title' => $deal['title'], 'reasons' => array_map(static fn ($r) => ['lost_reason_id' => (int) $r['lost_reason_id'], 'label' => $r['label']], $reasons)]);
    }
    render_screen('Lost: ' . $deal['title'], view('deals/lost.php', ['deal' => $deal, 'reasons' => $reasons]), ['activeNav' => 'pipeline', 'screen' => 'deal-lost', 'entity' => 'deal', 'recordId' => (string) $id]);
    exit;
}
crm_handler_begin();
require_editable($pdo, 'deal', $row);
if ($deal['status'] === 'lost') { refuse(422, 'The deal is lost already.'); }
$errors = [];
$reason = crm_ref($pdo, 'reason', null, "SELECT 1 FROM mcp_lost_reasons WHERE lost_reason_id = :id AND kind = 'deal' AND active", 'reason', $errors, false);
if (!req_has('reason')) { $errors['reason'] = 'Say why it was lost.'; }
elseif ($reason === null && !isset($errors['reason'])) { $errors['reason'] = 'Say why it was lost.'; }
if (isset($errors['reason']) && str_starts_with($errors['reason'], 'That reason')) { $errors['reason'] = 'Choose one of a deal\'s reasons.'; }
$note = crm_text('note', null, 500, 'The note', $errors);
if ($errors !== []) { crm_refuse_fields($errors); }
$r = deal_guard($pdo, static function () use ($pdo, $id, $reason, $note, $deal): array {
    $pdo->beginTransaction();
    $r = deal_close($pdo, $id, 'lost', ['reason' => $reason, 'note' => $note]);
    log_activity($pdo, 'deal.lost', 'deal', $id, ['deal_id' => $id, 'account_id' => $deal['account_id'], 'contact_id' => $deal['primary_contact_id'], 'after' => $r]);
    $pdo->commit();
    return $r;
});
crm_done('Lost ' . $deal['title'] . ' — ' . $r['reason'], $id, crm_land(return_path('/deals/' . $id), 'lost'), 'dealChanged');
