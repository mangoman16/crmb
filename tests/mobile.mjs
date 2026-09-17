/**
 * The layout check the PHP suite cannot do: open every page in a real browser,
 * at the two widths that matter, in both appearances, for every role - and fail
 * on anything a thumb cannot hit or a screen cannot hold.
 *
 *   node tests/mobile.mjs --base http://127.0.0.1:8099/index.php \
 *        --admin ui@example.test --family familie@example.test --password '...'
 *
 * It needs Playwright and a Chromium, and a portal with example data in it
 * (Einstellungen → System → Beispieldaten anlegen). Nothing is written: every
 * page is opened with GET and read.
 *
 * What it refuses to let pass:
 *   - anything wider than the screen, so nothing is cut off or scrolls sideways
 *   - a link, button or tab shorter than 44pt, which is Apple's minimum and the
 *     figure this project has used since its first review
 *   - text below 12px
 *   - a JavaScript error, or a resource the page asked for and did not get
 */
// Playwright is a developer's tool, not a dependency of the portal: it is not in
// composer.json and it is not on the server. Found where it is installed rather
// than required from here, so this file can sit in the repository without
// pretending the portal needs it.
const playwrightFrom = process.env.PLAYWRIGHT_PATH
    || (process.env.npm_config_prefix ? process.env.npm_config_prefix + '/lib/node_modules/playwright/index.mjs' : '')
    || '/opt/node22/lib/node_modules/playwright/index.mjs';
let chromium;
try { ({ chromium } = await import(playwrightFrom)); }
catch { try { ({ chromium } = await import('playwright')); }
        catch { console.error('Playwright not found. Install it (npm i -g playwright) or set PLAYWRIGHT_PATH to its index.mjs.'); process.exit(2); } }

const arg = (name, fallback = '') => {
    const i = process.argv.indexOf('--' + name);
    return i > 0 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
};
const BASE = arg('base', 'http://127.0.0.1:8099/index.php');
const CHROME = arg('chromium', process.env.CHROMIUM_PATH || '');
const ACCOUNTS = { admin: arg('admin'), family: arg('family') };
const PASSWORD = arg('password');
const WIDTHS = [320, 390];

if (!ACCOUNTS.admin || !PASSWORD) {
    console.error('Usage: node tests/mobile.mjs --admin <email> --password <password> [--family <email>] [--base <url>]');
    process.exit(2);
}

/** Pages worth opening, and the query strings the interface really produces. */
const pages = async (page, role) => {
    const first = async (sql) => await page.evaluate(() => 0);   // ids come from the links below
    const common = ['dashboard', 'students', 'messages', 'news', 'profile'];
    const staff = ['classes', 'attendance', 'payments', 'invoices', 'accounts', 'outbox', 'compose',
                   'manage', 'manage&tab=ages', 'manage&tab=tariffs', 'manage&tab=payments'];
    const admin = ['settings', 'settings&tab=organisation', 'settings&tab=fields', 'settings&tab=smtp',
                   'settings&tab=privacy', 'settings&tab=system', 'history'];
    return role === 'admin' ? [...common, ...staff, ...admin] : common;
};

/**
 * Everything measured in the browser, so the numbers are the rendered ones.
 *
 * $expected is the device width this page was opened at. It matters because a
 * phone does not simply cut off content that is too wide - it zooms out until
 * the widest thing fits, exactly as Chromium does here. So a card 100px too
 * wide shows up as a layout viewport 100px wider than the phone and text that
 * has quietly shrunk, not as an element hanging over the edge. Both are checked:
 * the second catches what the first hides.
 */
const inspect = ({ label, expected }) => {
    const vw = window.innerWidth;
    const found = [];
    const seen = new Set();
    const name = (el) => (el.tagName.toLowerCase()
        + (el.id ? '#' + el.id : '')
        + (typeof el.className === 'string' && el.className.trim() ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '')).slice(0, 64);
    // A tab strip and a table scroll on purpose; their children are allowed to
    // be wider than the screen, and so is anything the page pins in place.
    const inScroller = (el) => {
        for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
            const s = getComputedStyle(p);
            if (s.overflowX === 'auto' || s.overflowX === 'scroll' || s.position === 'fixed') return true;
        }
        return false;
    };
    const add = (problem) => { const k = JSON.stringify(problem); if (!seen.has(k)) { seen.add(k); found.push(problem); } };

    for (const el of document.querySelectorAll('body *')) {
        const r = el.getBoundingClientRect();
        if (r.width === 0 || r.height === 0) continue;
        const s = getComputedStyle(el);
        if (s.visibility === 'hidden' || s.display === 'none') continue;
        if (r.right > vw + 1 && s.position !== 'fixed' && !inScroller(el))
            add({ kind: 'wider than the screen', el: name(el), right: Math.round(r.right), screen: vw });
        const size = parseFloat(s.fontSize);
        const ownText = [...el.childNodes].filter(n => n.nodeType === 3).map(n => n.textContent.trim()).join('');
        if (ownText && size && size < 12) add({ kind: 'text under 12px', el: name(el), size });
    }
    for (const el of document.querySelectorAll('a[href], button, input[type=submit], summary, .segmented label, .chip')) {
        const r = el.getBoundingClientRect();
        const s = getComputedStyle(el);
        if (r.width === 0 || r.height === 0 || s.visibility === 'hidden') continue;
        if (r.height < 44)
            add({ kind: 'tap target under 44pt', el: name(el), text: (el.textContent || '').trim().slice(0, 24), height: Math.round(r.height) });
    }
    if (vw > expected + 1)
        add({ kind: 'the page zoomed out to fit', screen: expected, neededToFit: vw });
    return { label, problems: found, sideways: document.documentElement.scrollWidth > vw + 1 };
};

const run = async () => {
    const browser = await chromium.launch(CHROME ? { executablePath: CHROME } : {});
    const failures = [];
    let screens = 0;

    // The pages somebody sees before they are signed in, and the one they see
    // when a link has gone stale. They use a different header and footer from
    // the rest of the portal, so they need looking at on their own - which is
    // exactly why their tap targets were the ones left too small.
    for (const width of WIDTHS) {
        const ctx = await browser.newContext({ viewport: { width, height: 780 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
        const page = await ctx.newPage();
        for (const query of ['login', 'forgot', 'privacy', 'student&id=999999']) {
            await page.goto(BASE + '?page=' + query, { waitUntil: 'networkidle' });
            const result = await page.evaluate(inspect, { label: `signed out ${width}px ?page=${query}`, expected: width });
            screens++;
            if (result.sideways) result.problems.push({ kind: 'the page scrolls sideways' });
            if (result.problems.length) failures.push(result);
        }
        await ctx.close();
    }

    for (const [role, email] of Object.entries(ACCOUNTS)) {
        if (!email) continue;
        // Signed in once per role: the portal rate-limits sign-ins, as it should.
        const session = await browser.newContext({ viewport: { width: 390, height: 800 }, isMobile: true, hasTouch: true });
        const door = await session.newPage();
        await door.goto(BASE + '?page=login');
        await door.fill('input[name=email]', email);
        await door.fill('input[name=password]', PASSWORD);
        await door.click('form button[type=submit]');
        await door.waitForLoadState('networkidle');
        if (!await door.$('.mobile-nav')) {
            failures.push({ label: role + ' sign-in', problems: [{ kind: 'could not sign in', text: (await door.textContent('body')).trim().slice(0, 120) }] });
            await session.close();
            continue;
        }
        const state = await session.storageState();
        await session.close();

        for (const width of WIDTHS) {
            for (const scheme of ['light', 'dark']) {
                const ctx = await browser.newContext({
                    viewport: { width, height: 780 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true,
                    colorScheme: scheme, storageState: state,
                    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
                });
                const page = await ctx.newPage();
                const noise = [];
                page.on('pageerror', e => noise.push('JavaScript error: ' + e.message));
                page.on('response', r => { if (r.status() >= 400) noise.push(r.status() + ' ' + r.url().replace(BASE, '')); });
                for (const query of await pages(page, role)) {
                    await page.goto(BASE + '?page=' + query, { waitUntil: 'networkidle' });
                    const label = `${role} ${width}px ${scheme} ?page=${query}`;
                    const result = await page.evaluate(inspect, { label, expected: width });
                    screens++;
                    if (result.sideways) result.problems.push({ kind: 'the page scrolls sideways' });
                    for (const n of noise.splice(0)) result.problems.push({ kind: 'browser complained', text: n });
                    if (result.problems.length) failures.push(result);
                }
                await ctx.close();
            }
        }
    }
    await browser.close();

    console.log(`${screens} screens opened`);
    for (const f of failures) {
        console.log('\n' + f.label);
        for (const p of f.problems) console.log('   ', JSON.stringify(p));
    }
    console.log(failures.length ? `\n${failures.length} screens with something to fix` : '\nNothing to fix.');
    process.exit(failures.length ? 1 : 0);
};
run().catch(e => { console.error(e); process.exit(2); });
