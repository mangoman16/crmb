/**
 * What the top bar's menus do when tapped, swiped and escaped, run against the
 * real public/assets/app.js - called by the shell suite, not by hand:
 *
 *   node tests/topbar-menus.mjs    prints one JSON array of {what, pass, detail}
 *
 * No browser: the script is loaded into a page made of a few stand-in elements
 * with just what the menu code touches (open, closest, contains, focus), and two
 * menus, as the top bar has: the bell and the account menu. Events are sent the way a phone sends them: a
 * tap is pointerdown, pointerup, then the click that opens or shuts a <details>;
 * a swipe is pointerdown and pointercancel, with no pointerup. A browser fires
 * „toggle" after the change rather than during it, so toggles wait in a queue
 * until settle().
 *
 * What the stand-in page does not do: document.querySelectorAll() answers only
 * '.topbar-menu' (anything else finds nothing, so the rest of app.js stays out
 * of the way - and so would a menu script that looked for its menus another
 * way); getElementById() and querySelector() find nothing. On the stand-in
 * elements, closest(), matches() and querySelector() understand a tag, classes
 * and [open], and throw on any other selector rather than guess.
 */
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const pendingToggles = [];

class FakeElement {
  constructor(tag, classes = [], parent = null) {
    this.tagName = tag.toUpperCase();
    this.classes = new Set(classes);
    this.parent = parent;
    this.children = [];
    this.listeners = {};
    this._open = false;
    if (parent) parent.children.push(this);
  }
  get open() { return this._open; }
  set open(value) {
    value = Boolean(value);
    if (value === this._open) return;
    this._open = value;
    if (this.tagName === 'DETAILS') pendingToggles.push(this);
  }
  addEventListener(type, fn) { (this.listeners[type] ??= []).push(fn); }
  matches(selector) {
    const m = /^([a-z]+)?((?:\.[\w-]+)*)((?:\[[\w-]+\])*)$/.exec(selector.trim());
    if (!m) throw new Error('the stand-in page cannot answer the selector ' + JSON.stringify(selector));
    if (m[1] && this.tagName !== m[1].toUpperCase()) return false;
    for (const cls of (m[2].match(/[\w-]+/g) ?? [])) if (!this.classes.has(cls)) return false;
    for (const attr of (m[3].match(/[\w-]+/g) ?? [])) {
      if (attr !== 'open') throw new Error('the stand-in page knows no attribute ' + attr);
      if (!this._open) return false;
    }
    return true;
  }
  closest(selector) {
    for (let el = this; el; el = el.parent) if (el.matches(selector)) return el;
    return null;
  }
  contains(other) {
    for (let el = other; el; el = el.parent) if (el === this) return true;
    return false;
  }
  descendants() { return this.children.flatMap(child => [child, ...child.descendants()]); }
  querySelector(selector) { return this.descendants().find(el => el.matches(selector)) ?? null; }
  querySelectorAll(selector) { return this.descendants().filter(el => el.matches(selector)); }
  focus() { page.activeElement = this; }
}

// The page: a top bar with its two menus and the language link beside them, and some content.
const body = new FakeElement('body');
const topbar = new FakeElement('header', ['topbar'], body);
const menu = name => {
  const details = new FakeElement('details', ['topbar-menu', name], topbar);
  const summary = new FakeElement('summary', [], details);
  const panel = new FakeElement('div', ['topbar-menu-panel'], details);
  const entry = new FakeElement('a', ['notification'], panel);
  return { details, summary, panel, entry };
};
const bell = menu('notification-pane');
const other = menu('account-menu');
const elsewhere = new FakeElement('a', ['language'], topbar);
const main = new FakeElement('main', [], body);
const heading = new FakeElement('h1', [], main);

const documentListeners = {};
const page = {
  activeElement: body,
  body: Object.assign(body, { classList: { remove() {}, toggle() { return false; } } }),
  // app.js marks the page as scripted ("js" on <html>), which is all it asks of it.
  documentElement: { classList: { add() {} } },
  getElementById: () => null,
  querySelector: () => null,
  // Only the menus are on this page: every other feature of app.js finds nothing
  // to attach to and stays out of the way.
  querySelectorAll: selector => (selector === '.topbar-menu' ? body.querySelectorAll(selector) : []),
  addEventListener: (type, fn) => { (documentListeners[type] ??= []).push(fn); },
  createElement: tag => new FakeElement(tag),
};

const context = vm.createContext({
  document: page,
  Element: FakeElement,
  location: { hash: '', pathname: '/index.php', search: '' },
  history: { replaceState() {} },
  navigator: {},
  console,
  // The window's own listeners (pageshow) are not exercised here.
  addEventListener() {},
});
context.window = context;
vm.runInContext(readFileSync(new URL('../public/assets/app.js', import.meta.url), 'utf8'), context,
  { filename: 'public/assets/app.js' });

function send(type, event) {
  for (const fn of documentListeners[type] ?? []) fn(event);
}
function settle() {
  while (pendingToggles.length) {
    const el = pendingToggles.shift();
    for (const fn of el.listeners.toggle ?? []) fn({ target: el });
  }
}
function tap(target) {
  send('pointerdown', { target });
  send('pointerup', { target });
  // What the browser does with the click that follows: a <summary> opens or
  // shuts its own <details>, nothing else changes anything.
  const summary = target.closest('summary');
  if (summary) summary.parent.open = !summary.parent.open;
  settle();
}
function swipeFrom(target) {
  send('pointerdown', { target });
  send('pointercancel', { target });
  settle();
}
function key(name) {
  send('keydown', { key: name });
  settle();
}
function shutAll() {
  bell.details.open = false; other.details.open = false; settle();
  page.activeElement = body;
}

const results = [];
const check = (what, pass, detail = '') => results.push({ what, pass: Boolean(pass), detail });
const state = () => `bell ${bell.details.open ? 'open' : 'shut'}, other ${other.details.open ? 'open' : 'shut'}`;

try {
  tap(bell.summary);
  check('tapping the bell opens it', bell.details.open, state());
  check('and leaves the other menu shut', !other.details.open, state());

  tap(other.summary);
  check('opening the other menu closes the bell', !bell.details.open && other.details.open, state());
  tap(bell.summary);
  check('and opening the bell again closes the other', bell.details.open && !other.details.open, state());
  // Enter or Space on a <summary> opens it with no pointer event at all, so
  // only the toggle listener can close the bell here - the tap above would pass
  // on the outside-tap rule alone.
  other.details.open = true;
  settle();
  check('opening the other from the keyboard closes the bell too', !bell.details.open && other.details.open, state());
  shutAll();
  tap(bell.summary);

  tap(bell.entry);
  check('a tap inside the open panel leaves it open', bell.details.open, state());

  tap(heading);
  check('a tap elsewhere on the page closes it', !bell.details.open && !other.details.open, state());

  tap(bell.summary);
  swipeFrom(heading);
  check('a swipe to scroll the page leaves it open', bell.details.open, state());

  send('pointerdown', { target: bell.entry });
  send('pointerup', { target: heading });
  settle();
  check('pressing inside the panel and letting go outside leaves it open', bell.details.open, state());

  tap(bell.summary);
  check('tapping the bell again shuts it', !bell.details.open, state());

  shutAll();
  tap(bell.summary);
  bell.entry.focus();
  key('Escape');
  check('Escape closes it', !bell.details.open, state());
  check('and puts focus back on the bell when focus was in the menu', page.activeElement === bell.summary,
    'focus on ' + page.activeElement.tagName);

  shutAll();
  tap(bell.summary);
  elsewhere.focus();
  key('Escape');
  check('Escape with focus elsewhere closes it too', !bell.details.open, state());
  check('but leaves focus where it was', page.activeElement === elsewhere, 'focus on ' + page.activeElement.tagName);

  shutAll();
  heading.focus();
  key('Escape');
  check('Escape with nothing open moves nothing', page.activeElement === heading && !bell.details.open && !other.details.open,
    state());

  tap(bell.summary);
  key('Enter');
  check('another key leaves it open', bell.details.open, state());
} catch (error) {
  check('the menu code ran against the stand-in page', false, String(error && error.stack || error));
}

process.stdout.write(JSON.stringify(results));
