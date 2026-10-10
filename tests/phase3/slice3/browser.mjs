// Headless Chromium proof of slice 3, the board: a card dragged to another stage (the server's columns come back); a drag the stage refuses (the flash names
// the fields, the card snaps back); a drop on Won opens the Won form and posting it closes the deal; the card menu's Move to… with JavaScript off; the stage
// bar on the deal page; New deal from an account prefilled; a line added; Save this view; the poll picking up another seller's move; the nine screens at
// 375 x 740 and 1280 x 800 with no sideways scroll and no console error; at 375 the stage chooser scrolls to a column.
// Run through tests/phase3/slice3/run.sh. Playwright from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8801';
const ROOT = new URL('../../../', import.meta.url).pathname;
const SHOTS = process.env.SHOTS || '/tmp/crm-shots/slice3';
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
const sql = (q) => execFileSync('sudo', ['-n', '-u', 'postgres', 'psql', '-At', '-d', process.env.DB_NAME, '-c', q], { encoding: 'utf8' }).trim().split('\n')[0];
const stage = (name, p = 1) => Number(sql(`SELECT id FROM stages WHERE pipeline_id = ${p} AND name = '${name}'`));
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const browser = await chromium.launch();
async function session(member, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/status of 422/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.on('dialog', (d) => d.accept());
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
// Fixtures: an account with a contact; three deals of Priya's — one ready for any stage, one lacking a contact, one to win.
const acc = Number(sql(`INSERT INTO accounts (name, owner_member_id, created_by) VALUES ('Browser Co ${X}', 26, 26) RETURNING id`));
const con = Number(sql(`INSERT INTO contacts (first_name, last_name, account_id, owner_member_id, created_by) VALUES ('Bea', 'Browser ${X}', ${acc}, 26, 26) RETURNING id`));
const mk = (title, extra = '') => Number(sql(`INSERT INTO deals (title, account_id, primary_contact_id, pipeline_id, stage_id, value, expected_close_date, owner_member_id, created_by)
  VALUES ('${title} ${X}', ${acc}, ${extra === 'bare' ? 'NULL' : con}, 1, ${stage('Qualification')}, 12000, ${extra === 'bare' ? 'NULL' : "current_date + 20"}, 26, 26) RETURNING id`));
const ready = mk('Ready'), bare = mk('Bare', 'bare'), win = mk('Winner'), menu = mk('Menu'), poll = mk('Polled');
const board = `${BASE}/pipeline/new_business/board?q=${X}`;
{
  const { ctx, page, errors } = await session(26, { width: 1920, height: 900 });       // wide enough that every column, Won and Lost too, is on screen
  console.log('1920 x 900 — the drag');
  await page.goto(board, { waitUntil: 'networkidle' });
  ok(await page.evaluate(() => !!window.Sortable), 'SortableJS is loaded on the board');
  await page.locator(`#deal-card-${ready}`).dragTo(page.locator(`#board-col-${stage('Discovery')}-body`));
  await page.waitForSelector(`#board-col-${stage('Discovery')}-body #deal-card-${ready}`, { timeout: 10000 });
  await page.waitForTimeout(300);
  ok(sql(`SELECT stage_id FROM deals WHERE id = ${ready}`) === String(stage('Discovery')), 'a card dragged to Discovery: deal_move posted, the deal is in Discovery');
  await page.locator(`#deal-card-${bare}`).dragTo(page.locator(`#board-col-${stage('Proposal')}-body`));
  await page.waitForSelector('#flash-message', { timeout: 10000 });
  ok((await page.locator('#flash-message').innerText()).includes('Proposal needs: primary contact'), 'a drag the stage refuses: the flash names the fields');
  await page.waitForSelector(`#board-col-${stage('Qualification')}-body #deal-card-${bare}`, { timeout: 10000 });
  ok(sql(`SELECT stage_id FROM deals WHERE id = ${bare}`) === String(stage('Qualification')), '…and the card snaps back to Qualification');
  await page.screenshot({ path: `${SHOTS}/desktop-board-refused.png` });
  await page.locator(`#deal-card-${win}`).dragTo(page.locator(`#board-col-${stage('Won')}-body`));
  await page.waitForURL(new RegExp(`/deals/${win}/won$`), { timeout: 10000 });
  ok(sql(`SELECT status FROM deals WHERE id = ${win}`) === 'open', 'a drop on Won opens the Won form — nothing closed silently');
  await page.fill('#deal-won-field-value', '15000');
  await page.screenshot({ path: `${SHOTS}/desktop-won.png` });
  await page.click('#deal-won-save-btn');
  await page.waitForURL(new RegExp(`/deals/${win}\\?notice=won`));
  ok(sql(`SELECT status || ' ' || value FROM deals WHERE id = ${win}`) === 'won 15000.00' && sql(`SELECT kind FROM accounts WHERE id = ${acc}`) === 'customer', 'posting it wins the deal at the confirmed value; the account is a customer');
  ok((await page.locator('#deal-stage-bar-closed').innerText()).includes('Won'), 'the deal page\'s stage bar ends in Won');

  console.log('1920 x 900 — the deal page');
  await page.goto(`${BASE}/deals/${ready}`, { waitUntil: 'networkidle' });
  await page.click(`#deal-stage-bar-${stage('Negotiation')} button`);
  await page.waitForURL(new RegExp(`/deals/${ready}\\?notice=moved`));
  ok(sql(`SELECT stage_id FROM deals WHERE id = ${ready}`) === String(stage('Negotiation')) && (await page.locator(`#deal-stage-bar-${stage('Negotiation')}`).getAttribute('class')).includes('deal-stage-current'), 'a click on the stage bar moves the deal; the bar marks it');
  await page.fill('#deal-product-add-field-name', 'Seats');
  await page.fill('#deal-product-add-field-quantity', '4');
  await page.fill('#deal-product-add-field-unit_price', '250');
  await page.click('#deal-product-add-btn');
  await page.waitForURL(/notice=product_added/);
  ok((await page.locator('#deal-products-total').innerText()).includes('$1,000'), 'a line added through the form: the total');
  await page.screenshot({ path: `${SHOTS}/desktop-deal.png`, fullPage: true });
  await page.goto(`${BASE}/accounts/${acc}`, { waitUntil: 'networkidle' });
  await page.click('#account-new-deal-btn');
  await page.waitForURL(new RegExp(`/deals/new\\?account=${acc}`));
  ok((await page.inputValue('#deal-form-field-title')) === `Browser Co ${X} — new business` && (await page.inputValue('#deal-form-field-account')) === String(acc), 'New deal from the account: prefilled title and account');
  await page.click('#deal-form-save-btn');
  await page.waitForURL(/\/deals\/\d+\?notice=created/);
  ok((await page.locator('#deal-view-title').innerText()).includes(`Browser Co ${X} — new business`), 'saved, it lands on the new deal');

  console.log('1920 x 900 — a saved view and the poll');
  await page.goto(`${board}&owner=me`, { waitUntil: 'networkidle' });
  await page.click('#board-save-view summary');
  await page.fill('#board-save-view-field-name', `Mine ${X}`);
  await page.click('#board-save-view-save-btn');
  await page.waitForURL(/\/pipeline\/new_business\/board\?view=\d+/);
  ok((await page.locator('#board-saved-views .btn-info').textContent()).includes(`Mine ${X}`), 'Save this view: it runs, its chip lit');
  await page.goto(board, { waitUntil: 'networkidle' });
  sql(`SELECT 1`);
  execFileSync('php', ['-r', `require '${ROOT}tests/phase3/slice3/lib.php'; post_as(27, '/deals/${poll}/move', ['deal' => ${poll}, 'to_stage' => 'Proposal']);`], { env: process.env });
  await page.waitForSelector(`#board-col-${stage('Proposal')}-body #deal-card-${poll}`, { timeout: 40000 });
  ok(true, 'the poll (every 30 s): another person\'s move appears without a reload');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
{
  const { ctx, page } = await session(26, { width: 375, height: 740 }, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await page.goto(board, { waitUntil: 'load' });
  await page.click(`#deal-card-${menu}-menu summary`);
  await page.selectOption(`#deal-card-${menu}-move`, String(stage('Discovery')));
  await page.click(`#deal-card-${menu}-move-btn`);
  await page.waitForURL(/\/pipeline\/new_business\/board/);
  ok(sql(`SELECT stage_id FROM deals WHERE id = ${menu}`) === String(stage('Discovery')) && (await page.locator(`#board-col-${stage('Discovery')}-body #deal-card-${menu}`).count()) === 1, 'the card menu\'s Move to… posts a plain form and lands back on the board, the card moved');
  await page.goto(`${BASE}/deals/${menu}/lost`, { waitUntil: 'load' });
  await page.check('#deal-lost-reason-3');
  await page.click('#deal-lost-save-btn');
  await page.waitForURL(new RegExp(`/deals/${menu}\\?notice=lost`));
  ok(sql(`SELECT status FROM deals WHERE id = ${menu}`) === 'lost', 'the Lost form works from the server alone');
  await ctx.close();
}
const d = ready;
const screens = { 'board': '/pipeline/new_business/board', 'deal-list': '/pipeline/new_business/list', 'forecast': '/pipeline/new_business/forecast', 'deal-view': `/deals/${d}`,
  'deal-add': '/deals/new', 'deal-edit': `/deals/${d}/edit`, 'deal-won': `/deals/${d}/won`, 'deal-lost': `/deals/${d}/lost`, 'saved-views': '/views' };
for (const [w, h] of [[375, 740], [1280, 800]]) {
  const { ctx, page, errors } = await session(27, { width: w, height: h });
  console.log(`${w} x ${h} — the nine screens`);
  for (const [id, url] of Object.entries(screens)) {
    await page.goto(BASE + url, { waitUntil: 'networkidle' });
    const o = await overflow(page);
    const stamp = await page.evaluate(() => document.getElementById('page-content').dataset.screen);
    ok(o <= 0 && stamp === id, `${id}: no sideways scroll (${o}), stamped ${stamp}`);
    await page.screenshot({ path: `${SHOTS}/${w}-${id}.png`, fullPage: w === 375 && id !== 'board' });
  }
  if (w === 375) {
    await page.goto(BASE + screens.board, { waitUntil: 'networkidle' });
    await page.selectOption('#board-stage-chooser', String(stage('Negotiation')));
    await page.waitForTimeout(600);
    const left = await page.evaluate((s) => { const c = document.getElementById('board-col-' + s), r = document.getElementById('board-columns-scroller'); return c.getBoundingClientRect().left - r.getBoundingClientRect().left; }, stage('Negotiation'));
    ok(Math.abs(left) < 30, 'at 375 the stage chooser scrolls the region to that column (one column wide)');
    ok(await page.evaluate(() => [...document.querySelectorAll('.board-card-tag')].every((t) => getComputedStyle(t).display === 'none')), 'at 375 the cards keep everything but the tag chips');
  }
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
