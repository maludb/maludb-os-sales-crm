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
