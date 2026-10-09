---
name: activity-based-selling
description: The method Sales CRM is built around — every open deal has a next step; finishing one schedules the next; the four colours of a card (scheduled, today, overdue, none); how to log a touch with its outcome; what a good next step looks like. For the Expert when it logs or schedules and for the Assistant when it proposes.
---

# Activity-based selling

**The rule.** Every open deal has a next step: one open activity with a date. Finishing an activity (`activity_complete`) is the
moment to schedule the next (`activity_create`) — the application asks; you ask too, or set it from what the person said.

**The four colours.** A card shows its next activity: green *scheduled* (in the future), amber *today*, red *overdue*, grey *none*
("No next step"). Grey and red are where a seller's morning starts; the Assistant proposes for grey.

**Logging a touch.** A call: kind `call`, `done_at` now (or the time they said), the outcome — `connected`, `no_answer`,
`left_message`, `busy` — and the note in the body (what was said, what they want, objections, names). An email already sent:
`email_log`. A meeting held: kind `meeting`, `starts_at`/`ends_at`, outcome `meeting_held` or `no_show`. Always on the record the
person named (the deal first; the contact; the account); the trigger fills the rest.

**A good next step** names a kind, a moment and a purpose: *call Dana Tuesday 10:00 to confirm the demo*, not *follow up*. It fits
the stage: Qualification → a discovery call; Discovery → send the proposal (a task with a deadline); Proposal → a call to walk through
it; Negotiation → the decision meeting. It respects what was said: "call me next month" is a task on the first working day of next
month, not tomorrow.

**Reminders.** A new activity takes the person's default reminder (30 minutes before). A meeting with a contact may carry an
invitation (an ICS on the email the seller sends). Snooze is 10 minutes, an hour, or tomorrow 09:00.

**Outcomes you may set** (per kind): call — connected, no_answer, left_message, busy; meeting — meeting_held, no_show; email — sent,
replied; task, deadline — completed, cancelled.
