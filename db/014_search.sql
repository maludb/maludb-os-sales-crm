-- 014: the ONE SEARCH BOX — leads, accounts, contacts and deals in one index, every result judged by the wall (design §6.5, §7).
--
-- THE ESTATE'S DATA MODEL: `search_index` is Spaces db/013 CANONICAL, verbatim, with entity_kind widened to the four records and
-- account_id / owner_member_id / restricted_* appended so the wall is a per-row expression over the index row itself (no join per hit).
-- tsv = A the name/title · B the company, email, domain, tags, number · C the description/message (first 20,000 characters);
-- `simple` + unaccent; websearch_to_tsquery; ts_headline; trigram on the name for misspellings. Written by trigger on every record.
BEGIN;

CREATE TABLE search_index (
    entity_kind   text NOT NULL CHECK (entity_kind IN ('lead', 'account', 'contact', 'deal')),
    entity_uuid   uuid,
    entity_id     bigint,
    page_id       uuid,                                                    -- Spaces' columns, kept for the verbatim rule (unused)
    space_id      bigint,
    channel_id    bigint,
    author_member_id bigint,
    title         text,
    body          text,
    tsv           tsvector,
    has_link      boolean NOT NULL DEFAULT false,
    has_file      boolean NOT NULL DEFAULT false,
    occurred_at   timestamptz NOT NULL,
    updated_at    timestamptz NOT NULL DEFAULT now(),
    CHECK ((entity_uuid IS NULL) <> (entity_id IS NULL)),
    -- appended (design §6.5): the wall's facts and the filters
    account_id    bigint,
    owner_member_id bigint,
    restricted_to_member_id bigint,
    restricted_to_department_id bigint,
    status        text,
    deleted       boolean NOT NULL DEFAULT false
);
CREATE UNIQUE INDEX search_index_entity_uq ON search_index (entity_kind, entity_id);
CREATE INDEX search_index_tsv  ON search_index USING gin (tsv);
CREATE INDEX search_index_time ON search_index (occurred_at DESC);
CREATE INDEX search_index_title_trgm ON search_index USING gin (title gin_trgm_ops);
CREATE INDEX search_index_account ON search_index (account_id);

CREATE OR REPLACE FUNCTION crm_tsv(p_a text, p_b text, p_c text) RETURNS tsvector
    LANGUAGE sql IMMUTABLE AS $$
    SELECT setweight(to_tsvector('simple', unaccent(COALESCE(p_a, ''))), 'A')
        || setweight(to_tsvector('simple', unaccent(COALESCE(p_b, ''))), 'B')
        || setweight(to_tsvector('simple', unaccent(left(COALESCE(p_c, ''), 20000))), 'C');
$$;

-- One record's index row, rebuilt.
CREATE OR REPLACE FUNCTION crm_index_record(p_kind text, p_id bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r record;
BEGIN
    IF p_kind = 'lead' THEN
        SELECT crm_lead_label(l) AS title, concat_ws(' ', l.company, l.email::text, l.phone, l.title, l.city, l.country, array_to_string(crm_tags_of('lead', l.id), ' ')) AS b, l.message AS c,
               NULL::bigint AS account_id, l.owner_member_id, l.restricted_to_member_id, l.restricted_to_department_id, l.status, l.deleted_at IS NOT NULL AS deleted, l.updated_at AS at
          INTO r FROM leads l WHERE l.id = p_id;
    ELSIF p_kind = 'account' THEN
        SELECT a.name AS title, concat_ws(' ', a.legal_name, a.domain::text, a.email::text, a.phone, a.industry, a.city, a.country, array_to_string(crm_tags_of('account', a.id), ' ')) AS b, a.description AS c,
               a.id AS account_id, a.owner_member_id, a.restricted_to_member_id, a.restricted_to_department_id, a.kind AS status, a.deleted_at IS NOT NULL AS deleted, a.updated_at AS at
          INTO r FROM accounts a WHERE a.id = p_id;
    ELSIF p_kind = 'contact' THEN
        SELECT c.name AS title, concat_ws(' ', c.email::text, c.email_alt::text, c.phone, c.mobile, c.title, (SELECT name FROM accounts WHERE id = c.account_id), c.city, array_to_string(crm_tags_of('contact', c.id), ' ')) AS b, NULL::text AS c,
               c.account_id, c.owner_member_id, c.restricted_to_member_id, c.restricted_to_department_id, c.lifecycle AS status, c.deleted_at IS NOT NULL AS deleted, c.updated_at AS at
          INTO r FROM contacts c WHERE c.id = p_id;
    ELSIF p_kind = 'deal' THEN
        SELECT d.title, concat_ws(' ', d.number, (SELECT name FROM accounts WHERE id = d.account_id), (SELECT name FROM contacts WHERE id = d.primary_contact_id), array_to_string(crm_tags_of('deal', d.id), ' ')) AS b, d.description AS c,
               d.account_id, d.owner_member_id, d.restricted_to_member_id, d.restricted_to_department_id, d.status, d.deleted_at IS NOT NULL AS deleted, d.updated_at AS at
          INTO r FROM deals d WHERE d.id = p_id;
    ELSE RETURN; END IF;
    IF r IS NULL OR r.title IS NULL THEN DELETE FROM search_index WHERE entity_kind = p_kind AND entity_id = p_id; RETURN; END IF;
    INSERT INTO search_index (entity_kind, entity_id, title, body, tsv, occurred_at, account_id, owner_member_id, restricted_to_member_id, restricted_to_department_id, status, deleted)
    VALUES (p_kind, p_id, r.title, left(COALESCE(r.c, ''), 20000), crm_tsv(r.title, r.b, r.c), r.at, r.account_id, r.owner_member_id, r.restricted_to_member_id, r.restricted_to_department_id, r.status, r.deleted)
    ON CONFLICT (entity_kind, entity_id) DO UPDATE SET title = EXCLUDED.title, body = EXCLUDED.body, tsv = EXCLUDED.tsv, occurred_at = EXCLUDED.occurred_at, account_id = EXCLUDED.account_id,
        owner_member_id = EXCLUDED.owner_member_id, restricted_to_member_id = EXCLUDED.restricted_to_member_id, restricted_to_department_id = EXCLUDED.restricted_to_department_id,
        status = EXCLUDED.status, deleted = EXCLUDED.deleted, updated_at = now();
END$$;

CREATE OR REPLACE FUNCTION crm_index_trigger() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN DELETE FROM search_index WHERE entity_kind = TG_ARGV[0] AND entity_id = OLD.id; RETURN NULL; END IF;
    PERFORM crm_index_record(TG_ARGV[0], NEW.id);
    RETURN NULL;
END$$;
CREATE TRIGGER leads_index    AFTER INSERT OR UPDATE OR DELETE ON leads    FOR EACH ROW EXECUTE FUNCTION crm_index_trigger('lead');
CREATE TRIGGER accounts_index AFTER INSERT OR UPDATE OR DELETE ON accounts FOR EACH ROW EXECUTE FUNCTION crm_index_trigger('account');
CREATE TRIGGER contacts_index AFTER INSERT OR UPDATE OR DELETE ON contacts FOR EACH ROW EXECUTE FUNCTION crm_index_trigger('contact');
CREATE TRIGGER deals_index    AFTER INSERT OR UPDATE OR DELETE ON deals    FOR EACH ROW EXECUTE FUNCTION crm_index_trigger('deal');
-- a tag change re-indexes its record
CREATE OR REPLACE FUNCTION crm_record_tags_index() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM crm_index_record(COALESCE(NEW.record_type, OLD.record_type), COALESCE(NEW.record_id, OLD.record_id));
    RETURN NULL;
END$$;
CREATE TRIGGER record_tags_index AFTER INSERT OR DELETE ON record_tags FOR EACH ROW EXECUTE FUNCTION crm_record_tags_index();

-- The catch-up (the worker): rows whose record changed after the index row.
CREATE OR REPLACE FUNCTION crm_search_catch_up(p_limit integer DEFAULT 500) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer := 0; r record;
BEGIN
    FOR r IN
        SELECT 'lead' AS k, id FROM leads l WHERE NOT EXISTS (SELECT 1 FROM search_index s WHERE s.entity_kind = 'lead' AND s.entity_id = l.id AND s.updated_at >= l.updated_at)
        UNION ALL SELECT 'account', id FROM accounts a WHERE NOT EXISTS (SELECT 1 FROM search_index s WHERE s.entity_kind = 'account' AND s.entity_id = a.id AND s.updated_at >= a.updated_at)
        UNION ALL SELECT 'contact', id FROM contacts c WHERE NOT EXISTS (SELECT 1 FROM search_index s WHERE s.entity_kind = 'contact' AND s.entity_id = c.id AND s.updated_at >= c.updated_at)
        UNION ALL SELECT 'deal', id FROM deals d WHERE NOT EXISTS (SELECT 1 FROM search_index s WHERE s.entity_kind = 'deal' AND s.entity_id = d.id AND s.updated_at >= d.updated_at)
        LIMIT p_limit
    LOOP PERFORM crm_index_record(r.k, r.id); n := n + 1; END LOOP;
    RETURN n;
END$$;

-- THE SEARCH: websearch syntax, the wall per row, a trigram fallback on the name, ranked, with a highlight and the total.
CREATE OR REPLACE FUNCTION crm_search(p_q text, p_kinds text[] DEFAULT NULL, p_limit integer DEFAULT 50, p_offset integer DEFAULT 0)
    RETURNS TABLE (entity_kind text, entity_id bigint, title text, headline text, status text, account_id bigint, owner_member_id bigint, rank real, total bigint)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE q tsquery; qq text := btrim(COALESCE(p_q, ''));
BEGIN
    IF qq = '' THEN RETURN; END IF;
    q := websearch_to_tsquery('simple', unaccent(qq));
    RETURN QUERY
    SELECT s.entity_kind, s.entity_id, s.title,
           ts_headline('simple', COALESCE(s.title || ' — ' || left(s.body, 400), s.title), q, 'MaxFragments=1, MaxWords=24, MinWords=8'),
           s.status, s.account_id, s.owner_member_id,
           GREATEST(ts_rank_cd(s.tsv, q), word_similarity(qq, s.title))::real AS rank,
           count(*) OVER () AS total
      FROM search_index s
     WHERE NOT s.deleted
       AND (p_kinds IS NULL OR s.entity_kind = ANY (p_kinds))
       AND (s.tsv @@ q OR qq <% s.title)
       AND crm_wall(s.owner_member_id, s.restricted_to_member_id, s.restricted_to_department_id)
     ORDER BY rank DESC, s.occurred_at DESC
     LIMIT LEAST(COALESCE(p_limit, 50), 100) OFFSET GREATEST(COALESCE(p_offset, 0), 0);
END$$;

GRANT SELECT ON search_index TO sales_crm_rw, sales_crm_records_ro;
GRANT INSERT, UPDATE, DELETE ON search_index TO sales_crm_rw;
GRANT EXECUTE ON FUNCTION crm_tsv(text, text, text), crm_search(text, text[], integer, integer) TO sales_crm_rw, sales_crm_records_ro;
REVOKE ALL ON FUNCTION crm_index_record(text, bigint), crm_search_catch_up(integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION crm_index_record(text, bigint), crm_search_catch_up(integer) TO sales_crm_rw;

COMMIT;
