<?php
declare(strict_types=1);

/**
 * The private calendar feed (activities-calendar.md §5) — txtSchedules' app/features/calendar/ics.php copied: a URL with a 48-hex token per person;
 * only its SHA-256 is kept (calendar_feeds); feed_rotate makes or replaces it (the old URL then answers 404); the feed carries the person's OWN
 * activities in the settings' window (crm_feed_rows): one VEVENT each, CANCELLED once done, a VALARM when a reminder is set.
 */

function feed_hash(string $token): string
{
    return hash('sha256', $token);
}

/** Make (or replace) the person's link. Answers the token — the one and only time it exists in the clear. */
function feed_rotate(PDO $pdo, int $member): string
{
    $token = bin2hex(random_bytes(24));
    $pdo->prepare('INSERT INTO calendar_feeds (member_id, token_hash) VALUES (:m, :h)
                   ON CONFLICT (member_id) DO UPDATE SET token_hash = EXCLUDED.token_hash, created_at = now(), last_used_at = NULL, revoked_at = NULL')
        ->execute(['m' => $member, 'h' => feed_hash($token)]);
    return $token;
}

/** The person a token belongs to (active, link not revoked), or null — a wrong token and a replaced one look the same. Marks the link used. */
function feed_for_token(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        return null;
    }
    $r = one_row($pdo, "SELECT f.member_id, m.display_name FROM calendar_feeds f JOIN members m ON m.id = f.member_id AND m.status = 'active'
                         WHERE f.token_hash = :h AND f.revoked_at IS NULL", ['h' => feed_hash($token)]);
    if ($r === null) {
        return null;
    }
    $pdo->prepare('UPDATE calendar_feeds SET last_used_at = now() WHERE member_id = :m')->execute(['m' => (int) $r['member_id']]);
    return ['member_id' => (int) $r['member_id'], 'name' => (string) $r['display_name']];
}

/** At most $perMinute opens of one token a minute (a counter file per token and minute in the temp directory; the token's hash names it). */
function feed_rate_ok(string $token, int $perMinute = 60): bool
{
    $dir = sys_get_temp_dir() . '/sales_crm_ics';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $file = $dir . '/' . substr(feed_hash($token), 0, 32) . '-' . gmdate('YmdHi');
    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        return true;
    }
    flock($fh, LOCK_EX);
    $n = (int) stream_get_contents($fh) + 1;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, (string) $n);
    flock($fh, LOCK_UN);
    fclose($fh);
    if (random_int(1, 50) === 1) {
        foreach (glob($dir . '/*') ?: [] as $old) { if (filemtime($old) < time() - 180) { @unlink($old); } }
    }
    return $n <= $perMinute;
}

/** RFC 5545 text escaping. */
function ics_text(string $s): string
{
    return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\,', '\n', '\n', '\n'], $s);
}

/** A content line folded at 75 octets (continuation lines start with one space), CRLF-terminated. */
function ics_line(string $line): string
{
    $out = '';
    $max = 75;
    while (strlen($line) > $max) {
        $cut = $max;
        while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
            $cut--;                                                 // never split a UTF-8 character
        }
        $out .= substr($line, 0, $cut) . "\r\n ";
        $line = substr($line, $cut);
        $max = 74;                                                  // a continuation line spends one octet on its leading space
    }
    return $out . $line . "\r\n";
}

function ics_utc(string $ts): string
{
    return (new DateTimeImmutable($ts))->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
}

/** The UID's domain: the application's host name. */
function ics_domain(): string
{
    return (string) (parse_url(app_url('/'), PHP_URL_HOST) ?: 'sales-crm');
}

/**
 * One activity as a VEVENT (the feed's, and the invitation slice 5 attaches with METHOD:REQUEST). $f: crm_feed_rows' row + body, record_url, updated_at, timezone.
 * An INVITATION (slice 5; email.md) adds `organizer` [name, email], `attendees` [[name, email], …] and `external` => true: a stranger reads it, so the summary is
 * the subject alone, the description the message and the meeting link — never the CRM's own address of the activity, its record's name or its internal notes —
 * and the URL line is the meeting link when there is one.
 */
function ics_event(array $f, string $tz, string $stamp): string
{
    $id = (int) $f['activity_id'];
    $external = !empty($f['external']);
    $out = ics_line('BEGIN:VEVENT') . ics_line('UID:crm-activity-' . $id . '@' . ics_domain()) . ics_line('DTSTAMP:' . $stamp);
    if ($f['all_day']) {
        $start = (new DateTimeImmutable($f['starts_at']))->setTimezone(new DateTimeZone($f['timezone'] ?: $tz));
        $out .= ics_line('DTSTART;VALUE=DATE:' . $start->format('Ymd')) . ics_line('DTEND;VALUE=DATE:' . $start->modify('+1 day')->format('Ymd'));
    } else {
        $out .= ics_line('DTSTART:' . ics_utc((string) $f['starts_at'])) . ics_line('DTEND:' . ics_utc((string) $f['ends_at']));
    }
    if ($external) {
        $summary = (string) $f['subject'];
        $desc = trim((string) ($f['body'] ?? '') . (($f['meeting_url'] ?? '') !== '' ? "\n\n" . $f['meeting_url'] : ''));
        $out .= ics_line('SUMMARY:' . ics_text($summary));
        if ($desc !== '') { $out .= ics_line('DESCRIPTION:' . ics_text($desc)); }
        if (($f['meeting_url'] ?? '') !== '') { $out .= ics_line('URL:' . $f['meeting_url']); }
    } else {
        $summary = (ACTIVITY_KINDS[$f['kind']] ?? ucfirst((string) $f['kind'])) . ': ' . $f['subject'] . (($f['record_name'] ?? '') !== '' ? ' — ' . $f['record_name'] : '');
        $desc = $f['record_url'] . (($f['body'] ?? '') !== '' ? "\n\n" . mb_substr((string) $f['body'], 0, 200) : '');
        $out .= ics_line('SUMMARY:' . ics_text($summary)) . ics_line('DESCRIPTION:' . ics_text($desc)) . ics_line('URL:' . app_url('/activities/' . $id));
    }
    if (($f['location'] ?? '') !== '') {
        $out .= ics_line('LOCATION:' . ics_text((string) $f['location']));
    }
    if (isset($f['updated_at'])) {
        $out .= ics_line('LAST-MODIFIED:' . ics_utc((string) $f['updated_at']));
    }
    if (!empty($f['organizer'])) {
        $out .= ics_line('ORGANIZER;CN=' . ics_param((string) $f['organizer'][0]) . ':mailto:' . $f['organizer'][1]);
    }
    foreach ($f['attendees'] ?? [] as [$name, $email]) {
        $out .= ics_line('ATTENDEE;CN=' . ics_param((string) $name) . ';ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:' . $email);
    }
    $out .= ics_line('STATUS:' . ($f['done'] ? 'CANCELLED' : 'CONFIRMED'));
    if ($f['remind_minutes_before'] !== null && !$f['done']) {
        $out .= ics_line('BEGIN:VALARM') . ics_line('ACTION:DISPLAY') . ics_line('DESCRIPTION:' . ics_text($summary))
            . ics_line('TRIGGER:-PT' . (int) $f['remind_minutes_before'] . 'M') . ics_line('END:VALARM');
    }
    return $out . ics_line('END:VEVENT');
}

/** A parameter value (CN=…): quoted, with the characters a parameter cannot hold taken out. */
function ics_param(string $v): string
{
    return '"' . str_replace(['"', "\r", "\n"], ["'", ' ', ' '], $v) . '"';
}

/** One meeting as an invitation (an .ics a mail client offers Accept/Decline on): METHOD:REQUEST around ics_event(), the organizer the seller, the attendees the contacts. */
function ics_invitation(array $f, string $tz): string
{
    return ics_line('BEGIN:VCALENDAR') . ics_line('VERSION:2.0') . ics_line('PRODID:-//MaluDB Business OS//Sales CRM//EN') . ics_line('CALSCALE:GREGORIAN') . ics_line('METHOD:REQUEST')
        . ics_event($f + ['external' => true], $tz, gmdate('Ymd\THis\Z')) . ics_line('END:VCALENDAR');
}

/** The person's feed as text (CRLF lines): their own activities in the settings' window (crm_feed_rows), the record's URL and the body's start in each. */
function feed_render(PDO $pdo, int $member): string
{
    $name = (string) one_value($pdo, 'SELECT display_name FROM members WHERE id = :m', ['m' => $member]);
    $tz = (string) (one_value($pdo, 'SELECT timezone FROM members WHERE id = :m', ['m' => $member]) ?: 'UTC');
    $st = $pdo->prepare('SELECT f.*, a.body, a.updated_at, a.timezone, a.deal_id, a.contact_id, a.account_id, a.lead_id FROM crm_feed_rows(:m) f JOIN activities a ON a.id = f.activity_id');
    $st->execute(['m' => $member]);
    $out = ics_line('BEGIN:VCALENDAR') . ics_line('VERSION:2.0') . ics_line('PRODID:-//MaluDB Business OS//Sales CRM//EN') . ics_line('CALSCALE:GREGORIAN') . ics_line('METHOD:PUBLISH')
        . ics_line('X-WR-CALNAME:' . ics_text('Sales CRM — ' . $name)) . ics_line('X-WR-TIMEZONE:' . $tz)
        . ics_line('REFRESH-INTERVAL;VALUE=DURATION:PT15M') . ics_line('X-PUBLISHED-TTL:PT15M');
    $stamp = gmdate('Ymd\THis\Z');
    foreach ($st->fetchAll() as $f) {
        $rec = row_record($f);
        $f['record_url'] = app_url($rec === null ? '/activities/' . (int) $f['activity_id'] : $rec[2]);
        $f['all_day'] = (bool) $f['all_day'];
        $f['done'] = (bool) $f['done'];
        $f['remind_minutes_before'] = $f['remind_minutes_before'] === null ? null : (int) $f['remind_minutes_before'];
        $out .= ics_event($f, $tz, $stamp);
    }
    return $out . ics_line('END:VCALENDAR');
}

