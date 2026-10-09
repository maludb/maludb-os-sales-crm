-- 012: CAMPAIGNS and their members, LISTS (static and smart) and their members, SAVED VIEWS, IMPORTS and their templates, EXPORTS,
-- the WORKER's passes (design §6.4; D12).
--
-- THE ESTATE'S DATA MODEL (design §6.5): `saved_views` is Help Desk db/011 VERBATIM (queue_id → pipeline_id, the external-key
-- substitution; layout widened); `imports`, `exports`, `worker_passes` are Spaces db/016 verbatim with the kind lists ours and the
-- targets appended; `campaigns`, `campaign_members`, `lists`, `list_members`, `import_templates`, `import_rows` are NEW CANONICAL.
BEGIN;

CREATE TABLE campaigns (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name            text NOT NULL,
    kind            text NOT NULL DEFAULT 'other' CHECK (kind IN ('email', 'event', 'webinar', 'ads', 'content', 'outbound', 'referral', 'other')),
    status          text NOT NULL DEFAULT 'planned' CHECK (status IN ('planned', 'active', 'done', 'cancelled')),
    starts_on       date,
    ends_on         date,
    budget          numeric(14,2) CHECK (budget IS NULL OR budget >= 0),
    currency        char(3) CHECK (currency IS NULL OR currency ~ '^[A-Z]{3}$'),
    expected_leads  integer CHECK (expected_leads IS NULL OR expected_leads >= 0),
    utm_campaign    text,
    owner_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    description     text,
    deleted_at      timestamptz,
    deleted_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    created_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    CHECK (ends_on IS NULL OR starts_on IS NULL OR ends_on >= starts_on)
);
CREATE INDEX campaigns_status_idx ON campaigns (status) WHERE deleted_at IS NULL;
CREATE TRIGGER campaigns_touch BEFORE UPDATE ON campaigns FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
ALTER TABLE leads ADD CONSTRAINT leads_campaign_fk FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL;
ALTER TABLE deals ADD CONSTRAINT deals_campaign_fk FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL;

CREATE TABLE campaign_members (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    campaign_id  bigint NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
    lead_id      bigint REFERENCES leads(id) ON DELETE CASCADE,
    contact_id   bigint REFERENCES contacts(id) ON DELETE CASCADE,
    status       text NOT NULL DEFAULT 'sent' CHECK (status IN ('planned', 'sent', 'opened', 'responded', 'attended', 'converted', 'unsubscribed')),
    added_by     bigint REFERENCES members(id) ON DELETE SET NULL,
    added_at     timestamptz NOT NULL DEFAULT now(),
    responded_at timestamptz,
    CHECK ((lead_id IS NULL) <> (contact_id IS NULL))
);
CREATE UNIQUE INDEX campaign_members_lead_uq ON campaign_members (campaign_id, lead_id) WHERE lead_id IS NOT NULL;
CREATE UNIQUE INDEX campaign_members_contact_uq ON campaign_members (campaign_id, contact_id) WHERE contact_id IS NOT NULL;
CREATE INDEX campaign_members_contact_idx ON campaign_members (contact_id);

CREATE TABLE lists (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name            text NOT NULL,
    kind            text NOT NULL CHECK (kind IN ('static', 'smart')),
    record_kind     text NOT NULL CHECK (record_kind IN ('lead', 'contact', 'account', 'deal')),
    -- a smart list's filter: the bounded vocabulary of the find_* tools (design §7), e.g. {"owner": "me"|<id>, "status": [..], "source_id": [..],
    --  "country": [..], "tags": [..], "untouched_days": 30, "lifecycle": [..], "kind": [..], "pipeline_id": .., "stage_id": [..], "q": "…"}
    filters         jsonb NOT NULL DEFAULT '{}'::jsonb,
    owner_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    is_shared       boolean NOT NULL DEFAULT false,
    member_count    integer NOT NULL DEFAULT 0,                    -- static: by trigger; smart: the worker, hourly
    counted_at      timestamptz,
    description     text,
    deleted_at      timestamptz,
    created_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    CHECK (is_shared OR owner_member_id IS NOT NULL)
);
CREATE TRIGGER lists_touch BEFORE UPDATE ON lists FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE list_members (
    list_id    bigint NOT NULL REFERENCES lists(id) ON DELETE CASCADE,
    record_id  bigint NOT NULL,
    added_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    added_at   timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (list_id, record_id)
);
CREATE OR REPLACE FUNCTION crm_list_members_count() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE l bigint := COALESCE(NEW.list_id, OLD.list_id);
BEGIN
    IF TG_OP = 'INSERT' AND (SELECT kind FROM lists WHERE id = l) <> 'static' THEN RAISE EXCEPTION 'A smart list has no members of its own' USING ERRCODE = 'check_violation'; END IF;
    UPDATE lists SET member_count = (SELECT count(*) FROM list_members WHERE list_id = l), counted_at = now() WHERE id = l;
    RETURN NULL;
END$$;
CREATE TRIGGER list_members_count AFTER INSERT OR DELETE ON list_members FOR EACH ROW EXECUTE FUNCTION crm_list_members_count();

-- reuse: Help Desk db/011 verbatim; queue_id → pipeline_id; layout widened; the filters vocabulary ours
CREATE TABLE saved_views (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name           text NOT NULL,
    scope          text NOT NULL DEFAULT 'personal' CHECK (scope IN ('personal', 'queue', 'global')),   -- 'queue' = one pipeline's (the canonical value kept)
    owner_member_id bigint REFERENCES members(id) ON DELETE CASCADE,
    pipeline_id    bigint REFERENCES pipelines(id) ON DELETE CASCADE,
    filters        jsonb NOT NULL DEFAULT '{}'::jsonb,
    sort_by        text NOT NULL DEFAULT 'last_activity_at',
    sort_dir       text NOT NULL DEFAULT 'desc' CHECK (sort_dir IN ('asc', 'desc')),
    columns        text[] NOT NULL DEFAULT '{number,subject,requester,status,priority,assignee,updated}',
    layout         text NOT NULL DEFAULT 'list' CHECK (layout IN ('list', 'board', 'calendar', 'forecast')),
    board          jsonb NOT NULL DEFAULT '{}'::jsonb,
    is_system      boolean NOT NULL DEFAULT false,
    system_key     text UNIQUE,
    sort_order     integer NOT NULL DEFAULT 100,
    archived_at    timestamptz,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    CHECK ((scope = 'personal') = (owner_member_id IS NOT NULL)),
    CHECK ((scope = 'queue') = (pipeline_id IS NOT NULL)),
    -- appended: which screen the view belongs to
    record_kind    text NOT NULL DEFAULT 'deal' CHECK (record_kind IN ('lead', 'account', 'contact', 'deal', 'activity'))
);
CREATE TRIGGER saved_views_touch BEFORE UPDATE ON saved_views FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
INSERT INTO saved_views (name, scope, record_kind, filters, sort_by, layout, is_system, system_key, sort_order, columns) VALUES
 ('My open deals', 'global', 'deal', '{"owner": "me", "status": ["open"]}', 'expected_close_date', 'board', true, 'my_open_deals', 10, '{number,title,account,stage,value,close,owner}'),
 ('Closing this month', 'global', 'deal', '{"status": ["open"], "close": "this_month"}', 'expected_close_date', 'list', true, 'closing_this_month', 20, '{number,title,account,stage,value,weighted,close,owner}'),
 ('No next step', 'global', 'deal', '{"status": ["open"], "no_next_step": true}', 'stage_entered_at', 'list', true, 'no_next_step', 30, '{number,title,account,stage,value,owner,entered}'),
 ('Rotting', 'global', 'deal', '{"status": ["open"], "rotting": true}', 'stage_entered_at', 'list', true, 'rotting', 40, '{number,title,account,stage,value,owner,entered}'),
 ('New leads', 'global', 'lead', '{"status": ["new"]}', 'created_at', 'list', true, 'new_leads', 50, '{name,company,source,owner,created}'),
 ('My activities today', 'global', 'activity', '{"owner": "me", "when": "today"}', 'when', 'list', true, 'my_today', 60, '{when,kind,subject,record,owner}');

-- reuse: Spaces db/016 verbatim; kinds ours; the CRM's targets appended
CREATE TABLE imports (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind          text NOT NULL CHECK (kind IN ('leads_csv', 'contacts_csv', 'accounts_csv', 'deals_csv')),
    file_name     text NOT NULL,
    status        text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'done', 'failed', 'rolled_back')),
    pages_made    integer NOT NULL DEFAULT 0,                      -- Spaces' columns kept (unused here)
    rows_made     integer NOT NULL DEFAULT 0,                      -- records created
    blocks_made   integer NOT NULL DEFAULT 0,
    unsupported   integer NOT NULL DEFAULT 0,                      -- rows refused
    log           text,
    created_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    finished_at   timestamptz,
    -- appended (design §6.1)
    storage_path  text,
    byte_size     bigint,
    row_count     integer,
    mapping       jsonb NOT NULL DEFAULT '{}'::jsonb,              -- {"csv column": "field or custom:key"}
    dedupe_policy text NOT NULL DEFAULT 'skip' CHECK (dedupe_policy IN ('skip', 'update', 'create')),
    dry_run       boolean NOT NULL DEFAULT true,
    rows_updated  integer NOT NULL DEFAULT 0,
    rows_skipped  integer NOT NULL DEFAULT 0,
    owner_member_id bigint REFERENCES members(id) ON DELETE SET NULL,   -- the records' owner when the file names none
    source_id     bigint REFERENCES lead_sources(id) ON DELETE SET NULL,
    list_id       bigint REFERENCES lists(id) ON DELETE SET NULL,  -- every created record joins this static list
    template_id   bigint,
    rollback_until timestamptz,
    rolled_back_at timestamptz
);
CREATE INDEX imports_status_idx ON imports (status, created_at);

CREATE TABLE import_templates (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name        text NOT NULL,
    kind        text NOT NULL CHECK (kind IN ('leads_csv', 'contacts_csv', 'accounts_csv', 'deals_csv')),
    mapping     jsonb NOT NULL DEFAULT '{}'::jsonb,
    dedupe_policy text NOT NULL DEFAULT 'skip' CHECK (dedupe_policy IN ('skip', 'update', 'create')),
    is_shared   boolean NOT NULL DEFAULT false,
    owner_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER import_templates_touch BEFORE UPDATE ON import_templates FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
ALTER TABLE imports ADD CONSTRAINT imports_template_fk FOREIGN KEY (template_id) REFERENCES import_templates(id) ON DELETE SET NULL;

-- every row's result, kept (the rollback tombstones what `created` names)
CREATE TABLE import_rows (
    import_id   bigint NOT NULL REFERENCES imports(id) ON DELETE CASCADE,
    row_number  integer NOT NULL,
    result      text NOT NULL CHECK (result IN ('created', 'updated', 'skipped', 'refused')),
    record_id   bigint,
    message     text,
    PRIMARY KEY (import_id, row_number)
);

-- reuse: Spaces db/016 verbatim; kinds ours
CREATE TABLE exports (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind          text NOT NULL CHECK (kind IN ('leads', 'contacts', 'accounts', 'deals', 'activities', 'report', 'list')),
    format        text NOT NULL CHECK (format IN ('csv', 'json')),
    include_subpages boolean NOT NULL DEFAULT true,                -- Spaces' column kept (unused)
    status        text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'done', 'failed')),
    storage_path  text,
    byte_size     bigint,
    item_count    integer NOT NULL DEFAULT 0,
    log           text,
    created_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    finished_at   timestamptz,
    expires_at    timestamptz NOT NULL DEFAULT now() + interval '7 days',
    downloaded_at timestamptz,
    -- appended
    params        jsonb NOT NULL DEFAULT '{}'::jsonb,              -- the filters, the list, the report's name and period
    list_id       bigint REFERENCES lists(id) ON DELETE SET NULL
);
CREATE INDEX exports_status_idx ON exports (status, created_at);

-- reuse: Spaces db/023 verbatim
CREATE TABLE worker_passes (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    started_at    timestamptz NOT NULL DEFAULT now(),
    finished_at   timestamptz,
    steps         jsonb NOT NULL DEFAULT '{}'::jsonb,
    error         text
);

GRANT SELECT, INSERT, UPDATE, DELETE ON campaigns, campaign_members, lists, list_members, saved_views, imports, import_templates, import_rows, exports, worker_passes TO sales_crm_rw;

COMMIT;
