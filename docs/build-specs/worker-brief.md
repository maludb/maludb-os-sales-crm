# Worker brief — Sales CRM slices 5–9 and Phase 4 (Sonnet 5.5)

You build ONE slice of the Sales CRM from its build spec. The planning model built Phase 2 and slices 1–4 (the exemplars) and verifies and commits
your work. Follow this brief exactly; the spec is the contract.

## Where and what you may touch
- The repository is `/srv/apps/sales_crm` (main branch, NOT installed — a scratch clone; `config/.env` has no DB_PASSWORD). Work in it directly.
- **Never commit, never push, never `git stash`/`reset`/`checkout` away anyone's work.** Leave every change in the working tree; the planning model
  reviews the diff and commits.
- Never touch `/var/www` (the kernel) or another application under `/srv/apps/*` — read them for reference only. Never run `app_install.php apply`.
- Databases: only the scratch `crm_dev<N>` your proof makes (`sudo -n -u postgres` is allowed for scratch `crm_dev*` databases only).
- Never put a secret, a token or a transcript in a file.

## Read before writing any code
1. Your slice's spec in `docs/build-specs/` — every section: divergences, who may do what, screens, files ("exactly these"), tools, manifest rows,
   log events, status vocabulary, out of scope, proof, open questions.
2. The manifest rows it claims in `docs/sales-crm-action-manifest.md`, the tools in `docs/sales-crm-mcp-tool-surface.md`, and the design sections
   it names in `docs/sales-crm-design.md`.
3. The schema it names (`db/*.sql`) — read the functions and triggers you call; the database is the rule-keeper (the wall, refusals).
4. The exemplars, which are binding on shape and idiom:
   - slice 1 (the CRUD exemplar): `app/features/{accounts,contacts,records,notes,tags}`, `html/accounts`, `html/contacts`, `html/records`;
   - slice 3 (the board): `app/features/{deals,pipeline,views}`, `html/deals`, `html/pipeline`;
   - slice 4 (activities and the calendar): `app/features/{activities,calendar}`, `html/{activities,calendar,log}`, `bin/worker.php`'s passes;
   - their proofs `tests/phase3/slice{1,3,4}/` (lib.php, the per-area PHP proofs, `browser.mjs`, `run.sh`);
   - the kit: `app/handler.php`, `app/http.php`, `app/bootstrap.php`, `app/db.php`, `app/activity.php`, `app/picker.php`, `app/pickers.php`, `app/mail.php`,
     `app/views/layout.php`.
   - each finished spec's "Built and proven" section (e.g. `docs/build-specs/activities-reminders.md`) for decisions already taken.
5. The builder plugin from its clone (the installed copy is stale): `~/htmx-php-builder/skills/php-patterns/`, `design-system/` (incl.
   `references/record-picker.md`), `malumail-send/` when you send mail, `mcp-servers/` for Phase 4, `chat-actions/` for the manifest.

## The kit's idiom (must match)
- A write handler: `crm_handler_begin()` → `require_record($pdo, kind, id)` (a hidden record is the same 404 as a missing one) → the rule check
  (`has_right()` / a `require_*_may`) → field readers (`crm_text`, `crm_email`, `crm_choice`, `req_val`, …) collecting `$errors` → `crm_refuse_fields($errors)`
  → `crm_guard($pdo, fn)` (a transaction + `log_activity()`; it turns the schema's refusals into 422/404/403 with the schema's sentence) →
  `crm_done(message, id, crm_land(...), 'hxEvent', extra)`. Every state-changing handler is POST + CSRF + authorization + `log_activity()`.
- A screen: `require_login()`, `log_screen_view()`, `wants_json()` → `respond_screen()` from a whitelist presenter in `present.php` (never raw rows),
  else `render_screen(title, view(...), [...activeNav, screen, entity, recordId])`.
- `view()` uses `extract(EXTR_SKIP)`: NEVER pass data keys named `template`, `data`, `path` or `viewsRoot`.
- Full pages, no modals (the record picker and `hx-confirm` are the only exemptions); works at 375 px with no sideways scroll; a form chooses a
  record through the record picker, never a `<select>` of rows; lists of named things are cards, ledgers and line items tables.
- Words, not codes, on screen; the spec's status vocabulary and element ids exactly.
- A body, a password or a token never goes into an activity-log payload.
- Match the surrounding code's comment density and naming. Constants are global — check for a clash (`grep -rn "const NAME\|define('NAME"`).

## Migrations
`db/001`–`db/020` are never modified. A schema defect you find gets a NEW numbered migration (the next free number, `db/021_<what>.sql`, additive,
views keep `security_barrier`), applied by `tests/setup_dev.sh` with the rest, recorded under "Found and fixed". Re-run the schema proof
(`db/proof/phase0_proof.sql`, inside every run.sh) after it.

## Ambiguity
If the spec is silent on a detail that changes nothing outside the slice (wording, layout, an order), take the reading closest to the exemplars and
the design and record it under "Decisions taken here". If an answer would change the schema's meaning, a right, the manifest, the tool surface, or
contradicts another spec — STOP: end your turn with a report beginning `STOPPED:` and the exact question; you will be resumed with the answer.
Never improvise around a gap.

## Proof (the slice is not done until all of this is green)
- `tests/phase3/slice<N>/run.sh` modelled on slice 4's: a fresh scratch `crm_dev<N>` (env `/tmp/crm-dev<N>.env`, state `/tmp/crm-dev<N>-state`,
  shots `/tmp/crm-shots/slice<N>`), the schema proof, `tests/phase2/servers.sh` (app :8801, fake kernel :8802, fake MaluDB :8803, fake MaluMail :8806),
  one PHP proof per area plus `json.php` (every screen and handler in JSON mode, the HTMX stamps, the log vocabulary, the registry counts) and
  `browser.mjs` (Playwright from `/var/www/web/node_modules`, read only: the slice's screens at 375×740 and 1280×800, no sideways scroll, no console
  error, the enhancements and the no-JS path), the server-log check, the registry `--check`. Everything the spec's Proof section lists is a check.
- Run it under php -S AND `CRM_APP=apache`; both must pass.
- Look at the screenshots (the Read tool shows images) — viewport shots, not full-page — and fix what looks wrong (overflow, truncation, overlap).
- `php bin/build_action_registry.php` after the manifest rows are built; `--check` green.
- Re-run every earlier suite and keep them green: `tests/phase3/slice{1..N-1}/run.sh` and `tests/phase2/run.sh` under php -S and under Apache,
  `tests/phase0/run.sh`, `tests/phase1/check_specs.sh`. An earlier proof that asserted this slice UNBUILT (a placeholder, a greyed link, a stub)
  is changed to follow whether it is built (as slices 1–4 did), never deleted.
- Never run two suites at once (they share the ports). Do not pipe `servers.sh start` into another command (its background servers hold the pipe).
- The kernel's installer `plan` is the planning model's to check; never run the installer.

## Docs (in the same working tree)
- The spec gains `## Built and proven (<date>)` in the form of `activities-reminders.md`'s: what was built, **Proven** with each proof's count,
  **Found and fixed**, **Decisions taken here (within the spec)**.
- `docs/sales-crm-design.md` §16 gains a new entry at the top in the form of slice 4's; `README.md`'s State line extended.

## Your final report (your last message)
1. `DONE` or `STOPPED: <question>`.
2. Files added and changed (a list), migrations added and why.
3. Each proof's count under php -S and under Apache; the registry's screens/actions built.
4. Every earlier suite re-run and its result (both servers).
5. Decisions taken; defects found and fixed; anything in the spec NOT done, and why.
6. A draft commit message in the repository's style (`Sales CRM: slice <N> — …`, one long paragraph like slice 4's).
