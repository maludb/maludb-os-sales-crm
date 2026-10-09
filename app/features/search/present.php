<?php
declare(strict_types=1);

/** The JSON shape of a search result (whitelisted; the headline as plain text, the highlight markers removed). */
function present_search_row(array $r): array
{
    return ['entity_kind' => $r['entity_kind'], 'entity_id' => (int) $r['entity_id'], 'title' => $r['title'],
            'headline' => strip_tags((string) $r['headline']), 'status' => $r['status'], 'account_id' => $r['account_id'] !== null ? (int) $r['account_id'] : null,
            'owner_member_id' => $r['owner_member_id'] !== null ? (int) $r['owner_member_id'] : null, 'rank' => round((float) $r['rank'], 4), 'url' => search_result_url($r)];
}
