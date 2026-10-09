-- 002: activity memory -- the one funnel (memory.md §2). Exists before the first feature ships; it cannot be
-- backfilled. Every row is shipped to the tenant's one MaluDB as an `activity` episode by mcp/activity_ingest.py
-- (payload key "application": "sales_crm" first). A RECORD'S HISTORY IS THIS TABLE (design §6.5): there is no audit table;
-- `before`/`after` carry what the timeline shows: ids, names, counts, a kind, a status, a stage, a value, 120 characters of a
-- search query at most. AN EMAIL'S BODY, A NOTE'S TEXT, A TOKEN OR A KEY IS NEVER HERE (design §6.5); a key is named by its label.
--
-- THE ESTATE'S DATA MODEL (design §6.5): the contract table (memory.md / Consultant Tracking db/002) with Sales CRM's audit
-- keys APPENDED: lead_id, account_id, contact_id, deal_id — every event about a lead, an account, a contact, a deal, an activity,
-- an email, a note, a tag or a campaign membership carries the records it concerns, so each record's TIMELINE is one index;
-- token_id as Knowledge db/002 appended it (the key an API call ran under). No FK: history outlives a record. The siblings' own
-- appended keys (DocCloud's library_id …) are their domain and are not copied.
BEGIN;

CREATE TABLE activity_log (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    occurred_at     timestamptz NOT NULL DEFAULT now(),
    actor_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    source          text NOT NULL DEFAULT 'web'
                    CHECK (source IN ('web', 'assistant', 'mcp', 'cron', 'agent', 'desk', 'webhook', 'portal', 'api', 'application')),
    action          text NOT NULL,                 -- entity.verb
    screen          text,
    route           text,                          -- "METHOD /path"
    entity_type     text,
    entity_id       bigint,
    before          jsonb,
    after           jsonb,                         -- changed fields only
    request_id      text,
    session_id      text,
    ip_address      inet,
    agent_run_id    bigint,                        -- the kernel's run id when an agent acted (no FK)
    department_id   bigint,                        -- the department the event concerns (a share to it, a group mirroring it)
    location_id     bigint,                        -- the contract's (unused: scope none)
    kernel_request_id text,                        -- the kernel's request id for a call that crossed to it
    created_at      timestamptz NOT NULL DEFAULT now(),
    -- appended (design §6.5): Sales CRM's audit keys (no FK — history outlives a record)
    lead_id         bigint,                        -- the lead the event concerns
    account_id      bigint,                        -- the account (its own event, a contact's, a deal's, an activity's — filled from the child when known)
    contact_id      bigint,                        -- the contact (its own event, an activity's, an email's)
    deal_id         bigint,                        -- the deal (its own event, a move, a product line, an activity's, an email's)
    token_id        bigint                         -- the key (mcp_access_tokens.id) a token-API event ran under; its LABEL is in `after`, never the key
);
CREATE INDEX activity_log_actor_idx    ON activity_log (actor_member_id, occurred_at DESC);
CREATE INDEX activity_log_entity_idx   ON activity_log (entity_type, entity_id, occurred_at DESC);
CREATE INDEX activity_log_action_idx   ON activity_log (action, occurred_at DESC);
CREATE INDEX activity_log_time_idx     ON activity_log (occurred_at DESC);
CREATE INDEX activity_log_request_idx  ON activity_log (request_id);
CREATE INDEX activity_log_lead_idx     ON activity_log (lead_id, occurred_at DESC) WHERE lead_id IS NOT NULL;
CREATE INDEX activity_log_account_idx  ON activity_log (account_id, occurred_at DESC) WHERE account_id IS NOT NULL;
CREATE INDEX activity_log_contact_idx  ON activity_log (contact_id, occurred_at DESC) WHERE contact_id IS NOT NULL;
CREATE INDEX activity_log_deal_idx     ON activity_log (deal_id, occurred_at DESC) WHERE deal_id IS NOT NULL;
CREATE INDEX activity_log_token_idx    ON activity_log (token_id, occurred_at DESC) WHERE token_id IS NOT NULL;

GRANT INSERT, SELECT ON activity_log TO sales_crm_rw;
REVOKE ALL ON activity_log FROM sales_crm_records_ro, sales_crm_activity_ro;

-- The ingest checkpoint: only ever moves forward.
CREATE TABLE activity_ingest_state (
    id          smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    last_id     bigint NOT NULL DEFAULT 0,
    updated_at  timestamptz NOT NULL DEFAULT now()
);
INSERT INTO activity_ingest_state (id, last_id) VALUES (1, 0) ON CONFLICT DO NOTHING;
GRANT SELECT, UPDATE ON activity_ingest_state TO sales_crm_rw;

COMMIT;
