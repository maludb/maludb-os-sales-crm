-- 009: LEADS, their SOURCES' rules and the CONVERSION (design §6.1; D3). A lead is one row: a person and the company they are at,
-- from a source, owned by a seller, in a status (new → working → qualified | unqualified). Converting makes or finds an account
-- (by domain, then name), a contact (by email) and, if asked, a deal — in one transaction; the lead becomes qualified and read-only,
-- pointing at the three. A lead is never on the board.
--
-- THE ESTATE'S DATA MODEL (design §6.5): `leads`, `assignment_rules` are NEW CANONICAL (nothing in the estate qualifies anyone).
BEGIN;

CREATE TABLE leads (
    id                    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    first_name            text,
    last_name             text,
    company               text,
    title                 text,
    email                 citext,
    phone                 text,
    website               text,
    address               text,
    city                  text,
    region                text,
    country               char(2) CHECK (country IS NULL OR country ~ '^[A-Z]{2}$'),
    message               text,                                                        -- what the form or the mailbox said
    source_id             bigint REFERENCES lead_sources(id) ON DELETE SET NULL,
    campaign_id           bigint,                                                      -- FK added in db/012
    utm                   jsonb NOT NULL DEFAULT '{}'::jsonb,                           -- utm_source/medium/campaign/term/content from the form
    status                text NOT NULL DEFAULT 'new' CHECK (status IN ('new', 'working', 'qualified', 'unqualified')),
    unqualified_reason_id bigint REFERENCES lost_reasons(id) ON DELETE SET NULL,
    unqualified_note      text,
    score                 smallint CHECK (score IS NULL OR score BETWEEN 0 AND 100),  -- the Assistant's proposal or a rule; shown, never a gate
    owner_member_id       bigint REFERENCES members(id) ON DELETE SET NULL,
    last_touched_at       timestamptz,
    next_activity_at      timestamptz,
    converted_at          timestamptz,
    converted_by          bigint REFERENCES members(id) ON DELETE SET NULL,
    converted_account_id  bigint REFERENCES accounts(id) ON DELETE SET NULL,
    converted_contact_id  bigint REFERENCES contacts(id) ON DELETE SET NULL,
    converted_deal_id     bigint REFERENCES deals(id) ON DELETE SET NULL,
    proposed_from_email_id bigint,                                                     -- a lead the mailbox proposed; FK added in db/011
    is_proposed           boolean NOT NULL DEFAULT false,                              -- in the inbox until a seller accepts or dismisses
    restricted_to_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    restricted_to_department_id bigint REFERENCES departments(id) ON DELETE SET NULL,
    custom_values         jsonb NOT NULL DEFAULT '{}'::jsonb,
    deleted_at            timestamptz,
    deleted_by            bigint REFERENCES members(id) ON DELETE SET NULL,
    created_by            bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at            timestamptz NOT NULL DEFAULT now(),
    updated_at            timestamptz NOT NULL DEFAULT now(),
    CHECK (first_name IS NOT NULL OR last_name IS NOT NULL OR company IS NOT NULL OR email IS NOT NULL),
    CHECK ((status = 'qualified') = (converted_at IS NOT NULL)),
    CHECK (status <> 'unqualified' OR unqualified_reason_id IS NOT NULL)
);
CREATE INDEX leads_status_idx ON leads (status, owner_member_id) WHERE deleted_at IS NULL;
CREATE INDEX leads_owner_idx ON leads (owner_member_id) WHERE deleted_at IS NULL;
CREATE INDEX leads_email_idx ON leads (email);
CREATE INDEX leads_touched_idx ON leads (last_touched_at) WHERE deleted_at IS NULL AND status IN ('new', 'working');
CREATE INDEX leads_source_idx ON leads (source_id);
CREATE INDEX leads_name_trgm_idx ON leads USING gin ((COALESCE(first_name, '') || ' ' || COALESCE(last_name, '') || ' ' || COALESCE(company, '')) gin_trgm_ops);
ALTER TABLE deals ADD CONSTRAINT deals_lead_fk FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL;
ALTER TABLE mcp_access_tokens ADD CONSTRAINT mcp_access_tokens_lead_source_fk FOREIGN KEY (lead_source_id) REFERENCES lead_sources(id) ON DELETE SET NULL;

-- The lead's display label (resolvers, cards).
CREATE OR REPLACE FUNCTION crm_lead_label(l leads) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE WHEN NULLIF(btrim(COALESCE(l.first_name, '') || ' ' || COALESCE(l.last_name, '')), '') IS NULL THEN COALESCE(l.company, l.email::text, 'Lead ' || l.id)
                ELSE btrim(COALESCE(l.first_name, '') || ' ' || COALESCE(l.last_name, '')) || COALESCE(' · ' || l.company, '') END;
$$;

CREATE TABLE assignment_rules (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name        text NOT NULL,
    position    integer NOT NULL DEFAULT 100,
    active      boolean NOT NULL DEFAULT true,
    -- the bounded vocabulary: {"source_id": [..], "country": ["US"], "campaign_id": [..], "domain_suffix": [".edu"], "utm_source": [..]}
    match       jsonb NOT NULL DEFAULT '{}'::jsonb,
    assign_kind text NOT NULL CHECK (assign_kind IN ('member', 'department', 'round_robin')),
    member_id   bigint REFERENCES members(id) ON DELETE SET NULL,
    department_id bigint REFERENCES departments(id) ON DELETE SET NULL,
    members     bigint[] NOT NULL DEFAULT '{}',                                        -- round_robin: in turn
    last_index  integer NOT NULL DEFAULT -1,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    CHECK ((assign_kind = 'member') = (member_id IS NOT NULL)),
    CHECK ((assign_kind = 'department') = (department_id IS NOT NULL)),
    CHECK (assign_kind <> 'round_robin' OR cardinality(members) > 0)
);
CREATE TRIGGER assignment_rules_touch BEFORE UPDATE ON assignment_rules FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- The first active rule a lead matches decides its owner (a department → its manager); none → unowned (the inbox).
CREATE OR REPLACE FUNCTION crm_assign_lead(p_lead leads) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r assignment_rules%ROWTYPE; m jsonb; dom text; idx integer; who bigint;
BEGIN
    dom := CASE WHEN p_lead.email IS NOT NULL THEN lower(split_part(p_lead.email::text, '@', 2)) END;
    FOR r IN SELECT * FROM assignment_rules WHERE active ORDER BY position, id LOOP
        m := r.match;
        CONTINUE WHEN m ? 'source_id' AND NOT (m -> 'source_id') @> to_jsonb(p_lead.source_id);
        CONTINUE WHEN m ? 'campaign_id' AND NOT (m -> 'campaign_id') @> to_jsonb(p_lead.campaign_id);
        CONTINUE WHEN m ? 'country' AND NOT (m -> 'country') @> to_jsonb(p_lead.country);
        CONTINUE WHEN m ? 'utm_source' AND NOT (m -> 'utm_source') @> to_jsonb(p_lead.utm ->> 'utm_source');
        CONTINUE WHEN m ? 'domain_suffix' AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements_text(m -> 'domain_suffix') s WHERE dom IS NOT NULL AND dom LIKE '%' || s);
        IF r.assign_kind = 'member' THEN RETURN r.member_id;
        ELSIF r.assign_kind = 'department' THEN SELECT manager_member_id INTO who FROM departments WHERE id = r.department_id; RETURN who;
        ELSE
            idx := (r.last_index + 1) % cardinality(r.members);
            UPDATE assignment_rules SET last_index = idx WHERE id = r.id;
            RETURN r.members[idx + 1];
        END IF;
    END LOOP;
    RETURN NULL;
END$$;

CREATE OR REPLACE FUNCTION crm_leads_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    NEW.first_name := NULLIF(btrim(COALESCE(NEW.first_name, '')), '');
    NEW.last_name := NULLIF(btrim(COALESCE(NEW.last_name, '')), '');
    NEW.company := NULLIF(btrim(COALESCE(NEW.company, '')), '');
    IF NEW.email IS NOT NULL AND NEW.email::text !~ '^[^@\s]+@[^@\s]+\.[^@\s]+$' THEN RAISE EXCEPTION 'Not an email address: %', NEW.email USING ERRCODE = 'check_violation'; END IF;
    IF TG_OP = 'UPDATE' AND OLD.converted_at IS NOT NULL AND NEW.converted_at IS NOT NULL
       AND (NEW.first_name, NEW.last_name, NEW.company, NEW.email, NEW.phone, NEW.status, NEW.source_id) IS DISTINCT FROM (OLD.first_name, OLD.last_name, OLD.company, OLD.email, OLD.phone, OLD.status, OLD.source_id) THEN
        RAISE EXCEPTION 'A converted lead is read-only' USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'INSERT' AND NEW.owner_member_id IS NULL AND NOT NEW.is_proposed THEN NEW.owner_member_id := crm_assign_lead(NEW); END IF;
    IF TG_OP = 'UPDATE' AND NEW.status = 'working' AND OLD.status = 'new' THEN NEW.last_touched_at := COALESCE(NEW.last_touched_at, now()); END IF;
    IF NEW.restricted_to_member_id IS NOT NULL OR NEW.restricted_to_department_id IS NOT NULL THEN
        IF NOT crm_setting_bool('allow_restricted_records') THEN RAISE EXCEPTION 'Restricted records are off in the settings' USING ERRCODE = 'check_violation'; END IF;
    END IF;
    PERFORM crm_validate_custom_values('lead', NEW.custom_values);
    RETURN NEW;
END$$;
CREATE TRIGGER leads_before BEFORE INSERT OR UPDATE ON leads FOR EACH ROW EXECUTE FUNCTION crm_leads_before();
CREATE TRIGGER leads_touch BEFORE UPDATE ON leads FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- THE CONVERSION (D3), one transaction. p_account: NULL = find by domain then by name, else make; a given id = that account.
-- p_contact likewise by email. p_make_deal with the pipeline (NULL = the default), a title (NULL = "<company> — <first deal>"),
-- a value and a close date. Answers the three ids. A converted lead is refused a second time.
CREATE OR REPLACE FUNCTION crm_lead_convert(p_lead bigint, p_account bigint DEFAULT NULL, p_contact bigint DEFAULT NULL, p_make_deal boolean DEFAULT true,
                                            p_pipeline bigint DEFAULT NULL, p_deal_title text DEFAULT NULL, p_value numeric DEFAULT NULL, p_close date DEFAULT NULL)
    RETURNS TABLE (account_id bigint, contact_id bigint, deal_id bigint, account_made boolean, contact_made boolean)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE l leads%ROWTYPE; a bigint := p_account; c bigint := p_contact; d bigint; dom text; pipe bigint; st bigint; a_made boolean := false; c_made boolean := false; me bigint := app_current_member_id();
BEGIN
    SELECT * INTO l FROM leads WHERE id = p_lead AND deleted_at IS NULL FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such lead' USING ERRCODE = 'no_data_found'; END IF;
    IF l.converted_at IS NOT NULL THEN RAISE EXCEPTION 'The lead was converted already' USING ERRCODE = 'check_violation'; END IF;
    IF l.status = 'unqualified' THEN RAISE EXCEPTION 'An unqualified lead is not converted; requalify it first' USING ERRCODE = 'check_violation'; END IF;
    dom := CASE WHEN l.email IS NOT NULL THEN lower(split_part(l.email::text, '@', 2)) END;
    IF dom IN ('gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'icloud.com', 'aol.com', 'proton.me', 'protonmail.com', 'live.com', 'me.com') THEN dom := NULL; END IF;
    -- the account
    IF a IS NULL AND (l.company IS NOT NULL OR dom IS NOT NULL) THEN
        IF dom IS NOT NULL THEN SELECT id INTO a FROM accounts WHERE domain = dom AND deleted_at IS NULL AND merged_into_id IS NULL; END IF;
        IF a IS NULL AND l.company IS NOT NULL THEN SELECT id INTO a FROM accounts WHERE lower(name) = lower(l.company) AND deleted_at IS NULL AND merged_into_id IS NULL ORDER BY id LIMIT 1; END IF;
        IF a IS NULL THEN
            INSERT INTO accounts (name, domain, website, phone, billing_address, city, region, country, owner_member_id, source_id, created_by)
            VALUES (COALESCE(l.company, dom), dom, l.website, CASE WHEN l.first_name IS NULL AND l.last_name IS NULL THEN l.phone END, l.address, l.city, l.region, l.country, COALESCE(l.owner_member_id, me), l.source_id, me)
            RETURNING id INTO a; a_made := true;
        END IF;
    END IF;
    -- the contact
    IF c IS NULL AND (l.first_name IS NOT NULL OR l.last_name IS NOT NULL OR l.email IS NOT NULL) THEN
        IF l.email IS NOT NULL THEN c := crm_contact_by_address(l.email); END IF;
        IF c IS NULL THEN
            INSERT INTO contacts (account_id, first_name, last_name, title, email, phone, address, city, region, country, owner_member_id, source_id, created_by)
            VALUES (a, l.first_name, l.last_name, l.title, l.email, l.phone, l.address, l.city, l.region, l.country, COALESCE(l.owner_member_id, me), l.source_id, me)
            RETURNING id INTO c; c_made := true;
        ELSIF a IS NOT NULL THEN
            UPDATE contacts SET account_id = COALESCE(contacts.account_id, a) WHERE id = c;
        END IF;
    END IF;
    IF a IS NULL AND c IS NULL THEN RAISE EXCEPTION 'Nothing to convert: the lead names no company and no person' USING ERRCODE = 'check_violation'; END IF;
    -- the deal
    IF p_make_deal THEN
        pipe := COALESCE(p_pipeline, crm_setting_int('default_pipeline_id'), (SELECT id FROM pipelines WHERE is_default));
        SELECT id INTO st FROM stages WHERE pipeline_id = pipe AND kind = 'open' AND archived_at IS NULL ORDER BY position LIMIT 1;
        IF st IS NULL THEN RAISE EXCEPTION 'The pipeline has no open stage' USING ERRCODE = 'check_violation'; END IF;
        INSERT INTO deals (title, account_id, primary_contact_id, pipeline_id, stage_id, value, expected_close_date, source_id, campaign_id, lead_id, owner_member_id, created_by)
        VALUES (COALESCE(p_deal_title, COALESCE((SELECT name FROM accounts WHERE id = a), l.company, btrim(COALESCE(l.first_name, '') || ' ' || COALESCE(l.last_name, ''))) || ' — new business'),
                a, c, pipe, st, COALESCE(p_value, 0), p_close, l.source_id, l.campaign_id, p_lead, COALESCE(l.owner_member_id, me), me)
        RETURNING id INTO d;
    END IF;
    UPDATE leads SET status = 'qualified', converted_at = now(), converted_by = me, converted_account_id = a, converted_contact_id = c, converted_deal_id = d, is_proposed = false WHERE id = p_lead;
    PERFORM crm_contact_lifecycle(c);
    RETURN QUERY SELECT a, c, d, a_made, c_made;
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON leads, assignment_rules TO sales_crm_rw;
GRANT EXECUTE ON FUNCTION crm_lead_label(leads) TO sales_crm_rw, sales_crm_records_ro, sales_crm_activity_ro;
REVOKE ALL ON FUNCTION crm_assign_lead(leads), crm_lead_convert(bigint, bigint, bigint, boolean, bigint, text, numeric, date) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION crm_assign_lead(leads), crm_lead_convert(bigint, bigint, bigint, boolean, bigint, text, numeric, date) TO sales_crm_rw;

COMMIT;
