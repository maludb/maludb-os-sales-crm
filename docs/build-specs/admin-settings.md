# Build spec: admin — pipelines, vocabularies, rules, fields, tags, the mailbox, quotas, settings, tokens, purge (slice 9)

What exists at the end: the admin area's fifteen screens over the book's rules, every one a full page with the pinned Save; the pipelines and stages editor with
drag order (the enhancement over `stage_reorder`); the assignment rules with a test box; custom fields per record kind; the tag vocabulary's rename, merge and
delete; the mailbox's row and health; quotas as a grid; the settings; every key and token; the tombstones and the purge.

Schema (db/005–009, 011, 013, 017, never modified): `crm_settings` (db/005); `lead_sources`, `lost_reasons`, `tags`, `custom_field_defs`, `crm_validate_custom_values()` (db/006);
`pipelines`, `stages`, `quotas` (db/008); `assignment_rules`, `crm_assign_lead()` (db/009); `mailboxes` (db/011); `crm_tombstones_purge()` (db/013); `mcp_settings`, `mcp_tombstones`,
`mcp_tokens`, `mcp_assignment_rules`, `mcp_custom_fields` (db/016).

## Divergences from the design
1. **`assignment_rules_save` takes the whole ordered list** (one form, one transaction: delete and re-insert) — rows are few. 2. **`tag_delete` is a hard delete** that takes the tag off every
   record (the counts follow); `tag_merge` moves the rows. 3. **A pipeline or a stage with deals is archived, never deleted** (`pipeline_save` / `stage_save` with `archived`); delete refuses.

## Who may do what
pipeline_*, stage_*: `pipelines.manage` (save/reorder pause as `other`; delete as `deletion`) · source_*, lost_reason_*: `sources.manage` · assignment_rules_save: `assignment.rules` · custom_field_save:
`fields.manage` · tag_save/merge/delete: `tags.manage` · mailbox_save: `mailbox.manage`, people · quota_set: `quotas.manage` · settings_save: `settings.manage` · capture_key_mint: `tokens.manage`, people ·
records_purge: `records.purge`.

## Screens
`admin` `/admin` · `admin-pipelines` `/admin/pipelines` (each pipeline a card with its stages as a sortable list — probability, rotting, required fields chips, the Assistant flag, WIP; Add stage;
Edit; archived below) · `admin-pipeline-add` / `admin-pipeline-edit` · `admin-stage-add` `/admin/pipelines/{id}/stages/new` · `admin-stage-edit` `/admin/stages/{id}/edit` (required fields as a
multi-chip input over deal columns and the deal custom fields) · `admin-sources` `/admin/sources` (two lists: sources, lost reasons by kind; inline add) · `admin-rules` `/admin/rules` (the ordered
rules as rows with the match fields, the assign kind and target; Add; the test box: a lead's fields → the rule that matches, `crm_assign_lead` dry) · `admin-fields` `/admin/fields?kind=` ·
`admin-tags` `/admin/tags` (chips with counts; Rename · Merge into (picker) · Delete) · `admin-mailbox` `/admin/mailbox` (the row's form; the health from `mailbox_health`; the recipe of
ROOT_STEPS step 3) · `admin-quotas` `/admin/quotas?year=` (sellers × months grid, each cell an input; Save posts `quota_set` per changed cell) · `admin-settings` `/admin/settings` (the
settings grouped: the business, the pipeline, duplicates, reminders and the note, imports and bulk, the API, records) · `admin-tokens` `/admin/tokens` (every key and token; Revoke; Mint a
lead-capture key: label, source) · `admin-purge` `/admin/purge` (the tombstones by kind with purge dates; Purge now).

## Files (exactly these)
`html/admin/{index,pipelines,sources,rules,fields,tags,mailbox,quotas,settings,tokens,purge}.php` (the pages), `html/admin/pipelines/{form,save,delete}.php`, `html/admin/stages/{form,save,reorder,delete}.php`,
`html/admin/sources/{save,delete}.php`, `html/admin/reasons/{save,delete}.php`, `html/admin/rules/{save,test}.php` (test: a GET partial), `html/admin/fields/save.php`, `html/admin/tags/{save,merge,delete}.php`,
`html/admin/mailbox/save.php`, `html/admin/quotas/save.php`, `html/admin/settings/save.php`, `html/admin/tokens/capture-mint.php`, `html/admin/purge.php` (POST = `records_purge`);
`app/features/admin/{queries,present}.php`, `app/features/pipelines/{write}.php`, `app/features/vocab/{queries,write}.php` (sources, reasons, tags), `app/features/rules/{queries,write,test}.php`,
`app/features/custom_fields/write.php`, `app/features/mailbox/{queries,write}.php`, `app/features/quotas/{queries,write}.php`, `app/features/settings/admin_write.php`, `app/features/tombstones/{queries,write}.php`;
`app/views/admin/{index,pipelines,pipeline-form,stage-form,sources,rules,fields,tags,mailbox,quotas,settings,tokens,purge}.php`, `app/views/admin/partials/{stage-row,rule-row,field-row,tag-chip,quota-grid}.php`;
`html/assets/js/stage-order.js` (SortableJS over the stage list → `stage_reorder`); `tests/phase3/slice9/`.

## Query functions (signatures fixed)
`admin_summary(PDO): array` · `pipelines_with_stages(PDO, bool $archived): array` · `vocab(PDO): array{sources, reasons}` · `rules(PDO): array` · `rule_test(PDO, array $leadFields): ?array` · `fields(PDO, string $kind): array` ·
`tags_with_counts(PDO): array` · `mailbox(PDO): ?array` · `quota_grid(PDO, int $year): array` · `settings(PDO): array` · `tokens_all(PDO): array` · `tombstones(PDO, ?string $kind): array` · writes: `pipeline_save` · `pipeline_delete` ·
`stage_save` · `stage_reorder(PDO, int $pipeline, array $stageIds)` · `stage_delete` · `source_save/delete` · `reason_save/delete` · `rules_save(PDO, array $rules)` · `field_save` · `tag_save` · `tag_merge(PDO, int $from, int $into)` ·
`tag_delete` · `mailbox_save` · `quota_set(PDO, int $member, string $month, ?string $amount)` · `settings_save(PDO, array $f)` · `capture_key_mint(PDO, string $label, int $source, ?int $perDay, int $by): array{id, raw}` · `records_purge(PDO, ?string $kind, ?string $before): int`

## MCP tools this slice makes real
`settings_catalogue`, `members_with_roles`, `tombstones`.

## Manifest rows claimed
Screens (15): `admin`, `admin-pipelines`, `admin-pipeline-add`, `admin-pipeline-edit`, `admin-stage-add`, `admin-stage-edit`, `admin-sources`, `admin-rules`, `admin-fields`, `admin-tags`, `admin-mailbox`, `admin-quotas`, `admin-settings`, `admin-tokens`, `admin-purge`
Actions (19): `pipeline_save`, `pipeline_delete`, `stage_save`, `stage_reorder`, `stage_delete`, `source_save`, `source_delete`, `lost_reason_save`, `lost_reason_delete`, `assignment_rules_save`, `custom_field_save`, `tag_save`, `tag_merge`, `tag_delete`, `mailbox_save`, `quota_set`, `settings_save`, `capture_key_mint`, `records_purge`

## Activity log events
`pipeline.save|delete`, `stage.save|reorder|delete`, `source.save|delete`, `reason.save|delete`, `rules.save`, `field.save`, `tag.save|merge|delete`, `mailbox.save` (the secret's env key name,
never a value), `quota.set`, `settings.save` (changed fields), `key.capture_mint`, `records.purge` (the count by kind).

## Status vocabulary
Stage kind: open the stage's colour, won `success`, lost `danger`; archived `dark`. Rule assign kind: member `primary`, department `info`, round_robin `warning`. Ids: `admin-cards`,
`pipelines-list`, `pipeline-{id}`, `stage-list-{pipeline}`, `stage-row-{id}`, `stage-form`, `rules-list`, `rule-row-{n}`, `rule-test`, `fields-list`, `field-form`, `tags-chips`, `mailbox-form`,
`quota-grid`, `quota-{member}-{month}`, `settings-form`, `tokens-table`, `capture-key-form`, `purge-list`.

## Out of scope for this slice
The agents' settings (8), the reports (7), the keys of a person (8 — this slice lists every key and mints capture keys only).

## Proof (`tests/phase3/slice9/run.sh`: scratch `crm_dev9`)
A pipeline added with stages, reordered by the handler, one archived (deals keep it), delete refused with deals; a stage's required fields chosen from the deal columns and custom keys and
refusing a move in the board's handler; sources and reasons added, deactivated, delete refused when named; the rules saved and the test box naming the matching rule; a custom field added
and refusing a wrong type on a record; a tag renamed, merged (rows moved and counts right), deleted; the mailbox row saved with the env key name and health shown; quotas saved per cell;
every setting saved and read back, a bad value refused with the field; a capture key minted (shown once) and listed; the purge removes the tombstones past the window; an agent's
`pipeline_save` pauses; JSON mode; 375 and 1280; the registry `--check` (15 screens, 19 actions, 14 approvals).

## Open questions
None.

## Built and proven (2026-10-11)
**Screens (15) and actions (19), and the last two worker passes — PHASE 3 IS COMPLETE.** `/admin` — the areas as cards, each with a number in words and shown only to a holder of its right (the Sales Manager sees five, Marketing two), the logging mailbox's health and the
tombstones due as warnings; `/admin/pipelines` — every pipeline a card with its stages as a list (probability, rotting, WIP, the Assistant badge, the fields a deal must have as chips, the open deals in it), the order DRAGGED (`stage-order.js`, board.js's pattern: it posts
`stage_reorder` and swaps in the server's list; a refusal re-fetches the region so the row snaps back) with Move up / Move down buttons that are plain forms (the no-JS path), the archived pipelines below with Restore, `/admin/pipelines/new|{id}/edit` and
`/admin/pipelines/{id}/stages/new|/admin/stages/{id}/edit` (the required fields as checkbox chips over the deal's seven columns and the live deal custom fields); `/admin/sources` — sources and the lead and deal lost reasons, each row its own form with the switch and the
count of what names it, a delete that is disabled with the reason when something does, an inline add; `/admin/rules` — the ordered rules as ONE form, a blank rule to add, a Remove tick, the record picker for people and campaigns, source chips, and the TEST BOX (a GET
partial: the rule that would take a lead, who, and "nothing was assigned"); `/admin/fields?kind=` — the five kinds as tabs, a field's row and the add/change form beside it; `/admin/tags` — a card per tag with its count, Rename · Merge into (the record picker, then the
confirm) · Delete, a search; `/admin/mailbox` — the form (the address, the provider, the NAME of the secret's env key and whether that key is set, the signature, polling, on/off), the health card, the recipe of ROOT_STEPS step 3; `/admin/quotas?year=` — sellers × twelve
months, each cell an input, the year's total; `/admin/settings` — 25 settings in seven groups; `/admin/tokens` — every key and token with its kind, owner, source, limits, calls today, last use and state, Revoke on the live ones, and the lead-capture key form;
`/admin/purge` — the tombstones by kind (due / waiting), the list with the date each is purged, Purge now. The actions: `pipeline_save`, `pipeline_delete`, `stage_save`, `stage_reorder`, `stage_delete`, `source_save`, `source_delete`, `lost_reason_save`,
`lost_reason_delete`, `assignment_rules_save`, `custom_field_save`, `tag_save`, `tag_merge`, `tag_delete`, `mailbox_save`, `quota_set`, `settings_save`, `capture_key_mint`, `records_purge`. The worker's `tombstones_purge` (the SAME `crm_tombstones_purge_kinds()` the
screen calls, the stored files removed after the commit, one `records.purge` from cron with the count by kind) and `key_usage_prune` (`crm_prune_key_usage()`, db/005's window: minute buckets older than a day, day buckets older than 35 days) are real —
`bin/worker.php` holds no `'stub' => true` and no controller calls `render_nav_stub`. The Phase 4 reads this makes real as PHP functions: `settings_catalogue()`, `members_with_roles()`, `tombstones()`. **The registry reads 84 of 84 screens and 121 of 121 actions built.**
Of this slice's 19, FIFTEEN pause for an agent (the spec's "14" is short by one; the manifest's rows are the contract): `pipeline_save`, `stage_save`, `stage_reorder`, `assignment_rules_save`, `custom_field_save`, `mailbox_save`, `quota_set`, `settings_save`, `capture_key_mint` as `other`;
`pipeline_delete`, `stage_delete`, `source_delete`, `lost_reason_delete`, `tag_delete`, `records_purge` as `deletion`; `source_save`, `lost_reason_save`, `tag_save`, `tag_merge` are free. `mailbox_save` and `capture_key_mint` are people-only.

**Proven** by `tests/phase3/slice9/run.sh` on a fresh `crm_dev9` — pipelines 71, vocab 44, rules 40, fields 36, mailbox 30, quotas 29, settings 25, tokens 36, purge 38, json 117, browser 67 (533 checks under php -S and under a real Apache), the schema proof (173), the server log and the registry; slices 1–8, Phase 2, Phase 0 and Phase 1 re-run green under both servers (the slice 6 and 8 and Phase 2 proofs that asserted slice 9 unbuilt now follow whether it is built). Pipelines: archive, delete, the order by handler, HTMX and button, a required field refusing slice 3's move, the default as two facts. Vocab: delete refused by name, a tag renamed (slug, search, a saved view's filter), merged (counts), deleted. Rules: the list in one transaction, the dry function against the real one on every kind, nothing assigned. Fields, mailbox (the secret planted and grepped for), quotas, settings (25 bad values each naming its field), tokens (shown once, no hash anywhere), purge (a tombstone inside the window survives; children and files go), the worker's two passes, the registry at 84/84 and 121/121.

**Found and fixed:** (1) db/024: `crm_validate_custom_values()` (db/006) looked a key up among the LIVE definitions only, so archiving a custom field made every record that held a value for it impossible to save (a form sends the record's whole `custom_values` back) — archived keys stay valid, the same
typed checks. (2) `deal_guard()` (slice 3) asked the stage refusal for a custom field's LABEL (a query) inside the aborted transaction, so a stage that required a custom key answered 500 "current transaction is aborted" instead of the 422 naming the field — it rolls back first now; the
slice 9 proof is the first to require a custom key. (3) The layout's navigation map lit no menu item for `admin-*` screens after an HTMX navigation (slice 8's agents screen had the same defect): every `admin-*` screen keeps Admin highlighted. (4) `.main-content` is
`overflow: hidden/auto` in the template, which made it the scroller a sticky bar sticks inside — it never scrolls, so a pinned Save never pinned; the admin forms' `.main-content` is `overflow-x: clip` (a sideways guard without being a scroll container). (5) The tag picker admitted
`tags.apply` only; a holder of `tags.manage` alone (Marketing has both, a custom role may not) now may pick the merge target.

**Decisions taken here (within the spec):**
- **Tags.** Slice 1's `app/features/tags/` keeps the one tag query layer; the admin's `tag_save` / `tag_merge` / `tag_delete` are appended to its `write.php`, the list with counts is `admin/queries.php`'s `tags_with_counts()`. `app/features/vocab/{queries,write}.php` holds only sources and reasons.
  A rename also re-indexes the records (the search finds them by the new name) and makes a saved view's or smart list's `tag` filter follow it; a merge does the same to the survivor's name.
- **Custom fields.** `custom_field_save` writes `custom_field_defs`; slice 1's readers (`custom_field_defs()`, `mcp_custom_fields`) pick the change up on the next render. A key and a record kind never change; a field with values keeps its type; a deal field cannot take the name of a
  deal column (a stage's required fields name both in one list); pipelines and "required to win" mean something for deals only (stored empty and off for the rest).
- **Stage rules.** A stage's required fields are the DATABASE's (db/008's trigger): the admin only chooses them, and slice 3's `deal_move` refuses a move naming them (proved). Other rules in PHP, in words: a stage with deals keeps its kind; Won stays at 100 and Lost at 0; one Won and one Lost per pipeline
  (a new pipeline is made WITH both); Won and Lost ask for nothing; the Assistant is asked on open stages only; a pipeline or stage with deals is archived, never deleted — a stage a deal ever PASSED THROUGH (the funnel reads `deal_stage_history`) counts; one holding OPEN deals is not archived either; the default
  pipeline and the last live one cannot be archived or deleted; the default moves as two facts (`pipelines.is_default` and `crm_settings.default_pipeline_id`); a changed probability reaches the open deals in the stage (a deal's own override stays).
- **The order.** `stage_reorder` takes the open, live stages — every one, once — and renumbers 10, 20, 30 …; Won (90) and Lost (99) keep theirs. From the screen (`on` = list, over HTMX) the answer is the pipeline's stage list; otherwise it redirects. The drag has the Move up / Move down forms beside it.
- **Assignment rules.** `rules[]` is the whole list; a row with an `id` keeps its round-robin turn, a row without is new, one left out (or ticked Remove) is deleted; positions are renumbered by the list's order (a row's `position` only orders ties). The TEST BOX asks `crm_assign_lead_dry()` (db/024, STABLE) — it
  cannot write, names the NEXT person of a round-robin without moving the turn, and the proof compares it with `crm_assign_lead()` inside a rolled-back transaction on every kind. No lead is made and nothing is logged by a test.
- **The mailbox.** `webhook_secret_ref` is the NAME of an env key; a text that is not shaped like one (capital letters, digits, underscores, an underscore in it) is refused, so a pasted secret is never stored, printed or logged (the proof plants the real secret and greps every page, JSON and log row for it).
  The page says whether that key is set in the environment (yes or no, nothing else). The application logs through ONE mailbox: a save without an id changes it; a new address clears the poll position and the old failure. The signature is logged as "(changed)", never its text.
- **Settings.** `SETTINGS_FIELDS` (`settings/admin_write.php`) is the whole catalogue — group, kind, bounds, label, help — so the form, the request reader and the JSON agree; 25 settings. Not here: the Assistant's note and proposals (slice 8's agents screen), the key rotation overlap, the attachment ceiling and the storage directory (the install's).
  The email footer's text is logged as "(changed)". Every value is checked with its field named; the schema's CHECKs are the last word.
- **Quotas.** A seller is a human holding Sales Rep, Sales Manager or CRM admin; the grid posts every cell as `cells[member][YYYY-MM]` and only the cells that differ are written and logged (`quota.set` each, with before and after); a single cell (`member`, `month`, `amount`) is the agent's door. A bad cell refuses the whole grid.
- **Keys.** `capture_key_mint` is slice 8's `key_mint()` with `lead_source_id` set (a new optional parameter) — the minter's own, shown once on the next screen, only the hash kept. Capture keys count apart from a person's ten and are capped at 50 live. The list reads `mcp_tokens` (db/016) — never a hash; Revoke
  posts to slice 8's `key_revoke` with `return_to=/admin/tokens` (the handler honours a local return path now); an MCP token has no Revoke here (it is its owner's on My settings → Tokens).
- **The purge.** `crm_tombstones_purge_kinds(kind, before, limit)` (db/024) answers the count by kind; `before` can only narrow the cut-off to an older edge, never into the window, so a tombstone inside it survives every call (proved for all five kinds). `crm_tombstones_purge(integer)` is the same
  function's total (db/013's signature kept). An account that still has a contact or a deal is kept past the window. The stored files of purged records are deleted AFTER the commit (the rows go with the record; a file another row names stays). Activities are listed on the screen from their own table (`mcp_tombstones` leaves them out).
- **Where things live.** `admin/queries.php` holds the reads of the area and the shared constants; `tombstones/`, `mailbox/`, `quotas/`, `vocab/`, `rules/` their own queries and writes as the spec says; the extras are `admin/present.php` (the JSON whitelists), `views/admin/partials/{stage-list,rule-test}.php`, and `rules/queries.php` holds the rule constants.
- Not built: nothing of the spec. The three Phase 4 tools (`settings_catalogue`, `members_with_roles`, `tombstones`) exist as PHP reads; their Python servers are Phase 4's.
