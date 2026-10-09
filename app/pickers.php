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
 * people), owner (people and agents holding a role here), tag. Later slices add: lead, source, campaign, list, import_template (2); deal, stage,
 * pipeline, product (3); activity (4); template (5).
 * A short enumeration (a kind, a role, a lifecycle, a size band) stays a <select>.
 */
require_once __DIR__ . '/features/accounts/queries.php';
require_once __DIR__ . '/features/contacts/queries.php';
require_once __DIR__ . '/features/tags/queries.php';

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
];
