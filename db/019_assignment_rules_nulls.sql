-- Sales CRM, slice 2 (2026-10-10) — crm_assign_lead() matched a rule's condition against a lead that LACKS the field: `(m -> 'country') @> to_jsonb(NULL)`
-- is NULL, `NOT NULL` is NULL, and `CONTINUE WHEN NULL` does not skip — so a lead with no country matched the country rule (and no source the source rule,
-- no campaign the campaign rule, no utm_source the utm rule). The slice 2 proof found it: a "Germany to Dana" rule took every lead without a country.
-- A condition on a field the lead does not have is now NOT met. Nothing else changes (the order, the three kinds, round-robin in turn).
BEGIN;

CREATE OR REPLACE FUNCTION crm_assign_lead(p_lead leads) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r assignment_rules%ROWTYPE; m jsonb; dom text; idx integer; who bigint;
BEGIN
    dom := CASE WHEN p_lead.email IS NOT NULL THEN lower(split_part(p_lead.email::text, '@', 2)) END;
    FOR r IN SELECT * FROM assignment_rules WHERE active ORDER BY position, id LOOP
        m := r.match;
        CONTINUE WHEN m ? 'source_id' AND NOT COALESCE((m -> 'source_id') @> to_jsonb(p_lead.source_id), false);
        CONTINUE WHEN m ? 'campaign_id' AND NOT COALESCE((m -> 'campaign_id') @> to_jsonb(p_lead.campaign_id), false);
        CONTINUE WHEN m ? 'country' AND NOT COALESCE((m -> 'country') @> to_jsonb(p_lead.country::text), false);
        CONTINUE WHEN m ? 'utm_source' AND NOT COALESCE((m -> 'utm_source') @> to_jsonb(p_lead.utm ->> 'utm_source'), false);
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
REVOKE ALL ON FUNCTION crm_assign_lead(leads) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION crm_assign_lead(leads) TO sales_crm_rw;

COMMIT;
