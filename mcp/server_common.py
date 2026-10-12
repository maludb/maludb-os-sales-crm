"""Shared runner for Sales CRM's read MCP servers: bearer-auth ASGI middleware, the kernel's run-facts
gate for agents, and the uvicorn launch (mcp-and-api.md §1, agents.md).

A person's token sees every tool (row visibility does the scoping); an API key (scope `api`, mcp_resolve_token(…, 'mcp') never admits one) is refused, keys are for the records API alone. An AGENT presents the
tenant's run token; Sales CRM cannot read the kernel's grants, so it asks the kernel's run-facts call once per run (cached 300 s) and offers exactly the tools the
kernel names for THIS endpoint. A token the kernel does not vouch for lists and calls nothing — fail closed. An evaluation run may read (these servers never write). The kernel's own token reaches app_roles and the five K7 shares — nothing else — and the consumer comes from its headers
(K26), never an argument. docs/shareholder-intelligence-mcp-tool-surface.md "Who may call".
"""
from __future__ import annotations

import json
import logging
import re
import time
from typing import Any

import httpx
import uvicorn
from mcp.server.fastmcp import FastMCP
from mcp.server.fastmcp.exceptions import ToolError
from mcp.types import TextContent
from pydantic import ValidationError

import db

log = logging.getLogger("crm_mcp")

# What the kernel's own token may reach: the roles catalogue (kernel db/145) and the five tools shares[] declares (K7) — nothing else. The five shares are the KERNEL's
# alone (design §8, D13): a person or an agent never lists or calls them — they read the same facts through the tools of their own role.
SHARES = {"accounts_index", "contacts_index", "deals_won", "deals_open_by_account", "account_timeline_summary"}
KERNEL_TOOLS = {"app_roles"} | SHARES
KERNEL_ONLY: set[str] = set(SHARES)
# What the kernel strips from a share's arguments (K26); we drop them too, so a stray one can never reach a model that forbids extras.
CONSUMER_ARGS = {"consumer", "consumer_agent_id", "as_agent"}

# run id -> (facts, fetched_at). A run's grants do not change while it runs; a short TTL bounds a mistake.
_facts_cache: dict[int, tuple[dict, float]] = {}
_FACTS_TTL = 300


async def run_facts(token: str) -> dict | None:
    """The kernel's word on a token: {valid, is_agent, member_id, run_id, request_id, is_eval, endpoints:[{name, tools:{}}]}.
    None when the kernel cannot be reached — the caller fails closed."""
    verified = db.verify_run_token(token)
    run_id = verified[1] if verified else None
    if run_id is not None and run_id in _facts_cache and time.time() - _facts_cache[run_id][1] < _FACTS_TTL:
        return _facts_cache[run_id][0]
    base = db.ENV.get("OS_INTERNAL_URL", "http://127.0.0.1:8080").rstrip("/")
    app_token = db.ENV.get("OS_APPLICATION_TOKEN", "")
    if not app_token:
        log.error("OS_APPLICATION_TOKEN is not configured; agents are refused")
        return None
    try:
        async with httpx.AsyncClient(timeout=10) as client:
            r = await client.post(f"{base}/api/v1/runs/facts.php", json={"token": token},
                                  headers={"Authorization": f"Bearer {app_token}", "Accept": "application/json"})
        if r.status_code != 200:
            log.warning("run-facts answered %s", r.status_code)
            return None
        facts = r.json()
    except Exception as exc:  # noqa: BLE001
        log.warning("run-facts unreachable: %s", exc)
        return None
    if run_id is not None and facts.get("valid"):
        _facts_cache[run_id] = (facts, time.time())
    return facts


def invalid_argument(ex: ValidationError):
    """A parameter that failed validation is an ANSWER (the surface: errors are answers, `fields` names the parameter), never an exception — and never an echo of what was sent."""
    fields: list[str] = []
    for e in ex.errors():
        loc = ".".join(str(p) for p in e.get("loc", ()) if p != "params")
        if loc and loc not in fields:
            fields.append(loc)
    body = {"ok": False, "error": {"code": "invalid_argument", "message": "A parameter failed validation: " + (", ".join(fields) or "the arguments") + ". Check the tool's schema.", "retry": False, "fields": fields}}
    text = json.dumps(body)
    return [TextContent(type="text", text=text)], {"result": text}          # what FastMCP returns for a tool that answers a string: the content and the structured result


def install_grants(mcp: FastMCP, endpoint_name: str) -> None:
    """Replace list_tools / call_tool so an agent sees and calls only what the kernel granted here."""

    async def granted() -> dict | None:
        """None = a person (no filtering). A dict = the agent's granted tools -> constraints."""
        token = db.request_token.get()
        if not token or db.verify_run_token(token) is None:
            return None if db.request_member_id.get() is not None else {}
        facts = await run_facts(token)
        if not facts or not facts.get("valid"):
            return {}
        if not facts.get("is_agent"):
            return None
        for ep in facts.get("endpoints") or []:
            if ep.get("name") == endpoint_name:
                return dict(ep.get("tools") or {})
        return {}

    async def invoke(name: str, arguments: dict[str, Any]):
        try:
            return await mcp.call_tool(name, arguments)
        except ToolError as ex:
            if isinstance(ex.__cause__, ValidationError):
                return invalid_argument(ex.__cause__)
            raise

    async def list_tools():
        tools = await mcp.list_tools()
        if db.request_is_kernel.get():
            return [t for t in tools if t.name in KERNEL_TOOLS]
        g = await granted()
        tools = [t for t in tools if t.name not in KERNEL_ONLY]
        return tools if g is None else [t for t in tools if t.name in g]

    async def call_tool(name: str, arguments: dict[str, Any]):
        if db.request_is_kernel.get():
            if name not in KERNEL_TOOLS:
                raise ToolError(f"The kernel's token reaches {', '.join(sorted(KERNEL_TOOLS))} only.")
            if name != "app_roles" and "params" not in arguments:
                # the kernel sends a share's arguments flat (issuer, since, forms, filing …); the tool takes one `params` object
                arguments = {"params": {k: v for k, v in arguments.items() if k not in CONSUMER_ARGS and k != "scope_id"}}
            return await invoke(name, arguments)
        if name in KERNEL_ONLY:
            raise ToolError(f"'{name}' is for the Business OS kernel's own token only.")
        g = await granted()
        if g is not None and name not in g:
            raise ToolError(f"'{name}' is not among the tools this agent was granted on {endpoint_name}.")
        return await invoke(name, arguments)

    mcp._mcp_server.list_tools()(list_tools)
    mcp._mcp_server.call_tool(validate_input=False)(call_tool)


def make_app(mcp: FastMCP, db_user_key: str, db_pw_key: str, endpoint_name: str):
    user = db.ENV[db_user_key]
    password = db.ENV[db_pw_key]
    install_grants(mcp, endpoint_name)
    inner = mcp.streamable_http_app()

    async def app(scope, receive, send):
        if scope["type"] != "http":
            return await inner(scope, receive, send)
        headers = {k.decode().lower(): v.decode() for k, v in scope.get("headers", [])}
        auth = headers.get("authorization", "")
        token = auth[7:].strip() if auth[:7].lower() == "bearer " else ""
        pool = await db.get_pool(user, password)
        if token and db.verify_kernel_token(token):
            # The kernel itself (kernel db/145): no member, no rows — app_roles and the five shares only.
            db.request_is_kernel.set(True)
            db.request_member_id.set(None)
            db.request_role.set("anon")
            db.request_token.set(token)
            db.request_run_id.set(None)
            # K26: the kernel says which application asks (and as which expert agent), in two headers trusted with the token; and the request it is answering.
            consumer = headers.get("x-os-consumer", "").strip()
            agent = headers.get("x-os-consumer-agent", "").strip()
            request_id = headers.get("x-request-id", "").strip()
            db.request_consumer.set(consumer if re.fullmatch(r"[a-z0-9_]{1,60}", consumer) else None)
            db.request_consumer_agent.set(int(agent) if agent.isdigit() else None)
            db.request_kernel_request_id.set(request_id[:80] if re.fullmatch(r"[A-Za-z0-9._:-]{1,80}", request_id) else None)
            return await inner(scope, receive, send)
        db.request_is_kernel.set(False)
        db.request_consumer.set(None)
        db.request_consumer_agent.set(None)
        db.request_kernel_request_id.set(None)
        ctx = await db.resolve_token(pool, token) if token else None      # mcp_resolve_token(…, 'mcp'): a key (scope api) never resolves here
        if ctx is None and token and db.verify_run_token(token) is not None:
            # An agent's first contact: the mirror has the agent but no admission yet. The kernel's
            # run-facts call decides; a vouched agent with endpoints here is admitted (db/011) and
            # resolved again. Anything the kernel does not vouch for stays refused.
            facts = await run_facts(token)
            if facts and facts.get("valid") and facts.get("is_agent") and facts.get("endpoints"):
                async with pool.acquire() as con:
                    await con.fetchval("SELECT mcp_admit_agent($1)", int(facts["member_id"]))
                ctx = await db.resolve_token(pool, token)
        if ctx is None:
            body = json.dumps({"error": "unauthorized: provide a valid MCP access token as a Bearer token"}).encode()
            await send({"type": "http.response.start", "status": 401,
                        "headers": [(b"content-type", b"application/json"), (b"www-authenticate", b"Bearer")]})
            await send({"type": "http.response.body", "body": body})
            return
        db.request_member_id.set(ctx[0])
        db.request_role.set(ctx[1])
        db.request_token.set(token)
        run = db.verify_run_token(token)
        db.request_run_id.set(run[1] if run else None)
        await inner(scope, receive, send)

    return app


def run(mcp: FastMCP, db_user_key: str, db_pw_key: str, port: int, endpoint_name: str) -> None:
    uvicorn.run(make_app(mcp, db_user_key, db_pw_key, endpoint_name), host="127.0.0.1", port=port, log_level="info")


async def run_context() -> dict:
    """What a tool needs to know about the run behind this call, from the kernel's run-facts (cached per run): {run_id, request_id, is_eval, door}.
    A person's own token has no run: door 'mcp'. A run token the kernel does not vouch for is already refused at the gate; here it reads as an eval-less 'agent'."""
    token = db.request_token.get()
    verified = db.verify_run_token(token) if token else None
    if verified is None:
        return {"run_id": None, "request_id": None, "is_eval": False, "door": "mcp"}
    facts = await run_facts(token) or {}
    return {"run_id": verified[1], "request_id": facts.get("request_id"), "is_eval": bool(facts.get("is_eval")), "door": "agent"}
