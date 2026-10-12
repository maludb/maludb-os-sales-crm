"""The business and the admin (Q3, Q32–Q37; tool surface "Campaigns, the business, the admin"): find_campaigns, get_campaign, campaign_results, campaign_members, find_sources, find_lost_reasons, find_tags,
settings_catalogue, members_with_roles, find_members, find_departments, find_keys, key_usage_summary, assistant_proposals, agent_dispatches, my_notifications, get_settings, tombstones, search, records_search.
(`record_history` is the ACTIVITY server's: the records role reads no part of the log.) The rows are the walled views' and the read functions' as the asking member; a key's hash, a mailbox's secret and a
draft that is not the caller's are not in any view this role reads."""
from __future__ import annotations

import json

from pydantic import Field

import db
from crm_common import (RO, Ref, Refusal, ListIn, Where, _Base, answers, campaign_id, describe_models, detail, exact_first, has_right, is_admin, is_manager, like, lim, listing, managers_only, me, member_ref, not_found, off,
                        owner_filter, page, resolving, dumps, cut, when)

SEARCH_CELL = 2000


class FindCampaignsIn(ListIn):
    status: list[str] | None = Field(None, description="Only these statuses: planned, active, done, cancelled")
    kind: str | None = Field(None, max_length=40, description="The campaign's kind: email, event, webinar, ads, content, outbound, referral, other")
    owner: Ref | None = Field(None, description="A member (id, name or me), or none")


class CampaignRefIn(_Base):
    campaign: Ref = Field(..., description="A campaign (id or name)")


class CampaignResultsIn(_Base):
    status: list[str] | None = Field(None, description="Only these statuses: planned, active, done, cancelled")
    limit: int = Field(25, ge=1, le=100, description="How many campaigns at most")


class CampaignMembersIn(CampaignRefIn):
    status: str | None = Field(None, max_length=40, description="Only members with this status: planned, sent, opened, responded, attended, converted, unsubscribed")
    limit: int = Field(50, ge=1, le=100, description="How many members at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many members (paging)")


class FindSourcesIn(ListIn):
    active: bool | None = Field(None, description="true: only active sources")


class FindLostReasonsIn(ListIn):
    kind: str | None = Field(None, pattern=r"^(lead|deal)$", description="lead or deal")


class FindTagsIn(ListIn):
    pass


class CatalogueIn(_Base):
    part: str | None = Field(None, pattern=r"^(fields|pipelines|sources|reasons|rules|settings)$", description="One part only: fields, pipelines, sources, reasons, rules or settings; omitted: all")


class MembersWithRolesIn(ListIn):
    role: str | None = Field(None, max_length=40, description="Only people holding this role (reader, user, curator, admin …)")
    agents_only: bool = Field(False, description="true: only the agents that work here")


class FindMembersIn(ListIn):
    role: str | None = Field(None, max_length=40, description="Only people holding this role")


class FindDepartmentsIn(ListIn):
    pass


class FindKeysIn(_Base):
    q: str | None = Field(None, max_length=100, description="Words in the key's label (alone, also resolves one key by its label)")
    mine: bool | None = Field(None, description="true (the default): only my own keys; false: every key — needs the right to manage tokens")
    scope: str | None = Field(None, max_length=20, description="mcp or api")
    limit: int = Field(25, ge=1, le=100, description="How many keys at most")


class KeyUsageIn(_Base):
    days: int = Field(7, ge=1, le=90, description="How many days back")


class ProposalsIn(_Base):
    kind: str | None = Field(None, max_length=40, description="next_step, lead_qualify, lead_unqualify, duplicate, email_draft, rotting, reassign, close_date")
    status: str | None = Field(None, max_length=20, description="proposed (waiting), accepted, dismissed, expired")
    for_: Ref | None = Field(None, alias="for", description="Proposals for this person (id, name or me)")
    deal: Ref | None = Field(None, description="Only for this deal (id, number or title)")
    limit: int = Field(25, ge=1, le=100, description="How many proposals at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many proposals (paging)")


class DispatchesIn(_Base):
    agent: Ref | None = Field(None, description="Only this agent (id or name)")
    kind: str | None = Field(None, max_length=40, description="The kind of ask: lead_qualify, next_step, reminder, stage_entered, ask, duty_proposal")
    status: str | None = Field(None, max_length=20, description="sent, answered, refused, failed, awaiting_approval")
    since: str | None = Field(None, description="Only since this day or time")
    limit: int = Field(25, ge=1, le=100, description="How many dispatches at most")


class NotificationsIn(_Base):
    unread_only: bool = Field(False, description="true: only the unread ones")
    limit: int = Field(25, ge=1, le=100, description="How many notices at most")


class TombstonesIn(_Base):
    kind: str | None = Field(None, pattern=r"^(lead|account|contact|deal)$", description="lead, account, contact or deal")
    limit: int = Field(50, ge=1, le=200, description="How many at most")


class SearchIn(_Base):
    q: str = Field(..., min_length=2, max_length=200, description="What to look for: a name, a number, a company, an email, a domain, a tag or a word in a description (web-search syntax; a misspelt name is found)")
    kinds: list[str] | None = Field(None, description="Only these kinds of record: lead, account, contact, deal")
    limit: int = Field(25, ge=1, le=100, description="How many results at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many results (paging)")


class RecordsSearchIn(_Base):
    sql: str = Field(..., min_length=7, max_length=4000, description="One read-only SELECT over the mcp_* views — the walled views the named tools read. 5 seconds, 200 rows, a cell cut at 2,000 characters")


describe_models(globals())


def register(mcp, q, bridge=None) -> None:
    @mcp.tool(name="find_campaigns", annotations={"title": "Find campaigns", **RO})
    @answers
    async def find_campaigns(params: FindCampaignsIn) -> str:
        """Which campaigns are running, planned or done: name, kind, status, dates, budget, expected leads, owner. `q` alone resolves a campaign by name."""
        w = Where()
        if params.q:
            w.add("name ILIKE {}", like(params.q))
        if params.status:
            w.add("status = ANY({}::text[])", list(params.status))
        if params.kind:
            w.add("kind = {}", params.kind)
        await owner_filter(q, w, "owner_member_id", params.owner)
        rows, total = await page(q, f"SELECT campaign_id, name, kind, status, starts_on, ends_on, budget, currency, expected_leads, owner_member_id FROM mcp_campaigns WHERE {w.sql()}", w.args,
                                 lim(params.limit), off(params.offset), "ORDER BY starts_on DESC NULLS LAST, campaign_id DESC")
        if resolving(params):
            rows = exact_first(rows, params.q, "name")
        return listing(rows, params.limit, total)

    @mcp.tool(name="get_campaign", annotations={"title": "One campaign with its results", **RO})
    @answers
    async def get_campaign(params: CampaignRefIn) -> str:
        """One campaign with its results: members, responded, leads, converted, deals, won, won value, open value, cost per lead and per win."""
        cid = await campaign_id(q, params.campaign)
        camp = (await q("SELECT * FROM mcp_campaigns WHERE campaign_id = $1", cid))[0]
        res = await q("SELECT members, responded, leads, converted, deals, won, won_value, open_value, cost_per_lead, cost_per_won FROM crm_campaign_results($1)", cid)
        return detail(campaign=camp, results=res[0] if res else {})

    @mcp.tool(name="campaign_results", annotations={"title": "What each campaign brought", **RO})
    @answers
    async def campaign_results(params: CampaignResultsIn) -> str:
        """Every campaign's cost against what it brought: members, responded, leads, converted, deals, won, won value, cost per lead and per win."""
        w = Where()
        if params.status:
            w.add("c.status = ANY({}::text[])", list(params.status))
        rows, total = await page(q, f"SELECT c.campaign_id, c.name, c.kind, c.budget, r.members, r.responded, r.leads, r.converted, r.deals, r.won, r.won_value, r.cost_per_lead, r.cost_per_won "
                                    f"FROM mcp_campaigns c CROSS JOIN LATERAL crm_campaign_results(c.campaign_id) r WHERE {w.sql()}", w.args, lim(params.limit), 0, "ORDER BY won_value DESC NULLS LAST, campaign_id")
        return listing(rows, params.limit, total)

    @mcp.tool(name="campaign_members", annotations={"title": "Who is in a campaign", **RO})
    @answers
    async def campaign_members(params: CampaignMembersIn) -> str:
        """Who is in a campaign, a lead or a contact, with their status and when they were added and responded."""
        cid = await campaign_id(q, params.campaign)
        w = Where().add("m.campaign_id = {}", cid)
        if params.status:
            w.add("m.status = {}", params.status)
        rows, total = await page(q, f"SELECT m.campaign_member_id, m.lead_id, m.contact_id, COALESCE(l.label, c.name) AS name, m.status, m.added_at, m.responded_at FROM mcp_campaign_members m "
                                    f"LEFT JOIN mcp_leads l ON l.lead_id = m.lead_id LEFT JOIN mcp_contacts c ON c.contact_id = m.contact_id WHERE {w.sql()}", w.args, lim(params.limit, 50), off(params.offset), "ORDER BY added_at DESC, campaign_member_id DESC")
        return listing(rows, params.limit, total)

    @mcp.tool(name="find_sources", annotations={"title": "Lead sources", **RO})
    @answers
    async def find_sources(params: FindSourcesIn) -> str:
        """The lead sources (name, kind, active). `q` alone resolves a source by name."""
        w = Where()
        if params.q:
            w.add("name ILIKE {}", like(params.q))
        if params.active is not None or resolving(params):
            w.raw("active" if params.active is not False else "NOT active")
        rows, total = await page(q, f"SELECT source_id, name, kind, active, sort_order FROM mcp_lead_sources WHERE {w.sql()}", w.args, lim(params.limit), off(params.offset), "ORDER BY sort_order, name, source_id")
        if resolving(params):
            rows = exact_first(rows, params.q, "name")
        return listing(rows, params.limit, total)

    @mcp.tool(name="find_lost_reasons", annotations={"title": "Lost reasons", **RO})
    @answers
    async def find_lost_reasons(params: FindLostReasonsIn) -> str:
        """The reasons a lead is unqualified or a deal is lost (kind, label, active). `q` alone resolves a reason by label."""
        w = Where()
        if params.q:
            w.add("label ILIKE {}", like(params.q))
        if params.kind:
            w.add("kind = {}", params.kind)
        if resolving(params):
            w.raw("active")
        rows, total = await page(q, f"SELECT lost_reason_id, kind, label, active, sort_order FROM mcp_lost_reasons WHERE {w.sql()}", w.args, lim(params.limit), off(params.offset), "ORDER BY kind, sort_order, label, lost_reason_id")
        if resolving(params):
            rows = exact_first(rows, params.q, "label")
        return listing(rows, params.limit, total)

    @mcp.tool(name="find_tags", annotations={"title": "Tags", **RO})
    @answers
    async def find_tags(params: FindTagsIn) -> str:
        """The tags in use (slug, label, how many records carry it). `q` alone resolves a tag by label."""
        w = Where()
        if params.q:
            w.add("(label ILIKE {0} OR slug ILIKE {0})", like(params.q))
        rows, total = await page(q, f"SELECT tag_id, slug, label, usage_count FROM mcp_tags WHERE {w.sql()}", w.args, lim(params.limit), off(params.offset), "ORDER BY usage_count DESC, label, tag_id")
        if resolving(params):
            rows = exact_first(rows, params.q, "label", "slug")
        return listing(rows, params.limit, total)

    @mcp.tool(name="settings_catalogue", annotations={"title": "What a form can be filled with", **RO})
    @answers
    async def settings_catalogue(params: CatalogueIn) -> str:
        """The custom fields, the pipelines with their stages, the lead sources, the lost reasons, the assignment rules and the settings in one read — what the expert needs to fill a form. Rules only for someone who may assign leads; the mailbox id only for the admin."""
        part, out = params.part, {}
        if part in (None, "fields"):
            out["fields"] = await q("SELECT field_id, key, label, field_type, options, applies_to, pipeline_ids, required_to_win FROM mcp_custom_fields WHERE archived_at IS NULL ORDER BY sort_order, field_id")
        if part in (None, "pipelines"):
            pipes = await q("SELECT pipeline_id, key, name, is_default, currency, rotting_days FROM mcp_pipelines WHERE archived_at IS NULL ORDER BY position, pipeline_id")
            stages = await q("SELECT stage_id, pipeline_id, name, position, probability, kind, rotting_days, required_fields FROM mcp_stages WHERE archived_at IS NULL ORDER BY pipeline_id, position")
            for p in pipes:
                p["stages"] = [s for s in stages if s["pipeline_id"] == p["pipeline_id"]]
            out["pipelines"] = pipes
        if part in (None, "sources"):
            out["sources"] = await q("SELECT source_id, name, kind FROM mcp_lead_sources WHERE active ORDER BY sort_order, name")
        if part in (None, "reasons"):
            out["reasons"] = await q("SELECT lost_reason_id, kind, label FROM mcp_lost_reasons WHERE active ORDER BY kind, sort_order, label")
        if part in (None, "rules"):
            out["rules"] = await q("SELECT rule_id, name, position, active, match, assign_kind, member_id, department_id, members FROM mcp_assignment_rules ORDER BY position")
        if part in (None, "settings"):
            out["settings"] = (await q("SELECT * FROM mcp_settings"))[0]
        return detail(**out)

    @mcp.tool(name="members_with_roles", annotations={"title": "Who holds which role", **RO})
    @answers
    async def members_with_roles(params: MembersWithRolesIn) -> str:
        """Who holds which role here, and which agents work here: member, kind, agent or not, the roles they hold, their departments and time zone."""
        w = Where()
        if params.q:
            w.add("display_name ILIKE {}", like(params.q))
        if params.role:
            w.add("{} = ANY (roles_resolved)", params.role)
        if params.agents_only:
            w.raw("is_agent")
        rows, total = await page(q, f"SELECT member_id, display_name, member_kind, is_agent, roles_resolved, department_ids, timezone FROM mcp_members WHERE {w.sql()}", w.args, lim(params.limit), off(params.offset), "ORDER BY display_name, member_id")
        return listing(rows, params.limit, total)

    @mcp.tool(name="find_members", annotations={"title": "Find a colleague or an agent", **RO})
    @answers
    async def find_members(params: FindMembersIn) -> str:
        """A colleague or an agent by name. `q` alone resolves an owner, assignee, seller or agent by display name."""
        w = Where()
        if params.q:
            w.add("display_name ILIKE {}", like(params.q))
        if params.role:
            w.add("{} = ANY (roles_resolved)", params.role)
        rows, total = await page(q, f"SELECT member_id, display_name, member_kind, is_agent, roles_resolved, timezone FROM mcp_members WHERE {w.sql()}", w.args, lim(params.limit), off(params.offset), "ORDER BY display_name, member_id")
        if resolving(params):
            rows = exact_first(rows, params.q, "display_name")
        return listing(rows, params.limit, total)

    @mcp.tool(name="find_departments", annotations={"title": "Find a team", **RO})
    @answers
    async def find_departments(params: FindDepartmentsIn) -> str:
        """A team (department) by name, with its manager. `q` alone resolves a team by name."""
        w = Where().raw("archived_at IS NULL")
        if params.q:
            w.add("name ILIKE {}", like(params.q))
        rows, total = await page(q, f"SELECT department_id, name, manager_member_id FROM mcp_departments WHERE {w.sql()}", w.args, lim(params.limit), off(params.offset), "ORDER BY name, department_id")
        if resolving(params):
            rows = exact_first(rows, params.q, "name")
        return listing(rows, params.limit, total)

    @mcp.tool(name="find_keys", annotations={"title": "Keys and tokens", **RO})
    @answers
    async def find_keys(params: FindKeysIn) -> str:
        """The keys and tokens: mine (the default), or every one with the right to manage tokens — label, scope, rate limits, the lead source of a capture key, calls today, created, last used, expiry and revocation. Never the key itself."""
        w = Where()
        manage = await has_right(q, "tokens.manage")
        mine = params.mine if params.mine is not None else not (resolving(params) and manage)
        if params.q:
            w.add("label ILIKE {}", like(params.q))
        if mine:
            w.add("member_id = {}", await me(q))
        elif not manage:
            raise managers_only("Every key (it needs the right to manage tokens)")
        if params.scope:
            w.add("scope = {}", params.scope)
        rows, total = await page(q, f"SELECT key_id, member_id, label, scope, rate_per_minute, rate_per_day, lead_source_id, calls_today, created_at, last_used_at, expires_at, revoked_at FROM mcp_tokens WHERE {w.sql()}", w.args,
                                 lim(params.limit), 0, "ORDER BY created_at DESC, key_id DESC")
        if resolving(params):
            rows = exact_first(rows, params.q, "label")
        return listing(rows, params.limit, total)

    @mcp.tool(name="key_usage_summary", annotations={"title": "How much each key was used", **RO})
    @answers
    async def key_usage_summary(params: KeyUsageIn) -> str:
        """How much each key was used over the last N days (7 by default): calls and refused calls, with the key's label. Needs the right to manage tokens."""
        if not await has_right(q, "tokens.manage"):
            raise managers_only("Key usage (it needs the right to manage tokens)")
        rows = await q("SELECT t.key_id, t.label, COALESCE(sum(u.calls), 0)::int AS calls, COALESCE(sum(u.refused), 0)::int AS refused FROM mcp_tokens t LEFT JOIN mcp_key_usage u ON u.key_id = t.key_id AND u.day >= current_date - $1::int "
                       "GROUP BY t.key_id, t.label ORDER BY calls DESC, t.key_id LIMIT 201", params.days)
        return listing(rows, 200, days=params.days)

    @mcp.tool(name="assistant_proposals", annotations={"title": "What the Assistant proposed", **RO})
    @answers
    async def assistant_proposals(params: ProposalsIn) -> str:
        """What the Assistant proposed — a next step, a qualification, a duplicate, a rotting deal — for me or (a manager) for everyone, and whether it was accepted or dismissed."""
        from crm_common import deal_id
        w = Where()
        if params.kind:
            w.add("kind = {}", params.kind)
        if params.status:
            w.add("status = {}", params.status)
        if params.for_ not in (None, ""):
            w.add("for_member_id = {}", await member_ref(q, params.for_))
        if params.deal not in (None, ""):
            w.add("deal_id = {}", await deal_id(q, params.deal))
        rows, total = await page(q, f"SELECT proposal_id, kind, deal_id, lead_id, contact_id, account_id, for_member_id, title, payload, proposed_by, status, decided_by, decided_at, result_activity_id, created_at FROM mcp_assistant_proposals WHERE {w.sql()}",
                                 w.args, lim(params.limit), off(params.offset), "ORDER BY created_at DESC, proposal_id DESC")
        return listing(rows, params.limit, total)

    @mcp.tool(name="agent_dispatches", annotations={"title": "What the agents were asked to do", **RO})
    @answers
    async def agent_dispatches(params: DispatchesIn) -> str:
        """What the agents were asked to do here and what came back: the record, the agent, the kind and way of asking, status, the first 200 characters of the reply and the attempts. The agents' settings right sees all; an agent sees its own."""
        w = Where()
        if params.agent not in (None, ""):
            w.add("agent_member_id = {}", await member_ref(q, params.agent))
        if params.kind:
            w.add("kind = {}", params.kind)
        if params.status:
            w.add("status = {}", params.status)
        if params.since:
            w.add("created_at >= {}", when(params.since, "since"))
        rows, total = await page(q, f"SELECT dispatch_id, record_type, record_id, agent_member_id, kind, via, acting_member_id, status, left(reply_excerpt, 200) AS reply_excerpt, attempts, created_at, answered_at, deal_id, lead_id FROM mcp_agent_dispatches WHERE {w.sql()}",
                                 w.args, lim(params.limit), 0, "ORDER BY created_at DESC, dispatch_id DESC")
        return listing(rows, params.limit, total)

    @mcp.tool(name="my_notifications", annotations={"title": "My notices", **RO})
    @answers
    async def my_notifications(params: NotificationsIn) -> str:
        """My notices, unread first: kind, title, body, the record it is about and when."""
        w = Where()
        if params.unread_only:
            w.raw("read_at IS NULL")
        rows, total = await page(q, f"SELECT notification_id, kind, title, body, record_type, record_id, deal_id, contact_id, activity_id, read_at, created_at FROM mcp_notifications WHERE {w.sql()}", w.args, lim(params.limit), 0,
                                 "ORDER BY (read_at IS NULL) DESC, created_at DESC, notification_id DESC")
        return listing(rows, params.limit, total)

    @mcp.tool(name="get_settings", annotations={"title": "The business's settings", **RO})
    @answers
    async def get_settings() -> str:
        """The business's settings as the screens show them: currency, default pipeline, rotting days, forecast months, the untouched thresholds, tombstone and import limits, key rate limits and time zone (the logging mailbox's id only for the admin)."""
        return detail(settings=(await q("SELECT * FROM mcp_settings"))[0])

    @mcp.tool(name="tombstones", annotations={"title": "What waits in the tombstones", **RO})
    @answers
    async def tombstones(params: TombstonesIn) -> str:
        """What waits in the tombstones — deleted or merged leads, accounts, contacts and deals — and when each purges. A manager's."""
        if not await is_manager(q):
            raise managers_only("The tombstones")
        w = Where()
        if params.kind:
            w.add("record_kind = {}", params.kind)
        days = (await q("SELECT tombstone_days FROM mcp_settings"))[0]["tombstone_days"]
        rows, total = await page(q, f"SELECT record_kind, record_id, name, deleted_at, deleted_by, merged_into_id, deleted_at + make_interval(days => {int(days)}) AS purges_at FROM mcp_tombstones WHERE {w.sql()}", w.args,
                                 lim(params.limit, 50, 200), 0, "ORDER BY deleted_at DESC, record_kind, record_id")
        return listing(rows, lim(params.limit, 50, 200), total)

    @mcp.tool(name="search", annotations={"title": "The one search box", **RO})
    @answers
    async def search(params: SearchIn) -> str:
        """The one box: leads, accounts, contacts and deals by name, number, company, email, domain, tags and description — web-search syntax, and a misspelt name is found by sound-alike. Every result is judged by the wall. Returns the kind, id, title, a headline with the match, the status, the account and the owner."""
        kinds = [k for k in (params.kinds or []) if k in ("lead", "account", "contact", "deal")] or None
        n = lim(params.limit)
        rows = await q(f"SELECT entity_kind, entity_id, title, headline, status, account_id, owner_member_id, rank, total FROM crm_search($1, $2, $3, $4)", params.q, kinds, n + 1, off(params.offset))
        total = int(rows[0]["total"]) if rows else 0
        for r in rows:
            r.pop("total", None)
        return listing(rows, n, total)

    @mcp.tool(name="records_search", annotations={"title": "Guarded read of the views", **RO})
    @answers
    async def records_search(params: RecordsSearchIn) -> str:
        """The long tail: one read-only SELECT over the walled mcp_* views the named tools read, as the asking member — 5 seconds, 200 rows, a cell cut at 2,000 characters. Both the wall and the right to see a record are the view's own. Use a named tool when one answers the question."""
        pool = await db.get_pool(db.ENV["MCP_RECORDS_DB_USER"], db.ENV["MCP_RECORDS_DB_PASSWORD"])
        raw = json.loads(await db.run_search(pool, params.sql))
        if isinstance(raw, dict) and raw.get("error"):
            raise Refusal("invalid_argument", str(raw["error"])[:300], fields=["sql"])
        cut_cells = False
        for r in raw:
            for k, v in r.items():
                if isinstance(v, str) and len(v) > SEARCH_CELL:
                    r[k] = v[:SEARCH_CELL] + "…"
                    cut_cells = True
        return listing(raw, 200, cells_cut=cut_cells)
