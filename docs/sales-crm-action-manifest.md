# Sales CRM Action Manifest

2026-10-09 · **Phase 1, for the checkpoint**

> The registry that the command bar (through the kernel's chat endpoint), the kernel's Actions MCP and the screens resolve against.
> A screen or action missing here cannot be reached by voice or by an agent. That is unfinished design, like a question with no tool.
> Pairs with `db/` (tables), `docs/sales-crm-mcp-tool-surface.md` (read tools) and the ten slice specs in `docs/build-specs/`.
> `bin/build_action_registry.php` generates `mcp/action_registry.json` from this file. An action whose file does not exist yet is
> registered but not exposed. **The approval categories here ARE `maludb-os.json` `approvals[]`**: `php bin/build_action_registry.php
> --approvals` (or `bin/sync_approvals.php`) rewrites that block from this file, and `--check` (in every proof) fails when the registry
> or the approvals are stale. The installer reads the resolve block from `deploy/kernel-registry-sales_crm.json`.

## Conventions

### Screens
- **Ids** are kebab-case: `{entity}-list`, `{entity}-add`, `{entity}-view`, `{entity}-edit`, plus named screens.
- **Canonical URLs** need the rewrites in `deploy/apache-sales_crm.conf` (and `tests/dev_router.php`): `/{x}/new` → `form.php`,
  `/{x}/{id}/edit` → `form.php?id=`, `/{x}/{id}` → `view.php?id=`, `/{x}/{id}/{page}` → `{page}.php?id=`; `/pipeline/{pipeline}/board`
  → `pipeline/board.php?pipeline=` (and `list`, `forecast`); `/calendar` → `calendar/index.php` with `view`, `at`, `who`, `kinds` as the
  query. Every id is an integer; a pipeline may be named by its key. The home, the agenda, a contact's page, the quick-log forms and the
  board (one column wide) are read at 375 px first; the board, the month and week grids and the forecast are designed at 1280 px and work
  at 375. No screen is a modal; the record picker is the one sanctioned modal.
- **Prefill params** become query-string values that the GET controller reads.
- **Every screen partial stamps** `data-screen`, `data-entity` and `data-record-id` on `#page-content`, so "log a call on this deal",
  "move it to Proposal" and "tag that" resolve against the page the user is on.
- **Doors that are not screens** have no row here: the ICS feed (`/api/v1/calendar.ics?token=`), the mailbox webhook
  (`/channels/malumail/mail`), the records API (`/api/v1/*`), the picker (`/pick/`), the polling partials (`/pipeline/{p}/columns`,
  `/calendar/grid`, `/import/{id}/status`), the unsubscribe door (`/u/{token}`, a public GET + POST that writes an opt-out; no session,
  no action token, never an action), `/sso` and `/sso/logout`.

### Actions
- **Name** `{entity}_{verb}`. The **log event** `{entity}.{verb}` is what the handler writes with `log_activity()` (with `lead_id`,
  `account_id`, `contact_id`, `deal_id` on every row that concerns one). It is also what the kernel's approval policies match
  (`approval_policies.action_pattern` = the registry's `log_event`). So **an action that pauses for an agent has a log event no free
  action shares**: `email.send` beside `email.log` and `email.draft`; `record.delete` beside `record.restore`; `records.merge` beside
  `duplicate.dismiss`; `deal.reopen` beside `deal.move`; `bulk.assign`, `bulk.update`, `bulk.delete` beside `bulk.tag`; `pipeline.save`,
  `stage.save`, `stage.reorder`, `rules.save`, `field.save`, `mailbox.save`, `settings.save`, `quota.set`, `agents_settings.save`,
  `key.capture_mint` beside the free `source.save`, `reason.save`, `tag.save`.
- **Endpoints** are POST to `/{base}/{file}` with `crm_handler_begin()` (`require_post()`, `require_login()`, `verify_csrf()` or the relayed
  action token), the right (`require_right()`), the record's wall (`require_visible()`: `none` → 404 — the same nothing for a record that
  does not exist), the edit rule (`require_editable()`: the owner, an unowned record, a manager → else 403), `crm_guard()` around the
  database's verb, `log_activity()`, `emit_action_status()` and `crm_done()`. PHP never re-implements a rule the database keeps (a stage's
  required fields, the status from the stage's kind, the weighted value, the next step, the reminder's moment, the domain's uniqueness,
  the conversion's finding and making, the merge's re-pointing). A create's `location` ends in the new record's id.
- **Params:** **bold** = required; `[]` = repeated; parentheses = a hint (no comma or semicolon inside; no note after the last
  parameter). A param named for a record accepts an id or a name, resolved through the records tool named in the resolve block of
  `deploy/kernel-registry-sales_crm.json`: `lead`, `account`, `company`, `parent_account`, `contact`, `person`, `primary_contact`, `deal`,
  `opportunity`, `pipeline`, `stage`, `to_stage`, `activity`, `task`, `meeting`, `call`, `campaign`, `list`, `segment`, `template`, `source`,
  `lead_source`, `lost_reason`, `reason`, `tag`, `saved_view`, `view`, `api_key`, `member`, `owner`, `assignee`, `seller`, `agent`,
  `new_owner`, `who`, `department`, `team`. `note`, `attachment`, `email` (an inbound or outbound email id), `import`, `proposal`,
  `dispatch`, `notification`, `token`, `product_line`, `participant`, `field`, `rule`, `export` are ids only. `any field of X` means X's
  fields, all optional: a partial update.
- **Who** is enforced in the endpoint: `own` = the record's owner (or the caller's own thing); a right (`leads.write`, `leads.convert`,
  `leads.assign`, `accounts.write`, `contacts.write`, `deals.write`, `deals.any`, `activities.write`, `activities.any`, `email.log`,
  `email.send`, `templates.manage`, `tags.apply`, `tags.manage`, `lists.own`, `lists.shared`, `campaigns.manage`, `sources.manage`,
  `imports.own`, `imports.shared`, `export.own`, `export.shared`, `records.restrict`, `records.merge`, `records.bulk`, `pipelines.manage`,
  `assignment.rules`, `reports.team`, `calendar.own`, `calendar.team`, `settings.manage`, `fields.manage`, `mailbox.manage`,
  `quotas.manage`, `agents.settings`, `tokens.manage`, `api.manage`, `records.purge`) is held through `crm_has_right()`; **`edit`** means
  the edit rule on that record (`crm_edit_wall()`: the owner, an unowned record, a manager); **`see`** means the wall (`crm_wall()`). A
  super-admin holds every right. An agent is granted like a person.
- **Undo** is the inverse `undo_last` applies. `—` means it cannot be undone, and the reply says so.
- **Confirm (✔)** = the command bar asks first (destructive, it reaches people outside, or it changes many records).
- **Agent approval** names the kernel's category that pauses the action when an **agent** performs it (design §5): `external_send`
  (words reach a contact: an email, a text, an invitation, a campaign email), `deletion` (a tombstone, a merge, a purge, a pipeline, a
  stage, a campaign, a list, a template, a tag, a rollback, a bulk delete), `other` (the book's rules and mass change: settings, pipelines
  and stages, assignment rules, custom fields, the mailbox, quotas, the agents' settings, bulk assign and update, a reopen, a key).
  People are never paused. **Looking, logging, creating, moving and scheduling inside the business are free.** The categories are
  exactly `maludb-os.json` `approvals[]`.
- **Agent** says whether the endpoint admits an agent's token at all: `yes`, or `people` (the handler calls `require_human()`). An
  action that pauses is still `yes`: the pause comes first, then the call.
- **record_id** is what the JSON answer's `record_id` names: the record made (a create) or the record acted on.
- **Refresh:** a data action returns `HX-Trigger: {entity}Changed` (camelCase entity); a move returns `dealChanged` and the board's
  columns partial re-renders.
- **Log payloads** (what `before`/`after` must carry) are in the tool surface's activity vocabulary. **An email's body, a note's text, a
  dictated activity body, more than 120 characters of a query, a token or a key are never in a payload** (ids, names, counts, kinds,
  statuses, stages, values and dates are).

### Result contract
```json
{"status": "success", "did": "Moved Acme — 20 seats to Proposal", "record_id": 88, "undo_id": "act_31", "refresh": "dealChanged"}
{"status": "pending_approval", "did": "An email to Dana Reyes waits for a person's approval", "approval_request_id": 17}
{"status": "refused", "did": "Proposal needs a primary contact and a close date", "errors": {"primary_contact_id": "required by the stage", "expected_close_date": "required by the stage"}}
```

## Home, me and the shell (Phase 2; the search box)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `home` | `/` | the start — *Today*: my activities due and overdue, my deals closing this month (value, weighted), no next step and rotting as links to the filtered board, replies since yesterday, the morning note; a manager sees the team's forecast tile and the leaderboard sparkline; a new person's one button, Add your first lead |
| `search` | `/search` | the one box's results grouped by kind with highlights (params: `q`, `kinds`) |
| `notifications` | `/notifications` | my notices, unread first |
| `activity` | `/activity` | my own trail here |
| `settings` | `/settings/` | my preferences: reminders by email and text, the default reminder, the morning note and its hour, my signature, my ICS link |
| `settings-tokens` | `/settings/tokens/` | my MCP tokens and my API keys: mint (shown once), rotate, revoke |

Actions (base `/settings/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `prefs_save` | `prefs.php` | email_enabled (yes or no), text_enabled (yes or no), kinds[] (the kinds that notify), text_kinds[], default_remind_minutes (0 to 10080), morning_note (yes or no), morning_hour (0 to 23), digest (yes or no) | restore prior | | | `settings.prefs` | own | yes | the prefs |
| `signature_save` | `signature.php` | signature (the words under my emails — up to 1000 characters) | restore prior | | | `settings.signature` | own | people | the member |
| `feed_rotate` | `feed.php` | confirm (yes — a new ICS link replaces the old one) | — | ✔ | | `feed.rotate` | own and calendar.own | people | the member |
| `notification_read` | `/notifications/read.php` | notification (one — or none for all) | — | | | `notification.read` | own | yes | the notification |
| `notifications_read_all` | `/notifications/read-all.php` | all (yes — every unread notice of mine) | — | | | `notification.read_all` | own | yes | the count |
| `token_mint` | `tokens/mint.php` | **label** (up to 80 characters), expires_in_days (blank for never) | token_revoke | | | `token.mint` | own | people | the token |
| `token_revoke` | `tokens/revoke.php` | **token** | — | ✔ | | `token.revoke` | own | people | the token |
| `key_mint` | `tokens/key-mint.php` | **label** (up to 80 characters), rate_per_minute (1 to 100000 — the setting by default), rate_per_day (1 to 10000000), expires_in_days | key_revoke | | other | `key.mint` | own | people | the key |
| `key_rotate` | `tokens/key-rotate.php` | **api_key** (the old one keeps 24 hours) | — | ✔ | | `key.rotate` | own | people | the new key |
| `key_revoke` | `tokens/key-revoke.php` | **api_key** | — | ✔ | | `key.revoke` | own or tokens.manage | people | the key |

## Accounts and contacts (slice 1)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `account-list` | `/accounts` | our accounts as cards: kind, owner, city, last touched, the next step's colour, open deals (params: `q`, `kind`, `owner`, `industry`, `country`, `tag`, `untouched_days`, `view`) |
| `account-view` | `/accounts/{id}` | one account: Overview (fields, kind, owner, the next step, the ledger's id and balance when connected), Contacts (cards, primary first), Deals (open then closed, the board link), Activities (agenda), Emails, Notes & files, Tickets (K7; an empty panel on no_connection), History — tabs by anchor; Log call · Schedule · New deal · Send email in the header (params: `tab`) |
| `account-add` | `/accounts/new` | to add a company: name, domain or website, industry, size, address, owner, source, parent, custom fields (params: `name`, `domain`) |
| `account-edit` | `/accounts/{id}/edit` | to change an account's fields, kind (partner, other, former by hand), owner, parent, the restriction (managers) |
| `contact-list` | `/contacts` | our contacts as cards: name, title, account, lifecycle, owner, last touched, the next step's colour, opt-outs (params: `q`, `account`, `lifecycle`, `owner`, `role`, `opt_out`, `tag`, `untouched_days`, `view`) |
| `contact-view` | `/contacts/{id}` | one person: the opt-out banner, fields, their account, Deals, Activities, Emails, Notes & files, History; Log call · Schedule · Send email (params: `tab`) |
| `contact-add` | `/contacts/new` | to add a person: names, title, email (the duplicate warning as you type), phones, role, account (the picker), owner, source, birthday, custom fields (params: `account`, `email`, `first_name`, `last_name`) |
| `contact-edit` | `/contacts/{id}/edit` | to change a contact's fields, account, role, primary flag, opt-outs, the restriction (managers) |

Actions (base `/accounts/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `account_create` | `save.php` | **name**, domain, website, legal_name, industry, employees (a band 1-10 to 5000+), annual_revenue, currency (ISO 4217), phone, email, billing_address, shipping_address, city, region, country (ISO 3166-1 alpha-2), kind (prospect or partner or other), parent_account (an account), owner (a member — the caller by default), source, description, tags[], custom_values (an object of custom field keys) | record_delete | | | `account.create` | accounts.write | yes | the account |
| `account_update` | `save.php` | **account**, any field of account_create | restore prior | | | `account.update` | edit | yes | the account |
| `contact_create` | `/contacts/save.php` | first_name, last_name, **email** (or a name — one of them is required), email_alt, title, department_text, phone, mobile, role (decision_maker or champion or influencer or user or finance or procurement or other), account (an account — found by the address's domain when omitted), is_primary (yes or no), linkedin_url, address, city, region, country, timezone, preferred_channel (email or phone or sms), birthday (a date), owner, source, tags[], custom_values | record_delete | | | `contact.create` | contacts.write | yes | the contact |
| `contact_update` | `/contacts/save.php` | **contact**, any field of contact_create | restore prior | | | `contact.update` | edit | yes | the contact |
| `contact_set_primary` | `/contacts/primary.php` | **contact** (becomes the primary of its account) | restore prior | | | `contact.primary` | edit | yes | the contact |
| `contact_opt_out` | `/contacts/opt-out.php` | **contact**, **channel** (email or sms), reason (up to 200 characters), undo (yes — lifts the opt-out instead) | contact_opt_out | | | `contact.opt_out` | edit | yes | the contact |
| `record_restrict` | `/records/restrict.php` | **kind** (lead or account or contact or deal), **record** (its id), member (the owner it is restricted to — the owner by default), department (a team it is restricted to), lift (yes — removes the restriction) | record_restrict | | | `record.restrict` | records.restrict | yes | the record |
| `record_delete` | `/records/delete.php` | **kind** (lead or account or contact or deal or activity or campaign), **record** (its id — to the tombstone for 30 days) | record_restore | ✔ | deletion | `record.delete` | edit (a manager for an account with deals) | yes | the record |
| `record_restore` | `/records/restore.php` | **kind**, **record** (a tombstone of mine or any for a manager) | record_delete | | | `record.restore` | own or deals.any | yes | the record |
| `note_add` | `/notes/add.php` | **kind** (lead or account or contact or deal or activity or campaign), **record**, **body** (up to 10000 characters) | note_delete | | | `note.add` | see | yes | the note |
| `note_delete` | `/notes/delete.php` | **note** | — | ✔ | deletion | `note.delete` | own or deals.any | yes | the note |
| `attachment_add` | `/attachments/add.php` | **kind**, **record**, **file** (the bytes — up to 25 MB) | attachment_delete | | | `attachment.add` | edit | yes | the attachment |
| `attachment_delete` | `/attachments/delete.php` | **attachment** | — | ✔ | deletion | `attachment.delete` | own or deals.any | yes | the attachment |
| `tag_apply` | `/tags/apply.php` | **kind** (lead or account or contact or deal or activity or campaign), **record**, **tag** (a label — a new one is made), records[] (several records of that kind instead of one) | tag_remove | | | `tag.apply` | tags.apply and edit | yes | the record |
| `tag_remove` | `/tags/remove.php` | **kind**, **record**, **tag** | tag_apply | | | `tag.remove` | tags.apply and edit | yes | the record |

## Leads, conversion, the inbox, import and duplicates (slice 2)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `lead-list` | `/leads` | leads as cards: status, source, owner, score, last touched (params: `q`, `status`, `owner`, `source`, `campaign`, `untouched_days`, `tag`, `view`) |
| `lead-inbox` | `/leads/inbox` | unowned and proposed leads — from the website form and the mailbox — to assign, accept or dismiss, in bulk |
| `lead-view` | `/leads/{id}` | one lead: fields, the duplicate warning, the score and its reasons, the timeline; Convert · Unqualify · Assign · Log call · Schedule |
| `lead-add` | `/leads/new` | to add a lead: person, company, email, phone, website, country, source, campaign, message, custom fields (params: `first_name`, `last_name`, `company`, `email`, `source`) |
| `lead-edit` | `/leads/{id}/edit` | to change a lead's fields (read-only once converted) |
| `lead-convert` | `/leads/{id}/convert` | the conversion form: the account found by domain or name (or new), the contact found by email (or new), a deal yes or no with its pipeline, title, value and close date |
| `import-list` | `/import` | my imports (every import for a manager): kind, file, status, counts, rollback until |
| `import-new` | `/import/new` | to import a CSV: the kind, the file, the mapping (a saved template or by hand), the dedupe policy, the owner, the source, a list to join; a dry run first (params: `kind`, `template`) |
| `import-view` | `/import/{id}` | one import: the counts, every row's result, Run (after a dry run) · Roll back (within 24 hours) |
| `duplicates` | `/duplicates` | possible duplicates by kind with the matching fields highlighted (params: `kind`) |
| `merge` | `/duplicates/merge` | two records side by side; choose the survivor (params: `kind`, `a`, `b`) |

Actions (base `/leads/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `lead_create` | `save.php` | first_name, last_name, **company** (or a person or an email — one of them is required), title, email, phone, website, address, city, region, country, source (a lead source — Outbound by default), campaign, message (up to 2000 characters), owner (the rules decide when omitted), tags[], custom_values | record_delete | | | `lead.create` | leads.write | yes | the lead |
| `lead_update` | `save.php` | **lead**, any field of lead_create | restore prior | | | `lead.update` | edit | yes | the lead |
| `lead_qualify` | `qualify.php` | **lead**, score (0 to 100), status (working — the default) | restore prior | | | `lead.qualify` | edit | yes | the lead |
| `lead_unqualify` | `unqualify.php` | **lead**, **reason** (a lost reason of kind lead), note (up to 500 characters) | lead_requalify | | | `lead.unqualify` | edit | yes | the lead |
| `lead_requalify` | `requalify.php` | **lead** (back to working) | lead_unqualify | | | `lead.requalify` | edit | yes | the lead |
| `lead_convert` | `convert.php` | **lead**, account (an existing account — found by domain then name when omitted), contact (an existing contact — found by email when omitted), make_deal (yes or no — yes by default), pipeline (the default pipeline when omitted), deal_title, value, close_date (a date) | — | | | `lead.convert` | leads.convert and edit | yes | the deal (or the contact or the account) |
| `lead_assign` | `assign.php` | **lead** (or leads[] for several), **owner** (a member — or none to send it to the inbox) | restore prior | | | `lead.assign` | leads.assign or edit | yes | the lead |
| `lead_accept_proposed` | `accept.php` | **lead** (a proposed lead from the mailbox or the form — into the book with the rules' owner), owner | — | | | `lead.accept` | leads.write | yes | the lead |
| `lead_dismiss_proposed` | `dismiss.php` | **lead** (a proposed lead — to the tombstone) | record_restore | | deletion | `lead.dismiss` | leads.write | yes | the lead |
| `import_start` | `/import/start.php` | **kind** (leads_csv or contacts_csv or accounts_csv or deals_csv), **file** (a CSV up to 50000 rows), mapping (an object of CSV column to field or custom:key), template (a saved mapping), dedupe_policy (skip or update or create), owner (the records' owner when the file names none), source, list (a static list every created record joins), dry_run (yes — the default) | — | | | `import.start` | imports.own | people | the import |
| `import_run` | `/import/run.php` | **import** (after its dry run) | import_rollback | ✔ | | `import.run` | own or imports.shared | people | the import |
| `import_rollback` | `/import/rollback.php` | **import** (within 24 hours — the records it created go to the tombstone) | — | ✔ | deletion | `import.rollback` | own or imports.shared | people | the import |
| `import_template_save` | `/import/template-save.php` | **name**, **kind**, **mapping**, dedupe_policy, is_shared (yes or no) | — | | | `import.template_save` | imports.own | people | the template |
| `records_merge` | `/duplicates/merge.php` | **kind** (lead or account or contact), **survivor** (its id), **loser** (its id) | — | ✔ | deletion | `records.merge` | records.merge | yes | the survivor |
| `duplicate_dismiss` | `/duplicates/dismiss.php` | **kind**, **a** (one id), **b** (the other — the pair is not shown again) | — | | | `duplicate.dismiss` | records.merge or edit | yes | the pair |

## Pipelines, deals and THE BOARD — THE FIRST EXEMPLAR (slice 3)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `board` | `/pipeline/{pipeline}/board` | the kanban board of a pipeline: a column per open stage with count, value and weighted, Won and Lost as collapsed drop targets with the month's totals; cards with title, account, value, owner, the next step in the four colours, the rotting mark, tags, the missing-fields flag; a drag is a move; one column wide at 375 (params: `owner`, `team`, `close`, `tag`, `source`, `campaign`, `min_value`, `max_value`, `no_next_step`, `rotting`, `q`, `view`) |
| `deal-list` | `/pipeline/{pipeline}/list` | the same deals as a sortable table grouped by stage or owner, columns from the saved view (params as the board plus `group`, `sort`) |
| `forecast` | `/pipeline/{pipeline}/forecast` | the forecast grid: sellers (or stages) by the next six months of expected close — value, weighted, won, quota (params: `by`, `months`, `team`) |
| `deal-view` | `/deals/{id}` | one deal: the stage bar (a click moves; refused with the fields named), value and weighted, close date, owner, contacts with roles, products, the next step, the timeline, the stage history strip with durations; Log call · Schedule · Send email · Won · Lost (params: `tab`) |
| `deal-add` | `/deals/new` | to add a deal: title, account and primary contact (pickers), pipeline, stage, value, currency, close date, owner, source, campaign, description, custom fields (params: `account`, `contact`, `pipeline`, `title`, `value`, `close`) |
| `deal-edit` | `/deals/{id}/edit` | to change a deal's fields, products, contacts, the probability override, the restriction (managers) |
| `deal-won` | `/deals/{id}/won` | the won form: confirm the value and the close date |
| `deal-lost` | `/deals/{id}/lost` | the lost form: the reason and a note |
| `saved-views` | `/views` | my saved views and the shared ones: name, kind, filters as chips, layout; Run · Edit · Delete |

Actions (base `/deals/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `deal_create` | `save.php` | **title**, account (an account), primary_contact (a contact), pipeline (the default when omitted), stage (the pipeline's first open stage when omitted), value (0 or more), currency (the pipeline's or the business's when omitted), expected_close_date (a date), owner (the caller by default), source, campaign, description, tags[], custom_values | record_delete | | | `deal.create` | deals.write | yes | the deal |
| `deal_update` | `save.php` | **deal**, any field of deal_create, probability_override (0 to 100 or blank for the stage's), value_from_products (yes or no) | restore prior | | | `deal.update` | edit | yes | the deal |
| `deal_move` | `move.php` | **deal**, **to_stage** (a stage of the deal's pipeline — refused with the missing fields named), position (0 for the top of the column) | deal_move | | | `deal.move` | edit | yes | the deal |
| `deal_won` | `won.php` | **deal**, value (confirmed), close_date (today by default) | deal_reopen | | | `deal.won` | edit | yes | the deal |
| `deal_lost` | `lost.php` | **deal**, **reason** (a lost reason of kind deal), note (up to 500 characters) | deal_reopen | | | `deal.lost` | edit | yes | the deal |
| `deal_reopen` | `reopen.php` | **deal** (a won or lost deal back to its last open stage) | — | ✔ | other | `deal.reopen` | deals.any | yes | the deal |
| `deal_assign` | `assign.php` | **deal** (or deals[]), **owner** (a member) | restore prior | | | `deal.assign` | deals.any or own | yes | the deal |
| `deal_product_add` | `products/add.php` | **deal**, **name** (or a product from Inventory or the ledger through the picker), provider_app (inventory or gl), provider_id, sku, quantity (1 by default), unit_price, discount_percent (0 to 100), recurring (none or monthly or yearly) | deal_product_remove | | | `deal.product_add` | edit | yes | the line |
| `deal_product_update` | `products/save.php` | **product_line**, any field of deal_product_add, position | restore prior | | | `deal.product_update` | edit | yes | the line |
| `deal_product_remove` | `products/remove.php` | **product_line** | deal_product_add | | | `deal.product_remove` | edit | yes | the line |
| `deal_contact_add` | `contacts/add.php` | **deal**, **contact**, role (decision_maker or champion or influencer or user or finance or procurement or other), is_primary (yes or no) | deal_contact_remove | | | `deal.contact_add` | edit | yes | the deal |
| `deal_contact_update` | `contacts/save.php` | **deal**, **contact**, role, is_primary | restore prior | | | `deal.contact_update` | edit | yes | the deal |
| `deal_contact_remove` | `contacts/remove.php` | **deal**, **contact** (never the primary — set another first) | deal_contact_add | | | `deal.contact_remove` | edit | yes | the deal |
| `saved_view_save` | `/views/save.php` | **name**, **record_kind** (lead or account or contact or deal or activity), filters (the screen's filters as an object), sort_by, sort_dir (asc or desc), columns[], layout (list or board or calendar or forecast), pipeline, scope (personal or global — global for managers), saved_view (to change an existing one) | saved_view_delete | | | `view.save` | own or reports.team | yes | the view |
| `saved_view_delete` | `/views/delete.php` | **saved_view** | — | ✔ | | `view.delete` | own or reports.team | yes | the view |

## Activities, reminders and THE CALENDAR — THE SECOND EXEMPLAR (slice 4)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `calendar` | `/calendar` | the calendar: month, week, day or agenda of activities for me, a colleague or a team, chips by kind and state, New on a cell, drag to reschedule; the agenda is the phone's default (params: `view`, `at`, `who`, `kinds`) |
| `activity-list` | `/activities` | activities as a list: mine, the team's or all; due, overdue, done; by kind; by record (params: `who`, `state`, `kind`, `from`, `to`, `account`, `contact`, `deal`, `lead`, `view`) |
| `activity-view` | `/activities/{id}` | one activity: the record it belongs to, participants, the reminder, notes; Done with outcome and the next-step prompt · Snooze · Reschedule · Reassign |
| `activity-add` | `/activities/new` | to schedule: kind, subject, the record (pickers), when (a due time or a start and an end), all day, reminder, participants, location or link, priority, recurrence (tasks), body (params: `kind`, `deal`, `contact`, `account`, `lead`, `at`, `subject`) |
| `activity-edit` | `/activities/{id}/edit` | to change an activity's fields |
| `log-call` | `/log/call` | the quick log: who (picker), the deal, the outcome, what was said, the next step — one screen on a phone (params: `contact`, `deal`, `account`, `lead`) |
| `log-meeting` | `/log/meeting` | the quick log of a meeting held: who, when, the outcome, notes, the next step (params as log-call) |

Actions (base `/activities/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `activity_create` | `save.php` | **kind** (call or email or meeting or task or deadline or lunch), **subject**, deal, contact, account, lead (at least one — a bare task needs none), due_at (a task or a deadline — or the moment of a logged call), starts_at, ends_at (a meeting or a call or a lunch), all_day (yes or no), timezone, owner (who does it — the caller by default), body (up to 10000 characters), remind_minutes_before (0 to 10080 — the default when omitted), done (yes — logged as done now), outcome (connected or no_answer or left_message or busy or meeting_held or no_show or completed or cancelled), outcome_note, participants[] (contacts), members[] (colleagues), location, meeting_url, priority (low or normal or high), recurrence (an RRULE for tasks), tags[], custom_values | record_delete | | | `activity.create` | activities.write and see on the record | yes | the activity |
| `activity_update` | `save.php` | **activity**, any field of activity_create | restore prior | | | `activity.update` | own or activities.any | yes | the activity |
| `activity_complete` | `complete.php` | **activity**, outcome (per kind — the kind's default when omitted), outcome_note (up to 2000 characters), done_at (now by default), next_kind (the next step's kind — none when omitted), next_subject, next_at | activity_reopen | | | `activity.complete` | own or assignee or activities.any | yes | the activity (the next step's id in `next_activity_id`) |
| `activity_reopen` | `reopen.php` | **activity** (done → open again) | activity_complete | | | `activity.reopen` | own or activities.any | yes | the activity |
| `activity_reschedule` | `reschedule.php` | **activity**, **at** (the new due time or start), ends_at, all_day | restore prior | | | `activity.reschedule` | own or assignee or calendar.team | yes | the activity |
| `activity_snooze` | `snooze.php` | **activity**, **until** (10m or 1h or tomorrow or a time) | — | | | `activity.snooze` | own or assignee | yes | the activity |
| `activity_assign` | `assign.php` | **activity**, **owner** (a member — or an agent) | restore prior | | | `activity.assign` | own or activities.any | yes | the activity |
| `activity_participant_add` | `participants/add.php` | **activity**, contact or member (one of them) | activity_participant_remove | | | `activity.participant_add` | own or activities.any | yes | the activity |
| `activity_participant_remove` | `participants/remove.php` | **activity**, contact or member | activity_participant_add | | | `activity.participant_remove` | own or activities.any | yes | the activity |

## Email (slice 5)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `email-compose` | `/email/compose` | to write to a contact: To from the record's contacts, a template (the merge preview), attachments, the ICS when from a meeting; Send (a person) or Save draft (params: `contact`, `deal`, `account`, `template`, `activity`, `draft`) |
| `email-view` | `/email/{direction}/{id}` | one email: headers, the body (when I may read it), the contacts matched, the deal, attachments; Mark private · Attach to contact · Make lead |
| `email-unmatched` | `/email/unmatched` | emails matched to no one, and proposed leads from strangers: Make lead · Attach to contact · Ignore |
| `log-email` | `/log/email` | to log an email already sent or received: who, the direction, the subject, the pasted text, the deal (params: `contact`, `deal`) |
| `template-list` | `/email/templates` | the templates — shared and mine — with their kind and uses; New |
| `template-add` | `/email/templates/new` | a template: name, kind, subject and body with the merge fields listed, shared or mine |
| `template-edit` | `/email/templates/{id}/edit` | to change a template |

Actions (base `/email/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `email_draft` | `draft.php` | **contact** (or contacts[] — every address must be allowed), deal, template (its merge fields filled), subject, body (Markdown), attachments[] (attachment ids), activity (a meeting — its ICS attached), draft (an existing draft to change) | — | | | `email.draft` | email.send or email.log | yes | the draft |
| `email_send` | `send.php` | **draft** (a draft of mine — or the fields of email_draft to make one and send it), confirm (yes) | — | ✔ | external_send | `email.send` | email.send and see on the contact | yes | the outbound email |
| `email_log` | `/log/email.php` | **contact** (or contacts[]), **direction** (in or out), **subject**, body (the pasted text — up to 100000 characters), deal, at (when it was sent — now by default) | record_delete | | | `email.log` | email.log and see on the contact | yes | the activity |
| `email_private_set` | `private.php` | **email** (an inbound or outbound email id), **direction** (in or out), private (yes or no) | email_private_set | | | `email.private` | own (the sender) or deals.any | yes | the email |
| `email_attach_contact` | `attach.php` | **email** (an unmatched inbound email), **contact** (the address is added to their addresses) | — | | | `email.attach` | contacts.write | yes | the email |
| `email_make_lead` | `make-lead.php` | **email** (an unmatched inbound email — a lead from its sender) | record_delete | | | `email.make_lead` | leads.write | yes | the lead |
| `email_ignore` | `ignore.php` | **email** (an unmatched inbound email — hidden) | — | | | `email.ignore` | leads.write | yes | the email |
| `meeting_invite_send` | `invite.php` | **activity** (a meeting with contact participants), message (a line above the invitation) | — | ✔ | external_send | `email.invite` | own or activities.any | yes | the activity |
| `template_save` | `templates/save.php` | **name**, **subject**, **body** (Markdown with merge fields), kind (general or intro or follow_up or proposal or meeting or thank_you or renewal or win_back), is_shared (yes or no — shared needs templates.manage), template (to change an existing one) | restore prior | | | `template.save` | own or templates.manage | yes | the template |
| `template_delete` | `templates/delete.php` | **template** (archived when used) | — | ✔ | deletion | `template.delete` | own or templates.manage | yes | the template |

## Campaigns, lists, segments and bulk actions (slice 6)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `campaign-list` | `/campaigns` | campaigns as cards: kind, status, dates, budget, leads and deals produced (params: `status`, `kind`, `owner`) |
| `campaign-view` | `/campaigns/{id}` | one campaign: members and their status, the leads and deals it produced (value won and open), cost per lead and per won deal |
| `campaign-add` | `/campaigns/new` | a campaign: name, kind, status, dates, budget, expected leads, utm, owner, description |
| `campaign-edit` | `/campaigns/{id}/edit` | to change a campaign |
| `list-list` | `/lists` | my lists and the shared ones: kind (static or smart), record kind, count, owner (params: `record_kind`) |
| `list-view` | `/lists/{id}` | one list's members as the record kind's cards or rows; the bulk bar; a smart list shows its filters as chips and its live members (params: `page`) |
| `list-add` | `/lists/new` | a list: name, kind, record kind; a smart list's filter builder is the find_* vocabulary as form fields (params: `record_kind`, `filters`) |
| `list-edit` | `/lists/{id}/edit` | to change a list's name, filters, sharing |
| `export-list` | `/exports` | my exports: ready to download, being made, expiring |

Actions (base `/campaigns/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `campaign_create` | `save.php` | **name**, kind (email or event or webinar or ads or content or outbound or referral or other), status (planned or active or done or cancelled), starts_on, ends_on, budget, currency, expected_leads, utm_campaign, owner, description | record_delete | | | `campaign.create` | campaigns.manage | yes | the campaign |
| `campaign_update` | `save.php` | **campaign**, any field of campaign_create | restore prior | | | `campaign.update` | campaigns.manage | yes | the campaign |
| `campaign_member_add` | `members/add.php` | **campaign**, lead or contact (one — or leads[] or contacts[]), status (planned or sent or opened or responded or attended or converted) | campaign_member_remove | | | `campaign.member_add` | campaigns.manage or contacts.write | yes | the campaign |
| `campaign_member_status` | `members/status.php` | **campaign**, **contact** (or lead), **status** | restore prior | | | `campaign.member_status` | campaigns.manage | yes | the campaign |
| `campaign_member_remove` | `members/remove.php` | **campaign**, **contact** (or lead) | campaign_member_add | | | `campaign.member_remove` | campaigns.manage | yes | the campaign |
| `campaign_email_send` | `email.php` | **campaign**, **template**, members (planned — the default — or all), confirm (yes) | — | ✔ | external_send | `campaign.email_send` | campaigns.manage and email.send | yes | the campaign |
| `list_create` | `/lists/save.php` | **name**, **kind** (static or smart), **record_kind** (lead or contact or account or deal), filters (a smart list's — the find_* vocabulary as an object), is_shared (yes or no — shared needs lists.shared), description | record_delete | | | `list.create` | lists.own | yes | the list |
| `list_update` | `/lists/save.php` | **list**, any field of list_create | restore prior | | | `list.update` | own or lists.shared | yes | the list |
| `list_delete` | `/lists/delete.php` | **list** | — | ✔ | deletion | `list.delete` | own or lists.shared | yes | the list |
| `list_add` | `/lists/add.php` | **list** (a static one), **record** (or records[]) | list_remove | | | `list.add` | own or lists.shared | yes | the list |
| `list_remove` | `/lists/remove.php` | **list**, **record** (or records[]) | list_add | | | `list.remove` | own or lists.shared | yes | the list |
| `bulk_assign` | `/bulk/assign.php` | **kind** (lead or account or contact or deal), **owner**, records[] or list or filters (what to act on — one of them) | — | ✔ | other | `bulk.assign` | records.bulk | yes | the count |
| `bulk_tag` | `/bulk/tag.php` | **kind**, **tag**, remove (yes — takes the tag off instead), records[] or list or filters | — | ✔ | | `bulk.tag` | records.bulk or tags.apply | yes | the count |
| `bulk_update` | `/bulk/update.php` | **kind**, **field** (one column or custom:key), **value**, records[] or list or filters | — | ✔ | other | `bulk.update` | records.bulk | yes | the count |
| `bulk_campaign_add` | `/bulk/campaign.php` | **campaign**, **kind** (lead or contact), records[] or list or filters | — | ✔ | | `bulk.campaign_add` | campaigns.manage | yes | the count |
| `bulk_list_add` | `/bulk/list.php` | **list** (a static one), records[] or filters | — | ✔ | | `bulk.list_add` | own or lists.shared | yes | the count |
| `bulk_delete` | `/bulk/delete.php` | **kind**, records[] or list or filters (to the tombstone) | — | ✔ | deletion | `bulk.delete` | records.bulk | yes | the count |
| `export_create` | `/exports/create.php` | **kind** (leads or contacts or accounts or deals or activities or list or report), format (csv or json), list, filters, report (a report's name and period) | — | | | `export.create` | export.own (export.shared for every record) | yes | the export |

## Reports and the forecast (slice 7)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `reports` | `/reports` | the reports as cards with a number each: forecast, funnel, win rate, cycle time, sources, campaigns, activity, leaderboard, lost reasons |
| `report-funnel` | `/reports/funnel` | where the funnel leaks: per stage entered, moved on, lost, still here, median days (params: `pipeline`, `from`, `to`, `owner`) |
| `report-win-rate` | `/reports/win-rate` | won against lost by seller, source or pipeline in a period, with the cycle (params: `from`, `to`, `by`) |
| `report-cycle-time` | `/reports/cycle-time` | days from creation to won: average and median by seller, with touches to win (params: `from`, `to`) |
| `report-sources` | `/reports/sources` | leads, conversions, deals and won value by source (params: `from`, `to`) |
| `report-campaigns` | `/reports/campaigns` | campaigns against their cost: leads, won, cost per lead and per won deal (params: `status`) |
| `report-activity` | `/reports/activity` | calls, emails, meetings and tasks per seller in a period, with outcomes (params: `from`, `to`, `team`) |
| `report-leaderboard` | `/reports/leaderboard` | won against quota this month, open weighted, activities done (params: `month`, `team`) |
| `report-lost-reasons` | `/reports/lost-reasons` | why we lost, by reason, seller and period (params: `from`, `to`) |
| `report-forecast` | `/reports/forecast` | the forecast by month across pipelines (params: `by`, `months`, `team`) |

Actions (base `/reports/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `report_export` | `export.php` | **report** (funnel or win_rate or cycle_time or sources or campaigns or activity or leaderboard or lost_reasons or forecast), from, to, by, pipeline, team | — | | | `report.export` | reports.team (a seller their own rows) | yes | the export |

## Agents, proposals, notifications, K7 and the API (slice 8)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `proposals` | `/proposals` | the Assistant's proposals for me — next steps, qualifications, duplicates, drafts — Accept · Dismiss; a manager sees everyone's (params: `kind`, `status`, `who`) |
| `admin-agents` | `/admin/agents` | the agents working here, their last runs, the morning note's settings, proposals on or off per pipeline, dispatches |

Actions (base `/proposals/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `proposal_accept` | `accept.php` | **proposal** (a next step becomes my activity — a qualification sets the score — a close date is set) | proposal_dismiss | | | `proposal.accept` | own (for_member) or deals.any | yes | the proposal (the activity's id in `result_activity_id`) |
| `proposal_dismiss` | `dismiss.php` | **proposal** | — | | | `proposal.dismiss` | own or deals.any | yes | the proposal |
| `next_step_propose` | `propose.php` | **deal**, **kind** (call or email or meeting or task or deadline), **subject**, **at** (a time in the seller's zone), body (what to say — up to 2000 characters), for (the seller — the deal's owner by default) | proposal_dismiss | | | `proposal.next_step` | activities.write | yes | the proposal |
| `lead_qualify_propose` | `propose-lead.php` | **lead**, **score** (0 to 100), reasons[] (short lines), owner (a suggested owner) | proposal_dismiss | | | `proposal.lead_qualify` | leads.write | yes | the proposal |
| `duplicate_propose` | `propose-duplicate.php` | **kind** (lead or account or contact), **a**, **b**, matched_on (email or domain or phone or name), for (a manager) | proposal_dismiss | | | `proposal.duplicate` | records.read | yes | the proposal |
| `notification` | `/notify/send.php` | **member** (a seller or a manager), **kind** (morning_note or proposal or mention or digest), **title**, body (Markdown — up to 4000 characters), deal, contact, activity | — | | | `notification.send` | activities.write (an agent or a manager) | yes | the notification |
| `sms_send` | `/sms/send.php` | **contact**, **body** (up to 300 characters — refused until the kernel's K28 lands) | — | ✔ | external_send | `sms.send` | email.send and see on the contact | yes | the contact |
| `dispatch_cancel` | `/admin/agents/dispatch-cancel.php` | **dispatch** (sent or awaiting — cancelled) | — | | | `dispatch.cancel` | agents.settings | people | the dispatch |
| `agents_settings_save` | `/admin/agents/save.php` | morning_note_enabled (yes or no), morning_note_hour (0 to 23), assistant_proposals (yes or no), lead_untouched_days (1 to 365), account_untouched_days, ask_assistant_stages[] (the stages that dispatch on entry) | restore prior | | other | `agents_settings.save` | agents.settings | people | the settings |

## Admin: pipelines, vocabularies, rules, fields, the mailbox, quotas, settings, tokens, purge (slice 9)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `admin` | `/admin` | the admin home: the areas as cards with a number each; the mailbox's health; the tombstones due |
| `admin-pipelines` | `/admin/pipelines` | the pipelines and their stages: order (drag, the enhancement), probability, rotting, required fields, ask-the-Assistant, colour, WIP; archived ones |
| `admin-pipeline-add` | `/admin/pipelines/new` | a pipeline: key, name, currency, rotting days, default |
| `admin-pipeline-edit` | `/admin/pipelines/{id}/edit` | to change a pipeline, archive or restore it |
| `admin-stage-add` | `/admin/pipelines/{id}/stages/new` | a stage: name, probability, kind, rotting days, required fields (deal columns and custom keys), ask the Assistant, colour, WIP |
| `admin-stage-edit` | `/admin/stages/{id}/edit` | to change a stage |
| `admin-sources` | `/admin/sources` | the lead sources and the lost reasons (leads' and deals'): order, active |
| `admin-rules` | `/admin/rules` | the assignment rules in order: match, assign to a member, a department or a round-robin; a test box |
| `admin-fields` | `/admin/fields` | the custom fields per record kind: key, label, type, options, pipelines, required to win |
| `admin-tags` | `/admin/tags` | the tag vocabulary with usage: rename, merge, delete |
| `admin-mailbox` | `/admin/mailbox` | the logging mailbox: address, provider, webhook health, the last poll, the failures, the pending count; the secret's env key |
| `admin-quotas` | `/admin/quotas` | the quota per seller per month, a grid of the next twelve months (params: `year`) |
| `admin-settings` | `/admin/settings` | the business's currency, fiscal year, default pipeline, rotting, weighted method, forecast months, duplicates policy, reminders, tombstones, imports, the API's rates, restricted records, the ICS windows, the email footer |
| `admin-tokens` | `/admin/tokens` | every key and token: whose, label, rates, calls today, last used; lead-capture keys for the website's form; revoke |
| `admin-purge` | `/admin/purge` | the tombstones: what waits, when it purges; purge now |

Actions (base `/admin/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `pipeline_save` | `pipelines/save.php` | **name**, key (a slug — made from the name when omitted), currency, rotting_days (1 to 365 or blank), is_default (yes or no), position, archived (yes or no), pipeline (to change an existing one) | restore prior | | other | `pipeline.save` | pipelines.manage | yes | the pipeline |
| `pipeline_delete` | `pipelines/delete.php` | **pipeline** (only one with no deals — else archive it) | — | ✔ | deletion | `pipeline.delete` | pipelines.manage | yes | the pipeline |
| `stage_save` | `stages/save.php` | **pipeline**, **name**, probability (0 to 100), kind (open or won or lost), rotting_days, required_fields[] (deal columns or custom keys), ask_assistant (yes or no), colour (a hex), wip_limit, position, archived (yes or no), stage (to change an existing one) | restore prior | | other | `stage.save` | pipelines.manage | yes | the stage |
| `stage_reorder` | `stages/reorder.php` | **pipeline**, **stages[]** (the open stages in their new order) | restore prior | | other | `stage.reorder` | pipelines.manage | yes | the pipeline |
| `stage_delete` | `stages/delete.php` | **stage** (only one with no deals — else archive it) | — | ✔ | deletion | `stage.delete` | pipelines.manage | yes | the stage |
| `source_save` | `sources/save.php` | **name**, kind (inbound or outbound), active (yes or no), sort_order, source (to change an existing one) | restore prior | | | `source.save` | sources.manage | yes | the source |
| `source_delete` | `sources/delete.php` | **source** (only one no record names — else deactivate it) | — | ✔ | deletion | `source.delete` | sources.manage | yes | the source |
| `lost_reason_save` | `reasons/save.php` | **kind** (lead or deal), **label**, active (yes or no), sort_order, lost_reason (to change an existing one) | restore prior | | | `reason.save` | sources.manage | yes | the reason |
| `lost_reason_delete` | `reasons/delete.php` | **lost_reason** (only one no record names) | — | ✔ | deletion | `reason.delete` | sources.manage | yes | the reason |
| `assignment_rules_save` | `rules/save.php` | **rules[]** (the whole ordered list — each row a name plus match plus assign_kind plus member or department or members) | restore prior | | other | `rules.save` | assignment.rules | yes | the rules |
| `custom_field_save` | `fields/save.php` | **applies_to** (lead or account or contact or deal or activity), **key** (a slug), **label**, **field_type** (text or number or date or select or multi_select or checkbox or url or email or money or member), options[], pipelines[] (deals — shown for these pipelines only), required_to_win (yes or no), sort_order, archived (yes or no), field (to change an existing one) | restore prior | | other | `field.save` | fields.manage | yes | the field |
| `tag_save` | `tags/save.php` | **tag**, **label** (renamed — the slug follows) | restore prior | | | `tag.save` | tags.manage | yes | the tag |
| `tag_merge` | `tags/merge.php` | **tag** (the one that goes), **into** (the tag that stays) | — | ✔ | | `tag.merge` | tags.manage | yes | the surviving tag |
| `tag_delete` | `tags/delete.php` | **tag** (taken off every record) | — | ✔ | deletion | `tag.delete` | tags.manage | yes | the tag |
| `mailbox_save` | `mailbox/save.php` | **address**, **display_name**, provider (malumail or imap), webhook_secret_ref (the env key holding the secret — never the secret), signature, poll_enabled (yes or no), active (yes or no), mailbox (to change an existing one) | restore prior | | other | `mailbox.save` | mailbox.manage | people | the mailbox |
| `quota_set` | `quotas/save.php` | **member**, **month** (a month), **amount** (0 or more — blank removes it) | restore prior | | other | `quota.set` | quotas.manage | yes | the quota |
| `settings_save` | `settings/save.php` | business_name, currency (ISO 4217), fiscal_year_start_month (1 to 12), default_pipeline, rotting_days (1 to 365 or blank for off), weighted_method (stage or override_or_stage), forecast_months (1 to 24), duplicates_policy (off or warn or block_exact_email), duplicates_name_similarity (0.3 to 1), default_remind_minutes (0 to 10080), next_step_prompt (yes or no), lead_untouched_days, account_untouched_days, tombstone_days (1 to 3650), import_max_rows, import_rollback_hours, bulk_sync_rows, key_rate_per_minute, key_rate_per_day, capture_rate_per_day, allow_restricted_records (yes or no), ics_days_ahead, ics_days_back, email_footer (Markdown — the unsubscribe link is always added), timezone | restore prior | | other | `settings.save` | settings.manage | yes | the settings |
| `capture_key_mint` | `tokens/capture-mint.php` | **label** (the form it serves — up to 80 characters), **source** (the lead source the key's leads carry), rate_per_day (600 by default) | key_revoke | | other | `key.capture_mint` | tokens.manage | people | the key |
| `records_purge` | `purge.php` | kind (lead or account or contact or deal or activity — all when omitted), before (a date — tombstones older than it; the window's edge by default) | — | ✔ | deletion | `records.purge` | records.purge | yes | the count |
