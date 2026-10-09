<?php
declare(strict_types=1);
/**
 * /settings/ — the person's own settings (screen `settings`): HOW I AM TOLD (email, text, which events, which by text), MY REMINDERS (the default
 * lead time a new activity's reminder gets, the morning note and its hour, the digest), MY SIGNATURE (under the emails I send), MY CALENDAR LINK
 * (the ICS feed — slice 4 makes and rotates it), MY TIME ZONE (the directory's — changed in the operating system). Texts go to the phone verified in
 * the operating system — this application never sees it; what the kernel last said about it is shown in words. People only.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/present.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
$prefs = find_prefs($pdo, $me);
$defaults = settings_defaults($pdo);
$tz = member_timezone();
$refusal = last_text_refusal($pdo, $me);
$signature = my_signature($pdo, $me);
$feed = my_feed($pdo, $me);
$recent = $pdo->prepare('SELECT id, kind, channel, subject, status, detail, created_at FROM notification_outbox WHERE member_id = :m ORDER BY id DESC LIMIT 10');
$recent->execute(['m' => $me]);
$recent = $recent->fetchAll();
log_screen_view($pdo, 'settings');
$may = ['settings' => nav_has_right(ADMIN_RIGHTS), 'feed' => has_right('calendar.own'), 'signature' => has_right('email.send') || has_right('email.log')];
if (wants_json()) {
    respond_screen(['notify' => present_prefs($prefs) + ['text_note' => $refusal], 'kinds' => NOTICE_KINDS, 'remind_choices' => REMIND_CHOICES, 'defaults' => $defaults,
        'signature' => ['set' => $signature !== '', 'length' => mb_strlen($signature)], 'feed' => ['exists' => $feed !== null, 'created_at' => json_ts($feed['created_at'] ?? null)],
        'timezone' => $tz, 'may' => $may]);
}
$notices = ['prefs_saved' => ['success', 'Saved how you are told and reminded.'], 'signature_saved' => ['success', 'Saved your signature.']];
$launcher = rtrim((string) env('OS_LAUNCHER_URL', '/'), '/');
render_screen('My settings', view('settings/index.php', ['prefs' => $prefs, 'defaults' => $defaults, 'refusal' => $refusal, 'tz' => $tz, 'may' => $may,
    'signature' => $signature, 'feed' => $feed, 'osChannels' => $launcher . '/settings/channels', 'osProfile' => $launcher . '/settings',
    'recent' => $recent, 'notice' => $notices[$_GET['notice'] ?? ''] ?? null]),
    ['activeNav' => 'my-settings', 'screen' => 'settings', 'entity' => 'settings']);
