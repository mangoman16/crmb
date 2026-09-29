---
status: accepted
date: 2026-09-29
---

# 0018. Club news by email is on by default

## Context

The owner decided that news by email is club information, not advertising. New accounts
should receive it unless they switch it off. It is no longer something a person opts into.

What the code does today:

- `accounts.newsletter` is `TINYINT NOT NULL DEFAULT 0` (migration 001).
- No `INSERT INTO accounts` sets it, so the column default decides for every new row. The
  inserts are in `actions.php`, `auth.php` and `demo.php`.
- **But activation overwrites it.** The `activate` case in `app/actions.php` writes
  `newsletter=post('newsletter')?1:0` and calls `record_consent(…,'newsletter',…)`.
  - The checkbox on `views/activate.php` therefore decides for every invited account,
    whatever the default says.
  - A migration alone would change the outcome only for accounts created directly with a
    password (`student_invite` with `$direct`, and the first administrator).
- `preferences_save` records a consent line whenever the switch changes. Every news mail
  carries the signed unsubscribe link (`views/unsubscribe.php`, `unsubscribe_categories()`).

## Decision

1. **Migration `021_club_news_is_on_by_default.sql`:**

   ```sql
   ALTER TABLE accounts MODIFY newsletter TINYINT NOT NULL DEFAULT 1;
   ```

   - It changes the default for new rows only. **Existing accounts keep the value they
     chose.** Turning on a switch a person turned off, or never turned on, would be sending
     them mail they declined.
   - `database-engineer` checks that the SQLite translation handles `MODIFY`, or rebuilds
     the column the way the runner already does.
2. **Activation.** The checkbox on `views/activate.php` is **ticked by default** and can
   still be unticked. Its wording changes from asking for consent to stating the fact:
   „Neuigkeiten des Vereins per E-Mail erhalten (jederzeit abbestellbar)". The `activate`
   case keeps writing what was posted, so the person's choice on that page still decides.
3. **Records.**
   - `record_consent(…,'newsletter',…)` stays on activation and on `preferences_save`. It
     is now a record of the person's choice, not the legal basis for sending, and it is
     what shows that somebody opted out.
   - The unsubscribe link stays on every news mail.
4. **The privacy notice** changes the legal basis for news mail from consent to the club's
   legitimate interest in informing its members. It keeps the right to object at any time.
   `docs-writer` drafts the sentence, and the owner releases it with 021.

## Rejected

**Changing only the column default.** The activation page overwrites it for every invited
account, so nothing a family sees would change.

**Setting `newsletter=1` on existing accounts.** It overrides choices people already made,
or mails people who were never asked. Under a consent basis that was the promise they were
given.

**Removing the switch or the unsubscribe link.** "On by default" is the owner's decision.
"No way out" is not, and the unsubscribe page is how a parent stops mail without signing in.

**Recording this inside ADR 0015.** It has nothing to do with presence, and one decision per
file keeps a later reversal findable.

## Consequences

- **This is a change in how families' data is used, and the owner has decided it.** Whether
  news mail to members counts as advertising under § 174 TKG 2021, which requires prior
  consent, is a legal question that this record does not answer. She carries that judgement.
  The unsubscribe link and the recorded opt-outs are the mitigation.
- **`qa-tester`:**
  - a directly created account has `newsletter=1`;
  - activating with the box unticked stores 0 and a consent line;
  - migration 021 leaves existing accounts' values unchanged, checked with one 0 and one 1
    before the update;
  - run on SQLite and on MariaDB (`tests/mariadb-local.sh`).
- **`TESTING.md`:** invite a family, confirm the box is ticked, untick it, and confirm no news
  mail arrives.
