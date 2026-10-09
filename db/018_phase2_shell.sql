-- Sales CRM, Phase 2 (2026-10-09) — what the shell found the schema lacked (docs/build-specs/sso-shell.md).
--
-- My activity (screen `activity`) and a record's History tab read the trail through mcp_activity_log, the view that applies the wall to a row
-- about a lead, an account, a contact or a deal (db/016). db/016 granted it to the activity server's role only; the application's own role reads
-- it too, so the screens show exactly what the activity server would — never activity_log directly.
BEGIN;

GRANT SELECT ON mcp_activity_log TO sales_crm_rw;

COMMIT;
