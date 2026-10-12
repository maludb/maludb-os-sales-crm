-- Sales CRM, Phase 4 (2026-10-12) — what the two MCP servers needed that the schema did not give them, and one exposure closed. Additive; nothing earlier is modified.
--
-- 1. THE RECORDS ROLE NO LONGER READS search_index DIRECTLY. db/014 granted it SELECT on the unwalled index (the text of every record, restricted ones included), and the records server's
--    guarded `records_search` runs a person's own SELECT as that role — a restricted account's name and description would have been one query away. crm_search() is SECURITY DEFINER and judges the
--    wall per row, so the role never needed the table. (Shareholder Intelligence found the same on its unwalled base tables and revoked them in its db/028.) The kit's SEARCH_DENY also names
--    search_index, crm_share_ and k7_cache now — belt and braces; the REVOKE is the rule.
-- 2. mcp_key_usage — a key's calls and refused calls per day, for `key_usage_summary`; the right to manage tokens only (the view tests the caller once per statement).
-- 3. mcp_outbox_health — the notification outbox counted by channel and status over the last seven days, for `reminders_due`'s failed count; the admin only. No address, subject or body.
-- 4. The activity role reads mcp_assistant_proposals (already walled: the proposal's recipient and proposer, and managers) so `agent_activity` can count what the Assistant proposed that nobody took.
BEGIN;

REVOKE SELECT ON search_index FROM sales_crm_records_ro;

CREATE OR REPLACE VIEW mcp_key_usage WITH (security_barrier = true) AS
SELECT u.token_id AS key_id, u.bucket_start::date AS day, u.calls, u.refused
  FROM key_usage u WHERE u.bucket_kind = 'day' AND (SELECT crm_has_right('tokens.manage'));

CREATE OR REPLACE VIEW mcp_outbox_health WITH (security_barrier = true) AS
SELECT o.channel, o.status, count(*)::int AS n
  FROM notification_outbox o WHERE o.created_at > now() - interval '7 days' AND (SELECT crm_is_admin())
 GROUP BY o.channel, o.status;

GRANT SELECT ON mcp_key_usage, mcp_outbox_health TO sales_crm_records_ro;
GRANT SELECT ON mcp_assistant_proposals TO sales_crm_activity_ro;

COMMIT;
