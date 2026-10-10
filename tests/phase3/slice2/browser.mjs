// Headless Chromium proof of slice 2: a lead added through its form and converted through the conversion form (Found / New, then the landing); the inbox's
// Accept; an import uploaded in the browser — the columns mapped from the header row, one changed by hand — dry-run with the page polling until the worker
// finishes, then run; the merge with the other record chosen; the eleven screens at 375 x 740 and 1280 x 800 with no sideways scroll and no console error;
// with JavaScript off the import form still posts (the server maps the columns) and the lead page is whole.
// Run through tests/phase3/slice2/run.sh. Playwright from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8801';
const ROOT = new URL('../../../', import.meta.url).pathname;
const SHOTS = process.env.SHOTS || '/tmp/crm-shots/slice2';
fs.mkdirSync(SHOTS, { recursive: true });
const KEY = process.env.ACTION_TOKEN_KEY;
const X = crypto.randomBytes(3).toString('hex');
const fixture = JSON.parse(fs.readFileSync(ROOT + 'bin/dev_directory.json', 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.sales_crm.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const claims = { ...fixture.claims[String(member)], member_id: member };
  const text = Buffer.from(JSON.stringify(claims)).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
const worker = () => execFileSync('php', [ROOT + 'bin/worker.php', '--passes=imports'], { encoding: 'utf8' });
const sql = (q) => execFileSync('sudo', ['-n', '-u', 'postgres', 'psql', '-At', '-d', process.env.DB_NAME, '-c', q], { encoding: 'utf8' }).trim();
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const browser = await chromium.launch();
async function session(member, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.on('dialog', (d) => d.accept());
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const idOf = (page) => Number(new URL(page.url()).pathname.split('/').filter(Boolean)[1]);

let lead, imp, a, b;
{
  const { ctx, page, errors } = await session(26, { width: 1280, height: 800 });
  console.log('1280 x 800 — a lead, its conversion, an import');
  await page.goto(BASE + '/leads/new', { waitUntil: 'networkidle' });
  await page.fill('#lead-form-field-first_name', 'Grace');
  await page.fill('#lead-form-field-last_name', `Lead ${X}`);
  await page.fill('#lead-form-field-company', `Grace Co ${X}`);
  await page.fill('#lead-form-field-email', `grace.${X}@graceco-${X}.example.com`);
  await page.click('#lead-form-save-btn');
  await page.waitForURL(/\/leads\/\d+\?notice=created/);
  lead = idOf(page);
  ok(lead > 0 && (await page.locator('#lead-view-name').innerText()).includes(`Grace Lead ${X}`), 'the form adds the lead and lands on it');
  await page.fill('#lead-qualify-field-score', '75');
  await page.click('#lead-qualify-btn');
  await page.waitForSelector('#lead-qualify-form .lead-score span:text("75")');
  ok(true, 'the score is saved and its ring shows 75');
  await page.click('#lead-convert-btn');
  await page.waitForURL(new RegExp(`/leads/${lead}/convert$`));
  ok((await page.locator('#convert-account-new').innerText()).includes(`New: Grace Co ${X}`) && (await page.locator('#convert-contact-new').innerText()).includes(`Grace Lead ${X}`), 'the conversion form says New for the account and the contact');
  await page.fill('#convert-form-field-value', '18000');
  await page.screenshot({ path: `${SHOTS}/desktop-convert.png` });
  await page.click('#convert-form-save-btn');
  await page.waitForURL(/\/contacts\/\d+\?notice=converted/);
  ok((await page.locator('#contact-view-account-link').innerText()).includes(`Grace Co ${X}`), 'converting lands on the new contact, at the new account (the deal page is slice 3\'s)');
  await page.goto(BASE + `/leads/${lead}`, { waitUntil: 'networkidle' });
  ok((await page.locator('#lead-converted-account').count()) === 1 && (await page.locator('#lead-converted-deal').count()) === 1 && (await page.locator('#lead-edit-btn').count()) === 0, 'the lead now shows its three records as cards and no Edit');

  const csv = path.join(os.tmpdir(), `crm-browser-${X}.csv`);
  fs.writeFileSync(csv, 'Given Name,Surname,Org,Mail Address\n' + Array.from({ length: 25 }, (_, i) => `G${i},S${i} ${X},Org ${i} ${X},g${i}.${X}@org${i}.example.com`).join('\n') + '\n');
  await page.goto(BASE + '/import/new?kind=leads_csv', { waitUntil: 'networkidle' });
  await page.setInputFiles('#import-form-field-file', csv);
  await page.waitForSelector('#import-map-3');
  ok((await page.locator('#import-mapping-rows select').count()) === 4 && (await page.inputValue('#import-map-0')) === 'first_name' && (await page.inputValue('#import-map-1')) === 'last_name'
     && (await page.inputValue('#import-map-2')) === 'skip' && (await page.inputValue('#import-map-3')) === 'skip', 'the columns appear from the header row: Given Name and Surname map themselves, Org and Mail Address stay "skip"');
  await page.selectOption('#import-map-2', 'company');
  await page.selectOption('#import-map-3', 'email');
  await page.screenshot({ path: `${SHOTS}/desktop-import-mapping.png`, fullPage: true });
  await page.click('#import-form-save-btn');
  await page.waitForURL(/\/import\/\d+\?notice=started/);
  imp = idOf(page);
  ok((await page.locator('#import-status-badge').innerText()).includes('Waiting for its dry run'), 'Dry run lands on the import, waiting');
  worker();
  await page.waitForSelector('#import-run-btn', { timeout: 15000 });
  ok((await page.locator('#import-count-created').innerText()).includes('25 would be created'), 'the page polled, reloaded when the worker finished, and shows 25 would be created');
  await page.click('#import-run-btn');
  await page.waitForURL(new RegExp(`/import/${imp}\\?notice=run`));
  worker();
  await page.waitForSelector('#import-rollback-btn', { timeout: 15000 });
  ok((await page.locator('#import-count-created').innerText()).includes('25 created') && sql(`SELECT count(*) FROM leads WHERE email LIKE '%.${X}@org%'`) === '25', 'Run it for real: 25 leads made, Roll back offered');
  await page.screenshot({ path: `${SHOTS}/desktop-import-view.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
{
  sql(`INSERT INTO leads (first_name, email, is_proposed, message) VALUES ('Inbox', 'inbox.${X}@new.example.com', true, 'hello')`);
  sql(`UPDATE crm_settings SET duplicates_policy = 'warn'`);
  a = Number(sql(`INSERT INTO contacts (first_name, last_name, email, owner_member_id, created_by) VALUES ('Mia', 'Merge ${X}', 'mia.${X}@m.example.com', 26, 26) RETURNING id`).split('\n')[0]);
  b = Number(sql(`INSERT INTO contacts (first_name, last_name, email, title, owner_member_id, created_by) VALUES ('Mia', 'Merged ${X}', 'mia.${X}@m.example.com', 'COO', 26, 26) RETURNING id`).split('\n')[0]);
  const { ctx, page, errors } = await session(27, { width: 1280, height: 800 });
  console.log('1280 x 800 — the inbox and the merge (the Sales Manager)');
  await page.goto(BASE + '/leads/inbox', { waitUntil: 'networkidle' });
  const row = page.locator('[id^="inbox-proposed-"]', { hasText: `inbox.${X}@new.example.com` });
  const pid = Number((await row.getAttribute('id')).split('-').pop());
  await page.click(`#inbox-accept-${pid}`);
  await page.waitForURL(/notice=accepted/);
  ok((await page.locator(`#inbox-proposed-${pid}`).count()) === 0, 'Accept takes it out of Proposed');
  await page.goto(BASE + `/duplicates/merge?kind=contact&a=${a}&b=${b}`, { waitUntil: 'networkidle' });
  ok(await page.isChecked('#merge-survivor-a'), 'the older is chosen by default');
  await page.check('#merge-survivor-b');
  await page.screenshot({ path: `${SHOTS}/desktop-merge.png` });
  await page.click('#merge-form-save-btn');
  await page.waitForURL(new RegExp(`/contacts/${b}\\?notice=merged`));
  ok(sql(`SELECT merged_into_id FROM contacts WHERE id = ${a}`) === String(b), 'choosing the newer keeps it and merges the older into it');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
const screens = { 'lead-list': '/leads', 'lead-inbox': '/leads/inbox', 'lead-view': `/leads/${lead}`, 'lead-add': '/leads/new', 'lead-convert': null, 'lead-edit': null,
  'import-list': '/import', 'import-new': '/import/new', 'import-view': `/import/${imp}`, 'duplicates': '/duplicates?kind=contact', 'merge': null };
const fresh = Number(sql(`INSERT INTO leads (first_name, company, owner_member_id, created_by) VALUES ('Shot', 'Shot Co ${X}', 27, 27) RETURNING id`).split('\n')[0]);
const c1 = Number(sql(`INSERT INTO contacts (first_name, last_name, email, owner_member_id, created_by) VALUES ('Ray', 'Dup ${X}', 'ray.${X}@d.example.com', 27, 27) RETURNING id`).split('\n')[0]);
const c2 = Number(sql(`INSERT INTO contacts (first_name, last_name, email, owner_member_id, created_by) VALUES ('Ray', 'Dupe ${X}', 'ray.${X}@d.example.com', 27, 27) RETURNING id`).split('\n')[0]);
screens['lead-edit'] = `/leads/${fresh}/edit`; screens['lead-convert'] = `/leads/${fresh}/convert`; screens['merge'] = `/duplicates/merge?kind=contact&a=${c1}&b=${c2}`;
for (const [w, h] of [[375, 740], [1280, 800]]) {
  const { ctx, page, errors } = await session(27, { width: w, height: h });
  console.log(`${w} x ${h} — the eleven screens`);
  for (const [id, url] of Object.entries(screens)) {
    await page.goto(BASE + url, { waitUntil: 'networkidle' });
    const o = await overflow(page);
    const stamp = await page.evaluate(() => document.getElementById('page-content').dataset.screen);
    ok(o <= 0 && stamp === id, `${id}: no sideways scroll (${o}), stamped ${stamp}`);
    await page.screenshot({ path: `${SHOTS}/${w}-${id}.png`, fullPage: w === 375 });
  }
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
{
  const { ctx, page } = await session(26, { width: 375, height: 740 }, { javaScriptEnabled: false });
  console.log('JavaScript off');
  const csv = path.join(os.tmpdir(), `crm-nojs-${X}.csv`);
  fs.writeFileSync(csv, 'First Name,Last Name,E-mail\nNo,Script ' + X + ',noscript.' + X + '@nojs.example.com\n');
  await page.goto(BASE + '/import/new?kind=leads_csv', { waitUntil: 'load' });
  ok((await page.locator('#import-mapping-rows select').count()) === 0 && (await page.locator('#import-mapping-hint').innerText()).includes('matched by its name'), 'without JavaScript the form says the columns are matched by their names');
  await page.setInputFiles('#import-form-field-file', csv);
  await page.click('#import-form-save-btn');
  await page.waitForURL(/\/import\/\d+\?notice=started/);
  const id = idOf(page);
  ok(JSON.parse(sql(`SELECT mapping FROM imports WHERE id = ${id}`)).hasOwnProperty('E-mail'), 'the plain form posted and the server mapped the columns');
  await page.goto(BASE + `/leads/${lead}`, { waitUntil: 'load' });
  ok((await page.locator('#lead-converted-contact a').count()) === 1, 'the lead page is whole from the server alone');
  await ctx.close();
}
await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
