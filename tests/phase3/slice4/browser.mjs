// Headless Chromium proof of slice 4, the calendar and activities: a chip dragged to another day of the month and to another hour of the week (the server's
// region comes back); a drag the server refuses (the activity was done meanwhile: the flash, the chip back); the month at 1280 and the agenda at 375 as the
// defaults; New on a cell prefilled; the form showing the kind's fields; Log a call from the phone's Log sheet with its next step; Done with the prompt;
// Snooze from the menu; Done and the quick log with JavaScript off; the seven screens at 375 x 740 and 1280 x 800 with no sideways scroll and no console error.
// Run through tests/phase3/slice4/run.sh. Playwright from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8801';
const ROOT = new URL('../../../', import.meta.url).pathname;
const SHOTS = process.env.SHOTS || '/tmp/crm-shots/slice4';
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
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const browser = await chromium.launch();
async function session(member, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/status of (422|403)/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.on('dialog', (d) => d.accept());
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const day = (n) => new Date(Date.now() + n * 86400000).toISOString().slice(0, 10);
// Fixtures: an account, a contact, a deal of Priya's; activities in this month and week (dates within the month containing today+1 and today+2 when possible).
const acc = Number(sql(`INSERT INTO accounts (name, owner_member_id, created_by) VALUES ('Cal Co ${X}', 26, 26) RETURNING id`));
const con = Number(sql(`INSERT INTO contacts (first_name, last_name, account_id, owner_member_id, created_by) VALUES ('Cleo', 'Cal ${X}', ${acc}, 26, 26) RETURNING id`));
const deal = Number(sql(`INSERT INTO deals (title, account_id, primary_contact_id, pipeline_id, stage_id, value, expected_close_date, owner_member_id, created_by)
  VALUES ('Cal deal ${X}', ${acc}, ${con}, 1, (SELECT id FROM stages WHERE pipeline_id = 1 AND kind = 'open' ORDER BY position LIMIT 1), 5000, current_date + 30, 26, 26) RETURNING id`));
const mk = (kind, subject, at, extra = '') => Number(sql(`INSERT INTO activities (kind, subject, deal_id, owner_member_id, created_by, ${['task', 'deadline'].includes(kind) ? 'due_at' : 'starts_at'})
  VALUES ('${kind}', '${subject} ${X}', ${deal}, 26, 26, '${at}') RETURNING id`));
// the drags happen on a day ~75 days ahead (the other proofs fill today's cell past its four chips), moved to a day of the same month
const today = day(75);
const sameMonth = (n) => { const d = day(75 + n); return d.slice(0, 7) === today.slice(0, 7) ? d : day(75 - n); };
sql(`DELETE FROM activities WHERE owner_member_id = 26 AND COALESCE(starts_at, due_at) >= '${day(60)}' AND COALESCE(starts_at, due_at) < '${day(90)}'`);   // a rerun's leftovers (scratch only)
const dragA = mk('call', 'Drag me', `${today} 10:00+00`);
const dragTo = sameMonth(2);
const weekA = mk('meeting', 'Week block', `${today} 09:00+00`);
const refused = mk('task', 'Done meanwhile', `${today} 15:00+00`);
const doneMe = mk('call', 'Finish me', `${day(1)} 11:00+00`);
{
  const { ctx, page, errors } = await session(26, { width: 1280, height: 1400 });     // tall enough that a month's last week is above the command bar
  console.log('1280 wide — the calendar');
  await page.goto(`${BASE}/calendar`, { waitUntil: 'networkidle' });
  ok(await page.locator('#calendar-view-month.active').count() === 1 && await page.locator('#calendar-month').count() === 1, 'the month is the wide screen\'s default');
  ok(await page.evaluate(() => !!window.Sortable && !!window.bindCalendar), 'SortableJS and calendar.js are loaded');
  await page.goto(`${BASE}/calendar?at=${today}`, { waitUntil: 'networkidle' });
  ok(await page.locator(`#cal-day-${today} #cal-item-${dragA}`).count() === 1, 'the call sits in its day\'s cell');
  let answered = page.waitForResponse((r) => r.url().includes(`/activities/${dragA}/reschedule`), { timeout: 10000 });
  await page.locator(`#cal-item-${dragA}`).dragTo(page.locator(`#cal-day-${dragTo} .cal-day-body`));
  await answered;
  await page.waitForSelector(`#cal-day-${dragTo} #cal-item-${dragA}`, { timeout: 10000 });
  ok(sql(`SELECT to_char(starts_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') FROM activities WHERE id = ${dragA}`) === `${dragTo} 10:00`, 'a chip dragged to another day: activity_reschedule posted, the time of day kept');
  ok(Number(sql(`SELECT count(*) FROM activity_log WHERE action = 'activity.reschedule' AND entity_id = ${dragA}`)) === 1, '…activity.reschedule logged');
  sql(`UPDATE activities SET done_at = now() WHERE id = ${refused}`);
  await page.locator(`#cal-item-${refused}`).dragTo(page.locator(`#cal-day-${dragTo} .cal-day-body`));
  await page.waitForSelector('#flash-message', { timeout: 10000 });
  ok((await page.locator('#flash-message').innerText()).includes('done already'), 'a drag the server refuses (done meanwhile): the flash says why');
  await page.waitForTimeout(1200);
  ok(await page.locator(`#cal-day-${today} #cal-item-${refused}`).count() === 1 && await page.locator(`#cal-item-${refused}.cal-state-done`).count() === 1, '…the region re-rendered: the chip back on its day, struck as done');
  await page.click('#calendar-view-week');
  await page.waitForSelector('#calendar-week', { timeout: 10000 });
  ok(page.url().includes('view=week'), 'Week: the region swapped, the URL pushed');
  answered = page.waitForResponse((r) => r.url().includes(`/activities/${weekA}/reschedule`), { timeout: 10000 });
  await page.locator(`#cal-item-${weekA}`).dragTo(page.locator(`#cal-slot-${today}-14`));
  await answered;
  await page.waitForSelector(`#cal-slot-${today}-14 #cal-item-${weekA}`, { timeout: 10000 });
  ok(sql(`SELECT to_char(starts_at AT TIME ZONE 'UTC', 'HH24:MI') || '-' || to_char(ends_at AT TIME ZONE 'UTC', 'HH24:MI') FROM activities WHERE id = ${weekA}`) === '14:00-15:00', 'a block dragged to 2pm in the week: starts at 14:00, still an hour');
  await page.click('#calendar-next');
  await page.waitForTimeout(500);
  ok((await page.locator('#calendar-title').innerText()) !== '' && page.url().includes('at='), 'next: the following week, the URL follows');
  await page.click('#calendar-today');
  await page.waitForTimeout(500);
  ok(page.url().includes('at=' + day(0)) || !page.url().includes('at='), 'today: back to this week');
  await page.goto(`${BASE}/calendar?view=month&at=${today}`, { waitUntil: 'networkidle' });
  await page.locator(`#cal-new-${dragTo}`).click({ force: true });
  await page.waitForSelector('#activity-form');
  ok((await page.locator('#activity-form-field-starts_at').inputValue()).startsWith(dragTo + 'T09:00'), 'New on a cell: the form on that date at 09:00');
  await page.click('label[for="activity-form-field-kind-task"]');
  ok(await page.locator('[data-kind-when="timed"]').first().isHidden() && await page.locator('[data-kind-when="due"]').isVisible() && await page.locator('[data-kind-when="task"]').isVisible(), 'a task: its due time and repeat shown, a start and an end hidden');
  await page.click('label[for="activity-form-field-kind-meeting"]');
  ok(await page.locator('[data-kind-when="due"]').isHidden() && await page.locator('[data-kind-when="timed"]').first().isVisible(), 'a meeting: a start and an end');
  await page.fill('#activity-form-field-subject', `Planned ${X}`);
  await page.click('#activity-form-save-btn');
  await page.waitForSelector('#flash-message', { timeout: 10000 });
  ok((await page.locator('#flash-message').innerText()).includes('Say what it is about'), 'a meeting from a bare cell: refused in words — only a task stands alone');
  await page.waitForTimeout(800);                                                   // the flash scrolls the page to the top
  await page.click('label[for="activity-form-field-kind-task"]');
  await page.click('#activity-form-save-btn');
  await page.waitForSelector('#calendar-grid', { timeout: 10000 });
  const planned = Number(sql(`SELECT id FROM activities WHERE subject = 'Planned ${X}'`));
  ok(planned > 0 && (await page.locator('#notice-banner').innerText()).includes('Scheduled') && await page.locator(`#cal-day-${dragTo} #cal-item-${planned}`).count() === 1,
    'Schedule it: back on the calendar it came from, "Scheduled.", the chip on its day');
  await page.goto(`${BASE}/activities/${doneMe}`, { waitUntil: 'networkidle' });
  await page.click('#activity-snooze-btn');
  await page.click('#activity-snooze-1h');
  await page.waitForTimeout(800);
  ok(sql(`SELECT snoozed_until IS NOT NULL FROM activities WHERE id = ${doneMe}`) === 't', 'Snooze → For an hour: snoozed');
  await page.goto(`${BASE}/activities/${doneMe}`, { waitUntil: 'networkidle' });
  await page.click('#activity-done-btn');
  await page.waitForSelector('#activity-complete-form');
  ok(await page.locator('section#next-step-section').count() === 0 && await page.locator('details#next-step-section').count() === 1, 'Done while another step is planned: the next step folded');
  await page.click('label[for="outcome-no_answer"]');
  await page.click('#activity-complete-btn');
  await page.waitForSelector('#activity-outcome', { timeout: 10000 });
  ok((await page.locator('#activity-view-outcome').innerText()) === 'No answer', 'Mark done: the outcome chosen');
  for (const [p, id] of [['/calendar', 'calendar-grid'], ['/activities', 'activities-list'], [`/activities/${doneMe}`, 'activity-view-title'], ['/activities/new', 'activity-form'], [`/activities/${weekA}/edit`, 'activity-form'],
    [`/log/call?deal=${deal}`, 'log-call-form'], [`/log/meeting?deal=${deal}`, 'log-meeting-form']]) {
    await page.goto(BASE + p, { waitUntil: 'networkidle' });
    const name = p.split('?')[0].replace(/\//g, '-').replace(/^-/, '').replace(/-\d+/g, '');
    await page.screenshot({ path: `${SHOTS}/1280-${name || 'home'}.png` });
    ok(await page.locator('#' + id).count() === 1 && await overflow(page) <= 1, `1280 ${p}: renders, no sideways scroll`);
  }
  ok(errors.length === 0, 'no console error at 1280' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(26, { width: 375, height: 740 });
  console.log('375 x 740 — the phone');
  await page.goto(`${BASE}/calendar`, { waitUntil: 'networkidle' });
  await page.waitForSelector('#calendar-agenda', { timeout: 10000 });
  ok(await page.locator('#calendar-view-agenda.active').count() === 1, 'the agenda is the phone\'s default');
  ok(await overflow(page) <= 1, '…no sideways scroll');
  await page.click('#tab-log');
  ok(await page.locator('a#tab-log-log-call').count() === 1 && await page.locator('a#tab-log-log-meeting').count() === 1 && await page.locator('a#tab-log-schedule').count() === 1, 'the Log sheet: Log a call, Log a meeting, Schedule are links now');
  await page.click('#tab-log-log-call');
  await page.waitForSelector('#log-call-form', { timeout: 10000 });
  ok(await page.locator('#log-call-field-contact').count() === 1, '…Log a call opens the quick form');
  await page.goto(`${BASE}/log/call?deal=${deal}`, { waitUntil: 'networkidle' });
  await page.click('label[for="outcome-left_message"]');
  await page.fill('#log-call-field-body', 'Left a voicemail about the pilot');
  if (await page.locator('details#next-step-section').count()) { await page.click('details#next-step-section > summary'); }
  await page.selectOption('#next-step-kind', 'call');
  await page.click('label[for="next-step-at-tomorrow"]');
  await page.click('#log-call-form-save-btn');
  await page.waitForSelector('#activity-view-title', { timeout: 10000 });
  const touch = Number(page.url().match(/activities\/(\d+)/)[1]);
  ok(sql(`SELECT outcome FROM activities WHERE id = ${touch}`) === 'left_message' && (await page.locator('#notice-banner').innerText()).includes('next step'), 'Log it: the touch done, the notice links the next step');
  ok(Number(sql(`SELECT count(*) FROM activities WHERE deal_id = ${deal} AND kind = 'call' AND done_at IS NULL AND starts_at::date = current_date + 1`)) >= 1, '…and the next call tomorrow is open on the deal');
  for (const [p, id] of [['/calendar', 'calendar-grid'], ['/activities', 'activities-list'], [`/activities/${touch}`, 'activity-view-title'], ['/activities/new', 'activity-form'], [`/activities/${touch}/edit`, 'activity-form'],
    [`/log/call?deal=${deal}`, 'log-call-form'], [`/log/meeting?deal=${deal}`, 'log-meeting-form'], ['/calendar?view=month', 'calendar-month'], ['/calendar?view=week', 'calendar-week']]) {
    await page.goto(BASE + p, { waitUntil: 'networkidle' });
    const name = p.replace(/\?view=/, '-').split('?')[0].replace(/\//g, '-').replace(/^-/, '').replace(/-\d+/g, '');
    await page.screenshot({ path: `${SHOTS}/375-${name}.png` });
    ok(await page.locator('#' + id).count() === 1 && await overflow(page) <= 1, `375 ${p}: renders, no sideways scroll`);
  }
  ok(errors.length === 0, 'no console error at 375' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
{
  const { ctx, page } = await session(26, { width: 1280, height: 800 }, { javaScriptEnabled: false });
  console.log('JavaScript off');
  const plain = mk('call', 'No script', `${day(2)} 10:00+00`);
  await page.goto(`${BASE}/activities/${plain}/complete`);
  await page.click('label[for="outcome-busy"]');
  await page.click('#activity-complete-btn');
  await page.waitForLoadState('load');
  ok(sql(`SELECT outcome FROM activities WHERE id = ${plain}`) === 'busy' && page.url().includes(`/activities/${plain}`), 'Done without JavaScript: posted, the activity\'s page');
  await page.goto(`${BASE}/log/meeting?deal=${deal}`);
  await page.click('#log-meeting-form-save-btn');
  await page.waitForLoadState('load');
  ok(Number(sql(`SELECT count(*) FROM activities WHERE deal_id = ${deal} AND kind = 'meeting' AND done_at IS NOT NULL`)) >= 1, 'a meeting logged without JavaScript');
  await page.goto(`${BASE}/calendar?view=week`);
  ok(await page.locator('#calendar-week').count() === 1 && await page.locator('#activity-reschedule-form').count() === 0, 'the calendar draws without JavaScript (the links stand)');
  await page.goto(`${BASE}/activities/${weekA}`);
  await page.fill('#activity-reschedule-at', `${day(3)}T16:00`);
  await page.click('#activity-reschedule-btn');
  await page.waitForLoadState('load');
  ok(sql(`SELECT to_char(starts_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') FROM activities WHERE id = ${weekA}`) === `${day(3)} 16:00`, 'Reschedule without JavaScript: the form moves it');
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
