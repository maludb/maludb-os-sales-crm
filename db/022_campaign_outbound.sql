-- Sales CRM, slice 6 (2026-10-11) — a campaign's email is queued per contact and sent by the worker (campaigns-lists.md, "Decisions taken here" 1; the planning model's decision):
--   * outbound_email.campaign_id — which campaign a queued message belongs to. The worker's `outbox` pass sends exactly the queued rows that have one (through slice 5's email_deliver(),
--     so each gets the same footer, unsubscribe link and Message-ID); a person's own draft has none and is only ever sent by that person.
--   * outbound_email.attempts / retry_at — a retryable answer from the provider (429, 502, a transport error) leaves the row queued for a later pass; it is tried at most five times,
--     the next not before retry_at (a minute, then four, nine … after each failure), then it is `failed` like any other send that did not go.
--   * mcp_emails — the same view as db/021 with campaign_id appended as the LAST column, and a queued or failed campaign message is nobody's draft: it is shown only once it is sent (then like
--     any sent email, with its activity on the contact). The campaign's own page reads its batches from the table.
-- Additive: nothing existing is removed or renamed; the view keeps security_barrier and its grants.
BEGIN;

ALTER TABLE outbound_email
    ADD COLUMN campaign_id bigint REFERENCES campaigns(id) ON DELETE SET NULL,
    ADD COLUMN attempts smallint NOT NULL DEFAULT 0,
    ADD COLUMN retry_at timestamptz;
CREATE INDEX outbound_email_campaign_idx ON outbound_email (campaign_id) WHERE campaign_id IS NOT NULL;
CREATE INDEX outbound_email_campaign_queue_idx ON outbound_email (created_at, id) WHERE campaign_id IS NOT NULL AND status = 'queued';

CREATE OR REPLACE VIEW mcp_emails WITH (security_barrier = true) AS
SELECT 'in' AS direction, i.id AS email_id, i.received_at AS at, i.from_address::text AS from_address, i.from_name, i.to_addresses, i.cc_addresses, i.subject,
       CASE WHEN NOT i.is_private OR i.sender_member_id = app_current_member_id() OR (SELECT crm_is_manager()) THEN i.body_text END AS body_text,
       i.sender_member_id, i.deal_id, i.matched_contact_ids, i.match_status, i.status, i.is_private, NULL::bigint AS campaign_id
  FROM inbound_email i
 WHERE (SELECT crm_is_member_here()) AND i.match_status IN ('matched', 'proposed_lead', 'unmatched')
   AND (NOT i.is_private OR i.sender_member_id = app_current_member_id() OR (SELECT crm_is_manager()))
   AND (i.match_status <> 'matched' OR EXISTS (SELECT 1 FROM contacts c WHERE c.id = ANY (i.matched_contact_ids) AND crm_wall(c.owner_member_id, c.restricted_to_member_id, c.restricted_to_department_id)))
UNION ALL
SELECT 'out', o.id, COALESCE(o.sent_at, o.created_at), (SELECT email::text FROM members WHERE id = o.sender_member_id), (SELECT display_name FROM members WHERE id = o.sender_member_id), ARRAY[o.to_address::text], o.cc_addresses, o.subject,
       CASE WHEN NOT o.is_private OR o.sender_member_id = app_current_member_id() OR (SELECT crm_is_manager()) THEN o.body_text END,
       o.sender_member_id, o.deal_id, CASE WHEN o.contact_id IS NULL THEN '{}'::bigint[] ELSE ARRAY[o.contact_id] END, 'matched', o.status, o.is_private, o.campaign_id
  FROM outbound_email o
 WHERE (SELECT crm_is_member_here())
   AND (o.status = 'sent' OR (o.sender_member_id = app_current_member_id() AND o.campaign_id IS NULL))
   AND (NOT o.is_private OR o.sender_member_id = app_current_member_id() OR (SELECT crm_is_manager()))
   AND (o.contact_id IS NULL OR EXISTS (SELECT 1 FROM contacts c WHERE c.id = o.contact_id AND crm_wall(c.owner_member_id, c.restricted_to_member_id, c.restricted_to_department_id)));

COMMIT;
