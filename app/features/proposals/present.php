<?php
declare(strict_types=1);

/**
 * How a proposal is shown. proposal_render() turns a find_proposals row into what the card and the JSON need — the kind's icon and words, the record it is about (a link only when the viewer may open it),
 * and the payload said in plain lines in the viewer's time zone: a next step's kind, moment and subject; a qualification's score and reasons; a duplicate's two sides; a draft's subject. present_proposal() is
 * the whitelist for JSON. The payload is stored as the agent wrote it; only its known keys are read, and they are printed escaped.
 */
require_once dirname(__DIR__) . '/activities/queries.php';
require_once dirname(__DIR__) . '/records/queries.php';
require_once dirname(__DIR__) . '/duplicates/queries.php';

function proposal_payload(array $p): array
{
    $v = json_decode((string) ($p['payload'] ?? '{}'), true);
    return is_array($v) ? $v : [];
}

/** The record a proposal is about: ['label', 'url'] for the deal, else the lead, contact or account — a link only when the viewer's wall lets them open it (the join was through the walled views). */
function proposal_record(array $p): ?array
{
    if ($p['deal_id'] !== null && ($p['deal_number'] ?? null) !== null) { return ['label' => $p['deal_number'] . ' · ' . $p['deal_title'], 'url' => '/deals/' . (int) $p['deal_id'], 'kind' => 'deal']; }
    if ($p['lead_id'] !== null && ($p['lead_label'] ?? null) !== null) { return ['label' => (string) $p['lead_label'], 'url' => '/leads/' . (int) $p['lead_id'], 'kind' => 'lead']; }
    if ($p['contact_id'] !== null && ($p['contact_name'] ?? null) !== null) { return ['label' => (string) $p['contact_name'], 'url' => '/contacts/' . (int) $p['contact_id'], 'kind' => 'contact']; }
    if ($p['account_id'] !== null && ($p['account_name'] ?? null) !== null) { return ['label' => (string) $p['account_name'], 'url' => '/accounts/' . (int) $p['account_id'], 'kind' => 'account']; }
    return null;
}

/** A duplicate proposal's two sides: [{kind, id, label, url}] — a side the viewer cannot see is shown as "a record you cannot open". */
function proposal_duplicate_sides(PDO $pdo, array $p, array $pay): array
{
    $kind = in_array($pay['kind'] ?? null, ['contact', 'account', 'lead'], true) ? $pay['kind'] : null;
    if ($kind === null) { return []; }
    $a = (int) ($p[$kind . '_id'] ?? 0);
    $b = (int) ($pay['other_id'] ?? 0);
    $sides = [];
    foreach ([$a, $b] as $id) {
        $r = $id > 0 ? duplicate_record($pdo, $kind, $id) : null;
        $sides[] = ['kind' => $kind, 'id' => $id, 'label' => $r === null ? 'a record you cannot open' : duplicate_name($kind, $r), 'url' => $r === null ? null : RECORD_KINDS[$kind]['url'] . $id];
    }
    return $sides;
}

function proposal_render(array $p): array
{
    $pdo = db();
    $tz = member_timezone();
    $pay = proposal_payload($p);
    [$kindWords, $icon] = PROPOSAL_KINDS[$p['kind']] ?? [$p['kind'], 'feather-zap'];
    [$statusWords, $tone] = PROPOSAL_STATUS[$p['status']] ?? [$p['status'], 'secondary'];
    $lines = [];
    $extra = [];
    switch ($p['kind']) {
        case 'next_step':
            $kind = (string) ($pay['kind'] ?? 'task');
            $at = isset($pay['at']) ? format_ts((string) $pay['at'], $tz, 'D M j, g:i A') : '';
            $lines[] = ucfirst($kind) . ($at !== '' ? ' · ' . $at : '') . ' · ' . (string) ($pay['subject'] ?? 'Next step');
            if (!empty($pay['body'])) { $lines[] = mb_substr((string) $pay['body'], 0, 240); }
            $extra = ['step_kind' => $kind, 'step_at' => $pay['at'] ?? null, 'step_subject' => (string) ($pay['subject'] ?? '')];
            break;
        case 'lead_qualify':
            $lines[] = 'Score ' . (int) ($pay['score'] ?? 0) . ' of 100';
            foreach (array_slice((array) ($pay['reasons'] ?? []), 0, 8) as $r) { $lines[] = '• ' . mb_substr((string) $r, 0, 200); }
            if (!empty($pay['owner_member_id'])) { $lines[] = 'Suggested owner: ' . (string) (member_name($pdo, (int) $pay['owner_member_id']) ?? 'someone'); }
            $extra = ['score' => (int) ($pay['score'] ?? 0), 'reasons' => array_map(static fn ($r): string => mb_substr((string) $r, 0, 200), array_slice((array) ($pay['reasons'] ?? []), 0, 8))];
            break;
        case 'duplicate':
            $sides = proposal_duplicate_sides($pdo, $p, $pay);
            $lines[] = 'Matched on ' . (string) ($pay['matched_on'] ?? 'a likeness');
            $extra = ['sides' => $sides, 'record_kind' => $pay['kind'] ?? null, 'matched_on' => $pay['matched_on'] ?? null];
            break;
        case 'email_draft':
            $lines[] = 'Subject: ' . mb_substr((string) ($pay['subject'] ?? ''), 0, 200);
            break;
        case 'rotting':
            $lines[] = 'In ' . (string) ($pay['stage'] ?? 'its stage') . ' for ' . (int) ($pay['days'] ?? 0) . ' days';
            break;
        case 'close_date':
            $lines[] = 'Close date ' . format_date(isset($pay['date']) ? (string) $pay['date'] : null);
            break;
        default:
            break;
    }
    $decided = $p['status'] === 'proposed' ? null : $statusWords . ($p['decided_by_name'] ?? null ? ' by ' . $p['decided_by_name'] : '') . ($p['decided_at'] ? ' · ' . format_ts((string) $p['decided_at'], $tz, 'M j, g:i A') : '');
    return ['id' => (int) $p['proposal_id'], 'kind' => $p['kind'], 'kind_label' => $kindWords, 'icon' => $icon, 'title' => (string) $p['title'], 'status' => $p['status'], 'status_label' => $statusWords, 'tone' => $tone,
            'for_member_id' => $p['for_member_id'] === null ? null : (int) $p['for_member_id'], 'for_name' => $p['for_name'] ?? null, 'proposed_by_name' => $p['proposed_by_name'] ?? null,
            'record' => proposal_record($p), 'lines' => $lines, 'extra' => $extra, 'decided' => $decided, 'result_activity_id' => $p['result_activity_id'] === null ? null : (int) $p['result_activity_id'],
            'created_at' => (string) $p['created_at']];
}

/** The JSON whitelist of a rendered proposal. */
function present_proposal(array $r): array
{
    return ['proposal_id' => $r['id'], 'kind' => $r['kind'], 'kind_label' => $r['kind_label'], 'title' => $r['title'], 'status' => $r['status'], 'status_label' => $r['status_label'], 'for_member_id' => $r['for_member_id'],
            'for_name' => $r['for_name'], 'proposed_by_name' => $r['proposed_by_name'], 'record' => $r['record'] === null ? null : ['kind' => $r['record']['kind'], 'label' => $r['record']['label'], 'url' => $r['record']['url']],
            'summary' => $r['lines'], 'detail' => $r['extra'], 'decided' => $r['decided'], 'result_activity_id' => $r['result_activity_id'], 'created_at' => json_ts($r['created_at'])];
}
