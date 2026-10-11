# Sales CRM — an application of the MaluDB Business OS

Prospecting and customer relationship management for a business running the [MaluDB Business OS](https://github.com/maludb/maludb-os-core):
leads worked and converted, accounts and contacts with one timeline, deals through pipelines on a kanban board that shows the sales
cycle, every call, email, meeting and task as an activity on a reminder calendar, email logged by a BCC mailbox, campaigns, lists,
imports and the reports a sales manager runs a team by. Two agents ship with it: the Sales CRM Expert (the command bar) and the
Sales Assistant (the weekday morning note and next-step proposals).

Built with the `htmx-php-builder` plugin (`maludb-os-htmx-php-guidelines`) and fitted to the kernel by `maludb-os-integration`.
Installed by the kernel's installer at `/srv/apps/sales_crm`, served as `crm.<domain>`; catalog key `sales_crm`.

**State (2026-10-11):** Phase 0 COMPLETE (schema db/001–016 proven by 173 checks, the kit by 71; `maludb-os.json`, the two agents, nine skills, `deploy/`; the installer's plan clean). Phase 1 WRITTEN the same day (72 + 7 tools, 84 screens, 121 actions, ten slice specs, 55 consistency checks) — APPROVED by the owner 2026-10-09 as written; Phase 2 the shell BUILT and proven the same day (271 checks under php -S, 276 under Apache); slice 1 accounts and contacts (the CRUD exemplar) BUILT and proven the same day (234 checks under both); slice 2 leads, import, duplicates and merge BUILT and proven 2026-10-10 (202 checks under both); slice 3 the board, deals and saved views (the first exemplar) BUILT and proven the same day (191 checks under both); slice 4 activities, reminders and the calendar (the second exemplar) BUILT and proven 2026-10-11 (263 checks under both; db/020) — the handoff point: slices 5–9 and Phases 4–5 are Sonnet 5.5's; slice 5 email BUILT and proven the same day (397 checks under both; db/021). See `docs/sales-crm-design.md` §16.
