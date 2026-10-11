<?php
declare(strict_types=1);
/** /admin/tags (screen `admin-tags`; tags.manage; ?q= narrows): the tag vocabulary with its use — every tag a chip with its count — and the three things a manager does to one: Rename, Merge into another (the record picker), Delete. JSON: the tags. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/present.php';
require_login();
require_right('tags.manage');
$pdo = db();
$q = mb_substr(request_string('q'), 0, 60);
$tags = tags_with_counts($pdo, $q);
$total = (int) one_value($pdo, 'SELECT count(*) FROM tags');
log_screen_view($pdo, 'admin-tags');
if (wants_json()) {
    respond_screen(['tags' => array_map('present_tag_row', $tags), 'total' => $total, 'q' => $q]);
}
$notices = ['saved' => ['success', 'Renamed the tag.'], 'merged' => ['success', 'Merged the tags.'], 'deleted' => ['success', 'Deleted the tag.']];
render_screen('Tags', view('admin/tags.php', ['tags' => $tags, 'total' => $total, 'q' => $q, 'notice' => crm_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'admin', 'screen' => 'admin-tags', 'entity' => 'tag']);
