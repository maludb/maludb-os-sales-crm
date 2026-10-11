# Build spec: email (slice 5)

What exists at the end: the logging mailbox's webhook and poll land every email as an `inbound_email` row; the worker's matcher (`crm_match_inbound_email`) puts
it on contacts and the deal, proposes strangers as leads, ignores bounces; the unmatched screen with Make lead · Attach · Ignore; compose from a record with
a template's merge fields filled, attachments and a meeting's ICS, sent through MaluMail with Reply-To the mailbox and threaded by our message id; the log
form for an email already sent; private emails; templates shared and own; the unsubscribe door; the meeting invitation.

Schema (db/011, 010, never modified): `mailboxes`, `inbound_email`, `outbound_email`, `email_templates`, `crm_email_allowed()`, `crm_contact_opt_out()`, `crm_match_inbound_email()` (db/011);
`activities` kind email (db/010); `mcp_emails`, `mcp_email_templates`, `mcp_mailboxes` (db/016).

## Divergences from the design
1. **A draft is an `outbound_email` row with status `queued` and `sent_at NULL` owned by its sender** (no drafts table); `email_send` flips it to the outbox and the worker sends.
2. **The webhook (`/channels/malumail/mail`) and the poll both write `inbound_email`; the matcher runs in the worker** (`mail_match` pass), never in the webhook request.
3. **The unsubscribe door `/u/{token}`** (a 48-hex token per contact per send, sha256 kept in `outbound_email.message_id`-derived rows): a public GET shows one button; the POST
   calls `crm_contact_opt_out(contact, 'email', 'unsubscribe_link')`; a route, never an action.

## Who may do what
email_draft: `email.send` or `email.log` · email_send: `email.send` + `see` on every recipient contact + `crm_email_allowed()` for each (refused by name) — pauses for an agent ·
email_log: `email.log` + `see` · email_private_set: the sender or a manager · email_attach_contact: `contacts.write` · email_make_lead / email_ignore: `leads.write` · meeting_invite_send:
own or `activities.any`, pauses for an agent · template_save: own; shared needs `templates.manage` · template_delete: own or `templates.manage` (deletion).

## Screens
`email-compose` `/email/compose?contact=&deal=&account=&template=&activity=&draft=` (To as chips from the record's contacts with the opt-out mark, the `template` picker with a
live merge preview, subject, body (Markdown editor), attachments, the ICS line when `activity` is a meeting, Save draft · Send) · `email-view` `/email/{direction}/{id}` · `email-unmatched`
`/email/unmatched` · `log-email` `/log/email?contact=&deal=` · `template-list` `/email/templates` · `template-add` / `template-edit` `/email/templates/new` · `/email/templates/{id}/edit`.

## The matcher, the webhook, the poll (`app/features/email/{inbound,matcher,send,merge}.php`)
- `html/channels/malumail/mail.php`: verify the signature with the env key `mailboxes.webhook_secret_ref` names (`hash_hmac('sha256', body, secret)` in `X-MaluMail-Signature`, ±5 min);
  insert `inbound_email` (status `received`, `match_status pending`; a bounce or auto-reply header → status `bounce`/`auto_reply`); 204; logged `mail.received` (the message id, never the body).
- The worker's `mail_poll` pass: MaluMail's mailbox API for unread since `last_polled_at` when `poll_enabled` (the fallback); `mail_match`: `crm_match_inbound_email(id)` per pending row,
  attachments saved through the canonical store on the activity; `mailboxes.last_webhook_at`/`last_polled_at`/`last_error` kept.
- `merge.php`: `{{contact.first_name}}`, `{{contact.name}}`, `{{account.name}}`, `{{deal.title}}`, `{{deal.value}}`, `{{activity.subject}}`, `{{activity.date}}`, `{{activity.time}}`,
  `{{sender.name}}`, `{{sender.company}}`, `{{sender.signature}}`; an unknown field stays as typed and the preview marks it.
- `send.php`: `malumail_send()` with From the business's `MAIL_FROM` and the seller's name, `Reply-To` the mailbox, `Message-ID` ours (kept in `outbound_email.message_id`), `In-Reply-To`
  when replying, the footer + the unsubscribe link, the ICS as `text/calendar; method=REQUEST` when attached; on success `status sent`, an `activities` row (kind email, out, done) per
  recipient contact and the deal; `email_templates.usage_count + 1`.

## Files (exactly these)
`html/email/{compose,view,unmatched,draft,send,private,attach,make-lead,ignore,invite}.php`, `html/email/templates/{index,form,save,delete}.php`, `html/log/email.php`, `html/channels/malumail/mail.php`,
`html/u/index.php`; `app/features/email/{queries,present,write,inbound,matcher,send,merge}.php`, `app/features/templates/{queries,write}.php`; `app/views/email/{compose,view,unmatched}.php`,
`app/views/email/partials/{to-chips,merge-preview,thread,unmatched-row}.php`, `app/views/templates/{index,form}.php`, `app/views/public/unsubscribe.php`, `app/views/emails/{notification,reminder,morning-note}.php`
(the outbox's mail bodies — slice 8 sends them); `bin/worker.php`'s real `mail_poll`, `mail_match`; `app/pickers.php` gains `template`; `tests/phase3/slice5/`.

## MCP tools this slice makes real
`find_emails`, `get_email`, `emails_unmatched`, `proposed_leads`, `find_templates` (the resolver), `mailbox_health`.

## Manifest rows claimed
Screens (7): `email-compose`, `email-view`, `email-unmatched`, `log-email`, `template-list`, `template-add`, `template-edit`
Actions (10): `email_draft`, `email_send`, `email_log`, `email_private_set`, `email_attach_contact`, `email_make_lead`, `email_ignore`, `meeting_invite_send`, `template_save`, `template_delete`

## Activity log events
`email.draft|send|log|private|attach|make_lead|ignore|invite`, `template.save|delete`, `mail.received`, `mail.matched|proposed|unmatched|ignored` (the worker, per email: the message id and
contact ids), `contact.opt_out` (the door, reason `unsubscribe_link`). A body is never in a payload.

## Status vocabulary
Direction: in `info` "received", out `primary` "sent"; draft `secondary`; private: a lock. Match: matched `success`, proposed_lead `warning`, unmatched `secondary`, bounce `danger`. Ids:
`compose-form`, `compose-to`, `compose-template`, `compose-preview`, `email-view-body`, `unmatched-list`, `unmatched-{id}`, `template-form`, `template-merge-fields`.

## Out of scope for this slice
The outbox's sending of notifications (8), texts (8), campaign emails (6 — `campaign_email_send` queues drafts per member through this slice's `send.php`).

## Proof (`tests/phase3/slice5/run.sh`: scratch `crm_dev5`; the fake MaluMail receives sends and posts the webhook; the worker by hand)
The Phase 0 proof's mailbox cases through the webhook and the worker (two contacts and a deal by tag, a reply by thread, a stranger proposed, a bounce ignored, a duplicate message id refused);
compose fills the merge fields and refuses an opted-out contact by name; a draft saved and sent → the fake MaluMail got Reply-To, Message-ID, the footer with the unsubscribe link; the door
opts the contact out; a reply to our Message-ID lands on the deal; private hides from another seller and not from a manager; an agent's `email_send` pauses; templates shared and own, a shared
one by a Rep is 403; the invitation carries the ICS; JSON mode; 375 and 1280; the registry `--check` (7 screens, 10 actions, 3 approvals).

## Open questions
None.

## Built and proven (2026-10-11)
The logging mailbox's **webhook** (`/channels/malumail/mail`: the signature `t=…,v1=` of `app/mail.php` checked with the env key `mailboxes.webhook_secret_ref` names; the notice carries headers
only, so the message is read through MaluMail's mailbox MCP and kept RAW as an `inbound_email` row — received/pending, a delivery report → bounce, an auto-reply → auto_reply, our own address →
ignored — with its files in the canonical store; marked read in the mailbox; 204 whatever became of it) and the worker's **`mail_poll`** (the unread mail the webhook missed; a failed read a `failed`
row retried; `last_polled_at` / `last_error` kept) and **`mail_match`** (`crm_match_inbound_email()` per email in its own transaction, the files copied onto each activity, a contact's reply told to its
owner as `reply_received`, `mail.matched|proposed|unmatched|ignored` logged). **Compose** (`/email/compose`: To as chips from the record's people with the opt-out marked, the template picker with ONE HTMX
refresh of `#compose-fill`, plain template links without JavaScript, the Markdown body with a preview of what the merge filled and what is still to fill, files from the record, the meeting's ICS line,
Reply), **drafts** (an `outbound_email` row still `queued`, its sender's alone), **send** (From `MAIL_FROM` under the seller's name, `Reply-To` the logging mailbox, a Message-ID of ours, In-Reply-To /
References, the footer with the unsubscribe link in both parts, the files and the ICS as `text/calendar; method=REQUEST`; one message per person, merge fields filled for each; the opt-out refused by
name before anything goes; merge fields left standing refused), **`email_log`**, **private**, **Attach · Make lead · Ignore** on the unmatched screen, the **meeting's invitation** (also a button on
the meeting's page), **templates** (list, form with the eleven merge fields, save, delete-or-archive), the **unsubscribe door** `/u/{token}`, the picker `template`, the three mail bodies
`app/views/emails/{notification,reminder,morning-note}.php` with `render_mail()`. Registry: 48 screens and 71 actions built (7 and 10 of them this slice's); 3 paused for agents (`email_send`,
`meeting_invite_send`: external_send; `template_delete`: deletion).

**Proven** by `tests/phase3/slice5/run.sh` on a fresh `crm_dev5`, under php -S and a real Apache — mail 63 (the webhook's refusals; a seller's BCC stored raw then matched by the worker onto two contacts and the
deal by tag; a reply by thread; a stranger proposed; a bounce and an auto-reply ignored; a duplicate message id and a retried notice refused; the poll; a failed read tried again; a file on its activity;
the unmatched screen), compose 53 (chips, opt-out marked, the template filled, the unknown and the unfillable marked, drafts kept as their sender's alone, files, no-JavaScript, Reply), send 67 (what the
fake MaluMail receives, the token nowhere in the database, refusals before anything goes, several people, a reply by our Message-ID and by the provider's, In-Reply-To, files and the ICS, a failure left as
a draft, private, `email_log`), door 22, templates 44, invite 25, json 57 (every screen and handler, the HTMX stamps, the log's vocabulary, the registry), browser 66 (a real compose with the template
picked through the record picker, Save draft, Send, the unsubscribe link at 375, Reply, private, the unmatched screen's three actions, templates, the log form, the invitation, compose with JavaScript
off, ten screens at 375 and 1280). Slices 1–4, Phase 2 (its routing, Log-sheet and webhook proofs now follow what is built), Phase 0 and Phase 1 re-run green.

**Found and fixed:** **db/021** — (1) `outbound_email` had no place for what a reply answers or the meeting an invitation is for: `in_reply_to`, `references_ids`, `activity_id` added; (2) `inbound_email
(mailbox_id, provider_uid)` was not unique (Help Desk's copy is), so a retried notice or an overlapping poll could make a second row — now one; (3) `mcp_emails` showed an outbound DRAFT (or a send that did
not go) to anyone who sees the contact, and so would every records tool — the outbound half now needs `status = 'sent'` or the sender; (4) `crm_match_inbound_email()` threaded a reply only by OUR
Message-ID — it also takes the id MaluMail answered with (`provider_message_id`); (5) the three seeded shared templates held a backslash and an `n` where db/011 meant a line break — broken into lines.
Also: `tests/setup_dev.sh` now sets `MALUMAIL_MCP_URL` (the mailbox MCP read the real api.malumail.com); `tests/phase2/servers.sh` starts the fake MaluMail with workers and a state file and gives it the
webhook secret; the fake MaluMail gained `POST /_deliver` (puts a message in a mailbox and posts the signed notice as MaluMail does) and honours the `message_id` a send names; `bin/build_action_registry.php`
learned the routing form `/email/{direction}/{id}`; the unsubscribe door logs its route as `POST /u/{token}` — the log's `route` column would otherwise hold the token.

**Decisions taken here (within the spec):**
- **`email_send` sends in the request** (the spec's divergence 1 says "flips it to the outbox and the worker sends", `send.php` says "on success status sent"): a seller gets the provider's answer on the page, a
  paused agent's approved replay sends at once, and the outbox (slice 8's) stays notifications and texts. A failure leaves the row `failed` with the sentence and it is still a draft to send again; a
  suppressed address is `suppressed` and is not.
- **One outbound row is one message to one person.** A draft with several To's keeps the first as `to_address` and the others in `cc_addresses`; the send gives each their own row (own Message-ID, own
  unsubscribe link, own merge). Merge fields about the contact stay as typed at compose when several are ticked and are filled for each at the send; any `{{…}}` still standing then (an unknown field, one with
  nothing to fill it) is refused by name — the draft stays.
- **The unsubscribe token** (48 hex per message): only its SHA-256 is kept, as the local part of the message's Message-ID, `<sha256@MAIL_FROM's domain>`; the link carries the token, the door hashes it and finds
  the send by that Message-ID. The stored `body_html` is the body without the footer (its link holds the token). The door never asks MaluMail to suppress the address (that list is the whole account's).
- **The seller's signature** is appended at send unless the text already holds it (`{{sender.signature}}` with none written fills as nothing); `{{deal.title}}` with no deal is "missing" and refused.
- **Reply** (`?reply=<received email id>` on compose, `reply` on `email_draft`/`email_send`, a button on a received email) is not in the manifest's parameter lists; it is how "In-Reply-To when replying" gets
  an entry point. The manifest is unchanged.
- **The party of an unmatched email** (Attach, Make lead): when a stranger wrote it, the sender; when a seller of ours BCC'd it (the only way a message is `unmatched`), the first address they wrote to that is no
  mailbox and no member, named from the header. Make lead on a proposed one accepts the proposed lead; either way the email is put on the lead as an activity. Attach also dismisses a lead the email had proposed
  (as a person's act, with `contacts.write` and the contact's edit rule) and runs the matcher again.
- **`email_log`** makes one done email activity per contact with the pasted text as the activity's body (no inbound/outbound row: `inbound_email` needs a mailbox); an opted-out contact can still have what passed logged.
- **Private**: the sender, or `deals.any`; an email a contact wrote has no sender of ours, so only a manager marks it. The activities it made follow.
- **Drafts** are the sender's alone — a manager and the admin do not see them either; they are listed on `/email/unmatched` ("Your drafts") and on the record's Emails tab for their sender. There is no delete-draft
  action in the manifest, so none was built.
- **`meeting_invite_send`** goes to the meeting's contact participants and the contact it is with (made a participant if not); an opted-out one refuses the whole invitation by name. The line the seller writes is in the
  mail and the event's description; the internal notes, the record's name and the CRM's own address never are (`ics_event()` gained `organizer`, `attendees` and `external` — one ICS writer).
- **The reply notification** (`reply_received`, a kind the schema and My settings already name) is made by `mail_match` for a matched reply from a contact, to the deal's owner, else the contact's, if a person.
- **Mail bodies**: slice 4's reminder pass writes no mail body (the database composes the outbox row's subject and body); `render_mail('reminder'|'morning-note'|'notification', row)` dresses that same row, one
  layout in `notification.php`. Slice 8's `outbox` pass calls it.
- **What MaluMail must accept.** The send uses `reply_to`, `in_reply_to`, `references` (as Help Desk's does — MaluMail M4), and `message_id` and `attachments[{filename, content_type, content_base64}]` besides; the
  malumail-send skill documents none of the last two (and says there are no attachments or Reply-To). The proofs' fake accepts them; the live API must before Phase 5 — if it keeps its own Message-ID the matcher still
  threads by `provider_message_id`, but the unsubscribe link then depends on our id surviving.
- Added files beyond the list: `app/views/log/email.php` (the log form's view), `db/021_outbound_threading.sql`, the proofs' `lib.php`.

**Owed at install (found at verification, 2026-10-11).** The send passes MaluMail `reply_to`, `message_id`, `in_reply_to`, `references` and
`attachments[]`. The `malumail-send` skill says MaluMail's live `/v1/send` supports no Reply-To, no custom headers and no attachments. Only the fake MaluMail
has been proven; the live API was not checked (it needs a key, and a send is outward-facing). What depends on what:
- **Unsubscribe does NOT depend on MaluMail.** The door finds a send by the token's hash, stored as our own `outbound_email.message_id`.
- **Threading of replies does not depend on it either.** If MaluMail keeps its own Message-ID, the provider's id is stored and db/021's matcher threads by it.
- **Replies reaching the logging mailbox DO depend on it.** If Reply-To is dropped, a reply goes to `MAIL_FROM`. The fix with no code is to set `MAIL_FROM` to the logging mailbox's own address.
- **Files and the meeting's invitation (.ics) DO depend on it.** If attachments are dropped or refused, an invitation arrives without its calendar entry, or the send fails and stays a draft.

The Phase 5 end-to-end proof sends one email with a file and an invitation to a real mailbox and looks. If attachments are unsupported, MaluMail owes them (an MM item).
