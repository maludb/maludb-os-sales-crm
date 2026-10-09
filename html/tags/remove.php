<?php
declare(strict_types=1);
/** Action `tag_remove` (log tag.remove): `kind`, `record` (or `records[]`), `tag` (a label or a slug). tags.apply and the edit rule on every record. */
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
if ($ids === []) { $errors['record'] = 'Say which record to untag.'; }
if ($errors !== []) {
    crm_refuse_fields($errors);
}
$tag = $pdo->prepare('SELECT id, label FROM tags WHERE slug = :s OR lower(label) = lower(:l) LIMIT 1');
$tag->execute(['s' => tag_slug((string) $label), 'l' => $label]);
$t = $tag->fetch();
if ($t === false) {
    crm_refuse_fields(['tag' => 'There is no tag ' . $label . '.']);
}
$rows = [];
foreach ($ids as $rid) {
    $row = require_record($pdo, $kind, $rid);
    require_editable($pdo, $kind, $row);
    $rows[$rid] = $row;
}
$r = crm_guard($pdo, static function () use ($pdo, $kind, $ids, $t, $rows): array {
    $pdo->beginTransaction();
    $r = tag_remove($pdo, $kind, $ids, (int) $t['id']);
    foreach ($r['removed'] as $rid) {
        $keys = in_array($kind, ['lead', 'account', 'contact', 'deal'], true) ? [$kind . '_id' => $rid] : [];
        log_activity($pdo, 'tag.remove', $kind, $rid, $keys + ['account_id' => $kind === 'account' ? $rid : $rows[$rid]['account_id'], 'after' => ['tag' => $t['label'], 'tag_id' => (int) $t['id']]]);
    }
    $pdo->commit();
    return $r;
});
crm_done('Removed ' . $t['label'] . (count($ids) === 1 ? '' : ' from ' . count($r['removed'])), $ids[0], crm_land(return_path(RECORD_KINDS[$kind]['url'] . $ids[0])), $kind . 'Changed',
    ['removed' => $r['removed'], 'skipped' => $r['skipped']]);
