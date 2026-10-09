# Build spec: Phase 2 — sign-on, the mirror, the shell (before any slice)

What exists at the end: a person clicks Sales CRM on the launcher — or types `crm.<domain>` on a phone — and lands, signed in, on a home that
is finished though the book is empty: **one button for a new person ("Add your first lead")**, the *Today* strip, the bell, the command bar,
the one search box, the application switcher, the bottom tab bar; their notification preferences exist before they look; the mirror refreshes
every minute; every request is logged; the command bar answers through the kernel; the record picker is in the shell for every later form.
**The kit is in the repo already (Phase 0) and proven without a kernel (`tests/phase0/run.sh`, 71 checks)**; this phase wires the shell around
it and proves it under Apache at 375 and 1280. It is copied from DocCloud (the nearest kit, 2026-10-09); every file keeps its sibling's contract.

Schema it runs on (db/001–017, never modified): `members` (+ `is_agent`, `email_signature`), `departments`, `department_members`, `directory_sync_state`,
`sso_nonces`, `member_sessions` (db/001); `activity_log` (+ `lead_id`, `account_id`, `contact_id`, `deal_id`, `token_id`) (db/002); `mcp_access_tokens` (+ the
rate columns, `lead_source_id`) + `mcp_resolve_token()` (db/003); `crm_rights`, `crm_roles`, `crm_role_rights`, `crm_has_right()`, `crm_is_admin()`,
`crm_is_manager()`, `crm_is_viewer()`, `crm_is_member_here()`, `mcp_app_roles` (db/004); `crm_settings`, `crm_setting_*()`, `crm_resolve_key()`,
`crm_rate_ok()`, `crm_key_rotate()` (db/005); `notifications`, `notification_prefs`, `notification_outbox`, `crm_notify()`, `worker_passes` (db/013);
`search_index`, `crm_search()` (db/014); `crm_my_activities()`, `crm_calendar()` (db/017); the `mcp_*` views (db/016).

## Files
**In the repo (proven by `tests/phase0/run.sh`):** `app/{bootstrap,db,http,directory,auth,handler,activity,mail,partial_update}.php`, `app/api/{bootstrap,kernel}.php`,
`bin/{directory_sync,dev_handoff,mint_mcp_token,make_brand_images,worker}.php`, `bin/dev_directory.json`, `mcp/{activity_ingest.py,db.py,server_common.py,requirements.txt}`,
`tests/{dev_router,fake_kernel,fake_maludb,fake_malumail}.php`, `tests/setup_dev.sh`, `tests/phase0/`.
**Phase 2 adds:**
- `app/features/shell/{nav,queries}.php` (`NAV_ITEMS`: Today, Leads, Accounts, Contacts, Pipeline, Calendar, Activities, Email, Campaigns, Lists, Reports, Admin —
  each with the right that shows it and `NAV_SLICES` naming who builds its placeholder; `bell_count()`; `inbox_count()`; `business_name()`),
  `app/features/home/queries.php` (`home_summary()`), `app/features/settings/{queries,present,write}.php`, `app/features/search/{queries,present}.php`,
  `app/features/activity/queries.php`, `app/features/assistant/queries.php`, `app/switcher.php` (the kernel's `GET /api/v1/apps/mine.php`, cached 5 min).
- `app/picker.php` (`picker_field()`, `picker_source()`, `picker_label()`), **`app/pickers.php` EMPTY** (each slice adds its sources), `html/pick/index.php`,
  `shared/picker-rows.php`, `assets/js/record-picker.js`, the one `#record-picker` modal in `layout.php` after `</main>`.
- Views: `layout.php` (the nxl shell, the header with the search box, the switcher, the bell, the command bar; the bottom tab bar at 375: Today, Pipeline, Calendar,
  Log (a sheet of the quick-log links), More), `shared/{header,flash,notice,message,tab-bar,timeline,assistant-bar,assistant-reply,picker-rows,app-switcher,page-header}.php`,
  `home/{index,first-run}.php` + `partials/{today,closing,flags,replies,note}.php`, `search/index.php` + `partials/result.php`, `settings/{index,tokens}.php` +
  `partials/{prefs,signature,feed}.php`, `notifications/{page.php,partials/bell.php}`, `activity/{page.php,partials/rows.php}`, `sso/refused.php`,
  `nav-stub.php` (the placeholder every slice's screen renders until built: the screen's name, the slice that builds it, 501 for JSON and POST).
- Handlers: `html/{index,search,logout,notifications,activity}.php`, `html/notifications/{read,read-all}.php`, `html/settings/{index,prefs,signature,feed,tokens}.php`,
  `html/settings/tokens/{mint,revoke,key-mint,key-rotate,key-revoke}.php` (the key handlers are slice 8's to finish; the files exist as stubs here),
  `html/assistant/ask.php`, `html/pick/index.php`, `html/sso.php`, `html/sso/logout.php`, `html/api/v1/health.php`, the placeholders.
- `deploy/apache-sales_crm.conf` and `tests/dev_router.php` carry every rewrite of the manifest's URL forms.

## Sales CRM is not DocCloud
No libraries, no blobs, no graph: the shell has no storage of its own but `storage/` for attachments, imports and exports (ROOT_STEPS 2b). The
sidebar is the book's areas; **the search box is the one search** (`crm_search`, the four kinds, highlights); **the Today strip** on the home is
`crm_my_activities('today')` + `deals_at_risk` counts; a manager's home adds the forecast tile (`crm_forecast` this month, the team) and the leaderboard's
top three. The first-run home (no lead, no account, no deal the person may see) is one button. `notification_prefs` is created on sign-on and by the
directory sync for every new member (not a personal library). The team (department) is a filter, never a wall.

## The receiver (`/sso`) — in order
As DocCloud's: verify the two signatures, the TTL, the audience (`APP_KEY = sales_crm`), the single-use nonce; mirror the member and departments from the
claims; refuse an unknown or ungranted member (403, logged `member.sign_on.refused` with the reason, never told); open the hardened session; ensure
`notification_prefs`; log `member.sign_on` with the roles; 302 to `/` (or the `next` the launcher carried, same-origin only).

## Every request
`app/bootstrap.php`: the session, the member re-checked against the mirror (revoked → the launcher within a minute), `app.member_id` on the PDO connection,
the action-token / relay path for the kernel's actions server, `json_mode_begin()`, `log_screen_view()` on GET screens.

## MCP tools this slice names (Phase 4 serves them; the shell's screens read the same SQL)
`app_roles`, `my_notifications`, `get_settings`, `search` (the one box IS the records server's), `find_members`, `find_departments` (the pickers and the
switcher's names), `records_search` (the guarded long tail), `activity_query` (the activity server's).

## Query functions (signatures fixed)
- `home_summary(PDO, int $memberId): array` → `['today' => rows, 'overdue_count', 'closing' => ['count','value','weighted'], 'flags' => ['no_next_step','rotting'], 'replies' => rows, 'note' => ?text, 'first_run' => bool, 'manager' => ?['forecast','leaders']]`
- `bell_count(PDO, int $memberId): int` · `inbox_count(PDO): int` · `business_name(PDO): string`
- `search(PDO, string $q, array $kinds, int $page): array{rows, total}` (over `crm_search`)
- `my_prefs(PDO, int $memberId): array` · `save_prefs(PDO, int $memberId, array $fields): array` · `save_signature(PDO, int $memberId, string $text): void`
- `find_my_notifications(PDO, int $memberId, bool $unreadOnly, int $limit, int $page): array` · `mark_notifications_read(PDO, int $memberId, ?int $id): int`
- `find_my_tokens(PDO, int $memberId): array` · `mint_token(PDO, int $memberId, string $label, string $scope): array` · `revoke_token(PDO, int $memberId, int $id): bool`
- `find_my_activity(PDO, int $memberId, int $page, array $filters): array` · `activity_sentence(array $row): string`
- `ask_assistant(PDO, int $memberId, string $utterance, array $context): array`

## Handlers (built)
`settings/prefs.php` (`prefs_save`), `settings/signature.php` (`signature_save`, people), `notifications/read.php` (`notification_read`), `notifications/read-all.php`
(`notifications_read_all`), `settings/tokens/mint.php` (`token_mint`: scope `mcp` only here), `settings/tokens/revoke.php` (`token_revoke`), `logout.php` (POST + CSRF;
`member.sign_out`), `assistant/ask.php` (`assistant.ask`: the length and the screen, never the words). `settings/feed.php` (`feed_rotate`) is slice 4's.

## Manifest rows claimed
Screens (6): `home`, `search`, `notifications`, `activity`, `settings`, `settings-tokens`
Actions (6): `prefs_save`, `signature_save`, `notification_read`, `notifications_read_all`, `token_mint`, `token_revoke`

Routes, not actions: `/sso`, `/sso/logout`, `/api/v1/health`, `/pick/`, the command bar's post to the kernel's chat endpoint, the switcher's feed. The placeholders
(Leads, Accounts, Contacts, Pipeline, Calendar, Activities, Email, Campaigns, Lists, Reports, Admin) are the slices' screens whose files call `render_nav_stub()`: the
registry marks them `stub`; they are claimed by the slices that build them, never here. `feed_rotate` is slice 4's, `key_mint`/`key_rotate`/`key_revoke` slice 8's.

## Activity log events
`member.sign_on`, `member.sign_on.refused`, `member.sign_out`, `member.session_ended`, `screen.view` (every GET screen, the screen id), `settings.prefs`,
`settings.signature`, `notification.read`, `notification.read_all`, `token.mint`, `token.revoke`, `assistant.ask`, `search.keyword` (the query cut at 120, the count).

## Status vocabulary
The bell `badge text-bg-danger` with the unread count; the inbox badge `text-bg-warning`; the Today strip's chips in the four states (`overdue` red, `today` amber,
`scheduled` green, `done` muted). Ids: `header-search`, `header-user-name`, `bell`, `bell-count`, `tab-bar`, `home-today`, `home-closing`, `home-flags`, `home-first-run-btn`,
`record-picker`, `assistant-bar`.

## Out of scope for this phase
Every record screen (the slices); the worker's real passes (stubs until their slices); the API's routes but health; the keys (slice 8).

## Proof (`tests/phase2/run.sh`: scratch database `crm_dev`, php -S on 8801, the fake kernel on 8802, the fake MaluDB + MaluMail on 8803; then the same under a real Apache vhost)
Sign-on for every fixture member with the right roles and the refusals of Phase 0 re-run; the home for a new person (one button), a Rep (Today), a manager (the
forecast tile); the search box finds a fixture account and honours the wall; the bell counts and reads; prefs save and read back; a token minted once and revoked;
the switcher renders the fake kernel's applications; every placeholder answers 200 to a person, 501 to JSON; the command bar's post reaches the fake kernel's chat
endpoint and renders the reply; 375 (the tab bar, no horizontal scroll) and 1280; no console errors; the installer's `plan` clean.

## Open questions
None.

## Built and proven (2026-10-09)
The shell stands: a person signs on through the kernel's hand-off and lands on **Today** (`crm_my_activities('today')` overdue first, the chips in the
four states with the word printed beside the colour; my deals closing this month — value and weighted, another currency counted apart; the two
flags as links to the board filtered; replies since yesterday on my deals and to my contacts; the Sales Assistant's morning note when there is one;
for `reports.team` the team's forecast tile and the leaderboard's top three — who won something). A new person on the empty book sees ONE button,
Add your first lead (a plain link until slice 2's form ships); a Viewer is told the book is empty. The sidebar is `nav_groups()` — one menu table,
every item gated by its right (Email `email.log`, Lists `lists.own`, Admin any admin area's right); the Leads item carries the lead inbox's badge; the
header carries the business name, the highest role as a badge (Super-admin › CRM admin › Sales Manager › Marketing › Sales Rep › Viewer), THE ONE
SEARCH BOX (a magnifier at 375), the bell, the switcher (K31) and the account menu. The phone's tab bar is Today · Pipeline (the default pipeline's
board by its key) · Calendar · Log (a dropup of the five quick-log links, each greyed with its slice until built — never a modal, never a link to a
404) · More (the sidebar). Search (`/search`, `crm_search`): grouped by kind, the words marked, a misspelling found, the wall the function's, every
search logged `search.keyword` with the query cut at 120 and the count. My settings: how I am told (the fifteen kinds), my reminders (the default
lead time, the morning note and its hour, the digest — blank means the business's), my signature, my calendar link (slice 4 makes it), my time zone.
Tokens (an expiry in days), notifications (read one, read all), My activity in words. Eleven placeholders and four action stubs (`key_*` slice 8,
`feed_rotate` slice 4) call `render_nav_stub()`; the registry reads 6 screens and 6 actions built, 11 stubs.

**Proven** by `tests/phase2/run.sh`: **271 checks under php -S, 276 under a real Apache** serving the rendered vhost with the internal port
(firstrun 15 on the empty book, browser 75 at 375 × 740, 1280 × 800 and JavaScript off, gates 97 over six roles, guard 6, vhost 44/49, picker 22,
switcher 12, the registry) and the schema proof (173) on the scratch database; Phase 0's kit (71) and Phase 1's claims (55) re-run green; the
installer's plan clean (57 steps, 9 notes).

**Found and fixed in Phase 2:**
- **db/018** — `mcp_activity_log` was granted to the activity server's role only; My activity (and slice 1's History tab) read it as the application.
- **The write guard read only P0001**: the schema raises its refusals as `check_violation` (34 of them, a stage's required fields among them),
  `unique_violation` (an address another contact holds), `foreign_key_violation` and `no_data_found` — each would have answered 500. `crm_guard()` now
  answers 422 (404 for no_data_found) with the schema's sentence, and `db_message()` never shows PostgreSQL's own constraint text (`tests/phase2/guard.php`).
  Inventory fixed the check_violation half the same day; its other codes, and DocCloud's guard, are worth the same look.
- **mod_headers** was not enabled on this host and the vhost's `Header` lines would fail Apache's config test at apply: they are guarded by
  `<IfModule mod_headers.c>` and `deploy/ROOT_STEPS.sh` gains **step 0b** (`a2enmod rewrite proxy proxy_http headers`).
- Kit leftovers from the siblings: the session cookie was Inventory's (`INVSID` → `CRMSID`), `is_dc_admin()` → `is_crm_admin()`, the kernel bridge's
  refresh events named Inventory's entities, the web manifest named Inventory, the brand images were Inventory's package (now a target, drawn by
  `bin/make_brand_images.php`), the dev router and the internal vhost carried DocCloud's `/d/` `/f/` `/upload/` doors, `tests/setup_dev.sh` wrote
  DocCloud's env keys (now a scratch storage directory and the mailbox's webhook secret).
- Phase 0's rate-rule check failed when a run crossed a minute boundary; it now makes 61 calls in one statement and accepts either side.
- The fake kernel answers K31's `GET /api/v1/apps/mine.php` from its state, so the switcher is proven through the header, not only from a fixture.

**Decisions taken here** (within the spec): `notification_prefs` is made on sign-on and by every sync for every admitted member, person or agent; the
receiver honours a same-origin `next`; the health check reports the storage directory without failing on it (slices 1, 2 and 6 write there);
`prefs_save` admits an agent (its own preferences), `signature_save` and the tokens are people's.
