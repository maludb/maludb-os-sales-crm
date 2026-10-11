-- 023: slice 8 (agents, notifications, K7, the API and keys). One table and three function corrections; db/001–022 are not modified.
--
-- 1. k7_cache — the five-minute cache of the two K7 reads this application consumes (design §8; agents-k7-api.md divergence 3): what a sibling's shared tool
--    answered for one set of arguments (`gl.customer_by_crm_account`, `helpdesk.tickets_by_organization`, the product pickers' catalogue reads). The row
--    holds the PROVIDER'S answer, never a person's identity; whether a caller may ask about an account is judged BEFORE the read (the wall), never by the cache.
--    PHP's writer only. `status` is the degraded word too (no_connection, unavailable) so a refused or failing provider is not asked again every page view.
-- 2. crm_key_rotate() — a defect found in db/005 while building the keys: the new key copied the old one's person, label and limits but NOT lead_source_id,
--    so rotating a LEAD-CAPTURE key made a key that acts as its person (an admin) with every route open — the capture key's whole point lost. It also
--    dropped the old key's own expiry. The replacement copies both; nothing else about the function changes (same signature, same privileges).
BEGIN;

CREATE TABLE k7_cache (
    provider    text NOT NULL CHECK (provider ~ '^[a-z][a-z0-9_]{1,31}$'),
    tool        text NOT NULL CHECK (tool ~ '^[a-z][a-z0-9_]{0,63}$'),
    args_hash   text NOT NULL,                                   -- sha256 of the arguments, keys sorted
    status      text NOT NULL CHECK (status IN ('ok', 'no_connection', 'unavailable')),
    rows        jsonb NOT NULL DEFAULT '[]'::jsonb,
    fetched_at  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (provider, tool, args_hash)
);
CREATE INDEX k7_cache_fetched_idx ON k7_cache (fetched_at);
GRANT SELECT, INSERT, UPDATE, DELETE ON k7_cache TO sales_crm_rw;

CREATE OR REPLACE FUNCTION crm_key_rotate(p_old_token_id bigint, p_new_hash text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE o mcp_access_tokens%ROWTYPE; nid bigint; overlap integer;
BEGIN
    SELECT * INTO o FROM mcp_access_tokens WHERE id = p_old_token_id AND scope = 'api' FOR UPDATE;
    IF NOT FOUND OR o.revoked_at IS NOT NULL THEN RAISE EXCEPTION 'Only a live key is rotated' USING ERRCODE = 'check_violation'; END IF;
    overlap := crm_setting_int('key_rotation_overlap_hours')::integer;
    INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, rate_per_minute, rate_per_day, rotated_from, lead_source_id, expires_at)
    VALUES (o.member_id, o.label, p_new_hash, 'api', o.rate_per_minute, o.rate_per_day, o.id, o.lead_source_id, o.expires_at)
    RETURNING id INTO nid;
    UPDATE mcp_access_tokens SET expires_at = LEAST(COALESCE(expires_at, 'infinity'::timestamptz), now() + make_interval(hours => overlap)) WHERE id = o.id;
    RETURN nid;
END$$;

-- 3. crm_share_accounts_index() — a leak found while building the shares: open_deals_count, open_deals_value and won_deals_value are the account's own counters, kept
--    over EVERY deal (db/008 crm_account_recount), so an account that is shared carried the number and value of the deals RESTRICTED to a person or a department. A share
--    never includes a restricted record (design §8); the totals are now counted from the deals a share may show (not restricted, not tombstoned). Same signature.
CREATE OR REPLACE FUNCTION crm_share_accounts_index(p_consumer text, p_since timestamptz DEFAULT NULL, p_q text DEFAULT NULL, p_limit integer DEFAULT 200, p_offset integer DEFAULT 0)
    RETURNS TABLE (account_id bigint, name text, legal_name text, domain text, kind text, owner_member_id bigint, owner_name text, gl_customer_id bigint, helpdesk_organization_id bigint,
                   country text, city text, open_deals_count integer, open_deals_value numeric, won_deals_value numeric, currency text, updated_at timestamptz, total bigint)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT a.id, a.name, a.legal_name, a.domain::text, a.kind, a.owner_member_id, (SELECT display_name FROM members WHERE id = a.owner_member_id), a.gl_customer_id, a.helpdesk_organization_id,
           a.country::text, a.city, COALESCE(x.open_count, 0)::integer, COALESCE(x.open_value, 0), COALESCE(x.won_value, 0), COALESCE(a.currency, crm_setting_text('currency'))::text, a.updated_at, count(*) OVER ()
      FROM accounts a
      LEFT JOIN LATERAL (SELECT count(*) FILTER (WHERE d.status = 'open') AS open_count, sum(d.value) FILTER (WHERE d.status = 'open') AS open_value, sum(d.value) FILTER (WHERE d.status = 'won') AS won_value
                           FROM deals d WHERE d.account_id = a.id AND d.deleted_at IS NULL AND d.restricted_to_member_id IS NULL AND d.restricted_to_department_id IS NULL) x ON true
     WHERE a.deleted_at IS NULL AND a.merged_into_id IS NULL AND a.restricted_to_member_id IS NULL AND a.restricted_to_department_id IS NULL
       AND (p_since IS NULL OR a.updated_at > p_since) AND (p_q IS NULL OR a.name ILIKE '%' || p_q || '%' OR a.domain::text ILIKE '%' || p_q || '%')
     ORDER BY a.updated_at DESC, a.id LIMIT LEAST(p_limit, 500) OFFSET GREATEST(p_offset, 0);
$$;

-- 4. crm_share_account_timeline_summary() — a defect found while building the shares: the trailing ORDER BY ... LIMIT of db/017 applied to the WHOLE union, so an
--    account with more open activities than the limit answered open ones only (latest first) — no past touch, and the "next step" was the farthest one, not the
--    nearest. The answer is now what the document promises: the last `p_limit` DONE touches, newest first, then exactly one row for the NEXT step (the earliest open
--    activity), flagged is_next_step. Same signature; dates and subjects only, never a body.
CREATE OR REPLACE FUNCTION crm_share_account_timeline_summary(p_consumer text, p_account bigint, p_limit integer DEFAULT 10)
    RETURNS TABLE (at timestamptz, kind text, subject text, who text, direction text, deal_number text, is_next_step boolean)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    (SELECT COALESCE(a.done_at, a.starts_at, a.due_at) AS at, a.kind, a.subject, (SELECT display_name FROM members WHERE id = COALESCE(a.done_by, a.owner_member_id)) AS who, a.email_direction AS direction,
            (SELECT number FROM deals WHERE id = a.deal_id) AS deal_number, false AS is_next_step
       FROM activities a JOIN accounts x ON x.id = a.account_id
      WHERE a.account_id = p_account AND a.deleted_at IS NULL AND a.done_at IS NOT NULL AND NOT a.is_private
        AND x.deleted_at IS NULL AND x.restricted_to_member_id IS NULL AND x.restricted_to_department_id IS NULL
      ORDER BY 1 DESC LIMIT LEAST(GREATEST(p_limit, 1), 50))
    UNION ALL
    (SELECT COALESCE(a.starts_at, a.due_at), a.kind, a.subject, (SELECT display_name FROM members WHERE id = a.owner_member_id), NULL::text,
            (SELECT number FROM deals WHERE id = a.deal_id), true
       FROM activities a JOIN accounts x ON x.id = a.account_id
      WHERE a.account_id = p_account AND a.deleted_at IS NULL AND a.done_at IS NULL AND NOT a.is_private
        AND x.deleted_at IS NULL AND x.restricted_to_member_id IS NULL AND x.restricted_to_department_id IS NULL
      ORDER BY 1 NULLS LAST LIMIT 1);
$$;

COMMIT;
