<?php
declare(strict_types=1);
/**
 * Action `tag_apply` (log tag.apply, one row per record): `kind`, `record` (or `records[]` — several of that kind), `tag` (a label; a new one is made).
 * tags.apply and the edit rule on every record (a record the caller may not edit refuses the whole call, naming it).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/tags/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/tags/write.php';
crm_handler_begin();
require_right('tags.apply');
$pdo = db();
$kind = record_kind_from_request();
$ids = array_values(array_unique(array_map('intval', array_filter(request_list('records') ?? [], 'ctype_digit'))));
if (($one = request_integer('record') ?? request_integer('id')) !== null) { array_unshift($ids, $one); $ids = array_values(array_unique($ids)); }
$errors = [];
$label = crm_text('tag', null, 60, 'The tag', $errors, true);
if ($label === null && !isset($errors['tag'])) { $errors['tag'] = 'Name the tag.'; }
if ($ids === []) { $errors['record'] = 'Say which record to tag.'; }
if (count($ids) > 200) { $errors['records'] = 'Tag up to 200 records at once; a list does more (slice 6).'; }
if ($errors !== []) {
    crm_refuse_fields($errors);
}
$rows = [];
foreach ($ids as $rid) {
    $row = require_record($pdo, $kind, $rid);
    require_editable($pdo, $kind, $row);
    $rows[$rid] = $row;
}
$r = crm_guard($pdo, static function () use ($pdo, $kind, $ids, $label, $rows): array {
    $pdo->beginTransaction();
    [$tid, $tlabel, $made] = tag_ensure($pdo, $label);
    $r = tag_apply($pdo, $kind, $ids, $tid);
    foreach ($r['applied'] as $rid) {
        $keys = in_array($kind, ['lead', 'account', 'contact', 'deal'], true) ? [$kind . '_id' => $rid] : [];
        log_activity($pdo, 'tag.apply', $kind, $rid, $keys + ['account_id' => $kind === 'account' ? $rid : $rows[$rid]['account_id'], 'after' => ['tag' => $tlabel, 'tag_id' => $tid, 'made' => $made]]);
    }
    $pdo->commit();
    return $r + ['label' => $tlabel, 'tag_id' => $tid, 'made' => $made];
});
$n = count($r['applied']);
crm_done(count($ids) === 1 ? ($n === 1 ? 'Tagged it ' . $r['label'] : 'It already carries ' . $r['label']) : 'Tagged ' . $n . ' of ' . count($ids) . ' with ' . $r['label'], $ids[0],
    crm_land(return_path(RECORD_KINDS[$kind]['url'] . $ids[0])), $kind . 'Changed', ['applied' => $r['applied'], 'skipped' => $r['skipped'], 'tag_id' => $r['tag_id'], 'tag_made' => $r['made']]);
