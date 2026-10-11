// Headless Chromium proof of slice 5, email: compose from a deal with the template picked through the record picker (the one HTMX refresh of the fill region), Save draft and Send as a real
// click (the fake MaluMail receives it), the unsubscribe door opened by a stranger at 375, an email's page with its conversation and the private toggle, the unmatched screen's Make lead ·
// Attach (the picker) · Ignore, the templates (list, New, Delete), the log form, the meeting's invitation from the activity page, compose with JavaScript OFF (a template link, Save draft,
// Send), and every screen at 375 x 740 and 1280 x 800 with no sideways scroll and no console error. Run through tests/phase3/slice5/run.sh. Playwright from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8801';
const ROOT = new URL('../../../', import.meta.url).pathname;
const SHOTS = process.env.SHOTS || '/tmp/crm-shots/slice5';
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
const sendsBefore = sends().length;                        // the servers are shared with the proofs before this one
const mine = () => sends().slice(sendsBefore);
const deliver = async (m) => (await fetch('http://127.0.0.1:8806/_deliver', { method: 'POST', headers: { Authorization: 'Bearer ' + process.env.MALUMAIL_API_KEY, 'Content-Type': 'application/json' }, body: JSON.stringify(m) })).json();
const worker = (passes) => execFileSync('php', [ROOT + 'bin/worker.php', '--passes=' + passes], { encoding: 'utf8', env: process.env });
const mail = (from, to, subject, text, extra = {}) => ({ address: 'crm-log@example.invalid', text, headers: { from, to: to.join(', '), subject, 'message-id': `<b-${crypto.randomBytes(5).toString('hex')}@browser.example.invalid>`, ...extra } });
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
const tap = (page, sel) => page.locator(sel).evaluate((el) => el.click());   // the phone's tab bar sits over the page's foot, and a page without scripts never settles for a pointer: the click itself
const shot = (page, name) => page.screenshot({ path: `${SHOTS}/${name}.png` });

// Fixtures (scratch only): the logging mailbox, an account with three people, a deal, a meeting.
sql(`INSERT INTO mailboxes (address, display_name, provider, webhook_secret_ref, poll_enabled, active) VALUES ('crm-log@example.invalid', 'Sales CRM log', 'malumail', 'MAILBOX_CRM_WEBHOOK_SECRET', true, true) ON CONFLICT (address) DO NOTHING`);
sql(`UPDATE crm_settings SET mailbox_id = (SELECT id FROM mailboxes WHERE address = 'crm-log@example.invalid')`);
const acc = Number(sql(`INSERT INTO accounts (name, owner_member_id, created_by) VALUES ('Mail Co ${X}', 26, 26) RETURNING id`));
const person = (first, email) => Number(sql(`INSERT INTO contacts (first_name, last_name, email, account_id, owner_member_id, created_by) VALUES ('${first}', 'Mailer ${X}', '${email}', ${acc}, 26, 26) RETURNING id`));
const ann = person('Ann', `ann-${X}@bro-${X}.example.invalid`);
const cat = person('Cat', `cat-${X}@bro-${X}.example.invalid`);
const bob = person('Bob', `bob-${X}@bro-${X}.example.invalid`);
sql(`SELECT crm_contact_opt_out(${bob}, 'email', 'asked')`);
const deal = Number(sql(`INSERT INTO deals (title, account_id, primary_contact_id, pipeline_id, stage_id, value, expected_close_date, owner_member_id, created_by)
  VALUES ('Mail deal ${X}', ${acc}, ${ann}, 1, (SELECT id FROM stages WHERE pipeline_id = 1 AND kind = 'open' ORDER BY position LIMIT 1), 5000, current_date + 30, 26, 26) RETURNING id`));
const meet = Number(sql(`INSERT INTO activities (kind, subject, deal_id, contact_id, owner_member_id, created_by, starts_at, ends_at, location) VALUES ('meeting', 'Kickoff ${X}', ${deal}, ${ann}, 26, 26, now() + interval '4 days', now() + interval '4 days 1 hour', 'Their office') RETURNING id`));
const intro = Number(sql(`SELECT id FROM email_templates WHERE name = 'Introduction' AND is_shared`));

{
  const { page, errors } = await session(26, { width: 1280, height: 800 });
  console.log('1280 wide — compose, draft, send');
  await page.goto(`${BASE}/deals/${deal}`, { waitUntil: 'networkidle' });
  await page.click('#deal-email-btn');
  await page.waitForSelector('#compose-form', { timeout: 10000 });
  ok(page.url().includes(`/email/compose?deal=${deal}`), 'Send email on the deal opens compose for it');
  ok(await page.locator(`#compose-to-${ann}`).isChecked() && !(await page.locator(`#compose-to-${cat}`).isChecked()), 'the deal\'s primary contact is ticked, the account\'s others offered');
  ok(await page.locator(`#compose-to-${bob}`).isDisabled() && await page.locator(`#compose-to-optout-${bob}`).count() === 1, 'the one who opted out is marked and cannot be ticked');
  await shot(page, 'compose-1280');
  await page.click('#compose-template-open');
  await page.waitForSelector('#record-picker-results .record-picker-row', { timeout: 10000 });
  await page.fill('#record-picker-search', 'Intro');
  await page.waitForFunction(() => document.querySelectorAll('#record-picker-results .record-picker-row').length === 1, null, { timeout: 10000 });
  await page.click(`#record-picker-row-${intro}`);
  await page.waitForFunction(() => document.querySelector('#compose-subject').value.startsWith('Hello from'), null, { timeout: 10000 });
  ok((await page.inputValue('#compose-body')).startsWith('Hi Ann,') && (await page.inputValue('#compose-body')).includes(`Mail Co ${X}`), 'picking a template refreshes the fill region: subject and body filled for Ann');
  ok(await page.locator('#compose-preview-filled').count() === 1 && await page.locator('#compose-template-label').count() === 1, 'the preview says what was filled');
  await page.fill('#compose-body', (await page.inputValue('#compose-body')) + '\nBROWSER-LINE');
  await Promise.all([page.waitForURL(/email\/compose\?draft=\d+/, { timeout: 10000 }), page.click('#compose-draft-btn')]);
  await page.waitForSelector('#notice-banner', { timeout: 10000 });
  ok((await page.locator('#notice-banner').innerText()).includes('Draft saved'), 'Save draft: lands on the draft with "Draft saved."');
  const draft = Number(sql(`SELECT id FROM outbound_email WHERE sender_member_id = 26 AND contact_id = ${ann} AND status = 'queued' ORDER BY id DESC LIMIT 1`));
  ok(draft > 0 && sql(`SELECT body_text LIKE '%BROWSER-LINE%' FROM outbound_email WHERE id = ${draft}`) === 't' && mine().length === 0, '…a draft row with the text, and nothing sent');
  await shot(page, 'compose-draft-1280');
  await Promise.all([page.waitForURL(new RegExp(`/deals/${deal}`), { timeout: 15000 }), page.click('#compose-send-btn')]);
  await page.waitForSelector('#notice-banner', { timeout: 10000 });
  ok((await page.locator('#notice-banner').innerText()).includes('Sent'), 'Send (the confirmation accepted): lands on the deal with "Sent."');
  const s = mine();
  ok(s.length === 1 && s[0].to === `ann-${X}@bro-${X}.example.invalid` && s[0].reply_to === 'crm-log@example.invalid' && s[0].text.includes('BROWSER-LINE'), 'the fake MaluMail received it, Reply-To the log mailbox');
  const token = (s[0].text.match(/\/u\/([a-f0-9]{48})/) || [])[1];
  const out = Number(sql(`SELECT id FROM outbound_email WHERE status = 'sent' AND contact_id = ${ann} ORDER BY id DESC LIMIT 1`));
  ok(sql(`SELECT status FROM outbound_email WHERE id = ${draft}`) === 'sent' && out === draft, '…and the draft is the sent email');

  console.log('1280 wide — the email, the reply, private');
  await page.goto(`${BASE}/deals/${deal}?tab=emails`, { waitUntil: 'networkidle' });
  ok(await page.locator(`#deal-email-out-${out}`).count() === 1, 'the deal\'s Emails tab lists it');
  await page.goto(`${BASE}/email/out/${out}`, { waitUntil: 'networkidle' });
  ok(await page.locator('#email-view-body').innerText().then((t) => t.includes('BROWSER-LINE')), 'its page shows the text');
  await shot(page, 'email-out-1280');
  await Promise.all([page.waitForSelector('#email-view-private', { timeout: 10000 }), page.click('#email-private-btn')]);
  ok(sql(`SELECT is_private FROM outbound_email WHERE id = ${out}`) === 't', 'Mark private: the lock shows and the row is private');
  await page.waitForSelector('#email-private-btn', { timeout: 5000 });
  await Promise.all([page.waitForFunction(() => !document.querySelector('#email-view-private'), null, { timeout: 10000 }), page.click('#email-private-btn')]);
  ok(sql(`SELECT is_private FROM outbound_email WHERE id = ${out}`) === 'f', 'Make public: the lock goes');
  const reply = `<reply-${X}@bro.example.invalid>`;
  await deliver(mail(`Ann <ann-${X}@bro-${X}.example.invalid>`, ['crm-log@example.invalid'], 'Re: Hello', 'Sounds good to me.', { 'message-id': reply, 'in-reply-to': mine()[0].message_id }));
  worker('mail_match');
  const inId = Number(sql(`SELECT id FROM inbound_email WHERE message_id = '${reply}'`));
  ok(sql(`SELECT deal_id FROM inbound_email WHERE id = ${inId}`) === String(deal), 'a reply to the sent email lands on the deal (matched by the worker)');
  await page.goto(`${BASE}/email/in/${inId}`, { waitUntil: 'networkidle' });
  ok(await page.locator(`#email-thread-out-${out}`).count() === 1, 'the reply\'s page shows our email in the conversation');
  await shot(page, 'email-in-1280');
  await page.click('#email-reply-btn');
  await page.waitForSelector('#compose-form', { timeout: 10000 });
  ok((await page.inputValue('#compose-subject')) === 'Re: Re: Hello' || (await page.inputValue('#compose-subject')).startsWith('Re:'), 'Reply opens compose, "Re:" the subject');
  ok(await page.locator(`#compose-to-${ann}`).isChecked(), '…to the one who wrote');
  ok(errors.length === 0, 'no console error: ' + errors.join('; '));

  console.log('1280 wide — the meeting\'s invitation');
  await page.goto(`${BASE}/activities/${meet}`, { waitUntil: 'networkidle' });
  ok(await page.locator('#activity-invite-form').count() === 1, 'the meeting\'s page has the invitation form');
  await shot(page, 'activity-invite-1280');
  await page.fill('#activity-invite-message', 'See you there');
  const n = mine().length;
  await Promise.all([page.waitForSelector('#notice-banner', { timeout: 15000 }), page.click('#activity-invite-btn')]);
  ok((await page.locator('#notice-banner').innerText()).includes('invitation') && mine().length === n + 1, 'Send the invitation: the notice, and the fake received one with invite.ics');
  ok(mine().at(-1).attachments.some((a) => a.filename === 'invite.ics'), '…the .ics attached');
  await page.context().close();
}

{
  const ctx = await browser.newContext({ viewport: { width: 375, height: 740 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/status of (422|403|404)/.test(m.text())) errors.push(m.text()); });
  console.log('375 wide — a stranger opens the unsubscribe link (no sign-in)');
  const token = (mine()[0].text.match(/\/u\/([a-f0-9]{48})/) || [])[1];
  await page.goto(`${BASE}/u/${token}`, { waitUntil: 'networkidle' });
  ok(await page.locator('#unsubscribe-btn').count() === 1 && (await overflow(page)) <= 1, 'the door: one button, no sideways scroll');
  await shot(page, 'unsubscribe-375');
  ok(sql(`SELECT email_opt_out FROM contacts WHERE id = ${ann}`) === 'f', 'opening it opts nobody out');
  await Promise.all([page.waitForLoadState('networkidle'), page.click('#unsubscribe-btn')]);
  ok(await page.locator('#unsubscribe-done').count() === 1 && sql(`SELECT email_opt_out || ' ' || opt_out_reason FROM contacts WHERE id = ${ann}`) === 'true unsubscribe_link', 'the button opts the contact out, and the page says so');
  await shot(page, 'unsubscribed-375');
  ok(errors.length === 0, 'no console error: ' + errors.join('; '));
  await ctx.close();
}

{
  const { page, errors } = await session(26, { width: 375, height: 740 });
  console.log('375 wide — the unmatched screen: Make lead · Attach · Ignore');
  const m1 = await deliver(mail(`Sam Stranger <sam-${X}@stranger.example.invalid>`, ['crm-log@example.invalid'], `Hello ${X}`, 'Do you do training?'));
  const m2 = await deliver(mail('Priya <priya@example.invalid>', [`Una Unknown <una-${X}@elsewhere.example.invalid>`], `Una ${X}`, 'Copying the log.', { cc: 'crm-log@example.invalid' }));
  const m3 = await deliver(mail('Priya <priya@example.invalid>', [`ign-${X}@elsewhere.example.invalid`], `Ignore ${X}`, 'Not sales.', { cc: 'crm-log@example.invalid' }));
  worker('mail_match');
  const idOf = (subject) => Number(sql(`SELECT id FROM inbound_email WHERE subject = '${subject}'`));
  const e1 = idOf(`Hello ${X}`), e2 = idOf(`Una ${X}`), e3 = idOf(`Ignore ${X}`);
  ok(sql(`SELECT match_status FROM inbound_email WHERE id = ${e1}`) === 'proposed_lead' && sql(`SELECT match_status FROM inbound_email WHERE id = ${e2}`) === 'unmatched', 'setup: a stranger proposed as a lead, a seller\'s BCC to someone unknown unmatched');
  await page.goto(`${BASE}/email/unmatched`, { waitUntil: 'networkidle' });
  ok(await page.locator(`#unmatched-${e1}`).count() === 1 && await page.locator(`#unmatched-${e2}`).count() === 1 && (await overflow(page)) <= 1, 'both rows are there, no sideways scroll');
  await shot(page, 'unmatched-375');
  // Attach (the record picker) to a new contact
  const una = person('Una', `una-contact-${X}@bro-${X}.example.invalid`);
  await page.click(`#unmatched-${e2}-contact-open`);
  await page.waitForSelector('#record-picker-results .record-picker-row', { timeout: 10000 });
  await page.fill('#record-picker-search', `Mailer ${X}`);
  await page.waitForTimeout(700);
  await page.click(`#record-picker-row-${una}`);
  await Promise.all([page.waitForURL(/notice=attached/, { timeout: 10000 }), page.click(`#unmatched-${e2}-attach-btn`)]);
  ok(sql(`SELECT match_status FROM inbound_email WHERE id = ${e2}`) === 'matched' && sql(`SELECT count(*) FROM contact_emails WHERE contact_id = ${una} AND address = 'una-${X}@elsewhere.example.invalid'`) === '1', 'Attach: the picked contact keeps the address and the email is matched');
  ok((await page.locator('#notice-banner').innerText()).includes('Put the email on the contact'), '…with its notice');
  // Make lead
  await Promise.all([page.waitForURL(/\/leads\/\d+/, { timeout: 10000 }), page.click(`#unmatched-${e1}-lead-btn`)]);
  ok(sql(`SELECT count(*) FROM leads WHERE proposed_from_email_id = ${e1} AND NOT is_proposed AND owner_member_id = 26`) === '1', 'Make lead (a proposed one): accepted as mine, the lead\'s page opens');
  // Ignore
  await page.goto(`${BASE}/email/unmatched`, { waitUntil: 'networkidle' });
  await Promise.all([page.waitForURL(/notice=ignored/, { timeout: 10000 }), page.click(`#unmatched-${e3}-ignore-btn`)]);
  ok(sql(`SELECT match_status FROM inbound_email WHERE id = ${e3}`) === 'ignored' && await page.locator(`#unmatched-${e3}`).count() === 0, 'Ignore (confirmed): gone from the list');
  await shot(page, 'unmatched-after-375');

  console.log('375 wide — templates and the log form');
  await page.goto(`${BASE}/email/templates`, { waitUntil: 'networkidle' });
  ok(await page.locator(`#template-card-${intro}-use`).count() === 1 && (await overflow(page)) <= 1, 'the templates as cards, no sideways scroll');
  await shot(page, 'templates-375');
  await page.click('#template-new-btn');
  await page.waitForSelector('#template-form', { timeout: 10000 });
  ok((await page.locator('[id^="merge-field-"]').count()) === 11 && (await overflow(page)) <= 1, 'the form lists the eleven merge fields');
  await page.fill('#template-form-field-name', `Browser tpl ${X}`);
  await page.fill('#template-form-field-subject', 'Hello {{contact.first_name}}');
  await page.fill('#template-form-field-body', 'Hi {{contact.first_name}}, about {{deal.title}}.');
  await shot(page, 'template-form-375');
  await Promise.all([page.waitForURL(/\/email\/templates\?notice=created/, { timeout: 10000 }), page.click('#template-form-save-btn')]);
  const tid = Number(sql(`SELECT id FROM email_templates WHERE name = 'Browser tpl ${X}'`));
  ok(tid > 0 && await page.locator(`#template-card-${tid}`).count() === 1, 'New template: saved and on the list');
  await Promise.all([page.waitForURL(/notice=deleted/, { timeout: 10000 }), page.click(`#template-card-${tid}-delete`)]);
  ok(sql(`SELECT count(*) FROM email_templates WHERE id = ${tid}`) === '0', 'Delete (confirmed): gone (never used)');
  await page.goto(`${BASE}/log/email?contact=${ann}&deal=${deal}`, { waitUntil: 'networkidle' });
  ok((await overflow(page)) <= 1 && await page.locator('#log-email-field-direction-out').isChecked(), 'the log form at 375: no sideways scroll, "I sent it" the default');
  await shot(page, 'log-email-375');
  await page.click('label[for=log-email-field-direction-in]');
  await page.fill('#log-email-field-subject', `Pasted ${X}`);
  await page.fill('#log-email-field-body', 'Pasted from my mail client.');
  await Promise.all([page.waitForURL(new RegExp(`/deals/${deal}.*email_logged`), { timeout: 10000 }), page.click('#log-email-form-save-btn')]);
  ok(sql(`SELECT email_direction FROM activities WHERE subject = 'Pasted ${X}' AND kind = 'email'`) === 'in', 'Log it: lands on the deal; an email activity, direction in');
  ok(errors.length === 0, 'no console error: ' + errors.join('; '));
  await page.context().close();
}

{
  console.log('JavaScript off — compose, a template link, Save draft, Send');
  const ctx = await browser.newContext({ viewport: { width: 375, height: 740 }, javaScriptEnabled: false });
  const page = await ctx.newPage();
  await page.goto(mint(26), { waitUntil: 'networkidle' });
  await page.goto(`${BASE}/email/compose?contacts[]=${cat}&deal=${deal}`, { waitUntil: 'networkidle' });
  ok(await page.locator(`#compose-to-${cat}`).isChecked(), 'compose opens with Cat ticked');
  await tap(page, `#compose-template-link-${intro}`);
  await page.waitForLoadState('networkidle');
  ok((await page.inputValue('#compose-subject')).startsWith('Hello from') && (await page.inputValue('#compose-body')).startsWith('Hi Cat,'), 'a plain link starts from the template, filled for Cat');
  await Promise.all([page.waitForURL(/draft=\d+/, { timeout: 10000 }), tap(page, '#compose-draft-btn')]);
  ok((await page.locator('#notice-banner').innerText()).includes('Draft saved'), 'Save draft with a plain form post: back on the draft with its notice');
  const n = mine().length;
  await Promise.all([page.waitForURL(new RegExp(`/deals/${deal}`), { timeout: 15000 }), tap(page, '#compose-send-btn')]);
  ok(mine().length === n + 1 && mine().at(-1).to === `cat-${X}@bro-${X}.example.invalid` && (await page.locator('#notice-banner').innerText()).includes('Sent'), 'Send with a plain form post: the fake received it, the deal says Sent');
  await page.goto(`${BASE}/email/unmatched`, { waitUntil: 'networkidle' });
  ok(await page.locator('#unmatched-list').count() === 1, 'the unmatched screen renders without scripts');
  await ctx.close();
}

console.log('Every screen at 375 x 740 and 1280 x 800');
const outId = Number(sql(`SELECT id FROM outbound_email WHERE status = 'sent' AND contact_id = ${ann} ORDER BY id LIMIT 1`));
const inRow = Number(sql(`SELECT id FROM inbound_email WHERE match_status = 'matched' ORDER BY id DESC LIMIT 1`));
const tpl = Number(sql(`SELECT id FROM email_templates WHERE is_shared ORDER BY id LIMIT 1`));
const screens = [['unmatched', '/email/unmatched'], ['compose', `/email/compose?contact=${ann}&deal=${deal}&template=${intro}`], ['email-out', `/email/out/${outId}`], ['email-in', `/email/in/${inRow}`],
  ['templates', '/email/templates'], ['template-new', '/email/templates/new'], ['template-edit', `/email/templates/${tpl}/edit`], ['log-email', `/log/email?contact=${ann}`], ['activity', `/activities/${meet}`], ['contact-emails', `/contacts/${ann}?tab=emails`]];
for (const [w, h] of [[375, 740], [1280, 800]]) {
  const { page, errors } = await session(26, { width: w, height: h });
  for (const [name, url] of screens) {
    await page.goto(BASE + url, { waitUntil: 'networkidle' });
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
