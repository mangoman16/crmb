# Validation — 0.1.0

Executed against PHP **8.2.32**, MariaDB **10.11.18**, and PHPMailer **7.1.1** with a disposable database and synthetic test accounts.

## Completed

- All application PHP files passed syntax checks.
- The initial migration ran successfully and a second run left it unchanged.
- **61 application integration assertions** passed: sign-in, invitation verification and replay rejection, private-route protection, account isolation, role checks, CSRF, safe text rendering, duplicate payment prevention, confirmed/partial balances, tariff price preservation, archived field values, stale-form protection, contacts, filters, messaging, subscriptions, suspension and deletion.
- **14 additional assertions** passed: password reset invalidates sessions, email changes require verification, expired invitation rejection, authenticated STARTTLS SMTP sending and queue status, expired-security-mail cancellation, no-reply notice, signed unsubscribe confirmation, maintenance mode and unchanged counts/totals.
- English server-rendered routes returned successfully; German was used for the application workflow tests.
- Composer audit returned no known advisories or abandoned packages for the locked production dependency at the time of testing.

The SMTP test used a local capture server and a dedicated temporary trusted certificate. It checked actual SMTP/TLS interaction without sending messages to real people.

## Still to check on the target hosting

- The browser security policy blocked local visual previews in the build environment. Responsive CSS is implemented, but visual inspection on an actual phone and desktop remains outstanding.
- PHP-FPM/Apache/LiteSpeed/Nginx configuration, HTTPS redirects, session storage, actual document root, and any proxy or caching rules.
- The chosen mail provider's credentials, allowed sender, DNS authentication, inbox delivery and actual cron execution.
- The exact MySQL or MariaDB version used by the target host. MariaDB 10.11 was exercised; MySQL 8 is the intended compatible target but was not independently run in this environment.
- The operator-specific privacy wording and process for minors/health-related absence information.

No public deployment, independent security audit, production load test or live-data migration has been performed. The release is prepared for self-hosted installation and review.
