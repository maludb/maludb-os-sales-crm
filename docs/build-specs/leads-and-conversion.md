# Build spec: leads and the conversion (design §6.1; D3, D12) — read before slice 2

A lead is one row until a seller converts it into the three records of the relationship. This spec fixes the lead's life, the inbox,
the rules, the conversion form, the import and the duplicates so slice 2 builds them once. The database is the referee
(`crm_assign_lead`, `crm_lead_convert`, the read-only rule after conversion, `crm_duplicates_open`, `crm_records_merge` — db/009, db/007,
db/013, db/017); PHP renders, uploads, batches and logs.

## 1. The lead's life
`new` (created by a seller, Marketing, the form's key, the mailbox's proposal accepted, an import) → `working` (any touch: a note, an
activity, `lead_qualify`) → `qualified` (= converted, read-only, pointing at the three) | `unqualified` (a reason of kind `lead`, a note;
`lead_requalify` brings it back to `working`). A **proposed** lead (`is_proposed`: from the mailbox's stranger or the form before the
rules ran) sits in the inbox until `lead_accept_proposed` (into the book, the rules assign it) or `lead_dismiss_proposed` (a tombstone).
**The owner** is set on insert by `crm_assign_lead` from the rules when none is given (member · department's manager · round-robin in
turn), else NULL = the inbox's "unowned" list; `lead_assign` changes it (a seller may take an unowned lead; `leads.assign` gives any).

## 2. The inbox (`/leads/inbox`)
Two lists on one page: **Proposed** (from the form and the mailbox, newest first: the sender, the subject or message, Accept · Dismiss)
and **Unowned** (Assign to… the `member` picker, or Take). A bulk bar: select several → `lead_assign` with `leads[]`. The count is the
sidebar's badge (`leads_inbox`).

## 3. The conversion form (`/leads/{id}/convert`, `lead_convert`)
The GET shows what the function will do, before it does it (`find_accounts` by the address's domain then by the company's name;
`find_contacts` by email): **Account** — "Found: Acme Industries (acme.example)" with a switch to "Make a new one" or the `account`
picker; or "New: Globex Corp" with the name editable; **Contact** — "Found: Dana Reyes" or "New: Jordan Lee <jordan.lee@…>"; **Deal** —
a checkbox "Make a deal" (on), the `pipeline` picker (the default), the title (prefilled "<account> — new business"), value, currency
(the pipeline's), close date. POST → `crm_lead_convert(lead, account?, contact?, make_deal, pipeline?, title?, value?, close?)`; the answer
names the three (`record_id` = the deal, else the contact, else the account; `made: {account, contact}`); the page lands on the deal (or
the contact). The command bar's expert runs the same function from a sentence (the `convert-a-lead-into-a-deal` runbook). A converted
lead's page shows the three as cards and no edit button; `lead_update` on it is 422 "A converted lead is read-only".

## 4. The import (`/import/new` → `import_start` → `/import/{id}` → `import_run` → `import_rollback`)
- **Upload**: a CSV (UTF-8 or Latin-1 sniffed, `,` or `;` sniffed, a header row), ≤ `import_max_rows` (50,000; `CRM_MAX_IMPORT_ROWS`
  may only lower), stored under `storage/imports/{id}.csv`; the first 20 rows previewed.
- **Mapping**: a column per CSV header → a field of the kind (`leads_csv`: first_name, last_name, company, title, email, phone, website,
  city, region, country, source, campaign, message, owner_email, tags; `contacts_csv`: the contact's + account_name, account_domain;
  `accounts_csv`: the account's; `deals_csv`: title, account_name, contact_email, pipeline, stage, value, currency, close_date, owner_email)
  or `custom:<key>` or "skip"; a **template** (`import_templates`) saves and prefills it; the owner (`owner_email` column, else the form's
  owner, else the caller); the source; a static list every created record joins.
- **Dedupe** by the kind's key (lead: email; contact: email then phone; account: domain then name; deal: title + account): `skip` (the row
  is skipped and says which record it matched), `update` (fills the matched record's empty fields), `create` (always a new record).
- **Dry run first** (`dry_run = yes`, the default): the worker's `imports` pass runs the batches of 500 inside a transaction it ROLLS BACK,
  writing `import_rows` with what WOULD happen; the page shows the counts and the rows; **Run** (`import_run`) queues the real run (the same
  code, committed, `rows_made` etc. counted, `rollback_until = now() + import_rollback_hours`); **Roll back** (`import_rollback`, within the
  window) tombstones every `created` row's record and reverts nothing else (updated rows keep their values — the log says so).
- The page polls `/import/{id}/status` every 3 s while `running`.

## 5. Duplicates (`/duplicates?kind=`, `/duplicates/merge?kind=&a=&b=`)
`crm_duplicates_open(kind)` → pairs grouped by `matched_on` (email, domain, phone, name) with the matching field highlighted on both
sides; a pair → **Merge** (the merge page: the two records in two columns, every differing field, radio buttons choosing the survivor — the
older by default; the merge takes the survivor's values and the loser's where empty, so the choice is of the SURVIVOR only) → `records_merge`
(`records.merge`; pauses for an agent) or **Not a duplicate** → `duplicate_dismiss` (`duplicate_dismissals`). The warning on a record's page
("Possible duplicate of …") is the same read for that record. The Assistant's `duplicate_propose` lands in the manager's proposals (slice 8).

## 6. The website's form (the lead-capture key)
`POST /api/v1/leads` with a capture key (`mcp_access_tokens.lead_source_id` set): `first_name`, `last_name`, `email`, `company`, `phone`,
`message`, `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`; the source fixed by the key; honeypot field `website_url`
(non-empty → 200 and nothing made); 600/day (`capture_rate_per_day`); the lead is `new` with the rules' owner (or proposed when the admin's
setting `capture_to_inbox` — a later setting; v1 runs the rules); the answer `{ok: true, lead_id}` and nothing else; logged `lead.create`
with source `api` and the key's label. Built with slice 8's API; the key is minted in slice 9's admin (`capture_key_mint`).

## 7. Files (exactly these, slice 2)
`html/leads/{index,inbox,view,form,convert,save,qualify,unqualify,requalify,assign,accept,dismiss}.php`, `html/import/{index,view,form,start,run,rollback,template-save,status}.php`,
`html/duplicates/{index,merge,dismiss}.php`; `app/features/leads/{queries,present,write}.php`, `app/features/imports/{queries,present,write,csv}.php`,
`app/features/duplicates/{queries,present,write}.php`; `app/views/leads/{index,inbox,view,form,convert}.php`,
`app/views/leads/partials/{card,inbox-row,convert-found}.php`, `app/views/import/{index,view,form}.php`, `app/views/import/partials/{mapping,rows,status}.php`,
`app/views/duplicates/{index,merge}.php`; `bin/worker.php` gains the real `imports`; `app/pickers.php` gains `lead`, `source`, `campaign`, `list`, `import_template`.

## 8. Proof (slice 2's `tests/phase3/slice2/run.sh`)
A lead from the form and from a seller; the rules route three leads three ways; a note moves new → working; the inbox lists a proposed and an
unowned lead, accept and assign empty it; the conversion form shows "Found" and "New" right for four fixtures and the POST makes exactly what it
said; a converted lead is read-only; unqualify needs a reason and requalify restores; a CSV of 2,000 rows with 30 duplicates dry-runs with the
right counts, runs, and rolls back leaving tombstones; a template prefills the mapping; the duplicates page shows the pairs, a merge moves the
children and a dismissal hides the pair; an agent's `records_merge` pauses; JSON mode; 375 and 1280; the registry `--check`.
