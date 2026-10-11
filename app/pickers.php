<?php
declare(strict_types=1);

/**
 * app/pickers.php — the registry of what a form can pick (app/picker.php has the contract; design-system record-picker.md).
 * One entry per record kind, added by the slice whose form chooses it:
 *   key => ['title', 'noun', 'roles' => [Sales CRM rights, any one admits — has_right()], 'params' => [filter keys the endpoint forwards, as integers],
 *           'search' => fn(PDO, string $q, array $params, int $page): array{rows, total, page, page_size, pages},   rows: [id, label, detail?, badge?, color?]
 *           'label'  => fn(PDO, int $id): ?string]
 * `search` reuses the feature's own query (accounts_for_pick …), never a second one; `label` names the chosen record. Every source reads through a
 * view, so a picker never offers a record behind the wall.
 *
 * Slice 1: account (a contact's company, a parent account — `except` leaves the account being edited out), contact (`account` narrows to its
 * people), owner (people and agents holding a role here), tag. Slice 2: lead (open, not proposed), source, campaign (slice 6 builds its screens;
 * the picker reads mcp_campaigns), list (static lists of a `record_kind` — the import's target), import_template (of a `kind`, the caller's or shared).
 * Slice 3: deal (`account` narrows to its deals), pipeline, stage (`pipeline` required — none named, none offered; Won and Lost are their forms'), product
 * (a sibling's catalog over K7 when a super-admin connected one — ids "inventory:<id>" / "gl:<id>"; none connected, no rows: the line is written by name).
 * Slice 4: activity (`deal` narrows to its activities), member (colleagues — the people, not agents: an activity's participants).
 * Slice 5: template (the email templates the caller may use — shared and their own, not archived).
 * A short enumeration (a kind, a role, a lifecycle, a size band) stays a <select>.
 */
require_once __DIR__ . '/features/accounts/queries.php';
require_once __DIR__ . '/features/contacts/queries.php';
require_once __DIR__ . '/features/tags/queries.php';
require_once __DIR__ . '/features/leads/queries.php';
require_once __DIR__ . '/features/deals/queries.php';
require_once __DIR__ . '/features/activities/queries.php';
require_once __DIR__ . '/features/templates/queries.php';

/** A small picker over one view: rows {id, label, detail?} by a name ILIKE, paged — for the short vocabularies (sources, campaigns, lists, templates). */
function crm_simple_pick(PDO $pdo, string $sql, string $q, int $page, callable $row, array $args = []): array
{
    $size = 20;
    $st = $pdo->prepare($sql . ' LIMIT :l OFFSET :o');
    foreach ($args + ['q' => $q] as $k => $v) { $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : (is_null($v) ? PDO::PARAM_NULL : PDO::PARAM_STR)); }
    $st->bindValue('l', $size, PDO::PARAM_INT);
    $st->bindValue('o', (max(1, $page) - 1) * $size, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();
    $total = $rows === [] ? 0 : (int) $rows[0]['total'];
    return ['rows' => array_map($row, $rows), 'total' => $total, 'page' => max(1, $page), 'page_size' => $size, 'pages' => max(1, (int) ceil($total / $size))];
}

return [
    'account' => [
        'title' => 'Accounts', 'noun' => 'account', 'roles' => ['records.read'], 'params' => ['except'],
        'search' => static fn (PDO $pdo, string $q, array $p, int $page): array => accounts_for_pick($pdo, $q, array_filter($p, static fn ($v) => $v !== null), $page),
        'label' => static fn (PDO $pdo, int $id): ?string => account_label($pdo, $id),
    ],
    'contact' => [
        'title' => 'Contacts', 'noun' => 'contact', 'roles' => ['records.read'], 'params' => ['account'],
        'search' => static fn (PDO $pdo, string $q, array $p, int $page): array => contacts_for_pick($pdo, $q, array_filter($p, static fn ($v) => $v !== null), $page),
        'label' => static fn (PDO $pdo, int $id): ?string => contact_label($pdo, $id),
    ],
    'owner' => [
        'title' => 'People and agents', 'noun' => 'owner', 'roles' => ['records.read'], 'params' => [],
        'search' => static fn (PDO $pdo, string $q, array $p, int $page): array => members_for_pick($pdo, $q, $p, $page),
        'label' => static fn (PDO $pdo, int $id): ?string => (($v = one_value($pdo, 'SELECT display_name FROM mcp_members WHERE member_id = :id', ['id' => $id])) === null || $v === false) ? null : (string) $v,
    ],
    'tag' => [
        'title' => 'Tags', 'noun' => 'tag', 'roles' => ['tags.apply'], 'params' => [],
        'search' => static function (PDO $pdo, string $q, array $p, int $page): array {
            $size = 20;
            $rows = find_tags($pdo, $q, $size, (max(1, $page) - 1) * $size);
            $total = $rows === [] ? 0 : (int) $rows[0]['total'];
            return ['rows' => array_map(static fn (array $r): array => ['id' => (int) $r['tag_id'], 'label' => $r['label'], 'detail' => (int) $r['usage_count'] . ' use' . ((int) $r['usage_count'] === 1 ? '' : 's')], $rows),
                'total' => $total, 'page' => max(1, $page), 'page_size' => $size, 'pages' => max(1, (int) ceil($total / $size))];
        },
        'label' => static fn (PDO $pdo, int $id): ?string => (($v = one_value($pdo, 'SELECT label FROM mcp_tags WHERE tag_id = :id', ['id' => $id])) === null || $v === false) ? null : (string) $v,
    ],
    'lead' => [
        'title' => 'Leads', 'noun' => 'lead', 'roles' => ['records.read'], 'params' => [],
        'search' => static fn (PDO $pdo, string $q, array $p, int $page): array => leads_for_pick($pdo, $q, $p, $page),
        'label' => static fn (PDO $pdo, int $id): ?string => lead_label($pdo, $id),
    ],
    'source' => [
        'title' => 'Lead sources', 'noun' => 'source', 'roles' => ['records.read'], 'params' => [],
        'search' => static fn (PDO $pdo, string $q, array $p, int $page): array => crm_simple_pick($pdo, "SELECT source_id, name, kind, count(*) OVER () AS total FROM mcp_lead_sources WHERE active AND (:q = '' OR name ILIKE '%' || :q || '%') ORDER BY sort_order, name", $q, $page,
            static fn (array $r): array => ['id' => (int) $r['source_id'], 'label' => $r['name'], 'detail' => $r['kind']]),
        'label' => static fn (PDO $pdo, int $id): ?string => (($v = one_value($pdo, 'SELECT name FROM mcp_lead_sources WHERE source_id = :id', ['id' => $id])) === null || $v === false) ? null : (string) $v,
    ],
    'campaign' => [
        'title' => 'Campaigns', 'noun' => 'campaign', 'roles' => ['records.read'], 'params' => [],
        'search' => static fn (PDO $pdo, string $q, array $p, int $page): array => crm_simple_pick($pdo, "SELECT campaign_id, name, status, count(*) OVER () AS total FROM mcp_campaigns WHERE (:q = '' OR name ILIKE '%' || :q || '%') ORDER BY (status = 'active') DESC, created_at DESC", $q, $page,
            static fn (array $r): array => ['id' => (int) $r['campaign_id'], 'label' => $r['name'], 'badge' => $r['status'], 'color' => $r['status'] === 'active' ? 'success' : 'secondary']),
        'label' => static fn (PDO $pdo, int $id): ?string => (($v = one_value($pdo, 'SELECT name FROM mcp_campaigns WHERE campaign_id = :id', ['id' => $id])) === null || $v === false) ? null : (string) $v,
    ],
    'list' => [
        'title' => 'Static lists', 'noun' => 'list', 'roles' => ['lists.own'], 'params' => [],
        'search' => static function (PDO $pdo, string $q, array $p, int $page): array {
            $kind = in_array($_GET['record_kind'] ?? '', ['lead', 'contact', 'account', 'deal'], true) ? $_GET['record_kind'] : null;
            return crm_simple_pick($pdo, "SELECT list_id, name, record_kind, member_count, count(*) OVER () AS total FROM mcp_lists WHERE kind = 'static' AND (CAST(:k AS text) IS NULL OR record_kind = CAST(:k AS text)) AND (:q = '' OR name ILIKE '%' || :q || '%') ORDER BY name", $q, $page,
                static fn (array $r): array => ['id' => (int) $r['list_id'], 'label' => $r['name'], 'detail' => $r['record_kind'] . 's · ' . (int) $r['member_count']], ['k' => $kind]);
        },
        'label' => static fn (PDO $pdo, int $id): ?string => (($v = one_value($pdo, 'SELECT name FROM mcp_lists WHERE list_id = :id', ['id' => $id])) === null || $v === false) ? null : (string) $v,
    ],
    'import_template' => [
        'title' => 'Saved mappings', 'noun' => 'mapping', 'roles' => ['imports.own'], 'params' => [],
        'search' => static function (PDO $pdo, string $q, array $p, int $page): array {
            $kind = in_array($_GET['kind'] ?? '', ['leads_csv', 'contacts_csv', 'accounts_csv', 'deals_csv'], true) ? $_GET['kind'] : null;
            return crm_simple_pick($pdo, "SELECT id, name, kind, is_shared, count(*) OVER () AS total FROM import_templates WHERE (is_shared OR owner_member_id = app_current_member_id()) AND (CAST(:k AS text) IS NULL OR kind = CAST(:k AS text)) AND (:q = '' OR name ILIKE '%' || :q || '%') ORDER BY name", $q, $page,
                static fn (array $r): array => ['id' => (int) $r['id'], 'label' => $r['name'], 'detail' => str_replace('_csv', '', $r['kind']), 'badge' => $r['is_shared'] ? 'shared' : null], ['k' => $kind]);
        },
        'label' => static fn (PDO $pdo, int $id): ?string => (($v = one_value($pdo, 'SELECT name FROM import_templates WHERE id = :id AND (is_shared OR owner_member_id = app_current_member_id())', ['id' => $id])) === null || $v === false) ? null : (string) $v,
    ],
    'deal' => [
        'title' => 'Deals', 'noun' => 'deal', 'roles' => ['records.read'], 'params' => ['account'],
        'search' => static fn (PDO $pdo, string $q, array $p, int $page): array => deals_for_pick($pdo, $q, $p, $page),
        'label' => static fn (PDO $pdo, int $id): ?string => deal_label($pdo, $id),
    ],
    'pipeline' => [
        'title' => 'Pipelines', 'noun' => 'pipeline', 'roles' => ['records.read'], 'params' => [],
        'search' => static fn (PDO $pdo, string $q, array $p, int $page): array => pipelines_for_pick($pdo, $q, $p, $page),
        'label' => static fn (PDO $pdo, int $id): ?string => (($v = one_value($pdo, 'SELECT name FROM mcp_pipelines WHERE pipeline_id = :id', ['id' => $id])) === null || $v === false) ? null : (string) $v,
    ],
    'stage' => [
        'title' => 'Stages', 'noun' => 'stage', 'roles' => ['records.read'], 'params' => ['pipeline'],
        'search' => static fn (PDO $pdo, string $q, array $p, int $page): array => stages_for_pick($pdo, $q, $p, $page),
        'label' => static fn (PDO $pdo, int $id): ?string => (($v = one_value($pdo, 'SELECT name FROM mcp_stages WHERE stage_id = :id', ['id' => $id])) === null || $v === false) ? null : (string) $v,
    ],
    'product' => [
        'title' => 'Products', 'noun' => 'product', 'roles' => ['deals.write'], 'params' => [],
        'search' => static fn (PDO $pdo, string $q, array $p, int $page): array => products_for_pick($pdo, $q, $p, $page),
        'label' => static fn (PDO $pdo, int $id): ?string => null,
    ],
    'activity' => [
        'title' => 'Activities', 'noun' => 'activity', 'roles' => ['records.read'], 'params' => ['deal'],
        'search' => static fn (PDO $pdo, string $q, array $p, int $page): array => activities_for_pick($pdo, $q, $p, $page),
        'label' => static fn (PDO $pdo, int $id): ?string => activity_label($pdo, $id),
    ],
    'template' => [
        'title' => 'Email templates', 'noun' => 'template', 'roles' => ['email.send', 'email.log', 'templates.manage'], 'params' => [],
        'search' => static fn (PDO $pdo, string $q, array $p, int $page): array => email_templates_for_pick($pdo, $q, $p, $page),
        'label' => static fn (PDO $pdo, int $id): ?string => email_template_label($pdo, $id),
    ],
    'member' => [
        'title' => 'Colleagues', 'noun' => 'colleague', 'roles' => ['records.read'], 'params' => [],
        'search' => static fn (PDO $pdo, string $q, array $p, int $page): array => colleagues_for_pick($pdo, $q, $p, $page),
        'label' => static fn (PDO $pdo, int $id): ?string => (($v = one_value($pdo, "SELECT display_name FROM mcp_members WHERE member_id = :id AND member_kind = 'human'", ['id' => $id])) === null || $v === false) ? null : (string) $v,
    ],
];
