-- 015: the READS the questions of design §7 need and a screen or a tool answers in one call — the pipeline summary, the forecast, the
-- funnel, win rate, cycle time, the deals needing a hand, who we have not spoken to, the activity summary, the leaderboard, the
-- sources' and campaigns' results, a record's timeline. Every read is STABLE, judges the wall per row, and never returns a body.
BEGIN;

-- Q14 the pipeline by stage: count, value, weighted (base currency; other currencies counted apart in `other_currency_count`).
CREATE OR REPLACE FUNCTION crm_pipeline_summary(p_pipeline bigint, p_owner bigint DEFAULT NULL, p_department bigint DEFAULT NULL)
    RETURNS TABLE (stage_id bigint, stage_name text, stage_position integer, probability smallint, kind text, colour text, wip_limit integer,
                   deals bigint, value numeric, weighted numeric, rotting bigint, no_next_step bigint, other_currency_count bigint)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT s.id, s.name, s.position, s.probability, s.kind, s.colour, s.wip_limit,
           count(d.id), COALESCE(sum(d.value) FILTER (WHERE d.currency = crm_setting_text('currency')), 0), COALESCE(sum(d.weighted_value) FILTER (WHERE d.currency = crm_setting_text('currency')), 0),
           count(d.id) FILTER (WHERE crm_deal_is_rotting(d.status, d.stage_id, d.stage_entered_at)),
           count(d.id) FILTER (WHERE d.status = 'open' AND d.next_activity_at IS NULL),
           count(d.id) FILTER (WHERE d.currency <> crm_setting_text('currency'))
      FROM stages s
      LEFT JOIN deals d ON d.stage_id = s.id AND d.deleted_at IS NULL
           AND (s.kind = 'open' OR COALESCE(d.won_at, d.lost_at) >= date_trunc('month', now()))
           AND (p_owner IS NULL OR d.owner_member_id = p_owner)
           AND (p_department IS NULL OR d.owner_member_id IN (SELECT member_id FROM department_members WHERE department_id = p_department AND left_at IS NULL))
           AND crm_wall(d.owner_member_id, d.restricted_to_member_id, d.restricted_to_department_id)
     WHERE s.pipeline_id = p_pipeline AND s.archived_at IS NULL
     GROUP BY s.id ORDER BY s.position;
$$;

-- Q15 the forecast: by month of expected close for the next N months, by seller (or by stage): open value, weighted, won so far, quota.
CREATE OR REPLACE FUNCTION crm_forecast(p_pipeline bigint DEFAULT NULL, p_months integer DEFAULT NULL, p_by text DEFAULT 'seller', p_department bigint DEFAULT NULL)
    RETURNS TABLE (month date, row_id bigint, row_name text, open_value numeric, weighted numeric, won_value numeric, deals bigint, quota numeric)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer := COALESCE(p_months, crm_setting_int('forecast_months')::integer); cur text := crm_setting_text('currency');
BEGIN
    IF p_by = 'stage' THEN
        RETURN QUERY
        SELECT m.month::date, s.id, s.name,
               COALESCE(sum(d.value) FILTER (WHERE d.status = 'open'), 0), COALESCE(sum(d.weighted_value) FILTER (WHERE d.status = 'open'), 0),
               COALESCE(sum(d.value) FILTER (WHERE d.status = 'won'), 0), count(d.id), NULL::numeric
          FROM generate_series(date_trunc('month', now()), date_trunc('month', now()) + make_interval(months => n - 1), interval '1 month') m(month)
          CROSS JOIN stages s
          LEFT JOIN deals d ON d.stage_id = s.id AND d.deleted_at IS NULL AND d.currency = cur AND d.status <> 'lost'
               AND date_trunc('month', COALESCE(d.won_at::date, d.expected_close_date)) = m.month::date
               AND (p_department IS NULL OR d.owner_member_id IN (SELECT member_id FROM department_members WHERE department_id = p_department AND left_at IS NULL))
               AND crm_wall(d.owner_member_id, d.restricted_to_member_id, d.restricted_to_department_id)
         WHERE (p_pipeline IS NULL OR s.pipeline_id = p_pipeline) AND s.kind <> 'lost' AND s.archived_at IS NULL
         GROUP BY m.month, s.id ORDER BY m.month, s.position;
    ELSE
        RETURN QUERY
        SELECT m.month::date, mem.id, mem.display_name,
               COALESCE(sum(d.value) FILTER (WHERE d.status = 'open'), 0), COALESCE(sum(d.weighted_value) FILTER (WHERE d.status = 'open'), 0),
               COALESCE(sum(d.value) FILTER (WHERE d.status = 'won'), 0), count(d.id),
               (SELECT q.amount FROM quotas q WHERE q.member_id = mem.id AND q.month = m.month::date)
          FROM generate_series(date_trunc('month', now()), date_trunc('month', now()) + make_interval(months => n - 1), interval '1 month') m(month)
          CROSS JOIN members mem
          LEFT JOIN deals d ON d.owner_member_id = mem.id AND d.deleted_at IS NULL AND d.currency = cur AND d.status <> 'lost'
               AND (p_pipeline IS NULL OR d.pipeline_id = p_pipeline)
               AND date_trunc('month', COALESCE(d.won_at::date, d.expected_close_date)) = m.month::date
               AND crm_wall(d.owner_member_id, d.restricted_to_member_id, d.restricted_to_department_id)
         WHERE mem.status = 'active' AND mem.capability IS NOT NULL AND 'user' = ANY (crm_member_roles(mem.id))
           AND (p_department IS NULL OR mem.id IN (SELECT member_id FROM department_members WHERE department_id = p_department AND left_at IS NULL))
         GROUP BY m.month, mem.id ORDER BY m.month, mem.display_name;
    END IF;
END$$;

-- Q20 the funnel from deal_stage_history: deals that entered each stage in the period, how many went on, how long they sat.
CREATE OR REPLACE FUNCTION crm_funnel(p_pipeline bigint, p_from date, p_to date, p_owner bigint DEFAULT NULL)
    RETURNS TABLE (stage_id bigint, stage_name text, stage_position integer, entered bigint, moved_on bigint, lost_from bigint, still_here bigint, median_days numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH h AS (
        SELECT h.deal_id, h.to_stage_id AS stage_id, h.at,
               lead(h.to_stage_id) OVER (PARTITION BY h.deal_id ORDER BY h.at) AS next_stage,
               lead(h.at) OVER (PARTITION BY h.deal_id ORDER BY h.at) AS next_at
          FROM deal_stage_history h JOIN deals d ON d.id = h.deal_id
         WHERE d.pipeline_id = p_pipeline AND d.deleted_at IS NULL AND (p_owner IS NULL OR d.owner_member_id = p_owner)
           AND crm_wall(d.owner_member_id, d.restricted_to_member_id, d.restricted_to_department_id)
    )
    SELECT s.id, s.name, s.position,
           count(h.deal_id) FILTER (WHERE h.at::date BETWEEN p_from AND p_to),
           count(h.deal_id) FILTER (WHERE h.at::date BETWEEN p_from AND p_to AND h.next_stage IS NOT NULL AND (SELECT kind FROM stages x WHERE x.id = h.next_stage) <> 'lost'),
           count(h.deal_id) FILTER (WHERE h.at::date BETWEEN p_from AND p_to AND (SELECT kind FROM stages x WHERE x.id = h.next_stage) = 'lost'),
           count(h.deal_id) FILTER (WHERE h.at::date BETWEEN p_from AND p_to AND h.next_stage IS NULL),
           round((percentile_cont(0.5) WITHIN GROUP (ORDER BY extract(epoch FROM (COALESCE(h.next_at, now()) - h.at))) / 86400)::numeric, 1)
      FROM stages s LEFT JOIN h ON h.stage_id = s.id
     WHERE s.pipeline_id = p_pipeline AND s.kind = 'open' AND s.archived_at IS NULL
     GROUP BY s.id ORDER BY s.position;
$$;

-- Q19 win rate and cycle time by seller, source or pipeline over closed deals in a period.
CREATE OR REPLACE FUNCTION crm_win_rate(p_from date, p_to date, p_by text DEFAULT 'seller')
    RETURNS TABLE (row_id bigint, row_name text, won bigint, lost bigint, win_rate numeric, won_value numeric, avg_cycle_days numeric, median_cycle_days numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT CASE p_by WHEN 'source' THEN d.source_id WHEN 'pipeline' THEN d.pipeline_id ELSE d.owner_member_id END,
           CASE p_by WHEN 'source' THEN (SELECT name FROM lead_sources WHERE id = d.source_id) WHEN 'pipeline' THEN (SELECT name FROM pipelines WHERE id = d.pipeline_id) ELSE (SELECT display_name FROM members WHERE id = d.owner_member_id) END,
           count(*) FILTER (WHERE d.status = 'won'), count(*) FILTER (WHERE d.status = 'lost'),
           round(100.0 * count(*) FILTER (WHERE d.status = 'won') / NULLIF(count(*), 0), 1),
           COALESCE(sum(d.value) FILTER (WHERE d.status = 'won' AND d.currency = crm_setting_text('currency')), 0),
           round(avg(extract(epoch FROM (d.won_at - d.created_at)) / 86400) FILTER (WHERE d.status = 'won')::numeric, 1),
           round((percentile_cont(0.5) WITHIN GROUP (ORDER BY extract(epoch FROM (d.won_at - d.created_at)) / 86400) FILTER (WHERE d.status = 'won'))::numeric, 1)
      FROM deals d
     WHERE d.deleted_at IS NULL AND d.status IN ('won', 'lost') AND COALESCE(d.won_at, d.lost_at)::date BETWEEN p_from AND p_to
       AND crm_wall(d.owner_member_id, d.restricted_to_member_id, d.restricted_to_department_id)
     GROUP BY 1, 2 ORDER BY 2;
$$;

-- Q16 the deals needing a hand: no next step, rotting, closing within N days without a touch in M days.
CREATE OR REPLACE FUNCTION crm_deals_at_risk(p_owner bigint DEFAULT NULL, p_closing_days integer DEFAULT 14, p_untouched_days integer DEFAULT 7)
    RETURNS TABLE (deal_id bigint, number text, title text, account_name text, owner_member_id bigint, stage_name text, value numeric, expected_close_date date,
                   reason text, days integer)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT d.id, d.number, d.title, a.name, d.owner_member_id, s.name, d.value, d.expected_close_date, r.reason, r.days
      FROM deals d JOIN stages s ON s.id = d.stage_id LEFT JOIN accounts a ON a.id = d.account_id
      CROSS JOIN LATERAL (
          SELECT 'no_next_step'::text AS reason, extract(day FROM now() - d.stage_entered_at)::integer AS days WHERE d.next_activity_at IS NULL
          UNION ALL SELECT 'rotting', extract(day FROM now() - d.stage_entered_at)::integer WHERE crm_deal_is_rotting(d.status, d.stage_id, d.stage_entered_at)
          UNION ALL SELECT 'closing_untouched', (d.expected_close_date - current_date) WHERE d.expected_close_date <= current_date + p_closing_days AND COALESCE(d.last_touched_at, d.created_at) < now() - make_interval(days => p_untouched_days)
          UNION ALL SELECT 'overdue_close', (current_date - d.expected_close_date) WHERE d.expected_close_date < current_date
      ) r
     WHERE d.status = 'open' AND d.deleted_at IS NULL AND (p_owner IS NULL OR d.owner_member_id = p_owner)
       AND crm_wall(d.owner_member_id, d.restricted_to_member_id, d.restricted_to_department_id)
     ORDER BY d.owner_member_id, r.reason, d.expected_close_date NULLS LAST;
$$;

-- Q10 accounts and contacts not touched in N days (customers first).
CREATE OR REPLACE FUNCTION crm_untouched(p_kind text, p_days integer DEFAULT NULL, p_owner bigint DEFAULT NULL)
    RETURNS TABLE (record_id bigint, name text, kind text, owner_member_id bigint, last_touched_at timestamptz, days integer)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer := COALESCE(p_days, crm_setting_int('account_untouched_days')::integer);
BEGIN
    IF p_kind = 'account' THEN
        RETURN QUERY SELECT a.id, a.name, a.kind, a.owner_member_id, a.last_touched_at, extract(day FROM now() - COALESCE(a.last_touched_at, a.created_at))::integer
          FROM accounts a WHERE a.deleted_at IS NULL AND a.merged_into_id IS NULL AND COALESCE(a.last_touched_at, a.created_at) < now() - make_interval(days => n)
           AND (p_owner IS NULL OR a.owner_member_id = p_owner) AND crm_wall(a.owner_member_id, a.restricted_to_member_id, a.restricted_to_department_id)
         ORDER BY (a.kind = 'customer') DESC, a.last_touched_at NULLS FIRST;
    ELSE
        RETURN QUERY SELECT c.id, c.name, c.lifecycle, c.owner_member_id, c.last_touched_at, extract(day FROM now() - COALESCE(c.last_touched_at, c.created_at))::integer
          FROM contacts c WHERE c.deleted_at IS NULL AND c.merged_into_id IS NULL AND COALESCE(c.last_touched_at, c.created_at) < now() - make_interval(days => n)
           AND (p_owner IS NULL OR c.owner_member_id = p_owner) AND crm_wall(c.owner_member_id, c.restricted_to_member_id, c.restricted_to_department_id)
         ORDER BY (c.lifecycle = 'customer') DESC, c.last_touched_at NULLS FIRST;
    END IF;
END$$;

-- Q1 leads untouched for N days (new or working), for the morning note.
CREATE OR REPLACE FUNCTION crm_leads_untouched(p_days integer DEFAULT NULL, p_owner bigint DEFAULT NULL)
    RETURNS TABLE (lead_id bigint, label text, status text, owner_member_id bigint, source text, days integer)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT l.id, crm_lead_label(l), l.status, l.owner_member_id, (SELECT name FROM lead_sources WHERE id = l.source_id),
           extract(day FROM now() - COALESCE(l.last_touched_at, l.created_at))::integer
      FROM leads l WHERE l.deleted_at IS NULL AND l.status IN ('new', 'working') AND NOT l.is_proposed
       AND COALESCE(l.last_touched_at, l.created_at) < now() - make_interval(days => COALESCE(p_days, crm_setting_int('lead_untouched_days')::integer))
       AND (p_owner IS NULL OR l.owner_member_id = p_owner) AND crm_wall(l.owner_member_id, l.restricted_to_member_id, l.restricted_to_department_id)
     ORDER BY l.created_at;
$$;

-- Q13 birthdays and renewals (a deal in a renewals pipeline closing) in the next N days.
CREATE OR REPLACE FUNCTION crm_upcoming_dates(p_days integer DEFAULT 30, p_owner bigint DEFAULT NULL)
    RETURNS TABLE (kind text, record_kind text, record_id bigint, name text, on_date date, owner_member_id bigint)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT 'birthday', 'contact', c.id, c.name, bd.d, c.owner_member_id
      FROM contacts c CROSS JOIN LATERAL (SELECT make_date(extract(year FROM current_date)::int + CASE WHEN to_char(c.birthday, 'MMDD') < to_char(current_date, 'MMDD') THEN 1 ELSE 0 END, extract(month FROM c.birthday)::int, LEAST(extract(day FROM c.birthday)::int, 28)) AS d) bd
     WHERE c.birthday IS NOT NULL AND c.deleted_at IS NULL AND bd.d BETWEEN current_date AND current_date + p_days
       AND (p_owner IS NULL OR c.owner_member_id = p_owner) AND crm_wall(c.owner_member_id, c.restricted_to_member_id, c.restricted_to_department_id)
    UNION ALL
    SELECT 'renewal', 'deal', d.id, d.title, d.expected_close_date, d.owner_member_id
      FROM deals d JOIN pipelines p ON p.id = d.pipeline_id
     WHERE p.key = 'renewals' AND d.status = 'open' AND d.deleted_at IS NULL AND d.expected_close_date BETWEEN current_date AND current_date + p_days
       AND (p_owner IS NULL OR d.owner_member_id = p_owner) AND crm_wall(d.owner_member_id, d.restricted_to_member_id, d.restricted_to_department_id)
    ORDER BY 5;
$$;

-- Q26 activities logged per seller in a period, by kind and outcome.
CREATE OR REPLACE FUNCTION crm_activity_summary(p_from date, p_to date, p_department bigint DEFAULT NULL)
    RETURNS TABLE (member_id bigint, display_name text, kind text, done bigint, scheduled bigint, outcomes jsonb)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT m.id, m.display_name, a.kind,
           count(*) FILTER (WHERE a.done_at IS NOT NULL), count(*) FILTER (WHERE a.done_at IS NULL),
           COALESCE(jsonb_object_agg(o.outcome, o.n) FILTER (WHERE o.outcome IS NOT NULL), '{}'::jsonb)
      FROM activities a JOIN members m ON m.id = a.owner_member_id
      LEFT JOIN LATERAL (SELECT x.outcome, count(*) AS n FROM activities x WHERE x.owner_member_id = a.owner_member_id AND x.kind = a.kind AND x.done_at::date BETWEEN p_from AND p_to AND x.deleted_at IS NULL AND x.outcome IS NOT NULL GROUP BY x.outcome) o ON true
     WHERE a.deleted_at IS NULL AND COALESCE(a.done_at, a.starts_at, a.due_at)::date BETWEEN p_from AND p_to
       AND (p_department IS NULL OR m.id IN (SELECT member_id FROM department_members WHERE department_id = p_department AND left_at IS NULL))
       AND (crm_is_manager() OR a.owner_member_id = app_current_member_id())
     GROUP BY m.id, a.kind ORDER BY m.display_name, a.kind;
$$;

-- Q21 the leaderboard: won this month against quota, open weighted, activities done.
CREATE OR REPLACE FUNCTION crm_leaderboard(p_month date DEFAULT date_trunc('month', now())::date, p_department bigint DEFAULT NULL)
    RETURNS TABLE (member_id bigint, display_name text, won_value numeric, won_count bigint, quota numeric, attainment numeric, open_weighted numeric, activities_done bigint)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT m.id, m.display_name,
           COALESCE((SELECT sum(d.value) FROM deals d WHERE d.owner_member_id = m.id AND d.status = 'won' AND d.deleted_at IS NULL AND d.currency = crm_setting_text('currency') AND date_trunc('month', d.won_at)::date = p_month), 0),
           (SELECT count(*) FROM deals d WHERE d.owner_member_id = m.id AND d.status = 'won' AND d.deleted_at IS NULL AND date_trunc('month', d.won_at)::date = p_month),
           (SELECT amount FROM quotas q WHERE q.member_id = m.id AND q.month = p_month),
           round(100.0 * COALESCE((SELECT sum(d.value) FROM deals d WHERE d.owner_member_id = m.id AND d.status = 'won' AND d.deleted_at IS NULL AND d.currency = crm_setting_text('currency') AND date_trunc('month', d.won_at)::date = p_month), 0)
                 / NULLIF((SELECT amount FROM quotas q WHERE q.member_id = m.id AND q.month = p_month), 0), 1),
           COALESCE((SELECT sum(d.weighted_value) FROM deals d WHERE d.owner_member_id = m.id AND d.status = 'open' AND d.deleted_at IS NULL AND d.currency = crm_setting_text('currency')), 0),
           (SELECT count(*) FROM activities a WHERE a.owner_member_id = m.id AND a.deleted_at IS NULL AND date_trunc('month', a.done_at)::date = p_month)
      FROM members m
     WHERE m.status = 'active' AND m.capability IS NOT NULL AND 'user' = ANY (crm_member_roles(m.id)) AND m.member_kind = 'human'
       AND (p_department IS NULL OR m.id IN (SELECT member_id FROM department_members WHERE department_id = p_department AND left_at IS NULL))
       AND crm_has_right('reports.team')
     ORDER BY 3 DESC;
$$;

-- Q3 sources and campaigns: leads, converted, deals, won value.
CREATE OR REPLACE FUNCTION crm_source_performance(p_from date, p_to date)
    RETURNS TABLE (source_id bigint, source_name text, leads bigint, converted bigint, unqualified bigint, deals bigint, won bigint, won_value numeric, conversion_rate numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT s.id, s.name,
           (SELECT count(*) FROM leads l WHERE l.source_id = s.id AND l.deleted_at IS NULL AND l.created_at::date BETWEEN p_from AND p_to),
           (SELECT count(*) FROM leads l WHERE l.source_id = s.id AND l.deleted_at IS NULL AND l.created_at::date BETWEEN p_from AND p_to AND l.status = 'qualified'),
           (SELECT count(*) FROM leads l WHERE l.source_id = s.id AND l.deleted_at IS NULL AND l.created_at::date BETWEEN p_from AND p_to AND l.status = 'unqualified'),
           (SELECT count(*) FROM deals d WHERE d.source_id = s.id AND d.deleted_at IS NULL AND d.created_at::date BETWEEN p_from AND p_to),
           (SELECT count(*) FROM deals d WHERE d.source_id = s.id AND d.deleted_at IS NULL AND d.status = 'won' AND d.won_at::date BETWEEN p_from AND p_to),
           COALESCE((SELECT sum(d.value) FROM deals d WHERE d.source_id = s.id AND d.deleted_at IS NULL AND d.status = 'won' AND d.won_at::date BETWEEN p_from AND p_to AND d.currency = crm_setting_text('currency')), 0),
           round(100.0 * (SELECT count(*) FROM leads l WHERE l.source_id = s.id AND l.deleted_at IS NULL AND l.created_at::date BETWEEN p_from AND p_to AND l.status = 'qualified')
                 / NULLIF((SELECT count(*) FROM leads l WHERE l.source_id = s.id AND l.deleted_at IS NULL AND l.created_at::date BETWEEN p_from AND p_to), 0), 1)
      FROM lead_sources s WHERE crm_has_right('records.read') ORDER BY s.sort_order;
$$;

CREATE OR REPLACE FUNCTION crm_campaign_results(p_campaign bigint)
    RETURNS TABLE (members bigint, responded bigint, leads bigint, converted bigint, deals bigint, won bigint, won_value numeric, open_value numeric, cost_per_lead numeric, cost_per_won numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT (SELECT count(*) FROM campaign_members cm WHERE cm.campaign_id = c.id),
           (SELECT count(*) FROM campaign_members cm WHERE cm.campaign_id = c.id AND cm.status IN ('responded', 'attended', 'converted')),
           (SELECT count(*) FROM leads l WHERE l.campaign_id = c.id AND l.deleted_at IS NULL),
           (SELECT count(*) FROM leads l WHERE l.campaign_id = c.id AND l.deleted_at IS NULL AND l.status = 'qualified'),
           (SELECT count(*) FROM deals d WHERE d.campaign_id = c.id AND d.deleted_at IS NULL),
           (SELECT count(*) FROM deals d WHERE d.campaign_id = c.id AND d.deleted_at IS NULL AND d.status = 'won'),
           COALESCE((SELECT sum(d.value) FROM deals d WHERE d.campaign_id = c.id AND d.deleted_at IS NULL AND d.status = 'won'), 0),
           COALESCE((SELECT sum(d.value) FROM deals d WHERE d.campaign_id = c.id AND d.deleted_at IS NULL AND d.status = 'open'), 0),
           round(c.budget / NULLIF((SELECT count(*) FROM leads l WHERE l.campaign_id = c.id AND l.deleted_at IS NULL), 0), 2),
           round(c.budget / NULLIF((SELECT count(*) FROM deals d WHERE d.campaign_id = c.id AND d.deleted_at IS NULL AND d.status = 'won'), 0), 2)
      FROM campaigns c WHERE c.id = p_campaign AND crm_has_right('records.read');
$$;

-- Q27 THE TIMELINE of one record: activities, emails (subject and direction, never the body), notes (first 200 characters), stage moves
-- and the activity_log rows naming it — one list, newest first. Private emails only to their seller and managers.
CREATE OR REPLACE FUNCTION crm_timeline(p_kind text, p_id bigint, p_limit integer DEFAULT 100, p_before timestamptz DEFAULT NULL)
    RETURNS TABLE (at timestamptz, item_kind text, item_id bigint, title text, detail text, actor_member_id bigint, deal_id bigint, contact_id bigint)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE acc bigint; con bigint; dl bigint; ld bigint; ok boolean;
BEGIN
    IF p_kind = 'account' THEN acc := p_id; SELECT crm_wall(owner_member_id, restricted_to_member_id, restricted_to_department_id) INTO ok FROM accounts WHERE id = p_id;
    ELSIF p_kind = 'contact' THEN con := p_id; SELECT crm_wall(owner_member_id, restricted_to_member_id, restricted_to_department_id) INTO ok FROM contacts WHERE id = p_id;
    ELSIF p_kind = 'deal' THEN dl := p_id; SELECT crm_wall(owner_member_id, restricted_to_member_id, restricted_to_department_id) INTO ok FROM deals WHERE id = p_id;
    ELSIF p_kind = 'lead' THEN ld := p_id; SELECT crm_wall(owner_member_id, restricted_to_member_id, restricted_to_department_id) INTO ok FROM leads WHERE id = p_id;
    ELSE RETURN; END IF;
    IF NOT COALESCE(ok, false) THEN RETURN; END IF;
    RETURN QUERY
    SELECT * FROM (
        SELECT COALESCE(a.done_at, a.starts_at, a.due_at) AS at, 'activity.' || a.kind AS item_kind, a.id, a.subject,
               CASE WHEN a.done_at IS NOT NULL THEN COALESCE(a.outcome, 'done') ELSE 'scheduled' END || COALESCE(' · ' || a.email_direction, ''), COALESCE(a.done_by, a.owner_member_id), a.deal_id, a.contact_id
          FROM activities a
         WHERE a.deleted_at IS NULL AND ((acc IS NOT NULL AND a.account_id = acc) OR (con IS NOT NULL AND a.contact_id = con) OR (dl IS NOT NULL AND a.deal_id = dl) OR (ld IS NOT NULL AND a.lead_id = ld))
           AND (NOT a.is_private OR a.owner_member_id = app_current_member_id() OR a.created_by = app_current_member_id() OR crm_is_manager())
        UNION ALL
        SELECT n.created_at, 'note', n.id, left(n.body, 200), NULL, n.member_id, CASE WHEN n.record_type = 'deal' THEN n.record_id END, CASE WHEN n.record_type = 'contact' THEN n.record_id END
          FROM notes n WHERE n.record_type = p_kind AND n.record_id = p_id
        UNION ALL
        SELECT h.at, 'stage', h.id, (SELECT name FROM stages WHERE id = h.to_stage_id), (SELECT name FROM stages WHERE id = h.from_stage_id), h.member_id, h.deal_id, NULL
          FROM deal_stage_history h WHERE dl IS NOT NULL AND h.deal_id = dl
        UNION ALL
        SELECT l.occurred_at, 'change', l.id, l.action, left(COALESCE(l.after::text, ''), 200), l.actor_member_id, l.deal_id, l.contact_id
          FROM activity_log l
         WHERE l.action NOT LIKE 'screen.%' AND l.action NOT LIKE '%.view' AND ((acc IS NOT NULL AND l.account_id = acc AND l.entity_type = 'account') OR (con IS NOT NULL AND l.contact_id = con AND l.entity_type = 'contact')
            OR (dl IS NOT NULL AND l.deal_id = dl AND l.entity_type = 'deal') OR (ld IS NOT NULL AND l.lead_id = ld AND l.entity_type = 'lead'))
    ) t
    WHERE p_before IS NULL OR t.at < p_before
    ORDER BY t.at DESC LIMIT LEAST(COALESCE(p_limit, 100), 500);
END$$;

-- Q46 touches to win: activities done on the deals won in a period.
CREATE OR REPLACE FUNCTION crm_touches_to_win(p_from date, p_to date)
    RETURNS TABLE (deals bigint, avg_touches numeric, median_touches numeric, avg_days numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH w AS (SELECT d.id, d.created_at, d.won_at, (SELECT count(*) FROM activities a WHERE a.deal_id = d.id AND a.done_at IS NOT NULL AND a.deleted_at IS NULL) AS touches
                 FROM deals d WHERE d.status = 'won' AND d.deleted_at IS NULL AND d.won_at::date BETWEEN p_from AND p_to
                  AND crm_wall(d.owner_member_id, d.restricted_to_member_id, d.restricted_to_department_id))
    SELECT count(*), round(avg(touches), 1), percentile_cont(0.5) WITHIN GROUP (ORDER BY touches)::numeric, round(avg(extract(epoch FROM (won_at - created_at)) / 86400)::numeric, 1) FROM w;
$$;

-- Q22 may the deal enter the stage? The missing fields, by name.
CREATE OR REPLACE FUNCTION crm_stage_requirements_check(p_deal bigint, p_stage bigint) RETURNS text[]
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT crm_stage_missing_fields(d, p_stage) FROM deals d WHERE d.id = p_deal AND crm_wall(d.owner_member_id, d.restricted_to_member_id, d.restricted_to_department_id);
$$;

GRANT EXECUTE ON FUNCTION crm_pipeline_summary(bigint, bigint, bigint), crm_forecast(bigint, integer, text, bigint), crm_funnel(bigint, date, date, bigint), crm_win_rate(date, date, text),
    crm_deals_at_risk(bigint, integer, integer), crm_untouched(text, integer, bigint), crm_leads_untouched(integer, bigint), crm_upcoming_dates(integer, bigint), crm_activity_summary(date, date, bigint),
    crm_leaderboard(date, bigint), crm_source_performance(date, date), crm_campaign_results(bigint), crm_timeline(text, bigint, integer, timestamptz), crm_touches_to_win(date, date),
    crm_stage_requirements_check(bigint, bigint) TO sales_crm_rw, sales_crm_records_ro;
GRANT EXECUTE ON FUNCTION crm_timeline(text, bigint, integer, timestamptz) TO sales_crm_activity_ro;

COMMIT;
