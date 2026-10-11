<?php
declare(strict_types=1);
/**
 * Action `bulk_campaign_add` (log bulk.campaign_add {kind, count, filters?}; confirm — campaigns.manage): `campaign`, `kind` (lead or contact), and what to act on — `records[]` or `list` or `filters`.
 * They join as `planned` (a campaign's email then reaches them); one already a member keeps its status. A record is only read, never changed, so the edit rule does not apply — the wall does.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bulk/select.php';
require_once dirname(__DIR__, 2) . '/app/features/bulk/write.php';
crm_handler_begin();
require_right('campaigns.manage');
$pdo = db();
$kind = record_kind_from_request(['lead', 'contact']);
$c = request_integer('campaign');
$camp = $c === null ? null : find_campaign($pdo, $c);
if ($camp === null) { refuse($c === null ? 422 : 404, $c === null ? 'Choose the campaign.' : 'Campaign not found.'); }
[$sel, $r] = crm_guard($pdo, static function () use ($pdo, $kind, $c): array {
    $sel = bulk_select($pdo, $kind, $_POST);
    $pdo->beginTransaction();
    $r = campaign_member_add($pdo, $c, $kind === 'lead' ? $sel['ids'] : [], $kind === 'contact' ? $sel['ids'] : [], 'planned');
    log_activity($pdo, 'bulk.campaign_add', null, null, ['after' => ['kind' => $kind, 'count' => count($r['added']), 'selected' => count($sel['ids']), 'already' => $r['already'], 'source' => $sel['source'], 'list_id' => $sel['list'],
        'filters' => $sel['filters'], 'campaign_id' => $c]]);
    $pdo->commit();
    return [$sel, $r];
});
bulk_done('campaign', $kind, $sel, count($r['added']), 'Added ' . count($r['added']) . ' to ' . $camp['name'] . ($r['already'] > 0 ? ' (' . $r['already'] . ' were already in it)' : ''), ['added' => $r['added'], 'already' => $r['already'], 'campaign_id' => $c]);
