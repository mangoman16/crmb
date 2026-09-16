'use strict';
const menu = document.getElementById('menu-toggle');
const backdrop = document.getElementById('menu-backdrop');
function closeMenu() {
  document.body.classList.remove('menu-open');
  if (menu) menu.setAttribute('aria-expanded', 'false');
  if (backdrop) backdrop.hidden = true;
}
menu?.addEventListener('click', () => {
  const open = document.body.classList.toggle('menu-open');
  menu.setAttribute('aria-expanded', String(open));
  if (backdrop) backdrop.hidden = !open;
});
backdrop?.addEventListener('click', closeMenu);
document.addEventListener('keydown', event => { if (event.key === 'Escape') closeMenu(); });
document.querySelectorAll('[data-select-all]').forEach(control => {
  control.addEventListener('change', () => {
    document.querySelectorAll('input[name="student_ids[]"]:not(:disabled)').forEach(box => { box.checked = control.checked; });
  });
});
document.querySelectorAll('[data-insert]').forEach(button => {
  button.addEventListener('click', () => {
    const field = document.querySelector('textarea[name="body"]');
    if (!field) return;
    field.setRangeText(button.dataset.insert, field.selectionStart, field.selectionEnd, 'end');
    field.focus();
  });
});
const type = document.querySelector('select[name="field_type"]');
function updateFieldEditor() {
  if (!type) return;
  const options = document.querySelector('[data-field-options]');
  const defaults = document.querySelector('[data-field-default]');
  const checkbox = document.querySelector('[data-field-checkbox]');
  if (options) options.hidden = !['select', 'multiselect'].includes(type.value);
  if (defaults) defaults.hidden = type.value === 'checkbox';
  if (checkbox) checkbox.hidden = type.value !== 'checkbox';
  const field = defaults?.querySelector('[name="default_value"]');
  if (field && type.value === 'multiselect' && field.tagName !== 'TEXTAREA') {
    const area = document.createElement('textarea');
    area.name = field.name; area.id = field.id; area.value = field.value; area.rows = 3;
    field.replaceWith(area);
  }
}
type?.addEventListener('change', updateFieldEditor);
updateFieldEditor();
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
// The database request id also prevents duplicate records after a repeated POST.
document.querySelectorAll('form[method="post"]').forEach(form => {
  form.addEventListener('submit', () => {
    if (form.dataset.submitted) return;
    form.dataset.submitted = '1';
    window.setTimeout(() => { form.querySelectorAll('button[type="submit"]').forEach(button => { button.disabled = true; }); }, 0);
  });
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
      sync();
      field.focus();
    });
  }
  sync();
});

// A voice message, recorded in the browser and handed to the file input the
// paper clip already uses. One way in rather than two: without this the clip
// still works, and a browser that cannot record simply never shows the button.
document.querySelectorAll('[data-record]').forEach(button => {
  const form = button.closest('form');
  const field = form?.querySelector('input[type="file"]');
  const seconds = form?.querySelector('[data-record-seconds]');
  const status = form?.querySelector('[data-record-status]');
  const canRecord = window.MediaRecorder && navigator.mediaDevices?.getUserMedia
    && window.DataTransfer && window.File;
  if (!field || !canRecord) return;
  button.hidden = false;

  let recorder = null, chunks = [], startedAt = 0, ticker = 0;
  const say = text => { if (!status) return; status.hidden = text === ''; status.textContent = text; };

  const stop = () => {
    window.clearInterval(ticker);
    if (recorder && recorder.state !== 'inactive') recorder.stop();
    recorder?.stream?.getTracks().forEach(track => track.stop());
    recorder = null;
    button.classList.remove('is-recording');
  };

  button.addEventListener('click', async () => {
    if (recorder) { stop(); return; }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      recorder = new MediaRecorder(stream);
      chunks = [];
      startedAt = Date.now();
      recorder.addEventListener('dataavailable', event => { if (event.data.size) chunks.push(event.data); });
      recorder.addEventListener('stop', () => {
        const length = Math.round((Date.now() - startedAt) / 1000);
        const blob = new Blob(chunks, { type: recorder?.mimeType || chunks[0]?.type || 'audio/webm' });
        // Named for the type the recorder actually produced: the server reads
        // the bytes rather than the name, but a sensible name is what the
        // person sees again in their downloads.
        const extension = blob.type.includes('mp4') ? 'm4a' : 'webm';
        const transfer = new DataTransfer();
        transfer.items.add(new File([blob], 'sprachnachricht.' + extension, { type: blob.type }));
        field.files = transfer.files;
        if (seconds) seconds.value = String(length);
        say(button.dataset.recorded || ('Aufnahme bereit (' + length + 's). Zum Senden auf den Pfeil tippen.'));
      });
      recorder.start();
      button.classList.add('is-recording');
      ticker = window.setInterval(() => {
        say('Aufnahme läuft … ' + Math.round((Date.now() - startedAt) / 1000) + 's');
      }, 500);
    } catch (error) {
      recorder = null;
      say(button.dataset.denied || 'Kein Zugriff auf das Mikrofon. Du kannst stattdessen eine Datei anhängen.');
    }
  });

  // A recording that is still running when the form is submitted would arrive
  // empty, so stopping first is not optional.
  form?.addEventListener('submit', () => { if (recorder) stop(); });
});

// The composer grows with what is typed, up to a point, the way a messenger does.
document.querySelectorAll('.composer textarea').forEach(box => {
  const grow = () => { box.style.height = 'auto'; box.style.height = Math.min(box.scrollHeight, 180) + 'px'; };
  box.addEventListener('input', grow);
  grow();
});
