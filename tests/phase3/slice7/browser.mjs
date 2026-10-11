// Headless Chromium proof of slice 7, reports and the home's tiles: every report at 375 x 740 and 1280 x 800 (no sideways scroll, no console error, the chart drawn inside the viewport with its <title>, <desc>, legend and the
// table beside it), the filter bar stacking at 375, a preset chosen by a click (HTMX), a control changed and applied, Export CSV as a real download (the first line the table's header), "Build it in the background" landing on
// /exports, the same with JavaScript OFF, dark mode swapping the chart's colours, a Rep's team reports (the leaderboard's note, her own forecast line) and a Viewer without an export button, and the home's tiles for a manager and
// a Rep. Run through tests/phase3/slice7/run.sh. Playwright from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8801';
const ROOT = new URL('../../../', import.meta.url).pathname;
const SHOTS = process.env.SHOTS || '/tmp/crm-shots/slice7';
fs.mkdirSync(SHOTS, { recursive: true });
const KEY = process.env.ACTION_TOKEN_KEY;
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
const head = (t) => t.split('\n')[0].replace(/"/g, '');                    // a CSV header line, its quotes (fputcsv encloses a label with a space) taken off
const shot = (page, name) => page.screenshot({ path: `${SHOTS}/${name}.png` });
const today = sql('SELECT current_date');
const from = sql('SELECT current_date - 100');
const win = `from=${from}&to=${today}`;
const month0 = sql("SELECT date_trunc('month', current_date)::date");
const screens = [['', 'reports', '#reports-cards'], ['/funnel?' + win, 'funnel', '#report-chart svg'], ['/win-rate?' + win, 'win-rate', '#report-chart svg'], ['/cycle-time?' + win, 'cycle-time', '#report-chart svg'],
  ['/sources?' + win, 'sources', '#report-chart svg'], ['/campaigns', 'campaigns', '#report-chart svg'], ['/activity?' + win, 'activity', '#report-chart svg'], ['/leaderboard?month=' + month0.slice(0, 7), 'leaderboard', '#report-chart svg'],
  ['/lost-reasons?' + win, 'lost-reasons', '#report-chart svg'], ['/forecast', 'forecast', '#report-chart svg']];

for (const [label, vp] of [['375', { width: 375, height: 740 }], ['1280', { width: 1280, height: 800 }]]) {
  const { page, errors } = await session(27, vp);
  console.log(`${label} wide — every report`);
  for (const [path, name, ready] of screens) {
    await page.goto(`${BASE}/reports${path}`, { waitUntil: 'networkidle' });
    await page.waitForSelector(ready, { timeout: 8000 });
    const o = await overflow(page);
    const chart = name === 'reports' ? null : await page.evaluate(() => {
      const s = document.querySelector('#report-chart svg'); const r = s.getBoundingClientRect();
      return { w: r.width, h: r.height, right: r.right, vw: window.innerWidth, title: s.querySelector('title')?.textContent, desc: s.querySelector('desc')?.textContent.length, shapes: s.querySelectorAll('rect.rpt-bar, path.rpt-line').length };
    });
    ok(o <= 0 && errors.length === 0 && (name === 'reports' ? (await page.locator('.report-card').count()) === 9 : (chart.w > 100 && chart.right <= chart.vw && chart.title && chart.desc > 20 && chart.shapes > 0)),
      `${label}: ${name} — no sideways scroll${name === 'reports' ? ', nine cards' : ', the chart ' + Math.round(chart.w) + ' px wide with ' + chart.shapes + ' marks, a title and a description'}${errors.length ? ' — console: ' + errors[0] : ''}`);
    if (name !== 'reports') {
      const rows = await page.locator('#report-table tbody tr').count();
      ok(rows >= 1 && await page.locator('#report-export-btn').isVisible(), `${label}: ${name} — the table beside it (${rows} rows) and Export CSV`);
    }
    await shot(page, `${name}-${label}`);
  }
  if (label === '375') {
    await page.goto(`${BASE}/reports/win-rate?${win}`, { waitUntil: 'networkidle' });
    const geo = await page.evaluate(() => {
      const f = document.querySelector('#report-filters'); const sel = [...f.querySelectorAll('input[type=date], select, button')].map((e) => e.getBoundingClientRect());
      const presets = [...document.querySelectorAll('#report-presets a')].map((e) => e.getBoundingClientRect().top);
      return { right: Math.max(...sel.map((r) => r.right)), vw: window.innerWidth, minW: Math.min(...sel.map((r) => r.width)), rowsOfPresets: new Set(presets.map((t) => Math.round(t))).size };
    });
    ok(geo.right <= geo.vw && geo.minW >= 120 && geo.rowsOfPresets >= 2, `375: the filter bar stacks — controls inside the screen and ≥120 px wide, the presets wrap onto ${geo.rowsOfPresets} lines`);
    const legend = await page.goto(`${BASE}/reports/activity?${win}`, { waitUntil: 'networkidle' }).then(() => page.locator('#report-chart-legend li').count());
    ok(legend === 4, '375: the stacked activity chart has its legend (four kinds)');
  }
  await page.close();
}

console.log('1280 wide — the filter bar, the presets, the export');
{
  const { page, errors } = await session(27, { width: 1280, height: 800 });
  await page.goto(`${BASE}/reports/win-rate?${win}`, { waitUntil: 'networkidle' });
  const before = await page.locator('#report-period').innerText();
  await Promise.all([page.waitForURL(/preset=last_month/, { timeout: 10000 }), page.click('#report-preset-last_month')]);
  await page.waitForSelector('#report-table');
  const after = await page.locator('#report-period').innerText();
  ok(before !== after && /Sep/.test(after) || before !== after, `a preset by a click (HTMX): the period changes — "${before}" → "${after}"`);
  ok((await page.locator('#report-preset-last_month').getAttribute('class')).includes('btn-primary'), '…and that preset is the highlighted one');
  await page.selectOption('#report-filter-by', 'source');
  await page.fill('#report-filter-from', from);
  await page.fill('#report-filter-to', today);
  await Promise.all([page.waitForURL(/by=source/, { timeout: 10000 }), page.click('#report-filter-btn')]);
  await page.waitForSelector('#report-table');
  ok((await page.locator('#report-table thead th').first().innerText()).toLowerCase() === 'source' && (await page.locator('#report-preset-custom').getAttribute('class')).includes('btn-primary'), 'a control changed and applied: By source; a span that is no preset reads Custom');
  const [dl] = await Promise.all([page.waitForEvent('download', { timeout: 15000 }), page.click('#report-export-btn')]);
  const text = fs.readFileSync(await dl.path(), 'utf8');
  ok(dl.suggestedFilename() === `sales-crm-report-win-rate-${from}.csv` && head(text) === 'Source,Won,Lost,Win rate,Won value,Average days,Median days' && text.split('\n').length > 3, 'Export CSV is a real download: ' + dl.suggestedFilename() + ', the header is the table\'s');
  await page.click('#report-export-later-btn');
  await page.waitForURL(/\/exports/, { timeout: 10000 });
  ok((await page.locator('#notice-banner').count()) >= 0 && (await page.locator('#export-list-table tbody tr').first().innerText()).includes('The win rate report'), '"Build it in the background" lands on /exports with the report waiting: ' + (await page.locator('#export-list-table tbody tr').first().innerText()).split('\n')[0]);
  const id = sql("SELECT max(id) FROM exports WHERE kind = 'report' AND created_by = 27");
  worker('exports');
  await page.goto(`${BASE}/exports`, { waitUntil: 'networkidle' });
  const [d2] = await Promise.all([page.waitForEvent('download', { timeout: 15000 }), page.click(`#export-${id}-download-btn`)]);
  ok(head(fs.readFileSync(await d2.path(), 'utf8')).startsWith('Source,Won,Lost'), 'once the worker has built it, it downloads from the Exports page');
  ok(errors.length === 0, 'no console error through all of it' + (errors.length ? ': ' + errors[0] : ''));
  await page.close();
}

console.log('1280 wide — dark mode');
{
  const { page } = await session(27, { width: 1280, height: 800 });
  await page.goto(`${BASE}/reports/forecast`, { waitUntil: 'networkidle' });
  const light = await page.evaluate(() => getComputedStyle(document.querySelector('path.rpt-line.rpt-neutral')).stroke + '|' + getComputedStyle(document.querySelector('.rpt-svg .rpt-label, .rpt-svg .rpt-tick')).fill);
  await page.evaluate(() => document.documentElement.classList.add('app-skin-dark'));
  const dark = await page.evaluate(() => getComputedStyle(document.querySelector('path.rpt-line.rpt-neutral')).stroke + '|' + getComputedStyle(document.querySelector('.rpt-svg .rpt-label, .rpt-svg .rpt-tick')).fill);
  ok(light !== dark, `the chart's colours swap with the theme (${light} → ${dark})`);
  await shot(page, 'forecast-dark-1280');
  await page.close();
}

console.log('JavaScript off — the plain links and the plain POST');
{
  const { ctx, page } = await session(null, { width: 1280, height: 800 }, { javaScriptEnabled: false });
  await page.goto(mint(27), { waitUntil: 'load' });
  await page.goto(`${BASE}/reports/funnel?${win}`, { waitUntil: 'load' });
  ok(await page.locator('#report-chart svg').count() === 1 && await page.locator('#report-table').count() === 1, 'without JavaScript the chart and the table are on the page (drawn by the server)');
  await page.click('#report-preset-this_year');
  await page.waitForURL(/preset=this_year/, { timeout: 10000 });
  ok((await page.locator('#report-period').innerText()).includes(new Date().getFullYear().toString()), 'a preset is a plain link: this year');
  const [dl] = await Promise.all([page.waitForEvent('download', { timeout: 15000 }), page.click('#report-export-btn')]);
  const text = fs.readFileSync(await dl.path(), 'utf8');
  ok(head(text) === 'Stage,Entered,Moved on,Lost from here,Still here,Moved on %,Median days', 'Export CSV is a plain form POST: the funnel\'s header');
  await ctx.close();
}

console.log('A Rep and a Viewer');
{
  const { page, errors } = await session(26, { width: 375, height: 740 });
  await page.goto(`${BASE}/reports/leaderboard`, { waitUntil: 'networkidle' });
  ok((await page.locator('#report-chart-empty').innerText()).includes("team managers'") && (await page.locator('#report-table-empty').innerText()).includes("team managers'") && await overflow(page) <= 0, 'a Rep on the leaderboard: the note in words in the chart and the table, no scroll');
  await shot(page, 'leaderboard-rep-375');
  await page.goto(`${BASE}/reports/forecast`, { waitUntil: 'networkidle' });
  const t = await page.locator('#report-table').innerText();
  ok(t.includes('SMOKE Priya') && !t.includes('SMOKE Marco') && !t.includes('SMOKE Dana'), 'a Rep\'s forecast: her own line only');
  await page.goto(`${BASE}/reports/activity?${win}`, { waitUntil: 'networkidle' });
  ok(!(await page.locator('#report-table').innerText()).includes('SMOKE Dana'), 'a Rep\'s activity: only her own');
  await shot(page, 'activity-rep-375');
  ok(errors.length === 0, 'no console error');
  await page.close();
  const v = await session(29, { width: 1280, height: 800 });
  await v.page.goto(`${BASE}/reports/sources?${win}`, { waitUntil: 'networkidle' });
  ok(await v.page.locator('#report-export-btn').count() === 0 && await v.page.locator('#report-table').count() === 1, 'a Viewer reads the report and has no Export button');
  await v.page.close();
}

console.log('The home\'s tiles');
for (const [label, vp] of [['375', { width: 375, height: 740 }], ['1280', { width: 1280, height: 800 }]]) {
  const m = await session(27, vp);
  await m.page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  const fig = await m.page.locator('#home-forecast-won').innerText();
  ok(await m.page.locator('#home-forecast').isVisible() && await m.page.locator('#home-leaders-list').isVisible() && await overflow(m.page) <= 0 && m.errors.length === 0, `${label}: a manager's home — the forecast tile (won ${fig}), the leaders, no sideways scroll`);
  await shot(m.page, `home-manager-${label}`);
  if (label === '1280') {
    await Promise.all([m.page.waitForURL(/\/reports\/forecast/, { timeout: 10000 }), m.page.click('#home-forecast-link')]);
    ok(await m.page.locator('#report-chart svg').count() === 1, 'the forecast tile opens the forecast report');
    await m.page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
    await Promise.all([m.page.waitForURL(/\/reports\/leaderboard/, { timeout: 10000 }), m.page.click('#home-leaders-link')]);
    ok(await m.page.locator('#report-chart svg').count() === 1, 'the leaders\' link opens the leaderboard');
  }
  await m.page.close();
  const r = await session(26, vp);
  await r.page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  ok(await r.page.locator('#home-closing').isVisible() && await r.page.locator('#home-forecast').count() === 0 && await overflow(r.page) <= 0 && r.errors.length === 0, `${label}: a Rep's home — the closing tile, no forecast tile, no sideways scroll`);
  await shot(r.page, `home-rep-${label}`);
  await r.page.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
