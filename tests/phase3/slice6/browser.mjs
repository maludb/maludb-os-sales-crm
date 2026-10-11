// Headless Chromium proof of slice 6, campaigns, lists, segments, bulk actions and exports: the bulk bar on the contacts screen (ticks, Select this page, the count, Tag, Assign through the record picker, "all N
// matching" with Export), the Exports page and a real download (once), the filter builder with its live count and a smart list saved from it, a campaign made, a member added through the picker, the email queued and
// then sent by the worker, a list's page with the bar, the bar with JavaScript OFF (the buttons post the form), a Viewer who sees no bar, and every screen at 375 x 740 and 1280 x 800 with no sideways scroll and no
// console error. Run through tests/phase3/slice6/run.sh. Playwright from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8801';
const ROOT = new URL('../../../', import.meta.url).pathname;
const SHOTS = process.env.SHOTS || '/tmp/crm-shots/slice6';
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
const sends = () => { const f = process.env.CRM_DEV_STATE + '/malumail.log'; return fs.existsSync(f) ? fs.readFileSync(f, 'utf8').split('\n').filter(Boolean).map((l) => JSON.parse(l)) : []; };
const sendsBefore = sends().length;
const mine = () => sends().slice(sendsBefore);
const worker = (passes) => execFileSync('php', [ROOT + 'bin/worker.php', '--passes=' + passes], { encoding: 'utf8', env: process.env });
const browser = await chromium.launch();
async function session(member, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, acceptDownloads: true, ...opts });
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
const pick = async (page, openSel, search, rowSel) => {
  await page.click(openSel);
  await page.waitForSelector('#record-picker-results .record-picker-row', { timeout: 10000 });
  await page.fill('#record-picker-search', search);
  await page.waitForTimeout(700);
  await page.click(rowSel);
};

// Fixtures (scratch only): an account with five people of Priya's under one tag, a Dana-owned contact, a static list, a template.
const acc = Number(sql(`INSERT INTO accounts (name, owner_member_id, created_by) VALUES ('Bulk Co ${X}', 26, 26) RETURNING id`));
const person = (first, owner = 26) => Number(sql(`INSERT INTO contacts (first_name, last_name, email, account_id, owner_member_id, created_by) VALUES ('${first}', 'Bulker ${X}', '${first.toLowerCase()}-${X}@bro6-${X}.example.invalid', ${acc}, ${owner}, ${owner}) RETURNING id`));
const people = ['Ada', 'Bea', 'Cal', 'Dov', 'Eli'].map((n) => person(n));
const dana = person('Fay', 30);
sql(`INSERT INTO tags (slug, label) VALUES ('bro6-${X}', 'bro6-${X}') ON CONFLICT DO NOTHING`);
for (const id of [...people, dana]) { sql(`INSERT INTO record_tags (record_type, record_id, tag_id) VALUES ('contact', ${id}, (SELECT id FROM tags WHERE slug = 'bro6-${X}'))`); }
const tpl = Number(sql(`INSERT INTO email_templates (name, subject, body, is_shared, owner_member_id, kind) VALUES ('Bro6 ${X}', 'News for {{contact.first_name}}', 'Hello {{contact.first_name}}.', true, 27, 'general') RETURNING id`));
const danaId = 30;

{
  const { page, errors } = await session(27, { width: 1280, height: 800 });
  console.log('1280 wide — the bulk bar on the contacts screen');
  await page.goto(`${BASE}/contacts?tag=bro6-${X}`, { waitUntil: 'networkidle' });
  ok(await page.locator('#bulk-bar').count() === 1 && await page.locator('.bulk-tick').count() === 6, 'the bar and a tick on each of the six cards');
  ok((await page.locator('#bulk-count').innerText()) === '0 selected' && await page.locator('#bulk-scope-all-label').innerText().then((t) => /all 6 matching/i.test(t)), '"0 selected" and the whole result offered: "All 6 matching"');
  await page.check(`#bulk-tick-contact-${people[0]}`);
  await page.check(`#bulk-tick-contact-${people[1]}`);
  ok((await page.locator('#bulk-count').innerText()) === '2 selected', 'two ticks: "2 selected" (the count follows)');
  await page.check('#bulk-select-all');
  ok((await page.locator('#bulk-count').innerText()) === '6 selected', 'Select this page: all six');
  await page.uncheck('#bulk-select-all');
  ok((await page.locator('#bulk-count').innerText()) === '0 selected', '…and none again');
  await page.check(`#bulk-tick-contact-${people[0]}`);
  await page.check(`#bulk-tick-contact-${people[1]}`);
  await page.click('#bulk-actions-summary');
  await page.fill('#bulk-tag', `picked-${X}`);
  await shot(page, 'bar-open-1280');
  await Promise.all([page.waitForURL(/notice=bulk_tag/, { timeout: 10000 }), page.click('#bulk-tag-btn')]);
  ok((await page.locator('#notice-banner').innerText()).includes('Changed the tags of 2 contacts.'), 'Tag: back on the screen with "Changed the tags of 2 contacts."');
  ok(sql(`SELECT count(*) FROM record_tags rt JOIN tags t ON t.id = rt.tag_id WHERE t.label = 'picked-${X}'`) === '2', '…two contacts carry the tag');
  ok(page.url().includes(`tag=bro6-${X}`), '…and the screen\'s filter is kept');

  console.log('1280 wide — Assign through the record picker; all matching');
  await page.check(`#bulk-tick-contact-${people[2]}`);
  await page.click('#bulk-actions-summary');
  await pick(page, '#bulk-owner-open', 'Dana', `.record-picker-row[data-label="SMOKE Dana"]`);
  await Promise.all([page.waitForURL(/notice=bulk_assign/, { timeout: 10000 }), page.click('#bulk-assign-btn')]);
  ok(sql(`SELECT owner_member_id FROM contacts WHERE id = ${people[2]}`) === String(danaId) && (await page.locator('#notice-banner').innerText()).includes('Gave 1 contact a new owner.'), 'Assign (the owner chosen in the picker): Dana owns it now, the banner says so');
  await page.click('label[for=bulk-scope-all]');
  await page.click('#bulk-actions-summary');
  await page.fill('#bulk-tag', `everyone-${X}`);
  await Promise.all([page.waitForURL(/notice=bulk_tag/, { timeout: 10000 }), page.click('#bulk-tag-btn')]);
  ok(sql(`SELECT count(*) FROM record_tags rt JOIN tags t ON t.id = rt.tag_id WHERE t.label = 'everyone-${X}'`) === '6', '"All 6 matching" tags all six (the manager may change them all)');
  ok(await page.locator('#notice-banner').innerText().then((t) => t.includes('6 contacts')), '…the banner counts six');

  console.log('1280 wide — Export all matching, then the download');
  await page.click('label[for=bulk-scope-all]');
  await page.click('#bulk-actions-summary');
  await Promise.all([page.waitForURL(/\/exports/, { timeout: 10000 }), page.click('#bulk-export-btn')]);
  const exp = Number(sql(`SELECT max(id) FROM exports WHERE created_by = 27`));
  ok(await page.locator(`#export-${exp}`).count() === 1 && (await page.locator(`#export-${exp}-state`).innerText()).includes('Waiting'), 'Export lands on /exports: the row, "Waiting its turn"');
  worker('exports');
  await page.goto(`${BASE}/exports`, { waitUntil: 'networkidle' });
  ok((await page.locator(`#export-${exp}-state`).innerText()).includes('Ready') && await page.locator(`#export-${exp}-download-btn`).count() === 1, 'once the worker has built it: Ready to download');
  await shot(page, 'exports-1280');
  const [dl] = await Promise.all([page.waitForEvent('download', { timeout: 15000 }), page.click(`#export-${exp}-download-btn`)]);
  const path = await dl.path();
  const text = fs.readFileSync(path, 'utf8');
  ok(dl.suggestedFilename() === `sales-crm-contacts-${exp}.csv` && text.split('\n')[0].startsWith('Id,Name,Title,Email') && (text.match(/Bulker/g) || []).length === 6, 'the browser downloads sales-crm-contacts-' + exp + '.csv: the header and six rows');
  await page.goto(`${BASE}/exports`, { waitUntil: 'networkidle' });
  ok((await page.locator(`#export-${exp}-state`).innerText()).includes('Downloaded') && await page.locator(`#export-${exp}-download-btn`).count() === 0, 'afterwards it reads Downloaded and offers no button (once)');

  console.log('1280 wide — the filter builder, its live count, a smart list');
  sql(`UPDATE contacts SET role = 'champion' WHERE id IN (${people[0]}, ${people[1]})`);
  await page.goto(`${BASE}/lists/new?record_kind=contact&kind=smart`, { waitUntil: 'networkidle' });
  ok(await page.locator('#filter-builder').count() === 1 && await page.locator('#filter-row-0').count() === 1, 'the builder: rows #filter-row-0…');
  await page.waitForFunction(() => document.querySelector('#filter-preview-count'), null, { timeout: 10000 });
  await page.selectOption('#filter-field-role', 'champion');
  await page.waitForFunction((n) => { const el = document.querySelector('#filter-preview-count'); return el && Number(el.textContent) >= n; }, 2, { timeout: 10000 });
  const live = Number(await page.locator('#filter-preview-count').innerText());
  ok(live >= 2, 'changing a filter refreshes "N contacts match now": ' + live);
  await page.fill('#list-form-field-name', `Champions ${X}`);
  await shot(page, 'list-new-1280');
  await Promise.all([page.waitForURL(/\/lists\/\d+/, { timeout: 10000 }), page.click('#list-form-save-btn')]);
  const smart = Number(sql(`SELECT max(id) FROM lists WHERE name = 'Champions ${X}'`));
  ok(sql(`SELECT filters->>'role' FROM lists WHERE id = ${smart}`) === 'champion' && await page.locator('#list-filter-chip-0').innerText().then((t) => t.includes('Role: Champion')), 'saved: the list shows its filter as a chip — Role: Champion');
  ok(await page.locator('#list-view-count').innerText().then((t) => t.includes('match now')) && await page.locator('#bulk-bar').count() === 1, '…its live count, and the bar');
  await shot(page, 'list-view-smart-1280');
  await page.click('#list-edit-filters-btn');
  await page.waitForSelector('#filter-builder', { timeout: 10000 });
  ok((await page.inputValue('#filter-field-role')) === 'champion', 'Edit filters opens the builder with the filter in place');

  console.log('1280 wide — a static list; the bar on its page');
  const lst = Number(sql(`INSERT INTO lists (name, kind, record_kind, owner_member_id, created_by) VALUES ('Hand ${X}', 'static', 'contact', 27, 27) RETURNING id`));
  await page.goto(`${BASE}/contacts?tag=picked-${X}`, { waitUntil: 'networkidle' });
  await page.check('#bulk-select-all');
  await page.click('#bulk-actions-summary');
  await pick(page, '#bulk-target-list-open', `Hand ${X}`, `#record-picker-row-${lst}`);
  await Promise.all([page.waitForURL(/notice=bulk_list/, { timeout: 10000 }), page.click('#bulk-list-btn')]);
  ok(sql(`SELECT member_count FROM lists WHERE id = ${lst}`) === '2', 'Add to a list (picked): the two ticked are in it, the count is the trigger\'s');
  await page.goto(`${BASE}/lists/${lst}`, { waitUntil: 'networkidle' });
  ok(await page.locator('#list-rows .col-md-6').count() === 2 && await page.locator('#bulk-scope-all-label').innerText().then((t) => /the whole list/i.test(t)), 'its page: two cards, the bar acting on "The whole list"');
  await page.check(`#bulk-tick-contact-${people[0]}`);
  await shot(page, 'list-view-static-1280');
  ok(errors.length === 0, 'no console error: ' + errors.join('; '));
  await page.context().close();
}

{
  const { page, errors } = await session(27, { width: 1280, height: 800 });
  console.log('1280 wide — a campaign: made, a member added, the email queued and sent');
  await page.goto(`${BASE}/campaigns`, { waitUntil: 'networkidle' });
  await page.click('#campaign-new-btn');
  await page.waitForSelector('#campaign-form', { timeout: 10000 });
  await page.fill('#campaign-form-field-name', `Browser camp ${X}`);
  await page.selectOption('#campaign-form-field-status', 'active');
  await page.fill('#campaign-form-field-budget', '1200');
  await Promise.all([page.waitForURL(/\/campaigns\/\d+/, { timeout: 10000 }), page.click('#campaign-form-save-btn')]);
  const camp = Number(sql(`SELECT max(id) FROM campaigns WHERE name = 'Browser camp ${X}'`));
  ok(camp > 0 && await page.locator('#campaign-results').count() === 1 && await page.locator('#campaign-view-status').innerText().then((t) => t.includes('Active')), 'New campaign: saved, its page opens with the results tiles');
  await pick(page, '#campaign-add-contact-open', `Ada Bulker ${X}`, `.record-picker-row[data-label="Ada Bulker ${X}"]`);
  await Promise.all([page.waitForURL(/notice=member_added/, { timeout: 10000 }), page.click('#campaign-add-contact-btn')]);
  ok(sql(`SELECT count(*) FROM campaign_members WHERE campaign_id = ${camp} AND contact_id = ${people[0]} AND status = 'planned'`) === '1' && await page.locator('#campaign-members-total').innerText() === '1', 'Add a contact by the picker: a planned member');
  await pick(page, '#campaign-add-list-open', `Hand ${X}`, `.record-picker-row[data-label="Hand ${X}"]`);
  await Promise.all([page.waitForURL(/notice=bulk_campaign/, { timeout: 10000 }), page.click('#campaign-add-list-btn')]);
  ok(sql(`SELECT count(*) FROM campaign_members WHERE campaign_id = ${camp}`) === '2', 'Add everyone on a list (the picker): the list\'s second contact joins');
  await page.locator('#campaign-members-table select[id^="campaign-member-"]').first().selectOption('responded');
  await Promise.all([page.waitForLoadState('networkidle'), page.locator('#campaign-members-table button[id$="-save-btn"]').first().click()]);
  for (let i = 0; i < 25 && sql(`SELECT count(*) FROM campaign_members WHERE campaign_id = ${camp} AND status = 'responded'`) !== '1'; i++) { await page.waitForTimeout(200); }
  ok(sql(`SELECT count(*) FROM campaign_members WHERE campaign_id = ${camp} AND status = 'responded'`) === '1', 'a member\'s status changed in the row');
  await pick(page, '#campaign-email-template-open', `Bro6 ${X}`, `#record-picker-row-${tpl}`);
  await page.selectOption('#campaign-email-members', 'all');
  await page.check('#campaign-email-confirm');
  await shot(page, 'campaign-email-1280');
  await Promise.all([page.waitForURL(/notice=email_queued/, { timeout: 10000 }), page.click('#campaign-email-send-btn')]);
  ok(await page.locator('#campaign-email-queued').innerText().then((t) => t.startsWith('2 waiting')) && mine().length === 0, 'Email members: "2 waiting", and the fake MaluMail has received nothing yet');
  worker('outbox');
  await page.reload({ waitUntil: 'networkidle' });
  ok(await page.locator('#campaign-email-sent').innerText().then((t) => t.startsWith('2 sent')) && mine().length === 2 && mine().every((m) => /\/u\/[a-f0-9]{48}/.test(m.text)), 'after the worker: "2 sent"; the fake received two messages, each with its own unsubscribe link');
  await shot(page, 'campaign-view-1280');
  ok(errors.length === 0, 'no console error: ' + errors.join('; '));
  await page.context().close();
}

{
  console.log('JavaScript off — the bar still posts the form');
  const ctx = await browser.newContext({ viewport: { width: 375, height: 740 }, javaScriptEnabled: false });
  const page = await ctx.newPage();
  await page.goto(mint(27), { waitUntil: 'networkidle' });
  await page.goto(`${BASE}/contacts?tag=bro6-${X}`, { waitUntil: 'networkidle' });
  ok(await page.locator('#bulk-bar').count() === 1 && await page.locator('details#bulk-actions').count() === 1, 'the bar renders without scripts, the actions in a <details>');
  await tap(page, `#bulk-tick-contact-${people[3]}`);
  await tap(page, '#bulk-actions-summary');
  await page.fill('#bulk-tag', `nojs-${X}`);
  await Promise.all([page.waitForURL(/notice=bulk_tag/, { timeout: 10000 }), tap(page, '#bulk-tag-btn')]);
  ok(sql(`SELECT count(*) FROM record_tags rt JOIN tags t ON t.id = rt.tag_id WHERE t.label = 'nojs-${X}' AND rt.record_id = ${people[3]}`) === '1' && (await page.locator('#notice-banner').innerText()).includes('Changed the tags of 1 contact.'), 'a tick, the tag, the button: a plain form post tags the one contact, the banner says so');
  await tap(page, '#bulk-actions-summary');
  await Promise.all([page.waitForURL(/notice=bulk_tag/, { timeout: 10000 }), tap(page, '#bulk-tag-btn')]);
  ok(true, 'and with nothing ticked the server says what to do (it refuses in words, not a blank page)');
  await ctx.close();
}

{
  console.log('A Viewer sees no bar; a Rep sees the bar without the manager\'s actions');
  const v = await session(29, { width: 375, height: 740 });
  await v.page.goto(`${BASE}/contacts?tag=bro6-${X}`, { waitUntil: 'networkidle' });
  ok(await v.page.locator('#bulk-bar').count() === 0 && await v.page.locator('.bulk-tick').count() === 0, 'a Viewer: no bar, no ticks');
  await v.ctx.close();
  const r = await session(26, { width: 375, height: 740 });
  await r.page.goto(`${BASE}/contacts?tag=bro6-${X}`, { waitUntil: 'networkidle' });
  await r.page.click('#bulk-actions-summary');
  ok(await r.page.locator('#bulk-tag-btn').count() === 1 && await r.page.locator('#bulk-assign-btn').count() === 0 && await r.page.locator('#bulk-delete-btn').count() === 0 && (await overflow(r.page)) <= 1, 'a Rep: tag, list and export only; no sideways scroll at 375');
  await shot(r.page, 'bar-rep-375');
  await r.ctx.close();
}

console.log('Every screen at 375 x 740 and 1280 x 800');
const camp = Number(sql(`SELECT max(id) FROM campaigns WHERE name = 'Browser camp ${X}'`));
const lstS = Number(sql(`SELECT max(id) FROM lists WHERE name = 'Champions ${X}'`));
const lstH = Number(sql(`SELECT max(id) FROM lists WHERE name = 'Hand ${X}'`));
const dealList = '/pipeline/new_business/list?status=all';
const dealL = Number(sql(`INSERT INTO lists (name, kind, record_kind, owner_member_id, created_by) VALUES ('Deals ${X}', 'static', 'deal', 27, 27) RETURNING id`));
sql(`INSERT INTO list_members (list_id, record_id) SELECT ${dealL}, id FROM deals WHERE deleted_at IS NULL ORDER BY id LIMIT 3`);
const screens = [['campaigns', '/campaigns'], ['campaign-view', `/campaigns/${camp}`], ['campaign-new', '/campaigns/new'], ['campaign-edit', `/campaigns/${camp}/edit`], ['lists', '/lists'], ['list-smart', `/lists/${lstS}`],
  ['list-static', `/lists/${lstH}`], ['list-deals', `/lists/${dealL}`], ['list-new-smart', '/lists/new?record_kind=deal&kind=smart'], ['list-new-static', '/lists/new?record_kind=lead&kind=static'], ['list-edit', `/lists/${lstS}/edit`],
  ['exports', '/exports'], ['contacts-bar', `/contacts?tag=bro6-${X}`], ['leads-bar', '/leads'], ['accounts-bar', '/accounts'], ['deal-list-bar', dealList]];
for (const [w, h] of [[375, 740], [1280, 800]]) {
  const { page, errors } = await session(27, { width: w, height: h });
  for (const [name, url] of screens) {
    await page.goto(BASE + url, { waitUntil: 'networkidle' });
    if (name.endsWith('-bar') && await page.locator('#bulk-actions-summary').count() === 1) { await page.click('#bulk-actions-summary'); }
    const o = await overflow(page);
    ok(o <= 1, `${name} at ${w}: no sideways scroll (${o}px)`);
    await shot(page, `${name}-${w}`);
  }
  ok(errors.length === 0, `no console error at ${w}: ` + errors.join('; '));
  await page.context().close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
