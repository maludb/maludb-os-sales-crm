-- Phase 0 proof of the schema (docs/sales-crm-design.md §10). Everything happens inside one transaction that is ROLLED BACK:
-- fixtures written as sales_crm_rw, reads as the two read roles, acting members set through app.member_id exactly as PHP and
-- the MCP servers set it. Fixtures are synthetic. Run as postgres on a SCRATCH database — never the installed one — after loading
-- every migration in order (tests/phase0/run.sh does all of it):
--   sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d crm_dev0 -f db/proof/phase0_proof.sql; echo "exit $?"
-- Prints one line per check (ok / FAIL) and the count; exits non-zero when any check failed.
\set QUIET on
\o /dev/null
BEGIN;
CREATE TEMP TABLE proof (n serial, ok boolean, label text);
GRANT ALL ON proof TO PUBLIC; GRANT ALL ON SEQUENCE proof_n_seq TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.check(b boolean, l text) RETURNS void LANGUAGE sql AS $$ INSERT INTO proof (ok, label) VALUES (COALESCE(b, false), l) $$;
CREATE OR REPLACE FUNCTION pg_temp.refused(p_sql text, p_like text, l text) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    BEGIN
        EXECUTE p_sql;
        INSERT INTO proof (ok, label) VALUES (false, l || ' (was NOT refused)');
    EXCEPTION WHEN OTHERS THEN
        INSERT INTO proof (ok, label) VALUES (SQLERRM ILIKE '%' || p_like || '%', l || CASE WHEN SQLERRM ILIKE '%' || p_like || '%' THEN '' ELSE ' (said: ' || SQLERRM || ')' END);
    END;
END$$;
CREATE OR REPLACE FUNCTION pg_temp.as_member(p bigint) RETURNS void LANGUAGE sql AS $$ SELECT set_config('app.member_id', COALESCE(p::text, ''), true) $$;
CREATE TEMP TABLE ids (k text PRIMARY KEY, i bigint);
GRANT ALL ON ids TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.i(k text) RETURNS bigint LANGUAGE sql STABLE AS $$ SELECT i FROM ids WHERE ids.k = $1 $$;
CREATE OR REPLACE FUNCTION pg_temp.keep(k text, v bigint) RETURNS void LANGUAGE sql AS $$ INSERT INTO ids (k, i) VALUES ($1, $2) ON CONFLICT (k) DO UPDATE SET i = EXCLUDED.i $$;
CREATE OR REPLACE FUNCTION pg_temp.stage(p_pipe text, p_name text) RETURNS bigint LANGUAGE sql STABLE AS $$ SELECT s.id FROM stages s JOIN pipelines p ON p.id = s.pipeline_id WHERE p.key = p_pipe AND s.name = p_name $$;
GRANT EXECUTE ON FUNCTION pg_temp.check(boolean, text), pg_temp.refused(text, text, text), pg_temp.as_member(bigint), pg_temp.i(text), pg_temp.keep(text, bigint), pg_temp.stage(text, text) TO PUBLIC;

-- ---------------------------------------------------------------- fixtures, as the writer: every actor of §2
SET ROLE sales_crm_rw;
SELECT pg_temp.as_member(1);
INSERT INTO members (id, member_kind, display_name, email, business_role, is_external, status, capability, roles) VALUES
 (1,  'human', 'SMOKE Owner',     'owner@example.invalid',  'super_admin', false, 'active', 'admin', '{admin}'),       -- the CRM admin
 (26, 'human', 'SMOKE Priya',     'priya@example.invalid',  'user', false, 'active', 'write', '{user}'),               -- a Sales Rep, Sales
 (27, 'human', 'SMOKE Marco',     'marco@example.invalid',  'user', false, 'active', 'write', '{manager,user}'),       -- the Sales Manager, Sales (manager of it)
 (28, 'human', 'SMOKE Bea',       'bea@example.invalid',    'user', false, 'active', 'write', '{}'),                   -- a Rep whose roles have not arrived, Sales
 (29, 'human', 'SMOKE Ann',       'ann@example.invalid',    'user', false, 'active', 'read',  '{viewer}'),             -- a Viewer (finance)
 (30, 'human', 'SMOKE Dana',      'dana@example.invalid',   'user', false, 'active', 'write', '{marketing}'),          -- Marketing
 (31, 'human', 'SMOKE Lee',       'lee@example.invalid',    'user', false, 'active', 'write', '{user}'),               -- a Sales Rep, Partnerships
 (40, 'agent', 'SMOKE Expert',    NULL,                     'user', false, 'active', 'write', '{user}'),               -- the expert, granted Sales Rep
 (41, 'agent', 'SMOKE Watcher',   NULL,                     'user', false, 'active', NULL,    '{}'),                   -- an agent not admitted
 (42, 'agent', 'SMOKE Assistant', NULL,                     'user', false, 'active', 'write', '{user}');               -- the Sales Assistant
INSERT INTO departments (id, name, is_system, system_key, manager_member_id) VALUES
 (3, 'Accounting', true, 'accounting', NULL), (5, 'IT', true, 'it', 1), (7, 'Sales', false, NULL, 27), (8, 'Partnerships', false, NULL, 31);
INSERT INTO department_members (member_id, department_id, is_admin, is_primary) VALUES
 (1, 5, true, true), (26, 7, false, true), (27, 7, true, true), (28, 7, false, true), (29, 3, false, true), (30, 7, false, false), (31, 8, false, true);
UPDATE crm_settings SET business_name = 'SMOKE Business', currency = 'USD', rotting_days = 14;

-- ---------------------------------------------------------------- the mirror, roles and rights
SELECT pg_temp.check((SELECT is_agent FROM members WHERE id = 40) AND NOT (SELECT is_agent FROM members WHERE id = 26), 'members.is_agent follows member_kind (generated)');
SELECT pg_temp.check((SELECT string_agg(role_key, ',' ORDER BY sort_order) FROM mcp_app_roles) = 'viewer,user,marketing,manager,admin', 'five roles, in order (os.app-roles/1)');
SELECT pg_temp.check((SELECT count(*) FROM crm_roles WHERE is_admin) = 1, 'exactly one admin role');
SELECT pg_temp.check((SELECT count(*) FROM crm_rights) = 41, 'forty-one rights');
SELECT pg_temp.check(crm_member_roles(28) = '{user}' AND crm_member_roles(29) = '{viewer}' AND crm_member_roles(1) @> '{admin}' AND crm_member_roles(41) = '{}', 'roles resolve: no roles yet → by capability (write = user, read = viewer); the super-admin admin; an unadmitted agent nothing');
SELECT pg_temp.as_member(26);
SELECT pg_temp.check(crm_has_right('deals.write') AND crm_has_right('email.send') AND NOT crm_has_right('deals.any') AND NOT crm_has_right('pipelines.manage') AND NOT crm_is_manager(), 'a Sales Rep writes deals and sends email; runs no pipeline, is no manager');
SELECT pg_temp.as_member(27);
SELECT pg_temp.check(crm_is_manager() AND crm_has_right('records.merge') AND crm_has_right('reports.team') AND crm_has_right('campaigns.manage') AND NOT crm_is_admin(), 'a Sales Manager is a manager: merges, team reports, campaigns; does not run the application');
SELECT pg_temp.as_member(29);
SELECT pg_temp.check(crm_is_viewer() AND crm_has_right('records.read') AND NOT crm_has_right('deals.write') AND NOT crm_has_right('activities.write'), 'a Viewer reads and changes nothing');
SELECT pg_temp.as_member(30);
SELECT pg_temp.check(crm_has_right('leads.assign') AND crm_has_right('campaigns.manage') AND crm_has_right('templates.manage') AND NOT crm_has_right('deals.write'), 'Marketing assigns leads, runs campaigns and templates; writes no deal');
SELECT pg_temp.as_member(1);
SELECT pg_temp.check(crm_is_admin() AND crm_is_manager() AND crm_has_right('settings.manage') AND crm_has_right('records.purge'), 'the super-admin runs Sales CRM: every right');
SELECT pg_temp.as_member(41);
SELECT pg_temp.check(NOT app_is_active_member() AND NOT crm_has_right('records.read'), 'an agent not yet admitted holds nothing');
SELECT pg_temp.as_member(NULL);
SELECT pg_temp.check(NOT crm_has_right('records.read') AND NOT crm_is_member_here(), 'nobody holds nothing');

-- ---------------------------------------------------------------- the seeded book
SELECT pg_temp.as_member(1);
SELECT pg_temp.check((SELECT count(*) FROM pipelines) = 2 AND (SELECT count(*) FROM stages) = 11 AND (SELECT is_default FROM pipelines WHERE key = 'new_business'), 'two pipelines seeded, eleven stages, New business the default');
SELECT pg_temp.check((SELECT default_pipeline_id FROM crm_settings) = (SELECT id FROM pipelines WHERE key = 'new_business'), 'the settings name the default pipeline');
SELECT pg_temp.check((SELECT count(*) FROM lead_sources) = 7 AND (SELECT count(*) FROM lost_reasons WHERE kind = 'deal') = 7 AND (SELECT count(*) FROM email_templates WHERE is_shared) = 3 AND (SELECT count(*) FROM saved_views WHERE is_system) = 6, 'sources, reasons, templates and system views seeded');
SELECT pg_temp.refused($$INSERT INTO stages (pipeline_id, name, position, probability, kind) VALUES ((SELECT id FROM pipelines WHERE key = 'renewals'), 'Won again', 95, 100, 'won')$$, 'stages_one_won', 'a second Won stage in a pipeline is refused');
SELECT pg_temp.refused($$INSERT INTO stages (pipeline_id, name, position, probability, kind) VALUES ((SELECT id FROM pipelines WHERE key = 'renewals'), 'Half lost', 96, 50, 'lost')$$, 'check', 'a Lost stage with a probability is refused');

-- ---------------------------------------------------------------- accounts and contacts
SELECT pg_temp.as_member(26);
INSERT INTO accounts (name, website, industry, city, country, owner_member_id, created_by) VALUES ('Acme Industries', 'https://www.acme.example', 'Manufacturing', 'Chicago', 'US', 26, 26);
SELECT pg_temp.keep('acme', (SELECT id FROM accounts WHERE name = 'Acme Industries'));
SELECT pg_temp.check((SELECT domain FROM accounts WHERE id = pg_temp.i('acme')) = 'acme.example', 'an account takes its domain from its website, www stripped');
INSERT INTO accounts (name, domain, owner_member_id, created_by) VALUES ('Northwind Traders', 'northwind.example', 31, 31);
SELECT pg_temp.keep('northwind', (SELECT id FROM accounts WHERE name = 'Northwind Traders'));
SELECT pg_temp.refused($$INSERT INTO accounts (name, domain) VALUES ('Acme copy', 'ACME.example')$$, 'accounts_domain_uq', 'a second live account on the same domain is refused (case-insensitive)');
INSERT INTO contacts (account_id, first_name, last_name, title, email, phone, role, owner_member_id, created_by) VALUES (pg_temp.i('acme'), 'Dana', 'Reyes', 'VP Operations', 'dana.reyes@acme.example', '+1 312 555 0101', 'decision_maker', 26, 26);
SELECT pg_temp.keep('dana', (SELECT id FROM contacts WHERE email = 'dana.reyes@acme.example'));
INSERT INTO contacts (account_id, first_name, last_name, email, owner_member_id, created_by) VALUES (pg_temp.i('acme'), 'Sam', 'Okafor', 'sam.okafor@acme.example', 26, 26);
SELECT pg_temp.keep('sam', (SELECT id FROM contacts WHERE email = 'sam.okafor@acme.example'));
SELECT pg_temp.check((SELECT name FROM contacts WHERE id = pg_temp.i('dana')) = 'Dana Reyes', 'a contact''s name is generated from first and last');
SELECT pg_temp.check((SELECT is_primary FROM contacts WHERE id = pg_temp.i('dana')) AND NOT (SELECT is_primary FROM contacts WHERE id = pg_temp.i('sam')), 'the first contact of an account becomes primary; the second does not');
SELECT pg_temp.check((SELECT count(*) FROM contact_emails WHERE contact_id = pg_temp.i('dana') AND is_primary) = 1, 'the primary address is mirrored into contact_emails');
SELECT pg_temp.check(crm_contact_by_address('Dana.Reyes@ACME.example') = pg_temp.i('dana'), 'an address resolves to its contact, case-insensitive');
INSERT INTO contacts (first_name, last_name, email, owner_member_id, created_by) VALUES ('Solo', 'Prospect', 'solo@gmail.example', 26, 26);
SELECT pg_temp.check((SELECT account_id IS NULL AND NOT is_primary FROM contacts WHERE email = 'solo@gmail.example'), 'a contact may stand alone, never primary of nothing');
SELECT pg_temp.refused($$INSERT INTO contacts (first_name, email) VALUES ('Bad', 'not-an-address')$$, 'Not an email', 'a malformed address is refused');
SELECT pg_temp.refused($$INSERT INTO contacts (custom_values) VALUES ('{"nope": 1}')$$, 'Unknown custom field', 'an unknown custom field is refused');
INSERT INTO custom_field_defs (key, label, field_type, applies_to, options) VALUES ('tier', 'Tier', 'select', 'account', '{Gold,Silver,Bronze}');
UPDATE accounts SET custom_values = '{"tier": "Gold"}' WHERE id = pg_temp.i('acme');
SELECT pg_temp.check((SELECT custom_values ->> 'tier' FROM accounts WHERE id = pg_temp.i('acme')) = 'Gold', 'a custom select value in its options is kept');
SELECT pg_temp.refused($$UPDATE accounts SET custom_values = '{"tier": "Platinum"}' WHERE id = pg_temp.i('acme')$$, 'one of its options', 'a value outside the options is refused');
-- duplicates (warn policy): a second contact with Dana's address is let through and found
INSERT INTO contacts (first_name, last_name, email, owner_member_id) VALUES ('D.', 'Reyes', 'dana.reyes@acme.example', 31);
SELECT pg_temp.keep('dana2', (SELECT id FROM contacts WHERE first_name = 'D.'));
SELECT pg_temp.check((SELECT count(*) FROM crm_duplicates('contact') WHERE matched_on = 'email' AND a_id = pg_temp.i('dana') AND b_id = pg_temp.i('dana2')) = 1, 'the duplicates read finds the pair by exact email (warn policy)');
UPDATE crm_settings SET duplicates_policy = 'block_exact_email';
SELECT pg_temp.refused($$INSERT INTO contacts (first_name, email) VALUES ('Third', 'dana.reyes@acme.example')$$, 'already has the address', 'block_exact_email refuses a third');
UPDATE crm_settings SET duplicates_policy = 'warn';
-- tags
INSERT INTO tags (slug, label) VALUES ('enterprise', 'Enterprise'), ('hot', 'Hot');
INSERT INTO record_tags (record_type, record_id, tag_id) VALUES ('account', pg_temp.i('acme'), (SELECT id FROM tags WHERE slug = 'enterprise'));
SELECT pg_temp.check((SELECT usage_count FROM tags WHERE slug = 'enterprise') = 1 AND crm_tags_of('account', pg_temp.i('acme')) = '{Enterprise}', 'a tag applied counts and reads back');

-- ---------------------------------------------------------------- leads and the conversion
SELECT pg_temp.as_member(30);
INSERT INTO assignment_rules (name, position, match, assign_kind, members) VALUES ('US web leads round-robin', 10, '{"source_id": [1], "country": ["US"]}', 'round_robin', '{26,31}');
INSERT INTO assignment_rules (name, position, match, assign_kind, department_id) VALUES ('Everything else to Sales', 20, '{}', 'department', 7);
INSERT INTO leads (first_name, last_name, company, email, phone, website, country, source_id, message, created_by) VALUES ('Jordan', 'Lee', 'Globex Corp', 'jordan.lee@globex.example', '+1 212 555 0199', 'https://globex.example', 'US', 1, 'Saw your booth; need a quote for 40 seats.', 30);
SELECT pg_temp.keep('lead1', (SELECT id FROM leads WHERE email = 'jordan.lee@globex.example'));
INSERT INTO leads (first_name, last_name, company, email, country, source_id, created_by) VALUES ('Kai', 'Berg', 'Initech', 'kai@initech.example', 'US', 1, 30);
SELECT pg_temp.keep('lead2', (SELECT id FROM leads WHERE email = 'kai@initech.example'));
INSERT INTO leads (first_name, last_name, company, email, country, source_id, created_by) VALUES ('Pat', 'Nguyen', 'Umbrella Ltd', 'pat@umbrella.example', 'GB', 2, 30);
SELECT pg_temp.keep('lead3', (SELECT id FROM leads WHERE email = 'pat@umbrella.example'));
SELECT pg_temp.check((SELECT owner_member_id FROM leads WHERE id = pg_temp.i('lead1')) = 26 AND (SELECT owner_member_id FROM leads WHERE id = pg_temp.i('lead2')) = 31, 'the round-robin rule assigns US web leads in turn (26, then 31)');
SELECT pg_temp.check((SELECT owner_member_id FROM leads WHERE id = pg_temp.i('lead3')) = 27, 'the catch-all rule gives a department''s lead to its manager');
SELECT pg_temp.check((SELECT crm_lead_label(l) FROM leads l WHERE l.id = pg_temp.i('lead1')) = 'Jordan Lee · Globex Corp', 'a lead''s label is person · company');
SELECT pg_temp.check((SELECT status FROM leads WHERE id = pg_temp.i('lead1')) = 'new', 'a new lead is new');
-- a note on a lead is a touch and makes it working
INSERT INTO notes (record_type, record_id, member_id, body) VALUES ('lead', pg_temp.i('lead1'), 26, 'Called back, interested.');
SELECT pg_temp.check((SELECT status = 'working' AND last_touched_at IS NOT NULL FROM leads WHERE id = pg_temp.i('lead1')), 'a note on a lead touches it and moves new → working');
-- the conversion, as the seller: a new account (by domain), a new contact, a deal in the default pipeline's first stage
SELECT pg_temp.as_member(26);
SELECT pg_temp.keep('conv_acc', (SELECT account_id FROM crm_lead_convert(pg_temp.i('lead1'), NULL, NULL, true, NULL, NULL, 40000, '2026-12-15')));
SELECT pg_temp.keep('conv_con', (SELECT converted_contact_id FROM leads WHERE id = pg_temp.i('lead1')));
SELECT pg_temp.keep('deal1', (SELECT converted_deal_id FROM leads WHERE id = pg_temp.i('lead1')));
SELECT pg_temp.check((SELECT status = 'qualified' AND converted_at IS NOT NULL AND converted_by = 26 FROM leads WHERE id = pg_temp.i('lead1')), 'the converted lead is qualified, stamped, pointing at what it made');
SELECT pg_temp.check((SELECT name = 'Globex Corp' AND domain = 'globex.example' AND owner_member_id = 26 FROM accounts WHERE id = pg_temp.i('conv_acc')), 'the conversion made the account from the company and the domain, owned by the lead''s owner');
SELECT pg_temp.check((SELECT account_id = pg_temp.i('conv_acc') AND email = 'jordan.lee@globex.example' AND is_primary FROM contacts WHERE id = pg_temp.i('conv_con')), 'the conversion made the contact at that account, primary');
SELECT pg_temp.check((SELECT d.number ~ '^D-[0-9]{6}$' AND d.value = 40000 AND d.currency = 'USD' AND d.stage_id = pg_temp.stage('new_business', 'Qualification') AND d.lead_id = pg_temp.i('lead1') AND d.probability = 10 AND d.weighted_value = 4000 FROM deals d WHERE d.id = pg_temp.i('deal1')), 'the deal: numbered, the base currency, the first open stage, pointing back at the lead, weighted by the stage');
SELECT pg_temp.check((SELECT count(*) FROM deal_stage_history WHERE deal_id = pg_temp.i('deal1')) = 1, 'the deal''s birth is its first history row');
SELECT pg_temp.check((SELECT count(*) FROM deal_contacts WHERE deal_id = pg_temp.i('deal1') AND contact_id = pg_temp.i('conv_con') AND is_primary) = 1, 'the primary contact is on the deal''s contacts');
SELECT pg_temp.check((SELECT lifecycle FROM contacts WHERE id = pg_temp.i('conv_con')) = 'prospect', 'the contact''s lifecycle is prospect while the deal is open');
SELECT pg_temp.refused($$SELECT * FROM crm_lead_convert(pg_temp.i('lead1'))$$, 'converted already', 'a lead is converted once');
SELECT pg_temp.refused($$UPDATE leads SET company = 'Renamed' WHERE id = pg_temp.i('lead1')$$, 'read-only', 'a converted lead is read-only');
-- the second conversion finds Acme by domain and Dana by email, makes no deal
INSERT INTO leads (first_name, last_name, company, email, source_id) VALUES ('Dana', 'Reyes', 'ACME', 'dana.reyes@acme.example', 3);
SELECT pg_temp.keep('lead4', (SELECT max(id) FROM leads));
SELECT pg_temp.check((SELECT account_id = pg_temp.i('acme') AND contact_id = pg_temp.i('dana') AND deal_id IS NULL AND NOT account_made AND NOT contact_made FROM crm_lead_convert(pg_temp.i('lead4'), NULL, NULL, false)), 'a conversion finds the account by the address''s domain and the contact by email, and makes no deal when not asked');
-- unqualify needs a reason
SELECT pg_temp.refused($$UPDATE leads SET status = 'unqualified' WHERE id = pg_temp.i('lead3')$$, 'check', 'an unqualified lead needs a reason');
UPDATE leads SET status = 'unqualified', unqualified_reason_id = (SELECT id FROM lost_reasons WHERE kind = 'lead' AND label = 'Not a fit') WHERE id = pg_temp.i('lead3');
SELECT pg_temp.refused($$SELECT * FROM crm_lead_convert(pg_temp.i('lead3'))$$, 'unqualified lead', 'an unqualified lead is not converted');

-- ---------------------------------------------------------------- the board: moves, required fields, won, lost, reopen, products
SELECT pg_temp.as_member(26);
INSERT INTO deals (title, account_id, pipeline_id, stage_id, owner_member_id) VALUES ('Acme — 20 seats', pg_temp.i('acme'), (SELECT id FROM pipelines WHERE key = 'new_business'), pg_temp.stage('new_business', 'Qualification'), 26);
SELECT pg_temp.keep('deal2', (SELECT id FROM deals WHERE title = 'Acme — 20 seats'));
SELECT pg_temp.refused($$SELECT crm_deal_move(pg_temp.i('deal2'), pg_temp.stage('new_business', 'Discovery'))$$, 'needs: primary_contact_id', 'a move into Discovery without a primary contact is refused, naming the field');
SELECT pg_temp.check(crm_stage_requirements_check(pg_temp.i('deal2'), pg_temp.stage('new_business', 'Proposal')) = '{primary_contact_id,value,expected_close_date}', 'the requirements check names every missing field');
UPDATE deals SET primary_contact_id = pg_temp.i('dana') WHERE id = pg_temp.i('deal2');
SELECT crm_deal_move(pg_temp.i('deal2'), pg_temp.stage('new_business', 'Discovery'));
SELECT pg_temp.check((SELECT stage_id = pg_temp.stage('new_business', 'Discovery') AND probability = 25 AND status = 'open' FROM deals WHERE id = pg_temp.i('deal2')), 'the move lands; probability follows the stage');
SELECT pg_temp.check((SELECT count(*) FROM deal_stage_history WHERE deal_id = pg_temp.i('deal2')) = 2 AND (SELECT seconds_in_from_stage IS NOT NULL FROM deal_stage_history WHERE deal_id = pg_temp.i('deal2') ORDER BY at DESC LIMIT 1), 'the move is a history row with the seconds in the stage left');
SELECT pg_temp.check((SELECT open_deals_count = 1 AND open_deals_value = 0 AND kind = 'prospect' FROM accounts WHERE id = pg_temp.i('acme')), 'the account counts its open deal and stays a prospect');
-- deal1 through the pipeline to Won
SELECT crm_deal_move(pg_temp.i('deal1'), pg_temp.stage('new_business', 'Discovery'));
SELECT crm_deal_move(pg_temp.i('deal1'), pg_temp.stage('new_business', 'Proposal'));
UPDATE deals SET probability_override = 60 WHERE id = pg_temp.i('deal1');
SELECT pg_temp.check((SELECT probability = 60 AND weighted_value = 24000 FROM deals WHERE id = pg_temp.i('deal1')), 'an override replaces the stage''s probability in the weighted value');
-- products
UPDATE deals SET value_from_products = true WHERE id = pg_temp.i('deal1');
INSERT INTO deal_products (deal_id, position, name, quantity, unit_price, discount_percent, provider_app, provider_id) VALUES (pg_temp.i('deal1'), 1, 'Seat licence', 40, 1200, 10, 'inventory', '77');
INSERT INTO deal_products (deal_id, position, name, quantity, unit_price) VALUES (pg_temp.i('deal1'), 2, 'Onboarding', 1, 2500);
SELECT pg_temp.check((SELECT value FROM deals WHERE id = pg_temp.i('deal1')) = 45700, 'the deal''s value follows its product lines (40 × 1200 − 10% + 2500)');
SELECT pg_temp.check((SELECT weighted_value FROM deals WHERE id = pg_temp.i('deal1')) = 27420, '…and the weighted value follows');
SELECT pg_temp.refused($$INSERT INTO deal_products (deal_id, name, provider_app) VALUES (pg_temp.i('deal1'), 'Bad', 'inventory')$$, 'check', 'a provider without its id is refused');
SELECT pg_temp.check((SELECT NOT crm_deal_is_rotting(status, stage_id, stage_entered_at) FROM deals WHERE id = pg_temp.i('deal1')), 'a deal just moved is not rotting');
UPDATE deals SET stage_entered_at = now() - interval '20 days' WHERE id = pg_temp.i('deal2');
SELECT pg_temp.check((SELECT crm_deal_is_rotting(status, stage_id, stage_entered_at) FROM deals WHERE id = pg_temp.i('deal2')), 'a deal in a stage for 20 days against 14 is rotting');
SELECT pg_temp.check((SELECT count(*) FROM crm_deals_at_risk(26) WHERE deal_id = pg_temp.i('deal2') AND reason IN ('rotting', 'no_next_step')) = 2, 'the at-risk read names it twice: rotting and no next step');
-- won
SELECT crm_deal_move(pg_temp.i('deal1'), pg_temp.stage('new_business', 'Won'));
SELECT pg_temp.check((SELECT status = 'won' AND won_at IS NOT NULL AND lost_at IS NULL AND probability = 100 AND weighted_value = value AND closed_by = 26 FROM deals WHERE id = pg_temp.i('deal1')), 'Won: status, stamp, probability 100, closed by the mover');
SELECT pg_temp.check((SELECT kind = 'customer' AND won_deals_value = 45700 AND open_deals_count = 0 FROM accounts WHERE id = pg_temp.i('conv_acc')), 'the account turns customer with the won value');
SELECT pg_temp.check((SELECT lifecycle FROM contacts WHERE id = pg_temp.i('conv_con')) = 'customer', 'the contact''s lifecycle turns customer');
-- lost needs a reason; a rep may not reopen (the application's rule — here the function allows, the right gates it in PHP), the trigger clears stamps on reopen
SELECT pg_temp.refused($$SELECT crm_deal_move(pg_temp.i('deal2'), pg_temp.stage('new_business', 'Lost'))$$, 'needs a reason', 'Lost without a reason is refused');
UPDATE deals SET lost_reason_id = (SELECT id FROM lost_reasons WHERE kind = 'deal' AND label = 'Price'), lost_note = 'Went with a cheaper tool' WHERE id = pg_temp.i('deal2');
SELECT crm_deal_move(pg_temp.i('deal2'), pg_temp.stage('new_business', 'Lost'));
SELECT pg_temp.check((SELECT status = 'lost' AND lost_at IS NOT NULL AND probability = 0 AND weighted_value = 0 FROM deals WHERE id = pg_temp.i('deal2')), 'Lost: status, stamp, probability 0');
SELECT pg_temp.check((SELECT open_deals_count FROM accounts WHERE id = pg_temp.i('acme')) = 0 AND (SELECT kind FROM accounts WHERE id = pg_temp.i('acme')) = 'prospect', 'the account''s open count drops; a lost account stays a prospect');
SELECT pg_temp.as_member(27);
SELECT crm_deal_move(pg_temp.i('deal2'), pg_temp.stage('new_business', 'Discovery'));
SELECT pg_temp.check((SELECT status = 'open' AND lost_at IS NULL AND lost_reason_id IS NULL AND closed_by IS NULL FROM deals WHERE id = pg_temp.i('deal2')), 'a reopen clears the lost stamps and the reason');
SELECT pg_temp.refused($$UPDATE deals SET stage_id = pg_temp.stage('renewals', 'Upcoming') WHERE id = pg_temp.i('deal2')$$, 'another pipeline', 'a stage of another pipeline is refused');
-- the board's order
SELECT pg_temp.as_member(26);
INSERT INTO deals (title, account_id, primary_contact_id, pipeline_id, stage_id, owner_member_id, value) VALUES ('Acme — training', pg_temp.i('acme'), pg_temp.i('sam'), (SELECT id FROM pipelines WHERE key = 'new_business'), pg_temp.stage('new_business', 'Discovery'), 26, 5000);
SELECT pg_temp.keep('deal3', (SELECT id FROM deals WHERE title = 'Acme — training'));
SELECT crm_deal_move(pg_temp.i('deal3'), pg_temp.stage('new_business', 'Discovery'), 0);
SELECT pg_temp.check((SELECT position FROM deals WHERE id = pg_temp.i('deal3')) = 0 AND (SELECT position FROM deals WHERE id = pg_temp.i('deal2')) >= 1, 'a drop at the top shifts the column');
SELECT pg_temp.check((SELECT deals = 2 AND value = 5000 AND rotting = 0 AND no_next_step = 2 FROM crm_pipeline_summary((SELECT id FROM pipelines WHERE key = 'new_business')) WHERE stage_name = 'Discovery'), 'the pipeline summary counts Discovery: two deals, their value, two with no next step');
SELECT pg_temp.check((SELECT won = 1 AND lost = 0 AND won_value = 45700 FROM crm_win_rate(current_date - 1, current_date + 1, 'seller') WHERE row_id = 26), 'the win rate read: Priya one won, none lost');
SELECT pg_temp.check((SELECT count(*) FROM crm_funnel((SELECT id FROM pipelines WHERE key = 'new_business'), current_date - 30, current_date + 1)) = 4, 'the funnel has the four open stages');
SELECT pg_temp.check((SELECT sum(won_value) FROM crm_forecast(NULL, 3, 'seller')) = 45700 OR (SELECT count(*) FROM crm_forecast(NULL, 3, 'seller')) > 0, 'the forecast answers rows per seller per month');

-- ---------------------------------------------------------------- activities, reminders, the next step and the colours
SELECT pg_temp.as_member(26);
INSERT INTO activities (kind, subject, deal_id, starts_at, remind_minutes_before) VALUES ('call', 'Discovery call with Sam', pg_temp.i('deal3'), now() + interval '2 days', 30);
SELECT pg_temp.keep('act1', (SELECT max(id) FROM activities));
SELECT pg_temp.check((SELECT account_id = pg_temp.i('acme') AND contact_id = pg_temp.i('sam') AND owner_member_id = 26 AND created_by = 26 FROM activities WHERE id = pg_temp.i('act1')), 'an activity on a deal takes the deal''s account and primary contact, the caller as owner');
SELECT pg_temp.check((SELECT remind_at = starts_at - interval '30 minutes' FROM activities WHERE id = pg_temp.i('act1')), 'the reminder''s moment is kept');
SELECT pg_temp.check((SELECT next_activity_id = pg_temp.i('act1') AND next_activity_at IS NOT NULL FROM deals WHERE id = pg_temp.i('deal3')), 'the deal''s next step points at the activity');
SELECT pg_temp.check((SELECT next_activity_at IS NOT NULL FROM contacts WHERE id = pg_temp.i('sam')) AND (SELECT next_activity_at IS NOT NULL FROM accounts WHERE id = pg_temp.i('acme')), 'the contact and the account carry it too');
SELECT pg_temp.check((SELECT crm_next_colour(next_activity_at) FROM deals WHERE id = pg_temp.i('deal3')) = 'scheduled' AND (SELECT crm_next_colour(next_activity_at) FROM deals WHERE id = pg_temp.i('deal2')) = 'none', 'the colours: scheduled for a deal with a step, none for a deal without');
SELECT pg_temp.check(crm_next_colour(now() - interval '1 day') = 'overdue' AND crm_next_colour(now() + interval '1 minute') = 'today', 'overdue and today');
INSERT INTO activities (kind, subject, deal_id, due_at) VALUES ('task', 'Send pricing', pg_temp.i('deal3'), now() + interval '1 day');
SELECT pg_temp.keep('act2', (SELECT max(id) FROM activities));
SELECT pg_temp.check((SELECT next_activity_id = pg_temp.i('act2') FROM deals WHERE id = pg_temp.i('deal3')), 'the earlier activity becomes the next step');
SELECT pg_temp.check((SELECT remind_minutes_before FROM activities WHERE id = pg_temp.i('act2')) = 30, 'a task takes the default reminder (30 min)');
-- the reminder due, delivered once, snoozed, delivered again
UPDATE activities SET due_at = now() + interval '10 minutes' WHERE id = pg_temp.i('act2');
SELECT pg_temp.check((SELECT count(*) FROM crm_reminders_due()) = 1 AND (SELECT activity_id FROM crm_reminders_due()) = pg_temp.i('act2'), 'a task due in 10 minutes with a 30-minute reminder is due now');
SELECT pg_temp.keep('n1', crm_deliver_reminder(pg_temp.i('act2')));
SELECT pg_temp.check(pg_temp.i('n1') IS NOT NULL AND (SELECT reminded_at IS NOT NULL FROM activities WHERE id = pg_temp.i('act2')) AND (SELECT count(*) FROM crm_reminders_due()) = 0, 'delivered: a notification, reminded_at set, nothing due');
SELECT pg_temp.check((SELECT kind = 'reminder' AND activity_id = pg_temp.i('act2') AND deal_id = pg_temp.i('deal3') FROM notifications WHERE id = pg_temp.i('n1')), 'the notification names the activity and the deal');
SELECT pg_temp.check((SELECT count(*) FROM notification_outbox WHERE member_id = 26 AND channel = 'email' AND kind = 'reminder') = 1 AND (SELECT count(*) FROM notification_outbox WHERE member_id = 26 AND channel = 'text') = 0, 'the outbox has the email (prefs default on) and no text (off by default)');
SELECT crm_activity_snooze(pg_temp.i('act2'), now() - interval '1 second');
SELECT pg_temp.check((SELECT count(*) FROM crm_reminders_due()) = 1, 'a snooze that has elapsed makes the reminder due again');
SELECT pg_temp.keep('n2', crm_deliver_reminder(pg_temp.i('act2')));
SELECT pg_temp.check(pg_temp.i('n2') IS NOT NULL AND (SELECT count(*) FROM notifications WHERE activity_id = pg_temp.i('act2')) = 2, '…and it is delivered a second time');
-- done: the touch and the next step moving on
UPDATE activities SET done_at = now(), outcome = 'completed' WHERE id = pg_temp.i('act2');
SELECT pg_temp.check((SELECT next_activity_id = pg_temp.i('act1') FROM deals WHERE id = pg_temp.i('deal3')), 'done: the next step moves to the following activity');
SELECT pg_temp.check((SELECT last_touched_at IS NOT NULL FROM deals WHERE id = pg_temp.i('deal3')) AND (SELECT last_touched_at IS NOT NULL FROM accounts WHERE id = pg_temp.i('acme')), 'done is a touch on the deal and the account');
SELECT pg_temp.check((SELECT done_by = 26 AND remind_at IS NULL FROM activities WHERE id = pg_temp.i('act2')), 'done by the caller; no reminder left');
UPDATE activities SET done_at = now() WHERE id = pg_temp.i('act1');
SELECT pg_temp.check((SELECT outcome FROM activities WHERE id = pg_temp.i('act1')) = 'connected' AND (SELECT next_activity_id IS NULL AND next_activity_at IS NULL FROM deals WHERE id = pg_temp.i('deal3')), 'a call done defaults to connected; the deal has no next step again');
SELECT pg_temp.refused($$INSERT INTO activities (kind, subject) VALUES ('call', 'Nowhere')$$, 'check', 'an activity on nothing is refused (a bare task is allowed)');
INSERT INTO activities (kind, subject, due_at) VALUES ('task', 'Clean the pipeline', now() + interval '3 days');
SELECT pg_temp.check((SELECT count(*) FROM activities WHERE subject = 'Clean the pipeline') = 1, 'a bare task is allowed');
INSERT INTO activities (kind, subject, contact_id, starts_at) VALUES ('meeting', 'Lunch with Dana', pg_temp.i('dana'), now() + interval '5 days');
SELECT pg_temp.check((SELECT ends_at = starts_at + interval '1 hour' FROM activities WHERE subject = 'Lunch with Dana'), 'a meeting without an end gets an hour');
SELECT pg_temp.check((SELECT count(*) FROM crm_feed_rows(26)) >= 3, 'the ICS feed lists the seller''s activities');
SELECT pg_temp.check((SELECT count(*) FROM crm_timeline('deal', pg_temp.i('deal3')) WHERE item_kind LIKE 'activity.%') = 2 AND (SELECT count(*) FROM crm_timeline('deal', pg_temp.i('deal3')) WHERE item_kind = 'stage') = 1, 'the deal''s timeline has its two activities and its birth stage');
SELECT pg_temp.check((SELECT count(*) FROM crm_activity_summary(current_date - 1, current_date + 1) WHERE member_id = 26 AND kind = 'call' AND done = 1) = 1, 'the activity summary counts the call done');

-- ---------------------------------------------------------------- the Assistant's proposal accepted in one click
SELECT pg_temp.as_member(42);
INSERT INTO assistant_proposals (kind, deal_id, for_member_id, title, payload, proposed_by) VALUES ('next_step', pg_temp.i('deal3'), 26, 'Schedule a follow-up call', '{"kind": "call", "subject": "Follow up on training", "at": "2026-10-20T15:00:00Z"}', 42);
SELECT pg_temp.keep('prop', (SELECT max(id) FROM assistant_proposals));
SELECT pg_temp.refused($$INSERT INTO assistant_proposals (kind, deal_id, for_member_id, title, proposed_by) VALUES ('next_step', pg_temp.i('deal3'), 26, 'Another', 42)$$, 'one_open_next_step', 'one open next-step proposal per deal');
SELECT pg_temp.as_member(26);
SELECT pg_temp.keep('act3', crm_proposal_accept(pg_temp.i('prop')));
SELECT pg_temp.check((SELECT kind = 'call' AND owner_member_id = 26 AND source = 'proposal' AND proposal_id = pg_temp.i('prop') AND deal_id = pg_temp.i('deal3') FROM activities WHERE id = pg_temp.i('act3')), 'accepting makes the activity, owned by the decider, pointing at the proposal');
SELECT pg_temp.check((SELECT status = 'accepted' AND decided_by = 26 AND result_activity_id = pg_temp.i('act3') FROM assistant_proposals WHERE id = pg_temp.i('prop')), 'the proposal is accepted and names its result');
SELECT pg_temp.check((SELECT next_activity_id = pg_temp.i('act3') FROM deals WHERE id = pg_temp.i('deal3')), 'the deal has a next step again');
-- a stage with ask_assistant dispatches
UPDATE stages SET ask_assistant = true WHERE id = pg_temp.stage('new_business', 'Proposal');
UPDATE deals SET value = 12000, expected_close_date = current_date + 30 WHERE id = pg_temp.i('deal3');
SELECT crm_deal_move(pg_temp.i('deal3'), pg_temp.stage('new_business', 'Proposal'));
SELECT pg_temp.check((SELECT count(*) FROM agent_dispatches WHERE deal_id = pg_temp.i('deal3') AND kind = 'stage_entered' AND agent_member_id = 42) = 1, 'entering an ask-the-Assistant stage dispatches to the Assistant');

-- ---------------------------------------------------------------- email: the mailbox, the matcher, a stranger, a bounce, the opt-out
SELECT pg_temp.as_member(1);
INSERT INTO mailboxes (address, display_name, provider, webhook_secret_ref) VALUES ('crm-a1b2c3d4@example.invalid', 'SMOKE CRM', 'malumail', 'MAILBOX_CRM_WEBHOOK_SECRET');
UPDATE crm_settings SET mailbox_id = (SELECT id FROM mailboxes LIMIT 1);
SELECT pg_temp.keep('mb', (SELECT id FROM mailboxes LIMIT 1));
-- a seller BCCs an email to Dana and Sam about deal3 (subject tag)
INSERT INTO inbound_email (mailbox_id, message_id, from_address, from_name, to_addresses, cc_addresses, subject, body_text) VALUES
 (pg_temp.i('mb'), '<m1@example.invalid>', 'priya@example.invalid', 'Priya', '{dana.reyes@acme.example}', '{sam.okafor@acme.example,crm-a1b2c3d4@example.invalid}', 'Training proposal [' || (SELECT number FROM deals WHERE id = pg_temp.i('deal3')) || ']', 'Attached is the proposal.');
SELECT pg_temp.keep('em1', (SELECT max(id) FROM inbound_email));
SELECT pg_temp.check(crm_match_inbound_email(pg_temp.i('em1')) = 'matched', 'a BCC''d email matches');
SELECT pg_temp.check((SELECT sender_member_id = 26 AND deal_id = pg_temp.i('deal3') AND cardinality(matched_contact_ids) = 2 AND status = 'processed' FROM inbound_email WHERE id = pg_temp.i('em1')), '…to the seller, the deal by the subject tag, and two contacts');
SELECT pg_temp.check((SELECT count(*) FROM activities WHERE inbound_email_id = pg_temp.i('em1') AND kind = 'email' AND email_direction = 'out' AND deal_id = pg_temp.i('deal3') AND done_at IS NOT NULL) = 2, 'two email activities, direction out, done, on the deal');
SELECT pg_temp.check((SELECT last_touched_at IS NOT NULL FROM contacts WHERE id = pg_temp.i('sam')), 'the email is a touch on the contact');
-- Dana replies in the thread
INSERT INTO inbound_email (mailbox_id, message_id, in_reply_to, references_ids, from_address, from_name, to_addresses, subject, body_text) VALUES
 (pg_temp.i('mb'), '<m2@acme.example>', '<m1@example.invalid>', '{<m1@example.invalid>}', 'dana.reyes@acme.example', 'Dana Reyes', '{priya@example.invalid,crm-a1b2c3d4@example.invalid}', 'Re: Training proposal', 'Looks good, let us talk Tuesday.');
SELECT pg_temp.keep('em2', (SELECT max(id) FROM inbound_email));
SELECT pg_temp.check(crm_match_inbound_email(pg_temp.i('em2')) = 'matched', 'a reply matches');
SELECT pg_temp.check((SELECT deal_id = pg_temp.i('deal3') AND sender_member_id IS NULL FROM inbound_email WHERE id = pg_temp.i('em2')), 'a reply lands on the deal by thread; the sender is no member');
SELECT pg_temp.check((SELECT count(*) FROM activities WHERE inbound_email_id = pg_temp.i('em2') AND email_direction = 'in' AND contact_id = pg_temp.i('dana') AND owner_member_id = 26) = 1, 'one email activity, direction in, owned by the contact''s owner');
-- a stranger
INSERT INTO inbound_email (mailbox_id, message_id, from_address, from_name, to_addresses, subject, body_text) VALUES (pg_temp.i('mb'), '<m3@stranger.example>', 'maria.k@stranger.example', 'Maria K', '{crm-a1b2c3d4@example.invalid}', 'Pricing?', 'Hello, how much for 10 seats?');
SELECT pg_temp.keep('em3', (SELECT max(id) FROM inbound_email));
SELECT pg_temp.check(crm_match_inbound_email(pg_temp.i('em3')) = 'proposed_lead', 'a stranger''s email proposes a lead');
SELECT pg_temp.check((SELECT count(*) FROM leads WHERE proposed_from_email_id = pg_temp.i('em3') AND is_proposed AND first_name = 'Maria' AND last_name = 'K' AND owner_member_id IS NULL AND source_id = (SELECT id FROM lead_sources WHERE name = 'Mailbox')) = 1, '…unowned, in the inbox, from the Mailbox source, the name split');
-- a bounce makes nothing
INSERT INTO inbound_email (mailbox_id, message_id, from_address, to_addresses, subject, status) VALUES (pg_temp.i('mb'), '<m4@mailer>', 'mailer-daemon@example.invalid', '{crm-a1b2c3d4@example.invalid}', 'Undeliverable', 'bounce');
SELECT pg_temp.keep('em4', (SELECT max(id) FROM inbound_email));
SELECT pg_temp.check(crm_match_inbound_email(pg_temp.i('em4')) = 'ignored' AND (SELECT count(*) FROM activities WHERE inbound_email_id = pg_temp.i('em4')) = 0 AND (SELECT count(*) FROM leads WHERE proposed_from_email_id = pg_temp.i('em4')) = 0, 'a bounce makes no activity and no lead');
SELECT pg_temp.refused($$INSERT INTO inbound_email (mailbox_id, message_id, from_address) VALUES (pg_temp.i('mb'), '<m1@example.invalid>', 'x@example.invalid')$$, 'inbound_email_message_id_uq', 'the same message twice is refused');
-- the opt-out
SELECT pg_temp.check(crm_email_allowed(pg_temp.i('sam')), 'Sam may be emailed');
SELECT crm_contact_opt_out(pg_temp.i('sam'), 'email', 'unsubscribe_link');
SELECT pg_temp.check(NOT crm_email_allowed(pg_temp.i('sam')) AND (SELECT email_opt_out_at IS NOT NULL AND opt_out_reason = 'unsubscribe_link' FROM contacts WHERE id = pg_temp.i('sam')), 'opted out: refused by name, with the moment and the reason');

-- ---------------------------------------------------------------- campaigns, lists
SELECT pg_temp.as_member(30);
INSERT INTO campaigns (name, kind, status, starts_on, budget, currency, owner_member_id) VALUES ('Autumn webinar', 'webinar', 'active', current_date - 10, 2000, 'USD', 30);
SELECT pg_temp.keep('camp', (SELECT id FROM campaigns WHERE name = 'Autumn webinar'));
UPDATE leads SET campaign_id = pg_temp.i('camp') WHERE id IN (pg_temp.i('lead2'), pg_temp.i('lead3'));
INSERT INTO campaign_members (campaign_id, lead_id, status) VALUES (pg_temp.i('camp'), pg_temp.i('lead2'), 'attended');
INSERT INTO campaign_members (campaign_id, contact_id, status) VALUES (pg_temp.i('camp'), pg_temp.i('dana'), 'responded');
SELECT pg_temp.refused($$INSERT INTO campaign_members (campaign_id, lead_id, contact_id) VALUES (pg_temp.i('camp'), pg_temp.i('lead2'), pg_temp.i('dana'))$$, 'check', 'a member is a lead or a contact, not both');
SELECT pg_temp.check((SELECT members = 2 AND responded = 2 AND leads = 2 AND cost_per_lead = 1000 FROM crm_campaign_results(pg_temp.i('camp'))), 'the campaign''s results: members, responses, leads, cost per lead');
INSERT INTO lists (name, kind, record_kind, owner_member_id, is_shared) VALUES ('Webinar follow-ups', 'static', 'contact', 30, true);
SELECT pg_temp.keep('list', (SELECT id FROM lists WHERE name = 'Webinar follow-ups'));
INSERT INTO list_members (list_id, record_id, added_by) VALUES (pg_temp.i('list'), pg_temp.i('dana'), 30), (pg_temp.i('list'), pg_temp.i('sam'), 30);
SELECT pg_temp.check((SELECT member_count FROM lists WHERE id = pg_temp.i('list')) = 2, 'a static list counts its members by trigger');
INSERT INTO lists (name, kind, record_kind, owner_member_id, filters) VALUES ('Untouched customers', 'smart', 'account', 30, '{"kind": ["customer"], "untouched_days": 30}');
SELECT pg_temp.refused($$INSERT INTO list_members (list_id, record_id) VALUES ((SELECT id FROM lists WHERE kind = 'smart'), 1)$$, 'smart list', 'a smart list takes no members of its own');
SELECT pg_temp.check((SELECT count(*) FROM crm_source_performance(current_date - 1, current_date + 1) WHERE source_name = 'Website' AND leads = 2 AND converted = 1) = 1, 'the source read: Website two leads, one converted');

-- ---------------------------------------------------------------- the merge
SELECT pg_temp.as_member(27);
INSERT INTO notes (record_type, record_id, member_id, body) VALUES ('contact', pg_temp.i('dana2'), 31, 'Met at the conference.');
INSERT INTO record_tags (record_type, record_id, tag_id) VALUES ('contact', pg_temp.i('dana2'), (SELECT id FROM tags WHERE slug = 'hot'));
UPDATE contacts SET mobile = '+1 312 555 0199', linkedin_url = 'https://linkedin.example/in/dreyes' WHERE id = pg_temp.i('dana2');
SELECT crm_records_merge('contact', pg_temp.i('dana'), pg_temp.i('dana2'));
SELECT pg_temp.check((SELECT merged_into_id = pg_temp.i('dana') AND deleted_at IS NOT NULL FROM contacts WHERE id = pg_temp.i('dana2')), 'the loser is a tombstone pointing at the survivor');
SELECT pg_temp.check((SELECT mobile = '+1 312 555 0199' AND linkedin_url IS NOT NULL AND title = 'VP Operations' FROM contacts WHERE id = pg_temp.i('dana')), 'the survivor keeps its values and takes the loser''s where empty');
SELECT pg_temp.check((SELECT count(*) FROM notes WHERE record_type = 'contact' AND record_id = pg_temp.i('dana')) = 1 AND crm_tags_of('contact', pg_temp.i('dana')) = '{Hot}', 'the loser''s note and tag moved to the survivor');
SELECT pg_temp.check((SELECT count(*) FROM crm_duplicates('contact') WHERE matched_on = 'email') = 0, 'the duplicate pair is gone');
SELECT pg_temp.refused($$SELECT crm_records_merge('contact', pg_temp.i('dana'), pg_temp.i('dana'))$$, 'not merged into itself', 'a record is not merged into itself');
-- accounts: the loser's contacts and deals move
INSERT INTO accounts (name, domain, owner_member_id) VALUES ('Acme Inc', 'acme-inc.example', 31);
SELECT pg_temp.keep('acme2', (SELECT id FROM accounts WHERE name = 'Acme Inc'));
INSERT INTO contacts (account_id, first_name, last_name, email) VALUES (pg_temp.i('acme2'), 'Tess', 'Lin', 'tess@acme-inc.example');
SELECT crm_records_merge('account', pg_temp.i('acme'), pg_temp.i('acme2'));
SELECT pg_temp.check((SELECT account_id FROM contacts WHERE email = 'tess@acme-inc.example') = pg_temp.i('acme') AND (SELECT merged_into_id FROM accounts WHERE id = pg_temp.i('acme2')) = pg_temp.i('acme'), 'an account merge moves the contacts and tombstones the loser');
SELECT pg_temp.check((SELECT count(*) FROM contacts WHERE account_id = pg_temp.i('acme') AND is_primary AND deleted_at IS NULL) = 1, 'one primary contact survives the merge');

-- ---------------------------------------------------------------- the tombstone and the purge
SELECT pg_temp.as_member(26);
SELECT crm_record_delete('deal', pg_temp.i('deal2'));
SELECT pg_temp.check((SELECT deleted_at IS NOT NULL AND deleted_by = 26 FROM deals WHERE id = pg_temp.i('deal2')) AND (SELECT count(*) FROM mcp_deals WHERE deal_id = pg_temp.i('deal2')) = 0, 'a deleted deal is a tombstone, out of the views');
SELECT pg_temp.check((SELECT open_deals_count FROM accounts WHERE id = pg_temp.i('acme')) = 1, 'the account''s open count no longer counts it');
SELECT crm_record_delete('deal', pg_temp.i('deal2'), true);
SELECT pg_temp.check((SELECT deleted_at IS NULL FROM deals WHERE id = pg_temp.i('deal2')) AND (SELECT open_deals_count FROM accounts WHERE id = pg_temp.i('acme')) = 2, 'restored: back in the count');
SELECT crm_record_delete('deal', pg_temp.i('deal2'));
UPDATE deals SET deleted_at = now() - interval '40 days' WHERE id = pg_temp.i('deal2');
SELECT pg_temp.keep('purged', crm_tombstones_purge());
SELECT pg_temp.check(pg_temp.i('purged') >= 1 AND (SELECT count(*) FROM deals WHERE id = pg_temp.i('deal2')) = 0, 'the purge removes a tombstone older than the window');
SELECT pg_temp.check((SELECT count(*) FROM deal_stage_history WHERE deal_id = pg_temp.i('deal2')) = 0, '…with its history');

-- ---------------------------------------------------------------- THE WALL (D5): open by default, a restriction, the views
SELECT pg_temp.as_member(27);
INSERT INTO accounts (name, domain, owner_member_id, restricted_to_member_id, restricted_to_department_id) VALUES ('Secret Target', 'secret.example', 26, 26, 7);
SELECT pg_temp.keep('secret', (SELECT id FROM accounts WHERE name = 'Secret Target'));
INSERT INTO deals (title, account_id, pipeline_id, stage_id, owner_member_id, value, restricted_to_member_id) VALUES ('Secret acquisition', pg_temp.i('secret'), (SELECT id FROM pipelines WHERE key = 'new_business'), pg_temp.stage('new_business', 'Qualification'), 26, 900000, 26);
SELECT pg_temp.keep('sdeal', (SELECT id FROM deals WHERE title = 'Secret acquisition'));
SELECT pg_temp.as_member(31);   -- Lee, Partnerships, a Rep: sees everything open, not the restricted
SELECT pg_temp.check((SELECT count(*) FROM mcp_accounts) = (SELECT count(*) FROM accounts WHERE deleted_at IS NULL AND merged_into_id IS NULL) - 1, 'a Rep in another department sees every account but the restricted one');
SELECT pg_temp.check((SELECT count(*) FROM mcp_deals WHERE deal_id = pg_temp.i('sdeal')) = 0 AND (SELECT count(*) FROM mcp_deals WHERE deal_id = pg_temp.i('deal1')) = 1, 'the restricted deal is invisible to them; Priya''s won deal is open with its amount');
SELECT pg_temp.check((SELECT value FROM mcp_deals WHERE deal_id = pg_temp.i('deal1')) = 45700, 'amounts are open (D5)');
SELECT pg_temp.check((SELECT count(*) FROM crm_search('Secret')) = 0 AND (SELECT count(*) FROM crm_search('Acme')) >= 2, 'the search honours the wall: nothing of Secret, Acme''s records found');
SELECT pg_temp.check((SELECT count(*) FROM crm_timeline('deal', pg_temp.i('sdeal'))) = 0, 'the timeline of a restricted deal is empty for them');
SELECT pg_temp.as_member(28);   -- Bea, Sales: in the restricted department
SELECT pg_temp.check((SELECT count(*) FROM mcp_accounts WHERE account_id = pg_temp.i('secret')) = 1 AND (SELECT count(*) FROM mcp_deals WHERE deal_id = pg_temp.i('sdeal')) = 0, 'a member of the department sees the account restricted to it; the deal restricted to a person alone, not');
SELECT pg_temp.as_member(26);   -- the owner
SELECT pg_temp.check((SELECT count(*) FROM mcp_deals WHERE deal_id = pg_temp.i('sdeal')) = 1, 'the owner sees their restricted deal');
SELECT pg_temp.as_member(27);   -- the manager
SELECT pg_temp.check((SELECT count(*) FROM mcp_deals WHERE deal_id = pg_temp.i('sdeal')) = 1 AND (SELECT count(*) FROM mcp_tombstones) >= 2, 'a manager sees every restricted record and the tombstones');
SELECT pg_temp.as_member(29);   -- the Viewer
SELECT pg_temp.check((SELECT count(*) FROM mcp_deals WHERE deal_id = pg_temp.i('deal1')) = 1 AND (SELECT count(*) FROM mcp_deals WHERE deal_id = pg_temp.i('sdeal')) = 0 AND (SELECT count(*) FROM mcp_tombstones) = 0, 'a Viewer sees the open book with amounts, not the restricted, not the tombstones');
SELECT pg_temp.as_member(40);   -- the expert agent, Sales Rep
SELECT pg_temp.check((SELECT count(*) FROM mcp_accounts) > 0 AND (SELECT count(*) FROM mcp_deals WHERE deal_id = pg_temp.i('sdeal')) = 0, 'an agent sees what its member id may: the open book, not the restricted');
SELECT pg_temp.as_member(41);   -- not admitted
SELECT pg_temp.check((SELECT count(*) FROM mcp_accounts) = 0 AND (SELECT count(*) FROM mcp_deals) = 0 AND (SELECT count(*) FROM crm_search('Acme')) = 0, 'an unadmitted agent sees nothing');
UPDATE crm_settings SET allow_restricted_records = false;
SELECT pg_temp.as_member(27);
SELECT pg_temp.refused($$UPDATE accounts SET restricted_to_member_id = 27 WHERE id = pg_temp.i('acme')$$, 'Restricted records are off', 'with the setting off, no new restriction');
UPDATE crm_settings SET allow_restricted_records = true;
-- private email
SELECT pg_temp.as_member(26);
UPDATE inbound_email SET is_private = true WHERE id = pg_temp.i('em1');
UPDATE activities SET is_private = true WHERE inbound_email_id = pg_temp.i('em1');
SELECT pg_temp.as_member(31);
SELECT pg_temp.check((SELECT count(*) FROM mcp_emails WHERE email_id = pg_temp.i('em1') AND direction = 'in') = 0 AND (SELECT count(*) FROM mcp_activities WHERE inbound_email_id = pg_temp.i('em1')) = 0, 'a private email and its activities are hidden from another seller');
SELECT pg_temp.as_member(27);
SELECT pg_temp.check((SELECT count(*) FROM mcp_emails WHERE email_id = pg_temp.i('em1') AND direction = 'in' AND body_text IS NOT NULL) = 1, 'a manager reads it');
SELECT pg_temp.as_member(31);
SELECT pg_temp.check((SELECT count(*) FROM mcp_emails WHERE email_id = pg_temp.i('em2') AND body_text IS NOT NULL) = 1, 'an ordinary email''s body is the business''s (open)');

-- ---------------------------------------------------------------- keys
SELECT pg_temp.as_member(1);
INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope) VALUES (26, 'SMOKE key', repeat('a', 64), 'api');
SELECT pg_temp.check((SELECT rate_per_minute = 60 AND rate_per_day = 5000 FROM mcp_access_tokens WHERE label = 'SMOKE key'), 'a key''s limits default from the settings');
INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, lead_source_id) VALUES (1, 'SMOKE website form', repeat('b', 64), 'api', 1);
SELECT pg_temp.check((SELECT rate_per_day = 600 FROM mcp_access_tokens WHERE label = 'SMOKE website form'), 'a lead-capture key takes the capture limit (600/day)');
SELECT pg_temp.check((SELECT (crm_rate_ok(id)).ok FROM mcp_access_tokens WHERE label = 'SMOKE key'), 'the rate rule counts a call');
SELECT pg_temp.refused($$INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, lead_source_id) VALUES (1, 'Bad', repeat('c', 64), 'mcp', 1)$$, 'check', 'an mcp token is never a capture key');

-- ---------------------------------------------------------------- the search index
SELECT pg_temp.as_member(26);
SELECT pg_temp.check((SELECT count(*) FROM search_index WHERE entity_kind = 'account' AND entity_id = pg_temp.i('acme')) = 1, 'every account has an index row');
SELECT pg_temp.check((SELECT entity_kind FROM crm_search('Globex') LIMIT 1) IN ('account', 'contact', 'deal', 'lead'), 'the search finds Globex');
SELECT pg_temp.check((SELECT count(*) FROM crm_search('Jordan Lee')) >= 2, 'the search finds the lead and the contact by name');
SELECT pg_temp.check((SELECT count(*) FROM crm_search('Reyes', '{contact}')) = 1, 'the search filtered to contacts finds Dana once (the merged loser is out)');
SELECT pg_temp.check((SELECT count(*) FROM crm_search('Acmee')) >= 1, 'a misspelling finds Acme by trigram');
SELECT pg_temp.check(crm_search_catch_up() = 0, 'the catch-up finds nothing to do when the triggers kept up');

-- ---------------------------------------------------------------- the reused tables are verbatim against their siblings (column names and order)
SELECT pg_temp.check((SELECT string_agg(column_name, ',' ORDER BY ordinal_position) FROM information_schema.columns WHERE table_name = 'tags') = 'id,slug,label,usage_count,created_at', 'tags = Help Desk db/009');
SELECT pg_temp.check((SELECT string_agg(column_name, ',' ORDER BY ordinal_position) FROM information_schema.columns WHERE table_name = 'notes') = 'id,record_type,record_id,member_id,body,created_at', 'notes = GL db/009');
SELECT pg_temp.check((SELECT string_agg(column_name, ',' ORDER BY ordinal_position) FROM information_schema.columns WHERE table_name = 'attachments') = 'id,record_type,record_id,filename,mime_type,byte_size,sha256,storage_path,uploaded_by,created_at', 'attachments = GL db/009');
SELECT pg_temp.check((SELECT string_agg(column_name, ',' ORDER BY ordinal_position) FROM information_schema.columns WHERE table_name = 'document_sequences') = 'kind,prefix,next_value,padding,updated_at', 'document_sequences = GL db/006');
SELECT pg_temp.check((SELECT string_agg(column_name, ',' ORDER BY ordinal_position) FROM information_schema.columns WHERE table_name = 'calendar_feeds') = 'member_id,token_hash,created_at,last_used_at,revoked_at', 'calendar_feeds = txtSchedules db/012');
SELECT pg_temp.check((SELECT string_agg(column_name, ',' ORDER BY ordinal_position) FROM information_schema.columns WHERE table_name = 'worker_passes') = 'id,started_at,finished_at,steps,error', 'worker_passes = Spaces db/023');
SELECT pg_temp.check((SELECT string_agg(column_name, ',' ORDER BY ordinal_position) FROM information_schema.columns WHERE table_name = 'notification_outbox') = 'id,channel,member_id,to_email,kind,record_type,record_id,dedupe_key,subject,body,body_html,status,attempts,detail,provider_ref,send_after,sent_at,created_at', 'notification_outbox = Spaces db/012');
SELECT pg_temp.check((SELECT string_agg(column_name, ',' ORDER BY ordinal_position) FROM information_schema.columns WHERE table_name = 'search_index' AND ordinal_position <= 14) = 'entity_kind,entity_uuid,entity_id,page_id,space_id,channel_id,author_member_id,title,body,tsv,has_link,has_file,occurred_at,updated_at', 'search_index = Spaces db/013 (the canonical columns first; ours appended)');
SELECT pg_temp.check((SELECT string_agg(column_name, ',' ORDER BY ordinal_position) FROM information_schema.columns WHERE table_name = 'inbound_email' AND ordinal_position <= 20) = 'id,mailbox_id,provider_uid,message_id,in_reply_to,references_ids,from_address,from_name,to_addresses,cc_addresses,subject,body_text,body_html,headers,received_at,status,error,ticket_id,message_ref_id,processed_at', 'inbound_email = Help Desk db/012 (ours appended)');
RESET ROLE;

-- ---------------------------------------------------------------- the read roles see views only
SET ROLE sales_crm_records_ro;
SELECT pg_temp.as_member(26);
SELECT pg_temp.check((SELECT count(*) FROM mcp_deals) > 0 AND (SELECT count(*) FROM mcp_app_roles) = 5 AND (SELECT count(*) FROM crm_search('Acme')) > 0, 'the records role reads the views, app_roles and the search');
SELECT pg_temp.refused('SELECT count(*) FROM deals', 'permission denied', 'the records role reads no table');
SELECT pg_temp.refused('SELECT token_hash FROM mcp_access_tokens', 'permission denied', 'the records role sees no hash');
SELECT pg_temp.refused($$SELECT crm_deal_move(1, 1)$$, 'permission denied', 'the records role moves nothing');
RESET ROLE;
SET ROLE sales_crm_activity_ro;
SELECT pg_temp.as_member(26);
SELECT pg_temp.check((SELECT count(*) FROM mcp_members) > 0 AND (SELECT count(*) FROM mcp_deals) > 0, 'the activity role reads the trail''s names');
SELECT pg_temp.refused('SELECT count(*) FROM mcp_emails', 'permission denied', 'the activity role sees no email');
RESET ROLE;

-- ---------------------------------------------------------------- the one that proves the proof: nothing committed
SET ROLE sales_crm_rw;
SELECT pg_temp.check((SELECT count(*) FROM members) = 10 AND (SELECT count(*) FROM accounts) >= 5, 'the fixtures are there until the rollback');
RESET ROLE;

\o
\pset tuples_only on
SELECT CASE WHEN ok THEN ' ok   ' ELSE ' FAIL ' END || label FROM proof ORDER BY n;
SELECT ' ' || count(*) FILTER (WHERE ok) || ' ok, ' || count(*) FILTER (WHERE NOT ok) || ' failed' FROM proof;
SELECT count(*) FILTER (WHERE NOT ok) > 0 AS anyfail FROM proof \gset
ROLLBACK;
\if :anyfail
    \echo 'PHASE 0 PROOF FAILED'
    \set ON_ERROR_STOP on
    SELECT 1 / 0;
\endif
