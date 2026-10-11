<?php
declare(strict_types=1);

/**
 * Lists (screens `list-list`, `list-view`, `list-add`, `list-edit`; the records server's find_lists / list_members / segment_preview read the same views). A STATIC list is a set of
 * records someone chose (list_members; its count is a trigger's); a SMART list is a filter over the find_* vocabulary (filters.php) and has no members of its own — its members are whatever
 * the filter matches when it is read. Either way the records come through the kind's walled view, so a list never shows a record the reader may not see. mcp_lists shows the lists that are
 * shared, the caller's own, and — to a manager — everyone's.
 */
require_once __DIR__ . '/filters.php';

const LIST_KINDS = ['static' => 'Static', 'smart' => 'Smart'];
const LIST_RECORD_KINDS = ['lead' => 'Leads', 'contact' => 'Contacts', 'account' => 'Accounts', 'deal' => 'Deals'];

/** The lists the caller can see: record_kind, kind (static|smart), q; mine first, then the shared ones, by name. [rows] each with the owner's name. */
function find_lists(PDO $pdo, array $f = []): array
{
    $w = ['true'];
    $a = [];
    if (isset($f['record_kind']) && isset(LIST_RECORD_KINDS[$f['record_kind']])) { $w[] = 'l.record_kind = :rk'; $a['rk'] = $f['record_kind']; }
    if (isset($f['kind']) && isset(LIST_KINDS[$f['kind']])) { $w[] = 'l.kind = :k'; $a['k'] = $f['kind']; }
    if (($f['q'] ?? '') !== '') { $w[] = "l.name ILIKE '%' || :q || '%'"; $a['q'] = mb_substr((string) $f['q'], 0, 120); }
    $st = $pdo->prepare('SELECT l.list_id, l.name, l.kind, l.record_kind, l.filters, l.owner_member_id, m.display_name AS owner_name, l.is_shared, l.member_count, l.counted_at, l.description, l.created_at
                           FROM mcp_lists l LEFT JOIN members m ON m.id = l.owner_member_id WHERE ' . implode(' AND ', $w)
                       . ' ORDER BY (l.owner_member_id = app_current_member_id()) DESC NULLS LAST, l.is_shared DESC, lower(l.name), l.list_id');
    $st->execute($a);
    return $st->fetchAll();
}

/** One list the caller may see (null otherwise — the same 404 as a missing one), the filters decoded. */
function find_list(PDO $pdo, int $id): ?array
{
    $r = one_row($pdo, 'SELECT l.list_id, l.name, l.kind, l.record_kind, l.filters, l.owner_member_id, m.display_name AS owner_name, l.is_shared, l.member_count, l.counted_at, l.description, l.created_at
                          FROM mcp_lists l LEFT JOIN members m ON m.id = l.owner_member_id WHERE l.list_id = :id', ['id' => $id]);
    if ($r === null) { return null; }
    $r['filters'] = filters_decode($r['filters']);
    return $r;
}

/** What the caller may do with a list: change or delete it — its owner, or the holder of lists.shared (a shared list is the marketing team's). */
function list_may(?array $l): array
{
    $own = $l !== null && $l['owner_member_id'] !== null && (int) $l['owner_member_id'] === (int) current_member_id();
    $shared = has_right('lists.shared');
    return ['edit' => $l === null ? has_right('lists.own') : ($own || $shared), 'share' => $shared, 'add' => $l !== null && $l['kind'] === 'static' && ($own || $shared)];
}

/**
 * One page of a list's members as the kind's own find_* rows: a static list's members (the walled view; a member the reader may not see is not there), a smart list's filter run now.
 * ['rows', 'total'].
 */
function list_rows(PDO $pdo, int $id, int $page = 1): array
{
    $l = find_list($pdo, $id);
    if ($l === null) { return ['rows' => [], 'total' => 0]; }
    return $l['kind'] === 'static'
        ? filters_find($pdo, $l['record_kind'], [], $page, ['in_list' => $id])
        : filters_find($pdo, $l['record_kind'], $l['filters'], $page);
}

/** A smart list's live count (what its filter matches for the reader now); a static list's is its member count as the reader's wall leaves it. */
function list_live_count(PDO $pdo, array $l): int
{
    return $l['kind'] === 'static'
        ? filters_find($pdo, $l['record_kind'], [], 1, ['in_list' => (int) $l['list_id']])['total']
        : filters_find($pdo, $l['record_kind'], $l['filters'], 1)['total'];
}

function list_label(PDO $pdo, int $id): ?string
{
    $v = one_value($pdo, 'SELECT name FROM mcp_lists WHERE list_id = :id', ['id' => $id]);
    return $v === null || $v === false ? null : (string) $v;
}

/** Whether a record of a kind is in a static list (the cards' "in a list" marks). */
function list_has_member(PDO $pdo, int $list, int $record): bool
{
    return one_value($pdo, 'SELECT 1 FROM list_members WHERE list_id = :l AND record_id = :r', ['l' => $list, 'r' => $record]) !== null;
}
