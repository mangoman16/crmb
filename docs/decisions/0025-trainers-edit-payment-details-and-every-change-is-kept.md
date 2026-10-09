---
status: accepted, amended by 0026
date: 2026-10-06
---

# 0025. Trainers edit payment details, and every change to them is kept

> **Amended on 2026-10-08 by the project manager, from the security audit: the QR code is a bank
> transfer, and administrators hear of every change to where the money goes.** Trainers still
> change the IBAN, as the owner decided.
>
> - `qr_template` must give an EPC payload for a SEPA credit transfer (EPC069-12): what
>   `qr_payload()` makes from it has `BCD` as its first line. `profile_save` refuses a template that
>   does not, and `qr_payload()` draws no code from one, so a template saved before is held to it
>   too. One check does both.
> - *The architect's call:* the template's sixth and seventh lines are `{recipient}` and `{iban}`
>   exactly, so the code pays the account the IBAN field shows, the one the change log and the
>   notice name. Without it, a template could carry an IBAN of its own, past `valid_iban()` and
>   past the notice about the IBAN.
> - Every administrator gets a notice in the bell (`notify_admins()`), written in the same
>   transaction, when a profile's IBAN, recipient or template changes: who changed it, and a link
>   to „Änderungen". A new profile counts as a change (*the architect's reading*: it brings an IBAN
>   of its own).
>
> These parts no longer hold: in the Decision, "No other change"; in Rejected, "A notice to
> administrators" (a second person's approval stays rejected). Everything else stands.
>
> *Added 2026-10-08, from today's reviews (the project manager):* every administrator is also told
> when a course is moved to another payment profile (`class_save`), and when the default payment
> profile changes (`default_payment_profile`). Each changes where a family's money goes without
> touching a profile.

> **Superseded in part by ADR 0026 (2026-10-07).** Copying records („Kopieren", `record_duplicate`,
> `duplicate_record()`) is gone. These parts no longer hold: "Copying a profile stays administrators
> only", and in the `structure` item, "with `duplicate_record()` as it is": every write of
> `payment_profiles` is inside `tracked()` or `tracked_insert()`, without exception. Everything else
> stands.

## Context

The owner, on 2026-10-06: "trainer should be able to change iban".

What the code does today:

- **Who may edit.** `profile_save` (`app/actions_config.php`) is `require_staff()`, so trainers can
  already change a payment profile. That covers the recipient, IBAN, BIC, currency, the QR-code
  template, the note and whether it is archived.
- **No history of a change.** `profile_save` writes with a plain `UPDATE` or `INSERT` and
  `audit('profile.saved')`. `payment_profiles` is listed in `tracked_entities()`, but `profile_save`
  never calls `tracked()`. So the change log never saw an IBAN change: who changed it is in the audit
  log, but what it was before is nowhere.
- **Where an IBAN is read.**
  - An issued invoice copies the bank details into its `snapshot_json` (`app/invoices.php`), so it
    keeps the IBAN it was issued with.
  - A payment QR code is built from the profile as it is now (`qr_payload()`).
- **Copying is stricter.** Copying a profile („Kopieren", `record_duplicate`) is administrators only.

The portal has no undo. `tracked()` records history only (`app/history.php`).

## Decision

- **Trainers keep editing payment profiles.** `profile_save` stays `require_staff()`. Nothing about
  who may do it changes.
- **Every change goes through the change log.**
  - `profile_save`'s `UPDATE` runs inside `tracked('payment_profiles', $id, $name, …)`.
  - Its `INSERT` runs inside `tracked_insert('payment_profiles', …)`.

  The change log then keeps each change with the values before and after, and who made it:
  recipient, IBAN, BIC, currency, QR template, note and archived. The audit entry stays.
- **No other change**, as the owner and the project manager asked. Copying a profile stays
  administrators only. Issued invoices keep their frozen IBAN. Open charges' QR codes show the
  current one, which is the point of changing it.

## Rejected

- **Administrators only.** The owner wants her trainer to be able to change it.
- **Leaving it untracked.** An IBAN decides where families' money goes. A change to it that leaves
  no "before" is the change one would most need to read back. Setting a value back by hand from the
  change log is the way back here (0020 §7), and that needs the old value recorded.
- **A notice to administrators, or a second person's approval, when an IBAN changes.** Not asked
  for. The change log, which only administrators read, already shows every change. Either can come
  later if she wants it.
- **An IBAN history table of its own.** The change log is that history, with the same retention as
  everything else („Änderungen aufbewahren").

## Consequences

- **No schema change, no new action, no new file.**
- **`backend-dev`:** the two wrappers in `profile_save`.
- **`qa-tester`:**
  - a trainer's IBAN change leaves one version row, with the old and new IBAN and the trainer as
    actor;
  - a new profile leaves an insert row;
  - an issued invoice still shows the old IBAN;
  - `structure`: every `UPDATE` or `INSERT` of `payment_profiles` in `app/` is inside `tracked()` or
    `tracked_insert()`, with `duplicate_record()` as it is.
- **`docs-writer`:** `CHANGELOG`.
- **Must stay true:** no write to `payment_profiles` outside the change log.

## In plain words, for the owner

- Your trainer can change the bank account, as before.
- Every change is now kept in „Änderungen", with the old and the new IBAN and who changed it.
- Invoices already sent keep the account they were sent with.
