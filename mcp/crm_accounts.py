"""Accounts and contacts (Q7–Q13; tool surface "Accounts and contacts"): find_accounts, get_account, account_contacts, account_deals, account_tickets, accounts_untouched, find_contacts, get_contact,
contacts_untouched, upcoming_dates, find_notes, find_attachments. The rows are the walled views' (mcp_accounts, mcp_contacts …); the two K7 reads this application consumes — the account's Help Desk tickets
and its ledger customer — go through the bridge (PHP holds the application's token and judges the wall on the account first). A restricted account or contact is, for someone who may not see it, the
same `not_found` as one that does not exist."""
from __future__ import annotations

from pydantic import Field

import crm_bridge
from crm_common import (RO, Ref, Refusal, ListIn, Where, _Base, account_id, answers, ago, contact_id, cut, describe_models, detail, exact_first, like, lim, listing, member_ref, not_found, off, owner_filter, page,
                        record_ref, resolving, dumps, RECORD_KINDS)

ACCOUNT_COLS = """a.account_id, a.name, a.domain, a.kind, a.industry, a.city, a.country, a.owner_member_id, a.last_touched_at, a.next_activity_at, a.next_colour, a.open_deals_count, a.open_deals_value,
                  a.won_deals_value, a.contacts_count, a.tags"""
ACCOUNT_SORTS = {"name": "name, account_id", "touched": "last_touched_at DESC NULLS LAST, account_id", "open_value": "open_deals_value DESC NULLS LAST, account_id", "won_value": "won_deals_value DESC NULLS LAST, account_id"}
CONTACT_COLS = """c.contact_id, c.account_id, c.account_name, c.name, c.title, c.email, c.phone, c.mobile, c.role, c.is_primary, c.lifecycle, c.email_opt_out, c.sms_opt_out, c.owner_member_id,
                  c.last_touched_at, c.next_activity_at, c.next_colour, c.open_deals_count, c.tags"""
CONTACT_SORTS = {"name": "name, contact_id", "touched": "last_touched_at DESC NULLS LAST, contact_id", "created": "created_at DESC, contact_id DESC"}


class FindAccountsIn(ListIn):
    kind: list[str] | None = Field(None, description="Only these kinds: prospect, customer, partner, former, other")
    owner: Ref | None = Field(None, description="A member (id, name or me), or none for the unowned")
    industry: str | None = Field(None, max_length=80, description="An industry (a part of its name)")
    country: str | None = Field(None, max_length=2, description="A two-letter country code, US")
    tag: str | None = Field(None, max_length=60, description="A tag's label")
    untouched_days: int | None = Field(None, ge=1, le=3650, description="Accounts nobody has touched for this many days")
    has_open_deals: bool | None = Field(None, description="true: only accounts with an open deal; false: only those without")
    sort: str | None = Field(None, description="name (the default), touched, open_value or won_value")


async def accounts_query(q, p: FindAccountsIn, extra: str = "") -> tuple[list[dict], int | None]:
    w = Where()
    if p.q:
        w.add("(a.name ILIKE {0} OR a.domain ILIKE {0} OR a.legal_name ILIKE {0})", like(p.q))
    if p.kind:
        w.add("a.kind = ANY({}::text[])", list(p.kind))
    await owner_filter(q, w, "a.owner_member_id", p.owner)
    if p.industry:
        w.add("a.industry ILIKE {}", like(p.industry))
    if p.country:
        w.add("upper(a.country) = upper({})", p.country)
    if p.tag:
        w.add("EXISTS (SELECT 1 FROM unnest(a.tags) t WHERE lower(t) = lower({}))", p.tag)
    if p.untouched_days:
        w.add("COALESCE(a.last_touched_at, a.created_at) < {}", ago(p.untouched_days))
    if p.has_open_deals is not None:
        w.raw("a.open_deals_count > 0" if p.has_open_deals else "a.open_deals_count = 0")
    if extra:
        w.raw(extra)
    order = "ORDER BY " + ACCOUNT_SORTS.get((p.sort or "name").lower(), ACCOUNT_SORTS["name"])
    return await page(q, f"SELECT {ACCOUNT_COLS} FROM mcp_accounts a WHERE {w.sql()}", w.args, lim(p.limit), off(p.offset), order)


class FindContactsIn(ListIn):
    account: Ref | None = Field(None, description="An account (id, name or domain)")
    lifecycle: list[str] | None = Field(None, description="Only these lifecycle stages: lead, prospect, customer, churned")
    owner: Ref | None = Field(None, description="A member (id, name or me), or none for the unowned")
    role: str | None = Field(None, max_length=40, description="Their role: decision_maker, champion, influencer, user, finance, procurement, other")
    opt_out: str | None = Field(None, pattern=r"^(email|sms|any)$", description="Only people who opted out of email, sms or either")
    tag: str | None = Field(None, max_length=60, description="A tag's label")
    untouched_days: int | None = Field(None, ge=1, le=3650, description="People nobody has touched for this many days")
    sort: str | None = Field(None, description="name (the default), touched or created")


async def contacts_query(q, p: FindContactsIn, extra: str = "") -> tuple[list[dict], int | None]:
    w = Where()
    if p.q:
        w.add("(c.name ILIKE {0} OR c.email ILIKE {0} OR c.account_name ILIKE {0} OR EXISTS (SELECT 1 FROM mcp_contact_emails e WHERE e.contact_id = c.contact_id AND e.address ILIKE {0}))", like(p.q))
    if p.account not in (None, ""):
        w.add("c.account_id = {}", await account_id(q, p.account))
    if p.lifecycle:
        w.add("c.lifecycle = ANY({}::text[])", list(p.lifecycle))
    await owner_filter(q, w, "c.owner_member_id", p.owner)
    if p.role:
        w.add("c.role = {}", p.role)
    if p.opt_out == "email":
        w.raw("c.email_opt_out")
    elif p.opt_out == "sms":
        w.raw("c.sms_opt_out")
    elif p.opt_out == "any":
        w.raw("(c.email_opt_out OR c.sms_opt_out)")
    if p.tag:
        w.add("EXISTS (SELECT 1 FROM unnest(c.tags) t WHERE lower(t) = lower({}))", p.tag)
    if p.untouched_days:
        w.add("COALESCE(c.last_touched_at, c.created_at) < {}", ago(p.untouched_days))
    if extra:
        w.raw(extra)
    order = "ORDER BY " + CONTACT_SORTS.get((p.sort or "name").lower(), CONTACT_SORTS["name"])
    return await page(q, f"SELECT {CONTACT_COLS} FROM mcp_contacts c WHERE {w.sql()}", w.args, lim(p.limit), off(p.offset), order)


class AccountRefIn(_Base):
    account: Ref = Field(..., description="An account (id, name or domain)")


class GetAccountIn(AccountRefIn):
    with_ledger: bool = Field(False, description="true: also ask the general ledger for this account's customer and balance (needs the approved connection)")


class AccountListIn(AccountRefIn):
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class AccountDealsIn(AccountListIn):
    status: list[str] | None = Field(None, description="Only these statuses: open, won, lost")


class UntouchedIn(_Base):
    days: int | None = Field(None, ge=1, le=3650, description="Untouched for this many days; omitted: the business's setting")
    owner: Ref | None = Field(None, description="A member (id, name or me), or none for the unowned")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class GetContactIn(_Base):
    contact: Ref = Field(..., description="A contact (id, name or email)")


class UpcomingIn(_Base):
    days: int = Field(30, ge=1, le=366, description="How many days ahead")
    owner: Ref | None = Field(None, description="A member (id, name or me), or none for the unowned")
    limit: int = Field(50, ge=1, le=100, description="How many rows at most")


class RecordRowsIn(_Base):
    kind: str = Field(..., pattern=r"^(lead|account|contact|deal)$", description="lead, account, contact or deal")
    record: Ref = Field(..., description="The record (id, or its name)")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class FindNotesIn(RecordRowsIn):
    full: bool = Field(False, description="true: the whole text of each note (up to 5,000 characters); otherwise the first 200")


describe_models(globals())


async def next_step(q, column: str, rid: int) -> dict | None:
    rows = await q(f"SELECT a.activity_id, a.kind, a.subject, a.at, m.display_name AS owner_name FROM mcp_activities a LEFT JOIN mcp_members m ON m.member_id = a.owner_member_id "
                   f"WHERE a.{column} = $1 AND a.done_at IS NULL ORDER BY a.at LIMIT 1", rid)
    return rows[0] if rows else None


def register(mcp, q, bridge=None) -> None:
    @mcp.tool(name="find_accounts", annotations={"title": "Find accounts", **RO})
    @answers
    async def find_accounts(params: FindAccountsIn) -> str:
        """Our accounts (companies) by kind, owner, industry, country, last touch, tag and text. `q` alone resolves an account by name or domain. Returns name, domain, kind, owner, last touch, next activity, open and won deal value, contacts count and tags, with `total`. Answers 'which customers has nobody called in 90 days', 'accounts in Texas', 'Acme's owner'."""
        rows, total = await accounts_query(q, params)
        if resolving(params):
            rows = exact_first(rows, params.q, "name", "domain")
        return listing(rows, params.limit, total)

    @mcp.tool(name="get_account", annotations={"title": "One account at a glance", **RO})
    @answers
    async def get_account(params: GetAccountIn) -> str:
        """Everything about one account at a glance: its fields, the primary contact, the next step, and — with `with_ledger` — the general ledger's customer and balance when that connection is approved (else `ledger.error` says no_connection)."""
        aid = await account_id(q, params.account)
        acc = (await q("SELECT a.*, m.display_name AS owner_name FROM mcp_accounts a LEFT JOIN mcp_members m ON m.member_id = a.owner_member_id WHERE a.account_id = $1", aid))[0]
        primary = None
        if acc.get("primary_contact_id"):
            r = await q("SELECT contact_id, name, email, title FROM mcp_contacts WHERE contact_id = $1", acc["primary_contact_id"])
            primary = r[0] if r else None
        out = {"account": acc, "primary_contact": primary, "next_step": await next_step(q, "account_id", aid)}
        if params.with_ledger:
            out["ledger"] = await ledger_of(aid)
        return detail(**out)

    @mcp.tool(name="account_contacts", annotations={"title": "Who we know at an account", **RO})
    @answers
    async def account_contacts(params: AccountListIn) -> str:
        """Who we know at this company, the primary contact first, with their roles."""
        aid = await account_id(q, params.account)
        p = FindContactsIn(account=aid, limit=params.limit)
        rows, total = await contacts_query(q, p)
        rows.sort(key=lambda r: (not r["is_primary"], str(r["name"]).lower()))
        return listing(rows, params.limit, total)

    @mcp.tool(name="account_deals", annotations={"title": "An account's deals", **RO})
    @answers
    async def account_deals(params: AccountDealsIn) -> str:
        """The account's deals: open first with stage and next step, then won and lost. Same rows as find_deals."""
        from crm_deals import DEAL_COLS
        aid = await account_id(q, params.account)
        w = Where().add("d.account_id = {}", aid)
        if params.status:
            w.add("d.status = ANY({}::text[])", list(params.status))
        rows, total = await page(q, f"SELECT {DEAL_COLS} FROM mcp_deals d WHERE {w.sql()}", w.args, lim(params.limit), 0,
                                 "ORDER BY (status = 'open') DESC, expected_close_date NULLS LAST, deal_id")
        return listing(rows, params.limit, total)

    @mcp.tool(name="account_tickets", annotations={"title": "An account's help desk tickets", **RO})
    @answers
    async def account_tickets(params: AccountListIn) -> str:
        """The account's open tickets in Help Desk (a K7 read over the connection a super-admin approved): number, subject, status, priority, last update and link. `no_connection` says there is no approved connection — nothing else is guessed."""
        aid = await account_id(q, params.account)
        r = await crm_bridge.as_member("k7_read", {"read": "account_tickets", "account": aid, "limit": lim(params.limit)})
        return tickets_answer(r)

    @mcp.tool(name="accounts_untouched", annotations={"title": "Accounts nobody spoke to", **RO})
    @answers
    async def accounts_untouched(params: UntouchedIn) -> str:
        """Accounts we have not spoken to in N days, customers first: name, kind, owner, last touch and days. `days` defaults to the business's setting."""
        rows = await untouched(q, "account", params)
        return listing(rows, params.limit)

    @mcp.tool(name="find_contacts", annotations={"title": "Find contacts", **RO})
    @answers
    async def find_contacts(params: FindContactsIn) -> str:
        """People by name, email (any address they have), account, lifecycle, owner, role, opt-out and tag. `q` alone resolves a contact by name or email. Returns name, title, account, email, phone, role, primary, lifecycle, opt-outs, owner, last touch, next activity, open deals and tags, with `total`. Answers 'who is the decision maker at Acme', 'who opted out of email', 'people nobody called in 60 days'."""
        rows, total = await contacts_query(q, params)
        if resolving(params):
            rows = exact_first(rows, params.q, "name", "email")
        return listing(rows, params.limit, total)

    @mcp.tool(name="get_contact", annotations={"title": "One person in full", **RO})
    @answers
    async def get_contact(params: GetContactIn) -> str:
        """One person: their fields, every address, their account, their open and closed deals with the role they play, the next step, and their opt-outs."""
        cid = await contact_id(q, params.contact)
        contact = (await q("SELECT * FROM mcp_contacts WHERE contact_id = $1", cid))[0]
        emails = await q("SELECT contact_email_id, address, is_primary, verified_at FROM mcp_contact_emails WHERE contact_id = $1 ORDER BY is_primary DESC, contact_email_id", cid)
        acc = None
        if contact.get("account_id"):
            r = await q("SELECT account_id, name, domain, kind, owner_member_id FROM mcp_accounts WHERE account_id = $1", contact["account_id"])
            acc = r[0] if r else None
        deals = await q("SELECT d.deal_id, d.number, d.title, d.stage_name, d.status, dc.role FROM mcp_deal_contacts dc JOIN mcp_deals d ON d.deal_id = dc.deal_id WHERE dc.contact_id = $1 ORDER BY (d.status = 'open') DESC, d.deal_id DESC LIMIT 50", cid)
        return detail(contact=contact, emails=emails, account=acc, deals=deals, next_step=await next_step(q, "contact_id", cid))

    @mcp.tool(name="contacts_untouched", annotations={"title": "People nobody spoke to", **RO})
    @answers
    async def contacts_untouched(params: UntouchedIn) -> str:
        """People we have not spoken to in N days: name, lifecycle, owner, last touch and days."""
        rows = await untouched(q, "contact", params)
        return listing(rows, params.limit)

    @mcp.tool(name="upcoming_dates", annotations={"title": "Birthdays and renewals", **RO})
    @answers
    async def upcoming_dates(params: UpcomingIn) -> str:
        """Birthdays and renewals coming up in the next N days (30 by default): the kind, the record, its name and the date."""
        owner = None if params.owner in (None, "") or str(params.owner).lower() == "none" else await member_ref(q, params.owner)
        rows = await q(f"SELECT kind, record_kind, record_id, name, on_date, owner_member_id FROM crm_upcoming_dates($1, $2) ORDER BY on_date, record_id LIMIT {lim(params.limit, 50) + 1}", params.days, owner)
        return listing(rows, lim(params.limit, 50))

    @mcp.tool(name="find_notes", annotations={"title": "Notes on a record", **RO})
    @answers
    async def find_notes(params: FindNotesIn) -> str:
        """The notes on a lead, account, contact or deal, newest first — the first 200 characters of each unless `full`. Only if you may see the record."""
        rid = await record_ref(q, params.kind, params.record)
        n = 5000 if params.full else 200
        rows = await q(f"SELECT note_id, member_id, left(body, {n}) AS body, length(body) > {n} AS cut, created_at FROM mcp_notes WHERE record_type = $1 AND record_id = $2 ORDER BY created_at DESC, note_id DESC LIMIT {lim(params.limit) + 1}", params.kind, rid)
        return listing(rows, lim(params.limit))

    @mcp.tool(name="find_attachments", annotations={"title": "Files on a record", **RO})
    @answers
    async def find_attachments(params: RecordRowsIn) -> str:
        """The files on a lead, account, contact or deal: name, type, size, who uploaded and when. Only if you may see the record. (The file itself is opened in the application.)"""
        rid = await record_ref(q, params.kind, params.record)
        rows = await q(f"SELECT attachment_id, filename, mime_type, byte_size, uploaded_by, created_at FROM mcp_attachments WHERE record_type = $1 AND record_id = $2 ORDER BY created_at DESC, attachment_id DESC LIMIT {lim(params.limit) + 1}", params.kind, rid)
        return listing(rows, lim(params.limit))


async def untouched(q, kind: str, p) -> list[dict]:
    owner = None if p.owner in (None, "") or str(p.owner).lower() == "none" else await member_ref(q, p.owner)
    order = "ORDER BY (kind = 'customer') DESC, days DESC, record_id" if kind == "account" else "ORDER BY days DESC, record_id"
    return await q(f"SELECT record_id, name, kind, owner_member_id, last_touched_at, days FROM crm_untouched($1, $2, $3) {order} LIMIT {lim(p.limit) + 1}", kind, p.days, owner)


def tickets_answer(r: dict) -> str:
    from crm_common import fail
    if not r.get("ok", False):
        e = r.get("error") or {}
        if e.get("code") in ("insufficient_right", "unknown_member"):
            raise Refusal("not_found", "No account you can read matches that.")
        raise Refusal("bridge_unavailable", "The application could not ask for the tickets: " + str(e.get("message", "no answer"))[:120], retry=True)
    status = r.get("status")
    if status == "not_found":
        raise not_found("account")
    if status == "no_connection":
        raise Refusal("no_connection", "Help Desk is not connected to this application: a super-admin approves the connection first.")
    if status != "ok":
        raise Refusal("bridge_unavailable", "Help Desk did not answer; try again in a moment.", retry=True)
    return dumps({"ok": True, "tickets": [{k: t.get(k) for k in ("number", "subject", "status", "priority", "updated_at", "url")} for t in (r.get("rows") or [])]})


async def ledger_of(aid: int) -> dict:
    """The ledger's customer for an account, or {error: …} saying why not — the account itself is answered either way."""
    try:
        r = await crm_bridge.as_member("k7_read", {"read": "account_ledger", "account": aid})
    except Refusal as e:
        return {"error": {"code": e.code, "message": e.message}}
    if not r.get("ok", False):
        return {"error": {"code": "bridge_unavailable", "message": "The ledger could not be asked."}}
    status = r.get("status")
    if status == "no_connection":
        return {"error": {"code": "no_connection", "message": "The general ledger is not connected to this application."}}
    if status != "ok":
        return {"error": {"code": "bridge_unavailable", "message": "The general ledger did not answer."}}
    rows = r.get("rows") or []
    return dict(rows[0]) if rows else {"customer": None}
