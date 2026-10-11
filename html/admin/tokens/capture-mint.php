<?php
declare(strict_types=1);
/**
 * Action `capture_key_mint` (log key.capture_mint {key id, label, source, rate_per_day} — NEVER the key; pauses for an agent as `other`, and then refuses an agent in the handler: people only; tokens.manage): a LEAD-CAPTURE key for the
 * website's form — `label` (up to 80 characters: the form it serves), `source` (the lead source its leads carry), `rate_per_day` (1 to 10,000,000; the settings' capture rate by default). It admits POST /api/v1/leads and nothing else. The value is
 * shown ONCE, on the next screen (or in this answer for JSON); only its hash is kept.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/keys/write.php';
crm_handler_begin();
require_human();
require_right('tokens.manage');
$pdo = db();
$me = (int) current_member_id();
$errors = [];
$label = crm_text('label', null, 80, 'The label', $errors, true);
if ($label === null && !isset($errors['label'])) { $errors['label'] = 'The label is required.'; }
$source = crm_ref($pdo, 'source', null, 'SELECT 1 FROM lead_sources WHERE id = :id AND active', 'lead source', $errors, false);
if ($source === null && !isset($errors['source'])) { $errors['source'] = 'Choose the lead source its leads carry.'; }
$perDay = crm_int('rate_per_day', null, 1, 10000000, 'Leads a day', $errors, true);
if ($errors !== []) { crm_refuse_fields($errors); }
$key = crm_guard($pdo, static function () use ($pdo, $me, $label, $source, $perDay): array {
    $pdo->beginTransaction();
    $k = capture_key_mint($pdo, (string) $label, (int) $source, $perDay, $me);
    $row = one_row($pdo, 'SELECT rate_per_day FROM mcp_access_tokens WHERE id = :id', ['id' => $k['id']]);
    log_activity($pdo, 'key.capture_mint', 'mcp_access_token', $k['id'], ['token_id' => $k['id'], 'after' => ['label' => $label, 'source_id' => $source, 'rate_per_day' => (int) $row['rate_per_day']]]);
    $pdo->commit();
    return $k;
});
emit_action_status(true, ['did' => 'Minted the lead-capture key "' . $label . '" — copy it now, it is shown once', 'record_id' => $key['id'], 'refresh' => 'tokenChanged']);
if (wants_json()) {
    respond_saved(['did' => 'Minted the lead-capture key "' . $label . '"', 'record_id' => $key['id'], 'key' => $key['raw'], 'location' => '/admin/tokens#key-' . $key['id'], 'refresh' => 'tokenChanged']);
}
$_SESSION['minted_key'] = ['id' => $key['id'], 'raw' => $key['raw'], 'label' => $key['label'], 'what' => 'lead-capture key'];
saved_go('/admin/tokens', 'tokenChanged');
