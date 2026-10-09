/**
 * The owner's first evening, driven in Chromium at 390px against a real server.
 *
 *   tests/e2e.sh                       starts everything this needs, then runs it
 *   tests/e2e.sh --stop-after <step>   stop once the named step has run
 *
 * Run through tests/e2e.sh, which provides a fresh copy of the portal behind
 * `php -S`, a throwaway MariaDB and an SMTP sink, and passes their addresses in
 * CRM_E2E_* variables. On its own this file has nothing to talk to.
 *
 * What it walks, through the real forms and buttons, as she and a family would:
 *   1. public/setup.php, then signing in lands on „Dein Portal einrichten“, 0 of 9;
 *      then a page held back on its way: the waiting page, carried on by the next
 *      page without a gap, „Abbrechen“, Reduce Motion, no app.js (Part 0.4b)
 *   2. each of the nine steps from its own button, back via „Zurück zur
 *      Einrichtung“, and the tick after each, up to 9 of 9 and „Alles eingerichtet“
 *   3. the family: invitation link from the captured mail, password, „Dein Foto“
 *      skipped, dashboard, Profil, the charge, a payment proof, „Etwas
 *      funktioniert nicht“; a person invited by address adds a photo there
 *   4. the trainer: the charge and the payment, confirming it, an invoice PDF,
 *      the problem report with its trail
 *   5. an unexpected error (a table renamed underneath the portal): the family
 *      sees only the friendly page, Rückmeldungen shows it with a count
 *
 * And throughout, on every page opened: HTTP 500s, JavaScript errors, PHP
 * warnings in the server's error log, anything wider than the screen, tap
 * targets under 44px - and whether app.css was applied at all, because a sweep
 * of unstyled pages once reported clean.
 */
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import zlib from 'node:zlib';
import path from 'node:path';

const playwrightFrom = process.env.PLAYWRIGHT_PATH || '/opt/node22/lib/node_modules/playwright/index.mjs';
let chromium;
try { ({ chromium } = await import(playwrightFrom)); }
catch { try { ({ chromium } = await import('playwright')); }
        catch { console.error('Playwright not found. Set PLAYWRIGHT_PATH to its index.mjs.'); process.exit(2); } }

const env = (k) => { const v = process.env[k]; if (!v) { console.error(`${k} is not set: run this through tests/e2e.sh`); process.exit(2); } return v; };
const BASE = env('CRM_E2E_BASE');
const WORK = env('CRM_E2E_WORK');
const SOCKET = env('CRM_E2E_SOCKET');
const DB = env('CRM_E2E_DB');
const DB_PORT = env('CRM_E2E_DB_PORT');
const SMTP_PORT = env('CRM_E2E_SMTP_PORT');
const MAILDIR = path.join(WORK, 'mail');
const SHOTS = path.join(WORK, 'shots');
const ERROR_LOG = path.join(WORK, 'php-error.log');
const STOP_AFTER = (() => { const i = process.argv.indexOf('--stop-after'); return i > 0 ? process.argv[i + 1] : ''; })();

const ADMIN = { name: 'Sabine Berger', email: 'trainerin@example.test', password: 'E2e-Admin-Pass-2026' };
const FAMILY = { name: 'Familie Huber', email: 'huber@example.test', password: 'E2e-Family-Pass-2026' };
const CHILDREN = [{ first: 'Lena', last: 'Huber', born: '2015-04-12' }, { first: 'Jonas', last: 'Huber', born: '2013-09-30' }];
const COURSE = 'Kinder Anfänger';
// Invited by address alone („Per E-Mail einladen“, ADR 0021 §3): she types only
// the address, and the person makes their own record and asks for a course.
const NEWCOMER = { first: 'Mira', last: 'Novak', born: '2012-05-20', email: 'mira.novak@example.test', password: 'E2e-Newcomer-Pass-2026' };
// An English invitation, opened and then withdrawn before it is taken up.
const GUEST_EN = { email: 'guest.en@example.test' };
// The child the family will be invited for (step 9 picks the first by name)
// joined part-way through this month; the other one joins on the day of the run.
const MID_JOINER = 'Jonas';
const IBAN = 'AT611904300234573201';

// The people whose pages are opened again at 320px, each with its own session.
const SIGNED_IN_ROLES = ['admin', 'family', 'newcomer'];

// --- results ------------------------------------------------------------------
const results = [];       // {step, ok, name, detail}
const layout = new Map(); // "problem|page" -> {page, width, role, problem}
const browserErrors = []; // {step, page, text}
let current = '';
const ok = (cond, name, detail = '') => { results.push({ step: current, ok: !!cond, name, detail: cond ? '' : String(detail) }); if (!cond) console.log(`   FAIL ${name}${detail ? ' - ' + String(detail).slice(0, 300) : ''}`); return !!cond; };
/** Seen, worth a decision, but what the design says - printed, not failed. */
const notes = [];
const note = (cond, name, detail = '') => { if (!cond) { notes.push({ step: current, name, detail: String(detail) }); console.log(`   NOTE ${name}`); } };
const must = (cond, name, detail = '') => { if (!ok(cond, name, detail)) throw new Error('cannot continue: ' + name); };

// --- the database and the mailbox, read the way a person cannot ---------------
const sql = (query) => execFileSync('mariadb', ['--socket=' + SOCKET, '-uroot', '--default-character-set=utf8mb4', '-N', '-B', DB, '-e', query], { encoding: 'utf8' }).trim();
const setting = (key) => sql(`SELECT setting_value FROM settings WHERE setting_key='${key}'`);
const mails = () => fs.readdirSync(MAILDIR).filter(f => f.endsWith('.eml')).sort().map(f => {
    const raw = fs.readFileSync(path.join(MAILDIR, f), 'utf8');
    return { file: f, raw, to: (raw.match(/^X-E2E-Rcpt: (.*)$/m) || [])[1] || '', subject: decodeSubject(raw), body: decodeBody(raw) };
});
function decodeSubject(raw) {
    const m = raw.match(/^Subject: (.*(?:\r?\n[ \t].*)*)/m); if (!m) return '';
    return m[1].replace(/\r?\n[ \t]/g, '').replace(/=\?utf-8\?([BQ])\?([^?]*)\?=/gi, (_, enc, t) =>
        enc.toUpperCase() === 'B' ? Buffer.from(t, 'base64').toString('utf8')
            : Buffer.from(t.replace(/_/g, ' ').replace(/=([0-9A-F]{2})/gi, (_, h) => String.fromCharCode(parseInt(h, 16))), 'latin1').toString('utf8'));
}
function decodeBody(raw) {
    // Quoted-printable or base64, single part or the first text part: whatever
    // PHPMailer chose, a link in it has to come out the way a mail client shows it.
    const text = raw.replace(/=\r?\n/g, '');
    const qp = /Content-Transfer-Encoding: quoted-printable/i.test(raw);
    const b64 = raw.match(/Content-Transfer-Encoding: base64\r?\n\r?\n([A-Za-z0-9+/=\r\n]+)/i);
    if (b64) return Buffer.from(b64[1].replace(/\s/g, ''), 'base64').toString('utf8');
    return qp ? Buffer.from(text.replace(/=([0-9A-F]{2})/gi, (_, h) => String.fromCharCode(parseInt(h, 16))), 'latin1').toString('utf8') : text;
}
/** A mail's text as a reader sees it: decodeBody() keeps the headers of a quoted-printable mail. */
const mailText = m => (/^X-E2E-Rcpt:/m.test(m.body) ? m.body.slice(m.body.search(/\r?\n\r?\n/)) : m.body).trimStart();
const waitFor = async (what, fn, seconds, poke) => {
    const until = Date.now() + seconds * 1000;
    for (;;) {
        const v = await fn(); if (v) return v;
        if (Date.now() > until) return null;
        if (poke) await poke();
        await new Promise(r => setTimeout(r, 2000));
    }
};

// --- what is checked on every page --------------------------------------------
/** Same rules as tests/mobile.mjs, so the two cannot disagree about a page. */
const inspect = ({ expected }) => {
    const vw = window.innerWidth;
    const found = [];
    const name = (el) => (el.tagName.toLowerCase() + (el.id ? '#' + el.id : '')
        + (typeof el.className === 'string' && el.className.trim() ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '')).slice(0, 64);
    const inScroller = (el) => {
        for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
            const s = getComputedStyle(p);
            if (s.overflowX === 'auto' || s.overflowX === 'scroll' || s.position === 'fixed') return true;
        }
        return false;
    };
    for (const el of document.querySelectorAll('body *')) {
        const r = el.getBoundingClientRect();
        if (r.width === 0 || r.height === 0) continue;
        const s = getComputedStyle(el);
        if (s.visibility === 'hidden' || s.display === 'none') continue;
        if (r.right > vw + 1 && s.position !== 'fixed' && !inScroller(el))
            found.push(`wider than the screen: ${name(el)} ends at ${Math.round(r.right)}px of ${vw}`);
    }
    for (const el of document.querySelectorAll('a[href], button, input[type=submit], summary, .segmented label, .chip')) {
        const r = el.getBoundingClientRect();
        const s = getComputedStyle(el);
        if (r.width === 0 || r.height === 0 || s.visibility === 'hidden') continue;
        if (r.height < 44) found.push(`tap target under 44px: ${name(el)} "${(el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 30)}" ${Math.round(r.height)}px`);
    }
    if (vw > expected + 1) found.push(`the page zoomed out to fit: needs ${vw}px on a ${expected}px screen`);
    if (document.documentElement.scrollWidth > vw + 1) found.push(`the page scrolls sideways: ${document.documentElement.scrollWidth}px on ${vw}`);
    // The measurement is only worth anything on a styled page.
    const sheet = [...document.styleSheets].find(s => (s.href || '').includes('app.css'));
    let rules = 0; try { rules = sheet ? sheet.cssRules.length : 0; } catch { rules = -1; }
    // PHP that reached the page as text: a call left outside its <?php block.
    const leaked = (document.body.innerText.match(/[\w\]\)]\s*;\s*\?>|<\?(php|=)|\b[a-z_]+\([^()]{0,80}\);\s*\?>/g) || []);
    return { found, leaked, styled: !!sheet && rules !== 0, title: document.title };
};

const label = (url) => {
    const u = new URL(url);
    if (u.pathname.endsWith('setup.php')) return 'setup.php';
    const q = [...u.searchParams].filter(([k]) => !['csrf', 'token', 'id', 'from', 'edit', 'signature', 'account'].includes(k)).map(([k, v]) => `${k}=${v}`).join('&');
    return '?' + (q || 'page=dashboard');
};

/** Opened, measured and noted: every page this walk looks at goes through here. */
async function look(page, role, width = 390) {
    await page.waitForLoadState('networkidle').catch(() => {});
    const r = await page.evaluate(inspect, { expected: width });
    const where = label(page.url());
    // Only pages that answered: one that failed is already reported where it failed.
    if (width === 390 && SIGNED_IN_ROLES.includes(role) && (page.__status || 200) < 400) (S.visited[role] ||= new Set()).add(page.url().replace(/#.*/, ''));
    if (!r.styled) ok(false, `app.css applied on ${where}`, 'the stylesheet did not load, so nothing measured on this page means anything');
    if (r.leaked.length && !S.leakSeen?.has(where + width)) (S.leakSeen ||= new Set()).add(where + width) && ok(false, `no PHP source printed on ${where}`, r.leaked.join(' / '));
    for (const f of r.found) {
        const key = `${f}|${where}|${width}`;
        if (!layout.has(key)) layout.set(key, { page: where, width, role, problem: f, step: current });
    }
    return r;
}

function watch(page, role) {
    page.on('console', m => { if (m.type() === 'error' && !(page.__expect4xx && /status of 4\d\d/.test(m.text())) && !(page.__expect5xx && /status of 5\d\d/.test(m.text()))) browserErrors.push({ step: current, role, page: label(page.url()), text: 'console: ' + m.text() }); });
    page.on('pageerror', e => browserErrors.push({ step: current, role, page: label(page.url()), text: 'JavaScript error: ' + e.message }));
    page.on('response', r => {
        const s = r.status();
        if (r.request().isNavigationRequest() && r.frame() === page.mainFrame()) page.__status = s;
        if (s >= 500 && !page.__expect5xx) browserErrors.push({ step: current, role, page: label(r.url()), text: `HTTP ${s} ${r.request().method()} ${r.url().replace(BASE, '')}` });
        else if (s >= 400 && s < 500 && s !== 404 && !page.__expect4xx) browserErrors.push({ step: current, role, page: label(r.url()), text: `HTTP ${s} ${r.url().replace(BASE, '')}` });
    });
    page.on('requestfailed', r => browserErrors.push({ step: current, role, page: label(page.url()), text: `request failed: ${r.url().replace(BASE, '')} ${r.failure()?.errorText || ''}` }));
}

const newPhone = async (browser, role, width = 390) => {
    const ctx = await browser.newContext({
        viewport: { width, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, locale: 'de-AT',
        userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
    });
    const page = await ctx.newPage();
    page.setDefaultTimeout(20000);
    watch(page, role);
    return { ctx, page };
};

/** A child's charges, with every date the rules below need. */
function chargesOf(studentId) {
    return sql(`SELECT c.id, c.amount_cents, c.due_on, COALESCE(c.overdue_on, c.due_on), c.period_from, c.period_to,
                       COALESCE(c.covered_from, c.period_from), COALESCE(c.covered_to, c.period_to), DATE(c.created_at),
                       (SELECT cs.joined_on FROM class_students cs WHERE cs.student_id=c.student_id AND cs.class_id=c.class_id LIMIT 1)
                FROM charges c WHERE c.student_id=${studentId} AND c.cancelled=0 ORDER BY c.id`)
        .split('\n').filter(Boolean).map(l => { const v = l.split('\t');
            return { id: +v[0], cents: +v[1], due: v[2], overdue: v[3], from: v[4], to: v[5], coveredFrom: v[6], coveredTo: v[7], created: v[8], joined: v[9] }; });
}
/** What a charge for somebody who joined part-way through must say (fixed after fd0d179). */
function chargeRules(c, who) {
    const today = todayVienna();
    ok(c.coveredFrom === (c.joined > c.from ? c.joined : c.from), `${who}'s charge covers from the day they joined (${fmtDate(c.joined)})`, `covers from ${c.coveredFrom}, calendar period from ${c.from}`);
    ok(c.coveredTo === lastOfMonth(c.coveredFrom), `${who}'s charge covers to the month's end`, c.coveredTo);
    ok(c.due >= c.coveredFrom, `${who}'s charge is not due before the first day it covers`, `due ${c.due}, covers from ${c.coveredFrom}`);
    ok(c.due >= c.created, `${who}'s charge is not due before the day it was written`, `due ${c.due}, written ${c.created}`);
    ok(c.overdue > today, `${who}'s charge is not overdue on the day it appears`, `overdue from ${c.overdue}, today ${today}`);
    // 35 € for the whole month, prorated by the days covered.
    const days = (Date.parse(c.coveredTo) - Date.parse(c.coveredFrom)) / 864e5 + 1, month = Number(lastOfMonth(c.coveredFrom).slice(8, 10));
    ok(Math.abs(c.cents - Math.round(3500 * days / month)) <= 1, `${who}'s charge is ${days} of ${month} days of 35 €`, `${fmtEuro(c.cents)}, expected about ${fmtEuro(Math.round(3500 * days / month))}`);
}

/** The phone bar as it reads: each entry's visible word, not the full name a
 *  screen reader hears from its visually hidden twin. */
const barWords = (page) => page.evaluate(() => [...document.querySelectorAll('.mobile-nav a, .mobile-nav button')].map(a =>
    [...a.querySelectorAll('span')].filter(s => !s.classList.contains('visually-hidden') && !s.classList.contains('count')).map(s => s.textContent.trim()).join(' ')).join(' '));
const barLink = (page, word) => page.locator('.mobile-nav a').filter({ has: page.locator('span:not(.visually-hidden):not(.count)', { hasText: new RegExp('^' + word + '$') }) });

/** Sign in on the page that is open, the way she does. */
async function signIn(page, who, role = who === ADMIN ? 'admin' : 'family') {
    if (!/page=login/.test(page.url())) await page.goto(BASE + '/index.php?page=login');
    await look(page, role);
    // One box, for the address (ADR 0030 §1), posted as login.
    await page.fill('input[name=login]', who.email); await page.fill('input[name=password]', who.password);
    await submit(page, page.locator('main form button[type=submit]'));
    await look(page, role);
}
/** Mein Konto → Abmelden: the button at the foot of the page, not the account
 *  menu's copy in the top bar, which sits folded away on a phone. */
async function signOut(page) {
    await page.goto(BASE + '/index.php?page=profile');
    await submit(page, page.locator('main form:has(input[name=action][value=logout]) button'));
    ok(label(page.url()) === '?page=login' || await page.locator('form:has(input[name=action][value=login])').count() === 1,
       '„Abmelden“ ends the session', page.url());
}

/** Press a button and wait for the page it leads to. */
async function submit(page, locator) {
    await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), locator.click()]);
}
const flash = async (page) => (await page.locator('.flash, .notice.error, .notice.success, [role=alert], [role=status]').allInnerTexts().catch(() => [])).join(' | ').replace(/\s+/g, ' ').trim();
const mainText = async (page) => (await page.locator('main').innerText().catch(async () => await page.locator('body').innerText())).replace(/\s+/g, ' ');

// --- the checklist -------------------------------------------------------------
const STEP_NAMES = { organisation: 'Name und Anschrift', bank: 'Bankkonto', first_course: 'Ersten Kurs anlegen',
    course_prices: 'Preis für jeden Kurs', students: 'Kinder eintragen', billing: 'Beiträge', mail: 'E-Mails verschicken',
    privacy: 'Datenschutzerklärung', invite: 'Familien einladen' };
const stepItem = (page, key) => page.locator('li.step', { has: page.locator('strong', { hasText: new RegExp('^' + STEP_NAMES[key] + '$') }) });

async function progress(page) {
    const m = (await page.locator('.setup-progress').innerText()).match(/(\d+) von (\d+) erledigt/);
    return m ? [Number(m[1]), Number(m[2])] : null;
}
/** From the checklist, the step's own button. */
async function openStep(page, key) {
    must(label(page.url()) === '?page=start', `on the checklist before step ${STEP_NAMES[key]}`, page.url());
    const item = stepItem(page, key);
    must(await item.count() === 1, `step „${STEP_NAMES[key]}“ is listed`);
    await submit(page, item.locator('a.step-button'));
    await look(page, 'admin');
    ok(await page.locator('nav.setup-return').count() === 1, `„Zurück zur Einrichtung“ shows on ${label(page.url())}`);
}
/** Back through the bar, and the tick for the step just done. */
async function backAndTick(page, key, expectDone) {
    const bar = page.locator('nav.setup-return a');
    if (await bar.count()) await submit(page, bar);
    else { ok(expectDone === 9, `„Zurück zur Einrichtung“ still there after ${STEP_NAMES[key]}`, `on ${label(page.url())}: ${await flash(page)}`); await page.goto(BASE + '/index.php?page=start'); }
    await look(page, 'admin');
    ok(label(page.url()) === '?page=start', `the bar leads back to the checklist after ${STEP_NAMES[key]}`, page.url());
    const item = stepItem(page, key);
    const cls = await item.getAttribute('class');
    ok(/is-done/.test(cls || ''), `„${STEP_NAMES[key]}“ is ticked`, `class="${cls}", ${(await item.innerText()).replace(/\s+/g, ' ')}`);
    const [done, total] = await progress(page) || [];
    ok(done === expectDone && total === 9, `checklist reads ${expectDone} von 9 erledigt`, `reads ${done} von ${total}`);
}

/** The smallest PDF a reader opens: one page, one line of text. */
function minimalPdf(line) {
    const objs = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 144] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        null, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
    const stream = `BT /F1 12 Tf 20 70 Td (${line}) Tj ET`;
    objs[3] = `<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`;
    let out = '%PDF-1.4\n'; const offs = [];
    objs.forEach((o, i) => { offs.push(out.length); out += `${i + 1} 0 obj\n${o}\nendobj\n`; });
    const xref = out.length;
    out += `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n` + offs.map(o => String(o).padStart(10, '0') + ' 00000 n \n').join('');
    return out + `trailer\n<< /Size ${objs.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
}

/** What a PDF reader would trip over first, found without one. */
function pdfProblems(buf) {
    const p = [];
    const s = buf.toString('latin1');
    if (!s.startsWith('%PDF-1.') && !s.startsWith('%PDF-2.')) p.push('no %PDF- header: ' + JSON.stringify(s.slice(0, 20)));
    if (!/%%EOF\s*$/.test(s)) p.push('does not end in %%EOF');
    const sx = s.match(/startxref\s+(\d+)\s+%%EOF\s*$/);
    if (!sx) p.push('no startxref');
    else { const at = s.slice(Number(sx[1]), Number(sx[1]) + 20); if (!/^(xref|\d+ \d+ obj)/.test(at)) p.push('startxref points at ' + JSON.stringify(at)); }
    if (!/\/Type\s*\/Page[^s]/.test(s)) p.push('no page object');
    for (const m of s.matchAll(/(\d+) 0 obj/g)) if (!s.includes(`${m[1]} 0 obj`)) p.push('object missing');
    return p;
}
/** The text a reader would show, as far as uncompressed and Flate streams give it up. */
function pdfText(buf) {
    const s = buf.toString('latin1'); let out = '';
    for (const m of s.matchAll(/stream\r?\n/g)) {
        const start = m.index + m[0].length; const end = s.indexOf('endstream', start);
        const raw = buf.subarray(start, end);
        let data; try { data = zlib.inflateSync(raw).toString('latin1'); } catch { data = raw.toString('latin1'); }
        for (const t of data.matchAll(/\(((?:\\.|[^\\)])*)\)\s*Tj|\[((?:[^\]])*)\]\s*TJ/g))
            out += (t[1] ?? t[2].replace(/\)\s*-?\d+(\.\d+)?\s*\(/g, '').replace(/^\(|\)$/g, '')) + ' ';
    }
    return out;
}

// --- the walk ------------------------------------------------------------------
const steps = [];
const step = (name, fn) => steps.push({ name, fn });
const S = { visited: {} }; // what one step leaves for the next

step('install', async ({ browser }) => {
    const { ctx, page } = await newPhone(browser, 'install');
    await page.goto(BASE + '/setup.php');
    await look(page, 'install');
    await page.fill('#db_host', '127.0.0.1'); await page.fill('#db_port', DB_PORT);
    await page.fill('#db_name', DB); await page.fill('#db_user', 'crm'); await page.fill('#db_password', 'e2e-db-pass');
    await page.fill('#admin_name', ADMIN.name); await page.fill('#admin_email', ADMIN.email);
    await page.fill('#admin_password', ADMIN.password); await page.fill('#admin_password2', ADMIN.password);
    await submit(page, page.locator('button[type=submit]'));
    await look(page, 'install');
    must((await mainText(page)).includes('Das Portal ist eingerichtet'), 'setup.php says „Das Portal ist eingerichtet“', await mainText(page));
    ok(fs.existsSync(path.join(WORK, 'site/config/config.php')), 'setup.php wrote config/config.php');
    // Refused with 403 on purpose once an administrator exists.
    page.__expect4xx = true;
    const again = await page.goto(BASE + '/setup.php');
    page.__expect4xx = false;
    ok(again.status() === 403, 'setup.php answers 403 once installed', again.status());
    ok(!(await mainText(page)).includes('Datenbankserver'), 'setup.php does not offer to install a second time');
    await ctx.close();
});

step('admin signs in', async ({ browser }) => {
    S.admin = await newPhone(browser, 'admin');
    const page = S.admin.page;
    await page.goto(BASE + '/index.php');
    await signIn(page, ADMIN);
    must(label(page.url()) === '?page=start', 'the admin lands on the checklist', page.url());
    ok((await page.locator('h1').innerText()).includes('Dein Portal einrichten'), 'heading „Dein Portal einrichten“');
    const p = await progress(page);
    ok(p && p[0] === 0 && p[1] === 9, '0 von 9 erledigt on a fresh portal', JSON.stringify(p));
    ok(await page.locator('li.step.is-done').count() === 0, 'nothing ticked on a fresh portal');
    // U.46: the phone's bar.
    ok(await barWords(page) === 'Übersicht Schüler Anwesend Chats Mehr', 'the phone bar reads Übersicht, Schüler, Anwesend, Chats, Mehr', await barWords(page));
    // U.31: signing out and in again lands on the checklist again.
    await signOut(page);
    await signIn(page, ADMIN);
    ok(label(page.url()) === '?page=start', 'signing in again lands on the checklist again', page.url());
});

/* What every page of the slow-page step records from its first moment, kept in
   sessionStorage so it outlives the change of page: at each animation frame the
   waiting page's display, visibility and opacity, the classes on <html>, where
   the shuttle is and what is pressed; and when the tap came, when the page went,
   and whether the browser's own cross-fade ran. */
const WAIT_PROBE = `(() => {
    const now = () => performance.timeOrigin + performance.now();
    const page = new URLSearchParams(location.search).get('page') || '';
    const log = e => { try { const a = JSON.parse(sessionStorage.getItem('e2e-probe') || '[]'); a.push(Object.assign({ t: now(), page }, e)); sessionStorage.setItem('e2e-probe', JSON.stringify(a)); } catch (x) {} };
    let last = '';
    const frame = () => {
        const w = document.querySelector('.wait-page');
        if (w) {
            const s = getComputedStyle(w), r = w.querySelector('.glyph-shuttle')?.getBoundingClientRect();
            const focused = document.activeElement;
            const e = { o: +s.opacity, d: s.display, v: s.visibility, c: document.documentElement.className, slow: w.classList.contains('is-slow'),
                        pending: document.querySelectorAll('.is-pending').length, at: r ? Math.round(r.left) + ',' + Math.round(r.top) : '',
                        said: document.querySelector('.wait-said')?.textContent || '',
                        focus: !focused ? '' : focused.classList.contains('wait-cancel') ? 'Abbrechen' : focused.tagName + (focused.getAttribute('href') || '') };
            const sig = [e.d, e.v, e.o, e.c, e.pending, e.said, e.focus].join('|');
            if (e.d !== 'none' || sig !== last) log(e);
            last = sig;
        }
        requestAnimationFrame(frame);
    };
    requestAnimationFrame(frame);
    addEventListener('click', () => log({ ev: 'tap' }), true);
    addEventListener('pagehide', () => log({ ev: 'gone' }));
    addEventListener('pagereveal', e => { if (e.viewTransition) e.viewTransition.ready.then(() => log({ ev: 'cross-fade ran' }), () => log({ ev: 'cross-fade skipped' })); });
})();`;

step('a slow page', async ({ browser }) => {
    /* Part 0.4b, on the trainer's own sign-in in sessions of its own: the next
       page held back on its way (CDP, documents only, so the cache stays on), the
       tap a real touch, and every frame the screen shows read at two points - on
       the net, and in the page's margin, where a white frame would show. */
    const state = await S.admin.ctx.storageState();
    const decoder = await (await browser.newContext()).newPage();
    const pixels = (frames, points) => decoder.evaluate(async ({ frames, points }) => {
        const out = [];
        for (const data of frames) {
            const img = new Image(); img.src = 'data:image/png;base64,' + data; await img.decode();
            const canvas = new OffscreenCanvas(img.width, img.height), g = canvas.getContext('2d');
            g.drawImage(img, 0, 0);
            out.push(points.map(([x, y]) => [...g.getImageData(x, y, 1, 1).data.slice(0, 3)]));
        }
        return out;
    }, { frames, points });
    const near = (a, b, d) => Math.abs(a[0] - b[0]) + Math.abs(a[1] - b[1]) + Math.abs(a[2] - b[2]) <= d;
    const sleep = ms => new Promise(r => setTimeout(r, ms));

    /** Students, tapped in the bar, held back `hold` ms; what the pages recorded and the screen showed. */
    const slowTap = async ({ hold, motion = 'no-preference', cancelAt = 0, withoutAppJs = false, after = 2600, keyboard = false }) => {
        const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 1, isMobile: true, hasTouch: true,
                                               locale: 'de-AT', reducedMotion: motion, storageState: state });
        await ctx.addInitScript(WAIT_PROBE);
        const page = await ctx.newPage();
        page.setDefaultTimeout(20000);
        await page.goto(BASE + '/index.php?page=start', { waitUntil: 'load' });
        await sleep(300);
        // Where the net, the margin and „Abbrechen" are, and their colours: the waiting page shown for a moment by its classes alone.
        const spots = await page.evaluate(() => {
            const html = document.documentElement, wait = document.querySelector('.wait-page');
            html.classList.add('is-waiting'); wait.classList.add('is-slow');
            const net = document.querySelector('.wait-net').getBoundingClientRect(), cancel = document.querySelector('.wait-cancel').getBoundingClientRect();
            const spots = { net: [Math.round(net.left + net.width / 2 - 0.5), Math.round(net.top + net.height / 2)], margin: [3, 600],
                            netColour: getComputedStyle(document.querySelector('.wait-net')).backgroundColor, ground: getComputedStyle(document.body).backgroundColor,
                            cancel: { x: cancel.left + cancel.width / 2, y: cancel.top + cancel.height / 2 } };
            html.classList.remove('is-waiting'); wait.classList.remove('is-slow'); sessionStorage.clear();
            return spots;
        });
        const cdp = await ctx.newCDPSession(page);
        const frames = [];
        cdp.on('Page.screencastFrame', f => { frames.push({ t: f.metadata.timestamp * 1000, data: f.data }); cdp.send('Page.screencastFrameAck', { sessionId: f.sessionId }).catch(() => {}); });
        const patterns = [{ urlPattern: '*page=students*', resourceType: 'Document', requestStage: 'Request' }];
        if (withoutAppJs) { await cdp.send('Network.enable'); await cdp.send('Network.setCacheDisabled', { cacheDisabled: true }); patterns.push({ urlPattern: '*/assets/app.js*', resourceType: 'Script', requestStage: 'Request' }); }
        await cdp.send('Fetch.enable', { patterns });
        cdp.on('Fetch.requestPaused', async e => {
            if (e.resourceType === 'Script') return cdp.send('Fetch.failRequest', { requestId: e.requestId, errorReason: 'BlockedByClient' }).catch(() => {});
            await sleep(hold);
            await cdp.send('Fetch.continueRequest', { requestId: e.requestId }).catch(() => {});   // refused once „Abbrechen" has stopped it
        });
        await cdp.send('Page.startScreencast', { format: 'png', everyNthFrame: 1 });
        const link = page.locator('.mobile-nav a[href*="page=students"]').first();
        const box = await link.boundingBox();
        const touch = async (x, y) => {
            await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y }] });
            await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
        };
        // Enter on whatever has focus, as a keyboard or VoiceOver acts.
        const enter = async () => {
            for (const type of ['keyDown', 'keyUp'])
                await cdp.send('Input.dispatchKeyEvent', { type, key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, nativeVirtualKeyCode: 13, ...(type === 'keyDown' ? { text: '\r' } : {}) });
        };
        if (keyboard) { await link.focus(); await enter(); }
        else await touch(box.x + box.width / 2, box.y + box.height / 2);
        // Only a touch or a key while the page is on its way: evaluate() waits for a pending navigation.
        if (cancelAt) { await sleep(cancelAt); if (keyboard) await enter(); else await touch(spots.cancel.x, spots.cancel.y); }
        await sleep(cancelAt ? after : hold + after);
        await cdp.send('Page.stopScreencast').catch(() => {});
        await page.waitForLoadState('load').catch(() => {});
        const end = await page.evaluate(() => {
            const wait = document.querySelector('.wait-page'), s = getComputedStyle(wait), mid = document.elementFromPoint(innerWidth / 2, innerHeight / 2);
            const focused = document.activeElement;
            return { page: new URLSearchParams(location.search).get('page'), html: document.documentElement.className, stored: sessionStorage.getItem('crmb-wait'),
                     pending: document.querySelectorAll('.is-pending').length, said: document.querySelector('.wait-said').textContent,
                     focus: !focused ? '' : focused.classList.contains('wait-cancel') ? 'Abbrechen' : focused.tagName + (focused.getAttribute('href') || ''),
                     d: s.display, o: +s.opacity, v: s.visibility, tapReachesPage: !!mid && !wait.contains(mid), probe: JSON.parse(sessionStorage.getItem('e2e-probe') || '[]') };
        });
        await ctx.close();
        const probe = end.probe, tap = probe.find(e => e.ev === 'tap')?.t ?? 0, since = t => Math.round(t - tap);
        const samples = probe.filter(e => e.d !== undefined && e.t >= tap);
        const shown = samples.find(e => e.d !== 'none' && e.o > 0);
        const gone = probe.find(e => e.ev === 'gone');
        const firstNew = samples.find(e => e.page === 'students');
        const fading = samples.find(e => /\bis-arrived\b/.test(e.c));
        const hidden = shown && samples.find(e => e.t > shown.t && (e.d === 'none' || e.o === 0 || e.v === 'hidden'));
        const seen = frames.filter(f => f.t >= tap);
        const read = seen.length ? await pixels(seen.map(f => f.data), [spots.net, spots.margin]) : [];
        const netColour = spots.netColour.match(/\d+/g).slice(0, 3).map(Number), ground = spots.ground.match(/\d+/g).slice(0, 3).map(Number);
        // Up to its fade-out; without one, until the next page has been there 0.2 s, or „Abbrechen“.
        const until = fading ? fading.t : Math.min(firstNew ? firstNew.t + 200 : Infinity, cancelAt ? tap + cancelAt : Infinity);
        const gaps = [], white = [];
        seen.forEach((f, i) => {
            if (shown && f.t > shown.t + 300 && f.t < until && !near(read[i][0], netColour, 30)) gaps.push(since(f.t));
            if (!near(read[i][1], ground, 24)) white.push(since(f.t) + ' ms: ' + read[i][1].join(','));
        });
        return { end, samples, shown: shown && since(shown.t), gone: gone && since(gone.t), firstNew, fading: fading && since(fading.t), fadeFrom: fading?.o,
                 hidden: hidden && since(hidden.t), onScreen: shown && hidden ? Math.round(hidden.t - shown.t) : null, frames: seen.length, gaps, white,
                 slowLine: samples.find(e => e.slow) && since(samples.find(e => e.slow).t), said: [...new Set(samples.map(e => e.said).filter(Boolean))],
                 pressedAtOnce: samples.some(e => e.pending > 0 && e.t - tap < 400), places: new Set(samples.filter(e => e.d !== 'none' && e.at).map(e => e.at)).size,
                 focusedAbbrechen: samples.find(e => e.focus === 'Abbrechen') && since(samples.find(e => e.focus === 'Abbrechen').t),
                 focusBefore: [...samples].reverse().find(e => e.focus !== 'Abbrechen' && samples.some(f => f.focus === 'Abbrechen' && f.t > e.t))?.focus,
                 crossFade: probe.filter(e => /^cross-fade/.test(e.ev || '')).map(e => e.ev.slice(11)) };
    };
    const say = (what, r) => console.log(`   ${what}: shown ${r.shown ?? 'never'}${r.firstNew ? `, next page's first frame at ${r.firstNew.o.toFixed(2)}` : ''}`
        + `${r.fading != null ? `, faded from ${r.fadeFrom?.toFixed(2)} at ${r.fading}` : ''}${r.onScreen != null ? `, ${r.onScreen} ms on screen` : ''}`
        + `, ${r.gaps.length} gaps and ${r.white.length} white in ${r.frames} frames${r.crossFade.length ? ', cross-fade ' + r.crossFade[0] : ''}`);
    const brief = r => JSON.stringify({ shown: r.shown, gone: r.gone, firstNew: r.firstNew && [Math.round(r.firstNew.o * 100) / 100, r.firstNew.d, r.firstNew.c],
                                        fading: r.fading, fadeFrom: r.fadeFrom, hidden: r.hidden, onScreen: r.onScreen, frames: r.frames, gaps: r.gaps.slice(0, 5),
                                        white: r.white.slice(0, 3), crossFade: r.crossFade, end: { ...r.end, probe: undefined } });

    // A page that comes at once: as before, with the browser's own cross-fade.
    const fast = await slowTap({ hold: 0, after: 1200 });
    say('held 0 s', fast);
    if (fast.gone !== undefined && fast.gone < 450) {
        ok(fast.shown === null || fast.shown === undefined, 'a page that comes within 0.5 s shows no waiting page', brief(fast));
        if (fast.crossFade.length) ok(fast.crossFade[0] === 'ran', 'and keeps the browser\'s own cross-fade', brief(fast));
    } else note(false, 'the page held back 0 ms came within 0.45 s, so a quick page could be judged', brief(fast));

    // Held 1.2 s: pressed at once, the waiting page after 0.5 s, carried on by the next page and faded out by it.
    const slow = await slowTap({ hold: 1200 });
    say('held 1.2 s', slow);
    ok(slow.pressedAtOnce, 'a slow page: the tapped link is pressed at once', brief(slow));
    ok(slow.shown >= 480 && slow.shown <= 900, 'the waiting page shows once the page has not come after 0.5 s', brief(slow));
    ok(slow.said[0] === 'Wird geladen …', 'and says „Wird geladen …“', brief(slow));
    ok(slow.firstNew && slow.firstNew.d === 'flex' && slow.firstNew.o >= 0.95 && /\bis-arriving\b/.test(slow.firstNew.c),
       'the next page carries it on: its first frame shows the waiting page, fully there', brief(slow));
    ok(slow.frames > 10 && slow.gaps.length === 0, 'no frame across the change of page is without it', brief(slow));
    ok(slow.white.length === 0, 'and no frame is anything but the page\'s own ground in its margin', brief(slow));
    ok(slow.fadeFrom >= 0.9 && slow.hidden - slow.fading >= 150, 'it fades out from fully there, not cut', brief(slow));
    ok(slow.end.page === 'students' && slow.end.d === 'none' && !/\bis-(waiting|arriving|arrived)\b/.test(slow.end.html) && slow.end.stored === null && slow.end.pending === 0,
       'and then nothing of it is left: not shown, not kept, nothing pressed', brief(slow));
    if (slow.crossFade.length) ok(slow.crossFade[0] === 'skipped', 'the browser\'s own cross-fade is skipped, so no second shuttle fades in', brief(slow));
    ok(slow.places > 5, 'the shuttle flies', brief(slow) + ' places ' + slow.places);

    // Held just past the 0.5 s: never on screen for less than about 0.75 s, and still a fade.
    const justSlow = await slowTap({ hold: 650 });
    say('held 0.65 s', justSlow);
    ok(justSlow.onScreen >= 700, 'a page that comes just after 0.5 s keeps the waiting page up 0.5 s and fades it, about 0.75 s in all', brief(justSlow));
    ok(justSlow.fadeFrom >= 0.9 && justSlow.hidden - justSlow.fading >= 150, 'its fade-out starts from fully there too', brief(justSlow));

    // Reduce Motion: the shuttle rests; the page is carried on all the same.
    const still = await slowTap({ hold: 1200, motion: 'reduce' });
    say('Reduce Motion', still);
    ok(still.shown >= 480 && still.places === 1, 'with Reduce Motion the shuttle does not fly', brief(still) + ' places ' + still.places);
    ok(still.firstNew && still.firstNew.o >= 0.95 && still.gaps.length === 0, 'and the waiting page is carried on all the same', brief(still));

    // Without app.js on the next page, the waiting page goes by itself and lets every tap through.
    const stuck = await slowTap({ hold: 600, withoutAppJs: true, after: 3200 });
    say('without app.js', stuck);
    ok(stuck.firstNew && /\bis-arriving\b/.test(stuck.firstNew.c) && stuck.end.page === 'students' && !/\bjs\b/.test(stuck.end.html),
       'a next page whose app.js never comes still carries the waiting page on', brief(stuck));
    ok(stuck.end.o === 0 && stuck.end.v === 'hidden' && stuck.end.tapReachesPage, 'and within 2.3 s it is gone by itself, a tap reaching the page under it', brief(stuck));

    // Held 10 s: 6 s after it appeared it says so and offers „Abbrechen“, which stops the page and leaves this one as it was.
    // By the keyboard, as VoiceOver goes: Enter on the link, and Enter again once focus is on „Abbrechen“ - so focus
    // was somewhere before, and has somewhere to come back to.
    const cancelled = await slowTap({ hold: 10000, cancelAt: 7500, after: 3200, keyboard: true });
    say('„Abbrechen“ at 7.5 s', cancelled);
    ok(cancelled.slowLine >= cancelled.shown + 5950 && cancelled.slowLine < 7500 && cancelled.said.includes('Dauert länger als sonst.'),
       'a page that takes over 6 s more: „Dauert länger als sonst.“ and „Abbrechen“', brief(cancelled) + ' line at ' + cancelled.slowLine);
    ok(cancelled.focusedAbbrechen >= cancelled.slowLine && cancelled.focusedAbbrechen < cancelled.slowLine + 200,
       'focus goes to „Abbrechen“ with the line, and not before', brief(cancelled) + ` focus at ${cancelled.focusedAbbrechen}, line at ${cancelled.slowLine}`);
    ok(cancelled.end.page === 'start' && cancelled.end.d === 'none' && !/\bis-waiting\b/.test(cancelled.end.html) && cancelled.end.stored === null
       && cancelled.end.pending === 0 && cancelled.end.said === '', '„Abbrechen“ stops it and puts the page back as it was, for good', brief(cancelled));
    ok(cancelled.focusBefore !== undefined && cancelled.focusBefore !== 'BODY' && cancelled.end.focus === cancelled.focusBefore,
       'with focus back where it was, on the link activated', `before ${cancelled.focusBefore}, after ${cancelled.end.focus}`);
    await decoder.context().close();
});

step('1 organisation', async () => {
    const page = S.admin.page;
    await openStep(page, 'organisation');
    const f = page.locator('form:has(input[name=action][value=defaults_registry_save])');
    await f.locator('[name=set_org_name]').fill('Badmintonschule Sabine Berger');
    await f.locator('[name=set_org_street]').fill('Hallenweg 7');
    await f.locator('[name=set_org_zip]').fill('1100');
    await f.locator('[name=set_org_city]').fill('Wien');
    await f.locator('[name=set_org_email]').fill(ADMIN.email);
    await f.locator('[name=set_org_tax_mode]').selectOption('small');
    await save(page, f, 'the organisation form');
    await look(page, 'admin');
    ok(!/fehlgeschlagen|error/i.test(await flash(page)), 'organisation saved', await flash(page));
    // U.33: the way back stays on the page the save returns to and on any page
    // opened next; opening the checklist ends it.
    ok(await page.locator('nav.setup-return').count() === 1, 'the page the save returns to offers the way back');
    await submit(page, barLink(page, 'Übersicht'));
    await look(page, 'admin');
    ok(await page.locator('nav.setup-return').count() === 1, 'and so does the next page opened from the menu');
    await backAndTick(page, 'organisation', 1);
    await submit(page, barLink(page, 'Übersicht'));
    await look(page, 'admin');
    ok(await page.locator('nav.setup-return').count() === 0, 'once the checklist was opened, the way back is gone');
    await page.goto(BASE + '/index.php?page=start');
    await look(page, 'admin');
});

step('2 bank', async () => {
    const page = S.admin.page;
    await openStep(page, 'bank');
    const f = page.locator('form:has(input[name=action][value=profile_save])');
    await f.locator('[name=recipient]').fill('Sabine Berger');
    await f.locator('[name=iban]').fill(IBAN);
    await submit(page, f.locator('button[type=submit]'));
    await look(page, 'admin');
    ok(sql("SELECT REPLACE(iban,' ','') FROM payment_profiles WHERE id=1") === IBAN, 'the IBAN is stored', sql('SELECT iban FROM payment_profiles'));
    await backAndTick(page, 'bank', 2);
});

step('3 course', async () => {
    const page = S.admin.page;
    await openStep(page, 'first_course');
    const f = page.locator('form:has(input[name=action][value=class_save])');
    await f.locator('[name=name]').fill(COURSE);
    await f.locator('[name=location]').fill('Sporthalle Nord');
    const trainer = f.locator('[name=trainer_id]');
    if (await trainer.count()) await trainer.selectOption({ index: 1 });
    await f.locator('[name="day_weekday[]"]').first().selectOption('2');
    await f.locator('[name="day_starts_at_h[]"]').first().selectOption('16');
    await f.locator('[name="day_starts_at_m[]"]').first().selectOption('00');
    await f.locator('[name="day_ends_at_h[]"]').first().selectOption('17');
    await f.locator('[name="day_ends_at_m[]"]').first().selectOption('30');
    await submit(page, f.locator('button[type=submit]:has-text("Speichern")'));
    await look(page, 'admin');
    S.classId = Number(sql(`SELECT id FROM classes WHERE name='${COURSE}'`));
    must(S.classId > 0, 'the course is stored', await flash(page));
    ok(sql(`SELECT COUNT(*) FROM class_days WHERE class_id=${S.classId}`) === '1', 'with one training day (Dienstag)');
    await backAndTick(page, 'first_course', 3);
});

/** Save a form with its own visible button, as a thumb would. A form without
 *  one stops the walk there: nobody can get past it either. (fd0d179 shipped the
 *  tariff form like that; this walk found it by pressing buttons.) */
async function save(page, form, what) {
    const button = form.locator('button[type=submit], input[type=submit], button:not([type])').filter({ visible: true });
    must(await button.count() > 0, `${what} has a visible save button`, `on ${label(page.url())}; the page reads: ${(await form.innerText()).replace(/\s+/g, ' ').slice(-120)}`);
    return submit(page, button.last());
}

/** Two URLs name the same page when their query parameters are the same, in any order. */
const samePage = (a, b) => {
    const q = (u) => [...new URL(u).searchParams].filter(([k]) => k !== 'from').map(([k, v]) => k + '=' + v).sort().join('&');
    return q(a) === q(b);
};
/** The portal's today, which is Vienna's, not UTC's. */
const todayVienna = () => new Date().toLocaleDateString('sv-SE', { timeZone: 'Europe/Vienna' });
const fmtDate = (iso) => iso ? iso.slice(8, 10) + '.' + iso.slice(5, 7) + '.' + iso.slice(0, 4) : '';
const fmtEuro = (cents) => (cents / 100).toFixed(2).replace('.', ',') + ' €';
const lastOfMonth = (iso) => { const d = new Date(Date.UTC(+iso.slice(0, 4), +iso.slice(5, 7), 0)); return d.toISOString().slice(0, 10); };

step('4 price', async () => {
    const page = S.admin.page;
    await openStep(page, 'course_prices');
    ok(label(page.url()).includes('tab=tariffs'), 'step 4 opens the course\'s tariffs', page.url());
    const f = page.locator('form:has(input[name=action][value=tariff_save])');
    // U.53: the name and the price are shown, the rest waits shut under „Mehr
    // Möglichkeiten“, and nothing has to be opened to save.
    ok(await f.locator('details.more-options').count() === 1 && !(await f.locator('details.more-options').getAttribute('open') !== null),
       'the rest of a new tariff waits shut under „Mehr Möglichkeiten“');
    await f.locator('[name=name]').fill('Monatsbeitrag');
    await f.locator('[name="rate_interval[]"]').first().selectOption('1');
    await f.locator('[name="rate_price[]"]').first().fill('35,00');
    await save(page, f, 'the tariff form');
    await look(page, 'admin');
    ok(sql(`SELECT COUNT(*) FROM tariffs WHERE class_id=${S.classId} AND archived=0`) === '1', 'the tariff is stored', await flash(page));
    await backAndTick(page, 'course_prices', 4);
});

step('5 children', async () => {
    const page = S.admin.page;
    // A day strictly between the 1st and today, so the charge covers part of the
    // month and is written after the day it would naively be due. On the 1st and
    // 2nd of a month there is none; said, not skipped quietly.
    const today = todayVienna(), day = Number(today.slice(8, 10));
    S.midJoin = day >= 3 ? today.slice(0, 8) + String(Math.floor((1 + day) / 2)).padStart(2, '0') : null;
    note(S.midJoin, 'no mid-month join date before today exists on the 1st or 2nd; the earlier-joiner checks run with today only');
    for (const [i, child] of CHILDREN.entries()) {
        await openStep(page, 'students');
        // With a child already there, the step leads to the list, and a new
        // child starts from its „+ Schüler anlegen“; with none, the step opens
        // the wizard itself (ADR 0023 §5, §9).
        if (label(page.url()) === '?page=students') {
            // By its words: „Per E-Mail einladen“ beside it is a link to
            // page=students&invite=1, which an href match would take instead.
            const add = page.locator('main a[href*="page=student_new"]', { hasText: 'Schüler anlegen' });
            must(await add.count() === 1, 'the list of children offers „+ Schüler anlegen“', await mainText(page));
            await submit(page, add);
            await look(page, 'admin');
        }
        ok(new URL(page.url()).searchParams.get('page') === 'student_new', `${child.first} is added through the wizard`, page.url());
        // Step 1: who is joining. No course yet, so the checklist below still
        // leads to the child's „Kurse“ tab as it did.
        const f = page.locator('form:has(input[name=action][value=student_draft])');
        await f.locator('[name=first_name]').fill(child.first);
        await f.locator('[name=last_name]').fill(child.last);
        await f.locator('[name=birth_date]').fill(child.born);
        await f.locator('[name=course]').selectOption('none');
        await f.locator('[name=status]').selectOption('active');
        await save(page, f, 'step 1 of the wizard');
        await look(page, 'admin');
        ok(sql(`SELECT COUNT(*) FROM students WHERE first_name='${child.first}' AND last_name='${child.last}'`) === '0',
           `step 1 writes nothing: ${child.first} is not stored yet`, await mainText(page));
        // Step 2: how they sign in. Mail is not set up yet at this step, so
        // „Ohne Anmeldung anlegen“; the invitation comes at step 9.
        const later = page.locator('#later form:has(input[name=action][value=student_create])');
        must(await later.count() === 1, 'step 2 offers „Ohne Anmeldung anlegen“', await mainText(page));
        await save(page, later, '„Ohne Anmeldung anlegen“');
        await look(page, 'admin');
        const id = Number(sql(`SELECT id FROM students WHERE first_name='${child.first}' AND last_name='${child.last}'`));
        must(id > 0, `${child.first} is stored`, await flash(page));
        S.children = [...(S.children || []), id];
        // „Jedes Kind in einem Kurs mit Preis“: a child waiting for a course
        // holds the step up, and the step leads straight to that child.
        const bar = (await page.locator('nav.setup-return').innerText().catch(() => '')).match(/(\d+) von 9/);
        ok(bar && bar[1] === '4', `step 5 is not ticked while ${child.first} is in no course`,
           `the bar reads ${bar && bar[0]}`);
        await submit(page, page.locator('nav.setup-return a'));
        await look(page, 'admin');
        ok(!/is-done/.test(await stepItem(page, 'students').getAttribute('class') || ''), `„Kinder eintragen“ is open again while ${child.first} has no course`);
        await openStep(page, 'students');
        const u = new URL(page.url());
        ok(u.searchParams.get('id') === String(id) && u.searchParams.get('tab') === 'classes',
           `the step leads to ${child.first}'s „Kurse“ tab`, page.url());
        if (!(u.searchParams.get('id') === String(id) && u.searchParams.get('tab') === 'classes')) {
            await page.goto(BASE + `/index.php?page=student&id=${id}&tab=classes`);
            await look(page, 'admin');
        }
        const row = page.locator('#add-course .record-row', { hasText: COURSE });
        must(await row.count() === 1, `${COURSE} is offered on ${child.first}'s Kurse tab`, await mainText(page));
        await save(page, row.locator('form'), `„Eintragen“ for ${child.first}`);
        await look(page, 'admin');
        ok(sql(`SELECT COUNT(*) FROM class_students WHERE student_id=${id} AND class_id=${S.classId} AND left_on IS NULL`) === '1',
           `${child.first} is in ${COURSE}`, await flash(page));
        ok(sql(`SELECT COUNT(*) FROM class_students WHERE student_id=${id} AND tariff_id IS NOT NULL`) === '1',
           `${child.first}'s place has the tariff`, sql(`SELECT * FROM class_students WHERE student_id=${id}`));
        if (child.first === MID_JOINER && S.midJoin) {
            // Joined part-way through the month: the enrolment's own „Dabei
            // seit“, under „Tarif, Zahlungsweise und Rabatt → Mehr Möglichkeiten“.
            const form = page.locator('form:has(input[name=action][value=enrolment_save])');
            await page.locator('summary', { hasText: 'Tarif, Zahlungsweise und Rabatt' }).first().click();
            await form.locator('details.more-options > summary').click();
            await form.locator('[name=joined_on]').fill(S.midJoin);
            await save(page, form, `„Tarif, Zahlungsweise und Rabatt“ for ${child.first}`);
            await look(page, 'admin');
            ok(sql(`SELECT joined_on FROM class_students WHERE student_id=${id} AND class_id=${S.classId}`) === S.midJoin,
               `${child.first} joined ${COURSE} on ${fmtDate(S.midJoin)}, part-way through the month`, await flash(page));
        }
        await backAndTick(page, 'students', 5);
    }
});

step('6 charges', async () => {
    const page = S.admin.page;
    await openStep(page, 'billing');
    // The switch is inside „Monatsbeiträge" (the audit, N5), and the step's link
    // opens that row (from=start), so it is there without a tap. Read as served:
    // Chromium opens a shut <details> by itself when the link's #auto-charges
    // points into it, so what this browser shows would pass without the rule.
    const served = await (await page.request.get(page.url().split('#')[0])).text();
    must(served.includes('<details class="card fold-row billing-card" open>'), '„Monatsbeiträge“ is served open on arrival from the checklist', page.url());
    const f = page.locator('form:has(input[name=action][value=auto_billing_save])');
    const box = f.locator('[name=auto_billing]');
    await box.check();
    await save(page, f, 'automatic charges');
    await look(page, 'admin');
    const stored = setting('auto_billing');
    ok(stored === '1' || stored === 'true', 'automatic charges are on', `auto_billing=${stored}; ${await flash(page)}`);
    await backAndTick(page, 'billing', 6);
});

step('7 mail', async () => {
    const page = S.admin.page;
    await openStep(page, 'mail');
    const f = page.locator('form:has(input[name=action][value=smtp_save])');
    await f.locator('[name=host]').fill('127.0.0.1');
    await f.locator('[name=port]').fill(SMTP_PORT);
    await f.locator('[name=encryption]').selectOption('tls');
    await f.locator('[name=username]').fill('e2e-user');
    await f.locator('[name=smtp_password]').fill('e2e-smtp-secret');
    await f.locator('[name=from_email]').fill('noreply@example.test');
    await f.locator('[name=from_name]').fill('Badmintonschule Sabine Berger');
    await save(page, f, 'the SMTP form');
    await look(page, 'admin');
    ok(!/fehl|error/i.test(await flash(page)), 'SMTP saved', await flash(page));
    // U.34: „Nur Verbindung prüfen“ ticks the step; changing the server undoes
    // it until the next passing test; saving unchanged keeps it.
    const barCount = async () => Number(((await page.locator('nav.setup-return').innerText().catch(() => '')).match(/(\d+) von 9/) || [])[1]);
    const test = () => page.locator('form:has(input[name=action][value=smtp_test])');
    await submit(page, test().locator('button[value=connect]'));
    await look(page, 'admin');
    ok(await barCount() === 7, '„Nur Verbindung prüfen“ passing ticks the step (7 von 9)', `${await barCount()}; ${(await mainText(page)).slice(0, 300)}`);
    ok((await page.locator('.mail-test .test-result').innerText().catch(() => '')).includes('Erfolgreich'), 'the SMTP tab shows the last test as „Erfolgreich“');
    const smtpForm = () => page.locator('form:has(input[name=action][value=smtp_save])');
    await save(page, smtpForm(), 'the SMTP form, unchanged');
    await look(page, 'admin');
    ok(await barCount() === 7, 'saving the SMTP form unchanged keeps the tick', await barCount());
    await smtpForm().locator('[name=host]').fill('localhost');
    await save(page, smtpForm(), 'the SMTP form with another server');
    await look(page, 'admin');
    ok(await barCount() === 6, 'another server undoes the step until it is tested', await barCount());
    ok(await page.locator('.mail-test .test-result').count() === 0 && !(await mainText(page)).includes('Letzter Test'),
       'and the SMTP tab shows no last test', (await page.locator('.mail-test').innerText()).replace(/\s+/g, ' ').slice(-200));
    await smtpForm().locator('[name=host]').fill('127.0.0.1');
    await save(page, smtpForm(), 'the SMTP form, server put back');
    await look(page, 'admin');
    ok(await barCount() === 6, 'and putting the old one back is still untested', await barCount());
    const before = mails().length;
    const t = page.locator('form:has(input[name=action][value=smtp_test])');
    await t.locator('[name=test_email]').fill(ADMIN.email);
    await submit(page, t.locator('button[value=send]'));
    await look(page, 'admin');
    const said = await mainText(page);
    ok(/Testmail an .* verschickt/.test(said), 'the test reports „Testmail … verschickt“', (said.match(/.{0,200}(Fehlgeschlagen|verschickt|Verbindung).{0,200}/) || [said.slice(0, 300)])[0]);
    const got = mails().slice(before);
    ok(got.some(m => m.to.includes(ADMIN.email) && m.subject.includes('Test')), 'the test mail arrived at the SMTP sink', JSON.stringify(got.map(m => [m.to, m.subject])));
    ok(!JSON.stringify(sql("SELECT setting_value FROM settings WHERE setting_key='smtp_last_test'")).includes('e2e-smtp-secret'), 'the stored transcript does not hold the SMTP password');
    await backAndTick(page, 'mail', 7);
});

step('8 privacy', async () => {
    const page = S.admin.page;
    await openStep(page, 'privacy');
    const f = page.locator('form:has(input[name=action][value=privacy_save])');
    const draft = await f.locator('[name=privacy_de]').inputValue();
    const holes = draft.match(/\[[^\[\]\r\n]*\p{L}[^\[\]\r\n]*\](?!\()/gu) || [];
    ok(holes.length > 0, 'the German draft has placeholders to fill', 'none found');
    const answers = {
        'Vollständiger Name': 'Badmintonschule Sabine Berger, Hallenweg 7, 1100 Wien, trainerin@example.test',
        'Datum': '28.09.2026',
    };
    const filled = draft.replace(/\[[^\[\]\r\n]*\p{L}[^\[\]\r\n]*\](?!\()/gu, (hole) => {
        for (const [start, text] of Object.entries(answers)) if (hole.startsWith('[' + start)) return text;
        return 'Für den Testbetrieb festgelegt: nur die im Portal sichtbaren Angaben, Hosting in Österreich, Löschung nach 30 Tagen.';
    });
    await f.locator('[name=privacy_de]').fill(filled);
    await f.locator('[name=privacy_en]').fill('');   // „Leer lassen, wenn es keine englische Fassung gibt.“
    await f.locator('[name=privacy_ready]').check();
    await save(page, f, 'the privacy form');
    await look(page, 'admin');
    ok(setting('privacy_ready') === '1' || setting('privacy_ready') === 'true', 'the privacy notice is released', `privacy_ready=${setting('privacy_ready')}; ${await flash(page)}`);
    await backAndTick(page, 'privacy', 8);
});

step('9 invite', async () => {
    const page = S.admin.page;
    ok(!/is-blocked/.test(await stepItem(page, 'invite').getAttribute('class')), 'step 9 is open once 7 and 8 are done');
    await openStep(page, 'invite');
    const u = new URL(page.url());
    S.invitedChild = Number(u.searchParams.get('id'));
    must(u.searchParams.get('page') === 'student' && S.invitedChild > 0, 'step 9 leads to a child without access', page.url());
    S.childName = sql(`SELECT first_name FROM students WHERE id=${S.invitedChild}`);
    // U.20: no address yet - „Ohne Anmeldung“ (the placeholder every student
    // has, ADR 0023 §3), and the card's own form: an empty address box, the
    // invitation's language and „Einladung senden“ (ADR 0030 §6).
    const card = page.locator('#access');
    const empty = (await card.innerText()).replace(/\s+/g, ' ');
    const invite = card.locator('form:has(input[name=action][value=student_invite])');
    must(empty.includes('Ohne Anmeldung') && await invite.count() === 1 && await invite.locator('input[name=email]').inputValue() === ''
         && await invite.locator('select[name=locale]').count() === 1,
         'with no address the card says „Ohne Anmeldung“ and offers its own box for one, the language and „Einladung senden“', empty);
    await invite.locator('[name=email]').fill(FAMILY.email);
    await save(page, invite, '„Einladung senden“');
    await look(page, 'admin');
    const invited = (await page.locator('#access').innerText()).replace(/\s+/g, ' ');
    ok(invited.includes('Eingeladen') && invited.includes(FAMILY.email) && /Eingeladen am .+; der Link gilt bis .+\./.test(invited),
       'the card turns to „Eingeladen“: the address, „Eingeladen am …; der Link gilt bis …“', invited);
    S.familyId = Number(sql(`SELECT account_id FROM students WHERE id=${S.invitedChild}`));
    ok(S.familyId > 0 && sql(`SELECT state FROM accounts WHERE id=${S.familyId}`) === 'invited', 'an invited account exists for the family');
    // U.20: and the invitation is in Postausgang.
    await page.goto(BASE + '/index.php?page=outbox');
    await look(page, 'admin');
    ok((await mainText(page)).includes(FAMILY.email), 'the invitation is in „Postausgang“', (await mainText(page)).slice(0, 300));
    await page.goto(BASE + `/index.php?page=student&id=${S.invitedChild}`);
    await backAndTick(page, 'invite', 9);
    ok((await mainText(page)).includes('Alles eingerichtet'), '„Alles eingerichtet“ once all nine are done', await mainText(page));
    ok(await page.locator('li.step.is-done').count() === 9, 'all nine steps ticked');
    // U.39: „Ausblenden“, and signing in lands on the overview; „Wieder
    // anzeigen“ brings the checklist back.
    await save(page, page.locator('form:has(input[name=action][value=setup_visibility])'), '„Ausblenden“');
    await look(page, 'admin');
    ok(setting('setup_hidden') === 'true' || setting('setup_hidden') === '1', 'the checklist is hidden', setting('setup_hidden'));
    await signOut(page);
    await signIn(page, ADMIN);
    ok(label(page.url()) === '?page=dashboard', 'with it hidden, signing in lands on the overview', page.url());
    await page.goto(BASE + '/index.php?page=start');
    await look(page, 'admin');
    await save(page, page.locator('form:has(input[name=action][value=setup_visibility])'), '„Wieder anzeigen“');
    await look(page, 'admin');
    ok(!(setting('setup_hidden') === 'true' || setting('setup_hidden') === '1'), '„Wieder anzeigen“ brings it back', setting('setup_hidden'));
});

/** „Per E-Mail einladen“: an address and a language, reached from the wizard's
 *  first step - the students page's heading has one button, „+ Schüler
 *  anlegen“ (Part 1, revised 2026-10-08). */
async function inviteByAddress(page, email, locale) {
    await page.goto(BASE + '/index.php?page=dashboard');
    await submit(page, barLink(page, 'Schüler'));
    await look(page, 'admin');
    ok(await page.locator('main a[href*="invite=1"]').count() === 0, 'the students page no longer offers „Per E-Mail einladen“ beside „+ Schüler anlegen“');
    await submit(page, page.locator('main a[href*="page=student_new"]', { hasText: 'Schüler anlegen' }));
    await look(page, 'admin');
    const open = page.locator('main a[href*="invite=1"]', { hasText: 'Ohne Namen einladen' });
    must(await open.count() === 1, 'step 1 of the wizard offers „Nur die E-Mail-Adresse bekannt? Ohne Namen einladen“', await mainText(page));
    await submit(page, open);
    await look(page, 'admin');
    const f = page.locator('#invite form:has(input[name=action][value=email_invite])');
    must(await f.count() === 1, '„Per E-Mail einladen“ opens its form', await mainText(page));
    // Off, so her iPhone does not offer her own address for somebody else's.
    ok(await f.locator('[name=email]').getAttribute('autocomplete') === 'off' && await f.locator('[name=email]').getAttribute('type') === 'email',
       'the address box is type="email" with autocomplete="off"', await f.locator('[name=email]').evaluate(el => el.outerHTML));
    await f.locator('[name=email]').fill(email);
    await f.locator('[name=locale]').selectOption(locale);
    await save(page, f, '„Einladung senden“');
    await look(page, 'admin');
    return f;
}

step('invite by address', async () => {
    const page = S.admin.page;
    await inviteByAddress(page, NEWCOMER.email, 'de');
    ok((await flash(page)).includes(`Die Einladung an ${NEWCOMER.email} ist unterwegs.`), '„Die Einladung an … ist unterwegs.“', await flash(page));
    ok(new URL(page.url()).searchParams.get('invitations') === '1', 'and back on the students page with the invitations', page.url());
    const list = page.locator('details#invitations');
    ok(await list.count() === 1 && await list.getAttribute('open') !== null, '„Offene Einladungen“ is there and open');
    const row = list.locator('.record-row', { hasText: NEWCOMER.email });
    ok(await row.count() === 1 && await row.locator('button', { hasText: 'Erneut senden' }).count() === 1
       && await row.locator('summary', { hasText: 'Zurückziehen' }).count() === 1,
       'it lists the address with „Erneut senden“ and „Zurückziehen“', (await list.innerText().catch(() => '')).replace(/\s+/g, ' '));
    S.newcomerAccount = Number(sql(`SELECT id FROM accounts WHERE email='${NEWCOMER.email}'`));
    ok(S.newcomerAccount > 0 && sql(`SELECT CONCAT(role,'|',name,'|',verified_at IS NULL) FROM accounts WHERE id=${S.newcomerAccount}`) === 'student||1',
       'a student login with no name, not yet set up', sql(`SELECT role,name,verified_at FROM accounts WHERE email='${NEWCOMER.email}'`));
    ok(sql(`SELECT COUNT(*) FROM students WHERE account_id=${S.newcomerAccount}`) === '0', 'and no student until they set themselves up');
    // The same address again is refused, and the form comes back with it.
    const again = await inviteByAddress(page, NEWCOMER.email.toUpperCase(), 'de');
    ok(await page.locator('.flash.error').count() === 1 && (await flash(page)).includes('schon eine Einladung unterwegs'),
       'the same address again is refused, pointing to the invitation already on its way', await flash(page));
    ok(await again.locator('[name=email]').inputValue() === NEWCOMER.email.toUpperCase(), 'and the form comes back with what was typed',
       await again.locator('[name=email]').inputValue().catch(() => 'no form'));
    ok(sql(`SELECT COUNT(*) FROM accounts WHERE email='${NEWCOMER.email}'`) === '1', 'no second login is made');
    // An English one, which the next steps open and then withdraw.
    await inviteByAddress(page, GUEST_EN.email, 'en');
    S.guestAccount = Number(sql(`SELECT id FROM accounts WHERE email='${GUEST_EN.email}'`));
    ok(S.guestAccount > 0 && sql(`SELECT locale FROM accounts WHERE id=${S.guestAccount}`) === 'en', 'the English invitation is stored in English');
});

step('family accepts the invitation', async ({ browser }) => {
    // She has no shell and no cron: the queue is worked by the background task
    // that runs after page views, at most once a minute. So wait, while she
    // keeps using the portal, exactly as it would happen for her.
    const admin = S.admin.page;
    const invite = await waitFor('the invitation mail', () => mails().find(m => m.to.includes(FAMILY.email)), 150,
        async () => { await admin.goto(BASE + '/index.php?page=dashboard'); });
    must(invite, 'the invitation reaches the family\'s inbox within 150 s', JSON.stringify(sql("SELECT id,status,category,attempts,error FROM mail_jobs")));
    console.log(`   invitation: „${invite.subject}“`);
    // U.26: the family is greeted by the child's first name.
    ok(invite.body.includes(`Hallo ${S.childName},`), `the invitation starts „Hallo ${S.childName},“`, invite.body.slice(0, 120));
    const link = (invite.body.match(/https?:\/\/\S+token=[a-f0-9]{64}/) || [])[0];
    must(link, 'the invitation carries a link with a token', invite.body.slice(0, 500));
    ok(link.startsWith(BASE), 'the link points at this portal', link);
    S.family = await newPhone(browser, 'family');
    const page = S.family.page;
    await page.goto(link);
    await look(page, 'family');
    const f = page.locator('form:has(input[name=action][value=activate])');
    must(await f.count() === 1, 'the link opens „Konto einrichten“', await mainText(page));
    // „Schüler anlegen“ (option 2): the child exists, so only a password is asked.
    ok(await f.locator('[name=first_name], [name=last_name], [name=birth_date]').count() === 0,
       'for a child staff entered, the page asks for no name and no birth date', await mainText(page));
    await addressBesidePassword(f, FAMILY.email, 'the family');
    await f.locator('[name=password]').fill(FAMILY.password);
    await f.locator('[name=password_confirm]').fill(FAMILY.password);
    await f.locator('[name=privacy_seen]').check();
    await save(page, f, '„Konto aktivieren“');
    await look(page, 'family');
    ok((await flash(page)).includes(`Dein Konto ist bereit. Du meldest dich ab jetzt mit ${FAMILY.email} an.`),
       '„Dein Konto ist bereit. Du meldest dich ab jetzt mit … an.“', await flash(page));
    // „Dein Foto“ (ADR 0031, as amended): asked once, right after the first
    // password, for the child's picture. This family skips it.
    ok(label(page.url()) === '?page=welcome', 'the first password leads to „Dein Foto“', page.url());
    ok((await page.locator('h1').innerText()).trim() === `Willkommen, ${S.childName}!`, `headed „Willkommen, ${S.childName}!“`, await page.locator('h1').innerText());
    const pick = page.locator('main form:has(input[name=action][value=picture_save]) input[type=file][name=picture]');
    ok(await pick.count() === 1 && await pick.getAttribute('capture') === null, 'it offers „Foto hinzufügen“, from the camera or the library', await mainText(page));
    ok(await page.locator('form:has(input[name=action][value=picture_consent])').count() === 0, 'and no course switch: that waits on the child’s page');
    await submit(page, page.locator('main a.photo-skip', { hasText: 'Überspringen' }));
    await look(page, 'family');
    // Something is still missing (no emergency contact yet), so „Überspringen“
    // goes on to the child's own page with „Noch zu ergänzen“; the child is in a
    // course already, so choosing one is not among the steps.
    const u = new URL(page.url());
    ok(u.searchParams.get('page') === 'student' && u.searchParams.get('id') === String(S.invitedChild),
       `the family lands on ${S.childName}'s own page`, page.url() + ' ' + await flash(page));
    const steps = await page.locator('section.next-steps li a').allInnerTexts();
    ok(steps.includes('Notfallkontakt eintragen') && !steps.includes('Kurs wählen'),
       '„Noch zu ergänzen“ asks for an emergency contact, and not for a course they are already in', JSON.stringify(steps));
    ok(sql(`SELECT state FROM accounts WHERE id=${S.familyId}`) === 'active', 'the account is active');
    ok((await mainText(page)).includes(S.childName), `the page names ${S.childName}`, await mainText(page));
});

/** The read-only address beside the new password, which is what lets a phone
 *  save the password under it (ADR 0021 §1). */
async function addressBesidePassword(form, address, who) {
    const box = form.locator('input[name=email]');
    must(await box.count() === 1, `the set-up page shows ${who}'s address`);
    const a = await box.evaluate(el => ({ value: el.value, readOnly: el.readOnly, disabled: el.disabled, type: el.type,
        autocomplete: el.getAttribute('autocomplete'), visible: el.offsetParent !== null,
        beforePassword: !!(el.compareDocumentPosition(el.form.querySelector('[name=password]')) & Node.DOCUMENT_POSITION_FOLLOWING) }));
    ok(a.value === address && a.readOnly && !a.disabled && a.type === 'email' && a.autocomplete === 'username' && a.visible && a.beforePassword,
       `${who}'s address stands read-only, visible and autocomplete="username" above the new password`, JSON.stringify(a));
}

step('invited by address: own record and a course', async ({ browser }) => {
    // „Per E-Mail einladen“ (step „invite by address“) sent this; the person
    // makes their own record, then asks to join a course.
    must(S.newcomerAccount > 0, 'the invitation by address was sent');
    const admin = S.admin.page;
    const invite = await waitFor('the invitation by address', () => mails().find(m => m.to.includes(NEWCOMER.email)), 150,
        async () => { await admin.goto(BASE + '/index.php?page=dashboard'); });
    must(invite, 'the invitation by address arrives within 150 s', JSON.stringify(sql("SELECT id,status,category,attempts,error FROM mail_jobs")));
    // Nobody has a name yet: „Hallo,“ and not „Hallo ,“.
    ok(/^Hallo,\r?\n/.test(mailText(invite)), 'the mail opens „Hallo,“ with no name', JSON.stringify(mailText(invite).slice(0, 60)));
    ok(invite.body.includes('Vorname, Nachname, Geburtsdatum und ein Passwort') && invite.body.includes('Danach wählst du deinen Kurs'),
       'and says what the page will ask for, and that a course comes next', invite.body.slice(0, 400));
    const link = (invite.body.match(/https?:\/\/\S+token=[a-f0-9]{64}/) || [])[0];
    must(link, 'the invitation by address carries a link', invite.body.slice(0, 500));
    S.newcomer = await newPhone(browser, 'newcomer');
    const page = S.newcomer.page;
    await page.goto(link);
    await look(page, 'newcomer');
    const f = page.locator('form:has(input[name=action][value=activate])');
    must(await f.count() === 1, 'the link opens „Konto einrichten“', await mainText(page));
    ok((await page.locator('h1').innerText()).includes('Konto einrichten'), 'headed „Konto einrichten“');
    const birth = f.locator('input[name=birth_date]');
    ok(await f.locator('input[name=first_name][required]').count() === 1 && await f.locator('input[name=last_name][required]').count() === 1
       && await birth.getAttribute('type') === 'date' && await birth.getAttribute('max') === todayVienna(),
       'it asks for first name, last name and a birth date no later than today', `max=${await birth.getAttribute('max')}, today ${todayVienna()}`);
    await addressBesidePassword(f, NEWCOMER.email, 'the newcomer');
    await f.locator('[name=first_name]').fill(NEWCOMER.first);
    await f.locator('[name=last_name]').fill(NEWCOMER.last);
    await birth.fill(NEWCOMER.born);
    await f.locator('[name=password]').fill(NEWCOMER.password);
    await f.locator('[name=password_confirm]').fill(NEWCOMER.password);
    await f.locator('[name=privacy_seen]').check();
    await save(page, f, '„Konto aktivieren“');
    await look(page, 'newcomer');
    // „Dein Foto“: this one adds a photo. Chosen, it goes at once (app.js), and
    // the step goes on to their own page.
    ok(label(page.url()) === '?page=welcome', 'their first password leads to „Dein Foto“ too', page.url());
    const photo = await page.evaluate(() => {
        const canvas = document.createElement('canvas'); canvas.width = 400; canvas.height = 300;
        const g = canvas.getContext('2d'); g.fillStyle = '#c0392b'; g.fillRect(0, 0, 400, 300); g.fillStyle = '#2471a3'; g.fillRect(0, 0, 200, 300);
        return canvas.toDataURL('image/png').split(',')[1];
    });
    await Promise.all([page.waitForNavigation({ waitUntil: 'load' }),
        page.locator('main form:has(input[name=action][value=picture_save]) input[type=file][name=picture]')
            .setInputFiles({ name: 'ich.png', mimeType: 'image/png', buffer: Buffer.from(photo, 'base64') })]);
    await look(page, 'newcomer');
    ok((await flash(page)).includes('Foto gespeichert.'), 'choosing a photo saves it: „Foto gespeichert.“', await flash(page));
    ok(await page.locator('section#picture .avatar img').count() === 1, 'and their own page shows their face', await page.locator('section#picture').innerHTML().catch(() => ''));
    const own = sql(`SELECT id, first_name, last_name, birth_date, email FROM students WHERE account_id=${S.newcomerAccount}`).split('\n').filter(Boolean);
    must(own.length === 1, 'exactly one student is made for the login', JSON.stringify(own));
    const [id, first, last, born, email] = own[0].split('\t');
    S.newcomerStudent = Number(id);
    ok(first === NEWCOMER.first && last === NEWCOMER.last && born === NEWCOMER.born && email === NEWCOMER.email,
       'with the name and birth date typed, and the address invited', own[0]);
    ok(sql(`SELECT CONCAT(name,'|',state,'|',verified_at IS NOT NULL) FROM accounts WHERE id=${S.newcomerAccount}`) === `${NEWCOMER.first} ${NEWCOMER.last}|active|1`,
       'the login is called what its holder is called, and is active', sql(`SELECT name,state,verified_at FROM accounts WHERE id=${S.newcomerAccount}`));
    ok(sql(`SELECT COUNT(*) FROM class_students WHERE student_id=${S.newcomerStudent}`) === '0', 'and is in no course yet');
    const u = new URL(page.url());
    ok(u.searchParams.get('page') === 'student' && u.searchParams.get('id') === id, 'they land on their own page', page.url());
    let steps = await page.locator('section.next-steps li a').allInnerTexts();
    ok(steps[0] === 'Kurs wählen', '„Noch zu ergänzen“ starts with „Kurs wählen“', JSON.stringify(steps));
    // Staff hear about it, by name.
    const adminId = Number(sql(`SELECT id FROM accounts WHERE email='${ADMIN.email}'`));
    ok(sql(`SELECT COUNT(*) FROM notifications WHERE account_id=${adminId} AND title='Neu im Portal: ${NEWCOMER.first} ${NEWCOMER.last}'`) === '1',
       `the administrator is told „Neu im Portal: ${NEWCOMER.first} ${NEWCOMER.last}“ once`, sql(`SELECT kind,title FROM notifications WHERE account_id=${adminId}`));

    // „Kurs wählen“ → the course offered → „Anmeldung anfragen“.
    await submit(page, page.locator('section.next-steps li a', { hasText: 'Kurs wählen' }));
    await look(page, 'newcomer');
    ok(new URL(page.url()).searchParams.get('tab') === 'classes' && new URL(page.url()).hash === '#add-course', '„Kurs wählen“ opens the free courses', page.url());
    const row = page.locator('#add-course .record-row', { hasText: COURSE });
    must(await row.count() === 1, `${COURSE} is offered`, await mainText(page));
    await save(page, row.locator('form'), '„Anmeldung anfragen“');
    await look(page, 'newcomer');
    ok((await flash(page)).includes('Deine Anfrage ist unterwegs'), 'the request is on its way', await flash(page));
    ok(sql(`SELECT COUNT(*) FROM enrolment_requests WHERE student_id=${S.newcomerStudent} AND class_id=${S.classId} AND kind='join' AND state='pending'`) === '1',
       'one join request waits for the trainer', sql(`SELECT * FROM enrolment_requests WHERE student_id=${S.newcomerStudent}`));
    ok(sql(`SELECT COUNT(*) FROM class_students WHERE student_id=${S.newcomerStudent}`) === '0' && sql(`SELECT COUNT(*) FROM charges WHERE student_id=${S.newcomerStudent}`) === '0',
       'and nothing is enrolled or billed until she says yes');
    await page.goto(BASE + `/index.php?page=student&id=${S.newcomerStudent}`);
    await look(page, 'newcomer');
    steps = await page.locator('section.next-steps li a').allInnerTexts();
    ok(!steps.includes('Kurs wählen'), 'with a request waiting, „Kurs wählen“ is no longer asked', JSON.stringify(steps));

    // The trainer's side: the bell, the student page and the list.
    await admin.goto(BASE + '/index.php?page=dashboard');
    await look(admin, 'admin');
    const bell = (await admin.locator('.notification-list').textContent()).replace(/\s+/g, ' ');
    ok(bell.includes(`Neu im Portal: ${NEWCOMER.first} ${NEWCOMER.last}`), 'her bell lists „Neu im Portal: …“', bell.slice(0, 300));
    await admin.goto(BASE + `/index.php?page=student&id=${S.newcomerStudent}`);
    await look(admin, 'admin');
    const staffSteps = (await admin.locator('section.next-steps').innerText().catch(() => '')).replace(/\s+/g, ' ');
    ok(staffSteps.includes('Kursanfrage beantworten') && staffSteps.includes(`${NEWCOMER.first} möchte in „${COURSE}“.`),
       '„Kursanfrage beantworten“ on the student page, with the course', staffSteps);
    await admin.locator('section.next-steps a', { hasText: 'Kursanfrage beantworten' }).click();
    await look(admin, 'admin');
    ok(await admin.locator('#requests').count() === 1 && (await admin.locator('#requests').innerText()).includes(COURSE),
       'and its link opens the Anfragen card with the request', label(admin.url()));
    await admin.goto(BASE + '/index.php?page=students&invitations=1');
    await look(admin, 'admin');
    ok(!(await admin.locator('#invitations').innerText().catch(() => '')).includes(NEWCOMER.email), 'the accepted invitation has left „Offene Einladungen“');

    // Signing in again by the address, typed in odd capitals.
    await signOut(page);
    await signIn(page, { ...NEWCOMER, email: 'Mira.Novak@EXAMPLE.test' }, 'newcomer');
    ok(await page.locator('.mobile-nav').count() === 1 && label(page.url()) !== '?page=login',
       'the address signs in whatever its capitals', page.url() + ' ' + await flash(page));
});

step('an English invitation, withdrawn', async ({ browser }) => {
    must(S.guestAccount > 0, 'the English invitation was sent');
    const admin = S.admin.page;
    const invite = await waitFor('the English invitation', () => mails().find(m => m.to.includes(GUEST_EN.email)), 150,
        async () => { await admin.goto(BASE + '/index.php?page=dashboard'); });
    must(invite, 'the English invitation arrives within 150 s');
    ok(/^Hello,\r?\n/.test(mailText(invite)), 'in English it opens „Hello,“ with no name', JSON.stringify(mailText(invite).slice(0, 60)));
    const link = (invite.body.match(/https?:\/\/\S+token=[a-f0-9]{64}/) || [])[0];
    must(link, 'the English invitation carries a link');
    // A German phone that never chose a language: the invitation's decides.
    const guest = await newPhone(browser, 'guest');
    await guest.page.goto(link);
    await look(guest.page, 'guest');
    ok((await guest.page.locator('h1').innerText()).includes('Set up your account'), 'the page opens in English', await guest.page.locator('h1').innerText());
    // Withdrawn from „Offene Einladungen“, without typing anything.
    await admin.goto(BASE + '/index.php?page=students&invitations=1');
    await look(admin, 'admin');
    const row = admin.locator('#invitations .record-row', { hasText: GUEST_EN.email });
    must(await row.count() === 1, 'the English invitation is listed', await mainText(admin));
    // A fold that removes something opens as a sheet from the bottom of the
    // screen, with what it held moved into it (design language, Part 0 C11).
    const opener = row.locator('summary', { hasText: 'Zurückziehen' });
    const withdraw = 'form:has(input[name=mode][value=withdraw])';
    const sheet = admin.locator('dialog.sheet-dialog[open]');
    await opener.click();
    ok(await sheet.count() === 1 && (await sheet.locator('.sheet-title').innerText()).includes('Zurückziehen'),
       '„Zurückziehen“ opens a sheet titled with it', await sheet.innerText().catch(() => 'no sheet'));
    ok(await sheet.locator('button.sheet-cancel').count() === 1, 'with „Abbrechen“ under it');
    // „Abbrechen“ and Escape each shut it and change nothing: the form goes back
    // into its fold, where it lives without the script, ready to open again.
    const shutWith = async (how, shut) => {
        await shut();
        await admin.locator('dialog.sheet-dialog').waitFor({ state: 'detached', timeout: 3000 }).catch(() => {});
        ok(await admin.locator('dialog.sheet-dialog').count() === 0, `${how} shuts the sheet`, await admin.locator('dialog.sheet-dialog').count() + ' sheet(s) left');
        ok(await row.locator('details[data-sheet] ' + withdraw).count() === 1, `and after ${how} the fold holds its form again`,
           await row.innerHTML().catch(() => 'no row'));
    };
    await shutWith('„Abbrechen“', () => sheet.locator('button.sheet-cancel').click());
    await opener.click();
    await shutWith('Escape', () => admin.keyboard.press('Escape'));
    await opener.click();
    await save(admin, sheet.locator(withdraw), '„Einladung zurückziehen“');
    await look(admin, 'admin');
    ok((await flash(admin)).includes(`Die Einladung an ${GUEST_EN.email} ist zurückgezogen.`), '„Die Einladung an … ist zurückgezogen.“', await flash(admin));
    ok(sql(`SELECT COUNT(*) FROM accounts WHERE email='${GUEST_EN.email}'`) === '0', 'the login is gone');
    ok(sql(`SELECT COUNT(*) FROM accounts WHERE email='${NEWCOMER.email}'`) === '1' && sql(`SELECT COUNT(*) FROM accounts WHERE id=${S.familyId}`) === '1',
       'and nobody else\'s');
    await guest.page.goto(link);
    await look(guest.page, 'guest');
    ok(/Link no longer valid|Link nicht mehr gültig/.test(await guest.page.locator('h1').innerText()), 'the old link is dead', await mainText(guest.page));
    ok(await guest.page.locator('form:has(input[name=action][value=activate])').count() === 0, 'and offers no form');
    await guest.ctx.close();
});

step('family: Profil and charges', async () => {
    const page = S.family.page;
    // U.46/U.48: the family's bar.
    ok(await barWords(page) === 'Übersicht Beiträge Chats Profil', 'the family\'s bar reads Übersicht, Beiträge, Chats, Profil', await barWords(page));
    // U.31: the checklist is the administrator's alone.
    page.__expect4xx = true;
    const start = await page.goto(BASE + '/index.php?page=start');
    ok(start.status() === 403 && (await mainText(page)).includes('Nur für Administratoren'), '?page=start answers „Nur für Administratoren“ to a family',
       `${start.status()} ${(await mainText(page)).slice(0, 120)}`);
    page.__expect4xx = false;
    // U.52: Mein Konto ends with „Datenschutz und Hilfe“. A family reaches it
    // from Profil, „Anmeldung und Darstellung“. (The refusal page has no bar;
    // back to the overview first, as she would.)
    await page.goto(BASE + '/index.php?page=dashboard');
    await submit(page, barLink(page, 'Profil'));
    await look(page, 'family');
    const account = page.locator('main a[href*="page=profile"]', { hasText: 'Anmeldung und Darstellung' });
    must(await account.count() === 1, 'Profil has the row „Anmeldung und Darstellung“', await mainText(page));
    await submit(page, account);
    await look(page, 'family');
    ok(label(page.url()) === '?page=profile', 'and it opens Mein Konto', page.url());
    ok((await page.locator('.topbar .nav-back').innerText().catch(() => '')).trim() === 'Profil', 'whose bar leads back to „Profil“',
       await page.locator('.topbar').innerText());
    // The card, and „Abmelden“ straight under it as the last thing on the page.
    const ending = await page.evaluate(() => {
        const cards = [...document.querySelectorAll('main section.card')];
        const last = cards[cards.length - 1];
        const after = last?.nextElementSibling;
        return { card: (last?.innerText || '').replace(/\s+/g, ' '), next: after ? (after.innerText || '').trim() : '', laterCards: after ? [...document.querySelectorAll('main section.card')].filter(c => after.compareDocumentPosition(c) & 4).length : -1 };
    });
    ok(/^Datenschutz und Hilfe/.test(ending.card) && /Datenschutzerklärung/.test(ending.card) && /Etwas funktioniert nicht/.test(ending.card) && /Version \d/.test(ending.card)
       && ending.next === 'Abmelden' && ending.laterCards === 0,
       'Mein Konto ends with „Datenschutz und Hilfe“ (the notice, the report, the version) and „Abmelden“', JSON.stringify(ending));
    // U.56: with no English notice, English readers get the German one and are told so.
    await page.goto(BASE + '/index.php?page=privacy&lang=en');
    await look(page, 'family');
    ok((await mainText(page)).includes('This privacy notice is only available in German'), 'in English the German notice is shown, with the line saying so', (await mainText(page)).slice(0, 200));
    await page.goto(BASE + '/index.php?page=dashboard&lang=de');
    await look(page, 'family');
    ok((await mainText(page)).includes('Hallo'), 'and back in German', (await mainText(page)).slice(0, 80));
    // The bottom bar a phone shows; at this width the sidebar is not shown at all (ADR 0028).
    const profil = barLink(page, 'Profil');
    must(await profil.count() === 1, 'the family menu has „Profil“');
    await submit(page, profil);
    await look(page, 'family');
    ok(new URL(page.url()).searchParams.get('id') === String(S.invitedChild), `„Profil“ opens ${S.childName}'s page`, page.url());
    ok((await page.locator('h1').innerText()).includes(S.childName), `„Profil“ shows ${S.childName}`, await page.locator('h1').innerText());
    // Only their own child: the sibling was not invited and is not theirs to see.
    const other = CHILDREN.map(c => c.first).find(n => n !== S.childName);
    ok(!(await mainText(page)).includes(other), `the family does not see ${other}`);

    await submit(page, page.locator('main a[href*="tab=payments"]').filter({ visible: true }).first());
    await look(page, 'family');
    const all = chargesOf(S.invitedChild);
    must(all.length === 1, 'automatic charges created one charge for the invited child', sql('SELECT id,student_id,label,amount_cents,due_on FROM charges'));
    S.charge = all[0];
    const euros = fmtEuro(S.charge.cents);
    const text = await mainText(page);
    ok(text.includes(euros), `„Beiträge“ shows the charge of ${euros}`, text.slice(0, 400));
    const shown = (text.match(/Zeitraum (\d\d\.\d\d\.\d{4} – \d\d\.\d\d\.\d{4})/) || [])[1] || '';
    console.log(`   ${S.childName}: ${euros}, joined ${S.charge.joined}, covers ${S.charge.coveredFrom}..${S.charge.coveredTo}, due ${S.charge.due}, overdue from ${S.charge.overdue}, written ${S.charge.created}; the card says „Fällig am ${(text.match(/Fällig am (\S+)/) || [])[1]}“, „Zeitraum ${shown}“`);
    chargeRules(S.charge, S.childName);
    // What the family reads on the card.
    ok(text.includes('Fällig am ' + fmtDate(S.charge.due)), `the card says „Fällig am ${fmtDate(S.charge.due)}“`, (text.match(/Fällig am \S+/) || [''])[0]);
    ok(/Überfällig 0,00 €/.test(text), 'the family is not told anything is overdue on the day it appears', (text.match(/Überfällig [0-9,]+ €/) || [''])[0]);
    ok(await page.locator('.charge-card .badge.red').count() === 0, 'no red badge on a charge that is not late');
    S.periodOnCard = shown;
    ok(shown === `${fmtDate(S.charge.coveredFrom)} – ${fmtDate(S.charge.coveredTo)}`, 'the card names the span the money is for, from the day the child joined',
       `card „${shown}“, covered ${S.charge.coveredFrom}..${S.charge.coveredTo}, calendar period ${S.charge.from}..${S.charge.to}`);
    // The sibling is not the family's to see, but the same rules hold for them.
    const sibling = S.children.find(id => id !== S.invitedChild);
    const theirs = chargesOf(sibling);
    if (ok(theirs.length === 1, 'the sibling has one charge too', theirs.length))
        chargeRules(theirs[0], CHILDREN.find(c => c.first !== S.childName).first);
    ok(text.includes(IBAN.replace(/(.{4})/g, '$1 ').trim()), 'the IBAN for the transfer is shown');
    ok(await page.locator('main img[src*="qr"], main svg, main img[alt*="QR" i], main .qr').count() > 0, 'a QR code for the bank app is shown');
});

step('family uploads a payment proof', async () => {
    const page = S.family.page;
    const f = page.locator('form:has(input[name=action][value=proof_upload])');
    must(await f.count() === 1, '„Zahlungsbeleg“ upload is offered');
    const proof = path.join(WORK, 'beleg.pdf');
    fs.writeFileSync(proof, minimalPdf('Ueberweisung Beitrag'));
    await f.locator('[name=proof]').setInputFiles(proof);
    await f.locator('[name=note]').fill('Überwiesen am Montag');
    await save(page, f, '„Beleg hochladen“');
    await look(page, 'family');
    ok(!/fehl|nicht erlaubt|error/i.test(await flash(page)), 'the proof was accepted', await flash(page));
    S.payments = sql(`SELECT COUNT(*) FROM payments p JOIN charges c ON c.id=p.charge_id WHERE c.student_id=${S.invitedChild}`);
    console.log(`   after the upload: ${await flash(page)} | payments rows for the child: ${S.payments} | proofs: ${sql('SELECT COUNT(*) FROM payment_proofs')}`);
});

step('family reports a problem', async () => {
    const page = S.family.page;
    // At the foot of every page: „Etwas funktioniert hier nicht“, a disclosure.
    const link = page.locator('#feedback summary');
    must(await link.count() === 1, '„Etwas funktioniert hier nicht“ is on the page');
    ok((await link.innerText()).includes('Etwas funktioniert'), 'it is labelled „Etwas funktioniert hier nicht“', await link.innerText());
    await link.click();
    const from = page.url();
    const f = page.locator('form:has(input[name=action][value=feedback_send])');
    await f.locator('[name=message]').fill('Der Beleg ist hochgeladen, aber ich sehe nicht, ob er angekommen ist.');
    await save(page, f, 'the problem report');
    await look(page, 'family');
    const text = await flash(page);
    ok(/Danke|gesendet|angekommen|erhalten/i.test(text), 'the family is told the report arrived', text || await mainText(page));
    ok(sql("SELECT COUNT(*) FROM feedback WHERE message LIKE '%Beleg ist hochgeladen%'") === '1', 'the report is stored once');
    ok(samePage(page.url(), from) && page.__status === 200 && !(await mainText(page)).includes('Kein Zugriff'),
       'after the report the family is back on the page they reported from - same child, same tab',
       `sent from ${from.replace(BASE, '')}, landed on ${page.url().replace(BASE, '')} (HTTP ${page.__status}): ${(await mainText(page)).slice(0, 120)}`);
    ok((await mainText(page)).includes('Zahlungsbeleg'), 'and it is the „Beiträge“ tab again, with the proof card', (await mainText(page)).slice(0, 200));
});

step('admin: charge, proof, payment confirmed', async () => {
    const page = S.admin.page;
    const euros = fmtEuro(S.charge.cents);
    await page.goto(BASE + '/index.php?page=payments');
    await look(page, 'admin');
    const list = await mainText(page);
    ok(list.includes(`${CHILDREN.find(c => c.first === S.childName).first} Huber`) && list.includes(euros), `„Geld“ lists ${S.childName}'s open ${euros}`, list.slice(-600));
    // The child's Beiträge tab: the charge, the proof the family sent, and the
    // place to record the money as received.
    await page.goto(BASE + `/index.php?page=student&id=${S.invitedChild}&tab=payments`);
    await look(page, 'admin');
    const tab = await mainText(page);
    ok(tab.includes('beleg.pdf') && tab.includes('Überwiesen am Montag'), 'the family\'s proof and note are shown to the trainer', tab.slice(0, 600));
    const proofLink = page.locator('main a', { hasText: 'beleg.pdf' });
    if (ok(await proofLink.count() > 0, 'the proof opens from a link')) {
        const res = await page.request.get(new URL(await proofLink.first().getAttribute('href'), page.url()).href);
        const body = await res.body();
        ok(res.status() === 200 && body.subarray(0, 5).toString() === '%PDF-', 'the proof downloads as the PDF the family sent', `${res.status()} ${res.headers()['content-type']} ${body.subarray(0, 20)}`);
    }
    const summary = page.locator('summary', { hasText: 'Zahlung erfassen' });
    must(await summary.count() === 1, '„+ Zahlung erfassen“ is offered on the charge');
    await summary.click();
    const f = page.locator(`form:has(input[name=action][value=payment_add]):has(input[name=charge_id][value="${S.charge.id}"])`);
    if (!(await f.locator('[name=amount]').inputValue())) await f.locator('[name=amount]').fill(euros.replace(' €', ''));
    const confirmed = f.locator('[name=confirmed]');
    if (await confirmed.count()) await confirmed.check();
    await save(page, f, '„Zahlung erfassen“');
    await look(page, 'admin');
    const pay = sql(`SELECT amount_cents, confirmed_at IS NOT NULL, voided FROM payments WHERE charge_id=${S.charge.id}`);
    ok(pay === `${S.charge.cents}\t1\t0`, 'one confirmed payment of the full amount is recorded', pay + ' ' + await flash(page));
    ok(sql('SELECT COUNT(*) FROM payments') === '1', 'and no other charge got one');
    const after = await mainText(page);
    ok(after.includes('Offen 0,00 €') && after.includes('Bezahlt'), 'the charge shows as paid, nothing open', after.slice(0, 400));
    const rest = Number(sql(`SELECT SUM(amount_cents) FROM charges WHERE cancelled=0 AND id<>${S.charge.id}`));
    await page.goto(BASE + '/index.php?page=dashboard');
    await look(page, 'admin');
    ok((await mainText(page)).includes('Offene Beiträge ' + fmtEuro(rest)),
       `the dashboard's open total is the sibling's ${fmtEuro(rest)} alone`, (await mainText(page)).slice(0, 300));
});

step('admin issues an invoice', async () => {
    const page = S.admin.page;
    await page.goto(BASE + `/index.php?page=student&id=${S.invitedChild}&tab=invoices`);
    await look(page, 'admin');
    const f = page.locator('form:has(input[name=action][value=invoice_create])');
    must(await f.count() === 1, '„Rechnung ausstellen“ is offered');
    await f.locator(`[name="charge_ids[]"][value="${S.charge.id}"]`).check();
    await save(page, f, '„Rechnung ausstellen“');
    await look(page, 'admin');
    const inv = sql(`SELECT id, number FROM invoices WHERE student_id=${S.invitedChild}`).split('\t');
    must(inv.length === 2 && Number(inv[0]) > 0, 'the invoice is stored', await flash(page));
    S.invoice = { id: Number(inv[0]), number: inv[1] };
    ok((await mainText(page)).includes(S.invoice.number), `the tab lists invoice ${S.invoice.number}`);
    const link = page.locator('main a[href*="page=download"]').filter({ hasText: /PDF|Rechnung|herunterladen|öffnen/i }).first();
    must(await link.count() === 1, 'the invoice has a PDF link', await mainText(page));
    const res = await page.request.get(new URL(await link.getAttribute('href'), page.url()).href);
    const pdf = await res.body();
    fs.writeFileSync(path.join(WORK, 'invoice.pdf'), pdf);
    ok(res.status() === 200 && /application\/pdf/.test(res.headers()['content-type'] || ''), 'the PDF answers 200 application/pdf', `${res.status()} ${res.headers()['content-type']}`);
    const problems = pdfProblems(pdf);
    ok(!problems.length, 'the invoice is a well-formed PDF', problems.join('; '));
    const text = pdfText(pdf);
    ok(text.includes(fmtEuro(S.charge.cents).replace(' €', '')), `the PDF names the amount ${fmtEuro(S.charge.cents)}`, text.slice(0, 300));
    // The same span on the invoice as on the family's card, and the right one.
    // Two dates after the word, whatever the PDF made of the dash between them.
    const m = text.match(/Leistungszeitraum\D{0,20}?(\d\d\.\d\d\.\d{4})\D{1,16}?(\d\d\.\d\d\.\d{4})/);
    const onInvoice = m ? `${m[1]} – ${m[2]}` : '';
    const norm = (t) => t;
    console.log(`   invoice Leistungszeitraum „${onInvoice}“, the family's card „${S.periodOnCard}“`);
    ok(onInvoice !== '' && norm(onInvoice) === S.periodOnCard, 'the invoice\'s Leistungszeitraum is the period on the family\'s card',
       `invoice „${onInvoice}“, card „${S.periodOnCard}“`);
    ok(S.periodOnCard === `${fmtDate(S.charge.coveredFrom)} – ${fmtDate(S.charge.coveredTo)}`, 'and both are the days the child was in the course', S.periodOnCard);
    console.log(`   invoice ${S.invoice.number}: ${pdf.length} bytes, saved as ${path.join(WORK, 'invoice.pdf')}`);
});

const reports = async (page) => {
    await page.goto(BASE + '/index.php?page=settings&tab=feedback');
    await look(page, 'admin');
    return page.locator('details.mail-item');
};

step('admin reads the problem report', async () => {
    const page = S.admin.page;
    await page.goto(BASE + '/index.php?page=settings');
    const tab = page.locator('a', { hasText: /^Rückmeldungen/ }).filter({ visible: true }).first();
    ok(/Rückmeldungen \(1\)/.test(await tab.innerText()), 'the „Rückmeldungen“ tab counts one unread report', await tab.innerText());
    await submit(page, tab);
    await look(page, 'admin');
    const item = (await reports(page)).filter({ hasText: 'Beleg ist hochgeladen' });
    must(await item.count() === 1, 'the family\'s report is listed', await mainText(page));
    const head = await item.locator('summary').first().innerText();
    ok(head.includes(FAMILY.name) || head.includes(S.childName), 'it says who sent it', head);
    const trail = item.locator('details.report-trail');
    await trail.locator('summary').click();
    const all = (await item.innerText()).replace(/\s+/g, ' ');
    ok(/Technische Einzelheiten \(\d+ Schritte?\)/.test(all), 'the trail counts its steps', all.slice(0, 600));
    ok(all.includes('hier gemeldet'), 'the trail marks where it was reported', all.slice(0, 600));
    ok(/tab=payments|Beiträge/.test(all), 'the trail shows the Beiträge tab it came from', all.slice(0, 800));
    ok(/Beleg|proof_upload|hochladen/i.test(all), 'the trail shows the proof upload just before', all.slice(0, 800));
    ok(all.includes('iPhone'), 'the device is recorded', all.slice(-300));
    // U.16: an upload in the trail shows its size and type, never its name.
    ok(/proof=\d+ B application\/pdf/.test(all) && !all.includes('beleg.pdf'), 'the uploaded proof appears by size and type, not by name',
       (all.match(/proof=[^·]*/) || [''])[0]);
    console.log('   report as shown: ' + all.slice(0, 700));
});

/** The family opens Neuigkeiten $times times while the news table is gone (U.40). */
async function breakNews(times) {
    const family = S.family.page;
    const seen = [];
    sql('RENAME TABLE news TO news_e2e_away');
    S.renamed = true;
    family.__expect5xx = true;
    try {
        for (let i = 0; i < times; i++) {
            const res = await family.goto(BASE + '/index.php?page=news');
            seen.push({ status: res.status(), text: (await family.locator('body').innerText()).replace(/\s+/g, ' ') });
            S.expected5xx = (S.expected5xx || 0) + (res.status() >= 500 ? 1 : 0);
        }
    } finally {
        sql('RENAME TABLE news_e2e_away TO news');
        S.renamed = false;
        family.__expect5xx = false;
    }
    return seen;
}

step('an unexpected error', async () => {
    const family = S.family.page;
    const admin = S.admin.page;
    const adminId = Number(sql(`SELECT id FROM accounts WHERE email='${ADMIN.email}'`));
    const told = () => Number(sql(`SELECT COUNT(*) FROM notifications WHERE account_id=${adminId} AND kind='problem'`));
    const toldBefore = told();
    const before = Number(sql('SELECT COUNT(*) FROM feedback'));
    // Something the portal cannot foresee: a table gone from under it.
    const seen = await breakNews(3);
    console.log(`   the family saw: HTTP ${seen[0].status} „${seen[0].text.slice(0, 160)}“`);
    ok(seen.every(v => v.status === 503), 'Neuigkeiten answers 503, three times', seen.map(v => v.status).join(','));
    ok(seen.every(v => v.text.includes('vorübergehend nicht verfügbar')), 'the family sees the friendly page');
    const leaks = seen.map(v => v.text.match(/SQLSTATE|news_e2e|news|PDO|Exception|\.php|Stack|#0 /g)).flat().filter(Boolean);
    ok(!leaks.length, 'and nothing technical: no SQL, no file names, no exception', [...new Set(leaks)].join(', '));
    const rows = Number(sql('SELECT COUNT(*) FROM feedback')) - before;
    ok(rows === 1, 'the three failures are written down once, not three times', `${rows} new rows`);
    ok(told() - toldBefore === 1, 'and the administrator is told once, not three times', `${told() - toldBefore} notifications`);

    // U.41/U.42 as the administrator sees them.
    let list = await reports(admin);
    let auto = list.filter({ hasText: 'Automatisch erfasst' });
    must(await auto.count() === 1, 'Rückmeldungen lists it as „Automatisch erfasst“', await mainText(admin));
    let whole = (await auto.innerText()).replace(/\s+/g, ' ');
    ok(whole.includes('Fehler auf Seite news: PDOException'), 'titled „Fehler auf Seite news: PDOException“', whole.slice(0, 200));
    ok(/\b3×/.test(whole), 'seen 3×', whole.slice(0, 200));
    const support = auto.locator('textarea.support-text');
    must(await support.count() === 1, 'with a „Für den Support kopieren“ text');
    const text = await support.inputValue();
    console.log('   support text:\n' + text.split('\n').map(l => '     | ' + l).join('\n'));
    ok(/PDOException/.test(text) && /42S02/.test(text), 'the support text says what failed', text.slice(0, 300));
    ok(/Version/.test(text) && /iPhone/.test(text) && /page=news/.test(text), 'where, the version, the device and the pages visited', text.slice(0, 400));
    const personal = [FAMILY.email, FAMILY.name, S.childName, 'Huber', ADMIN.email, 'Hallenweg', '127.0.0.1', 'Überwiesen am Montag'].filter(x => text.includes(x));
    ok(!personal.length, 'no name, no email address, no IP address, nothing anybody typed', personal.join(', '));
    const tab = await admin.locator('a', { hasText: /^Rückmeldungen/ }).filter({ visible: true }).first().innerText();
    ok(/\(\d+\)/.test(tab), 'the tab counts it', tab);

    // U.43: marked „Erledigt“, the same error is news again, counted on.
    await save(admin, auto.locator('form:has(input[name=state][value=done])'), '„Erledigt“ on the error');
    await look(admin, 'admin');
    await breakNews(1);
    list = await reports(admin);
    auto = list.filter({ hasText: 'Automatisch erfasst' });
    ok(await auto.count() === 1, 'still one entry after it happened again');
    whole = (await auto.first().innerText()).replace(/\s+/g, ' ');
    ok(/\b4×/.test(whole) && /\bNeu\b/.test(whole), 'new again, counted 4×', whole.slice(0, 200));
    ok(told() - toldBefore === 2, 'with a new notification', `${told() - toldBefore} notifications in all`);

    // The portal is fine again once the table is back.
    const res = await family.goto(BASE + '/index.php?page=news');
    ok(res.status() === 200, 'Neuigkeiten works again after the table is restored', res.status());
});

step('320px spot-check', async ({ browser }) => {
    // Every page either of them opened at 390, once more at 320 with the same
    // session. GET only, so nothing is changed by looking.
    for (const role of SIGNED_IN_ROLES) {
        if (!S[role]) continue;
        const state = await S[role].ctx.storageState();
        const ctx = await browser.newContext({ viewport: { width: 320, height: 700 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, locale: 'de-AT', storageState: state });
        const page = await ctx.newPage();
        watch(page, role);
        const urls = [...(S.visited[role] || [])].filter(u => !/page=(activate|login|download)|setup\.php/.test(u));
        for (const u of urls) {
            const res = await page.goto(u);
            ok(res.status() < 400, `${role} opens ${label(u)} at 320px`, res.status());
            await look(page, role, 320);
        }
        console.log(`   ${role}: ${urls.length} pages at 320px`);
        // A sweep that opened nothing has measured nothing; it once printed
        // „admin: 0 pages at 320px“ and counted as passed.
        ok(urls.length > 0, `${role}: at least one page measured at 320px`, `${role} took part, but no page of theirs was recorded at 390px to open again`);
        await ctx.close();
    }
});

// --- run ------------------------------------------------------------------------
const browser = await chromium.launch();
const started = Date.now();
for (const s of steps) {
    current = s.name;
    console.log(`-- ${s.name}`);
    try { await s.fn({ browser }); }
    catch (e) {
        ok(false, `step „${s.name}“ ran to the end`, e.message.split('\n')[0]);
        for (const who of SIGNED_IN_ROLES) if (S[who]) await S[who].page.screenshot({ path: path.join(SHOTS, `${s.name.replace(/\W+/g, '_')}-${who}.png`), fullPage: true }).catch(() => {});
    }
    if (process.env.CRM_E2E_DUMP && s.name === STOP_AFTER) {
        for (const who of ['admin', 'family']) if (S[who]) {
            console.log(`\n==== ${who} is on ${S[who].page.url()}\n` + await mainText(S[who].page));
            console.log(await S[who].page.evaluate(() => [...document.forms].map(f => 'FORM ' + (f.querySelector('[name=action]')?.value || f.method) + ' :: '
                + [...f.elements].filter(e => e.name && e.name !== 'csrf' && e.name !== 'request_id').map(e => e.name + '(' + e.type + (e.type === 'hidden' ? '=' + e.value : '') + ')').join(' ')
                + ' || ' + [...f.querySelectorAll('button')].map(b => b.innerText.trim()).join(' | ')).join('\n')));
        }
    }
    if (s.name === STOP_AFTER) break;
}
await browser.close();
if (S.renamed) { try { sql('RENAME TABLE news_e2e_away TO news'); } catch {} }

// --- the server's side of it ------------------------------------------------------
const phpLog = fs.existsSync(ERROR_LOG) ? fs.readFileSync(ERROR_LOG, 'utf8').split('\n').filter(Boolean) : [];
const phpProblems = phpLog.filter(l => /PHP (Warning|Notice|Deprecated|Fatal|Parse|Strict|Recoverable)/i.test(l));
// The provoked error is logged by capture_error() on purpose; anything else is not.
const expectedLog = (l) => /CRM error \(request\): PDOException .*42S02/.test(l) && S.expected5xx;
const phpOther = phpLog.filter(l => !phpProblems.includes(l) && !expectedLog(l));
const webLog = fs.readFileSync(path.join(WORK, 'web.log'), 'utf8').split('\n');
const server5xx = webLog.filter(l => /\[5\d\d\]:/.test(l));

// --- report -------------------------------------------------------------------------
const failed = results.filter(r => !r.ok);
console.log(`\n==== ${process.env.CRM_E2E_SOURCE} · ${process.env.CRM_E2E_ENGINE} · PHP ${process.env.CRM_E2E_PHP_VERSION} · ${Math.round((Date.now() - started) / 1000)}s`);
console.log(`Checks: ${results.length - failed.length} passed, ${failed.length} failed`);
if (process.env.CRM_E2E_VERBOSE) for (const r of results.filter(r => r.ok)) console.log(`  ok   [${r.step}] ${r.name}`);
for (const f of failed) console.log(`  FAIL [${f.step}] ${f.name}${f.detail ? '\n       ' + f.detail.slice(0, 400) : ''}`);
console.log(`\nPHP warnings/notices/deprecations in the error log: ${phpProblems.length}`);
for (const l of [...new Set(phpProblems.map(l => l.replace(/^\[[^\]]*\] /, '')))]) console.log('  ' + l.slice(0, 400));
if (phpOther.length) { console.log(`Other lines in the PHP error log: ${phpOther.length}`); for (const l of [...new Set(phpOther.map(l => l.replace(/^\[[^\]]*\] /, '')))].slice(0, 30)) console.log('  ' + l.slice(0, 300)); }
console.log(`\nHTTP 5xx in the server log: ${server5xx.length} (${S.expected5xx || 0} of them provoked on purpose)`);
for (const l of server5xx) console.log('  ' + l.slice(0, 200));
console.log(`\nBrowser: console errors, JS errors, failed requests, unexpected 4xx/5xx: ${browserErrors.length}`);
for (const b of browserErrors) console.log(`  [${b.step}] ${b.role} ${b.page}: ${b.text.slice(0, 300)}`);
console.log(`\nLayout (overflow, tap targets under 44px): ${layout.size}`);
for (const l of [...layout.values()].sort((a, b) => (a.page + a.width).localeCompare(b.page + b.width))) console.log(`  ${l.role} ${l.width}px ${l.page}: ${l.problem}`);
const box = mails();
console.log(`\nMail captured by the SMTP sink: ${box.length}`);
for (const m of box) console.log(`  ${m.file} to ${m.to}: „${m.subject}“`);
console.log(`\nNotes (by design or needing a decision, not counted): ${notes.length}`);
for (const n of notes) console.log(`  [${n.step}] ${n.name}${n.detail ? '\n       ' + n.detail.slice(0, 300) : ''}`);
console.log(`\nScreenshots of failed steps: ${SHOTS}`);
const bad = failed.length + phpProblems.length + phpOther.length + browserErrors.length + layout.size + Math.max(0, server5xx.length - (S.expected5xx || 0));
console.log(bad ? `\nRESULT: FAIL (${bad} problems)` : '\nRESULT: PASS');
process.exit(bad ? 1 : 0);
