/**
 * What the top bar's menus do when tapped, swiped and escaped, and what a form
 * does when it is sent twice or comes back with the Back button, run against
 * the real public/assets/app.js - called by the shell suite, not by hand:
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
 * The page also holds one form sent by POST, with two buttons, one that only
 * looks something up (GET), and links of every kind a tap can leave by. A timer
 * the script sets runs when runTimers() says so, and is kept with its delay
 * until then; the window's listeners (pageshow) are kept to be sent like the
 * document's. A click or a submit reaches the element's own listeners first and
 * the document's after, as it bubbles.
 *
 * What the stand-in page does not do: document.querySelectorAll() answers only
 * the selectors in `answered` (anything else finds nothing, so the rest of
 * app.js stays out of the way - and so would a script that looked for its menus
 * or forms another way); getElementById() finds only the template of the
 * waiting shuttle (Part 0.4a), and querySelector() only the top bar. On the
 * stand-in elements, closest(), matches() and querySelector() understand a tag,
 * classes and attributes - [open], [name] and [name="value"] - and throw on any
 * other selector rather than guess.
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
    this.attributes = {};
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
  setAttribute(name, value) { this.attributes[name] = String(value); }
  getAttribute(name) { return this.attributes[name] ?? null; }
  hasAttribute(name) { return name in this.attributes; }
  removeAttribute(name) { delete this.attributes[name]; }
  get classList() {
    const classes = this.classes;
    return { add: name => { classes.add(name); }, remove: name => { classes.delete(name); }, contains: name => classes.has(name) };
  }
  append(...nodes) {
    for (const node of nodes) {
      if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1);
      node.parent = this;
      this.children.push(node);
    }
  }
  // data-* attributes, as element.dataset reads and writes them.
  get dataset() {
    const name = key => 'data-' + String(key).replace(/[A-Z]/g, c => '-' + c.toLowerCase());
    return new Proxy({}, {
      get: (_, key) => this.attributes[name(key)],
      set: (_, key, value) => { this.attributes[name(key)] = String(value); return true; },
      deleteProperty: (_, key) => { delete this.attributes[name(key)]; return true; },
    });
  }
  matches(selector) {
    const m = /^([a-z]+)?((?:\.[\w-]+)*)((?:\[[\w-]+(?:="[^"]*")?\])*)$/.exec(selector.trim());
    if (!m) throw new Error('the stand-in page cannot answer the selector ' + JSON.stringify(selector));
    if (m[1] && this.tagName !== m[1].toUpperCase()) return false;
    for (const cls of (m[2].match(/[\w-]+/g) ?? [])) if (!this.classes.has(cls)) return false;
    for (const [, attr, value] of m[3].matchAll(/\[([\w-]+)(?:="([^"]*)")?\]/g)) {
      if (attr === 'open') { if (value !== undefined || !this._open) return false; continue; }
      if (value === undefined ? !(attr in this.attributes) : this.attributes[attr] !== value) return false;
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
// A form sent by POST, with the button pressed and one beside it.
const form = new FakeElement('form', [], main);
form.setAttribute('method', 'post');
const pressed = new FakeElement('button', [], form);
const beside = new FakeElement('button', [], form);
for (const button of [pressed, beside]) { button.setAttribute('type', 'submit'); button.disabled = false; }
// A form that only looks something up: sent by GET, so app.js leaves it alone
// until it is sent.
const lookup = new FakeElement('form', [], main);
const lookupButton = new FakeElement('button', ['button'], lookup);
lookupButton.setAttribute('type', 'submit');
// A link as the page draws it, with what a browser works out from its address.
const pageAddress = 'https://portal.test/index.php?page=dashboard';
const link = (href, classes = [], attributes = {}) => {
  const a = new FakeElement('a', classes, main);
  a.setAttribute('href', href);
  for (const [name, value] of Object.entries(attributes)) a.setAttribute(name, value);
  const address = new URL(href, pageAddress);
  return Object.assign(a, { href: address.href, protocol: address.protocol, hash: address.hash, search: address.search, target: attributes.target ?? '' });
};
// The template of the waiting shuttle, as views/layout.php draws it.
const waitTemplate = {
  content: {
    cloneNode() {
      const parts = new FakeElement('template-content');
      const lane = new FakeElement('div', ['page-wait'], parts);
      new FakeElement('span', ['page-wait-flight'], lane);
      const said = new FakeElement('span', ['visually-hidden'], parts);
      said.setAttribute('role', 'status');
      said.setAttribute('data-text', 'Wird geladen …');
      return parts;
    },
  },
};
const answered = new Set(['.topbar-menu', 'form[method="post"]', 'form[data-submitted]', 'dialog.sheet-dialog[open]', '.is-pending']);

const documentListeners = {};
const windowListeners = {};
const timers = new Map();
let timerCount = 0;
const runTimers = () => { for (const [id, timer] of [...timers]) { timers.delete(id); timer.fn(); } };
const waiting = delay => [...timers.values()].some(timer => timer.ms === delay);
const page = {
  activeElement: body,
  body,
  // app.js marks the page as scripted ("js" on <html>), which is all it asks of it.
  documentElement: { classList: { add() {} } },
  getElementById: id => (id === 'page-wait' ? waitTemplate : null),
  querySelector: selector => (selector === '.topbar' ? topbar : null),
  // Only the menus and the form are on this page: every other feature of app.js
  // finds nothing to attach to and stays out of the way.
  querySelectorAll: selector => (answered.has(selector) ? body.querySelectorAll(selector) : []),
  addEventListener: (type, fn) => { (documentListeners[type] ??= []).push(fn); },
  createElement: tag => new FakeElement(tag),
};

const context = vm.createContext({
  document: page,
  Element: FakeElement,
  location: { hash: '', pathname: '/index.php', search: '?page=dashboard', href: pageAddress },
  history: { replaceState() {} },
  navigator: {},
  URLSearchParams,
  console,
  addEventListener: (type, fn) => { (windowListeners[type] ??= []).push(fn); },
  setTimeout: (fn, ms = 0) => { timers.set(++timerCount, { fn, ms }); return timerCount; },
  clearTimeout: id => { timers.delete(id); },
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

  // A form is sent once, and a page come back to with Back is a page to use.
  const submit = () => {
    const event = { submitter: pressed, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
    for (const fn of form.listeners.submit ?? []) fn(event);
    return event;
  };
  const formState = () => JSON.stringify({ form: form.attributes, pressed: pressed.attributes, pressedOff: pressed.disabled,
                                           beside: beside.attributes, besideOff: beside.disabled });
  check('the first send goes through', !submit().defaultPrevented, formState());
  check('and the button pressed says it is working', pressed.getAttribute('aria-busy') === 'true', formState());
  check('its buttons stay on while it is being sent, so the pressed one sends its name', !pressed.disabled && !beside.disabled, formState());
  runTimers();
  check('and are off once it is on its way', pressed.disabled && beside.disabled, formState());
  check('a second send is stopped', submit().defaultPrevented, formState());
  for (const fn of windowListeners.pageshow ?? []) fn({ persisted: false });
  check('a page loaded afresh is left alone', form.dataset.submitted === '1' && pressed.disabled, formState());
  for (const fn of windowListeners.pageshow ?? []) fn({ persisted: true });
  check('back to the page as it was left, its buttons are on again', !pressed.disabled && !beside.disabled, formState());
  check('nothing says it is working', pressed.getAttribute('aria-busy') === null, formState());
  check('and the form is no longer marked sent', form.dataset.submitted === undefined
        && pressed.dataset.sendingOff === undefined && beside.dataset.sendingOff === undefined, formState());
  check('so it can be sent again', !submit().defaultPrevented, formState());

  // Waiting for the next page (Part 0.4a): the tapped link stays pressed, and a
  // shuttle flies only if the page has not come after 0.7 s.
  const lane = topbar.children.find(el => el.classes.has('page-wait'));
  const said = body.children.find(el => el.getAttribute('role') === 'status');
  const pending = () => body.querySelectorAll('.is-pending');
  const waitState = () => JSON.stringify({ lane: lane && [...lane.classes], said: said?.textContent ?? null,
    pending: pending().map(el => el.tagName + '.' + [...el.classes].join('.')), timers: [...timers.values()].map(t => t.ms) });
  const back = () => { for (const fn of windowListeners.pageshow ?? []) fn({ persisted: true }); };
  const click = (target, extra = {}) => {
    const event = { target, button: 0, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...extra };
    send('click', event);
    return event;
  };
  // A form is sent the way it bubbles: its own listeners, then the document's.
  const sendForm = (sent, submitter) => {
    const event = { target: sent, submitter, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
    for (const fn of sent.listeners.submit ?? []) fn(event);
    send('submit', event);
    return event;
  };
  check('the shuttle\'s lane goes into the top bar, and the line a screen reader hears into the page',
        Boolean(lane) && Boolean(said) && said.parent === body, waitState());
  back(); timers.clear();
  check('and nothing shows before anything is tapped', lane && !lane.classes.has('is-on') && !said?.textContent && pending().length === 0, waitState());

  const row = link('index.php?page=student&id=7', ['student-card']);
  const rowText = new FakeElement('span', [], row);
  click(rowText);
  check('a tap on a row keeps it pressed at once', row.classes.has('is-pending'), waitState());
  check('and the shuttle waits 0.7 s', !lane.classes.has('is-on') && waiting(700), waitState());
  runTimers();
  check('then flies, and the page says it is loading', lane.classes.has('is-on') && said.textContent === 'Wird geladen …', waitState());
  const tab = link('index.php?page=student&id=7&tab=payments', ['chip']);
  click(tab);
  check('a second tap moves the pressed look to what was tapped last', tab.classes.has('is-pending') && pending().length === 1, waitState());
  back();
  check('back to the page as it was left, nothing is pressed and nothing flies',
        pending().length === 0 && !lane.classes.has('is-on') && said.textContent === '' && !waiting(700), waitState());

  // Taps that leave the page standing start nothing.
  const standing = [
    // Each with an address only its own rule leaves alone.
    ['a link that opens another tab', link('index.php?page=privacy', ['text-link'], { target: '_blank', rel: 'noopener' }), {}],
    ['a link that downloads', link('beleg.pdf', [], { download: '' }), {}],
    // In this tab, as the portal draws it: the file comes, and no page.
    ['a link to a file', link('index.php?page=download&what=invoice&id=1', ['button']), {}],
    ['a mail address', link('mailto:trainerin@example.test'), {}],
    ['a telephone number', link('tel:+43123456'), {}],
    ['a place further down this page', link('index.php?page=dashboard#feedback', ['text-link']), {}],
    ['a tap with a modifier key', link('index.php?page=classes', ['text-link']), { metaKey: true }],
    ['a middle click', link('index.php?page=classes', ['text-link']), { button: 1 }],
  ];
  for (const [what, target, extra] of standing) {
    back(); timers.clear();
    click(target, extra);
    check(what + ' starts nothing', pending().length === 0 && !waiting(700), waitState());
  }
  back(); timers.clear();
  const stopped = link('index.php?page=classes', ['text-link']);
  click(stopped, { defaultPrevented: true });
  check('nor does a tap something else has already handled', pending().length === 0 && !waiting(700), waitState());

  // A form that sends has its button's spinner, never the shuttle as well.
  back(); timers.clear();
  sendForm(form, pressed);
  runTimers();
  check('a form that sends shows its spinner and no shuttle', pressed.getAttribute('aria-busy') === 'true'
        && !lane.classes.has('is-on') && pending().length === 0, waitState());
  check('and its second send starts nothing either', sendForm(form, pressed).defaultPrevented && !waiting(700), waitState());
  // A form that only looks something up is a tap that leaves, like a link.
  back(); timers.clear();
  sendForm(lookup, lookupButton);
  check('a form that looks something up keeps its button pressed', lookupButton.classes.has('is-pending') && waiting(700), waitState());
  runTimers();
  check('and the shuttle flies if its page is slow', lane.classes.has('is-on'), waitState());
  back();
} catch (error) {
  check('the menu code ran against the stand-in page', false, String(error && error.stack || error));
}

process.stdout.write(JSON.stringify(results));
