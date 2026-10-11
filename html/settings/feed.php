<?php
declare(strict_types=1);
/**
 * Action `feed_rotate` (log feed.rotate — the member, never the token; own and calendar.own; people): `confirm` = yes. A new 48-hex link replaces the
 * old one, which answers 404 from then on (activities-calendar.md §5). The link is shown ONCE — on My settings as the answer, or as `url` in JSON —
 * and only its SHA-256 is kept.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/calendar/ics.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/present.php';
crm_handler_begin();
require_human();
require_right('calendar.own');
if (req_val('confirm') !== 'yes') {
    crm_refuse_fields(['confirm' => 'Confirm it: a new link stops the old one working.']);
}
$pdo = db();
$me = (int) current_member_id();
$had = my_feed($pdo, $me) !== null;
$token = crm_guard($pdo, static function () use ($pdo, $me, $had): string {
    $pdo->beginTransaction();
    $t = feed_rotate($pdo, $me);
    log_activity($pdo, 'feed.rotate', 'member', $me, ['after' => ['replaced' => $had]]);
    $pdo->commit();
    return $t;
});
$url = app_url('/api/v1/calendar.ics?token=' . $token);
emit_action_status(true, ['did' => $had ? 'Made a new calendar link; the old one stops working' : 'Made your calendar link', 'record_id' => $me]);
if (wants_json()) {
    respond_saved(['did' => $had ? 'Made a new calendar link; the old one stops working' : 'Made your calendar link', 'record_id' => $me, 'url' => $url]);
}
header('Cache-Control: no-store');
$prefs = find_prefs($pdo, $me);
$tz = member_timezone();
$recent = $pdo->prepare('SELECT id, kind, channel, subject, status, detail, created_at FROM notification_outbox WHERE member_id = :m ORDER BY id DESC LIMIT 10');
$recent->execute(['m' => $me]);
$launcher = rtrim((string) env('OS_LAUNCHER_URL', '/'), '/');
$html = view('settings/index.php', ['prefs' => $prefs, 'defaults' => settings_defaults($pdo), 'refusal' => last_text_refusal($pdo, $me), 'tz' => $tz,
    'may' => ['settings' => nav_has_right(ADMIN_RIGHTS), 'feed' => true, 'signature' => has_right('email.send') || has_right('email.log')],
    'signature' => my_signature($pdo, $me), 'feed' => my_feed($pdo, $me), 'feed_url' => $url, 'osChannels' => $launcher . '/settings/channels', 'osProfile' => $launcher . '/settings',
    'recent' => $recent->fetchAll(), 'notice' => ['success', $had ? 'Made a new calendar link — the old one stops working. Copy it now: it is shown once.' : 'Made your calendar link. Copy it now: it is shown once.']]);
if (is_htmx_request()) {
    header('HX-Push-Url: /settings/');
    echo $html;
    exit;
}
render_screen('My settings', $html, ['activeNav' => 'my-settings', 'screen' => 'settings', 'entity' => 'settings']);
