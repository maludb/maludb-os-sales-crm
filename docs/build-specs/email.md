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
