# Badminton CRM

Version **0.1.0**. A self-hosted PHP/MySQL application for a badminton coach and her students, built for mobile use, with German and English interfaces.

## Included

- Invitation-only email/password accounts; verified invitation links, password reset and verified email changes.
- One account can manage multiple students. Admin, manager and student roles; account suspension and deletion.
- Students, named contact people, membership dates, configurable statuses and dated absences.
- Editable custom fields: types, options, defaults, sections, ordering, student permissions and archiving.
- Named tariffs, default prices, individual prices and manual charges with coverage dates. Partial, confirmed and voided payments.
- In-app conversations with unread markers, recipient filters, saved filters, message templates and a preview before group sending.
- News, optional newsletter emails, separate private-message notifications, and unsubscribe links.
- SMTP settings, encrypted SMTP password, a mail queue with automatic retry and an outgoing-mail overview.
- Editable German/English privacy drafts, acknowledgement and subscription records.
- Light and dark appearance following the device, adjustable text size, and installable to a phone home screen.
- Versioned database migrations, a maintenance switch and record-count checks for updates.

## Start here

1. Follow [INSTALL.md](INSTALL.md) for PHP, the database, the first administrator and SMTP.
2. Configure the portal under **Einstellungen**. Create tariffs and edit student fields.
3. Add students, invite the account holders, and link each student to the appropriate account.
4. Before future updates, follow [UPDATING.md](UPDATING.md).

The distribution ZIP includes PHPMailer and its Composer autoloader. A Git checkout uses `composer install --no-dev --prefer-dist --optimize-autoloader` to install the exact dependency in `composer.lock`.

## Scope of this version

Charges are entered manually because the billing rules are not yet fixed. Tariff frequency labels do not automatically create charges. There is no online payment processor. SMTP sends email; replies belong in the app. No mailbox reader or backup system is included.

Custom content, tariff names and message templates are entered by the operator; switching the interface language does not translate their content. The privacy notice is an editable draft that still needs the actual operator and service-provider details. Invitations stay disabled until SMTP is configured and both privacy texts are completed in settings.

This is an initial release for installation and review. Validation results and
remaining hosting checks are in [VALIDATION.md](VALIDATION.md). A full bug,
security and design review of this release is in [AUDIT.md](AUDIT.md), and what
is done versus outstanding is in [ROADMAP.md](ROADMAP.md) — start there. The
migrations added by that review have not yet been run against MySQL or MariaDB.

## Layout

| Directory | Purpose |
|---|---|
| `public/` | The only web-accessible directory |
| `app/` | Database, authorization, actions and email handling |
| `views/` | Server-rendered HTML |
| `config/` | Configuration example; actual configuration is excluded from Git |
| `database/migrations/` | Ordered, checksummed schema changes |
| `bin/console.php` | Installation, mail processing and maintenance commands |
| `docs/` | Editable privacy drafts and hosting examples |
| `tests/` | Tests for a disposable database |

See [GITHUB.md](GITHUB.md) for publishing the source and its version tag to a new private repository.
