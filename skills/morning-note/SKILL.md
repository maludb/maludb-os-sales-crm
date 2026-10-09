---
name: morning-note
description: Runbook for the Sales Assistant's weekday duty (06:30) — one note per seller (today, overdue, no next step with a proposal each, rotting, untouched leads, closing untouched, overnight replies, birthdays and renewals) and one per manager (forecast against quota, stalled deals, the weak stage, the empty calendars); delivered to the person's assistant or as a notification. Run every weekday or when a manager asks.
kind: runbook
---

# The morning note

**When**: weekdays 06:30 (the duty), or when a manager asks. **Who runs it**: the Sales Assistant.
**Writes**: proposals (`next_step_propose`, `lead_qualify_propose`, `duplicate_propose`, `email_draft`), one `message_send` or
`notification` (kind `morning_note`) per person. Nothing else.

## 1. The sellers
`find_members` with the Sales Rep role, active, human. Work seller by seller, in their time zone.

## 2. Each seller's note
- `my_activities` (as them): today's, then overdue — kind, time, subject, record. Overdue first.
- `deals_without_next_step` (owner = them): each deal one line + your proposal (runbook `propose-the-next-step`); skip a deal with an
  open proposal (`assistant_proposals`).
- `deals_rotting`, `deals_at_risk` (closing_untouched, overdue_close): one line each.
- `leads_untouched` (owner = them, two days): one line each; a new lead from the form or the mailbox gets a `lead_qualify_propose`
  (runbook `qualifying-a-lead`) and an `email_draft` for a first reply.
- `find_emails` since yesterday 18:00, direction in, their contacts: "replies overnight" — sender, subject, the deal.
- `upcoming_dates` (30 days, owner = them): birthdays and renewals.
- Deliver: `message_send` to their assistant when the kernel names one, else `notification`.

## 3. Each manager's note
`find_members` with the Sales Manager role. `forecast` (this month, the team), `pipeline_summary` (the default pipeline), `funnel`
(the last 90 days), `leaderboard`, `deals_at_risk` (no owner filter): the month against quota in money, the weakest stage, the
stalled deals (rotting > 7 days over), sellers with nothing scheduled today. `find_duplicates` (contacts, accounts): a
`duplicate_propose` per new pair.

## 4. The shape of a note
Six lines or fewer per section; a clean section is one line ("Nothing overdue."). Name the record, link the screen; never a body,
never a note's text. Say how many proposals are open.
