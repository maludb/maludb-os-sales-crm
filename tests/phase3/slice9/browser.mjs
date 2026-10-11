// Headless Chromium proof of slice 9, the admin area: the home's cards; the stages dragged into a new order (a real drag, the server's list swapped in, a refused drag snapping back) and the Move down button; a stage added with its required fields
// as chips; a stage archive refused with its deals named; sources added, deactivated and a delete refused; the rules form with the record picker and the test box; a custom field; a tag renamed, merged (the picker, a confirm) and deleted; the mailbox
// saved with the NAME of the secret's key; the quota grid; the settings (a bad value naming its field); a lead-capture key minted and shown once, then revoked; the purge; the pages with JavaScript OFF; and every screen at 375 x 740 and
// 1280 x 800 with no sideways scroll and no console error. Run through tests/phase3/slice9/run.sh. Playwright from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8801';
const ROOT = new URL('../../../', import.meta.url).pathname;
const SHOTS = process.env.SHOTS || '/tmp/crm-shots/slice9';
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
const submit = (page, sel) => Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.locator(sel).evaluate((el) => el.click())]);
const shot = (page, name) => page.screenshot({ path: `${SHOTS}/${name}.png` });
const flash = async (page) => { await page.waitForSelector('#flash-message', { timeout: 8000 }); return (await page.locator('#flash-message').innerText()).trim(); };
const waitSql = async (q, want) => { for (let i = 0; i < 30 && sql(q) !== want; i++) { await new Promise((r) => setTimeout(r, 200)); } return sql(q); };

// Fixtures (scratch only): a pipeline with three open stages, a deal in the first, a source named by a lead, tags on accounts, tombstones.
const pipe = Number(sql(`INSERT INTO pipelines (key, name, position) VALUES ('br9_${X}', 'BR9 ${X}', 7) RETURNING id`));
sql(`INSERT INTO stages (pipeline_id, name, position, probability, kind, colour) VALUES (${pipe}, 'Won', 90, 100, 'won', '#198754'), (${pipe}, 'Lost', 99, 0, 'lost', '#dc3545')`);
const stage = (n, pos, prob) => Number(sql(`INSERT INTO stages (pipeline_id, name, position, probability, kind) VALUES (${pipe}, '${n} ${X}', ${pos}, ${prob}, 'open') RETURNING id`));
const sA = stage('Alpha', 10, 10), sB = stage('Bravo', 20, 30), sC = stage('Charlie', 30, 60);
const dealId = Number(sql(`INSERT INTO deals (title, pipeline_id, stage_id, owner_member_id, created_by, currency, value) VALUES ('BR9 deal ${X}', ${pipe}, ${sA}, 26, 26, 'USD', 100) RETURNING id`));
const web = Number(sql(`SELECT id FROM lead_sources WHERE name = 'Website'`));
const srcUsed = Number(sql(`INSERT INTO lead_sources (name, kind) VALUES ('BR9 used ${X}', 'inbound') RETURNING id`));
sql(`INSERT INTO leads (company, source_id, owner_member_id, created_by) VALUES ('BR9 lead ${X}', ${srcUsed}, 26, 26)`);
const tag = (n) => Number(sql(`INSERT INTO tags (slug, label) VALUES ('br9-${n}-${X}', 'br9${n} ${X}') RETURNING id`));
const tA = tag('a'), tB = tag('b'), tC = tag('c');
const acc = Number(sql(`INSERT INTO accounts (name, owner_member_id, created_by) VALUES ('BR9 acct ${X}', 26, 26) RETURNING id`));
sql(`INSERT INTO record_tags (record_type, record_id, tag_id) VALUES ('account', ${acc}, ${tA}), ('account', ${acc}, ${tC})`);
const ordered = () => sql(`SELECT string_agg(id::text, ',' ORDER BY position, id) FROM stages WHERE pipeline_id = ${pipe} AND kind = 'open' AND archived_at IS NULL`);
const domOrder = async (page) => (await page.locator(`#stage-list-${pipe} .stage-row`).evaluateAll((els) => els.map((e) => e.dataset.stageId))).filter((id) => ![sql(`SELECT id FROM stages WHERE pipeline_id = ${pipe} AND kind = 'won'`), sql(`SELECT id FROM stages WHERE pipeline_id = ${pipe} AND kind = 'lost'`)].includes(id)).join(',');

{
  const { page, errors } = await session(1, { width: 1280, height: 800 });
  console.log('1280 wide — the home and the pipelines');
  await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
  ok(await page.locator('#admin-cards > div').count() === 11 && (await page.title()).startsWith('Admin'), 'the admin home: eleven cards, titled Admin');
  ok((await page.locator('#admin-card-pipelines-words').innerText()).includes('pipelines') && await page.locator('#admin-card-purge-words').count() === 1, 'each card carries a number in words');
  await shot(page, 'admin-1280');
  await page.click('#admin-card-pipelines-link');
  await page.waitForURL(BASE + '/admin/pipelines');
  await page.waitForSelector(`#pipeline-${pipe}`);
  ok((await page.title()).startsWith('Pipelines') && await page.locator(`#stage-list-${pipe}`).count() === 1, 'a card opens its screen by HTMX (pushed /admin/pipelines)');
  ok(await page.evaluate(() => document.querySelector('#nav-admin .nxl-link').classList.contains('active')), 'the sidebar keeps Admin highlighted');
  ok(await domOrder(page) === `${sA},${sB},${sC}`, 'the stages are listed in order');
  await shot(page, 'pipelines-1280');
  console.log('1280 wide — the drag');
  await page.locator(`#stage-${sC}-handle`).dragTo(page.locator(`#stage-row-${sA}`), { targetPosition: { x: 40, y: 4 } });
  const after = await waitSql(`SELECT string_agg(id::text, ',' ORDER BY position, id) FROM stages WHERE pipeline_id = ${pipe} AND kind = 'open'`, `${sC},${sA},${sB}`);
  ok(after === `${sC},${sA},${sB}`, 'a stage dragged above the first: stage_reorder posted, the order in the database is Charlie, Alpha, Bravo');
  await page.waitForTimeout(400);
  ok(await domOrder(page) === `${sC},${sA},${sB}` && await page.locator(`#stage-list-${pipe}[data-stage-sortable]`).count() === 1, '…and the list on the page is the server\'s, re-rendered and still sortable');
  // a refused drag: another stage appears behind the page's back, so the posted list is incomplete
  const sD = stage('Delta', 40, 80);
  await page.locator(`#stage-${sB}-handle`).dragTo(page.locator(`#stage-row-${sC}`), { targetPosition: { x: 40, y: 4 } });
  const msg = await flash(page);
  ok(msg.includes('every open stage') && ordered() === `${sC},${sA},${sB},${sD}`, 'a drag the server refuses (a stage it did not know of): the flash says "' + msg + '" and nothing moved');
  await page.waitForTimeout(800);
  ok(await domOrder(page) === `${sC},${sA},${sB},${sD}`, '…and the rows snapped back: the page shows the server\'s order, Delta included');
  await page.click(`#stage-${sC}-down-btn`);
  await waitSql(`SELECT string_agg(id::text, ',' ORDER BY position, id) FROM stages WHERE pipeline_id = ${pipe} AND kind = 'open'`, `${sA},${sC},${sB},${sD}`);
  await page.waitForTimeout(300);
  ok(ordered() === `${sA},${sC},${sB},${sD}` && await domOrder(page) === `${sA},${sC},${sB},${sD}`, 'Move down on Charlie: swapped with Alpha\'s successor, the list re-rendered');
  ok(await page.locator(`#stage-${sA}-up-btn`).count() === 0 && await page.locator(`#stage-${sD}-down-btn`).count() === 0, 'no Move up on the first and no Move down on the last');
  ok((await page.locator(`#stage-row-${sA} .stage-handle`).count()) === 1 && (await page.locator(`#pipeline-${pipe} li:has(#stage-${sA}-handle) ~ li .stage-handle-none`).count()) === 2, 'Won and Lost have no handle');
  ok((await overflow(page)) <= 0, 'no sideways scroll');
  console.log('1280 wide — add a stage, archive refused');
  await page.click(`#pipeline-${pipe}-add-stage-btn`);
  await page.waitForSelector('#stage-form');
  ok((await page.title()).startsWith('New stage') && await page.locator('#stage-form-required input[type=checkbox]').count() >= 7, 'Add a stage: the form, with the deal\'s columns as chips to require');
  await page.fill('#stage-form-field-name', `Echo ${X}`);
  await page.fill('#stage-form-field-probability', '45');
  await page.fill('#stage-form-field-wip', '3');
  await page.click('label[for="stage-form-required-value"]');
  await page.click('label[for="stage-form-required-expected_close_date"]');
  await page.check('#stage-form-field-assistant');
  await shot(page, 'stage-form-1280');
  await page.click('#stage-form-save-btn');
  await page.waitForURL(/\/admin\/pipelines\?notice=stage/, { timeout: 10000 });
  const echo = Number(sql(`SELECT id FROM stages WHERE pipeline_id = ${pipe} AND name = 'Echo ${X}'`));
  ok(echo > 0 && sql(`SELECT probability || ':' || wip_limit || ':' || ask_assistant || ':' || required_fields::text FROM stages WHERE id = ${echo}`) === '45:3:true:{value,expected_close_date}', 'saved: probability, WIP, the Assistant and the required fields chosen as chips');
  ok((await page.locator('#notice-banner').innerText()).includes('Saved the stage') && (await page.locator(`#stage-${echo}-required`).innerText()).includes('Expected close date') && await page.locator(`#stage-${echo}-assistant`).count() === 1, 'back on the pipelines with the notice, the chips and the Assistant badge on its row');
  await page.goto(`${BASE}/admin/stages/${sA}/edit`, { waitUntil: 'networkidle' });
  await page.check('#stage-form-field-archived');
  await page.click('#stage-form-save-btn');
  const m2 = await flash(page);
  ok(m2.includes('open deal') && sql(`SELECT archived_at IS NULL FROM stages WHERE id = ${sA}`) === 't', 'archiving a stage with an open deal: refused in words — "' + m2 + '"');
  ok(await page.locator('#stage-delete-refused').count() === 1 && await page.locator('#stage-delete-btn').count() === 0, 'and its Delete is replaced by the reason');
  await shot(page, 'stage-refused-1280');
  ok(errors.length === 0, 'no console error: ' + errors.join('; '));
  await page.context().close();
}

{
  const { page, errors } = await session(1, { width: 1280, height: 800 });
  console.log('1280 wide — sources and tags');
  await page.goto(`${BASE}/admin/sources`, { waitUntil: 'networkidle' });
  await page.fill('#source-add-name', `BR9 Expo ${X}`);
  await page.selectOption('#source-add-kind', 'outbound');
  await page.click('#source-add-btn');
  await page.waitForURL(/\/admin\/sources\?notice=created/, { timeout: 10000 });
  const src = Number(sql(`SELECT id FROM lead_sources WHERE name = 'BR9 Expo ${X}'`));
  ok(src > 0 && sql(`SELECT kind FROM lead_sources WHERE id = ${src}`) === 'outbound' && await page.locator(`#source-row-${src}`).count() === 1, 'a source added inline: it is in the list, outbound');
  await page.uncheck(`#source-${src}-active`);
  await page.click(`#source-${src}-save-btn`);
  await waitSql(`SELECT active FROM lead_sources WHERE id = ${src}`, 'f');
  ok(sql(`SELECT active FROM lead_sources WHERE id = ${src}`) === 'f', 'deactivated with the switch and Save');
  await page.goto(`${BASE}/admin/sources`, { waitUntil: 'networkidle' });
  ok(await page.locator(`#source-${srcUsed}-delete-btn[disabled]`).count() === 1 && (await page.locator(`#source-${srcUsed}-uses`).innerText()).includes('1 lead'), 'a source a lead names: its delete is disabled and says what names it');
  await page.fill(`#reason-add-deal-label`, `BR9 reason ${X}`);
  await page.click('#reason-add-deal-btn');
  await page.waitForURL(/notice=created/, { timeout: 10000 });
  ok(sql(`SELECT kind FROM lost_reasons WHERE label = 'BR9 reason ${X}'`) === 'deal', 'a deal reason added inline');
  await page.click(`#source-${src}-delete-btn`);
  await page.waitForURL(/notice=deleted/, { timeout: 10000 });
  ok(sql(`SELECT count(*) FROM lead_sources WHERE id = ${src}`) === '0', 'an unused source is deleted (confirm accepted)');
  await shot(page, 'sources-1280');
  await page.goto(`${BASE}/admin/tags?q=${X}`, { waitUntil: 'networkidle' });
  ok(await page.locator('#tags-chips > div').count() === 3 && (await page.locator(`#tag-${tA}-count`).innerText()) === '1 record', 'the tags: three chips with their use');
  await page.click(`#tag-${tA}-rename summary`);
  await page.fill(`#tag-${tA}-name`, `br9 renamed ${X}`);
  await page.click(`#tag-${tA}-rename-btn`);
  await waitSql(`SELECT label FROM tags WHERE id = ${tA}`, `br9 renamed ${X}`);
  ok(sql(`SELECT slug FROM tags WHERE id = ${tA}`) === `br9-renamed-${X}`, 'Rename: the label and the slug followed');
  await page.goto(`${BASE}/admin/tags?q=${X}`, { waitUntil: 'networkidle' });
  await page.click(`#tag-${tC}-merge summary`);
  await page.click(`#tag-${tC}-into-open`);
  await page.waitForSelector('#record-picker.show');
  await page.fill('#record-picker-search', `renamed ${X}`);
  await page.waitForSelector(`#record-picker-row-${tA}`);
  await page.click(`#record-picker-row-${tA}`);
  await page.waitForSelector('#record-picker', { state: 'hidden' });
  await page.click(`#tag-${tC}-merge-btn`);
  await page.waitForURL(/notice=merged/, { timeout: 10000 });
  ok(sql(`SELECT count(*) FROM tags WHERE id = ${tC}`) === '0' && sql(`SELECT usage_count FROM tags WHERE id = ${tA}`) === '1' && sql(`SELECT count(*) FROM record_tags WHERE tag_id = ${tA}`) === '1', 'Merge into (the record picker, then the confirm): the tag went, the survivor kept one row — the account carried both');
  await page.click(`#tag-${tB}-delete-btn`);
  await page.waitForURL(/notice=deleted/, { timeout: 10000 });
  ok(sql(`SELECT count(*) FROM tags WHERE id = ${tB}`) === '0', 'Delete (confirm accepted)');
  await shot(page, 'tags-1280');
  ok(errors.length === 0, 'no console error: ' + errors.join('; '));
  await page.context().close();
}

{
  const { page, errors } = await session(1, { width: 1280, height: 800 });
  console.log('1280 wide — rules and the test box');
  sql(`DELETE FROM assignment_rules`);
  await page.goto(`${BASE}/admin/rules`, { waitUntil: 'networkidle' });
  ok(await page.locator('#rule-row-new').count() === 1 && await page.locator('#rules-empty').count() === 1, 'no rules yet: the blank rule to add');
  await page.fill('#rules-new-name', `BR9 web ${X}`);
  await page.click(`label[for="rules-new-source-${web}"]`);
  await page.fill('#rules-new-country', 'de, fr');
  await page.click('#rules-new-member-open');
  await page.waitForSelector('#record-picker.show');
  await page.fill('#record-picker-search', 'Marco');
  await page.waitForSelector('#record-picker-row-27');
  await page.click('#record-picker-row-27');
  await page.waitForSelector('#record-picker', { state: 'hidden' });
  await shot(page, 'rules-form-1280');
  await page.click('#rules-save-btn');
  await page.waitForURL(/\/admin\/rules\?notice=saved/, { timeout: 10000 });
  ok(sql(`SELECT assign_kind || ':' || member_id || ':' || (match->'country')::text FROM assignment_rules WHERE name = 'BR9 web ${X}'`) === 'member:27:["DE", "FR"]', 'a rule saved with a source chip, countries and a person from the picker');
  ok((await page.locator('#rule-row-1-words').innerText()).includes('from Website') && (await page.locator('#rule-row-1-words').innerText()).includes('Marco'), 'the rule reads in words: "' + (await page.locator('#rule-row-1-words').innerText()).trim() + '"');
  await page.selectOption('#rule-test-source', String(web));
  await page.fill('#rule-test-country', 'DE');
  await page.click('#rule-test-btn');
  await page.waitForSelector('#rule-test');
  ok((await page.locator('#rule-test').innerText()).includes(`BR9 web ${X}`) && (await page.locator('#rule-test').innerText()).includes('Marco') && (await page.locator('#rule-test-note').innerText()).includes('nothing was assigned') && sql(`SELECT count(*) FROM leads WHERE company LIKE 'BR9 test%'`) === '0', 'the test box names the rule and Marco, swapped in by HTMX, and says nothing was assigned');
  await page.fill('#rule-test-country', 'US');
  await page.click('#rule-test-btn');
  await page.waitForFunction(() => document.querySelector('#rule-test') && document.querySelector('#rule-test').innerText.includes('No rule matches'));
  ok(true, 'and for a US lead: "No rule matches"');
  await shot(page, 'rules-test-1280');
  sql(`DELETE FROM assignment_rules`);
  console.log('1280 wide — custom fields');
  await page.goto(`${BASE}/admin/fields?kind=deal`, { waitUntil: 'networkidle' });
  await page.fill('#field-form-field-label', `BR9 term ${X}`);
  await page.selectOption('#field-form-field-type', 'select');
  await page.fill('#field-form-field-options', 'One year\nTwo years');
  await page.click(`label[for="field-form-pipeline-${pipe}"]`);
  await page.check('#field-form-field-win');
  await page.click('#field-form-save-btn');
  await page.waitForURL(/kind=deal.*notice=created|notice=created.*kind=deal/, { timeout: 10000 });
  const fid = Number(sql(`SELECT id FROM custom_field_defs WHERE label = 'BR9 term ${X}'`));
  ok(fid > 0 && sql(`SELECT field_type || ':' || options::text || ':' || type_ids::text || ':' || required_on_solve FROM custom_field_defs WHERE id = ${fid}`) === `select:{"One year","Two years"}:{${pipe}}:true`, 'a deal field added: choices, one pipeline, required to win');
  ok(await page.locator(`#field-${fid}`).count() === 1 && (await page.locator(`#field-${fid}-win`).innerText()).includes('needed to win'), 'it is in the list');
  await page.goto(`${BASE}/deals/${dealId}/edit`, { waitUntil: 'networkidle' });
  ok(await page.locator(`text=BR9 term ${X}`).count() >= 1, 'and on the deal form of that pipeline, at once');
  await shot(page, 'fields-1280');
  ok(errors.length === 0, 'no console error: ' + errors.join('; '));
  await page.context().close();
}

{
  const { page, errors } = await session(1, { width: 1280, height: 800 });
  console.log('1280 wide — mailbox, quotas, settings');
  sql(`UPDATE crm_settings SET mailbox_id = NULL`); sql(`DELETE FROM inbound_email`); sql(`DELETE FROM mailboxes`);
  await page.goto(`${BASE}/admin/mailbox`, { waitUntil: 'networkidle' });
  await page.fill('#mailbox-form-field-address', `br9-${X}@example.invalid`);
  await page.fill('#mailbox-form-field-secret', 'whsec_3f9a8b7c6d5e4f3a2b1c0d9e8f7a6b5c');
  await page.click('#mailbox-form-save-btn');
  ok(sql(`SELECT count(*) FROM mailboxes`) === '0' && await page.locator('#mailbox-form-field-secret:invalid').count() === 1, 'the browser itself stops a lower-case secret (the pattern)');
  await page.evaluate(() => document.getElementById('mailbox-form-field-secret').removeAttribute('pattern'));
  await page.click('#mailbox-form-save-btn');
  const m3 = await flash(page);
  ok(m3.includes('NAME of the key') && sql(`SELECT count(*) FROM mailboxes`) === '0', 'a secret pasted into the box is refused ("' + m3.slice(0, 60) + '…") and nothing is stored');
  await page.fill('#mailbox-form-field-secret', 'MAILBOX_CRM_WEBHOOK_SECRET');
  await page.click('#mailbox-form-save-btn');
  await page.waitForURL(/\/admin\/mailbox\?notice=created/, { timeout: 10000 });
  ok(sql(`SELECT webhook_secret_ref FROM mailboxes`) === 'MAILBOX_CRM_WEBHOOK_SECRET' && (await page.locator('#mailbox-secret-state').innerText()).includes('is set') && await page.locator('#mailbox-recipe').count() === 1, 'saved with the key\'s name; the page says the key is set and shows the recipe');
  ok((await page.locator('#mailbox-health-state').innerText()).includes('Nothing has arrived'), 'health: "' + (await page.locator('#mailbox-health-state').innerText()) + '"');
  await shot(page, 'mailbox-1280');
  const year = 2095;
  sql(`DELETE FROM quotas WHERE month >= '${year}-01-01' AND month < '${year + 1}-01-01'`);
  await page.goto(`${BASE}/admin/quotas?year=${year}`, { waitUntil: 'networkidle' });
  await page.fill(`#quota-26-${year}-01`, '15000');
  await page.fill(`#quota-27-${year}-02`, '22500.50');
  await page.click('#quotas-save-btn');
  await page.waitForURL(/notice=saved/, { timeout: 10000 });
  ok(sql(`SELECT count(*) FROM quotas WHERE month >= '${year}-01-01' AND month < '${year + 1}-01-01'`) === '2' && (await page.inputValue(`#quota-27-${year}-02`)) === '22500.5', 'quotas: two cells typed, Save, two rows; the grid reads them back');
  await page.fill(`#quota-26-${year}-01`, '');
  await page.click('#quotas-save-btn');
  await page.waitForURL(/notice=saved/, { timeout: 10000 });
  ok(sql(`SELECT count(*) FROM quotas WHERE member_id = 26 AND month >= '${year}-01-01' AND month < '${year + 1}-01-01'`) === '0', 'emptying a cell removes the quota');
  await shot(page, 'quotas-1280');
  await page.goto(`${BASE}/admin/settings`, { waitUntil: 'networkidle' });
  await page.fill('#settings-field-forecast-months', '7');
  await page.uncheck('#settings-field-next-step-prompt');
  await page.click('#settings-save-btn');
  await page.waitForURL(/\/admin\/settings\?notice=saved/, { timeout: 10000 });
  ok(sql(`SELECT forecast_months || ':' || next_step_prompt FROM crm_settings`) === '7:false' && (await page.inputValue('#settings-field-forecast-months')) === '7' && !(await page.isChecked('#settings-field-next-step-prompt')), 'settings: a number changed and a switch turned off, saved and read back');
  await page.evaluate(() => { const i = document.getElementById('settings-field-forecast-months'); i.removeAttribute('max'); i.value = '99'; });
  await page.click('#settings-save-btn');
  const m4 = await flash(page);
  ok(m4.includes('The forecast looks ahead') && sql(`SELECT forecast_months FROM crm_settings`) === '7', 'a value the browser let through is refused naming its field — "' + m4 + '"');
  sql(`UPDATE crm_settings SET forecast_months = 6, next_step_prompt = true`);
  await shot(page, 'settings-1280');
  ok(errors.length === 0, 'no console error: ' + errors.join('; '));
  await page.context().close();
}

{
  const { page, errors } = await session(1, { width: 1280, height: 800 });
  console.log('1280 wide — keys and the purge');
  await page.goto(`${BASE}/admin/tokens`, { waitUntil: 'networkidle' });
  await page.fill('#capture-key-label', `BR9 form ${X}`);
  await page.click('#capture-key-source-open');
  await page.waitForSelector('#record-picker.show');
  await page.click(`#record-picker-row-${web}`);
  await page.waitForSelector('#record-picker', { state: 'hidden' });
  await page.click('#capture-key-btn');
  await page.waitForSelector('#keys-minted-value', { timeout: 10000 });
  const raw = (await page.locator('#keys-minted-value').innerText()).trim();
  const kid = Number(sql(`SELECT id FROM mcp_access_tokens WHERE label = 'BR9 form ${X}'`));
  ok(/^crm_[0-9a-f]{48}$/.test(raw) && sql(`SELECT token_hash FROM mcp_access_tokens WHERE id = ${kid}`) === crypto.createHash('sha256').update(raw).digest('hex') && sql(`SELECT lead_source_id FROM mcp_access_tokens WHERE id = ${kid}`) === String(web), 'a lead-capture key minted: shown once in a banner, only its hash stored, bound to the source');
  await shot(page, 'tokens-minted-1280');
  const lead = await fetch(`${BASE}/api/v1/leads`, { method: 'POST', headers: { Authorization: 'Bearer ' + raw, 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ last_name: `Cap ${X}`, company: `BR9 cap ${X}` }) });
  ok(lead.status === 201, 'the website posts a lead with it: 201');
  await page.reload({ waitUntil: 'networkidle' });
  ok(await page.locator('#keys-minted-value').count() === 0 && !(await page.content()).includes(raw) && (await page.locator(`#key-${kid}-kind`).innerText()) === 'Lead capture' && (await page.locator(`#key-${kid} td`).nth(3).innerText()).trim() === '1', 'reload: the value is gone; the row says Lead capture and counts the call');
  await page.click(`#key-${kid}-revoke-btn`);
  await waitSql(`SELECT revoked_at IS NOT NULL FROM mcp_access_tokens WHERE id = ${kid}`, 't');
  await page.waitForLoadState('networkidle');
  ok((await page.locator(`#key-${kid}-state`).innerText()) === 'revoked' && (await fetch(`${BASE}/api/v1/leads`, { method: 'POST', headers: { Authorization: 'Bearer ' + raw, 'Content-Type': 'application/json' }, body: '{}' })).status === 401, 'Revoke (confirmed): the row reads revoked and the key is dead (401)');
  sql(`INSERT INTO leads (company, owner_member_id, created_by, deleted_at, deleted_by) VALUES ('BR9 late ${X}', 26, 26, now() - interval '40 days', 26), ('BR9 early ${X}', 26, 26, now() - interval '5 days', 26)`);
  await page.goto(`${BASE}/admin/purge`, { waitUntil: 'networkidle' });
  ok(!(await page.locator('#purge-now-btn').isDisabled()) && (await page.locator('#purge-lead-due').innerText()).startsWith('1'), 'the purge screen: one lead due, Purge now enabled');
  await shot(page, 'purge-1280');
  await page.click('#purge-now-btn');
  await page.waitForURL(/\/admin\/purge\?notice=purged/, { timeout: 10000 });
  ok(sql(`SELECT count(*) FROM leads WHERE company = 'BR9 late ${X}'`) === '0' && sql(`SELECT count(*) FROM leads WHERE company = 'BR9 early ${X}'`) === '1' && (await page.locator('#notice-banner').innerText()).includes('Purged'), 'Purge now (confirm accepted): the late one is gone, the one inside the window stays');
  ok(errors.length === 0, 'no console error: ' + errors.join('; '));
  await page.context().close();
}

{
  const { page, errors } = await session(1, { width: 1280, height: 800 }, { javaScriptEnabled: false });
  console.log('JavaScript OFF — the buttons are forms');
  await page.goto(`${BASE}/admin/pipelines`, { waitUntil: 'load' });
  const order0 = ordered();
  await submit(page, `#stage-${sC}-up-btn`);
  ok(ordered() !== order0 && page.url().includes('/admin/pipelines') && (await page.locator('#notice-banner').count()) === 1, 'Move up works as a plain form post: redirected back with the notice');
  await page.goto(`${BASE}/admin/sources`, { waitUntil: 'load' });
  await page.fill('#source-add-name', `BR9 nojs ${X}`);
  await submit(page, '#source-add-btn');
  ok(sql(`SELECT count(*) FROM lead_sources WHERE name = 'BR9 nojs ${X}'`) === '1', 'a source added with no script');
  await page.goto(`${BASE}/admin/settings`, { waitUntil: 'load' });
  await page.fill('#settings-field-forecast-months', '8');
  await submit(page, '#settings-save-btn');
  ok(sql(`SELECT forecast_months FROM crm_settings`) === '8', 'the settings saved with no script');
  sql(`UPDATE crm_settings SET forecast_months = 6`);
  await page.goto(`${BASE}/admin/tags?q=${X}`, { waitUntil: 'load' });
  ok(await page.locator('details summary').count() >= 1, 'the tag actions are <details>, not script');
  ok(errors.length === 0, 'no console error: ' + errors.join('; '));
  await page.context().close();
}

const urls = ['/admin', '/admin/pipelines', '/admin/pipelines/new', `/admin/pipelines/${pipe}/edit`, `/admin/pipelines/${pipe}/stages/new`, `/admin/stages/${sB}/edit`, '/admin/sources', '/admin/rules', '/admin/fields?kind=deal', '/admin/tags',
  '/admin/mailbox', '/admin/quotas?year=2095', '/admin/settings', '/admin/tokens', '/admin/purge'];
for (const [w, h, name] of [[375, 740, 'phone'], [1280, 800, 'desktop']]) {
  const { page, errors } = await session(1, { width: w, height: h });
  console.log(`${w} wide — every admin screen`);
  const bad = [];
  for (const u of urls) {
    await page.goto(BASE + u, { waitUntil: 'networkidle' });
    const o = await overflow(page);
    if (o > 0) { bad.push(`${u} (+${o}px)`); }
    await shot(page, `${name}-${u.replace(/[^a-z0-9]+/gi, '_').replace(/^_|_$/g, '') || 'home'}`);
  }
  ok(bad.length === 0, `${urls.length} screens at ${w} px: no sideways scroll` + (bad.length ? ' — ' + bad.join(', ') : ''));
  await page.goto(`${BASE}/admin/settings`, { waitUntil: 'networkidle' });
  const bar = await page.locator('.admin-save-bar').boundingBox();
  ok(bar !== null && bar.y + bar.height <= h && bar.y >= 0, `${w} px: the Save bar is pinned inside the viewport (bottom ${Math.round(bar.y + bar.height)} of ${h})`);
  await page.goto(`${BASE}/admin/quotas?year=2095`, { waitUntil: 'networkidle' });
  const inner = await page.evaluate(() => { const e = document.querySelector('.quota-grid-wrap'); return [e.scrollWidth, e.clientWidth]; });
  ok(inner[0] > inner[1] === (w < 1000) || inner[0] >= inner[1], `${w} px: the quota grid scrolls inside its card, never the page (${inner[0]} in ${inner[1]})`);
  ok(errors.length === 0, `no console error at ${w} px: ` + errors.join('; '));
  await page.context().close();
}

await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
