<?php
declare(strict_types=1);
/**
 * Action `bulk_tag` (log bulk.tag {kind, count, filters?}; confirm — records.bulk or tags.apply): `kind`, `tag` (a label — a new one is made), `remove` (yes — takes the tag off instead), and what to act on —
 * `records[]` or `list` or `filters`. The edit rule on every record: one the caller may not change refuses the whole action, naming it.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bulk/select.php';
require_once dirname(__DIR__, 2) . '/app/features/bulk/write.php';
crm_handler_begin();
require_any_right('records.bulk|tags.apply');
$pdo = db();
$kind = record_kind_from_request(array_keys(FILTER_KINDS));
$errors = [];
$label = crm_text('tag', null, 60, 'The tag', $errors, true);
if ($label === null && !isset($errors['tag'])) { $errors['tag'] = 'Name the tag.'; }
$remove = crm_yes('remove', false);
if ($errors !== []) { crm_refuse_fields($errors); }
[$sel, $changed, $tlabel, $made] = crm_guard($pdo, static function () use ($pdo, $kind, $label, $remove): array {
    $sel = bulk_select($pdo, $kind, $_POST);
    bulk_require_editable($pdo, $kind, $sel['ids']);
    $pdo->beginTransaction();
    [$changed, $tlabel, $made] = bulk_tag($pdo, $kind, $sel['ids'], $label, $remove);
    log_activity($pdo, 'bulk.tag', null, null, ['after' => ['kind' => $kind, 'count' => count($changed), 'selected' => count($sel['ids']), 'source' => $sel['source'], 'list_id' => $sel['list'], 'filters' => $sel['filters'],
        'tag' => $tlabel, 'remove' => $remove, 'tag_made' => $made, 'record_ids' => $changed]]);
    $pdo->commit();
    return [$sel, $changed, $tlabel, $made];
});
bulk_done('tag', $kind, $sel, count($changed), ($remove ? 'Took the tag ' : 'Tagged ') . count($changed) . ' ' . RECORD_KINDS[$kind]['noun'] . (count($changed) === 1 ? '' : 's') . ($remove ? ' off: ' : ' ') . $tlabel, ['changed' => $changed, 'tag' => $tlabel]);
