<?php
declare(strict_types=1);
/**
 * Action `key_mint` (log `key.mint`: the key's id, label, limits and expiry — NEVER the key; pauses for an agent as `other`, and then refuses an agent in the handler: a key is a person's door): an API key
 * that acts as the signed-in person, shown ONCE on the next screen (or in this answer for JSON). label (up to 80 characters), rate_per_minute (1 to 100000), rate_per_day (1 to 10000000) — a person may
 * lower the settings' defaults, only a holder of tokens.manage may raise them — and expires_in_days (1 to 3650; blank for never).
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/keys/write.php';
crm_handler_begin();
require_human();
require_right('records.read');
$pdo = db();
$me = (int) current_member_id();
$errors = [];
$label = crm_text('label', null, 80, 'The label', $errors, true);
$perMin = crm_int('rate_per_minute', null, 1, 100000, 'Calls a minute', $errors, true);
$perDay = crm_int('rate_per_day', null, 1, 10000000, 'Calls a day', $errors, true);
$days = crm_int('expires_in_days', null, 1, 3650, 'Days until it expires', $errors, true);
[$defMin, $defDay] = key_default_limits($pdo);
if (!has_right('tokens.manage')) {
    if ($perMin !== null && $perMin > $defMin) { $errors['rate_per_minute'] = 'You may lower the limit but not raise it above ' . $defMin . ' a minute — ask a manager of keys.'; }
    if ($perDay !== null && $perDay > $defDay) { $errors['rate_per_day'] = 'You may lower the limit but not raise it above ' . $defDay . ' a day — ask a manager of keys.'; }
}
if ($errors !== []) { crm_refuse_fields($errors); }
$key = crm_guard($pdo, static function () use ($pdo, $me, $label, $perMin, $perDay, $days): array {
    $pdo->beginTransaction();
    $k = key_mint($pdo, $me, (string) $label, $perMin, $perDay, $days);
    $row = one_row($pdo, 'SELECT rate_per_minute, rate_per_day FROM mcp_access_tokens WHERE id = :id', ['id' => $k['id']]);
    log_activity($pdo, 'key.mint', 'mcp_access_token', $k['id'], ['token_id' => $k['id'], 'after' => ['label' => $label, 'rate_per_minute' => (int) $row['rate_per_minute'], 'rate_per_day' => (int) $row['rate_per_day'], 'expires_in_days' => $days]]);
    $pdo->commit();
    return $k;
});
emit_action_status(true, ['did' => 'Minted the key "' . $label . '" — copy it now, it is shown once', 'record_id' => $key['id'], 'refresh' => 'tokenChanged']);
if (wants_json()) {
    respond_saved(['did' => 'Minted the key "' . $label . '"', 'record_id' => $key['id'], 'key' => $key['raw'], 'location' => '/settings/tokens/#key-' . $key['id'], 'refresh' => 'tokenChanged']);       // the value, once
}
$_SESSION['minted_key'] = ['id' => $key['id'], 'raw' => $key['raw'], 'label' => $key['label'], 'what' => 'API key'];
saved_go('/settings/tokens/', 'tokenChanged');
