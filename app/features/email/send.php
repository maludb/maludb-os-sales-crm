<?php
declare(strict_types=1);

/**
 * Sending (email.md "send.php"): one outbound_email row is one message to ONE person. email_deliver() takes a row that is still to send (a draft: `queued`, or one
 * that did not go: `failed`), fills what merge fields remain for THIS recipient, builds the text and HTML parts with the footer and the unsubscribe link, and sends
 * it through MaluMail's /v1/send — From the business's MAIL_FROM under the seller's name, Reply-To the logging mailbox (so the answer lands on the timeline), a Message-ID
 * of ours (kept in outbound_email.message_id so the matcher finds the deal when they answer), In-Reply-To / References when it answers something, the meeting's
 * ICS as text/calendar; method=REQUEST, the files of the canonical store. On the provider's yes: status `sent`, one `activities` row (kind email, out, done) on the
 * contact and the deal, the template's use counted, `email.send` logged. A suppressed address is `suppressed`; any other failure `failed` with the sentence — the
 * seller may send it again. A body, a token or the key is never in the log.
 *
 * THE UNSUBSCRIBE TOKEN (48 hex, one per contact per send): only its SHA-256 is kept — as the local part of the message's Message-ID,
 * `<sha256(token)@MAIL_FROM's domain>`. The link in the mail carries the token; the door (/u/{token}) hashes it and finds the row by that Message-ID.
 */
require_once __DIR__ . '/merge.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/../calendar/ics.php';
require_once __DIR__ . '/../attachments/store.php';
require_once __DIR__ . '/../settings/present.php';
require_once __DIR__ . '/../activities/write.php';

/** The provider answers a campaign's message is tried again after (a rate limit, a relay that was down), and how many tries it gets in all before it is `failed`. */
const EMAIL_RETRYABLE_STATUSES = [429, 502, 503, 504];
const EMAIL_CAMPAIGN_ATTEMPTS = 5;

/** The domain of Message-IDs and of the sender: MAIL_FROM's (verified at MaluMail), else the application's host. */
function email_domain(): string
{
    $from = (string) env('MAIL_FROM', '');
    $d = str_contains($from, '@') ? substr($from, (int) strrpos($from, '@') + 1) : '';
    return $d !== '' ? strtolower($d) : (string) (parse_url(app_url('/'), PHP_URL_HOST) ?: 'localhost');
}

/** A fresh unsubscribe token: 48 hex. */
function email_unsub_token(): string
{
    return bin2hex(random_bytes(24));
}

/** The Message-ID that carries the token's SHA-256 (the only trace of the token in the database). */
function email_message_id_for(string $token): string
{
    return '<' . hash('sha256', $token) . '@' . email_domain() . '>';
}

/** The outbound email a token names (the door's lookup), or null: [id, contact_id, to_address]. */
function email_by_unsub_token(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        return null;
    }
    return one_row($pdo, "SELECT id, contact_id, to_address::text AS to_address, campaign_id FROM outbound_email WHERE message_id = :m AND status = 'sent'", ['m' => email_message_id_for($token)]);
}

/** The text under every CRM-sent email: the business's footer (Markdown) and the unsubscribe line. Answers [text, html]. */
function email_footer(PDO $pdo, string $unsubUrl): array
{
    $footer = trim((string) (one_value($pdo, "SELECT crm_setting_text('email_footer')") ?? ''));
    $text = ($footer !== '' ? "\n\n-- \n" . $footer : "\n\n-- ") . "\nIf you would rather not hear from us by email, unsubscribe: " . $unsubUrl . "\n";
    $html = '<hr style="border:0;border-top:1px solid #dde1e6;margin:24px 0 12px">'
        . ($footer !== '' ? '<div style="font-size:12px;color:#6b7280">' . md_render($footer) . '</div>' : '')
        . '<p style="font-size:12px;color:#6b7280">If you would rather not hear from us by email, <a href="' . e($unsubUrl) . '">unsubscribe</a>.</p>';
    return [$text, $html];
}

/** The files a draft carries as MaluMail takes them: [{filename, content_type, content_base64}], within 20 MB in all (a DomainException says when it is more). */
function email_payload_files(PDO $pdo, int $outboundId): array
{
    $st = $pdo->prepare("SELECT filename, mime_type, byte_size, storage_path FROM attachments WHERE record_type = 'outbound_email' AND record_id = :id ORDER BY id");
    $st->execute(['id' => $outboundId]);
    $out = [];
    $total = 0;
    foreach ($st->fetchAll() as $f) {
        $abs = attachment_storage_dir() . '/' . $f['storage_path'];
        $total += (int) $f['byte_size'];
        if ($total > 20 * 1048576) {
            throw new DomainException('The files come to more than 20 MB; send fewer or smaller ones.');
        }
        if (!is_file($abs)) {
            throw new DomainException('The file ' . $f['filename'] . ' is no longer here; take it off and attach it again.');
        }
        $out[] = ['filename' => $f['filename'], 'content_type' => $f['mime_type'], 'content_base64' => base64_encode((string) file_get_contents($abs))];
    }
    return $out;
}

/**
 * The invitation for one meeting and one attendee: METHOD:REQUEST, the seller the organizer. Answers the .ics text. $message is the line the seller wrote above the
 * invitation (it becomes the event's description). A meeting that is done, all-day-less without a start, or not visible to the caller is a DomainException.
 */
function email_invitation(PDO $pdo, int $activity, array $attendee, string $message = ''): string
{
    $a = one_row($pdo, 'SELECT activity_id, kind, subject, starts_at, ends_at, all_day, timezone, location, meeting_url, updated_at, done_at FROM mcp_activities WHERE activity_id = :a', ['a' => $activity]);
    if ($a === null || $a['starts_at'] === null) {
        throw new DomainException('That meeting is not here.');
    }
    $me = (int) current_member_id();
    $org = one_row($pdo, 'SELECT display_name, email::text AS email FROM members WHERE id = :m', ['m' => $me]);
    $tz = (string) (one_value($pdo, 'SELECT timezone FROM members WHERE id = :m', ['m' => $me]) ?: 'UTC');
    $a['ends_at'] ??= (new DateTimeImmutable($a['starts_at']))->modify('+1 hour')->format(DATE_ATOM);
    $a['all_day'] = (bool) $a['all_day'];
    $a['done'] = false;
    $a['remind_minutes_before'] = null;
    $a['body'] = $message;
    $a['organizer'] = [(string) ($org['display_name'] ?? 'Sales CRM'), (string) ($org['email'] ?? env('MAIL_FROM', 'crm@localhost'))];
    $a['attendees'] = [[(string) $attendee['name'], (string) $attendee['address']]];
    return ics_invitation($a, $tz);
}

/** What MaluMail's answer means: [state sent|suppressed|failed, the sentence for a person or null]. */
function email_provider_outcome(array $r): array
{
    $status = (int) $r['status'];
    $rejected = (array) ($r['body']['rejected'] ?? []);
    if ($status >= 200 && $status < 300 && $rejected === []) {
        return ['sent', null];
    }
    if ($status === 400 || ($status >= 200 && $status < 300 && $rejected !== [])) {
        $why = (string) ($rejected[0]['reason'] ?? ($r['body']['error'] ?? 'undeliverable'));
        return ['suppressed', str_starts_with($why, 'suppressed') ? 'MaluMail will not deliver to that address (' . $why . ').' : 'MaluMail would not take that address (' . $why . ').'];
    }
    return ['failed', 'MaluMail did not take it (HTTP ' . $status . (isset($r['body']['error']) ? ': ' . mb_substr((string) $r['body']['error'], 0, 160) : '') . '); send it again in a moment.'];
}

/**
 * Send one draft row now. $row's sender must be the caller (a draft is its sender's alone). Answers ['state' => sent|suppressed|failed, 'error' => ?sentence,
 * 'outbound_id', 'contact_id', 'to', 'activity_id' => ?int]. Refuses (DomainException) a recipient who opted out — by name — a row not theirs or already sent, merge fields
 * left unfilled, an invitation for a meeting that is gone. A CAMPAIGN's message (campaign_id) that the provider could not take for now (429, 502, 503, 504, a transport error) stays `queued` with attempts
 * counted and retry_at set (answers state `retry`) until EMAIL_CAMPAIGN_ATTEMPTS; one sent moves its member from planned to sent. $opts: invite_message (the line the seller wrote above an invitation). The advisory lock keeps a double click from sending twice.
 */
function email_deliver(PDO $pdo, int $id, array $opts = []): array
{
    $me = (int) current_member_id();
    if (!(bool) one_value($pdo, 'SELECT pg_try_advisory_lock(hashtext(:k))', ['k' => 'crm-email-send:' . $id])) {
        throw new DomainException('That email is being sent already.');
    }
    try {
        $o = one_row($pdo, "SELECT id, to_address::text AS to_address, subject, body_text, contact_id, deal_id, template_id, has_ics, activity_id, in_reply_to, references_ids::text AS refs, is_private, status, sender_member_id,
                                   campaign_id, attempts
                              FROM outbound_email WHERE id = :id", ['id' => $id]);
        if ($o === null || (int) $o['sender_member_id'] !== $me) { throw new DomainException('Not found.'); }
        if ($o['status'] === 'sent') { throw new DomainException('That email was sent already.'); }
        if ($o['contact_id'] === null) { throw new DomainException('That email has no one to go to.'); }
        $contact = (int) $o['contact_id'];
        $c = one_row($pdo, 'SELECT contact_id, name, first_name, email_opt_out FROM mcp_contacts WHERE contact_id = :c', ['c' => $contact]);
        if ($c === null) { throw new DomainException('Not found.'); }
        if (!(bool) one_value($pdo, 'SELECT crm_email_allowed(:c)', ['c' => $contact])) {
            throw new DomainException($c['name'] . ($c['email_opt_out'] ? ' has opted out of email.' : ' has no email address.'));
        }
        $ctx = merge_context($pdo, $contact, $o['deal_id'] === null ? null : (int) $o['deal_id'], null, $o['activity_id'] === null ? null : (int) $o['activity_id'], $me);
        $subject = merge_render((string) $o['subject'], $ctx)['text'];
        $bodyMd = merge_render((string) $o['body_text'], $ctx)['text'];
        $left = merge_leftovers($subject, $bodyMd);
        if ($left !== []) {
            throw new DomainException('Fill in or take out ' . implode(', ', array_slice($left, 0, 3)) . ' before sending.');
        }
        $sig = trim(my_signature($pdo, $me));
        if ($sig !== '' && !str_contains($bodyMd, $sig)) { $bodyMd .= "\n\n" . $sig; }
        $token = email_unsub_token();
        $messageId = email_message_id_for($token);
        [$footText, $footHtml] = email_footer($pdo, app_url('/u/' . $token));
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#1f2937">' . md_render($bodyMd) . $footHtml . '</div>';
        $mailbox = email_logging_mailbox($pdo);
        $to = (string) $o['to_address'];
        $mail = ['from' => (string) env('MAIL_FROM', 'crm@localhost'), 'from_name' => (string) one_value($pdo, 'SELECT display_name FROM members WHERE id = :m', ['m' => $me]),
            'to' => $to, 'subject' => $subject, 'text' => $bodyMd . $footText, 'html' => $html, 'message_id' => $messageId];
        if ($mailbox !== null) { $mail['reply_to'] = $mailbox['address']; }
        if ($o['in_reply_to'] !== null && $o['in_reply_to'] !== '') {
            $mail['in_reply_to'] = $o['in_reply_to'];
            $mail['references'] = array_values(array_unique(array_merge(pg_text_array((string) $o['refs']), [$o['in_reply_to']])));
        }
        $files = email_payload_files($pdo, $id);
        if ($o['has_ics'] && $o['activity_id'] !== null) {
            $ics = email_invitation($pdo, (int) $o['activity_id'], ['name' => $c['name'], 'address' => $to], (string) ($opts['invite_message'] ?? ''));
            $files[] = ['filename' => 'invite.ics', 'content_type' => 'text/calendar; charset=utf-8; method=REQUEST', 'content_base64' => base64_encode($ics)];
        }
        if ($files !== []) { $mail['attachments'] = $files; }
        $retryable = false;
        try {
            $r = malumail_send($mail);
            [$state, $why] = email_provider_outcome($r);
            $retryable = $state === 'failed' && in_array((int) $r['status'], EMAIL_RETRYABLE_STATUSES, true);
        } catch (RuntimeException $e) {
            $r = ['body' => [], 'message_id' => null];
            $retryable = !str_contains($e->getMessage(), 'not configured');                          // a transport error is worth another try; a missing key is not
            [$state, $why] = ['failed', str_contains($e->getMessage(), 'not configured') ? 'Sending mail is not set up: MaluMail has no key here.' : 'MaluMail could not be reached; send it again in a moment.'];
        }
        if ($retryable && $o['campaign_id'] !== null && (int) $o['attempts'] + 1 < EMAIL_CAMPAIGN_ATTEMPTS) {        // a campaign's message is tried again by the worker: still queued, not before retry_at
            $n = (int) $o['attempts'] + 1;
            $pdo->prepare("UPDATE outbound_email SET attempts = :n, retry_at = now() + make_interval(mins => :m), error = :e WHERE id = :id")
                ->execute(['n' => $n, 'm' => $n * $n, 'e' => mb_substr((string) $why, 0, 300), 'id' => $id]);
            return ['state' => 'retry', 'error' => $why, 'outbound_id' => $id, 'contact_id' => $contact, 'to' => $to, 'name' => (string) $c['name'], 'activity_id' => null];
        }
        $activity = null;
        $pdo->beginTransaction();
        if ($state === 'sent') {
            $pdo->prepare("UPDATE outbound_email SET status = 'sent', sent_at = now(), error = NULL, subject = :s, body_text = :bt, body_html = :bh, message_id = :mid, provider_message_id = :pid WHERE id = :id")
                ->execute(['s' => $subject, 'bt' => $bodyMd, 'bh' => md_render($bodyMd), 'mid' => $messageId, 'pid' => $r['message_id'] ?? null, 'id' => $id]);   // what was written — never the footer: its link holds the token
            $st = $pdo->prepare("INSERT INTO activities (kind, subject, deal_id, contact_id, owner_member_id, created_by, due_at, done_at, email_direction, outbound_email_id, source, is_private)
                                 VALUES ('email', :s, :d, :c, :me, :me, now(), now(), 'out', :o, :src, CAST(:p AS boolean)) RETURNING id");
            $st->execute(['s' => $subject, 'd' => $o['deal_id'], 'c' => $contact, 'me' => $me, 'o' => $id, 'src' => default_activity_kind_source(), 'p' => $o['is_private'] ? 't' : 'f']);
            $activity = (int) $st->fetchColumn();
            if ($o['template_id'] !== null) { $pdo->prepare('UPDATE email_templates SET usage_count = usage_count + 1 WHERE id = :t')->execute(['t' => $o['template_id']]); }
            if ($o['campaign_id'] !== null) {                                                       // a campaign's member who was still planned is now sent (an opened or answered one keeps its status)
                $pdo->prepare("UPDATE campaign_members SET status = 'sent' WHERE campaign_id = :c AND contact_id = :ct AND status = 'planned'")->execute(['c' => $o['campaign_id'], 'ct' => $contact]);
            }
        } else {
            $pdo->prepare('UPDATE outbound_email SET status = :st, error = :e WHERE id = :id')->execute(['st' => $state, 'e' => mb_substr((string) $why, 0, 300), 'id' => $id]);
        }
        log_activity($pdo, 'email.send', 'outbound_email', $id, array_filter(['contact_id' => $contact, 'deal_id' => $o['deal_id']], static fn ($v) => $v !== null)
            + ['after' => ['contact_id' => $contact, 'deal_id' => $o['deal_id'] === null ? null : (int) $o['deal_id'], 'template_id' => $o['template_id'] === null ? null : (int) $o['template_id'], 'status' => $state,
                           'attachments' => count($files) - ($o['has_ics'] ? 1 : 0), 'has_ics' => (bool) $o['has_ics'], 'activity_id' => $activity, 'error' => $state === 'sent' ? null : mb_substr((string) $why, 0, 160)]
                           + ($o['campaign_id'] === null ? [] : ['campaign_id' => (int) $o['campaign_id']])]);
        $pdo->commit();
        return ['state' => $state, 'error' => $why, 'outbound_id' => $id, 'contact_id' => $contact, 'to' => $to, 'name' => (string) $c['name'], 'activity_id' => $activity];
    } finally {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $pdo->prepare('SELECT pg_advisory_unlock(hashtext(:k))')->execute(['k' => 'crm-email-send:' . $id]);
    }
}

/**
 * Render one of the application's own mail bodies (the outbox's: notification, reminder, morning-note — app/views/emails/*.php): answers ['subject', 'text', 'html']
 * from the outbox row's data (title, body, record_type, record_id, plus the kind's own). One source: the words are the row's (crm_notify wrote them); the view only
 * dresses them. Slice 8's `outbox` pass sends what this renders.
 */
function render_mail(string $kind, array $data): array
{
    if (!in_array($kind, ['notification', 'reminder', 'morning-note'], true)) {
        throw new InvalidArgumentException('No such mail body: ' . $kind);
    }
    $data += ['title' => app_name(), 'body' => '', 'record_type' => null, 'record_id' => null];
    $url = notification_record_url(['record_type' => $data['record_type'], 'record_id' => $data['record_id']]);
    $data['url'] = $url === null ? null : app_url($url);
    $data['app'] = app_name();
    return ['subject' => (string) $data['title'], 'text' => trim(view('emails/' . $kind . '.php', $data + ['format' => 'text'])) . "\n", 'html' => view('emails/' . $kind . '.php', $data + ['format' => 'html'])];
}
