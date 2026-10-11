# Build spec: agents, proposals, notifications, K7, the API and keys (slice 8)

What exists at the end: the proposals screen with one-click Accept; the Assistant's and Expert's write doors (`next_step_propose`, `lead_qualify_propose`,
`duplicate_propose`, `notification`); the morning note's delivery (the note itself is the agent's duty through the kernel; this slice gives it the tools and
the inbox); the outbox pass sending email (MaluMail) and texts (K6) and the dispatch pass's chat turns; the five K7 shares and the two K7 reads through the
bridge; the records API with keys and the lead-capture route; the admin's agents screen. Phase 4 builds the MCP servers themselves; this slice builds every
PHP they call and the API.

Schema (db/013, 017, 003, 005, never modified): `assistant_proposals`, `crm_proposal_accept()`, `agent_dispatches`, `notifications`, `notification_outbox`, `crm_notify()` (db/013); the
`crm_share_*` functions (db/017); `mcp_access_tokens` + `key_usage`, `crm_resolve_key()`, `crm_rate_ok()`, `crm_key_rotate()` (db/003, 005); `mcp_assistant_proposals`, `mcp_agent_dispatches`,
`mcp_tokens` (db/016).

## Divergences from the design
1. **`sms_send` exists and is refused with `no_sender` until the kernel's K28 lands** (texting a non-member) — the action is registered so the approval policy exists on day one.
2. **The morning note is composed by the Assistant (the agent) in its duty run**, not by PHP: this slice gives it `notification` and the kernel's `message_send`; PHP's part is the
   `morning_note` notification kind, its mail body (slice 5's `emails/morning-note.php`) and the home's strip.
3. **The two K7 reads the application consumes** (`gl.customer_by_crm_account`, `helpdesk.tickets_by_organization`) are called by PHP (`app/api/kernel.php`'s `k7_read()`), cached 5
   minutes per account, and answer `no_connection` when the connection is not approved; the bridge exposes them to the records server.

## Who may do what
proposal_accept / proposal_dismiss: `for_member_id` or a manager · next_step_propose: `activities.write` (an agent or a person) · lead_qualify_propose: `leads.write` · duplicate_propose:
`records.read` · notification: `activities.write` (an agent to a seller; a manager to anyone) · sms_send: `email.send` + `see`, pauses · dispatch_cancel, agents_settings_save: `agents.settings`,
people · key_mint / key_rotate / key_revoke: own (`key_revoke` any with `tokens.manage`), people; `key_mint` pauses as `other`.

## Screens
`proposals` `/proposals?kind=&status=&who=` (cards: the kind's icon, the title, the deal or lead, the payload rendered — a next step's kind, moment and subject; a qualification's score and
reasons; a duplicate's two sides; a draft's subject — Accept · Dismiss; a manager's `who`) · `admin-agents` `/admin/agents` (the agents from `members_with_roles` agents_only with their
last dispatch, the dispatches table with status and Cancel, the settings form `agents_settings_save`, the ask-the-Assistant stages).

## The API (`html/api/v1/*.php`, `app/api/bootstrap.php` — the kit's gate)
`api_authenticate()` resolves a key as its person (`crm_resolve_key`), sets `app.member_id`, `crm_rate_ok` → 429 with `Retry-After`; **a capture key** (`lead_source_id` set) admits only
`POST /api/v1/leads` (`leads.php`: the fields of `leads-and-conversion.md` §6, the honeypot, `lead_create` as the key's owner with source = the key's; 600/day) and answers 403 `capture_key`
elsewhere. Routes: `GET /api/v1/{leads,accounts,contacts,deals,activities}` (= the `find_*` filters, paged), `GET /api/v1/{kind}/{id}` (= `get_*`), `POST /api/v1/{kind}` (= `*_create`), `PATCH
/api/v1/{kind}/{id}` (= `*_update` through `app/partial_update.php`), `POST /api/v1/activities` (= `activity_create`); every call logged with source `api` and `token_id`; CORS from
`API_CORS_ORIGINS`. `GET /api/v1/calendar.ics` is slice 4's.

## The bridge (`html/internal/bridge.php`, loopback, `X-CRM-Bridge`)
Ops: `k7_read` (the two reads as the member — the wall on the account checked first), `share_accounts_index`, `share_contacts_index`, `share_deals_won`, `share_deals_open_by_account`,
`share_account_timeline_summary` (the db/017 functions as the application with the consumer's key; `share.read` logged), `notify` (the records server never writes; an agent's `notification`
action goes through the Actions MCP — the bridge's `notify` is for the worker's dispatch replies). Any other op 404.

## The worker (`bin/worker.php`'s real passes)
`outbox`: queued `notification_outbox` rows → email through `malumail_send()` (the kind's body view), texts through the kernel's `POST /api/v1/notify/sms.php` (K6; `no_sender` → skipped with
the detail), three attempts with backoff, `sent_at`; `dispatches`: `agent_dispatches` sent → one chat turn on the kernel's chat endpoint as the acting member (`kind` → the opening sentence:
a lead to qualify, a deal that entered a stage, a reminder for an agent), the reply's first 200 characters kept, three attempts; `proposals`: the standing proposals the worker can make
without a model — a `duplicate` per new `crm_duplicates_open` pair for the manager of the owner's department, a `rotting` per deal newly rotting for its owner (once per deal per stage).

## Files (exactly these)
`html/proposals/{index,accept,dismiss,propose,propose-lead,propose-duplicate}.php`, `html/notify/send.php`, `html/sms/send.php`, `html/admin/agents/{index,save,dispatch-cancel}.php`,
`html/settings/tokens/{key-mint,key-rotate,key-revoke}.php` (the shell's stubs made real), `html/api/v1/{leads,accounts,contacts,deals,activities}.php`, `html/internal/bridge.php`;
`app/features/proposals/{queries,present,write}.php`, `app/features/agents/{queries,write,dispatch}.php`, `app/features/notify/{write,send}.php`, `app/features/keys/{queries,write}.php`,
`app/api/{routes,kernel}.php` (`k7_read()`, `kernel_send_text()`, `kernel_chat_turn()`), `app/features/shares/queries.php`; `app/views/proposals/{index}.php` + `partials/{card,payload-*}.php`,
`app/views/admin/agents.php`, `app/views/settings/partials/keys.php`; `bin/worker.php`'s real `outbox`, `dispatches`, `proposals`; `tests/phase3/slice8/`.

## Query functions (signatures fixed)
`find_proposals(PDO, array $f, int $page)` · `proposal_render(array $p): array` · `find_dispatches(PDO, array $f)` · `agents_here(PDO): array` · `find_keys(PDO, ?int $member)` · `k7_read(string $app, string $tool, array $args, int $accountId): array` ·
`share_*(string $consumer, array $args): array` (five) · writes: `proposal_accept(PDO, int $id, int $by): ?int` · `proposal_dismiss` · `propose(PDO, string $kind, array $f, int $by): int` · `notify(PDO, int $member, string $kind, string $title, ?string $body, array $links): int` ·
`sms_send(PDO, int $contact, string $body, int $by): array` · `dispatch_cancel` · `agents_settings_save` · `key_mint(PDO, int $member, string $label, ?int $perMin, ?int $perDay, ?int $days): array{id, raw}` · `key_rotate` · `key_revoke` ·
the worker: `worker_pass_outbox`, `worker_pass_dispatches`, `worker_pass_proposals`

## MCP tools this slice makes real
`assistant_proposals`, `agent_dispatches`, `account_tickets` (the K7 read through the bridge), the five shares `accounts_index`, `contacts_index`, `deals_won`, `deals_open_by_account`,
`account_timeline_summary`, `find_keys` (the resolver), `key_usage_summary`, and the activity server's `actor_history`, `agent_activity`, `activity_search`.

## Manifest rows claimed
Screens (2): `proposals`, `admin-agents`
Actions (12): `proposal_accept`, `proposal_dismiss`, `next_step_propose`, `lead_qualify_propose`, `duplicate_propose`, `notification`, `sms_send`, `dispatch_cancel`, `agents_settings_save`, `key_mint`, `key_rotate`, `key_revoke`

## Activity log events
`proposal.accept|dismiss|next_step|lead_qualify|duplicate`, `notification.send`, `sms.send` (refused `no_sender` until K28), `dispatch.send|answered|failed|cancel`, `agents_settings.save`,
`key.mint|rotate|revoke`, `share.read` (`{application, tool, rows, ms}`), `k7.read` (`{app, tool, outcome, ms}`), `outbox.send|skip|fail`, `lead.create` with source `api` (the capture route).

## Status vocabulary
Proposal: proposed `warning`, accepted `success`, dismissed `dark`, expired `secondary`. Dispatch: sent `primary`, answered `success`, refused/failed `danger`, awaiting_approval `warning`.
Ids: `proposals-list`, `proposal-{id}`, `proposal-{id}-accept`, `agents-table`, `dispatches-table`, `dispatch-{id}`, `agents-settings-form`, `keys-list`, `key-{id}`.

## Out of scope for this slice
The MCP servers (Phase 4), the morning note's text (the Assistant's, through the kernel), texting a contact for real (K28).

## Proof (`tests/phase3/slice8/run.sh`: scratch `crm_dev8`; the fake kernel answers chat turns, run-facts, K7 reads, K6 texts; the fake MaluMail)
A next-step proposal accepted makes the activity and the deal has a step; a qualification sets the score; a dismissed pair is not re-proposed; the outbox sends an email and a text, skips
`no_sender`, retries thrice; a dispatch runs a chat turn against the fake kernel and keeps the excerpt; the five shares answer their documents for a consumer and refuse without the header,
never a restricted account, never a body; `k7_read` caches and degrades; the API as a person lists and creates with the wall; a capture key creates a lead and reads nothing; rates 429; a
`sms_send` refuses `no_sender`; an agent's `key_mint` pauses; JSON mode; 375 and 1280; the registry `--check` (2 screens, 12 actions, 4 approvals).

## Open questions
None.

## Built and proven (2026-10-11)
**Screens (2) and actions (12), the doors and the worker.** `/proposals` — the Assistant's proposals as cards (the kind's icon and words, the title, the deal or lead as a link only when the viewer's wall lets them open it, the payload said in plain lines in
their zone: a next step's kind, moment and subject and what to say, a qualification's score, reasons and suggested owner, a duplicate's two sides as links and what matched), Waiting by default with Accepted, Dismissed and Everything and a kind
filter, a manager's `who` (everyone's or one person's; a Rep's is forced to "me"), Accept (a duplicate's reads "Review the pair" and sends the manager to the duplicates screen) and Dismiss as plain form buttons that HTMX upgrades; `/admin/agents` —
the agents admitted here with their last dispatch, what waits and what was answered this week, the dispatches table (status in words and tone, the kind in words, the excerpt, who it acted for, Cancel on a waiting one) and the Assistant's
settings form (the morning note and its hour, proposals on or off, the days a lead or an account may sit, the open stages that ask the Assistant); people only, `agents.settings`. The actions: `proposal_accept` / `proposal_dismiss` (the person it is
for, or a manager; a next step becomes THE DECIDER'S activity, source `proposal`, linked back; a qualification sets the score and moves a new lead to working; what the kind changes is judged by the walls as the decider), `next_step_propose`,
`lead_qualify_propose`, `duplicate_propose`, `notification`, `sms_send` (refused `no_sender`), `dispatch_cancel`, `agents_settings_save`, `key_mint`, `key_rotate`, `key_revoke` (the shell's stubs made real; the Tokens screen gained its keys card and
the one-time value). The records API (`html/api/v1/{leads,accounts,contacts,deals,activities}.php` over `app/api/routes.php`): a Bearer key only — NO cookie is read, NO session is started and none is set (`CRM_API` makes `app/bootstrap.php` skip it),
one 401 body for every failure, 429 with Retry-After, CORS from `API_CORS_ORIGINS` echoed exactly and never with credentials, `api.call` logged per call with the key's id; the list and the one record are the screens' own JSON, a create and a change the
`*_save` handlers run as the key's person with the JSON body as their form (so the rights, the walls, the schema's refusals and the log are the browser's); an agent's key is refused a write; a LEAD-CAPTURE key reaches only `POST /api/v1/leads`
(`api_capture_lead()`: the capped fields, the source the key's, the honeypot `website_url`, `{ok, lead_id}` and nothing else, a `lead_qualify` dispatch for the Assistant) and answers 403 `capture_key` anywhere else. The bridge
(`html/internal/bridge.php`, loopback + `X-CRM-Bridge`, ops `k7_read`, the five `share_*` and `notify`, any other op 404) and `app/features/shares/queries.php`. `k7_read()` is real (the kernel's `apps/read.php`, cached five minutes per provider, tool and arguments
in `k7_cache`, degraded to `no_connection` / `unavailable`, `k7.read` logged) and the account page's Tickets tab and ledger panel use `k7_account_read()` (the wall first). The worker: the `outbox` pass sends the `notification_outbox` rows (email
through MaluMail with `render_mail()`, texts through K6 with `no_sender` and the other refusals skipped with the code in `detail`, three attempts after 1 and 4 minutes), `dispatches` (one chat turn per dispatch, first 200 characters kept, three attempts,
a refusal final, a running run asked after, a paused run awaiting approval) and `proposals` (a `duplicate` per new pair for the manager of the owner's department, a `rotting` per deal per stage). The registry reads 69 screens and 102 actions built
(this slice's 2 and 12; THREE paused — `sms_send`, `agents_settings_save`, `key_mint` — the spec's "4 approvals" counted one that is not in the manifest's slice-8 section).

**Proven** by `tests/phase3/slice8/run.sh` on a fresh `crm_dev8`, under php -S and a real Apache (the proof's public name answers 404 under /internal/ and the bridge lives on the internal port) — keys 43, api 60, capture 51, shares 60, k7 31,
proposals 83, notify 44, outbox 35, agents 45, json 77, browser 62 (591 checks), the schema proof (173) and the log and the registry. Keys: minted once, only the hash stored, limits lowered never raised, ten live at most, rotation's overlap, a capture key
staying a capture key, revoke by the owner or `tokens.manage`, an agent's `key_mint` paused through the registry and refused, no key or hash in any log row. API: the same 401 for none, unknown, garbage, an MCP token and a session cookie; no Set-Cookie on any
answer; preflights; 429s; Priya's key does not list Marco's restricted account; the screen's JSON byte for byte; creates and PATCHes as the person with the screen's own refusals; an id in the body names nothing. Capture: the body cannot choose the source,
owner, status or score; the honeypot; every cap; 413; every other route 403; day limit counting honeypot hits; the dispatch. Shares and bridge: unsigned, wrong, stale, altered and prefix-less signatures all 401, forwarded headers ignored, `bridge_verify()`
refusing non-loopback, every op off the list 404, a share without a consumer (or one smuggled in the arguments) 403, each document's exact fields, nothing restricted, no body and no note, `share.read` logged. K7: cache, degradation, the wall first, the
account page's panels. Proposals, notices, texts, the outbox, dispatches and the agents' screen as the Proof lists. Earlier suites re-run green (the slice 6 and Phase 2 proofs that asserted slice 8 unbuilt now follow whether it is built).

**Found and fixed (db/023):** (1) `crm_key_rotate()` copied a key's person, label and limits but NOT `lead_source_id` or the expiry — rotating a lead-capture key made a full key acting as the admin, and a rotated key forgot its own expiry; the
replacement copies both. (2) `crm_share_accounts_index()` returned the account's own counters, which are kept over every deal, so a shared account carried the number and value of the deals RESTRICTED to someone; counted now from the deals a share may
show. (3) `crm_share_account_timeline_summary()`'s trailing ORDER BY/LIMIT applied to the whole union: with more open activities than the limit it returned no past touch and the farthest open one as the "next step"; now the last N done touches and the
nearest open one. (4) The existing API gate accepted a session cookie and started a PHP session (with a file) on every call, and `api_cors` answered a preflight through JSON mode as a 501; the API has no session now and a preflight is a 204.

**Decisions taken here (within the spec):**
- `k7_read(PDO $pdo, string $provider, string $tool, array $arguments)` keeps its signature and callers; the wall is `k7_account_read($pdo, $provider, $tool, $accountId, $extra)` (the spec's signature in effect), used by the account page and the bridge's `k7_read` op
  (`read` = `account_tickets` | `account_ledger`; a provider or tool named in the arguments is ignored). A refusal and an answer cache five minutes, a failing provider 30 seconds. Migration db/023 adds `k7_cache` (no migration existed for it).
- Keys are `crm_` + 48 hex (an MCP token is `mcp_`); a person may lower the settings' limits but only a holder of `tokens.manage` raises them; at most ten live keys per person; a Rep's rotate is their own only, `key_revoke` is the owner's or `tokens.manage`'s
  (another person's key answers 404). Capture keys are minted in slice 9's admin; the proofs make them in SQL.
- The API: JSON null clears a field, JSON booleans are 1/0; `POST /api/v1/{kind}/{id}` is 405 (change with PATCH); `PATCH` without an id is 405; `id`, the record's own name and `_partial`/`return_to` in a body are ignored; bodies are capped at 1 MB (32 kB on the
  capture route). `api.call` is logged for authenticated calls only (the web server's log has the rest).
- The capture route answers `{ok, lead_id}` as the design says (the id shows volume to anyone holding the website's key; a reference would need a schema change). A filled honeypot answers `{ok: true}` with no `lead_id` and logs `lead.capture_trap`.
- A dispatch's `cancelled` state is `refused` with the detail "Cancelled by NAME" (the reused table's status check has no `cancelled`; shown in words as Cancelled). `attempts` counts the attempt being made (default 1): three tries, retries after 1 and 4 minutes.
  The acting person is the row's, else the lead's or deal's owner (a person), else the row is refused. A lead from the capture route wakes the Assistant (the agent whose name says "assistant", db/013's convention) when proposals are on.
- Who decides a proposal about a record with no owner, and a duplicate: the manager of the owner's department (`departments.manager_member_id`) holding the Sales Manager or admin role, else the first such manager. `next_step_propose` refuses a deal that is closed, has a next step
  or has a proposal waiting; every `*_propose` refuses while the Assistant's proposals are off; a duplicate pair already proposed in any status, or set aside as not a duplicate, is refused (and the worker never makes it again).
- The bridge's `notify` is an AGENT's door only (a person uses the action), refused under an evaluation run; the recipient rules are the action's. A morning note is once a day per person (the outbox's dedupe key). A notice's links must be visible to the SENDER.
- Added beyond the list: the "Proposals" menu item (Me group); `app/features/proposals/write.php` holds the worker's `standing_proposals_run()` and `agents/dispatch.php` its `dispatches_run()`; the Apache vhost answers 404 under `/internal/`;
  `tests/fake_kernel.php` answers `/api/v1/apps/read.php` (approved, unapproved, failing, busy) and a chat run's state.
- Not built: a Python server (Phase 4); texting a contact (K28); the capture key's admin screen (slice 9).
