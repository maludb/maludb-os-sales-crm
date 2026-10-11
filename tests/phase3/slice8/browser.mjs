// Headless Chromium proof of slice 8, agents, proposals, notifications, K7 and the API: Proposals (a next step accepted in one click and the activity it made, one dismissed, a duplicate sent on to its pair), the Tokens screen's keys (a key
// minted and shown once, used from a script, rotated, revoked), the agents' screen (a dispatch cancelled, the settings saved), the account's Tickets tab degrading to words, the pages with JavaScript OFF, and every screen at 375 x 740 and
// 1280 x 800 with no sideways scroll and no console error. Run through tests/phase3/slice8/run.sh. Playwright from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8801';
const ROOT = new URL('../../../', import.meta.url).pathname;
const SHOTS = process.env.SHOTS || '/tmp/crm-shots/slice8';
fs.mkdirSync(SHOTS, { recursive: true });
const KEY = process.env.ACTION_TOKEN_KEY;
const X = crypto.randomBytes(3).toString('hex');
const fixture = JSON.parse(fs.readFileSync(ROOT + 'bin/dev_directory.json', 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.sales_crm.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const text = Buffer.from(JSON.stringify({ ...fixture.claims[String(member)], member_id: member })).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
const psql = (q) => execFileSync('sudo', ['-n', '-u', 'postgres', 'psql', '-At', '-d', process.env.DB_NAME, '-c', q], { encoding: 'utf8' }).trim();
const sql = (q) => psql(q).split('\n')[0];
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const browser = await chromium.launch();
async function session(member, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/status of (422|403|404)/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.on('dialog', (d) => d.accept());
  if (member) { await page.goto(mint(member), { waitUntil: 'networkidle' }); }
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const tap = (page, sel) => page.locator(sel).evaluate((el) => el.click());
const shot = (page, name) => page.screenshot({ path: `${SHOTS}/${name}.png` });

// Fixtures (scratch only): Priya's deals with proposals of each kind, a pair of contacts, a waiting dispatch, an account for the Tickets tab.
sql(`UPDATE crm_settings SET assistant_proposals = true`);
sql(`UPDATE mcp_access_tokens SET revoked_at = now() WHERE member_id = 26 AND scope = 'api' AND revoked_at IS NULL`);        // a Rep holds ten live keys at most: start from none
const acc = Number(sql(`INSERT INTO accounts (name, domain, owner_member_id, created_by) VALUES ('Bro8 Co ${X}', 'bro8-${X}.example.invalid', 26, 26) RETURNING id`));
const deal = (t) => Number(sql(`INSERT INTO deals (title, pipeline_id, stage_id, account_id, owner_member_id, created_by, value) SELECT '${t}', p.id, s.id, ${acc}, 26, 26, 1000 FROM pipelines p JOIN stages s ON s.pipeline_id = p.id WHERE p.key = 'new_business' AND s.name = 'Qualification' RETURNING id`));
const dA = deal(`Bro8 accept ${X}`), dB = deal(`Bro8 dismiss ${X}`), dC = deal(`Bro8 nojs ${X}`);
const atTomorrow = new Date(Date.now() + 86400000).toISOString().slice(0, 11) + '10:00:00Z';
const proposal = (kind, cols, payload, forM = 26, title = '') => Number(sql(`INSERT INTO assistant_proposals (kind, ${cols[0]}, for_member_id, title, payload, proposed_by) VALUES ('${kind}', ${cols[1]}, ${forM}, '${title.replace(/'/g, "''")}', '${JSON.stringify(payload).replace(/'/g, "''")}'::jsonb, 42) RETURNING id`));
const pA = proposal('next_step', ['deal_id', dA], { kind: 'call', subject: `Call the buyer ${X}`, at: atTomorrow, body: 'Lead with the discount' }, 26, `Next step on deal A ${X}`);
const pB = proposal('next_step', ['deal_id', dB], { kind: 'email', subject: `Send the quote ${X}`, at: atTomorrow }, 26, `Next step on deal B ${X}`);
const pC = proposal('next_step', ['deal_id', dC], { kind: 'task', subject: `No-JS task ${X}`, at: atTomorrow }, 26, `Next step on deal C ${X}`);
const lead = Number(sql(`INSERT INTO leads (company, email, owner_member_id, created_by) VALUES ('Bro8 Lead ${X}', 'bro8-lead-${X}@example.invalid', 26, 26) RETURNING id`));
const pL = proposal('lead_qualify', ['lead_id', lead], { score: 81, reasons: ['Domain matches a customer', 'Asked for a demo'] }, 26, `Qualify Bro8 Lead ${X}: 81 of 100`);
const c1 = Number(sql(`INSERT INTO contacts (first_name, last_name, email, owner_member_id, created_by) VALUES ('Dup', 'One ${X}', 'dup1-${X}@example.invalid', 26, 26) RETURNING id`));
const c2 = Number(sql(`INSERT INTO contacts (first_name, last_name, email, owner_member_id, created_by) VALUES ('Dup', 'Two ${X}', 'dup2-${X}@example.invalid', 26, 26) RETURNING id`));
const pD = proposal('duplicate', ['contact_id', c1], { kind: 'contact', other_id: c2, matched_on: 'name' }, 27, `Possible duplicate contacts: Dup One ${X} and Dup Two ${X}`);
const pR = proposal('rotting', ['deal_id', dA], { stage_id: 1, stage: 'Qualification', days: 21 }, 26, `Bro8 deal has sat in Qualification for 21 days`);
const disp = Number(sql(`INSERT INTO agent_dispatches (agent_member_id, kind, via, acting_member_id, status, detail) VALUES (42, 'lead_qualify', 'chat', 26, 'sent', 'bro8 ${X}') RETURNING id`));

{
  const { page, errors } = await session(26, { width: 1280, height: 800 });
  console.log('1280 wide — Proposals');
  await page.goto(`${BASE}/proposals`, { waitUntil: 'networkidle' });
  ok(await page.locator('#proposals-list').count() === 1 && await page.locator(`#proposal-${pA}`).count() === 1 && await page.locator(`#proposal-${pL}`).count() === 1 && await page.locator(`#proposal-${pD}`).count() === 0, 'the list: her cards (a next step, a qualification, a stale deal), not the manager\'s duplicate');
  ok((await page.locator(`#proposal-${pA}`).innerText()).includes(`Call the buyer ${X}`) && (await page.locator(`#proposal-${pA}-status`).innerText()) === 'Waiting', 'a next step card: its subject, and Waiting');
  ok((await page.locator(`#proposal-${pL}`).innerText()).includes('Score 81 of 100') && (await page.locator(`#proposal-${pL}`).innerText()).includes('Asked for a demo'), 'a qualification card: the score and the reasons');
  await shot(page, 'proposals-1280');
  await Promise.all([page.waitForURL(/notice=accepted/, { timeout: 10000 }), page.click(`#proposal-${pA}-accept`)]);
  ok((await page.locator('#notice-banner').innerText()).includes('Accepted'), 'Accept: back on the list with "Accepted."');
  const act = Number(sql(`SELECT result_activity_id FROM assistant_proposals WHERE id = ${pA}`));
  ok(sql(`SELECT status FROM assistant_proposals WHERE id = ${pA}`) === 'accepted' && sql(`SELECT owner_member_id || ':' || kind || ':' || deal_id FROM activities WHERE id = ${act}`) === `26:call:${dA}`, '…the activity is Priya\'s call on the deal');
  ok(await page.locator(`#proposal-${pA}`).count() === 0, '…and the card left the waiting list');
  await Promise.all([page.waitForURL(/notice=dismissed/, { timeout: 10000 }), page.click(`#proposal-${pB}-dismiss`)]);
  ok(sql(`SELECT status FROM assistant_proposals WHERE id = ${pB}`) === 'dismissed' && (await page.locator('#notice-banner').innerText()).includes('Dismissed'), 'Dismiss: dismissed, "Dismissed."');
  await page.goto(`${BASE}/proposals?status=accepted`, { waitUntil: 'networkidle' });
  ok((await page.locator(`#proposal-${pA}-status`).innerText()) === 'Accepted' && await page.locator(`#proposal-${pA}-activity`).count() === 1 && await page.locator(`#proposal-${pA}-accept`).count() === 0, 'the Accepted filter: the card reads Accepted, links to the activity it made and offers no buttons');
  await page.goto(`${BASE}/proposals?status=dismissed`, { waitUntil: 'networkidle' });
  ok((await page.locator(`#proposal-${pB}-status`).innerText()) === 'Dismissed', 'the Dismissed filter: the other reads Dismissed');
  await page.goto(`${BASE}/proposals?status=all`, { waitUntil: 'networkidle' });
  await shot(page, 'proposals-all-1280');
  ok(errors.length === 0, 'no console error: ' + errors.join('; '));
  await page.context().close();
}

{
  const { page, errors } = await session(27, { width: 1280, height: 800 });
  console.log('1280 wide — a manager: the duplicate, Everyone\'s');
  await page.goto(`${BASE}/proposals?who=all&kind=duplicate`, { waitUntil: 'networkidle' });
  ok(await page.locator(`#proposal-${pD}-sides a`).count() === 2 && /review the pair/i.test(await page.locator(`#proposal-${pD}-accept`).innerText()), 'the duplicate card: both sides as links, "Review the pair"');
  await shot(page, 'proposals-duplicate-1280');
  await Promise.all([page.waitForURL(/\/duplicates\?kind=contact/, { timeout: 10000 }), page.click(`#proposal-${pD}-accept`)]);
  ok(sql(`SELECT status FROM assistant_proposals WHERE id = ${pD}`) === 'accepted', 'Review the pair: accepted, and the manager is taken to the duplicates screen');
  await page.goto(`${BASE}/proposals?who=all`, { waitUntil: 'networkidle' });
  ok(await page.locator('#proposals-who').count() === 1 && await page.locator(`#proposal-${pL}`).count() === 1, 'a manager\'s "Everyone\'s" shows Priya\'s waiting proposals too');
  ok(errors.length === 0, 'no console error: ' + errors.join('; '));
  await page.context().close();
}

{
  const { page, errors } = await session(26, { width: 1280, height: 800 });
  console.log('1280 wide — API keys: minted once, used, rotated, revoked');
  await page.goto(`${BASE}/settings/tokens/`, { waitUntil: 'networkidle' });
  ok(await page.locator('#key-form').count() === 1 && await page.locator('#keys-list').count() === 1, 'the Tokens screen has the key form and the list');
  await page.fill('#key-form-field-label', `Browser key ${X}`);
  await page.fill('#key-form-field-minute', '30');
  await page.click('#key-form-save-btn');
  await page.waitForSelector('#keys-minted-value', { timeout: 10000 });
  const raw = (await page.locator('#keys-minted-value').innerText()).trim();
  ok(/^crm_[0-9a-f]{48}$/.test(raw), 'mint: the key is shown once on the screen');
  await shot(page, 'keys-minted-1280');
  const id = Number(sql(`SELECT id FROM mcp_access_tokens WHERE label = 'Browser key ${X}'`));
  ok(sql(`SELECT token_hash FROM mcp_access_tokens WHERE id = ${id}`) === crypto.createHash('sha256').update(raw).digest('hex') && sql(`SELECT rate_per_minute FROM mcp_access_tokens WHERE id = ${id}`) === '30' && await page.locator(`#key-${id}`).count() === 1, '…only its hash is stored; the limit she lowered is 30; the row is listed');
  const call = async (k) => (await fetch(`${BASE}/api/v1/accounts?q=Bro8`, { headers: { Authorization: 'Bearer ' + k, Accept: 'application/json' } }));
  const r1 = await call(raw);
  const j1 = await r1.json();
  ok(r1.status === 200 && j1.data.accounts.some((a) => a.account_id === acc) && !r1.headers.has('set-cookie'), 'a script uses it: GET /api/v1/accounts lists her account (and sets no cookie)');
  await page.reload({ waitUntil: 'networkidle' });
  ok(await page.locator('#keys-minted-value').count() === 0 && !(await page.content()).includes(raw), 'reload: the value is gone and cannot be shown again');
  await page.click(`#key-${id}-rotate-btn`);
  await page.waitForSelector('#keys-minted-value', { timeout: 10000 });
  const raw2 = (await page.locator('#keys-minted-value').innerText()).trim();
  ok(/^crm_[0-9a-f]{48}$/.test(raw2) && raw2 !== raw && (await call(raw2)).status === 200 && (await call(raw)).status === 200, 'rotate: a new key is shown once; both work during the overlap');
  const id2 = Number(sql(`SELECT max(id) FROM mcp_access_tokens WHERE label = 'Browser key ${X}'`));
  await page.click(`#key-${id2}-revoke-btn`);
  await page.waitForLoadState('networkidle');
  for (let i = 0; i < 20 && sql(`SELECT revoked_at IS NOT NULL FROM mcp_access_tokens WHERE id = ${id2}`) !== 't'; i++) { await page.waitForTimeout(200); }
  ok(sql(`SELECT revoked_at IS NOT NULL FROM mcp_access_tokens WHERE id = ${id2}`) === 't' && (await call(raw2)).status === 401, 'revoke (confirmed): the new key stops at once (401)');
  await page.goto(`${BASE}/settings/tokens/`, { waitUntil: 'networkidle' });
  ok((await page.locator(`#key-${id2}`).innerText()).includes('revoked') && await page.locator(`#key-${id2}-revoke-btn`).count() === 0, 'the list says revoked and offers no more buttons on it');
  await shot(page, 'keys-list-1280');
  ok(errors.length === 0, 'no console error: ' + errors.join('; '));
  await page.context().close();
}

{
  const { page, errors } = await session(1, { width: 1280, height: 800 });
  console.log('1280 wide — the agents\' screen');
  await page.goto(`${BASE}/admin/agents`, { waitUntil: 'networkidle' });
  ok(await page.locator('#agents-table').count() === 1 && await page.locator('#agent-42').count() === 1 && await page.locator('#agent-40').count() === 1 && await page.locator('#agent-41').count() === 0, 'the agents table: the Expert and the Assistant (not the unadmitted Watcher)');
  ok((await page.locator(`#dispatch-${disp}-status`).innerText()) === 'Waiting', 'the dispatch table: the waiting one reads Waiting');
  await shot(page, 'admin-agents-1280');
  await page.click(`#dispatch-${disp}-cancel`);
  for (let i = 0; i < 25 && sql(`SELECT status FROM agent_dispatches WHERE id = ${disp}`) !== 'refused'; i++) { await page.waitForTimeout(200); }
  await page.waitForLoadState('networkidle');
  ok(sql(`SELECT detail FROM agent_dispatches WHERE id = ${disp}`) === 'Cancelled by SMOKE Owner' && (await page.locator(`#dispatch-${disp}-status`).innerText()) === 'Cancelled' && await page.locator(`#dispatch-${disp}-cancel`).count() === 0, 'Cancel (confirmed): the row reads Cancelled and has no more button');
  await page.fill('#agents-settings-field-hour', '8');
  await page.uncheck('#agents-settings-field-proposals');
  const stage = Number(sql(`SELECT s.id FROM stages s JOIN pipelines p ON p.id = s.pipeline_id WHERE p.key = 'new_business' AND s.name = 'Proposal'`));
  await page.check(`#agents-settings-stage-${stage}`);
  await Promise.all([page.waitForURL(/notice=saved/, { timeout: 10000 }), page.click('#agents-settings-save-btn')]);
  ok(sql(`SELECT morning_note_hour || ':' || assistant_proposals FROM crm_settings`) === '8:false' && sql(`SELECT ask_assistant FROM stages WHERE id = ${stage}`) === 't' && (await page.locator('#notice-banner').innerText()).includes('Saved'), 'Save: the hour 8, proposals off, the Proposal stage asks the Assistant — "Saved the agents\' settings."');
  ok(await page.locator('#agents-settings-field-hour').inputValue() === '8' && !(await page.locator('#agents-settings-field-proposals').isChecked()) && await page.locator(`#agents-settings-stage-${stage}`).isChecked(), '…and the form shows what was saved');
  sql(`UPDATE crm_settings SET morning_note_hour = 6, assistant_proposals = true`);
  sql(`UPDATE stages SET ask_assistant = false`);
  ok(errors.length === 0, 'no console error: ' + errors.join('; '));
  await page.context().close();
}

{
  console.log('The account\'s Tickets tab says so in words when Help Desk is not connected');
  const { page, errors } = await session(26, { width: 1280, height: 800 });
  sql(`TRUNCATE k7_cache`);
  const ks = JSON.parse(fs.readFileSync(process.env.FAKE_KERNEL_STATE, 'utf8'));
  delete ks.reads;                                   // no connection approved at the kernel
  fs.writeFileSync(process.env.FAKE_KERNEL_STATE, JSON.stringify(ks));
  await page.goto(`${BASE}/accounts/${acc}?tab=tickets`, { waitUntil: 'networkidle' });
  ok(await page.locator('#account-tickets-no-connection').count() === 1 && (await page.locator('#account-tickets').innerText()).includes('Help Desk is not connected'), 'no connection: "Help Desk is not connected" — a page, not an error');
  await shot(page, 'account-tickets-1280');
  ok(errors.length === 0, 'no console error: ' + errors.join('; '));
  await page.context().close();
}

{
  console.log('JavaScript off — Accept and Mint post the form');
  const ctx = await browser.newContext({ viewport: { width: 375, height: 740 }, javaScriptEnabled: false });
  const page = await ctx.newPage();
  await page.goto(mint(26), { waitUntil: 'networkidle' });
  await page.goto(`${BASE}/proposals`, { waitUntil: 'networkidle' });
  ok(await page.locator(`#proposal-${pC}-accept`).count() === 1, 'the card renders without scripts, Accept a plain form button');
  await Promise.all([page.waitForURL(/notice=accepted/, { timeout: 10000 }), tap(page, `#proposal-${pC}-accept`)]);
  ok(sql(`SELECT status FROM assistant_proposals WHERE id = ${pC}`) === 'accepted' && (await page.locator('#notice-banner').innerText()).includes('Accepted'), 'a click without JavaScript: accepted, the banner says so');
  await page.goto(`${BASE}/settings/tokens/`, { waitUntil: 'networkidle' });
  await page.fill('#key-form-field-label', `No-JS key ${X}`);
  await Promise.all([page.waitForURL(/\/settings\/tokens\//, { timeout: 10000 }), tap(page, '#key-form-save-btn')]);
  await page.waitForSelector('#keys-minted-value', { timeout: 10000 });
  ok(/^crm_[0-9a-f]{48}$/.test((await page.locator('#keys-minted-value').innerText()).trim()) && sql(`SELECT count(*) FROM mcp_access_tokens WHERE label = 'No-JS key ${X}'`) === '1', 'a key minted by a plain form: shown once');
  await ctx.close();
}

{
  console.log('A Viewer: proposals empty, no key form is a problem');
  const v = await session(29, { width: 375, height: 740 });
  await v.page.goto(`${BASE}/proposals`, { waitUntil: 'networkidle' });
  ok(await v.page.locator('#proposals-empty').count() === 1 && (await v.page.locator('#proposals-empty').innerText()).includes('Nothing is waiting for you.') && (await overflow(v.page)) <= 1, 'a Viewer with none: the empty state in words');
  const r = await v.page.goto(`${BASE}/admin/agents`, { waitUntil: 'networkidle' });
  ok(r.status() === 403, 'and the agents\' screen is refused');
  await v.ctx.close();
}

console.log('Every screen at 375 x 740 and 1280 x 800');
const screens = [[26, 'proposals', '/proposals'], [26, 'proposals-all', '/proposals?status=all'], [26, 'proposals-kind', '/proposals?kind=lead_qualify&status=all'], [27, 'proposals-manager', '/proposals?who=all&status=all'],
  [1, 'admin-agents', '/admin/agents'], [1, 'admin-agents-filter', '/admin/agents?status=answered'], [26, 'tokens', '/settings/tokens/'], [1, 'tokens-admin', '/settings/tokens/'], [26, 'tickets-tab', `/accounts/${acc}?tab=tickets`], [26, 'ledger-overview', `/accounts/${acc}`]];
for (const [w, h] of [[375, 740], [1280, 800]]) {
  for (const member of [26, 27, 1]) {
    const { page, errors } = await session(member, { width: w, height: h });
    for (const [m, name, url] of screens) {
      if (m !== member) { continue; }
      await page.goto(BASE + url, { waitUntil: 'networkidle' });
      const o = await overflow(page);
      ok(o <= 1, `${name} at ${w}: no sideways scroll (${o}px)`);
      await shot(page, `${name}-${w}`);
    }
    ok(errors.length === 0, `no console error at ${w} (member ${member}): ` + errors.join('; '));
    await page.context().close();
  }
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
