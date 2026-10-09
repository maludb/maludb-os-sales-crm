-- 010: ACTIVITIES — the work — with the REMINDER as their columns, the next step on every record, and the CALENDAR FEED
-- (design §6.3; D8, D9). One table for a call, an email, a meeting, a task, a deadline, a lunch, on any of the four records; a due
-- time (a task, a deadline) or a start and an end (a meeting, a call, a lunch); done with an outcome; a reminder before it that the
-- worker delivers; `next_activity_at` kept on deals, contacts, accounts and leads by trigger — the board's four colours read it.
--
-- THE ESTATE'S DATA MODEL (design §6.5): `activities`, `activity_participants` are NEW CANONICAL (compared: Spaces `reminders` — a note
-- to self; Help Desk `time_entries` and Projects `worklogs` — time; Help Desk `messages` — a ticket's correspondence). `calendar_feeds`
-- is txtSchedules db/012 VERBATIM.
BEGIN;

CREATE TABLE activities (
    id                    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind                  text NOT NULL CHECK (kind IN ('call', 'email', 'meeting', 'task', 'deadline', 'lunch')),
    subject               text NOT NULL,
    body                  text,                                                        -- Markdown; an email's body lives in inbound/outbound_email
    account_id            bigint REFERENCES accounts(id) ON DELETE SET NULL,
    contact_id            bigint REFERENCES contacts(id) ON DELETE SET NULL,
    deal_id               bigint REFERENCES deals(id) ON DELETE SET NULL,
    lead_id               bigint REFERENCES leads(id) ON DELETE SET NULL,
    owner_member_id       bigint REFERENCES members(id) ON DELETE SET NULL,            -- who does it
    created_by            bigint REFERENCES members(id) ON DELETE SET NULL,
    due_at                timestamptz,                                                 -- a task, a deadline (and the moment of a logged call/email)
    starts_at             timestamptz,                                                 -- a meeting, a call, a lunch
    ends_at               timestamptz,
    all_day               boolean NOT NULL DEFAULT false,
    timezone              text,
    done_at               timestamptz,
    done_by               bigint REFERENCES members(id) ON DELETE SET NULL,
    outcome               text CHECK (outcome IS NULL OR outcome IN ('connected', 'no_answer', 'left_message', 'busy', 'meeting_held', 'no_show', 'sent', 'replied', 'completed', 'cancelled')),
    outcome_note          text,
    remind_minutes_before integer CHECK (remind_minutes_before IS NULL OR remind_minutes_before BETWEEN 0 AND 10080),
    remind_at             timestamptz,                                                 -- kept by trigger
    reminded_at           timestamptz,
    snoozed_until         timestamptz,
    email_direction       text CHECK (email_direction IS NULL OR email_direction IN ('in', 'out')),
    inbound_email_id      bigint,                                                      -- FK added in db/011
    outbound_email_id     bigint,                                                      -- FK added in db/011
    is_private            boolean NOT NULL DEFAULT false,                              -- an email the seller keeps to themselves and managers (§3.7)
    location              text,
    meeting_url           text,
    priority              text NOT NULL DEFAULT 'normal' CHECK (priority IN ('low', 'normal', 'high')),
    recurrence            text,                                                        -- RRULE (tasks: FREQ=DAILY|WEEKLY|MONTHLY[;INTERVAL=n]); expanded by the worker
    recurrence_parent_id  bigint REFERENCES activities(id) ON DELETE SET NULL,
    source                text NOT NULL DEFAULT 'web' CHECK (source IN ('web', 'command_bar', 'agent', 'mailbox', 'api', 'import', 'cron', 'proposal')),
    proposal_id           bigint,                                                      -- the Assistant's proposal it came from; FK added in db/013
    custom_values         jsonb NOT NULL DEFAULT '{}'::jsonb,
    deleted_at            timestamptz,
    deleted_by            bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at            timestamptz NOT NULL DEFAULT now(),
    updated_at            timestamptz NOT NULL DEFAULT now(),
    CHECK (due_at IS NOT NULL OR starts_at IS NOT NULL),
    CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at >= starts_at),
    CHECK (kind <> 'email' OR email_direction IS NOT NULL),
    CHECK (account_id IS NOT NULL OR contact_id IS NOT NULL OR deal_id IS NOT NULL OR lead_id IS NOT NULL OR kind = 'task')
);
CREATE INDEX activities_owner_open_idx ON activities (owner_member_id, COALESCE(starts_at, due_at)) WHERE done_at IS NULL AND deleted_at IS NULL;
CREATE INDEX activities_when_idx      ON activities (COALESCE(starts_at, due_at)) WHERE deleted_at IS NULL;
CREATE INDEX activities_deal_idx      ON activities (deal_id, COALESCE(starts_at, due_at) DESC) WHERE deleted_at IS NULL;
CREATE INDEX activities_contact_idx   ON activities (contact_id, COALESCE(starts_at, due_at) DESC) WHERE deleted_at IS NULL;
CREATE INDEX activities_account_idx   ON activities (account_id, COALESCE(starts_at, due_at) DESC) WHERE deleted_at IS NULL;
CREATE INDEX activities_lead_idx      ON activities (lead_id) WHERE deleted_at IS NULL;
CREATE INDEX activities_remind_idx    ON activities (remind_at) WHERE reminded_at IS NULL AND done_at IS NULL AND deleted_at IS NULL AND remind_at IS NOT NULL;
CREATE INDEX activities_snooze_idx    ON activities (snoozed_until) WHERE snoozed_until IS NOT NULL AND done_at IS NULL;

CREATE TABLE activity_participants (
    activity_id bigint NOT NULL REFERENCES activities(id) ON DELETE CASCADE,
    contact_id  bigint REFERENCES contacts(id) ON DELETE CASCADE,
    member_id   bigint REFERENCES members(id) ON DELETE CASCADE,
    response    text CHECK (response IS NULL OR response IN ('invited', 'accepted', 'declined', 'tentative')),
    CHECK ((contact_id IS NULL) <> (member_id IS NULL))
);
CREATE UNIQUE INDEX activity_participants_contact_uq ON activity_participants (activity_id, contact_id) WHERE contact_id IS NOT NULL;
CREATE UNIQUE INDEX activity_participants_member_uq  ON activity_participants (activity_id, member_id) WHERE member_id IS NOT NULL;
CREATE INDEX activity_participants_contact_idx ON activity_participants (contact_id);

-- reuse: txtSchedules db/012 verbatim
CREATE TABLE calendar_feeds (
    member_id    bigint PRIMARY KEY REFERENCES members(id) ON DELETE CASCADE,
    token_hash   text NOT NULL UNIQUE,
    created_at   timestamptz NOT NULL DEFAULT now(),
    last_used_at timestamptz,
    revoked_at   timestamptz
);

-- The moment an activity happens (one expression everywhere).
CREATE OR REPLACE FUNCTION crm_activity_when(a activities) RETURNS timestamptz
    LANGUAGE sql IMMUTABLE AS $$ SELECT COALESCE(a.starts_at, a.due_at) $$;

-- BEFORE: the account from the deal or contact; the owner from the caller; the reminder's moment; a logged call/email is done at its time.
CREATE OR REPLACE FUNCTION crm_activities_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE whenat timestamptz;
BEGIN
    NEW.subject := btrim(NEW.subject);
    IF NEW.subject = '' THEN RAISE EXCEPTION 'An activity needs a subject' USING ERRCODE = 'check_violation'; END IF;
    IF NEW.account_id IS NULL THEN
        IF NEW.deal_id IS NOT NULL THEN SELECT account_id INTO NEW.account_id FROM deals WHERE id = NEW.deal_id; END IF;
        IF NEW.account_id IS NULL AND NEW.contact_id IS NOT NULL THEN SELECT account_id INTO NEW.account_id FROM contacts WHERE id = NEW.contact_id; END IF;
    END IF;
    IF NEW.contact_id IS NULL AND NEW.deal_id IS NOT NULL THEN SELECT primary_contact_id INTO NEW.contact_id FROM deals WHERE id = NEW.deal_id; END IF;
    IF TG_OP = 'INSERT' THEN
        NEW.owner_member_id := COALESCE(NEW.owner_member_id, app_current_member_id());
        NEW.created_by := COALESCE(NEW.created_by, app_current_member_id());
        IF NEW.remind_minutes_before IS NULL AND NEW.done_at IS NULL AND NEW.kind IN ('call', 'meeting', 'task', 'deadline', 'lunch') THEN
            NEW.remind_minutes_before := COALESCE((SELECT default_remind_minutes FROM notification_prefs WHERE member_id = NEW.owner_member_id), crm_setting_int('default_remind_minutes')::integer);
        END IF;
    END IF;
    IF NEW.kind IN ('meeting', 'lunch') AND NEW.starts_at IS NOT NULL AND NEW.ends_at IS NULL THEN NEW.ends_at := NEW.starts_at + interval '1 hour'; END IF;
    whenat := COALESCE(NEW.starts_at, NEW.due_at);
    NEW.remind_at := CASE WHEN NEW.remind_minutes_before IS NULL OR NEW.done_at IS NOT NULL THEN NULL ELSE whenat - make_interval(mins => NEW.remind_minutes_before) END;
    IF TG_OP = 'UPDATE' AND (NEW.starts_at, NEW.due_at, NEW.remind_minutes_before) IS DISTINCT FROM (OLD.starts_at, OLD.due_at, OLD.remind_minutes_before) THEN NEW.reminded_at := NULL; END IF;
    IF NEW.done_at IS NOT NULL AND (TG_OP = 'INSERT' OR OLD.done_at IS NULL) THEN
        NEW.done_by := COALESCE(NEW.done_by, app_current_member_id());
        NEW.snoozed_until := NULL;
        IF NEW.outcome IS NULL THEN NEW.outcome := CASE NEW.kind WHEN 'email' THEN CASE NEW.email_direction WHEN 'in' THEN 'replied' ELSE 'sent' END WHEN 'meeting' THEN 'meeting_held' WHEN 'call' THEN 'connected' ELSE 'completed' END; END IF;
    END IF;
    IF NEW.done_at IS NULL THEN NEW.outcome := NULL; NEW.done_by := NULL; END IF;
    PERFORM crm_validate_custom_values('activity', NEW.custom_values);
    RETURN NEW;
END$$;
CREATE TRIGGER activities_before BEFORE INSERT OR UPDATE ON activities FOR EACH ROW EXECUTE FUNCTION crm_activities_before();
CREATE TRIGGER activities_touch BEFORE UPDATE ON activities FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- The next step of a record: the earliest open activity. Kept on deals, contacts, accounts and leads (the cards' colour, the "no next step" flag).
CREATE OR REPLACE FUNCTION crm_refresh_next_activity(p_account bigint, p_contact bigint, p_deal bigint, p_lead bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF p_deal IS NOT NULL THEN
        UPDATE deals d SET next_activity_id = n.id, next_activity_at = n.at
          FROM (SELECT (SELECT a.id FROM activities a WHERE a.deal_id = p_deal AND a.done_at IS NULL AND a.deleted_at IS NULL ORDER BY COALESCE(a.starts_at, a.due_at) LIMIT 1) AS id,
                       (SELECT COALESCE(a.starts_at, a.due_at) FROM activities a WHERE a.deal_id = p_deal AND a.done_at IS NULL AND a.deleted_at IS NULL ORDER BY COALESCE(a.starts_at, a.due_at) LIMIT 1) AS at) n
         WHERE d.id = p_deal;
    END IF;
    IF p_contact IS NOT NULL THEN
        UPDATE contacts SET next_activity_at = (SELECT min(COALESCE(a.starts_at, a.due_at)) FROM activities a WHERE a.contact_id = p_contact AND a.done_at IS NULL AND a.deleted_at IS NULL) WHERE id = p_contact;
    END IF;
    IF p_account IS NOT NULL THEN
        UPDATE accounts SET next_activity_at = (SELECT min(COALESCE(a.starts_at, a.due_at)) FROM activities a WHERE a.account_id = p_account AND a.done_at IS NULL AND a.deleted_at IS NULL) WHERE id = p_account;
    END IF;
    IF p_lead IS NOT NULL THEN
        UPDATE leads SET next_activity_at = (SELECT min(COALESCE(a.starts_at, a.due_at)) FROM activities a WHERE a.lead_id = p_lead AND a.done_at IS NULL AND a.deleted_at IS NULL) WHERE id = p_lead;
    END IF;
END$$;

-- AFTER: the next step on every record it touches (old and new), and the touch when done.
CREATE OR REPLACE FUNCTION crm_activities_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN PERFORM crm_refresh_next_activity(OLD.account_id, OLD.contact_id, OLD.deal_id, OLD.lead_id); RETURN NULL; END IF;
    PERFORM crm_refresh_next_activity(NEW.account_id, NEW.contact_id, NEW.deal_id, NEW.lead_id);
    IF TG_OP = 'UPDATE' AND (OLD.account_id, OLD.contact_id, OLD.deal_id, OLD.lead_id) IS DISTINCT FROM (NEW.account_id, NEW.contact_id, NEW.deal_id, NEW.lead_id) THEN
        PERFORM crm_refresh_next_activity(OLD.account_id, OLD.contact_id, OLD.deal_id, OLD.lead_id);
    END IF;
    IF NEW.done_at IS NOT NULL AND NEW.deleted_at IS NULL AND (TG_OP = 'INSERT' OR OLD.done_at IS NULL) THEN
        PERFORM crm_touch(NEW.account_id, NEW.contact_id, NEW.deal_id, NEW.done_at);
        IF NEW.lead_id IS NOT NULL THEN UPDATE leads SET last_touched_at = GREATEST(COALESCE(last_touched_at, '-infinity'), NEW.done_at), status = CASE WHEN status = 'new' THEN 'working' ELSE status END WHERE id = NEW.lead_id; END IF;
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER activities_after AFTER INSERT OR UPDATE OR DELETE ON activities FOR EACH ROW EXECUTE FUNCTION crm_activities_after();

-- The four colours (D7): scheduled (green), today (amber), overdue (red), none (grey).
CREATE OR REPLACE FUNCTION crm_next_colour(p_next timestamptz, p_tz text DEFAULT 'UTC') RETURNS text
    LANGUAGE sql STABLE AS $$
    SELECT CASE WHEN p_next IS NULL THEN 'none'
                WHEN p_next < date_trunc('day', now() AT TIME ZONE p_tz) AT TIME ZONE p_tz THEN 'overdue'
                WHEN p_next < (date_trunc('day', now() AT TIME ZONE p_tz) + interval '1 day') AT TIME ZONE p_tz THEN 'today'
                ELSE 'scheduled' END;
$$;

-- Reminders due (the worker, every minute): not done, not deleted, not reminded, not snoozed into the future.
CREATE OR REPLACE FUNCTION crm_reminders_due(p_at timestamptz DEFAULT now(), p_limit integer DEFAULT 200)
    RETURNS TABLE (activity_id bigint, member_id bigint, kind text, subject text, at timestamptz, deal_id bigint, contact_id bigint, account_id bigint)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT a.id, a.owner_member_id, a.kind, a.subject, COALESCE(a.starts_at, a.due_at), a.deal_id, a.contact_id, a.account_id
      FROM activities a
     WHERE a.done_at IS NULL AND a.deleted_at IS NULL AND a.owner_member_id IS NOT NULL
       AND ((a.remind_at IS NOT NULL AND a.remind_at <= p_at AND a.reminded_at IS NULL AND (a.snoozed_until IS NULL OR a.snoozed_until <= p_at))
            OR (a.snoozed_until IS NOT NULL AND a.snoozed_until <= p_at AND a.reminded_at IS NULL))
     ORDER BY COALESCE(a.snoozed_until, a.remind_at) LIMIT p_limit;
$$;

-- Snooze: 10 minutes, an hour, tomorrow 09:00 in the owner's zone — the reminder fires again then.
CREATE OR REPLACE FUNCTION crm_activity_snooze(p_activity bigint, p_until timestamptz) RETURNS void
    LANGUAGE sql SECURITY DEFINER SET search_path = public AS $$
    UPDATE activities SET snoozed_until = p_until, reminded_at = NULL WHERE id = p_activity AND done_at IS NULL;
$$;

-- The feed's rows (txtSchedules' shape): the person's own open activities, back and ahead by the settings' windows.
CREATE OR REPLACE FUNCTION crm_feed_rows(p_member bigint)
    RETURNS TABLE (activity_id bigint, kind text, subject text, starts_at timestamptz, ends_at timestamptz, all_day boolean, remind_minutes_before integer, record_name text, location text, done boolean)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT a.id, a.kind, a.subject, COALESCE(a.starts_at, a.due_at), COALESCE(a.ends_at, COALESCE(a.starts_at, a.due_at) + CASE WHEN a.kind IN ('meeting', 'lunch', 'call') THEN interval '30 minutes' ELSE interval '0' END),
           a.all_day, a.remind_minutes_before,
           COALESCE((SELECT d.title FROM deals d WHERE d.id = a.deal_id), (SELECT c.name FROM contacts c WHERE c.id = a.contact_id), (SELECT x.name FROM accounts x WHERE x.id = a.account_id), (SELECT crm_lead_label(l) FROM leads l WHERE l.id = a.lead_id)),
           a.location, a.done_at IS NOT NULL
      FROM activities a
     WHERE a.owner_member_id = p_member AND a.deleted_at IS NULL
       AND COALESCE(a.starts_at, a.due_at) BETWEEN now() - make_interval(days => crm_setting_int('ics_days_back')::integer) AND now() + make_interval(days => crm_setting_int('ics_days_ahead')::integer)
     ORDER BY 4;
$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON activities, activity_participants TO sales_crm_rw;
GRANT SELECT, INSERT, UPDATE ON calendar_feeds TO sales_crm_rw;
GRANT EXECUTE ON FUNCTION crm_activity_when(activities), crm_next_colour(timestamptz, text) TO sales_crm_rw, sales_crm_records_ro, sales_crm_activity_ro;
REVOKE ALL ON FUNCTION crm_refresh_next_activity(bigint, bigint, bigint, bigint), crm_reminders_due(timestamptz, integer), crm_activity_snooze(bigint, timestamptz), crm_feed_rows(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION crm_refresh_next_activity(bigint, bigint, bigint, bigint), crm_reminders_due(timestamptz, integer), crm_activity_snooze(bigint, timestamptz), crm_feed_rows(bigint) TO sales_crm_rw;

COMMIT;
