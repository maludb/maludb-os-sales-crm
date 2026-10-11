-- Sales CRM, slice 4 (2026-10-11) — crm_record_delete() (db/013) named the table by adding an "s" to the kind, so an ACTIVITY's table was "activitys":
-- deleting or restoring an activity failed with "relation activitys does not exist" (the slice 4 proof found it; the other five kinds were right by luck).
-- The table is now named per kind. Nothing else changes: the same kinds, the deal's account recount, the activity's records' next step refreshed.
BEGIN;

CREATE OR REPLACE FUNCTION crm_record_delete(p_kind text, p_id bigint, p_restore boolean DEFAULT false) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id(); acc bigint; tbl text;
BEGIN
    tbl := CASE p_kind WHEN 'lead' THEN 'leads' WHEN 'account' THEN 'accounts' WHEN 'contact' THEN 'contacts' WHEN 'deal' THEN 'deals'
                       WHEN 'activity' THEN 'activities' WHEN 'campaign' THEN 'campaigns' END;
    IF tbl IS NULL THEN RAISE EXCEPTION 'crm_record_delete: kind %', p_kind; END IF;
    IF p_restore THEN
        EXECUTE format('UPDATE %I SET deleted_at = NULL, deleted_by = NULL WHERE id = $1', tbl) USING p_id;
    ELSE
        EXECUTE format('UPDATE %I SET deleted_at = now(), deleted_by = $2 WHERE id = $1 AND deleted_at IS NULL', tbl) USING p_id, me;
    END IF;
    IF p_kind = 'deal' THEN SELECT account_id INTO acc FROM deals WHERE id = p_id; PERFORM crm_account_recount(acc); END IF;
    IF p_kind = 'activity' THEN PERFORM crm_refresh_next_activity(a.account_id, a.contact_id, a.deal_id, a.lead_id) FROM activities a WHERE a.id = p_id; END IF;
END$$;

COMMIT;
