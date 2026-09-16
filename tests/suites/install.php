<?php
/**
 * Installing and updating.
 *
 * The operator this is written for has no shell: she uploads files and opens
 * the portal. That makes the installer and the self-applying migrations part of
 * the product rather than a convenience, so they are checked here the way any
 * other behaviour is.
 *
 * What a suite without a web server cannot cover is the request itself. The
 * browser flow - fresh upload redirects to setup, a wrong password is reported
 * in words, the finished portal refuses to be installed twice, a new migration
 * file applies itself on the next page view - has been driven with curl against
 * a real MariaDB; see INSTALL.md.
 */

install_locale('de');

case_('The portal address is derived from the request that asks for it');
$request = fn(array $over = []) => $over + ['HTTP_HOST' => 'badminton.example.at', 'REQUEST_URI' => '/setup.php',
                                            'SERVER_NAME' => 'fallback.invalid', 'SERVER_PORT' => 80];
is_same('http://badminton.example.at', install_base_url($request()),
        'web root pointing at public/, or the root .htaccess rewriting into it');
is_same('http://badminton.example.at/verein', install_base_url($request(['REQUEST_URI' => '/verein/setup.php'])),
        'a subdirectory keeps its prefix');
is_same('http://badminton.example.at/public', install_base_url($request(['REQUEST_URI' => '/public/setup.php'])),
        'hosting without mod_rewrite, opened at /public/setup.php');
is_same('http://badminton.example.at', install_base_url($request(['REQUEST_URI' => '/setup.php?lang=en'])),
        'a query string is not part of the address');
is_same('http://badminton.example.at', install_base_url($request(['REQUEST_URI' => '/index.php?page=students'])),
        'index.php describes the same portal as setup.php');
is_same('https://badminton.example.at', install_base_url($request(['HTTPS' => 'on'])), 'HTTPS is detected');
is_same('https://badminton.example.at', install_base_url($request(['SERVER_PORT' => 443])), 'so is port 443');
is_same('https://badminton.example.at', install_base_url($request(['HTTP_X_FORWARDED_PROTO' => 'https'])),
        'and TLS terminated in front of PHP, which is the normal shared-hosting case');
ok(!install_is_https($request(['HTTPS' => 'off'])), 'HTTPS=off means off, which is how Apache reports plain HTTP');

case_('A hostile Host header cannot become the portal address');
/* app_url is printed into every invitation and password-reset email. A Host
   header the visitor chose would send those links wherever they liked, so it
   has to look like a host name before it is used at all. */
foreach (['evil.example/../x', 'a b.example', 'evil.example"onload=x', "evil\nexample", '',
          'javascript:alert(1)', '-leading.example'] as $hostile)
    is_same('http://fallback.invalid', install_base_url($request(['HTTP_HOST' => $hostile])),
            'refused and fell back to SERVER_NAME: ' . test_show($hostile));
is_same('http://badminton.example.at:8080', install_base_url($request(['HTTP_HOST' => 'badminton.example.at:8080'])),
        'a port is still allowed, because a portal can run on one');

case_('The written configuration is one the application accepts');
$values = ['app_url' => 'https://badminton.example.at', 'app_key' => base64_encode(str_repeat('x', 32)),
           'db' => ['host' => 'localhost', 'port' => 3306, 'database' => 'db_1', 'username' => 'u_1', 'password' => "a'b\\c\"d"],
           'timezone' => 'Europe/Vienna', 'secure_cookies' => true, 'session_idle_minutes' => 120,
           'maintenance_file' => '/srv/shared/maintenance.flag'];
$file = sys_get_temp_dir() . '/crm-install-test-' . getmypid() . '.php';
@unlink($file);
install_write_config($file, install_config_source($values));
ok(is_file($file), 'the file was created');
$written = install_read_config($file);
is_same($values['app_url'], $written['app_url'] ?? null, 'app_url survives');
is_same($values['app_key'], $written['app_key'] ?? null, 'app_key survives');
is_same($values['db']['password'], $written['db']['password'] ?? null,
        'a password with quotes and a backslash survives verbatim');
is_same(3306, $written['db']['port'] ?? null, 'the port is a number, not a string');
is_same(true, $written['secure_cookies'] ?? null, 'secure_cookies is a boolean');
ok(install_config_usable($written), 'and the result is a configuration the application will boot on');
is_same(install_config_keys(), array_keys($written), 'it carries exactly the declared keys, in order');

case_('The example configuration and the written one describe the same file');
/* Two files that have to agree and are edited months apart. Whichever one gains
   a key first, this is what notices. */
$example = install_read_config(APP_ROOT . '/config/config.example.php');
is_same(install_config_keys(), array_keys((array)$example), 'config.example.php carries the declared keys too');
is_same(array_keys($values['db']), array_keys((array)($example['db'] ?? [])), 'including the same database keys');

case_('Re-running setup never invents a new encryption key');
/* app_key decrypts the stored SMTP password and everything still in the mail
   queue. Replacing it loses both, and nothing would say so at the time. */
is_same($values['app_key'], install_app_key($file), 'an existing usable key is kept');
install_write_config($file, str_replace($values['app_key'], 'not-base64-at-all', install_config_source($values)));
$replacement = install_app_key($file);
ok($replacement !== 'not-base64-at-all', 'an unusable key is replaced');
is_same(32, strlen((string)base64_decode($replacement, true)), 'and the replacement is what seal() needs');
$fresh = install_app_key(sys_get_temp_dir() . '/crm-no-such-config-' . getmypid() . '.php');
is_same(32, strlen((string)base64_decode($fresh, true)), 'a missing file simply produces a fresh usable key');
ok($fresh !== install_app_key(sys_get_temp_dir() . '/crm-no-such-config-' . getmypid() . '.php'),
   'and a fresh one each time, rather than a constant somebody could look up');
@unlink($file);

case_('An incomplete configuration is treated as no configuration');
ok(!install_config_usable(null), 'nothing at all');
ok(!install_config_usable([]), 'an empty array');
ok(!install_config_usable(['app_key' => 'short', 'db' => ['database' => 'x']]), 'a key that is not 32 bytes');
ok(!install_config_usable(['app_key' => $values['app_key'], 'db' => ['database' => '']]), 'no database name');
ok(install_config_usable($values), 'a complete one');

case_('A database that refuses the connection is explained, not quoted');
$refusal = function (int $code): string {
    $e = new PDOException('SQLSTATE[HY000] [' . $code . '] something the operator should never have to read');
    return install_db_message($e);
};
ok(str_contains($refusal(1045), 'Passwort'), '1045 points at the password');
ok(str_contains($refusal(1044), 'nicht zugeordnet'), '1044 points at the panel, which is where both causes live');
ok(str_contains($refusal(1049), 'nicht zugeordnet'), '1049 says the same, because the server does not distinguish them');
ok(str_contains($refusal(2002), 'localhost'), '2002 names the host value that usually works');
ok(!str_contains($refusal(1045), 'SQLSTATE'), 'and none of them quote SQLSTATE at her');
ok(str_contains($refusal(9999), 'SQLSTATE'), 'an error with no advice keeps the original text rather than inventing one');

case_('The requirements check reports what the server can and cannot do');
$requirements = install_requirements();
ok(count($requirements) >= 7, 'every requirement is listed (' . count($requirements) . ')');
foreach ($requirements as $check) {
    ok(isset($check['label'], $check['ok'], $check['fatal'], $check['fix']), 'a check has a label, a verdict and a fix');
    ok($check['ok'] || $check['fix'] !== '', 'a failing check says what to do: ' . $check['label']);
}
$labels = implode(' | ', array_column($requirements, 'label'));
foreach (['PHP', 'pdo_mysql', 'mbstring', 'openssl', 'session', 'config/', 'storage/'] as $needed)
    ok(str_contains($labels, $needed), 'it checks ' . $needed);
// This process is running the suite, so these must be satisfied here by definition.
foreach ($requirements as $check)
    if (in_array($check['fatal'], [true], true) && str_contains($check['label'], 'pdo_mysql'))
        ok($check['ok'], 'pdo_mysql is reported as present, which it is');
ok(count(install_blockers()) <= count($requirements), 'blockers are a subset of the requirements');

case_('An old database server is named rather than silently accepted');
is_same('', install_server_note('8.0.36'), 'MySQL 8 is fine');
is_same('', install_server_note('10.11.14-MariaDB-0ubuntu0.24.04.1'), 'MariaDB 10.11 is fine, which is what this was run on');
ok(install_server_note('5.5.62') !== '', 'MySQL 5.5 is called out');
ok(install_server_note('10.1.48-MariaDB') !== '', 'MariaDB 10.1 is called out');
is_same('', install_server_note(''), 'and an unknown version is not guessed about');

case_('The set of migrations has a fingerprint that follows their contents');
$fingerprint = schema_fingerprint();
is_same(64, strlen($fingerprint), 'it is a sha256');
is_same($fingerprint, schema_fingerprint(), 'and it is stable');
$extra = APP_ROOT . '/database/migrations/zz_fingerprint_probe.sql';
file_put_contents($extra, "CREATE TABLE fingerprint_probe (id INT);\n");
ok(schema_fingerprint() !== $fingerprint, 'a migration added by an upload changes it, which is what triggers the update');
ok(in_array('zz_fingerprint_probe.sql', array_map('basename', migration_files()), true), 'and the file is in the list');
file_put_contents($extra, "CREATE TABLE fingerprint_probe (id BIGINT);\n");
$edited = schema_fingerprint();
file_put_contents($extra, "CREATE TABLE fingerprint_probe (id INT);\n");
ok(schema_fingerprint() !== $edited, 'editing a migration in place changes it too, rather than looking unchanged');
@unlink($extra);
is_same($fingerprint, schema_fingerprint(), 'removing it again restores the original');

case_('Migration files are applied in name order');
$names = array_map('basename', migration_files());
$sorted = $names; sort($sorted);
is_same($sorted, $names, 'the order is the numbering, not whatever the filesystem returns');
ok(count($names) >= 6, 'all of them are found (' . count($names) . ')');

case_('Only migrations the ledger has not recorded are pending');
db()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at TEXT NOT NULL)');
db()->exec('DELETE FROM schema_migrations');
is_same($names, schema_pending(), 'an empty ledger means every migration is pending');
foreach (array_slice($names, 0, 3) as $done)
    run('INSERT INTO schema_migrations (version,checksum,applied_at) VALUES (?,?,?)', [$done, str_repeat('0', 64), now()]);
is_same(array_slice($names, 3), schema_pending(), 'a partly filled ledger leaves the rest');
foreach (array_slice($names, 3) as $done)
    run('INSERT INTO schema_migrations (version,checksum,applied_at) VALUES (?,?,?)', [$done, str_repeat('0', 64), now()]);
is_same([], schema_pending(), 'and a full one leaves nothing, which is the state a running portal is in');
db()->exec('DROP TABLE schema_migrations');

case_('Older files than the database are refused, not silently accepted');
/* The wrong ZIP, or an older one put back to undo something. Nothing is pending
   so there is nothing to apply, and without this check the update looks like a
   success while the portal serves old code against a newer schema - the shape of
   problem that loses data quietly instead of failing. */
db()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at TEXT NOT NULL)');
db()->exec('DELETE FROM schema_migrations');
foreach ($names as $done)
    run('INSERT INTO schema_migrations (version,checksum,applied_at) VALUES (?,?,?)', [$done, hash_file('sha256', APP_ROOT.'/database/migrations/'.$done), now()]);
is_same([], schema_extra(), 'a matching release has nothing extra');
run('INSERT INTO schema_migrations (version,checksum,applied_at) VALUES (?,?,?)',
    ['099_from_a_newer_release.sql', str_repeat('0', 64), now()]);
is_same(['099_from_a_newer_release.sql'], schema_extra(), 'a migration the files do not contain is reported');
$refused = null;
try { schema_refuse_unsafe(fn(string $l) => null); } catch (Throwable $e) { $refused = $e; }
ok($refused instanceof UpdateBlocked, 'and the update is refused');
ok(str_contains($refused?->de ?? '', 'älter'), 'in German, saying the files are older');
ok(str_contains($refused?->en ?? '', 'older'), 'and in English');
ok(!str_contains($refused?->de ?? '', '099_'), 'without a file name a parent reading the page cannot act on');
ok(str_contains($refused?->getMessage() ?? '', '099_from_a_newer_release.sql'), 'the log line does name it');
run("DELETE FROM schema_migrations WHERE version='099_from_a_newer_release.sql'");
db()->exec('DROP TABLE schema_migrations');

case_('An incomplete upload is refused before the database is touched');
/* A file manager extracts one file at a time and an FTP client in text mode
   rewrites every PHP file it copies. Both leave a directory that lists fine. */
$tree = sys_get_temp_dir().'/crm-manifest-'.getmypid();
@mkdir($tree.'/app', 0777, true);
file_put_contents($tree.'/app/one.php', "<?php // one\n");
file_put_contents($tree.'/app/two.php', "<?php // two\n");
$manifest = $tree.'/MANIFEST';
$write = function (array $files) use ($manifest, $tree): void {
    $lines = [];
    foreach ($files as $relative) $lines[] = hash_file('sha256', $tree.'/'.$relative).'  '.$relative;
    file_put_contents($manifest, implode("\n", $lines)."\n");
};
$write(['app/one.php', 'app/two.php']);
is_same([], release_mismatches($manifest, $tree), 'an intact upload matches');
file_put_contents($tree.'/app/two.php', "<?php // two\r\n");          // FTP in text mode
is_same(['app/two.php'], release_mismatches($manifest, $tree), 'a rewritten line ending is caught');
file_put_contents($tree.'/app/two.php', "<?php // two\n");
@unlink($tree.'/app/one.php');                                        // extract stopped partway
is_same(['app/one.php'], release_mismatches($manifest, $tree), 'a file that never arrived is caught');
file_put_contents($tree.'/app/one.php', "<?php // one\n");
is_same([], release_mismatches($manifest, $tree), 'and it passes again once complete');
file_put_contents($manifest, "not a manifest line\n");
is_same(['MANIFEST'], release_mismatches($manifest, $tree), 'a mangled manifest is itself a mismatch');
file_put_contents($manifest, str_repeat('a', 64)."  ../../etc/passwd\n");
is_same(['MANIFEST'], release_mismatches($manifest, $tree), 'and a path trying to leave the release is refused, not hashed');
is_same([], release_mismatches($tree.'/no-such-manifest', $tree),
        'a git checkout ships no manifest and is skipped, rather than failing every update');
@unlink($manifest); @unlink($tree.'/app/one.php'); @unlink($tree.'/app/two.php');
@rmdir($tree.'/app'); @rmdir($tree);

case_('An update that loses records keeps the portal closed');
/* Counts do not prove an update was right, but a count that fell proves it was
   not, and that is worth catching while the backup is still the newest thing
   that happened. */
$before = ['students' => 12, 'payments' => 40];
does_not_throw(fn() => schema_verify_counts(['students' => 0], fn(string $l) => null),
               'a table that only grew is fine');
$dropped = null;
try { schema_verify_counts($before, fn(string $l) => null); } catch (Throwable $e) { $dropped = $e; }
ok($dropped instanceof UpdateBlocked, 'a table that shrank stops the update');
ok(str_contains($dropped?->de ?? '', 'students'), 'and names the table');
ok(str_contains($dropped?->de ?? '', 'storage/backups'), 'and says where the copy from beforehand is');
does_not_throw(fn() => schema_verify_counts(['no_such_table' => 5], fn(string $l) => null),
               'a table the release has not created yet is skipped rather than reported as lost');

case_('The guarded tables are the ones a family would notice');
foreach (['accounts', 'students', 'charges', 'payments', 'messages'] as $table)
    ok(in_array($table, schema_guarded_tables(), true), $table.' is guarded');
foreach (schema_guarded_tables() as $table)
    ok(test_has_table($table), 'the guarded table '.$table.' exists in the schema');
ok(array_key_exists('students', schema_counts()), 'counts are taken for it');

case_('A backup is required before migrating, and the way past it is deliberate');
is_same(test_driver() === 'mysql', backup_supported(),
        'a dump is offered exactly where its dialect is understood');
if (!backup_supported()) {
    // Said out loud in the run's footer rather than left as a silent gap: the
    // dump itself is only proven by tests/mariadb-local.sh.
    test_unsupported(array_merge(test_unsupported(), ['the pre-update database backup (MySQL dialect)']));
    throws(fn() => backup_database('test'), 'and elsewhere it throws rather than writing an unusable file', 'MySQL');
}
@unlink(backup_override_file());
ok(!backup_override_claimed(), 'without the override file there is no way past the backup');
file_put_contents(backup_override_file(), '');
ok(backup_override_claimed(), 'the file in storage/ lets an operator who backed up herself proceed');
ok(!is_file(backup_override_file()), 'and it is consumed, so it cannot quietly disable the next update too');
ok(!backup_override_claimed(), 'a second update needs a fresh one');

if (test_driver() === 'mysql') {
    case_('The backup is real SQL carrying the real data');
    /* Only reachable on the engine whose dialect it is written in. The proof
       that it imports again lives outside this suite, because importing needs a
       second database; see VALIDATION.md. */
    foreach (glob(backup_dir().'/*.sql') ?: [] as $stale) @unlink($stale);
    make_student(['first_name' => 'Sofía', 'last_name' => "O'Brien-Müller", 'internal_notes' => "a\\b \"c\"\nzweite Zeile"]);
    make_student(['first_name' => 'Jonas', 'last_name' => 'Groß']);
    $path = backup_database('suite');
    ok(is_file($path), 'a file was written');
    ok(str_ends_with($path, '.sql'), 'named .sql, which is what the panel import expects');
    ok(!glob(backup_dir().'/*.part'), 'and no half-written file is left beside it');
    $dump = (string)file_get_contents($path);
    ok(str_contains($dump, 'SET FOREIGN_KEY_CHECKS=0'), 'constraints are relaxed so table order cannot break the import');
    ok(str_contains($dump, 'SET NAMES utf8mb4'), 'and the charset is declared, or every umlaut comes back wrong');
    ok(str_contains($dump, 'CREATE TABLE `students`'), 'the structure is in it');
    ok(str_contains($dump, 'DROP TABLE IF EXISTS `students`'), 'and it is safe to import twice');
    is_same(2, substr_count($dump, 'INSERT INTO `students` VALUES'), 'one statement per row, so a failed import names the row');
    ok(str_contains($dump, 'Sofía') && str_contains($dump, 'Groß'), 'the data is there, umlauts intact');
    ok(str_contains($dump, 'Restore by importing'), 'and it opens with what to do with it');

    case_('Only the most recent copies are kept, and never the newest one');
    /* The decoys are older than the copy that follows them but carry names that
       sort above anything it can produce. Sorting by name rather than by age
       would therefore prune the one file that must not be pruned: the copy taken
       moments before a migration. */
    foreach (backups() as $copy) @unlink($copy['path']);
    for ($i = 0; $i <= BACKUP_KEEP; $i++) {
        $decoy = backup_dir() . '/9999-12-31-235959-decoy' . $i . '-ffffffff.sql';
        file_put_contents($decoy, '-- older, but named as though it were newer');
        touch($decoy, time() - 3600);
    }
    $newest = backup_database('vor-update');
    is_same(BACKUP_KEEP, count(backups()), 'a portal nobody prunes would eventually fill the disk quota');
    ok(is_file($newest), 'and the copy just written survived its own pruning');
    is_same(basename($newest), backups()[0]['name'], 'it is listed first, because the list is by age and not by name');
    ok(backups()[0]['bytes'] > 0, 'with something in it');
    foreach (backups() as $copy) @unlink($copy['path']);
    run('DELETE FROM students');
}

case_('A migration that stops partway says which one and where');
$stopped = new SchemaError('007_example.sql', 4, 12, 'ALTER TABLE students ADD COLUMN x INT', 'Duplicate column name');
is_same('007_example.sql: 4/12', $stopped->summary(), 'the short form names the file and the statement');
ok(str_contains($stopped->getMessage(), 'Duplicate column name'), 'the long form keeps the database error');
ok(str_contains($stopped->getMessage(), 'ALTER TABLE students'), 'and the statement, for the log and the console');
ok(str_contains($stopped->getMessage(), 'NOT recorded'), 'and says re-running would start it again');
ok(!str_contains($stopped->summary(), 'ALTER TABLE'), 'the short form carries no SQL, because a visitor may be reading it');

case_('The first administrator can only be created once');
run('DELETE FROM accounts');
$id = create_admin_account('Trainerin', 'Trainerin@Example.Test', 'korrektesPferdBatterie');
ok($id > 0, 'the first one is created');
$admin = one('SELECT * FROM accounts WHERE id=?', [$id]);
is_same('admin', $admin['role'], 'with the administrator role');
is_same('active', $admin['state'], 'active');
ok($admin['verified_at'] !== null, 'and already verified, because no invitation confirmed it');
is_same('trainerin@example.test', $admin['email'], 'the address is normalised the way sign-in expects it');
ok(password_verify('korrektesPferdBatterie', $admin['password_hash']), 'the password is hashed, and verifies');
throws(fn() => create_admin_account('Zweite', 'zweite@example.test', 'korrektesPferdBatterie'),
       'a second one is refused, which is what closes the setup page afterwards');
is_same(1, (int)scalar("SELECT COUNT(*) FROM accounts WHERE role='admin'"), 'and nothing was written');
does_not_throw(fn() => create_admin_account('Zweite', 'zweite@example.test', 'korrektesPferdBatterie', true),
               'the console can still force one, which is sometimes the only way back in');
run('DELETE FROM accounts');

case_('Waiting work happens without a cron job');
/* On hosting with no cron line, a queued invitation that nothing ever picks up
   is the difference between a working portal and a dead one. */
$expired = make_account();
run('INSERT INTO auth_tokens (account_id,token_hash,purpose,expires_at,created_at) VALUES (?,?,?,?,?)',
    [$expired, str_repeat('a', 64), 'invite', gmdate('Y-m-d H:i:s', time() - 3600), now()]);
run('INSERT INTO auth_tokens (account_id,token_hash,purpose,expires_at,created_at) VALUES (?,?,?,?,?)',
    [$expired, str_repeat('b', 64), 'invite', gmdate('Y-m-d H:i:s', time() + 3600), now()]);
set_setting('auto_background', true);
set_setting('tick_last_run', '');
set_setting('prune_last_run', '');
run_background_tasks();
setting_cache_clear();
ok((string)setting('tick_last_run') !== '', 'a page view leaves a background run behind');
is_same(1, (int)scalar('SELECT COUNT(*) FROM auth_tokens'), 'the expired token was removed');
is_same(str_repeat('b', 64), (string)scalar('SELECT token_hash FROM auth_tokens'), 'and the valid one was not');

case_('And not on every single request');
/* A busy minute has to cost one background run, not one per page view, so the
   check is that a recent run blocks the next one - not merely that two calls in
   the same second happen to write the same timestamp. */
$recent = gmdate('Y-m-d H:i:s', time() - 10);
set_setting('tick_last_run', $recent);
set_setting('prune_last_run', '');
run('INSERT INTO auth_tokens (account_id,token_hash,purpose,expires_at,created_at) VALUES (?,?,?,?,?)',
    [$expired, str_repeat('c', 64), 'invite', gmdate('Y-m-d H:i:s', time() - 3600), now()]);
run_background_tasks();
setting_cache_clear();
is_same($recent, (string)setting('tick_last_run'), 'a page view ten seconds after the last run does nothing');
is_same(2, (int)scalar('SELECT COUNT(*) FROM auth_tokens'), 'and no work was done, expired token still there');
set_setting('tick_last_run', gmdate('Y-m-d H:i:s', time() - TICK_INTERVAL - 1));
run_background_tasks();
setting_cache_clear();
ok((string)setting('tick_last_run') !== $recent, 'a page view after the interval runs again');
is_same(1, (int)scalar('SELECT COUNT(*) FROM auth_tokens'), 'and does the waiting work');

case_('And not while an operator is updating by hand');
set_setting('tick_last_run', '');
file_put_contents(maintenance_file(), now());
run_background_tasks();
setting_cache_clear();
is_same('', (string)setting('tick_last_run'), 'maintenance mode holds the background work too');
@unlink(maintenance_file());

case_('And not at all when the operator has a cron job and switched it off');
set_setting('auto_background', false);
set_setting('tick_last_run', '');
run_background_tasks();
setting_cache_clear();
is_same('', (string)setting('tick_last_run'), 'the switch under Einstellungen → System is honoured');
set_setting('auto_background', true);
run('DELETE FROM auth_tokens');
run('DELETE FROM accounts');

case_('The first administrator is validated like any other account');
foreach ([['', 'a@example.test', 'korrektesPferdBatterie', 'an empty name'],
          [str_repeat('n', 161), 'a@example.test', 'korrektesPferdBatterie', 'a name that is too long'],
          ['Name', 'not-an-address', 'korrektesPferdBatterie', 'an address that is not one'],
          ['Name', 'a@example.test', 'kurz', 'a password under 12 characters'],
          ['Name', 'a@example.test', 'passwordpassword', 'a password an attacker tries first']] as [$n, $m, $p, $what])
    throws(fn() => create_admin_account($n, $m, $p), 'refused: ' . $what);
is_same(0, (int)scalar('SELECT COUNT(*) FROM accounts'), 'and not one of them left a row behind');
