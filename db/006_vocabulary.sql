-- 006: the VOCABULARIES the book is kept in — lead sources, lost reasons (leads' and deals'), tags, custom fields, the deal
-- number sequence (design §6.1, §6.4; D12).
--
-- THE ESTATE'S DATA MODEL (design §6.5): `tags` is Help Desk db/009 verbatim; `record_tags` is Help Desk's `ticket_tags` with a
-- record_type (the shape DocCloud's document_tags took); `custom_field_defs` is Help Desk db/009 verbatim with `applies_to` widened to
-- the five records and `field_type` widened (url, email, money, member); `document_sequences` is GL db/006 verbatim with the kind list
-- ours (deal). `lead_sources`, `lost_reasons` are NEW (nothing in the estate qualifies anyone or records why a sale was lost).
BEGIN;

CREATE TABLE lead_sources (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name        text NOT NULL UNIQUE,
    kind        text NOT NULL DEFAULT 'inbound' CHECK (kind IN ('inbound', 'outbound')),
    active      boolean NOT NULL DEFAULT true,
    sort_order  integer NOT NULL DEFAULT 100,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER lead_sources_touch BEFORE UPDATE ON lead_sources FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
INSERT INTO lead_sources (name, kind, sort_order) VALUES
 ('Website', 'inbound', 10), ('Referral', 'inbound', 20), ('Event', 'inbound', 30), ('Partner', 'inbound', 40), ('Mailbox', 'inbound', 50),
 ('Outbound', 'outbound', 60), ('Import', 'outbound', 70);

CREATE TABLE lost_reasons (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind        text NOT NULL CHECK (kind IN ('lead', 'deal')),
    label       text NOT NULL,
    active      boolean NOT NULL DEFAULT true,
    sort_order  integer NOT NULL DEFAULT 100,
    created_at  timestamptz NOT NULL DEFAULT now(),
    UNIQUE (kind, label)
);
INSERT INTO lost_reasons (kind, label, sort_order) VALUES
 ('deal', 'Price', 10), ('deal', 'No budget', 20), ('deal', 'Timing', 30), ('deal', 'Went with competitor', 40), ('deal', 'No decision', 50),
 ('deal', 'Not a fit', 60), ('deal', 'Unresponsive', 70),
 ('lead', 'Not a fit', 10), ('lead', 'No budget', 20), ('lead', 'Unresponsive', 30), ('lead', 'Duplicate', 40), ('lead', 'Spam', 50), ('lead', 'Competitor', 60);

-- reuse: Help Desk db/009 verbatim
CREATE TABLE tags (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    slug        text NOT NULL UNIQUE CHECK (slug ~ '^[a-z0-9][a-z0-9_-]{0,49}$'),
    label       text NOT NULL,
    usage_count integer NOT NULL DEFAULT 0,
    created_at  timestamptz NOT NULL DEFAULT now()
);
-- reuse (shape): Help Desk's ticket_tags with record_type — the one join for every taggable record
CREATE TABLE record_tags (
    record_type text NOT NULL CHECK (record_type IN ('lead', 'account', 'contact', 'deal', 'activity', 'campaign')),
    record_id   bigint NOT NULL,
    tag_id      bigint NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
    PRIMARY KEY (record_type, record_id, tag_id)
);
CREATE INDEX record_tags_tag_idx ON record_tags (tag_id);
CREATE OR REPLACE FUNCTION crm_record_tags_count() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN UPDATE tags SET usage_count = usage_count + 1 WHERE id = NEW.tag_id;
    ELSIF TG_OP = 'DELETE' THEN UPDATE tags SET usage_count = GREATEST(0, usage_count - 1) WHERE id = OLD.tag_id; END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER record_tags_count AFTER INSERT OR DELETE ON record_tags FOR EACH ROW EXECUTE FUNCTION crm_record_tags_count();

-- The tags of one record, as text[] (for views and the search index).
CREATE OR REPLACE FUNCTION crm_tags_of(p_type text, p_id bigint) RETURNS text[]
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE(array_agg(t.label ORDER BY t.label), '{}') FROM record_tags rt JOIN tags t ON t.id = rt.tag_id WHERE rt.record_type = p_type AND rt.record_id = p_id;
$$;

-- reuse: Help Desk db/009 verbatim; applies_to and field_type widened
CREATE TABLE custom_field_defs (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    key               text NOT NULL CHECK (key ~ '^[a-z][a-z0-9_]{0,39}$'),
    label             text NOT NULL,
    field_type        text NOT NULL CHECK (field_type IN ('text', 'number', 'date', 'select', 'multi_select', 'checkbox', 'url', 'email', 'money', 'member')),
    options           text[] NOT NULL DEFAULT '{}',              -- for select / multi_select
    applies_to        text NOT NULL CHECK (applies_to IN ('lead', 'account', 'contact', 'deal', 'activity')),
    type_ids          bigint[] NOT NULL DEFAULT '{}',            -- a deal field shown for these PIPELINES only; empty = all
    required_on_solve boolean NOT NULL DEFAULT false,            -- a deal field required to WIN (the canonical column's name kept)
    requester_visible boolean NOT NULL DEFAULT false,            -- unused here (the canonical column kept)
    sort_order        integer NOT NULL DEFAULT 100,
    archived_at       timestamptz,
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now(),
    UNIQUE (applies_to, key)
);
CREATE TRIGGER custom_field_defs_touch BEFORE UPDATE ON custom_field_defs FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- custom_values validated against the definitions: unknown keys refused, typed values checked. Used by every record's trigger.
CREATE OR REPLACE FUNCTION crm_validate_custom_values(p_applies_to text, p_values jsonb) RETURNS void
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE k text; v jsonb; d custom_field_defs%ROWTYPE;
BEGIN
    IF p_values IS NULL OR p_values = '{}'::jsonb THEN RETURN; END IF;
    IF jsonb_typeof(p_values) <> 'object' THEN RAISE EXCEPTION 'custom_values must be an object' USING ERRCODE = 'check_violation'; END IF;
    FOR k, v IN SELECT * FROM jsonb_each(p_values) LOOP
        SELECT * INTO d FROM custom_field_defs WHERE applies_to = p_applies_to AND key = k AND archived_at IS NULL;
        IF NOT FOUND THEN RAISE EXCEPTION 'Unknown custom field % for %', k, p_applies_to USING ERRCODE = 'check_violation'; END IF;
        IF jsonb_typeof(v) = 'null' THEN CONTINUE; END IF;
        CASE d.field_type
            WHEN 'number', 'money' THEN IF jsonb_typeof(v) <> 'number' THEN RAISE EXCEPTION 'Custom field % takes a number', k USING ERRCODE = 'check_violation'; END IF;
            WHEN 'checkbox' THEN IF jsonb_typeof(v) <> 'boolean' THEN RAISE EXCEPTION 'Custom field % takes true or false', k USING ERRCODE = 'check_violation'; END IF;
            WHEN 'date' THEN IF jsonb_typeof(v) <> 'string' OR NOT (v #>> '{}') ~ '^\d{4}-\d{2}-\d{2}$' THEN RAISE EXCEPTION 'Custom field % takes a date', k USING ERRCODE = 'check_violation'; END IF;
            WHEN 'select' THEN IF jsonb_typeof(v) <> 'string' OR NOT ((v #>> '{}') = ANY (d.options)) THEN RAISE EXCEPTION 'Custom field % takes one of its options', k USING ERRCODE = 'check_violation'; END IF;
            WHEN 'multi_select' THEN IF jsonb_typeof(v) <> 'array' OR EXISTS (SELECT 1 FROM jsonb_array_elements_text(v) e WHERE NOT (e = ANY (d.options))) THEN RAISE EXCEPTION 'Custom field % takes its options', k USING ERRCODE = 'check_violation'; END IF;
            WHEN 'member' THEN IF jsonb_typeof(v) <> 'number' OR NOT EXISTS (SELECT 1 FROM members WHERE id = (v #>> '{}')::bigint) THEN RAISE EXCEPTION 'Custom field % takes a member', k USING ERRCODE = 'check_violation'; END IF;
            WHEN 'email' THEN IF jsonb_typeof(v) <> 'string' OR NOT (v #>> '{}') ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$' THEN RAISE EXCEPTION 'Custom field % takes an email address', k USING ERRCODE = 'check_violation'; END IF;
            WHEN 'url' THEN IF jsonb_typeof(v) <> 'string' OR NOT (v #>> '{}') ~ '^https?://' THEN RAISE EXCEPTION 'Custom field % takes a URL', k USING ERRCODE = 'check_violation'; END IF;
            ELSE IF jsonb_typeof(v) <> 'string' THEN RAISE EXCEPTION 'Custom field % takes text', k USING ERRCODE = 'check_violation'; END IF;
        END CASE;
    END LOOP;
END$$;

-- reuse: GL db/006 verbatim; the kind list ours
CREATE TABLE document_sequences (
    kind        text PRIMARY KEY CHECK (kind IN ('deal')),
    prefix      text NOT NULL CHECK (prefix ~ '^[A-Z][A-Z0-9]{0,7}-?$'),
    next_value  bigint NOT NULL DEFAULT 1 CHECK (next_value > 0),
    padding     smallint NOT NULL DEFAULT 5 CHECK (padding BETWEEN 1 AND 12),
    updated_at  timestamptz NOT NULL DEFAULT now()
);
INSERT INTO document_sequences (kind, prefix, padding) VALUES ('deal', 'D-', 6);
CREATE OR REPLACE FUNCTION crm_next_number(p_kind text) RETURNS text
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE s document_sequences%ROWTYPE;
BEGIN
    UPDATE document_sequences SET next_value = next_value + 1, updated_at = now() WHERE kind = p_kind RETURNING * INTO s;
    IF NOT FOUND THEN RAISE EXCEPTION 'No sequence for %', p_kind; END IF;
    RETURN s.prefix || lpad((s.next_value - 1)::text, s.padding, '0');
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON lead_sources, lost_reasons, tags, record_tags, custom_field_defs TO sales_crm_rw;
GRANT SELECT, UPDATE ON document_sequences TO sales_crm_rw;
GRANT EXECUTE ON FUNCTION crm_tags_of(text, bigint), crm_validate_custom_values(text, jsonb) TO sales_crm_rw, sales_crm_records_ro;
REVOKE ALL ON FUNCTION crm_next_number(text) FROM PUBLIC; GRANT EXECUTE ON FUNCTION crm_next_number(text) TO sales_crm_rw;

COMMIT;
