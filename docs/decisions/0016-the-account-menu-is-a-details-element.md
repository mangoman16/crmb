---
status: accepted
date: 2026-09-29
---

# 0016. The account menu is a `<details>`, and a status is a POST

## Context

The avatar and name in the top bar are a link to `?page=profile`:
`<a class="account-link compact">` in `views/layout.php`. The owner wants a dropdown in
their place, with three entries:

- Kontoeinstellungen;
- Status ändern, with the choices of ADR 0015;
- Abmelden.

Every page must work without JavaScript (ADR 0002). Every write must be a POST through an
action (ADR 0003). The top bar already has one menu that satisfies both: `.notification-pane`
is a `<details>` holding a POST form.

## Decision

**Markup.** `<details class="account-menu">` replaces the link, in a new partial,
`views/_account_menu.php`, required by the layout. The partial only reads.

- The `<summary>` holds:
  - `avatar($user,'tiny')`;
  - `presence_dot($user,$user)`: the person's own dot, as others would see it;
  - name and role;
  - an `aria-label` that states the current status.
- The panel holds, in order:
  1. a link „Kontoeinstellungen" to `url('profile')`;
  2. „Status", which is one small form per choice in `presence_choices()`:
     `start_form('presence_save',['presence'=>$key],'inline-form')` with one submit button
     each. The current choice is marked (`aria-pressed="true"`) and is not a second button
     doing nothing. Under the choices, one line says what „Als offline anzeigen" means, in
     the words of ADR 0015.
  3. `start_form('logout',[],'inline-form')` „Abmelden".
- While `impersonator()` is set, the status block is left out. The person being viewed
  keeps their own status.

`app.js` may add closing on Escape and on a click outside, and may close the notification
pane when this opens. Neither is needed for the menu to work.

**Action.** A new case, `presence_save`, goes in **`app/actions_settings.php`**, directly
after `preferences_save`. That is where a person's own account settings are saved (ADR
0003).

```php
case 'presence_save':
    $u=require_user();
    if(impersonator()) throw new UserError(t('…','…'));
    $choice=choose(post('presence'),array_keys(presence_choices()));
    run('UPDATE accounts SET presence=? WHERE id=?',[$choice,$u['id']]);
    $_SESSION['seen_written']=0;   // the next page view opens the right kind of period
    flash(…);
    return [post('return_page','dashboard'), /* return_id, return_tab as posted */];
```

- It runs inside the dispatcher's `transactional()`, like every case.
- It is not `tracked()` and not audited. It is one tap to reverse, and it is nobody's record
  but the person's own.
- It returns to the page the form was on, through the `return_page` / `return_id` /
  `return_tab` that `start_form()` already posts, as `notifications_read` does.

`views/profile.php` keeps its own „Abmelden" button and gains nothing else. The profile page
is where the menu's first entry leads.

## Rejected

**A JavaScript dropdown on a `<button>`.** Without JavaScript it is a button that does
nothing, and ADR 0002 rules that out.

**Status as a GET link (`?page=profile&presence=away`).** A GET that writes can be triggered
by a prefetch, an image tag or a link in a message, and it is not covered by the CSRF token
`start_form()` adds.

**One form with three radio buttons and a „Speichern" button.** That is three taps where
one does, inside a small panel on a phone.

**Putting the case in `actions.php` or `actions_config.php`.** `actions.php` is sign-in and
students. `actions_config.php` is the admin's configuration. `preferences_save`,
`email_change` and `password_change` sit in `actions_settings.php`.

**A second status control on the profile page.** Two places to change one thing, and the
menu is one tap from anywhere.

## Consequences

- **The action count goes from 68 to 70**: this case and `portal_logo_save` (ADR 0014).
- **`structure` suite:**
  - `views/_account_menu.php` contains no write;
  - `presence_save` refuses a value outside `presence_choices()`.
- **`mobile-tester`**, at 320 and 390 px, both roles, light and dark:
  - the open menu fits the screen;
  - every entry is at least 44 pt;
  - it does not sit under the notification pane;
  - it works with JavaScript off.
- **`TESTING.md`:** change status from the menu on an iPhone, check the dot, sign out from
  the menu, and check that the status block is missing while viewing as a family.
- `ui-ux-designer` specifies the wording and the layout of the panel before `frontend-dev`
  builds it.
