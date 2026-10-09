-- 011: EMAIL — logged, not hosted (design §4, §6.3; D10): the LOGGING MAILBOX at MaluMail receives BCCs and replies; every message
-- is kept with its headers (`inbound_email`) before it is matched; the matcher lands it on contacts (by every address they use) and
-- the deal (by thread, then by the [D-000123] tag in the subject) as `activities` of kind email; a stranger becomes a PROPOSED lead;
-- a bounce or an auto-reply makes nothing. Out: `email_templates` with merge fields, sent through MaluMail with Reply-To the mailbox,
-- kept in `outbound_email`, logged as activities on every recipient contact; an opted-out contact is refused by name.
--
-- THE ESTATE'S DATA MODEL (design §6.5): `mailboxes`, `inbound_email`, `outbound_email` are Help Desk db/012 VERBATIM with the
-- external-key substitution (queue_id kept nullable and unused; ticket_id/message_ref_id → the appended contact_id/deal_id/activity_id and
-- match_status). `email_templates` is NEW CANONICAL (Help Desk's canned_responses has no merge fields; name/subject/body kept).
BEGIN;

CREATE TABLE mailboxes (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    address            citext NOT NULL UNIQUE,
    display_name       text NOT NULL,
    queue_id           bigint,                                       -- Help Desk's column, kept for the verbatim rule; unused here
    provider           text NOT NULL DEFAULT 'malumail' CHECK (provider IN ('malumail', 'imap')),
    webhook_secret_ref text,                                      -- the env key holding the webhook secret (MAILBOX_CRM_WEBHOOK_SECRET)
    signature          text,
    poll_enabled       boolean NOT NULL DEFAULT true,
    last_polled_at     timestamptz,
    last_uid           bigint,
    active             boolean NOT NULL DEFAULT true,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now(),
    -- appended (design §6.3)
    last_webhook_at    timestamptz,
    last_error         text,
    last_error_at      timestamptz
);
CREATE TRIGGER mailboxes_touch BEFORE UPDATE ON mailboxes FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
ALTER TABLE crm_settings ADD CONSTRAINT crm_settings_mailbox_fk FOREIGN KEY (mailbox_id) REFERENCES mailboxes(id) ON DELETE SET NULL;

-- Nothing is thrown away before it is linked.
CREATE TABLE inbound_email (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    mailbox_id      bigint NOT NULL REFERENCES mailboxes(id) ON DELETE CASCADE,
    provider_uid    text,
    message_id      text,
    in_reply_to     text,
    references_ids  text[] NOT NULL DEFAULT '{}',
    from_address    citext,
    from_name       text,
    to_addresses    text[] NOT NULL DEFAULT '{}',
    cc_addresses    text[] NOT NULL DEFAULT '{}',
    subject         text,
    body_text       text,
    body_html       text,
    headers         jsonb,
    received_at     timestamptz NOT NULL DEFAULT now(),
    status          text NOT NULL DEFAULT 'received' CHECK (status IN ('received', 'processed', 'ignored', 'failed', 'bounce', 'auto_reply', 'suppressed')),
    error           text,
    ticket_id       bigint,                                        -- Help Desk's columns, kept nullable for the verbatim rule; unused here
    message_ref_id  bigint,
    processed_at    timestamptz,
    created_at      timestamptz NOT NULL DEFAULT now(),
    -- appended (design §6.3)
    sender_member_id bigint REFERENCES members(id) ON DELETE SET NULL,   -- a seller BCC'ing (matched by their directory email)
    deal_id         bigint REFERENCES deals(id) ON DELETE SET NULL,      -- by thread, then the subject's [D-000123]
    match_status    text NOT NULL DEFAULT 'pending' CHECK (match_status IN ('pending', 'matched', 'proposed_lead', 'unmatched', 'bounce', 'ignored')),
    matched_contact_ids bigint[] NOT NULL DEFAULT '{}',
    is_private      boolean NOT NULL DEFAULT false
);
CREATE UNIQUE INDEX inbound_email_message_id_uq ON inbound_email (mailbox_id, message_id) WHERE message_id IS NOT NULL;
CREATE INDEX inbound_email_status_idx ON inbound_email (status, match_status, received_at);
CREATE INDEX inbound_email_from_idx ON inbound_email (from_address);
CREATE INDEX inbound_email_deal_idx ON inbound_email (deal_id);
CREATE INDEX inbound_email_refs_idx ON inbound_email USING gin (references_ids);
ALTER TABLE leads ADD CONSTRAINT leads_proposed_from_email_fk FOREIGN KEY (proposed_from_email_id) REFERENCES inbound_email(id) ON DELETE SET NULL;

CREATE TABLE outbound_email (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ticket_id       bigint,                                        -- Help Desk's columns, kept nullable; unused here
    message_ref_id  bigint,
    to_address      citext NOT NULL,
    subject         text NOT NULL,
    template        text,
    provider_message_id text,
    status          text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'sent', 'failed', 'suppressed')),
    error           text,
    sent_at         timestamptz,
    created_at      timestamptz NOT NULL DEFAULT now(),
    -- appended (design §6.3)
    sender_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    contact_id      bigint REFERENCES contacts(id) ON DELETE SET NULL,
    deal_id         bigint REFERENCES deals(id) ON DELETE SET NULL,
    cc_addresses    text[] NOT NULL DEFAULT '{}',
    body_text       text NOT NULL DEFAULT '',
    body_html       text,
    message_id      text,                                          -- ours, for threading replies back (In-Reply-To / References)
    template_id     bigint,                                        -- FK below
    has_ics         boolean NOT NULL DEFAULT false,
    is_private      boolean NOT NULL DEFAULT false
);
CREATE INDEX outbound_email_contact_idx ON outbound_email (contact_id, created_at DESC);
CREATE INDEX outbound_email_deal_idx ON outbound_email (deal_id);
CREATE INDEX outbound_email_message_id_idx ON outbound_email (message_id);
CREATE INDEX outbound_email_status_idx ON outbound_email (status, created_at);
ALTER TABLE activities ADD CONSTRAINT activities_inbound_email_fk FOREIGN KEY (inbound_email_id) REFERENCES inbound_email(id) ON DELETE SET NULL;
ALTER TABLE activities ADD CONSTRAINT activities_outbound_email_fk FOREIGN KEY (outbound_email_id) REFERENCES outbound_email(id) ON DELETE SET NULL;

CREATE TABLE email_templates (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name            text NOT NULL,
    subject         text NOT NULL,
    body            text NOT NULL,                                 -- Markdown with {{contact.first_name}} {{contact.name}} {{account.name}} {{deal.title}} {{deal.value}} {{sender.name}} {{sender.signature}}
    kind            text NOT NULL DEFAULT 'general' CHECK (kind IN ('general', 'intro', 'follow_up', 'proposal', 'meeting', 'thank_you', 'renewal', 'win_back')),
    is_shared       boolean NOT NULL DEFAULT false,                -- Marketing's; own templates are the owner's alone
    owner_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    usage_count     integer NOT NULL DEFAULT 0,
    archived_at     timestamptz,
    created_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    CHECK (is_shared OR owner_member_id IS NOT NULL)
);
CREATE TRIGGER email_templates_touch BEFORE UPDATE ON email_templates FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
ALTER TABLE outbound_email ADD CONSTRAINT outbound_email_template_fk FOREIGN KEY (template_id) REFERENCES email_templates(id) ON DELETE SET NULL;
INSERT INTO email_templates (name, subject, body, kind, is_shared) VALUES
 ('Introduction', 'Hello from {{sender.company}}', 'Hi {{contact.first_name}},\n\nThank you for your interest in {{sender.company}}. I would be glad to learn what you are working on at {{account.name}} and see whether we can help.\n\nWould a short call this week suit you?\n\n{{sender.signature}}', 'intro', true),
 ('Follow-up', 'Following up — {{deal.title}}', 'Hi {{contact.first_name}},\n\nFollowing up on {{deal.title}}. Is there anything you need from us to take the next step?\n\n{{sender.signature}}', 'follow_up', true),
 ('Meeting confirmation', 'Confirmed: {{activity.subject}} on {{activity.date}}', 'Hi {{contact.first_name}},\n\nConfirming our {{activity.subject}} on {{activity.date}} at {{activity.time}}. The invitation is attached.\n\n{{sender.signature}}', 'meeting', true);

-- May this address be emailed? Refused by name when the contact opted out (the agent's first check, the sender's second).
CREATE OR REPLACE FUNCTION crm_email_allowed(p_contact bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT EXISTS (SELECT 1 FROM contacts c WHERE c.id = p_contact AND c.deleted_at IS NULL AND NOT c.email_opt_out AND (c.email IS NOT NULL OR EXISTS (SELECT 1 FROM contact_emails e WHERE e.contact_id = c.id)));
$$;

-- An opt-out, with the moment and the reason (the unsubscribe link writes 'unsubscribe_link').
CREATE OR REPLACE FUNCTION crm_contact_opt_out(p_contact bigint, p_channel text, p_reason text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF p_channel = 'email' THEN UPDATE contacts SET email_opt_out = true, email_opt_out_at = now(), opt_out_reason = p_reason WHERE id = p_contact;
    ELSIF p_channel = 'sms' THEN UPDATE contacts SET sms_opt_out = true, sms_opt_out_at = now(), opt_out_reason = p_reason WHERE id = p_contact;
    ELSE RAISE EXCEPTION 'opt-out channel %', p_channel; END IF;
END$$;

-- THE MATCHER for one inbound email (the worker runs it; slice 5 fills the PHP that reads the webhook and the poll):
-- the sender a member?; the deal by our message ids in References / In-Reply-To, else by [D-000123] in the subject; every other
-- address → a contact (contact_emails, email, email_alt); each matched contact gets an activity (kind email, direction in when a
-- contact wrote, out when a seller BCC'd); no contact and the sender not a member → a PROPOSED lead; a bounce/auto-reply status → nothing.
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
    SELECT o.deal_id INTO deal FROM outbound_email o WHERE o.message_id IS NOT NULL AND (o.message_id = e.in_reply_to OR o.message_id = ANY (e.references_ids)) AND o.deal_id IS NOT NULL LIMIT 1;
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

GRANT SELECT, INSERT, UPDATE, DELETE ON mailboxes, inbound_email, outbound_email, email_templates TO sales_crm_rw;
GRANT EXECUTE ON FUNCTION crm_email_allowed(bigint) TO sales_crm_rw, sales_crm_records_ro;
REVOKE ALL ON FUNCTION crm_contact_opt_out(bigint, text, text), crm_match_inbound_email(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION crm_contact_opt_out(bigint, text, text), crm_match_inbound_email(bigint) TO sales_crm_rw;

COMMIT;
