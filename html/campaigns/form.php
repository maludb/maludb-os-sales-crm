<?php
declare(strict_types=1);
/** /campaigns/new and /campaigns/{id}/edit (screens `campaign-add`, `campaign-edit`; prefill params name, kind). campaigns.manage. JSON: the values and the choices. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/campaigns/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/campaigns/write.php';
require_login();
require_right('campaigns.manage');
$pdo = db();
$id = request_integer('id');
$c = null;
if ($id === null) {
    $values = array_fill_keys(CAMPAIGN_COLUMNS, null);
    $values['name'] = mb_substr(request_string('name'), 0, 200) ?: null;
    $values['kind'] = isset(CAMPAIGN_KINDS[$k = request_string('kind')]) ? $k : 'email';
    $values['status'] = 'planned';
    $values['owner_member_id'] = (int) current_member_id();
} else {
    require_record($pdo, 'campaign', $id);
    $c = find_campaign($pdo, $id);
    $values = campaign_current($pdo, $id);
}
$screen = $id === null ? 'campaign-add' : 'campaign-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['campaign_id' => $id, 'values' => $values, 'kinds' => CAMPAIGN_KINDS, 'statuses' => CAMPAIGN_STATUSES]);
}
render_screen($id === null ? 'New campaign' : 'Edit ' . $c['name'], view('campaigns/form.php', ['c' => $c, 'values' => $values, 'base_currency' => base_currency($pdo)]),
    ['activeNav' => 'campaigns', 'screen' => $screen, 'entity' => 'campaign', 'recordId' => $id === null ? '' : (string) $id]);
