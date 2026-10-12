"""sales_crm_records_mcp — Sales CRM's record-memory server (PostgreSQL, the mcp_* views and the crm_* read functions, read-only).

Tool surface: docs/sales-crm-mcp-tool-surface.md. Every tool reads as the asking member, so a person or an agent sees what the screens show them: the views and the SECURITY DEFINER functions decide the rows
(crm_wall() per row, once per statement for the caller's facts); the server only resolves names through the same views, trims, and chooses the error. An agent sees only the tools the kernel granted it
(server_common). The kernel's own token reaches `app_roles` and the five K7 shares (accounts_index, contacts_index, deals_won, deals_open_by_account, account_timeline_summary — the kernel's alone) and
nothing else. Writes are never here: they are the kernel's Actions MCP, built from mcp/action_registry.json. This server writes NOTHING — a share is answered by the application's loopback bridge (crm_bridge),
which logs `share.read`; the two K7 reads this application consumes go the same way.
"""
from __future__ import annotations

from mcp.server.fastmcp import FastMCP

import db
import server_common
from crm_common import RO, answers, dumps

mcp = FastMCP("sales_crm_records_mcp")


async def _pool():
    return await db.get_pool(db.ENV["MCP_RECORDS_DB_USER"], db.ENV["MCP_RECORDS_DB_PASSWORD"])


async def q(sql: str, *args) -> list[dict]:
    return await db.fetch_scoped(await _pool(), sql, *args, timeout_ms=8000)


@mcp.tool(name="app_roles", annotations={"title": "Sales CRM's roles and rights", **RO})
@answers
async def app_roles() -> str:
    """The roles Sales CRM offers and the rights each gives — what the Business OS kernel reads to let a super-admin grant them (schema os.app-roles/1): Viewer, Sales Rep (user), Marketing, Sales Manager and CRM admin,
    with their rights. The catalogue is not about anyone, so the kernel's own token may read it; so may any person or agent."""
    pool = await _pool()
    async with pool.acquire() as con:
        rights = await con.fetch("SELECT right_key, description FROM crm_rights ORDER BY sort_order, right_key")
        roles = await con.fetch("SELECT role_key, name, description, capability, is_admin, sort_order, rights FROM mcp_app_roles ORDER BY sort_order")
    return dumps({
        "schema": "os.app-roles/1",
        "application": "sales_crm",
        "rights": [{"key": r["right_key"], "description": r["description"]} for r in rights],
        "roles": [{"key": r["role_key"], "name": r["name"], "description": r["description"], "capability": r["capability"], "is_admin": r["is_admin"], "rights": list(r["rights"])} for r in roles],
    })


import crm_accounts  # noqa: E402
import crm_activities  # noqa: E402
import crm_business  # noqa: E402
import crm_deals  # noqa: E402
import crm_email  # noqa: E402
import crm_leads  # noqa: E402
import crm_shares  # noqa: E402

for module in (crm_leads, crm_accounts, crm_deals, crm_activities, crm_email, crm_business, crm_shares):
    module.register(mcp, q)


if __name__ == "__main__":
    server_common.run(mcp, "MCP_RECORDS_DB_USER", "MCP_RECORDS_DB_PASSWORD", port=int(db.ENV["MCP_RECORDS_PORT"]), endpoint_name="Records MCP")
