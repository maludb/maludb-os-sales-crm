<?php
declare(strict_types=1);

/**
 * app/pickers.php — the registry of what a form can pick (app/picker.php has the contract; design-system record-picker.md).
 * One entry per record kind, added by the slice whose form chooses it:
 *   key => ['title', 'noun', 'roles' => [Sales CRM rights, any one admits — has_right()], 'params' => [filter keys the endpoint forwards, as integers],
 *           'search' => fn(PDO, string $q, array $params, int $page): array{rows, total, page, page_size, pages},   rows: [id, label, detail?, badge?, color?]
 *           'label'  => fn(PDO, int $id): ?string]
 * `search` reuses the feature's own list query (find_*() with ILIKE and paging), never a second one; `label` names the chosen record.
 *
 * Empty in the Phase 2 shell. The slices add (docs/build-specs/*.md):
 *   account, contact, member, department — slice 1 (a contact's account, an owner, a restriction's team)
 *   lead, source, campaign, list, import_template — slice 2
 *   deal, stage, pipeline, product — slice 3 (product through Inventory's K7 share when connected)
 *   activity — slice 4 · template — slice 5 · campaign and list made real — slice 6
 * A short enumeration (a kind, a status, a role, a lifecycle) stays a <select>.
 */
return [];
