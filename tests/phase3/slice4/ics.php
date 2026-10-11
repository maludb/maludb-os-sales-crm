<?php
/** Proof — the ICS feed (activities-calendar.md §5): feed_rotate (people, calendar.own) shows the link once; the feed lists the owner's activities as
 * VEVENTs (UID, times, SUMMARY, the record's URL, CANCELLED when done, a VALARM when a reminder is set; all day as a date); someone else's never; a
 * rotated token kills the old URL; a bad token 404; feed.read logged with the member, never the token. */
require __DIR__ . '/lib.php';
$x = run_tag();
$d = deal_make(26);
$a = act_id(26, ['kind' => 'meeting', 'subject' => "Feed meeting $x", 'deal' => $d, 'starts_at' => at('+2 days 14:00'), 'ends_at' => at('+2 days 15:00'), 'location' => 'Room 4', 'body' => str_repeat('Agenda. ', 40)]);
$done = act_id(26, ['kind' => 'call', 'subject' => "Feed done $x", 'deal' => $d, 'done' => 'yes']);
$quiet = act_id(26, ['kind' => 'task', 'subject' => "Feed quiet $x", 'due_at' => at('+3 days 09:00'), 'remind_minutes_before' => 'none']);
$allday = act_id(26, ['kind' => 'deadline', 'subject' => "Feed all day $x", 'deal' => $d, 'due_at' => gmdate('Y-m-d', strtotime('+5 days')), 'all_day' => 'yes']);
$others = act_id(30, ['kind' => 'task', 'subject' => "Dana feed $x", 'due_at' => at('+1 day')]);
echo "1. The link\n";
$since = last_activity_id();
ok(post_as(26, '/settings/feed.php', [])['code'] === 422, 'feed_rotate asks to be confirmed');
$r = post_as(26, '/settings/feed.php', ['confirm' => 'yes']);
$url = (string) ($r['json']['url'] ?? '');
ok($r['code'] === 200 && preg_match('#/api/v1/calendar\.ics\?token=([a-f0-9]{48})$#', $url, $m) === 1, 'feed_rotate: a link with a 48-hex token, shown once');
$token = $m[1] ?? '';
ok((int) one('SELECT count(*) FROM calendar_feeds WHERE member_id = 26 AND token_hash = :h', ['h' => hash('sha256', $token)]) === 1 && (int) one('SELECT count(*) FROM calendar_feeds WHERE token_hash = :t', ['t' => $token]) === 0, 'only its SHA-256 is kept');
$rl = activity('feed.rotate', $since);
ok(count($rl) === 1 && !str_contains(json_encode($rl), $token), 'feed.rotate logged, never the token');
[$jj, $tt] = actor(26);
$hh = req('POST', '/settings/feed.php', ['jar' => $jj, 'headers' => ['HX-Request: true', 'X-CSRF-Token: ' . $tt], 'form' => ['confirm' => 'yes']]);
ok($hh['code'] === 200 && preg_match('/id="settings-feed-url" value="[^"]*token=([a-f0-9]{48})"/', $hh['body'], $mm) === 1, 'from My settings: the page comes back with the new link in a box to copy');
$token = $mm[1] ?? $token;
ok(in_array(post_as(40, '/settings/feed.php', ['confirm' => 'yes'])['code'], [401, 403], true) && post_as(29, '/settings/feed.php', ['confirm' => 'yes'])['code'] === 403, 'an agent (people only) and a Viewer (no calendar.own) make none');
echo "2. The feed\n";
$since = last_activity_id();
$f = req('GET', '/api/v1/calendar.ics?token=' . $token);
$ics = $f['body'];
ok($f['code'] === 200 && str_contains($f['headers'], 'text/calendar') && str_starts_with($ics, "BEGIN:VCALENDAR\r\n") && str_ends_with($ics, "END:VCALENDAR\r\n"), 'text/calendar, CRLF lines');
ok(str_contains($ics, 'X-WR-CALNAME:Sales CRM — ') && str_contains($ics, 'REFRESH-INTERVAL;VALUE=DURATION:PT15M'), 'its name and a 15-minute refresh');
$ev = static function (string $ics, int $id): string { return preg_match('/BEGIN:VEVENT\r\nUID:crm-activity-' . $id . '@.*?END:VEVENT\r\n/s', $ics, $m) ? str_replace("\r\n ", '', $m[0]) : ''; };
$e = $ev($ics, $a);
ok($e !== '' && str_contains($e, 'SUMMARY:Meeting: Feed meeting ' . $x) && str_contains($e, 'DTSTART:' . gmdate('Ymd', strtotime('+2 days')) . 'T140000Z') && str_contains($e, 'DTEND:' . gmdate('Ymd', strtotime('+2 days')) . 'T150000Z'),
    'a meeting: its UID, the kind and subject, start and end in UTC');
ok(str_contains($e, 'LOCATION:Room 4') && str_contains($e, "/deals/$d") && str_contains($e, 'STATUS:CONFIRMED') && str_contains($e, "BEGIN:VALARM\r\nACTION:DISPLAY") && str_contains($e, 'TRIGGER:-PT30M'), '…where, the record\'s URL, confirmed, a VALARM 30 minutes before');
ok(mb_strlen(preg_replace('/.*DESCRIPTION:[^\r]*?\\\\n\\\\n([^\r]*)\r\n.*/s', '$1', $e)) <= 200, '…the body\'s first 200 characters at most');
ok(str_contains($ev($ics, $done), 'STATUS:CANCELLED') && !str_contains($ev($ics, $done), 'VALARM'), 'a done one: CANCELLED, no alarm');
ok($ev($ics, $quiet) !== '' && !str_contains($ev($ics, $quiet), 'VALARM'), 'no reminder: no VALARM');
ok(str_contains($ev($ics, $allday), 'DTSTART;VALUE=DATE:' . gmdate('Ymd', strtotime('+5 days'))) && str_contains($ev($ics, $allday), 'DTEND;VALUE=DATE:' . gmdate('Ymd', strtotime('+6 days'))), 'all day: a date, ending the next day');
ok($ev($ics, $others) === '', 'Dana\'s activity is never in Priya\'s feed');
$lines = explode("\r\n", rtrim($ics, "\r\n"));
ok(max(array_map('strlen', $lines)) <= 75, 'every line folded at 75 octets');
$fr = activity('feed.read', $since);
ok(count($fr) === 1 && (int) $fr[0]['actor_member_id'] === 26 && $fr[0]['source'] === 'api' && !str_contains(json_encode($fr), $token), 'feed.read logged with the member, source api, never the token');
ok(q('SELECT last_used_at FROM calendar_feeds WHERE member_id = 26')[0]['last_used_at'] !== null, 'the link\'s last use is kept (My settings shows it)');
echo "3. Rotated, wrong, revoked\n";
$r = post_as(26, '/settings/feed.php', ['confirm' => 'yes']);
parse_str((string) parse_url((string) $r['json']['url'], PHP_URL_QUERY), $q2);
ok(req('GET', '/api/v1/calendar.ics?token=' . $token)['code'] === 404 && req('GET', '/api/v1/calendar.ics?token=' . $q2['token'])['code'] === 200, 'a new link: the old URL answers 404, the new one 200');
ok(req('GET', '/api/v1/calendar.ics?token=' . str_repeat('a', 48))['code'] === 404 && req('GET', '/api/v1/calendar.ics')['code'] === 404 && req('GET', '/api/v1/calendar.ics?token=xyz')['code'] === 404, 'a wrong, missing or malformed token: the same 404');
ok(req('POST', '/api/v1/calendar.ics?token=' . $q2['token'])['code'] === 405, 'GET only');
pg_run("UPDATE calendar_feeds SET revoked_at = now() WHERE member_id = 26");
ok(req('GET', '/api/v1/calendar.ics?token=' . $q2['token'])['code'] === 404, 'a revoked link: 404');
ok(str_contains(html_as(26, '/settings/')['body'], 'id="settings-feed-btn"'), 'My settings offers to make one');
finish();
