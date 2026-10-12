"""Activities and the calendar (Q24–Q28; tool surface "Activities and the calendar"): my_activities, calendar, find_activities, get_activity, activity_summary, reminders_due, timeline. The rows are the walled
views' (mcp_activities, mcp_activity_participants) or the SECURITY DEFINER read functions' (crm_my_activities, crm_calendar, crm_activity_summary, crm_timeline), as the asking member: a private activity is its
owner's and a manager's; another seller's agenda and a team's calendar need the right (calendar.read / calendar.team) — the functions refuse, and the tool says so in words before it asks."""
from __future__ import annotations

from pydantic import Field

from crm_common import (RO, Ref, Refusal, ListIn, Where, _Base, account_id, activity_id, answers, contact_id, cut, day, deal_id, department_id, describe_models, detail, exact_first, has_right, is_admin, lead_id, like, lim, listing,
                        managers_only, me, member_ref, not_found, off, owner_filter, page, record_ref, resolving, span, when, dumps)

ACT_COLS = """a.activity_id, a.kind, a.subject, left(a.body, 300) AS body, a.account_id, a.contact_id, a.deal_id, a.lead_id, a.owner_member_id, a.due_at, a.starts_at, a.ends_at, a.at, a.all_day, a.done_at, a.outcome,
              a.priority, a.source, a.location, a.is_private, a.state"""
ACT_SORTS = {"at": "at, activity_id", "recent": "at DESC, activity_id DESC", "created": "activity_id DESC"}


class MyActivitiesIn(_Base):
    scope: str = Field("today", pattern=r"^(today|week|overdue)$", description="today, week or overdue")
    member: Ref | None = Field(None, description="Another seller's agenda (id or name) — needs the right to read colleagues' calendars")
    limit: int = Field(50, ge=1, le=100, description="How many activities at most")


class CalendarIn(_Base):
    from_: str = Field(..., alias="from", description="First day or time, YYYY-MM-DD or ISO 8601")
    to: str = Field(..., description="Last day (inclusive) or time, YYYY-MM-DD or ISO 8601")
    who: str = Field("me", max_length=100, description="me, a colleague (id or name), or team:<department id or name>")
    kinds: list[str] | None = Field(None, description="Only these kinds: call, email, meeting, task, deadline, lunch")
    include_done: bool = Field(False, description="true: finished activities too")
    limit: int = Field(100, ge=1, le=500, description="How many rows at most")


class FindActivitiesIn(ListIn):
    deal: Ref | None = Field(None, description="Only on this deal (id, number or title)")
    contact: Ref | None = Field(None, description="Only on this contact (id, name or email)")
    account: Ref | None = Field(None, description="Only on this account (id, name or domain)")
    lead: Ref | None = Field(None, description="Only on this lead (id, name or email)")
    owner: Ref | None = Field(None, description="A member (id, name or me), or none")
    kind: list[str] | None = Field(None, description="Only these kinds: call, email, meeting, task, deadline, lunch")
    state: str | None = Field(None, pattern=r"^(open|done|overdue|today)$", description="open, done, overdue or today")
    from_: str | None = Field(None, alias="from", description="At or after this day or time")
    to: str | None = Field(None, description="Before the end of this day, or at this time")
    source: str | None = Field(None, max_length=40, description="Where it came from: user, agent, assistant, email, import …")
    sort: str | None = Field(None, description="at (soonest first, the default), recent (latest first) or created")


class GetActivityIn(_Base):
    activity: Ref = Field(..., description="An activity (id or subject)")


class SummaryWindowIn(_Base):
    from_: str = Field(..., alias="from", description="First day, YYYY-MM-DD")
    to: str = Field(..., description="Last day, YYYY-MM-DD")
    team: Ref | None = Field(None, description="Only this department's members (id or name)")


class RemindersIn(_Base):
    limit: int = Field(50, ge=1, le=100, description="How many reminders at most")


class TimelineIn(_Base):
    kind: str = Field(..., pattern=r"^(lead|account|contact|deal)$", description="lead, account, contact or deal")
    record: Ref = Field(..., description="The record (id, or its name)")
    limit: int = Field(100, ge=1, le=500, description="How many items at most (500 is the cap)")
    before: str | None = Field(None, description="Only items before this time (ISO 8601) — to read on past the first page")


describe_models(globals())


def register(mcp, q, bridge=None) -> None:
    async def need_calendar(member: int | None, right: str, words: str) -> None:
        if member is not None and member != await me(q) and not await has_right(q, right):
            raise managers_only(words)

    @mcp.tool(name="my_activities", annotations={"title": "My agenda", **RO})
    @answers
    async def my_activities(params: MyActivitiesIn) -> str:
        """What I (or, with the right, a colleague) have to do: today, this week or overdue — kind, subject, when, state, the record it is about and the priority."""
        member = None if params.member in (None, "") else await member_ref(q, params.member)
        await need_calendar(member, "calendar.read", "Another seller's agenda")
        rows = await q(f"SELECT activity_id, kind, subject, at, state, deal_id, contact_id, account_id, lead_id, record_name, priority FROM crm_my_activities($1, $2) ORDER BY at, activity_id LIMIT {lim(params.limit, 50) + 1}", params.scope, member)
        return listing(rows, lim(params.limit, 50), scope=params.scope)

    @mcp.tool(name="calendar", annotations={"title": "The calendar", **RO})
    @answers
    async def calendar(params: CalendarIn) -> str:
        """The calendar of a window for me, a colleague (needs the right to read colleagues' calendars) or a team (needs the team calendar right): kind, subject, start and end, owner, done, state, the record, location and priority."""
        start, end = when(params.from_, "from"), when(params.to, "to", end_of_day=True)
        if start is None or end is None or end < start:
            raise Refusal("invalid_argument", "Give from and to (a day or an ISO time, from first).", fields=["from", "to"])
        who = (params.who or "me").strip()
        member = team = None
        if who.lower().startswith("team:"):
            team = await department_id(q, who[5:].strip())
            await_right = "calendar.team"
            if not await has_right(q, await_right):
                raise managers_only("A team's calendar")
        elif who.lower() == "me":
            member = await me(q)
        else:
            member = await member_ref(q, who)
            await need_calendar(member, "calendar.read", "A colleague's calendar")
        rows = await q(f"SELECT activity_id, kind, subject, starts_at, ends_at, all_day, owner_member_id, owner_name, done, state, deal_id, contact_id, account_id, lead_id, record_name, location, priority "
                       f"FROM crm_calendar($1, $2, $3, $4, $5, $6) ORDER BY starts_at, activity_id LIMIT {lim(params.limit, 100, 500) + 1}", start, end, member, team, list(params.kinds) if params.kinds else None, params.include_done)
        return listing(rows, lim(params.limit, 100, 500))

    @mcp.tool(name="find_activities", annotations={"title": "Find activities", **RO})
    @answers
    async def find_activities(params: FindActivitiesIn) -> str:
        """Activities (calls, emails, meetings, tasks) by record, owner, kind, state, window and text. `q` alone resolves an activity by subject. Returns the view's row (body cut to 300 characters) with `at`, `state` (done, overdue, today, scheduled) and `total`."""
        w = Where()
        if params.q:
            w.add("(a.subject ILIKE {0} OR a.body ILIKE {0})", like(params.q))
        for col, ref, fn in (("deal_id", params.deal, deal_id), ("contact_id", params.contact, contact_id), ("account_id", params.account, account_id), ("lead_id", params.lead, lead_id)):
            if ref not in (None, ""):
                w.add(f"a.{col} = {{}}", await fn(q, ref))
        await owner_filter(q, w, "a.owner_member_id", params.owner)
        if params.kind:
            w.add("a.kind = ANY({}::text[])", list(params.kind))
        if params.state == "open":
            w.raw("a.done_at IS NULL")
        elif params.state == "done":
            w.raw("a.done_at IS NOT NULL")
        elif params.state in ("overdue", "today"):
            w.add("a.done_at IS NULL AND a.state = {}", params.state)
        if params.from_:
            w.add("a.at >= {}", when(params.from_, "from"))
        if params.to:
            w.add("a.at < {}", when(params.to, "to", end_of_day=True))
        if params.source:
            w.add("a.source = {}", params.source)
        order = "ORDER BY " + ACT_SORTS.get((params.sort or "at").lower(), ACT_SORTS["at"])
        rows, total = await page(q, f"SELECT {ACT_COLS} FROM mcp_activities a WHERE {w.sql()}", w.args, lim(params.limit), off(params.offset), order)
        if resolving(params):
            rows = exact_first(rows, params.q, "subject")
        return listing(rows, params.limit, total)

    @mcp.tool(name="get_activity", annotations={"title": "One activity", **RO})
    @answers
    async def get_activity(params: GetActivityIn) -> str:
        """One activity: its fields (the body in full when you may read it), who is on it, the record it is about and the email behind it."""
        aid = await activity_id(q, params.activity)
        act = (await q("SELECT * FROM mcp_activities WHERE activity_id = $1", aid))[0]
        people = await q("SELECT p.contact_id, p.member_id, COALESCE(c.name, m.display_name) AS name, p.response FROM mcp_activity_participants p "
                         "LEFT JOIN mcp_contacts c ON c.contact_id = p.contact_id LEFT JOIN mcp_members m ON m.member_id = p.member_id WHERE p.activity_id = $1 ORDER BY p.contact_id NULLS LAST, p.member_id", aid)
        rec = None
        for kind, col, view, idc, lab in (("deal", "deal_id", "mcp_deals", "deal_id", "title"), ("contact", "contact_id", "mcp_contacts", "contact_id", "name"),
                                          ("account", "account_id", "mcp_accounts", "account_id", "name"), ("lead", "lead_id", "mcp_leads", "lead_id", "label")):
            if act.get(col):
                r = await q(f"SELECT {lab} AS name FROM {view} WHERE {idc} = $1", act[col])
                rec = {"kind": kind, "id": act[col], "name": r[0]["name"] if r else None}
                break
        return detail(activity=act, participants=people, record=rec)

    @mcp.tool(name="activity_summary", annotations={"title": "Activity per seller", **RO})
    @answers
    async def activity_summary(params: SummaryWindowIn) -> str:
        """Calls, emails, meetings and tasks per seller in a period — done and still scheduled — with the outcomes. Your own rows; the team's with the right to see the team's reports."""
        d1, d2 = span(params.from_, params.to)
        team = None if params.team in (None, "") else await department_id(q, params.team)
        rows = await q("SELECT member_id, display_name, kind, done, scheduled, outcomes FROM crm_activity_summary($1, $2, $3) ORDER BY display_name, kind", d1, d2, team)
        return listing(rows, 1000)

    @mcp.tool(name="reminders_due", annotations={"title": "Reminders due now", **RO})
    @answers
    async def reminders_due(params: RemindersIn) -> str:
        """Which reminders are due now (not done, not yet delivered, not snoozed into the future) and how many notices failed to send in the last week — the worker's queue, for the admin."""
        if not await is_admin(q):
            raise managers_only("The reminders queue (the admin's)")
        n = lim(params.limit, 50)
        rows = await q(f"SELECT a.activity_id, a.owner_member_id AS member_id, a.kind, a.subject, a.at, a.deal_id, a.contact_id, a.account_id FROM mcp_activities a "
                       f"WHERE a.done_at IS NULL AND a.owner_member_id IS NOT NULL AND a.remind_at IS NOT NULL AND a.remind_at <= now() AND a.reminded_at IS NULL AND (a.snoozed_until IS NULL OR a.snoozed_until <= now()) "
                       f"ORDER BY a.remind_at LIMIT {n + 1}")
        failed = (await q("SELECT COALESCE(sum(n), 0)::int AS n FROM mcp_outbox_health WHERE status = 'failed'"))[0]["n"]
        return listing(rows, n, failed_outbox=failed)

    @mcp.tool(name="timeline", annotations={"title": "Everything on one record", **RO})
    @answers
    async def timeline(params: TimelineIn) -> str:
        """Everything on one lead, account, contact or deal in order, newest first: activities, emails (subject and direction), notes (the first 200 characters), stage moves and changes. `before` pages back in time."""
        rid = await record_ref(q, params.kind, params.record)
        n = lim(params.limit, 100, 500)
        rows = await q("SELECT at, item_kind, item_id, title, left(detail, 200) AS detail, actor_member_id, deal_id, contact_id FROM crm_timeline($1, $2, $3, $4)", params.kind, rid, n + 1, when(params.before, "before"))
        return listing(rows, n)
