-- 003: the application's own bearer tokens for its two read MCP servers and its token API
-- (mcp-and-api.md §1). Raw form `mcp_` + 48 hex chars, shown once; only the hash is stored.
-- A kernel agent never holds one of these: it presents the tenant's signed run token, which the
-- servers verify with ACTION_TOKEN_KEY (the same key, copied into config/.env by the installer).
-- A person's own token (scope mcp) is READ-ONLY; a KEY (scope api) is the token API's door (design §4, §8, D13): it is a
-- person's own, acts as that person and sees exactly what they see, rate-limited per minute and per day, logged under its label.
--
-- THE ESTATE'S DATA MODEL (design §6.5): the contract table, Consultant Tracking db/003 verbatim, APPENDING Knowledge db/003's
-- rate columns (rate_per_minute, rate_per_day, rotated_from) WITHOUT its base binding, and APPENDING lead_source_id: a key is a
-- person's own, or a lead-capture key bound to a source that creates leads and reads nothing (D13). key_usage is Knowledge db/011's bucket table verbatim. The trigger that fills a key's defaults and the rate rule
-- (crm_rate_ok) are in db/005, after the settings they read.
BEGIN;

CREATE TABLE mcp_access_tokens (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id     bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    label         text NOT NULL,
    token_hash    text NOT NULL UNIQUE,            -- sha256(raw)
    scope         text NOT NULL DEFAULT 'mcp' CHECK (scope IN ('mcp', 'api')),
    last_used_at  timestamptz,
    expires_at    timestamptz,
    revoked_at    timestamptz,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now(),
    -- appended (design §4, D13): a key's limits and rotation
    rate_per_minute  integer CHECK (rate_per_minute IS NULL OR rate_per_minute BETWEEN 1 AND 100000),   -- NULL on insert = crm_settings default (filled by trigger, db/005)
    rate_per_day     integer CHECK (rate_per_day IS NULL OR rate_per_day BETWEEN 1 AND 10000000),
    rotated_from     bigint REFERENCES mcp_access_tokens(id) ON DELETE SET NULL,   -- the key this one replaced (the old one keeps 24 h)
    lead_source_id   bigint,                                                         -- appended (design §4, D13): a LEAD-CAPTURE key — creates leads under this source and reads nothing; FK added in db/007
    -- a person's mcp token carries none of a key's columns
    CHECK (scope = 'api' OR (rate_per_minute IS NULL AND rate_per_day IS NULL AND rotated_from IS NULL AND lead_source_id IS NULL))
);
CREATE INDEX mcp_access_tokens_member_idx ON mcp_access_tokens (member_id);
CREATE INDEX mcp_access_tokens_rotated_idx ON mcp_access_tokens (rotated_from) WHERE rotated_from IS NOT NULL;
CREATE TRIGGER mcp_access_tokens_touch BEFORE UPDATE ON mcp_access_tokens FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
GRANT SELECT, INSERT, UPDATE ON mcp_access_tokens TO sales_crm_rw;

-- The read roles have no grant on the table: this function is their only way to resolve a token.
CREATE OR REPLACE FUNCTION mcp_resolve_token(p_hash text, p_scope text DEFAULT 'mcp')
    RETURNS TABLE (member_id bigint, member_role text, member_kind text)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    UPDATE mcp_access_tokens t
       SET last_used_at = now()
      FROM members m
     WHERE t.token_hash = p_hash AND t.scope = p_scope
       AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > now())
       AND m.id = t.member_id AND m.status = 'active' AND m.capability IS NOT NULL;
    RETURN QUERY
        SELECT t.member_id, m.business_role, m.member_kind
          FROM mcp_access_tokens t JOIN members m ON m.id = t.member_id
         WHERE t.token_hash = p_hash AND t.scope = p_scope
           AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > now())
           AND m.status = 'active' AND m.capability IS NOT NULL;
END$$;
GRANT EXECUTE ON FUNCTION mcp_resolve_token(text, text) TO sales_crm_rw, sales_crm_records_ro, sales_crm_activity_ro;

-- A key's minute and day buckets (Knowledge db/011 verbatim).
CREATE TABLE key_usage (
    token_id      bigint NOT NULL REFERENCES mcp_access_tokens(id) ON DELETE CASCADE,
    bucket_kind   text NOT NULL CHECK (bucket_kind IN ('minute', 'day')),
    bucket_start  timestamptz NOT NULL,
    calls         integer NOT NULL DEFAULT 0 CHECK (calls >= 0),
    refused       integer NOT NULL DEFAULT 0 CHECK (refused >= 0),
    PRIMARY KEY (token_id, bucket_kind, bucket_start)
);
CREATE INDEX key_usage_time_idx ON key_usage (bucket_kind, bucket_start);
GRANT SELECT, INSERT, UPDATE, DELETE ON key_usage TO sales_crm_rw;

COMMIT;
