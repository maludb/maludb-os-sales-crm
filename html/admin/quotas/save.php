<?php
declare(strict_types=1);
/**
 * Action `quota_set` (log quota.set {member, month, before, after}; pauses for an agent as `other`; quotas.manage): `member`, `month` (2026-11) and `amount` (0 or more; blank removes it). The grid posts every cell as
 * cells[member][YYYY-MM] and the handler applies the ones that differ from what is stored, one quota.set each. The quota is for a seller (a Sales Rep, a Sales Manager or the CRM admin).
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/quotas/write.php';
crm_handler_begin();
require_right('quotas.manage');
$pdo = db();
$sellers = array_map('intval', $pdo->query("SELECT member_id FROM mcp_members WHERE member_kind = 'human' AND roles_resolved && ARRAY['user', 'manager', 'admin']::text[]")->fetchAll(PDO::FETCH_COLUMN));
$amount = static function (string $t, string $field, array &$errors): array {                // [ok, amount|null]
    $t = str_replace([',', ' '], '', trim($t));
    if ($t === '') { return [true, null]; }
    if (!is_numeric($t) || (float) $t < 0 || (float) $t > 99999999999999.0) { $errors[$field] = 'A quota is an amount of 0 or more, or blank for none.'; return [false, null]; }
    return [true, number_format((float) $t, 2, '.', '')];
};
$todo = [];                                                                                      // [member, month, amount|null]
$errors = [];
if (isset($_POST['cells']) && is_array($_POST['cells'])) {
    foreach ($_POST['cells'] as $m => $row) {
        if (!ctype_digit((string) $m) || !is_array($row)) { continue; }
        foreach ($row as $ym => $text) {
            $month = quota_month((string) $ym);
            if ($month === null) { $errors['cells'] = 'A month is like 2026-11.'; continue; }
            [$ok, $a] = $amount((string) $text, "cells[$m][$ym]", $errors);
            if (!$ok) { continue; }
            $now = quota_get($pdo, (int) $m, $month);
            if (($now === null) !== ($a === null) || ($now !== null && (float) $now !== (float) $a)) { $todo[] = [(int) $m, $month, $a]; }
        }
    }
} else {
    $m = request_integer('member');
    $month = quota_month((string) (req_val('month') ?? ''));
    if ($m === null) { $errors['member'] = 'Say whose quota.'; }
    if ($month === null) { $errors['month'] = 'The month is like 2026-11.'; }
    if (!req_has('amount')) { $errors['amount'] = 'Say the amount, or leave it blank to remove the quota.'; }
    [$ok, $a] = $amount((string) (req_val('amount') ?? ''), 'amount', $errors);
    if ($errors === []) { $todo[] = [$m, $month, $a]; }
}
foreach ($todo as [$m]) { if (!in_array($m, $sellers, true)) { $errors['member'] = 'A quota is for a seller — a Sales Rep, a Sales Manager or the CRM admin.'; } }
if ($errors !== []) { crm_refuse_fields($errors); }
$done = crm_guard($pdo, static function () use ($pdo, $todo): int {
    $pdo->beginTransaction();
    $n = 0;
    foreach ($todo as [$m, $month, $a]) {
        $r = quota_set($pdo, $m, $month, $a);
        if ($r['changed']) {
            log_activity($pdo, 'quota.set', 'quota', $m, ['after' => ['member_id' => $m, 'month' => substr($month, 0, 7), 'before' => $r['before'], 'amount' => $r['after']]]);
            $n++;
        }
    }
    $pdo->commit();
    return $n;
});
$year = $todo === [] ? null : (int) substr($todo[0][1], 0, 4);
crm_done($done === 0 ? 'Nothing to change' : 'Saved ' . $done . ' quota' . ($done === 1 ? '' : 's'), $todo === [] ? null : $todo[0][0], crm_land('/admin/quotas' . ($year === null ? '' : '?year=' . $year), $done === 0 ? 'nothing' : 'saved'), 'quotaChanged', ['changed' => $done]);
