---
status: accepted, amended by 0022, 0026
date: 2026-09-29
---

# 0016. The account menu is a `<details>`, and a status is a POST

> **Superseded in part by ADR 0026 (2026-10-07).** Profile pictures are gone, so the `<summary>` shows
> the person's initials, name and role. With the owner's word on 0026's †, the status block,
> `presence_save`, the dot and 0022's emoji picker go too, with the `structure`, `mobile-tester` and
> `TESTING.md` items about the status; the panel is then „Mein Konto" and „Abmelden" for everybody.
> The `<details>` itself, „Mein Konto", „Abmelden" and their rules stand.

> **Amended by ADR 0022 (2026-10-02).** Everybody's menu shows their own dot and an emoji picker:
> one form posting `status_emoji_save`, a button per emoji and „Keins", the current one marked and
> not a button. A family's panel is „Mein Konto", the emoji and „Abmelden". The status block stays
> staff only and loses its line about „Als offline anzeigen". Neither shows while viewing as
> somebody else. Everything else below stands.

> **Updated 2026-09-29.** The owner has answered the question this record left to ADR 0015:
> the status block and the dot are for staff only. A family's menu holds „Mein Konto" and
> „Abmelden". The action below is written as it shipped: `require_staff()` and
> `form_return()` (ADR 0015, "What shipped beyond the first draft").

## Context

The avatar and name in the top bar are a link to `?page=profile`
(`<a class="account-link compact">` in `views/layout.php`). The owner wants a dropdown in
their place with three things: account settings, a way to change status (the choices of ADR
0015), and sign-out.

Every page must work without JavaScript (ADR 0002), and every write is a POST through an
action (ADR 0003). The top bar already has one menu that satisfies both: `.notification-pane`
is a `<details>` holding a POST form.

## Decision

**Markup.** `<details class="account-menu">` replaces the link. It lives in a new partial,
`views/_account_menu.php`, which only reads.

- **`<summary>`:** `avatar($user,'tiny')`, then `presence_dot($user,$user)`, then name and
  role.
  - For staff, an `aria-label` states the current status.
  - For a family, `presence_dot()` returns `''` (ADR 0015), and the label is name and role
    only.
- **The panel, in order:**
  1. **„Mein Konto"**, a link to `url('profile')`. It carries the page's own heading, so the
     entry and the page it opens have the same name.
  2. **„Status"**, **staff only**, as three rows, one per choice in `presence_choices()`:
     - **The current status** is a marked row that is **not a button**: text and a tick,
       nothing to press.
     - **The other two** are each `start_form('presence_save',['presence'=>$key],'inline-form')`
       with one submit button.
     - No `aria-pressed`. A button that changes nothing when pressed is not offered, so
       there is nothing to toggle.
     - One line under the rows says what „Als offline anzeigen" means, in the words of ADR
       0015.
  3. **„Abmelden"**, `start_form('logout',[],'inline-form')`.
- **A family's panel** is therefore „Mein Konto" and „Abmelden" only (the owner,
  2026-09-29).
- **While `impersonator()` is set**, the status block is left out. The session is the
  family's, so the menu is the family's.

`app.js` may add close-on-Escape, close-on-outside-click, and closing the notification pane
when this menu opens. The menu must not need any of them.

**Action.** A new case, `presence_save`, goes in **`app/actions_settings.php`**, directly
after `preferences_save`. A person's own account settings live there. As shipped:

```php
case 'presence_save':
    // Staff only: the owner decided a family has no status to choose (ADR 0015).
    $u=require_staff();
    $choice=choose(post('presence'),array_keys(presence_choices()));
    run('UPDATE accounts SET presence=? WHERE id=?',[$choice,$u['id']]);
    $_SESSION['seen_written']=0;   // the next page view opens the right kind of period
    flash(…);
    return form_return();
```

- **`require_staff()` also covers impersonation.** While staff view the portal as a family,
  `current_user()` is the family, so the same line refuses. The first draft's separate
  `impersonator()` check was one rule written twice.
- It runs inside the dispatcher's `transactional()`.
- It is not `tracked()` and not audited. It is one tap to reverse, and it is the person's
  own business.
- **It returns to the page, record and tab the form was on**, through `form_return()` in
  `app/core.php`. That reads the `return_*` fields `start_form()` already posts, as
  `notifications_read` does.

`views/profile.php` keeps its own „Abmelden" button and gains no status control.

## Rejected

**A JavaScript dropdown on a `<button>`.** Without JavaScript it does nothing (ADR 0002).

**Status as a GET link.** A GET that writes can be triggered by a prefetch or a link, and it
has no CSRF token.

**Three submit buttons with `aria-pressed` on the current one.** It offers a button that does
nothing, and it describes a toggle that is not one. A marked row is plainer.

**One form with radios and „Speichern".** That is three taps where one does, in a small
panel on a phone.

**„Kontoeinstellungen" as the entry label.** The page it opens is headed „Mein Konto". Two
names for one place send her looking for a second page.

**`actions.php` or `actions_config.php`.** They hold sign-in and students, and the admin's
configuration. The person's own settings are in `actions_settings.php`.

**A second status control on the profile page.** Two places to change one thing.

**Keeping the link, not a menu, for families.** A menu with two entries is still one
pattern in the top bar for everybody. The owner asked for „Mein Konto" and „Abmelden" in it.

**Returning to the page name alone.** That is how „Alle gelesen" on a student's page used to
land on „Nicht gefunden": the page came back without its record. `form_return()` carries
page, record and tab in one place (ADR 0015).

## Consequences

- **Action count:** 68 becomes 70, counting `presence_save` and `portal_logo_save` (ADR
  0014).
- **`structure`:**
  - `views/_account_menu.php` contains no write;
  - `presence_save` refuses a value outside `presence_choices()`;
  - `presence_save` calls `require_staff()` before its `UPDATE`;
  - the menu never renders a submit button for the current status.
- **`mobile-tester`:** measure at 320 and 390 px, for both roles, in light and dark:
  - the open menu fits the screen;
  - every entry is at least 44 pt;
  - the menu does not sit under the notification pane;
  - it works with JavaScript off;
  - a family's menu holds exactly „Mein Konto" and „Abmelden", and no dot.
- **`TESTING.md`:**
  - change status from the menu on an iPhone, as a trainer, on a student's page, and land
    back on that student;
  - the current status shows as marked, not as a button;
  - sign out from the menu;
  - there is no status block while viewing as a family;
  - signed in as a family, the menu has no status and the avatar has no dot.
