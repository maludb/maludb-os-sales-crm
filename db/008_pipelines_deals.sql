-- 008: PIPELINES, STAGES and DEALS — the sales cycle the board shows (design §6.2; D6, D7, D11, (a)).
--
-- THE ESTATE'S DATA MODEL (design §6.5): `pipelines`, `stages`, `deals`, `deal_stage_history`, `deal_contacts`, `deal_products`,
-- `quotas` are NEW CANONICAL. Compared: Projects `workflow_statuses`/`board_columns`/`board_column_statuses` (an issue's workflow with a
-- mapping table a stage does not need — a stage IS a column), Inventory `sales_orders` (an order, after the sale). A stage carries a
-- probability, rotting days, the fields a deal must have to enter it, and an ask-the-Assistant switch. A deal's weighted value follows
-- the stage's probability or the owner's override; its value follows its product lines when any exist (D11); its status follows its
-- stage's kind. Every move writes deal_stage_history — the funnel, conversion and cycle time read nothing else.
BEGIN;

CREATE TABLE pipelines (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    key           text NOT NULL UNIQUE CHECK (key ~ '^[a-z][a-z0-9_]{0,39}$'),
    name          text NOT NULL,
    is_default    boolean NOT NULL DEFAULT false,
    currency      char(3) CHECK (currency IS NULL OR currency ~ '^[A-Z]{3}$'),          -- NULL = the business's
    rotting_days  integer CHECK (rotting_days IS NULL OR rotting_days BETWEEN 1 AND 365), -- NULL = the settings'
    position      integer NOT NULL DEFAULT 100,
    archived_at   timestamptz,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX pipelines_one_default ON pipelines (is_default) WHERE is_default;
CREATE TRIGGER pipelines_touch BEFORE UPDATE ON pipelines FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
ALTER TABLE crm_settings ADD CONSTRAINT crm_settings_default_pipeline_fk FOREIGN KEY (default_pipeline_id) REFERENCES pipelines(id) ON DELETE SET NULL;

CREATE TABLE stages (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    pipeline_id     bigint NOT NULL REFERENCES pipelines(id) ON DELETE CASCADE,
    name            text NOT NULL,
    position        integer NOT NULL,
    probability     smallint NOT NULL CHECK (probability BETWEEN 0 AND 100),
    kind            text NOT NULL DEFAULT 'open' CHECK (kind IN ('open', 'won', 'lost')),
    rotting_days    integer CHECK (rotting_days IS NULL OR rotting_days BETWEEN 1 AND 365),   -- NULL = the pipeline's
    required_fields text[] NOT NULL DEFAULT '{}',                                            -- deal columns or custom field keys a deal needs to ENTER
    ask_assistant   boolean NOT NULL DEFAULT false,                                          -- a dispatch when a deal enters
    colour          text CHECK (colour IS NULL OR colour ~ '^#[0-9a-fA-F]{6}$'),
    wip_limit       integer CHECK (wip_limit IS NULL OR wip_limit > 0),                      -- a warning, never a refusal
    archived_at     timestamptz,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    UNIQUE (pipeline_id, name),
    CHECK ((kind = 'won') = (probability = 100) OR kind = 'open'),
    CHECK (kind <> 'lost' OR probability = 0)
);
CREATE UNIQUE INDEX stages_one_won  ON stages (pipeline_id) WHERE kind = 'won';
CREATE UNIQUE INDEX stages_one_lost ON stages (pipeline_id) WHERE kind = 'lost';
CREATE INDEX stages_pipeline_pos_idx ON stages (pipeline_id, position);
CREATE TRIGGER stages_touch BEFORE UPDATE ON stages FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- Seeded (D6): New business and Renewals.
INSERT INTO pipelines (key, name, is_default, position) VALUES ('new_business', 'New business', true, 10), ('renewals', 'Renewals', false, 20);
INSERT INTO stages (pipeline_id, name, position, probability, kind, required_fields, colour)
SELECT p.id, s.name, s.pos, s.prob, s.kind, s.req, s.colour FROM pipelines p, (VALUES
  ('new_business', 'Qualification', 10, 10, 'open', '{}'::text[], '#6c757d'),
  ('new_business', 'Discovery',     20, 25, 'open', '{primary_contact_id}'::text[], '#0d6efd'),
  ('new_business', 'Proposal',      30, 50, 'open', '{primary_contact_id,value,expected_close_date}'::text[], '#6610f2'),
  ('new_business', 'Negotiation',   40, 75, 'open', '{primary_contact_id,value,expected_close_date}'::text[], '#fd7e14'),
  ('new_business', 'Won',           90, 100, 'won', '{}'::text[], '#198754'),
  ('new_business', 'Lost',          99, 0,  'lost', '{}'::text[], '#dc3545'),
  ('renewals', 'Upcoming',       10, 30, 'open', '{}'::text[], '#6c757d'),
  ('renewals', 'In discussion',  20, 60, 'open', '{primary_contact_id}'::text[], '#0d6efd'),
  ('renewals', 'Agreed',         30, 90, 'open', '{value,expected_close_date}'::text[], '#fd7e14'),
  ('renewals', 'Won',            90, 100, 'won', '{}'::text[], '#198754'),
  ('renewals', 'Lost',           99, 0,  'lost', '{}'::text[], '#dc3545')) AS s(pkey, name, pos, prob, kind, req, colour)
WHERE p.key = s.pkey;
UPDATE crm_settings SET default_pipeline_id = (SELECT id FROM pipelines WHERE key = 'new_business');

CREATE TABLE deals (
    id                    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number                text NOT NULL UNIQUE,
    title                 text NOT NULL,
    account_id            bigint REFERENCES accounts(id) ON DELETE SET NULL,
    primary_contact_id    bigint REFERENCES contacts(id) ON DELETE SET NULL,
    pipeline_id           bigint NOT NULL REFERENCES pipelines(id),
    stage_id              bigint NOT NULL REFERENCES stages(id),
    position              integer NOT NULL DEFAULT 0,                                   -- within the column (the drag's order)
    value                 numeric(14,2) NOT NULL DEFAULT 0 CHECK (value >= 0),
    currency              char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),              -- (a): recorded, never converted
    value_from_products   boolean NOT NULL DEFAULT false,                                -- D11: the value follows the lines
    probability_override  smallint CHECK (probability_override IS NULL OR probability_override BETWEEN 0 AND 100),
    probability           smallint NOT NULL DEFAULT 0,                                   -- kept by trigger: the override or the stage's
    weighted_value        numeric(14,2) NOT NULL DEFAULT 0,                              -- kept by trigger: value × probability / 100
    expected_close_date   date,
    status                text NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'won', 'lost')),   -- follows the stage's kind
    won_at                timestamptz,
    lost_at               timestamptz,
    lost_reason_id        bigint REFERENCES lost_reasons(id) ON DELETE SET NULL,
    lost_note             text,
    closed_by             bigint REFERENCES members(id) ON DELETE SET NULL,
    source_id             bigint REFERENCES lead_sources(id) ON DELETE SET NULL,
    campaign_id           bigint,                                                        -- FK added in db/012
    lead_id               bigint,                                                        -- the lead it came from; FK added in db/009
    owner_member_id       bigint REFERENCES members(id) ON DELETE SET NULL,
    stage_entered_at      timestamptz NOT NULL DEFAULT now(),
    last_touched_at       timestamptz,
    next_activity_id      bigint,                                                        -- kept by db/010's trigger; no FK (an activity may go)
    next_activity_at      timestamptz,
    description           text,
    restricted_to_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    restricted_to_department_id bigint REFERENCES departments(id) ON DELETE SET NULL,
    custom_values         jsonb NOT NULL DEFAULT '{}'::jsonb,
    deleted_at            timestamptz,
    deleted_by            bigint REFERENCES members(id) ON DELETE SET NULL,
    created_by            bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at            timestamptz NOT NULL DEFAULT now(),
    updated_at            timestamptz NOT NULL DEFAULT now(),
    CHECK ((status = 'won') = (won_at IS NOT NULL)),
    CHECK ((status = 'lost') = (lost_at IS NOT NULL))
);
CREATE INDEX deals_board_idx    ON deals (pipeline_id, stage_id, position) WHERE deleted_at IS NULL;
CREATE INDEX deals_owner_idx    ON deals (owner_member_id, status) WHERE deleted_at IS NULL;
CREATE INDEX deals_account_idx  ON deals (account_id) WHERE deleted_at IS NULL;
CREATE INDEX deals_contact_idx  ON deals (primary_contact_id);
CREATE INDEX deals_close_idx    ON deals (expected_close_date) WHERE status = 'open' AND deleted_at IS NULL;
CREATE INDEX deals_won_idx      ON deals (won_at) WHERE status = 'won';
CREATE INDEX deals_title_trgm_idx ON deals USING gin (title gin_trgm_ops);
CREATE INDEX deals_next_idx     ON deals (next_activity_at) WHERE status = 'open' AND deleted_at IS NULL;

CREATE TABLE deal_stage_history (
    id                     bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    deal_id                bigint NOT NULL REFERENCES deals(id) ON DELETE CASCADE,
    from_stage_id          bigint REFERENCES stages(id) ON DELETE SET NULL,
    to_stage_id            bigint NOT NULL REFERENCES stages(id) ON DELETE CASCADE,
    member_id              bigint REFERENCES members(id) ON DELETE SET NULL,
    at                     timestamptz NOT NULL DEFAULT now(),
    seconds_in_from_stage  bigint,
    source                 text NOT NULL DEFAULT 'web'
);
CREATE INDEX deal_stage_history_deal_idx ON deal_stage_history (deal_id, at);
CREATE INDEX deal_stage_history_to_idx ON deal_stage_history (to_stage_id, at);

CREATE TABLE deal_contacts (
    deal_id     bigint NOT NULL REFERENCES deals(id) ON DELETE CASCADE,
    contact_id  bigint NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
    role        text NOT NULL DEFAULT 'other' CHECK (role IN ('decision_maker', 'champion', 'influencer', 'user', 'finance', 'procurement', 'other')),
    is_primary  boolean NOT NULL DEFAULT false,
    added_at    timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (deal_id, contact_id)
);
CREATE INDEX deal_contacts_contact_idx ON deal_contacts (contact_id);

-- D11: lines by name with an optional provider id; no catalog of the CRM's own.
CREATE TABLE deal_products (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    deal_id          bigint NOT NULL REFERENCES deals(id) ON DELETE CASCADE,
    position         integer NOT NULL DEFAULT 0,
    name             text NOT NULL,
    provider_app     text CHECK (provider_app IS NULL OR provider_app IN ('inventory', 'gl')),
    provider_id      text,                                                                -- Inventory's variant id or the GL's item id, as text
    sku              text,
    quantity         numeric(12,3) NOT NULL DEFAULT 1 CHECK (quantity > 0),
    unit_price       numeric(14,4) NOT NULL DEFAULT 0 CHECK (unit_price >= 0),
    discount_percent numeric(5,2) NOT NULL DEFAULT 0 CHECK (discount_percent BETWEEN 0 AND 100),
    line_total       numeric(14,2) GENERATED ALWAYS AS (round(quantity * unit_price * (1 - discount_percent / 100), 2)) STORED,
    recurring        text NOT NULL DEFAULT 'none' CHECK (recurring IN ('none', 'monthly', 'yearly')),
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    CHECK ((provider_app IS NULL) = (provider_id IS NULL))
);
CREATE INDEX deal_products_deal_idx ON deal_products (deal_id, position);
CREATE TRIGGER deal_products_touch BEFORE UPDATE ON deal_products FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- The quota per seller per month (the forecast's line; the leaderboard).
CREATE TABLE quotas (
    member_id  bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    month      date NOT NULL CHECK (month = date_trunc('month', month)::date),
    amount     numeric(14,2) NOT NULL CHECK (amount >= 0),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (member_id, month)
);
CREATE TRIGGER quotas_touch BEFORE UPDATE ON quotas FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- A stage's effective rotting days: its own, the pipeline's, the settings'.
CREATE OR REPLACE FUNCTION crm_stage_rotting_days(p_stage bigint) RETURNS integer
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE(s.rotting_days, p.rotting_days, crm_setting_int('rotting_days')::integer) FROM stages s JOIN pipelines p ON p.id = s.pipeline_id WHERE s.id = p_stage;
$$;

-- The fields a deal lacks to enter a stage (D6): deal columns by name, custom fields by key. Empty = may enter.
CREATE OR REPLACE FUNCTION crm_stage_missing_fields(p_deal deals, p_stage bigint) RETURNS text[]
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE f text; missing text[] := '{}'; req text[]; v text;
BEGIN
    SELECT required_fields INTO req FROM stages WHERE id = p_stage;
    IF req IS NULL THEN RETURN '{}'; END IF;
    FOREACH f IN ARRAY req LOOP
        CASE f
            WHEN 'primary_contact_id' THEN IF p_deal.primary_contact_id IS NULL THEN missing := missing || f; END IF;
            WHEN 'account_id' THEN IF p_deal.account_id IS NULL THEN missing := missing || f; END IF;
            WHEN 'value' THEN IF p_deal.value <= 0 THEN missing := missing || f; END IF;
            WHEN 'expected_close_date' THEN IF p_deal.expected_close_date IS NULL THEN missing := missing || f; END IF;
            WHEN 'description' THEN IF COALESCE(p_deal.description, '') = '' THEN missing := missing || f; END IF;
            WHEN 'source_id' THEN IF p_deal.source_id IS NULL THEN missing := missing || f; END IF;
            WHEN 'products' THEN IF NOT EXISTS (SELECT 1 FROM deal_products WHERE deal_id = p_deal.id) THEN missing := missing || f; END IF;
            ELSE
                v := p_deal.custom_values ->> f;
                IF v IS NULL OR v = '' THEN missing := missing || f; END IF;
        END CASE;
    END LOOP;
    RETURN missing;
END$$;

-- BEFORE: the number, the currency, the stage's pipeline, probability and weighted value, status from the stage's kind, the
-- required-fields rule on a stage change (refused with the fields named), the value from the lines, custom values, the restriction.
CREATE OR REPLACE FUNCTION crm_deals_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE st stages%ROWTYPE; miss text[]; lines numeric;
BEGIN
    IF TG_OP = 'INSERT' THEN
        NEW.number := COALESCE(NEW.number, crm_next_number('deal'));
        NEW.currency := COALESCE(NEW.currency, (SELECT COALESCE(p.currency, crm_setting_text('currency')) FROM pipelines p WHERE p.id = NEW.pipeline_id));
        NEW.stage_entered_at := COALESCE(NEW.stage_entered_at, now());
    END IF;
    NEW.title := btrim(NEW.title);
    IF NEW.title = '' THEN RAISE EXCEPTION 'A deal needs a title' USING ERRCODE = 'check_violation'; END IF;
    SELECT * INTO st FROM stages WHERE id = NEW.stage_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such stage' USING ERRCODE = 'foreign_key_violation'; END IF;
    IF st.pipeline_id <> NEW.pipeline_id THEN RAISE EXCEPTION 'The stage belongs to another pipeline' USING ERRCODE = 'check_violation'; END IF;
    IF st.archived_at IS NOT NULL AND (TG_OP = 'INSERT' OR NEW.stage_id <> OLD.stage_id) THEN RAISE EXCEPTION 'The stage is archived' USING ERRCODE = 'check_violation'; END IF;
    IF NEW.value_from_products THEN
        SELECT COALESCE(sum(line_total), 0) INTO lines FROM deal_products WHERE deal_id = NEW.id;
        IF TG_OP = 'UPDATE' THEN NEW.value := lines; END IF;
    END IF;
    IF TG_OP = 'INSERT' OR NEW.stage_id <> OLD.stage_id THEN
        miss := crm_stage_missing_fields(NEW, NEW.stage_id);
        IF cardinality(miss) > 0 AND st.kind = 'open' THEN
            RAISE EXCEPTION 'The stage % needs: %', st.name, array_to_string(miss, ', ') USING ERRCODE = 'check_violation', HINT = array_to_string(miss, ',');
        END IF;
        IF TG_OP = 'UPDATE' THEN NEW.stage_entered_at := now(); END IF;
    END IF;
    -- status follows the stage's kind; won/lost stamps; a reopen clears them
    IF st.kind = 'won' THEN
        NEW.status := 'won'; NEW.won_at := COALESCE(NEW.won_at, now()); NEW.lost_at := NULL; NEW.lost_reason_id := NULL; NEW.lost_note := NULL;
        NEW.closed_by := COALESCE(NEW.closed_by, app_current_member_id());
    ELSIF st.kind = 'lost' THEN
        NEW.status := 'lost'; NEW.lost_at := COALESCE(NEW.lost_at, now()); NEW.won_at := NULL;
        NEW.closed_by := COALESCE(NEW.closed_by, app_current_member_id());
        IF NEW.lost_reason_id IS NULL THEN RAISE EXCEPTION 'A lost deal needs a reason' USING ERRCODE = 'check_violation'; END IF;
    ELSE
        NEW.status := 'open'; NEW.won_at := NULL; NEW.lost_at := NULL; NEW.closed_by := NULL;
        IF TG_OP = 'UPDATE' AND OLD.status <> 'open' THEN NEW.lost_reason_id := NULL; NEW.lost_note := NULL; END IF;   -- a reopen clears the reason; a reason set before the move stays
    END IF;
    NEW.probability := COALESCE(NEW.probability_override, st.probability);
    IF st.kind <> 'open' THEN NEW.probability := st.probability; END IF;
    NEW.weighted_value := round(NEW.value * NEW.probability / 100.0, 2);
    IF NEW.restricted_to_member_id IS NOT NULL OR NEW.restricted_to_department_id IS NOT NULL THEN
        IF NOT crm_setting_bool('allow_restricted_records') THEN RAISE EXCEPTION 'Restricted records are off in the settings' USING ERRCODE = 'check_violation'; END IF;
    END IF;
    PERFORM crm_validate_custom_values('deal', NEW.custom_values);
    RETURN NEW;
END$$;
CREATE TRIGGER deals_before BEFORE INSERT OR UPDATE ON deals FOR EACH ROW EXECUTE FUNCTION crm_deals_before();
CREATE TRIGGER deals_touch BEFORE UPDATE ON deals FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- AFTER: the history row on a stage change (with the seconds in the stage left), the account's counters and kind, the contact's
-- lifecycle, the primary contact in deal_contacts.
CREATE OR REPLACE FUNCTION crm_deals_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        INSERT INTO deal_stage_history (deal_id, from_stage_id, to_stage_id, member_id, at, source)
        VALUES (NEW.id, NULL, NEW.stage_id, app_current_member_id(), NEW.stage_entered_at, COALESCE(current_setting('app.source', true), 'web'));
    ELSIF NEW.stage_id <> OLD.stage_id THEN
        INSERT INTO deal_stage_history (deal_id, from_stage_id, to_stage_id, member_id, at, seconds_in_from_stage, source)
        VALUES (NEW.id, OLD.stage_id, NEW.stage_id, app_current_member_id(), NEW.stage_entered_at, GREATEST(0, extract(epoch FROM (NEW.stage_entered_at - OLD.stage_entered_at)))::bigint, COALESCE(current_setting('app.source', true), 'web'));
    END IF;
    IF NEW.primary_contact_id IS NOT NULL AND (TG_OP = 'INSERT' OR NEW.primary_contact_id IS DISTINCT FROM OLD.primary_contact_id) THEN
        UPDATE deal_contacts SET is_primary = false WHERE deal_id = NEW.id AND is_primary;
        INSERT INTO deal_contacts (deal_id, contact_id, role, is_primary) VALUES (NEW.id, NEW.primary_contact_id, COALESCE((SELECT role FROM contacts WHERE id = NEW.primary_contact_id), 'other'), true)
        ON CONFLICT (deal_id, contact_id) DO UPDATE SET is_primary = true;
    END IF;
    PERFORM crm_account_recount(NEW.account_id);
    IF TG_OP = 'UPDATE' AND OLD.account_id IS DISTINCT FROM NEW.account_id THEN PERFORM crm_account_recount(OLD.account_id); END IF;
    PERFORM crm_contact_lifecycle(NEW.primary_contact_id);
    RETURN NULL;
END$$;
CREATE TRIGGER deals_after AFTER INSERT OR UPDATE ON deals FOR EACH ROW EXECUTE FUNCTION crm_deals_after();

-- An account's open-deal counters and its derived kind (customer once a deal is won; prospect while none; former by hand later).
CREATE OR REPLACE FUNCTION crm_account_recount(p_account bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE won numeric; n integer; v numeric;
BEGIN
    IF p_account IS NULL THEN RETURN; END IF;
    SELECT count(*) FILTER (WHERE status = 'open'), COALESCE(sum(value) FILTER (WHERE status = 'open'), 0), COALESCE(sum(value) FILTER (WHERE status = 'won'), 0)
      INTO n, v, won FROM deals WHERE account_id = p_account AND deleted_at IS NULL;
    UPDATE accounts SET open_deals_count = n, open_deals_value = v, won_deals_value = won,
           kind = CASE WHEN kind_set_by_hand THEN kind WHEN kind = 'former' THEN kind WHEN won > 0 THEN 'customer' ELSE 'prospect' END
     WHERE id = p_account;
END$$;

-- A contact's derived lifecycle: customer on an account with a won deal, prospect with an open deal or none, churned on a former account, lead while an unconverted lead points at them.
CREATE OR REPLACE FUNCTION crm_contact_lifecycle(p_contact bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE lc text;
BEGIN
    IF p_contact IS NULL THEN RETURN; END IF;
    SELECT CASE
             WHEN a.kind = 'former' THEN 'churned'
             WHEN a.kind = 'customer' OR EXISTS (SELECT 1 FROM deals d WHERE d.status = 'won' AND d.deleted_at IS NULL AND (d.primary_contact_id = c.id OR d.id IN (SELECT deal_id FROM deal_contacts WHERE contact_id = c.id))) THEN 'customer'
             WHEN EXISTS (SELECT 1 FROM deals d WHERE d.status = 'open' AND d.deleted_at IS NULL AND (d.primary_contact_id = c.id OR d.id IN (SELECT deal_id FROM deal_contacts WHERE contact_id = c.id))) THEN 'prospect'
             WHEN EXISTS (SELECT 1 FROM leads l WHERE l.converted_contact_id = c.id AND l.converted_at IS NULL) THEN 'lead'
             ELSE 'prospect' END
      INTO lc FROM contacts c LEFT JOIN accounts a ON a.id = c.account_id WHERE c.id = p_contact;
    UPDATE contacts SET lifecycle = lc WHERE id = p_contact AND lifecycle <> lc;
END$$;

-- Product lines keep the deal's value when value_from_products.
CREATE OR REPLACE FUNCTION crm_deal_products_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE d bigint := COALESCE(NEW.deal_id, OLD.deal_id);
BEGIN
    UPDATE deals SET value = (SELECT COALESCE(sum(line_total), 0) FROM deal_products WHERE deal_id = d) WHERE id = d AND value_from_products;
    RETURN NULL;
END$$;
CREATE TRIGGER deal_products_after AFTER INSERT OR UPDATE OR DELETE ON deal_products FOR EACH ROW EXECUTE FUNCTION crm_deal_products_after();

-- The board's move (D7): a new stage and a position; the trigger enforces the fields; the others in the column shift.
CREATE OR REPLACE FUNCTION crm_deal_move(p_deal bigint, p_stage bigint, p_position integer DEFAULT NULL) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE pos integer;
BEGIN
    pos := COALESCE(p_position, 0);
    UPDATE deals SET position = position + 1 WHERE stage_id = p_stage AND position >= pos AND id <> p_deal AND deleted_at IS NULL;
    UPDATE deals SET stage_id = p_stage, position = pos WHERE id = p_deal;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such deal' USING ERRCODE = 'no_data_found'; END IF;
END$$;

-- Is a deal rotting (D6)? Open, in its stage longer than the effective rotting days.
CREATE OR REPLACE FUNCTION crm_deal_is_rotting(p_status text, p_stage bigint, p_entered timestamptz) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT p_status = 'open' AND crm_stage_rotting_days(p_stage) IS NOT NULL AND p_entered < now() - make_interval(days => crm_stage_rotting_days(p_stage));
$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON pipelines, stages, deals, deal_stage_history, deal_contacts, deal_products, quotas TO sales_crm_rw;
GRANT EXECUTE ON FUNCTION crm_stage_rotting_days(bigint), crm_stage_missing_fields(deals, bigint), crm_deal_is_rotting(text, bigint, timestamptz) TO sales_crm_rw, sales_crm_records_ro;
REVOKE ALL ON FUNCTION crm_deal_move(bigint, bigint, integer), crm_account_recount(bigint), crm_contact_lifecycle(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION crm_deal_move(bigint, bigint, integer), crm_account_recount(bigint), crm_contact_lifecycle(bigint) TO sales_crm_rw;

COMMIT;
