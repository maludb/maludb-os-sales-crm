"""Shared pieces for Sales CRM's tool modules: annotations, the input base, the where-builder, the refusals (errors are answers, not exceptions — surface "Errors"), the resolvers (a lead, an account,
a contact, a deal, a member … named by id, name or key, always through the walled mcp_* views, so a record the caller may not see does not exist for them), the list envelope the kernel's entity resolver
reads, and the wall's questions asked of the database (crm_has_right(), crm_is_manager(), crm_is_admin()). Every query runs as the asking member (db.fetch_scoped sets app.member_id from the verified
bearer): the views and the SECURITY DEFINER functions decide the rows; nothing here filters by who is asking. A tool asks `has_right()` only to choose the words of a refusal, never to filter rows.
"""
from __future__ import annotations

import datetime
import decimal
import functools
import re

import asyncpg
from pydantic import BaseModel, ConfigDict, Field

import db

RO = {"readOnlyHint": True, "openWorldHint": False}
Ref = int | str          # an id or a name / number / domain / email


class _Base(BaseModel):
    model_config = ConfigDict(str_strip_whitespace=True, extra="forbid", populate_by_name=True)


# Money is a string with two decimals (surface, conventions); these are the keys that carry it. A count, a ratio, a percent or a probability never does.
MONEY_KEYS = {"value", "weighted_value", "weighted", "open_value", "won_value", "lost_value", "budget", "unit_price", "line_total", "amount", "annual_revenue", "open_deals_value", "won_deals_value",
              "cost_per_lead", "cost_per_won", "quota", "open_weighted", "balance", "min_value", "max_value"}


def fmt_money(v) -> str:
    return f"{decimal.Decimal(str(v)).quantize(decimal.Decimal('0.01')):f}"


def _money(obj):
    if isinstance(obj, dict):
        return {k: (fmt_money(v) if k in MONEY_KEYS and isinstance(v, (int, float, decimal.Decimal)) and not isinstance(v, bool) else _money(v)) for k, v in obj.items()}
    if isinstance(obj, list):
        return [_money(x) for x in obj]
    return obj


def dumps(obj) -> str:
    return db.to_json(_money(obj))


class Refusal(Exception):
    """An answer, not a crash: the code, the sentence, whether trying again could help, and any extra keys (fields, candidates)."""

    def __init__(self, code: str, message: str, retry: bool = False, **extra) -> None:
        super().__init__(message)
        self.code, self.message, self.retry, self.extra = code, message, retry, extra


def fail(code: str, message: str, retry: bool = False, **extra) -> str:
    return dumps({"ok": False, "error": {"code": code, "message": message, "retry": retry, **extra}})


def answers(fn):
    """Turn a Refusal (and the database's refusals) into the tool's error answer; the tool never raises."""
    @functools.wraps(fn)
    async def inner(*a, **k):
        try:
            return await fn(*a, **k)
        except Refusal as r:
            return fail(r.code, r.message, r.retry, **r.extra)
        except asyncpg.NoDataFoundError:
            return fail("not_found", "No record you can read matches that.")
        except asyncpg.InsufficientPrivilegeError as ex:
            return fail("managers_only", (getattr(ex, "message", "") or "That is for a manager.")[:200])
        except asyncpg.PostgresError as ex:
            if isinstance(ex, (asyncpg.DataError, asyncpg.InvalidTextRepresentationError, asyncpg.CheckViolationError, asyncpg.RaiseError)):
                msg = getattr(ex, "message", "") or ""
                return fail("invalid_argument", (msg[:200] if isinstance(ex, asyncpg.RaiseError) else "A parameter is out of range or not of the right kind."))
            return fail("server_error", "The question could not be answered: " + ex.__class__.__name__ + ".", retry=True)
    return inner


def lim(n: int | None, default: int = 25, top: int = 100) -> int:
    return max(1, min(int(n or default), top))


def off(n: int | None) -> int:
    return max(0, min(int(n or 0), 10000))


class Where:
    """Builds `a = $1 AND b = ANY($2)` with positional parameters in order."""

    def __init__(self) -> None:
        self.parts: list[str] = []
        self.args: list = []

    def p(self, value) -> str:
        self.args.append(value)
        return f"${len(self.args)}"

    def add(self, sql: str, *values) -> "Where":
        self.parts.append(sql.format(*[self.p(v) for v in values]))
        return self

    def raw(self, sql: str) -> "Where":
        self.parts.append(sql)
        return self

    def sql(self) -> str:
        return " AND ".join(self.parts) if self.parts else "TRUE"


def like(s: str) -> str:
    return "%" + s.replace("\\", "\\\\").replace("%", "\\%").replace("_", "\\_") + "%"


def resolving(p) -> bool:
    """True when only `q` (and perhaps `limit`) was given: the kernel's entity resolver's call. It wants ONE row for an exact name or key — more than one is 'several match' and the action stops."""
    return bool(getattr(p, "q", None)) and set(p.model_fields_set) <= {"q", "limit"}


def exact_first(rows: list[dict], q: str | None, *cols: str) -> list[dict]:
    """The rows that equal q exactly (case-insensitive) in one of $cols, when there are any; else the rows as they are."""
    t = (q or "").strip().lower()
    hit = [r for r in rows if t and any(str(r.get(c) if r.get(c) is not None else "").lower() == t for c in cols)]
    return hit if hit else rows


def listing(rows: list[dict], limit: int, total: int | None = None, **more) -> str:
    """The list envelope: {ok, count, rows, total?, truncated} — the kernel's resolver reads `rows`. $rows may hold one more than $limit (the probe for `truncated`)."""
    truncated = len(rows) > limit
    rows = rows[:limit]
    out = {"ok": True, "count": len(rows), "rows": rows, "truncated": truncated}
    if total is not None:
        out["total"] = total
    return dumps({**out, **more})


def detail(**fields) -> str:
    return dumps({"ok": True, **fields})


async def page(q, sql: str, args: list, limit: int, offset: int = 0, order: str = "") -> tuple[list[dict], int | None]:
    """Run `sql` (no ORDER/LIMIT) for one page plus one probe row, with the total count of the whole answer; returns (rows, total). The caller hands both to listing()."""
    rows = await q(f"SELECT t.*, count(*) OVER () AS _total FROM ({sql}) t {order} LIMIT {int(limit) + 1} OFFSET {int(offset)}", *args)
    total = int(rows[0]["_total"]) if rows else None
    for r in rows:
        r.pop("_total", None)
    if total is None and offset == 0:
        total = 0
    return rows, total


def is_int(v) -> bool:
    return isinstance(v, int) or (isinstance(v, str) and v.strip().isdigit())


def cut(s: str | None, n: int) -> str | None:
    return None if s is None else (s if len(s) <= n else s[: n - 1].rstrip() + "…")


def day(s, what: str = "The date") -> datetime.date | None:
    """A YYYY-MM-DD date (None stays None)."""
    if s is None or s == "":
        return None
    if isinstance(s, datetime.date) and not isinstance(s, datetime.datetime):
        return s
    try:
        return datetime.date.fromisoformat(str(s).strip()[:10])
    except ValueError as ex:
        raise Refusal("invalid_argument", f"{what} is a day (2026-03-02).", fields=[what]) from ex


def when(s, what: str = "The time", end_of_day: bool = False) -> datetime.datetime | None:
    """A YYYY-MM-DD day or an ISO 8601 time as an aware datetime (None stays None); anything else is a sentence."""
    if s is None or s == "":
        return None
    if isinstance(s, datetime.datetime):
        return s if s.tzinfo else s.replace(tzinfo=datetime.timezone.utc)
    t = str(s).strip()
    try:
        if re.fullmatch(r"\d{4}-\d{2}-\d{2}", t):
            d = datetime.date.fromisoformat(t)
            return datetime.datetime.combine(d + datetime.timedelta(days=1 if end_of_day else 0), datetime.time.min, tzinfo=datetime.timezone.utc)
        v = datetime.datetime.fromisoformat(t.replace("Z", "+00:00"))
    except ValueError as ex:
        raise Refusal("invalid_argument", f"{what} is a day (2026-03-02) or an ISO time (2026-03-02T09:30:00Z).", fields=[what]) from ex
    return v if v.tzinfo else v.replace(tzinfo=datetime.timezone.utc)


def ago(days: int) -> datetime.datetime:
    return datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(days=days)


def span(a, b, what_a: str = "from", what_b: str = "to") -> tuple[datetime.date, datetime.date]:
    """A window of two days, the earlier first (a reversed one is swapped, as the screens do)."""
    d1, d2 = day(a, what_a), day(b, what_b)
    if d1 is None or d2 is None:
        raise Refusal("invalid_argument", f"Give both {what_a} and {what_b} (YYYY-MM-DD).", fields=[what_a, what_b])
    return (d2, d1) if d1 > d2 else (d1, d2)


def this_month() -> tuple[datetime.date, datetime.date]:
    t = datetime.date.today()
    first = t.replace(day=1)
    nxt = (first + datetime.timedelta(days=32)).replace(day=1)
    return first, nxt - datetime.timedelta(days=1)


async def flag(q, sql: str, *args) -> bool:
    rows = await q("SELECT " + sql + " AS ok", *args)
    return bool(rows and rows[0]["ok"])


async def me(q) -> int | None:
    rows = await q("SELECT nullif(current_setting('app.member_id', true), '')::bigint AS m")
    return rows[0]["m"] if rows else None


async def has_right(q, key: str) -> bool:
    """Whether the asking member holds a right — the SQL's own answer (crm_has_right), asked only to choose the words of a refusal."""
    return await flag(q, "crm_has_right($1)", key)


async def is_manager(q) -> bool:
    return await flag(q, "crm_is_manager()")


async def is_admin(q) -> bool:
    return await flag(q, "crm_is_admin()")


async def is_member(q) -> bool:
    return await flag(q, "crm_is_member_here()")


def candidates(rows: list[dict], id_field: str, label_field: str) -> list[dict]:
    return [{"id": r[id_field], "label": r[label_field]} for r in rows[:6]]


def ambiguous(what: str, v, rows: list[dict], id_field: str, label_field: str) -> Refusal:
    return Refusal("ambiguous", f"More than one {what} matches '{v}'; ask which (or give its id).", candidates=candidates(rows, id_field, label_field))


def not_found(what: str = "record") -> Refusal:
    return Refusal("not_found", f"No {what} you can read matches that.")


def managers_only(what: str) -> Refusal:
    return Refusal("managers_only", f"{what} is a manager's.")


# ---- resolving a name to a record, through the walled views ---------------------------------------------------------------------------------------------------

async def resolve(q, ref, what: str, view: str, id_col: str, label_col: str, exact: tuple[str, ...], fuzzy: tuple[str, ...], extra: str = "") -> int:
    """One record's id from an id or a name. Digits are an id (looked up in the view; a number that is also a name falls through to the name); else an exact match on `exact`, else ILIKE on `fuzzy`.
    Several → ambiguous with candidates; none (or one the wall hides) → not_found, in the same words."""
    if ref in (None, ""):
        raise Refusal("invalid_argument", f"Name the {what} (its id, or its name).", fields=[what.replace(" ", "_")])
    s = str(ref).strip()
    if is_int(ref):          # digits are an id and nothing else: an id the wall hides must not be read as a name that happens to contain it
        rows = await q(f"SELECT {id_col} FROM {view} WHERE {id_col} = $1 {extra}", int(s))
        if rows:
            return int(rows[0][id_col])
        raise not_found(what)
    conds = " OR ".join(f"lower({c}::text) = lower($1)" for c in exact)
    rows = await q(f"SELECT {id_col}, {label_col} FROM {view} WHERE ({conds}) {extra} ORDER BY {id_col} LIMIT 7", s)
    if not rows:
        conds = " OR ".join(f"{c}::text ILIKE $1" for c in fuzzy)
        rows = await q(f"SELECT {id_col}, {label_col} FROM {view} WHERE ({conds}) {extra} ORDER BY {label_col} LIMIT 7", like(s))
    if len(rows) > 1:
        raise ambiguous(what, s, rows, id_col, label_col)
    if rows:
        return int(rows[0][id_col])
    raise not_found(what)


async def lead_id(q, v) -> int:
    return await resolve(q, v, "lead", "mcp_leads", "lead_id", "label", ("label", "email"), ("label", "company", "email"))


async def account_id(q, v) -> int:
    return await resolve(q, v, "account", "mcp_accounts", "account_id", "name", ("name", "domain"), ("name", "domain", "legal_name"))


async def contact_id(q, v) -> int:
    return await resolve(q, v, "contact", "mcp_contacts", "contact_id", "name", ("name", "email"), ("name", "email"))


async def deal_id(q, v) -> int:
    return await resolve(q, v, "deal", "mcp_deals", "deal_id", "title", ("number", "title"), ("number", "title", "account_name"))


async def pipeline_id(q, v) -> int:
    return await resolve(q, v, "pipeline", "mcp_pipelines", "pipeline_id", "name", ("key", "name"), ("key", "name"))


async def stage_id(q, v, pipeline: int | None = None) -> int:
    return await resolve(q, v, "stage", "mcp_stages", "stage_id", "name", ("name",), ("name",), f"AND pipeline_id = {int(pipeline)}" if pipeline else "")


async def activity_id(q, v) -> int:
    return await resolve(q, v, "activity", "mcp_activities", "activity_id", "subject", ("subject",), ("subject",))


async def campaign_id(q, v) -> int:
    return await resolve(q, v, "campaign", "mcp_campaigns", "campaign_id", "name", ("name",), ("name",))


async def list_id(q, v) -> int:
    return await resolve(q, v, "list", "mcp_lists", "list_id", "name", ("name",), ("name",))


async def source_id(q, v) -> int:
    return await resolve(q, v, "source", "mcp_lead_sources", "source_id", "name", ("name",), ("name",))


async def department_id(q, v) -> int:
    return await resolve(q, v, "department", "mcp_departments", "department_id", "name", ("name",), ("name",))


async def default_pipeline(q) -> int:
    """The business's default pipeline (the settings' choice, else the first one)."""
    rows = await q("SELECT pipeline_id FROM mcp_pipelines WHERE archived_at IS NULL ORDER BY is_default DESC, position, pipeline_id LIMIT 1")
    if not rows:
        raise not_found("pipeline")
    return int(rows[0]["pipeline_id"])


async def one_by_id(q, what: str, view: str, id_col: str, v) -> dict:
    if v in (None, "") or not is_int(v):
        raise Refusal("invalid_argument", f"A {what} is named by its id.", fields=[what.replace(" ", "_")])
    rows = await q(f"SELECT * FROM {view} WHERE {id_col} = $1", int(v))
    if not rows:
        raise not_found(what)
    return rows[0]


async def member_ref(q, v) -> int:
    if v is None or v == "":
        raise Refusal("invalid_argument", "Name the member (their id, their name or me).", fields=["member"])
    if str(v).strip().lower() == "me":
        m = await me(q)
        if m is None:
            raise not_found("member")
        return int(m)
    if is_int(v):
        rows = await q("SELECT member_id FROM mcp_members WHERE member_id = $1", int(v))
        if rows:
            return int(rows[0]["member_id"])
        raise not_found("member")
    s = str(v).strip()
    rows = await q("SELECT member_id, display_name FROM mcp_members WHERE lower(display_name) = lower($1) LIMIT 7", s)
    if not rows:
        rows = await q("SELECT member_id, display_name FROM mcp_members WHERE display_name ILIKE $1 ORDER BY display_name LIMIT 7", like(s))
    if len(rows) > 1:
        raise ambiguous("member", s, rows, "member_id", "display_name")
    if rows:
        return int(rows[0]["member_id"])
    raise not_found("member")


async def owner_filter(q, w: Where, col: str, owner) -> None:
    """`owner`: a member (id, name, me) or `none` (unowned) — added to the where-builder."""
    if owner in (None, ""):
        return
    if str(owner).strip().lower() == "none":
        w.raw(f"{col} IS NULL")
    else:
        w.add(f"{col} = {{}}", await member_ref(q, owner))


async def team_id(q, team) -> int | None:
    return None if team in (None, "") else await department_id(q, team)


# ---- every field of every parameter model carries a description (the surface: "a described Field on every field") ---------------------------------------------

DESCRIBE = {
    "limit": "How many rows at most", "offset": "Skip this many rows (paging)", "q": "Words to look for (alone, also resolves one record by its name or key)",
    "from": "A day, YYYY-MM-DD", "to": "A day, YYYY-MM-DD", "since": "A day or ISO time — only what is newer", "owner": "A member (id, name or me), or none for the unowned",
    "team": "A department (id or name) — its members' records", "pipeline": "A pipeline (id, key or name); omitted: the default pipeline", "stage": "A stage (id or name)",
    "account": "An account (id, name or domain)", "contact": "A contact (id, name or email)", "deal": "A deal (id, number or title)", "lead": "A lead (id, name or email)",
    "activity": "An activity (id or subject)", "campaign": "A campaign (id or name)", "list": "A list (id or name)", "member": "A person or an agent (id, name or me)",
    "status": "The status", "kind": "The kind", "days": "A number of days", "tag": "A tag (its label or slug)", "source": "A lead source (id or name)",
    "record": "The record's id", "email": "An email (id)", "import": "An import (id)", "sort": "How to order the rows",
}


def describe_models(ns: dict) -> None:
    """Give every field without a description one (called at the foot of each tool module, before register() builds the tools)."""
    for obj in list(ns.values()):
        if isinstance(obj, type) and issubclass(obj, BaseModel) and obj.__module__ == ns["__name__"] and obj is not _Base:
            changed = False
            for name, f in obj.model_fields.items():
                if not f.description:
                    key = (f.alias or name).rstrip("_")
                    f.description = DESCRIBE.get(key) or name.replace("_", " ").capitalize()
                    changed = True
            if changed:
                obj.model_rebuild(force=True, _types_namespace=ns)


class ListIn(_Base):
    """The base of a list tool: `q`, `limit`, `offset`."""
    q: str | None = Field(None, max_length=100, description="Words to look for (alone, also resolves one record by its name or key)")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most (100 is the cap)")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many rows (paging)")


RECORD_KINDS = ("lead", "account", "contact", "deal")


async def record_ref(q, kind: str, v) -> int:
    """A record of one of the four kinds named by id or name — through its walled view, so a record the caller may not see does not exist for them."""
    if kind not in RECORD_KINDS:
        raise Refusal("invalid_argument", "The kind is lead, account, contact or deal.", fields=["kind"])
    return await {"lead": lead_id, "account": account_id, "contact": contact_id, "deal": deal_id}[kind](q, v)
