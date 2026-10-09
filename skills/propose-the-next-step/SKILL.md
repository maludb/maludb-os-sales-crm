---
name: propose-the-next-step
description: Runbook for a next-step proposal on a deal with no open activity — read the deal's stage, timeline and last touch, choose the kind, the moment (in the seller's zone, a working day) and the subject, write the proposal the seller accepts in one click; also what the Expert does when a seller asks "what should I do next on Acme". Never schedules the activity itself as the Assistant.
kind: runbook
---

# Propose the next step

**When**: a deal has no open activity (`deals_without_next_step`), a deal entered a stage with ask-the-Assistant set (a dispatch), or
a seller asks. **Who**: the Assistant proposes (`next_step_propose`); the Expert may schedule directly when the seller asks it to
(`activity_create`).

## 1. Read the deal
`get_deal` (stage, value, close date, owner, primary contact), `timeline` (the last three touches and what they said), `deal_history`
(how long in the stage), `get_contact` (the contact's preferred channel, time zone).

## 2. Choose the kind by the stage
Qualification → `call` (discovery). Discovery → `task` (send the proposal) with a deadline. Proposal → `call` (walk through it) or
`meeting`. Negotiation → `meeting` (the decision). Renewals: Upcoming → `email` (the renewal note, a draft); In discussion → `call`.
A contact who prefers email gets a `task` to write, not a call.

## 3. Choose the moment
What the last touch said ("call me next month") wins. Else: Qualification/Discovery within two working days; Proposal within three;
Negotiation within one; a deal closing this week, tomorrow. Always a working day, 09:00–17:00 in the seller's zone; never a weekend.

## 4. Write the proposal
`next_step_propose` with `payload {kind, subject, at, body}`: the subject names the purpose and the person (*Call Dana to confirm the
demo*), the body carries what to say (two lines from the timeline). One open proposal per deal; do not re-propose while one is open
or after a dismissal unless the facts changed.

## 5. When the seller accepts
`proposal_accept` makes the activity, owned by the seller, with their default reminder — the deal has a next step again.
