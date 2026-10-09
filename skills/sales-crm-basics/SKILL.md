---
name: sales-crm-basics
description: What a lead, an account, a contact, a deal, a pipeline, a stage, an activity, a reminder, a campaign, a list and a segment ARE in Sales CRM; which tool answers which question; the ownership rule; the one rule for agents — a draft is never sent by an agent. Read first, before any other Sales CRM skill.
---

# Sales CRM — the basics

**The book.** A **lead** is one row: a person and the company they are at, from a **source**, owned by a seller, in a status
(new → working → qualified | unqualified). Converting it makes or finds an **account** (a company, at any point of the
relationship: prospect, customer, partner, former) and a **contact** (a person; may stand alone) and, if asked, a **deal**. A deal
is an opportunity in a **pipeline**, in one **stage** (a column of the board) with a probability; it has a value, a currency, an
expected close date, an owner, contacts with roles, product lines; it ends **won** or **lost** with a reason. An **activity** is a
call, an email, a meeting, a task, a deadline or a lunch on any record, with a due time or a start and an end, done or not, with an
outcome; its **reminder** is columns on it. A **campaign** is a named effort with members and results; a **list** is a saved set of
records (static) or a saved filter (smart, a segment). **Teams** are the kernel's departments.

**Who sees what (D5).** Open by default: every role sees every record with amounts. A manager may restrict a record to its owner
and a department. What you cannot see does not exist for you. Editing is ownership: the owner, an unowned record, a manager.

**Which tool.** Finding: `search` (one box, all four kinds), then `find_leads` / `find_accounts` / `find_contacts` / `find_deals`
with filters, `get_*` for one. The pipeline: `pipeline_summary` (by stage), `forecast` (by month × seller or stage, with quota),
`funnel`, `win_rate`, `cycle_time`, `leaderboard`, `deals_at_risk` / `deals_without_next_step` / `deals_rotting`. The calendar:
`my_activities`, `calendar`, `find_activities`, `upcoming_dates`. The relationship: `timeline` (everything on one record, newest
first), `find_emails` (subjects; a body only when the asker may read it), `accounts_untouched`, `contacts_untouched`. Prospecting:
`leads_inbox`, `leads_untouched`, `find_duplicates`, `source_performance`, `campaign_results`, `find_lists`, `segment_preview`.

**Acting.** Create and change with `*_create` / `*_update`; `lead_convert`; `deal_move` (a stage may refuse, naming the fields a deal
needs to enter it — fill them with `deal_update`); `deal_won` / `deal_lost`; `activity_create` / `activity_complete` /
`activity_reschedule` / `activity_snooze`; `note_add`; `tag_apply`; `email_log` (one already sent); `email_draft` (a draft the
person sends).

**The one rule for agents.** Nothing you write reaches a contact. `email_send`, a text, an invitation pause for a person every
time; say so when you draft. Deleting, merging, purging, bulk actions and every rule of the book (pipelines, stages, probabilities,
assignment rules, custom fields, settings) are a manager's or the admin's.

**Words.** A time a person says is in their time zone. "Closing this month" means `expected_close_date` in the current month.
"Weighted" means value × probability. "Rotting" means too long in a stage. "No next step" means no open activity on the deal.
