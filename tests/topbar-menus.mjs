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
 * looks something up (GET), links of every kind a tap can leave by, and the
 * waiting page with its status line (Part 0.4b). A timer the script sets runs
 * when runTimers() says so, and is kept with its delay until then; the window's
 * listeners (pageshow) are kept to be sent like the document's. A click or a
 * submit reaches the element's own listeners first and the document's after, as
 * it bubbles. The clock (Date.now) moves only when a check moves it,
 * sessionStorage is a map, and window.stop() is counted.
 *
 * The page that comes next is a page of its own: app.js is loaded again into one
 * whose <html> wait.js has marked as carrying the waiting page on, and wait.js
 * itself runs in an empty head, with and without a time left for it.
 *
 * What the stand-in page does not do: document.querySelectorAll() answers only
 * the selectors in `answered` (anything else finds nothing, so the rest of
 * app.js stays out of the way - and so would a script that looked for its menus
 * or forms another way); querySelector() finds only the top bar, the waiting
 * page and its status line. On the stand-in elements, closest(), matches() and
 * querySelector() understand a tag, classes and attributes - [open], [name] and
 * [name="value"] - and throw on any other selector rather than guess.
 *
 * And a photo's card (ADR 0031): a form that sends a photo as soon as one is
 * chosen and a switch's form, each with its own button for a page without
 * JavaScript, two folds that open as a sheet, and attendance's bulk choices,
 * one of them in a sheet already open. For the photo, the page offers what the
 * shrinking asks of a browser - a reader that gives the file as a data:
 * address, a picture decoded to a size the check sets, a canvas that remembers
 * how it was painted, and a way to put a file back into the input - and
 * nothing more.
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
    return { add: (...names) => { names.forEach(name => classes.add(name)); }, remove: (...names) => { names.forEach(name => classes.delete(name)); },
             contains: name => classes.has(name) };
  }
  // Custom properties only, which is all the scripts set.
  get style() {
    const properties = (this._style ??= {});
    return { setProperty: (name, value) => { properties[name] = String(value); }, getPropertyValue: name => properties[name] ?? '' };
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
  get childNodes() { return [...this.children]; }
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
// The waiting page and its status line, as views/layout.php draws them.
const waitPageIn = (parent, { withCancel = true } = {}) => {
  const wait = new FakeElement('div', ['wait-page'], parent);
  wait.setAttribute('hidden', '');
  const cancel = withCancel ? new FakeElement('button', ['button', 'secondary', 'wait-cancel'], wait) : null;
  cancel?.setAttribute('type', 'button');
  const said = new FakeElement('span', ['visually-hidden', 'wait-said'], parent);
  said.setAttribute('role', 'status');
  said.setAttribute('data-text', 'Wird geladen …');
  said.setAttribute('data-slow', 'Dauert länger als sonst.');
  return { wait, cancel, said };
};
const waitParts = waitPageIn(body);
// The page's <html>, outside the body as in a browser.
const root = new FakeElement('html');
// The clock, sessionStorage and window.stop(), as the scripts use them.
let clock = 1800000000000;
class FakeDate extends Date { static now() { return clock; } }
const storage = () => {
  const items = new Map();
  return { items, getItem: key => (items.has(key) ? items.get(key) : null), setItem: (key, value) => { items.set(key, String(value)); }, removeItem: key => { items.delete(key); } };
};
const stored = storage();
let stops = 0;
// A child's photo card: the photo's form, its file input in the row's label,
// the switch's form, and the button of each for a page without JavaScript,
// which records being pressed.
const photoForm = new FakeElement('form', ['picture-form', 'auto-submit'], main);
photoForm.setAttribute('method', 'post');
const photoRow = new FakeElement('label', ['member-row'], photoForm);
const photoField = Object.assign(new FakeElement('input', [], photoRow), { type: 'file', files: [] });
const switchForm = new FakeElement('form', ['picture-consent', 'auto-submit'], main);
switchForm.setAttribute('method', 'post');
const switchRow = new FakeElement('label', [], switchForm);
const switchField = Object.assign(new FakeElement('input', [], switchRow), { type: 'checkbox', checked: true });
const presses = new Map();
const saveButton = form => {
  const button = new FakeElement('button', ['picture-save'], form);
  button.setAttribute('type', 'submit');
  button.click = () => presses.set(button, (presses.get(button) ?? 0) + 1);
  return button;
};
const photoSave = saveButton(photoForm);
const switchSave = saveButton(switchForm);
// Two folds that open as a sheet: one names its sheet itself, as the photo
// card's row does; the other is named by its summary, as every other fold is.
const fold = (title, summaryText) => {
  const details = new FakeElement('details', [], main);
  details.setAttribute('data-sheet', '');
  if (title) details.setAttribute('data-sheet-title', title);
  const summary = Object.assign(new FakeElement('summary', [], details), { textContent: summaryText });
  new FakeElement('p', [], details);
  details.querySelector = selector => (selector === ':scope > summary' ? summary : FakeElement.prototype.querySelector.call(details, selector));
  return { details, summary };
};
const namedFold = fold('Foto von Mia', 'MH Foto ändern Dein Trainerteam sieht es.');
const plainFold = fold('', ' Kind löschen ');
// Attendance's bulk choices (the audit, N3): „Alle: Anwesend" on the page, and
// one from the „Mehr" sheet - a <dialog> by then - which shuts it once chosen.
const markAll = (code, parent) => {
  const button = new FakeElement('button', ['button', 'secondary'], parent);
  button.setAttribute('type', 'button');
  button.setAttribute('data-mark-all', code);
  return button;
};
const markOnPage = markAll('present', main);
const moreSheet = Object.assign(new FakeElement('dialog', ['sheet-dialog'], body), { closed: 0, close() { this.closed++; } });
const markInSheet = markAll('absent', new FakeElement('div', ['sheet-body'], moreSheet));
// What the next photo decodes to, how big its smaller copy comes out, and every
// canvas drawn on.
let photo = { width: 3024, height: 4032, readable: true };
let copySize = 240000;
const canvases = [];
let decodedFrom = '';
class FakeImage {
  set src(address) { decodedFrom = address; }
  decode() { return photo.readable ? Promise.resolve() : Promise.reject(new Error('not a picture this browser reads')); }
  get naturalWidth() { return photo.width; }
  get naturalHeight() { return photo.height; }
}
const newCanvas = () => {
  const canvas = { width: 0, height: 0, painted: [] };
  canvas.getContext = () => ({
    set fillStyle(value) { canvas.painted.push(['fillStyle', value]); },
    fillRect: (...args) => canvas.painted.push(['fillRect', ...args]),
    drawImage: (image, ...args) => canvas.painted.push(['drawImage', image instanceof FakeImage, ...args]),
  });
  canvas.toBlob = (done, type) => done({ size: copySize, type });
  canvases.push(canvas);
  return canvas;
};
const answered = new Set(['.topbar-menu', 'form[method="post"]', 'form[data-submitted]', 'dialog.sheet-dialog[open]', '.is-pending',
                          'form.auto-submit', 'details[data-sheet]', '[data-mark-all]']);

const documentListeners = {};
const windowListeners = {};
const timers = new Map();
let timerCount = 0;
const runTimers = () => { for (const [id, timer] of [...timers]) { timers.delete(id); timer.fn(); } };
const waiting = delay => [...timers.values()].some(timer => timer.ms === delay);
const page = {
  activeElement: body,
  body,
  // app.js marks the page as scripted ("js" on <html>), and shows the waiting page by a class on it.
  documentElement: root,
  querySelector: selector => (['.topbar', '.wait-page', '.wait-said'].includes(selector) ? body.querySelector(selector) : null),
  // Only the menus and the form are on this page: every other feature of app.js
  // finds nothing to attach to and stays out of the way.
  querySelectorAll: selector => (answered.has(selector) ? body.querySelectorAll(selector) : []),
  addEventListener: (type, fn) => { (documentListeners[type] ??= []).push(fn); },
  createElement: tag => (tag === 'canvas' ? newCanvas()
    : tag === 'dialog' ? Object.assign(new FakeElement('dialog'), { showModal() { this.shown = true; }, close() { this.shown = false; } })
    : new FakeElement(tag)),
};

const context = vm.createContext({
  document: page,
  Element: FakeElement,
  location: { hash: '', pathname: '/index.php', search: '?page=dashboard', href: pageAddress },
  history: { replaceState() {} },
  navigator: {},
  URLSearchParams,
  // A blob: address, as URL.createObjectURL() makes one, is what the portal's
  // Content-Security-Policy refuses to show; FileReader gives a data: one.
  URL: { createObjectURL: () => 'blob:photo', revokeObjectURL() {} },
  FileReader: class { readAsDataURL(file) { this.result = 'data:' + file.type + ';base64,'; Promise.resolve().then(() => this.onload()); } },
  Image: FakeImage,
  File: class { constructor(parts, name, options) { Object.assign(this, { name, type: options.type, size: parts[0].size }); } },
  DataTransfer: class { constructor() { this.files = []; this.items = { add: file => this.files.push(file) }; } },
  HTMLDialogElement: function HTMLDialogElement() {},
  console,
  addEventListener: (type, fn) => { (windowListeners[type] ??= []).push(fn); },
  setTimeout: (fn, ms = 0) => { timers.set(++timerCount, { fn, ms }); return timerCount; },
  clearTimeout: id => { timers.delete(id); },
  Date: FakeDate,
  sessionStorage: stored,
  stop: () => { stops++; },
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

  // Waiting for the next page (Part 0.4b): the tapped link stays pressed, and the
  // waiting page shows only if the page has not come after 0.5 s.
  const { wait, cancel, said } = waitParts;
  const pending = () => body.querySelectorAll('.is-pending');
  const waitState = () => JSON.stringify({ html: [...root.classes], wait: [...wait.classes], said: said.textContent ?? '', stored: stored.getItem('crmb-wait'),
    elapsed: root.style.getPropertyValue('--wait-elapsed'), stops,
    pending: pending().map(el => el.tagName + '.' + [...el.classes].join('.')), timers: [...timers.values()].map(t => t.ms) });
  const shown = () => root.classes.has('is-waiting');
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
  // Only the timers with this delay, as the browser would run them when their time comes.
  const runTimersOf = delay => { for (const [id, timer] of [...timers]) if (timer.ms === delay) { timers.delete(id); timer.fn(); } };
  back(); timers.clear();
  check('the page is marked as scripted, and nothing waits before anything is tapped',
        root.classes.has('js') && !shown() && !said.textContent && stored.getItem('crmb-wait') === null && pending().length === 0, waitState());

  const row = link('index.php?page=student&id=7', ['student-card']);
  const rowText = new FakeElement('span', [], row);
  // Reached by the keyboard, so it has focus when it is tapped.
  row.focus();
  click(rowText);
  check('a tap on a row keeps it pressed at once', row.classes.has('is-pending'), waitState());
  check('and the waiting page waits 0.5 s', !shown() && waiting(500) && stored.getItem('crmb-wait') === null, waitState());
  clock += 500;
  runTimersOf(500);
  check('then it shows, and the page says it is loading', shown() && said.textContent === 'Wird geladen …', waitState());
  check('without taking focus from what was tapped', page.activeElement === row, 'focus on ' + page.activeElement.tagName + '.' + [...page.activeElement.classes].join('.'));
  check('it keeps the moment it appeared for the next page, which carries it on from there',
        stored.getItem('crmb-wait') === String(clock) && root.style.getPropertyValue('--wait-elapsed') === '0ms', waitState());
  check('nothing more is said before 6 s', !wait.classes.has('is-slow') && waiting(6000), waitState());
  clock += 6000;
  runTimersOf(6000);
  check('after 6 s it says it is taking longer, and offers „Abbrechen"', wait.classes.has('is-slow') && said.textContent === 'Dauert länger als sonst.', waitState());
  check('with focus on „Abbrechen", the one thing left to do', page.activeElement === cancel, 'focus on ' + page.activeElement.tagName + '.' + [...page.activeElement.classes].join('.'));
  for (const fn of cancel.listeners.click ?? []) fn({ target: cancel });
  check('„Abbrechen" stops the page that has not come', stops === 1, waitState());
  check('and gives focus back to what was tapped', page.activeElement === row, 'focus on ' + page.activeElement.tagName + '.' + [...page.activeElement.classes].join('.'));
  check('and puts this one back as it was: nothing shown, said, kept or pressed, nothing left to come',
        !shown() && !wait.classes.has('is-slow') && said.textContent === '' && stored.getItem('crmb-wait') === null
        && pending().length === 0 && !waiting(500) && !waiting(6000), waitState());

  // A second way out while the waiting page is up - a link reached behind it with
  // the keyboard, or a second Enter in a search field - carries it on. Two slow
  // timers lost the first one's handle, and the second took „Abbrechen", focused
  // by the first, for where focus was: it gave focus back to itself (code review).
  click(rowText);
  clock += 500;
  runTimersOf(500);
  const second = link('index.php?page=student&id=8', ['student-card']);
  second.focus();
  click(second);
  clock += 500;
  runTimersOf(500);
  check('a second way out while it is up carries it on, with one slow line still to come',
        shown() && second.classes.has('is-pending') && [...timers.values()].filter(t => t.ms === 6000).length === 1, waitState());
  clock += 5500;
  runTimersOf(6000);
  for (const fn of cancel.listeners.click ?? []) fn({ target: cancel });
  check('and „Abbrechen" gives focus back to what was activated last, not to itself', page.activeElement === second,
        'focus on ' + page.activeElement.tagName + '.' + [...page.activeElement.classes].join('.'));
  check('leaving nothing shown or left to come', !shown() && !waiting(500) && !waiting(6000), waitState());

  click(rowText);
  const tab = link('index.php?page=student&id=7&tab=payments', ['chip']);
  click(tab);
  check('a second tap moves the pressed look to what was tapped last', tab.classes.has('is-pending') && pending().length === 1, waitState());
  check('and waits 0.5 s from that tap, with one timer, not two', [...timers.values()].filter(t => t.ms === 500).length === 1, waitState());
  clock += 500;
  runTimersOf(500);
  clock += 6000;
  runTimersOf(6000);
  back();
  check('back to the page as it was left, nothing is pressed, shown, said or kept',
        pending().length === 0 && !shown() && !wait.classes.has('is-slow') && said.textContent === '' && stored.getItem('crmb-wait') === null, waitState());
  check('and nothing is left to come', !waiting(500) && !waiting(6000), waitState());

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
    check(what + ' starts nothing', pending().length === 0 && !waiting(500), waitState());
  }
  back(); timers.clear();
  const stopped = link('index.php?page=classes', ['text-link']);
  click(stopped, { defaultPrevented: true });
  check('nor does a tap something else has already handled', pending().length === 0 && !waiting(500), waitState());

  // A form that sends has its button's spinner, never the waiting page as well.
  back(); timers.clear();
  sendForm(form, pressed);
  clock += 500;
  runTimers();
  check('a form that sends shows its spinner and no waiting page', pressed.getAttribute('aria-busy') === 'true'
        && !shown() && stored.getItem('crmb-wait') === null && pending().length === 0, waitState());
  check('and its second send starts nothing either', sendForm(form, pressed).defaultPrevented && !waiting(500), waitState());
  // A form that only looks something up is a tap that leaves, like a link.
  back(); timers.clear();
  sendForm(lookup, lookupButton);
  check('a form that looks something up keeps its button pressed', lookupButton.classes.has('is-pending') && waiting(500), waitState());
  clock += 500;
  runTimersOf(500);
  check('and the waiting page shows if its page is slow', shown(), waitState());
  back();
  check('a page that came the usual way is never faded out', !root.classes.has('is-arrived'), waitState());

  // The page that comes next, in a page of its own: wait.js has run in its head.
  const arriving = (sinceAgo, now = clock, { withCancel = true } = {}) => {
    const html = new FakeElement('html', ['is-arriving']);
    html.dataset.waitSince = String(now - sinceAgo);
    const own = new FakeElement('body');
    waitPageIn(own, { withCancel });
    const ownTimers = new Map();
    let count = 0;
    const doc = { activeElement: own, body: own, documentElement: html, addEventListener() {}, createElement: tag => new FakeElement(tag),
                  querySelector: selector => (['.wait-page', '.wait-said'].includes(selector) ? own.querySelector(selector) : null),
                  querySelectorAll: () => [] };
    const ctx = vm.createContext({ document: doc, Element: FakeElement, location: { href: pageAddress }, history: { replaceState() {} }, navigator: {},
                                   URLSearchParams, console, addEventListener() {}, Date: FakeDate, sessionStorage: storage(), stop() {},
                                   setTimeout: (fn, ms = 0) => { ownTimers.set(++count, { fn, ms }); return count; }, clearTimeout: id => { ownTimers.delete(id); } });
    ctx.window = ctx;
    vm.runInContext(readFileSync(new URL('../public/assets/app.js', import.meta.url), 'utf8'), ctx, { filename: 'public/assets/app.js' });
    const next = () => { const [id, timer] = [...ownTimers][0] ?? []; if (id === undefined) return null; ownTimers.delete(id); timer.fn(); return timer.ms; };
    return { html, ownTimers, next, state: () => JSON.stringify({ html: [...html.classes], timers: [...ownTimers.values()].map(t => t.ms) }) };
  };
  const soon = arriving(100);
  check('a page that comes 0.1 s after the waiting page appeared keeps it 0.4 s more, so it is up 0.5 s',
        [...soon.ownTimers.values()].map(t => t.ms).join() === '400' && !soon.html.classes.has('is-arrived'), soon.state());
  soon.next();
  check('then fades it out', soon.html.classes.has('is-arrived') && soon.html.classes.has('is-arriving'), soon.state());
  check('and takes it away once the fade is over, 0.25 s later', soon.next() === 250 && !soon.html.classes.has('is-arrived') && !soon.html.classes.has('is-arriving'), soon.state());
  const late = arriving(1500);
  check('a page that comes after more than 0.5 s fades it out at once', [...late.ownTimers.values()].map(t => t.ms).join() === '0', late.state());
  // A waiting page drawn without its button must not stop the rest of app.js:
  // the fade above is set up after the button is looked for.
  let buttonless;
  try { buttonless = arriving(1500, clock, { withCancel: false }); } catch (error) { buttonless = { error: String(error) }; }
  check('a waiting page without „Abbrechen" stops nothing else the script does', !buttonless.error && buttonless.ownTimers.size === 1,
        buttonless.error ?? buttonless.state());

  // A sheet opened from a whole row is named by the fold, not by everything the row reads.
  const titleOf = ({ summary }) => {
    for (const fn of summary.listeners.click ?? []) fn({ preventDefault() {} });
    const dialog = body.children.filter(el => el.tagName === 'DIALOG').at(-1);
    return dialog?.children.find(el => el.tagName === 'H2')?.textContent;
  };
  check('a fold that names its sheet gives it that title, not the whole row', titleOf(namedFold) === 'Foto von Mia', String(titleOf(namedFold)));
  check('any other sheet is titled by its summary, as before', titleOf(plainFold) === 'Kind löschen', String(titleOf(plainFold)));

  // A bulk choice made in the „Mehr" sheet shuts it; one on the page has no sheet to shut.
  for (const fn of markInSheet.listeners.click ?? []) fn({ target: markInSheet });
  for (const fn of markOnPage.listeners.click ?? []) fn({ target: markOnPage });
  check('„Alle: Fehlt" from the „Mehr" sheet shuts the sheet once chosen, and „Alle: Anwesend" on the page shuts nothing',
        moreSheet.closed === 1, JSON.stringify({ closed: moreSheet.closed }));

  // A photo goes as soon as it is chosen, drawn smaller first (ADR 0031).
  // What a listener throws is kept for the check, as a browser would only log it.
  let thrown = null;
  const choose = async (files, field = photoField, form = photoForm) => {
    presses.clear(); canvases.length = 0; thrown = null;
    field.files = files;
    for (const fn of form.listeners.change ?? []) await Promise.resolve(fn({ target: field })).catch(error => { thrown = String(error); });
  };
  const photoState = () => JSON.stringify({ pressed: presses.get(photoSave) ?? 0, canvases: canvases.map(c => [c.width, c.height]),
    files: photoField.files.map(f => [f.name, f.type, f.size]), thrown });
  const fromPhone = { name: 'IMG_0001.png', type: 'image/png', size: 3500000 };
  photo = { width: 3024, height: 4032, readable: true }; copySize = 240000;
  await choose([fromPhone]);
  check('choosing a photo sends it by its form\'s own button, once', presses.get(photoSave) === 1, photoState());
  check('and the row says it is working', photoRow.getAttribute('aria-busy') === 'true', photoState());
  check('the photo is read as a data: address, which the portal\'s Content-Security-Policy lets a page show, not a blob: one',
        decodedFrom.startsWith('data:'), decodedFrom);
  check('a large photo is drawn at most 1024 long, on a canvas that size, never at its own',
        canvases.length === 1 && canvases[0].width === 768 && canvases[0].height === 1024, photoState());
  check('straight at that size, on white', JSON.stringify(canvases[0]?.painted)
        === JSON.stringify([['fillStyle', '#fff'], ['fillRect', 0, 0, 768, 1024], ['drawImage', true, 0, 0, 768, 1024]]), JSON.stringify(canvases[0]?.painted));
  check('and what goes is that copy, as a JPEG', photoField.files.length === 1 && photoField.files[0].size === 240000
        && photoField.files[0].type === 'image/jpeg' && photoField.files[0].name === 'IMG_0001.jpg', photoState());
  photo = { width: 800, height: 600, readable: true };
  await choose([fromPhone]);
  check('a photo already small enough goes as it was chosen', presses.get(photoSave) === 1 && canvases.length === 0 && photoField.files[0] === fromPhone, photoState());
  photo = { width: 4032, height: 3024, readable: false };
  await choose([fromPhone]);
  check('a photo this browser cannot read goes as it was chosen, for the server to judge', presses.get(photoSave) === 1 && photoField.files[0] === fromPhone, photoState());
  photo = { width: 4032, height: 3024, readable: true }; copySize = fromPhone.size;
  await choose([fromPhone]);
  check('a copy no smaller than the photo does not go in its place', presses.get(photoSave) === 1 && photoField.files[0] === fromPhone, photoState());
  copySize = 240000;
  const withFiles = context.DataTransfer;
  context.DataTransfer = undefined;
  await choose([fromPhone]);
  context.DataTransfer = withFiles;
  check('nor where the browser cannot put a file back into the field', presses.get(photoSave) === 1 && photoField.files[0] === fromPhone, photoState());
  await choose([]);
  check('closing the picker without a photo sends nothing', !presses.get(photoSave), photoState());
  presses.clear(); canvases.length = 0;
  for (const fn of switchForm.listeners.change ?? []) await fn({ target: switchField });
  check('flipping the course switch sends it at once, with nothing drawn', presses.get(switchSave) === 1 && canvases.length === 0,
        JSON.stringify({ pressed: presses.get(switchSave) ?? 0, canvases: canvases.length }));
} catch (error) {
  check('the menu code ran against the stand-in page', false, String(error && error.stack || error));
}

// wait.js, in the head of the next page: whether it carries the waiting page on.
const runWaitJs = (item, { broken = false } = {}) => {
  const html = new FakeElement('html');
  const listeners = {};
  const kept = storage();
  if (item !== undefined) kept.setItem('crmb-wait', item);
  const sessionStorage = broken ? { getItem() { throw new Error('SecurityError'); }, setItem() { throw new Error('SecurityError'); }, removeItem() { throw new Error('SecurityError'); } } : kept;
  const ctx = vm.createContext({ document: { documentElement: html }, Date: FakeDate, sessionStorage,
                                 addEventListener: (type, fn) => { (listeners[type] ??= []).push(fn); } });
  let threw = null;
  try { vm.runInContext(readFileSync(new URL('../public/assets/wait.js', import.meta.url), 'utf8'), ctx, { filename: 'public/assets/wait.js' }); }
  catch (error) { threw = String(error); }
  return { html, listeners, kept, threw,
           state: () => JSON.stringify({ html: [...html.classes], since: html.dataset.waitSince ?? null, elapsed: html.style.getPropertyValue('--wait-elapsed'),
                                         kept: kept.getItem('crmb-wait'), listens: Object.keys(listeners), threw }) };
};
try {
  const carried = runWaitJs(String(clock - 300));
  check('wait.js: a page whose waiting page appeared 0.3 s ago is carried on from that moment',
        carried.html.classes.has('is-arriving') && carried.html.dataset.waitSince === String(clock - 300)
        && carried.html.style.getPropertyValue('--wait-elapsed') === '300ms', carried.state());
  check('wait.js: and the time is used once', carried.kept.getItem('crmb-wait') === null, carried.state());
  let skipped = 0;
  for (const fn of carried.listeners.pagereveal ?? []) { fn({ viewTransition: { skipTransition() { skipped++; } } }); fn({ viewTransition: null }); }
  check('wait.js: the browser\'s own cross-fade is skipped for this change, and its absence is no error', skipped === 1, carried.state());
  const none = runWaitJs(undefined);
  check('wait.js: with nothing kept the page simply appears', none.html.classes.size === 0 && !none.listeners.pagereveal && !none.threw, none.state());
  for (const [what, item] of [['a time older than 15 s', String(clock - 15000)], ['a time in the future', String(clock + 1000)], ['something that is not a time', 'x']]) {
    const odd = runWaitJs(item);
    check(`wait.js: ${what} is not carried on, and is forgotten`, odd.html.classes.size === 0 && odd.kept.getItem('crmb-wait') === null && !odd.threw, odd.state());
  }
  const blocked = runWaitJs(String(clock - 300), { broken: true });
  check('wait.js: without sessionStorage the page simply appears, and nothing is thrown', blocked.html.classes.size === 0 && !blocked.threw, blocked.state());
} catch (error) {
  check('wait.js ran against the stand-in head', false, String(error && error.stack || error));
}

process.stdout.write(JSON.stringify(results));
