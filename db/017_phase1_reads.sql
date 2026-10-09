-- 017: the reads Phase 1's tool surface asked for (docs/sales-crm-mcp-tool-surface.md "What the schema lacked"): the calendar in one
-- call, a seller's agenda, the lost-reasons and products-pipeline reports, and THE FIVE SHARES for the kernel's token (K7) as
-- SECURITY DEFINER functions that take the consumer's key and answer as the application — no member identity, no bodies, no notes.
-- Additive.
BEGIN;

-- Q24/Q25 the calendar: activities in a window for me (who = NULL), one colleague (a member id) or a team (a department id with
-- p_team = true); every row judged by the wall of its record; private activities only to their seller and managers.
CREATE OR REPLACE FUNCTION crm_calendar(p_from timestamptz, p_to timestamptz, p_member bigint DEFAULT NULL, p_team bigint DEFAULT NULL, p_kinds text[] DEFAULT NULL, p_include_done boolean DEFAULT true)
    RETURNS TABLE (activity_id bigint, kind text, subject text, starts_at timestamptz, ends_at timestamptz, all_day boolean, owner_member_id bigint, owner_name text,
                   done boolean, state text, deal_id bigint, contact_id bigint, account_id bigint, lead_id bigint, record_name text, location text, priority text)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT a.id, a.kind, a.subject, COALESCE(a.starts_at, a.due_at), COALESCE(a.ends_at, COALESCE(a.starts_at, a.due_at)), a.all_day, a.owner_member_id, m.display_name,
           a.done_at IS NOT NULL, CASE WHEN a.done_at IS NOT NULL THEN 'done' ELSE crm_next_colour(COALESCE(a.starts_at, a.due_at)) END,
           a.deal_id, a.contact_id, a.account_id, a.lead_id,
           COALESCE((SELECT d.title FROM deals d WHERE d.id = a.deal_id), (SELECT c.name FROM contacts c WHERE c.id = a.contact_id), (SELECT x.name FROM accounts x WHERE x.id = a.account_id), (SELECT crm_lead_label(l) FROM leads l WHERE l.id = a.lead_id)),
           a.location, a.priority
      FROM activities a LEFT JOIN members m ON m.id = a.owner_member_id
     WHERE a.deleted_at IS NULL
       AND COALESCE(a.starts_at, a.due_at) >= p_from AND COALESCE(a.starts_at, a.due_at) < p_to
       AND (p_include_done OR a.done_at IS NULL)
       AND (p_kinds IS NULL OR a.kind = ANY (p_kinds))
       AND CASE WHEN p_team IS NOT NULL THEN crm_has_right('calendar.team') AND a.owner_member_id IN (SELECT dm.member_id FROM department_members dm WHERE dm.department_id = p_team AND dm.left_at IS NULL)
                WHEN p_member IS NOT NULL AND p_member <> app_current_member_id() THEN crm_has_right('calendar.read') AND a.owner_member_id = p_member
                ELSE a.owner_member_id = app_current_member_id() END
       AND (NOT a.is_private OR a.owner_member_id = app_current_member_id() OR crm_is_manager())
       AND (a.deal_id IS NULL OR EXISTS (SELECT 1 FROM deals d WHERE d.id = a.deal_id AND crm_wall(d.owner_member_id, d.restricted_to_member_id, d.restricted_to_department_id)))
       AND (a.account_id IS NULL OR EXISTS (SELECT 1 FROM accounts x WHERE x.id = a.account_id AND crm_wall(x.owner_member_id, x.restricted_to_member_id, x.restricted_to_department_id)))
     ORDER BY 4;
$$;

-- Q24 my agenda: overdue first, then today, then the next days; done today at the end.
CREATE OR REPLACE FUNCTION crm_my_activities(p_scope text DEFAULT 'today', p_member bigint DEFAULT NULL)
    RETURNS TABLE (activity_id bigint, kind text, subject text, at timestamptz, state text, deal_id bigint, contact_id bigint, account_id bigint, lead_id bigint, record_name text, priority text)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := COALESCE(p_member, app_current_member_id()); tz text := COALESCE((SELECT timezone FROM members WHERE id = me), crm_setting_text('timezone'));
        d0 timestamptz := date_trunc('day', now() AT TIME ZONE tz) AT TIME ZONE tz; d1 timestamptz; 
BEGIN
    IF me <> app_current_member_id() AND NOT crm_has_right('calendar.read') THEN RETURN; END IF;
    d1 := CASE p_scope WHEN 'week' THEN d0 + interval '7 days' WHEN 'overdue' THEN d0 ELSE d0 + interval '1 day' END;
    RETURN QUERY
    SELECT c.activity_id, c.kind, c.subject, c.starts_at, c.state, c.deal_id, c.contact_id, c.account_id, c.lead_id, c.record_name, c.priority
      FROM crm_calendar('-infinity'::timestamptz, d1, me, NULL, NULL, p_scope <> 'overdue') c
     WHERE (c.starts_at >= d0 OR NOT c.done) AND (p_scope <> 'overdue' OR c.starts_at < d0)
       AND (c.starts_at >= d0 - interval '90 days')
     ORDER BY c.done, c.starts_at;
END$$;

-- Q18 why we lost, by reason (and by seller when asked).
CREATE OR REPLACE FUNCTION crm_lost_reasons_summary(p_from date, p_to date, p_by_seller boolean DEFAULT false)
    RETURNS TABLE (lost_reason_id bigint, label text, member_id bigint, display_name text, deals bigint, lost_value numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT r.id, r.label, CASE WHEN p_by_seller THEN d.owner_member_id END, CASE WHEN p_by_seller THEN (SELECT display_name FROM members WHERE id = d.owner_member_id) END,
           count(d.id), COALESCE(sum(d.value) FILTER (WHERE d.currency = crm_setting_text('currency')), 0)
      FROM lost_reasons r LEFT JOIN deals d ON d.lost_reason_id = r.id AND d.status = 'lost' AND d.deleted_at IS NULL AND d.lost_at::date BETWEEN p_from AND p_to
           AND crm_wall(d.owner_member_id, d.restricted_to_member_id, d.restricted_to_department_id)
     WHERE r.kind = 'deal'
     GROUP BY r.id, 3, 4 ORDER BY 5 DESC, r.sort_order;
$$;

-- Q23 the products on open deals.
CREATE OR REPLACE FUNCTION crm_products_pipeline(p_pipeline bigint DEFAULT NULL)
    RETURNS TABLE (name text, provider_app text, provider_id text, deals bigint, quantity numeric, total numeric, weighted numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT p.name, p.provider_app, p.provider_id, count(DISTINCT d.id), sum(p.quantity), sum(p.line_total), sum(round(p.line_total * d.probability / 100.0, 2))
      FROM deal_products p JOIN deals d ON d.id = p.deal_id
     WHERE d.status = 'open' AND d.deleted_at IS NULL AND (p_pipeline IS NULL OR d.pipeline_id = p_pipeline)
       AND crm_wall(d.owner_member_id, d.restricted_to_member_id, d.restricted_to_department_id)
     GROUP BY 1, 2, 3 ORDER BY 6 DESC;
$$;

-- Q4 the pairs a manager dismissed are not shown again.
CREATE TABLE duplicate_dismissals (
    record_kind text NOT NULL CHECK (record_kind IN ('lead', 'account', 'contact')),
    a_id        bigint NOT NULL,
    b_id        bigint NOT NULL,
    dismissed_by bigint REFERENCES members(id) ON DELETE SET NULL,
    dismissed_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (record_kind, a_id, b_id),
    CHECK (a_id < b_id)
);
GRANT SELECT, INSERT, DELETE ON duplicate_dismissals TO sales_crm_rw;
CREATE OR REPLACE FUNCTION crm_duplicates_open(p_kind text, p_limit integer DEFAULT 200)
    RETURNS TABLE (a_id bigint, b_id bigint, matched_on text, similarity real)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT x.a_id, x.b_id, x.matched_on, x.similarity FROM crm_duplicates(p_kind, p_limit * 2) x
     WHERE NOT EXISTS (SELECT 1 FROM duplicate_dismissals dd WHERE dd.record_kind = p_kind AND dd.a_id = LEAST(x.a_id, x.b_id) AND dd.b_id = GREATEST(x.a_id, x.b_id))
       AND crm_has_right('records.read')
     LIMIT p_limit;
$$;

-- ---------------------------------------------------------------------------------------------
-- THE FIVE SHARES (K7, design §8, D13): called by the records server under the kernel's token with the consumer's key from
-- X-OS-Consumer (K26). SECURITY DEFINER: they read as the application, never as a member — restricted records are NOT shared
-- (a restricted account is the business's secret, not the estate's); no body, no note ever crosses.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION crm_share_accounts_index(p_consumer text, p_since timestamptz DEFAULT NULL, p_q text DEFAULT NULL, p_limit integer DEFAULT 200, p_offset integer DEFAULT 0)
    RETURNS TABLE (account_id bigint, name text, legal_name text, domain text, kind text, owner_member_id bigint, owner_name text, gl_customer_id bigint, helpdesk_organization_id bigint,
                   country text, city text, open_deals_count integer, open_deals_value numeric, won_deals_value numeric, currency text, updated_at timestamptz, total bigint)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT a.id, a.name, a.legal_name, a.domain::text, a.kind, a.owner_member_id, (SELECT display_name FROM members WHERE id = a.owner_member_id), a.gl_customer_id, a.helpdesk_organization_id,
           a.country::text, a.city, a.open_deals_count, a.open_deals_value, a.won_deals_value, COALESCE(a.currency, crm_setting_text('currency'))::text, a.updated_at, count(*) OVER ()
      FROM accounts a
     WHERE a.deleted_at IS NULL AND a.merged_into_id IS NULL AND a.restricted_to_member_id IS NULL AND a.restricted_to_department_id IS NULL
       AND (p_since IS NULL OR a.updated_at > p_since) AND (p_q IS NULL OR a.name ILIKE '%' || p_q || '%' OR a.domain::text ILIKE '%' || p_q || '%')
     ORDER BY a.updated_at DESC, a.id LIMIT LEAST(p_limit, 500) OFFSET GREATEST(p_offset, 0);
$$;

CREATE OR REPLACE FUNCTION crm_share_contacts_index(p_consumer text, p_account bigint DEFAULT NULL, p_email text DEFAULT NULL, p_since timestamptz DEFAULT NULL, p_limit integer DEFAULT 200, p_offset integer DEFAULT 0)
    RETURNS TABLE (contact_id bigint, account_id bigint, account_name text, name text, first_name text, last_name text, title text, role text, emails text[], phone text, mobile text,
                   lifecycle text, email_opt_out boolean, sms_opt_out boolean, owner_member_id bigint, updated_at timestamptz, total bigint)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT c.id, c.account_id, (SELECT name FROM accounts WHERE id = c.account_id), c.name, c.first_name, c.last_name, c.title, c.role,
           ARRAY(SELECT e.address::text FROM contact_emails e WHERE e.contact_id = c.id ORDER BY e.is_primary DESC, e.id) || CASE WHEN c.email_alt IS NOT NULL THEN ARRAY[c.email_alt::text] ELSE '{}' END,
           c.phone, c.mobile, c.lifecycle, c.email_opt_out, c.sms_opt_out, c.owner_member_id, c.updated_at, count(*) OVER ()
      FROM contacts c
     WHERE c.deleted_at IS NULL AND c.merged_into_id IS NULL AND c.restricted_to_member_id IS NULL AND c.restricted_to_department_id IS NULL
       AND (c.account_id IS NULL OR NOT EXISTS (SELECT 1 FROM accounts a WHERE a.id = c.account_id AND (a.restricted_to_member_id IS NOT NULL OR a.restricted_to_department_id IS NOT NULL)))
       AND (p_account IS NULL OR c.account_id = p_account)
       AND (p_email IS NULL OR c.id = crm_contact_by_address(p_email::citext))
       AND (p_since IS NULL OR c.updated_at > p_since)
     ORDER BY c.updated_at DESC, c.id LIMIT LEAST(p_limit, 500) OFFSET GREATEST(p_offset, 0);
$$;

CREATE OR REPLACE FUNCTION crm_share_deals_won(p_consumer text, p_from date, p_to date, p_limit integer DEFAULT 200, p_offset integer DEFAULT 0)
    RETURNS TABLE (deal_id bigint, number text, title text, account_id bigint, account_name text, account_legal_name text, gl_customer_id bigint, primary_contact_id bigint, primary_contact_name text, primary_contact_email text,
                   value numeric, currency text, won_at timestamptz, owner_member_id bigint, owner_name text, pipeline_key text, products jsonb, total bigint)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT d.id, d.number, d.title, d.account_id, a.name, a.legal_name, a.gl_customer_id, d.primary_contact_id, c.name, c.email::text,
           d.value, d.currency::text, d.won_at, d.owner_member_id, (SELECT display_name FROM members WHERE id = d.owner_member_id), p.key,
           COALESCE((SELECT jsonb_agg(jsonb_build_object('name', dp.name, 'provider_app', dp.provider_app, 'provider_id', dp.provider_id, 'sku', dp.sku, 'quantity', dp.quantity, 'unit_price', dp.unit_price,
                                                         'discount_percent', dp.discount_percent, 'line_total', dp.line_total, 'recurring', dp.recurring) ORDER BY dp.position) FROM deal_products dp WHERE dp.deal_id = d.id), '[]'::jsonb),
           count(*) OVER ()
      FROM deals d JOIN pipelines p ON p.id = d.pipeline_id LEFT JOIN accounts a ON a.id = d.account_id LEFT JOIN contacts c ON c.id = d.primary_contact_id
     WHERE d.status = 'won' AND d.deleted_at IS NULL AND d.won_at::date BETWEEN p_from AND p_to
       AND d.restricted_to_member_id IS NULL AND d.restricted_to_department_id IS NULL
       AND (a.id IS NULL OR (a.restricted_to_member_id IS NULL AND a.restricted_to_department_id IS NULL))
     ORDER BY d.won_at, d.id LIMIT LEAST(p_limit, 500) OFFSET GREATEST(p_offset, 0);
$$;

CREATE OR REPLACE FUNCTION crm_share_deals_open_by_account(p_consumer text, p_account bigint)
    RETURNS TABLE (deal_id bigint, number text, title text, stage_name text, probability smallint, value numeric, weighted_value numeric, currency text, expected_close_date date,
                   owner_member_id bigint, owner_name text, next_activity_at timestamptz, next_colour text, stage_entered_at timestamptz)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT d.id, d.number, d.title, s.name, d.probability, d.value, d.weighted_value, d.currency::text, d.expected_close_date, d.owner_member_id,
           (SELECT display_name FROM members WHERE id = d.owner_member_id), d.next_activity_at, crm_next_colour(d.next_activity_at), d.stage_entered_at
      FROM deals d JOIN stages s ON s.id = d.stage_id JOIN accounts a ON a.id = d.account_id
     WHERE d.account_id = p_account AND d.status = 'open' AND d.deleted_at IS NULL
       AND d.restricted_to_member_id IS NULL AND d.restricted_to_department_id IS NULL AND a.restricted_to_member_id IS NULL AND a.restricted_to_department_id IS NULL AND a.deleted_at IS NULL
     ORDER BY d.expected_close_date NULLS LAST, d.id;
$$;

CREATE OR REPLACE FUNCTION crm_share_account_timeline_summary(p_consumer text, p_account bigint, p_limit integer DEFAULT 10)
    RETURNS TABLE (at timestamptz, kind text, subject text, who text, direction text, deal_number text, is_next_step boolean)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT * FROM (
        SELECT COALESCE(a.done_at, a.starts_at, a.due_at), a.kind, a.subject, (SELECT display_name FROM members WHERE id = COALESCE(a.done_by, a.owner_member_id)), a.email_direction,
               (SELECT number FROM deals WHERE id = a.deal_id), false
          FROM activities a JOIN accounts x ON x.id = a.account_id
         WHERE a.account_id = p_account AND a.deleted_at IS NULL AND a.done_at IS NOT NULL AND NOT a.is_private
           AND x.deleted_at IS NULL AND x.restricted_to_member_id IS NULL AND x.restricted_to_department_id IS NULL
         ORDER BY 1 DESC LIMIT LEAST(p_limit, 50)) past
    UNION ALL
    SELECT COALESCE(a.starts_at, a.due_at), a.kind, a.subject, (SELECT display_name FROM members WHERE id = a.owner_member_id), NULL, (SELECT number FROM deals WHERE id = a.deal_id), true
      FROM activities a JOIN accounts x ON x.id = a.account_id
     WHERE a.account_id = p_account AND a.deleted_at IS NULL AND a.done_at IS NULL AND NOT a.is_private
       AND x.deleted_at IS NULL AND x.restricted_to_member_id IS NULL AND x.restricted_to_department_id IS NULL
     ORDER BY 1 DESC LIMIT LEAST(p_limit, 50) + 1;
$$;

-- every share call is logged by the server as share.read (source application); the functions themselves write nothing
GRANT EXECUTE ON FUNCTION crm_calendar(timestamptz, timestamptz, bigint, bigint, text[], boolean), crm_my_activities(text, bigint), crm_lost_reasons_summary(date, date, boolean), crm_products_pipeline(bigint),
    crm_duplicates_open(text, integer) TO sales_crm_rw, sales_crm_records_ro;
REVOKE ALL ON FUNCTION crm_share_accounts_index(text, timestamptz, text, integer, integer), crm_share_contacts_index(text, bigint, text, timestamptz, integer, integer), crm_share_deals_won(text, date, date, integer, integer),
    crm_share_deals_open_by_account(text, bigint), crm_share_account_timeline_summary(text, bigint, integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION crm_share_accounts_index(text, timestamptz, text, integer, integer), crm_share_contacts_index(text, bigint, text, timestamptz, integer, integer), crm_share_deals_won(text, date, date, integer, integer),
    crm_share_deals_open_by_account(text, bigint), crm_share_account_timeline_summary(text, bigint, integer) TO sales_crm_records_ro, sales_crm_rw;

COMMIT;
