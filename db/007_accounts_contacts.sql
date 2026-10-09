-- 007: ACCOUNTS and CONTACTS — the relationship — and THE WALL every record is judged by (design §3, §6.1; D5).
--
-- THE ESTATE'S DATA MODEL (design §6.5): `accounts`, `contacts`, `contact_emails` are NEW CANONICAL. Compared: GL/Inventory
-- `customers` (a billing party — read through K7 once a deal is won; the shared column names name, legal_name, email, phone,
-- billing_address, shipping_address, currency are kept so the ledger's import maps by name), Help Desk `organizations`/`requesters`
-- (support's view of the same companies and people — read; `merged_into_id` is theirs), Consultant Tracking `clients`/
-- `client_contacts` (an engagement's client; `name`, `email`, `phone`, `role`, `is_primary` kept). A company at every point of a
-- relationship, and a person who may belong to no company, are new.
--
-- THE WALL (D5): OPEN BY DEFAULT — every admitted member sees every record with amounts. A manager may RESTRICT a record to its
-- owner and a department (restricted_to_member_id / restricted_to_department_id; both NULL = open). crm_wall(owner, r_member, r_dept)
-- is the one rule, inlined per row by every view: it reads the caller's facts through STABLE functions evaluated once per statement
-- (the kernel's db/160 lesson). EDITING is ownership: the owner, an unowned record, a manager (deals.any), the admin. A tombstone
-- (deleted_at) hides a record from every list for tombstone_days, then the worker purges it.
BEGIN;

-- The caller's department ids, once per statement.
CREATE OR REPLACE FUNCTION crm_my_department_ids() RETURNS bigint[]
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE(array_agg(d), '{}') FROM app_member_department_ids() d;
$$;

-- THE WALL: may the caller see a record with these three facts?
CREATE OR REPLACE FUNCTION crm_wall(p_owner bigint, p_r_member bigint, p_r_dept bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT app_is_active_member()
       AND ((p_r_member IS NULL AND p_r_dept IS NULL)
            OR p_owner = app_current_member_id()
            OR p_r_member = app_current_member_id()
            OR (p_r_dept IS NOT NULL AND p_r_dept = ANY (crm_my_department_ids()))
            OR crm_is_manager());
$$;

-- May the caller EDIT a record they may see? The owner, an unowned record (with the kind's write right), a manager, the admin.
CREATE OR REPLACE FUNCTION crm_edit_wall(p_owner bigint, p_write_right text) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT crm_is_manager() OR (crm_has_right(p_write_right) AND (p_owner IS NULL OR p_owner = app_current_member_id()));
$$;

CREATE TABLE accounts (
    id                    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name                  text NOT NULL,
    legal_name            text,
    domain                citext CHECK (domain IS NULL OR domain ~ '^[a-z0-9.-]+\.[a-z]{2,}$'),     -- the dedupe key
    website               text,
    industry              text,
    employees             text CHECK (employees IS NULL OR employees IN ('1-10', '11-50', '51-200', '201-500', '501-1000', '1001-5000', '5000+')),
    annual_revenue        numeric(16,2) CHECK (annual_revenue IS NULL OR annual_revenue >= 0),
    currency              char(3) CHECK (currency IS NULL OR currency ~ '^[A-Z]{3}$'),             -- NULL = the base currency
    phone                 text,
    email                 citext,
    billing_address       text,
    shipping_address      text,
    city                  text,
    region                text,
    country               char(2) CHECK (country IS NULL OR country ~ '^[A-Z]{2}$'),
    kind                  text NOT NULL DEFAULT 'prospect' CHECK (kind IN ('prospect', 'customer', 'partner', 'former', 'other')),
    kind_set_by_hand      boolean NOT NULL DEFAULT false,                                           -- partner/other are a person's; prospect/customer/former derive
    parent_account_id     bigint REFERENCES accounts(id) ON DELETE SET NULL,
    owner_member_id       bigint REFERENCES members(id) ON DELETE SET NULL,
    source_id             bigint REFERENCES lead_sources(id) ON DELETE SET NULL,
    description           text,
    gl_customer_id        bigint,                                                                   -- the ledger's id (K7 gl.customer_by_crm_account); no FK
    helpdesk_organization_id bigint,                                                                -- Help Desk's id (HD5); no FK
    last_touched_at       timestamptz,
    next_activity_at      timestamptz,                                                              -- kept by db/010's trigger
    open_deals_count      integer NOT NULL DEFAULT 0,
    open_deals_value      numeric(16,2) NOT NULL DEFAULT 0,                                         -- kept by db/008's trigger, base currency
    won_deals_value       numeric(16,2) NOT NULL DEFAULT 0,
    restricted_to_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    restricted_to_department_id bigint REFERENCES departments(id) ON DELETE SET NULL,
    merged_into_id        bigint REFERENCES accounts(id) ON DELETE SET NULL,
    custom_values         jsonb NOT NULL DEFAULT '{}'::jsonb,
    deleted_at            timestamptz,
    deleted_by            bigint REFERENCES members(id) ON DELETE SET NULL,
    created_by            bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at            timestamptz NOT NULL DEFAULT now(),
    updated_at            timestamptz NOT NULL DEFAULT now(),
    CHECK (parent_account_id IS NULL OR parent_account_id <> id)
);
CREATE UNIQUE INDEX accounts_domain_uq ON accounts (domain) WHERE domain IS NOT NULL AND deleted_at IS NULL AND merged_into_id IS NULL;
CREATE INDEX accounts_name_trgm_idx ON accounts USING gin (name gin_trgm_ops);
CREATE INDEX accounts_owner_idx ON accounts (owner_member_id) WHERE deleted_at IS NULL;
CREATE INDEX accounts_kind_idx ON accounts (kind) WHERE deleted_at IS NULL;
CREATE INDEX accounts_touched_idx ON accounts (last_touched_at) WHERE deleted_at IS NULL;
CREATE INDEX accounts_parent_idx ON accounts (parent_account_id);

CREATE OR REPLACE FUNCTION crm_accounts_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    NEW.name := btrim(NEW.name);
    IF NEW.name = '' THEN RAISE EXCEPTION 'An account needs a name' USING ERRCODE = 'check_violation'; END IF;
    IF NEW.domain IS NULL AND NEW.website IS NOT NULL THEN
        NEW.domain := lower(regexp_replace(regexp_replace(NEW.website, '^https?://(www\.)?', ''), '[/:?#].*$', ''));
        IF NEW.domain !~ '^[a-z0-9.-]+\.[a-z]{2,}$' THEN NEW.domain := NULL; END IF;
    END IF;
    IF NEW.domain IS NOT NULL THEN NEW.domain := lower(regexp_replace(NEW.domain, '^www\.', '')); END IF;
    IF TG_OP = 'UPDATE' AND NEW.kind IS DISTINCT FROM OLD.kind AND NEW.kind IN ('partner', 'other') THEN NEW.kind_set_by_hand := true; END IF;
    IF TG_OP = 'UPDATE' AND NEW.kind IS DISTINCT FROM OLD.kind AND NEW.kind NOT IN ('partner', 'other') THEN NEW.kind_set_by_hand := false; END IF;
    IF TG_OP = 'INSERT' AND NEW.kind IN ('partner', 'other') THEN NEW.kind_set_by_hand := true; END IF;
    IF NEW.restricted_to_member_id IS NOT NULL OR NEW.restricted_to_department_id IS NOT NULL THEN
        IF NOT crm_setting_bool('allow_restricted_records') THEN RAISE EXCEPTION 'Restricted records are off in the settings' USING ERRCODE = 'check_violation'; END IF;
    END IF;
    PERFORM crm_validate_custom_values('account', NEW.custom_values);
    RETURN NEW;
END$$;
CREATE TRIGGER accounts_before BEFORE INSERT OR UPDATE ON accounts FOR EACH ROW EXECUTE FUNCTION crm_accounts_before();
CREATE TRIGGER accounts_touch BEFORE UPDATE ON accounts FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE contacts (
    id                    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    account_id            bigint REFERENCES accounts(id) ON DELETE SET NULL,
    first_name            text,
    last_name             text,
    name                  text GENERATED ALWAYS AS (btrim(COALESCE(first_name, '') || ' ' || COALESCE(last_name, ''))) STORED,
    title                 text,
    department_text       text,
    email                 citext,
    email_alt             citext,
    phone                 text,
    mobile                text,
    role                  text CHECK (role IS NULL OR role IN ('decision_maker', 'champion', 'influencer', 'user', 'finance', 'procurement', 'other')),
    is_primary            boolean NOT NULL DEFAULT false,
    linkedin_url          text,
    address               text,
    city                  text,
    region                text,
    country               char(2) CHECK (country IS NULL OR country ~ '^[A-Z]{2}$'),
    timezone              text,
    preferred_channel     text CHECK (preferred_channel IS NULL OR preferred_channel IN ('email', 'phone', 'sms')),
    birthday              date,
    lifecycle             text NOT NULL DEFAULT 'prospect' CHECK (lifecycle IN ('lead', 'prospect', 'customer', 'churned')),   -- derived (db/008, db/009)
    email_opt_out         boolean NOT NULL DEFAULT false,
    email_opt_out_at      timestamptz,
    sms_opt_out           boolean NOT NULL DEFAULT false,
    sms_opt_out_at        timestamptz,
    opt_out_reason        text,
    do_not_call           boolean NOT NULL DEFAULT false,
    owner_member_id       bigint REFERENCES members(id) ON DELETE SET NULL,
    source_id             bigint REFERENCES lead_sources(id) ON DELETE SET NULL,
    last_touched_at       timestamptz,
    next_activity_at      timestamptz,
    restricted_to_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    restricted_to_department_id bigint REFERENCES departments(id) ON DELETE SET NULL,
    merged_into_id        bigint REFERENCES contacts(id) ON DELETE SET NULL,
    custom_values         jsonb NOT NULL DEFAULT '{}'::jsonb,
    deleted_at            timestamptz,
    deleted_by            bigint REFERENCES members(id) ON DELETE SET NULL,
    created_by            bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at            timestamptz NOT NULL DEFAULT now(),
    updated_at            timestamptz NOT NULL DEFAULT now(),
    CHECK (first_name IS NOT NULL OR last_name IS NOT NULL OR email IS NOT NULL)
);
CREATE UNIQUE INDEX contacts_one_primary_uq ON contacts (account_id) WHERE is_primary AND deleted_at IS NULL AND account_id IS NOT NULL;
CREATE INDEX contacts_name_trgm_idx ON contacts USING gin (name gin_trgm_ops);
CREATE INDEX contacts_account_idx ON contacts (account_id) WHERE deleted_at IS NULL;
CREATE INDEX contacts_owner_idx ON contacts (owner_member_id) WHERE deleted_at IS NULL;
CREATE INDEX contacts_email_idx ON contacts (email);
CREATE INDEX contacts_birthday_idx ON contacts (EXTRACT(MONTH FROM birthday), EXTRACT(DAY FROM birthday)) WHERE birthday IS NOT NULL AND deleted_at IS NULL;

-- Every address a person uses (the mailbox matches on these); the row for `email` is kept by trigger.
CREATE TABLE contact_emails (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    contact_id  bigint NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
    address     citext NOT NULL,
    is_primary  boolean NOT NULL DEFAULT false,
    verified_at timestamptz,
    created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX contact_emails_address_uq ON contact_emails (address);
CREATE INDEX contact_emails_contact_idx ON contact_emails (contact_id);

CREATE OR REPLACE FUNCTION crm_contacts_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    NEW.first_name := NULLIF(btrim(COALESCE(NEW.first_name, '')), '');
    NEW.last_name := NULLIF(btrim(COALESCE(NEW.last_name, '')), '');
    IF NEW.email IS NOT NULL AND NEW.email::text !~ '^[^@\s]+@[^@\s]+\.[^@\s]+$' THEN RAISE EXCEPTION 'Not an email address: %', NEW.email USING ERRCODE = 'check_violation'; END IF;
    IF NEW.email_opt_out AND NOT COALESCE(OLD.email_opt_out, false) THEN NEW.email_opt_out_at := COALESCE(NEW.email_opt_out_at, now()); END IF;
    IF NEW.sms_opt_out AND NOT COALESCE(OLD.sms_opt_out, false) THEN NEW.sms_opt_out_at := COALESCE(NEW.sms_opt_out_at, now()); END IF;
    IF NEW.account_id IS NULL THEN NEW.is_primary := false; END IF;
    IF NEW.restricted_to_member_id IS NOT NULL OR NEW.restricted_to_department_id IS NOT NULL THEN
        IF NOT crm_setting_bool('allow_restricted_records') THEN RAISE EXCEPTION 'Restricted records are off in the settings' USING ERRCODE = 'check_violation'; END IF;
    END IF;
    PERFORM crm_validate_custom_values('contact', NEW.custom_values);
    RETURN NEW;
END$$;
CREATE TRIGGER contacts_before BEFORE INSERT OR UPDATE ON contacts FOR EACH ROW EXECUTE FUNCTION crm_contacts_before();
CREATE TRIGGER contacts_touch BEFORE UPDATE ON contacts FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- The primary address mirrored into contact_emails; the first contact of an account becomes primary; a duplicate address is
-- the duplicates policy's (block_exact_email refuses; warn lets it through — the duplicates screen shows it).
CREATE OR REPLACE FUNCTION crm_contacts_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE other bigint;
BEGIN
    IF NEW.email IS NOT NULL AND (TG_OP = 'INSERT' OR NEW.email IS DISTINCT FROM OLD.email) THEN
        SELECT contact_id INTO other FROM contact_emails WHERE address = NEW.email AND contact_id <> NEW.id;
        IF other IS NOT NULL THEN
            IF crm_setting_text('duplicates_policy') = 'block_exact_email' THEN
                RAISE EXCEPTION 'Another contact (%) already has the address %', other, NEW.email USING ERRCODE = 'unique_violation';
            END IF;
            -- warn: the address stays on the older contact's row; this contact's row carries it on `email` alone
        ELSE
            IF TG_OP = 'UPDATE' AND OLD.email IS NOT NULL THEN UPDATE contact_emails SET is_primary = false WHERE contact_id = NEW.id AND address = OLD.email; END IF;
            INSERT INTO contact_emails (contact_id, address, is_primary) VALUES (NEW.id, NEW.email, true)
            ON CONFLICT (address) DO UPDATE SET is_primary = true WHERE contact_emails.contact_id = NEW.id;
        END IF;
    END IF;
    IF NEW.account_id IS NOT NULL AND NOT NEW.is_primary AND NEW.deleted_at IS NULL
       AND NOT EXISTS (SELECT 1 FROM contacts c WHERE c.account_id = NEW.account_id AND c.is_primary AND c.deleted_at IS NULL AND c.id <> NEW.id) THEN
        UPDATE contacts SET is_primary = true WHERE id = NEW.id;
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER contacts_after AFTER INSERT OR UPDATE OF email, account_id, deleted_at ON contacts FOR EACH ROW EXECUTE FUNCTION crm_contacts_after();

-- The contact an address belongs to (the mailbox's matcher).
CREATE OR REPLACE FUNCTION crm_contact_by_address(p_address citext) RETURNS bigint
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT c.id FROM contact_emails ce JOIN contacts c ON c.id = ce.contact_id WHERE ce.address = p_address AND c.deleted_at IS NULL AND c.merged_into_id IS NULL
    UNION ALL
    SELECT c.id FROM contacts c WHERE (c.email = p_address OR c.email_alt = p_address) AND c.deleted_at IS NULL AND c.merged_into_id IS NULL
    LIMIT 1;
$$;

-- A touch: last_touched_at on the contact, its account, the deal (db/010 calls it from every activity and email).
CREATE OR REPLACE FUNCTION crm_touch(p_account bigint, p_contact bigint, p_deal bigint, p_at timestamptz) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF p_contact IS NOT NULL THEN UPDATE contacts SET last_touched_at = GREATEST(COALESCE(last_touched_at, '-infinity'), p_at) WHERE id = p_contact; END IF;
    IF p_account IS NOT NULL THEN UPDATE accounts SET last_touched_at = GREATEST(COALESCE(last_touched_at, '-infinity'), p_at) WHERE id = p_account; END IF;
    IF p_deal IS NOT NULL THEN UPDATE deals SET last_touched_at = GREATEST(COALESCE(last_touched_at, '-infinity'), p_at) WHERE id = p_deal; END IF;
END$$;

-- Possible duplicates of one kind (D12): exact email (contacts), exact domain (accounts), digits-only phone, trigram name (+ city for accounts).
CREATE OR REPLACE FUNCTION crm_duplicates(p_kind text, p_limit integer DEFAULT 200)
    RETURNS TABLE (a_id bigint, b_id bigint, matched_on text, similarity real)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE thr real := crm_setting_text('duplicates_name_similarity')::real;
BEGIN
    IF p_kind = 'contact' THEN
        RETURN QUERY
        SELECT x.id, y.id, 'email'::text, 1::real FROM contacts x JOIN contacts y ON y.id > x.id AND y.email = x.email
         WHERE x.email IS NOT NULL AND x.deleted_at IS NULL AND y.deleted_at IS NULL AND x.merged_into_id IS NULL AND y.merged_into_id IS NULL
        UNION ALL
        SELECT x.id, y.id, 'phone', 1 FROM contacts x JOIN contacts y ON y.id > x.id
           AND regexp_replace(COALESCE(y.mobile, y.phone), '\D', '', 'g') = regexp_replace(COALESCE(x.mobile, x.phone), '\D', '', 'g')
         WHERE length(regexp_replace(COALESCE(x.mobile, x.phone, ''), '\D', '', 'g')) >= 7 AND x.deleted_at IS NULL AND y.deleted_at IS NULL AND x.merged_into_id IS NULL AND y.merged_into_id IS NULL
        UNION ALL
        SELECT x.id, y.id, 'name', similarity(x.name, y.name) FROM contacts x JOIN contacts y ON y.id > x.id AND y.name % x.name AND similarity(x.name, y.name) >= thr
           AND (x.account_id = y.account_id OR x.account_id IS NULL OR y.account_id IS NULL)
         WHERE length(x.name) >= 5 AND x.deleted_at IS NULL AND y.deleted_at IS NULL AND x.merged_into_id IS NULL AND y.merged_into_id IS NULL
        LIMIT p_limit;
    ELSIF p_kind = 'account' THEN
        RETURN QUERY
        SELECT x.id, y.id, 'domain'::text, 1::real FROM accounts x JOIN accounts y ON y.id > x.id AND y.domain = x.domain
         WHERE x.domain IS NOT NULL AND x.deleted_at IS NULL AND y.deleted_at IS NULL
        UNION ALL
        SELECT x.id, y.id, 'name', similarity(x.name, y.name) FROM accounts x JOIN accounts y ON y.id > x.id AND y.name % x.name AND similarity(x.name, y.name) >= thr
           AND (x.city IS NULL OR y.city IS NULL OR lower(x.city) = lower(y.city))
         WHERE x.deleted_at IS NULL AND y.deleted_at IS NULL AND x.merged_into_id IS NULL AND y.merged_into_id IS NULL
        LIMIT p_limit;
    ELSIF p_kind = 'lead' THEN
        RETURN QUERY
        SELECT x.id, y.id, 'email'::text, 1::real FROM leads x JOIN leads y ON y.id > x.id AND y.email = x.email
         WHERE x.email IS NOT NULL AND x.deleted_at IS NULL AND y.deleted_at IS NULL AND x.converted_at IS NULL AND y.converted_at IS NULL
        LIMIT p_limit;
    ELSE
        RAISE EXCEPTION 'crm_duplicates: kind %', p_kind;
    END IF;
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON accounts, contacts, contact_emails TO sales_crm_rw;
GRANT EXECUTE ON FUNCTION crm_my_department_ids(), crm_wall(bigint, bigint, bigint), crm_edit_wall(bigint, text), crm_contact_by_address(citext), crm_duplicates(text, integer)
    TO sales_crm_rw, sales_crm_records_ro, sales_crm_activity_ro;
REVOKE ALL ON FUNCTION crm_touch(bigint, bigint, bigint, timestamptz) FROM PUBLIC; GRANT EXECUTE ON FUNCTION crm_touch(bigint, bigint, bigint, timestamptz) TO sales_crm_rw;

COMMIT;
