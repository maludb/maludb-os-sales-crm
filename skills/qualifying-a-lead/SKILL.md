---
name: qualifying-a-lead
description: What to look for in a new lead (fit, need, timing, authority, the source's history), how a score is proposed and never a gate, when a lead is unqualified and why that is recorded with a reason, what the conversion finds or makes. For the Assistant's lead_qualify proposals and the Expert's conversions.
---

# Qualifying a lead

**What a lead is.** One row: a person, a company, a source, a message. New until someone touches it; working while they do;
qualified when converted; unqualified with a reason (Not a fit, No budget, Unresponsive, Duplicate, Spam, Competitor).

**Look at** (`get_lead`, `source_performance`, `find_accounts` by domain, `find_duplicates`): is the company one we know (an account
exists by domain — the conversion will find it)? Does the source convert (the source's conversion rate)? Is the message a need we
serve, with a timing? Is the person likely to decide (title)? Is it a duplicate of an open lead or a known contact?

**The score** (0–100) is a proposal (`lead_qualify_propose` with `reasons[]`): the source's history (+), a known account (+), a
corporate domain (+), a title that decides (+), a message naming a need and a timing (+); a free-mail address (−), no company (−), a
competitor's domain (−), a duplicate (−). It is shown on the card and **never gates** anything: a seller converts a 20 and ignores a 90.

**Unqualified** is a decision with a reason, recorded so the source report learns; it is not deletion. A spam lead is unqualified
`Spam`, not deleted. A duplicate is unqualified `Duplicate` and the other kept — or merged by a manager.

**The conversion** (`lead_convert`) finds the account by the address's domain, then by the company's name; finds the contact by
email; makes what it does not find; makes a deal when asked (the default pipeline's first stage, the lead's owner). A free-mail
domain never makes an account. A converted lead is read-only and points at the three.
