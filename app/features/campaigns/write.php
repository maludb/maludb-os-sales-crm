<?php
declare(strict_types=1);

/**
 * Campaign writes (campaign_create / campaign_update, the members, campaign_email_send). The database keeps the rules it can (a member is a lead XOR a contact, once per campaign; the dates in order;
 * a budget of 0 or more); PHP checks the rights and the visibility and says why in words. A campaign's EMAIL is queued here and sent by the worker (see campaign_email_send).
 */
require_once __DIR__ . '/queries.php';
require_once dirname(__DIR__) . '/email/write.php';
require_once dirname(__DIR__) . '/templates/queries.php';

const CAMPAIGN_COLUMNS = ['name', 'kind', 'status', 'starts_on', 'ends_on', 'budget', 'currency', 'expected_leads', 'utm_campaign', 'owner_member_id', 'description'];

/** Insert ($id null) or change a campaign. $f holds the columns to write. Answers the id. */
function campaign_save(PDO $pdo, ?int $id, array $f, int $by): int
{
    $f = array_intersect_key($f, array_flip(CAMPAIGN_COLUMNS));
    if ($id === null) {
        $cols = array_keys($f);
        $st = $pdo->prepare('INSERT INTO campaigns (' . implode(', ', $cols) . ', created_by) VALUES (' . implode(', ', array_map(static fn (string $c): string => ':' . $c, $cols)) . ', :by) RETURNING id');
        $st->execute($f + ['by' => $by]);
        return (int) $st->fetchColumn();
    }
    if ($f !== []) {
        $pdo->prepare('UPDATE campaigns SET ' . implode(', ', array_map(static fn (string $c): string => $c . ' = :' . $c, array_keys($f))) . ' WHERE id = :id AND deleted_at IS NULL')->execute($f + ['id' => $id]);
    }
    return $id;
}

/** The stored row's writable columns, for "a field left out stays as it was" and the before/after diff. */
function campaign_current(PDO $pdo, int $id): array
{
    $r = one_row($pdo, 'SELECT ' . implode(', ', CAMPAIGN_COLUMNS) . ' FROM campaigns WHERE id = :id AND deleted_at IS NULL', ['id' => $id]) ?? throw new DomainException('Not found.');
    foreach (['owner_member_id', 'expected_leads'] as $k) { $r[$k] = $r[$k] === null ? null : (int) $r[$k]; }
    return $r;
}

/**
 * Put leads and contacts in a campaign with a status. Only records the caller may SEE are added (the views are the wall); one already a member keeps what it is. Answers
 * ['added' => [member rows' ids], 'already' => n, 'missing' => ids that are not here (or not visible)].
 */
function campaign_member_add(PDO $pdo, int $c, array $leads, array $contacts, string $status): array
{
    $added = [];
    $already = 0;
    $missing = [];
    foreach ([['lead', 'lead_id', 'mcp_leads', $leads], ['contact', 'contact_id', 'mcp_contacts', $contacts]] as [$kind, $col, $view, $ids]) {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) { continue; }
        $vis = $pdo->prepare("SELECT $col FROM $view WHERE $col = ANY (CAST(:ids AS bigint[]))");
        $vis->execute(['ids' => pg_array_literal($ids)]);
        $seen = array_map('intval', $vis->fetchAll(PDO::FETCH_COLUMN));
        $missing = array_merge($missing, array_diff($ids, $seen));
        $ins = $pdo->prepare("INSERT INTO campaign_members (campaign_id, {$col}, status, added_by, responded_at) VALUES (:c, :r, :s, :by, CASE WHEN :s IN ('responded', 'attended', 'converted') THEN now() END)
                              ON CONFLICT DO NOTHING RETURNING id");
        foreach ($seen as $rid) {
            $ins->execute(['c' => $c, 'r' => $rid, 's' => $status, 'by' => (int) current_member_id()]);
            if (($mid = $ins->fetchColumn()) !== false) { $added[] = (int) $mid; } else { $already++; }
        }
    }
    return ['added' => $added, 'already' => $already, 'missing' => array_values($missing)];
}

/** The member row of a lead or a contact in a campaign (id, status), or null. */
function campaign_member_row(PDO $pdo, int $c, ?int $lead, ?int $contact): ?array
{
    return one_row($pdo, 'SELECT id, status, lead_id, contact_id FROM campaign_members WHERE campaign_id = :c AND ((CAST(:l AS bigint) IS NOT NULL AND lead_id = :l) OR (CAST(:k AS bigint) IS NOT NULL AND contact_id = :k))',
        ['c' => $c, 'l' => $lead, 'k' => $contact]);
}

/** Change a member's status (a response stamps responded_at once). Answers the previous status. */
function campaign_member_status(PDO $pdo, int $c, ?int $lead, ?int $contact, string $status): string
{
    $m = campaign_member_row($pdo, $c, $lead, $contact) ?? throw new DomainException('That is not a member of this campaign.');
    $pdo->prepare("UPDATE campaign_members SET status = :s, responded_at = CASE WHEN :s IN ('responded', 'attended', 'converted') THEN COALESCE(responded_at, now()) ELSE responded_at END WHERE id = :id")
        ->execute(['s' => $status, 'id' => $m['id']]);
    return (string) $m['status'];
}

/** Take a lead or a contact out of a campaign. Answers whether it was a member. */
function campaign_member_remove(PDO $pdo, int $c, ?int $lead, ?int $contact): bool
{
    $m = campaign_member_row($pdo, $c, $lead, $contact);
    if ($m === null) { return false; }
    $pdo->prepare('DELETE FROM campaign_members WHERE id = :id')->execute(['id' => $m['id']]);
    return true;
}

/** What the last campaign_email_send did, by count (the handler logs and reports it): queued and each kind of skip. */
function campaign_email_last(?array $set = null): array
{
    static $last = ['queued' => 0, 'skipped_opted_out' => 0, 'skipped_no_address' => 0, 'skipped_unfilled' => 0, 'skipped_hidden' => 0, 'skipped_leads' => 0, 'skipped_already_queued' => 0, 'members' => 0];
    if ($set !== null) { $last = $set; }
    return $last;
}

/**
 * QUEUE a campaign's email (campaign_email_send): one outbound_email row per allowed contact among the members — `planned` (the default) or all but the unsubscribed — status `queued`, the
 * campaign's id on it, the template's subject and body filled for THAT contact through the merge functions of slice 5, the caller the sender. NOTHING IS SENT here: the worker's `outbox` pass
 * sends the rows (email_deliver, so each gets the footer, the unsubscribe link and a Message-ID of its own). A contact who has opted out, has no address, is behind the wall, has a field the template
 * cannot fill, or already has a message of this campaign waiting is skipped and counted; a lead is not emailed from here. Run it inside the caller's transaction. Answers how many were queued;
 * the breakdown is campaign_email_last(). A template that uses a field a campaign cannot fill ({{deal.title}}, a meeting's) or none of ours is refused by name, before anything is queued.
 */
function campaign_email_send(PDO $pdo, int $c, int $template, string $members, int $by): int
{
    $camp = find_campaign($pdo, $c) ?? throw new DomainException('Not found.');
    if (in_array($camp['status'], ['cancelled', 'done'], true)) { throw new DomainException('A ' . $camp['status'] . ' campaign is not emailed; make it active first.'); }
    $t = find_email_template($pdo, $template);
    if ($t === null || $t['archived_at'] !== null) { throw new DomainException('That template is not here.'); }
    $bad = array_values(array_filter(merge_fields_in((string) $t['subject'], (string) $t['body']), static fn (string $f): bool => !array_key_exists($f, MERGE_FIELDS) || str_starts_with($f, 'deal.') || str_starts_with($f, 'activity.')));
    if ($bad !== []) {
        throw new DomainException('A campaign has no deal or meeting to fill ' . implode(', ', array_map(static fn (string $f): string => '{{' . $f . '}}', array_slice($bad, 0, 3))) . '; take it out of the template.');
    }
    $all = $members === 'all';
    $r = ['queued' => 0, 'skipped_opted_out' => 0, 'skipped_no_address' => 0, 'skipped_unfilled' => 0, 'skipped_hidden' => 0, 'skipped_leads' => 0, 'skipped_already_queued' => 0, 'members' => 0];
    $r['skipped_leads'] = (int) one_value($pdo, "SELECT count(*) FROM campaign_members WHERE campaign_id = :c AND lead_id IS NOT NULL AND (:all OR status = 'planned')", ['c' => $c, 'all' => $all ? 't' : 'f']);
    $st = $pdo->prepare("SELECT cm.contact_id FROM campaign_members cm WHERE cm.campaign_id = :c AND cm.contact_id IS NOT NULL AND (CASE WHEN :all THEN cm.status <> 'unsubscribed' ELSE cm.status = 'planned' END)
                          ORDER BY cm.id");
    $st->bindValue('c', $c, PDO::PARAM_INT);
    $st->bindValue('all', $all, PDO::PARAM_BOOL);
    $st->execute();
    $ins = $pdo->prepare("INSERT INTO outbound_email (to_address, subject, body_text, status, sender_member_id, contact_id, template_id, campaign_id) VALUES (:to, :s, :b, 'queued', :me, :c, :t, :camp)");
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        $cid = (int) $cid;
        $r['members']++;
        $rec = email_recipient($pdo, $cid);
        if ($rec === null) { $r['skipped_hidden']++; continue; }
        if (!$rec['allowed']) { $r[$rec['opted_out'] ? 'skipped_opted_out' : 'skipped_no_address']++; continue; }
        if (one_value($pdo, "SELECT 1 FROM outbound_email WHERE campaign_id = :camp AND contact_id = :c AND status = 'queued'", ['camp' => $c, 'c' => $cid]) !== null) { $r['skipped_already_queued']++; continue; }
        $ctx = merge_context($pdo, $cid, null, null, null, $by);
        $subject = merge_render((string) $t['subject'], $ctx)['text'];
        $body = merge_render((string) $t['body'], $ctx)['text'];
        if (merge_leftovers($subject, $body) !== []) { $r['skipped_unfilled']++; continue; }
        $ins->execute(['to' => $rec['address'], 's' => $subject, 'b' => $body, 'me' => $by, 'c' => $cid, 't' => $template, 'camp' => $c]);
        $r['queued']++;
    }
    campaign_email_last($r);
    return $r['queued'];
}
