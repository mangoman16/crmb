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
    container.appendChild(row); row.querySelector('input:not([type="hidden"])').focus();
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
