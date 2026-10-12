"""The kernel's own contract, run against this application (tests/phase4/registry.php): the wrapper deploy/kernel-registry-sales_crm.json + mcp/action_registry.json composed the way the installer writes
mcp/registries/<app_key>.json, loaded by the KERNEL's loader (application_actions.load_registries), every built action registered as a tool on a FastMCP exactly as the kernel's actions server does (application_actions.register),
every resolve entity answered by THIS application's live records server through the kernel's own resolver (application_actions.resolve_via_mcp), and a few whole actions driven through the kernel's tool with a stub for the two
posts (the approval hook, the application's handler). Nothing is written anywhere: the registry file goes to a temp directory, the kernel's is never touched (and no bytecode is written beside its source). Prints one JSON document of results.
Usage: registry_check.py <app dir> <records mcp url> <json: {"resolve": [[entity, value, token], ...], "drive": [[action, params, token, hook?], ...], "env": <path of the proofs' env file>}>"""
import asyncio
import json
import pathlib
import sys
import tempfile

sys.dont_write_bytecode = True
KERNEL_MCP = "/var/www/mcp"
sys.path.insert(0, KERNEL_MCP)
import application_actions as aa  # noqa: E402  (the kernel's module, read only)
from mcp.server.fastmcp import FastMCP  # noqa: E402

app_dir, records_url, plan = pathlib.Path(sys.argv[1]), sys.argv[2], json.loads(sys.argv[3])
wrapper = json.loads((app_dir / "deploy/kernel-registry-sales_crm.json").read_text())
registry = json.loads((app_dir / "mcp/action_registry.json").read_text())
env = {}
for line in pathlib.Path(plan["env"]).read_text().splitlines():
    if "=" in line and not line.startswith("#"):
        k, v = line.split("=", 1)
        env[k.strip()] = v.strip().strip('"')

composed = {"schema": "maludb-os.registry/1", "app_key": "sales_crm", "name": "Sales CRM",
            "base_url": "http://127.0.0.1:" + env["APP_INTERNAL_PORT"], "records_url": records_url, "resolve": wrapper["resolve"], "registry": registry}
out: dict = {}
tmp = pathlib.Path(tempfile.mkdtemp(prefix="crm-registry-"))
(tmp / "sales_crm.json").write_text(json.dumps(composed))
aa.REGISTRIES_DIR = tmp
regs = aa.load_registries()
out["loaded"] = [r["app_key"] for r in regs]

posts: list = []


async def app_post(path, fields, base=None):
    posts.append({"path": path, "fields": fields, "base": base})
    if path == "/approvals/hook.php":
        return {"status": hook["v"], "message": "waiting for approval" if hook["v"] == "pending_approval" else ""}
    return {"status": "success", "did": "stub"}


token_now = {"v": ""}
hook = {"v": "success"}
mcp = FastMCP("kernel-actions-sim")
count = aa.register(mcp, app_post, lambda: token_now["v"], set())
out["registered"] = count


async def main():
    tools = await mcp.list_tools()
    out["tools"] = {t.name: {"description": t.description, "schema": t.inputSchema} for t in tools}
    out["resolved"] = {}
    for entity, value, token in plan["resolve"]:
        spec = composed["resolve"][entity]
        try:
            rows = await aa.resolve_via_mcp(records_url, token, spec["tool"], {spec.get("query_param", "q"): value})
            idf, lab = spec["id_field"], spec["label_field"]
            matches = [r for r in rows if isinstance(r, dict) and r.get(idf) is not None]
            out["resolved"][entity + "|" + value] = {"value": value, "rows": len(rows), "matches": len(matches), "id": matches[0].get(idf) if matches else None, "label": matches[0].get(lab) if matches else None,
                                       "keys": sorted(matches[0].keys()) if matches else []}
        except Exception as exc:  # noqa: BLE001
            out["resolved"][entity + "|" + value] = {"error": str(exc)}
    out["driven"] = []
    for entry in plan["drive"]:
        action, params, token = entry[:3]
        hook["v"] = entry[3] if len(entry) > 3 else "success"
        token_now["v"] = token
        del posts[:]
        res = await mcp.call_tool(action, {"params": params})
        text = res[0][0].text if isinstance(res, tuple) else res[0].text
        out["driven"].append({"action": action, "answer": json.loads(text), "posts": list(posts)})


asyncio.run(main())
print(json.dumps(out, default=str))
