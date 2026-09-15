# Integration tests

These tests create and delete synthetic accounts, students, payments and messages. Use only a disposable local installation with a database whose name ends in `_test`. They never belong on a production database. The database helper is CLI-only and outside the public directory.

Prerequisites: Python 3, PHP with the application extensions and Composer dependencies, and a local MySQL/MariaDB server. `integration.py` uses only the Python standard library. The optional local TLS test additionally uses Python `cryptography` to generate a temporary test certificate; it does not weaken the application's certificate checks.

1. Create a fresh empty database such as `badminton_crm_test`.
2. Create a separate configuration with a local `app_url`, fresh test `app_key`, test database credentials and `secure_cookies=false`. Keep it outside the repository.
3. Set `CRM_CONFIG` to its absolute path, then run `php bin/console.php migrate`.
4. Run `php bin/console.php create-admin`, entering **Test Coach**, **coach@example.test**, and **Temporary-CRM-2026!** twice. These credentials are fixtures used only in this disposable test setup.
5. Start `php -S 127.0.0.1:4180 -t public` with the same `CRM_CONFIG`.
6. Run in another terminal with the same environment:

```bash
export CRM_TEST_ALLOW_DESTRUCTIVE=1
export CRM_TEST_URL=http://127.0.0.1:4180
export CRM_TEST_APP_URL=http://127.0.0.1:4180
export CRM_TEST_PHP=/usr/bin/php
python3 tests/integration.py
python3 tests/smtp_integration.py
```

The second suite expects the state produced by the first. It starts a local fake SMTP server on port 2525, authenticates over STARTTLS with its temporary trusted certificate, captures messages locally and checks the queue. It also verifies password/email changes, unsubscribing and maintenance. No email is sent to an external mail server.

Start again with a fresh disposable database for a repeat run. Do not reuse the fixture credentials anywhere else. The test HTML snapshots contain synthetic test records and are for layout review only; they are not deployed with the application.
