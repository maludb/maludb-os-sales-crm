-- 013: the tables the estate already keeps and this application keeps rows of — notes, attachments, notifications, the person's
-- preferences, the outbox the worker sends, agent dispatches — plus the Assistant's PROPOSALS, the MERGE and the TOMBSTONE rules
-- (design §3.5, §6.5; D12, D14).
--
-- THE ESTATE'S DATA MODEL: `notes` and `attachments` are GL db/009 VERBATIM (record_type widened); `notifications`, `notification_prefs`,
-- `notification_outbox` are Spaces db/012 = DocCloud db/013 VERBATIM (member-only; the kind lists ours; prefs append the reminder and
-- morning-note settings); `agent_dispatches` is GL db/014 = CT db/013 verbatim (kinds ours; deal_id/lead_id appended); `assistant_proposals`
-- is DocCloud's `clerk_proposals` shape under this application's name (a proposal a person accepts in one click).
BEGIN;

-- reuse: GL db/009 verbatim; record_type widened
CREATE TABLE attachments (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type   text NOT NULL CHECK (record_type IN ('lead', 'account', 'contact', 'deal', 'activity', 'campaign', 'inbound_email', 'outbound_email')),
    record_id     bigint NOT NULL,
    filename      text NOT NULL,
    mime_type     text NOT NULL,
    byte_size     bigint NOT NULL CHECK (byte_size >= 0),
    sha256        text NOT NULL,
    storage_path  text NOT NULL,
    uploaded_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX attachments_record_idx ON attachments (record_type, record_id);
CREATE INDEX attachments_uploaded_by_idx ON attachments (uploaded_by);

-- reuse: GL db/009 verbatim
CREATE TABLE notes (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type   text NOT NULL,
    record_id     bigint NOT NULL,
    member_id     bigint REFERENCES members(id) ON DELETE SET NULL,
    body          text NOT NULL,
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX notes_record_idx ON notes (record_type, record_id, created_at DESC);
CREATE INDEX notes_member_idx ON notes (member_id);
-- a note is a touch
CREATE OR REPLACE FUNCTION crm_notes_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    CASE NEW.record_type
        WHEN 'account' THEN PERFORM crm_touch(NEW.record_id, NULL, NULL, NEW.created_at);
        WHEN 'contact' THEN PERFORM crm_touch((SELECT account_id FROM contacts WHERE id = NEW.record_id), NEW.record_id, NULL, NEW.created_at);
        WHEN 'deal' THEN PERFORM crm_touch((SELECT account_id FROM deals WHERE id = NEW.record_id), NULL, NEW.record_id, NEW.created_at);
        WHEN 'lead' THEN UPDATE leads SET last_touched_at = GREATEST(COALESCE(last_touched_at, '-infinity'), NEW.created_at), status = CASE WHEN status = 'new' THEN 'working' ELSE status END WHERE id = NEW.record_id;
        ELSE NULL;
    END CASE;
    RETURN NULL;
END$$;
CREATE TRIGGER notes_after AFTER INSERT ON notes FOR EACH ROW EXECUTE FUNCTION crm_notes_after();

-- reuse: Spaces db/012 verbatim (member-only); kinds ours; deal_id/contact_id/activity_id appended
CREATE TABLE notifications (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id    bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    kind         text NOT NULL CHECK (kind IN ('reminder', 'assigned', 'lead_assigned', 'reply_received', 'deal_won', 'deal_lost', 'deal_moved', 'mention', 'proposal',
                                               'morning_note', 'import_done', 'export_ready', 'duplicate_found', 'stage_wip', 'digest')),
    record_type  text,
    record_id    bigint,
    title        text NOT NULL,
    body         text,
    read_at      timestamptz,
    created_at   timestamptz NOT NULL DEFAULT now(),
    -- appended (design §6.3)
    deal_id      bigint REFERENCES deals(id) ON DELETE CASCADE,
    contact_id   bigint REFERENCES contacts(id) ON DELETE CASCADE,
    activity_id  bigint REFERENCES activities(id) ON DELETE CASCADE,
    actor_member_id bigint REFERENCES members(id) ON DELETE SET NULL
);
CREATE INDEX notifications_member_idx ON notifications (member_id, created_at DESC) WHERE read_at IS NULL;
CREATE INDEX notifications_record_idx ON notifications (record_type, record_id);
CREATE INDEX notifications_member_all_idx ON notifications (member_id, created_at DESC);

CREATE TABLE notification_prefs (
    member_id      bigint PRIMARY KEY REFERENCES members(id) ON DELETE CASCADE,
    email_enabled  boolean NOT NULL DEFAULT true,
    text_enabled   boolean NOT NULL DEFAULT false,
    kinds          text[] NOT NULL DEFAULT '{reminder,assigned,lead_assigned,reply_received,deal_won,mention,proposal,morning_note,import_done,export_ready}',
    text_kinds     text[] NOT NULL DEFAULT '{reminder}',
    updated_at     timestamptz NOT NULL DEFAULT now(),
    -- appended (design §6.3, D9, D14)
    default_remind_minutes integer CHECK (default_remind_minutes IS NULL OR default_remind_minutes BETWEEN 0 AND 10080),   -- NULL = the settings'
    morning_note   boolean NOT NULL DEFAULT true,
    morning_hour   smallint CHECK (morning_hour IS NULL OR morning_hour BETWEEN 0 AND 23),                                    -- NULL = the settings'
    digest         boolean NOT NULL DEFAULT false
);
CREATE TRIGGER notification_prefs_touch BEFORE UPDATE ON notification_prefs FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE notification_outbox (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    channel        text NOT NULL CHECK (channel IN ('email', 'text')),
    member_id      bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    to_email       citext,
    kind           text NOT NULL,
    record_type    text,
    record_id      bigint,
    dedupe_key     text,
    subject        text,
    body           text NOT NULL,
    body_html      text,
    status         text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'sent', 'skipped', 'failed')),
    attempts       integer NOT NULL DEFAULT 0,
    detail         text,
    provider_ref   text,
    send_after     timestamptz NOT NULL DEFAULT now(),
    sent_at        timestamptz,
    created_at     timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX notification_outbox_dedupe ON notification_outbox (dedupe_key) WHERE dedupe_key IS NOT NULL;
CREATE INDEX notification_outbox_queue_idx ON notification_outbox (status, send_after) WHERE status = 'queued';
CREATE INDEX notification_outbox_member_idx ON notification_outbox (member_id, created_at DESC);
CREATE INDEX notification_outbox_record_idx ON notification_outbox (record_type, record_id);

-- One notification: the bell row, and the outbox rows the person's preferences ask for (the worker delivers). DocCloud's dc_notify.
CREATE OR REPLACE FUNCTION crm_notify(p_member_id bigint, p_kind text, p_title text, p_body text, p_record_type text DEFAULT NULL, p_record_id bigint DEFAULT NULL,
                                      p_deal_id bigint DEFAULT NULL, p_contact_id bigint DEFAULT NULL, p_activity_id bigint DEFAULT NULL, p_actor bigint DEFAULT NULL, p_dedupe text DEFAULT NULL) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE nid bigint; p notification_prefs%ROWTYPE; em citext;
BEGIN
    IF p_dedupe IS NOT NULL AND EXISTS (SELECT 1 FROM notification_outbox WHERE dedupe_key = p_dedupe) THEN RETURN NULL; END IF;
    INSERT INTO notifications (member_id, kind, title, body, record_type, record_id, deal_id, contact_id, activity_id, actor_member_id)
    VALUES (p_member_id, p_kind, p_title, p_body, p_record_type, p_record_id, p_deal_id, p_contact_id, p_activity_id, p_actor) RETURNING id INTO nid;
    SELECT * INTO p FROM notification_prefs WHERE member_id = p_member_id;
    IF NOT FOUND THEN INSERT INTO notification_prefs (member_id) VALUES (p_member_id) RETURNING * INTO p; END IF;
    SELECT email INTO em FROM members WHERE id = p_member_id;
    IF p.email_enabled AND p_kind = ANY (p.kinds) AND em IS NOT NULL THEN
        INSERT INTO notification_outbox (channel, member_id, to_email, kind, record_type, record_id, dedupe_key, subject, body)
        VALUES ('email', p_member_id, em, p_kind, p_record_type, p_record_id, p_dedupe, p_title, COALESCE(p_body, p_title)) ON CONFLICT DO NOTHING;
    END IF;
    IF p.text_enabled AND p_kind = ANY (p.text_kinds) THEN
        INSERT INTO notification_outbox (channel, member_id, kind, record_type, record_id, dedupe_key, subject, body)
        VALUES ('text', p_member_id, p_kind, p_record_type, p_record_id, CASE WHEN p_dedupe IS NULL THEN NULL ELSE p_dedupe || ':text' END, p_title, left(COALESCE(p_body, p_title), 300)) ON CONFLICT DO NOTHING;
    END IF;
    RETURN nid;
END$$;

-- The reminder delivered (the worker's `reminders` pass calls it per due row): a notification, reminded_at set, one transaction.
CREATE OR REPLACE FUNCTION crm_deliver_reminder(p_activity bigint) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE a activities%ROWTYPE; nid bigint; rec text;
BEGIN
    SELECT * INTO a FROM activities WHERE id = p_activity AND done_at IS NULL AND deleted_at IS NULL FOR UPDATE;
    IF NOT FOUND OR a.owner_member_id IS NULL THEN RETURN NULL; END IF;
    rec := COALESCE((SELECT title FROM deals WHERE id = a.deal_id), (SELECT name FROM contacts WHERE id = a.contact_id), (SELECT name FROM accounts WHERE id = a.account_id), '');
    nid := crm_notify(a.owner_member_id, 'reminder', a.kind || ': ' || a.subject || CASE WHEN rec <> '' THEN ' — ' || rec ELSE '' END,
                      'At ' || to_char(COALESCE(a.starts_at, a.due_at) AT TIME ZONE COALESCE(a.timezone, crm_setting_text('timezone')), 'Dy DD Mon HH24:MI') || COALESCE('. ' || a.location, ''),
                      'activity', a.id, a.deal_id, a.contact_id, a.id, NULL, 'reminder:' || a.id || ':' || COALESCE(a.snoozed_until, a.remind_at)::text);
    UPDATE activities SET reminded_at = now(), snoozed_until = NULL WHERE id = p_activity;
    RETURN nid;
END$$;

-- reuse: GL db/014 = CT db/013 verbatim; kinds ours; deal_id/lead_id appended
CREATE TABLE agent_dispatches (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type      text,
    record_id        bigint,
    agent_member_id  bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    kind             text NOT NULL CHECK (kind IN ('lead_qualify', 'next_step', 'reminder', 'stage_entered', 'ask', 'duty_proposal')),
    via              text NOT NULL CHECK (via IN ('chat', 'wake', 'duty')),
    acting_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    run_id           bigint,
    request_id       text,
    status           text NOT NULL DEFAULT 'sent' CHECK (status IN ('sent', 'answered', 'refused', 'failed', 'awaiting_approval')),
    reply_excerpt    text,
    detail           text,
    attempts         integer NOT NULL DEFAULT 1,
    created_at       timestamptz NOT NULL DEFAULT now(),
    answered_at      timestamptz,
    -- appended (design §5)
    deal_id          bigint REFERENCES deals(id) ON DELETE SET NULL,
    lead_id          bigint REFERENCES leads(id) ON DELETE SET NULL,
    next_attempt_at  timestamptz
);
CREATE INDEX agent_dispatches_record_idx ON agent_dispatches (record_type, record_id, created_at DESC);
CREATE INDEX agent_dispatches_agent_idx ON agent_dispatches (agent_member_id, created_at DESC);
CREATE INDEX agent_dispatches_acting_idx ON agent_dispatches (acting_member_id);

-- A stage with ask_assistant set: a dispatch when a deal enters it (the worker sends the chat turn).
CREATE OR REPLACE FUNCTION crm_deals_dispatch() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE agent bigint;
BEGIN
    IF (SELECT ask_assistant FROM stages WHERE id = NEW.stage_id) THEN
        SELECT id INTO agent FROM members WHERE member_kind = 'agent' AND status = 'active' AND capability IS NOT NULL AND display_name ILIKE '%assistant%' ORDER BY id LIMIT 1;
        IF agent IS NOT NULL THEN
            INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, acting_member_id, deal_id, status, detail)
            VALUES ('deal', NEW.id, agent, 'stage_entered', 'chat', app_current_member_id(), NEW.id, 'sent', 'stage ' || NEW.stage_id);
        END IF;
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER deals_dispatch AFTER INSERT OR UPDATE OF stage_id ON deals FOR EACH ROW EXECUTE FUNCTION crm_deals_dispatch();

-- The Assistant's proposals (D14): a next step on a deal with none, a lead's qualification and score, a duplicate pair, a draft email —
-- accepted in one click by the person whose decision it is. DocCloud's clerk_proposals shape.
CREATE TABLE assistant_proposals (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind          text NOT NULL CHECK (kind IN ('next_step', 'lead_qualify', 'lead_unqualify', 'duplicate', 'email_draft', 'rotting', 'reassign', 'close_date')),
    deal_id       bigint REFERENCES deals(id) ON DELETE CASCADE,
    lead_id       bigint REFERENCES leads(id) ON DELETE CASCADE,
    contact_id    bigint REFERENCES contacts(id) ON DELETE CASCADE,
    account_id    bigint REFERENCES accounts(id) ON DELETE CASCADE,
    for_member_id bigint REFERENCES members(id) ON DELETE CASCADE,          -- whose decision it is (the owner, a manager)
    title         text NOT NULL,
    payload       jsonb NOT NULL DEFAULT '{}'::jsonb,                        -- next_step: {kind, subject, at, body}; lead_qualify: {score, reasons[]}; duplicate: {other_id, matched_on}; email_draft: {subject, body}
    proposed_by   bigint REFERENCES members(id) ON DELETE SET NULL,          -- the Assistant
    status        text NOT NULL DEFAULT 'proposed' CHECK (status IN ('proposed', 'accepted', 'dismissed', 'expired')),
    decided_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    decided_at    timestamptz,
    result_activity_id bigint REFERENCES activities(id) ON DELETE SET NULL,  -- the activity an accepted next_step made
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX assistant_proposals_open_idx ON assistant_proposals (for_member_id, created_at DESC) WHERE status = 'proposed';
CREATE INDEX assistant_proposals_kind_idx ON assistant_proposals (kind, status);
CREATE UNIQUE INDEX assistant_proposals_one_open_next_step ON assistant_proposals (deal_id) WHERE kind = 'next_step' AND status = 'proposed';
ALTER TABLE activities ADD CONSTRAINT activities_proposal_fk FOREIGN KEY (proposal_id) REFERENCES assistant_proposals(id) ON DELETE SET NULL;

-- Accepting a next-step proposal makes the activity (one click): the payload's kind, subject, moment and body, owned by the decider.
CREATE OR REPLACE FUNCTION crm_proposal_accept(p_proposal bigint) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE p assistant_proposals%ROWTYPE; aid bigint; k text; whenat timestamptz;
BEGIN
    SELECT * INTO p FROM assistant_proposals WHERE id = p_proposal AND status = 'proposed' FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'The proposal is not open' USING ERRCODE = 'check_violation'; END IF;
    IF p.kind = 'next_step' THEN
        k := COALESCE(p.payload ->> 'kind', 'task'); whenat := COALESCE((p.payload ->> 'at')::timestamptz, now() + interval '1 day');
        INSERT INTO activities (kind, subject, body, deal_id, contact_id, account_id, lead_id, owner_member_id, due_at, starts_at, source, proposal_id)
        VALUES (k, COALESCE(p.payload ->> 'subject', 'Next step'), p.payload ->> 'body', p.deal_id, p.contact_id, p.account_id, p.lead_id, app_current_member_id(),
                CASE WHEN k IN ('task', 'deadline', 'email') THEN whenat END, CASE WHEN k IN ('call', 'meeting', 'lunch') THEN whenat END, 'proposal', p.id)
        RETURNING id INTO aid;
    ELSIF p.kind = 'lead_qualify' THEN
        UPDATE leads SET score = (p.payload ->> 'score')::smallint, status = CASE WHEN status = 'new' THEN 'working' ELSE status END WHERE id = p.lead_id;
    ELSIF p.kind = 'close_date' THEN
        UPDATE deals SET expected_close_date = (p.payload ->> 'date')::date WHERE id = p.deal_id;
    END IF;
    UPDATE assistant_proposals SET status = 'accepted', decided_by = app_current_member_id(), decided_at = now(), result_activity_id = aid WHERE id = p_proposal;
    RETURN aid;
END$$;

-- ---------------------------------------------------------------------------------------------
-- THE MERGE (§3.5) and THE TOMBSTONE
-- ---------------------------------------------------------------------------------------------
-- Merge two records of one kind: the survivor keeps its values, takes the loser's where its own are empty, every child is
-- re-pointed, the loser is tombstoned with merged_into_id. Contacts, accounts, leads.
CREATE OR REPLACE FUNCTION crm_records_merge(p_kind text, p_survivor bigint, p_loser bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id();
BEGIN
    IF p_survivor = p_loser THEN RAISE EXCEPTION 'A record is not merged into itself' USING ERRCODE = 'check_violation'; END IF;
    IF p_kind = 'contact' THEN
        PERFORM 1 FROM contacts WHERE id IN (p_survivor, p_loser) AND deleted_at IS NULL FOR UPDATE;
        UPDATE contacts s SET first_name = COALESCE(s.first_name, l.first_name), last_name = COALESCE(s.last_name, l.last_name), title = COALESCE(s.title, l.title),
               email = COALESCE(s.email, l.email), email_alt = COALESCE(s.email_alt, CASE WHEN l.email IS DISTINCT FROM s.email THEN l.email END, l.email_alt),
               phone = COALESCE(s.phone, l.phone), mobile = COALESCE(s.mobile, l.mobile), account_id = COALESCE(s.account_id, l.account_id), role = COALESCE(s.role, l.role),
               linkedin_url = COALESCE(s.linkedin_url, l.linkedin_url), address = COALESCE(s.address, l.address), city = COALESCE(s.city, l.city), region = COALESCE(s.region, l.region),
               country = COALESCE(s.country, l.country), birthday = COALESCE(s.birthday, l.birthday), owner_member_id = COALESCE(s.owner_member_id, l.owner_member_id),
               source_id = COALESCE(s.source_id, l.source_id), email_opt_out = s.email_opt_out OR l.email_opt_out, sms_opt_out = s.sms_opt_out OR l.sms_opt_out,
               do_not_call = s.do_not_call OR l.do_not_call, last_touched_at = GREATEST(s.last_touched_at, l.last_touched_at), custom_values = l.custom_values || s.custom_values
          FROM contacts l WHERE s.id = p_survivor AND l.id = p_loser;
        UPDATE contact_emails SET contact_id = p_survivor, is_primary = false WHERE contact_id = p_loser;
        UPDATE activities SET contact_id = p_survivor WHERE contact_id = p_loser;
        UPDATE activity_participants ap SET contact_id = p_survivor WHERE contact_id = p_loser AND NOT EXISTS (SELECT 1 FROM activity_participants x WHERE x.activity_id = ap.activity_id AND x.contact_id = p_survivor);
        DELETE FROM activity_participants WHERE contact_id = p_loser;
        UPDATE deals SET primary_contact_id = p_survivor WHERE primary_contact_id = p_loser;
        UPDATE deal_contacts dc SET contact_id = p_survivor WHERE contact_id = p_loser AND NOT EXISTS (SELECT 1 FROM deal_contacts x WHERE x.deal_id = dc.deal_id AND x.contact_id = p_survivor);
        DELETE FROM deal_contacts WHERE contact_id = p_loser;
        UPDATE campaign_members cm SET contact_id = p_survivor WHERE contact_id = p_loser AND NOT EXISTS (SELECT 1 FROM campaign_members x WHERE x.campaign_id = cm.campaign_id AND x.contact_id = p_survivor);
        DELETE FROM campaign_members WHERE contact_id = p_loser;
        UPDATE leads SET converted_contact_id = p_survivor WHERE converted_contact_id = p_loser;
        UPDATE outbound_email SET contact_id = p_survivor WHERE contact_id = p_loser;
        UPDATE notes SET record_id = p_survivor WHERE record_type = 'contact' AND record_id = p_loser;
        UPDATE attachments SET record_id = p_survivor WHERE record_type = 'contact' AND record_id = p_loser;
        INSERT INTO record_tags (record_type, record_id, tag_id) SELECT 'contact', p_survivor, tag_id FROM record_tags WHERE record_type = 'contact' AND record_id = p_loser ON CONFLICT DO NOTHING;
        DELETE FROM record_tags WHERE record_type = 'contact' AND record_id = p_loser;
        UPDATE list_members lm SET record_id = p_survivor FROM lists li WHERE li.id = lm.list_id AND li.record_kind = 'contact' AND lm.record_id = p_loser AND NOT EXISTS (SELECT 1 FROM list_members x WHERE x.list_id = lm.list_id AND x.record_id = p_survivor);
        DELETE FROM list_members lm USING lists li WHERE li.id = lm.list_id AND li.record_kind = 'contact' AND lm.record_id = p_loser;
        UPDATE notifications SET contact_id = p_survivor WHERE contact_id = p_loser;
        UPDATE contacts SET merged_into_id = p_survivor, deleted_at = now(), deleted_by = me, is_primary = false WHERE id = p_loser;
        PERFORM crm_contact_lifecycle(p_survivor);
    ELSIF p_kind = 'account' THEN
        PERFORM 1 FROM accounts WHERE id IN (p_survivor, p_loser) AND deleted_at IS NULL FOR UPDATE;
        UPDATE accounts s SET legal_name = COALESCE(s.legal_name, l.legal_name), domain = COALESCE(s.domain, l.domain), website = COALESCE(s.website, l.website), industry = COALESCE(s.industry, l.industry),
               employees = COALESCE(s.employees, l.employees), annual_revenue = COALESCE(s.annual_revenue, l.annual_revenue), phone = COALESCE(s.phone, l.phone), email = COALESCE(s.email, l.email),
               billing_address = COALESCE(s.billing_address, l.billing_address), shipping_address = COALESCE(s.shipping_address, l.shipping_address), city = COALESCE(s.city, l.city),
               region = COALESCE(s.region, l.region), country = COALESCE(s.country, l.country), owner_member_id = COALESCE(s.owner_member_id, l.owner_member_id), source_id = COALESCE(s.source_id, l.source_id),
               description = COALESCE(s.description, l.description), gl_customer_id = COALESCE(s.gl_customer_id, l.gl_customer_id), helpdesk_organization_id = COALESCE(s.helpdesk_organization_id, l.helpdesk_organization_id),
               last_touched_at = GREATEST(s.last_touched_at, l.last_touched_at), custom_values = l.custom_values || s.custom_values
          FROM accounts l WHERE s.id = p_survivor AND l.id = p_loser;
        UPDATE accounts SET domain = NULL WHERE id = p_loser;   -- frees the unique domain for the survivor
        UPDATE contacts SET account_id = p_survivor, is_primary = false WHERE account_id = p_loser;
        UPDATE deals SET account_id = p_survivor WHERE account_id = p_loser;
        UPDATE activities SET account_id = p_survivor WHERE account_id = p_loser;
        UPDATE accounts SET parent_account_id = p_survivor WHERE parent_account_id = p_loser AND id <> p_survivor;
        UPDATE leads SET converted_account_id = p_survivor WHERE converted_account_id = p_loser;
        UPDATE notes SET record_id = p_survivor WHERE record_type = 'account' AND record_id = p_loser;
        UPDATE attachments SET record_id = p_survivor WHERE record_type = 'account' AND record_id = p_loser;
        INSERT INTO record_tags (record_type, record_id, tag_id) SELECT 'account', p_survivor, tag_id FROM record_tags WHERE record_type = 'account' AND record_id = p_loser ON CONFLICT DO NOTHING;
        DELETE FROM record_tags WHERE record_type = 'account' AND record_id = p_loser;
        UPDATE list_members lm SET record_id = p_survivor FROM lists li WHERE li.id = lm.list_id AND li.record_kind = 'account' AND lm.record_id = p_loser AND NOT EXISTS (SELECT 1 FROM list_members x WHERE x.list_id = lm.list_id AND x.record_id = p_survivor);
        DELETE FROM list_members lm USING lists li WHERE li.id = lm.list_id AND li.record_kind = 'account' AND lm.record_id = p_loser;
        UPDATE accounts SET merged_into_id = p_survivor, deleted_at = now(), deleted_by = me WHERE id = p_loser;
        PERFORM crm_account_recount(p_survivor);
    ELSIF p_kind = 'lead' THEN
        PERFORM 1 FROM leads WHERE id IN (p_survivor, p_loser) AND deleted_at IS NULL AND converted_at IS NULL FOR UPDATE;
        UPDATE leads s SET first_name = COALESCE(s.first_name, l.first_name), last_name = COALESCE(s.last_name, l.last_name), company = COALESCE(s.company, l.company), title = COALESCE(s.title, l.title),
               email = COALESCE(s.email, l.email), phone = COALESCE(s.phone, l.phone), website = COALESCE(s.website, l.website), message = COALESCE(s.message, l.message),
               source_id = COALESCE(s.source_id, l.source_id), campaign_id = COALESCE(s.campaign_id, l.campaign_id), owner_member_id = COALESCE(s.owner_member_id, l.owner_member_id),
               last_touched_at = GREATEST(s.last_touched_at, l.last_touched_at), custom_values = l.custom_values || s.custom_values
          FROM leads l WHERE s.id = p_survivor AND l.id = p_loser;
        UPDATE activities SET lead_id = p_survivor WHERE lead_id = p_loser;
        UPDATE campaign_members cm SET lead_id = p_survivor WHERE lead_id = p_loser AND NOT EXISTS (SELECT 1 FROM campaign_members x WHERE x.campaign_id = cm.campaign_id AND x.lead_id = p_survivor);
        DELETE FROM campaign_members WHERE lead_id = p_loser;
        UPDATE notes SET record_id = p_survivor WHERE record_type = 'lead' AND record_id = p_loser;
        UPDATE attachments SET record_id = p_survivor WHERE record_type = 'lead' AND record_id = p_loser;
        UPDATE leads SET deleted_at = now(), deleted_by = me WHERE id = p_loser;
    ELSE
        RAISE EXCEPTION 'crm_records_merge: kind %', p_kind;
    END IF;
END$$;

-- Tombstone and restore (a deleted record waits tombstone_days; the worker purges).
CREATE OR REPLACE FUNCTION crm_record_delete(p_kind text, p_id bigint, p_restore boolean DEFAULT false) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id(); acc bigint;
BEGIN
    IF p_kind NOT IN ('lead', 'account', 'contact', 'deal', 'activity', 'campaign') THEN RAISE EXCEPTION 'crm_record_delete: kind %', p_kind; END IF;
    IF p_restore THEN
        EXECUTE format('UPDATE %I SET deleted_at = NULL, deleted_by = NULL WHERE id = $1', p_kind || 's') USING p_id;
    ELSE
        EXECUTE format('UPDATE %I SET deleted_at = now(), deleted_by = $2 WHERE id = $1 AND deleted_at IS NULL', p_kind || 's') USING p_id, me;
    END IF;
    IF p_kind = 'deal' THEN SELECT account_id INTO acc FROM deals WHERE id = p_id; PERFORM crm_account_recount(acc); END IF;
    IF p_kind = 'activity' THEN PERFORM crm_refresh_next_activity(a.account_id, a.contact_id, a.deal_id, a.lead_id) FROM activities a WHERE a.id = p_id; END IF;
END$$;

-- Purge what has waited long enough (the worker): children go with the record.
CREATE OR REPLACE FUNCTION crm_tombstones_purge(p_limit integer DEFAULT 200) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE cutoff timestamptz := now() - make_interval(days => crm_setting_int('tombstone_days')::integer); n integer := 0; r record;
BEGIN
    FOR r IN SELECT 'activity' AS k, id FROM activities WHERE deleted_at < cutoff LIMIT p_limit LOOP DELETE FROM activities WHERE id = r.id; n := n + 1; END LOOP;
    FOR r IN SELECT id FROM deals WHERE deleted_at < cutoff LIMIT p_limit LOOP
        DELETE FROM notes WHERE record_type = 'deal' AND record_id = r.id; DELETE FROM attachments WHERE record_type = 'deal' AND record_id = r.id; DELETE FROM record_tags WHERE record_type = 'deal' AND record_id = r.id;
        DELETE FROM deals WHERE id = r.id; n := n + 1;
    END LOOP;
    FOR r IN SELECT id FROM leads WHERE deleted_at < cutoff LIMIT p_limit LOOP
        DELETE FROM notes WHERE record_type = 'lead' AND record_id = r.id; DELETE FROM attachments WHERE record_type = 'lead' AND record_id = r.id; DELETE FROM record_tags WHERE record_type = 'lead' AND record_id = r.id;
        DELETE FROM leads WHERE id = r.id; n := n + 1;
    END LOOP;
    FOR r IN SELECT id FROM contacts WHERE deleted_at < cutoff LIMIT p_limit LOOP
        DELETE FROM notes WHERE record_type = 'contact' AND record_id = r.id; DELETE FROM attachments WHERE record_type = 'contact' AND record_id = r.id; DELETE FROM record_tags WHERE record_type = 'contact' AND record_id = r.id;
        DELETE FROM contacts WHERE id = r.id; n := n + 1;
    END LOOP;
    FOR r IN SELECT id FROM accounts WHERE deleted_at < cutoff AND NOT EXISTS (SELECT 1 FROM deals WHERE account_id = accounts.id) AND NOT EXISTS (SELECT 1 FROM contacts WHERE account_id = accounts.id) LIMIT p_limit LOOP
        DELETE FROM notes WHERE record_type = 'account' AND record_id = r.id; DELETE FROM attachments WHERE record_type = 'account' AND record_id = r.id; DELETE FROM record_tags WHERE record_type = 'account' AND record_id = r.id;
        DELETE FROM accounts WHERE id = r.id; n := n + 1;
    END LOOP;
    RETURN n;
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON attachments, notes, notifications, notification_prefs, notification_outbox, agent_dispatches, assistant_proposals TO sales_crm_rw;
REVOKE ALL ON FUNCTION crm_notify(bigint, text, text, text, text, bigint, bigint, bigint, bigint, bigint, text), crm_deliver_reminder(bigint), crm_proposal_accept(bigint),
    crm_records_merge(text, bigint, bigint), crm_record_delete(text, bigint, boolean), crm_tombstones_purge(integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION crm_notify(bigint, text, text, text, text, bigint, bigint, bigint, bigint, bigint, text), crm_deliver_reminder(bigint), crm_proposal_accept(bigint),
    crm_records_merge(text, bigint, bigint), crm_record_delete(text, bigint, boolean), crm_tombstones_purge(integer) TO sales_crm_rw;

COMMIT;
