"""sales_crm_activity_mcp — Sales CRM's activity-memory server (activity_log through mcp_activity_log, read-only).

Tool surface: docs/sales-crm-mcp-tool-surface.md "Activity server". Every query runs as the asking member; the view decides which rows they see: the events about the deals and accounts (and leads and
contacts) the wall lets them see, and the events that concern none. The writers' rule (design §6.5) is that a payload never holds an email's or a note's body, a token or a key — a key is named by its label —
so what these tools return cannot either. The activity role reads mcp_activity_log plus mcp_members, mcp_departments, mcp_leads, mcp_accounts, mcp_contacts, mcp_deals, mcp_activities and
mcp_assistant_proposals (db/025) for the names beside a row, and nothing else. `record_history` is the records server's coverage of Q33 too: this is where it lives, because the records role reads no part of the log."""
from __future__ import annotations

import json

from mcp.server.fastmcp import FastMCP
from pydantic import Field

import db
from crm_common import (RO, Ref, Refusal, Where, _Base, ago, answers, day, describe_models, detail, has_right, is_admin, is_manager, lim, like, listing, managers_only, me, member_ref, not_found, page, record_ref, when)

mcp = FastMCP("sales_crm_activity_mcp")

COLS = """l.log_id, l.occurred_at, l.actor_member_id, m.display_name AS actor_name, COALESCE(m.is_agent, false) AS actor_is_agent, l.source, l.action, l.entity_type, l.entity_id, l.after,
          l.request_id, l.agent_run_id, l.lead_id, l.account_id, l.contact_id, l.deal_id, l.token_id"""
FROM = "mcp_activity_log l LEFT JOIN mcp_members m ON m.member_id = l.actor_member_id"
WORDS = {
    "member.sign_on": "signed on", "directory.sync": "refreshed the directory", "lead.create": "made a lead", "lead.update": "changed a lead", "lead.convert": "converted a lead", "lead.assign": "assigned a lead",
    "account.create": "made an account", "account.update": "changed an account", "contact.create": "made a contact", "contact.update": "changed a contact", "deal.create": "made a deal",
    "deal.update": "changed a deal", "deal.move": "moved a deal", "deal.won": "won a deal", "deal.lost": "lost a deal", "activity.create": "scheduled an activity", "activity.complete": "finished an activity",
    "email.send": "sent an email", "email.log": "logged an email", "note.create": "wrote a note", "records.merge": "merged records", "import.run": "ran an import", "bulk.assign": "assigned in bulk",
    "share.read": "read a share", "settings.save": "changed the settings", "key.mint": "minted a key", "key.revoke": "revoked a key", "proposal.create": "proposed something",
    "proposal.accept": "accepted a proposal", "proposal.dismiss": "dismissed a proposal", "notification.send": "sent a notice", "search.keyword": "searched", "agent.dispatch": "dispatched an agent",
    "agent.reply": "heard back from an agent", "api.call": "called the API",
}
ENTITY_TYPES = r"^(lead|account|contact|deal|activity|campaign|list|import|pipeline|stage|template|key)$"
NOT_VIEWS = "l.action <> 'screen.view' AND l.action NOT LIKE '%.view'"


class RecordHistoryIn(_Base):
    entity_type: str = Field(..., pattern=ENTITY_TYPES, description="lead, account, contact, deal, activity, campaign, list, import, pipeline, stage, template or key")
    entity_id: int = Field(..., ge=1, description="The record's id")
    limit: int = Field(50, ge=1, le=200, description="How many rows at most (200 is the cap)")


class ActorHistoryIn(_Base):
    member: Ref | None = Field(None, description="A person or an agent (id, name or me)")
    key: int | None = Field(None, ge=1, description="Or a key (its id from find_keys) — what it did")
    since: str | None = Field(None, description="A day or ISO time; default the last 7 days")
    limit: int = Field(50, ge=1, le=200, description="How many rows at most")


class RecentIn(_Base):
    since: str = Field(..., description="Since this day or time (ISO 8601) — what changed after you last looked")
    account: Ref | None = Field(None, description="Only on this account (id, name or domain)")
    deal: Ref | None = Field(None, description="Only on this deal (id, number or title)")
    owner: Ref | None = Field(None, description="Only on records this seller owns (id, name or me)")
    exclude_mine: bool = Field(True, description="true (the default): leave out what you did yourself")
    limit: int = Field(50, ge=1, le=200, description="How many rows at most")


class AgentActivityIn(_Base):
    since: str | None = Field(None, description="Since this day or time; default the last 7 days")
    agent: Ref | None = Field(None, description="Only this agent (id or name)")
    limit: int = Field(50, ge=1, le=200, description="How many actions at most")


class TimelineIn(_Base):
    kind: str = Field(..., pattern=r"^(account|deal)$", description="account or deal")
    record: Ref = Field(..., description="The account or deal (id, or its name)")
    from_: str | None = Field(None, alias="from", description="First day, YYYY-MM-DD; default 12 weeks back")
    to: str | None = Field(None, description="Last day, YYYY-MM-DD; default today")


class ActivitySearchIn(_Base):
    q: str = Field(..., min_length=2, max_length=200, description="Words to find in an action, a record type, a name, a title or a subject the payloads carry, and the actor's name — each word must match")
    since: str | None = Field(None, description="Only since this day or time")
    account: Ref | None = Field(None, description="Only the trail of this account (id, name or domain)")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class ActivityQueryIn(_Base):
    sql: str = Field(..., min_length=7, max_length=4000, description="One read-only SELECT over mcp_activity_log and the name views (mcp_members, mcp_departments, mcp_leads, mcp_accounts, mcp_contacts, mcp_deals, mcp_activities); 5 seconds, 200 rows")


describe_models(globals())


async def _pool():
    return await db.get_pool(db.ENV["MCP_ACTIVITY_DB_USER"], db.ENV["MCP_ACTIVITY_DB_PASSWORD"])


async def q(sql: str, *args) -> list[dict]:
    return await db.fetch_scoped(await _pool(), sql, *args, timeout_ms=8000)


def sentence(r: dict) -> str:
    who = r.get("actor_name") or "Sales CRM"
    after = r.get("after") if isinstance(r.get("after"), dict) else {}
    tail = ""
    for k in ("title", "name", "subject", "stage_name", "label"):
        if isinstance(after.get(k), str):
            tail = ": " + after[k][:80]
            break
    return f"{who} {WORDS.get(r['action'], str(r['action']).replace('.', ' ').replace('_', ' '))}{tail}"


def shape(rows: list[dict]) -> list[dict]:
    for r in rows:
        r["summary"] = sentence(r)
    return rows


def prefix(s: str) -> str:
    return s.replace("\\", "").replace("%", "").replace("_", "\\_") + "%"


async def owned_by(w: Where, owner: int) -> None:
    """Narrow to the trail of records one seller owns (the four kinds, through their walled views)."""
    n = w.p(owner)
    w.raw(f"(l.deal_id IN (SELECT deal_id FROM mcp_deals WHERE owner_member_id = {n}) OR l.account_id IN (SELECT account_id FROM mcp_accounts WHERE owner_member_id = {n}) "
          f"OR l.contact_id IN (SELECT contact_id FROM mcp_contacts WHERE owner_member_id = {n}) OR l.lead_id IN (SELECT lead_id FROM mcp_leads WHERE owner_member_id = {n}))")


@mcp.tool(name="record_history", annotations={"title": "A record's history", **RO})
@answers
async def record_history(params: RecordHistoryIn) -> str:
    """Who did what to a lead, an account, a contact, a deal, an activity, a campaign, a list, an import, a pipeline, a stage, a template or a key, in order — oldest first. For a lead, account, contact or deal this includes what was done to its activities, notes and emails. You see the rows about records you may see; for a record behind the wall there are none, in the words of one that has none."""
    p = params
    w = Where().raw(NOT_VIEWS)
    if p.entity_type in ("lead", "account", "contact", "deal"):
        col = f"l.{p.entity_type}_id"
        n1, n2 = w.p(p.entity_id), w.p(p.entity_type)
        w.raw(f"({col} = {n1} OR (l.entity_type = {n2} AND l.entity_id = {n1}))")
    else:
        w.add("l.entity_type = {}", p.entity_type).add("l.entity_id = {}", p.entity_id)
    n = lim(p.limit, 50, 200)
    rows, total = await page(q, f"SELECT {COLS} FROM {FROM} WHERE {w.sql()}", w.args, n, 0, "ORDER BY occurred_at ASC, log_id ASC")
    return listing(shape(rows), n, total, entity_type=p.entity_type, entity_id=p.entity_id)


@mcp.tool(name="actor_history", annotations={"title": "What a person, a key or an agent did", **RO})
@answers
async def actor_history(params: ActorHistoryIn) -> str:
    """What one person, key or agent did here since a time — the last 7 days by default — newest first. Your own trail is yours; another's is a manager's (the right to see the team's reports, or the admin) — anyone else sees only what that actor did on records they own."""
    p = params
    if (p.member in (None, "")) == (p.key is None):
        raise Refusal("invalid_argument", "Name one: a member (id, name or me) or a key (its id).", fields=["member", "key"])
    w = Where().raw(NOT_VIEWS).add("l.occurred_at >= {}", when(p.since, "since") if p.since else ago(7))
    mine = await me(q)
    privileged = await has_right(q, "reports.team") or await is_admin(q)
    if p.member not in (None, ""):
        target = await member_ref(q, p.member)
        w.add("l.actor_member_id = {}", target)
        own = target == mine
    else:
        target = None
        w.add("l.token_id = {}", p.key)
        own = False
    if not own and not privileged:
        await owned_by(w, mine)
    n = lim(p.limit, 50, 200)
    rows, total = await page(q, f"SELECT {COLS} FROM {FROM} WHERE {w.sql()}", w.args, n, 0, "ORDER BY occurred_at DESC, log_id DESC")
    return listing(shape(rows), n, total, member_id=target, key_id=p.key)


@mcp.tool(name="recent_activity", annotations={"title": "What happened here lately", **RO})
@answers
async def recent_activity(params: RecentIn) -> str:
    """What changed on my book — or on one account or deal, or on one seller's records — since I last looked, newest first, leaving out my own doing unless asked. For catching up before a call."""
    p = params
    from crm_common import account_id, deal_id
    w = Where().raw(NOT_VIEWS).add("l.occurred_at >= {}", when(p.since, "since"))
    if p.account not in (None, ""):
        w.add("l.account_id = {}", await account_id(q, p.account))
    if p.deal not in (None, ""):
        w.add("l.deal_id = {}", await deal_id(q, p.deal))
    if p.owner not in (None, ""):
        await owned_by(w, await member_ref(q, p.owner))
    elif p.account in (None, "") and p.deal in (None, ""):
        await owned_by(w, await me(q))
    if p.exclude_mine:
        w.add("(l.actor_member_id IS NULL OR l.actor_member_id <> {})", await me(q))
    n = lim(p.limit, 50, 200)
    rows, total = await page(q, f"SELECT {COLS} FROM {FROM} WHERE {w.sql()}", w.args, n, 0, "ORDER BY occurred_at DESC, log_id DESC")
    return listing(shape(rows), n, total)


@mcp.tool(name="agent_activity", annotations={"title": "What the agents did and proposed", **RO})
@answers
async def agent_activity(params: AgentActivityIn) -> str:
    """What the agents did here this week, and what the Assistant proposed that nobody took: the agents' actions in the window and the counts of proposals waiting (open), accepted and dismissed. The agents' settings right or a manager sees all; an agent sees its own."""
    p = params
    mine = await me(q)
    privileged = await has_right(q, "agents.settings") or await is_manager(q)
    agent = None if p.agent in (None, "") else await member_ref(q, p.agent)
    if not privileged:
        is_agent = (await q("SELECT is_agent FROM mcp_members WHERE member_id = $1", mine) or [{"is_agent": False}])[0]["is_agent"]
        if not is_agent:
            raise managers_only("The agents' activity")
        agent = mine
    since = when(p.since, "since") if p.since else ago(7)
    w = Where().raw(NOT_VIEWS).add("l.occurred_at >= {}", since)
    if agent is not None:
        w.add("l.actor_member_id = {}", agent)
    else:
        w.raw("(m.is_agent OR l.agent_run_id IS NOT NULL)")
    n = lim(p.limit, 50, 200)
    rows, total = await page(q, f"SELECT {COLS} FROM {FROM} WHERE {w.sql()}", w.args, n, 0, "ORDER BY occurred_at DESC, log_id DESC")
    pw = Where().add("created_at >= {}", since)
    if agent is not None:
        pw.add("proposed_by = {}", agent)
    counts = await q(f"SELECT status, count(*)::int AS n FROM mcp_assistant_proposals WHERE {pw.sql()} GROUP BY status", *pw.args)
    by = {r["status"]: r["n"] for r in counts}
    return detail(actions=shape(rows[:n]), actions_truncated=len(rows) > n, total=total, proposals={"open": by.get("proposed", 0), "accepted": by.get("accepted", 0), "dismissed": by.get("dismissed", 0)})


@mcp.tool(name="activity_timeline", annotations={"title": "How a relationship was worked, by week", **RO})
@answers
async def activity_timeline(params: TimelineIn) -> str:
    """The touches on an account or a deal over time, by week — calls, meetings, emails in and out — newest week first: how the relationship was worked. Twelve weeks by default."""
    p = params
    import datetime
    rid = await record_ref(q, p.kind, p.record)
    b = day(p.to, "to") or datetime.date.today()
    a = day(p.from_, "from") or (b - datetime.timedelta(weeks=12))
    a, b = min(a, b), max(a, b)
    if (b - a).days > 800:
        raise Refusal("too_large", "At most 800 days.", fields=["from", "to"])
    rows = await q("""SELECT date_trunc('week', t.at)::date AS week, count(*) FILTER (WHERE t.item_kind LIKE 'activity.%')::int AS touches,
                             count(*) FILTER (WHERE t.item_kind = 'activity.email' AND t.detail LIKE '% · in')::int AS emails_in,
                             count(*) FILTER (WHERE t.item_kind = 'activity.email' AND t.detail LIKE '% · out')::int AS emails_out,
                             count(*) FILTER (WHERE t.item_kind = 'activity.meeting')::int AS meetings, count(*) FILTER (WHERE t.item_kind = 'activity.call')::int AS calls
                        FROM crm_timeline($1, $2, 500, NULL) t WHERE t.at >= $3::date AND t.at < $4::date + 1 GROUP BY 1 ORDER BY 1 DESC LIMIT 200""", p.kind, rid, a, b)
    return listing(rows, 200, kind=p.kind, record=rid)


@mcp.tool(name="activity_search", annotations={"title": "Search the trail", **RO})
@answers
async def activity_search(params: ActivitySearchIn) -> str:
    """The trail by a phrase — 'when did we first talk to Acme about the renewal': each word is looked for in the action, the record type and the names, titles and subjects the payloads carry, and in the actor's name; newest first, only rows you may see."""
    p = params
    from crm_common import account_id
    w = Where().raw(NOT_VIEWS)
    for word in [x for x in p.q.split() if x][:6]:
        n = w.p(like(word))
        w.raw(f"(l.action ILIKE {n} OR coalesce(l.entity_type, '') ILIKE {n} OR coalesce(m.display_name, '') ILIKE {n} OR coalesce(l.after->>'name', '') ILIKE {n} OR coalesce(l.after->>'title', '') ILIKE {n} "
              f"OR coalesce(l.after->>'subject', '') ILIKE {n} OR coalesce(l.after->>'stage_name', '') ILIKE {n} OR coalesce(l.after->>'label', '') ILIKE {n})")
    if p.since:
        w.add("l.occurred_at >= {}", when(p.since, "since"))
    if p.account not in (None, ""):
        w.add("l.account_id = {}", await account_id(q, p.account))
    n = lim(p.limit)
    rows, total = await page(q, f"SELECT {COLS} FROM {FROM} WHERE {w.sql()}", w.args, n, 0, "ORDER BY occurred_at DESC, log_id DESC")
    return listing(shape(rows), n, total)


@mcp.tool(name="activity_query", annotations={"title": "Guarded read of the trail", **RO})
@answers
async def activity_query(params: ActivityQueryIn) -> str:
    """The long tail: one read-only SELECT over mcp_activity_log and the name views, as the asking member — 5 seconds, 200 rows, a cell cut at 2,000 characters. Use a named tool when one answers the question."""
    pool = await _pool()
    raw = json.loads(await db.run_search(pool, params.sql))
    if isinstance(raw, dict) and raw.get("error"):
        raise Refusal("invalid_argument", str(raw["error"])[:300], fields=["sql"])
    cut_cells = False
    for r in raw:
        for k, v in r.items():
            if isinstance(v, str) and len(v) > 2000:
                r[k] = v[:2000] + "…"
                cut_cells = True
    return listing(raw, 200, cells_cut=cut_cells)


if __name__ == "__main__":
    import server_common
    server_common.run(mcp, "MCP_ACTIVITY_DB_USER", "MCP_ACTIVITY_DB_PASSWORD", port=int(db.ENV["MCP_ACTIVITY_PORT"]), endpoint_name="Activity MCP")
