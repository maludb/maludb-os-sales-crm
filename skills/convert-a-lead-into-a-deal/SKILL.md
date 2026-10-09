---
name: convert-a-lead-into-a-deal
description: Runbook for converting a lead — what lead_convert finds (an account by domain then name, a contact by email) and what it makes, how to choose the pipeline, title, value and close date from what the seller said, what to say back, and what to do when the lead is a duplicate or should be unqualified instead. For the Expert; the Assistant only proposes.
kind: runbook
---

# Convert a lead into a deal

**When**: a seller says *convert the Globex lead / make a deal from Jordan Lee / qualify …*. **Who**: the Expert (`lead_convert`).

## 1. Read the lead
`get_lead`: status (new or working only — a converted lead is refused, an unqualified one must be requalified first), the company,
the address, the owner, the source, the message. `find_duplicates` (lead): a duplicate is unqualified `Duplicate`, not converted twice.

## 2. Say what will be found
By the address's domain (`find_accounts` domain=) then by the company's name: an existing account is found, not made; by the email
(`find_contacts` email=): an existing contact is found and attached to the account when it has none. A free-mail address (gmail
and the like) makes no account from its domain.

## 3. The deal
Make one unless the seller says not to. Pipeline: the one they named, else the default. Title: the account's name + what they
said (*Globex — 40 seats*), else *<account> — new business*. Value and close date: only what they said; empty otherwise, and say so.

## 4. Convert
`lead_convert` (lead, account?, contact?, make_deal, pipeline?, title?, value?, close?). Answers the three ids and whether the account
and contact were made or found.

## 5. Say it back
*Converted: Globex Corp (new), Jordan Lee (new), deal D-000012 in Qualification at 40,000 closing 15 Dec.* Then the next step
(`activity-based-selling`): *What's the first step — a discovery call?*
