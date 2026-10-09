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
