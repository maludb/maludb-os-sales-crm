# Build spec: campaigns, lists, segments, bulk actions and exports (slice 6)

What exists at the end: campaigns as cards with members and results; static lists with members and smart lists with a filter builder over the `find_*` vocabulary;
the bulk bar on every list screen and on a list's page (assign, tag, update a field, add to a campaign, add to a list, delete, export) in one transaction up to
`bulk_sync_rows`, the worker beyond; exports as CSV or JSON built by the worker; a campaign's email to its members through slice 5's send.

Schema (db/012, never modified): `campaigns`, `campaign_members`, `lists`, `list_members`, `exports`, `worker_passes`; `crm_campaign_results()` (db/015); `mcp_campaigns`, `mcp_campaign_members`,
`mcp_lists`, `mcp_list_members`, `mcp_exports` (db/016).

## Divergences from the design
1. **A bulk action takes `records[]` or `list` or `filters`** (one of them) and runs in one transaction up to `bulk_sync_rows`; **over the limit it is REFUSED** with "Narrow the
   selection or use a list" — the design's "the worker beyond" needs a jobs table the schema does not have and is Extended (recorded for the owner).
2. **A smart list's filters are the `find_*` vocabulary** rendered as form fields per record kind (`app/features/lists/filters.php` is the ONE place the vocabulary is defined and
   parsed; the list screens' filter bars and the saved views use it too).

## Who may do what
campaign_*: `campaigns.manage` (member add also `contacts.write`) · campaign_email_send: `campaigns.manage` + `email.send`, pauses · list_create: `lists.own`; `is_shared` needs
`lists.shared` · list_update/delete/add/remove: own or `lists.shared` · bulk_assign / bulk_update / bulk_delete: `records.bulk` (pause for an agent) · bulk_tag: `records.bulk` or
`tags.apply` · bulk_campaign_add: `campaigns.manage` · bulk_list_add: own or `lists.shared` · export_create: `export.own` (`export.shared` for every record).

## Screens
`campaign-list` `/campaigns?status=&kind=&owner=` · `campaign-view` `/campaigns/{id}` (results tiles from `crm_campaign_results`, members table with status, the leads and deals it produced,
Add members (the picker, multi) · Email members) · `campaign-add` / `campaign-edit` · `list-list` `/lists?record_kind=` · `list-view` `/lists/{id}?page=` (members as the kind's cards or rows,
the bulk bar; a smart list: the filters as chips, Edit filters, the live count) · `list-add` / `list-edit` `/lists/new?record_kind=&filters=` (the filter builder) · `export-list` `/exports`.

## Files (exactly these)
`html/campaigns/{index,view,form,save,email}.php`, `html/campaigns/members/{add,status,remove}.php`, `html/lists/{index,view,form,save,delete,add,remove}.php`, `html/bulk/{assign,tag,update,campaign,list,delete}.php`,
`html/exports/{index,create,download}.php`; `app/features/campaigns/{queries,present,write}.php`, `app/features/lists/{queries,present,write,filters}.php`, `app/features/bulk/{write,select}.php`
(`select.php`: `records[]` | `list` | `filters` → the ids, the wall applied, the limit), `app/features/exports/{queries,write,build}.php`; `app/views/campaigns/{index,view,form}.php`,
`app/views/campaigns/partials/{card,results,members}.php`, `app/views/lists/{index,view,form}.php`, `app/views/lists/partials/{filter-builder,chips}.php`, `app/views/shared/bulk-bar.php`
(included by every list screen of slices 1–4), `app/views/exports/index.php`; `bin/worker.php`'s real `smart_lists`, `exports`; `app/pickers.php` gains `campaign` (real), `list` (real); `tests/phase3/slice6/`.

## Query functions (signatures fixed)
`find_campaigns(PDO, array $f, int $page)` · `find_campaign(PDO, int $id)` · `campaign_results(PDO, int $id)` · `campaign_members(PDO, int $id, ?string $status, int $page)` · `find_lists(PDO, array $f)` ·
`find_list(PDO, int $id)` · `list_rows(PDO, int $id, int $page): array` (static: the members; smart: the kind's `find_*` with the filters) · `filters_form(string $kind, array $filters): string` ·
`filters_parse(string $kind, array $input): array` · `bulk_select(PDO, string $kind, array $input): array{ids, over_limit}` · writes: `campaign_save` · `campaign_member_add(PDO, int $c, array $leads, array $contacts, string $status)` ·
`campaign_member_status` · `campaign_member_remove` · `campaign_email_send(PDO, int $c, int $template, string $members, int $by): int` (drafts queued per contact through slice 5's `send.php`) ·
`list_save` · `list_delete` · `list_add(PDO, int $list, array $ids)` · `list_remove` · `bulk_assign/tag/update/campaign_add/list_add/delete(PDO, string $kind, array $ids, …): int` · `export_create(PDO, array $f, int $by): int` · `export_build(PDO, int $id)`

## MCP tools this slice makes real
`find_campaigns` (the resolver), `get_campaign`, `campaign_results`, `campaign_members`, `find_lists` (the resolver), `list_members`, `segment_preview`.

## Manifest rows claimed
Screens (9): `campaign-list`, `campaign-view`, `campaign-add`, `campaign-edit`, `list-list`, `list-view`, `list-add`, `list-edit`, `export-list`
Actions (18): `campaign_create`, `campaign_update`, `campaign_member_add`, `campaign_member_status`, `campaign_member_remove`, `campaign_email_send`, `list_create`, `list_update`, `list_delete`, `list_add`, `list_remove`, `bulk_assign`, `bulk_tag`, `bulk_update`, `bulk_campaign_add`, `bulk_list_add`, `bulk_delete`, `export_create`

## Activity log events
`campaign.create|update|member_add|member_status|member_remove|email_send`, `list.create|update|delete|add|remove`, `bulk.assign|tag|update|campaign_add|list_add|delete` (`{kind, count, filters?}`),
`export.create|build|download`.

## Status vocabulary
Campaign status: planned `secondary`, active `primary`, done `success`, cancelled `dark`. Member status: planned `secondary`, sent `info`, opened `primary`, responded `warning`, attended
`warning`, converted `success`, unsubscribed `dark`. Export: queued, running, done, failed as imports'. Ids: `campaign-card-{id}`, `campaign-results`, `campaign-members`, `list-card-{id}`,
`list-rows`, `filter-builder`, `filter-row-{n}`, `bulk-bar`, `bulk-count`, `bulk-{action}-btn`, `export-{id}`.

## Out of scope for this slice
The reports (7); the Assistant's duplicate proposals (8); a bulk over the limit by the worker (Extended, recorded above).

## Proof (`tests/phase3/slice6/run.sh`: scratch `crm_dev6`; the worker by hand)
A campaign with members by lead and contact, statuses, results tiles right against fixtures; a campaign email queues one draft per allowed contact and skips opted-out ones; a static list
counts by trigger; a smart list's filter builder round-trips for each record kind and the live count matches `find_*`; every bulk action over `records[]`, a list and filters; an over-limit
bulk is refused; `bulk_delete` by an agent pauses; an export builds a CSV with the kind's columns, downloads once, expires; JSON mode; 375 and 1280; the registry `--check` (9 screens, 18 actions, 5 approvals).

## Open questions
None.
