"""The Python side of the bridge (tool surface "The bridge"; agents-k7-api.md): how a read tool reaches what only PHP can do — the K7 reads this application CONSUMES (an account's Help Desk tickets and its ledger
customer: PHP holds OS_APPLICATION_TOKEN and calls the kernel) and the five shares it PROVIDES (PHP logs `share.read`). The call is the loopback `POST /internal/bridge.php` on the INTERNAL port, signed
`X-CRM-Bridge: {unix ts}.{hex hmac_sha256(ACTIONS_RELAY_KEY, "crm-bridge:" + ts + "." + sha256(body))}`; PHP refuses unless the request is loopback, within 30 s, the signature verifies and — for a read as a
member — the caller is an admitted member. This server never holds a model key, a mail key or a token of the kernel's: it holds ACTIONS_RELAY_KEY (the key the Actions MCP already relays with) and nothing more.
`bridge_unavailable` is an ANSWER (retry true), never an exception."""
from __future__ import annotations

import hashlib
import hmac
import json
import time

import httpx

import db
from crm_common import Refusal

TIMEOUT = {"k7_read": 30.0, "share": 20.0}


def sign(body: bytes, now: int | None = None) -> str:
    key = db.ENV.get("ACTIONS_RELAY_KEY", "")
    ts = str(now if now is not None else int(time.time()))
    return ts + "." + hmac.new(key.encode(), ("crm-bridge:" + ts + "." + hashlib.sha256(body).hexdigest()).encode(), hashlib.sha256).hexdigest()


async def call(op: str, args: dict, *, caller: int | None = None, door: str = "mcp", run_id: int | None = None, is_eval: bool = False, request_id: str | None = None,
               consumer: str | None = None, consumer_agent: int | None = None) -> dict:
    """One bridge call. Answers PHP's JSON as a dict; a transport failure or a malformed answer is a Refusal('bridge_unavailable')."""
    port = db.ENV.get("APP_INTERNAL_PORT", "")
    if not port or not db.ENV.get("ACTIONS_RELAY_KEY"):
        raise Refusal("bridge_unavailable", "The bridge to the application is not configured on this server.", retry=False)
    body: dict = {"op": op, "args": args}
    if caller is not None:
        body["caller"] = {"kind": "member", "member_id": int(caller)}
        body["door"] = door
        if run_id is not None:
            body["run_id"] = int(run_id)
        body["is_eval"] = bool(is_eval)
    if request_id:
        body["request_id"] = request_id
    if consumer is not None:
        body["consumer"] = consumer
        if consumer_agent is not None:
            body["consumer_agent"] = str(consumer_agent)
    raw = json.dumps(body, separators=(",", ":"), ensure_ascii=False).encode()
    try:
        async with httpx.AsyncClient(timeout=TIMEOUT["share" if op.startswith("share_") else "k7_read"]) as client:
            r = await client.post(f"http://127.0.0.1:{port}/internal/bridge.php", content=raw, headers={"Content-Type": "application/json", "X-CRM-Bridge": sign(raw), "Accept": "application/json"})
    except (httpx.HTTPError, OSError):
        raise Refusal("bridge_unavailable", "The application did not answer; try again in a moment.", retry=True) from None
    try:
        out = r.json()
    except ValueError:
        raise Refusal("bridge_unavailable", "The application's answer was not readable.", retry=True) from None
    if not isinstance(out, dict):
        raise Refusal("bridge_unavailable", "The application's answer was not readable.", retry=True)
    return out


async def as_member(op: str, args: dict) -> dict:
    """A bridge call on behalf of the asking member (their token's person, or the agent behind the run token): the door and the run come from the kernel's run-facts (server_common.run_context)."""
    import server_common
    member = db.request_member_id.get()
    if member is None:
        raise Refusal("not_found", "No member is asking.")
    ctx = await server_common.run_context()
    return await call(op, args, caller=member, door=ctx["door"], run_id=ctx["run_id"], is_eval=ctx["is_eval"], request_id=ctx["request_id"])


async def as_share(op: str, args: dict) -> dict:
    """A bridge call for a K7 share: the consumer and its agent are the headers the kernel set (K26), never an argument."""
    return await call(op, args, request_id=db.request_kernel_request_id.get(), consumer=db.request_consumer.get(), consumer_agent=db.request_consumer_agent.get())
