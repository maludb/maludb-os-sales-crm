<?php
declare(strict_types=1);

/**
 * Notices an agent or a manager sends (agents-k7-api.md; action `notification`): one bell row for a person, and the outbox rows their preferences ask for (email, a text) —
 * crm_notify() (db/013) does both; the worker's `outbox` pass delivers. WHO MAY: `activities.write`, and an AGENT sends to a seller, a MANAGER (deals.any) to anyone here; a
 * Rep sends only to themselves. The recipient is a PERSON admitted here (an agent has no bell). The links (a deal, a contact, an activity) must be records the SENDER may see — a
 * notice cannot be used to point at what is behind the wall. A body is Markdown, kept in the bell and the outbox and NEVER in the activity log.
 */
const NOTIFY_KINDS = ['morning_note' => 'Morning note', 'proposal' => 'Proposal', 'mention' => 'Mention', 'digest' => 'Digest'];

/** Why the current member may not send a notice to this member: [status, sentence], or null when they may. Reads the database's rights for the caller (app.member_id). */
function notify_authorize(PDO $pdo, int $recipient): ?array
{
    if (!has_right('activities.write')) {
        return [403, 'You may not ' . RIGHT_WORDS['activities.write'] . ', so you may not send notices.'];
    }
    $to = one_row($pdo, "SELECT id, member_kind, status, capability FROM members WHERE id = :id", ['id' => $recipient]);
    if ($to === null || $to['status'] !== 'active' || $to['capability'] === null || $to['member_kind'] !== 'human') {
        return [404, 'That person is not here.'];
    }
    $me = (int) current_member_id();
    $isAgent = (current_member()['member_kind'] ?? '') === 'agent';
    if ($me === $recipient || is_manager()) {
        return null;
    }
    if ($isAgent) {
        $seller = (bool) one_value($pdo, "SELECT crm_member_roles(:m) && ARRAY['user', 'manager', 'admin']", ['m' => $recipient]);
        return $seller ? null : [403, 'An agent sends notices to sellers and managers, and that person holds neither role.'];
    }
    return [403, 'Only a manager or an agent sends a notice to someone else.'];
}

/**
 * Send one notice: the bell row and the outbox rows (crm_notify). $links: deal, contact, activity (ids). Answers the notification id. A morning note is once a day per person
 * (the outbox's dedupe key) — a second one is a DomainException saying so. Raises DomainException in words for a bad kind, title, body or link.
 */
function notify(PDO $pdo, int $member, string $kind, string $title, ?string $body, array $links): int
{
    if (!isset(NOTIFY_KINDS[$kind])) {
        throw new DomainException('A notice is one of: ' . implode(', ', array_keys(NOTIFY_KINDS)) . '.');
    }
    $title = trim($title);
    if ($title === '' || mb_strlen($title) > 200) {
        throw new DomainException('A notice needs a title of up to 200 characters.');
    }
    if ($body !== null && mb_strlen($body) > 4000) {
        throw new DomainException('A notice body is up to 4000 characters.');
    }
    $ids = ['deal' => null, 'contact' => null, 'activity' => null];
    foreach (['deal' => 'mcp_deals', 'contact' => 'mcp_contacts', 'activity' => 'mcp_activities'] as $k => $view) {
        if (($links[$k] ?? null) === null) { continue; }
        $id = (int) $links[$k];
        if (one_value($pdo, "SELECT 1 FROM {$view} WHERE {$k}_id = :id", ['id' => $id]) === null) {
            throw new DomainException('Not found.');
        }
        $ids[$k] = $id;
    }
    [$type, $rid] = $ids['activity'] !== null ? ['activity', $ids['activity']] : ($ids['deal'] !== null ? ['deal', $ids['deal']] : ($ids['contact'] !== null ? ['contact', $ids['contact']] : [null, null]));
    $dedupe = null;
    if ($kind === 'morning_note') {
        $tz = (string) (one_value($pdo, 'SELECT timezone FROM members WHERE id = :m', ['m' => $member]) ?: 'UTC');
        try { $day = (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('Y-m-d'); } catch (Exception) { $day = gmdate('Y-m-d'); }
        $dedupe = 'morning:' . $member . ':' . $day;
    }
    $st = $pdo->prepare('SELECT crm_notify(:m, :k, :t, :b, :rt, :ri, :d, :c, :a, :actor, :dedupe)');
    $st->execute(['m' => $member, 'k' => $kind, 't' => $title, 'b' => $body, 'rt' => $type, 'ri' => $rid, 'd' => $ids['deal'], 'c' => $ids['contact'], 'a' => $ids['activity'], 'actor' => current_member_id(), 'dedupe' => $dedupe]);
    $nid = $st->fetchColumn();
    if ($nid === false || $nid === null) {
        throw new DomainException('A morning note was already sent to ' . (string) one_value($pdo, 'SELECT display_name FROM members WHERE id = :m', ['m' => $member]) . ' today.');
    }
    return (int) $nid;
}
