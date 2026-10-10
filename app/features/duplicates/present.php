<?php
declare(strict_types=1);

function present_duplicate_pair(string $kind, array $p): array
{
    $side = static fn (array $r, int $id): array => ['id' => $id, 'name' => duplicate_name($kind, $r), 'email' => $r['email'] ?? null, 'domain' => $r['domain'] ?? null, 'owner_name' => $r['owner_name'] ?? null, 'created_at' => json_ts($r['created_at'] ?? null)];
    return ['kind' => $kind, 'matched_on' => $p['matched_on'], 'similarity' => $p['similarity'], 'a' => $side($p['a'], $p['a_id']), 'b' => $side($p['b'], $p['b_id']),
            'merge_url' => '/duplicates/merge?' . http_build_query(['kind' => $kind, 'a' => $p['a_id'], 'b' => $p['b_id']])];
}
