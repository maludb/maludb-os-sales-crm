-- 004: Sales CRM's roles and the rights they give (kernel db/145; roles-and-rights.md; the design §3, D2).
--
-- Published to the kernel by app_roles on the records MCP (os.app-roles/1). The kernel grants a SET of roles; the claims and
-- the feed carry them back into members.roles. What a role lets its holder do is the application's to enforce:
-- crm_has_right(right) answers from the catalogue and the mirror. The base role's KEY IS `user` on purpose (Sales Rep): the
-- installer's --grant-standing-departments gives a department the role keyed member/user/write.
--
-- Rights are application-wide capabilities; WHO SEES WHICH RECORD is the wall of db/006 (open by default; a restriction to the
-- owner and a department; D5) and WHO MAY EDIT IT is ownership (the owner, a manager, Marketing for its kinds). Five roles:
-- Viewer, Sales Rep (user), Marketing, Sales Manager, CRM admin (is_admin). Teams are the kernel's departments.
--
-- THE ESTATE'S DATA MODEL (design §6.5): the shape is Consultant Tracking db/004 = DocCloud db/004 (dc_ → crm_); the roles and
-- rights are this application's; crm_member_roles(member) is Inventory db/004's convenience (the roles a member holds, resolved).
BEGIN;

CREATE TABLE crm_rights (
    right_key   text PRIMARY KEY CHECK (right_key ~ '^[a-z][a-z0-9_.]{0,59}$'),
    description text NOT NULL,
    sort_order  integer NOT NULL DEFAULT 0
);
CREATE TABLE crm_roles (
    role_key    text PRIMARY KEY CHECK (role_key ~ '^[a-z][a-z0-9_]{0,39}$'),
    name        text NOT NULL,
    description text NOT NULL,
    capability  text NOT NULL CHECK (capability IN ('read', 'write', 'admin')),
    is_admin    boolean NOT NULL DEFAULT false,
    sort_order  integer NOT NULL DEFAULT 0,
    CHECK (NOT is_admin OR capability = 'admin')
);
CREATE UNIQUE INDEX crm_roles_one_admin ON crm_roles (is_admin) WHERE is_admin;
CREATE TABLE crm_role_rights (
    role_key  text NOT NULL REFERENCES crm_roles(role_key) ON DELETE CASCADE,
    right_key text NOT NULL REFERENCES crm_rights(right_key) ON DELETE CASCADE,
    PRIMARY KEY (role_key, right_key)
);

INSERT INTO crm_rights (right_key, description, sort_order) VALUES
 ('records.read', 'See every lead, account, contact, deal and activity, with amounts (D5), and every report', 1),
 ('calendar.read', 'See the calendar of any seller and the team', 2),
 ('leads.write', 'Create and edit leads they own or that are unowned; qualify and unqualify them', 3),
 ('leads.convert', 'Convert a lead into an account, a contact and, if asked, a deal', 4),
 ('leads.assign', 'Assign leads to anyone (the inbox, the rules)', 5),
 ('accounts.write', 'Create accounts; edit those they own or that are unowned', 6),
 ('contacts.write', 'Create contacts; edit those they own or that are unowned; record opt-outs', 7),
 ('deals.write', 'Create deals; edit, move and close those they own or that are unowned', 8),
 ('deals.any', 'Edit, move, reassign, reopen and close anyone''s deal', 9),
 ('activities.write', 'Schedule and log activities on records they may see; complete any activity assigned to them', 10),
 ('activities.any', 'Edit, reassign and complete anyone''s activity', 11),
 ('email.log', 'Log an email already sent (paste, forward, the BCC mailbox)', 12),
 ('email.send', 'Send an email to a contact from the application through MaluMail (pauses for an agent)', 13),
 ('templates.manage', 'Keep the shared email templates (own templates need nothing)', 14),
 ('tags.apply', 'Apply tags to any record they may edit', 15),
 ('tags.manage', 'Keep the tag vocabulary: rename, merge, delete', 16),
 ('lists.own', 'Keep static and smart lists of their own', 17),
 ('lists.shared', 'Keep lists and segments shared with everyone', 18),
 ('campaigns.manage', 'Create and run campaigns, add and remove members, record results', 19),
 ('sources.manage', 'Keep the lead sources and lost reasons', 20),
 ('imports.own', 'Import a CSV into records they will own; roll it back within a day', 21),
 ('imports.shared', 'Import for anyone (the owner column in the file)', 22),
 ('export.own', 'Export what they see of a list or a screen as CSV', 23),
 ('export.shared', 'Export every record of a kind', 24),
 ('records.restrict', 'Restrict a record to its owner and a department, or lift the restriction', 25),
 ('records.merge', 'Merge two leads, accounts or contacts (pauses for an agent)', 26),
 ('records.bulk', 'Bulk assign, tag, update, add to a campaign or a list, or delete across a list or a filtered screen', 27),
 ('pipelines.manage', 'Keep the pipelines and stages: order, probability, rotting days, required fields, ask-the-Assistant, WIP', 28),
 ('assignment.rules', 'Keep the lead assignment rules', 29),
 ('reports.team', 'See the team reports: forecast against quota, funnel, win rate, cycle time, leaderboard, activity by seller', 30),
 ('calendar.own', 'Keep their own calendar and ICS feed', 31),
 ('calendar.team', 'See the team calendar and reschedule anyone''s activity', 32),
 ('settings.manage', 'Run Sales CRM: the application settings (currency, fiscal year, the default pipeline, rotting, duplicates policy, the morning note)', 33),
 ('fields.manage', 'Keep the custom fields of the five records', 34),
 ('mailbox.manage', 'Keep the logging mailbox and see its health', 35),
 ('quotas.manage', 'Set the quota per seller per month', 36),
 ('agents.settings', 'See every agent''s dispatches and the Assistant''s proposals and settings; link to the kernel for hires and grants', 37),
 ('tokens.manage', 'Mint and revoke keys for anyone, incl. lead-capture keys', 38),
 ('api.manage', 'Set the API''s rates and CORS', 39),
 ('records.purge', 'Purge tombstoned records before their time', 40),
 ('costs.read', 'See the agents'' usage by month', 41);

INSERT INTO crm_roles (role_key, name, description, capability, is_admin, sort_order) VALUES
 ('viewer',    'Viewer',        'Reads everything — every record with amounts, every report, every calendar — and changes nothing. Finance, support, leadership.', 'read',  false, 1),
 ('user',      'Sales Rep',     'A seller: their leads, accounts, contacts and deals and everyone else''s (open by default); logs activities, moves their deals, sends logged email, runs their calendar, imports into their own lists.', 'write', false, 2),
 ('marketing', 'Marketing',     'Leads, sources, campaigns, lists, segments, imports and the shared templates; deals and activities read-only.', 'write', false, 3),
 ('manager',   'Sales Manager', 'Runs a team: anyone''s deals and activities, reassigns, reopens, merges, bulk-edits, restricts; owns pipelines, stages, rules, quotas and lost reasons; every report; the team calendar.', 'write', false, 4),
 ('admin',     'CRM admin',     'Every right: settings, custom fields, the logging mailbox, the agents'' settings, keys and the API, purge, costs. A super-admin holds this role.', 'admin', true, 5);

INSERT INTO crm_role_rights (role_key, right_key)
SELECT 'viewer', r FROM unnest(ARRAY['records.read','calendar.read']) r
UNION ALL SELECT 'user', r FROM unnest(ARRAY['records.read','calendar.read','leads.write','leads.convert','accounts.write','contacts.write','deals.write','activities.write','email.log','email.send','tags.apply','lists.own','imports.own','export.own','calendar.own']) r
UNION ALL SELECT 'marketing', r FROM unnest(ARRAY['records.read','calendar.read','leads.write','leads.assign','sources.manage','campaigns.manage','lists.own','lists.shared','imports.own','imports.shared','templates.manage','tags.apply','tags.manage','export.own','export.shared','calendar.own']) r
UNION ALL SELECT 'manager', r FROM unnest(ARRAY['records.read','calendar.read','leads.write','leads.convert','leads.assign','accounts.write','contacts.write','deals.write','deals.any','activities.write','activities.any','email.log','email.send','templates.manage','tags.apply','tags.manage','lists.own','lists.shared','campaigns.manage','sources.manage','imports.own','imports.shared','export.own','export.shared','records.restrict','records.merge','records.bulk','pipelines.manage','assignment.rules','reports.team','calendar.own','calendar.team','quotas.manage']) r
UNION ALL SELECT 'admin', right_key FROM crm_rights;

-- The roles a member holds, resolved: the kernel's list; a super-admin always holds admin; a member the kernel has not yet sent
-- roles for is read from the capability (admin = admin, read = viewer, anything else = user). Empty for nobody.
CREATE OR REPLACE FUNCTION crm_member_roles(p_member_id bigint) RETURNS text[]
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE r text[];
BEGIN
    SELECT CASE
             WHEN m.member_kind = 'human' AND m.business_role = 'super_admin' THEN (SELECT array_agg(DISTINCT x) FROM unnest(m.roles || ARRAY['admin']) x)
             WHEN cardinality(m.roles) > 0 THEN m.roles
             ELSE ARRAY[CASE m.capability WHEN 'admin' THEN 'admin' WHEN 'read' THEN 'viewer' ELSE 'user' END]
           END
      INTO r
      FROM members m
     WHERE m.id = p_member_id AND m.status = 'active' AND m.capability IS NOT NULL;
    RETURN COALESCE(r, '{}');
END$$;

-- The rule: a right the member's roles give. The member must be active and admitted.
CREATE OR REPLACE FUNCTION crm_has_right(p_right text) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (
        SELECT 1 FROM crm_role_rights rr
         WHERE rr.right_key = p_right AND rr.role_key = ANY (crm_member_roles(app_current_member_id())));
END$$;

-- Runs Sales CRM: the super-admin, or a human holding settings.manage.
CREATE OR REPLACE FUNCTION crm_is_admin() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN app_is_super_admin() OR (app_member_kind() = 'human' AND crm_has_right('settings.manage'));
END$$;

-- A viewer: admitted and holding nothing above Viewer — reads everything, changes nothing.
CREATE OR REPLACE FUNCTION crm_member_is_viewer(p_member_id bigint) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (SELECT 1 FROM members m WHERE m.id = p_member_id AND m.status = 'active' AND m.capability IS NOT NULL)
       AND NOT (crm_member_roles(p_member_id) && ARRAY['user', 'marketing', 'manager', 'admin']);
END$$;

CREATE OR REPLACE FUNCTION crm_is_viewer() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN crm_member_is_viewer(app_current_member_id());
END$$;

-- A manager: holds deals.any (a Sales Manager or the admin) — may edit, move and reassign anyone's record and lift a restriction.
CREATE OR REPLACE FUNCTION crm_is_manager() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN crm_is_admin() OR crm_has_right('deals.any');
END$$;

-- Someone who belongs in the business here: admitted (any role; an agent granted a role too).
CREATE OR REPLACE FUNCTION crm_is_member_here() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN app_is_active_member();
END$$;

-- What app_roles answers, in one read.
CREATE OR REPLACE VIEW mcp_app_roles AS
SELECT r.role_key, r.name, r.description, r.capability, r.is_admin, r.sort_order,
       COALESCE((SELECT array_agg(rr.right_key ORDER BY h.sort_order) FROM crm_role_rights rr
                   JOIN crm_rights h ON h.right_key = rr.right_key WHERE rr.role_key = r.role_key), '{}') AS rights
FROM crm_roles r;

GRANT SELECT ON crm_rights, crm_roles, crm_role_rights, mcp_app_roles TO sales_crm_rw, sales_crm_records_ro;
GRANT EXECUTE ON FUNCTION crm_member_roles(bigint), crm_has_right(text), crm_is_admin(), crm_is_viewer(), crm_member_is_viewer(bigint), crm_is_manager(), crm_is_member_here()
    TO sales_crm_rw, sales_crm_records_ro, sales_crm_activity_ro;

COMMIT;
