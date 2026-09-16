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
monthly fees, skill assessment, and messages to parents. German first, English
available. Used mostly on a phone.

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
| A JavaScript framework | Server-rendered HTML plus ~79 lines of JS does the job. A build step is a thing that breaks between her and her data. |
| Multi-tenant / other clubs | This is one coach's tool. Multi-tenancy would change every access check for a user who does not exist. |
| Replacing it with an off-the-shelf CRM | Reasoning in [AUDIT.md](AUDIT.md). The domain here is narrow and the fit of a general CRM is poor. |
| More dependencies | Two today. Each one is something she must keep patched. |

---

## 2. Where the project actually stands

**Version 0.4.0. Never deployed. No real data exists yet.** That is the single
most important fact on this page, and it is good news: everything is still cheap
to change, and there is no migration debt.

| | |
|---|---|
| Application code | ~2,500 lines PHP, 984 lines of views, 180 lines CSS, 79 lines JS |
| Schema | 31 tables, 6 migrations |
| Configuration | 23 settings, each declared once with a type and a default |
| Tests | 10 suites, no database server needed, runs in seconds |
| Dependencies | 2: phpmailer 7.1.1, bacon/bacon-qr-code 3.1.1 (both pinned in `composer.lock`) |
| Undo coverage | 14 tables versioned with one-click revert |

### Honest status by area

| Area | State | Confidence |
|---|---|---|
| Students, contacts, custom fields | Built, tested | High |
| Classes and membership | Built, tested | High |
| Attendance | Built, tested, mobile-measured | High |
| Monthly billing | Built, edges tested, idempotent on MariaDB | High |
| Payment QR (SEPA) | Built, decoded end-to-end from the rendered page | High |
| Skill assessment | Built, tested, invisible to parents | High |
| Messages, news, email queue | Built | Medium — **no live SMTP has ever run** |
| Transactions and undo | Built, tested, undo exercised with real row locks | High |
| Privacy notice | **Placeholder drafts** | Not started in substance |
| Backups | **Explicitly the operator's job** | Out of scope by agreement |

### The risk that dominated everything else — now closed

This document previously led with the fact that no migration had ever run
against a real database engine. **That has now been done, against MariaDB
10.11.14.** What was verified:

- All six migrations apply — 57 statements, including the two
  `ALTER TABLE ... ADD CONSTRAINT` in migration 004 that the SQLite translation
  could not represent at all. Both foreign keys exist in the resulting schema.
- Re-running `migrate` applies nothing; the checksum guard refuses a migration
  edited after it shipped; `update` and `check` both work end to end.
- The whole test suite passes on the real engine, three runs in a row, with
  nothing left uncovered.
- Every page loads with no PHP error, and writes work: creating and editing a
  student, the version history, and **undo using real `SELECT … FOR UPDATE` row
  locks** — which the SQLite driver had been dropping silently, so that path had
  never actually executed as written.
- `billing:run` three times in a row created one charge, then none, then none,
  enforced by a unique index that genuinely exists on the real engine.

`tests/mariadb-local.sh` does all of this from nothing on a machine with no
database server, so it is repeatable rather than a thing that happened once.

**What is still not proven: MySQL 8.0 itself.** INSTALL.md names MySQL 8.0+ *or*
MariaDB 10.11+; only the second has been tried. The dialect risk is now small
rather than open, but it is not zero, and the honest sentence is "verified on
MariaDB" rather than "verified on the real engine".

---

## 3. Phase 1 — Get it real

**Goal: her data is in it, and safe.** Nothing below is optional and nothing in
Phase 2 starts first.

| # | Work | Why it blocks | Done when |
|---|---|---|---|
| 1.1 | ~~Run migrations and the suite against a real engine~~ **Done on MariaDB 10.11.14** | The dialect was unproven | ✅ Six migrations apply, suite passes, nothing left uncovered. Repeat with `tests/mariadb-local.sh`. Remaining: the same on MySQL 8.0, if that is the target |
| 1.2 | Send one real email end to end | The queue has never talked to a real server | An invitation arrives, is accepted, and a password is set |
| 1.3 | Complete both privacy drafts | Invitations stay disabled until they are done, by design | Both languages released in settings |
| 1.4 | Set up and verify the three cron jobs | Without `mail:work`, no email is ever sent | Each has run on schedule and left a log |
| 1.5 | Confirm the backup arrangement, including `app_key` | A dump without that key does not restore the SMTP password or queued mail | The operator has restored a copy somewhere safe |
| 1.6 | One real week of her data, entered by her, watched | Everything above is theory until she touches it | She has added a student, taken a register and sent a message unaided |

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
| 2.1 | **Export to CSV** — students, charges, payments, assessments | Her data must never be hostage to this app. It is also the honest answer to "what if this stops working". Highest value per hour on this page. |
| 2.2 | **A register/term view** — "who is at training on Thursday" | The attendance and class data already answer this; nothing asks the question. Likely her most frequent real-world query. |
| 2.3 | **Payment reminders she controls** | Reminder emails exist; deciding *when* they go is currently a setting, not a decision she makes per family. Money conversations are relationship conversations. |
| 2.4 | **Undo surfaced where mistakes happen** | The machinery is built and the Änderungen page lists changes, but a mis-tap on a student page does not offer "undo that" in the moment. The value is in the offer, not the page. |

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
| First month free from the first of the month after joining | Mid-month joiners get the partial month *and* the next month free | She says that is too generous — it is a one-line change |
| Absence does not affect billing | Fees are for the place, not the session | She starts issuing manual credits for absences |
| Trainer rates students; students see nothing | Assessment is a coaching tool, not a report card | She wants to share progress with parents |
| QR carries the outstanding amount, not the full charge | A partly paid €45 charge produces a €25 code | Never, unless a bank misreads it |
| Undo rather than soft delete | Restores under the original id, so references survive | Never — this is strictly better |
| No scheduled billing without a preview | The cron job is opt-in; the button shows the plan first | She finds the monthly click tedious *and* trusts it |

### Open questions — for her, not for the code

1. **Does she want parents to see anything about progress?** Currently: nothing.
   This decides whether Phase 3 needs a parent-facing assessment view.
2. **How does she want to handle a family with three children?** Decides 3.2.
3. **Does she think in months or terms?** Decides 3.1, and it is cheaper to know
   before a year of data exists in months.

---

## 8. How I would judge this project in six months

Not by features shipped. By these:

- She has not asked the administrator for help in a month.
- No data has been lost, and any mistake was undone by her, not restored by him.
- The billing run on the 1st is something she has stopped thinking about.
- Something in Phase 3 got built because she asked for it, not because it was on
  this list.

If instead she is keeping a parallel notebook "just in case", the project has
failed regardless of what is ticked above, and the right response is to find out
what the notebook does that this does not.
