-- 005: the application's SETTINGS (one row) and the KEY rules that read them (design §6.5 "crm_settings"; D5, D6, D9, D12, D13, D14, (a)).
--
-- THE ESTATE'S DATA MODEL (design §6.5): crm_settings is the siblings' one-row `app_settings` skeleton (id smallint = 1,
-- updated_at) with this application's columns. The key functions are Knowledge db/011's = DocCloud db/005's verbatim
-- (crm_resolve_key, crm_rate_ok, crm_key_calls_today, crm_key_rotate, crm_prune_key_usage) — here because they read the settings.
-- A lead-capture key (mcp_access_tokens.lead_source_id set, db/003) takes capture_rate_per_day as its day limit.
BEGIN;

CREATE TABLE crm_settings (
    id                          smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    business_name               text,
    currency                    char(3) NOT NULL DEFAULT 'USD' CHECK (currency ~ '^[A-Z]{3}$'),                                      -- (a): the one base currency
    fiscal_year_start_month     smallint NOT NULL DEFAULT 1 CHECK (fiscal_year_start_month BETWEEN 1 AND 12),                        -- the forecast's quarters
    default_pipeline_id         bigint,                                                                                               -- FK added in db/008
    rotting_days                integer CHECK (rotting_days IS NULL OR rotting_days BETWEEN 1 AND 365),                              -- D6: the business default; NULL = off
    weighted_method             text NOT NULL DEFAULT 'stage' CHECK (weighted_method IN ('stage', 'override_or_stage')),              -- how weighted_value is read
    forecast_months             smallint NOT NULL DEFAULT 6 CHECK (forecast_months BETWEEN 1 AND 24),
    duplicates_policy           text NOT NULL DEFAULT 'warn' CHECK (duplicates_policy IN ('off', 'warn', 'block_exact_email')),       -- D12
    duplicates_name_similarity  numeric(3,2) NOT NULL DEFAULT 0.60 CHECK (duplicates_name_similarity BETWEEN 0.30 AND 1.00),         -- D12: trigram on name + city
    default_remind_minutes      integer NOT NULL DEFAULT 30 CHECK (default_remind_minutes BETWEEN 0 AND 10080),                       -- D9: a new activity's reminder
    next_step_prompt            boolean NOT NULL DEFAULT true,                                                                        -- D9: done asks for the next step
    morning_note_hour           smallint NOT NULL DEFAULT 6 CHECK (morning_note_hour BETWEEN 0 AND 23),                               -- D14: the Assistant's duty (06:30)
    morning_note_enabled        boolean NOT NULL DEFAULT true,
    assistant_proposals         boolean NOT NULL DEFAULT true,                                                                        -- D14: next_step_propose on deals with no next step
    lead_untouched_days         integer NOT NULL DEFAULT 2 CHECK (lead_untouched_days BETWEEN 1 AND 365),                            -- §5: the morning note
    account_untouched_days      integer NOT NULL DEFAULT 30 CHECK (account_untouched_days BETWEEN 1 AND 3650),                       -- §7 Q10
    tombstone_days              integer NOT NULL DEFAULT 30 CHECK (tombstone_days BETWEEN 1 AND 3650),                               -- §3.5: a deleted record waits
    import_max_rows             integer NOT NULL DEFAULT 50000 CHECK (import_max_rows BETWEEN 100 AND 1000000),                     -- D12
    import_rollback_hours       integer NOT NULL DEFAULT 24 CHECK (import_rollback_hours BETWEEN 1 AND 168),                         -- D12
    bulk_sync_rows              integer NOT NULL DEFAULT 1000 CHECK (bulk_sync_rows BETWEEN 10 AND 100000),                          -- D12: one transaction up to here, the worker beyond
    key_rate_per_minute         integer NOT NULL DEFAULT 60 CHECK (key_rate_per_minute BETWEEN 1 AND 100000),                        -- D13
    key_rate_per_day            integer NOT NULL DEFAULT 5000 CHECK (key_rate_per_day BETWEEN 1 AND 10000000),                       -- D13
    capture_rate_per_day        integer NOT NULL DEFAULT 600 CHECK (capture_rate_per_day BETWEEN 1 AND 10000000),                    -- D13: a lead-capture key
    key_rotation_overlap_hours  smallint NOT NULL DEFAULT 24 CHECK (key_rotation_overlap_hours BETWEEN 0 AND 168),                   -- D13: the old key keeps 24 h
    mailbox_id                  bigint,                                                                                               -- the logging mailbox; FK added in db/011
    email_footer                text,                                                                                                 -- under every CRM-sent email (the unsubscribe link is always added)
    allow_restricted_records    boolean NOT NULL DEFAULT true,                                                                        -- D5: a manager may restrict
    ics_days_ahead              integer NOT NULL DEFAULT 90 CHECK (ics_days_ahead BETWEEN 7 AND 730),                                -- §6.3: the feed's window
    ics_days_back               integer NOT NULL DEFAULT 30 CHECK (ics_days_back BETWEEN 0 AND 365),
    max_attachment_bytes        bigint NOT NULL DEFAULT 26214400 CHECK (max_attachment_bytes BETWEEN 1048576 AND 1073741824),        -- the canonical store's 25 MB
    storage_dir                 text,                                                                                                 -- NULL = storage/attachments under the install
    timezone                    text NOT NULL DEFAULT 'UTC',
    updated_at                  timestamptz NOT NULL DEFAULT now()
);
INSERT INTO crm_settings (id) VALUES (1) ON CONFLICT DO NOTHING;
CREATE TRIGGER crm_settings_touch BEFORE UPDATE ON crm_settings FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- One setting by name, for the rules that read them (never for a column that is not a number / not text).
CREATE OR REPLACE FUNCTION crm_setting_int(p_name text) RETURNS bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v bigint;
BEGIN
    EXECUTE format('SELECT %I::bigint FROM crm_settings WHERE id = 1', p_name) INTO v;
    RETURN v;
END$$;
CREATE OR REPLACE FUNCTION crm_setting_text(p_name text) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v text;
BEGIN
    EXECUTE format('SELECT %I::text FROM crm_settings WHERE id = 1', p_name) INTO v;
    RETURN v;
END$$;
CREATE OR REPLACE FUNCTION crm_setting_bool(p_name text) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v boolean;
BEGIN
    EXECUTE format('SELECT %I::boolean FROM crm_settings WHERE id = 1', p_name) INTO v;
    RETURN v;
END$$;

-- ---------------------------------------------------------------------------------------------
-- KEYS (db/003): a key's defaults, its resolution, the rate rule, rotation and pruning — Knowledge db/011 minus the base.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION crm_access_tokens_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.scope = 'api' THEN
        NEW.rate_per_minute := COALESCE(NEW.rate_per_minute, crm_setting_int('key_rate_per_minute')::integer);
        NEW.rate_per_day := COALESCE(NEW.rate_per_day, CASE WHEN NEW.lead_source_id IS NOT NULL THEN crm_setting_int('capture_rate_per_day') ELSE crm_setting_int('key_rate_per_day') END::integer);
        IF NEW.rotated_from IS NOT NULL AND NOT EXISTS (SELECT 1 FROM mcp_access_tokens o WHERE o.id = NEW.rotated_from AND o.member_id = NEW.member_id AND o.scope = 'api') THEN
            RAISE EXCEPTION 'A key is rotated from a key of the same person' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER mcp_access_tokens_before BEFORE INSERT OR UPDATE ON mcp_access_tokens FOR EACH ROW EXECUTE FUNCTION crm_access_tokens_before();

-- Resolve a key by its hash: the token, its person, its label and its limits, with last_used_at touched. Nothing for a revoked,
-- expired or mcp-scope token, or one whose person is no longer admitted (a revoked grant shuts this door too).
CREATE OR REPLACE FUNCTION crm_resolve_key(p_hash text)
    RETURNS TABLE (token_id bigint, member_id bigint, label text, rate_per_minute integer, rate_per_day integer)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    UPDATE mcp_access_tokens t SET last_used_at = now()
      FROM members m
     WHERE t.token_hash = p_hash AND t.scope = 'api' AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > now())
       AND m.id = t.member_id AND m.status = 'active' AND m.capability IS NOT NULL;
    RETURN QUERY
        SELECT t.id, t.member_id, t.label, t.rate_per_minute, t.rate_per_day
          FROM mcp_access_tokens t JOIN members m ON m.id = t.member_id
         WHERE t.token_hash = p_hash AND t.scope = 'api' AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > now())
           AND m.status = 'active' AND m.capability IS NOT NULL;
END$$;

-- Count one call. ok = the call may proceed; when not, limit_hit says which bucket and retry_after how many seconds until it
-- opens. The minute bucket is charged first; a call the minute refuses is not charged to the day (so a burst never eats the
-- day); a call the day refuses stays counted in the minute (it was made). `refused` is kept per bucket for the keys screen.
CREATE OR REPLACE FUNCTION crm_rate_ok(p_token_id bigint, p_at timestamptz DEFAULT now())
    RETURNS TABLE (ok boolean, limit_hit text, retry_after integer, minute_calls integer, day_calls integer)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t mcp_access_tokens%ROWTYPE; m_start timestamptz; d_start timestamptz; m_calls integer; d_calls integer;
BEGIN
    SELECT * INTO t FROM mcp_access_tokens WHERE id = p_token_id AND scope = 'api' FOR UPDATE;   -- serialises the two upserts per key
    IF NOT FOUND THEN
        RETURN QUERY SELECT false, 'no_key'::text, 0, 0, 0; RETURN;
    END IF;
    IF t.revoked_at IS NOT NULL OR (t.expires_at IS NOT NULL AND t.expires_at <= p_at) THEN
        RETURN QUERY SELECT false, 'revoked'::text, 0, 0, 0; RETURN;
    END IF;
    m_start := date_trunc('minute', p_at);
    d_start := date_trunc('day', p_at);
    INSERT INTO key_usage (token_id, bucket_kind, bucket_start, calls) VALUES (p_token_id, 'minute', m_start, 1)
    ON CONFLICT (token_id, bucket_kind, bucket_start) DO UPDATE SET calls = key_usage.calls + 1
    RETURNING calls INTO m_calls;
    IF m_calls > t.rate_per_minute THEN
        UPDATE key_usage SET calls = calls - 1, refused = refused + 1 WHERE token_id = p_token_id AND bucket_kind = 'minute' AND bucket_start = m_start;
        SELECT COALESCE(calls, 0) INTO d_calls FROM key_usage WHERE token_id = p_token_id AND bucket_kind = 'day' AND bucket_start = d_start;
        RETURN QUERY SELECT false, 'minute'::text, GREATEST(1, ceil(extract(epoch FROM (m_start + interval '1 minute' - p_at)))::integer), m_calls - 1, COALESCE(d_calls, 0);
        RETURN;
    END IF;
    INSERT INTO key_usage (token_id, bucket_kind, bucket_start, calls) VALUES (p_token_id, 'day', d_start, 1)
    ON CONFLICT (token_id, bucket_kind, bucket_start) DO UPDATE SET calls = key_usage.calls + 1
    RETURNING calls INTO d_calls;
    IF d_calls > t.rate_per_day THEN
        UPDATE key_usage SET calls = calls - 1, refused = refused + 1 WHERE token_id = p_token_id AND bucket_kind = 'day' AND bucket_start = d_start;
        RETURN QUERY SELECT false, 'day'::text, GREATEST(1, ceil(extract(epoch FROM (d_start + interval '1 day' - p_at)))::integer), m_calls, d_calls - 1;
        RETURN;
    END IF;
    RETURN QUERY SELECT true, NULL::text, 0, m_calls, d_calls;
END$$;

-- Today's count for a key (the keys screen, find_keys).
CREATE OR REPLACE FUNCTION crm_key_calls_today(p_token_id bigint) RETURNS integer
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE((SELECT calls FROM key_usage WHERE token_id = p_token_id AND bucket_kind = 'day' AND bucket_start = date_trunc('day', now())), 0);
$$;

-- Rotate: a new key for the same person, label and limits; the old one expires after the overlap (D13: 24 h) and points nowhere —
-- the new one says `rotated_from`. Returns the new row's id; the raw key is the caller's (PHP mints the 48 hex, hashes them, and
-- passes the hash in).
CREATE OR REPLACE FUNCTION crm_key_rotate(p_old_token_id bigint, p_new_hash text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE o mcp_access_tokens%ROWTYPE; nid bigint; overlap integer;
BEGIN
    SELECT * INTO o FROM mcp_access_tokens WHERE id = p_old_token_id AND scope = 'api' FOR UPDATE;
    IF NOT FOUND OR o.revoked_at IS NOT NULL THEN RAISE EXCEPTION 'Only a live key is rotated' USING ERRCODE = 'check_violation'; END IF;
    overlap := crm_setting_int('key_rotation_overlap_hours')::integer;
    INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, rate_per_minute, rate_per_day, rotated_from)
    VALUES (o.member_id, o.label, p_new_hash, 'api', o.rate_per_minute, o.rate_per_day, o.id)
    RETURNING id INTO nid;
    UPDATE mcp_access_tokens SET expires_at = LEAST(COALESCE(expires_at, 'infinity'::timestamptz), now() + make_interval(hours => overlap)) WHERE id = o.id;
    RETURN nid;
END$$;

-- The worker prunes buckets once a minute: minute buckets older than a day, day buckets older than 35 days.
CREATE OR REPLACE FUNCTION crm_prune_key_usage() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    DELETE FROM key_usage WHERE (bucket_kind = 'minute' AND bucket_start < now() - interval '1 day') OR (bucket_kind = 'day' AND bucket_start < now() - interval '35 days');
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

GRANT SELECT, UPDATE ON crm_settings TO sales_crm_rw;
GRANT EXECUTE ON FUNCTION crm_setting_int(text), crm_setting_text(text), crm_setting_bool(text), crm_key_calls_today(bigint) TO sales_crm_rw, sales_crm_records_ro, sales_crm_activity_ro;
REVOKE ALL ON FUNCTION crm_resolve_key(text), crm_rate_ok(bigint, timestamptz), crm_key_rotate(bigint, text), crm_prune_key_usage() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION crm_resolve_key(text), crm_rate_ok(bigint, timestamptz), crm_key_rotate(bigint, text), crm_prune_key_usage() TO sales_crm_rw;

COMMIT;
