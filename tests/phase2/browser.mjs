// Headless Chromium proof of the shell (docs/build-specs/sso-shell.md, "Proof"):
//   at 375 x 740 and 1280 x 800 — scrollWidth = viewport; the bottom tab bar (Today · Pipeline · Calendar · Log · More) on a phone and the sidebar
//   on a desktop; the offcanvas from the menu button and from More; the Log sheet (a dropup, never a modal); the one button for a new person; a
//   Viewer's empty home; Today with its chips once the book holds deals; the header search on a desktop and the magnifier on a phone; the
//   placeholders reached by HTMX (title, highlight, context stamp); the bell; the command bar answering the fake kernel and following a navigate;
//   My settings saving; a token minted and shown once then revoked; every control ≥ 44 px; no console errors; the home whole with JavaScript
//   off; the web app manifest installable.
// Run through tests/phase2/run.sh (it starts the servers and passes the environment). Playwright comes from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8801';
const SHOTS = process.env.SHOTS || '/tmp/crm-shots';
const KEY = process.env.ACTION_TOKEN_KEY;
const STATE = process.env.FAKE_KERNEL_STATE;
const BOARD = '/pipeline/new_business/board';
const fixture = JSON.parse(fs.readFileSync(new URL('../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.sales_crm.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const claims = { ...fixture.claims[String(member)], member_id: member };
  const text = Buffer.from(JSON.stringify(claims)).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
const urls = { get dana() { return mint(30); }, get rep() { return mint(26); }, get manager() { return mint(27); }, get viewer() { return mint(29); }, get admin() { return mint(1); } };
const kernelState = (change) => { const s = JSON.parse(fs.readFileSync(STATE, 'utf8')); const n = change(s) || s; fs.writeFileSync(STATE, JSON.stringify(n)); };
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };

const browser = await chromium.launch();
async function session(signOn, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(signOn, { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
const shown = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0 && r.right > 0 && r.left < window.innerWidth; }, sel);
const toggleMenu = (page) => page.evaluate(() => document.getElementById('mobile-collapse').click());
const smallControls = (page, scope) => page.evaluate((s) => [...document.querySelectorAll(s + ' a.btn, ' + s + ' button, ' + s + ' input:not([type=hidden]):not([type=checkbox]), ' + s + ' select, ' + s + ' textarea, ' + s + ' .app-tab')].filter((a) => { const r = a.getBoundingClientRect(); return r.width > 0 && r.height > 0 && (r.height < 44 || r.width < 44); }).map((a) => (a.id || a.tagName) + ' ' + Math.round(a.getBoundingClientRect().width) + 'x' + Math.round(a.getBoundingClientRect().height)), scope);

// ---- phone: a new person (Dana, a Rep) on the empty book — the first-run home ----
{
  const { ctx, page, errors } = await session(urls.dana, { width: 375, height: 740 });
  console.log('375 x 740 — a new Rep, the empty book');
  ok(page.url() === BASE + '/', 'signed on and landed on Today (' + page.url() + ')');
  const o = await overflow(page);
  ok(o.sw === o.iw, `no sideways scroll on the home (scrollWidth ${o.sw} = ${o.iw})`);
  ok(await shown(page, '#app-tabbar') && !(await shown(page, '#left-sidenav')), 'the bottom tab bar is shown on a phone; the sidebar is not');
  const box = await page.locator('#app-tabbar').boundingBox();
  ok(Math.abs(box.y + box.height - 740) < 1 && box.height >= 44, `the tab bar sits on the bottom edge (bottom ${box.y + box.height}, height ${box.height})`);
  const bar = await page.locator('#assistant-bar').boundingBox();
  ok(bar.y + bar.height <= box.y + 1 && bar.y > 400, `the command bar sits just above the tab bar (bar bottom ${Math.round(bar.y + bar.height)} <= tab top ${Math.round(box.y)})`);
  const tabs = (await page.locator('#app-tabbar .app-tab').allInnerTexts()).map((t) => t.trim());
  ok(tabs.join(',') === 'Today,Pipeline,Calendar,Log,More', `five tabs: ${tabs.join(', ')}`);
  const small = await smallControls(page, '#app-tabbar');
  ok(small.length === 0, 'every tab is at least 44 x 44 px' + (small.length ? ': ' + small.join(', ') : ''));
  ok(await shown(page, '#home-first-run') && await shown(page, '#home-first-run-btn') && /add your first lead/i.test(await page.locator('#home-first-run-btn').innerText()), 'a new person sees the one button, Add your first lead');
  const btn = await page.locator('#home-first-run-btn').boundingBox();
  ok(btn.height >= 44 && btn.x >= 0 && btn.x + btn.width <= 375, `the button is ${Math.round(btn.height)} px tall and inside the screen (right edge ${Math.round(btn.x + btn.width)})`);
  ok((await page.locator('#header-role-badge').innerText()).includes('Sales Rep'), 'the header badge reads Sales Rep');
  ok(await shown(page, '#header-search-link') && !(await shown(page, '#header-search')), 'on a phone the header carries the search magnifier, not the box');
  await page.screenshot({ path: `${SHOTS}/phone-home-first-run.png` });
  await page.click('#tab-log');
  await page.waitForSelector('#tab-log-sheet.show');
  ok(await shown(page, '#tab-log-sheet') && (await page.locator('#tab-log-sheet .dropdown-item').count()) === 5 && /slice 4/.test(await page.locator('#tab-log-log-call').innerText()), 'Log opens its sheet above the bar: five quick-log entries, greyed with their slice until built');
  ok((await page.locator('.modal.show').count()) === 0, 'the sheet is a dropup, not a modal');
  const sheet = await page.locator('#tab-log-sheet').boundingBox();
  ok(sheet.y + sheet.height <= box.y + 1 && sheet.x >= 0 && sheet.x + sheet.width <= 375, 'the sheet sits above the tab bar, inside the screen');
  await page.screenshot({ path: `${SHOTS}/phone-log-sheet.png` });
  await page.click('#tab-log');
  await page.waitForTimeout(250);
  await page.click('#tab-more');
  await page.waitForSelector('nav.nxl-navigation.mob-navigation-active');
  await page.waitForTimeout(400);
  ok(await shown(page, '#left-sidenav') && (await page.locator('#nav-leads').count()) === 1 && (await page.locator('#nav-reports').count()) === 1 && (await page.locator('#nav-admin').count()) === 0, 'More opens the sidebar: Leads … Reports and Me, no Admin for a Rep');
  await page.screenshot({ path: `${SHOTS}/phone-sidebar.png` });
  await page.evaluate(() => { const l = document.querySelector('.navbar-content'); l.scrollTop = l.scrollHeight; });
  const lastAfter = await page.locator('#shell-menu .nxl-item:last-child .nxl-link').boundingBox();
  ok(lastAfter.y + lastAfter.height <= 740 - 56, `the last menu item can be scrolled clear of the bars (bottom ${Math.round(lastAfter.y + lastAfter.height)} <= ${740 - 56})`);
  await toggleMenu(page);
  await page.waitForTimeout(400);
  ok(!(await shown(page, '#left-sidenav')), 'the menu button closes it again');
  const stubbed = (f) => fs.readFileSync(new URL('../../html' + f, import.meta.url), 'utf8').includes('render_nav_stub(');   // a screen its slice has built is no placeholder any more
  await page.click('#tab-calendar');
  await page.waitForURL(new RegExp('^' + BASE + '/calendar'));
  if (stubbed('/calendar/index.php')) {
    await page.waitForSelector('#calendar-coming');
    ok((await page.title()).startsWith('Calendar') && (await page.locator('#calendar-coming').innerText()).includes('slice 4'), 'the Calendar tab opens its placeholder (slice 4), title "' + (await page.title()) + '"');
  } else {
    await page.waitForSelector('#page-content[data-screen^="calendar"]');
    ok((await page.title()).startsWith('Calendar'), 'the Calendar tab opens the calendar (slice 4 built), title "' + (await page.title()) + '"');
  }
  ok(await page.evaluate(() => document.querySelector('#tab-calendar').classList.contains('active') && !document.querySelector('#tab-home').classList.contains('active') && document.getElementById('page-content').dataset.screen.startsWith('calendar')), 'the tab is highlighted and the screen stamped calendar for the command bar');
  ok((await overflow(page)).sw === 375, 'no sideways scroll on the calendar tab\'s screen');
  await page.click('#tab-pipeline');
  await page.waitForURL(BASE + BOARD);
  if (stubbed('/pipeline/board.php')) {
    await page.waitForSelector('#pipeline-coming');
    ok((await page.locator('#pipeline-coming').innerText()).includes('slice 3'), 'the Pipeline tab opens the default pipeline\'s board placeholder (slice 3) at ' + BOARD);
  } else {
    await page.waitForSelector('#board-columns');
    ok(await page.evaluate(() => document.getElementById('page-content').dataset.screen === 'board' && document.querySelector('#tab-pipeline').classList.contains('active')), 'the Pipeline tab opens the default pipeline\'s board (slice 3 built) at ' + BOARD + ', the tab highlighted');
  }
  await page.goto(BASE + '/settings/', { waitUntil: 'networkidle' });
  ok((await overflow(page)).sw === 375, 'no sideways scroll on My settings');
  const smallS = await smallControls(page, '#page-content');
  ok(smallS.length === 0, 'every control on My settings is at least 44 px tall' + (smallS.length ? ': ' + smallS.slice(0, 6).join(', ') : ''));
  await page.screenshot({ path: `${SHOTS}/phone-settings.png`, fullPage: true });
  await page.uncheck('#prefs-field-email');
  await page.selectOption('#prefs-field-remind', '15');
  await page.click('#prefs-save-btn');
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#notice-banner').innerText()).includes('Saved'), 'saving the settings lands back on them with the notice');
  ok(!(await page.isChecked('#prefs-field-email')) && (await page.inputValue('#prefs-field-remind')) === '15', 'email stayed off and the reminder reads 15 minutes before');
  await page.goto(BASE + '/activity', { waitUntil: 'networkidle' });
  ok((await overflow(page)).sw === 375, 'no sideways scroll on My activity');
  await page.goto(BASE + '/notifications', { waitUntil: 'networkidle' });
  ok((await overflow(page)).sw === 375, 'no sideways scroll on Notifications');
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  ok(!(await shown(page, '#assistant-send-btn')), 'the Send button is hidden while the bar is idle');
  await page.fill('#assistant-input', 'Who should I call first today?');
  ok(await shown(page, '#assistant-send-btn'), 'and shown once something is typed');
  await page.click('#assistant-send-btn');
  await page.waitForSelector('#assistant-reply-text');
  ok((await page.locator('#assistant-reply-text').innerText()).includes('Hello from the fake expert'), 'the command bar shows the expert\'s reply above the bar');
  await page.screenshot({ path: `${SHOTS}/phone-assistant.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- phone: a Viewer on the empty book ----
{
  const { ctx, page, errors } = await session(urls.viewer, { width: 375, height: 740 });
  console.log('375 x 740 — a Viewer, the empty book');
  ok((await page.locator('#header-role-badge').innerText()).includes('Viewer'), 'the badge reads Viewer');
  ok(await shown(page, '#home-first-run-viewer') && (await page.locator('#home-first-run-btn').count()) === 0, 'a Viewer is told the book is empty and shown no button');
  const tabs = (await page.locator('#app-tabbar .app-tab').allInnerTexts()).map((t) => t.trim());
  ok(tabs.join(',') === 'Today,Pipeline,Calendar,More', 'a Viewer\'s tabs: Today · Pipeline · Calendar · More (nothing to log)');
  ok(errors.length === 0, 'no console errors');
  await ctx.close();
}

// ---- the SMOKE book exists from here ----
const seed = JSON.parse(execFileSync('php', [new URL('./seed.php', import.meta.url).pathname], { encoding: 'utf8' }).trim());
console.log(`seeded the book: account ${seed.acme}, Dana ${seed.dana}, the deal ${seed.deal}, the reply ${seed.mail}`);

// ---- phone: a Rep with a book (Priya) ----
{
  const { ctx, page, errors } = await session(urls.rep, { width: 375, height: 740 });
  console.log('375 x 740 — a Rep with deals, activities and a reply');
  ok(await shown(page, '#home-today-list') && (await page.locator('#home-today-list .today-row').count()) === 2 && (await page.locator('#home-first-run').count()) === 0, 'Today lists her two activities; the first-run card is gone');
  ok((await page.locator('#home-today-list .today-row').first().innerText()).includes('Overdue') && (await page.locator('#home-overdue-count').innerText()).includes('1 overdue'), 'the overdue task comes first, its chip in words, and the header counts it');
  ok((await page.locator('#home-closing-value').innerText()).includes('$24,000') && (await page.locator('#home-flag-no-next-step .badge').innerText()).trim() === '1', 'closing this month reads $24,000; one deal has no next step');
  ok(await shown(page, '#home-reply-' + seed.mail), 'the reply from Dana is listed');
  ok((await overflow(page)).sw === 375, 'no sideways scroll with the regions');
  const cardW = (await page.locator('#home-today').boundingBox()).width;
  ok(cardW >= 300 && cardW <= 375, `the Today card takes the width of the phone (${Math.round(cardW)} px)`);
  const rows = await smallControls(page, '#home-today-list');
  ok((await page.evaluate(() => [...document.querySelectorAll('#home-today-list .today-row, #home-flags .list-group-item')].every((r) => r.getBoundingClientRect().height >= 44))), 'every Today row and flag is at least 44 px tall');
  await page.screenshot({ path: `${SHOTS}/phone-home-today.png`, fullPage: true });
  await page.click('#header-search-link');
  await page.waitForURL(BASE + '/search');
  await page.fill('#search-field-q', 'acme');
  await page.click('#search-submit-btn');
  await page.waitForSelector('#search-group-account');
  ok(page.url().startsWith(BASE + '/search?') && (await page.locator(`#search-result-account-${seed.acme}`).count()) === 1 && (await page.locator('#search-result-contact-' + seed.dana).count()) === 1, 'the magnifier opens the search; acme finds the account and Dana');
  ok((await overflow(page)).sw === 375, 'no sideways scroll on the results');
  await page.screenshot({ path: `${SHOTS}/phone-search.png`, fullPage: true });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- desktop: the admin ----
{
  const { ctx, page, errors } = await session(urls.admin, { width: 1280, height: 800 });
  console.log('1280 x 800 — the admin');
  const o = await overflow(page);
  ok(o.sw === o.iw, `no sideways scroll (scrollWidth ${o.sw} = ${o.iw})`);
  ok(!(await shown(page, '#app-tabbar')), 'the tab bar is hidden on a desktop');
  ok(await shown(page, '#left-sidenav') && await shown(page, '#nav-admin') && await shown(page, '#nav-pipeline'), 'the sidebar is shown, with Pipeline and Admin');
  ok(await shown(page, '#header-search') && !(await shown(page, '#header-search-link')), 'the header carries the one search box on a desktop');
  const nav = await page.locator('#left-sidenav').boundingBox();
  const bar = await page.locator('#assistant-bar').boundingBox();
  ok(bar.x >= nav.width - 1 && bar.y + bar.height >= 780, `the command bar sits at the bottom, right of the sidebar (x ${Math.round(bar.x)}, sidebar ${Math.round(nav.width)})`);
  ok((await page.locator('#header-role-badge').innerText()).includes('Super-admin'), 'the badge reads Super-admin');
  ok(await shown(page, '#home-forecast') && await shown(page, '#home-leaders'), 'the admin (reports.team) sees the team\'s forecast tile and the leaders');
  await page.screenshot({ path: `${SHOTS}/desktop-home.png` });
  await page.fill('#header-search', 'acme');
  await page.press('#header-search', 'Enter');
  await page.waitForSelector('#search-group-account');
  ok(page.url().startsWith(BASE + '/search?q=acme') && (await page.title()).startsWith('Search'), 'the header box searches by HTMX and pushes /search?q=acme');
  await page.screenshot({ path: `${SHOTS}/desktop-search.png` });
  await page.click('#nav-admin .nxl-link');
  await page.waitForURL(BASE + '/admin');
  await page.waitForSelector('#admin-coming');
  ok((await page.title()).startsWith('Admin') && (await page.locator('#admin-coming').innerText()).includes('slice 9'), 'HTMX navigation: /admin pushed, the placeholder names slice 9');
  ok(await page.evaluate(() => document.querySelector('#nav-admin .nxl-link').classList.contains('active') && !document.querySelector('#nav-home .nxl-link').classList.contains('active')), 'the sidebar highlights the screen, not Today');
  await page.goBack();
  await page.waitForURL(/\/search\?q=acme/);
  ok(true, 'the browser back button returns to the results');
  await page.goto(BASE + '/settings/tokens/', { waitUntil: 'networkidle' });
  await page.fill('#token-form-field-label', 'proof');
  await page.click('#token-form-save-btn');
  await page.waitForSelector('#tokens-minted');
  ok((await page.locator('#tokens-minted-value').innerText()).startsWith('mcp_'), 'minting a token shows it once (mcp_…)');
  await page.screenshot({ path: `${SHOTS}/desktop-tokens.png` });
  await page.goto(BASE + '/settings/tokens/', { waitUntil: 'networkidle' });
  ok((await page.locator('#tokens-minted').count()) === 0, 'and never again');
  page.once('dialog', (d) => d.accept());
  await page.click('[id^="token-row-"][id$="-revoke-btn"]');
  await page.waitForSelector('.badge:has-text("revoked")');
  ok((await page.locator('.badge:has-text("revoked")').count()) >= 1, 'revoking it (with the confirm) marks it revoked');
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  ok((await page.locator('#header-bell').count()) === 1 && (await page.locator('#header-bell-count').count()) === 0, 'the bell shows no count (nothing unread)');
  kernelState((s) => { s.chat = { run_id: 9, status: 'succeeded', finished: true, reply: 'Opening your tokens.', actions: [], navigate: '/settings/tokens/' }; return s; });
  await page.fill('#assistant-input', 'open my tokens');
  await page.click('#assistant-send-btn');
  await page.waitForURL(BASE + '/settings/tokens/');
  await page.waitForSelector('#tokens-connect');
  ok(page.url() === BASE + '/settings/tokens/', 'a navigate answer from the command bar opened /settings/tokens/');
  kernelState((s) => { delete s.chat; return s; });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- desktop: the Sales Manager ----
{
  const { ctx, page, errors } = await session(urls.manager, { width: 1280, height: 800 });
  console.log('1280 x 800 — the Sales Manager');
  ok((await page.locator('#home-forecast-won').innerText()).includes('$9,000') && (await page.locator('#home-leaders-list .list-group-item').count()) === 1, 'the team won $9,000 this month; the leaders list Marco');
  ok((await overflow(page)).sw === 1280, 'no sideways scroll');
  await page.screenshot({ path: `${SHOTS}/desktop-manager-home.png` });
  ok(errors.length === 0, 'no console errors');
  await ctx.close();
}

// ---- JavaScript off: the home is whole ----
{
  const { ctx, page } = await session(urls.rep, { width: 375, height: 740 }, { javaScriptEnabled: false });
  console.log('JavaScript off');
  const text = await page.locator('#page-content').innerText();
  ok(text.includes('SMOKE Send the proposal') && text.includes('SMOKE Call Dana about the seats') && text.includes('$24,000') && text.includes('SMOKE Re: the seats'), 'Today, closing and the reply are in the server-rendered page');
  ok((await page.locator('#tab-pipeline').getAttribute('href')) === BOARD && (await page.locator('#tab-log').getAttribute('href')) === '/activities' && (await page.locator('#tab-more').getAttribute('href')) === '/settings/', 'the tabs are plain links (Log opens the activities, More My settings)');
  ok((await page.locator(`a[href="/deals/${seed.deal}?back=%2F"]`).count()) >= 1, 'an activity is a plain link to its deal carrying ?back=');
  ok((await page.locator('#header-search-form').getAttribute('action')) === '/search', 'the search box is a plain GET form');
  await ctx.close();
}

// ---- installable: the manifest, the icons, no service worker ----
{
  const ctx = await browser.newContext({ viewport: { width: 375, height: 740 } });
  const page = await ctx.newPage();
  await page.goto(urls.rep, { waitUntil: 'networkidle' });
  console.log('installable');
  const href = await page.getAttribute('link[rel="manifest"]', 'href');
  const res = await page.request.get(BASE + href);
  const m = await res.json();
  ok(res.status() === 200 && /manifest\+json|application\/json/.test(res.headers()['content-type'] || ''), 'the manifest is served (' + res.headers()['content-type'] + ')');
  ok(m.display === 'standalone' && m.start_url === '/' && m.name === 'Sales CRM', 'standalone, start_url /, named Sales CRM');
  const sizes = [];
  for (const i of m.icons) { const r = await page.request.get(BASE + i.src); sizes.push(r.status() === 200 && i.sizes); }
  ok(sizes.includes('192x192') && sizes.includes('512x512'), 'the 192 and 512 px icons are served');
  const sw = await page.evaluate(async () => (await navigator.serviceWorker?.getRegistrations?.() || []).length);
  ok(sw === 0, 'no service worker registered (none in version 1)');
  const html = await page.content();
  ok(html.includes('name="theme-color"') && html.includes('rel="apple-touch-icon"') && html.includes('name="viewport"'), 'theme-color, apple-touch-icon and viewport are set');
  const cdp = await ctx.newCDPSession(page);
  const inst = await cdp.send('Page.getInstallabilityErrors').catch((e) => ({ installabilityErrors: [{ errorId: 'cdp:' + e.message }] }));
  const errs = (inst.installabilityErrors || []).map((e) => e.errorId);
  ok(!errs.some((e) => /manifest|icon|start-url|display|name/i.test(e)), 'Chromium reports no manifest installability error' + (errs.length ? ' (remaining: ' + errs.join(', ') + ')' : ''));
  await ctx.close();
}

await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
