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
| [0004](0004-migrations-are-append-only.md) Migrations are append-only | accepted, amended by 0027 | A schema change is a new numbered migration, and a shipped one is never edited. An update refuses rather than guesses: older files, an incomplete upload, no backup, fewer rows in a guarded table. Since 0027 a loss keeps the portal closed until the rows are back. |
| [0005](0005-continuous-integration.md) Continuous integration | proposed, amended by 0021 | Not adopted. Proposed: on every push, the MariaDB suite, `php -l` and `composer audit`. Waits for the owner. |
| [0006](0006-what-frontend-means-here.md) What "front end" means here | accepted | `frontend-dev` owns the views, `app/ui.php`, `app.css` and `app.js`, as `CLAUDE.md`'s table of agents has it; validation is server-side, and escaping comes first. Accepted by the project manager on 2026-10-07. |
| [0007](0007-the-throttle-tells-you-an-address-is-registered.md) The sign-in throttle and registered addresses | superseded by 0019 | History. A throttle counts the typed value, never the row it names; today under 0021 §1, restored by 0030. |
| [0008](0008-the-portal-icon-is-an-upload-served-by-the-router.md) The portal icon is an upload | accepted | The icon is a square PNG uploaded under Einstellungen, kept in `storage/`, and served by the router at a versioned address. |
| [0009](0009-problem-reports-carry-the-last-steps.md) Problem reports carry the last steps | accepted | „Etwas funktioniert nicht" sends the person's last eight steps, with what they typed and no secrets, to the administrators. Kept for the beta, and decided again when it ends (0026 §2). |
| [0010](0010-one-account-is-one-student.md) One account is one student | accepted, amended by 0019, 0020, 0021, 0023, 0030 | One login is one student, enforced by a unique index. Once a login has an address, it is the student's address too. Read the notes first. |
| [0011](0011-the-start-checklist-and-a-seven-entry-menu.md) The start checklist, and a menu of seven | accepted, amended by 0022, 0026, 0028, 0031 | The nine-step start checklist, worked out from the data on every visit; one flat menu without sections, the desktop sidebar, its length measured against the desktop fold rather than capped at seven (0028); `nav_owner()` as the one table of which page belongs where; rarely used settings folded under „Erweitert". An administrator lands on the checklist at sign-in while it is unfinished; an invitation's activation passes „Dein Foto" first (0031). Read the notes first: a family's menu changed too. |
| [0012](0012-unexpected-errors-report-themselves.md) Unexpected errors report themselves | accepted | An unexpected error is stored as an automatic report, with its steps, under Einstellungen → Rückmeldungen. Families see a friendly page. |
| [0013](0013-brand-colours-are-a-stylesheet-served-by-the-router.md) Brand colours are a generated stylesheet | accepted, amended by f5d3c28 | Eight colour settings become a stylesheet served by the router, with readable contrast enforced. Since the design language (`f5d3c28`, Part 0), a club colour must read on the white group and the grey ground in light, and on `#1c1c1e` and `#2c2c2e` in dark; the browser's bar takes the ground, not the menu colour. Everybody may still pick a personal colour. Read the note first. |
| [0014](0014-the-portal-logo-is-a-second-upload-beside-the-icon.md) The portal logo | accepted | The club's logo is a second upload beside the icon, PNG, JPEG or WebP, shown top left. Two switches hide the name and the role line. |
| [0015](0015-presence-a-chosen-status-and-thirty-days-of-online-history.md) Presence | superseded by 0026 | History. The online dots, the chosen status and the 30 days of online history go (the owner, 2026-10-07). `form_return()`, which this record added to `app/core.php`, stays. |
| [0016](0016-the-account-menu-is-a-details-element.md) The account menu is a `<details>` | accepted, amended by 0022, 0026, 0031 | The top-bar account menu is a `<details>` with „Mein Konto" and „Abmelden", the same for everybody, under the person's picture where there is one, a team member's own and a family's its child's, and their initials otherwise (0031). Its status, dot and emoji go (0026). |
| [0017](0017-profile-pictures-are-cached-privately-at-a-versioned-address.md) Profile pictures | superseded by 0026 | History. Pictures came back under 0031, the children's and the team's, with a rule of their own; 0031 restates the caching it takes from here. |
| [0018](0018-club-news-by-email-is-on-by-default.md) Club news by email is on by default | accepted, amended by 0026 | News mail is on unless the person switches it off, with an unsubscribe link and the opt-outs recorded. |
| [0019](0019-sign-in-with-a-username-and-an-address-may-be-shared.md) Usernames, and shared addresses | superseded by 0021 | History. Shared addresses never shipped (0020). Usernames went (0021), came back for students only under 0023 §1, and went for good under 0030; nothing of this record is code. |
| [0020](0020-every-login-has-its-own-address-and-people-set-themselves-up.md) Every login has its own address, and people set themselves up | accepted, amended by 0021, 0023, 0026, 0030, 0031 | One person, one login, and an address belongs to one login only: since 0030 the whole rule, for every role. Nobody sets another person's password: the holder sets it from an invitation, and staff can have a reset link mailed. Families complete their own details, and every change is tracked. A family also adds its child's picture and says whether the course sees it, which staff can switch off and never on (0031). Read the notes first. |
| [0021](0021-sign-in-by-address-and-two-ways-to-add-a-person.md) Sign in by address, and two ways to add a person | accepted, amended by 0023, 0026, 0030, 0031 | An address signs in, and nothing else: 0023's usernames went under 0030. Staff can invite by address alone: the person makes their own record and asks for a course, after „Dein Foto" (0031). The other way to add a person became 0023's wizard. |
| [0022](0022-course-groups-direct-messages-and-a-status-emoji.md) Course groups and direct messages | accepted, amended by 0026, 0031, 0032 | Every course has a group chat its children are in. A student writes to staff, never to another student. Administrators read every chat, and nothing records it. Text and photos only (§11); voice notes and files sent before stay. The dots and the emoji go (0026). The contact requests go with their table (037; the project manager, 2026-10-07). A child's picture shows in the group where the family has said yes, and the team's to everybody (0031). Messages are kept a year with what they carry, and a removed one can be put back for 30 days (0032). A picture the cleaner cannot read through is kept as it came (§11.5); refusing it was proposed and declined by the owner on 2026-10-08. Read the notes first. |
| [0023](0023-every-student-has-a-login-a-wizard-adds-one-and-one-time-sign-in-links.md) Every student has a login, a wizard, sign-in links | accepted, amended by 0026, 0030, 0031 | Every student has a login from the moment they exist, a placeholder until it has an address and an invitation. „Schüler anlegen" is a two-step wizard, and „Zugänge" lists trainers, administrators and students. The first set-up lands on the student page after „Dein Foto" (0031). Its usernames and one-time sign-in links went under 0030. Read the notes first. |
| [0024](0024-a-child-removed-from-a-course-can-be-put-back.md) A child removed from a course can be put back | accepted | Removing a child from a course ends the enrolment and keeps it. Staff restore it as it was, and a family can ask for it back. |
| [0025](0025-trainers-edit-payment-details-and-every-change-is-kept.md) Trainers edit payment details | accepted, amended by 0026 | Trainers may change payment details, and every change to them goes through the change log. Since 2026-10-08 every administrator hears when an IBAN, a recipient or the QR template changes, when a profile is made, when a course moves to another profile, and when the default profile changes; and the QR code is always an EPC bank transfer to the profile's recipient and IBAN (the project manager). Read the notes first. |
| [0026](0026-who-the-portal-is-for-the-beta-and-what-it-no-longer-carries.md) Who the portal is for, the beta, and what goes | accepted, amended by 0031, 0032 | The owner, the trainer and the families; the beta's rules; the goals as the scope test; phones and wizards; any value from anyone, with a suite that proves it; custom fields and other unused features removed, the dots and the status emoji among them (the owner, 2026-10-07). Levels stay, and so do age groups as lists the trainer edits; a child's age group is worked out from the birth date by one rule, and the pin goes (038; the owner's later answer, 2026-10-07). The students list sorts A–Z or „Nach Alter", under a header per age group. The old contact requests are deleted with their table (037; the project manager, 2026-10-07), and the profile pictures' files with the pictures. Pictures came back under 0031, the children's and the team's, in new columns. Voice notes and files sent before go with their message after a year (0032). Privacy measures are proportionate to the risk, not maximal (the owner, 2026-10-08, §2). Read the notes first. |
| [0027](0027-a-refused-update-stays-refused.md) A refused update stays refused | accepted | The counts from before an update stay in `storage/update-unfinished.json` until a run passes. Meanwhile only the update changes the database, and nobody gets in. The portal reopens by itself when every table still guarded has its rows back (the previous files, then the copy from before), or when a release takes the table off the list. One copy per update. |
| [0028](0028-mehr-is-a-page-not-a-side-menu.md) „Mehr" is a page, not a side menu | accepted | On a phone, staff's „Mehr" is `?page=more`, for staff only. It lists the full menu's entries that the bar does not hold (worked out from `nav_entries()`, never written down twice), then Mein Konto, „Datenschutz und Hilfe" and „Abmelden". Every page under it goes back to „‹ Mehr" and lights „Mehr", by one shared check. The side menu is for the desktop only; the drawer and its JavaScript are gone. Neuigkeiten stays under Chats. Families have no „Mehr". |
| [0029](0029-a-restore-keeps-the-portal-closed-until-its-import-is-done.md) A restore keeps the portal closed until its import is done | accepted | Nothing touches a database that is being restored: an empty one over a `storage/` folder on which a run has passed (`storage/schema.stamp` exists), one with data and no ledger, or one holding `import_unfinished`, the table every copy the portal writes makes first and drops last. The runner refuses, the console runs only its allowlist, `prune_uploads()` deletes nothing and no copy is written; the closed page reloads itself every five minutes, so the portal opens once the import is done. A new portal on a used folder starts once `storage/schema.stamp` is deleted. Read the note for the texts as built, and §6 for what it leaves. |
| [0030](0030-one-person-one-address-usernames-and-sign-in-links-go.md) One person, one address: usernames and sign-in links go | accepted | The owner, 2026-10-08: one person, one own address, for every role, and the address is the only sign-in. Usernames go (039 drops the column; a login that signed in by one, a suspended one included, becomes a placeholder and leaves its chats and its bell), and so do one-time sign-in links: the invitation and the reset mail are the ways in. The placeholder stays as a student's login without an address yet, which the owner confirmed the same day, and the wizard keeps two cards. Nobody shares an address: a parent with two children has two logins. §7 says where the word „username" may still appear. Read the note first. |
| [0031](0031-profile-pictures-come-back-staff-see-every-face-a-course-once-the-family-agrees.md) Profile pictures come back | accepted | The owner, 2026-10-08: children's faces for the trainer, at attendance first, and the team's too. A child's picture is the student's (040), a team member's their account's (041), each one square JPEG of 320 pixels made on the server with gd, which the portal now requires, from a JPEG or PNG only. Staff see every child's picture, a family its own child's, and the children of a running course each other's only where the family has said yes: the child's own yes from 14, a parent's under it (§ 4 Abs. 4 DSG, `consent_age`); staff can only take it back. Everybody signed in sees the team's. The first sign-in offers „Dein Foto" once. `may_see_picture()` is the one rule, and a removed or replaced picture is deleted at once. That staff see a child's picture rests on the club's legitimate interest, a reading to be checked before real families. Read the note first. |
| [0032](0032-how-long-the-portal-keeps-what-it-holds.md) How long the portal keeps what it holds | accepted | Accepted by the owner as proposed, 2026-10-08. A period for each kind of data, each a setting whose default is the period chosen, and one line per period in the nightly prune: messages a year, a removed message 30 days, absences three months after they end, attendance two years, the bell's notices 90 days, sent mail a year, payment proofs two years, the audit log and replaced consents three years. Accounting records stay seven years (BAO § 132) and are never pruned. What is deleted stays in the last five backup copies until newer ones replace them. Read the notes first. |

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
