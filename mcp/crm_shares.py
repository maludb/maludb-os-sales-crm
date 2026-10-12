"""The five K7 shares (Q38–Q42; tool surface "The shares, for the kernel's token"; design §8, D13): accounts_index, contacts_index, deals_won, deals_open_by_account, account_timeline_summary. THEY ARE THE KERNEL'S
ALONE (server_common.KERNEL_ONLY): a sibling reads them through the kernel's approved connection, the kernel's token admits them and `app_roles` and nothing else, and who is asking comes from the headers the
kernel adds (K26: X-OS-Consumer, X-OS-Consumer-Agent — never an argument, which the kernel strips and server_common drops again). This server READS NOTHING itself for a share: it asks the application's loopback
bridge (op share_*), whose PHP calls the db/017 SECURITY DEFINER functions as the application — a restricted record is never shared, no email body and no note ever crosses, no member identity but an owner's
display name — and writes `share.read` ({application, tool, rows, ms}, never an answer). A call with no consumer is `consumer_unknown` and nothing is read. The documents are PHP's, passed on unchanged."""
from __future__ import annotations

from pydantic import Field

import crm_bridge
import db
from crm_common import RO, Refusal, _Base, answers, describe_models

SHARE_ONLY = "The kernel's own token reaches this share for a sibling application; a person or an agent reads the same facts through find_accounts, find_contacts, deals_closed, account_deals or timeline."


class AccountsIndexIn(_Base):
    since: str | None = Field(None, description="Only accounts changed at or after this time (ISO 8601)")
    q: str | None = Field(None, max_length=100, description="Words in the account's name or domain")
    limit: int | None = Field(None, ge=1, le=500, description="How many accounts at most (500 at most; 200 by default)")
    offset: int | None = Field(None, ge=0, le=10000000, description="Skip this many accounts (paging)")


class ContactsIndexIn(_Base):
    account: int | None = Field(None, ge=1, description="Only the people at this account (id)")
    email: str | None = Field(None, max_length=254, description="The person with this email address — Help Desk's requester match")
    since: str | None = Field(None, description="Only contacts changed at or after this time (ISO 8601)")
    limit: int | None = Field(None, ge=1, le=500, description="How many contacts at most (500 at most; 200 by default)")
    offset: int | None = Field(None, ge=0, le=10000000, description="Skip this many contacts (paging)")


class DealsWonIn(_Base):
    from_: str = Field(..., alias="from", description="First day, YYYY-MM-DD")
    to: str = Field(..., description="Last day, YYYY-MM-DD")
    limit: int | None = Field(None, ge=1, le=500, description="How many deals at most (500 at most; 200 by default)")
    offset: int | None = Field(None, ge=0, le=10000000, description="Skip this many deals (paging)")


class OpenByAccountIn(_Base):
    account: int = Field(..., ge=1, description="The account (id)")


class TimelineSummaryIn(_Base):
    account: int = Field(..., ge=1, description="The account (id)")
    limit: int | None = Field(None, ge=1, le=50, description="How many touches at most (50 at most; 10 by default)")


describe_models(globals())


def clean(args: dict) -> dict:
    return {k: v for k, v in args.items() if v is not None}


async def shared(op: str, args: dict) -> str:
    """One share through the bridge: the consumer is the kernel's header; PHP logs `share.read`; the document comes back as PHP built it."""
    if not db.request_consumer.get():
        raise Refusal("consumer_unknown", "A share is read on behalf of an application: the kernel names it in X-OS-Consumer, and none came. Nothing is shared.")
    r = await crm_bridge.as_share(op, clean(args))
    if r.get("ok") is True and isinstance(r.get("result"), dict):
        return db.to_json(r["result"])
    e = r.get("error") or {}
    code = e.get("code")
    if code in ("consumer_required",):
        raise Refusal("consumer_unknown", "A share is read on behalf of an application and none was named. Nothing is shared.")
    if code == "invalid_argument":
        raise Refusal("invalid_argument", str(e.get("message", "A parameter is not valid."))[:200])
    raise Refusal("bridge_unavailable", "The application could not answer the share; try again in a moment.", retry=True)


def register(mcp, q, bridge=None) -> None:
    @mcp.tool(name="accounts_index", annotations={"title": "Our accounts — for a sibling", **RO})
    @answers
    async def accounts_index(params: AccountsIndexIn) -> str:
        """Our accounts as a sibling needs them (os.sales-crm-accounts/1): name, legal name, domain, kind, owner's display name, the ledger customer and Help Desk organization ids, place, open and won deal value — newest change first, paged (500 at most). A restricted account is never shared. """ + SHARE_ONLY
        p = params
        return await shared("share_accounts_index", {"since": p.since, "q": p.q, "limit": p.limit, "offset": p.offset})

    @mcp.tool(name="contacts_index", annotations={"title": "Our contacts — for a sibling", **RO})
    @answers
    async def contacts_index(params: ContactsIndexIn) -> str:
        """Our contacts as a sibling needs them (os.sales-crm-contacts/1): name, title, role, every email, phones, lifecycle, opt-outs, account and owner — by account or by `email` (Help Desk's requester match), paged (500 at most). A restricted contact is never shared. """ + SHARE_ONLY
        p = params
        return await shared("share_contacts_index", {"account": p.account, "email": p.email, "since": p.since, "limit": p.limit, "offset": p.offset})

    @mcp.tool(name="deals_won", annotations={"title": "Deals won — for a sibling", **RO})
    @answers
    async def deals_won(params: DealsWonIn) -> str:
        """The deals won between two days (os.sales-crm-deals-won/1): number, title, the account with its ledger customer, the primary contact, value, won date, owner's display name, pipeline and the product lines — the ledger's customer and invoice, Inventory's order, Consultant Tracking's engagement. Paged (500 at most). A restricted deal is never shared. """ + SHARE_ONLY
        p = params
        return await shared("share_deals_won", {"from": p.from_, "to": p.to, "limit": p.limit, "offset": p.offset})

    @mcp.tool(name="deals_open_by_account", annotations={"title": "An account's open deals — for a sibling", **RO})
    @answers
    async def deals_open_by_account(params: OpenByAccountIn) -> str:
        """An account's open deals (os.sales-crm-deals-open/1): stage, probability, value, weighted value, expected close, owner's display name, next activity and its colour — a Help Desk ticket's panel, a Spaces card. A restricted or missing account answers an empty list. """ + SHARE_ONLY
        return await shared("share_deals_open_by_account", {"account": params.account})

    @mcp.tool(name="account_timeline_summary", annotations={"title": "An account's recent touches — for a sibling", **RO})
    @answers
    async def account_timeline_summary(params: TimelineSummaryIn) -> str:
        """An account's recent touches (os.sales-crm-timeline/1): when, kind, subject, who, direction and deal number, plus the next step — dates and subjects only, never a body or a note. """ + SHARE_ONLY
        return await shared("share_account_timeline_summary", {"account": params.account, "limit": params.limit})
