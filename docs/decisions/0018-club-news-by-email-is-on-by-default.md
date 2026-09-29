---
status: accepted
date: 2026-09-29
---

# 0018. Club news by email is on by default

## Context

The owner decided that news by email is club information, not advertising. New accounts
receive it unless they switch it off. It is no longer something a person opts into.

What the code does today:

- `accounts.newsletter` is `TINYINT NOT NULL DEFAULT 0` (migration 001).
- No `INSERT INTO accounts` names the column, so the default decides for every new row.
- **Activation overwrites it.** The `activate` case in `app/actions.php` writes
  `newsletter=post('newsletter')?1:0` and calls `record_consent(…,'newsletter',…)`. So the
  checkbox on `views/activate.php` decides for every invited account, whatever the default
  says. A migration alone changes the outcome only for accounts created directly with a
  password.
- `preferences_save` records a consent line whenever the switch changes. Every news mail
  carries the signed unsubscribe link.

## Decision

1. **Migration `021_news_by_email_by_default.sql`** (shipped):

   ```sql
   ALTER TABLE accounts ALTER COLUMN newsletter SET DEFAULT 1;
   ```

   - It uses `ALTER COLUMN … SET DEFAULT` rather than `MODIFY`, so it changes the default
     and nothing else.
     - The type and `NOT NULL` are not restated, where a slip could change them.
     - No row is rewritten.
     - A second run after an interrupted update does nothing twice.
   - **Existing accounts keep their value.**
2. **Activation.** The checkbox on `views/activate.php` is **ticked by default** and can
   still be unticked. Its wording states the fact rather than asking for consent: „Neuigkeiten
   des Vereins per E-Mail erhalten (jederzeit abbestellbar)". The `activate` case keeps
   writing what was posted.
3. **Records.** `record_consent(…,'newsletter',…)` stays on activation and in
   `preferences_save`. It now records the person's choice, and above all their opt-outs,
   rather than a legal basis. The unsubscribe link stays on every news mail.
4. **The privacy notice** changes the basis for news mail from consent to the club's
   legitimate interest in informing members, keeping the right to object at any time.
   `docs-writer` drafts the sentence, and the owner releases it with 021.

## Rejected

**Only changing the column default.** Activation overwrites it for every invited account.

**`MODIFY newsletter TINYINT NOT NULL DEFAULT 1`.** It restates the column, it may rebuild
the table, and it is one typo away from changing the type.

**Setting `newsletter=1` on existing accounts.** It would override choices people made, or
mail people who were never asked.

**Removing the switch or the unsubscribe link.** "On by default" is the owner's decision.
"No way out" is not.

**Recording this in ADR 0015.** It has nothing to do with presence.

## Consequences

- **The owner decided this change in how families' data is used.** Whether members' news
  mail counts as advertising under § 174 TKG 2021, which requires prior consent, is a legal
  judgement she carries. The unsubscribe link and the recorded opt-outs are the mitigation.
- **`qa-tester`:**
  - a directly created account has `newsletter=1`;
  - activating with the box unticked stores 0 and a consent line;
  - 021 leaves existing values unchanged, checked with one account at 0 and one at 1;
  - it applies on SQLite and on MariaDB.
- **`TESTING.md`:** invite a family, confirm the box is ticked, untick it, and confirm no
  news mail arrives.
