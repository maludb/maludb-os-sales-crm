# Phase 1 — the consistency record (2026-10-09)

One writer (the planning model) wrote the Phase 1 documents in one sitting — the tool surface, the action manifest, the three build specs, the ten
slice specs — against the Phase 0 schema, so no reconciliation between parallel writers was needed. This records the decisions taken in the
specs that the owner should confirm, what db/017 added, and the final counts. `tests/phase1/check_specs.sh` keeps it true: the registry matches
the manifest, the approvals match, every screen and action is claimed by exactly one slice, every tool of the surface is named by a slice and
every tool a slice names exists (55 checks, green).

## The rule
**The manifest (`docs/sales-crm-action-manifest.md`) is canonical for screens and actions; the surface (`docs/sales-crm-mcp-tool-surface.md`) for tools.**
A slice spec names them by the manifest's ids and the surface's names. Each slice spec carries a `## Manifest rows claimed` section with two lines
(`Screens (N): …`, `Actions (N): …`) and a `## MCP tools this slice …` section the check script reads.

## Decisions taken in the specs (for the owner to confirm)
| # | Decision | Where |
|---|---|---|
| 1 | **Delete, restore, restrict and tags are one handler per verb across record kinds** (`record_delete`, `record_restore`, `record_restrict`, `tag_apply`, `tag_remove` with `kind` + `record`), not one per record as design §9 sketched — one rule in one place | accounts-contacts.md |
| 2 | **`deal_won` / `deal_lost` are `crm_deal_move` to the Won/Lost stage after the form's fields are set**; a drop on Won or Lost navigates to the form; the probability override is a field of `deal_update` | deals-board.md |
| 3 | **`activity_complete` makes the next step in the same request** (the prompt is a form section); the quick-log forms post `activity_create` with `done = yes`; `feed_rotate` (the shell's row) is built by slice 4 | activities-reminders.md |
| 4 | **A draft is an `outbound_email` row** (`queued`, `sent_at NULL`) — no drafts table; the matcher runs in the worker, never in the webhook request; the unsubscribe door `/u/{token}` is a route | email.md |
| 5 | **A bulk action over `bulk_sync_rows` is refused**, not queued — "the worker beyond" needs a jobs table the schema lacks; Extended | campaigns-lists.md |
| 6 | **One `report_export` handler** through slice 6's `exports` (kind `report`) | reports-home.md |
| 7 | **`sms_send` exists and refuses `no_sender` until K28 lands** so its approval policy exists on day one; the morning note's text is the Assistant's (through the kernel), PHP gives it the tools, the inbox and the mail body | agents-k7-api.md |
| 8 | **The two K7 reads the application consumes are PHP's** (`k7_read()`, cached 5 minutes, `no_connection` degrades); the records server reaches them through the bridge | agents-k7-api.md, the surface |
| 9 | **`assignment_rules_save` takes the whole ordered list**; `tag_delete` is a hard delete off every record; a pipeline or stage with deals is archived, never deleted | admin-settings.md |
| 10 | **The capture key's lead runs the rules** (owned) rather than landing proposed — `capture_to_inbox` is a later setting | leads-and-conversion.md §6 |
| 11 | **The routing form**: `/pipeline/{pipeline}/board|list|forecast`, `/deals/{id}`, `/calendar?view=&at=&who=`, `/log/{call|meeting|email}`, `/email/{direction}/{id}`, `/u/{token}`; GET navigations are not actions | the manifest's conventions |

## What db/017 added (the surface's "What the schema lacked")
`crm_calendar()`, `crm_my_activities()`, `crm_lost_reasons_summary()`, `crm_products_pipeline()`, `duplicate_dismissals` + `crm_duplicates_open()`, and the five `crm_share_*` functions
(SECURITY DEFINER, as the application, restricted records never shared, no body or note). The Phase 0 proof re-run green (173) after db/017.

## Where the manifest's agent grants and the surface differ (Phase 4 reconciles)
`maludb-os.json` `tool_grants` were written from the design before the surface: `touches_to_win` is the surface's `cycle_time`; `search` is in the Expert's list; `my_notifications`,
`get_settings`, `tombstones`, `quotas`, `deals_closed`, `products_pipeline`, `members_with_roles`, `account_tickets`, `activity_timeline`, `agent_activity`, `actor_history`, `activity_search` are
added to the Expert's; the Assistant's list is the surface's "Agents' grants" paragraph. Phase 4 rewrites `tool_grants` to the surface's names and `bin/hire_application_agent.php
--reconcile-tools` grants the difference on an installed hire.

## Counts
84 screens (6 the shell's), 121 actions (33 paused for agents: 5 `external_send`, 13 `deletion`, 15 `other`; 15 people-only), 72 records tools (+ `app_roles`, `records_search`, five
shares), 7 activity tools, 17 resolver entities, 10 slice specs, 3 build specs. The registry `mcp/action_registry.json` is built; `maludb-os.json` `approvals[]` is in sync.
