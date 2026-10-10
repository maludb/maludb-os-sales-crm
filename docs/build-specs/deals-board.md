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

## Built and proven (2026-10-10)
The board of a pipeline (by id or key): a column per open stage with its colour, count, value and weighted (the business's currency; another currency counted
apart), the rotting count and the WIP warning; Won and Lost as narrow drop targets with the month's count and value; cards with the title, the account, the
short value, the owner's initials, the next step in the four colours, the hourglass, three tags (not at 375) and the flag naming what the next stage needs;
the card's menu as a `<details>` (Move to… — the no-JS move — Won, Lost, Log call and Schedule once slice 4 ships, Open); the drag (SortableJS, loaded in
the layout) posting `deal_move` and swapping in the columns the server renders; the region polled every 30 s (never mid-drag) and on dealChanged; at 375 one
column wide with snap and a stage chooser. The list (grouped by stage or owner, sortable, its columns the saved view's), the forecast (sellers or stages by
month, value · weighted, won, the quota line, totals; a cell opens the list of exactly those deals). The deal's page (number, status, account; value, weighted,
close, owner; Won · Lost or Reopen; ⋯ Edit · Reassign · Restrict · Delete; the stage bar; Overview — fields, products, people with roles, the next step, the
owner — Activities, Emails, Notes & files, History with the strip of time in each stage). The deal form (prefilled from an account's or a contact's page),
the Won and Lost forms, the saved views page. The registry reads 9 screens and 15 actions built, `deal_reopen` paused for agents.

**Proven** by `tests/phase3/slice3/run.sh` on a fresh `crm_dev3`: **191 checks** and the log and the registry, under php -S and a real Apache — board 33 (the
columns and crm_pipeline_summary()'s numbers; the four colours; rotting; WIP; the flag; a move logged with its five keys; position 0; the menu's select
over HTMX answering the columns; a refused move naming the fields with the card kept; another pipeline's stage; another Rep's deal; the poll after another
seller's move; Lost without a reason; five filters; the list sorted and grouped; the forecast's cell against the deals, the quota seen by the manager and not
by another Rep, a cell opening its deals; a pipeline by key or id), deals 46 (the defaults; prefilled from the account and from the contact; New deal on the
account page; made straight into Proposal refused then admitted; Won never set by a form; update, the override and back; a field left out stays; the stage
by name; another pipeline's first stage; the Won form, a future close refused, a back-dated win; the account and contact turn customer; Lost needs a deal's
reason; reopen a manager's into the stage it left; reassign — a Rep's own only, a manager's several, unowned a manager's; the wall), products 25, views 15,
json 34, the browser 38 (a real drag; a refused drag snapping back with the flash; a drop on Won opening the form and the post winning it; the stage bar; a
line; New deal from an account; Save this view; the poll; the menu's move and the Lost form with JavaScript off; the nine screens at 375 and 1280).
Slices 1 and 2, Phase 2, Phase 0 and Phase 1 re-run green; slice 2's conversion now lands on the new deal and its proof follows that; Phase 2's routing
and browser proofs now follow whether the board, the deal pages and the calendar are built (404 or a placeholder before, the screen after). Phase 2's
picker proof caught the deal form passing the contact picker's `include` as a list — the kit takes a comma-separated string (fixed); its log check now
prints the offending line.

**Decisions taken here (within the spec):**
- The drag's answer is the columns region re-rendered with the board's filters, which the move carries as `board_query` (parsed by
  `board_filters_from_request(…, $src)`); a 422 retargets to the flash and the script asks the region to re-render, so the card snaps back.
- The board is proven dragging at 1920 wide: at 1280 the Won and Lost columns are past the scroll edge. Dragging into an off-screen column relies on the
  browser scrolling during the drag; the menu's Move to… and the Won/Lost links are always there.
- The heads count what the filters leave, computed from the same rows as the cards; unfiltered they equal crm_pipeline_summary() (proven).
- A deal's custom fields follow its pipeline (`custom_field_defs.type_ids`, shown as `pipeline_ids`); a field marked required to win refuses the Won form by name.
- A won deal closed on an earlier day keeps that day as `won_at` (noon), so the forecast and the leaderboard count it in its month.
- `deal_product_update` and `deal_contact_update` share their add handlers (`products/save.php`, `contacts/save.php` include `add.php`).
- `/deals` in a browser goes to the default board; in JSON it is the `find_deals` resolver.
- A forecast row for a seller with no deal in the window and no quota is left out (agents, people who do not sell).
- Products from Inventory or the ledger: the picker source is registered and reads `k7_read()`; no connection is approved yet, so the form shows the name
  field only, and a line can carry `provider_app` + `provider_id` from an agent.
- The kit gained `one_row()` (app/db.php); `record_emails()` reads a deal's emails by `deal_id`.

**Seen, for the owner:** the seeded stages have no rotting days and the settings none, so nothing rots until someone sets them (slice 9's admin).
