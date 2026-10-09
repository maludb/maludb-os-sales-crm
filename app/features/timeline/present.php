<?php
declare(strict_types=1);

/** A History row in words: [icon, sentence, url|null]. A change row reads the action through activity_sentence(); a note shows its first words. */
function timeline_line(array $t): array
{
    $who = (string) ($t['actor_name'] ?? 'Sales CRM') . (!empty($t['actor_is_agent']) ? ' (agent)' : '');
    $kind = (string) $t['item_kind'];
    if (str_starts_with($kind, 'activity.')) {
        $k = substr($kind, 9);
        return [activity_kind_icon($k), ucfirst($k) . ': ' . $t['title'] . ($t['detail'] ? ' — ' . $t['detail'] : ''), '/activities/' . (int) $t['item_id']];
    }
    return match ($kind) {
        'note' => ['feather-file-text', $who . ' noted: ' . mb_substr((string) $t['title'], 0, 140), null],
        'stage' => ['feather-columns', $who . ' moved it to ' . $t['title'] . ($t['detail'] ? ' from ' . $t['detail'] : ''), null],
        'change' => ['feather-edit-2', activity_sentence(['actor_name' => $t['actor_name'], 'actor_is_agent' => $t['actor_is_agent'], 'action' => $t['title'], 'after' => $t['detail'], 'screen' => null]), null],
        default => ['feather-circle', (string) $t['title'], null],
    };
}

function present_timeline_row(array $t): array
{
    [, $sentence, $url] = timeline_line($t);
    return ['at' => json_ts($t['at']), 'item_kind' => $t['item_kind'], 'item_id' => (int) $t['item_id'], 'title' => $t['title'], 'sentence' => $sentence,
            'actor_member_id' => $t['actor_member_id'] !== null ? (int) $t['actor_member_id'] : null, 'deal_id' => $t['deal_id'] !== null ? (int) $t['deal_id'] : null,
            'contact_id' => $t['contact_id'] !== null ? (int) $t['contact_id'] : null, 'url' => $url];
}
