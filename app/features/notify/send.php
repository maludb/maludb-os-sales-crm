<?php
declare(strict_types=1);

/**
 * Texting a CONTACT (action `sms_send`; design §5 and D14). The kernel's K6 texts a MEMBER from the business's notification number; a person outside the business is K28, which is
 * not built: until it lands there is no sender for a contact, so the answer is `no_sender` — the action is registered so its approval policy (external_send, paused for an agent)
 * exists on day one, and so that the day K28 arrives only this function changes. Nothing is texted and nothing is stored; the handler logs the refusal without the words.
 */
const SMS_BODY_MAX = 300;

/** The outcome of an attempt to text $contact: ['sent' => bool, 'code' => string|null, 'message' => sentence]. Today always refused with `no_sender`. */
function sms_send(PDO $pdo, int $contact, string $body, int $by): array
{
    return ['sent' => false, 'code' => 'no_sender', 'message' => 'Texting a contact is not available yet: the kernel has no sender for people outside the business (no_sender). Nothing was sent — send an email, or call.'];
}

// ---- the notification outbox (the worker's `outbox` pass; agents-k7-api.md "The worker") -----------------------------------------------------------------------------------------
const OUTBOX_ATTEMPTS = 3;
/** The mail body (render_mail) a notification kind is dressed in. */
const OUTBOX_MAIL_BODY = ['reminder' => 'reminder', 'morning_note' => 'morning-note'];

/**
 * Deliver one queued notification_outbox row: an EMAIL through MaluMail with render_mail() (the kind's body view; the words are the row's), a TEXT through the kernel's K6 to a member. Answers sent | skipped | retried |
 * failed and records it on the row (status, attempts, detail, provider_ref, sent_at) — THREE attempts, a retry after 1 and 4 minutes (send_after). Skipped is a refusal that trying again does not change: MaluMail without a key,
 * an address MaluMail will not take, the member gone, K6's `no_sender` / `no_verified_phone` / `opted_out` (the code is in `detail`, where the person's settings page reads it). Logged outbox.send | outbox.skip | outbox.fail:
 * the row id, the channel, the kind, the member — never the words.
 */
function outbox_notification_deliver(PDO $pdo, array $row, DateTimeImmutable $now): string
{
    $id = (int) $row['id'];
    $fin = static function (string $status, ?string $detail, ?string $ref = null) use ($pdo, $id, $row): string {
        $pdo->prepare('UPDATE notification_outbox SET status = :s, detail = :d, provider_ref = :r, attempts = attempts + 1, sent_at = CASE WHEN :s2 = \'sent\' THEN now() ELSE sent_at END WHERE id = :id')
            ->execute(['s' => $status, 's2' => $status, 'd' => $detail === null ? null : mb_substr($detail, 0, 300), 'r' => $ref, 'id' => $id]);
        log_activity($pdo, $status === 'sent' ? 'outbox.send' : ($status === 'skipped' ? 'outbox.skip' : 'outbox.fail'), 'notification_outbox', $id,
            ['actor_member_id' => null, 'source' => 'cron', 'after' => ['channel' => $row['channel'], 'kind' => $row['kind'], 'member_id' => (int) $row['member_id'], 'attempts' => (int) $row['attempts'] + 1, 'detail' => $detail === null ? null : mb_substr($detail, 0, 120)]]);
        return $status;
    };
    $retry = static function (string $why) use ($pdo, $id, $row, $now, $fin): string {
        $n = (int) $row['attempts'] + 1;
        if ($n >= OUTBOX_ATTEMPTS) {
            return $fin('failed', $why);
        }
        $pdo->prepare('UPDATE notification_outbox SET attempts = :n, detail = :d, send_after = CAST(:at AS timestamptz) + make_interval(mins => :m) WHERE id = :id')
            ->execute(['n' => $n, 'd' => mb_substr($why, 0, 300), 'at' => $now->format(DATE_ATOM), 'm' => $n * $n, 'id' => $id]);
        return 'retried';
    };
    $who = one_row($pdo, 'SELECT status, capability FROM members WHERE id = :m', ['m' => $row['member_id']]);
    if ($who === null || $who['status'] !== 'active' || $who['capability'] === null) {
        return $fin('skipped', 'The person is no longer here.');
    }
    if ($row['channel'] === 'text') {
        [$outcome, $code, $ref] = kernel_send_text((int) $row['member_id'], (string) $row['body'], 'crm-outbox-' . $id);
        return match ($outcome) {
            'sent' => $fin('sent', null, $ref),
            'skipped' => $fin('skipped', 'The kernel did not text: ' . $code),
            'unconfigured' => $fin('skipped', 'unconfigured: this application has no kernel token'),
            default => $retry('The kernel could not text just now: ' . $code),
        };
    }
    if ((string) env('MALUMAIL_API_KEY', '') === '') {
        return $fin('skipped', 'Sending mail is not set up: MaluMail has no key here.');
    }
    if ($row['to_email'] === null || $row['to_email'] === '') {
        return $fin('skipped', 'The person has no email address.');
    }
    try {
        $mail = render_mail(OUTBOX_MAIL_BODY[$row['kind']] ?? 'notification', ['title' => (string) $row['subject'], 'body' => (string) $row['body'], 'record_type' => $row['record_type'], 'record_id' => $row['record_id']]);
        $r = malumail_send(['from' => (string) env('MAIL_FROM', 'crm@localhost'), 'from_name' => (string) env('MAIL_FROM_NAME', app_name()), 'to' => (string) $row['to_email'],
            'subject' => $mail['subject'], 'text' => $mail['text'], 'html' => $mail['html']]);
    } catch (Throwable $e) {
        return $retry('MaluMail could not be reached.');
    }
    [$state, $why] = email_provider_outcome($r);
    if ($state === 'sent') {
        return $fin('sent', null, $r['message_id'] ?? null);
    }
    if ($state === 'suppressed') {
        return $fin('skipped', $why);
    }
    return in_array((int) $r['status'], EMAIL_RETRYABLE_STATUSES, true) ? $retry((string) $why) : $fin('failed', $why);
}
