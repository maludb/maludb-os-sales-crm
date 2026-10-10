# Build spec: leads, conversion, the inbox, import and duplicates (slice 2)

Builds `leads-and-conversion.md` on the exemplar's pattern (slice 1). What exists at the end: leads as cards with their status, score and
source; the inbox of proposed and unowned leads with Accept, Dismiss, Assign and a bulk bar; the lead's page with the duplicate warning and
the three records once converted; the conversion form that says what it will find and make; unqualify with a reason; the CSV import with a
saved mapping, a dry run, a run and a 24-hour rollback, run by the worker in batches; the duplicates page by kind and the two-column merge.

Schema (db/009, 012, 013, 017, never modified): `leads`, `lead_sources`, `assignment_rules`, `crm_assign_lead()`, `crm_lead_convert()`, `crm_lead_label()` (db/009);
`imports`, `import_templates`, `import_rows`, `lists` (a target) (db/012); `crm_records_merge()`, `crm_record_delete()` (db/013); `duplicate_dismissals`,
`crm_duplicates_open()` (db/017); `mcp_leads`, `mcp_imports`, `mcp_import_rows`, `mcp_lead_sources`, `mcp_lost_reasons` (db/016).

## Divergences from the design
1. **Import runs in the worker** (`imports` pass), never in the request: the page polls `/import/{id}/status`; the dry run is a rolled-back transaction of the
   same code. 2. **`lead_qualify` is a status + score setter**, not a conversion (the conversion is `lead_convert`). 3. **The website form's route** (`POST
   /api/v1/leads`) is slice 8's API; this slice proves the lead it makes through `lead_create` with source `api`.

## Who may do what
| Action | Right | Wall |
|---|---|---|
| lead_create | `leads.write` | — |
| lead_update, lead_qualify, lead_unqualify, lead_requalify | `leads.write` | `edit` on the lead (a converted lead refuses) |
| lead_convert | `leads.convert` | `edit` |
| lead_assign | `leads.assign`, or `edit` (a seller takes an unowned lead) | — |
| lead_accept_proposed, lead_dismiss_proposed | `leads.write` | — (dismiss pauses for an agent) |
| import_start, import_run, import_rollback, import_template_save | `imports.own` (own imports); `imports.shared` for anyone's | people only |
| records_merge | `records.merge` | pauses for an agent |
| duplicate_dismiss | `records.merge`, or `edit` on both | — |

## Screens
`lead-list` `/leads?q=&status=&owner=&source=&campaign=&untouched_days=&tag=&view=` (cards: label, status badge, source, owner, score ring, last touched, the next step's dot);
`lead-inbox` `/leads/inbox` (Proposed · Unowned, the bulk bar); `lead-view` `/leads/{id}` (fields, score and reasons, duplicates, the three cards when converted, the
timeline; Convert · Unqualify · Assign · Log call · Schedule); `lead-add` / `lead-edit` `/leads/new` · `/leads/{id}/edit` (fields per `lead_create`; `source`, `campaign`,
`owner` pickers; custom fields); `lead-convert` `/leads/{id}/convert` (§3 of the build spec); `import-list` `/import`; `import-new` `/import/new?kind=&template=` (upload →
mapping → options → Dry run); `import-view` `/import/{id}` (counts, rows filtered by result, Run · Roll back, the status poll); `duplicates` `/duplicates?kind=`;
`merge` `/duplicates/merge?kind=&a=&b=`.

## Files (exactly these)
`html/leads/{index,inbox,view,form,convert,save,qualify,unqualify,requalify,assign,accept,dismiss}.php`, `html/import/{index,view,form,start,run,rollback,template-save,status}.php`,
`html/duplicates/{index,merge,dismiss}.php`; `app/features/leads/{queries,present,write}.php`, `app/features/imports/{queries,present,write,csv,runner}.php` (`runner.php`: the batch
code the worker and the dry run share), `app/features/duplicates/{queries,present,write}.php`; views as the build spec §7; `bin/worker.php`'s real `imports`; `app/pickers.php` gains
`lead`, `source`, `campaign` (slice 6's table; the picker reads `mcp_campaigns`), `list`, `import_template`; `tests/phase3/slice2/`.

## Query functions (signatures fixed)
`find_leads(PDO, array $filters, int $page): array` · `find_lead(PDO, int $id): ?array` · `inbox(PDO): array{proposed, unowned}` · `convert_preview(PDO, int $leadId): array{account: ?found, contact: ?found, deal_defaults}` ·
`find_imports(PDO, array $filters): array` · `find_import(PDO, int $id): ?array` · `import_rows(PDO, int $id, ?string $result, int $page): array` · `csv_sniff(string $path): array{delimiter, encoding, headers, preview}` ·
`duplicates(PDO, string $kind): array` · `merge_preview(PDO, string $kind, int $a, int $b): array` · writes: `lead_save` · `lead_qualify` · `lead_unqualify` · `lead_requalify` · `lead_convert(PDO, int $id, array $choices, int $by): array` ·
`lead_assign(PDO, array $ids, ?int $owner)` · `lead_accept(PDO, int $id, ?int $owner)` · `lead_dismiss(PDO, int $id)` · `import_start(PDO, array $f, array $file, int $by): int` · `import_run(PDO, int $id)` · `import_rollback(PDO, int $id)` ·
`import_execute(PDO, int $id, bool $dryRun): array` (the runner) · `template_save` · `records_merge(PDO, string $kind, int $s, int $l)` · `duplicate_dismiss(PDO, string $kind, int $a, int $b, int $by)`

## MCP tools this slice makes real
`find_leads` (the resolver), `get_lead`, `leads_inbox`, `leads_untouched`, `source_performance`, `find_duplicates`, `find_imports`, `get_import`, `import_rows`, `find_sources` (the resolver),
`find_lost_reasons` (the resolver).

## Manifest rows claimed
Screens (11): `lead-list`, `lead-inbox`, `lead-view`, `lead-add`, `lead-edit`, `lead-convert`, `import-list`, `import-new`, `import-view`, `duplicates`, `merge`
Actions (15): `lead_create`, `lead_update`, `lead_qualify`, `lead_unqualify`, `lead_requalify`, `lead_convert`, `lead_assign`, `lead_accept_proposed`, `lead_dismiss_proposed`, `import_start`, `import_run`, `import_rollback`, `import_template_save`, `records_merge`, `duplicate_dismiss`

## Activity log events
`lead.create|update|qualify|unqualify|requalify|convert|assign|accept|dismiss`, `import.start|run|rollback|template_save`, `records.merge`, `duplicate.dismiss`; `lead.convert` carries
`{account_id, contact_id, deal_id, account_made, contact_made}`; `import.run` the counts; a lead's message is never in a payload.

## Status vocabulary
Lead status: new `primary`, working `warning`, qualified `success`, unqualified `dark`; proposed `info` with the source (form, mailbox). Import status: queued `secondary`, running
`primary`, done `success`, failed `danger`, rolled_back `dark`. Ids: `lead-list-*`, `lead-card-{id}`, `lead-form-field-{name}`, `inbox-proposed`, `inbox-unowned`, `convert-form`,
`convert-account`, `convert-contact`, `convert-deal`, `import-form`, `import-mapping`, `import-rows`, `import-status`, `duplicates-list`, `merge-form`, `merge-survivor-{a|b}`.

## Out of scope for this slice
The capture route (8), campaigns' own screens (6), the Assistant's qualification proposals (8), the rules' admin screen (9 — the rules are proven here through fixtures).

## Proof (`tests/phase3/slice2/run.sh`: scratch `crm_dev2`; the worker run by hand for the import; Playwright)
The build spec's §8 list, plus JSON mode on every screen and handler, 375 and 1280, and the registry `--check` (11 screens built, 15 actions, 3 approvals in step).

## Open questions
None.

## Built and proven (2026-10-10)
Leads as cards (status, the score as a ring, the source, the owner, the next step) with their filters and the links to the inbox, imports and duplicates;
the inbox (Proposed — Accept · Dismiss; Unowned — Take, or select several and assign with the owner picker); a lead's page (the duplicate warning, the
three records as cards once converted, the fields and the message, qualify with a score, unqualify with a reason, requalify, assign, notes, files, the
agenda, the history); the lead form; the conversion form saying Found or New for the account and the contact before it does anything, then the deal; the
CSV import (the kind, the file, the columns, what to do with a match, the owner, the source, a list → the dry run → Run it for real → Roll back within 24
hours), run by the worker's `imports` pass; the duplicates page by kind with the matching field marked; the two-column merge; Not a duplicate. The
registry reads the 11 screens and 15 actions built, the 3 deletions (`lead_dismiss_proposed`, `import_rollback`, `records_merge`) paused for agents.

**Proven** by `tests/phase3/slice2/run.sh` on a fresh `crm_dev2`: **202 checks** and the log and the registry, under php -S and a real Apache — leads 41
(the form's lead and a seller's; the rules three ways — a member, a department's manager, round-robin in turn — and none matching leaving it unowned; a
note moving new → working; qualify; unqualify needing a lead's reason, requalify; the inbox's accept, take, assign several, dismiss; the read-only
converted lead), convert 22 (four fixtures — found by domain and by address, found by name with a new contact, all new, free mail with no company — and
the POST making exactly what the form said; the deal's title, value, close, stage; `lead.convert`'s payload), import 41 (2,000 rows with 30 duplicates:
the dry run's counts with nothing written, the run, the 24-hour window, the rollback's 1,970 tombstones; a template's mapping and policy; update filling
only empty fields; owner_email, tags and a list; three refusals in words that never sink the batch; Latin-1 with semicolons; the ceiling; the rights),
duplicates 24 (pairs only when both are visible, the highlight, the older kept by default, the merge moving notes, tags and the deal's contact, the
tombstone, leads merging, Not a duplicate for everyone, the pauses), json 33, the browser 41 (a lead added, scored and converted through the forms; the
import mapped in the browser, polled to done and run; the inbox's Accept; a merge choosing the newer; the eleven screens at 375 and 1280; JavaScript off).
Slice 1 and Phase 2 re-run green; Phase 0 and Phase 1 green.

**Found and fixed:**
- **db/019 — `crm_assign_lead()` matched a rule against a lead that lacks the field**: `(m -> 'country') @> to_jsonb(NULL)` is NULL and `CONTINUE WHEN NULL`
  does not skip, so a "Germany to Dana" rule took every lead without a country (likewise source, campaign, utm_source). A condition on a missing field is
  now not met.
- **The import's dedupe went through `mcp_leads`**, which computes every lead's label, tags and colour and cannot use the email index: a 2,000-row import
  took about 240 s a run. The runner now looks up the base tables by their indexes with `crm_wall()` checked on the candidate row (the view's own rule):
  **about 20 s a run** (10 ms a row; 50,000 rows ≈ 8 minutes in the worker).
- A view's data key named `template` collided with `view()`'s own `$template` (EXTR_SKIP keeps the function's): **never pass `template`, `data`, `path` or
  `viewsRoot` to `view()`**.

**Decisions taken here (within the spec):**
- The mapping step happens in the browser (JavaScript reads only the header row and draws a select per column, prefilled by the same words the server uses
  and by the template); without JavaScript, and for an agent, `import_start` maps each header by its own words or the template's. The manifest's
  parameters are unchanged.
- The dry run is the whole file in ONE rolled-back transaction (a later row matches an earlier one, as the run will); the real run commits batches of 500
  with their `import_rows`, so an interrupted run resumes after the last committed row. The worker logs `import.dry_run`, `import.done`, `import.failed`.
- A contacts import never makes an account: an unknown `account_name` leaves the contact without one and says so, so a rollback leaves nothing behind.
- `lead_accept_proposed` gives the owner named, else the rules', else unowned; `lead_assign` without `leads.assign` lets a seller take a lead for themselves only.
- When `crm_lead_convert()` would find a record restricted from the caller, the form says "Found: an account restricted from you" — never "New".
- `/leads/{id}/convert` and `/duplicates/merge` are the screen on GET and the action on POST; the merge derives the loser from the pair, so it works without JavaScript.
- The `list` and `import_template` picker sources read their text filter (`record_kind`, `kind`) from the query; the endpoint forwards only integer params.
- The worker's `search_catch_up` pass is real (it was slice 1's and was missed there).
- The leads list links the inbox, imports and duplicates; a touch-sized label aligns left.

**Seen, for the owner:** a lead a seller adds with no owner and no rule matching is unowned (the spec: the rules decide when the owner is omitted). The form
says so ("The assignment rules decide"), but a seller may expect their own lead to be theirs — a one-line change if the owner wants the caller as the default.
