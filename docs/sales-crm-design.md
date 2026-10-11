# Sales CRM — design (Phase 0 of the new-app workflow, fitted to the Business OS)

2026-10-09 · the business's **prospecting and customer relationship management system**: **leads** found and worked until they
are qualified, **accounts** (the companies) and **contacts** (the people), **deals** moved through a **pipeline** on a **kanban
board** that shows the sales cycle, every call, email, meeting and task as an **activity** on a **reminder calendar**, the
emails exchanged with a contact logged against them, campaigns and lead sources with their results, lists and segments for
prospecting, and the reports a sales manager runs a team by — forecast, funnel, win rate, cycle time, activity. The owner's brief
(2026-10-09): *a new project, `maludb-os-sales-crm`, a prospecting and customer relationship management system built for the
MaluDB Business OS with the `maludb-os-htmx-php-guidelines` plugin; a full-featured CRM with a reminder calendar and a kanban
board that can display and process the sales cycle.* Its own repository `/srv/apps/sales_crm` (origin
`github.com/maludb/maludb-os-sales-crm`, private — created 2026-10-09), served as `crm.<domain>`, fitted to
`maludb-os-integration` 0.9.0 and `htmx-php-builder` 0.9.0 (the record picker, the application switcher), exactly as HR,
Projects, Help Desk, Spaces, Knowledge, Inventory, ProcessCore and DocCloud were. `/srv/apps/projects` (the board, SortableJS as
the enhancement over a POST) and `/srv/apps/cidery` (the server-rendered schedule timeline and month) are the nearest built
exemplars for the two centrepieces; `/srv/apps/helpdesk` for the mail doors, tags, custom fields and saved views;
`/srv/apps/consultant_tracking` and `/srv/apps/doccloud` for the kit.

> **What this document is.** The plan, in the kernel's words, and the brief a worker model builds from. Phase 0's first half is
> this document and the owner's answers (§13, recorded in §15); the schema in `db/`, `maludb-os.json`, `os/`, `skills/` and
> `deploy/` complete it. Phase 1 adds `docs/sales-crm-mcp-tool-surface.md`, `docs/sales-crm-action-manifest.md`,
> `docs/build-specs/pipeline-board.md`, `docs/build-specs/activities-calendar.md`, `docs/build-specs/leads-and-conversion.md` and
> the slice specs. **No feature PHP is written until the owner approves them together.**

## 0. The research — what the field does, and what the estate gives today

### 0.1 The field

**Salesforce** — the four-object model: a **Lead** (an unqualified person-and-company in one row, from a web form, a list or an
event) is **converted** into an **Account** + a **Contact** + an optional **Opportunity**; opportunities have stages with a
probability and a *forecast category* (pipeline, best case, commit, closed); **Activities** are Tasks (a due date, done or not)
and Events (a start and an end) attached to any record; Campaigns with members and attribution; record types, page layouts and a
field for everything. *Kept:* the lead and its conversion (prospecting lists stay clean of people who never became anything;
the owner's word was "prospecting"), accounts, contacts, deals with stages and probabilities, the two shapes of activity in one
table, campaigns with members, lost reasons. *Dropped:* forecast categories as a separate field (the stage's probability and the
owner's override do the work), record types and layouts (custom fields suffice), territories (Extended).

**HubSpot** — contacts, companies, deals and tickets as peers with *associations*; several **pipelines**, each with stages that
carry a probability; the **deal board** by pipeline; **tasks** with a queue and reminders; email logging by a BCC/forwarding
address and a tracking extension; sequences (automated email cadences); meetings links; lifecycle stage on a contact
(subscriber → lead → MQL → SQL → opportunity → customer); lists (active and static). *Kept:* several pipelines, each with its
own stages; associations as explicit join tables (`deal_contacts` with a role); the BCC logging address (MaluMail receives it);
active (smart) and static lists; the lifecycle stage **as a derived fact**, never a field someone maintains. *Dropped:* sequences
(Extended — an agent drafting the next email is the OS's way), the tracking pixel, meetings links (an `activity` of kind meeting
is enough; booking pages are another application's).

**Pipedrive** — **activity-based selling**: the pipeline is the home screen; a deal shows the next activity's date in a colour
(green scheduled, yellow today, red overdue, grey none) and the method is *always schedule the next step when you finish one*;
**rotting** deals (no change for N days in a stage turn red); the deal's value with products; a calendar of activities synced
both ways; Leads Inbox (a pre-pipeline list) and the Prospector add-on (purchased data). *Kept:* the next-activity colour on every
card, rotting per stage, "every open deal has a next step" as a flag the board and the morning note show, products on a deal,
the calendar as a first-class screen, the leads inbox as our Leads list. *Dropped:* purchased prospect data (nothing enters this
installation but what the business types, imports or receives).

**Zoho CRM, Close, Attio, Twenty** — Zoho's blueprint (stage transitions with required fields) and territory rules; Close's
built-in calling and SMS; Attio's and Twenty's "a CRM is a flexible object database" with notes as the record of a relationship.
*Kept:* required fields per stage (a stage may name the fields a deal needs to enter it — a Zoho blueprint, light), SMS to a
contact through the kernel (K6 is for members; texting a *contact* is K28, owed, §12), timeline-first record pages (every
activity, email, note and change on one trail). *Dropped:* telephony (Extended through Retell, which the kernel already speaks),
a custom object builder (custom fields on the five records are enough for v1).

### 0.2 What the estate gives (the survey of the siblings' `db/*.sql`, 2026-10-09)

| Need | The estate has | Used here as |
|---|---|---|
| The directory, sign-on, roles, tokens, the activity log | the kit (Consultant Tracking `db/001`–`004`, DocCloud `001`–`005`): `members` (+ `is_agent`, `is_external`, `roles text[]`), `departments`, `department_members`, `sso_nonces`, `member_sessions`, `directory_sync_state`, `<x>_rights/_roles/_role_rights`, `mcp_access_tokens`, `activity_log`, `activity_ingest_state`, the `app_settings` skeleton | **reuse** verbatim (`crm_`) |
| A company we deal with | GL `customers` (the billing party; canonical, also Inventory's) · Help Desk `organizations` (the customer companies tickets come from) · Consultant Tracking `clients` | **not the same concept**: a CRM **account** is a company at any point of the relationship — a prospect, a customer, a partner, a former customer — most of them never a billing party. `accounts` is **new canonical** (§6.4); the GL's `customers` is **read** (K7) once a deal is won and the ledger has made the customer — the account holds `gl_customer_id` |
| A person at a company | Consultant Tracking `client_contacts` (name, email, phone, role, is_primary) · GL `party_contacts` · Help Desk `requesters` (kind member/external/agent, merged_into_id, last_seen_at) | `contacts` is **new canonical**: a contact may exist with no account (an individual prospect), carries the prospecting fields (title, source, lifecycle, owner, opt-outs, social), and keeps the columns the two canonical contact tables share **under the same names** (`name`, `email`, `phone`, `role`, `is_primary`); `merged_into_id` from `requesters` |
| A board of cards by column, a card's move a POST, drag the enhancement | Projects `board_columns` / `board_column_statuses`, `html/board/columns.php` polled every 30 s and on change, `assets/js/board.js` (SortableJS from the theme's `vendors.min.js`, the selects stand alone without it) | **the shape of the pipeline board** (§6.2, `docs/build-specs/pipeline-board.md`): a stage *is* a column, so no mapping table; the columns partial, the 30 s poll and `board.js` copied as code |
| A calendar | the cidery's `/schedule/` **server-rendered** timeline and month (`docs/16-equipment-schedule-design.md`); txtSchedules' `calendar_feeds` (a 48-hex token, sha256 kept, one per member, the ICS of the person's own items); the theme ships the `tui-calendar` family (jQuery-era) | **server-rendered HTMX grids** (month, week, day, agenda) as the cidery's — no JS calendar vendor (D8); **reuse** `calendar_feeds` + `app/features/calendar/ics.php` copied as code |
| A reminder | Spaces `reminders` (member, a message or a page or free text, `remind_at`, `done_at`, `notified_at`) | **not reused**: a CRM reminder is a property of an activity (`activities.remind_at`, `reminded_at`), never a thing of its own; a free-text reminder is an activity of kind `task` |
| Email in and out | Help Desk `mailboxes` (provider malumail/imap, `webhook_secret_ref`), `inbound_email` (every header, status received/processed/ignored/failed/bounce/auto_reply/suppressed; nothing thrown away before it is linked), `outbound_email`; the kernel's MaluMail webhook pattern | **reuse** the three tables (`ticket_id`/`message_ref_id` → `contact_id`/`activity_id`); the logging address `crm-<token>@<mail domain>` in BCC (§6.3) |
| Tags, custom fields, saved views | Help Desk `tags (slug, label, usage_count)` + `ticket_tags`; `custom_field_defs (key, label, field_type, options, applies_to, …)` + `custom_values jsonb` on the record; `saved_views (scope personal/queue/global, filters jsonb, sort, columns, layout list/board, board jsonb)` | **reuse** all three: `record_tags` on the shape of `ticket_tags` (+ `record_type`); `applies_to` widened to `lead|account|contact|deal|activity`; `saved_views.queue_id` → `pipeline_id` (the external-key substitution), `layout` widened with `calendar` |
| Notes, attachments, notifications, dispatches, sequences, tax rates | GL `notes`, `attachments` (canonical); Spaces' member-only `notifications`/`_prefs`/`_outbox`; GL `agent_dispatches`; GL `document_sequences` | **reuse** (`record_type` widened; deal numbers `D-000123` from `document_sequences`; no tax rates — the CRM prices nothing) |
| Products on a deal | Inventory `products` / `product_variants` (owned by Inventory), its `availability_index` share; GL `items` | **read** (K7 `inventory.availability_index`, `gl.items` when it exists) for the picker; **`deal_products` new canonical**: the deal's lines name a product by text and an optional provider id, with quantity, unit price, discount — the CRM keeps no catalog of its own |
| A cross-record search | **Spaces `search_index`** (canonical; `entity_kind`, tsvector A/B, `websearch`, `ts_headline`) | **reuse** + append `account_id`, `owner_member_id`; `entity_kind` widened with `lead|account|contact|deal` — the one search box answers all four |
| Imports and exports | Spaces `imports` (file, mapping, counts, errors) and `exports` (+ `params`), `worker_passes` | **reuse** (`kind` widened: `leads_csv`, `contacts_csv`, `accounts_csv`, `deals_csv`) |
| A trail of changes | Help Desk and Projects log `entity.verb` to `activity_log` with `detail` | the record's **timeline** is the union of its activities, emails, notes and the `activity_log` rows naming it — nothing stored twice |

Free prefix: **`crm_`** (taken: `hr_ projects_ hd_ sp_ kn_ inv_ gl_ ct_ ts_ dc_ pc_`). Ports after DocCloud's 8190/8841/8842: **8191 /
8843 / 8844**, scratch 8801–8807 (the kernel's own MCPs start at 8811). The kernel's category check already admits **`crm`**
(db/130's list), so the catalog row (K32) widens nothing; the business area is **Sales & Service** (where HubSpot and Salesforce
sit as externals today).

### 0.3 What MaluDB gives, and what the CRM asks of it

The CRM has **no memory database of its own**: it is a system of record whose questions are record questions, answered from
PostgreSQL through the records MCP, and activity questions ("when did we last talk to Acme about the renewal?", "who changed the
stage?") answered from the tenant's one memory where every `activity_log` row lands tagged `sales_crm`. The expert's
ask-me-anything is the kernel's chat endpoint over the application's tools, as every sibling's. No model call happens inside the
application — drafting an email, proposing a next step and summarising a relationship are the agents' work through the kernel.

## 1. What Sales CRM is, and is not

Sales CRM **owns the business's prospects, customers-as-relationships and deals**: who the business is trying to sell to, who it
talks to there, what it is trying to sell them, what stage that is at, what was said and done and when, what is to be done next,
and what came of it — **so a seller always knows the next step and a manager always knows the forecast.**

- **Leads are worked, then converted.** A lead is one row: a person and the company they are at, from a source, owned by a
  seller, in a status (new → working → qualified | unqualified). Converting it makes (or finds, by email and domain) an account
  and a contact and, if asked, a deal — the lead keeps pointing at all three.
- **Accounts and contacts are the relationship.** Every email, call, meeting, note, deal and ticket is on their timeline. A contact
  may stand alone; an account has many contacts, one primary.
- **Deals move through a pipeline on a board.** Several pipelines (new business, renewals, partners), each with ordered stages
  carrying a probability, a rotting threshold and the fields a deal must have to enter; a deal has a value, a currency, an
  expected close date, an owner, products, contacts with roles, and ends won or lost with a reason. The **board** is the home
  screen: columns are stages, cards are deals, a drag is a `deal_move`, the column foot shows count, value and weighted value,
  every card shows its next activity in Pipedrive's four colours.
- **Activities are the work, and the calendar shows them.** One table for calls, emails, meetings, tasks, deadlines and lunches
  on any record; each with a due or a start and end, an owner, participants, done or not, an outcome; a **reminder** before it
  (in-app, email and, opt-in, a text), delivered by the worker; the **calendar** in month, week, day and agenda for me, a
  colleague or the team; an ICS feed for the phone's calendar.
- **Email is logged, not hosted.** A BCC address lands every sent email on the right contacts; replies to the logged thread do
  too; a seller may send from the CRM through MaluMail with a template and merge fields, logged as an activity. The CRM is not a
  mail client.
- **Prospecting is lists.** Import a CSV with a saved mapping and dedupe; static lists and smart segments over filters; bulk
  assign, tag, add to a campaign, export; duplicates found and merged; campaigns with members and the deals they produced.
- **Agents sell beside people.** The expert logs, creates, moves and answers in the command bar; the Sales Assistant writes
  each seller's morning note and proposes the next step on every deal that lacks one; sending anything to a contact pauses for a
  person; nothing about money moves.
- **Nothing of identity, billing or support lives here.** The kernel signs people in; the ledger makes the customer and the
  invoice from a won deal (K7); Help Desk owns tickets and shows the account's; HR owns the sellers' employment.
- **Full MCP coverage** is a requirement (`maludb-os-integration`, `mcp-and-api.md`).
- **The easy path is three steps**: add a lead, convert it into a deal on the board, schedule the next call — and the calendar
  reminds you.

Not in version 1: quotes and e-signature (a quote is a document a deal attaches; a priced, numbered quote is Extended with the
GL's items), telephony and call recording (Extended through Retell), email sequences and automated cadences (Extended), two-way
calendar sync with Google and Microsoft 365 (Extended; the ICS feed is one-way), web-to-lead forms hosted here (Extended;
Spaces' published pages and Help Desk's request form exist), territories and quota plans (Extended), a customer portal, purchased
or scraped prospect data (never), commissions (HR's and the ledger's), marketing email at scale (Mailchimp's; a campaign here
records membership and results).

## 2. Who uses it (the actors, in the OS's words)

"Agent" always means an OS AI agent. A **seller** is a member holding Sales Rep; a **manager** holds Sales Manager; a **lead** is a
row to qualify; an **account** a company; a **contact** a person; a **deal** an opportunity in a pipeline; a **stage** a column of a
pipeline; an **activity** a call, email, meeting, task, deadline or lunch; a **reminder** the notice before an activity; a
**campaign** a named effort with members; a **list** a saved set of leads or contacts; a **segment** a saved filter.

| Actor | Who | How they reach the application |
|---|---|---|
| **Sales Rep** (`user`) | A seller: their leads, accounts, contacts and deals and everyone else's (open by default, D5); logs activities; moves their deals; sends logged email; runs their calendar; imports into their own lists | The launcher → `crm.<domain>`; the phone (375 px first: today's activities, a contact's page, log a call, the board scrolls sideways); the command bar; the ICS feed in their phone's calendar |
| **Sales Manager** | Runs a team: reassigns, reopens, merges, bulk-edits, owns pipelines and stages, campaigns and lost reasons; every report for every seller; the team calendar | The application, reports, the board filtered by seller or team |
| **Marketing** | Leads, sources, campaigns, lists, segments, imports and the template library; deals read-only | The application |
| **Viewer** | Finance, support, leadership: reads everything, changes nothing; a super-admin without a sales role holds this through the admin role | The application, read-only |
| **CRM admin** (`is_admin`) | Settings, custom fields, the logging mailbox, assignment rules, duplicates policy, the agents' settings, tokens, the API, data purge. A super-admin holds it | The admin area |
| **An agent** | The shipped **expert** and **Sales Assistant**, and any hired agent granted Sales Rep: reads through the records MCP under its run token, acts through the kernel's Actions MCP, pauses where §5 says | Records MCP · Actions MCP (relayed) · a chat turn · a duty |
| **A contact** (outside) | Never signs in; receives the seller's emails (through MaluMail), texts (K28 when it lands), a meeting invitation (ICS attached to the activity's email); replies land on their timeline | Email; nothing of the application |
| **An outside system** | A website form poster, a scanner, a workflow tool holding a key: creates leads and reads what the key's person may | `/api/v1/*` (§8) |
| **A sibling application** | The General Ledger making a customer from a won deal; Help Desk showing an account's open deals on a ticket and matching requesters to contacts; Inventory and Consultant Tracking taking a won deal as an order or an engagement; Spaces unfurling a deal | K7 `accounts_index` / `contacts_index` / `deals_won` / `deals_open_by_account` / `account_timeline_summary` over an approved connection |
| **The kernel** | Sign-on, the directory, the agents, approvals, the command bar (chat endpoint), texts to members (K6), the siblings' reads (K7) | — |
| **System** | The worker every minute: reminders due, the outbox, the inbound mail poll and webhook queue, rotting and no-next-step flags, segment counts, the search index catch-up, import and export jobs, the Assistant's wake | `source = 'cron'`, every row logged |

Tenancy: the business running the application is the tenant — one installation, one record database; the tenant's one memory
receives this application's activity episodes.

## 3. Who sees what — the rules, in one place

**Roles** (published by `app_roles`, `os.app-roles/1`; exactly one `is_admin`; ordered). The base role's key is `user` so the
installer's `--grant-standing-departments` makes an insider a Sales Rep where the super-admin grants the department.

| Role key | Name | Capability | Rights |
|---|---|---|---|
| `viewer` | Viewer | read | `records.read` (every lead, account, contact, deal, activity and report; no amounts hidden — D5), `calendar.read` |
| `user` | Sales Rep | write | Viewer's + `leads.write`, `leads.convert`, `accounts.write`, `contacts.write`, `deals.write` (create; edit and move **own** and unowned deals; close own), `activities.write` (own and on own records; complete any activity assigned to them), `email.log`, `email.send` (through the logging mailbox; pauses for an agent), `lists.own`, `imports.own`, `tags.apply`, `export.own`, `calendar.own`, `feed.own` |
| `marketing` | Marketing | write | Viewer's + `leads.write`, `leads.assign`, `sources.manage`, `campaigns.manage`, `lists.shared`, `segments.shared`, `imports.shared`, `templates.manage`, `tags.manage`, `export.shared` — deals and activities read-only |
| `manager` | Sales Manager | write | Sales Rep's + Marketing's + `deals.any` (edit, move, reassign, reopen, close anyone's), `activities.any`, `leads.assign`, `pipelines.manage` (pipelines, stages, probabilities, rotting, required fields, lost reasons), `records.merge`, `records.bulk`, `reports.team`, `calendar.team`, `assignment.rules` |
| `admin` | CRM admin (is_admin) | admin | every right + `settings.manage`, `fields.manage` (custom fields), `mailbox.manage`, `agents.settings`, `tokens.manage`, `api.manage`, `records.purge`, `costs.read` |

**The walls** (`crm_can_see(kind, id)`, `crm_can_edit(kind, id)`; STABLE, the principals once per statement — the kernel's db/160 lesson):

1. **Open by default (D5).** Every holder of any role sees every lead, account, contact, deal and activity, with amounts: an SMB
   sales team works one book. A record may be marked **restricted** by a manager (`restricted_to_member_id`, `restricted_to_department_id`):
   then only its owner, that department, managers and the admin see it — for the sensitive key account or the acquisition
   target. `crm_can_see()` is `NOT restricted OR owner OR in the department OR manager OR admin`.
2. **Ownership gates editing**: a Sales Rep edits what they own (`owner_member_id`) or what is unowned; a manager edits anything;
   Marketing edits leads, campaigns, lists and templates; a Viewer nothing.
3. **An activity assigned to me is mine to complete** wherever its record's ownership lies.
4. **A deal moves by its owner, a manager, or an agent acting for either** (the run-facts gate and the acting member decide); a
   stage with required fields refuses a move that lacks them, naming the fields; a won or lost deal reopens only by a manager.
5. **A merge keeps the survivor's values, the duplicate's where the survivor's are empty, every child (activities, emails, notes,
   deals, tags, campaign memberships) re-pointed**, and leaves `merged_into_id` on the loser — a deleted record is a tombstone for
   30 days, then purged by the admin.
6. **An agent sees what its member id may see** — the same functions, no special case; `is_eval` refuses every write.
7. **Email bodies** logged against a contact are visible to anyone who sees the contact (D5 again — the business's
   correspondence, not the seller's); a seller may mark one email **private** (visible to themselves and managers).
8. **Scope: none.** One business, one book; departments mirrored from the kernel are the **teams** for filters, leaderboards and
   restriction; `department` scope (one pipeline book per department) is Extended.

## 4. Doors for those who are not inside — contacts, the mailbox, keys and the siblings

The kernel's rules: *application users are not OS users*; *never a password, a login form or an account of the application's own*;
for a reach with no account, **a token is the authority**.

**A contact never signs in.** They receive what the business sends — an email (MaluMail; the From is the seller's own address or
the business's, the Reply-To the logging mailbox so the reply lands on the timeline), an ICS invitation attached to a meeting's
email, a text (K28, owed to the kernel: texting a non-member from the business's number with an opt-out — Inventory asked for
the same). A contact's **opt-outs** (`email_opt_out`, `sms_opt_out`, with the date and the reason) stop every send, an agent's
first; an unsubscribe link in every CRM-sent email writes the opt-out.

**The logging mailbox** (`mailboxes`, Help Desk's table: `crm-<token>@<mail domain>`, MaluMail, `webhook_secret_ref`) receives
BCCs and replies: the worker matches `From`/`To`/`Cc` addresses to contacts (and the sender to a member by their directory email),
creates an `activities` row of kind `email` on each matched contact (and the deal the thread is tied to, by the thread id), keeps
the `inbound_email` row with every header; an unmatched address makes a **proposed lead** the seller accepts or ignores (never a
contact by itself). Attachments go to `attachments` on the activity under the canonical store rules.

**A key** (`mcp_access_tokens`, `scope = 'api'`, Knowledge's rate columns) acts **as its person** (60/min, 5,000/day), minted on
*My settings* — or, for the admin, a **lead-capture key** bound to a source (`POST /api/v1/leads` from the website's form, creating
leads only, 600/day, nothing readable). The API (§8).

**A sibling application** reads through **K7**: `accounts_index`, `contacts_index`, `deals_won`, `deals_open_by_account`,
`account_timeline_summary` are `shares[]`; the kernel calls them over an approved connection and names the consumer
(`X-OS-Consumer`); the answer never carries email bodies or notes.

**Rejected:** a customer portal (Help Desk's secure ticket link is the outsider's door into this business); inviting contacts into
accounts; a tracking pixel in emails; buying or scraping prospect data.

## 5. Agents and the application — the OS requirement, in detail

**Looking, logging, creating, moving and scheduling inside the business are free; anything that reaches a contact, destroys or
merges a record, or changes the book's rules is a person's.** For every agent:

| Action | Category | Why |
|---|---|---|
| `email_send`, `sms_send` (K28), `meeting_invite_send`, `campaign_email_send` | `external_send` | Words reach a contact outside the business |
| `record_delete` (lead, account, contact, deal, activity — to the tombstone), `records_merge`, `records_purge`, `pipeline_delete`, `stage_delete`, `campaign_delete`, `list_delete`, `import_rollback`, `bulk_delete` | `deletion` | |
| `settings_save`, `pipeline_save`, `stage_save` (probabilities and required fields), `assignment_rules_save`, `custom_field_save`, `mailbox_save`, `agents_settings_save`, `bulk_assign`, `bulk_update`, `deal_reopen` | `other` | Configuration, the book's rules, mass change |
| `lead_create`, `lead_update`, `lead_qualify`, `lead_convert`, `account_create/update`, `contact_create/update`, `deal_create`, `deal_update`, `deal_move`, `deal_won`, `deal_lost`, `deal_product_add/remove`, `deal_contact_add/remove`, `activity_create` (schedule), `activity_complete`, `activity_reschedule`, `activity_assign` (to itself or its acting person), `note_add`, `tag_apply/remove`, `list_add/remove`, `campaign_member_add`, `email_log` (an email the person already sent, pasted or forwarded), `email_draft` (a draft the person sends), `saved_view_save` (own), `next_step_propose`, `assistant_proposal_*` | — | An agent must do these alone — this is what makes it a seller's colleague |

**Working.** (a) **The command bar**: a person's turn on the expert through the kernel's chat endpoint — *log a call with Dana at
Acme: they want a demo next Tuesday; create a deal for Acme, forty thousand, closing in March; what's closing this month; who have
we not spoken to in thirty days; move the Acme deal to proposal; what did we last say to Northwind* — the expert answers with
the records tools and acts through the registry; a draft email is a draft the person sends. (b) **A duty** (cron) for the Sales
Assistant. (c) **A dispatch** (`agent_dispatches`): a lead from the website's form wakes the Assistant for a first qualification
proposal (score, suggested owner by the rules, a draft first email); a deal entering a stage with "ask the Assistant" set wakes it
for a next-step proposal (one chat turn until K8 lands).

**Shipped agents** (`maludb-os.json` `agents[]`; **hired on install**):

| Key | Job | Reads | Acts | Pauses on |
|---|---|---|---|---|
| `expert` — Sales CRM Expert | The command bar and the house name: finds any record, answers the pipeline (value, weighted, by stage, by seller, by month), the calendar (what's due, overdue, free), the relationship (the last touches, the open deals, the tickets through Help Desk's read); logs activities and emails the person dictates; creates leads, contacts, accounts, deals; moves deals; schedules the next step; drafts an email and **pauses**; never deletes or merges | every records tool, `search`, `timeline`, `pipeline_summary`, `forecast`, `record_history` | `lead_create`, `lead_convert`, `*_create/update`, `deal_move`, `deal_won/lost`, `activity_create/complete/reschedule`, `note_add`, `tag_apply`, `email_log`, `email_send` (paused) | `email_send`, every `deletion`, every `other` |
| `assistant` — Sales Assistant | A duty every weekday 06:30 (`30 6 * * 1-5`): one **morning note** per seller — today's activities, what is overdue, deals with **no next step**, **rotting** deals, leads untouched for two days, deals closing this week without a recent touch, replies received overnight, birthdays and renewals in 30 days — and one to each manager with the team's forecast against the month, stalled deals, and the funnel's weak stage; **proposes** a next step on every open deal lacking one (a `next_step_propose` the seller accepts in one click: it becomes the activity); qualifies new leads from the form (a score from the source's history, the domain, the fields) and drafts the first email; posts each note as a kernel message to the seller's assistant when they have one, else as an in-app notification and an email | the report tools of §7, `deals_without_next_step`, `deals_rotting`, `leads_untouched`, `forecast`, `funnel` | `next_step_propose`, `lead_qualify` (a proposal, not a status), `email_draft`, `notification`, `message_send` (the kernel's) | nothing (it never sends to a contact, deletes, merges or changes a rule) |

**Skills** (`skills/`): `sales-crm-basics` (what a lead, account, contact, deal, stage, activity, reminder, campaign, list and
segment *are*; which tool answers what; the ownership rule; a draft is never sent by an agent), `activity-based-selling` (every
open deal has a next step; the four colours; how to log a touch; outcomes), `qualifying-a-lead` (what to look for, the conversion
and what it finds or makes, when a lead is unqualified and why that is recorded), `reading-the-pipeline` (weighted value, the
funnel, rotting, cycle time, what to say to a manager); runbooks (`kind: runbook`): `morning-note`, `propose-the-next-step`,
`log-a-call-from-dictation`, `convert-a-lead-into-a-deal`, `find-and-merge-duplicates` (a proposal for the manager).

## 6. Memory model — what Sales CRM remembers

One store: **record memory** (PostgreSQL 17, `<tenant>_sales_crm`) holds leads, accounts, contacts, deals and their stages,
products and contacts, activities with reminders, emails in and out, campaigns and members, lists and segments, tags, custom
fields, imports, saved views, the search index and the kit; attachments on disk under the canonical store rules; the tenant's
memory receives the activity episodes. Every record carries `owner_member_id`, `created_by`, `created_at`, `updated_at`,
`deleted_at` (the 30-day tombstone), `custom_values jsonb`, `restricted_*` (§3) and a `search_index` row by trigger.

### 6.1 Leads, accounts, contacts (`app/features/leads/`, `accounts/`, `contacts/`)

**`leads`**: `id`, `first_name`, `last_name`, `company`, `title`, `email` citext, `phone`, `website`, `address` (text), `city`,
`region`, `country`, `source_id` → `lead_sources`, `campaign_id`, `status new|working|qualified|unqualified` with
`unqualified_reason_id` → `lost_reasons (kind lead)`, `score` (0–100, the Assistant's proposal or a rule; shown, never a gate),
`owner_member_id`, `last_touched_at` (any activity or email), `converted_at`, `converted_account_id`, `converted_contact_id`,
`converted_deal_id`, `proposed_from_email_id` (a lead the mailbox proposed), `custom_values`, the common columns. A lead is
**converted** by `lead_convert` (an account found by domain or name or made; a contact found by email or made; a deal made when the
form says so, in the chosen pipeline's first stage, owned by the lead's owner) in one transaction; the lead becomes `qualified`
and read-only, pointing at the three. **`lead_sources`** (a vocabulary: Website, Referral, Event, Outbound, Partner, Import,
Mailbox; `kind inbound|outbound`, `active`) and **`assignment_rules`** (ordered; `match jsonb` on source, country, company size,
campaign; `assign_to member|department|round_robin[]`; the first match wins; none → unowned, the manager's inbox).

**`accounts`** (**new canonical**): `id`, `name`, `legal_name`, `domain` citext (unique when present — the dedupe key), `website`,
`industry`, `employees` (a band), `annual_revenue` numeric, `currency` char(3), `phone`, `email` citext, `billing_address`,
`shipping_address`, `city`, `region`, `country`, `kind prospect|customer|partner|former|other` (**derived** by trigger: a won deal
→ `customer`; set by hand only to `partner`/`other`), `parent_account_id` (a hierarchy, one level shown), `owner_member_id`,
`source_id`, `description`, `gl_customer_id` (the ledger's id, written when the GL's read answers), `helpdesk_organization_id`,
`last_touched_at`, `next_activity_at`, `open_deals_count`, `open_deals_value` (kept by trigger), `tags` through `record_tags`,
`custom_values`, the common columns. The columns `name`, `legal_name`, `email`, `phone`, `billing_address`, `shipping_address`,
`currency`, `notes`→`description`, `member_id`→`owner_member_id`, `archived_at`→`deleted_at` keep the names of GL `customers` where
the concept is the same, so the GL's import of a won account maps by name.

**`contacts`** (**new canonical**): `id`, `account_id` (nullable), `first_name`, `last_name`, `name` (generated), `title`,
`department_text`, `email` citext, `email_alt` citext, `phone`, `mobile`, `role` (Decision maker, Champion, User, Finance,
Procurement, Other — the deal-level role lives on `deal_contacts`), `is_primary` (one per account, by partial unique index),
`linkedin_url`, `address`, `city`, `region`, `country`, `timezone`, `preferred_channel email|phone|sms`, `birthday` date,
`lifecycle` (**derived**: `lead` while any lead points at them unconverted → `prospect` with an open deal → `customer` on an
account with a won deal → `churned` when the account turns `former`), `email_opt_out`, `email_opt_out_at`, `sms_opt_out`,
`sms_opt_out_at`, `opt_out_reason`, `do_not_call`, `owner_member_id`, `source_id`, `last_touched_at`, `next_activity_at`,
`merged_into_id`, `custom_values`, the common columns. **`contact_emails`** (contact, address citext unique, `is_primary`,
`verified_at`) so the mailbox matches every address a person uses.

**Duplicates**: `crm_duplicates(kind)` finds pairs by `email`/`contact_emails` (exact), `domain` (exact), `name` + `city`
(trigram ≥ 0.6), `phone` (digits only); shown on the record ("Possible duplicate of …") and in the manager's *Duplicates* screen;
`records_merge(kind, survivor, loser)` per §3.5. **Import** (`imports`, Spaces' table): a CSV up to 50,000 rows, a mapping saved
as a template (`import_templates`), a dedupe policy (skip / update / create), a dry run with counts, then the job by the worker
in batches of 500, every row's result kept, a rollback within 24 h (the rows it created are tombstoned).

### 6.2 Pipelines, stages, deals and the board (`app/features/pipelines/`, `deals/`, `board/`)

**`pipelines`**: `id`, `name`, `key` (`new_business`), `is_default`, `currency` (NULL = the business's), `rotting_days` default
(NULL = off), `position`, `archived_at`. **`stages`**: `id`, `pipeline_id`, `name`, `position`, `probability` (0–100),
`kind open|won|lost` (one won, one lost per pipeline, always last), `rotting_days` (NULL = the pipeline's), `required_fields
text[]` (deal columns and custom field keys a deal needs to enter), `ask_assistant` (a dispatch on entry), `colour`, `wip_limit`
(a warning, never a refusal), `archived_at`. Seeded pipeline *New business*: Qualification 10 → Discovery 25 → Proposal 50 →
Negotiation 75 → Won 100 → Lost 0; *Renewals*: Upcoming 30 → In discussion 60 → Agreed 90 → Won → Lost.

**`deals`**: `id`, `number` (`D-000123`, `document_sequences`), `title`, `account_id`, `primary_contact_id`, `pipeline_id`,
`stage_id`, `position` (within the column — the drag's order), `value` numeric(14,2), `currency`, `probability_override`
(NULL = the stage's), `weighted_value` (generated: value × coalesce(override, stage.probability)/100 — kept by trigger since it
crosses tables), `expected_close_date`, `status open|won|lost` (follows the stage's kind by trigger), `won_at`, `lost_at`,
`lost_reason_id` → `lost_reasons (kind deal)`, `lost_note`, `source_id`, `campaign_id`, `owner_member_id`, `stage_entered_at`,
`last_touched_at`, `next_activity_id`, `next_activity_at` (the two kept by trigger from `activities` — the card's colour),
`is_rotting` (generated in the read: `stage_entered_at < now() - rotting_days`), `has_next_step` (read: `next_activity_at IS NOT
NULL`), `closed_by`, `description`, `custom_values`, the common columns. **`deal_stage_history`** (deal, from_stage, to_stage,
member, at, `seconds_in_from_stage`) — the funnel, conversion and cycle time read this and nothing else. **`deal_contacts`** (deal,
contact, `role decision_maker|champion|influencer|user|finance|procurement|other`, `is_primary`). **`deal_products`** (**new
canonical**: deal, `position`, `name`, `provider_app` (`inventory`/`gl`/NULL), `provider_id`, `sku`, `quantity` numeric(12,3),
`unit_price` numeric(14,4), `discount_percent`, `line_total` generated, `recurring none|monthly|yearly`); `deals.value` follows the
lines' sum when any line exists (a switch per deal: `value_from_products`). **`lost_reasons`** (`kind lead|deal`, `label`,
`position`, `active`; seeded: Price, No budget, Timing, Went with competitor, No decision, Not a fit, Unresponsive).

**The board** (`/pipeline/{pipeline}/board`; `docs/build-specs/pipeline-board.md`): the columns partial as Projects' (polled every
30 s and on `dealChanged`), one column per open stage plus the Won and Lost columns collapsed to a drop target with the period's
count and value; a card: title, account, value, owner avatar, the next activity's icon and date in the four colours (green
scheduled, amber today, red overdue, grey none → "No next step"), the rotting mark, tags, a flag for a stage whose required
fields are missing; the column head: name, count, sum, weighted sum, the WIP warning; **a drag is `POST /deals/{deal}/move`**
(`stage_id`, `position`) — SortableJS from the theme's bundle is the enhancement, the card's stage `<select>` and *Move…* in the
card menu stand alone; a move into a stage with required fields the deal lacks is refused with the fields named and the card
snaps back; a drop on Won opens the won form (close date, value confirm); on Lost the lost form (reason, note). Filters: owner,
team (department), period of expected close, tag, source, campaign, value range, "no next step", "rotting", `q`. Views of the same
query: the **list** (sortable, grouped by stage or owner, columns from `saved_views`), the **forecast** (rows = sellers or
stages, columns = the next six months by expected close, cells = value and weighted; totals; the manager's quota per month as a
line when set in `settings`). The board scrolls sideways with a visible scrollbar at 375 px, one column wide, a stage chooser above
it; a card opens the deal; nothing is a modal but the record picker and `hx-confirm`.

### 6.3 Activities, reminders, the calendar and email (`app/features/activities/`, `calendar/`, `email/`)

**`activities`** (**new canonical** — the estate's first activities table; Help Desk's and Projects' worklogs are time, not touches):
`id`, `kind call|email|meeting|task|deadline|lunch|note_call_back`, `subject`, `body` (Markdown), `account_id`, `contact_id`,
`deal_id`, `lead_id` (any of the four; a trigger fills `account_id` from a deal or contact), `owner_member_id` (who does it),
`created_by`, **the time**: `due_at` timestamptz (a task or deadline), `starts_at` / `ends_at` (a meeting, call or lunch), `all_day`
boolean, `timezone`; **the state**: `done_at`, `done_by`, `outcome connected|no_answer|left_message|busy|meeting_held|no_show|sent|
replied|completed|cancelled` (per kind), `outcome_note`; **the reminder**: `remind_minutes_before` (NULL = none; default from
`notification_prefs.default_remind_minutes`, 30), `remind_at` (generated), `reminded_at`, `snoozed_until`; **email**: `email_id`
→ `inbound_email` / `outbound_email`, `direction in|out`, `is_private`; `location`, `meeting_url`, `participants` through
`activity_participants (activity, contact_id | member_id)`; `priority low|normal|high`; `recurrence` (RRULE text, Extended: v1
stores it, expands daily/weekly/monthly by the worker for tasks only); `source` (screen, command_bar, agent, mailbox, api,
import); `custom_values`, the common columns. Indexes on `(owner_member_id, done_at, due_at)`, `(deal_id)`, `(contact_id)`,
`(remind_at) WHERE reminded_at IS NULL AND done_at IS NULL`.

**The calendar** (`/calendar`, `docs/build-specs/activities-calendar.md`): **server-rendered** month, week and day grids and an
agenda list, as the cidery's schedule — `GET /calendar?view=month&at=2026-10&who=me|<member>|team:<dept>&kinds=…` renders the
grid partial; each cell lists the day's activities as chips (kind icon, time, subject, the record's name) coloured by state (done
muted, overdue red, today amber, scheduled by kind); a click opens the activity (full page); **New** on a day opens the create form
with the date set; a drag of a chip to another day is `POST /activities/{activity}/reschedule` (SortableJS between cells —
the enhancement; the form stands alone); the week view shows the hours 07–19 with meetings as blocks; the agenda is the phone's
default (today, tomorrow, this week; overdue first); a **Today** strip on the home page. The **team calendar** is the same query
for a department's members, one colour per person, read by managers. **ICS**: `calendar_feeds` (txtSchedules') — one private URL
per person, their own open activities for the next 90 days and the last 30, `VEVENT` with `VALARM` from `remind_minutes_before`,
the record's name in the title and its URL in the description; a meeting's invitation to a contact is the same `VEVENT` attached to
the email the seller sends. Two-way sync is Extended (§11).

**Reminders**: the worker, every minute: `activities WHERE remind_at <= now() AND reminded_at IS NULL AND done_at IS NULL AND
(snoozed_until IS NULL OR snoozed_until <= now())` → a `notifications` row (in-app, the bell), an email through the outbox when
`notification_prefs.email` says so, a text through K6 when `sms` is on and the phone is verified in the kernel; `reminded_at` set
in the same transaction; **snooze** (10 min, 1 h, tomorrow 09:00) clears `reminded_at` and sets `snoozed_until`; a reminder for an
activity assigned to an agent is a dispatch (`kind reminder`) instead. The morning note (§5) is the digest; `notification_prefs`
appends `default_remind_minutes`, `morning_note` (on/off, the hour), `digest`.

**Email**: **in** — the logging mailbox (§4) and the webhook `POST /channels/malumail/mail` (the kernel's shape, signed with the
mailbox's secret) plus a once-a-minute poll of unread mail as a fallback; the worker's matcher (addresses → `contact_emails` →
contacts; the sender → a member; the thread → a deal by `references_ids` of an email the CRM sent or logged; the subject's
`[D-000123]` tag as the fallback) makes one `activities (kind email, direction in)` per matched contact sharing one
`inbound_email`; nothing matched → a proposed lead; a bounce or an auto-reply sets the status and never makes an activity. **Out** —
`email_send` from a contact's or deal's page: `To` from the record's contacts, a **template** (`email_templates`: name, subject,
body with merge fields `{{contact.first_name}}`, `{{account.name}}`, `{{deal.title}}`, `{{sender.name}}`, `{{sender.signature}}`,
shared or own, Marketing manages the shared ones), attachments, the ICS of a meeting; sent through MaluMail with `Reply-To` the
logging mailbox and the thread id kept in `outbound_email`, logged as `activities (kind email, direction out)` on every recipient
contact and the deal; the unsubscribe link; a contact opted out is refused by name. `email_log` pastes or forwards one the person
sent elsewhere. **Templates are the only automation**; sequences are Extended.

### 6.4 Campaigns, lists, segments, tags, custom fields, saved views (`app/features/campaigns/`, `lists/`, `settings/`)

**`campaigns`**: `id`, `name`, `kind email|event|webinar|ads|content|outbound|referral|other`, `status planned|active|done|cancelled`,
`starts_on`, `ends_on`, `budget` numeric, `currency`, `expected_leads`, `owner_member_id`, `description`, `utm_campaign`, the common
columns; **`campaign_members`** (campaign, `lead_id` | `contact_id`, `status sent|opened|responded|attended|converted|unsubscribed`,
`added_by`, `added_at`, `responded_at`); results **derived**: leads with `campaign_id`, deals with `campaign_id` (value won,
open), cost per lead and per won deal. **`lists`** (`kind static|smart`, `record_kind lead|contact|account|deal`, `name`,
`filters jsonb` (the bounded vocabulary of §7's `find_*` filters, for a smart list), `owner_member_id`, `is_shared`,
`member_count` (kept by the worker hourly for smart lists), the common columns) and **`list_members`** (list, record_id, added_by,
added_at — static only); **bulk actions** over a list or a filtered screen (assign owner, apply or remove a tag, add to a
campaign, set a field, add to a list, export, delete → tombstone) run in one transaction up to 1,000 rows, the worker beyond,
every row logged. **`tags`** + **`record_tags`** (Help Desk's shape with `record_type`). **`custom_field_defs`** (Help Desk's,
`applies_to` widened, `field_type` + `url`, `email`, `money`, `member`) with `custom_values` on the five records; shown in a
*Details* section of every form and page, filterable, importable. **`saved_views`** (Help Desk's; `pipeline_id` for `queue_id`;
`layout list|board|calendar|forecast`). **`email_templates`**, **`import_templates`**, **`crm_settings`** (the singleton: the
business's currency, the fiscal year start for the forecast, the default pipeline, `rotting_days`, the duplicates policy, the
mailbox id, the quota per month per seller as `quotas (member, month, amount)`, the weighted method, the morning note's hour, the
Assistant's reach, `allow_restricted_records`).

### 6.5 Tables against the estate — read, reuse, new (the shared-schema rule)

| Table(s) | Decision | Source and what differs |
|---|---|---|
| `members`, `departments`, `department_members`, `sso_nonces`, `member_sessions`, `directory_sync_state` | **reuse** | DocCloud `db/001` / Consultant Tracking `db/001`; `members` with `is_agent`, `is_external`, `roles text[]`; **appends** `email_signature` (for merge fields), `phone_display` |
| `activity_log`, `activity_ingest_state` | **reuse** | `memory.md` / CT `db/002`; **appends** `account_id`, `contact_id`, `deal_id`, `lead_id` |
| `mcp_access_tokens`, `key_usage` | **reuse** | HR `db/003` + Knowledge's rate columns; **appends** `lead_source_id` (a lead-capture key) |
| `crm_rights`, `crm_roles`, `crm_role_rights`, `mcp_app_roles` | **reuse** (shape) | CT `db/004` (`ct_` → `crm_`) |
| `crm_settings` | **reuse** (skeleton) | the `app_settings` singleton |
| `document_sequences` | **reuse** | GL `db/006`; `kind` = `deal` |
| `notes`, `attachments` | **reuse** | GL `db/009`; `record_type` widened to `lead|account|contact|deal|activity|campaign` |
| `notifications`, `notification_prefs`, `notification_outbox` | **reuse** | Spaces `db/012` (member-only; no external-party column); `kind` ours; prefs **append** `default_remind_minutes`, `morning_note`, `morning_hour`, `sms` |
| `agent_dispatches` | **reuse** | GL `db/014` = CT `db/013`; `kind` ours (`lead_qualify`, `next_step`, `reminder`, `ask`); **appends** `deal_id`, `lead_id` |
| `mailboxes`, `inbound_email`, `outbound_email` | **reuse** | Help Desk `db/012`; `queue_id` dropped (one logging mailbox; the FK column kept nullable for the verbatim rule), `ticket_id`/`message_ref_id` → **appended** `contact_id`, `activity_id`, `deal_id`, `match_status matched|proposed_lead|unmatched|bounce` |
| `tags`, `record_tags` | **reuse** | Help Desk `db/009` `tags` verbatim; `record_tags` = `ticket_tags` + `record_type` (the DocCloud `document_tags` precedent) |
| `custom_field_defs` | **reuse** | Help Desk `db/009`; `applies_to` widened; `field_type` widened (`url`, `email`, `money`, `member`) |
| `saved_views` | **reuse** | Help Desk `db/011`; `queue_id` → `pipeline_id`; `layout` widened |
| `search_index` | **reuse** | Spaces `db/013` canonical; **appends** `account_id`, `owner_member_id`; `entity_kind` widened |
| `imports`, `exports`, `worker_passes` | **reuse** | Spaces `db/016` + `023`; `kind` widened |
| `calendar_feeds` | **reuse** | txtSchedules `db/012` verbatim |
| `accounts`, `contacts`, `contact_emails` | **new canonical** | compared: GL/Inventory `customers` (a billing party — read instead), Help Desk `organizations`/`requesters` (support's view of the same companies and people — read instead), CT `clients`/`client_contacts` (an engagement's client); none is a company at every point of a relationship or a person who may belong to no company. The shared column names are kept (§6.1) |
| `leads`, `lead_sources`, `assignment_rules` | **new canonical** | nothing in the estate qualifies anyone |
| `pipelines`, `stages`, `deals`, `deal_stage_history`, `deal_contacts`, `deal_products`, `lost_reasons` | **new canonical** | compared: Projects `workflow_statuses`/`board_columns` (an issue's workflow, with a mapping table a stage does not need), Inventory `sales_orders` (an order, after the sale). A stage is a column; a deal is not an order |
| `activities`, `activity_participants` | **new canonical** | compared: Spaces `reminders` (a note to self), Help Desk `time_entries`/Projects `worklogs` (time), Help Desk `messages` (correspondence inside a ticket). A dated touch with an owner, a state, an outcome and a reminder is new. The next sibling that schedules calls or meetings (Pro Appointments, Reservations' follow-ups) copies this |
| `campaigns`, `campaign_members`, `lists`, `list_members`, `email_templates`, `import_templates`, `quotas` | **new canonical** | compared: Help Desk `canned_responses` (a template without merge fields — `email_templates` keeps its `name`/`subject`/`body` names), Spaces' database views (a filter, inside a page) |
| `customers` (GL), `products`/`product_variants` (Inventory), `items` (GL), `tickets` (Help Desk), `employment` (HR) | **read** | `gl.customer_by_crm_account` (G3, owed), `inventory.availability_index` (built), `gl.items_index` (G4, owed), `helpdesk.tickets_by_organization` (HD5, owed) — each degrades to a typed value or an empty panel on `no_connection` |

New canonical tables (`accounts`, `contacts`, `contact_emails`, `activities`, `activity_participants`, `deal_products`,
`record_tags` already DocCloud's shape) go into the plugin's `shared-schema.md` catalogue in the same change (§12, the plugin item).

## 7. The question inventory — what Sales CRM exists to answer

Record questions are tools on the records MCP (`crm_` views over `<tenant>_sales_crm` as `sales_crm_records_ro`); activity questions
are the activity MCP over the tenant's memory filtered to `application = sales_crm`. **A question with no tool is unfinished design.**

**Leads and prospecting**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| 1 | Which leads are new, unowned, or untouched for N days? | record | rep, manager, Assistant | `find_leads` (status, owner, source, campaign, untouched_days, q) |
| 2 | Where did this lead come from, and what has happened to it? | record | rep | `get_lead`, `timeline` |
| 3 | Which sources and campaigns produce leads that convert and win? | record | manager, marketing | `source_performance`, `campaign_results` |
| 4 | Is this lead (or contact, or account) a duplicate of something we have? | record | rep, manager | `find_duplicates` |
| 5 | What did the import do, and can it be undone? | record | manager | `get_import`, `import_rows` |
| 6 | Who is in this list or segment right now? | record | marketing, rep | `list_members`, `segment_preview` |

**Accounts and contacts**
| 7 | Who are our accounts, by kind, owner, industry, country, last touch? | record | all | `find_accounts` |
| 8 | Everything about this account: contacts, open deals, won history, next step, tickets, the timeline | record | all | `get_account`, `account_contacts`, `account_deals`, `timeline`, `account_tickets` (the K7 read) |
| 9 | Who do we know at this company, and who is the decision maker? | record | rep, expert | `account_contacts`, `deal_contacts` |
| 10 | Who have we not spoken to in 30 / 60 / 90 days? | record | rep, Assistant | `accounts_untouched`, `contacts_untouched` |
| 11 | What was the last thing we said to this person, and what did they say? | record | rep, expert | `timeline (kind email)`, `get_email` |
| 12 | Which contacts have opted out, and when? | record | marketing, admin | `find_contacts (opt_out)` |
| 13 | Whose birthday or renewal is in the next 30 days? | record | rep, Assistant | `upcoming_dates` |

**Pipeline and deals**
| 14 | What is in the pipeline, by stage: count, value, weighted? | record | all | `pipeline_summary` |
| 15 | What is closing this month / quarter, and how sure are we? | record | rep, manager, expert | `forecast` (by month, by seller, by stage; value, weighted, quota) |
| 16 | Which deals have no next step? Which are rotting? Which are closing soon with no recent touch? | record | rep, manager, Assistant | `deals_without_next_step`, `deals_rotting`, `deals_at_risk` |
| 17 | Everything about this deal: stage history, contacts, products, activities, emails, the timeline | record | all | `get_deal`, `deal_history`, `deal_products`, `timeline` |
| 18 | What did we win and lose this period, and why did we lose? | record | manager | `deals_closed`, `lost_reasons_summary` |
| 19 | What is our win rate and our sales cycle, by seller, source, pipeline, period? | record | manager | `win_rate`, `cycle_time` |
| 20 | Where does the funnel leak — which stage loses the most, and how long do deals sit there? | record | manager | `funnel` (from `deal_stage_history`) |
| 21 | Who is ahead and behind on quota? | record | manager | `leaderboard`, `forecast (quota)` |
| 22 | May this deal enter that stage — what fields are missing? | record | rep, expert | `stage_requirements_check` |
| 23 | What products are on open deals, and what is their total? | record | manager | `products_pipeline` |

**Activities and the calendar**
| 24 | What do I have to do today, this week; what is overdue? | record | rep, expert, Assistant | `my_activities` (today, week, overdue), `find_activities` |
| 25 | What is on the team's calendar on this day or week, and who is free? | record | manager | `calendar (who=team)` |
| 26 | How many calls, emails and meetings did each seller log this week, and with what outcomes? | record | manager | `activity_summary` |
| 27 | What happened on this record, in order? | record | all | `timeline (record)` |
| 28 | Which reminders are due, snoozed, or failed to send? | record | admin | `reminders_due`, `notification_outbox` |

**Email**
| 29 | Which emails arrived that matched no one? | record | rep, marketing | `emails_unmatched`, `proposed_leads` |
| 30 | Which templates exist and how often has each been used? | record | marketing | `find_templates` |
| 31 | Is the logging mailbox alive — the last webhook, the last poll, the failures? | record | admin | `mailbox_health` |

**The business and the admin**
| 32 | Which campaigns are running, what did they cost, what did they bring? | record | marketing, manager | `find_campaigns`, `campaign_results` |
| 33 | What changed on this record, by whom, when? | record | all | `record_history` |
| 34 | Who holds which role, and which agents work here? | record | admin | `app_roles`, `members_with_roles` |
| 35 | What are the custom fields, pipelines, stages, sources, reasons, rules? | record | admin, expert | `settings_catalogue` |
| 36 | Which keys exist and how much have they been used? | record | admin | `find_tokens`, `key_usage_summary` |
| 37 | What did the Assistant propose, and what was accepted? | record | manager | `assistant_proposals` |

**Shares for the siblings (K7)**
| 38 | Which accounts exist, with the ledger's id when known? | share | the GL, Help Desk, Inventory, CT | `accounts_index` |
| 39 | Which contacts, by email, belong to which account? | share | Help Desk (requesters), Spaces | `contacts_index` |
| 40 | Which deals were won in a period — account, contacts, value, products? | share | the GL (a customer and an invoice), Inventory (an order), CT (an engagement) | `deals_won` |
| 41 | What open deals does this account have? | share | Help Desk (on a ticket), Spaces (a card) | `deals_open_by_account` |
| 42 | When did we last touch this account and what is next? | share | Help Desk, Spaces | `account_timeline_summary` (dates and subjects only; no bodies) |

**Activity memory (MaluDB, the tenant's)**
| 43 | When did we first talk to Acme about the renewal, and who was on it? | activity | expert | `activity_search` |
| 44 | Who moved this deal back a stage last month, and why? | activity | manager | `actor_history`, `record_episodes` |
| 45 | What did the agents do here this week — and what did they propose that nobody took? | activity | manager, Auditor | `agent_activity` |
| 46 | How many touches did it take to win the deals we won this quarter? | activity + record | manager | `touches_to_win` (record), `activity_timeline` |

Guarded search: `search` (the one box — `search_index` over leads, accounts, contacts and deals, visibility as a per-row gate) and
`activity_search` (the activity server's). Every `find_*` resolver answers `{"rows": [...]}` for the kernel's registry wrapper.

## 8. The kernel's part, and the application's doors

- **Sign-on and the mirror**: the contract (`sign-on-and-directory.md`), the kit from DocCloud; on sign-on and every sync a member's
  `notification_prefs` row exists with the defaults; departments mirrored are the teams.
- **Roles**: `app_roles` on the records server; the kernel's token admitted to it and the `shares[]` alone (reading
  `X-OS-Consumer` / `X-OS-Consumer-Agent`, K26).
- **Agents**: run tokens on the two read servers (the run-facts gate, fail closed); the Actions MCP from the registry
  (`deploy/kernel-registry-sales_crm.json`, a `find_<entity>` resolver per entity: `find_leads`, `find_accounts`, `find_contacts`,
  `find_deals`, `find_activities`, `find_pipelines`, `find_stages`, `find_campaigns`, `find_lists`, `find_templates`,
  `find_members`); the approval hook before every action with a category; the chat endpoint for the command bar and dispatches;
  the duty for the Assistant.
- **Texts (K6)**: a reminder to a seller (opt-in, a verified phone). **K28** (owed): a text to a contact.
- **Mail**: the installer's `mail` step fills `MALUMAIL_API_KEY`, `MAIL_FROM`, `MAIL_FROM_NAME`; `ROOT_STEPS.sh` prints the
  logging address to create at MaluMail with its webhook secret (`MAILBOX_CRM_WEBHOOK_SECRET`) — the one manual step, as Help
  Desk's.
- **The API** (bearer = a key, acting as its person or as a lead-capture key; allow-listed in the vhost): `POST /api/v1/leads`
  (a lead-capture key: `first_name`, `last_name`, `email`, `company`, `phone`, `message`, `utm_*`, `source` fixed by the key;
  600/day, nothing readable, the website's form posts here), `GET /api/v1/leads|accounts|contacts|deals` (+ `/{id}`, the `find_*`
  filters), `POST /api/v1/{kind}` and `PATCH /api/v1/{kind}/{id}` (as the person), `POST /api/v1/activities`, `GET
  /api/v1/calendar.ics?token=` (the feed; `auth_kind none`, the token the authority), `GET /api/v1/health`, `POST
  /channels/malumail/mail` (the signed webhook). Every call logged with the key's label; 60/min, 5,000/day per key.
- **Vhost allow-list (public)**: `/api/v1/`, `/channels/malumail/mail`, `/mcp/records`, `/mcp/activity`; the ICS route is
  `/api/v1/calendar.ics` so the allow-list stays one prefix.

## 9. Screens (the nxl look; the phone screens — today, a contact, log a call, the agenda — are read at 375 px first; the board, the
calendar's month and week and the forecast are designed at 1280 px and must still work at 375 px, the board one column at a time)

| Screen | Questions | Notes |
|---|---|---|
| `/` **home** | 24, 14, 16, 13 | *Today*: my activities due and overdue; my deals closing this month (value, weighted); no next step and rotting counts as links to the filtered board; replies received since yesterday; the morning note's last text; a manager sees the team's forecast tile and the leaderboard sparkline |
| `/leads`, `/leads/{id}`, `/leads/new`, `/leads/{id}/edit`, `/leads/{id}/convert` | 1, 2, 4 | the list is **cards** (the owner's rule: lists of named things are cards) with status chips, the source, the owner, last touched; the detail page: fields, the duplicate warning, the timeline, *Convert* (the form: account found or new, contact found or new, deal yes/no with pipeline, title, value, close date), *Unqualify* (reason) |
| `/leads/inbox` | 1, 29 | unowned and proposed leads (from the mailbox and the form), assign or dismiss in bulk |
| `/accounts`, `/accounts/{id}`, `/accounts/new`, `/accounts/{id}/edit` | 7, 8, 9, 10 | cards; the detail page has tabs by anchor, no modal: Overview (fields, kind, owner, the next step), Contacts (cards, primary first), Deals (open then closed, with the board link), Activities (agenda), Emails, Notes & files, Tickets (K7, empty panel on `no_connection`), History; *Log call*, *Schedule*, *New deal*, *Send email* in the page header |
| `/contacts`, `/contacts/{id}`, `/contacts/new`, `/contacts/{id}/edit` | 7, 11, 12, 13 | cards; the detail page as the account's: the opt-out banner; *Log*, *Schedule*, *Send email* |
| `/pipeline/{pipeline}/board` · `/list` · `/forecast` | 14, 15, 16, 22, 23 | **the board** (§6.2); the list; the forecast grid; the pipeline chooser; saved views in the toolbar |
| `/deals/{id}`, `/deals/new`, `/deals/{id}/edit`, `/deals/{id}/won`, `/deals/{id}/lost` | 17, 22 | the detail page: the stage bar (click a stage = move, refused with fields named), value and weighted, close date, owner, contacts with roles (the record picker), products (lines with the picker over `inventory.availability_index` when connected, else typed), the next step, the timeline, the stage history as a strip with durations |
| `/calendar` (month · week · day · agenda) | 24, 25 | §6.3; `who=` me / a colleague / a team; kinds filter; **New** on a cell; the team view read by managers |
| `/activities`, `/activities/{id}`, `/activities/new`, `/activities/{id}/edit` | 24, 26, 27, 28 | the list (mine / team / all; due, overdue, done; by kind); the detail page: the record it belongs to, participants, the reminder, *Done* with outcome and the **next step prompt** ("Schedule the next activity?" — the Pipedrive method, a form section, not a modal), *Snooze*, *Reschedule* |
| `/log/call`, `/log/email`, `/log/meeting` | 27 | the quick-log forms the phone and the command bar use: record picker, outcome, note, the next step |
| `/email/compose` (from a record), `/email/{id}` | 11, 29, 30 | the compose page (template picker, merge preview, attachments, the ICS when from a meeting); an email's page (headers, body, the contacts matched, the deal, *Mark private*) |
| `/email/unmatched` | 29 | emails matched to no one: *Make lead*, *Attach to contact* (picker), *Ignore* |
| `/campaigns`, `/campaigns/{id}`, `/campaigns/new`, `/campaigns/{id}/edit` | 3, 32 | cards; the detail: members (status), leads produced, deals produced (value won/open), cost per lead and per won deal |
| `/lists`, `/lists/{id}`, `/lists/new`, `/lists/{id}/edit` | 6 | static and smart; the filter builder is the `find_*` vocabulary as form fields; bulk actions bar on the list and on every filtered list screen |
| `/import`, `/import/{id}` | 5 | upload, mapping (saved templates), dedupe policy, dry run counts, run, the rows with results, *Roll back* |
| `/duplicates` | 4 | pairs by kind with the matching fields highlighted; *Merge* (choose the survivor — a full page comparing the two) |
| `/reports` and `/reports/{funnel,win-rate,cycle-time,sources,campaigns,activity,leaderboard,lost-reasons,forecast}` | 3, 15, 18–21, 26 | tables and the dataviz skill's charts (bar, funnel, line); filters by period, seller, team, pipeline, source; CSV export of every table |
| `/settings` (the person's) | — | notification prefs (reminders, email, text, the morning note and its hour), the ICS link (rotate), signature, keys |
| `/admin/pipelines`, `/admin/pipelines/{id}`, `/admin/stages/{id}/edit` | 35 | pipelines and stages: order (SortableJS as the enhancement; position fields stand alone), probability, rotting, required fields, ask-the-Assistant, colours, WIP |
| `/admin/sources`, `/admin/reasons`, `/admin/rules`, `/admin/fields`, `/admin/templates`, `/admin/tags`, `/admin/mailbox`, `/admin/quotas`, `/admin/agents`, `/admin/tokens`, `/admin/settings`, `/admin/purge` | 31, 34–37 | the vocabularies and rules; the mailbox's health; quotas per seller per month; the Assistant's settings (hour, reach, proposals on/off per pipeline); keys incl. lead-capture keys; the tombstones to purge |
| `/search?q=` | all | the one box in the header; results grouped by kind with highlights |

About 60 screens; every one works as a full page and as an HTMX partial; forms carry `page-header-form` with the pinned Save;
record fields that name a record (account, contact, deal, owner, pipeline, stage, template, campaign, list, product) are **record
pickers**, never selects of every row; `hx-confirm` on destructive controls only; the record picker is the one modal.

## 10. Build order, gates and size

| Phase | Deliverable | Gate |
|---|---|---|
| 0 | this document and the owner's answers; the schema `db/001`–`0NN` proven on a scratch database (`db/proof/phase0_proof.sql`: every actor of §2 and both walls incl. a restricted account; a lead converted into a found account, a new contact and a deal in one transaction; a lead converted twice refused; the seeded pipelines; a deal moved stage by stage with `deal_stage_history` and `seconds_in_from_stage`; a move into a stage with required fields refused with the names; won → status, `won_at`, the account's `kind` turning `customer`, the contact's lifecycle; lost with a reason; reopen by a manager alone; `weighted_value` following the stage and the override; `deals.value` following the products; `next_activity_at` and the four colours as an activity is scheduled, done, rescheduled; a reminder due, sent, snoozed; the ICS feed's rows; an inbound email matched to two contacts and a deal by thread, an unmatched one proposing a lead, a bounce making nothing; an opt-out refusing a send; a merge re-pointing every child and leaving the tombstone; a smart list's count; an import's dry run, run and rollback; `search_index` honouring the wall; `custom_values` validated against `custom_field_defs`; the reused tables verbatim against their siblings); the kit proven without a kernel (`tests/phase0/run.sh`); `maludb-os.json`, `os/{expert,assistant}.md`, nine skills (five runbooks), `deploy/` with `ROOT_STEPS.sh` (ports, the mailbox note); the installer's `plan` clean | the owner's go |
| 1 | `docs/sales-crm-mcp-tool-surface.md`, `docs/sales-crm-action-manifest.md`, `mcp/action_registry.json`, `docs/build-specs/pipeline-board.md`, `docs/build-specs/activities-calendar.md`, `docs/build-specs/leads-and-conversion.md`, and the slice specs (`sso-shell` and nine slices; **the board and the calendar are the exemplars**) | **approved together, before any PHP** |
| 2 | `/sso`, `/sso/logout`, the mirror and its timer, `members.roles`, `crm_has_right()`, the ingest bridge, the shell with the record picker and the application switcher, the one search box, `/api/v1/health`, the vhost allow-list, the worker's empty pass | hand-off replay refused; an unknown member refused; a revoked grant shuts every door within a minute; 375 and 1280 |
| 3 | the slices, in order: **(1) accounts and contacts** (the CRUD exemplar: cards, detail pages with anchored tabs, the primary contact, `contact_emails`, opt-outs, restriction, notes and files, tags, custom fields on the forms, the duplicate warning, the timeline's skeleton) → **(2) leads, sources, conversion, the inbox, import and duplicates** (`leads`, `lead_sources`, `assignment_rules`, `lead_convert`, the inbox, `imports` with templates, dry run and rollback, `find_duplicates` and `records_merge`) → **(3) pipelines, deals and THE BOARD — THE FIRST EXEMPLAR** (pipelines and stages admin, deals with contacts and products, `deal_move` with required fields and history, won and lost forms, the board with the four colours, rotting, the list and the forecast, saved views) → **(4) activities, reminders and THE CALENDAR — THE SECOND EXEMPLAR** (activities of every kind, the quick-log forms, done with outcome and the next-step prompt, the reminder worker with snooze, the month/week/day/agenda grids, the team calendar, the ICS feed, `next_activity_at` on cards) → **(5) email** (the logging mailbox, the webhook and the poll, the matcher, proposed leads, the unmatched screen, compose with templates and merge fields, the ICS invitation, private emails, the unsubscribe link) → **(6) campaigns, lists, segments and bulk actions** (campaigns and members, results, static and smart lists, the filter builder, the bulk bar, exports) → **(7) reports, forecast and home** (the nine reports with charts, quotas, the leaderboard, the home page's tiles) → **(8) agents, notifications, K7 and the API** (the two agents' tools and actions, dispatches, the morning note, `next_step_propose` and acceptance, notifications and prefs, K6 texts, the five shares, the API and lead-capture keys) → **(9) admin, settings and tokens** (vocabularies, rules, fields, the mailbox's health, quotas, the Assistant's settings, keys, purge) | each slice: screens + handlers + logging + manifest entries + tools, proven at 375 and 1280 on a scratch database |
| 4 | the two MCP servers with the run-facts gate, `app_roles` and the five `shares[]` admitted to the kernel's token (K26 headers), the registry wrapper, the two agents with their grants and the duty; the Actions-MCP contract proved against the kernel's `application_actions.register()` | the command bar logs a call and schedules the next step from one sentence; an agent's `email_send` pauses; the Assistant sends nothing, deletes nothing, changes no rule |
| 5 | the owner's `app_install.php apply` (ports pinned first), the logging mailbox at MaluMail with its secret, DNS and TLS, the hires, the lead-capture key for the website | **a seller adds a lead from a phone, converts it, drags the deal to Proposal on the board, schedules a call for tomorrow 10:00 and is reminded by email and in-app at 09:30; an email they BCC to the logging address lands on the contact and the deal; the contact's reply lands too; the manager's forecast shows the deal weighted; the morning note names a deal with no next step and the seller accepts the proposed step in one click; the GL's read of `deals_won` shows the won deal; a restricted account is invisible to a second seller; the ICS feed shows the call in the phone's calendar** |

**Extended** (§11). Size: ten specs (sso-shell + nine slices), about 60 screens, 110 actions, 70 records tools, 7 activity tools.
**Three to four days is the honest estimate**: accounts, contacts and leads one; the board and the calendar one; email, campaigns and
reports one; the rest and Phase 5 with the owner the remainder — the Spaces division (D16).

## 11. Extended, later, and what other applications owe

**Extended** (each its own spec, in the likely order): **two-way calendar sync** (Google Calendar and Microsoft 365 through their
APIs with the person's own consent; the ICS feed stays) · **email sequences** (a cadence of templated steps with waits and
stop-on-reply, each send still a pause for an agent) · **quotes** (numbered, priced from the GL's items, a PDF, accepted by a
secure link — the GL's invoice follows) · **web-to-lead forms hosted here** (a published form with a lead-capture key behind it) ·
**telephony** (Retell's voice agent as a caller and a receptionist; click-to-call logging) · **texting contacts** (K28) ·
**territories and quota plans** (regions, rules, plans per period) · **department scope** (a book per department) · **recurring
activities beyond tasks** · **account hierarchy reports** (roll-ups) · **a customer-health score** · **enrichment from a
provider the business pays** (never scraping) · **evals**: the Assistant's proposals against what sellers accepted.

**Later**: a marketing automation suite (Mailchimp's job); a partner portal; a CPQ.

## 12. What the kernel, MaluDB and the siblings owe this application

| # | Item | State |
|---|---|---|
| **K32** | **Catalog entry `sales_crm`** (kind `ours`, Sales & Service, category `crm` — already admitted —, `feather-target`); on request, not a default (D15) | owed: kernel `db/177`, after the owner's go |
| K26 | K7 names the consumer; `timeout_seconds` | **built** (db/173) |
| K6 | texts to a member | **built** — the reminder's text |
| **K28** | **texts to a non-member** (a contact) from the business's number with an opt-out — asked by Inventory too | owed: the kernel; email until then |
| K8 | wake an agent from an application | owed to every sibling; the chat-turn dispatch until then |
| **K33** | the kernel's **`application_endpoints.auth_kind`** admits `none` for the ICS and the webhook (it does — DocCloud's `/s/`), nothing new | nothing owed; recorded |
| **G3** | the General Ledger reads **`deals_won`** and **`accounts_index`** to make a customer (with `crm_account_id`) and, when asked, an invoice from the deal's products; answers **`customer_by_crm_account`** so the account shows the ledger's balance | owed by the GL (`reads[]`) |
| G4 | the GL's **`items_index`** share for the product picker | owed by the GL |
| **HD5** | Help Desk reads **`accounts_index`** / **`contacts_index`** to match requesters and organizations; shows **`deals_open_by_account`** on a ticket; answers **`tickets_by_organization`** for the account's Tickets tab | owed by Help Desk |
| INV3 | Inventory reads `deals_won` to draft a sales order | owed by Inventory |
| CT4 | Consultant Tracking reads `deals_won` to propose an engagement | owed by CT |
| SP4 | Spaces unfurls a deal or an account from `deals_open_by_account` / `account_timeline_summary` | owed by Spaces |
| plugin | `shared-schema.md` gains `accounts`, `contacts`, `contact_emails`, `activities`, `activity_participants`, `deal_products` as canonical and records the `mailboxes`/`inbound_email` reuse with the appended match columns | the planning model, in Phase 0 |
| MaluMail | one mailbox `crm-<token>@<mail domain>` with an inbound webhook, as Help Desk's queues have | the owner, at Phase 5 |

## 13. The decisions (recommendations; the owner's answers are §15 — every one taken, 2026-10-09)

| # | Decision | Recommendation | Alternative |
|---|---|---|---|
| D1 | Name, key, label | **"Sales CRM", catalog key `sales_crm`** (the installer admits `[a-z0-9_]`; the repository `maludb-os-sales-crm` as the owner named it, the clone `/srv/apps/sales_crm`), **DNS label `crm`** (`crm.<domain>`), database `<tenant>_sales_crm`, roles `sales_crm_rw` / `_records_ro` / `_activity_ro`, prefix `crm_`; business area Sales & Service, category `crm`, icon `feather-target` | label `sales` (`sales.<domain>`) or `salescrm`; key `crm` (shorter, but the category's name) |
| D2 | Roles | **Five**: Viewer, Sales Rep (`user`), Marketing, Sales Manager, CRM admin; teams are the kernel's departments, never a table | three (Rep, Manager, admin) — Marketing folded into Manager, Viewer into the admin's grant |
| D3 | The lead | **A separate Lead object converted into account + contact (+ deal), Salesforce's way; a converted lead stays, read-only, pointing at what it made; a lead is never on the board** | HubSpot's way — a contact with a lifecycle stage and no lead object (one fewer table; prospecting lists fill with people who never became anything) |
| D4 | What the command bar's expert does and never does | **Finds, answers, logs, creates, moves, schedules; drafts an email and pauses; never sends, deletes, merges or changes a rule** | the expert sends email to contacts under a standing approval |
| D5 | Visibility | **Open by default: every role sees every record with amounts; a manager may restrict a record to its owner and a department; ownership gates editing; email bodies are the business's, a seller may mark one private** | Salesforce's private model (own records + role hierarchy) — rejected for an SMB book: it hides the team's knowledge and doubles every query's wall |
| D6 | Pipelines and stages | **Several pipelines, each with ordered stages carrying probability, rotting days, required fields, ask-the-Assistant, one Won and one Lost; two seeded; a stage is a column (no mapping table); a deal's weighted value from the stage or an override** | one pipeline (HubSpot's free tier); forecast categories as a field (Salesforce) |
| D7 | The board | **Projects' shape: the columns partial polled every 30 s, SortableJS from the theme bundle as the enhancement over `POST /deals/{deal}/move`, the four next-activity colours, rotting, column totals (count, sum, weighted), Won/Lost as collapsed drop targets opening the close forms, filters and saved views; the list and the forecast as views of the same query; one column wide at 375 px** | a JS board component (jKanban, etc.) — rejected: the estate has a working board and the rule "a move is a POST" |
| D8 | The calendar | **Server-rendered HTMX grids (month, week, day, agenda) as the cidery's schedule; `who=` me / colleague / team; drag between cells as the enhancement over `reschedule`; the ICS feed one-way (txtSchedules' `calendar_feeds`); two-way sync Extended** | the theme's `tui-calendar` (jQuery-era, weak at 375 px) or FullCalendar (another vendor) — rejected |
| D9 | Activities and reminders | **One `activities` table for every kind (call, email, meeting, task, deadline, lunch) on any of the four records; a reminder is columns on the activity (`remind_minutes_before`, `remind_at`, `reminded_at`, `snoozed_until`), delivered by the worker in-app + email + opt-in text; done asks for the next step (the Pipedrive method); `next_activity_at` kept on deals, contacts and accounts by trigger** | Salesforce's two tables (Task, Event); Spaces' free-standing `reminders` |
| D10 | Email | **Logged, not hosted: a BCC logging mailbox at MaluMail (Help Desk's tables), the matcher by address and thread, proposed leads from strangers; compose from a record with templates and merge fields through MaluMail, Reply-To the mailbox; an unsubscribe link and opt-outs; sequences Extended** | a per-seller IMAP connection (Help Desk's `provider imap` exists) — Extended, when a business asks; a tracking pixel — never |
| D11 | Products on a deal | **`deal_products` lines by name with an optional provider id (Inventory's variant or the GL's item through K7 pickers), quantity, unit price, discount, recurring; the deal's value follows the lines when any exist; no catalog of the CRM's own** | a `products` table of the CRM's own — rejected: the estate owns products in Inventory and items in the GL |
| D12 | Prospecting tools | **CSV import up to 50,000 rows with saved mappings, dedupe policy, dry run and 24-hour rollback; static and smart lists over the `find_*` filter vocabulary; bulk actions in one transaction up to 1,000 rows then the worker; duplicates by email, domain, phone and trigram name with a two-column merge page** | no smart lists (static only); no rollback |
| D13 | The API, keys and K7 | **A key acts as its person (60/min, 5,000/day); a lead-capture key creates leads only (600/day) for the website's form; the ICS route under `/api/v1/`; five shares — `accounts_index`, `contacts_index`, `deals_won`, `deals_open_by_account`, `account_timeline_summary` (no bodies, no notes)** | an application-wide key — rejected: visibility must be a person's |
| D14 | Agents | **Expert and Sales Assistant hired on install; the pause list of §5; the Assistant weekdays 06:30 with one note per seller and one per manager, next-step proposals accepted in one click, lead qualification as a proposal** | one agent; the Assistant moving deals or sending the first email itself — rejected |
| D15 | Default or on request; the repository | **On request, not a default; the repository private until the owner says** | a fifth default install — most businesses sell; the owner's call |
| D16 | Ports and the division | **8191 / 8843 / 8844**, scratch 8801–8807; **the Spaces division**: the planning model builds K32, Phase 0, Phase 1, Phase 2, slice 1 and the exemplars (slice 3 the board, slice 4 the calendar, with slice 2 between); Sonnet 5.5 builds slices 5–9 and Phases 4–5 | — |

**Open questions for the owner beyond the table**: (a) **currency** — one currency per installation (the business's) with a per-deal
currency recorded but not converted (the recommendation; the forecast sums the base currency and lists the others apart), or
exchange rates kept here (the GL's job, through a read later); (b) **the won deal and the ledger** — should a won deal *offer* the
GL a customer and a draft invoice automatically (the GL's `reads[]` of `deals_won`, G3, the recommendation), or should the seller
press a button that asks the GL (an action across applications the kernel has no shape for yet); (c) **Help Desk's organizations
and requesters** — should the CRM become the one list of companies and people the Help Desk matches against (HD5, through K7 — the
recommendation, additive: the Help Desk keeps its tables), or stay apart.

## 14. Ports, names and files

- Repository `/srv/apps/sales_crm` (origin `github.com/maludb/maludb-os-sales-crm`, private); record database `<tenant>_sales_crm`
  (`subello_sales_crm`); roles `sales_crm_rw`, `sales_crm_records_ro`, `sales_crm_activity_ro`; the tenant's memory
  (`certstudy_memory`) receives the activity episodes tagged `sales_crm`.
- `crm.<domain>` (`vhost.label = "crm"`); `APP_INTERNAL_PORT=8191`, `MCP_RECORDS_PORT=8843`, `MCP_ACTIVITY_PORT=8844`, all three in
  `env.required` and written to `config/.env` before `apply`; `deploy/` files are templates (`{{DOMAIN}}`, `{{APP_DIR}}`, `{{APP_KEY}}`,
  `{{APP_INTERNAL_PORT}}`, `{{MCP_RECORDS_PORT}}`, `{{MCP_ACTIVITY_PORT}}`); attachments 25 MB (the canonical store's ceiling).
- Units: `sales_crm-records-mcp`, `sales_crm-activity-mcp`, `sales_crm-activity-ingest` (+ timer), `sales_crm-directory-sync` (+
  timer), `sales_crm-worker` (+ timer, every minute: reminders due, the outbox, the mail poll and webhook queue, the matcher,
  rotting and next-step flags, smart-list counts hourly, the search index catch-up, imports and exports, recurring tasks, the
  tombstone purge window, dispatch retries).
- `maludb-os.json`: `catalog_key sales_crm`, `name "Sales CRM"`, `business_area "Sales & Service"`, `category crm`, `icon
  feather-target`, `criticality medium`, `vhost.label crm`, no `scopes`, `sso`, `directory {reads: true, writes: false}`, `assistant
  {command_bar: true, agent: expert}`, `agents [expert, assistant]` each `hired_on_install: true` (the Assistant with `duty "30 6 * *
  1-5"`), `approvals` (§5), endpoints (incl. `/api/v1/calendar.ics` and `/channels/malumail/mail` as `auth_kind none`), services,
  `shares` (five), `reads` (`gl.customer_by_crm_account`, `gl.items_index`, `inventory.availability_index`, `helpdesk.tickets_by_organization`
  — each with its `why`).
- Env: the contract's required keys plus `MALUMAIL_API_KEY`, `MAIL_FROM`, `MAIL_FROM_NAME`, `MAILBOX_CRM_WEBHOOK_SECRET`,
  `CRM_STORAGE_DIR` (default `storage`), `CRM_MAX_IMPORT_ROWS` (optional).
- Vhost allow-list (public): `/api/v1/`, `/channels/malumail/mail`, `/mcp/records`, `/mcp/activity`.
- Kernel files: `mcp/registries/sales_crm.json` (made by the installer), db/177 (K32).
- Proof scratch ports: 8801–8807; scratch databases `crm_dev`, `crm_proof`; fixtures `tests/fixtures/` (synthetic leads, a CSV
  with duplicates, an inbound email thread, a bounce; nothing of a real person).

## 15. The owner's answers

**2026-10-09 — every recommendation of §13 taken, and the three open questions answered**, walked through one by one.

| # | Decision | Where it lives |
|---|---|---|
| D1 | **"Sales CRM", key `sales_crm`, DNS label `crm`**, database `<tenant>_sales_crm`, roles `sales_crm_rw` / `_records_ro` / `_activity_ro`, prefix `crm_`; the repository keeps the name `maludb-os-sales-crm` | `maludb-os.json`; §14 |
| D2 | **Five roles**: Viewer, Sales Rep (`user`), Marketing, Sales Manager, CRM admin; teams are the kernel's departments | §3; `crm_roles`; `app_roles` |
| D3 | **A separate Lead object, converted** into account + contact (+ deal) in one transaction; the converted lead stays read-only, pointing at what it made; never on the board | §6.1; `docs/build-specs/leads-and-conversion.md` |
| D4 | **The Expert acts inside and drafts out**: finds, answers, logs, creates, moves, schedules on its own; drafts an email and pauses; never deletes, merges or changes a rule | §5; `os/expert.md` |
| D5 | **Open by default**: every role sees every record with amounts; a manager may restrict a record to its owner and a department; ownership gates editing; a seller may mark one email private | §3 |
| D6 | **Several pipelines**, stages with probability, rotting days, required fields, ask-the-Assistant, one Won and one Lost; two seeded; a stage is a column; weighted value from the stage or an override | §6.2 |
| D7 | **The board in Projects' shape**: the columns partial polled every 30 s, SortableJS the enhancement over `POST /deals/{deal}/move`, the four next-activity colours, rotting, column totals, Won/Lost drop targets opening the close forms; the list and the forecast as views of the same query | §6.2; `docs/build-specs/pipeline-board.md` |
| D8 | **Server-rendered HTMX calendar grids** (month, week, day, agenda) as the cidery's schedule; `who=` me / colleague / team; drag between cells over `reschedule`; the ICS feed one-way on `calendar_feeds`; two-way sync Extended | §6.3; `docs/build-specs/activities-calendar.md` |
| D9 | **One `activities` table** for every kind on any record; the reminder as its columns, delivered by the worker in-app + email + opt-in text; done prompts the next step; `next_activity_at` kept by trigger | §6.3 |
| D10 | **A BCC logging mailbox at MaluMail** on Help Desk's mail tables; the matcher by address and thread; proposed leads from strangers; compose with templates and merge fields, Reply-To the mailbox; opt-outs and the unsubscribe link; IMAP and sequences Extended | §4; §6.3; slice 5 |
| D11 | **`deal_products` lines by name with an optional provider id** through K7 pickers (Inventory's variants, the GL's items); the deal's value follows the lines when any exist; no catalog of the CRM's own | §6.2 |
| D12 | **CSV import** up to 50,000 rows with saved mappings, dedupe policy, dry run and 24-hour rollback; **static and smart lists**; bulk actions; duplicates by email, domain, phone and trigram name with a two-column merge page | §6.1; §6.4; slices 2 and 6 |
| D13 | **A key acts as its person** (60/min, 5,000/day); a lead-capture key creates leads only (600/day); the ICS route under `/api/v1/`; **five K7 shares** with no email bodies or notes | §8; slice 8 |
| D14 | **Expert and Sales Assistant** hired on install; the pause list of §5; the Assistant weekdays 06:30, one note per seller and per manager, next-step proposals accepted in one click, lead qualification as a proposal | §5; `agents[]` |
| D15 | **On request, not a default; the repository private** until the owner says | §12; the org |
| D16 | **Ports 8191 / 8843 / 8844**, scratch 8801–8807; **the Spaces division** — the planning model builds K32, Phases 0–2, slices 1 and 2 and the exemplars (slice 3 the board, slice 4 the calendar); Sonnet 5.5 builds slices 5–9 and Phases 4–5 | §14; `CLAUDE.md` |
| (a) | **One base currency** from settings; a deal may carry another, recorded and shown, never converted; the forecast sums the base currency and lists the others apart | §6.2; `crm_settings` |
| (b) | **The General Ledger reads `deals_won`** (G3, owed by the GL) and proposes the customer and a draft invoice; the account shows the ledger's id and balance through `gl.customer_by_crm_account`; no cross-application action | §12; slice 8 |
| (c) | **Help Desk matches requesters and organizations against `accounts_index` / `contacts_index` through K7, additively** (HD5, owed by Help Desk) and shows `deals_open_by_account` on a ticket; the CRM shows the account's tickets through the Help Desk's read; both keep their tables | §12; slice 1 (the Tickets tab) |

## 16. State

**2026-10-11 — SLICE 9 ADMIN: PIPELINES, VOCABULARIES, RULES, FIELDS, TAGS, THE MAILBOX, QUOTAS, SETTINGS, KEYS AND THE PURGE BUILT AND PROVEN — PHASE 3 COMPLETE (84 of 84 screens, 121 of 121 actions).** 15 screens and 19 actions; pipelines and stages with the order dragged (`stage-order.js` over `stage_reorder`, Move up/down as the no-JS path) and the stage's
required fields chosen as chips — the database's rule, proved by slice 3's board refusing a move naming them; sources and lost reasons (deleting one that is named refused by name, deactivate instead); the assignment rules as one saved list with a TEST BOX that asks a STABLE dry function and assigns nothing;
custom fields the forms read at once; the tag vocabulary's rename, merge and delete on slice 1's tag layer; the logging mailbox with the NAME of its secret's key (a value-shaped text refused, the secret on no page and in no log) and its health; quotas as a grid; 25 settings in seven groups; every key and token (no hash)
with lead-capture keys minted once; the tombstones and the purge. The worker's `tombstones_purge` and `key_usage_prune` are real — no stub pass and no placeholder screen remain. db/024: `crm_tombstones_purge_kinds()` (the count by kind; a tombstone inside the window survives every call), `crm_assign_lead_dry()`, and a
fix — an archived custom field's values stayed unsavable. Also fixed: `deal_guard()` answered 500 for a stage requiring a custom key, the menu lit nothing for `admin-*` screens after HTMX navigation, a sticky Save never pinned (the template's `.main-content` is a scroller). Fifteen of the nineteen actions pause for an agent (the spec said 14).
`tests/phase3/slice9/run.sh`: 533 checks under php -S and under Apache (pipelines 71, vocab 44, rules 40, fields 36, mailbox 30, quotas 29, settings 25, tokens 36, purge 38, json 117, browser 67) and the schema proof (173); slices 1–8, Phase 2, Phase 0 and Phase 1 re-run green. The record: `docs/build-specs/admin-settings.md` "Built and proven". Next: Phase 4 (the MCP servers and the actions server's registry) and Phase 5 (the owner's install).

**2026-10-11 — SLICE 8 AGENTS, PROPOSALS, NOTIFICATIONS, K7, THE API AND KEYS BUILT AND PROVEN.** 2 screens and 12 actions; the proposals screen with one-click Accept (a next step becomes the decider's activity, a qualification sets the score, a duplicate sends a manager to the pair), the Assistant's three
propose doors, notices and the refused `sms_send` (K28), the agents' screen with dispatches and the Assistant's settings; the records API behind a Bearer key (no cookie, no session; the screens' and actions' own code run as the key's person; rates, CORS, `api.call`) and the lead-capture
route (honeypot, caps, the source the key's); the loopback bridge (`X-CRM-Bridge`) with the five K7 shares (consumer required, nothing restricted, no body) and the two K7 reads this application consumes (cached five minutes, degraded to words); the worker's `outbox` notification half, `dispatches` and
`proposals` real. db/023: `k7_cache`, and three corrections found here — `crm_key_rotate()` dropped a capture key's source (a rotated capture key became the admin's full key), the accounts share counted restricted deals, the timeline share's next step was the farthest. `tests/phase3/slice8/run.sh`:
591 checks under php -S and under Apache and the schema proof (173); slices 1–7, Phase 2, Phase 0 and Phase 1 re-run green. The record: `docs/build-specs/agents-k7-api.md` "Built and proven". Next: slice 9, admin, settings and tokens.

**2026-10-11 — SLICE 7 REPORTS, THE FORECAST REPORT AND THE HOME'S TILES BUILT AND PROVEN.** 10 screens and 1 action; nine reports as cards and pages (funnel, win rate, cycle time, sources, campaigns, activity, leaderboard, lost reasons, forecast), each a
db/015 or db/017 function drawn by the server as inline SVG (bars, the funnel as stacked bars, lines per month; the table beside it is the accessible version), period presets resolved in the member's time zone, `report_export` through slice 6's
`exports` (a small report at once by POST, a large one built by the worker as the maker). No migration. `tests/phase3/slice7/run.sh`: the checks under php -S and under Apache recorded in the spec; slices 1–6, Phase 2, Phase 0 and Phase 1 re-run green. The record:
`docs/build-specs/reports-home.md` "Built and proven". Next: slice 8, agents, notifications, K7 and the API.

**2026-10-11 — SLICE 6 CAMPAIGNS, LISTS, SEGMENTS, BULK ACTIONS AND EXPORTS BUILT AND PROVEN.** 9 screens and 18 actions; campaigns with results, members and an email queued per contact and SENT BY THE WORKER's `outbox`
pass (db/022: `outbound_email.campaign_id`, attempts, retries; the planning model's decision); static and smart lists with one filter vocabulary (`lists/filters.php`) and a live count; the bulk bar on every list screen;
exports built by the worker, downloaded once. `tests/phase3/slice6/run.sh`: 625 checks under php -S and under Apache; slices 1–5, Phase 2, Phase 0 and Phase 1 re-run green. The record:
`docs/build-specs/campaigns-lists.md` "Built and proven".

**2026-10-11 — SLICE 5 EMAIL BUILT AND PROVEN.** 7 screens and 10 actions; the logging mailbox's signed webhook and the worker's `mail_poll` and `mail_match` real (a message kept raw, matched by the
database's `crm_match_inbound_email` onto contacts and the deal by thread or tag, a stranger proposed, a bounce ignored, a reply told to its owner); compose with templates and merge fields, drafts (their sender's
alone), send through MaluMail (Reply-To the mailbox, a Message-ID of ours, the footer and the unsubscribe link, files, the meeting's ICS), the opt-out refused by name, `email_log`, private, the unmatched screen
(Make lead · Attach · Ignore), the meeting's invitation, templates, the unsubscribe door `/u/{token}` (only the token's SHA-256 kept), and the three mail bodies of slice 8. `tests/phase3/slice5/run.sh`: 397
checks under php -S and under Apache; slices 1–4, Phase 2, Phase 0 and Phase 1 re-run green. **db/021** adds the reply's and the invitation's columns to `outbound_email`, a unique uid on `inbound_email`, a
sender-only draft in `mcp_emails`, a matcher that also takes the provider's id, and real line breaks in the seeded templates. The record: `docs/build-specs/email.md` "Built and proven".

**2026-10-11 — SLICE 4 ACTIVITIES, REMINDERS AND THE CALENDAR (THE SECOND EXEMPLAR) BUILT AND PROVEN.** 7 screens and 10 actions; the calendar in four
views from one query (month, week, day, agenda — the phone's default) for me, a colleague or a team, the drag that reschedules; the activities list; an
activity's page; the schedule form; Done with the next-step prompt; the two quick-log forms; the worker's `reminders`, `recurring_tasks` and
`next_step_flags` passes real; the ICS feed and its rotation. `tests/phase3/slice4/run.sh`: 263 checks under php -S and under Apache; slices 1–3, Phase 2,
Phase 0 and Phase 1 re-run green. **db/020** fixes `crm_record_delete()` naming an activity's table `activitys`. The record:
`docs/build-specs/activities-reminders.md` "Built and proven". **THE HANDOFF POINT:** slices 5–9 and Phases 4–5 are Sonnet 5.5's, one worker per slice,
each verified by the planning model.

**2026-10-10 — SLICE 3 PIPELINES, DEALS AND THE BOARD (THE FIRST EXEMPLAR) BUILT AND PROVEN.** 9 screens and 15 actions; the board with the drag, the
poll and the no-JS move; the list and the forecast over the same query; the deal page with the stage bar; won, lost, reopen, reassign; product lines and
people with roles; saved views. `tests/phase3/slice3/run.sh`: 191 checks under php -S and under Apache; slices 1–2, Phase 2, Phase 0 and Phase 1 re-run green.
The record: `docs/build-specs/deals-board.md` "Built and proven". **Next:** slice 4, activities and reminders (THE SECOND EXEMPLAR).

**2026-10-10 — SLICE 2 LEADS, CONVERSION, THE INBOX, IMPORT, DUPLICATES AND MERGE BUILT AND PROVEN.** 11 screens and 15 actions; the worker's `imports` and
`search_catch_up` passes real. `tests/phase3/slice2/run.sh`: 202 checks under php -S and under Apache; slice 1, Phase 2, Phase 0 and Phase 1 re-run green.
**db/019** fixes `crm_assign_lead()` matching a rule against a missing field; the import's dedupe moved to indexed lookups (240 s → 20 s for 2,000 rows).
The record: `docs/build-specs/leads-import.md` "Built and proven". **Next:** slice 3, the board (THE FIRST EXEMPLAR).

**2026-10-09 — SLICE 1 ACCOUNTS AND CONTACTS (THE CRUD EXEMPLAR) BUILT AND PROVEN, the same day.** 8 screens and 15 actions: cards, full pages with tabs by
anchor, forms with the record picker and the duplicate warning, custom fields, notes, attachments in the canonical store, tags, restrict / delete /
restore across kinds. `tests/phase3/slice1/run.sh`: 234 checks under php -S and under Apache; Phase 2 (271 / 276), Phase 0 and Phase 1 re-run green.
The record and the decisions taken: `docs/build-specs/accounts-contacts.md` "Built and proven". **Next:** slice 2 (leads, import, duplicates and merge).

**2026-10-09 — PHASE 2 THE SHELL BUILT AND PROVEN, the same day.** Today (the regions of §9 for a Rep and a manager, one button on the empty book),
the one menu table with its rights, the tab bar Today · Pipeline · Calendar · Log · More, the one search box, the bell, the switcher, the command bar
through the kernel, My settings with reminders and the signature, tokens, notifications, My activity; eleven placeholders. `tests/phase2/run.sh`:
271 checks under php -S, 276 under Apache; Phase 0 (173 + 71) and Phase 1 (55) green; the installer's plan clean. **db/018** grants the trail view to
the application; the write guard now reads the schema's five refusal codes; ROOT_STEPS step 0b enables mod_headers; the kit's sibling leftovers
fixed — the record is `docs/build-specs/sso-shell.md` "Built and proven". **Next:** slice 1 (accounts and contacts, the CRUD exemplar).

**2026-10-09 — PHASE 1 APPROVED by the owner, as written** ("Approved as written"): the schema db/001–017, the tool surface and the action manifest together, and the
eleven decisions of `docs/phase1-consistency.md`. The checkpoint gate is open: Phase 2 (`docs/build-specs/sso-shell.md`) begins the same day.

**2026-10-09 — PHASE 1 WRITTEN, the same day, for the owner's checkpoint.** `docs/sales-crm-mcp-tool-surface.md` (72 records tools incl. the five shares reading
`X-OS-Consumer`, 7 activity tools, 17 resolver entities, the records API and the capture route, the activity vocabulary), `docs/sales-crm-action-manifest.md`
(84 screens, 121 actions, 33 paused — 5 `external_send`, 13 `deletion`, 15 `other`; 15 people-only) and `mcp/action_registry.json` built from it
(`bin/build_action_registry.php --check` green, `bin/sync_approvals.php` rewrote `maludb-os.json` `approvals[]` from the manifest: 33 in sync), the three build specs
(`docs/build-specs/pipeline-board.md`, `activities-calendar.md`, `leads-and-conversion.md`), ten slice specs (`sso-shell` + nine; `deals-board` and
`activities-reminders` THE EXEMPLARS), `tests/phase1/check_specs.sh` (55 checks: every manifest row claimed once, every tool named, every approval a manifest
action), `docs/phase1-consistency.md` (eleven decisions taken in the specs for the owner to confirm); **db/017** adds the reads Phase 1 asked for (`crm_calendar`,
`crm_my_activities`, `crm_lost_reasons_summary`, `crm_products_pipeline`, `duplicate_dismissals` + `crm_duplicates_open`, and the five `crm_share_*` functions as the
application) — the Phase 0 proof re-run green (173). **Next:** the owner's approval of the schema, the surface and the manifest together → Phase 2 (`sso-shell.md`) →
slice 1 → slice 2 → slice 3 (THE FIRST EXEMPLAR) → slice 4 (THE SECOND) — the handoff point to Sonnet 5.5.

**2026-10-09 — PHASE 0 COMPLETE, the same day as the plan.** K32 built as the kernel's db/177 (the catalog row under Sales & Service, category `crm`,
applied). The schema `db/001`–`016`: the kit's five from DocCloud (dc_ → crm_; `members.email_signature` appended; the five audit keys
lead/account/contact/deal/token; five roles and forty-one rights; `crm_settings` with the design's defaults; keys with the lead-capture limit),
`006` the vocabularies (sources, lost reasons, Help Desk's `tags` + `record_tags`, `custom_field_defs` with typed validation, GL's
`document_sequences`), `007` accounts, contacts, `contact_emails` and THE WALL (`crm_wall` inlined per row: open by default, a restriction to the
owner and a department, managers everything; `crm_duplicates`; `crm_touch`), `008` pipelines and stages (two seeded), deals with the required-fields
rule refusing a move by name, status from the stage's kind, weighted value from the stage or the override, value from the product lines, every
move a history row, the account's counters and kind and the contact's lifecycle by trigger, `crm_deal_move`, rotting, quotas, `009` leads with the
assignment rules (member, department, round-robin) and `crm_lead_convert` (find by domain and name, by email; make; the deal in the default
pipeline's first stage; one transaction; read-only after), `010` activities with the reminder as columns (`remind_at`, `reminded_at`,
`snoozed_until`), `next_activity_at` kept on deals, contacts, accounts and leads, the four colours, `crm_reminders_due`, the ICS rows, txtSchedules'
`calendar_feeds`, `011` Help Desk's mail tables reused with the matcher `crm_match_inbound_email` (the sender a member, the deal by thread then by
the subject's tag, contacts by every address, strangers as proposed leads, bounces nothing), templates with merge fields, opt-outs, `012` campaigns,
lists (static by trigger, smart by filter), Help Desk's `saved_views` with six system views, Spaces' `imports`/`exports`/`worker_passes`,
`013` GL's `notes`/`attachments`, Spaces' notifications with `crm_notify` and `crm_deliver_reminder`, `agent_dispatches` (an ask-the-Assistant
stage dispatches), `assistant_proposals` with `crm_proposal_accept` (one click → the activity), THE MERGE `crm_records_merge` (contacts, accounts,
leads: every child re-pointed, the loser a tombstone), the tombstone and `crm_tombstones_purge`, `014` Spaces' `search_index` with the four kinds,
`crm_search` (websearch + word-trigram, the wall per row), `015` the reads of §7 (`crm_pipeline_summary`, `crm_forecast`, `crm_funnel`,
`crm_win_rate`, `crm_deals_at_risk`, `crm_untouched`, `crm_leads_untouched`, `crm_upcoming_dates`, `crm_activity_summary`, `crm_leaderboard`,
`crm_source_performance`, `crm_campaign_results`, `crm_timeline`, `crm_touches_to_win`, `crm_stage_requirements_check`), `016` forty-one `mcp_*`
views. **Proven** by `db/proof/phase0_proof.sql` (**173 checks**, rolled back: every actor and role, the seeded book, accounts and contacts with
duplicates and custom fields, the rules routing leads, the conversion finding and making, the board's refusals and moves, won and lost and
reopen, products, rotting, the four colours, a reminder delivered, snoozed and delivered again, done moving the next step, a proposal accepted,
the mailbox matching two contacts and a deal, a reply by thread, a stranger proposed, a bounce ignored, the opt-out, campaigns and lists, the
merge, the tombstone and the purge, THE WALL for eight actors, private email, keys, the search, the reused tables verbatim against their
siblings, the read roles seeing views only) and by the kit without a kernel (`tests/phase0/run.sh`, **71 checks**: the mirror, roles, sign-on with
every refusal, CSRF, the funnel with the new keys, keys and rates, the kernel's sign-out, the change feed, the worker's empty pass, the ingest
bridge). `maludb-os.json` (five roles, two agents — the Assistant with the duty `30 6 * * 1-5` —, 25 approvals, five shares, four reads, seven
endpoints), `os/{expert,assistant}.md`, nine skills (five runbooks), `deploy/` with `ROOT_STEPS.sh` (the ports, the storage directory, the
mailbox as the one manual step); the plugin's `shared-schema.md` catalogue gained the new canonical tables; **the installer's `plan` reads
clean** (36 steps; the ports unfilled until step 0 pins them, as every sibling). Found on the way: a proof that runs an action and inspects the
result in one expression evaluates the subquery first (Spaces' and DocCloud's lesson, met again — `keep` the result, then check); `position`
is reserved in a RETURNS TABLE; a reopen must clear the lost reason only on a reopen, never when a reason is set before the move; a short
misspelling finds a long name by `word_similarity`, not `similarity`. **Next:** Phase 1 (the tool surface, the action manifest, the three build
specs, ten slice specs) for the owner's approval → Phase 2 → slices 1–2 → the exemplars (3 the board, 4 the calendar) → the handoff to Sonnet 5.5.

**2026-10-09 — APPROVED the same day (§15: D1–D16 and (a)–(c), every recommendation), walked through one by one with the owner.**
**Next:** K32 (kernel db/177, the catalog row under Sales & Service, category `crm`) → Phase 0 → Phase 1 for approval → Phase 2 → slices 1–2 → the exemplars (slices 3 and 4) → the handoff to Sonnet 5.5.

**2026-10-09 — planned; awaiting the owner's decisions (§13).** The research of §0 done: the field (Salesforce, HubSpot, Pipedrive,
Zoho, Close, Attio, Twenty), the estate surveyed in every sibling's `db/*.sql` (GL's `customers`/`party_contacts`, Help Desk's
`organizations`/`requesters`/`mailboxes`/`inbound_email`/`tags`/`custom_field_defs`/`saved_views`, Consultant Tracking's
`clients`/`client_contacts`, Spaces' `reminders`/`search_index`/`imports`, txtSchedules' `calendar_feeds`, Projects' board and
`board.js`, the cidery's server-rendered schedule), the free ports and prefix found, the catalog's `crm` category and the Sales &
Service area confirmed. Repository `maludb/maludb-os-sales-crm` created private and cloned to `/srv/apps/sales_crm`. **Next:** the
owner's answers → K32 (kernel db/177) → Phase 0 (schema and proofs, the kit, `maludb-os.json`, agents, skills, `deploy/`, the
plugin's catalogue) → Phase 1 for approval → Phase 2 → slice 1 → slice 2 → the exemplars (slices 3 and 4) → the handoff to Sonnet 5.5.
