"""Shared DB helpers for Sales CRM's read MCP servers (copied from the kernel, mcp-and-api.md §1).

Each server connects with its own read-only role (sales_crm_records_ro / sales_crm_activity_ro),
which can touch nothing but the mcp_* views and the mcp_resolve_token() function. A bearer
token is resolved to (member_id, role); every query runs with app.member_id / app.role set
so the views scope to that member — exactly what the member sees in the UI.
"""
from __future__ import annotations

import contextvars
import datetime
import decimal
import hashlib
import hmac
import os
import time
from pathlib import Path

import json

import asyncpg

ENV_PATH = Path(__file__).resolve().parent.parent / "config" / ".env"

# Per-request auth context, set by the bearer-auth middleware, read by tools.
request_member_id: contextvars.ContextVar[int | None] = contextvars.ContextVar("member_id", default=None)
request_role: contextvars.ContextVar[str] = contextvars.ContextVar("role", default="anon")
# True while the kernel itself is calling (a kernel token): only app_roles is listed or callable.
request_is_kernel: contextvars.ContextVar[bool] = contextvars.ContextVar("is_kernel", default=False)
# The agent run a request belongs to, when its bearer is an agent RUN token (else None).
request_run_id: contextvars.ContextVar[int | None] = contextvars.ContextVar("run_id", default=None)
# The raw bearer the caller presented (a run token is shown to the kernel's run-facts call).
request_token: contextvars.ContextVar[str] = contextvars.ContextVar("token", default="")


_ENV_KEYS = ("DB_HOST", "DB_PORT", "DB_NAME", "DB_USER", "DB_PASSWORD", "MCP_RECORDS_DB_USER", "MCP_RECORDS_DB_PASSWORD",
             "MCP_ACTIVITY_DB_USER", "MCP_ACTIVITY_DB_PASSWORD", "ACTION_TOKEN_KEY", "ACTIONS_RELAY_KEY", "APP_KEY",
             "OS_INTERNAL_URL", "OS_APPLICATION_TOKEN", "MALUDB_API_URL", "MALUDB_API_TOKEN", "MCP_RECORDS_PORT", "MCP_ACTIVITY_PORT")


def load_env() -> dict[str, str]:
    env: dict[str, str] = {}
    if ENV_PATH.exists():
        for line in ENV_PATH.read_text().splitlines():
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, v = line.split("=", 1)
            v = v.strip()
            if len(v) >= 2 and v[0] in "\"'" and v[-1] == v[0]:
                v = v[1:-1]
            env[k.strip()] = v
    # A real environment variable wins over the file (as in app/bootstrap.php) — how the proofs run without a config/.env.
    for k in _ENV_KEYS:
        if os.environ.get(k):
            env[k] = os.environ[k]
    return env


ENV = load_env()


_pools: dict[str, asyncpg.Pool] = {}


async def get_pool(user: str, password: str) -> asyncpg.Pool:
    """Cached pool per role user (shared by the auth middleware and the tools)."""
    if user not in _pools:
        _pools[user] = await make_pool(user, password)
    return _pools[user]


async def _init_connection(con: asyncpg.Connection) -> None:
    """Decode json/jsonb into Python objects.

    Without this, a jsonb column (an address, a policy's parameters, a tool's nested
    people/deals block) reaches the caller as an escaped JSON string inside JSON, which an
    agent then has to unescape and parse by hand. Decoding here means every tool on every
    server returns one clean object.
    """
    for type_name in ("json", "jsonb"):
        await con.set_type_codec(type_name, encoder=json.dumps, decoder=json.loads, schema="pg_catalog")


async def make_pool(user: str, password: str) -> asyncpg.Pool:
    return await asyncpg.create_pool(
        host=ENV.get("DB_HOST", "127.0.0.1"),
        port=int(ENV.get("DB_PORT", "5432")),
        database=ENV.get("DB_NAME", "sales_crm"),
        user=user,
        password=password,
        min_size=1,
        max_size=8,
        command_timeout=15,
        init=_init_connection,
    )


def hash_token(raw: str) -> str:
    return hashlib.sha256(raw.encode()).hexdigest()


def _action_key() -> bytes | None:
    key = ENV.get("ACTION_TOKEN_KEY", "")
    return key.encode() if key else None      # an unset key must never verify anything


def verify_action_token(token: str) -> int | None:
    """The member a signed token speaks for, or None. Two shapes share ACTION_TOKEN_KEY:

      '{mid}.{exp}.{hmac}'        the assistant's action token (PHP mint_action_token), signed over
                                  "mid.exp" — 600 s, acts for the person at the keyboard;
      '{mid}.{exp}.{run}.{hmac}'  an agent RUN token, minted only by the agent runner, signed over
                                  "run:mid.exp.run" — lives as long as the run, and ties every
                                  call to one agent_runs row. See verify_run_token().
    """
    parts = token.split(".")
    if len(parts) == 4:
        verified = verify_run_token(token)
        return verified[0] if verified else None
    if len(parts) != 3:
        return None
    mid, exp, sig = parts
    key = _action_key()
    if key is None or not (mid.isdigit() and exp.isdigit()):
        return None
    expected = hmac.new(key, f"{mid}.{exp}".encode(), hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, sig) or time.time() > int(exp):
        return None
    return int(mid)


def verify_run_token(token: str) -> tuple[int, int] | None:
    """(member_id, agent_run_id) for a valid agent run token, else None. The "run:" prefix keeps
    the two shapes' signatures in separate domains."""
    parts = token.split(".")
    if len(parts) != 4:
        return None
    mid, exp, run, sig = parts
    key = _action_key()
    if key is None or not (mid.isdigit() and exp.isdigit() and run.isdigit()):
        return None
    expected = hmac.new(key, f"run:{mid}.{exp}.{run}".encode(), hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, sig) or time.time() > int(exp):
        return None
    return int(mid), int(run)


def verify_kernel_token(token: str) -> bool:
    """The kernel itself calling (kernel db/145): 'kernel.{exp}.{app_key}.{nonce}.{hmac}' signed over
    "kernel:exp.app_key.nonce" with ACTION_TOKEN_KEY, 60 s, bound to this application. It admits to
    app_roles and nothing else (server_common)."""
    parts = token.split(".")
    if len(parts) != 5 or parts[0] != "kernel":
        return False
    _, exp, app, nonce, sig = parts
    key = _action_key()
    if key is None or not exp.isdigit() or app != ENV.get("APP_KEY", "sales_crm") or len(nonce) != 32:
        return False
    expected = hmac.new(key, f"kernel:{exp}.{app}.{nonce}".encode(), hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, sig) and time.time() <= int(exp)


def relay_signature(token: str) -> str | None:
    """What the actions server adds when it relays a run token to PHP. The agent holds the token
    but never ACTIONS_RELAY_KEY, so it cannot POST to a handler itself and step around its grants."""
    key = ENV.get("ACTIONS_RELAY_KEY", "")
    return hmac.new(key.encode(), token.encode(), hashlib.sha256).hexdigest() if key else None


async def resolve_token(pool: asyncpg.Pool, raw_token: str) -> tuple[int, str] | None:
    """Resolve a bearer token to (member_id, role). A personal `mcp_` token resolves through
    mcp_resolve_token(); the tenant's signed action or run token (ACTION_TOKEN_KEY) names a member
    the mirror must already know and admit — an unknown id is refused, never created."""
    if not raw_token:
        return None
    async with pool.acquire() as con:
        row = await con.fetchrow("SELECT member_id, member_role FROM mcp_resolve_token($1, 'mcp')", hash_token(raw_token))
    if row is not None:
        return int(row["member_id"]), str(row["member_role"])
    mid = verify_action_token(raw_token)
    if mid is not None:
        # The mirror must already know AND admit the member (an unknown id is refused, never created). mcp_member_kind() answers only
        # for an active member with a capability — an agent the directory has not admitted yet falls to the servers' first-contact path.
        async with pool.acquire() as con:
            kind = await con.fetchval("SELECT mcp_member_kind($1)", mid)
        if kind:
            return mid, str(kind)
    return None


def _json_default(o):
    if isinstance(o, (datetime.date, datetime.datetime)):
        return o.isoformat()
    if isinstance(o, datetime.timedelta):
        return o.days
    if isinstance(o, decimal.Decimal):
        return float(o)
    return str(o)


def to_json(rows) -> str:
    return json.dumps(rows, default=_json_default, ensure_ascii=False)


async def fetch_scoped(pool: asyncpg.Pool, sql: str, *params, member_id: int | None = None, role: str | None = None) -> list[dict]:
    """Run a read query with the RLS request context set from the current member."""
    mid = member_id if member_id is not None else request_member_id.get()
    rol = role if role is not None else request_role.get()
    async with pool.acquire() as con:
        async with con.transaction():
            await con.execute(
                "SELECT set_config('app.member_id', $1, true), set_config('app.role', $2, true)",
                "" if mid is None else str(mid), rol or "anon",
            )
            rows = await con.fetch(sql, *params)
            return [dict(r) for r in rows]


SEARCH_DENY = ("set_config", "current_setting", "mcp_admit_agent", "mcp_resolve_token", "crm_resolve_key", "crm_rate_ok", "crm_key_rotate", "storage_path", "token_hash", "body_html", "crm_mailbox_secret", "pg_", "dblink", "lo_import", "lo_export")


async def run_search(pool: asyncpg.Pool, sql: str, row_cap: int = 200) -> str:
    """Guarded arbitrary read: a single SELECT, statement_timeout + row cap, RLS-scoped.
    Safe by construction — the read role can only see the mcp_* views."""
    stripped = sql.strip().rstrip(";").strip()
    low = stripped.lower()
    if not (low.startswith("select") or low.startswith("with")):
        return json.dumps({"error": "Only a single SELECT (or WITH ... SELECT) is allowed."})
    if ";" in stripped:
        return json.dumps({"error": "Only one statement is allowed."})
    forbidden = (" insert ", " update ", " delete ", " drop ", " alter ", " grant ", " create ", " copy ")
    padded = f" {low} "
    if any(f in padded for f in forbidden):
        return json.dumps({"error": "Only read queries are allowed."})
    # The read role can call a few functions from a SELECT. None of these is a way to read or to act: set_config could
    # impersonate another member for the views, the others are the kernel's door, an admission, a token lookup.
    for banned in SEARCH_DENY:
        if banned in low:
            return json.dumps({"error": f"'{banned}' is not available in records_search / activity_search — use the views and the named tools."})
    mid = request_member_id.get()
    rol = request_role.get()
    async with pool.acquire() as con:
        async with con.transaction():
            await con.execute("SET LOCAL statement_timeout = '5s'")
            await con.execute(
                "SELECT set_config('app.member_id', $1, true), set_config('app.role', $2, true)",
                "" if mid is None else str(mid), rol or "anon",
            )
            try:
                rows = await con.fetch(f"SELECT * FROM ({stripped}) _q LIMIT {row_cap}")
            except asyncpg.PostgresError as e:
                return json.dumps({"error": f"Query failed: {e!s}"})
            return to_json([dict(r) for r in rows])
