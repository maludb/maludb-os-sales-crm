<?php
declare(strict_types=1);
/**
 * GET /duplicates/merge?kind=&a=&b= — screen `merge`: the two records in two columns, every differing field marked, the survivor chosen (the older by default).
 * POST — action `records_merge` (log records.merge; confirm; PAUSES FOR AN AGENT — deletion — records.merge): `kind` (lead, account or contact), `survivor`, `loser`.
 * crm_records_merge keeps the survivor's values and takes the loser's where empty, moves every child (emails, activities, deals, notes, files, tags, lists), and
 * leaves the loser a tombstone pointing at the survivor.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/duplicates/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/duplicates/present.php';
require_once dirname(__DIR__, 2) . '/app/features/duplicates/write.php';
require_login();
$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    require_right('records.read');
    $kind = isset(DUPLICATE_KINDS[$k = request_string('kind')]) ? $k : refuse(422, 'Say which kind: contact, account or lead.');
    $a = request_integer('a');
    $b = request_integer('b');
    $m = $a !== null && $b !== null && $a !== $b ? merge_preview($pdo, $kind, $a, $b) : [];
    if ($m === []) { refuse(404, 'Those two records are not here.'); }
    log_screen_view($pdo, 'merge');
    if (wants_json()) {
        $side = static fn (array $r): array => array_intersect_key($r, MERGE_FIELDS[$kind]);
        respond_screen(['kind' => $kind, 'a' => $a, 'b' => $b, 'survivor' => $m['survivor'], 'fields' => $m['fields'], 'differs' => $m['differs'], 'records' => ['a' => $side($m['a']), 'b' => $side($m['b'])]]);
    }
    render_screen('Merge', view('duplicates/merge.php', ['kind' => $kind, 'a' => $a, 'b' => $b, 'm' => $m, 'may' => ['merge' => has_right('records.merge')]]), ['activeNav' => 'leads', 'screen' => 'merge', 'entity' => $kind]);
    exit;
}
crm_handler_begin();
require_right('records.merge');
$kind = record_kind_from_request(array_keys(DUPLICATE_KINDS));
$errors = [];
$s = request_integer('survivor');
$l = request_integer('loser');
if ($s !== null && ($pa = request_integer('a')) !== null && ($pb = request_integer('b')) !== null) {     // the form sends the pair: the loser is the one not kept
    if ($s !== $pa && $s !== $pb) { crm_refuse_fields(['survivor' => 'Keep one of the two.']); }
    $l = $s === $pa ? $pb : $pa;
}
if ($s === null) { $errors['survivor'] = 'Say which record is kept.'; }
if ($l === null) { $errors['loser'] = 'Say which record is merged into it.'; }
if ($errors === [] && $s === $l) { $errors['loser'] = 'A record is not merged into itself.'; }
if ($errors !== []) { crm_refuse_fields($errors); }
$rs = require_record($pdo, $kind, $s);
$rl = require_record($pdo, $kind, $l);
crm_guard($pdo, static function () use ($pdo, $kind, $s, $l, $rs, $rl): void {
    $pdo->beginTransaction();
    records_merge($pdo, $kind, $s, $l);
    $keys = [$kind . '_id' => $s] + ($kind === 'contact' && $rs['account_id'] !== null ? ['account_id' => (int) $rs['account_id']] : []);
    log_activity($pdo, 'records.merge', $kind, $s, $keys + ['after' => ['kind' => $kind, 'survivor' => $s, 'loser' => $l, 'survivor_name' => $rs['name'], 'loser_name' => $rl['name']]]);
    $pdo->commit();
});
crm_done('Merged ' . $rl['name'] . ' into ' . $rs['name'], $s, crm_land(return_path(RECORD_KINDS[$kind]['url'] . $s), 'merged'), $kind . 'Changed', ['loser' => $l]);
