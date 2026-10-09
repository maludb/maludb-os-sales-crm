-- 016: WHO SEES WHAT — the mcp_* views, the only thing the two read roles see (memory.md §1; design §3). Every WHERE clause IS the
-- row rule; the servers never filter in Python. The wall is crm_wall() inlined per row over the row's own three facts; the caller's
-- facts are STABLE functions evaluated once per statement. A tombstoned record is out of every list but mcp_tombstones (managers).
--
-- The primary key is re-aliased <entity>_id; raw columns that hide internals are not selected: a token's hash NEVER, a mailbox's secret
-- reference NEVER, an outbox body, a session hash. An email's BODY is content a viewer may read only for a contact they see and an email
-- that is not private (mcp_emails).
BEGIN;

CREATE OR REPLACE FUNCTION mcp_admit_agent(p_member_id bigint) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    UPDATE members SET capability = 'write', synced_at = now()
     WHERE id = p_member_id AND member_kind = 'agent' AND status = 'active' AND capability IS NULL;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n = 1;
END$$;
REVOKE ALL ON FUNCTION mcp_admit_agent(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mcp_admit_agent(bigint) TO sales_crm_records_ro, sales_crm_activity_ro, sales_crm_rw;

CREATE OR REPLACE FUNCTION mcp_member_kind(p_member_id bigint) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT member_kind FROM members WHERE id = p_member_id AND status = 'active' AND capability IS NOT NULL;
$$;
REVOKE ALL ON FUNCTION mcp_member_kind(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mcp_member_kind(bigint) TO sales_crm_records_ro, sales_crm_activity_ro, sales_crm_rw;

CREATE OR REPLACE VIEW mcp_members WITH (security_barrier = true) AS
SELECT m.id AS member_id, m.display_name, m.member_kind, m.is_agent, m.business_role, m.is_external, m.status, m.capability, m.roles, crm_member_roles(m.id) AS roles_resolved,
       m.job_title, m.timezone, m.email::text AS email,
       (SELECT array_agg(dm.department_id) FROM department_members dm WHERE dm.member_id = m.id AND dm.left_at IS NULL) AS department_ids
  FROM members m
 WHERE m.status = 'active' AND m.capability IS NOT NULL AND (SELECT crm_is_member_here());

CREATE OR REPLACE VIEW mcp_departments WITH (security_barrier = true) AS
SELECT d.id AS department_id, d.name, d.description, d.parent_id, d.manager_member_id, d.is_system, d.system_key, d.archived_at
  FROM departments d WHERE (SELECT crm_is_member_here());

CREATE OR REPLACE VIEW mcp_settings WITH (security_barrier = true) AS
SELECT s.business_name, s.currency, s.fiscal_year_start_month, s.default_pipeline_id, s.rotting_days, s.weighted_method, s.forecast_months, s.duplicates_policy,
       s.default_remind_minutes, s.next_step_prompt, s.morning_note_hour, s.morning_note_enabled, s.assistant_proposals, s.lead_untouched_days, s.account_untouched_days,
       s.tombstone_days, s.import_max_rows, s.import_rollback_hours, s.key_rate_per_minute, s.key_rate_per_day, s.capture_rate_per_day, s.allow_restricted_records,
       s.ics_days_ahead, s.ics_days_back, s.timezone, CASE WHEN (SELECT crm_is_admin()) THEN s.mailbox_id END AS mailbox_id, s.updated_at
  FROM crm_settings s WHERE (SELECT crm_is_member_here());

CREATE OR REPLACE VIEW mcp_lead_sources WITH (security_barrier = true) AS
SELECT s.id AS source_id, s.name, s.kind, s.active, s.sort_order FROM lead_sources s WHERE (SELECT crm_is_member_here());
CREATE OR REPLACE VIEW mcp_lost_reasons WITH (security_barrier = true) AS
SELECT r.id AS lost_reason_id, r.kind, r.label, r.active, r.sort_order FROM lost_reasons r WHERE (SELECT crm_is_member_here());
CREATE OR REPLACE VIEW mcp_tags WITH (security_barrier = true) AS
SELECT t.id AS tag_id, t.slug, t.label, t.usage_count FROM tags t WHERE (SELECT crm_is_member_here());
CREATE OR REPLACE VIEW mcp_custom_fields WITH (security_barrier = true) AS
SELECT f.id AS field_id, f.key, f.label, f.field_type, f.options, f.applies_to, f.type_ids AS pipeline_ids, f.required_on_solve AS required_to_win, f.sort_order, f.archived_at
  FROM custom_field_defs f WHERE (SELECT crm_is_member_here());

CREATE OR REPLACE VIEW mcp_pipelines WITH (security_barrier = true) AS
SELECT p.id AS pipeline_id, p.key, p.name, p.is_default, p.currency, p.rotting_days, p.position, p.archived_at FROM pipelines p WHERE (SELECT crm_is_member_here());
CREATE OR REPLACE VIEW mcp_stages WITH (security_barrier = true) AS
SELECT s.id AS stage_id, s.pipeline_id, s.name, s.position, s.probability, s.kind, crm_stage_rotting_days(s.id) AS rotting_days, s.required_fields, s.ask_assistant, s.colour, s.wip_limit, s.archived_at
  FROM stages s WHERE (SELECT crm_is_member_here());

CREATE OR REPLACE VIEW mcp_leads WITH (security_barrier = true) AS
SELECT l.id AS lead_id, crm_lead_label(l) AS label, l.first_name, l.last_name, l.company, l.title, l.email::text AS email, l.phone, l.website, l.city, l.region, l.country,
       left(l.message, 500) AS message, l.source_id, (SELECT name FROM lead_sources WHERE id = l.source_id) AS source, l.campaign_id, l.utm, l.status, l.unqualified_reason_id, l.score,
       l.owner_member_id, l.last_touched_at, l.next_activity_at, crm_next_colour(l.next_activity_at) AS next_colour, l.converted_at, l.converted_account_id, l.converted_contact_id, l.converted_deal_id,
       l.is_proposed, l.restricted_to_member_id IS NOT NULL OR l.restricted_to_department_id IS NOT NULL AS restricted, crm_tags_of('lead', l.id) AS tags, l.custom_values, l.created_by, l.created_at, l.updated_at
  FROM leads l WHERE l.deleted_at IS NULL AND crm_wall(l.owner_member_id, l.restricted_to_member_id, l.restricted_to_department_id);

CREATE OR REPLACE VIEW mcp_accounts WITH (security_barrier = true) AS
SELECT a.id AS account_id, a.name, a.legal_name, a.domain::text AS domain, a.website, a.industry, a.employees, a.annual_revenue, a.currency, a.phone, a.email::text AS email,
       a.billing_address, a.shipping_address, a.city, a.region, a.country, a.kind, a.parent_account_id, a.owner_member_id, a.source_id, a.description, a.gl_customer_id, a.helpdesk_organization_id,
       a.last_touched_at, a.next_activity_at, crm_next_colour(a.next_activity_at) AS next_colour, a.open_deals_count, a.open_deals_value, a.won_deals_value,
       (SELECT count(*) FROM contacts c WHERE c.account_id = a.id AND c.deleted_at IS NULL) AS contacts_count,
       (SELECT c.id FROM contacts c WHERE c.account_id = a.id AND c.is_primary AND c.deleted_at IS NULL) AS primary_contact_id,
       a.restricted_to_member_id IS NOT NULL OR a.restricted_to_department_id IS NOT NULL AS restricted, crm_tags_of('account', a.id) AS tags, a.custom_values, a.created_by, a.created_at, a.updated_at
  FROM accounts a WHERE a.deleted_at IS NULL AND a.merged_into_id IS NULL AND crm_wall(a.owner_member_id, a.restricted_to_member_id, a.restricted_to_department_id);

CREATE OR REPLACE VIEW mcp_contacts WITH (security_barrier = true) AS
SELECT c.id AS contact_id, c.account_id, (SELECT name FROM accounts WHERE id = c.account_id) AS account_name, c.first_name, c.last_name, c.name, c.title, c.department_text, c.email::text AS email,
       c.email_alt::text AS email_alt, c.phone, c.mobile, c.role, c.is_primary, c.linkedin_url, c.address, c.city, c.region, c.country, c.timezone, c.preferred_channel, c.birthday, c.lifecycle,
       c.email_opt_out, c.email_opt_out_at, c.sms_opt_out, c.do_not_call, c.owner_member_id, c.source_id, c.last_touched_at, c.next_activity_at, crm_next_colour(c.next_activity_at) AS next_colour,
       (SELECT count(*) FROM deals d WHERE d.deleted_at IS NULL AND d.status = 'open' AND (d.primary_contact_id = c.id OR d.id IN (SELECT deal_id FROM deal_contacts WHERE contact_id = c.id))) AS open_deals_count,
       c.restricted_to_member_id IS NOT NULL OR c.restricted_to_department_id IS NOT NULL AS restricted, crm_tags_of('contact', c.id) AS tags, c.custom_values, c.created_by, c.created_at, c.updated_at
  FROM contacts c WHERE c.deleted_at IS NULL AND c.merged_into_id IS NULL AND crm_wall(c.owner_member_id, c.restricted_to_member_id, c.restricted_to_department_id);

CREATE OR REPLACE VIEW mcp_contact_emails WITH (security_barrier = true) AS
SELECT e.id AS contact_email_id, e.contact_id, e.address::text AS address, e.is_primary, e.verified_at FROM contact_emails e WHERE e.contact_id IN (SELECT contact_id FROM mcp_contacts);

CREATE OR REPLACE VIEW mcp_deals WITH (security_barrier = true) AS
SELECT d.id AS deal_id, d.number, d.title, d.account_id, (SELECT name FROM accounts WHERE id = d.account_id) AS account_name, d.primary_contact_id,
       (SELECT name FROM contacts WHERE id = d.primary_contact_id) AS primary_contact_name, d.pipeline_id, d.stage_id, (SELECT name FROM stages WHERE id = d.stage_id) AS stage_name,
       d.position, d.value, d.currency, d.value_from_products, d.probability, d.probability_override, d.weighted_value, d.expected_close_date, d.status, d.won_at, d.lost_at, d.lost_reason_id,
       (SELECT label FROM lost_reasons WHERE id = d.lost_reason_id) AS lost_reason, d.lost_note, d.closed_by, d.source_id, d.campaign_id, d.lead_id, d.owner_member_id, d.stage_entered_at,
       extract(day FROM now() - d.stage_entered_at)::integer AS days_in_stage, crm_deal_is_rotting(d.status, d.stage_id, d.stage_entered_at) AS is_rotting,
       d.last_touched_at, d.next_activity_id, d.next_activity_at, crm_next_colour(d.next_activity_at) AS next_colour, d.next_activity_at IS NOT NULL AS has_next_step,
       d.description, d.restricted_to_member_id IS NOT NULL OR d.restricted_to_department_id IS NOT NULL AS restricted, crm_tags_of('deal', d.id) AS tags, d.custom_values, d.created_by, d.created_at, d.updated_at
  FROM deals d WHERE d.deleted_at IS NULL AND crm_wall(d.owner_member_id, d.restricted_to_member_id, d.restricted_to_department_id);

CREATE OR REPLACE VIEW mcp_deal_contacts WITH (security_barrier = true) AS
SELECT dc.deal_id, dc.contact_id, (SELECT name FROM contacts WHERE id = dc.contact_id) AS contact_name, dc.role, dc.is_primary, dc.added_at FROM deal_contacts dc WHERE dc.deal_id IN (SELECT deal_id FROM mcp_deals);
CREATE OR REPLACE VIEW mcp_deal_products WITH (security_barrier = true) AS
SELECT p.id AS deal_product_id, p.deal_id, p.position, p.name, p.provider_app, p.provider_id, p.sku, p.quantity, p.unit_price, p.discount_percent, p.line_total, p.recurring FROM deal_products p WHERE p.deal_id IN (SELECT deal_id FROM mcp_deals);
CREATE OR REPLACE VIEW mcp_deal_stage_history WITH (security_barrier = true) AS
SELECT h.id AS history_id, h.deal_id, h.from_stage_id, (SELECT name FROM stages WHERE id = h.from_stage_id) AS from_stage, h.to_stage_id, (SELECT name FROM stages WHERE id = h.to_stage_id) AS to_stage,
       h.member_id, h.at, h.seconds_in_from_stage, h.source FROM deal_stage_history h WHERE h.deal_id IN (SELECT deal_id FROM mcp_deals);

CREATE OR REPLACE VIEW mcp_activities WITH (security_barrier = true) AS
SELECT a.id AS activity_id, a.kind, a.subject, CASE WHEN NOT a.is_private OR a.owner_member_id = app_current_member_id() OR a.created_by = app_current_member_id() OR (SELECT crm_is_manager()) THEN a.body END AS body,
       a.account_id, a.contact_id, a.deal_id, a.lead_id, a.owner_member_id, a.created_by, a.due_at, a.starts_at, a.ends_at, COALESCE(a.starts_at, a.due_at) AS at, a.all_day, a.timezone,
       a.done_at, a.done_by, a.outcome, a.outcome_note, a.remind_minutes_before, a.remind_at, a.reminded_at, a.snoozed_until, a.email_direction, a.inbound_email_id, a.outbound_email_id, a.is_private,
       a.location, a.meeting_url, a.priority, a.recurrence, a.source, a.proposal_id, a.custom_values, a.created_at, a.updated_at,
       CASE WHEN a.done_at IS NOT NULL THEN 'done' ELSE crm_next_colour(COALESCE(a.starts_at, a.due_at)) END AS state
  FROM activities a
 WHERE a.deleted_at IS NULL
   AND (NOT a.is_private OR a.owner_member_id = app_current_member_id() OR a.created_by = app_current_member_id() OR (SELECT crm_is_manager()))
   AND (a.deal_id IS NULL OR EXISTS (SELECT 1 FROM deals d WHERE d.id = a.deal_id AND crm_wall(d.owner_member_id, d.restricted_to_member_id, d.restricted_to_department_id)))
   AND (a.contact_id IS NULL OR EXISTS (SELECT 1 FROM contacts c WHERE c.id = a.contact_id AND crm_wall(c.owner_member_id, c.restricted_to_member_id, c.restricted_to_department_id)))
   AND (a.account_id IS NULL OR EXISTS (SELECT 1 FROM accounts x WHERE x.id = a.account_id AND crm_wall(x.owner_member_id, x.restricted_to_member_id, x.restricted_to_department_id)))
   AND (a.lead_id IS NULL OR EXISTS (SELECT 1 FROM leads l WHERE l.id = a.lead_id AND crm_wall(l.owner_member_id, l.restricted_to_member_id, l.restricted_to_department_id)))
   AND (SELECT crm_is_member_here());

CREATE OR REPLACE VIEW mcp_activity_participants WITH (security_barrier = true) AS
SELECT ap.activity_id, ap.contact_id, ap.member_id, ap.response FROM activity_participants ap WHERE ap.activity_id IN (SELECT activity_id FROM mcp_activities);

-- Emails: headers for anyone who sees the contact; the body only when not private (or theirs / a manager's).
CREATE OR REPLACE VIEW mcp_emails WITH (security_barrier = true) AS
SELECT 'in' AS direction, i.id AS email_id, i.received_at AS at, i.from_address::text AS from_address, i.from_name, i.to_addresses, i.cc_addresses, i.subject,
       CASE WHEN NOT i.is_private OR i.sender_member_id = app_current_member_id() OR (SELECT crm_is_manager()) THEN i.body_text END AS body_text,
       i.sender_member_id, i.deal_id, i.matched_contact_ids, i.match_status, i.status, i.is_private
  FROM inbound_email i
 WHERE (SELECT crm_is_member_here()) AND i.match_status IN ('matched', 'proposed_lead', 'unmatched')
   AND (NOT i.is_private OR i.sender_member_id = app_current_member_id() OR (SELECT crm_is_manager()))
   AND (i.match_status <> 'matched' OR EXISTS (SELECT 1 FROM contacts c WHERE c.id = ANY (i.matched_contact_ids) AND crm_wall(c.owner_member_id, c.restricted_to_member_id, c.restricted_to_department_id)))
UNION ALL
SELECT 'out', o.id, COALESCE(o.sent_at, o.created_at), (SELECT email::text FROM members WHERE id = o.sender_member_id), (SELECT display_name FROM members WHERE id = o.sender_member_id), ARRAY[o.to_address::text], o.cc_addresses, o.subject,
       CASE WHEN NOT o.is_private OR o.sender_member_id = app_current_member_id() OR (SELECT crm_is_manager()) THEN o.body_text END,
       o.sender_member_id, o.deal_id, CASE WHEN o.contact_id IS NULL THEN '{}'::bigint[] ELSE ARRAY[o.contact_id] END, 'matched', o.status, o.is_private
  FROM outbound_email o
 WHERE (SELECT crm_is_member_here())
   AND (NOT o.is_private OR o.sender_member_id = app_current_member_id() OR (SELECT crm_is_manager()))
   AND (o.contact_id IS NULL OR EXISTS (SELECT 1 FROM contacts c WHERE c.id = o.contact_id AND crm_wall(c.owner_member_id, c.restricted_to_member_id, c.restricted_to_department_id)));

CREATE OR REPLACE VIEW mcp_email_templates WITH (security_barrier = true) AS
SELECT t.id AS template_id, t.name, t.subject, t.body, t.kind, t.is_shared, t.owner_member_id, t.usage_count, t.archived_at
  FROM email_templates t WHERE (SELECT crm_is_member_here()) AND (t.is_shared OR t.owner_member_id = app_current_member_id() OR (SELECT crm_is_admin()));

CREATE OR REPLACE VIEW mcp_mailboxes WITH (security_barrier = true) AS
SELECT m.id AS mailbox_id, m.address::text AS address, m.display_name, m.provider, m.poll_enabled, m.last_polled_at, m.last_webhook_at, m.last_error, m.last_error_at, m.active,
       (SELECT count(*) FROM inbound_email i WHERE i.mailbox_id = m.id AND i.received_at > now() - interval '7 days') AS received_7d,
       (SELECT count(*) FROM inbound_email i WHERE i.mailbox_id = m.id AND i.match_status = 'pending') AS pending
  FROM mailboxes m WHERE (SELECT crm_has_right('mailbox.manage'));

CREATE OR REPLACE VIEW mcp_campaigns WITH (security_barrier = true) AS
SELECT c.id AS campaign_id, c.name, c.kind, c.status, c.starts_on, c.ends_on, c.budget, c.currency, c.expected_leads, c.utm_campaign, c.owner_member_id, c.description, c.created_at, c.updated_at
  FROM campaigns c WHERE c.deleted_at IS NULL AND (SELECT crm_is_member_here());
CREATE OR REPLACE VIEW mcp_campaign_members WITH (security_barrier = true) AS
SELECT cm.id AS campaign_member_id, cm.campaign_id, cm.lead_id, cm.contact_id, cm.status, cm.added_by, cm.added_at, cm.responded_at FROM campaign_members cm WHERE (SELECT crm_is_member_here());

CREATE OR REPLACE VIEW mcp_lists WITH (security_barrier = true) AS
SELECT l.id AS list_id, l.name, l.kind, l.record_kind, l.filters, l.owner_member_id, l.is_shared, l.member_count, l.counted_at, l.description, l.created_at
  FROM lists l WHERE l.deleted_at IS NULL AND (SELECT crm_is_member_here()) AND (l.is_shared OR l.owner_member_id = app_current_member_id() OR (SELECT crm_is_manager()));
CREATE OR REPLACE VIEW mcp_list_members WITH (security_barrier = true) AS
SELECT lm.list_id, lm.record_id, lm.added_by, lm.added_at FROM list_members lm WHERE lm.list_id IN (SELECT list_id FROM mcp_lists);

CREATE OR REPLACE VIEW mcp_saved_views WITH (security_barrier = true) AS
SELECT v.id AS saved_view_id, v.name, v.scope, v.owner_member_id, v.pipeline_id, v.record_kind, v.filters, v.sort_by, v.sort_dir, v.columns, v.layout, v.board, v.is_system, v.system_key, v.sort_order
  FROM saved_views v WHERE v.archived_at IS NULL AND (SELECT crm_is_member_here()) AND (v.scope <> 'personal' OR v.owner_member_id = app_current_member_id());

CREATE OR REPLACE VIEW mcp_imports WITH (security_barrier = true) AS
SELECT i.id AS import_id, i.kind, i.file_name, i.status, i.row_count, i.rows_made AS rows_created, i.rows_updated, i.rows_skipped, i.unsupported AS rows_refused, i.mapping, i.dedupe_policy, i.dry_run,
       i.owner_member_id, i.source_id, i.list_id, i.template_id, i.rollback_until, i.rolled_back_at, i.created_by, i.created_at, i.finished_at, left(i.log, 2000) AS log
  FROM imports i WHERE (SELECT crm_is_member_here()) AND (i.created_by = app_current_member_id() OR (SELECT crm_has_right('imports.shared')) OR (SELECT crm_is_manager()));
CREATE OR REPLACE VIEW mcp_import_rows WITH (security_barrier = true) AS
SELECT r.import_id, r.row_number, r.result, r.record_id, r.message FROM import_rows r WHERE r.import_id IN (SELECT import_id FROM mcp_imports);
CREATE OR REPLACE VIEW mcp_exports WITH (security_barrier = true) AS
SELECT e.id AS export_id, e.kind, e.format, e.status, e.byte_size, e.item_count, e.params, e.list_id, e.created_by, e.created_at, e.finished_at, e.expires_at, e.downloaded_at
  FROM exports e WHERE e.created_by = app_current_member_id() OR (SELECT crm_is_admin());

CREATE OR REPLACE VIEW mcp_notes WITH (security_barrier = true) AS
SELECT n.id AS note_id, n.record_type, n.record_id, n.member_id, n.body, n.created_at FROM notes n
 WHERE (n.record_type = 'lead' AND n.record_id IN (SELECT lead_id FROM mcp_leads)) OR (n.record_type = 'account' AND n.record_id IN (SELECT account_id FROM mcp_accounts))
    OR (n.record_type = 'contact' AND n.record_id IN (SELECT contact_id FROM mcp_contacts)) OR (n.record_type = 'deal' AND n.record_id IN (SELECT deal_id FROM mcp_deals))
    OR (n.record_type IN ('activity', 'campaign') AND (SELECT crm_is_member_here()));
CREATE OR REPLACE VIEW mcp_attachments WITH (security_barrier = true) AS
SELECT a.id AS attachment_id, a.record_type, a.record_id, a.filename, a.mime_type, a.byte_size, a.uploaded_by, a.created_at FROM attachments a
 WHERE (a.record_type = 'lead' AND a.record_id IN (SELECT lead_id FROM mcp_leads)) OR (a.record_type = 'account' AND a.record_id IN (SELECT account_id FROM mcp_accounts))
    OR (a.record_type = 'contact' AND a.record_id IN (SELECT contact_id FROM mcp_contacts)) OR (a.record_type = 'deal' AND a.record_id IN (SELECT deal_id FROM mcp_deals))
    OR (a.record_type IN ('activity', 'campaign', 'inbound_email', 'outbound_email') AND (SELECT crm_is_member_here()));

CREATE OR REPLACE VIEW mcp_notifications WITH (security_barrier = true) AS
SELECT n.id AS notification_id, n.kind, n.record_type, n.record_id, n.title, n.body, n.read_at, n.created_at, n.deal_id, n.contact_id, n.activity_id, n.actor_member_id
  FROM notifications n WHERE n.member_id = app_current_member_id();
CREATE OR REPLACE VIEW mcp_notification_prefs WITH (security_barrier = true) AS
SELECT p.member_id, p.email_enabled, p.text_enabled, p.kinds, p.text_kinds, p.default_remind_minutes, p.morning_note, p.morning_hour, p.digest FROM notification_prefs p WHERE p.member_id = app_current_member_id() OR (SELECT crm_is_admin());

CREATE OR REPLACE VIEW mcp_assistant_proposals WITH (security_barrier = true) AS
SELECT p.id AS proposal_id, p.kind, p.deal_id, p.lead_id, p.contact_id, p.account_id, p.for_member_id, p.title, p.payload, p.proposed_by, p.status, p.decided_by, p.decided_at, p.result_activity_id, p.created_at
  FROM assistant_proposals p WHERE (SELECT crm_is_member_here()) AND (p.for_member_id = app_current_member_id() OR p.proposed_by = app_current_member_id() OR (SELECT crm_is_manager()));
CREATE OR REPLACE VIEW mcp_agent_dispatches WITH (security_barrier = true) AS
SELECT d.id AS dispatch_id, d.record_type, d.record_id, d.agent_member_id, d.kind, d.via, d.acting_member_id, d.run_id, d.status, d.reply_excerpt, d.detail, d.attempts, d.created_at, d.answered_at, d.deal_id, d.lead_id
  FROM agent_dispatches d WHERE (SELECT crm_has_right('agents.settings')) OR d.agent_member_id = app_current_member_id() OR d.acting_member_id = app_current_member_id();

CREATE OR REPLACE VIEW mcp_assignment_rules WITH (security_barrier = true) AS
SELECT r.id AS rule_id, r.name, r.position, r.active, r.match, r.assign_kind, r.member_id, r.department_id, r.members FROM assignment_rules r WHERE (SELECT crm_has_right('assignment.rules')) OR (SELECT crm_has_right('leads.assign'));
CREATE OR REPLACE VIEW mcp_quotas WITH (security_barrier = true) AS
SELECT q.member_id, q.month, q.amount FROM quotas q WHERE q.member_id = app_current_member_id() OR (SELECT crm_has_right('reports.team'));

CREATE OR REPLACE VIEW mcp_tokens WITH (security_barrier = true) AS
SELECT t.id AS key_id, t.member_id, t.label, t.scope, t.rate_per_minute, t.rate_per_day, t.lead_source_id, t.rotated_from, crm_key_calls_today(t.id) AS calls_today, t.created_at, t.last_used_at, t.expires_at, t.revoked_at
  FROM mcp_access_tokens t WHERE t.member_id = app_current_member_id() OR (SELECT crm_has_right('tokens.manage'));

-- a manager's view of what waits in the tombstones
CREATE OR REPLACE VIEW mcp_tombstones WITH (security_barrier = true) AS
SELECT 'lead' AS record_kind, l.id AS record_id, crm_lead_label(l) AS name, l.deleted_at, l.deleted_by, NULL::bigint AS merged_into_id FROM leads l WHERE l.deleted_at IS NOT NULL AND (SELECT crm_is_manager())
UNION ALL SELECT 'account', a.id, a.name, a.deleted_at, a.deleted_by, a.merged_into_id FROM accounts a WHERE a.deleted_at IS NOT NULL AND (SELECT crm_is_manager())
UNION ALL SELECT 'contact', c.id, c.name, c.deleted_at, c.deleted_by, c.merged_into_id FROM contacts c WHERE c.deleted_at IS NOT NULL AND (SELECT crm_is_manager())
UNION ALL SELECT 'deal', d.id, d.title, d.deleted_at, d.deleted_by, NULL FROM deals d WHERE d.deleted_at IS NOT NULL AND (SELECT crm_is_manager());

CREATE OR REPLACE VIEW mcp_activity_log WITH (security_barrier = true) AS
SELECT l.id AS log_id, l.occurred_at, l.actor_member_id, l.source, l.action, l.screen, l.entity_type, l.entity_id, l.after, l.request_id, l.agent_run_id, l.department_id, l.lead_id, l.account_id, l.contact_id, l.deal_id, l.token_id
  FROM activity_log l
 WHERE (SELECT crm_is_member_here())
   AND (l.deal_id IS NULL OR EXISTS (SELECT 1 FROM deals d WHERE d.id = l.deal_id AND crm_wall(d.owner_member_id, d.restricted_to_member_id, d.restricted_to_department_id)))
   AND (l.account_id IS NULL OR EXISTS (SELECT 1 FROM accounts a WHERE a.id = l.account_id AND crm_wall(a.owner_member_id, a.restricted_to_member_id, a.restricted_to_department_id)))
   AND (l.contact_id IS NULL OR EXISTS (SELECT 1 FROM contacts c WHERE c.id = l.contact_id AND crm_wall(c.owner_member_id, c.restricted_to_member_id, c.restricted_to_department_id)))
   AND (l.lead_id IS NULL OR EXISTS (SELECT 1 FROM leads x WHERE x.id = l.lead_id AND crm_wall(x.owner_member_id, x.restricted_to_member_id, x.restricted_to_department_id)));

GRANT SELECT ON mcp_members, mcp_departments, mcp_settings, mcp_lead_sources, mcp_lost_reasons, mcp_tags, mcp_custom_fields, mcp_pipelines, mcp_stages, mcp_leads, mcp_accounts, mcp_contacts,
    mcp_contact_emails, mcp_deals, mcp_deal_contacts, mcp_deal_products, mcp_deal_stage_history, mcp_activities, mcp_activity_participants, mcp_emails, mcp_email_templates, mcp_mailboxes,
    mcp_campaigns, mcp_campaign_members, mcp_lists, mcp_list_members, mcp_saved_views, mcp_imports, mcp_import_rows, mcp_exports, mcp_notes, mcp_attachments, mcp_notifications,
    mcp_notification_prefs, mcp_assistant_proposals, mcp_agent_dispatches, mcp_assignment_rules, mcp_quotas, mcp_tokens, mcp_tombstones, mcp_app_roles TO sales_crm_records_ro, sales_crm_rw;
GRANT SELECT ON mcp_activity_log, mcp_members, mcp_departments, mcp_leads, mcp_accounts, mcp_contacts, mcp_deals, mcp_activities TO sales_crm_activity_ro;

COMMIT;
