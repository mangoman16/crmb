---
status: accepted, amended by 0022, 0026, 0028, 0031
date: 2026-09-27
---

# 0011. The start checklist, and a menu of seven

> **Amended by ADR 0031 (2026-10-08).** An invitation's activation lands on „Dein Foto" first: one
> optional step for a picture (0031's note, 4). Saved or skipped, it goes on to
> `landing_after_sign_in()`, or for a family to its child's page (0023 §5). In "Where the logic
> lives", "The `login` and `activate` cases return it" now holds for a sign-in, a reset and a
> confirmed address, and so does `qa-tester`'s "followed by `login` and by `activate`" in
> Consequences. Everything else stands.

> **Amended by ADR 0028 (2026-10-07).** On a phone, staff's „Mehr" is a page, `?page=more`, not the
> side menu slid in. These parts no longer hold:
>
> - in Menu, seven as the length of the menu. The menu stays flat and without sections, but its
>   length is measured against the desktop fold instead (0028 §7);
> - in Rejected, the reason "a list of seven fits on a phone": on a phone the list is now four places
>   on the bar and the „Mehr" page.
>
> Added: `nav_owner()` gains `more`, and staff's Mein Konto belongs to it.
>
> The family's rows in the Menu table and under "Phone bar" were already replaced by the owner's
> decision of 2026-10-07 on the design language (ROADMAP, "Decided"), which no record had noted:
>
> - the side menu is Übersicht · Beiträge · Nachrichten · Profil;
> - the bar is Übersicht · Beiträge · Chats · Profil;
> - „Neuigkeiten", „Neues" and „Konto" are gone, and Mein Konto is a row on Profil.
>
> Staff's bar reads Übersicht · Schüler · Anwesend · Chats · Mehr (design language, Part 0, C3).
>
> Everything else stands.

> **Superseded in part by ADR 0026 (2026-10-07).** Custom fields and writing to many are gone. These
> parts no longer hold:
>
> - in "The other simplifications", (9)'s last line: „Eigene Felder" moves inside „Erweitert" too;
> - in the Menu's table, `compose` among the pages Nachrichten owns, and its chip. `outbox` and
>   `news` stay there.
>
> Everything else stands.

> **Amended by ADR 0022 (2026-10-02).** The first chip at the top of Nachrichten is now
> „An mehrere schreiben", not „Gruppe anschreiben": „Gruppe" now means a course's group chat.
> Everything else stands.

## Context

The owner asked for two things:

- a start wizard that "guides you to do everything needed to actually start";
- a simpler portal: "if needed simplify unneeded features or merge them".

`ui-ux-designer` specified the wizard as a checklist, not a stepper. It is a new admin page,
`?page=start`, with nine steps. Each step is ticked from the data on every visit and links to
the screen that already does the job. The owner then chose the simplifications listed under
Decision.

She did **not** choose:

- merging Beiträge with Rechnungen;
- merging Verwaltung with Einstellungen;
- renaming things into plain words;
- a single „Gruppen" section;
- a simpler students list.

Several of the rules the wizard needs are already written somewhere:

- `invoice_issuer_problems()` checks the organisation name, the address and the IBAN.
- `class_next_steps()` checks a training day and a tariff.
- `student_next_steps()` and `billing_plan()` decide whether a child has a course with a
  price.
- `account_mail_ready()` checks SMTP and the released privacy notice.

A wizard that re-spelled any of these would start to disagree with the pages it links to.

Example data is already recognisable without a schema change. Migration 007 gave `accounts`,
`students`, `classes`, `tariffs` and `news` an `is_demo` column, and `demo_fill()` sets it on
every row it writes. The rest follows from those rows:

- charges and enrolments hang off a student;
- training days hang off a course.

`demo_fill()` writes no payment profile, no organisation settings, no SMTP settings and no
privacy notice. **No column is needed and no migration is written.**

## Decision

### Where the logic lives

A new file, `app/start.php`, is required in `app/bootstrap.php` after `mail.php`. It reads
course, billing, mail and account state, so it belongs after all of them. Nothing loaded
earlier calls it: only the router, `dispatch_action()`, `app/ui.php` and the views do, all at
request time.

It holds:

- `setup_steps(): array`. It returns the nine steps in order, each with:
  - `key`
  - `what` and `why`, both through `t()`
  - `page` and `params`, which point at the existing screen and carry `from=start`
  - `done` (bool)
  - `blocked_by` (a list of keys)

  Each step decides "done" by **calling an existing predicate**, never a copy of one. Every
  query excludes `is_demo=1` rows, directly or through the student or course they hang off.
  - **Name und Anschrift**: `invoice_issuer_problems()` has none of its name, address or tax
    problems. To make that possible it returns keyed problems (`name`, `address`, `tax`,
    `iban`) instead of a plain list. Its callers only ever printed the values, so they still
    work.
  - **Bankkonto**: no `iban` problem. `invoice_issuer_problems()` also gains the case "no
    default recipient is chosen", which invoices needed anyway.
  - **Erster Kurs** and **Preis für jeden Kurs**: at least one active real course, and
    `class_next_steps()` is empty for each active real course. Courses are few, so one call
    per course is fine.
  - **Kinder eintragen**: done when all three of these hold:
    - there is at least one real child whose membership has not ended;
    - every such child is in at least one current course;
    - every one of those current courses has a price.

    *Changed after the end-to-end walk:* the first wording ("none of them has an enrolment
    that billing would skip") ticked this step for a child in no course at all, because a
    child with no enrolment has no enrolment to skip.
    - That rule is named once, as `enrolment_has_price(array $row): bool` in
      `app/billing.php`. `billing_plan()`, `student_next_steps()` and `setup_steps()` all
      use it. The wizard runs it over `billing_enrolments()`, which is one query and gains
      `s.is_demo`, and does not loop `student_next_steps()` over every child.
  - **Beiträge**: `setting('auto_billing')`, or a charge exists on a real student.
  - **E-Mails verschicken**: `smtp_tested_ok(): bool` in `app/mail.php`. It is the same
    answer the SMTP tab shows as green, and that tab uses it too.
  - **Datenschutzerklärung**: `setting('privacy_ready')`.
  - **Familien einladen**: a real student has an account in state `invited` or `active`.
    `blocked_by` is the SMTP and privacy steps.
- `setup_progress(): array`, which returns `['done'=>n, 'total'=>9, 'next'=>step|null]`. It is
  memoised for the request.
- `setup_unfinished(): bool`, true when `setup_hidden` is off and at least one step is not
  done. It stops before running any query when the checklist is hidden.
- `landing_after_sign_in(array $account): array`. It returns `['start',[]]` for an
  administrator while `setup_unfinished()`, and `['dashboard',[]]` otherwise. The `login`
  and `activate` cases return it instead of their literal `['dashboard',[]]`. The rule
  applies at **every** sign-in while setup is unfinished, not only the first. It is not an
  every-visit redirect of the dashboard, which keeps its card.
- `note_setup_return(string $page): void` and `setup_return_active(): bool`, for the way
  back described next.

### The way back

`note_setup_return($page)` is called once, from `public/index.php`, directly after
`record_step()` (ADR 0009). For an administrator's GET:

- `from=start` sets `$_SESSION['setup_return']`;
- opening `start` clears it.

Logout clears it with the rest of the session. The layout shows the bar on staff pages when
`setup_return_active()` is true and `setup_unfinished()` holds. The flag is in the session,
not in the URL, so the bar survives the post-save redirect without any action having to
pass it on. No JavaScript is involved. Writing to the session on a GET has precedent: the
locale and the trail do it.

### Hiding it

A new setting, `setup_hidden`, is declared in `app/defaults.php` as `kind bool`, `internal`,
group `system`, default `false`. The new action `setup_visibility`, with `hidden` set to 0 or
1, lives in `app/actions_settings.php` and is `require_admin()`.

- „Ausblenden" is offered once everything is done.
- „Einrichtung ansehen" under Einstellungen links to `start`, and offers „Wieder anzeigen"
  when the checklist is hidden.
- Hiding stops the landing, the menu entry, the dashboard card and the bar. If the data
  later falls back, each page's own notices (`class_next_steps()` and the rest) say so.

### Router

`start` joins `$allowed` and the `require_admin()` list. `views/start.php` only reads, and
reuses `next_steps_card()`, which gains an optional per-step `done` flag. Families and
trainers never see the page. No existing page changes its classification.

### Menu

`nav_entries()` returns a flat list, with no sections.

| Who | Entries |
| --- | --- |
| Administrator | (Einrichtung, while unfinished) · Übersicht · Schüler · Kurse · Anwesenheit · Geld · Nachrichten · Einstellungen |
| Trainer | Übersicht · Schüler · Kurse · Anwesenheit · Geld · Nachrichten · **Verwaltung** |
| Family | unchanged: Übersicht · Profil · Nachrichten · Neuigkeiten |

`settings` is admin-only, and that is not changing, so a trainer's seventh entry is
Verwaltung: it is what she can open.

Pages without an entry are reached from their owner. One table, `nav_owner(string $page):
string` in `app/ui.php`, replaces the special cases in `nav_is_current()`, so the owner
entry is highlighted:

| Page | Owner entry | Reached from |
| --- | --- | --- |
| `invoices` | Geld | a „Beiträge · Rechnungen" switch at the top of both pages, and the child's payments tab |
| `compose`, `outbox`, `news` (for staff) | Nachrichten | the chips at the top of Nachrichten: „Gruppe anschreiben", „Neuigkeiten", „Postausgang". `outbox` is also linked under the SMTP test result, which is where a failed send is noticed. |
| `manage`, `accounts`, `history` | Einstellungen (admin) or Verwaltung (trainer) | the Einstellungen page begins with a hub of cards: Verwaltung, Konten, Änderungen, Einrichtung ansehen. Postausgang is **not** on the hub. Verwaltung links to Konten for trainers. |
| `student`, `classes` detail | Schüler, Kurse | as today |

`sidebar_nav()`'s section branch has nothing left to render, so it is deleted.

**Phone bar.** One function, `mobile_nav_entries(array $user)`, replaces the literal list in
`views/layout.php`:

- **staff:** Übersicht, Schüler, Nachrichten, Anwesenheit, Mehr;
- **family:** Übersicht, Profil, Nachrichten, Neues, **Konto**. With no „Mehr", a family's
  way to the privacy notice and the version moves to „Mein Konto".

### The other simplifications

- **(4) The „Tarif und Beitrag" box goes.** `student_save` stops writing `tariff_id`,
  `price_cents` and `price_note`, which keep their stored values. Without this, removing the
  box would blank them on the next save. „Dabei seit" and „Mitgliedschaft bis" are in that
  box too. They are membership dates, not prices, and **move** to „Einteilung" rather than
  disappear. The setting `default_tariff` fed only that box, so it is deleted from
  `defaults.php`. A leftover row in `settings` is harmless.
  - The family's read-only „Mitgliedschaft" card (`views/student.php`) loses its „Tarif" and
    „Vereinbarter Preis" rows for the same reason: they show columns that bill nobody. It
    keeps the two dates.
- **(9) Automatic charges.** `auto_billing` stays declared in `defaults.php` and becomes
  `internal`, so it is not listed twice. A new admin-only action, `auto_billing_save`, sits on
  the Beiträge page.
  - Settings can carry `'advanced' => true`, and `_settings_registry.php` renders those
    inside one „Erweitert" `<details>`. `auto_background` and `history_months` are marked
    advanced.
  - „Eigene Felder" moves inside „Erweitert" too.
- **(10)** The course price form and the course fields on a child's page put everything
  beyond name and price inside a „Mehr Möglichkeiten" `<details>`. A closed `<details>`
  still posts its fields, so nothing changes on the server.
- **(5)** `database/defaults.php` stops seeding „Trainingsgruppe". It is a seed, not a
  migration.
- **(13)** `default_payment_profile` gets the new kind `reference`, with `'table' =>
  'payment_profiles'`.
  - It is shown as a dropdown of profiles that are not archived.
  - `setting_validate()` checks the id exists, and passes the table through `sql_name()`
    after checking an allowlist, as `tracked_entities()` does.
  - The "…damit nie ein undefinierter Wert entsteht" sentence goes.
- **(14)** „Warum?" is a `<details>`. `app.js` hides the VAT rate and UID number unless „Mit
  Umsatzsteuer" is picked, so without JavaScript every field shows.

### An English notice is optional

- `privacy_save` checks only the German text before release. An English text must be
  either empty or pass the same length and placeholder checks: a half-translated notice is
  not released.
- `privacy_text('en')` falls back to the German text when the English one is empty. The
  privacy page and the activation page then add one line: „This notice is available in
  German only."
- `notice_version()` hashes the German text plus the English text as stored, empty when
  there is none, and never the fallback copy. Adding a translation later therefore creates
  a new version, which is true.

## Rejected

**A stepper.** Setup is not linear. She will do the bank details on the evening she finds
the letter.

**Ticking steps by hand.** A tick that the data contradicts is the failure this project
keeps finding in green test runs.

**A `setup_done` flag instead of reading the data.** It goes stale the first time a course
loses its price.

**An `is_demo` column on payment profiles or enrolments.** The example data does not write
the one, and the other is reached through its student.

**Putting the way back in the URL.** Every action's return target would have to carry it
through post/redirect/get. The session already survives the redirect.

**Redirecting every dashboard visit to the checklist.** She would be trapped on the
checklist until everything is done. Sign-in is the moment the owner asked for.

**Keeping the menu sections with fewer entries.** A section still hides what it holds, and a
list of seven fits on a phone.

**Letting trainers reach `settings`.** That would change a classification the `structure`
suite pins, for a page full of administrator decisions.

## Consequences

- **No schema change, and nothing here needs the owner's yes** under the stop-and-ask rule.
  Two small calls are hers to overrule if she disagrees:
  - a trainer's seventh entry is Verwaltung;
  - families lose „Mehr".
- **Order of work:**
  1. `ui-ux-designer` specifies the Einstellungen hub, the links at the top of Nachrichten
     and Geld, the family's way to the privacy notice, and where the membership dates go.
  2. `database-engineer` removes the seed.
  3. `backend-dev`:
     - `app/start.php` and the bootstrap line;
     - the refactors of `invoice_issuer_problems()`, `enrolment_has_price()` and
       `smtp_tested_ok()`;
     - the router, and the `login` and `activate` landing;
     - `setup_visibility` and `auto_billing_save`;
     - `defaults.php` (`setup_hidden`, `advanced`, `reference`, removing `default_tariff`);
     - `student_save`;
     - privacy.
  4. `frontend-dev`: `app/ui.php` (`nav_entries`, `nav_owner`, `mobile_nav_entries`,
     `next_steps_card`), `views/start.php`, `layout.php`, `dashboard.php`, `student.php`,
     the settings views, `app.js`.
  5. `qa-tester` and `mobile-tester`, then the two reviewers, then `docs-writer`.
- **`qa-tester`**, each rule broken once on purpose:
  - `start` is classified admin.
  - Every page in `$allowed` is either a menu entry for some role, or has a `nav_owner()`
    whose page links to it.
  - After `demo_fill()`, every data step is still undone.
  - The invitations step is blocked until SMTP and privacy are done.
  - `landing_after_sign_in()` is followed by `login` and by `activate`.
  - The bar survives a save's redirect and ends at `start`.
  - `setup_hidden` stops all four surfaces.
  - `student_save` with posted `tariff_id` or `price_cents` changes neither.
  - Release works with no English text; an English page falls back to German; the version
    changes when English is added.
  - `auto_billing_save` refuses a trainer.
- **Must not:**
  - re-spell a readiness rule inside `start.php`;
  - add any flag or table to tell example data apart;
  - reach the checklist from a family or trainer session;
  - add JavaScript that a step, the bar or the menu depends on;
  - change any page's classification in the router.
