// Headless Chromium proof of slice 1 (accounts-contacts.md "Proof" 10): the eight screens at 375 x 740 and 1280 x 800 with no sideways scroll and no
// console error; making an account through its form (the duplicate warning on blur, the parent chosen in the record picker, the owner picker), the
// account page's tabs switching by click and by ?tab=, a contact added from the account's Contacts tab with the account prefilled, the tag editor,
// the opt-out banner; every control at least 44 px on the phone forms; the list and the page whole with JavaScript off.
// Run through tests/phase3/slice1/run.sh. Playwright from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';

const BASE = 'http://127.0.0.1:8801';
const SHOTS = process.env.SHOTS || '/tmp/crm-shots/slice1';
fs.mkdirSync(SHOTS, { recursive: true });
const KEY = process.env.ACTION_TOKEN_KEY;
const X = crypto.randomBytes(3).toString('hex');
const fixture = JSON.parse(fs.readFileSync(new URL('../../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.sales_crm.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const claims = { ...fixture.claims[String(member)], member_id: member };
  const text = Buffer.from(JSON.stringify(claims)).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
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
const shown = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0; }, sel);
const small = (page, scope) => page.evaluate((s) => [...document.querySelectorAll(s + ' a.btn, ' + s + ' button.btn, ' + s + ' input:not([type=hidden]):not([type=checkbox]):not([type=file]), ' + s + ' select:not([multiple])')].filter((a) => { const r = a.getBoundingClientRect(); return r.width > 0 && r.height > 0 && r.height < 44 && !a.closest('.dropdown-menu') && !a.closest('.modal'); }).map((a) => (a.id || a.name || a.tagName) + ' ' + Math.round(a.getBoundingClientRect().height)), scope);

// ---- desktop: Priya makes an account through the form ----
let acc, con;
{
  const { ctx, page, errors } = await session(26, { width: 1280, height: 800 });
  console.log('1280 x 800 — making an account');
  await page.goto(BASE + '/accounts/new', { waitUntil: 'networkidle' });
  await page.fill('#account-form-field-name', `Parent Co ${X}`);
  await page.fill('#account-form-field-website', `https://parent-${X}.example.com`);
  await page.click('#account-form-save-btn');
  await page.waitForURL(/\/accounts\/\d+\?notice=created/);
  const parentId = Number(new URL(page.url()).pathname.split('/').pop());
  ok(parentId > 0 && (await page.locator('#notice-banner').innerText()).includes('Added the account'), 'the form makes the account and lands on it with the notice');
  await page.goto(BASE + '/accounts/new', { waitUntil: 'networkidle' });
  await page.fill('#account-form-field-name', `Parent Co ${X}`);
  await page.locator('#account-form-field-name').blur();
  await page.waitForSelector(`#duplicate-warning-${parentId}`);
  ok(await shown(page, `#duplicate-warning-${parentId}`), 'the duplicate warning appears on blur, naming the account that looks the same');
  await page.fill('#account-form-field-name', `Child Co ${X}`);
  await page.fill('#account-form-field-website', `https://child-${X}.example.com`);
  await page.click('#account-form-field-parent-open');
  await page.waitForSelector('#record-picker.show');
  await page.fill('#record-picker-search', `Parent Co ${X}`);
  await page.waitForSelector(`#record-picker-row-${parentId}`);
  ok((await page.locator('.modal.show').count()) === 1, 'the parent is chosen in the record picker (the one sanctioned modal)');
  await page.click(`#record-picker-row-${parentId}`);
  await page.waitForSelector('#record-picker', { state: 'hidden' });
  ok((await page.inputValue('#account-form-field-parent')) === String(parentId) && (await page.locator('#account-form-field-parent-open').innerText()).includes(`Parent Co ${X}`), 'the picker fills the field with the id and shows the name');
  await page.selectOption('#account-form-field-employees', '51-200');
  await page.fill('#account-form-field-tags', `enterprise-${X}`);
  await page.click('#account-form-save-btn');
  await page.waitForURL(/\/accounts\/\d+\?notice=created/);
  acc = Number(new URL(page.url()).pathname.split('/').pop());
  ok((await page.locator('#account-field-parent').innerText()).includes(`Parent Co ${X}`) && (await page.locator(`text=enterprise-${X}`).count()) >= 1, 'the child account shows its parent (a link) and its tag');
  ok((await overflow(page)) <= 0, 'no sideways scroll on the account page');
  await page.screenshot({ path: `${SHOTS}/desktop-account-view.png` });
  await page.click('#account-tab-contacts-link');
  await page.waitForSelector('#account-tab-contacts.active.show');
  ok(await shown(page, '#account-tab-contacts') && !(await shown(page, '#account-tab-overview')), 'clicking the Contacts tab shows its pane (Bootstrap tabs, no reload)');
  await page.click('#account-add-contact-btn');
  await page.waitForURL(/\/contacts\/new\?account=\d+/);
  ok((await page.inputValue('#contact-form-field-account')) === String(acc), 'Add a contact from the tab prefills the account');
  await page.fill('#contact-form-field-first_name', 'Grace');
  await page.fill('#contact-form-field-last_name', `Hopper ${X}`);
  await page.fill('#contact-form-field-email', `grace@child-${X}.example.com`);
  await page.click('#contact-form-save-btn');
  await page.waitForURL(/\/contacts\/\d+\?notice=created/);
  con = Number(new URL(page.url()).pathname.split('/').pop());
  ok((await page.locator('#contact-view-account-link').innerText()).includes(`Child Co ${X}`), 'the contact is at the account (its name links back)');
  await page.fill('#tag-editor-field-tag', `champion-${X}`);
  await page.click('#tag-editor-add-btn');
  await page.waitForSelector(`text=champion-${X}`);
  ok(true, 'the tag editor adds a tag and the page shows it');
  await page.click('#contact-more-btn');
  await page.click('#contact-opt-out-email-btn');
  await page.waitForSelector('#contact-opt-out-banner');
  ok((await page.locator('#contact-opt-out-banner').innerText()).includes('No email'), 'recording No email shows the banner');
  await page.goto(BASE + `/accounts/${acc}?tab=history`, { waitUntil: 'networkidle' });
  ok(await shown(page, '#account-tab-history') && (await page.locator('#account-timeline-list .list-group-item').count()) >= 1, '?tab=history opens on the History pane');
  await page.goto(BASE + `/accounts/${acc}#account-tab-notes`, { waitUntil: 'networkidle' });
  ok(await shown(page, '#notes-panel'), 'an anchor (#account-tab-notes) opens that pane');
  await page.fill('#note-form-field-body', 'Met at the conference.');
  await page.click('#note-form-save-btn');
  await page.waitForSelector('text=Met at the conference.');
  ok(await shown(page, '#notes-panel'), 'adding a note lands back on Notes & files with the note');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- the eight screens at both sizes ----
const screens = (a, c) => ({ 'account-list': '/accounts', 'account-view': `/accounts/${a}`, 'account-add': '/accounts/new', 'account-edit': `/accounts/${a}/edit`,
  'contact-list': '/contacts', 'contact-view': `/contacts/${c}`, 'contact-add': `/contacts/new?account=${a}`, 'contact-edit': `/contacts/${c}/edit` });
for (const [w, h] of [[375, 740], [1280, 800]]) {
  const { ctx, page, errors } = await session(27, { width: w, height: h });
  console.log(`${w} x ${h} — the eight screens (the Sales Manager)`);
  for (const [id, url] of Object.entries(screens(acc, con))) {
    await page.goto(BASE + url, { waitUntil: 'networkidle' });
    const o = await overflow(page);
    const stamp = await page.evaluate(() => document.getElementById('page-content').dataset.screen);
    ok(o <= 0 && stamp === id, `${id}: no sideways scroll (${o}), stamped ${stamp}`);
    if (w === 375 && (id === 'account-add' || id === 'contact-edit')) {
      const s = await small(page, '#page-content');
      ok(s.length === 0, `${id} at 375: every control is at least 44 px tall` + (s.length ? ': ' + s.slice(0, 6).join(', ') : ''));
    }
    await page.screenshot({ path: `${SHOTS}/${w}-${id}.png`, fullPage: w === 375 });
  }
  if (w === 375) {
    const av = await page.evaluate(() => { const a = document.getElementById('header-user-menu').getBoundingClientRect(); return { left: a.left, right: a.right, iw: window.innerWidth }; });
    ok(av.left >= 0 && av.right <= av.iw, `the header's avatar stays inside the phone's width with the longest badge (right edge ${Math.round(av.right)} <= ${av.iw})`);
    await page.goto(BASE + `/accounts/${acc}`, { waitUntil: 'networkidle' });
    const tabs = await page.locator('#account-tabs').boundingBox();
    ok(tabs.width <= 375, 'the tab strip scrolls inside itself on a phone, the page does not');
    await page.goto(BASE + '/accounts', { waitUntil: 'networkidle' });
    const card = await page.locator('#account-list-cards .card').first().boundingBox();
    ok(card.width >= 300 && card.width <= 375, `a card takes the width of the phone (${Math.round(card.width)} px)`);
  }
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- JavaScript off ----
{
  const { ctx, page } = await session(26, { width: 375, height: 740 }, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await page.goto(BASE + `/accounts?q=${encodeURIComponent('Child Co ' + X)}`, { waitUntil: 'load' });
  ok((await page.locator(`#account-card-${acc} a[href^="/accounts/${acc}?back="]`).count()) === 1, 'the list is cards with plain links');
  await page.goto(BASE + `/accounts/${acc}?tab=contacts`, { waitUntil: 'load' });
  ok(await shown(page, '#account-tab-contacts') && (await page.locator(`#account-contact-${con}`).count()) === 1, '?tab=contacts shows the Contacts pane from the server alone');
  ok((await page.locator('#account-tab-history-link').getAttribute('href')) === `/accounts/${acc}?tab=history#account-tab-history`, 'each tab is a plain link (?tab= and its anchor)');
  await ctx.close();
}
await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
