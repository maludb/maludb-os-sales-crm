-- The Phase 4 world (tests/phase4/lib.php p4_world()), run once per database as postgres AFTER tests/phase2/lib.php's seed_book() (Priya's SMOKE Acme Industries with Dana Reyes and two open deals, Marco's restricted
-- SMOKE Globex with a won deal, Priya's lead SMOKE Initech, Dana Reyes's reply matched to the deal). Everything added here is named "p4 …". It prints ONE line, p4world|<ids…>, in the order lib.php reads.
-- The cast: 1 admin, 26 Priya (Rep), 27 Marco (Sales Manager), 29 Ann (Viewer), 30 Dana (Rep, Partnerships), 31 Lee, 40 the Expert, 42 the Assistant.
\set ON_ERROR_STOP on
\pset tuples_only on
\pset format unaligned
SELECT set_config('app.member_id', '26', false);

SELECT id AS acme FROM accounts WHERE name = 'SMOKE Acme Industries' \gset
SELECT id AS dana_c FROM contacts WHERE last_name = 'Reyes' \gset
SELECT id AS deal1 FROM deals WHERE title = 'SMOKE Acme — 20 seats' \gset
SELECT id AS deal2 FROM deals WHERE title = 'SMOKE Acme — support add-on' \gset
SELECT id AS lead1 FROM leads WHERE company = 'SMOKE Initech' \gset
SELECT id AS globex FROM accounts WHERE name = 'SMOKE Globex' \gset
SELECT id AS globex_deal FROM deals WHERE title = 'SMOKE Globex — pilot' \gset
SELECT id AS pipe FROM pipelines WHERE key = 'new_business' \gset
SELECT id AS st_won FROM stages WHERE pipeline_id = :pipe AND kind = 'won' \gset
SELECT id AS st_lost FROM stages WHERE pipeline_id = :pipe AND kind = 'lost' \gset
SELECT id AS st_qual FROM stages WHERE pipeline_id = :pipe AND name = 'Qualification' \gset
SELECT id AS st_prop FROM stages WHERE pipeline_id = :pipe AND name = 'Proposal' \gset

-- ---- tags
INSERT INTO tags (slug, label) VALUES ('p4-vip', 'p4-vip') RETURNING id AS tag_vip \gset
INSERT INTO record_tags (record_type, record_id, tag_id) VALUES ('account', :acme, :tag_vip), ('deal', :deal1, :tag_vip);

-- ---- accounts and contacts
SELECT set_config('app.member_id', '30', false);
INSERT INTO accounts (name, domain, kind, industry, country, owner_member_id, created_by) VALUES ('p4 Umbrella Retail', 'umbrella.p4.example.invalid', 'customer', 'Retail', 'US', 30, 30) RETURNING id AS umbrella \gset
INSERT INTO contacts (first_name, last_name, email, account_id, owner_member_id, created_by, email_opt_out, lifecycle, role) VALUES ('Pat', 'Umbra', 'pat@umbrella.p4.example.invalid', :umbrella, 30, 30, true, 'customer', 'decision_maker') RETURNING id AS pat \gset
INSERT INTO contact_emails (contact_id, address, is_primary) VALUES (:pat, 'pat.umbra@elsewhere.p4.example.invalid', false) ON CONFLICT DO NOTHING;
INSERT INTO accounts (name, domain, kind, owner_member_id, restricted_to_member_id, created_by, description) VALUES ('p4 Hidden Holdings', 'hidden.p4.example.invalid', 'prospect', 30, 30, 30, 'p4 HIDDEN-DESCRIPTION the others must never read') RETURNING id AS hidden \gset
INSERT INTO contacts (first_name, last_name, email, account_id, owner_member_id, restricted_to_member_id, created_by) VALUES ('Hid', 'Den', 'hid@hidden.p4.example.invalid', :hidden, 30, 30, 30) RETURNING id AS hid_c \gset
INSERT INTO deals (title, account_id, pipeline_id, stage_id, value, currency, owner_member_id, restricted_to_member_id, created_by, expected_close_date)
  VALUES ('p4 Hidden — renewal', :hidden, :pipe, :st_qual, 77000, 'USD', 30, 30, 30, current_date + 5) RETURNING id AS hid_deal \gset
INSERT INTO notes (record_type, record_id, member_id, body) VALUES ('account', :hidden, 30, 'p4 HIDDEN-NOTE the others must never read') RETURNING id AS hid_note \gset
INSERT INTO activities (kind, subject, account_id, deal_id, owner_member_id, created_by, due_at) VALUES ('call', 'p4 HIDDEN-CALL', :hidden, :hid_deal, 30, 30, now() + interval '1 day');

-- ---- leads: working, inbox (unowned), proposed, qualified-ish, old and untouched, one in the tombstones
SELECT set_config('app.member_id', '26', false);
INSERT INTO leads (first_name, last_name, company, email, status, owner_member_id, source_id, created_by, score) VALUES ('Wanda', 'Working', 'p4 Workco', 'wanda@workco.p4.example.invalid', 'working', 26, 1, 26, 40) RETURNING id AS lead_work \gset
INSERT INTO leads (first_name, last_name, company, email, status, source_id, created_by) VALUES ('Ina', 'Inbox', 'p4 Inboxco', 'ina@inboxco.p4.example.invalid', 'new', 3, 26) RETURNING id AS lead_inbox \gset
INSERT INTO leads (first_name, last_name, company, email, status, is_proposed, source_id, created_by, message) VALUES ('Pru', 'Proposed', 'p4 Proposedco', 'pru@proposedco.p4.example.invalid', 'new', true, 5, 26, 'p4 hello from the form') RETURNING id AS lead_prop \gset
INSERT INTO leads (first_name, last_name, company, email, status, owner_member_id, source_id, created_by, created_at, updated_at) VALUES ('Oldie', 'Untouched', 'p4 Oldco', 'old@oldco.p4.example.invalid', 'new', 26, 6, 26, now() - interval '120 days', now() - interval '120 days') RETURNING id AS lead_old \gset
INSERT INTO leads (first_name, last_name, company, email, status, owner_member_id, source_id, created_by, deleted_at, deleted_by) VALUES ('Gone', 'Tomb', 'p4 Tombco', 'gone@tombco.p4.example.invalid', 'new', 26, 1, 26, now() - interval '40 days', 26) RETURNING id AS lead_tomb \gset
INSERT INTO inbound_email (mailbox_id, message_id, from_address, from_name, to_addresses, subject, body_text, received_at, match_status, status)
  VALUES ((SELECT id FROM mailboxes ORDER BY id LIMIT 1), '<p4-stranger@x>', 'stranger@elsewhere.p4.example.invalid', 'Sam Stranger', ARRAY['crm-log@example.invalid'], 'p4 Hello, who are you', 'p4 stranger text', now() - interval '2 hours', 'unmatched', 'processed') RETURNING id AS mail_unmatched \gset
INSERT INTO inbound_email (mailbox_id, message_id, from_address, from_name, to_addresses, subject, body_text, received_at, match_status, status)
  VALUES ((SELECT id FROM mailboxes ORDER BY id LIMIT 1), '<p4-prop@x>', 'pru@proposedco.p4.example.invalid', 'Pru Proposed', ARRAY['crm-log@example.invalid'], 'p4 Interested', 'p4 proposed text', now() - interval '3 hours', 'proposed_lead', 'processed') RETURNING id AS mail_prop \gset

-- ---- deals: a won one of Priya's this month, a lost one, a rotting one; products; contacts; a campaign; notes; a file
SELECT set_config('app.member_id', '26', false);
INSERT INTO deals (title, account_id, pipeline_id, stage_id, value, currency, owner_member_id, created_by) VALUES ('p4 Won — Priya', :acme, :pipe, :st_qual, 5000, 'USD', 26, 26) RETURNING id AS deal_won \gset
SELECT crm_deal_move(:deal_won, :st_won, NULL);
INSERT INTO deals (title, account_id, pipeline_id, stage_id, value, currency, owner_member_id, created_by, lost_reason_id) VALUES ('p4 Lost — Priya', :acme, :pipe, :st_qual, 8000, 'USD', 26, 26, 2) RETURNING id AS deal_lost \gset
SELECT crm_deal_move(:deal_lost, :st_lost, NULL);
INSERT INTO deals (title, account_id, primary_contact_id, expected_close_date, pipeline_id, stage_id, value, currency, owner_member_id, created_by, stage_entered_at) VALUES ('p4 Rotting — Priya', :acme, :dana_c, current_date + 30, :pipe, :st_prop, 12000, 'USD', 26, 26, now() - interval '200 days') RETURNING id AS deal_rot \gset
INSERT INTO deal_products (deal_id, position, name, provider_app, provider_id, sku, quantity, unit_price, discount_percent, recurring) VALUES
  (:deal1, 1, 'p4 Seat licence', 'inventory', '1001', 'SEAT-1', 20, 1000, 0, 'yearly'), (:deal1, 2, 'p4 Onboarding', NULL, NULL, 'ONB', 1, 4000, 0, 'none');
INSERT INTO deal_contacts (deal_id, contact_id, role, is_primary) VALUES (:deal1, :dana_c, 'decision_maker', true) ON CONFLICT DO NOTHING;
INSERT INTO notes (record_type, record_id, member_id, body) VALUES ('deal', :deal1, 26, 'p4 NOTE-LONG ' || repeat('x', 400)) RETURNING id AS note1 \gset
INSERT INTO attachments (record_type, record_id, filename, mime_type, byte_size, sha256, storage_path, uploaded_by) VALUES ('deal', :deal1, 'p4-proposal.pdf', 'application/pdf', 1234, repeat('a', 64), 'p4/none/p4-proposal.pdf', 26) RETURNING id AS att1 \gset
SELECT set_config('app.member_id', '27', false);
INSERT INTO campaigns (name, kind, status, starts_on, ends_on, budget, currency, expected_leads, owner_member_id, created_by) VALUES ('p4 Spring Push', 'email', 'active', current_date - 10, current_date + 20, 1000, 'USD', 50, 27, 27) RETURNING id AS camp \gset
INSERT INTO campaign_members (campaign_id, lead_id, status, added_by) VALUES (:camp, :lead1, 'sent', 27);
INSERT INTO campaign_members (campaign_id, contact_id, status, added_by) VALUES (:camp, :dana_c, 'responded', 27);
UPDATE leads SET campaign_id = :camp WHERE id = :lead1;

-- ---- lists, a saved view, quotas
SELECT set_config('app.member_id', '26', false);
INSERT INTO lists (name, kind, record_kind, filters, owner_member_id, is_shared, created_by) VALUES ('p4 Smart new leads', 'smart', 'lead', '{"status":"new"}', 26, true, 26) RETURNING id AS list_smart \gset
INSERT INTO lists (name, kind, record_kind, owner_member_id, is_shared, created_by) VALUES ('p4 Static contacts', 'static', 'contact', 26, true, 26) RETURNING id AS list_static \gset
INSERT INTO list_members (list_id, record_id, added_by) VALUES (:list_static, :dana_c, 26), (:list_static, :hid_c, 26);
INSERT INTO lists (name, kind, record_kind, owner_member_id, is_shared, created_by) VALUES ('p4 Priya private list', 'static', 'lead', 26, false, 26) RETURNING id AS list_private \gset
INSERT INTO saved_views (name, scope, owner_member_id, pipeline_id, record_kind, filters, sort_by, sort_dir, layout) VALUES ('p4 My open deals', 'personal', 26, NULL, 'deal', '{"status":"open"}', 'value', 'desc', 'board') RETURNING id AS view_p \gset
INSERT INTO quotas (member_id, month, amount) VALUES (26, date_trunc('month', current_date)::date, 20000), (27, date_trunc('month', current_date)::date, 50000) ON CONFLICT DO NOTHING;

-- ---- activities: a done call with an outcome, an email in, a private activity of Marco's, a task without a record
INSERT INTO activities (kind, subject, deal_id, contact_id, owner_member_id, created_by, due_at, done_at, done_by, outcome) VALUES ('call', 'p4 Done call', :deal1, :dana_c, 26, 26, now() - interval '3 days', now() - interval '3 days', 26, 'connected') RETURNING id AS act_done \gset
INSERT INTO activities (kind, subject, deal_id, contact_id, owner_member_id, created_by, due_at, done_at, done_by, email_direction) VALUES ('email', 'p4 Email out', :deal1, :dana_c, 26, 26, now() - interval '8 days', now() - interval '8 days', 26, 'out') RETURNING id AS act_mail \gset
SELECT set_config('app.member_id', '27', false);
INSERT INTO activities (kind, subject, account_id, owner_member_id, created_by, due_at, is_private) VALUES ('call', 'p4 PRIVATE-ACTIVITY', :acme, 27, 27, now() + interval '2 days', true) RETURNING id AS act_private \gset
INSERT INTO activity_participants (activity_id, contact_id, response) VALUES (:act_private, :dana_c, 'invited');

-- ---- emails out: private to Priya, public, a draft (queued); both visible only as the schema says
INSERT INTO outbound_email (to_address, subject, status, sent_at, sender_member_id, contact_id, deal_id, body_text, is_private, message_id) VALUES
  ('dana.reyes@acme.example.invalid', 'p4 PRIVATE Re: pricing', 'sent', now() - interval '5 hours', 26, :dana_c, :deal1, 'p4 PRIVATE-BODY only Priya and managers', true, '<p4-priv@x>') RETURNING id AS mail_priv \gset
INSERT INTO outbound_email (to_address, subject, status, sent_at, sender_member_id, contact_id, deal_id, body_text, is_private, message_id) VALUES
  ('dana.reyes@acme.example.invalid', 'p4 Public follow-up', 'sent', now() - interval '4 hours', 26, :dana_c, :deal1, 'p4 PUBLIC-BODY follow up', false, '<p4-pub@x>') RETURNING id AS mail_pub \gset
INSERT INTO outbound_email (to_address, subject, status, sender_member_id, contact_id, deal_id, body_text, is_private, message_id) VALUES
  ('dana.reyes@acme.example.invalid', 'p4 DRAFT subject', 'queued', 26, :dana_c, :deal1, 'p4 DRAFT-BODY', false, '<p4-draft@x>') RETURNING id AS mail_draft \gset

-- ---- an import, a key with usage, proposals, dispatches, notifications
INSERT INTO imports (kind, file_name, status, row_count, rows_made, rows_updated, rows_skipped, created_by, owner_member_id, finished_at, mapping, log) VALUES ('leads_csv', 'p4.csv', 'done', 3, 2, 0, 1, 26, 26, now(), '{"email":"Email"}', 'p4 import log') RETURNING id AS imp \gset
INSERT INTO import_rows (import_id, row_number, result, record_id, message) VALUES (:imp, 1, 'created', :lead_work, NULL), (:imp, 2, 'created', :lead_inbox, NULL), (:imp, 3, 'skipped', NULL, 'p4 duplicate');
INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, rate_per_minute, rate_per_day) VALUES (26, 'p4 priya key', repeat('b', 64), 'api', 60, 5000) RETURNING id AS key_p \gset
INSERT INTO key_usage (token_id, bucket_kind, bucket_start, calls, refused) VALUES (:key_p, 'day', date_trunc('day', now()), 14, 2), (:key_p, 'day', date_trunc('day', now()) - interval '2 days', 6, 0), (:key_p, 'day', date_trunc('day', now()) - interval '30 days', 99, 9);
INSERT INTO assistant_proposals (kind, deal_id, for_member_id, title, payload, proposed_by, status) VALUES ('next_step', :deal2, 26, 'p4 Call about the add-on', '{"kind":"call"}', 42, 'proposed') RETURNING id AS prop_open \gset
INSERT INTO assistant_proposals (kind, deal_id, for_member_id, title, payload, proposed_by, status, decided_by, decided_at) VALUES ('rotting', :deal_rot, 26, 'p4 Rotting deal', '{}', 42, 'dismissed', 26, now()) RETURNING id AS prop_done \gset
INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, acting_member_id, status, reply_excerpt, attempts, deal_id, lead_id, answered_at) VALUES
  ('lead', :lead1, 42, 'lead_qualify', 'chat', 26, 'answered', repeat('r', 400), 1, NULL, :lead1, now()) RETURNING id AS disp \gset
INSERT INTO notifications (member_id, kind, title, body, deal_id) VALUES (26, 'deal_moved', 'p4 A deal moved', 'p4 body', :deal1), (26, 'reminder', 'p4 read one', NULL, NULL), (27, 'deal_won', 'p4 Marco only', NULL, NULL);
UPDATE notifications SET read_at = now() WHERE member_id = 26 AND title = 'p4 read one';

-- ---- aging: a stage that rots, an account nobody called, a birthday coming, a duplicate lead
UPDATE stages SET rotting_days = 30 WHERE id = :st_prop;
UPDATE accounts SET last_touched_at = now() - interval '60 days' WHERE id = :umbrella;
UPDATE contacts SET birthday = (current_date + 10 - interval '30 years')::date WHERE id = :pat;
INSERT INTO leads (first_name, last_name, company, email, status, owner_member_id, source_id, created_by) VALUES ('Wanda', 'Working', 'p4 Workco', 'wanda@workco.p4.example.invalid', 'new', 26, 1, 26) RETURNING id AS lead_dup \gset

-- ---- the trail: rows the activity tools read (a write made the way the application does is log_activity(); these are its rows, planted)
INSERT INTO activity_log (occurred_at, actor_member_id, source, action, screen, entity_type, entity_id, after, deal_id, account_id, agent_run_id) VALUES
  (now() - interval '2 hours', 26, 'web', 'deal.move', 'deal-board', 'deal', :deal1, '{"from_stage":"Qualification","to_stage":"Proposal","stage_name":"Proposal","seconds_in_stage":86400}', :deal1, :acme, NULL),
  (now() - interval '90 minutes', 42, 'agent', 'activity.create', NULL, 'activity', :act_done, '{"kind":"call","subject":"p4 Done call"}', :deal1, :acme, 424242),
  (now() - interval '80 minutes', 26, 'web', 'screen.view', 'deal-board', 'deal', :deal1, NULL, :deal1, :acme, NULL),
  (now() - interval '70 minutes', NULL, 'application', 'share.read', NULL, 'share', NULL, '{"application":"gl","tool":"accounts_index","rows":2,"ms":4}', NULL, NULL, NULL),
  (now() - interval '60 minutes', 30, 'web', 'deal.update', 'deal-edit', 'deal', :hid_deal, '{"name":"p4 Hidden — renewal"}', :hid_deal, :hidden, NULL),
  (now() - interval '50 minutes', 27, 'web', 'key.mint', 'tokens', 'key', :key_p, '{"label":"p4 priya key"}', NULL, NULL, NULL),
  (now() - interval '40 minutes', 26, 'api', 'api.call', NULL, 'key', :key_p, '{"route":"/api/v1/deals"}', NULL, NULL, NULL),
  (now() - interval '30 minutes', 26, 'web', 'lead.create', 'lead-form', 'lead', :lead_work, '{"name":"Wanda Working"}', NULL, NULL, NULL),
  (now() - interval '20 minutes', 27, 'web', 'settings.save', 'settings', 'settings', NULL, '{"changed":["rotting_days"]}', NULL, NULL, NULL);
UPDATE activity_log SET lead_id = :lead_work WHERE entity_type = 'lead' AND entity_id = :lead_work AND action = 'lead.create';
UPDATE activity_log SET token_id = :key_p WHERE action = 'api.call' AND entity_id = :key_p;

\echo p4world|:acme|:dana_c|:deal1|:deal2|:lead1|:globex|:globex_deal|:pipe|:umbrella|:pat|:hidden|:hid_c|:hid_deal|:hid_note|:lead_work|:lead_inbox|:lead_prop|:lead_old|:lead_tomb|:mail_unmatched|:mail_prop|:deal_won|:deal_lost|:deal_rot|:note1|:att1|:camp|:list_smart|:list_static|:list_private|:view_p|:act_done|:act_mail|:act_private|:mail_priv|:mail_pub|:mail_draft|:imp|:key_p|:prop_open|:prop_done|:disp|:tag_vip|:lead_dup
