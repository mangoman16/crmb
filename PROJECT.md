# Project plan

Written from the project manager's chair: what this is, what state it is really
in, what has to be true before it holds real data, and what comes after — in the
order that serves the one person who will use it.

[ROADMAP.md](ROADMAP.md) is the task list. This is the reasoning behind its
order, the decisions already made, and the ones still open. Where the two
disagree, this file is the intent and the roadmap is the detail.

---

## 1. What this project is

A self-hosted CRM for **one badminton coach** — students, classes, attendance,
monthly fees, levels and age groups, and messages to parents. German first,
English available. Used mostly on a phone.

**One user matters.** She is not technical. She will not read a manual, will not
recover from a confusing error, and will not use a feature she has to think
about twice. Every decision below is downstream of that.

**A second user matters less but exists:** the administrator (technical, sets it
up, changes configuration). Parents and students are a third audience who see a
deliberately small part of the app.

### Success, stated plainly

This project succeeds if, a year after go-live, she is still using it instead of
the notebook it replaced, and has not lost anything. Nothing else on this page
matters more than that sentence.

### Non-goals, decided and closed

Reopening these needs a reason, not a preference.

| Not doing | Why |
|---|---|
| Online card payments | A processor brings PCI scope, a contract and a fraud surface. Bank transfer with a QR code is the right answer at this size. |
| A JavaScript framework | Server-rendered HTML plus ~280 lines of JS does the job, and every page works without it. A build step is a thing that breaks between her and her data. |
| Multi-tenant / other clubs | This is one coach's tool. Multi-tenancy would change every access check for a user who does not exist. |
| Replacing it with an off-the-shelf CRM | Reasoning in [AUDIT.md](AUDIT.md). The domain here is narrow and the fit of a general CRM is poor. |
| More dependencies | Two today. Each one is something she must keep patched. |

---

## 2. Where the project actually stands

**Version 0.6.0. Never deployed. No real data exists yet.** That is the single
most important fact on this page, and it is good news: everything is still cheap
to change, and there is no migration debt.

| | |
|---|---|
| Application code | ~12,080 lines PHP in `app/`, 3,090 lines of views, 1,183 lines CSS, 282 lines JS |
| Schema | 44 tables with the migration ledger, 27 migrations |
| Configuration | 67 settings, each declared once with a type and a default |
| Tests | 31 suites on MariaDB (`tests/mariadb-local.sh` starts a throwaway server), about two minutes; plus `tests/e2e.sh`, a browser walk |
| Dependencies | 2: phpmailer 7.1.1, bacon/bacon-qr-code 3.1.1 (both pinned in `composer.lock`) |
| Change log | 13 tables recorded field by field under „Änderungen“; it informs, and puts nothing back |

Those numbers were counted on 2026-09-28, against 0.6.0 at commit `645b059`.
They have gone stale three times; re-measure them when you cut a release rather
than trusting the row.

### Honest status by area

| Area | State | Confidence |
|---|---|---|
| Students, contacts, custom fields | Built, tested | High |
| Classes and membership | Built, tested | High |
| Attendance | Built, tested, mobile-measured | High |
| Monthly billing | Built, edges tested, idempotent on MariaDB | High |
| Payment QR (SEPA) | Built, decoded end-to-end from the rendered page | High |
| Levels and age groups | Built, tested | High |
| Messages, news, email queue | Built; an invitation delivered over STARTTLS to a mail server on the same machine in the browser walk | Medium — **no real mail provider has ever been used** |
| Transactions and change log | Built, tested. The undo was removed at her request on 2026-09-16 | High |
| Accounts: one login per student | Built, tested; migration 019's data move checked on SQLite only | High |
| Start checklist, seven-entry menu | Built, tested, walked end to end in Chromium at 390 and 320px | High for Chromium; **no real iPhone yet** |
| Problem reports and error capture | Built, tested; the 30-day deletions tested by ageing the stored date, not by waiting | High |
| Privacy notice | **Drafts**: what the portal itself stores is written out; the operator, hosting, retention and legal-basis notes are still placeholders. Only the German one must be released | Not finished |
| Backups | **Explicitly the operator's job** | Out of scope by agreement |

### The risk that dominated everything else — now closed

This document previously led with the fact that no migration had ever run
against a real database engine. **That has now been done, against MariaDB
10.11.14.** What was verified:

- All twenty-one migrations apply, including the two
  `ALTER TABLE ... ADD CONSTRAINT` in migration 004 that the SQLite translation
  could not represent at all. Both foreign keys exist in the resulting schema.
- Re-running `migrate` applies nothing; the checksum guard refuses a migration
  edited after it shipped; `update` and `check` both work end to end.
- The whole test suite passes on the real engine. What that run cannot reach it
  names at the end rather than leaving it to be assumed: the data moved by
  migrations 015, 016 and 019 is still checked on SQLite only.
- Every page loads with no PHP error, and writes work: creating and editing a
  student, the version history, and the real `SELECT … FOR UPDATE` row locks —
  which the SQLite driver had been dropping silently, so that path had never
  actually executed as written.
- `tests/e2e.sh` walks the first evening in Chromium against a real MariaDB —
  setup, the nine checklist steps, an invitation through a mail server on the
  same machine, the family signing in and paying, an invoice, a provoked error —
  and passed at `645b059` on PHP 8.4.19 and 8.5.11.
- `billing:run` three times in a row created one charge, then none, then none,
  enforced by a unique index that genuinely exists on the real engine.

`tests/mariadb-local.sh` does all of this from nothing on a machine with no
database server, so it is repeatable rather than a thing that happened once.

**What is still not proven: MySQL 8.0 itself.** INSTALL.md names MySQL from 5.7
*or* MariaDB from 10.4; only MariaDB 10.11.14 has been tried. The dialect risk is now small
rather than open, but it is not zero, and the honest sentence is "verified on
MariaDB" rather than "verified on the real engine".

---

## 3. Phase 1 — Get it real

**Goal: her data is in it, and safe.** Nothing below is optional and nothing in
Phase 2 starts first.

| # | Work | Why it blocks | Done when |
|---|---|---|---|
| 1.1 | ~~Run migrations and the suite against a real engine~~ **Done on MariaDB 10.11.14** | The dialect was unproven | ✅ Twenty-one migrations apply and the suite passes there; the run names what it could not cover. Repeat with `tests/mariadb-local.sh`, or `tests/existing-database.sh` on her hosting. Remaining: the same on MySQL 8.0, if that is the target |
| 1.2 | Send one real email end to end | The queue has only ever talked to a mail server on the test machine, never to a real provider | The SMTP test passes on her host, an invitation arrives, is accepted, and a password is set |
| 1.3 | Complete the German privacy draft | Invitations stay disabled until it is released, by design; English is optional | The German notice released in settings, with the operator, hosting and retention placeholders filled in |
| 1.4 | Watch the background work run on her host | No cron job is needed: queued mail, clean-up and, when switched on, the monthly charges run just after a page is served. That has not yet been watched on a shared host | **Einstellungen → System** shows a recent „Letzter Hintergrundlauf“ and the invitation from 1.2 left the queue without anybody pressing anything |
| 1.5 | Confirm the backup arrangement, including `app_key` | A dump without that key does not restore the SMTP password or queued mail | The operator has restored a copy somewhere safe |
| 1.6 | One real week of her data, entered by her, watched | Everything above is theory until she touches it | She has added a student, taken a register and sent a message unaided |

„Dein Portal einrichten“ walks her through 1.2 and 1.3 among its nine steps, and
ticks them only once the data says they are done.

With 1.1 closed, **1.6 is the real gate.** The rest are checkable; only the
sixth tells us whether this works for the person it was built for. Expect it to generate more
work than the five above combined, and treat whatever it surfaces as Phase 1,
not Phase 2.

### Exit criteria

Phase 1 is complete when she has run a full month — including a billing run on
the 1st — without needing the administrator. Not when the tasks are ticked.

---

## 4. Phase 2 — The first things she will ask for

Ordered by how likely each is to come up in her first month, which is a
different order from how interesting they are to build.

| # | Work | The argument for it |
|---|---|---|
| 2.1 | **Export to CSV** — students, charges, payments | Her data must never be hostage to this app. It is also the honest answer to "what if this stops working". Highest value per hour on this page. |
| 2.2 | **A register/term view** — "who is at training on Thursday" | The attendance and class data already answer this; nothing asks the question. Likely her most frequent real-world query. |
| 2.3 | **Payment reminders she controls** | Reminder emails exist; deciding *when* they go is currently a setting, not a decision she makes per family. Money conversations are relationship conversations. |
| 2.4 | ~~**Undo surfaced where mistakes happen**~~ **Withdrawn** | The undo itself was removed on 2026-09-16 at her request: the change log is to inform, and undoing an old edit also undid a later edit to the same field that nobody was looking at. „Änderungen“ shows what changed and cannot put it back. If she asks for a way back from one particular mistake, build that one. |

**Deliberately not in Phase 2:** push notifications (email may well be enough —
ask before building), search (fine without it at this scale), 2FA (invitation-only
plus a password is a reasonable posture for a family app).

---

## 5. Phase 3 — Only once it has been lived in

Do not start any of these from a guess. Each needs evidence from real use.

- **Season/term structure.** Badminton has terms; the app has months. If she
  starts working around that, model it. If she does not, do not.
- **Sibling/family billing.** One invoice per family rather than per student.
  Real if she has families with several children; invented if she does not.
- **Waiting lists and trials.** `trial` is already a status. Whether it needs a
  workflow depends on how she actually recruits.
- **An audit-log viewer.** Everything is recorded; nothing displays it. Worth
  building when somebody first asks "who changed this".

---

## 6. Standing engineering commitments

These are not phases. They apply to every change, and they are the reason the
codebase is in the state it is.

- **Quality over speed.** The conventions and the standing permission to improve
  what you touch are in [CLAUDE.md](CLAUDE.md).
- **Every new rule gets a test, and the test gets broken on purpose** to prove it
  fails. A test that has never failed has not been tested.
- **No page may grow a query per row.** The `performance` suite enforces this.
  Two pages have already been caught this way.
- **Schema changes are additive first.** Add, migrate, verify, and only remove in
  a later release.
- **Say what has not been verified.** She is making decisions about her family's
  data based on what this project claims.

---

## 7. Decisions on the record

| Decision | Made | Revisit if |
|---|---|---|
| How a child joining mid-period is charged is a rule on each tariff | Pro rata by days (the default), pro rata by whole months, the whole period, or not until the next one. A charge is never due before the first day it covers or before the day it is written | A club's form promises something none of the four covers |
| Absence does not affect billing | Fees are for the place, not the session | She starts issuing manual credits for absences |
| No skill assessment | Built in 0.2.0, removed by migration 008; there are no such screens | She wants to track or share progress |
| QR carries the outstanding amount, not the full charge | A partly paid €45 charge produces a €25 code | Never, unless a bank misreads it |
| A change log, not an undo | She asked for „Änderungen“ to inform only; an undo from a list of every change could quietly undo a later edit to the same field (removed 2026-09-16) | She asks for a way back from a specific mistake |
| No scheduled billing without a preview | Automatic monthly charges are opt-in, on the Beiträge page; the button shows the plan first | She finds the monthly click tedious *and* trusts it |
| One login is one student | Her decision (ADR 0010): each child has their own address and login, and the database refuses two on one. Migration 019 separated the shared ones; nothing was deleted | Families refuse to keep an address per child |
| Setup is a checklist ticked from the data | ADR 0011: nine steps, never ticked by hand, example data never counts; she lands there at sign-in until it is done | She finds it in the way once the portal is running — it can already be hidden |
| Only the German privacy notice is required | ADR 0011: English is optional, and shown in German with a line saying so | An English-speaking family needs a translation |
| Errors report themselves into „Rückmeldungen“ | ADR 0012: she cannot read a server log. No password, typed value, IP address or database message in what is copied for support; deleted 30 days after the error last happened | The entries become noise she ignores |
| A done problem report is deleted 30 days later | ADR 0009, her decision; typed values go the moment it is marked done | She needs a longer record of what went wrong |

### Open questions — for her, not for the code

1. **Does she want parents to see anything about progress?** Currently: nothing,
   and since migration 008 there is no assessment at all. A yes means building
   one, not showing one.
2. **How does she want to bill a family with three children?** Logins are
   decided — one per child — but one invoice for the family is not. Decides 3.2.
3. **Does she think in months or terms?** Decides 3.1, and it is cheaper to know
   before a year of data exists in months.

---

## 8. How I would judge this project in six months

Not by features shipped. By these:

- She has not asked the administrator for help in a month.
- No data has been lost, and any mistake was put right by her, not restored by him.
- The billing run on the 1st is something she has stopped thinking about.
- Something in Phase 3 got built because she asked for it, not because it was on
  this list.

If instead she is keeping a parallel notebook "just in case", the project has
failed regardless of what is ticked above, and the right response is to find out
what the notebook does that this does not.
