# Sales CRM — MCP tool surface

2026-10-09 · **Phase 1, for the checkpoint.** The read tools that answer every question in `docs/sales-crm-design.md` §7 (Q1–Q46), the five
K7 shares, and the resolvers the kernel's actions server names. Writes are never here: they are the action tools the kernel's Actions MCP
builds from `docs/sales-crm-action-manifest.md`. **No tool reaches a contact and no tool returns an email's body or a note's text to a
caller who may not read it** (D4, D5).

Conventions (`mcp-and-api.md`): Python 3 + FastMCP (`mcp>=1.2,<2`), streamable HTTP at `/mcp`, bound to `127.0.0.1`, one systemd unit each
(`sales_crm-records-mcp`, `sales_crm-activity-mcp`); `mcp/db.py` and `mcp/server_common.py` (already here, the kit's). Every query runs
through `db.fetch_scoped()` with `app.member_id` and `app.role` set **transaction-locally** from the verified bearer, so the `mcp_*` views
(db/016) and the read functions (db/007–015, 017) decide the rows and answer as the caller. Tool names are snake_case. Every tool takes one
`params` object (Pydantic v2, `extra="forbid"`, `str_strip_whitespace=True`, a described `Field` on every field). Every list tool takes
`limit` (1–100, default 25) and, where it pages, `offset` (0–10,000). Every tool carries `readOnlyHint: true` and `openWorldHint: false`;
every result is a JSON string (`db.to_json`). Ids are integers, times ISO 8601 with the zone, money as a string with two decimals plus a
`currency`. **The wall is per row** (`crm_wall()` inlined in every view and function): no tool asks for the caller's whole visible set.

**Errors are answers, not exceptions.** A tool that cannot answer returns `{"ok": false, "error": {"code", "message", "retry": bool}}`.
It never raises, except for the run-facts gate's own refusal, which is the contract's ToolError. Codes:

| Code | Means |
|---|---|
| `not_found` | no such record, **or one the caller may not see**: the same words, so a tool never confirms that a restricted record exists |
| `managers_only` | the record is readable, but this tool is a manager's (another seller's agenda, the team calendar, the leaderboard, the tombstones) |
| `invalid_argument` | a parameter failed validation; `fields` names it |
| `ambiguous` | a name matched several records; `candidates[]` (≤ 6, id + label) so the agent asks one question |
| `no_connection` | a K7 read the application depends on (the ledger's customer, Help Desk's tickets) has no approved connection; the tool answers what it has and says so |
| `bridge_unavailable` | the PHP side of the bridge did not answer (Apache down); `retry: true` |
| `too_large` | the answer would pass the tool's byte cap (an email body over 1 MB, a timeline over 500 rows): ask for less |

Gates are what the view or function already enforces. The tool only trims and chooses the error. Words used below (db/004, db/007, db/016,
design §3):

| Word | Means |
|---|---|
| `see` | `crm_wall()` is true for the caller: the record is open (not restricted), or they own it, or it is restricted to them or their department, or they are a manager |
| `edit` | `crm_edit_wall()`: the owner, an unowned record (with the kind's write right), a manager |
| `own` | the caller's own: their agenda, notifications, tokens, saved views, drafts, trail |
| `insider` | `crm_is_member_here()`: admitted (any role, agents included) |
| `manager` | `crm_is_manager()`: `deals.any` or the admin — anyone's records, the team's reads, the tombstones |
| `admin` | `crm_is_admin()`: the super-admin, or a human holding `settings.manage` |
| `kernel` | the kernel's own token (`kernel.{exp}.{app}.{nonce}.{hmac}`): `app_roles` and the five shares, nothing else |

**Reach is the record's, never the role's alone.** A right is a capability; whether it applies to this record is the wall of db/007. **An
agent is a member**: the same functions, no special case. **A contact is nobody here**: nothing of the application is theirs to call.

## The two servers

| | Records | Activity |
|---|---|---|
| FastMCP name | `sales_crm_records_mcp` | `sales_crm_activity_mcp` |
| Port | `MCP_RECORDS_PORT` (pinned 8843) | `MCP_ACTIVITY_PORT` (pinned 8844) |
| Role / env | `sales_crm_records_ro` | `sales_crm_activity_ro` |
| Sees | every `mcp_*` view but `mcp_activity_log`; the db/007–015, 017 read functions granted to it; `mcp_resolve_token`, `mcp_admit_agent`, `mcp_member_kind` | `mcp_activity_log` + `mcp_members`, `mcp_departments`, `mcp_leads`, `mcp_accounts`, `mcp_contacts`, `mcp_deals`, `mcp_activities` (names beside a row), `crm_timeline()` |
| Public path | `crm.<domain>/mcp/records` | `/mcp/activity` |
| Kernel registry endpoint name | `Records MCP` | `Activity MCP` |

**Who may call: three token shapes, one middleware** (`server_common.make_app`, the kit's).

1. **A person's own token** (`mcp_` + 48 hex, scope `mcp`, shown once, sha256 kept): `mcp_resolve_token(hash, 'mcp')`, active and
   admitted. **A key (scope `api`) is refused here** (401): keys are for the records API. Never tool-filtered; its rows are the views'.
2. **The tenant's signed tokens** (`ACTION_TOKEN_KEY`): the person's `{mid}.{exp}.{hmac}` or the agent run token `{mid}.{exp}.{run}.{hmac}`.
   The member id must be in the mirror and admitted; **an unknown id is refused, never created**. For a run token the server asks
   the kernel's run-facts (`POST {OS_INTERNAL_URL}/api/v1/runs/facts.php`, bearer `OS_APPLICATION_TOKEN`), caches 300 s, and offers
   exactly the tools named for `Records MCP` / `Activity MCP`; a vouched agent is admitted on first contact (`mcp_admit_agent`).
   **Fail closed** when the kernel is unreachable or `valid: false`. **`is_eval`:** these servers never write.
3. **The kernel's own token** (`verify_kernel_token`, 60 s, bound to `APP_KEY = sales_crm`): `KERNEL_TOOLS = {app_roles, accounts_index,
   contacts_index, deals_won, deals_open_by_account, account_timeline_summary}`, all `KERNEL_ONLY` but `app_roles`. No member identity is
   set; the shares answer through the db/017 `crm_share_*` functions as the application, with the consumer's key from `X-OS-Consumer` (K26).

**Identity on every query.** `app.member_id` = the resolved member, `app.role` = `members.business_role`, transaction-local. The
server never filters rows in Python by who is asking: the views and functions do. Names resolve to ids through the same views.

### The bridge (how a Python tool reaches PHP-only reads)
The read servers never send mail, never touch a draft, never call the kernel for a K7 read. Two reads live in PHP and are reached
through the internal port only: the account's **tickets** (`helpdesk.tickets_by_organization`) and the ledger's **customer** (`gl.customer_by_crm_account`)
— PHP holds `OS_APPLICATION_TOKEN` and calls the kernel's `POST /api/v1/apps/read.php`; the tool posts
`POST http://127.0.0.1:{APP_INTERNAL_PORT}/internal/bridge.php` with `X-CRM-Bridge: {unix_ts}.{hmac_sha256(ACTIONS_RELAY_KEY, "crm-bridge:" + ts + "." + sha256(body))}`
and `{"op": "k7_read", "caller": {"member_id"}, "args": {"app", "tool", "account_id"}}`; PHP refuses unless loopback, ±30 s, the HMAC verifies and the
member may see the account; `no_connection` is an answer. Built by slice 8; the Python side (`mcp/crm_bridge.py`) built by Phase 4.

## Records server: `sales_crm_records_mcp`

### Roles (kernel db/145)
`app_roles` takes no arguments. It answers `os.app-roles/1`: `{"schema", "application": "sales_crm", "roles": [{key, name, description,
capability, is_admin, rights[]}], "rights": [{key, description}]}` from `mcp_app_roles` and `crm_rights` (db/004): 5 roles, 41 rights.
Callers: `kernel` and anyone admitted.

### Leads and prospecting (Q1–Q6)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `find_leads` | Which leads: by status, owner, source, campaign, untouched days, tag, text. `q` alone resolves a lead by name or company (the registry's `lead` resolver) | Q1 | `mcp_leads`; `crm_lead_label()` | `q?` (≤ 100: name, company or email ILIKE), `status?[]` (new, working, qualified, unqualified), `owner?` (member, or `me`, or `none`), `source?`, `campaign?`, `untouched_days?`, `tag?`, `proposed?` (bool: the inbox), `sort?` (created, touched, score), `limit`, `offset` | rows `{lead_id, label, first_name, last_name, company, email, phone, status, score, source, owner_member_id, last_touched_at, next_activity_at, next_colour, is_proposed, created_at}`, `total` | see (the view) |
| `get_lead` | One lead in full: its fields, score, the three records it became, its duplicates | Q2 | `mcp_leads`, `crm_duplicates_open('lead')` for its pairs, `mcp_notes` | `lead` (id or name) | `{lead (the view's row), converted: {account_id, contact_id, deal_id}, duplicates: [{other_id, matched_on}], notes_count, tags}` | see |
| `leads_inbox` | What waits in the inbox: unowned leads and the proposed ones from the form and the mailbox | Q1 | `mcp_leads WHERE is_proposed OR owner_member_id IS NULL` | `limit`, `offset` | rows as `find_leads` + `proposed_from` (form, mailbox) | `leads.write` |
| `leads_untouched` | Leads nobody touched for N days (the morning note) | Q1 | `crm_leads_untouched(days, owner)` | `days?` (the setting), `owner?` | rows `{lead_id, label, status, owner_member_id, source, days}` | see |
| `source_performance` | Which sources bring leads that convert and win | Q3 | `crm_source_performance(from, to)` | `from`, `to` (dates) | rows `{source_id, source_name, leads, converted, unqualified, deals, won, won_value, conversion_rate}` | insider |
| `find_duplicates` | Possible duplicates of a kind — pairs with what matched (the Assistant's Monday read; the manager's screen) | Q4 | `crm_duplicates_open(kind)` | `kind` (lead, account, contact), `limit` (≤ 200) | rows `{a_id, b_id, a_label, b_label, matched_on, similarity}` | insider |
| `find_imports` | My imports (every import for a manager): status, counts, rollback window | Q5 | `mcp_imports` | `kind?`, `status?`, `limit` | rows `{import_id, kind, file_name, status, row_count, rows_created, rows_updated, rows_skipped, rows_refused, dry_run, rollback_until, created_by, created_at}` | own, `imports.shared`, manager |
| `get_import` | One import with its mapping and log | Q5 | `mcp_imports` | `import` (id) | the view's row + `mapping`, `log` | own, `imports.shared`, manager |
| `import_rows` | Every row's result of an import | Q5 | `mcp_import_rows` | `import`, `result?` (created, updated, skipped, refused), `limit` (≤ 500), `offset` | rows `{row_number, result, record_id, message}` | as `get_import` |
| `find_lists` | My lists and the shared ones; `q` alone resolves a list | Q6 | `mcp_lists` | `q?`, `kind?` (static, smart), `record_kind?`, `limit` | rows `{list_id, name, kind, record_kind, filters, is_shared, owner_member_id, member_count, counted_at}` | own, shared, manager (the view) |
| `list_members` | Who is in a static list (or the live members of a smart one) | Q6 | `mcp_list_members`; for smart: the record kind's view filtered by `filters` | `list`, `limit`, `offset` | rows of the record kind (as its `find_*`), `total` | as `find_lists` |
| `segment_preview` | How many, and who, a filter object would match before a list is saved | Q6 | the record kind's view with the filters | `record_kind`, `filters` (the `find_*` vocabulary), `limit` (≤ 50) | `{count, sample: [...]}` | insider |

### Accounts and contacts (Q7–Q13)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `find_accounts` | Our accounts by kind, owner, industry, country, last touch, tag, text. `q` alone resolves an account by name or domain (the registry's `account` resolver) | Q7 | `mcp_accounts` (+ `pg_trgm` on name) | `q?`, `kind?[]`, `owner?`, `industry?`, `country?`, `tag?`, `untouched_days?`, `has_open_deals?`, `sort?` (name, touched, open_value, won_value), `limit`, `offset` | rows `{account_id, name, domain, kind, industry, city, country, owner_member_id, last_touched_at, next_activity_at, next_colour, open_deals_count, open_deals_value, won_deals_value, contacts_count, tags}`, `total` | see |
| `get_account` | Everything about one account at a glance: fields, counts, the primary contact, the ledger's id and balance (when connected), the next step | Q8 | `mcp_accounts`, `mcp_contacts` (primary), the bridge for `gl.customer_by_crm_account` | `account` (id, name or domain), `with_ledger?` (bool) | `{account (the view's row), primary_contact: {contact_id, name, email, title}, ledger?: {customer_id, balance, currency} or {error: no_connection}, next_step?: {activity_id, kind, subject, at, owner_name}}` | see |
| `account_contacts` | Who we know at this company, primary first, with roles | Q8, Q9 | `mcp_contacts WHERE account_id` | `account`, `limit` | rows as `find_contacts` | see |
| `account_deals` | The account's deals: open first with stage and next step, then won and lost | Q8 | `mcp_deals WHERE account_id` | `account`, `status?[]`, `limit` | rows as `find_deals` | see |
| `account_tickets` | The account's open tickets in Help Desk (K7; empty on no_connection) | Q8 | the bridge → `helpdesk.tickets_by_organization` | `account`, `limit` | `{tickets: [{number, subject, status, priority, updated_at, url}]}` or `{error: no_connection}` | see |
| `accounts_untouched` | Accounts we have not spoken to in N days, customers first | Q10 | `crm_untouched('account', days, owner)` | `days?`, `owner?`, `limit` | rows `{record_id, name, kind, owner_member_id, last_touched_at, days}` | see |
| `find_contacts` | People by name, email, account, lifecycle, owner, role, opt-out, tag. `q` alone resolves a contact (the registry's `contact` resolver) | Q7, Q12 | `mcp_contacts`, `mcp_contact_emails` for an address | `q?` (name, email ILIKE, trigram), `account?`, `lifecycle?[]`, `owner?`, `role?`, `opt_out?` (email, sms, any), `tag?`, `untouched_days?`, `sort?`, `limit`, `offset` | rows `{contact_id, account_id, account_name, name, title, email, phone, mobile, role, is_primary, lifecycle, email_opt_out, sms_opt_out, owner_member_id, last_touched_at, next_activity_at, next_colour, open_deals_count, tags}`, `total` | see |
| `get_contact` | One person: fields, every address, their account, open deals, the next step, opt-outs | Q8 | `mcp_contacts`, `mcp_contact_emails`, `mcp_deals`, `mcp_deal_contacts` | `contact` (id, name or email) | `{contact, emails[], account?, deals: [{deal_id, number, title, stage_name, role}], next_step?}` | see |
| `contacts_untouched` | People we have not spoken to in N days | Q10 | `crm_untouched('contact', days, owner)` | `days?`, `owner?`, `limit` | rows `{record_id, name, kind (lifecycle), owner_member_id, last_touched_at, days}` | see |
| `upcoming_dates` | Birthdays and renewals in the next N days | Q13 | `crm_upcoming_dates(days, owner)` | `days?` (30), `owner?`, `limit` | rows `{kind (birthday, renewal), record_kind, record_id, name, on_date, owner_member_id}` | see |
| `find_notes` | The notes on a record (first 200 characters each unless `full`) | Q8 | `mcp_notes` | `kind`, `record`, `full?` (bool), `limit` | rows `{note_id, member_id, body, created_at}` | see |
| `find_attachments` | The files on a record | Q8 | `mcp_attachments` | `kind`, `record`, `limit` | rows `{attachment_id, filename, mime_type, byte_size, uploaded_by, created_at}` | see |

### Pipeline and deals (Q14–Q23)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `find_pipelines` | Which pipelines exist; `q` alone resolves one by key or name | Q14 | `mcp_pipelines` | `q?`, `include_archived?` | rows `{pipeline_id, key, name, is_default, currency, rotting_days, position}` | insider |
| `find_stages` | A pipeline's stages in order with probability, rotting days, required fields; `q` alone resolves a stage (with `pipeline`) | Q14, Q22 | `mcp_stages` | `pipeline?`, `q?`, `kind?` | rows `{stage_id, pipeline_id, name, position, probability, kind, rotting_days, required_fields, ask_assistant, colour, wip_limit}` | insider |
| `pipeline_summary` | What is in the pipeline by stage: count, value, weighted, rotting, no next step | Q14 | `crm_pipeline_summary(pipeline, owner, department)` | `pipeline` (the default when omitted), `owner?`, `team?` (department) | rows `{stage_id, stage_name, position, probability, kind, colour, wip_limit, deals, value, weighted, rotting, no_next_step, other_currency_count}` + `currency` | see (the function) |
| `forecast` | What is closing by month: value, weighted, won, quota — by seller or by stage | Q15, Q21 | `crm_forecast(pipeline, months, by, department)` | `pipeline?`, `months?` (1–24), `by?` (seller, stage), `team?` | rows `{month, row_id, row_name, open_value, weighted, won_value, deals, quota}` + `currency` | see; another seller's rows only to a manager (`reports.team`) |
| `funnel` | Where the funnel leaks: per stage entered, moved on, lost, still here, median days | Q20 | `crm_funnel(pipeline, from, to, owner)` | `pipeline`, `from`, `to`, `owner?` | rows `{stage_id, stage_name, stage_position, entered, moved_on, lost_from, still_here, median_days}` | see |
| `win_rate` | Won against lost in a period by seller, source or pipeline, with the cycle | Q19 | `crm_win_rate(from, to, by)` | `from`, `to`, `by?` (seller, source, pipeline) | rows `{row_id, row_name, won, lost, win_rate, won_value, avg_cycle_days, median_cycle_days}` | see |
| `cycle_time` | Days from creation to won, and touches to win | Q19, Q46 | `crm_win_rate(from, to, 'seller')` + `crm_touches_to_win(from, to)` | `from`, `to` | `{by_seller: [...], overall: {deals, avg_touches, median_touches, avg_days}}` | see |
| `deals_at_risk` | Deals needing a hand: no next step, rotting, closing untouched, overdue close | Q16 | `crm_deals_at_risk(owner, closing_days, untouched_days)` | `owner?`, `reason?[]`, `closing_days?` (14), `untouched_days?` (7), `limit` | rows `{deal_id, number, title, account_name, owner_member_id, stage_name, value, expected_close_date, reason, days}` | see |
| `deals_without_next_step` | Open deals with no open activity (the Assistant proposes for these) | Q16 | `mcp_deals WHERE status = 'open' AND NOT has_next_step` | `owner?`, `pipeline?`, `limit` | rows as `find_deals` | see |
| `deals_rotting` | Open deals too long in their stage | Q16 | `mcp_deals WHERE is_rotting` | `owner?`, `pipeline?`, `limit` | rows as `find_deals` + `days_in_stage` | see |
| `find_deals` | Deals by pipeline, stage, status, owner, account, contact, close window, value, tag, text. `q` alone resolves a deal by title or number (the registry's `deal` resolver) | Q17 | `mcp_deals` | `q?` (title, number, account ILIKE), `pipeline?`, `stage?[]`, `status?[]`, `owner?`, `team?`, `account?`, `contact?`, `close_from?`, `close_to?`, `close?` (this_month, next_month, this_quarter, overdue), `min_value?`, `max_value?`, `tag?`, `source?`, `campaign?`, `no_next_step?`, `rotting?`, `sort?` (close, value, stage, touched, position), `limit`, `offset` | rows `{deal_id, number, title, account_id, account_name, primary_contact_id, primary_contact_name, pipeline_id, stage_id, stage_name, position, value, currency, probability, weighted_value, expected_close_date, status, owner_member_id, stage_entered_at, days_in_stage, is_rotting, next_activity_at, next_colour, has_next_step, tags}`, `total` | see |
| `get_deal` | One deal in full: fields, contacts with roles, products, the stage's requirements, the next step, counts | Q17 | `mcp_deals`, `mcp_deal_contacts`, `mcp_deal_products`, `mcp_stages` | `deal` (id, number or title) | `{deal, contacts: [{contact_id, name, role, is_primary}], products: [...], stage: {name, probability, required_fields}, missing_for_next_stage: [...], next_step?, notes_count, attachments_count}` | see |
| `deal_history` | A deal's moves: from, to, who, when, how long in each stage | Q17 | `mcp_deal_stage_history` | `deal`, `limit` | rows `{history_id, from_stage, to_stage, member_id, at, seconds_in_from_stage, source}` oldest first | see |
| `deal_products` | A deal's product lines and their total | Q17, Q23 | `mcp_deal_products` | `deal` | rows `{deal_product_id, position, name, provider_app, provider_id, sku, quantity, unit_price, discount_percent, line_total, recurring}` + `total` | see |
| `deal_contacts` | Who is on a deal and in what role | Q9 | `mcp_deal_contacts` | `deal` | rows `{contact_id, contact_name, role, is_primary}` | see |
| `deals_closed` | What was won and lost in a period, by seller | Q18 | `mcp_deals WHERE status IN (won, lost) AND closed in the window` | `from`, `to`, `status?`, `owner?`, `limit`, `offset` | rows as `find_deals` + `won_at`, `lost_at`, `lost_reason` | see |
| `lost_reasons_summary` | Why we lost, by reason (and by seller) | Q18 | `crm_lost_reasons_summary(from, to, by_seller)` | `from`, `to`, `by_seller?` | rows `{lost_reason_id, label, member_id, display_name, deals, lost_value}` | see |
| `leaderboard` | Who is ahead and behind on quota this month | Q21 | `crm_leaderboard(month, department)` | `month?`, `team?` | rows `{member_id, display_name, won_value, won_count, quota, attainment, open_weighted, activities_done}` | `reports.team` (the function answers nothing else) |
| `stage_requirements_check` | May this deal enter that stage — what is missing | Q22 | `crm_stage_requirements_check(deal, stage)` | `deal`, `stage` | `{missing: [...], may_enter: bool}` | see |
| `products_pipeline` | Which products are on open deals and their totals | Q23 | `crm_products_pipeline(pipeline)` | `pipeline?` | rows `{name, provider_app, provider_id, deals, quantity, total, weighted}` | see |
| `saved_views` | My saved views and the shared ones; `q` alone resolves one | Q14 | `mcp_saved_views` | `q?`, `record_kind?`, `layout?` | rows `{saved_view_id, name, scope, record_kind, pipeline_id, filters, sort_by, sort_dir, columns, layout, is_system}` | own, shared |
| `quotas` | The quotas by seller and month | Q21 | `mcp_quotas` | `member?`, `from_month?`, `to_month?` | rows `{member_id, month, amount}` | own; `reports.team` for others |

### Activities and the calendar (Q24–Q28)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `my_activities` | What I (or a seller, for a manager) have to do today, this week, overdue — the agenda | Q24 | `crm_my_activities(scope, member)` | `scope?` (today, week, overdue), `member?` (a manager's), `limit` | rows `{activity_id, kind, subject, at, state, deal_id, contact_id, account_id, lead_id, record_name, priority}` | own; another's with `calendar.read` |
| `calendar` | The calendar of a window for me, a colleague or a team | Q25 | `crm_calendar(from, to, member, team, kinds, include_done)` | `from`, `to`, `who?` (me, a member, or `team:<department>`), `kinds?[]`, `include_done?` | rows `{activity_id, kind, subject, starts_at, ends_at, all_day, owner_member_id, owner_name, done, state, deal_id, contact_id, account_id, lead_id, record_name, location, priority}` | own; a colleague with `calendar.read`; a team with `calendar.team` |
| `find_activities` | Activities by record, owner, kind, state, window, text. `q` alone resolves an activity by subject (the registry's `activity` resolver) | Q24, Q27 | `mcp_activities` | `q?`, `deal?`, `contact?`, `account?`, `lead?`, `owner?`, `kind?[]`, `state?` (open, done, overdue, today), `from?`, `to?`, `source?`, `sort?`, `limit`, `offset` | rows (the view's row with `at`, `state`), `total` | see (the view) |
| `get_activity` | One activity: its record, participants, the reminder, the email behind it | Q27 | `mcp_activities`, `mcp_activity_participants` | `activity` | `{activity, participants: [{contact_id or member_id, name, response}], record: {kind, id, name}}` | see |
| `activity_summary` | Calls, emails, meetings and tasks per seller in a period, with outcomes | Q26 | `crm_activity_summary(from, to, department)` | `from`, `to`, `team?` | rows `{member_id, display_name, kind, done, scheduled, outcomes}` | own rows; `reports.team` for the team |
| `reminders_due` | Which reminders are due now (not done, not delivered, not snoozed into the future) and how many notices failed to send in a week (the worker's queue, for the admin) | Q28 | `mcp_activities` (`remind_at`, `reminded_at`, `snoozed_until`), `mcp_outbox_health` (db/025: counts by channel and status, no address or body) | `limit` | rows `{activity_id, member_id, kind, subject, at, deal_id, contact_id, account_id}` + `{failed_outbox: n}` | admin |
| `timeline` | Everything on one record in order: activities, emails (subject, direction), notes (first 200 characters), stage moves, changes | Q27 | `crm_timeline(kind, id, limit, before)` | `kind` (lead, account, contact, deal), `record`, `limit` (≤ 500), `before?` | rows `{at, item_kind, item_id, title, detail, actor_member_id, deal_id, contact_id}` newest first | see (the function) |

### Email (Q11, Q29–Q31)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `find_emails` | The emails on a contact, a deal or an account, or since a time: subject, direction, who, when — the body only when the asker may read it | Q11 | `mcp_emails` (+ `mcp_activities` kind email for the record join) | `contact?`, `deal?`, `account?`, `since?`, `direction?` (in, out), `with_body?` (false), `limit`, `offset` | rows `{direction, email_id, at, from_address, from_name, to_addresses, subject, body_text? (≤ 20,000 characters when with_body), sender_member_id, deal_id, contact_ids, is_private}`, `total` | see; private only to the sender and managers (the view) |
| `get_email` | One email in full (the body when the asker may read it; `too_large` over 1 MB) | Q11 | `mcp_emails`, `mcp_attachments` | `email`, `direction` | the view's row + `attachments[]` + `activities: [{activity_id, contact_id}]` | as `find_emails` |
| `emails_unmatched` | Emails that matched no one, and proposed leads from strangers | Q29 | `mcp_emails WHERE match_status IN (unmatched, proposed_lead)` | `status?`, `limit`, `offset` | rows `{email_id, at, from_address, from_name, subject, match_status, proposed_lead_id?}` | `leads.write` |
| `proposed_leads` | The leads the mailbox and the form proposed, waiting in the inbox | Q29 | `mcp_leads WHERE is_proposed` | `limit` | rows as `find_leads` + `proposed_from_email_id` | `leads.write` |
| `find_templates` | The email templates I may use — shared and mine — with their merge fields; `q` alone resolves one | Q30 | `mcp_email_templates` | `q?`, `kind?`, `mine?`, `limit` | rows `{template_id, name, subject, body, kind, is_shared, owner_member_id, usage_count, merge_fields[]}` | the view |
| `mailbox_health` | Is the logging mailbox alive: the last webhook, the last poll, the failures, the pending count | Q31 | `mcp_mailboxes` | — | rows `{mailbox_id, address, provider, poll_enabled, last_polled_at, last_webhook_at, last_error, last_error_at, active, received_7d, pending}` | `mailbox.manage` |

### Campaigns, the business, the admin (Q32–Q37)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `find_campaigns` | Which campaigns are running, planned, done; `q` alone resolves one | Q32 | `mcp_campaigns` | `q?`, `status?[]`, `kind?`, `owner?`, `limit` | rows `{campaign_id, name, kind, status, starts_on, ends_on, budget, currency, expected_leads, owner_member_id}` | insider |
| `get_campaign` | One campaign with its results | Q32 | `mcp_campaigns`, `crm_campaign_results(id)` | `campaign` | `{campaign, results: {members, responded, leads, converted, deals, won, won_value, open_value, cost_per_lead, cost_per_won}}` | insider |
| `campaign_results` | Every campaign's cost against what it brought | Q3, Q32 | `crm_campaign_results()` per campaign | `status?`, `limit` | rows `{campaign_id, name, kind, budget, members, responded, leads, converted, deals, won, won_value, cost_per_lead, cost_per_won}` | insider |
| `campaign_members` | Who is in a campaign, with their status | Q32 | `mcp_campaign_members` + the lead/contact names | `campaign`, `status?`, `limit`, `offset` | rows `{campaign_member_id, lead_id, contact_id, name, status, added_at, responded_at}` | insider |
| `find_sources` | The lead sources; `q` alone resolves one | Q35 | `mcp_lead_sources` | `q?`, `active?` | rows `{source_id, name, kind, active}` | insider |
| `find_lost_reasons` | The lost reasons of a kind; `q` alone resolves one | Q35 | `mcp_lost_reasons` | `kind?` (lead, deal), `q?` | rows `{lost_reason_id, kind, label, active}` | insider |
| `find_tags` | The tags with usage; `q` alone resolves one | Q35 | `mcp_tags` | `q?`, `limit` | rows `{tag_id, slug, label, usage_count}` | insider |
| `settings_catalogue` | The custom fields, pipelines, stages, sources, reasons and assignment rules in one read (what the expert needs to fill a form) | Q35 | `mcp_custom_fields`, `mcp_pipelines`, `mcp_stages`, `mcp_lead_sources`, `mcp_lost_reasons`, `mcp_assignment_rules`, `mcp_settings` | `part?` (fields, pipelines, sources, reasons, rules, settings) | `{fields: [...], pipelines: [...with stages], sources, reasons, rules?, settings}` (rules only to `leads.assign`; the mailbox id only to the admin) | insider |
| `members_with_roles` | Who holds which role here; which agents work here | Q34 | `mcp_members` | `role?`, `agents_only?`, `q?`, `limit` | rows `{member_id, display_name, member_kind, is_agent, roles_resolved, department_ids, timezone}` | insider |
| `find_members` | A colleague or an agent by name (the registry's `member`, `owner`, `assignee`, `seller`, `agent` resolver) | — | `mcp_members` | `q?`, `role?`, `limit` | rows `{member_id, display_name, member_kind, is_agent, roles_resolved, timezone}` | insider |
| `find_departments` | A team by name (the registry's `department`, `team` resolver) | — | `mcp_departments` | `q?`, `limit` | rows `{department_id, name, manager_member_id}` | insider |
| `find_keys` | The keys and tokens: mine, or every one for `tokens.manage`; `q` alone resolves a key by label (a holder of `tokens.manage` resolves among every key) | Q36 | `mcp_tokens` | `q?`, `mine?` (true), `scope?`, `limit` | rows `{key_id, member_id, label, scope, rate_per_minute, rate_per_day, lead_source_id, calls_today, created_at, last_used_at, expires_at, revoked_at}` | own; `tokens.manage` |
| `key_usage_summary` | How much each key was used | Q36 | `mcp_tokens` + `mcp_key_usage` (db/025: the day buckets, `tokens.manage` only) | `days?` (7) | rows `{key_id, label, calls, refused}` | `tokens.manage` |
| `assistant_proposals` | What the Assistant proposed, for me or for everyone, and what was accepted | Q37 | `mcp_assistant_proposals` | `kind?`, `status?`, `for?` (member), `deal?`, `limit`, `offset` | rows `{proposal_id, kind, deal_id, lead_id, contact_id, account_id, for_member_id, title, payload, proposed_by, status, decided_by, decided_at, result_activity_id, created_at}` | own; manager (the view) |
| `agent_dispatches` | What the agents were asked to do here and what came back | Q37, Q45 | `mcp_agent_dispatches` | `agent?`, `kind?`, `status?`, `since?`, `limit` | rows `{dispatch_id, record_type, record_id, agent_member_id, kind, via, acting_member_id, status, reply_excerpt, attempts, created_at, answered_at, deal_id, lead_id}` | `agents.settings`; an agent its own |
| `my_notifications` | My notices, unread first | — | `mcp_notifications` | `unread_only?`, `limit` | rows `{notification_id, kind, title, body, record_type, record_id, deal_id, contact_id, activity_id, read_at, created_at}` | own |
| `get_settings` | The business's settings as the screens show them | Q35 | `mcp_settings` | — | the view's row | insider |
| `tombstones` | What waits in the tombstones and when it purges | — | `mcp_tombstones`, `mcp_settings.tombstone_days` | `kind?`, `limit` | rows `{record_kind, record_id, name, deleted_at, deleted_by, merged_into_id, purges_at}` | manager |
| `search` | **The one box**: leads, accounts, contacts and deals by name, number, company, email, domain, tags, description — websearch syntax, a misspelt name by trigram, every result judged by the wall | all | `crm_search(q, kinds, limit, offset)` | `q` (2–200), `kinds?[]`, `limit`, `offset` | rows `{entity_kind, entity_id, title, headline, status, account_id, owner_member_id, rank}`, `total` | see (the function) |
| `records_search` | The contract's guarded long-tail read over the `mcp_*` views (one SELECT, the deny list, 200 rows, 5 s) | — | the views | `sql` | ≤ 200 rows | the views (the caller's rows) |

## The shares, for the kernel's token (K7, design §8, D13)

**Who is asking comes from the kernel (K26):** `X-OS-Consumer` (the consumer's catalog key) and `X-OS-Consumer-Agent`; anything the consumer put in
the arguments as `consumer` or `as_agent` is stripped by the kernel. The gate reads the headers into request-scoped variables (`db.request_consumer`, built in Phase 4) and the bridge passes
`consumer` to the db/017 `crm_share_*` functions; no header → `consumer_unknown`, nothing shared. Every share keeps the kernel's 8 s. **A restricted
record is never shared** (the function's rule), **no body and no note ever crosses**, no member identity crosses but an owner's display name.

| Tool | Document | Arguments (flat from the kernel) | Answers |
|---|---|---|---|
| `accounts_index` | `os.sales-crm-accounts/1` | `since?` (a time), `q?`, `limit?` (≤ 500), `offset?` | `crm_share_accounts_index(consumer, …)`: `{schema, application, accounts: [{account_id, name, legal_name, domain, kind, owner_member_id, owner_name, gl_customer_id, helpdesk_organization_id, country, city, open_deals_count, open_deals_value, won_deals_value, currency, updated_at}], total}` |
| `contacts_index` | `os.sales-crm-contacts/1` | `account?` (id), `email?`, `since?`, `limit?`, `offset?` | `crm_share_contacts_index(consumer, …)`: `{schema, application, contacts: [{contact_id, account_id, account_name, name, first_name, last_name, title, role, emails[], phone, mobile, lifecycle, email_opt_out, sms_opt_out, owner_member_id, updated_at}], total}` — the Help Desk's requester match by `email` |
| `deals_won` | `os.sales-crm-deals-won/1` | `from` (a date), `to`, `limit?`, `offset?` | `crm_share_deals_won(consumer, …)`: `{schema, application, deals: [{deal_id, number, title, account: {account_id, name, legal_name, gl_customer_id}, primary_contact: {contact_id, name, email}, value, currency, won_at, owner_member_id, owner_name, pipeline_key, products: [{name, provider_app, provider_id, sku, quantity, unit_price, discount_percent, line_total, recurring}]}], total}` — the ledger's customer and invoice (G3), Inventory's order (INV3), Consultant Tracking's engagement (CT4) |
| `deals_open_by_account` | `os.sales-crm-deals-open/1` | `account` (id) | `crm_share_deals_open_by_account(consumer, account)`: `{schema, application, account_id, deals: [{deal_id, number, title, stage_name, probability, value, weighted_value, currency, expected_close_date, owner_member_id, owner_name, next_activity_at, next_colour, stage_entered_at}]}` — a Help Desk ticket's panel (HD5), a Spaces card (SP4) |
| `account_timeline_summary` | `os.sales-crm-timeline/1` | `account` (id), `limit?` (≤ 50) | `crm_share_account_timeline_summary(consumer, account, limit)`: `{schema, application, account_id, touches: [{at, kind, subject, who, direction, deal_number}], next_step?: {at, kind, subject, who, deal_number}}` — dates and subjects only |

- **Logged:** `share.read` (source `application`, `after = {application, tool, account_id or window, rows, ms}`) on every call.
- The kernel refuses an answer over 256 kB and waits 8 s; the indexes page.

## The records API (bearer = a key; `/api/v1/` on the vhost's allow-list; design §8)
A key acts as its person: `api_authenticate()` resolves it (`crm_resolve_key`), sets `app.member_id`, counts it (`crm_rate_ok` → 429 with
`Retry-After`), and every route reads the same views and functions as the tools above: `GET /api/v1/leads|accounts|contacts|deals|activities`
= the `find_*` tools with the same filters; `GET /api/v1/{kind}/{id}` = `get_*`; `POST /api/v1/{kind}` and `PATCH /api/v1/{kind}/{id}` are the
`*_create` / `*_update` actions under the key's person (an agent's key pauses like the agent); `POST /api/v1/activities` = `activity_create`.
**A lead-capture key** (`lead_source_id` set) answers only `POST /api/v1/leads` (`first_name`, `last_name`, `email`, `company`, `phone`,
`message`, `utm_*`; the source fixed by the key; 600/day) and nothing readable: every other route is 403 `capture_key`. `GET
/api/v1/calendar.ics?token=` is the feed (the token the authority, `calendar_feeds`). `GET /api/v1/health`. Logged with source `api` and `token_id`.

## Activity server: `sales_crm_activity_mcp`

| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `record_history` | Who did what to a lead, an account, a contact, a deal, an activity, a campaign, in order | Q33, Q44 | `mcp_activity_log` | `entity_type` (lead, account, contact, deal, activity, campaign, list, import, pipeline, stage, template, key), `entity_id`, `limit` (≤ 200) | rows `{log_id, occurred_at, actor_member_id, actor_name, actor_is_agent, source, action, before, after, request_id, agent_run_id}` oldest first | the view: rows about what the caller may see |
| `actor_history` | What person, key or agent X did here since a time | Q44, Q45 | `mcp_activity_log` | `member?` or `key?` (one), `since?` (7 days), `limit` | rows as above + `lead_id, account_id, contact_id, deal_id`, newest first | own trail: anyone; another's: `reports.team` or the admin (else only rows on records the caller owns) |
| `recent_activity` | What changed on my book, or on an account's, since I last looked | Q27 | `mcp_activity_log` | `since` (required), `account?`, `deal?`, `owner?`, `exclude_mine?` (true), `limit` | rows, newest first | the view |
| `agent_activity` | What the agents did here this week, and what they proposed that nobody took | Q45 | `mcp_activity_log WHERE actor_is_agent`, `mcp_assistant_proposals` | `since?` (7 days), `agent?`, `limit` | `{actions: [...], proposals: {open, accepted, dismissed}}` | `agents.settings`, a manager; an agent its own |
| `activity_timeline` | The touches on an account or a deal over time, by week — how the relationship was worked | Q46 | `crm_timeline()` + `mcp_activity_log` | `kind` (account, deal), `record`, `from?`, `to?` | rows `{week, touches, emails_in, emails_out, meetings, calls}` | see |
| `activity_search` | The trail by a phrase in actions, names and labels — "when did we first talk to Acme about the renewal" | Q43 | `mcp_activity_log` (`action`, `entity_type`, `after->>'name'`, `->>'title'`, `->>'subject'`) | `q` (2–200), `since?`, `account?`, `limit` | rows | the view |
| `activity_query` | The contract's guarded long-tail search over `mcp_activity_log` and the name views | — | as listed | `sql` | ≤ 200 rows, 5 s | the view |

### The activity vocabulary (design §6.5): what the log must carry for these tools
Every row names the record (`lead_id`, `account_id`, `contact_id`, `deal_id`, defaulted from the entity) and carries in `after`: a create — the
record's name/title, owner, kind/status; an update — the changed fields (before/after), never a body; `deal.move` — `{from_stage, to_stage,
stage_name, seconds_in_stage, position}`; `deal.won` / `deal.lost` — `{value, currency, close_date, reason?}`; `activity.create` /
`activity.complete` — `{kind, subject, at, outcome?, next_activity_id?}` (never the body); `email.send` / `email.log` — `{subject, direction,
contact_ids, deal_id, template?}` (never the body); `lead.convert` — `{account_id, contact_id, deal_id, account_made, contact_made}`; `records.merge`
— `{kind, survivor, loser, children_moved}`; `import.run` — `{kind, rows, created, updated, skipped, refused}`; `bulk.*` — `{kind, count,
filters?}`; `share.read` — `{application, tool, rows, ms}`; `search.keyword` — the query cut at 120 and the count; `notification.send` — `{kind,
member_id, title}`; `proposal.*` — `{kind, deal_id, result_activity_id?}`. A token or a key is named by its label, never its value.

## Entity resolution for the action tools (the registry's `resolve` block, `deploy/kernel-registry-sales_crm.json`)
Each tool answers the resolver's plain list when called with `q` alone: digits are an id, otherwise ILIKE with at most 6 candidates.

| Param(s) | Tool (with `q`) | id field | label |
|---|---|---|---|
| `lead` | `find_leads` | `lead_id` | `label` (person · company) |
| `account`, `company`, `parent_account` | `find_accounts` | `account_id` | `name` (domain) |
| `contact`, `person`, `primary_contact` | `find_contacts` | `contact_id` | `name` (account) |
| `deal`, `opportunity` | `find_deals` | `deal_id` | `title` (number) |
| `pipeline` | `find_pipelines` | `pipeline_id` | `name` |
| `stage`, `to_stage` | `find_stages` | `stage_id` | `name` (pipeline) |
| `activity`, `task`, `meeting`, `call` | `find_activities` | `activity_id` | `subject` |
| `campaign` | `find_campaigns` | `campaign_id` | `name` |
| `list`, `segment` | `find_lists` | `list_id` | `name` |
| `template` | `find_templates` | `template_id` | `name` |
| `source`, `lead_source` | `find_sources` | `source_id` | `name` |
| `lost_reason`, `reason` | `find_lost_reasons` | `lost_reason_id` | `label` |
| `tag` | `find_tags` | `tag_id` | `label` |
| `saved_view`, `view` | `saved_views` | `saved_view_id` | `name` |
| `api_key` | `find_keys` | `key_id` | `label` |
| `member`, `person_member`, `owner`, `assignee`, `seller`, `agent`, `new_owner`, `who` | `find_members` | `member_id` | `display_name` |
| `department`, `team` | `find_departments` | `department_id` | `name` |
| `note`, `attachment`, `email`, `import`, `proposal`, `dispatch`, `notification`, `token`, `product_line`, `participant`, `field`, `rule`, `export`, `draft` | **ids only**, never resolved by name (read from `find_notes`, `find_attachments`, `find_emails`, `find_imports`, `assistant_proposals`, `agent_dispatches`, `my_notifications`, `find_keys`, `deal_products`, `get_activity`, `settings_catalogue`, `find_imports`) | | |

## Coverage

**Design §7 → tools**
| # | Tools |
|---|---|
| Q1 `find_leads`, `leads_inbox`, `leads_untouched` · Q2 `get_lead`, `timeline` · Q3 `source_performance`, `campaign_results` · Q4 `find_duplicates` · Q5 `find_imports`, `get_import`, `import_rows` · Q6 `find_lists`, `list_members`, `segment_preview` |
| Q7 `find_accounts`, `find_contacts` · Q8 `get_account`, `account_contacts`, `account_deals`, `account_tickets`, `get_contact`, `find_notes`, `find_attachments`, `timeline` · Q9 `account_contacts`, `deal_contacts` · Q10 `accounts_untouched`, `contacts_untouched` · Q11 `find_emails`, `get_email` · Q12 `find_contacts (opt_out)` · Q13 `upcoming_dates` |
| Q14 `pipeline_summary`, `find_pipelines`, `find_stages`, `saved_views` · Q15 `forecast` · Q16 `deals_at_risk`, `deals_without_next_step`, `deals_rotting` · Q17 `get_deal`, `deal_history`, `deal_products`, `find_deals`, `timeline` · Q18 `deals_closed`, `lost_reasons_summary` · Q19 `win_rate`, `cycle_time` · Q20 `funnel` · Q21 `leaderboard`, `forecast`, `quotas` · Q22 `stage_requirements_check` · Q23 `products_pipeline` |
| Q24 `my_activities`, `find_activities` · Q25 `calendar` · Q26 `activity_summary` · Q27 `timeline`, `get_activity`, `recent_activity` · Q28 `reminders_due` |
| Q29 `emails_unmatched`, `proposed_leads` · Q30 `find_templates` · Q31 `mailbox_health` |
| Q32 `find_campaigns`, `get_campaign`, `campaign_results`, `campaign_members` · Q33 `record_history` · Q34 `app_roles`, `members_with_roles` · Q35 `settings_catalogue`, `find_sources`, `find_lost_reasons`, `find_tags`, `get_settings` · Q36 `find_keys`, `key_usage_summary` · Q37 `assistant_proposals`, `agent_dispatches` |
| Q38 `accounts_index` · Q39 `contacts_index` · Q40 `deals_won` · Q41 `deals_open_by_account` · Q42 `account_timeline_summary` |
| Q43 `activity_search` · Q44 `actor_history`, `record_history` · Q45 `agent_activity` · Q46 `cycle_time` (touches to win), `activity_timeline` |

**Agents' grants** (`maludb-os.json`): the Expert holds every records tool above but `find_keys`, `key_usage_summary`, `mailbox_health`,
`find_imports`, `get_import`, `import_rows`, `reminders_due`, `tombstones`, `agent_dispatches`, `records_search`, `app_roles` and the shares, and
the activity server's `record_history`, `recent_activity`, `actor_history`, `activity_search`; the Assistant holds the morning note's reads
(`my_activities`, `calendar`, `deals_at_risk`, `deals_without_next_step`, `deals_rotting`, `leads_untouched`, `upcoming_dates`, `find_emails`,
`forecast`, `pipeline_summary`, `funnel`, `leaderboard`, `win_rate`, `activity_summary`, `source_performance`, `find_duplicates`,
`assistant_proposals`) and what it needs to name things (`find_leads`, `get_lead`, `leads_inbox`, `find_accounts`, `get_account`,
`account_deals`, `find_contacts`, `get_contact`, `find_deals`, `get_deal`, `deal_history`, `find_pipelines`, `find_stages`, `find_activities`,
`emails_unmatched`, `proposed_leads`, `find_templates`, `find_members`, `find_departments`, `timeline`, `touches_to_win` → `cycle_time`) and
`record_history`, `recent_activity`. **Phase 4 reconciled the manifest's `tool_grants` to these names (2026-10-12)**: `touches_to_win` became `cycle_time`; `contact_emails` (no such
tool — `get_contact` carries every address) was dropped and `record_history` moved to the Expert's Activity MCP list (the records role reads no part of the log); `search`, `my_notifications`, `get_settings`,
`tombstones`, `quotas`, `deals_closed`, `products_pipeline`, `members_with_roles`, `account_tickets` were added to the Expert's records list and `activity_timeline`, `agent_activity` to its activity
list; `reminders_due`, in the Expert's list, was removed (the spec withholds it). `tests/phase4/registry.php` proves each list against the running servers.

## What the schema lacked that a tool needs — built in db/017 (Phase 1, the lead)
`crm_calendar(from, to, member, team, kinds, include_done)` (the calendar in one call, the wall per row, private rows to their seller and managers),
`crm_my_activities(scope, member)` (the agenda: overdue, today, the week), `crm_lost_reasons_summary(from, to, by_seller)`, `crm_products_pipeline(pipeline)`,
`duplicate_dismissals` + `crm_duplicates_open(kind)` (a dismissed pair stays dismissed), and **the five shares as SECURITY DEFINER functions**
`crm_share_accounts_index`, `crm_share_contacts_index`, `crm_share_deals_won`, `crm_share_deals_open_by_account`, `crm_share_account_timeline_summary`
(as the application; restricted records never shared; no body, no note). Still through the bridge with no schema change: the two K7 reads the
application consumes (`account_tickets`, the ledger panel of `get_account`) — PHP holds the application token.

## Size
Records: **85 tools** — 80 a person or an agent calls (the 79 of the tables above and `app_roles`) and the five kernel-only shares (the Phase 1 estimate, 72, was short of the tables' own rows); none calls a model. Activity: **7 tools**. The resolve block names 17 entities. Five share documents, the records API's routes plus the capture route and the feed.
Built as `mcp/records_server.py` (`app_roles`) over `crm_leads` (12), `crm_accounts` (12), `crm_deals` (22), `crm_activities` (7), `crm_email` (6), `crm_business` (20), `crm_shares` (5), plus
`mcp/activity_server.py` (7), `mcp/crm_common.py` (the answers-not-exceptions envelope, the resolvers, money) and `mcp/crm_bridge.py` (the signed loopback call to PHP).

## Built as (Phase 4, 2026-10-12)
Where the build departs from the tables above, or settles what they left open:
- **`record_history` is the activity server's alone.** The records role reads no part of the log (the table above listed it twice); the Expert is granted the activity server's.
- **A number is an id and nothing else.** `get_account 4` is the account with id 4 or `not_found` — never an account whose name contains a 4 (the wall proof found the fallback reading a hidden id as a name).
- **The shares are not read here.** The five tools call the application's bridge (`op share_*`), whose PHP calls the db/017 SECURITY DEFINER functions as the application and writes `share.read`; the document
  comes back as PHP built it, money as the numbers PHP chose. A call with no `X-OS-Consumer` is `consumer_unknown`; a bridge that cannot be reached, or whose signature PHP refuses, is `bridge_unavailable`
  (retry true). The two K7 reads this application consumes (`account_tickets`, `get_account`'s ledger) go the same way (`op k7_read`).
- **db/025.** The records role loses its direct SELECT on `search_index` (the guarded `records_search` runs a person's SELECT as that role); `mcp_key_usage` and `mcp_outbox_health` serve `key_usage_summary` and
  `reminders_due` (the records role has no EXECUTE on `crm_reminders_due`, a worker's function, and no read on the outbox); the activity role reads `mcp_assistant_proposals` for `agent_activity`.
- **Rights:** `calendar.read` is held by every role (a colleague's agenda and calendar are open; a private activity is not in them), `calendar.team` by managers and the admin. A missing right is `managers_only`
  with a sentence naming it. `leaderboard` needs `reports.team`; the forecast by seller keeps a caller's own line without it (the one rule PHP and this server both add).
- **Codes added to the table above:** `consumer_unknown` (a share without a consumer). A hidden record is `not_found` in the very same words as a missing one.
- **Status words are the schema's:** proposals are `proposed` (the `open` count of `agent_activity`), `accepted`, `dismissed`, `expired`; a campaign is `planned`, `active`, `done`, `cancelled`; an account kind
  `prospect`, `customer`, `partner`, `former`, `other`; a contact role `decision_maker`, `champion`, `influencer`, `user`, `finance`, `procurement`, `other`.
- **Resolving by `q` alone** wants ONE row for an exact name: a stage named in two pipelines (`Won`, `Lost`) and a lost reason with the same label for a lead and a deal (`No budget`) are several matches —
  the agent passes the id (the answer's `candidates` say which).
- **Open question for the owner:** a deal (or contact) that is not itself restricted is visible by its own flag even when its ACCOUNT is restricted, and the deal row carries the account's name and id
  (`mcp_deals.account_name`, db/016 — the screens do the same). The wall of an account therefore does not hide its name from someone who can see one of its deals. Hiding it needs a change to db/016's two
  views (and the screens that print the name); nothing is changed here.

## Open questions
(none)
