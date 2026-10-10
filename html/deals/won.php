<?php
declare(strict_types=1);
/**
 * GET /deals/{id}/won — screen `deal-won`: confirm the value and the close date (a drop on the board's Won column lands here).
 * POST — action `deal_won` (log deal.won {value, currency, close_date} — the edit rule): `value` (confirmed; a deal whose value follows its lines keeps the
 * lines' total), `close_date` (today by default, never later). The fields set, then crm_deal_move to the pipeline's Won stage — the account turns customer.
 * A custom field required to win (required_to_win) that the deal lacks is refused by name.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/deals/write.php';
require_login();
$pdo = db();
$id = request_integer('deal') ?? request_integer('id');
$row = require_record($pdo, 'deal', $id);
$deal = find_deal($pdo, $id);
$today = (string) one_value($pdo, "SELECT (now() AT TIME ZONE COALESCE(NULLIF(:tz, ''), 'UTC'))::date::text", ['tz' => member_timezone()]);
$requiredToWin = array_values(array_filter(custom_field_defs($pdo, 'deal', (int) $deal['pipeline_id']), static fn ($d) => $d['required_to_win'] === true || $d['required_to_win'] === 't'));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    require_editable($pdo, 'deal', $row);
    if ($deal['status'] === 'won') { redirect('/deals/' . $id . '?notice=won'); }
    log_screen_view($pdo, 'deal-won');
    if (wants_json()) {
        respond_screen(['deal_id' => $id, 'title' => $deal['title'], 'value' => (float) $deal['value'], 'currency' => $deal['currency'], 'value_from_products' => (bool) $deal['value_from_products'],
            'close_date' => $today, 'required_to_win' => array_map(static fn ($d) => $d['key'], $requiredToWin)]);
    }
    render_screen('Won: ' . $deal['title'], view('deals/won.php', ['deal' => $deal, 'today' => $today, 'required' => $requiredToWin]), ['activeNav' => 'pipeline', 'screen' => 'deal-won', 'entity' => 'deal', 'recordId' => (string) $id]);
    exit;
}
crm_handler_begin();
require_editable($pdo, 'deal', $row);
if ($deal['status'] === 'won') { refuse(422, 'The deal is won already.'); }
$errors = [];
$f = ['value' => null, 'close_date' => $today];
if (req_has('value') && (string) req_val('value') !== '' && !$deal['value_from_products']) {
    $v = str_replace([',', ' '], '', (string) req_val('value'));
    if (!is_numeric($v) || (float) $v < 0 || (float) $v >= 1e12) { $errors['value'] = 'The value is an amount of 0 or more.'; } else { $f['value'] = $v; }
}
if (req_has('close_date') && (string) req_val('close_date') !== '') {
    $d = (string) req_val('close_date');
    if (($dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d)) === false || $dt->format('Y-m-d') !== $d) { $errors['close_date'] = 'The close date is a date (YYYY-MM-DD).'; }
    elseif ($d > $today) { $errors['close_date'] = 'A won deal closed today or earlier.'; }
    else { $f['close_date'] = $d; }
}
foreach ($requiredToWin as $d) {
    $v = $deal['custom_values'][$d['key']] ?? null;
    if ($v === null || $v === '' || $v === []) { $errors['custom[' . $d['key'] . ']'] = $d['label'] . ' is required to win — set it on the deal first.'; }
}
if ($errors !== []) { crm_refuse_fields($errors); }
$r = deal_guard($pdo, static function () use ($pdo, $id, $f, $deal): array {
    $pdo->beginTransaction();
    $r = deal_close($pdo, $id, 'won', $f);
    log_activity($pdo, 'deal.won', 'deal', $id, ['deal_id' => $id, 'account_id' => $deal['account_id'], 'contact_id' => $deal['primary_contact_id'], 'after' => $r]);
    $pdo->commit();
    return $r;
});
crm_done('Won ' . $deal['title'] . ' — ' . money($r['value'], $r['currency']), $id, crm_land(return_path('/deals/' . $id), 'won'), 'dealChanged');
