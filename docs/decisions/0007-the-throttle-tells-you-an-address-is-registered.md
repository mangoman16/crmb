---
status: proposed
date: 2026-09-23
---

# 0007. The sign-in throttle tells you whether an address is registered

## Context

`accounts.email` is compared by the database, not by PHP. Every table in
`database/migrations/` is `utf8mb4` / `utf8mb4_unicode_ci`, so `WHERE email=?` folds case,
accents, `ss` against `ß`, ligatures and full-width letters: one account row answers to
many typed spellings. Measured on MariaDB 10.11.14 by the implementer of `bcfa796` and
recorded in `VALIDATION.md`; a first measurement said the opposite and was discarded
because the client character set was wrong.

One near-miss belongs here so it is not undone by accident. A trailing space is *not* a
spelling this portal has to worry about, but the reason is PHP, not the database:
`post()` (`app/core.php:151`) trims, and `attempted_email()` is `mb_strtolower(post('email'))`,
so the space never reaches SQL. `utf8mb4_unicode_ci` is a PAD SPACE collation and would
have folded it too — but the modern `utf8mb4_0900_*` family is NO PAD and would not. The
trim is therefore doing real work that a reader may assume the collation is doing, and
removing it on that assumption would open a variant of exactly the hole `bcfa796` closed.

Before `bcfa796`, `handle_post()` counted a sign-in attempt against the typed PHP string.
Ten guesses per spelling, and nobody is short of spellings — the ten-per-address limit was
worth nothing, and what remained was `throttle('auth-ip',$ip,60)`: sixty unauthenticated
POSTs per IP per fifteen minutes.

`bcfa796` fixed that. In `app/actions.php`:

```php
function attempted_identity(): string {
    $id=scalar('SELECT id FROM accounts WHERE email=?',[attempted_email()]);
    return $id ? account_identity((int)$id) : attempted_email();
}
```

The address is resolved by the engine's own rules and the attempt is counted against
`account_identity($id)` = `'account:'.$id`; `rate_limit_bucket()` and `throttle_clear()`
in `app/auth.php` then agree on one key whichever spelling was typed.

**The last line is the decision this file records.** An address with *no* account is still
counted against the typed string, so the two cases behave differently at the eleventh
request:

- Ten refused attempts at `familie@beispiel.at`, then one at `famílie@beispiel.at`.
- **Immediate „Zu viele Versuche"** — both spellings resolved to the same row. The address
  is registered.
- **A fresh allowance, „Anmeldung nicht möglich"** — they did not. It is not.

Eleven requests, comfortably inside the per-IP cap of sixty, to learn one bit about one
address. `VALIDATION.md` already states this as knowingly left open; this ADR is the case
being put to the owner, not a change being made.

### What is exposed, stated at its real size

One bit: *this address has an account in this portal.* Not a password, not a name, not a
student record, not a birth date. Nothing behind the login opens without a password, and
the login itself leaks nothing else: `dispatch_action()` throws one identical `UserError`
for an unknown address, a wrong password, a suspended account and an unverified one, and
`password_verify()` runs against a fixed dummy hash when no row was found, so the absent
row costs no less work. „Passwort vergessen" answers *„Wenn ein aktives Konto existiert…"*
whether or not one does. The only unauthenticated actions are `login`, `forgot` and
`activate`; there is no self-service registration to probe.

What the bit is worth to an attacker: target selection. A confirmed address is a better
phishing target („Dein Badminton-Portal…") and a better credential-stuffing target than an
unconfirmed one, and the login it selects sits in front of children's birth dates, health
notes and bank details.

What it is worth *here*: less than it would be almost anywhere else. This is one club of a
few dozen families. Membership is not a secret — people stand in the same hall every week
— and an attacker who already has the exact address, which the probe requires, has
generally got it from somewhere that also told him whose it is. A phisher does not need
confirmation before sending mail; confirmation only saves him the wasted sends. The
portal's own worst case is not "he learns she is a member" but "he guesses her password",
and that is bounded by the limits below, not by this bit.

Both halves of that matter. It is not nothing: it is a login oracle in authentication
code, and those age badly. It is also not a breach: it reveals a fact most of these
families would tell you themselves.

### What already bounds it

- `throttle('auth-ip',$ip,60)` — sixty attempts per IP per fifteen minutes, counted for
  `login`, `forgot` and `activate` alike, and **never cleared by a successful sign-in**
  (`forget_attempts_after_success()` clears only the account's own `login` bucket, on
  purpose). Eleven requests per address therefore caps a single IP at about five addresses
  a quarter of an hour.
- Refused attempts are themselves counted — `throttle()` counts before it decides — so
  probing does not become cheaper once the lockout has started.
- The counters live on a second connection (`run_counter()`), so nothing an attacker can
  make fail will roll a count back.
- `forgot` is never cleared by anything, so the oracle cannot be run through the
  reset form more cheaply than through the sign-in form.
- The per-address limit of ten applies to both sides: the probe cannot be extended into
  password guessing, only into a yes/no.

## Decision — proposed, needs the owner

**Recommended: leave the channel open, keep it written down, and revisit it if the portal
ever grows an unauthenticated surface where the fact matters** — self-service signup, a
public parent enquiry form, or a second club on the same install (which `PROJECT.md` rules
out).

The recommendation is not "this is fine"; it is that every way of closing it costs more
than one bit of public-ish information is worth **on the engines this project can actually
verify**. The grounds, in order:

1. The fact revealed is low value in this specific setting, and the attack it feeds —
   phishing a known member — does not require the confirmation.
2. Every close depends on the collation, and the collation is the one thing the default
   test suite cannot see (below). A security rule in authentication code that no automated
   run exercises is a rule that decays silently; `app/actions_config.php` was once
   truncated to 36 bytes with every behavioural test green.
3. The bound that actually protects these families — the password, plus sixty attempts per
   IP per quarter hour — is unaffected either way.

If the owner would rather close it anyway, **the option to take is the collation-backed
identity table, not `WEIGHT_STRING`.** That is a change of ranking from this ADR's first
draft, and the reason is in the next section: MySQL's own manual calls `WEIGHT_STRING()` a
debugging function for internal use whose behaviour can change without notice between
versions, which is disqualifying for a value we hash into a persistent key. Either way it
is not a decision to take halfway: an untested fold is worse than a documented gap.

## Rejected

**The collation's own sort key (`WEIGHT_STRING`) — struck, not merely not-taken.**
Key the unknown-address bucket on what the engine says the string sorts as:
`HEX(WEIGHT_STRING(? COLLATE utf8mb4_unicode_ci))`. Two spellings the collation folds
produce one key, so the unknown case behaves exactly like the known one and the eleventh
request tells the attacker nothing. Mechanically it is neat: the value can ride on the
`SELECT` `attempted_identity()` already sends, so no extra round trip; it binds a value
rather than interpolating a name, so rule 4 is untouched; and `rate_limit_bucket()` hashes
whatever it is given, so key length is irrelevant. What it costs:

- **The function is documented as internal and unstable.** The MySQL 8.4 reference manual,
  string-functions page, says verbatim: *"WEIGHT_STRING() is a debugging function intended
  for internal use. Its behavior can change without notice between MySQL versions. It can
  be used for testing and debugging of collations, especially if you are adding a new
  collation."* It is **not** deprecated — no notice, no version named, no removal
  announced; the only 8.0-era change found in the release notes is a bug fix in 8.0.21 for
  an integer argument. For this use that is a **sharper** objection than deprecation would
  have been. Deprecation is loud: a version, a warning, time to migrate. "Behavior can
  change without notice" is silent, and the value is what `rate_limit_bucket()` hashes into
  a stored key. A point release that alters the weight string orphans every existing bucket
  for an address — counts reset at an upgrade, with nothing to announce it — and, worse,
  two spellings it folded yesterday may stop folding tomorrow, which is this option's
  entire purpose reappearing as a silent regression. It also compounds the testing problem
  below: a value explicitly not stable across versions is one a suite would have to pin,
  and the default suite cannot see it at all. And building an authentication control on a
  function whose own documentation says it is for debugging collations is using it outside
  what it is for. (Checked against the 8.4 manual and the 8.0 release notes, not every
  version — and **MySQL 8.0 remains unverified on this project** in every other respect
  too, so nobody here has watched this function behave at all.)
- **It is engine SQL.** `tests/sqlite-driver.php` strips `COLLATE` from DDL (line 53) and
  compares bytes; there is no `WEIGHT_STRING` and no equivalent. The default suite could
  not exercise the rule at all, exactly as it already cannot exercise the fold it protects
  — `tests/suites/security.php` asks the engine whether two spellings are one row and
  declares *"two spellings of one address sharing a throttle bucket (needs the MySQL
  collation)"* through `test_unsupported()` when they are not. `database-engineer` would
  have to teach the translation a fold, or the rule ships untested.
- It adds a second thing `TESTING.md` 4.6e must be walked for, by hand, on a real engine.

**A collation-backed identity table — rejected as the default, and the one to take if the
owner wants the channel closed.** Give a small `login_identities` table an address column
with `COLLATE utf8mb4_unicode_ci`, resolve an unknown address to a row in it with ordinary
`=`, and count against that row's id. The *engine* folds, with its own rules, and no
engine-specific function is named — it works on MariaDB and MySQL alike, and the SQLite
translation runs the same code path (folding by bytes, which is exactly how it already
treats `accounts.email`, so the suite would be no more dishonest than it is today).

It has the stability `WEIGHT_STRING` lacks, and the evidence is MySQL's catalogue rather
than reasoning about indexes. `utf8mb4_unicode_ci` is built on UCA **4.0.0** weight keys
and still is: when MySQL moved to newer Unicode it *added* names instead of redefining
that one — `utf8mb4_unicode_520_ci` for UCA 5.2.0, then in 8.0 the whole `utf8mb4_0900_*`
family for UCA 9.0.0, which also became the new `utf8mb4` default. The UCA version is in
the name precisely because the existing name keeps its weights. That is MySQL's consistent
documented practice across the 5.x-to-8.0 transition, **not a stability guarantee written
as a contract**, and MariaDB's practice has not been separately checked here. The key is
also a row id rather than a weight, so even an engine that one day folded differently would
start a new row rather than orphan every bucket that already exists.

The same catalogue carries the trap: the 8.0 family is **NO PAD** where
`utf8mb4_unicode_ci` and its generation are **PAD SPACE**, so trailing spaces are
insignificant under the collation this project uses and significant under the modern
default. That is not an exception to the pattern — it is two differently *named*
collations behaving differently, which is the pattern — but it is what makes the column's
collation a decision with weight rather than a formality (see Consequences).

It is not recommended because it is heavier than the problem: a migration and a schema
change, which under `CLAUDE.md` the owner must be asked about anyway; a write on the
counter connection *before* anybody has authenticated, which is a new unauthenticated
INSERT in exactly the place the `structure` rule "a throttle is counted before its action
writes anything" was added to police; and a table an attacker can grow one row per request,
needing pruning that nothing else here needs. Three moving parts to hide one bit — but
three ordinary, inspectable moving parts, rather than one that may change under her.

**Fold the address in PHP.** Normalise unknown addresses (NFKD, case fold, `ß`→`ss`) and
key on that. Portable, no migration, testable on SQLite. Rejected on its merits: the good
version needs `ext-intl`, which is not in `composer.json` and which a shared host may not
have, and without it the fold misses full-width letters and several ligatures — precisely
the spellings `attempted_identity()`'s own comment says no PHP imitation reaches. Over-
folding would be harmless here (an address with no account has no legitimate user to lock
out), but under-folding leaves the channel open while looking closed, which is the worst
of the three outcomes.

**One shared "unknown address" bucket per IP.** Tempting, and wrong — recorded so it is
not proposed again. Count every attempt at an unregistered address into one bucket keyed
on the IP; then ten attempts at one spelling refuse the eleventh whichever case it was,
and the oracle appears to close. It does not: an attacker fills that shared bucket with
ten attempts at nonsense, then tries the target **once**. Refused ⇒ unregistered; „Anmeldung
nicht möglich" ⇒ registered, because a registered address has its own empty bucket. That
is one request against the target instead of ten, strictly worse than today.

**Lower the per-IP cap, or delay every refusal.** Neither closes anything; both raise the
price of a bulk sweep. Sixty is already the only thing standing between a determined
attacker and the whole roster, and lowering it hits the shared-connection case in
`TESTING.md` 4.6d — a club behind one mobile network — before it hits anybody hostile.

**Revert `bcfa796`.** Named for completeness: the pre-fix behaviour had this same oracle
*and* ten password guesses per invented spelling. Strictly worse in both directions.

## Consequences

If the recommendation stands (leave it open):

- `VALIDATION.md` keeps its statement of this weakness, and it stays there through future
  releases rather than being quietly dropped once it is old news.
- `security-reviewer` treats this as a known, accepted finding and does not re-raise it
  per diff; a **new** unauthenticated surface — self-service signup, a public form, an API
  — reopens the question rather than inheriting the answer.
- Nothing in `app/` changes, no migration is written, and `TESTING.md` 4.6e continues to
  cover the half that is fixed.
- The trim in `post()` stays, and stays understood as load-bearing for the trailing-space
  spelling (Context).

If the owner chooses to close it, by the identity table:

- It is a schema change, so it is her decision twice over — once here and once under the
  "stop and ask" rule, and she has no way to undo a migration that has run.
- **The collation on that column is named deliberately, in the migration, with the reason
  written down, and it matches `accounts.email` — PAD behaviour included.** Inheriting the
  server default is the failure mode this option exists to remove, coming back through the
  door marked "default": on a newer MySQL that default is `utf8mb4_0900_ai_ci`, which is
  NO PAD and a different UCA version, so the throttle would fold addresses by one rule
  while the accounts table resolved them by another — two spellings one bucket apart
  again, with a migration's worth of confidence that they could not be.
- `database-engineer` owns the migration, that collation, the pruning, and the question of
  whether the SQLite translation can exercise the fold or must declare it uncovered;
  `backend-dev` owns the change in `attempted_identity()`. The change is not finished until
  either the default suite exercises the rule or the run declares that it cannot, as it
  already does for the fold.
- The new unauthenticated write needs the `structure` rule about counting a throttle before
  its action writes to be re-read against it, and probably extended — that rule and the one
  about a handler refusing before it writes are the precedent for how such a rule is
  written here.
- Whoever writes the rule breaks it on purpose and watches it fail, per `CLAUDE.md`.
- The claim "this works" then names **MariaDB 10.11.14** and nothing else, and MySQL 8.0
  stays unverified — which is an argument for the portable option and against the
  engine-specific one, not a reason to skip saying it.

Either way: the asymmetry itself must stay deliberate and commented. The failure mode
worth preventing is not this bit leaking — it is somebody a year from now reading
`attempted_identity()`, not seeing why the fallback is a raw string, and "simplifying" it
back to counting the typed string in both branches.

## In plain words, for the owner

Someone who already knows a family's email address can, in about eleven tries, find out
whether that address has an account in your portal. That is all they learn — no password,
no name, no child's record, and they still cannot get in. It is the kind of fact most of
your families would tell anyone who asked, since they turn up in the same hall every week;
its only real use to someone dishonest is picking whom to send a fake „Passwort
zurücksetzen" email to. We can close it. The quick way uses a database function whose own
makers describe it as an internal debugging tool that may behave differently from one
update to the next — so we would be putting a lock on the door and accepting that it might
quietly unlock itself at some future update, with nothing to tell us. The sound way needs
a change to the database structure, which is the kind of change you have to approve and
which cannot be undone once it has run. The recommendation is to leave it, write it down,
and close it properly if the portal ever gains a public sign-up page. **You are being asked
whether that trade is the one you want**, or whether it is worth the database change to
close a small, known leak now.

## Open for the owner

1. Leave the channel open and documented, or close it with the identity table?
2. If closed: it is a migration against live family data, and it adds a write that happens
   before anybody has signed in. Both are hers to approve.
3. Does anything planned add an unauthenticated surface where "is this address
   registered?" would matter more than it does today?
