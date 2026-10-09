---
name: find-and-merge-duplicates
description: Runbook for duplicates — how find_duplicates matches (exact email, exact domain, digits-only phone, trigram name), how the Assistant proposes a pair and what the proposal carries, how a manager chooses the survivor and what records_merge does (the survivor keeps its values, takes the loser's where empty, every child re-pointed, the loser a tombstone). The Assistant proposes; a manager merges.
kind: runbook
---

# Find and merge duplicates

**When**: the Monday part of the morning note, after an import, or when a manager asks. **Who**: the Assistant proposes
(`duplicate_propose`); a Sales Manager merges (`records_merge`, which pauses for an agent — it is a person's).

## 1. Find
`find_duplicates` (kind: contact, account, lead): pairs with `matched_on` — `email` (exact, any address the person uses), `domain`
(exact), `phone` (digits only, 7 or more), `name` (trigram similarity over the threshold; accounts also by city when both have one).
Email and domain pairs are near-certain; name pairs are a suggestion.

## 2. Propose
One `duplicate_propose` per pair not already open or dismissed (`assistant_proposals`): the two ids, `matched_on`, and a line for
each side (name, company, email, owner, last touched, open deals) so the manager decides without opening both.

## 3. The survivor (the manager's choice)
The older record, or the one with the open deals, or the one owned by the seller who works the account. The merge page shows the
two columns; the manager picks.

## 4. What the merge does
`records_merge(kind, survivor, loser)`: the survivor keeps its values and takes the loser's where its own are empty (every address
moves to `contact_emails`); every child is re-pointed — activities, participants, deals and deal contacts, campaign memberships,
notes, attachments, tags, list memberships, notifications, the lead that converted into it; the loser becomes a tombstone with
`merged_into_id` for thirty days, then is purged. For accounts the loser's contacts and deals move too; one primary contact survives.

## 5. Never
Merge a lead into a contact (convert it). Merge across kinds. Merge a restricted record you cannot see. Merge when the pair is only
a name match and the two have different domains — propose, and let the manager look.
