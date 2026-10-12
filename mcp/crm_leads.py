"""Leads and prospecting (Q1–Q6; tool surface "Leads and prospecting"): find_leads, get_lead, leads_inbox, leads_untouched, source_performance, find_duplicates, find_imports, get_import, import_rows,
find_lists, list_members, segment_preview. Every row is the walled view's (mcp_leads, mcp_imports, mcp_lists …) or a SECURITY DEFINER read function's, as the asking member. The smart-list filters are the
`find_*` vocabulary (app/features/lists/filters.php): list_members and segment_preview run a record kind's own finder with a stored filter object (`filters_to_params`)."""
from __future__ import annotations

import json

from pydantic import Field

from crm_common import (RO, Ref, Refusal, ListIn, Where, _Base, answers, campaign_id, day, describe_models, detail, exact_first, has_right, is_manager, is_member, lead_id, like, lim, list_id, listing, off, owner_filter,
                        page, resolving, source_id, span, not_found, managers_only, one_by_id, ago, dumps)

LEAD_COLS = """lead_id, label, first_name, last_name, company, email, phone, status, score, source, owner_member_id, last_touched_at, next_activity_at, next_colour, is_proposed, created_at"""
LEAD_SORTS = {"created": "created_at DESC, lead_id DESC", "touched": "last_touched_at DESC NULLS LAST, lead_id DESC", "score": "score DESC NULLS LAST, lead_id DESC"}


class FindLeadsIn(ListIn):
    status: list[str] | None = Field(None, description="Only these statuses: new, working, qualified, unqualified")
    owner: Ref | None = Field(None, description="A member (id, name or me), or none for the unowned")
    source: Ref | None = Field(None, description="A lead source (id or name)")
    campaign: Ref | None = Field(None, description="A campaign (id or name)")
    untouched_days: int | None = Field(None, ge=1, le=3650, description="Open leads nobody has touched for this many days")
    tag: str | None = Field(None, max_length=60, description="A tag's label")
    proposed: bool | None = Field(None, description="true: only the leads the form and the mailbox proposed (the inbox)")
    sort: str | None = Field(None, description="created (newest first, the default), touched or score")


async def leads_query(q, p: FindLeadsIn, extra: str = "") -> tuple[list[dict], int | None]:
    w = Where()
    if p.q:
        w.add("(label ILIKE {0} OR company ILIKE {0} OR email ILIKE {0})", like(p.q))
    if p.status:
        w.add("status = ANY({}::text[])", list(p.status))
    await owner_filter(q, w, "owner_member_id", p.owner)
    if p.source not in (None, ""):
        w.add("source_id = {}", await source_id(q, p.source))
    if p.campaign not in (None, ""):
        w.add("campaign_id = {}", await campaign_id(q, p.campaign))
    if p.untouched_days:
        w.add("status IN ('new', 'working') AND COALESCE(last_touched_at, created_at) < {}", ago(p.untouched_days))
    if p.tag:
        w.add("EXISTS (SELECT 1 FROM unnest(tags) t WHERE lower(t) = lower({}))", p.tag)
    if p.proposed is not None:
        w.raw("is_proposed" if p.proposed else "NOT is_proposed")
    if extra:
        w.raw(extra)
    order = "ORDER BY " + LEAD_SORTS.get((p.sort or "created").lower(), LEAD_SORTS["created"])
    return await page(q, f"SELECT {LEAD_COLS} FROM mcp_leads WHERE {w.sql()}", w.args, lim(p.limit), off(p.offset), order)


class GetLeadIn(_Base):
    lead: Ref = Field(..., description="A lead (id, name or email)")


class LeadsInboxIn(_Base):
    limit: int = Field(25, ge=1, le=100, description="How many leads at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many leads (paging)")


class UntouchedLeadsIn(_Base):
    days: int | None = Field(None, ge=1, le=3650, description="Untouched for this many days; omitted: the business's setting")
    owner: Ref | None = Field(None, description="A member (id, name or me), or none for the unowned")


class WindowIn(_Base):
    from_: str = Field(..., alias="from", description="First day, YYYY-MM-DD")
    to: str = Field(..., description="Last day, YYYY-MM-DD")


class FindDuplicatesIn(_Base):
    kind: str = Field(..., pattern=r"^(lead|account|contact)$", description="lead, account or contact")
    limit: int = Field(50, ge=1, le=200, description="How many pairs at most (200 is the cap)")


class FindImportsIn(_Base):
    kind: str | None = Field(None, max_length=40, description="What the import made: leads_csv, contacts_csv, accounts_csv or deals_csv")
    status: str | None = Field(None, max_length=40, description="The import's status: queued, running, done, failed or rolled_back")
    limit: int = Field(25, ge=1, le=100, description="How many imports at most")


class GetImportIn(_Base):
    import_: int = Field(..., alias="import", ge=1, description="The import (id)")


class ImportRowsIn(_Base):
    import_: int = Field(..., alias="import", ge=1, description="The import (id)")
    result: str | None = Field(None, pattern=r"^(created|updated|skipped|refused)$", description="Only rows with this result: created, updated, skipped or refused")
    limit: int = Field(100, ge=1, le=500, description="How many rows at most (500 is the cap)")
    offset: int = Field(0, ge=0, le=100000, description="Skip this many rows (paging)")


class FindListsIn(ListIn):
    kind: str | None = Field(None, pattern=r"^(static|smart)$", description="static or smart")
    record_kind: str | None = Field(None, pattern=r"^(lead|contact|account|deal)$", description="Which records the list holds: lead, contact, account or deal")


class ListMembersIn(_Base):
    list: Ref = Field(..., description="A list (id or name)")
    limit: int = Field(25, ge=1, le=100, description="How many members at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many members (paging)")


class SegmentPreviewIn(_Base):
    record_kind: str = Field(..., pattern=r"^(lead|contact|account|deal)$", description="lead, contact, account or deal")
    filters: dict[str, str | int | float | bool | list[str | int] | None] = Field(default_factory=dict, description="The find_* vocabulary of that kind, e.g. {\"owner\": \"me\", \"status\": \"new\", \"untouched_days\": 30}")
    limit: int = Field(10, ge=1, le=50, description="How many sample rows at most (50 is the cap)")


describe_models(globals())


def filters_to_params(model, filters: dict, drop: tuple[str, ...] = ()):
    """A stored smart-list filter object (scalars, the screens' keys) as a finder's parameters: a scalar for a list-typed field becomes a one-element list, the saved views' older spellings are
    folded, 'all' status means no filter, an unknown key is ignored."""
    alias = {"source_id": "source", "campaign_id": "campaign", "pipeline_id": "pipeline", "stage_id": "stage", "account_id": "account", "contact_id": "contact", "tags": "tag"}
    out: dict = {}
    for k, v in (filters or {}).items():
        k = alias.get(k, k)
        if k in drop or k not in model.model_fields or v in (None, "", [], False):
            continue
        if isinstance(v, list) and k not in ("status", "stage", "kind", "lifecycle") and v:
            v = v[0]
        ann = str(model.model_fields[k].annotation)
        if "list[" in ann and not isinstance(v, list):
            v = [v]
        if k == "status" and (v == ["all"] or v == "all"):
            continue
        if "bool" in ann and isinstance(v, (str, int)):
            v = str(v).lower() in ("1", "true", "yes", "on")
        if k in ("untouched_days", "min_value", "max_value") and isinstance(v, str):
            try:
                v = float(v) if k != "untouched_days" else int(v)
            except ValueError:
                continue
        out[k] = v
    return model.model_validate(out)


def register(mcp, q, bridge=None) -> None:
    @mcp.tool(name="find_leads", annotations={"title": "Find leads", **RO})
    @answers
    async def find_leads(params: FindLeadsIn) -> str:
        """Which leads: by status, owner, source, campaign, untouched days, tag, text. `q` alone resolves a lead by name, company or email. Newest first unless `sort`. Returns lead_id, label, name, company, email, phone, status, score, source, owner, last touch, next activity and its colour, whether it is a proposed lead, and `total`. Answers 'which leads came in this week', 'who owns the Acme lead', 'leads nobody has touched in a month'."""
        rows, total = await leads_query(q, params)
        if resolving(params):
            rows = exact_first(rows, params.q, "label", "email")
        return listing(rows, params.limit, total)

    @mcp.tool(name="get_lead", annotations={"title": "One lead in full", **RO})
    @answers
    async def get_lead(params: GetLeadIn) -> str:
        """One lead in full: its fields and score, the records it became (account, contact, deal), the possible duplicates waiting on it, how many notes it has and its tags."""
        lid = await lead_id(q, params.lead)
        lead = (await q("SELECT * FROM mcp_leads WHERE lead_id = $1", lid))[0]
        dup = await q("SELECT CASE WHEN a_id = $1 THEN b_id ELSE a_id END AS other_id, matched_on FROM crm_duplicates_open('lead', 200) WHERE a_id = $1 OR b_id = $1", lid)
        notes = await q("SELECT count(*) AS n FROM mcp_notes WHERE record_type = 'lead' AND record_id = $1", lid)
        return detail(lead=lead, converted={"account_id": lead["converted_account_id"], "contact_id": lead["converted_contact_id"], "deal_id": lead["converted_deal_id"]},
                      duplicates=dup, notes_count=notes[0]["n"], tags=lead["tags"])

    @mcp.tool(name="leads_inbox", annotations={"title": "The leads inbox", **RO})
    @answers
    async def leads_inbox(params: LeadsInboxIn) -> str:
        """What waits in the inbox: leads with no owner and the ones the web form and the logging mailbox proposed. Needs the right to write leads."""
        if not await has_right(q, "leads.write"):
            raise managers_only("The leads inbox (it needs the right to write leads)")
        lim_ = lim(params.limit)
        rows, total = await page(q, f"SELECT {LEAD_COLS}, CASE WHEN NOT is_proposed THEN NULL WHEN EXISTS (SELECT 1 FROM mcp_emails e WHERE e.match_status = 'proposed_lead' AND lower(e.from_address) = lower(mcp_leads.email)) THEN 'mailbox' ELSE 'form' END AS proposed_from FROM mcp_leads WHERE (is_proposed OR owner_member_id IS NULL) AND status IN ('new', 'working')",
                                 [], lim_, off(params.offset), "ORDER BY created_at DESC, lead_id DESC")
        return listing(rows, lim_, total)

    @mcp.tool(name="leads_untouched", annotations={"title": "Leads nobody touched", **RO})
    @answers
    async def leads_untouched(params: UntouchedLeadsIn) -> str:
        """Leads nobody has touched for N days (the morning note's read): lead_id, label, status, owner, source and how many days. `days` defaults to the business's setting."""
        owner = None
        if params.owner not in (None, "") and str(params.owner).lower() != "none":
            from crm_common import member_ref
            owner = await member_ref(q, params.owner)
        rows = await q("SELECT lead_id, label, status, owner_member_id, source, days FROM crm_leads_untouched($1, $2) ORDER BY days DESC, lead_id LIMIT 101", params.days, owner)
        return listing(rows, 100)

    @mcp.tool(name="source_performance", annotations={"title": "Which sources convert", **RO})
    @answers
    async def source_performance(params: WindowIn) -> str:
        """Which lead sources bring leads that convert and win, between two days: leads, converted, unqualified, deals, won, won value and the conversion rate per source."""
        d1, d2 = span(params.from_, params.to)
        rows = await q("SELECT source_id, source_name, leads, converted, unqualified, deals, won, won_value, conversion_rate FROM crm_source_performance($1, $2) ORDER BY leads DESC, source_id", d1, d2)
        return listing(rows, 200)

    @mcp.tool(name="find_duplicates", annotations={"title": "Possible duplicates", **RO})
    @answers
    async def find_duplicates(params: FindDuplicatesIn) -> str:
        """Possible duplicate pairs of a kind (lead, account or contact) with what matched and how alike they are — a dismissed pair stays dismissed. The Assistant's Monday read; the manager's merge screen."""
        kind = params.kind
        view, idc, lab = {"lead": ("mcp_leads", "lead_id", "label"), "account": ("mcp_accounts", "account_id", "name"), "contact": ("mcp_contacts", "contact_id", "name")}[kind]
        rows = await q(f"SELECT d.a_id, d.b_id, a.{lab} AS a_label, b.{lab} AS b_label, d.matched_on, d.similarity FROM crm_duplicates_open($1, $2) d "
                       f"JOIN {view} a ON a.{idc} = d.a_id JOIN {view} b ON b.{idc} = d.b_id ORDER BY d.similarity DESC, d.a_id LIMIT {lim(params.limit, 50, 200) + 1}", kind, lim(params.limit, 50, 200))
        return listing(rows, lim(params.limit, 50, 200))

    @mcp.tool(name="find_imports", annotations={"title": "Imports", **RO})
    @answers
    async def find_imports(params: FindImportsIn) -> str:
        """My imports (every import for a manager): kind, file, status, the counts, whether it was a dry run and when its rollback window ends."""
        w = Where()
        if params.kind:
            w.add("kind = {}", params.kind)
        if params.status:
            w.add("status = {}", params.status)
        rows, total = await page(q, f"SELECT import_id, kind, file_name, status, row_count, rows_created, rows_updated, rows_skipped, rows_refused, dry_run, rollback_until, created_by, created_at FROM mcp_imports WHERE {w.sql()}",
                                 w.args, lim(params.limit), 0, "ORDER BY created_at DESC, import_id DESC")
        return listing(rows, lim(params.limit), total)

    @mcp.tool(name="get_import", annotations={"title": "One import", **RO})
    @answers
    async def get_import(params: GetImportIn) -> str:
        """One import with its column mapping and its log: kind, file, status, the counts, the rollback window. Only if you may see the import (your own, or a shared one, or a manager)."""
        row = await one_by_id(q, "import", "mcp_imports", "import_id", params.import_)
        return detail(**{"import": row, "mapping": row.get("mapping"), "log": row.get("log")})

    @mcp.tool(name="import_rows", annotations={"title": "An import's rows", **RO})
    @answers
    async def import_rows(params: ImportRowsIn) -> str:
        """Every row's result of an import: row number, created / updated / skipped / refused, the record made and the message. Only if you may see the import."""
        await one_by_id(q, "import", "mcp_imports", "import_id", params.import_)
        w = Where().add("import_id = {}", params.import_)
        if params.result:
            w.add("result = {}", params.result)
        rows, total = await page(q, f"SELECT row_number, result, record_id, message FROM mcp_import_rows WHERE {w.sql()}", w.args, lim(params.limit, 100, 500), off(params.offset), "ORDER BY row_number")
        return listing(rows, lim(params.limit, 100, 500), total)

    @mcp.tool(name="find_lists", annotations={"title": "Lists", **RO})
    @answers
    async def find_lists(params: FindListsIn) -> str:
        """My lists and the shared ones: name, static or smart, which records, the filters of a smart one, member count. `q` alone resolves a list by name."""
        w = Where()
        if params.q:
            w.add("name ILIKE {}", like(params.q))
        if params.kind:
            w.add("kind = {}", params.kind)
        if params.record_kind:
            w.add("record_kind = {}", params.record_kind)
        rows, total = await page(q, f"SELECT list_id, name, kind, record_kind, filters, is_shared, owner_member_id, member_count, counted_at FROM mcp_lists WHERE {w.sql()}", w.args,
                                 lim(params.limit), off(params.offset), "ORDER BY name, list_id")
        if resolving(params):
            rows = exact_first(rows, params.q, "name")
        return listing(rows, params.limit, total)

    @mcp.tool(name="list_members", annotations={"title": "Who is in a list", **RO})
    @answers
    async def list_members(params: ListMembersIn) -> str:
        """Who is in a static list, or the live members of a smart one — the rows of the list's record kind (leads, contacts, accounts or deals), with `total`."""
        lid = await list_id(q, params.list)
        lst = (await q("SELECT * FROM mcp_lists WHERE list_id = $1", lid))[0]
        kind, filters = lst["record_kind"], lst["filters"] or {}
        if isinstance(filters, str):
            filters = json.loads(filters)
        limit, offset = lim(params.limit), off(params.offset)
        if lst["kind"] == "smart":
            rows, total = await kind_find(q, kind, filters, limit, offset)
        else:
            rows, total = await kind_find(q, kind, {}, limit, offset, in_list=lid)
        return listing(rows, limit, total, list_id=lid, record_kind=kind)

    @mcp.tool(name="segment_preview", annotations={"title": "Preview a segment", **RO})
    @answers
    async def segment_preview(params: SegmentPreviewIn) -> str:
        """How many records, and a sample of who, a filter object would match before a list is saved. The filters are the find_* vocabulary of the record kind."""
        if not await is_member(q):
            raise not_found("segment")
        rows, total = await kind_find(q, params.record_kind, params.filters, lim(params.limit, 10, 50), 0)
        return dumps({"ok": True, "count": total or 0, "sample": rows[: lim(params.limit, 10, 50)]})


async def kind_find(q, kind: str, filters: dict, limit: int, offset: int, in_list: int | None = None):
    """One page of a record kind's own finder with a stored filter object; `in_list` narrows to a static list's members (through mcp_list_members)."""
    if kind == "lead":
        p = filters_to_params(FindLeadsIn, filters)
        p.limit, p.offset = limit, offset
        extra = f"lead_id IN (SELECT record_id FROM mcp_list_members WHERE list_id = {int(in_list)})" if in_list else ""
        return await leads_query(q, p, extra)
    from crm_accounts import accounts_query, contacts_query, FindAccountsIn, FindContactsIn
    from crm_deals import deals_query, FindDealsIn
    if kind == "account":
        p = filters_to_params(FindAccountsIn, filters)
        p.limit, p.offset = limit, offset
        return await accounts_query(q, p, f"account_id IN (SELECT record_id FROM mcp_list_members WHERE list_id = {int(in_list)})" if in_list else "")
    if kind == "contact":
        p = filters_to_params(FindContactsIn, filters)
        p.limit, p.offset = limit, offset
        return await contacts_query(q, p, f"contact_id IN (SELECT record_id FROM mcp_list_members WHERE list_id = {int(in_list)})" if in_list else "")
    p = filters_to_params(FindDealsIn, filters)
    p.limit, p.offset = limit, offset
    return await deals_query(q, p, f"deal_id IN (SELECT record_id FROM mcp_list_members WHERE list_id = {int(in_list)})" if in_list else "")
