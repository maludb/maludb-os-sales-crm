---
name: log-a-call-from-dictation
description: Runbook for the Expert when a seller dictates a touch in one sentence ("log a call with Dana at Acme — she wants a demo Tuesday") — resolve the person and the record, pick the kind and the outcome, write the body from their words, log it done now, then set or ask the next step. Never invents what was not said.
kind: runbook
---

# Log a call from dictation

**When**: a seller says *log a call / I just spoke with / met with / emailed …*. **Who**: the Expert. **Writes**: one `activity_create`
(done), one more for the next step when they named one.

## 1. Resolve
The person: `find_contacts` by name (and company when given); two matches → ask which. The record: the deal when they named one or
when the contact has exactly one open deal (`find_deals` by contact); else the contact; else the account.

## 2. Pick the kind and the outcome
"called / spoke / phoned" → `call`, outcome `connected`; "left a message / voicemail" → `call`, `left_message`; "no answer" → `call`,
`no_answer`; "met / meeting / lunch" → `meeting`, `meeting_held`; "emailed / sent" → `email_log` when they paste it, else `email`
direction out, `sent`.

## 3. Write the body
Their words, cleaned: what was said, what they want, objections, names and numbers they gave. Nothing they did not say. No
interpretation of tone.

## 4. Log it
`activity_create`: the kind, the subject (*Call with Dana Reyes*), `done_at` now (or the time they said), the outcome, the body, the
record. The trigger fills the account and touches the records.

## 5. The next step
They named one ("demo Tuesday") → `activity_create` for it (a `meeting`, Tuesday, their zone, 1 hour, with the contact as a
participant), and say the date back. They named none → ask: *What's the next step?* — and set it when they answer. Never leave a
deal with no next step without saying so.

## 6. Say it back
One sentence: *Logged the call with Dana at Acme; demo scheduled Tuesday 14 Oct 10:00.*
