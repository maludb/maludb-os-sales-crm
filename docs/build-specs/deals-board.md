# Build spec: pipelines, deals and THE BOARD (slice 3 — THE FIRST EXEMPLAR)

Builds `pipeline-board.md`. What exists at the end: the board, the list and the forecast of a pipeline as three views of one query; a deal's
page with the stage bar, value and weighted, contacts with roles, products, the next step, the timeline and the history strip; create and
edit; won and lost forms; reopen by a manager; reassign; saved views. The pipelines' and stages' admin is slice 9's; the two seeded
pipelines serve this slice.

Schema (db/008, 012, 015, 017, never modified): `pipelines`, `stages`, `deals`, `deal_stage_history`, `deal_contacts`, `deal_products`, `quotas`, `crm_deal_move()`,
`crm_stage_missing_fields()`, `crm_deal_is_rotting()`, `crm_account_recount()`, `crm_contact_lifecycle()` (db/008); `saved_views` (db/012); `crm_pipeline_summary()`,
`crm_forecast()`, `crm_stage_requirements_check()`, `crm_deals_at_risk()` (db/015); `crm_products_pipeline()` (db/017); `mcp_deals`, `mcp_deal_contacts`, `mcp_deal_products`,
`mcp_deal_stage_history`, `mcp_pipelines`, `mcp_stages`, `mcp_saved_views`, `mcp_quotas` (db/016).

## Divergences from the design
1. **`deal_won` and `deal_lost` are `crm_deal_move` to the pipeline's Won/Lost stage after the form's fields are set** (value and close date; reason and note) —
   one database rule. 2. **A drop on Won or Lost navigates to the form**, never closes silently. 3. **The probability override is a field of `deal_update`**
   (design §9 had a quick action; the card menu opens the edit form at that field).

## Who may do what
deal_create: `deals.write` · deal_update / deal_move / deal_won / deal_lost / products / contacts: `edit` on the deal (owner, unowned, manager) · deal_reopen: `deals.any`
(pauses for an agent, `other`) · deal_assign: `deals.any`, or the owner giving their own away · saved_view_save/delete: own; `global` scope needs `reports.team`.

## Screens
`board` `/pipeline/{pipeline}/board?…` · `deal-list` `/pipeline/{pipeline}/list?…&group=&sort=` · `forecast` `/pipeline/{pipeline}/forecast?by=&months=&team=` (the build spec §1, §4) ·
`deal-view` `/deals/{id}?tab=` (header: number, title, account, value · weighted, close date, owner, Log call · Schedule · Send email · Won · Lost · ⋯ Edit · Reassign · Restrict · Delete;
the **stage bar** (a click = `deal_move`; a refused stage shows the fields); tabs: Overview (fields, the next step card, the products table with Add line, the contacts with roles and
Add), Activities, Emails, Notes & files, History (the stage history strip with durations above the timeline)) · `deal-add` / `deal-edit` `/deals/new?account=&contact=&pipeline=&title=&value=&close=` ·
`/deals/{id}/edit` (fields; the `account`, `primary_contact`, `pipeline`, `stage`, `owner`, `source`, `campaign` pickers; value or value-from-products; the override; custom fields per
pipeline; the restriction) · `deal-won` `/deals/{id}/won` · `deal-lost` `/deals/{id}/lost` · `saved-views` `/views`.

## Files (exactly these)
The build spec §5, plus `tests/phase3/slice3/{run.sh,lib.php,board.php,deals.php,products.php,views.php,json.php,browser.mjs}`.

## Query functions (signatures fixed)
`find_board_deals(PDO, int $pipeline, array $filters, string $sort): array` · `board_columns(PDO, int $pipeline, array $filters): array{stages: [{stage, deals[], count, value, weighted, rotting, no_next_step}], closed: {won, lost}}` ·
`forecast_grid(PDO, ?int $pipeline, int $months, string $by, ?int $team): array` · `find_deals(PDO, array $filters, int $page): array` · `find_deal(PDO, int $id): ?array` · `deal_summary(PDO, int $id): array` (contacts, products, missing for next, next step) ·
`deals_for_pick(…)`, `stages_for_pick(PDO, string $q, array $params)` (needs `pipeline`), `pipelines_for_pick(…)`, `products_for_pick(PDO, string $q, array $params)` (the bridge when connected, else `[]` + `allow_new`) ·
`my_saved_views(PDO, string $recordKind): array` · writes: `deal_save(PDO, ?int $id, array $f, int $by): int` · `deal_move(PDO, int $id, int $stage, ?int $position)` · `deal_close(PDO, int $id, string $as, array $f)` ·
`deal_reopen(PDO, int $id)` · `deal_assign(PDO, array $ids, int $owner)` · `deal_product_save(PDO, int $deal, ?int $line, array $f): int` · `deal_product_remove(PDO, int $line)` · `deal_contact_set(PDO, int $deal, int $contact, string $role, bool $primary)` ·
`deal_contact_remove(PDO, int $deal, int $contact)` · `saved_view_save(PDO, ?int $id, array $f, int $by): int` · `saved_view_delete(PDO, int $id)`

## MCP tools this slice makes real
`find_pipelines` (the resolver), `find_stages` (the resolver), `pipeline_summary`, `forecast`, `deals_at_risk`, `deals_without_next_step`, `deals_rotting`, `find_deals` (the resolver), `get_deal`,
`deal_history`, `deal_products`, `deal_contacts`, `stage_requirements_check`, `products_pipeline`, `saved_views` (the resolver), `quotas` (read; set is slice 9's).

## Manifest rows claimed
Screens (9): `board`, `deal-list`, `forecast`, `deal-view`, `deal-add`, `deal-edit`, `deal-won`, `deal-lost`, `saved-views`
Actions (15): `deal_create`, `deal_update`, `deal_move`, `deal_won`, `deal_lost`, `deal_reopen`, `deal_assign`, `deal_product_add`, `deal_product_update`, `deal_product_remove`, `deal_contact_add`, `deal_contact_update`, `deal_contact_remove`, `saved_view_save`, `saved_view_delete`

## Activity log events
`deal.create|update|move|won|lost|reopen|assign|product_add|product_update|product_remove|contact_add|contact_update|contact_remove`, `view.save|delete`; `deal.move` carries
`{from_stage, to_stage, stage_name, seconds_in_stage, position}`; `deal.won|lost` `{value, currency, close_date, reason?}`.

## Status vocabulary
The build spec §6.

## Out of scope for this slice
Activities on a deal (4 — the Schedule and Log buttons link to slice 4's forms, stubbed here), email compose (5), the pipelines' admin (9), the Assistant's proposals on a deal (8).

## Proof (`tests/phase3/slice3/run.sh`: scratch `crm_dev3`; Playwright; the registry `--check`)
The build spec §7, plus: a deal made from the account page's New deal is prefilled; value from products follows the lines; the stage bar refuses with the fields; a Rep cannot reassign
another's deal, a manager can; a saved view saves the filters and re-runs; JSON mode everywhere; 9 screens built, 15 actions, 1 approval in step.

## Open questions
None.
