# Build spec: accounts and contacts (slice 1 — the CRUD exemplar)

What exists at the end: the relationship's two records as cards and full pages — an account with its contacts, deals, activities, emails, notes
and files, tickets and history on anchored tabs; a contact with the same and its opt-out banner; create and edit as full pages with the record
picker for the account, the owner, the parent; notes and attachments on any record; tags; the duplicate warning as you type; the restriction a
manager sets; the tombstone and its restore. **This slice is the CRUD exemplar**: every later record screen copies its files, its pages' shape,
its handler's order and its proof.

Schema (db/006, 007, 013, 014, 016, never modified): `accounts`, `contacts`, `contact_emails`, `crm_wall()`, `crm_edit_wall()`, `crm_contact_by_address()`,
`crm_duplicates()`, `crm_touch()` (db/007); `tags`, `record_tags`, `crm_tags_of()`, `custom_field_defs`, `crm_validate_custom_values()` (db/006); `notes`,
`attachments`, `crm_record_delete()` (db/013); `search_index` triggers (db/014); `crm_timeline()`, `crm_untouched()`, `crm_upcoming_dates()` (db/015);
`mcp_accounts`, `mcp_contacts`, `mcp_contact_emails`, `mcp_notes`, `mcp_attachments`, `mcp_tags`, `mcp_custom_fields` (db/016). **The database is the
referee**: the domain's uniqueness, the primary contact, the address mirror, the duplicates policy, the custom values' types, the restriction's setting.

## Divergences from the design
1. **Delete, restore and restrict are one handler per verb across kinds** (`record_delete`, `record_restore`, `record_restrict` with `kind` + `record`) —
   design §9 named them per record; one handler keeps the rule in one place (`crm_record_delete`).
2. **Tags are one pair of handlers across kinds** (`tag_apply`, `tag_remove` with `kind`); a new label makes a tag (`tags.apply`); the vocabulary's
   rename/merge/delete are slice 9's admin.
3. **The Tickets tab** reads Help Desk through the bridge (slice 8 builds the bridge); here it renders the panel from a stubbed `k7_read()` that
   answers `no_connection` — the empty panel is the deliverable.

## Who may do what
| Action | Right | Wall |
|---|---|---|
| account_create, contact_create | `accounts.write` / `contacts.write` | — |
| account_update, contact_update, contact_set_primary, contact_opt_out, tag_apply/remove, attachment_add | the kind's write right | `edit` on the record |
| note_add | `records.read` | `see` on the record |
| note_delete, attachment_delete | own, or a manager | — |
| record_restrict | `records.restrict` | a manager |
| record_delete | `edit`; an account with deals or contacts needs a manager | pauses for an agent (`deletion`) |
| record_restore | own tombstone, or a manager | — |

## Screens (cards for the lists — the owner's rule; 375 px first)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `account-list` | `/accounts?q=&kind=&owner=&industry=&country=&tag=&untouched_days=&view=` | `RecordCard`s: name, domain, kind badge, owner avatar, city, last touched, the next step's colour dot, open deals (n · value); the filter bar; the saved views; the bulk bar (slice 6's buttons appear then) |
| `account-view` | `/accounts/{id}?tab=` | the header (name, kind, owner, Log call · Schedule · New deal · Send email · ⋯ Edit · Restrict · Delete), tabs by anchor: Overview (fields in two columns, custom fields, the ledger panel, the next step card), Contacts (cards, primary first, Add), Deals (open then closed, Add), Activities (agenda + Schedule), Emails (subjects, direction, date), Notes & files (the note form, the list, the attachment drop), Tickets (the panel), History (`crm_timeline`) |
| `account-add` / `account-edit` | `/accounts/new` · `/accounts/{id}/edit` | `page-header-form`; fields per `account_create`; the `parent_account` and `owner` pickers; the duplicate warning (`find_duplicates` by domain and name as you type — HTMX on blur); the custom fields section; a manager's Restriction section |
| `contact-list` | `/contacts?q=&account=&lifecycle=&owner=&role=&opt_out=&tag=&untouched_days=&view=` | cards: name, title, account, lifecycle badge, owner, last touched, the next step's dot, opt-out marks |
| `contact-view` | `/contacts/{id}?tab=` | the opt-out banner when opted out; the header (name, title, account, Log call · Schedule · Send email · ⋯); tabs: Overview (fields, every address, custom fields, the next step), Deals (with the role), Activities, Emails, Notes & files, History |
| `contact-add` / `contact-edit` | `/contacts/new` · `/contacts/{id}/edit` | fields per `contact_create`; the `account` picker; the duplicate warning by email (block or warn per the setting); role, primary, opt-outs, birthday; custom fields; the restriction |

## Files (exactly these)
- `html/accounts/{index,view,form,save}.php`, `html/contacts/{index,view,form,save,primary,opt-out}.php`, `html/records/{restrict,delete,restore}.php`,
  `html/notes/{add,delete}.php`, `html/attachments/{add,delete,download}.php` (download: the gate, `/attachments/{id}`), `html/tags/{apply,remove}.php`
- `app/features/accounts/{queries,present,write}.php`, `app/features/contacts/{queries,present,write}.php`, `app/features/records/{queries,write}.php` (restrict,
  delete, restore, the duplicate check), `app/features/notes/{queries,write}.php`, `app/features/attachments/{queries,write,store}.php` (the canonical store's rules:
  finfo, 25 MB, `storage/attachments/<kind>/<id>/<sha12>-<name>`, never inline for executables and HTML), `app/features/tags/{queries,write}.php`,
  `app/features/timeline/{queries,present}.php`, `app/features/custom_fields/{queries,form}.php` (render and read the custom fields section for any kind)
- `app/views/accounts/{index,view,form}.php`, `app/views/accounts/partials/{card,header,tab-overview,tab-contacts,tab-deals,tab-activities,tab-emails,tab-notes,tab-tickets,tab-history}.php`,
  `app/views/contacts/{index,view,form}.php`, `app/views/contacts/partials/{card,header,opt-out-banner,tabs…}.php`, `app/views/shared/{notes-panel,attachments-panel,tag-editor,timeline,duplicate-warning,custom-fields,restriction}.php`
- `app/pickers.php` gains `account`, `contact` (with `account` as a declared param), `owner` (members with a Sales role), `tag`
- `assets/js/tag-editor.js`; `tests/phase3/slice1/{run.sh,lib.php,accounts.php,contacts.php,records.php,notes.php,json.php,browser.mjs}`

## Query functions (signatures fixed)
- `find_accounts(PDO, array $filters, int $page): array{rows, total}` · `find_account(PDO, int $id): ?array` · `account_summary(PDO, int $id): array` (counts, primary, next step)
- `find_contacts(PDO, array $filters, int $page): array` · `find_contact(PDO, int $id): ?array` · `contact_addresses(PDO, int $id): array`
- `accounts_for_pick(PDO, string $q, array $params, int $page): array` · `contacts_for_pick(…)` · `members_for_pick(…)` · `tags_for_pick(…)`
- `record_duplicates(PDO, string $kind, array $fields): array` (the warning as you type) · `timeline(PDO, string $kind, int $id, int $limit, ?string $before): array`
- writes: `account_save(PDO, ?int $id, array $f, int $by): int` · `contact_save(…)` · `contact_set_primary(PDO, int $id)` · `contact_opt_out(PDO, int $id, string $channel, ?string $reason, bool $undo)` ·
  `record_restrict(PDO, string $kind, int $id, ?int $member, ?int $dept, bool $lift)` · `record_delete(PDO, string $kind, int $id)` · `record_restore(…)` · `note_add(PDO, string $kind, int $id, string $body, int $by): int` ·
  `note_delete(PDO, int $id)` · `attachment_add(PDO, string $kind, int $id, array $file, int $by): int` · `attachment_delete(PDO, int $id)` · `tag_apply(PDO, string $kind, array $ids, string $tag): array{applied, skipped}` · `tag_remove(…)`

## MCP tools this slice makes real
`find_accounts` (the resolver), `get_account` (the ledger panel through the bridge: slice 8 wires it; the no-connection answer here), `account_contacts`, `account_deals`,
`accounts_untouched`, `find_contacts` (the resolver), `get_contact`, `contacts_untouched`, `upcoming_dates`, `find_notes`, `find_attachments`, `find_tags`, `timeline`,
`record_history` (the records server's row).

## Manifest rows claimed
Screens (8): `account-list`, `account-view`, `account-add`, `account-edit`, `contact-list`, `contact-view`, `contact-add`, `contact-edit`
Actions (15): `account_create`, `account_update`, `contact_create`, `contact_update`, `contact_set_primary`, `contact_opt_out`, `record_restrict`, `record_delete`, `record_restore`, `note_add`, `note_delete`, `attachment_add`, `attachment_delete`, `tag_apply`, `tag_remove`

## Activity log events
`account.create|update`, `contact.create|update|primary|opt_out`, `record.restrict|delete|restore`, `note.add|delete`, `attachment.add|delete|download`, `tag.apply|remove`.
Every row carries `account_id` / `contact_id`; a note's text and an attachment's path are never in a payload.

## Status vocabulary
Account kind: prospect `secondary`, customer `success`, partner `info`, former `dark`, other `light`. Lifecycle: lead `secondary`, prospect `primary`, customer `success`,
churned `dark`. Opt-out: `badge text-bg-danger` "No email" / "No texts". Restricted: a lock icon in the header. Ids: `account-list-*`, `account-card-{id}`, `account-form-field-{name}`,
`account-tab-{name}`, `contact-*` likewise, `notes-panel`, `note-{id}`, `attachments-panel`, `attachment-{id}`, `tag-editor`, `duplicate-warning`, `restriction-section`.

## Out of scope for this slice
Leads (2), deals and the board (3), activities (4), email compose (5), bulk actions and lists (6), the bridge's real K7 reads (8), the tag vocabulary's admin (9).

## Proof (`tests/phase3/slice1/run.sh`: scratch database `crm_dev1`; ports as Phase 2; Playwright; the registry `--check`)
1. An account made with a website gets its domain; a second on the same domain is refused with the field named; the parent picker shows only live accounts.
2. A contact made at an account is primary; a second is not; set-primary swaps; the address mirror holds every address.
3. The duplicate warning fires by domain, by name and by email; `block_exact_email` refuses, `warn` lets through.
4. Custom fields render per kind and refuse a wrong type with the field named.
5. Notes touch the record; an attachment of 25 MB is stored under the canonical path and streamed as attachment, an HTML file never inline; a 26 MB file refused.
6. Tags apply, remove, count; a new label makes a tag; bulk apply to three.
7. A restriction by a manager hides the account from another seller's list, page, search and timeline; the owner and the department still see it; a Rep's restrict is 403.
8. Delete tombstones (out of the lists; the account's counters follow), restore brings back; an agent's `record_delete` pauses; an account with deals needs a manager.
9. The tabs: every tab renders from its own partial; the Tickets panel shows the no-connection state; the History tab is `crm_timeline`.
10. JSON mode on every screen and handler; the eight screens at 375 and 1280 in the browser with no console errors.
11. Registry `--check`: 8 screens built, 15 actions, 3 approvals in step.

## Open questions
None.

## Built and proven (2026-10-09)
The relationship's two records stand: accounts and contacts as cards with their filters (text by likeness, kind or lifecycle, owner, industry, role,
opt-out, tag, last touch, a saved view), full pages with tabs by anchor (account: Overview · Contacts · Deals · Activities · Emails · Notes & files ·
Tickets · History; contact: the opt-out banner, Overview with every address · Deals with the role · Activities · Emails · Notes & files · History),
create and edit as full pages with the record picker for the parent, the account and the owner, the duplicate warning on blur, the custom fields per
kind, and a manager's Restriction section; notes, attachments and tags on any record; restrict, delete (the tombstone) and restore across kinds.
The registry reads the 8 screens and 15 actions built, the 3 deletions paused for agents.

**Proven** by `tests/phase3/slice1/run.sh` on a fresh `crm_dev1`: **234 checks under php -S and the same under a real Apache** — accounts 35 (the
domain derived and unique, named on its field; the parent picker's live accounts and the cycle refused; the kind by hand; custom fields typed and
refused naming the field; a field left out kept; the edit rule naming the owner), contacts 34 (primary and the swap; the address mirror; placement
by the address's domain, never free mail; the duplicate warning by email, name and domain; `block_exact_email` refusing on `email` naming the holder;
the opt-out, its banner and its undo), records 33 (the restriction hiding the account from another seller's list, page, search, timeline, notes and
picker while the owner, the team named and the managers see it; the setting off; the tombstone, the counters, restore by its owner, an account with
deals needing a manager; the deletion approvals in `maludb-os.json`), notes 40 (a note touches; author or manager deletes; 25 MB stored at
`attachments/<kind>/<id>/<sha12>-<name>` and streamed as a download; 26 MB, a program and an empty file refused; HTML never inline, an image inline
when asked; an agent's base64 attachment; tags made, counted, applied to three, refused whole on one record the caller may not edit), json 49 (every
screen and handler in JSON, the eight and six tabs, the Tickets and ledger panels' no-connection state, History = `crm_timeline`, the HTMX stamps, the
registry), the browser 41 (the form with the picker and the warning, tabs by click, by `?tab=` and by anchor, a contact added from the tab, the tag
editor, the banner, the eight screens at 375 and 1280, JavaScript off), the server log clean, the registry `--check`. Phase 2's suite re-run green
(271 / 276), Phase 0 (173 + 71), Phase 1 (55); the installer's plan clean.

**Decisions taken here (within the spec):**
- Two shared partials beyond the file list — `shared/record-agenda.php` and `shared/record-emails.php` — serve both kinds' Activities and Emails tabs and
  are the ones deals (slice 3) and leads (slice 2) reuse.
- The duplicate warning is the form controller answering `?dupes=1` (Pattern A, nothing logged), not a new file.
- Every tab pane is rendered by the server; `?tab=` chooses the active one (whole with JavaScript off); an anchor (`#account-tab-notes`) opens its pane
  through the layout's one script, on load and after an HTMX navigation.
- The header's Log call · Schedule · New deal · Send email appear only once their slice's controller exists — never a link to a 404.
- The owner picker reads `mcp_members` (people and agents holding a role here); the kit's `members_for_pick()` took the picker contract.
- A restriction with nobody named goes to the record's owner; restoring is for whoever deleted it, its owner, or a manager.
- Saving a contact as primary clears the account's other primary in the same transaction (one primary per account is the database's index).
- An agent attaches with `file_base64` + `filename` (the actions server posts forms, not multipart).
- `k7_read()` is a stub in `app/api/kernel.php` answering `no_connection`; slice 8 makes it real.
- The kit gained `crm_text()`, `crm_email()`, `crm_choice()`; the header at 375 px hides the dark-mode toggle below 576 px and caps the role badge (the
  Sales Manager's badge pushed the avatar off the edge).
- Phase 2's proofs now follow the placeholders still owed (read from the files), so they stay green as each slice lands.

**Seen, not changed:** a primary contact who is deleted leaves the account without a primary until someone sets one — the trigger promotes only on a
live contact's insert or update. The spec is silent; worth a word in slice 2's merge work, which touches the same trigger's ground.
