"""Pipeline and deals (Q14–Q23; tool surface "Pipeline and deals"): find_pipelines, find_stages, pipeline_summary, forecast, funnel, win_rate, cycle_time, deals_at_risk, deals_without_next_step, deals_rotting,
find_deals, get_deal, deal_history, deal_products, deal_contacts, deals_closed, lost_reasons_summary, leaderboard, stage_requirements_check, products_pipeline, saved_views, quotas. The rows are the walled
views' (mcp_deals …) or the SECURITY DEFINER read functions' (crm_pipeline_summary, crm_forecast, crm_funnel …), as the asking member. THE ONE RULE added here (reports/queries.php does the same): crm_forecast()
answers every seller, so a caller without `reports.team` keeps only their own line of a forecast by seller."""
from __future__ import annotations

import datetime

from pydantic import Field

from crm_common import (RO, Ref, Refusal, ListIn, Where, _Base, account_id, answers, campaign_id, contact_id, day, deal_id, default_pipeline, department_id, describe_models, detail, exact_first, has_right, like, lim, listing,
                        managers_only, me, member_ref, not_found, off, owner_filter, page, pipeline_id, resolving, source_id, span, this_month, dumps, one_by_id)

DEAL_COLS = """d.deal_id, d.number, d.title, d.account_id, d.account_name, d.primary_contact_id, d.primary_contact_name, d.pipeline_id, d.stage_id, d.stage_name, d.position, d.value, d.currency, d.probability,
               d.weighted_value, d.expected_close_date, d.status, d.owner_member_id, d.stage_entered_at, d.days_in_stage, d.is_rotting, d.next_activity_at, d.next_colour, d.has_next_step, d.tags"""
DEAL_SORTS = {"close": "expected_close_date NULLS LAST, deal_id", "value": "value DESC NULLS LAST, deal_id", "touched": "last_touched_at DESC NULLS LAST, deal_id", "position": "pipeline_id, position, deal_id",
              "stage": "pipeline_id, (SELECT s.position FROM mcp_stages s WHERE s.stage_id = t.stage_id), position, deal_id"}


class FindDealsIn(ListIn):
    pipeline: Ref | None = Field(None, description="A pipeline (id, key or name)")
    stage: list[Ref] | None = Field(None, description="Only these stages (ids or names; a name matches in every pipeline that has it)")
    status: list[str] | None = Field(None, description="Only these statuses: open, won, lost. Omitted: every status — give [open] for the live pipeline")
    owner: Ref | None = Field(None, description="A member (id, name or me), or none for the unowned")
    team: Ref | None = Field(None, description="A department (id or name) — the deals its members own")
    account: Ref | None = Field(None, description="An account (id, name or domain)")
    contact: Ref | None = Field(None, description="A contact on the deal (id, name or email)")
    close_from: str | None = Field(None, description="Expected to close on or after this day, YYYY-MM-DD")
    close_to: str | None = Field(None, description="Expected to close on or before this day, YYYY-MM-DD")
    close: str | None = Field(None, pattern=r"^(this_month|next_month|this_quarter|overdue)$", description="A close window: this_month, next_month, this_quarter or overdue (open deals past their date)")
    min_value: float | None = Field(None, ge=0, description="Worth at least this much")
    max_value: float | None = Field(None, ge=0, description="Worth at most this much")
    tag: str | None = Field(None, max_length=60, description="A tag's label")
    source: Ref | None = Field(None, description="A lead source (id or name)")
    campaign: Ref | None = Field(None, description="A campaign (id or name)")
    no_next_step: bool | None = Field(None, description="true: open deals with no open activity")
    rotting: bool | None = Field(None, description="true: open deals too long in their stage")
    sort: str | None = Field(None, description="close (the default), value, stage, touched or position")


async def stage_ids(q, values: list, pipeline: int | None = None) -> list[int]:
    """Stage ids from ids or names; a name matches in every pipeline that has it (or only in `pipeline`)."""
    out: list[int] = []
    for v in values:
        s = str(v).strip()
        if s.isdigit():
            out.append(int(s))
            continue
        extra = f" AND pipeline_id = {int(pipeline)}" if pipeline else ""
        rows = await q(f"SELECT stage_id FROM mcp_stages WHERE lower(name) = lower($1){extra}", s)
        if not rows:
            rows = await q(f"SELECT stage_id FROM mcp_stages WHERE name ILIKE $1{extra}", like(s))
        if not rows:
            raise not_found("stage")
        out += [int(r["stage_id"]) for r in rows]
    return out


def quarter_window(today: datetime.date) -> tuple[datetime.date, datetime.date]:
    m0 = 3 * ((today.month - 1) // 3) + 1
    first = datetime.date(today.year, m0, 1)
    nxt = datetime.date(today.year + (1 if m0 == 10 else 0), 1 if m0 == 10 else m0 + 3, 1)
    return first, nxt - datetime.timedelta(days=1)


async def deals_query(q, p: FindDealsIn, extra: str = "") -> tuple[list[dict], int | None]:
    w = Where()
    pipeline = None
    if p.pipeline not in (None, ""):
        pipeline = await pipeline_id(q, p.pipeline)
        w.add("d.pipeline_id = {}", pipeline)
    if p.q:
        w.add("(d.title ILIKE {0} OR d.number ILIKE {0} OR d.account_name ILIKE {0})", like(p.q))
    if p.stage:
        w.add("d.stage_id = ANY({}::bigint[])", await stage_ids(q, list(p.stage), pipeline))
    if p.status:
        w.add("d.status = ANY({}::text[])", [s for s in p.status])
    await owner_filter(q, w, "d.owner_member_id", p.owner)
    if p.team not in (None, ""):
        w.add("d.owner_member_id IN (SELECT member_id FROM mcp_members WHERE {} = ANY (department_ids))", await department_id(q, p.team))
    if p.account not in (None, ""):
        w.add("d.account_id = {}", await account_id(q, p.account))
    if p.contact not in (None, ""):
        w.add("d.deal_id IN (SELECT deal_id FROM mcp_deal_contacts WHERE contact_id = {})", await contact_id(q, p.contact))
    if p.close_from:
        w.add("d.expected_close_date >= {}", day(p.close_from, "close_from"))
    if p.close_to:
        w.add("d.expected_close_date <= {}", day(p.close_to, "close_to"))
    today = datetime.date.today()
    if p.close == "this_month":
        a, b = this_month()
        w.add("d.expected_close_date BETWEEN {} AND {}", a, b)
    elif p.close == "next_month":
        a = (this_month()[1] + datetime.timedelta(days=1))
        b = (a + datetime.timedelta(days=32)).replace(day=1) - datetime.timedelta(days=1)
        w.add("d.expected_close_date BETWEEN {} AND {}", a, b)
    elif p.close == "this_quarter":
        a, b = quarter_window(today)
        w.add("d.expected_close_date BETWEEN {} AND {}", a, b)
    elif p.close == "overdue":
        w.add("d.status = 'open' AND d.expected_close_date < {}", today)
    if p.min_value is not None:
        w.add("d.value >= {}", p.min_value)
    if p.max_value is not None:
        w.add("d.value <= {}", p.max_value)
    if p.tag:
        w.add("EXISTS (SELECT 1 FROM unnest(d.tags) t WHERE lower(t) = lower({}))", p.tag)
    if p.source not in (None, ""):
        w.add("d.source_id = {}", await source_id(q, p.source))
    if p.campaign not in (None, ""):
        w.add("d.campaign_id = {}", await campaign_id(q, p.campaign))
    if p.no_next_step:
        w.raw("d.status = 'open' AND NOT d.has_next_step")
    if p.rotting:
        w.raw("d.status = 'open' AND d.is_rotting")
    if extra:
        w.raw(extra)
    order = "ORDER BY " + DEAL_SORTS.get((p.sort or "close").lower(), DEAL_SORTS["close"])
    return await page(q, f"SELECT {DEAL_COLS}, d.last_touched_at FROM mcp_deals d WHERE {w.sql()}", w.args, lim(p.limit), off(p.offset), order)


class FindPipelinesIn(ListIn):
    include_archived: bool = Field(False, description="true: archived pipelines too")


class FindStagesIn(ListIn):
    pipeline: Ref | None = Field(None, description="A pipeline (id, key or name); omitted: every pipeline's stages")
    kind: str | None = Field(None, pattern=r"^(open|won|lost)$", description="open, won or lost stages")


class SummaryIn(_Base):
    pipeline: Ref | None = Field(None, description="A pipeline (id, key or name); omitted: the default pipeline")
    owner: Ref | None = Field(None, description="Only this seller's deals (id, name or me)")
    team: Ref | None = Field(None, description="Only the deals of this department's members (id or name)")


class ForecastIn(_Base):
    pipeline: Ref | None = Field(None, description="A pipeline (id, key or name); omitted: every pipeline")
    months: int = Field(6, ge=1, le=24, description="How many months ahead (1 to 24)")
    by: str = Field("seller", pattern=r"^(seller|stage)$", description="seller or stage")
    team: Ref | None = Field(None, description="Only this department's members (id or name)")


class FunnelIn(_Base):
    pipeline: Ref | None = Field(None, description="A pipeline (id, key or name); omitted: the default pipeline")
    from_: str = Field(..., alias="from", description="First day, YYYY-MM-DD")
    to: str = Field(..., description="Last day, YYYY-MM-DD")
    owner: Ref | None = Field(None, description="Only this seller's deals (id, name or me)")


class WinRateIn(_Base):
    from_: str = Field(..., alias="from", description="First day, YYYY-MM-DD")
    to: str = Field(..., description="Last day, YYYY-MM-DD")
    by: str = Field("seller", pattern=r"^(seller|source|pipeline)$", description="seller, source or pipeline")


class CycleIn(_Base):
    from_: str = Field(..., alias="from", description="First day, YYYY-MM-DD")
    to: str = Field(..., description="Last day, YYYY-MM-DD")


class AtRiskIn(_Base):
    owner: Ref | None = Field(None, description="Only this seller's deals (id, name or me)")
    reason: list[str] | None = Field(None, description="Only these reasons: no_next_step, rotting, closing_untouched, overdue (as the function names them)")
    closing_days: int = Field(14, ge=1, le=365, description="Closing within this many days counts as 'closing soon'")
    untouched_days: int = Field(7, ge=1, le=365, description="Untouched for this many days counts as 'untouched'")
    limit: int = Field(50, ge=1, le=100, description="How many deals at most")


class OwnerPipelineIn(_Base):
    owner: Ref | None = Field(None, description="Only this seller's deals (id, name or me)")
    pipeline: Ref | None = Field(None, description="A pipeline (id, key or name)")
    limit: int = Field(50, ge=1, le=100, description="How many deals at most")


class GetDealIn(_Base):
    deal: Ref = Field(..., description="A deal (id, number or title)")


class DealRowsIn(GetDealIn):
    limit: int = Field(100, ge=1, le=200, description="How many rows at most")


class ClosedIn(_Base):
    from_: str = Field(..., alias="from", description="First day, YYYY-MM-DD")
    to: str = Field(..., description="Last day, YYYY-MM-DD")
    status: str | None = Field(None, pattern=r"^(won|lost)$", description="won or lost; omitted: both")
    owner: Ref | None = Field(None, description="Only this seller's deals (id, name or me)")
    limit: int = Field(50, ge=1, le=100, description="How many deals at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many deals (paging)")


class LostIn(_Base):
    from_: str = Field(..., alias="from", description="First day, YYYY-MM-DD")
    to: str = Field(..., description="Last day, YYYY-MM-DD")
    by_seller: bool = Field(False, description="true: split each reason by seller")


class LeaderboardIn(_Base):
    month: str | None = Field(None, description="A month, YYYY-MM or any day in it; omitted: this month")
    team: Ref | None = Field(None, description="Only this department's members (id or name)")


class StageCheckIn(_Base):
    deal: Ref = Field(..., description="A deal (id, number or title)")
    stage: Ref = Field(..., description="The stage it would enter (id or name, in the deal's pipeline)")


class ProductsIn(_Base):
    pipeline: Ref | None = Field(None, description="A pipeline (id, key or name); omitted: the default pipeline")


class SavedViewsIn(ListIn):
    record_kind: str | None = Field(None, pattern=r"^(lead|contact|account|deal|activity)$", description="Only views of this kind of record")
    layout: str | None = Field(None, max_length=20, description="board, list, calendar …")


class QuotasIn(_Base):
    member: Ref | None = Field(None, description="A seller (id, name or me); omitted: whoever you may see")
    from_month: str | None = Field(None, description="First month, YYYY-MM or a day in it")
    to_month: str | None = Field(None, description="Last month, YYYY-MM or a day in it")


describe_models(globals())


def month_start(s, what: str) -> datetime.date | None:
    if s in (None, ""):
        return None
    t = str(s).strip()
    if len(t) == 7:
        t += "-01"
    return day(t, what).replace(day=1)


def register(mcp, q, bridge=None) -> None:
    async def pipe(v) -> int:
        return await default_pipeline(q) if v in (None, "") else await pipeline_id(q, v)

    async def owner_arg(v):
        return None if v in (None, "") or str(v).lower() == "none" else await member_ref(q, v)

    @mcp.tool(name="find_pipelines", annotations={"title": "Pipelines", **RO})
    @answers
    async def find_pipelines(params: FindPipelinesIn) -> str:
        """Which pipelines exist (key, name, default, currency, rotting days). `q` alone resolves a pipeline by key or name."""
        w = Where()
        if params.q:
            w.add("(key ILIKE {0} OR name ILIKE {0})", like(params.q))
        if not params.include_archived:
            w.raw("archived_at IS NULL")
        rows, total = await page(q, f"SELECT pipeline_id, key, name, is_default, currency, rotting_days, position FROM mcp_pipelines WHERE {w.sql()}", w.args, lim(params.limit), off(params.offset), "ORDER BY position, pipeline_id")
        if resolving(params):
            rows = exact_first(rows, params.q, "key", "name")
        return listing(rows, params.limit, total)

    @mcp.tool(name="find_stages", annotations={"title": "Stages", **RO})
    @answers
    async def find_stages(params: FindStagesIn) -> str:
        """A pipeline's stages in order with their probability, rotting days and the fields a deal must have to enter. `q` alone (with `pipeline`) resolves a stage by name."""
        w = Where().raw("archived_at IS NULL")
        if params.pipeline not in (None, ""):
            w.add("pipeline_id = {}", await pipeline_id(q, params.pipeline))
        if params.q:
            w.add("name ILIKE {}", like(params.q))
        if params.kind:
            w.add("kind = {}", params.kind)
        rows, total = await page(q, f"SELECT stage_id, pipeline_id, name, position, probability, kind, rotting_days, required_fields, ask_assistant, colour, wip_limit FROM mcp_stages WHERE {w.sql()}", w.args,
                                 lim(params.limit), off(params.offset), "ORDER BY pipeline_id, position, stage_id")
        if resolving(params):
            rows = exact_first(rows, params.q, "name")
        return listing(rows, params.limit, total)

    @mcp.tool(name="pipeline_summary", annotations={"title": "What is in the pipeline", **RO})
    @answers
    async def pipeline_summary(params: SummaryIn) -> str:
        """What is in the pipeline by stage: deal count, value, weighted value, how many are rotting and how many have no next step — for everyone, one seller or one team."""
        pid = await pipe(params.pipeline)
        team = None if params.team in (None, "") else await department_id(q, params.team)
        rows = await q("SELECT stage_id, stage_name, stage_position AS position, probability, kind, colour, wip_limit, deals, value, weighted, rotting, no_next_step, other_currency_count FROM crm_pipeline_summary($1, $2, $3) ORDER BY stage_position",
                       pid, await owner_arg(params.owner), team)
        cur = (await q("SELECT COALESCE((SELECT currency FROM mcp_pipelines WHERE pipeline_id = $1), (SELECT currency FROM mcp_settings)) AS c", pid))[0]["c"]
        return listing(rows, 200, currency=cur, pipeline_id=pid)

    @mcp.tool(name="forecast", annotations={"title": "What is closing by month", **RO})
    @answers
    async def forecast(params: ForecastIn) -> str:
        """What is closing by month — open value, weighted value, won value, deal count and quota — by seller or by stage, across every pipeline or one. Another seller's line is a manager's: without that right a forecast by seller holds your own line only."""
        pid = None if params.pipeline in (None, "") else await pipeline_id(q, params.pipeline)
        team = None if params.team in (None, "") else await department_id(q, params.team)
        rows = await q("SELECT month, row_id, row_name, open_value, weighted, won_value, deals, quota FROM crm_forecast($1, $2, $3, $4) ORDER BY month, row_name, row_id", pid, params.months, params.by, team)
        if params.by == "seller" and not await has_right(q, "reports.team"):
            mine = await me(q)
            rows = [r for r in rows if r["row_id"] == mine]
        cur = (await q("SELECT currency FROM mcp_settings"))[0]["currency"]
        return listing(rows, 10000, currency=cur, by=params.by)

    @mcp.tool(name="funnel", annotations={"title": "Where the funnel leaks", **RO})
    @answers
    async def funnel(params: FunnelIn) -> str:
        """Where the funnel leaks, stage by stage: how many entered, moved on, were lost from there, are still there, and the median days in the stage."""
        d1, d2 = span(params.from_, params.to)
        pid = await pipe(params.pipeline)
        rows = await q("SELECT stage_id, stage_name, stage_position, entered, moved_on, lost_from, still_here, median_days FROM crm_funnel($1, $2, $3, $4) ORDER BY stage_position", pid, d1, d2, await owner_arg(params.owner))
        return listing(rows, 200, pipeline_id=pid)

    @mcp.tool(name="win_rate", annotations={"title": "Won against lost", **RO})
    @answers
    async def win_rate(params: WinRateIn) -> str:
        """Won against lost in a period, by seller, source or pipeline, with the average and median cycle in days."""
        d1, d2 = span(params.from_, params.to)
        rows = await q("SELECT row_id, row_name, won, lost, win_rate, won_value, avg_cycle_days, median_cycle_days FROM crm_win_rate($1, $2, $3) ORDER BY won DESC, row_name", d1, d2, params.by)
        return listing(rows, 500, by=params.by)

    @mcp.tool(name="cycle_time", annotations={"title": "Days and touches to win", **RO})
    @answers
    async def cycle_time(params: CycleIn) -> str:
        """Days from creation to won and touches to win in a period: per seller, and overall (deals, average and median touches, average days)."""
        d1, d2 = span(params.from_, params.to)
        by = await q("SELECT row_id AS member_id, row_name, won, avg_cycle_days, median_cycle_days FROM crm_win_rate($1, $2, 'seller') ORDER BY won DESC, row_name", d1, d2)
        overall = await q("SELECT deals, avg_touches, median_touches, avg_days FROM crm_touches_to_win($1, $2)", d1, d2)
        return detail(by_seller=by, overall=overall[0] if overall else {"deals": 0})

    @mcp.tool(name="deals_at_risk", annotations={"title": "Deals needing a hand", **RO})
    @answers
    async def deals_at_risk(params: AtRiskIn) -> str:
        """Deals that need a hand, with the reason: no next step, rotting, closing soon but untouched, or past their close date. Returns deal, account, owner, stage, value, close date, reason and days."""
        rows = await q("SELECT deal_id, number, title, account_name, owner_member_id, stage_name, value, expected_close_date, reason, days FROM crm_deals_at_risk($1, $2, $3) ORDER BY days DESC, deal_id",
                       await owner_arg(params.owner), params.closing_days, params.untouched_days)
        if params.reason:
            want = set(params.reason)
            rows = [r for r in rows if r["reason"] in want]
        return listing(rows, params.limit)

    async def open_deals(params: OwnerPipelineIn, cond: str, extra_cols: str = "") -> str:
        w = Where().raw("d.status = 'open'").raw(cond)
        await owner_filter(q, w, "d.owner_member_id", params.owner)
        if params.pipeline not in (None, ""):
            w.add("d.pipeline_id = {}", await pipeline_id(q, params.pipeline))
        rows, total = await page(q, f"SELECT {DEAL_COLS}{extra_cols} FROM mcp_deals d WHERE {w.sql()}", w.args, lim(params.limit), 0, "ORDER BY days_in_stage DESC, deal_id")
        return listing(rows, lim(params.limit), total)

    @mcp.tool(name="deals_without_next_step", annotations={"title": "Deals with no next step", **RO})
    @answers
    async def deals_without_next_step(params: OwnerPipelineIn) -> str:
        """Open deals with no open activity — the ones the Assistant proposes a next step for. Same rows as find_deals."""
        return await open_deals(params, "NOT d.has_next_step")

    @mcp.tool(name="deals_rotting", annotations={"title": "Deals too long in a stage", **RO})
    @answers
    async def deals_rotting(params: OwnerPipelineIn) -> str:
        """Open deals that have been in their stage longer than the stage allows. Same rows as find_deals (days_in_stage says how long)."""
        return await open_deals(params, "d.is_rotting")

    @mcp.tool(name="find_deals", annotations={"title": "Find deals", **RO})
    @answers
    async def find_deals(params: FindDealsIn) -> str:
        """Deals by pipeline, stage, status, owner, team, account, contact, close window, value, tag, source, campaign, no next step or rotting. `q` alone resolves a deal by title or number. Returns number, title, account, stage, value, probability, weighted value, expected close, status, owner, days in stage, rotting, next activity and tags, with `total`. Omitted `status` means every status — give [open] for the live pipeline. Answers 'what closes this month', 'Priya's open deals over 20k', 'the Acme renewal'."""
        rows, total = await deals_query(q, params)
        if resolving(params):
            rows = exact_first(rows, params.q, "number", "title")
        return listing([{k: v for k, v in r.items() if k != "last_touched_at"} for r in rows], params.limit, total)

    @mcp.tool(name="get_deal", annotations={"title": "One deal in full", **RO})
    @answers
    async def get_deal(params: GetDealIn) -> str:
        """One deal in full: its fields, the contacts on it with their roles, its product lines, the stage and what the NEXT stage still requires, the next step and the counts of notes and files."""
        did = await deal_id(q, params.deal)
        deal = (await q("SELECT * FROM mcp_deals WHERE deal_id = $1", did))[0]
        contacts = await q("SELECT contact_id, contact_name AS name, role, is_primary FROM mcp_deal_contacts WHERE deal_id = $1 ORDER BY is_primary DESC, added_at", did)
        products = await q("SELECT deal_product_id, position, name, provider_app, provider_id, sku, quantity, unit_price, discount_percent, line_total, recurring FROM mcp_deal_products WHERE deal_id = $1 ORDER BY position", did)
        stage = (await q("SELECT name, probability, required_fields, position, pipeline_id FROM mcp_stages WHERE stage_id = $1", deal["stage_id"]))
        missing: list = []
        if stage and deal["status"] == "open":
            nxt = await q("SELECT stage_id FROM mcp_stages WHERE pipeline_id = $1 AND position > $2 AND archived_at IS NULL ORDER BY position LIMIT 1", stage[0]["pipeline_id"], stage[0]["position"])
            if nxt:
                missing = (await q("SELECT crm_stage_requirements_check($1, $2) AS m", did, nxt[0]["stage_id"]))[0]["m"] or []
        step = None
        if deal.get("next_activity_id"):
            r = await q("SELECT a.activity_id, a.kind, a.subject, a.at, m.display_name AS owner_name FROM mcp_activities a LEFT JOIN mcp_members m ON m.member_id = a.owner_member_id WHERE a.activity_id = $1", deal["next_activity_id"])
            step = r[0] if r else None
        notes = (await q("SELECT count(*) AS n FROM mcp_notes WHERE record_type = 'deal' AND record_id = $1", did))[0]["n"]
        files = (await q("SELECT count(*) AS n FROM mcp_attachments WHERE record_type = 'deal' AND record_id = $1", did))[0]["n"]
        return detail(deal=deal, contacts=contacts, products=products, stage=({k: stage[0][k] for k in ("name", "probability", "required_fields")} if stage else None),
                      missing_for_next_stage=list(missing), next_step=step, notes_count=notes, attachments_count=files)

    @mcp.tool(name="deal_history", annotations={"title": "A deal's moves", **RO})
    @answers
    async def deal_history(params: DealRowsIn) -> str:
        """A deal's moves, oldest first: from which stage to which, who moved it, when, how long it had sat in the stage it left, and how (screen, API, agent)."""
        did = await deal_id(q, params.deal)
        rows = await q(f"SELECT history_id, from_stage, to_stage, member_id, at, seconds_in_from_stage, source FROM mcp_deal_stage_history WHERE deal_id = $1 ORDER BY at, history_id LIMIT {lim(params.limit, 100, 200) + 1}", did)
        return listing(rows, lim(params.limit, 100, 200))

    @mcp.tool(name="deal_products", annotations={"title": "A deal's product lines", **RO})
    @answers
    async def deal_products(params: GetDealIn) -> str:
        """A deal's product lines (name, SKU, quantity, unit price, discount, line total, recurring) and their total."""
        did = await deal_id(q, params.deal)
        rows = await q("SELECT deal_product_id, position, name, provider_app, provider_id, sku, quantity, unit_price, discount_percent, line_total, recurring FROM mcp_deal_products WHERE deal_id = $1 ORDER BY position", did)
        cur = (await q("SELECT currency FROM mcp_deals WHERE deal_id = $1", did))[0]["currency"]
        from crm_common import fmt_money
        total = sum(float(r["line_total"] or 0) for r in rows)
        return listing(rows, 200, total=fmt_money(total), currency=cur)

    @mcp.tool(name="deal_contacts", annotations={"title": "Who is on a deal", **RO})
    @answers
    async def deal_contacts(params: GetDealIn) -> str:
        """Who is on a deal and in what role (decision maker, champion …), the primary first."""
        did = await deal_id(q, params.deal)
        rows = await q("SELECT contact_id, contact_name, role, is_primary FROM mcp_deal_contacts WHERE deal_id = $1 ORDER BY is_primary DESC, added_at", did)
        return listing(rows, 200)

    @mcp.tool(name="deals_closed", annotations={"title": "What was won and lost", **RO})
    @answers
    async def deals_closed(params: ClosedIn) -> str:
        """What was won and lost in a period: the deal rows with when it closed and the lost reason."""
        d1, d2 = span(params.from_, params.to)
        w = Where().raw("d.status IN ('won', 'lost')").add("COALESCE(d.won_at, d.lost_at)::date BETWEEN {} AND {}", d1, d2)
        if params.status:
            w.add("d.status = {}", params.status)
        await owner_filter(q, w, "d.owner_member_id", params.owner)
        rows, total = await page(q, f"SELECT {DEAL_COLS}, d.won_at, d.lost_at, d.lost_reason FROM mcp_deals d WHERE {w.sql()}", w.args, lim(params.limit), off(params.offset), "ORDER BY COALESCE(won_at, lost_at) DESC, deal_id DESC")
        return listing(rows, params.limit, total)

    @mcp.tool(name="lost_reasons_summary", annotations={"title": "Why we lost", **RO})
    @answers
    async def lost_reasons_summary(params: LostIn) -> str:
        """Why we lost, by reason — and by seller when asked: deals lost and their value."""
        d1, d2 = span(params.from_, params.to)
        rows = await q("SELECT lost_reason_id, label, member_id, display_name, deals, lost_value FROM crm_lost_reasons_summary($1, $2, $3) ORDER BY deals DESC, label", d1, d2, params.by_seller)
        return listing(rows, 500)

    @mcp.tool(name="leaderboard", annotations={"title": "Quota attainment", **RO})
    @answers
    async def leaderboard(params: LeaderboardIn) -> str:
        """Who is ahead and behind on quota in a month: won value and count, quota, attainment, weighted open pipeline and activities done. A manager's (the right to see the team's reports)."""
        if not await has_right(q, "reports.team"):
            raise managers_only("The leaderboard (it needs the right to see the team's reports)")
        month = month_start(params.month, "month") or this_month()[0]
        team = None if params.team in (None, "") else await department_id(q, params.team)
        rows = await q("SELECT member_id, display_name, won_value, won_count, quota, attainment, open_weighted, activities_done FROM crm_leaderboard($1, $2) ORDER BY won_value DESC, display_name", month, team)
        return listing(rows, 500, month=month.isoformat())

    @mcp.tool(name="stage_requirements_check", annotations={"title": "May this deal enter that stage", **RO})
    @answers
    async def stage_requirements_check(params: StageCheckIn) -> str:
        """May this deal enter that stage — and if not, which fields are missing."""
        did = await deal_id(q, params.deal)
        pl = (await q("SELECT pipeline_id FROM mcp_deals WHERE deal_id = $1", did))[0]["pipeline_id"]
        from crm_common import stage_id
        sid = await stage_id(q, params.stage, pl)
        missing = (await q("SELECT crm_stage_requirements_check($1, $2) AS m", did, sid))[0]["m"] or []
        return detail(missing=list(missing), may_enter=not missing, deal_id=did, stage_id=sid)

    @mcp.tool(name="products_pipeline", annotations={"title": "Which products are on open deals", **RO})
    @answers
    async def products_pipeline(params: ProductsIn) -> str:
        """Which products are on open deals, with the number of deals, quantity, total and weighted total."""
        pid = await pipe(params.pipeline)
        rows = await q("SELECT name, provider_app, provider_id, deals, quantity, total, weighted FROM crm_products_pipeline($1) ORDER BY total DESC NULLS LAST, name", pid)
        from crm_common import fmt_money
        for r in rows:
            r["total"] = fmt_money(r["total"]) if r["total"] is not None else None
        return listing(rows, 500, pipeline_id=pid)

    @mcp.tool(name="saved_views", annotations={"title": "Saved views", **RO})
    @answers
    async def saved_views(params: SavedViewsIn) -> str:
        """My saved views and the shared ones (name, scope, record kind, filters, sort, columns, layout). `q` alone resolves a view by name."""
        w = Where()
        if params.q:
            w.add("name ILIKE {}", like(params.q))
        if params.record_kind:
            w.add("record_kind = {}", params.record_kind)
        if params.layout:
            w.add("layout = {}", params.layout)
        rows, total = await page(q, f"SELECT saved_view_id, name, scope, record_kind, pipeline_id, filters, sort_by, sort_dir, columns, layout, is_system FROM mcp_saved_views WHERE {w.sql()}", w.args,
                                 lim(params.limit), off(params.offset), "ORDER BY is_system DESC, name, saved_view_id")
        if resolving(params):
            rows = exact_first(rows, params.q, "name")
        return listing(rows, params.limit, total)

    @mcp.tool(name="quotas", annotations={"title": "Quotas", **RO})
    @answers
    async def quotas(params: QuotasIn) -> str:
        """The quotas by seller and month. Your own; the team's with the right to see the team's reports."""
        w = Where()
        if params.member not in (None, ""):
            w.add("member_id = {}", await member_ref(q, params.member))
        a, b = month_start(params.from_month, "from_month"), month_start(params.to_month, "to_month")
        if a:
            w.add("month >= {}", a)
        if b:
            w.add("month <= {}", b)
        rows, total = await page(q, f"SELECT member_id, month, amount FROM mcp_quotas WHERE {w.sql()}", w.args, 200, 0, "ORDER BY month, member_id")
        return listing(rows, 200, total)
