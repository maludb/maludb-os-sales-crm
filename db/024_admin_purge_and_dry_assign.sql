-- Sales CRM, slice 9 (2026-10-11) — the admin area needs two functions the schema did not have, and one defect closed. Additive; nothing earlier is modified (db/013's crm_tombstones_purge(integer) is
-- REPLACED here with the same signature and the same total, now calling the new function, so the worker and the screen are one code path).
--
-- 1. crm_tombstones_purge_kinds(kind, before, limit) → jsonb {lead, account, contact, deal, activity} — purges what has waited past the window (tombstone_days) and answers the
--    COUNT BY KIND, which the log and the screen both print. `p_kind` NULL = every kind. `p_before` can only NARROW the cut-off (an older edge), never reach inside the window: a
--    tombstone inside the window survives every call. The bodies are db/013's, kind by kind, unchanged.
-- 2. crm_assign_lead_dry(lead) → the first active rule the lead matches, and who it WOULD assign — without writing a row. crm_assign_lead() advances a round-robin rule's
--    last_index as it chooses, so the admin's test box cannot call it; this function reads the same conditions (db/019's, verbatim) and names the NEXT member of a round-robin
--    rule without moving the index. STABLE: it cannot write. The slice 9 proof compares the two on every rule kind.
-- 3. crm_validate_custom_values() — the key of an ARCHIVED field stays valid (below).
BEGIN;

CREATE OR REPLACE FUNCTION crm_tombstones_purge_kinds(p_kind text DEFAULT NULL, p_before timestamptz DEFAULT NULL, p_limit integer DEFAULT 200) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE
    cutoff timestamptz := LEAST(now() - make_interval(days => crm_setting_int('tombstone_days')::integer), COALESCE(p_before, 'infinity'::timestamptz));
    counts jsonb := '{"lead": 0, "account": 0, "contact": 0, "deal": 0, "activity": 0}'::jsonb;
    n integer; r record;
BEGIN
    IF p_kind IS NOT NULL AND p_kind NOT IN ('lead', 'account', 'contact', 'deal', 'activity') THEN
        RAISE EXCEPTION 'Purge takes a lead, account, contact, deal or activity' USING ERRCODE = 'check_violation';
    END IF;
    IF p_kind IS NULL OR p_kind = 'activity' THEN
        n := 0;
        FOR r IN SELECT id FROM activities WHERE deleted_at < cutoff ORDER BY id LIMIT p_limit LOOP DELETE FROM activities WHERE id = r.id; n := n + 1; END LOOP;
        counts := jsonb_set(counts, '{activity}', to_jsonb(n));
    END IF;
    IF p_kind IS NULL OR p_kind = 'deal' THEN
        n := 0;
        FOR r IN SELECT id FROM deals WHERE deleted_at < cutoff ORDER BY id LIMIT p_limit LOOP
            DELETE FROM notes WHERE record_type = 'deal' AND record_id = r.id; DELETE FROM attachments WHERE record_type = 'deal' AND record_id = r.id; DELETE FROM record_tags WHERE record_type = 'deal' AND record_id = r.id;
            DELETE FROM deals WHERE id = r.id; n := n + 1;
        END LOOP;
        counts := jsonb_set(counts, '{deal}', to_jsonb(n));
    END IF;
    IF p_kind IS NULL OR p_kind = 'lead' THEN
        n := 0;
        FOR r IN SELECT id FROM leads WHERE deleted_at < cutoff ORDER BY id LIMIT p_limit LOOP
            DELETE FROM notes WHERE record_type = 'lead' AND record_id = r.id; DELETE FROM attachments WHERE record_type = 'lead' AND record_id = r.id; DELETE FROM record_tags WHERE record_type = 'lead' AND record_id = r.id;
            DELETE FROM leads WHERE id = r.id; n := n + 1;
        END LOOP;
        counts := jsonb_set(counts, '{lead}', to_jsonb(n));
    END IF;
    IF p_kind IS NULL OR p_kind = 'contact' THEN
        n := 0;
        FOR r IN SELECT id FROM contacts WHERE deleted_at < cutoff ORDER BY id LIMIT p_limit LOOP
            DELETE FROM notes WHERE record_type = 'contact' AND record_id = r.id; DELETE FROM attachments WHERE record_type = 'contact' AND record_id = r.id; DELETE FROM record_tags WHERE record_type = 'contact' AND record_id = r.id;
            DELETE FROM contacts WHERE id = r.id; n := n + 1;
        END LOOP;
        counts := jsonb_set(counts, '{contact}', to_jsonb(n));
    END IF;
    IF p_kind IS NULL OR p_kind = 'account' THEN
        n := 0;
        FOR r IN SELECT id FROM accounts WHERE deleted_at < cutoff AND NOT EXISTS (SELECT 1 FROM deals WHERE account_id = accounts.id) AND NOT EXISTS (SELECT 1 FROM contacts WHERE account_id = accounts.id) ORDER BY id LIMIT p_limit LOOP
            DELETE FROM notes WHERE record_type = 'account' AND record_id = r.id; DELETE FROM attachments WHERE record_type = 'account' AND record_id = r.id; DELETE FROM record_tags WHERE record_type = 'account' AND record_id = r.id;
            DELETE FROM accounts WHERE id = r.id; n := n + 1;
        END LOOP;
        counts := jsonb_set(counts, '{account}', to_jsonb(n));
    END IF;
    RETURN counts;
END$$;

-- 3. crm_validate_custom_values() (db/006) refused the key of an ARCHIVED field as unknown, so archiving a field in the admin would have made every record that carries a value for it
--    impossible to save (a form sends the record's whole custom_values back). An archived field is hidden from the forms and the pickers, its values stay valid: the same typed checks,
--    the archived rows included in the lookup. Nothing else in the function changes.
CREATE OR REPLACE FUNCTION crm_validate_custom_values(p_applies_to text, p_values jsonb) RETURNS void
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE k text; v jsonb; d custom_field_defs%ROWTYPE;
BEGIN
    IF p_values IS NULL OR p_values = '{}'::jsonb THEN RETURN; END IF;
    IF jsonb_typeof(p_values) <> 'object' THEN RAISE EXCEPTION 'custom_values must be an object' USING ERRCODE = 'check_violation'; END IF;
    FOR k, v IN SELECT * FROM jsonb_each(p_values) LOOP
        SELECT * INTO d FROM custom_field_defs WHERE applies_to = p_applies_to AND key = k;
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

-- db/013's function, same signature and the same total — one code path now.
CREATE OR REPLACE FUNCTION crm_tombstones_purge(p_limit integer DEFAULT 200) RETURNS integer
    LANGUAGE sql SECURITY DEFINER SET search_path = public AS $$
    SELECT (SELECT sum(value::integer) FROM jsonb_each_text(crm_tombstones_purge_kinds(NULL, NULL, p_limit)))::integer;
$$;

CREATE OR REPLACE FUNCTION crm_assign_lead_dry(p_lead leads) RETURNS TABLE (rule_id bigint, rule_name text, assign_kind text, owner_member_id bigint)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE r assignment_rules%ROWTYPE; m jsonb; dom text; who bigint;
BEGIN
    dom := CASE WHEN p_lead.email IS NOT NULL THEN lower(split_part(p_lead.email::text, '@', 2)) END;
    FOR r IN SELECT * FROM assignment_rules WHERE active ORDER BY position, id LOOP
        m := r.match;
        CONTINUE WHEN m ? 'source_id' AND NOT COALESCE((m -> 'source_id') @> to_jsonb(p_lead.source_id), false);
        CONTINUE WHEN m ? 'campaign_id' AND NOT COALESCE((m -> 'campaign_id') @> to_jsonb(p_lead.campaign_id), false);
        CONTINUE WHEN m ? 'country' AND NOT COALESCE((m -> 'country') @> to_jsonb(p_lead.country::text), false);
        CONTINUE WHEN m ? 'utm_source' AND NOT COALESCE((m -> 'utm_source') @> to_jsonb(p_lead.utm ->> 'utm_source'), false);
        CONTINUE WHEN m ? 'domain_suffix' AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements_text(m -> 'domain_suffix') s WHERE dom IS NOT NULL AND dom LIKE '%' || s);
        IF r.assign_kind = 'member' THEN who := r.member_id;
        ELSIF r.assign_kind = 'department' THEN SELECT manager_member_id INTO who FROM departments WHERE id = r.department_id;
        ELSE who := r.members[((r.last_index + 1) % cardinality(r.members)) + 1];
        END IF;
        RETURN QUERY SELECT r.id, r.name, r.assign_kind, who;
        RETURN;
    END LOOP;
    RETURN;
END$$;

REVOKE ALL ON FUNCTION crm_tombstones_purge_kinds(text, timestamptz, integer), crm_tombstones_purge(integer), crm_assign_lead_dry(leads) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION crm_tombstones_purge_kinds(text, timestamptz, integer), crm_tombstones_purge(integer), crm_assign_lead_dry(leads) TO sales_crm_rw;

COMMIT;
