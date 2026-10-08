'use strict';
// iOS shows :active - a row turning grey, a button dimming while it is pressed -
// only while a touch listener exists, and an inline one is refused by the
// portal's own Content-Security-Policy. This one does nothing but exist. The
// class says the script ran: only then does the stylesheet switch off the
// system's grey tap flash, because only then is :active there to replace it.
document.addEventListener('touchstart', () => {}, { passive: true });
document.documentElement.classList.add('js');

// The top bar's menus are <details>: without this each opens and closes by its
// own button and nothing else. Added here is what a menu is expected to do as
// well: Escape closes it (and puts focus back on its button if focus was in it),
// a tap anywhere else closes it, and opening one closes the other.
const topbarMenus = [...document.querySelectorAll('.topbar-menu')];
const closeTopbarMenus = except => topbarMenus.forEach(item => { if (item !== except) item.open = false; });
topbarMenus.forEach(item => item.addEventListener('toggle', () => { if (item.open) closeTopbarMenus(item); }));
document.addEventListener('keydown', event => {
  const openMenu = event.key === 'Escape' && topbarMenus.find(item => item.open);
  if (!openMenu) return;
  // Only when focus was in the menu: pulling it back from wherever she had
  // moved on to would lose her place on the page.
  const hadFocus = openMenu.contains(document.activeElement);
  openMenu.open = false;
  if (hadFocus) openMenu.querySelector('summary')?.focus();
});
// Pointer events rather than click: iOS sends no click for a tap on something
// that is not a link or button, so a tap on the page would not close the menu.
// Down and up both outside, so a swipe to scroll - which ends in pointercancel,
// not pointerup - leaves it open.
let pressedOutside = false;
const outsideMenus = target => !(target instanceof Element && target.closest('.topbar-menu[open]'));
document.addEventListener('pointerdown', event => { pressedOutside = outsideMenus(event.target); });
document.addEventListener('pointerup', event => {
  if (pressedOutside && outsideMenus(event.target)) closeTopbarMenus(null);
  pressedOutside = false;
});
document.querySelectorAll('[data-add-option]').forEach(button => {
  button.addEventListener('click', () => {
    const key = button.dataset.addOption;
    const container = document.querySelector('[data-option-editor="' + key + '"]');
    if (!container) return;
    const row = container.lastElementChild.cloneNode(true);
    row.querySelectorAll('input').forEach(input => { input.value = ''; });
    // A cloned <select> keeps whatever was chosen in the row above it, which on
    // the course form silently added a second Monday every time the button was
    // pressed.
    row.querySelectorAll('select').forEach(select => { select.selectedIndex = 0; });
    container.appendChild(row);
    row.querySelector('select, input:not([type="hidden"])')?.focus();
  });
});
// A form is sent once. The database request id already refuses a second post;
// here a second tap does nothing at all, and the button that was pressed says it
// is working (aria-busy, which the stylesheet draws as a spinner) until the next
// page arrives. The buttons are switched off after the submit, not during it, so
// the one pressed still sends its name and value.
document.querySelectorAll('form[method="post"]').forEach(form => {
  form.addEventListener('submit', event => {
    if (form.dataset.submitted) { event.preventDefault(); return; }
    form.dataset.submitted = '1';
    event.submitter?.setAttribute('aria-busy', 'true');
    window.setTimeout(() => {
      form.querySelectorAll('button[type="submit"]').forEach(button => { button.disabled = true; button.dataset.sendingOff = '1'; });
    }, 0);
  });
});
// A page that is slow to come (Part 0.4a): the link that was tapped stays
// pressed, as an iPhone keeps a tapped row lit until the next screen is in, and
// if the page has not come after 0.7 s a shuttle flies along the bottom of the
// bar - in the home-screen app no browser bar says a page is loading. Nothing
// waits for it, so no page is slower. A form that sends has its button's
// spinner instead (above): the two never show for one tap.
const waitParts = document.getElementById('page-wait')?.content.cloneNode(true);
const wait = waitParts?.querySelector('.page-wait');
const waitSaid = waitParts?.querySelector('[role="status"]');
if (wait) { (document.querySelector('.topbar') || document.body).append(wait); document.body.append(waitSaid); }
let waitTimer = 0;
const leaving = pressed => {
  document.querySelectorAll('.is-pending').forEach(el => el.classList.remove('is-pending'));
  pressed?.classList.add('is-pending');
  window.clearTimeout(waitTimer);
  waitTimer = window.setTimeout(() => {
    if (!wait) return;
    wait.classList.add('is-on');
    waitSaid.textContent = waitSaid.dataset.text;
  }, 700);
};
document.addEventListener('click', event => {
  const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
  if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
  // Somewhere that leaves this page standing: another tab, a file, mail or the
  // phone, or a place further down this page. The portal's own download page is
  // a file as well: it answers with the file and no page. It still opens here,
  // not in a new tab, which in the home-screen app would have Safari's cookies.
  if ((link.target && link.target !== '_self') || link.hasAttribute('download') || !/^https?:$/.test(link.protocol)
      || new URLSearchParams(link.search).get('page') === 'download') return;
  if (link.hash && link.href.split('#')[0] === location.href.split('#')[0]) return;
  leaving(link);
});
document.addEventListener('submit', event => {
  const form = event.target;
  if (event.defaultPrevented || (form.target && form.target !== '_self')) return;
  // Marked by the form's own listener above, which runs first: it is sending.
  if (event.submitter?.getAttribute('aria-busy') === 'true') return;
  leaving(event.submitter);
});
// Back to a page the browser kept as it was left - a form sent, its buttons off,
// a sheet open, a link still pressed: it is a page to use again, not one still
// sending or leaving.
window.addEventListener('pageshow', event => {
  if (!event.persisted) return;
  window.clearTimeout(waitTimer);
  wait?.classList.remove('is-on');
  if (waitSaid) waitSaid.textContent = '';
  document.querySelectorAll('.is-pending').forEach(el => el.classList.remove('is-pending'));
  document.querySelectorAll('form[data-submitted]').forEach(form => {
    delete form.dataset.submitted;
    form.querySelectorAll('[data-sending-off]').forEach(button => { button.disabled = false; delete button.dataset.sendingOff; });
    form.querySelectorAll('[aria-busy]').forEach(button => { button.removeAttribute('aria-busy'); });
  });
  document.querySelectorAll('dialog.sheet-dialog[open]').forEach(dialog => { dialog.close(); });
});

// A fold that removes or makes something (details[data-sheet]) opens as a sheet
// from the bottom of the screen, the way iOS asks before it deletes: the fold's
// summary as the title, what it holds under it, and „Abbrechen", which shuts it
// and changes nothing. What it holds is moved into a <dialog> and back again,
// never copied, so the form in it is the same form with the same fields. Escape
// and a tap on the dimmed page beside it shut it too. Without this the fold
// opens in place, as it always did; a browser without <dialog> keeps that.
let sheets = 0;
document.querySelectorAll('details[data-sheet]').forEach(fold => {
  const summary = fold.querySelector(':scope > summary');
  if (!summary || typeof window.HTMLDialogElement !== 'function') return;
  // Said before the tap: this opens a sheet, not a fold in place.
  summary.setAttribute('aria-haspopup', 'dialog');
  const open = () => {
    const dialog = document.createElement('dialog');
    dialog.className = 'sheet-dialog';
    const title = document.createElement('h2');
    title.className = 'sheet-title';
    title.id = 'sheet-title-' + (++sheets);
    title.tabIndex = -1;
    title.textContent = summary.textContent.trim();
    dialog.setAttribute('aria-labelledby', title.id);
    const grabber = document.createElement('span');
    grabber.className = 'sheet-grabber';
    grabber.setAttribute('aria-hidden', 'true');
    const body = document.createElement('div');
    body.className = 'sheet-body';
    const held = [...fold.childNodes].filter(node => node !== summary);
    body.append(...held);
    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'button subtle sheet-cancel';
    // The word comes from the page, in the page's language, like every other.
    cancel.textContent = document.body.dataset.sheetCancel;
    dialog.append(grabber, title, body, cancel);
    document.body.append(dialog);
    cancel.addEventListener('click', () => { dialog.close(); });
    // A tap on the dimmed page lands on the dialog itself, outside its box; a
    // tap inside it, on its padding, lands there too, so where it landed decides.
    dialog.addEventListener('click', event => {
      if (event.target !== dialog) return;
      const box = dialog.getBoundingClientRect();
      if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) dialog.close();
    });
    dialog.addEventListener('close', () => {
      fold.append(...held);
      fold.open = false;
      dialog.remove();
      summary.focus();
    });
    dialog.showModal();
    // The title first, so what happens is read before anything is typed, and a
    // phone does not put its keyboard over the sentence that explains it.
    title.focus();
  };
  summary.addEventListener('click', event => { event.preventDefault(); open(); });
  // Opened by the page itself: as a sheet as well.
  if (fold.open) { fold.open = false; open(); }
});

// Reveal controls that only make sense with JavaScript available.
document.querySelectorAll('[data-needs-js]').forEach(el => { el.hidden = false; });

// Attendance: set every student's choice at once, then correct the exceptions.
// Without JavaScript the radios still work one by one, so this is additive.
document.querySelectorAll('[data-mark-all]').forEach(button => {
  button.addEventListener('click', () => {
    const value = button.dataset.markAll;
    document.querySelectorAll('.attendance-list .segmented').forEach(group => {
      const radio = group.querySelector('input[value="' + CSS.escape(value) + '"]');
      if (radio) radio.checked = true;
    });
  });
});

// A field that takes a default when it is left empty: the default is printed
// next to it either way, and this adds the button that fills it in. The class on
// the wrapper is what the styling uses to show "this is the default" rather than
// "this is your own value", and it has to follow the box as it is typed in.
document.querySelectorAll('.with-default').forEach(wrapper => {
  const field = wrapper.querySelector('input, textarea');
  const button = wrapper.querySelector('.default-reset');
  if (!field) return;
  const sync = () => { wrapper.classList.toggle('is-default', field.value.trim() === ''); };
  field.addEventListener('input', sync);
  if (button) {
    button.hidden = false;
    button.addEventListener('click', () => {
      // Empty means "follow the default", which is what the field already does;
      // writing the number in would freeze today's value into the record.
      field.value = '';
      // As an input event, so whatever else follows the box - the colour
      // picker beside a colour - hears of it as it hears of typing.
      field.dispatchEvent(new Event('input'));
      field.focus();
    });
  }
  sync();
});

// A colour picker beside each colour box on the „Aussehen" card. The box is
// the setting - it can be empty, meaning the built-in colour, which a native
// picker cannot say (ADR 0013) - so the picker only writes into it, and follows
// it when a whole colour is typed. Without JavaScript she types the colour.
document.querySelectorAll('.colour-field').forEach(wrapper => {
  const field = wrapper.querySelector('input[type="text"]');
  if (!field) return;
  const picker = document.createElement('input');
  picker.type = 'color';
  picker.className = 'colour-picker';
  // The label comes from the page, in the page's language, like every other word.
  picker.setAttribute('aria-label', wrapper.dataset.pickerLabel || '');
  const whole = value => /^#[0-9a-f]{6}$/i.test(value.trim()) ? value.trim().toLowerCase() : '';
  picker.value = whole(field.value) || whole(wrapper.dataset.colour || '') || '#000000';
  const row = document.createElement('span');
  row.className = 'colour-row';
  field.before(row);
  row.append(field, picker);
  picker.addEventListener('input', () => {
    field.value = picker.value;
    field.dispatchEvent(new Event('input'));
  });
  field.addEventListener('input', () => {
    const typed = whole(field.value) || (field.value.trim() === '' ? whole(field.placeholder) : '');
    if (typed && typed !== picker.value) picker.value = typed;
  });
});

// The composer grows with what is typed, up to a point, the way a messenger does.
document.querySelectorAll('.composer textarea').forEach(box => {
  const grow = () => { box.style.height = 'auto'; box.style.height = Math.min(box.scrollHeight, 180) + 'px'; };
  box.addEventListener('input', grow);
  grow();
});

// Betrieb: the tax rate and the UID number only matter with VAT, so they wait
// until „Mit Umsatzsteuer“ is picked. Without JavaScript every field shows.
const taxMode = document.querySelector('select[name="set_org_tax_mode"]');
if (taxMode) {
  const vatFields = ['set_org_vat_rate', 'set_org_vat_id']
    .map(name => document.querySelector('[name="' + name + '"]')?.closest('.field'))
    .filter(Boolean);
  const syncTax = () => { vatFields.forEach(field => { field.hidden = taxMode.value !== 'vat'; }); };
  taxMode.addEventListener('change', syncTax);
  syncTax();
}

// „Für den Support kopieren“: the text is in a read-only box that can be
// selected and copied by hand; this adds one button where the browser offers
// a clipboard, and says „Kopiert“ for a moment afterwards.
if (navigator.clipboard) {
  document.querySelectorAll('[data-copy-from]').forEach(button => {
    const box = document.getElementById(button.dataset.copyFrom);
    const status = button.parentElement?.querySelector('[data-copy-status]');
    if (!box) return;
    button.hidden = false;
    button.addEventListener('click', () => {
      navigator.clipboard.writeText(box.value).then(() => {
        if (!status) return;
        status.hidden = false;
        window.setTimeout(() => { status.hidden = true; }, 2500);
      }, () => { box.focus(); box.select(); });
    });
  });
}
