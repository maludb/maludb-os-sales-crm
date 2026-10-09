<?php
declare(strict_types=1);
/**
 * Action `prefs_save` (log `settings.prefs`): a person's own notification and reminder choices — email_enabled and text_enabled (yes/no),
 * kinds[] (the events to be told about), text_kinds[] (the events to be texted about), default_remind_minutes (0 to 10080; blank = the business's
 * setting), morning_note (yes/no), morning_hour (0 to 23; blank = the business's), digest (yes/no). A field left out stays as it was. Both
 * channels off is allowed: that person is told nothing but in the app. Always about oneself; an agent may set its own.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
require_post();
require_login();
verify_csrf();
$pdo = db();
$me = (int) current_member_id();
$has = static fn (string $k): bool => array_key_exists($k, $_POST);
$yes = static function (string $k): bool {
    $v = strtolower(trim((string) $_POST[$k]));
    if (in_array($v, ['1', 'true', 'on', 'yes'], true)) {
        return true;
    }
    if (in_array($v, ['0', 'false', 'off', 'no', ''], true)) {
        return false;
    }
    refuse(422, 'Say yes or no for ' . str_replace('_', ' ', $k) . '.');
};
$list = static function (string $k): ?array {
    if (!array_key_exists($k, $_POST)) {
        return null;
    }
    $v = $_POST[$k];
    $v = is_array($v) ? $v : explode(',', (string) $v);
    $v = array_values(array_unique(array_filter(array_map(static fn ($x): string => trim((string) $x), $v), static fn (string $x): bool => $x !== '')));
    $bad = array_diff($v, array_keys(NOTICE_KINDS));
    if ($bad !== []) {
        refuse(422, 'Unknown event: ' . implode(', ', array_map(static fn (string $x): string => mb_substr($x, 0, 30), $bad)) . '.');
    }
    return $v;
};
$intOrBlank = static function (string $k, int $lo, int $hi, string $words): ?int {
    $v = trim((string) $_POST[$k]);
    if ($v === '') {
        return null;
    }
    if (!preg_match('/^\d+$/', $v) || (int) $v < $lo || (int) $v > $hi) {
        refuse(422, $words);
    }
    return (int) $v;
};
$f = [];
foreach (['email_enabled', 'text_enabled', 'morning_note', 'digest'] as $k) {
    if ($has($k)) {
        $f[$k] = $yes($k);
    }
}
if (($kinds = $list('kinds')) !== null) {
    $f['kinds'] = $kinds;
}
if (($textKinds = $list('text_kinds')) !== null) {
    $f['text_kinds'] = $textKinds;
}
if ($has('default_remind_minutes')) {
    $f['default_remind_minutes'] = $intOrBlank('default_remind_minutes', 0, 10080, 'A reminder is set from 0 minutes to a week (10080 minutes) before, or left blank for the business\'s default.');
}
if ($has('morning_hour')) {
    $f['morning_hour'] = $intOrBlank('morning_hour', 0, 23, 'The morning note\'s hour is 0 to 23, or blank for the business\'s.');
}
$pdo->beginTransaction();
$r = save_prefs($pdo, $me, $f);
log_activity($pdo, 'settings.prefs', 'notification_prefs', $me, ['before' => $r['before'], 'after' => $r['after']]);
$pdo->commit();
emit_action_status(true, ['did' => 'Saved how you are told and reminded', 'record_id' => $me, 'refresh' => 'prefsChanged', 'email_enabled' => $r['prefs']['email_enabled'], 'text_enabled' => $r['prefs']['text_enabled']]);
$return = safe_local_path($_POST['return_to'] ?? null) ?? '/settings/';
saved_go($return . (str_contains($return, '?') ? '&' : '?') . 'notice=prefs_saved', 'prefsChanged');
