"""Email (Q11, Q29–Q31; tool surface "Email"): find_emails, get_email, emails_unmatched, proposed_leads, find_templates, mailbox_health. The rows are mcp_emails' (the inbound and the outbound union of db/021): an email
is visible if its contact is, a private one only to its sender and a manager, and its BODY is NULL for anyone who may not read it — the view decides, this tool only cuts and chooses the error. No tool returns
the HTML, an address of the logging mailbox's secret, or a draft that is not the caller's. The body is cut at 20,000 characters in a list and refused over 1 MB for one email (`too_large`)."""
from __future__ import annotations

import re

from pydantic import Field

from crm_common import (RO, Ref, Refusal, ListIn, Where, _Base, account_id, answers, contact_id, deal_id, describe_models, detail, exact_first, has_right, like, lim, listing, managers_only, not_found, off, page, resolving, when)

BODY_LIST = 20000
BODY_ONE = 1_000_000
TEMPLATE_FIELD = re.compile(r"\{\{\s*([a-z0-9_.]+)\s*\}\}", re.I)


class FindEmailsIn(_Base):
    contact: Ref | None = Field(None, description="Emails to or from this contact (id, name or email)")
    deal: Ref | None = Field(None, description="Emails on this deal (id, number or title)")
    account: Ref | None = Field(None, description="Emails with anyone at this account (id, name or domain)")
    since: str | None = Field(None, description="Only emails at or after this day or time")
    direction: str | None = Field(None, pattern=r"^(in|out)$", description="in (received) or out (sent)")
    with_body: bool = Field(False, description="true: include the text of each email you may read (cut at 20,000 characters)")
    limit: int = Field(25, ge=1, le=100, description="How many emails at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many emails (paging)")


class GetEmailIn(_Base):
    email: int = Field(..., ge=1, description="The email (id from find_emails)")
    direction: str = Field(..., pattern=r"^(in|out)$", description="in (received) or out (sent) — ids are numbered separately")


class EmailsUnmatchedIn(_Base):
    status: str | None = Field(None, pattern=r"^(unmatched|proposed_lead)$", description="unmatched, or proposed_lead")
    limit: int = Field(25, ge=1, le=100, description="How many emails at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many emails (paging)")


class ProposedLeadsIn(_Base):
    limit: int = Field(25, ge=1, le=100, description="How many leads at most")


class FindTemplatesIn(ListIn):
    kind: str | None = Field(None, max_length=40, description="The template's kind")
    mine: bool | None = Field(None, description="true: only my own templates")


describe_models(globals())


def register(mcp, q, bridge=None) -> None:
    @mcp.tool(name="find_emails", annotations={"title": "Find emails", **RO})
    @answers
    async def find_emails(params: FindEmailsIn) -> str:
        """The emails on a contact, a deal or an account, or since a time: direction, when, from, to, subject, who sent it — and the text only for an email you may read (a private email is its sender's and a manager's). Newest first, with `total`."""
        w = Where()
        if params.contact not in (None, ""):
            w.add("{} = ANY (e.matched_contact_ids)", await contact_id(q, params.contact))
        if params.deal not in (None, ""):
            w.add("e.deal_id = {}", await deal_id(q, params.deal))
        if params.account not in (None, ""):
            w.add("e.matched_contact_ids && COALESCE((SELECT array_agg(c.contact_id) FROM mcp_contacts c WHERE c.account_id = {}), '{{}}'::bigint[])", await account_id(q, params.account))
        if params.since:
            w.add("e.at >= {}", when(params.since, "since"))
        if params.direction:
            w.add("e.direction = {}", params.direction)
        body = f", left(e.body_text, {BODY_LIST}) AS body_text, length(e.body_text) > {BODY_LIST} AS body_cut" if params.with_body else ""
        rows, total = await page(q, f"SELECT e.direction, e.email_id, e.at, e.from_address, e.from_name, e.to_addresses, e.subject{body}, e.sender_member_id, e.deal_id, e.matched_contact_ids AS contact_ids, e.is_private "
                                    f"FROM mcp_emails e WHERE {w.sql()}", w.args, lim(params.limit), off(params.offset), "ORDER BY at DESC, email_id DESC")
        return listing(rows, params.limit, total)

    @mcp.tool(name="get_email", annotations={"title": "One email in full", **RO})
    @answers
    async def get_email(params: GetEmailIn) -> str:
        """One email in full — the text when you may read it (an email over 1 MB is `too_large`), the files on it and the activities it made."""
        rows = await q("SELECT e.*, length(e.body_text) AS body_bytes FROM mcp_emails e WHERE e.direction = $1 AND e.email_id = $2", params.direction, params.email)
        if not rows:
            raise not_found("email")
        row = rows[0]
        if (row.pop("body_bytes", None) or 0) > BODY_ONE:
            raise Refusal("too_large", "That email's text is over 1 MB; ask find_emails for the subject and open it in the application.")
        kind = "inbound_email" if params.direction == "in" else "outbound_email"
        files = await q("SELECT attachment_id, filename, mime_type, byte_size FROM mcp_attachments WHERE record_type = $1 AND record_id = $2 ORDER BY attachment_id", kind, params.email)
        col = "inbound_email_id" if params.direction == "in" else "outbound_email_id"
        acts = await q(f"SELECT activity_id, contact_id FROM mcp_activities WHERE {col} = $1 ORDER BY activity_id", params.email)
        return detail(email=row, attachments=files, activities=acts)

    @mcp.tool(name="emails_unmatched", annotations={"title": "Emails that matched no one", **RO})
    @answers
    async def emails_unmatched(params: EmailsUnmatchedIn) -> str:
        """Emails the logging mailbox could not match to anyone, and the ones from strangers it proposed as leads: when, from, subject and status. Needs the right to write leads."""
        if not await has_right(q, "leads.write"):
            raise managers_only("The unmatched emails (they need the right to write leads)")
        w = Where().raw("e.direction = 'in' AND e.match_status IN ('unmatched', 'proposed_lead')")
        if params.status:
            w.add("e.match_status = {}", params.status)
        rows, total = await page(q, f"SELECT e.email_id, e.at, e.from_address, e.from_name, e.subject, e.match_status FROM mcp_emails e WHERE {w.sql()}", w.args, lim(params.limit), off(params.offset), "ORDER BY at DESC, email_id DESC")
        return listing(rows, params.limit, total)

    @mcp.tool(name="proposed_leads", annotations={"title": "Leads the mailbox and the form proposed", **RO})
    @answers
    async def proposed_leads(params: ProposedLeadsIn) -> str:
        """The leads the logging mailbox and the web form proposed, waiting in the inbox for a person to accept them. Needs the right to write leads."""
        if not await has_right(q, "leads.write"):
            raise managers_only("The proposed leads (they need the right to write leads)")
        from crm_leads import LEAD_COLS
        rows, total = await page(q, f"SELECT {LEAD_COLS}, (SELECT e.email_id FROM mcp_emails e WHERE e.direction = 'in' AND e.match_status = 'proposed_lead' AND lower(e.from_address) = lower(l.email) ORDER BY e.at DESC LIMIT 1) AS proposed_from_email_id "
                                    f"FROM mcp_leads l WHERE l.is_proposed AND l.status IN ('new', 'working')", [], lim(params.limit), 0, "ORDER BY created_at DESC, lead_id DESC")
        return listing(rows, params.limit, total)

    @mcp.tool(name="find_templates", annotations={"title": "Email templates", **RO})
    @answers
    async def find_templates(params: FindTemplatesIn) -> str:
        """The email templates I may use — the shared ones and mine — with their subject, body and the merge fields in them. `q` alone resolves a template by name."""
        from crm_common import me
        w = Where().raw("archived_at IS NULL")
        if params.q:
            w.add("name ILIKE {}", like(params.q))
        if params.kind:
            w.add("kind = {}", params.kind)
        if params.mine:
            w.add("owner_member_id = {}", await me(q))
        rows, total = await page(q, f"SELECT template_id, name, subject, body, kind, is_shared, owner_member_id, usage_count FROM mcp_email_templates WHERE {w.sql()}", w.args, lim(params.limit), off(params.offset), "ORDER BY name, template_id")
        for r in rows:
            r["merge_fields"] = sorted({m.lower() for m in TEMPLATE_FIELD.findall(f"{r.get('subject') or ''} {r.get('body') or ''}")})
        if resolving(params):
            rows = exact_first(rows, params.q, "name")
        return listing(rows, params.limit, total)

    @mcp.tool(name="mailbox_health", annotations={"title": "Is the logging mailbox alive", **RO})
    @answers
    async def mailbox_health() -> str:
        """Is the logging mailbox alive: its address, when it was last polled and last heard from, the last failure, how many messages arrived in 7 days and how many wait to be matched. Needs the right to manage the mailbox."""
        if not await has_right(q, "mailbox.manage"):
            raise managers_only("The mailbox's health (it needs the right to manage the mailbox)")
        rows = await q("SELECT mailbox_id, address, provider, poll_enabled, last_polled_at, last_webhook_at, last_error, last_error_at, active, received_7d, pending FROM mcp_mailboxes ORDER BY mailbox_id")
        return listing(rows, 20)
