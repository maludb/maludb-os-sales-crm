-- Sales CRM, slice 5 (2026-10-11) — six things an email's sending and receiving needed and db/011 / db/016 did not give (additive; nothing existing is removed):
--   * outbound_email.in_reply_to / references_ids — a reply we send names the message it answers (In-Reply-To, References), so the other side's mail client
--     threads it and the matcher (db/011) puts their answer on the deal by OUR message id. A draft keeps them until it is sent.
--   * outbound_email.activity_id — the meeting an invitation (or an email with the meeting's ICS) is for; a draft keeps it until it is sent.
--   * inbound_email (mailbox_id, provider_uid) unique — the webhook and the poll both write the row of a message; a retried notice or a poll that overlaps
--     the webhook finds the row by its uid instead of making a second one (Help Desk db/012 has this index; Sales CRM's copy of the table missed it).
--   * mcp_emails — a draft is its sender's alone (below).
--   * the seeded templates' line breaks (below).
--   * crm_match_inbound_email — a reply is also matched by the id the provider answered with (below).
BEGIN;

ALTER TABLE outbound_email
    ADD COLUMN in_reply_to text,
    ADD COLUMN references_ids text[] NOT NULL DEFAULT '{}',
    ADD COLUMN activity_id bigint REFERENCES activities(id) ON DELETE SET NULL;
CREATE INDEX outbound_email_activity_idx ON outbound_email (activity_id) WHERE activity_id IS NOT NULL;
CREATE UNIQUE INDEX inbound_email_uid_uq ON inbound_email (mailbox_id, provider_uid) WHERE provider_uid IS NOT NULL;


-- A DRAFT is its sender's alone (email.md divergence 1): db/016's mcp_emails showed every outbound row, a queued draft or a send that did not go among them, to anyone who sees the
-- contact — and so did every reader of the view (the records tools, the record's Emails tab). The same view, one more condition on the outbound half: sent mail for everyone the contact's
-- wall admits, anything else only for the one who wrote it. (The columns, the order and the received half are db/016's.)
CREATE OR REPLACE VIEW mcp_emails WITH (security_barrier = true) AS
SELECT 'in' AS direction, i.id AS email_id, i.received_at AS at, i.from_address::text AS from_address, i.from_name, i.to_addresses, i.cc_addresses, i.subject,
       CASE WHEN NOT i.is_private OR i.sender_member_id = app_current_member_id() OR (SELECT crm_is_manager()) THEN i.body_text END AS body_text,
       i.sender_member_id, i.deal_id, i.matched_contact_ids, i.match_status, i.status, i.is_private
  FROM inbound_email i
 WHERE (SELECT crm_is_member_here()) AND i.match_status IN ('matched', 'proposed_lead', 'unmatched')
   AND (NOT i.is_private OR i.sender_member_id = app_current_member_id() OR (SELECT crm_is_manager()))
   AND (i.match_status <> 'matched' OR EXISTS (SELECT 1 FROM contacts c WHERE c.id = ANY (i.matched_contact_ids) AND crm_wall(c.owner_member_id, c.restricted_to_member_id, c.restricted_to_department_id)))
UNION ALL
SELECT 'out', o.id, COALESCE(o.sent_at, o.created_at), (SELECT email::text FROM members WHERE id = o.sender_member_id), (SELECT display_name FROM members WHERE id = o.sender_member_id), ARRAY[o.to_address::text], o.cc_addresses, o.subject,
       CASE WHEN NOT o.is_private OR o.sender_member_id = app_current_member_id() OR (SELECT crm_is_manager()) THEN o.body_text END,
       o.sender_member_id, o.deal_id, CASE WHEN o.contact_id IS NULL THEN '{}'::bigint[] ELSE ARRAY[o.contact_id] END, 'matched', o.status, o.is_private
  FROM outbound_email o
 WHERE (SELECT crm_is_member_here())
   AND (o.status = 'sent' OR o.sender_member_id = app_current_member_id())
   AND (NOT o.is_private OR o.sender_member_id = app_current_member_id() OR (SELECT crm_is_manager()))
   AND (o.contact_id IS NULL OR EXISTS (SELECT 1 FROM contacts c WHERE c.id = o.contact_id AND crm_wall(c.owner_member_id, c.restricted_to_member_id, c.restricted_to_department_id)));

-- The matcher answers a reply to what we SENT whichever id the provider put on it: our own Message-ID (outbound_email.message_id, which the send asks MaluMail to use) or the one MaluMail
-- answered with (provider_message_id) — the same function as db/011 with that one condition widened, so a provider that keeps its own id still threads the answer onto the deal.
CREATE INDEX outbound_email_provider_mid_idx ON outbound_email (provider_message_id) WHERE provider_message_id IS NOT NULL;
CREATE OR REPLACE FUNCTION crm_match_inbound_email(p_email bigint) RETURNS text
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE e inbound_email%ROWTYPE; addr text; cid bigint; cids bigint[] := '{}'; sender_member bigint; deal bigint; dir text; num text; who_wrote bigint; lid bigint;
BEGIN
    SELECT * INTO e FROM inbound_email WHERE id = p_email FOR UPDATE;
    IF NOT FOUND THEN RETURN 'missing'; END IF;
    IF e.status IN ('bounce', 'auto_reply', 'suppressed', 'ignored') THEN
        UPDATE inbound_email SET match_status = CASE WHEN e.status = 'bounce' THEN 'bounce' ELSE 'ignored' END, processed_at = now() WHERE id = p_email;
        RETURN 'ignored';
    END IF;
    SELECT id INTO sender_member FROM members WHERE email = e.from_address AND status = 'active' LIMIT 1;
    -- the deal: by our thread, then the subject tag
    SELECT o.deal_id INTO deal FROM outbound_email o WHERE (o.message_id IS NOT NULL OR o.provider_message_id IS NOT NULL) AND (o.message_id = e.in_reply_to OR o.message_id = ANY (e.references_ids) OR o.provider_message_id = e.in_reply_to OR o.provider_message_id = ANY (e.references_ids)) AND o.deal_id IS NOT NULL LIMIT 1;
    IF deal IS NULL THEN SELECT i.deal_id INTO deal FROM inbound_email i WHERE i.message_id IS NOT NULL AND (i.message_id = e.in_reply_to OR i.message_id = ANY (e.references_ids)) AND i.deal_id IS NOT NULL LIMIT 1; END IF;
    IF deal IS NULL THEN
        num := substring(COALESCE(e.subject, '') FROM '\[(D-[0-9]{3,12})\]');
        IF num IS NOT NULL THEN SELECT id INTO deal FROM deals WHERE number = num AND deleted_at IS NULL; END IF;
    END IF;
    -- the contacts: every address but the mailbox's own and members'
    FOR addr IN SELECT DISTINCT lower(x) FROM unnest(ARRAY[e.from_address::text] || e.to_addresses || e.cc_addresses) x WHERE x IS NOT NULL LOOP
        CONTINUE WHEN EXISTS (SELECT 1 FROM mailboxes mb WHERE mb.address = addr::citext);
        CONTINUE WHEN EXISTS (SELECT 1 FROM members m WHERE m.email = addr::citext);
        cid := crm_contact_by_address(addr::citext);
        IF cid IS NOT NULL THEN cids := cids || cid; END IF;
    END LOOP;
    IF cardinality(cids) = 0 THEN
        IF sender_member IS NULL AND NOT EXISTS (SELECT 1 FROM members m WHERE m.email = e.from_address) THEN
            INSERT INTO leads (first_name, last_name, email, message, source_id, is_proposed, proposed_from_email_id, created_by)
            VALUES (NULLIF(split_part(COALESCE(e.from_name, ''), ' ', 1), ''), NULLIF(btrim(substring(COALESCE(e.from_name, '') FROM position(' ' IN COALESCE(e.from_name, '') || ' '))), ''),
                    e.from_address, left(COALESCE(e.body_text, ''), 2000), (SELECT id FROM lead_sources WHERE name = 'Mailbox'), true, p_email, NULL)
            RETURNING id INTO lid;
            UPDATE inbound_email SET match_status = 'proposed_lead', status = 'processed', processed_at = now(), sender_member_id = sender_member, deal_id = deal WHERE id = p_email;
            RETURN 'proposed_lead';
        END IF;
        UPDATE inbound_email SET match_status = 'unmatched', status = 'processed', processed_at = now(), sender_member_id = sender_member, deal_id = deal WHERE id = p_email;
        RETURN 'unmatched';
    END IF;
    who_wrote := crm_contact_by_address(e.from_address);
    dir := CASE WHEN who_wrote IS NOT NULL THEN 'in' ELSE 'out' END;
    FOREACH cid IN ARRAY cids LOOP
        INSERT INTO activities (kind, subject, contact_id, deal_id, owner_member_id, created_by, due_at, done_at, email_direction, inbound_email_id, source, is_private)
        VALUES ('email', COALESCE(e.subject, '(no subject)'), cid, deal, COALESCE(sender_member, (SELECT owner_member_id FROM contacts WHERE id = cid)), sender_member, e.received_at, e.received_at, dir, p_email, 'mailbox', e.is_private);
    END LOOP;
    UPDATE inbound_email SET match_status = 'matched', status = 'processed', processed_at = now(), sender_member_id = sender_member, deal_id = deal, matched_contact_ids = cids WHERE id = p_email;
    RETURN 'matched';
END$$;

-- The three templates db/011 seeds were written with '\n' inside ordinary string literals, so their bodies hold a backslash and an n, not a line break: every email started from one
-- carried the two characters. Broken into lines now (only the seeded shared rows, only that).
UPDATE email_templates SET body = replace(body, E'\\n', E'\n') WHERE is_shared AND owner_member_id IS NULL AND position(E'\\n' IN body) > 0;

COMMIT;
