# Decisions

One file per decision, numbered in sequence, never renumbered. A decision that is
later reversed is not deleted: it gets `status: superseded by NNNN` and the new file
explains what changed.

A decision that is reversed only **in part** keeps `accepted` and adds `amended by NNNN`.
A note under its title names the sections that no longer hold. Everything the note does not
name still stands. The text itself is not rewritten, because it is the record of what was
decided at the time.

## Which records hold

Read this list first, and open only the records it says hold. A superseded record is
history: it explains how things came to be, and nothing in it is a rule any more. An
amended record holds except where its notes say; read the notes before the text.

The list was checked against every record on 2026-10-07 (ADR 0026). A new record updates it
in the same commit, the rows of the records it amends or supersedes included.

| Record | Status | What holds today |
| --- | --- | --- |
| [0001](0001-record-architecture-decisions.md) Record architecture decisions | accepted | Decisions that are expensive to reverse are recorded here, one per file. A change that contradicts an accepted record supersedes it first. |
| [0002](0002-procedural-php-without-a-framework.md) Procedural PHP, server-rendered, without a framework | accepted | PHP 8.2+, procedural, server-rendered through one front controller. Every page works without JavaScript. No framework, build step or client application. |
| [0003](0003-views-read-actions-write.md) Views read, actions write | accepted | A write happens only in a `case` of the four `app/actions*.php` dispatchers, inside `transactional()`. Views and GETs only read; the one named exception is that opening a chat marks it read (0022 §8). |
| [0004](0004-migrations-are-append-only.md) Migrations are append-only | accepted | A schema change is a new numbered migration, and a shipped one is never edited. An update refuses rather than guesses: older files, an incomplete upload, no backup, fewer rows in a guarded table. |
| [0005](0005-continuous-integration.md) Continuous integration | proposed, amended by 0021 | Not adopted. Proposed: on every push, the MariaDB suite, `php -l` and `composer audit`. Waits for the owner. |
| [0006](0006-what-frontend-means-here.md) What "front end" means here | proposed | Not confirmed by the owner. Proposed: `frontend-dev` owns the views, `app.css` and `app.js`; validation is server-side, and escaping comes first. `CLAUDE.md`'s table of agents already works this way. |
| [0007](0007-the-throttle-tells-you-an-address-is-registered.md) The sign-in throttle and registered addresses | superseded by 0019 | History. A throttle counts the typed value, never the row it names; today under 0023 §7. |
| [0008](0008-the-portal-icon-is-an-upload-served-by-the-router.md) The portal icon is an upload | accepted | The icon is a square PNG uploaded under Einstellungen, kept in `storage/`, and served by the router at a versioned address. |
| [0009](0009-problem-reports-carry-the-last-steps.md) Problem reports carry the last steps | accepted | „Etwas funktioniert nicht" sends the person's last eight steps, with what they typed and no secrets, to the administrators. Kept for the beta, and decided again when it ends (0026 §2). |
| [0010](0010-one-account-is-one-student.md) One account is one student | accepted, amended by 0019, 0020, 0021, 0023 | One login is one student, enforced by a unique index. Once a login has an address, it is the student's address too. Read the notes first. |
| [0011](0011-the-start-checklist-and-a-seven-entry-menu.md) The start checklist, and a menu of seven | accepted, amended by 0022, 0026 | The nine-step start checklist, worked out from the data on every visit; a flat menu of seven entries; rarely used settings folded under „Erweitert". |
| [0012](0012-unexpected-errors-report-themselves.md) Unexpected errors report themselves | accepted | An unexpected error is stored as an automatic report, with its steps, under Einstellungen → Rückmeldungen. Families see a friendly page. |
| [0013](0013-brand-colours-are-a-stylesheet-served-by-the-router.md) Brand colours are a generated stylesheet | accepted | Eight colour settings become a stylesheet served by the router, with readable contrast enforced. Everybody may still pick a personal colour. |
| [0014](0014-the-portal-logo-is-a-second-upload-beside-the-icon.md) The portal logo | accepted | The club's logo is a second upload beside the icon, PNG, JPEG or WebP, shown top left. Two switches hide the name and the role line. |
| [0015](0015-presence-a-chosen-status-and-thirty-days-of-online-history.md) Presence | accepted, amended by 0022 | Online dots for everybody, a status staff choose, and 30 days of when each login was online, for staff. 0026 would remove all of it, and waits for the owner's word. `form_return()` stays either way. |
| [0016](0016-the-account-menu-is-a-details-element.md) The account menu is a `<details>` | accepted, amended by 0022, 0026 | The top-bar account menu is a `<details>` with „Mein Konto" and „Abmelden", under the person's initials. Its status and emoji stay until the owner decides 0026's †. |
| [0017](0017-profile-pictures-are-cached-privately-at-a-versioned-address.md) Profile pictures | superseded by 0026 | History. There are no profile pictures; people show as initials. |
| [0018](0018-club-news-by-email-is-on-by-default.md) Club news by email is on by default | accepted, amended by 0026 | News mail is on unless the person switches it off, with an unsubscribe link and the opt-outs recorded. |
| [0019](0019-sign-in-with-a-username-and-an-address-may-be-shared.md) Usernames, and shared addresses | superseded by 0021 | History. Shared addresses never shipped (0020). Usernames went (0021) and came back for students only under 0023 §1, which reuses this record's alphabet and functions. |
| [0020](0020-every-login-has-its-own-address-and-people-set-themselves-up.md) Every login has its own address, and people set themselves up | accepted, amended by 0021, 0023, 0026 | One person, one login, and an address belongs to one login only. Nobody sets another person's password: the holder sets it, from an invitation or a sign-in link (0023), and staff can have a reset link mailed. Families complete their own details, and every change is tracked. Read the notes first. |
| [0021](0021-sign-in-by-address-and-two-ways-to-add-a-person.md) Sign in by address, and two ways to add a person | accepted, amended by 0023 | An address signs in, and since 0023 a student's username too. Staff can invite by address alone: the person makes their own record and asks for a course. The other way to add a person became 0023's wizard. |
| [0022](0022-course-groups-direct-messages-and-a-status-emoji.md) Course groups and direct messages | accepted, amended by 0026 | Every course has a group chat its children are in. A student writes to staff, never to another student. Administrators read every chat, and nothing records it. Text and photos only (§11). The dots and the emoji wait for the owner's word on 0026. |
| [0023](0023-every-student-has-a-login-a-wizard-adds-one-and-one-time-sign-in-links.md) Every student has a login, a wizard, sign-in links | accepted, amended by 0026 | Every student has a login from the moment they exist, a placeholder until it is set up. „Schüler anlegen" is a two-step wizard. A student without an address can have a username. Staff can make a one-time sign-in link that lasts 48 hours. |
| [0024](0024-a-child-removed-from-a-course-can-be-put-back.md) A child removed from a course can be put back | accepted | Removing a child from a course ends the enrolment and keeps it. Staff restore it as it was, and a family can ask for it back. |
| [0025](0025-trainers-edit-payment-details-and-every-change-is-kept.md) Trainers edit payment details | accepted, amended by 0026 | Trainers may change payment details, and every change to them goes through the change log. |
| [0026](0026-who-the-portal-is-for-the-beta-and-what-it-no-longer-carries.md) Who the portal is for, the beta, and what goes | accepted | The owner, the trainer and the families; the beta's rules; the goals as the scope test; phones and wizards; any value from anyone, with a suite that proves it; custom fields and other unused features removed, three of them (†) waiting for the owner. |

## Format

```
NNNN-kebab-case-title.md
```

Front matter, then four sections:

```markdown
---
status: accepted | accepted, amended by NNNN | proposed | superseded by NNNN
date: YYYY-MM-DD
---

# NNNN. Title

## Context
What forced a choice. The constraint, not the preference.

## Decision
What was decided, in the present tense.

## Rejected
What else was on the table and why it lost. An ADR that lists only the
winner is half a record.

## Consequences
What this costs, and what now has to stay true.
```

The numbers in these files are load-bearing: `CLAUDE.md` and the `architect` agent
refer to them, and a review that says "ADR 0003" should land somewhere.
