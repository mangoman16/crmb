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

case_('A new portal asks families nothing the trainer has not added herself');
// The runner has just emptied the database and applied the seed, which is what a
// fresh install is. A seeded example field („Trainingsgruppe") showed every family
// an empty „Weitere Angaben" card until she found and deleted it (ADR 0011).
is_same(0, (int)scalar('SELECT COUNT(*) FROM field_definitions'), 'the seed creates no custom field');
ok((int)scalar('SELECT COUNT(*) FROM message_templates') > 0,
   'while the examples she does start with are still there, so the check above ran against a seeded portal');

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
// The third copy: the file every suite run boots on. A key the installer
// gains and the tests never write would be a key no test ever ran with.
$runConfig = test_run_dir().'/keys-'.bin2hex(random_bytes(4)).'.php';
write_run_config($runConfig, $values['db'], test_run_dir());
$ran = install_read_config($runConfig);
@unlink($runConfig);
is_same(install_config_keys(), array_keys((array)$ran), 'and so does the configuration the suites run on');
is_same(array_keys($values['db']), array_keys((array)($ran['db'] ?? [])), 'with the same database keys');

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
/* On a copy, never on the shipped directory. The owner may run this suite inside
   the folder the portal is served from, and a probe written there even for a
   moment is a migration the next page view applies to her live database and
   records in the ledger. Once the probe is deleted, the ledger names a file the
   upload no longer has, and the portal refuses to open. */
$fingerprint = schema_fingerprint();
$shipped = migration_files();
is_same(64, strlen($fingerprint), 'it is a sha256');
is_same($fingerprint, schema_fingerprint(), 'and it is stable');
$copy = sys_get_temp_dir() . '/crm-migrations-' . getmypid();
@mkdir($copy, 0777, true);
foreach ($shipped as $file) copy($file, $copy . '/' . basename($file));
is_same($fingerprint, schema_fingerprint($copy), 'a copy of the same files has the same fingerprint');
$extra = $copy . '/zz_fingerprint_probe.sql';
file_put_contents($extra, "CREATE TABLE fingerprint_probe (id INT);\n");
ok(schema_fingerprint($copy) !== $fingerprint, 'a migration added by an upload changes it, which is what triggers the update');
ok(in_array('zz_fingerprint_probe.sql', array_map('basename', migration_files($copy)), true), 'and the file is in the list');
is_same($shipped, migration_files(), 'while the shipped directory never saw the probe');
is_same($fingerprint, schema_fingerprint(), 'so the portal’s own fingerprint did not move');
file_put_contents($extra, "CREATE TABLE fingerprint_probe (id BIGINT);\n");
$edited = schema_fingerprint($copy);
file_put_contents($extra, "CREATE TABLE fingerprint_probe (id INT);\n");
ok(schema_fingerprint($copy) !== $edited, 'editing a migration in place changes it too, rather than looking unchanged');
@unlink($extra);
is_same($fingerprint, schema_fingerprint($copy), 'removing it again restores the original');
foreach (glob($copy . '/*.sql') ?: [] as $file) @unlink($file);
@rmdir($copy);

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

case_('A migration statement that returns rows does not poison the rest of the run');
// PDO::exec() leaves an open result set behind for anything that returns rows -
// a SELECT that checks something before altering it, a SHOW - and every query
// after it on the same connection then fails with "unbuffered queries are
// active", blaming a statement two lines further down.
run_migration_statement('SELECT 1');
does_not_throw(fn() => scalar('SELECT COUNT(*) FROM accounts'),
               'the connection is still usable afterwards');
run_migration_statement('SELECT 1');
does_not_throw(fn() => run_migration_statement('SELECT 2'), 'and so is the next statement of the migration');

case_('A CALL is drained to its last result set, and fails the migration if that one fails');
/* A CALL can answer with several result sets, and an error raised after the
   first arrives only when the next is asked for. Swallowing it there - which the
   runner once did, for a test engine that had no second result set - records the
   migration as applied when its statement failed half way. Routines are made
   here because a hosting panel's database user may not be allowed to. */
$routine = function (string $name, string $body): bool {
    try { db()->exec('DROP PROCEDURE IF EXISTS '.$name); db()->exec('CREATE PROCEDURE '.$name.'() BEGIN '.$body.' END'); return true; }
    catch (PDOException) { return false; }
};
if (!$routine('crm_test_two_answers', 'SELECT 1; SELECT 2;') || !$routine('crm_test_fails_second', "SELECT 1; SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'zweite Hälfte';")) {
    test_unsupported(array_merge(test_unsupported(),
        ['a migration statement that CALLs a procedure with several result sets (this database user may not create one)']));
} else {
    does_not_throw(fn() => run_migration_statement('CALL crm_test_two_answers()'), 'a CALL answering twice is drained');
    does_not_throw(fn() => scalar('SELECT COUNT(*) FROM accounts'), 'and the connection is usable afterwards');
    throws(fn() => run_migration_statement('CALL crm_test_fails_second()'),
           'a CALL that fails after its first answer fails the statement, so the migration is not recorded as applied', 'zweite Hälfte');
    does_not_throw(fn() => scalar('SELECT COUNT(*) FROM accounts'), 'and the connection is still usable for the error to be reported');
}
foreach (['crm_test_two_answers', 'crm_test_fails_second'] as $name)
    try { db()->exec('DROP PROCEDURE IF EXISTS '.$name); } catch (PDOException) { /* never made */ }

case_('The guarded tables are the ones a family would notice');
foreach (['accounts', 'students', 'charges', 'payments', 'messages'] as $table)
    ok(in_array($table, schema_guarded_tables(), true), $table.' is guarded');
foreach (schema_guarded_tables() as $table)
    ok(test_has_table($table), 'the guarded table '.$table.' exists in the schema');
ok(array_key_exists('students', schema_counts()), 'counts are taken for it');

case_('A backup is required before migrating, and the way past it is deliberate');
@unlink(backup_override_file());
ok(!backup_override_claimed(), 'without the override file there is no way past the backup');
file_put_contents(backup_override_file(), '');
ok(backup_override_claimed(), 'the file in storage/ lets an operator who backed up herself proceed');
ok(!is_file(backup_override_file()), 'and it is consumed, so it cannot quietly disable the next update too');
ok(!backup_override_claimed(), 'a second update needs a fresh one');

case_('The backup is real SQL carrying the real data');
/* The proof that it imports again lives outside this suite, because importing
   needs a second database; see VALIDATION.md. */
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

case_('A backup that cannot reach the database leaves nothing behind');
/* Its connection is opened before its file. The other way round, every attempt
   that could not connect left an empty .part file in the folder she opens to
   restore from. A login that does not exist fails on any host, over a socket or
   a port alike; db() keeps the connection it already has. */
$kept = $GLOBALS['config']['db'];
$GLOBALS['config']['db']['username'] = 'niemand_'.bin2hex(random_bytes(3));
$GLOBALS['config']['db']['password'] = 'falsch';
try { throws(fn() => backup_database('test'), 'a backup the database refuses to talk to is refused'); }
finally { $GLOBALS['config']['db'] = $kept; }
is_same([], glob(backup_dir().'/*.part') ?: [], 'and leaves no half-written file behind');
is_same([], backups(), 'nor a copy that would look restorable');

case_('A migration that stops partway says which one and where');
$stopped = new SchemaError('007_example.sql', 4, 12, 'ALTER TABLE students ADD COLUMN x INT', 'Duplicate column name');
is_same('007_example.sql: 4/12', $stopped->summary(), 'the short form names the file and the statement');
ok(str_contains($stopped->getMessage(), 'Duplicate column name'), 'the long form keeps the database error');
ok(str_contains($stopped->getMessage(), 'ALTER TABLE students'), 'and the statement, for the log and the console');
ok(str_contains($stopped->getMessage(), 'NOT recorded'), 'and says re-running would start it again');
ok(!str_contains($stopped->summary(), 'ALTER TABLE'), 'the short form carries no SQL, because a visitor may be reading it');

case_('The first administrator can only be created once');
run('DELETE FROM accounts');
$made = create_admin_account('Trainerin', 'Trainerin@Example.Test', 'korrektesPferdBatterie');
$id = (int)($made['id'] ?? 0);
ok($id > 0, 'the first one is created');
$admin = one('SELECT * FROM accounts WHERE id=?', [$id]);
is_same('admin', $admin['role'], 'with the administrator role');
is_same('active', $admin['state'], 'active');
ok($admin['verified_at'] !== null, 'and already verified, because no invitation confirmed it');
is_same('trainerin@example.test', $admin['email'], 'the address is normalised the way every lookup expects it');
ok(password_verify('korrektesPferdBatterie', $admin['password_hash']), 'the password is hashed, and verifies');
throws(fn() => create_admin_account('Zweite', 'zweite@example.test', 'korrektesPferdBatterie'),
       'a second one is refused, which is what closes the setup page afterwards');
is_same(1, (int)scalar("SELECT COUNT(*) FROM accounts WHERE role='admin'"), 'and nothing was written');
does_not_throw(fn() => create_admin_account('Zweite', 'zweite@example.test', 'korrektesPferdBatterie', true),
               'the console can still force one, which is sometimes the only way back in');
is_same(['Zweite', 'admin'], array_values(one("SELECT name,role FROM accounts WHERE email='zweite@example.test'") ?? []),
        'as an administrator of that name');
run('DELETE FROM accounts');

case_('Setup and the console give no administrator an address another login uses (ADR 0021, §5)');
/* Every creator asks refuse_address_in_use(), the setup page and the console
   with --force included, so the person reads a sentence rather than a 23000. */
make_account(['role' => 'student', 'email' => 'familie@example.test']);
throws(fn() => create_admin_account('Trainerin', 'familie@example.test', 'korrektesPferdBatterie'),
       'a family’s address is refused at setup', 'Jede Person braucht ihre eigene');
throws(fn() => create_admin_account('Trainerin', 'Familie@Example.Test', 'korrektesPferdBatterie', true),
       'and by the console with --force, however it is capitalised', 'Jede Person braucht ihre eigene');
is_same(0, (int)scalar("SELECT COUNT(*) FROM accounts WHERE role='admin'"), 'and no administrator was written');
$first = create_admin_account('Lena Müller', 'lena@example.test', 'korrektesPferdBatterie');
$second = create_admin_account('Lena Müller', 'lena2@example.test', 'korrektesPferdBatterie', true);
ok($first['id'] > 0 && $second['id'] > $first['id'], 'two administrators may share a name: each has an address of their own');
run('DELETE FROM accounts');

case_('The password chosen at setup is the one sign-in accepts');
/* Setup kept the spaces around the password and sign-in removed them, so an
   administrator who typed one stray space was stored under one password and
   checked against another, and could never sign in. */
$sent = install_submission(['admin_name' => ' Trainerin ', 'admin_email' => ' setup@example.test ',
                            'admin_password' => ' korrektesPferdBatterie ', 'admin_password2' => ' korrektesPferdBatterie ',
                            'db_password' => ' vom Panel '], ['admin_name' => '', 'admin_email' => '']);
$_POST = ['password' => ' korrektesPferdBatterie '];
is_same(post('password'), $sent['password'], 'setup reads the password exactly as every other form does');
is_same($sent['password'], $sent['repeat'], 'and its repetition the same way');
is_same('Trainerin', $sent['form']['admin_name'], 'and the other fields too');
is_same(' vom Panel ', $sent['db_password'], 'only the database password arrives as typed: the server checks it, not the portal');
create_admin_account($sent['form']['admin_name'], $sent['form']['admin_email'], $sent['password']);
does_not_throw(fn() => submit('login', ['login' => $sent['form']['admin_email'], 'password' => ' korrektesPferdBatterie ']),
               'signing in with the address and the password typed at setup works');
sign_out();
run('DELETE FROM accounts');

case_('Setup and the console name the address to sign in with, and the example logins by theirs');
$console = (string)file_get_contents(APP_ROOT.'/bin/console.php');
$setup = (string)file_get_contents(APP_ROOT.'/public/setup.php');
ok(str_contains($setup, "install_e(email_normalised(\$form['admin_email']))") && str_contains($console, 'Sign in with the email address'),
   'both tell the first administrator to sign in with the address they typed');
ok(str_contains($setup, "install_e(\$login['email'])") && str_contains($console, "\$login['email']"),
   'and list the example logins by address');

case_('The page after installing points to the checklist rather than listing steps');
/* It listed two steps - the mail settings and "both" privacy drafts - after the
   checklist had taken them over: only the German draft is required, invitations
   need a passed mail test, and there are nine steps (ADR 0011). The page is read
   as source because it can only be rendered by installing; the title is read
   from the checklist itself, so renaming one without the other fails here. */
$setupPage = (string)file_get_contents(APP_ROOT.'/public/setup.php');
preg_match("/page_head\(t\('([^']+)','([^']+)'\)/", (string)file_get_contents(APP_ROOT.'/views/start.php'), $title);
ok(isset($title[2]), 'the checklist\'s title is found on the checklist');
ok(str_contains($setupPage, '„'.($title[1] ?? '?').'“') && str_contains($setupPage, '“'.($title[2] ?? '?').'”'),
   'the finished page names the checklist by that title, in both languages');
foreach (['zwei Schritte', 'two steps', 'beide Entwürfe', 'both drafts'] as $stale)
    ok(!str_contains($setupPage, $stale), 'and no longer says '.test_show($stale));

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

case_('The version is written into the database, not only into the files');
// Every shipped migration recorded as applied, so schema_apply() has nothing to
// run and what is exercised here is its bookkeeping: the marker, the version and
// the history it writes afterwards.
db()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at TEXT NOT NULL)');
db()->exec('DELETE FROM schema_migrations');
foreach (migration_files() as $file)
    run('INSERT INTO schema_migrations (version,checksum,applied_at) VALUES (?,?,?)',
        [basename($file), hash_file('sha256', $file), now()]);
is_same([], schema_pending(), 'nothing is waiting to be applied');
schema_apply();
setting_cache_clear();
is_same(app_version(), database_version(), 'applying records which release wrote this database');
ok(str_contains(schema_state(), app_version()), 'the "already current" marker carries the version, not only the migrations');
is_same(true, schema_is_current(), 'and afterwards there is nothing waiting');

case_('A release that brings no migration still records itself');
// Without the version in the marker, schema_written_by would keep naming
// whichever older release last happened to change the schema, and the number
// the operator is asked to trust would be quietly wrong.
set_setting('schema_written_by', '0.0.1');
@unlink(schema_stamp_file());
is_same(false, schema_is_current(), 'a version the database does not know means there is work to do');
schema_apply();
setting_cache_clear();
is_same(app_version(), database_version(), 'and doing it brings the marker up to date');
$history = version_history();
ok($history !== [], 'the move between releases is written down');
is_same('0.0.1', $history[0]['from'] ?? '', 'saying which release it came from');
is_same(app_version(), $history[0]['to'] ?? '', 'and which it is on now');

case_('Status says plainly whether files and database agree');
$status = version_status();
is_same('current', $status['state'], 'they agree');
is_same(true, $status['ok'], 'so nothing needs doing');
ok(version_status_text($status) !== '', 'and it can say so in words');
ok($status['applied'] >= count(migration_files()), 'every shipped migration is recorded as applied');

db()->exec('DROP TABLE schema_migrations');

// ---------------------------------------------------------------------------
// Who may finish an installation
// ---------------------------------------------------------------------------

/* Each configuration below lives in a folder of its own inside the run's
   folder, with its maintenance flag beside it, so whatever the setup page
   writes - the setup code, the schema marker - lands there and nowhere else. */
$setupConfig = function (array $db): string {
    $work = test_run_dir().'/setup-'.bin2hex(random_bytes(4));
    mkdir($work, 0700);
    write_run_config($work.'/config.php', $db, $work);
    return $work.'/config.php';
};
$ownDb = config('db');
$downDb = ['host' => '127.0.0.1', 'port' => 1, 'database' => 'crm_setup_probe_test', 'username' => 'crm_setup_probe', 'password' => ''];
$down = $setupConfig($downDb);
$denied = $setupConfig(['username' => 'niemand_'.bin2hex(random_bytes(3)), 'password' => 'falsch'] + $ownDb);
$ours = $setupConfig($ownDb);

case_('A database that does not answer is unreachable, never an installation waiting to be finished');
/* "configured" - a configuration and no administrator - is the one state in
   which the setup page creates an administrator. A database that failed to
   answer read as that state too, so on an installed portal a moment's outage
   turned the page back into an offer to whoever opened it next. */
is_same('unreachable', install_state($down), 'a server where nothing listens is unreachable');
is_same('unreachable', install_state($denied), 'and so is one that refuses the login');
run('DELETE FROM accounts');
is_same('configured', install_state($ours), 'a database that answers and has no administrator is waiting for one');
make_account(['role' => 'admin']);
is_same('installed', install_state($ours), 'and one with an administrator is installed');
run('DELETE FROM accounts');
is_same('fresh', install_state(test_run_dir().'/no-such-config.php'), 'no configuration at all is a fresh upload');
$noTables = (string)getenv('CRM_MIGRATION_CONFIG');
if ($noTables === '')
    test_unsupported(array_merge(test_unsupported(),
        ['setup reading a database with no tables yet as one still to be installed (set CRM_MIGRATION_CONFIG to the config of a'
         .' second, empty *_test database; tests/mariadb-local.sh does)']));
else
    is_same('configured', install_state($noTables),
            'a database that answers with no tables in it yet is one still to be installed: the configuration was written, the schema was not');

case_('The installer skips the update\'s safeguards only on a database that has never been migrated');
/* The setup page applies the migrations without the backup, the older-files
   check and the manifest check, because a first install has nothing to protect.
   Asked on a database that has a ledger, that same call would let an outdated
   upload run against a portal with families in it, with no copy taken first. */
db()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL)');
db()->exec('DELETE FROM schema_migrations');
foreach (migration_files() as $file)
    run('INSERT INTO schema_migrations (version,checksum,applied_at) VALUES (?,?,?)', [basename($file), hash_file('sha256', $file), now()]);
run('INSERT INTO schema_migrations (version,checksum,applied_at) VALUES (?,?,?)', ['099_from_a_newer_release.sql', str_repeat('0', 64), now()]);
$waived = null;
try { schema_apply(null, safeguards: false); } catch (Throwable $e) { $waived = $e; }
ok($waived instanceof UpdateBlocked, 'asked to skip them on a database with a ledger, it checks anyway and refuses the older files');
ok(str_contains($waived?->en ?? '', 'older'), 'for the reason the update itself would have given');
run("DELETE FROM schema_migrations WHERE version='099_from_a_newer_release.sql'");

case_('Nobody finishes an installation without showing they can reach its files');
/* If creating the first administrator was refused after the configuration had
   been written - a password on the list of common ones, a database user that
   may not create tables - the page stayed open in the "configured" state, and
   the first stranger to open it became the administrator. The page is asked
   here the way a browser asks it, in a process of its own (tests/setup-request.php). */
$setupPage = function (string $config, array $request = []): array {
    $file = test_run_dir().'/request-'.bin2hex(random_bytes(4)).'.json';
    file_put_contents($file, json_encode($request + ['method' => 'GET', 'host' => '127.0.0.1', 'https' => false,
                                                     'headers' => [], 'post' => [], 'cookie' => []]));
    $out = [];
    exec('CRM_CONFIG='.escapeshellarg($config).' '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(TEST_ROOT.'/setup-request.php')
         .' '.escapeshellarg($file).' 2>&1', $out, $code);
    @unlink($file);
    $answer = json_decode((string)end($out), true);
    return is_array($answer) ? $answer + ['log' => implode("\n", array_slice($out, 0, -1))]
                             : ['status' => 0, 'body' => '', 'log' => implode("\n", $out)];
};
$firstAccount = ['admin_name' => 'Fremde Person', 'admin_email' => 'fremd@example.test',
                 'admin_password' => 'korrektesPferdBatterie', 'admin_password2' => 'korrektesPferdBatterie',
                 'app_url' => 'http://127.0.0.1:4192', 'timezone' => 'Europe/Vienna', 'lang' => 'de'];
$admins = fn(): int => (int)scalar("SELECT COUNT(*) FROM accounts WHERE role='admin'");
$codeFile = dirname($ours).'/setup-code.txt';
$codeIn = function () use ($codeFile): string {
    preg_match('/\b[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}\b/', (string)@file_get_contents($codeFile), $m);
    return $m[0] ?? '';
};
if (!function_exists('exec')) {
    test_unsupported(array_merge(test_unsupported(), ['the setup page itself, asked as a browser asks it (this PHP disables exec)']));
} else {
    run('DELETE FROM accounts');
    $page = $setupPage($ours);
    is_same(200, $page['status'], 'a configured portal with no administrator shows the setup page'.($page['log'] !== '' ? ': '.$page['log'] : ''));
    ok(str_contains($page['body'], 'name="admin_password"'), 'with the form for the first account');
    ok(!str_contains($page['body'], (string)$ownDb['database']), 'but not the name of the database, which a visitor has no business knowing');
    ok(!preg_match('~<dt>[^<]*Server[^<]*</dt>~', $page['body']), 'nor the server it is on');
    ok(str_contains($page['body'], 'name="setup_code"'), 'and it asks for the setup code');
    ok(str_contains($page['body'], 'storage/setup-code.txt') || str_contains($page['body'], 'setup-code.txt'),
       'saying which file the code is in');
    ok($codeIn() !== '', 'which it has written beside the maintenance flag, where only somebody with the files can read it');

    $setupPage($ours, ['method' => 'POST', 'post' => $firstAccount]);
    is_same(0, $admins(), 'a stranger who sends the form without the code creates no administrator');
    $wrong = $setupPage($ours, ['method' => 'POST', 'post' => $firstAccount + ['setup_code' => 'AAAA-BBBB-CCCC']]);
    is_same(0, $admins(), 'nor with a code that is not the one in the file');
    ok(str_contains($wrong['body'], 'Einrichtungscode'), 'and is told that the code is what is missing');
    ok(is_file($codeFile), 'while the code stays where it was, for the person who can read it');

    $typed = strtolower(str_replace('-', ' ', $codeIn()));
    $done = $setupPage($ours, ['method' => 'POST', 'post' => ['admin_name' => 'Trainerin', 'admin_email' => 'trainerin@example.test',
                                                             'setup_code' => ' '.$typed.' '] + $firstAccount]);
    is_same(1, $admins(), 'the person who read the code from the file finishes it, typed in lower case and with spaces');
    is_same(['trainerin@example.test'], array_column(rows("SELECT email FROM accounts WHERE role='admin'"), 'email'),
            'as the administrator she named');
    ok(str_contains($done['body'], 'Das Portal ist eingerichtet'), 'and is told so');
    ok(!is_file($codeFile), 'the code is removed afterwards, because it has nothing left to open');

    run('DELETE FROM accounts');
    $setupPage($ours);
    $cookie = $codeIn();
    ok($cookie !== '', 'an installation left unfinished again gets a code of its own');
    $setupPage($ours, ['method' => 'POST', 'post' => $firstAccount, 'cookie' => ['badminton_setup' => $cookie]]);
    is_same(1, $admins(), 'and the browser that wrote the configuration, which was handed the code as a cookie, needs no file manager');
    run('DELETE FROM accounts');
    @unlink($codeFile);

    $gone = $setupPage($down);
    is_same(503, $gone['status'], 'a portal whose database does not answer is refused, not offered');
    ok(!str_contains($gone['body'], 'name="admin_password"'), 'with no form to create an administrator with');
    ok(!str_contains($gone['body'], 'crm_setup_probe'), 'and without naming its database or the user it signs in as');
    $tried = $setupPage($down, ['method' => 'POST', 'post' => $firstAccount]);
    is_same(503, $tried['status'], 'a form sent to it anyway is refused the same way');
    ok(!str_contains($tried['body'], 'SQLSTATE') && !str_contains($tried['body'], 'crm_setup_probe'),
       'without a connection error that names the database or its user');
}

case_('A portal is not installed over plain HTTP, except on the computer it runs on');
/* Installed over http://, the portal wrote app_url=http and secure_cookies=false
   without a word: the database password and hers crossed the network readable,
   and every sign-in afterwards did too, for as long as the portal ran. Refused
   rather than warned about, because a box to tick is ticked to make the warning
   go away, while what it costs is permanent. */
$hosts = ['localhost' => true, 'localhost:8080' => true, '127.0.0.1' => true, '127.0.0.1:4192' => true,
          '127.1.2.3' => true, '[::1]' => true, '[::1]:8080' => true, 'crm.localhost' => true, 'LOCALHOST' => true,
          'badminton.example.at' => false, 'badminton.example.at:8080' => false, 'localhost.example.at' => false,
          'example.localhost.at' => false, '127.0.0.1.example.at' => false, 'mylocalhost' => false,
          '10.0.0.5' => false, '192.168.1.10' => false, '' => false];
foreach ($hosts as $host => $local)
    is_same($local, install_host_is_local((string)$host), test_show((string)$host).($local ? ' is this computer' : ' is not this computer'));
if (!function_exists('exec')) {
    test_unsupported(array_merge(test_unsupported(), ['the setup page refusing plain HTTP, asked as a browser asks it (this PHP disables exec)']));
} else {
    $freshDir = test_run_dir().'/fresh-'.bin2hex(random_bytes(4));
    mkdir($freshDir, 0700);
    $fresh = $freshDir.'/config.php';
    $install = $firstAccount + ['db_host' => '127.0.0.1', 'db_port' => '1', 'db_name' => 'crm_setup_probe_test',
                                'db_user' => 'crm_setup_probe', 'db_password' => ''];
    $plain = $setupPage($fresh, ['host' => 'badminton.example.at']);
    ok(!str_contains($plain['body'], 'name="admin_password"'), 'opened over http:// on a real address, setup offers no form');
    ok(str_contains($plain['body'], 'HTTPS'), 'it says that HTTPS is what is missing');
    ok(str_contains($plain['body'], 'href="https://badminton.example.at/setup.php"'), 'and links to the same page over https://');
    $sent = $setupPage($fresh, ['host' => 'badminton.example.at', 'method' => 'POST', 'post' => ['app_url' => 'https://badminton.example.at'] + $install]);
    ok(str_contains($sent['body'], 'href="https://badminton.example.at/setup.php"') && !str_contains($sent['body'], 'nicht erreichbar'),
       'a form sent over http:// anyway is refused before the database it names is even tried');
    ok(!is_file($fresh), 'and no configuration is written');
    ok(str_contains($setupPage($fresh, ['host' => 'badminton.example.at', 'https' => true])['body'], 'name="admin_password"'),
       'over https:// the form is there');
    ok(str_contains($setupPage($fresh, ['host' => 'badminton.example.at', 'headers' => ['X-Forwarded-Proto' => 'https']])['body'], 'name="admin_password"'),
       'and when TLS ends at the host\'s proxy, which says so, too');
    ok(str_contains($setupPage($fresh, ['host' => 'localhost:8080'])['body'], 'name="admin_password"'),
       'a test on the computer itself may use http://');
    $typed = $setupPage($fresh, ['host' => 'badminton.example.at', 'https' => true, 'method' => 'POST',
                                 'post' => ['app_url' => 'http://badminton.example.at'] + $install]);
    ok(str_contains($typed['body'], 'muss mit https:// beginnen'), 'an http:// address typed into the form for a real host is refused');
    ok(!is_file($fresh), 'and nothing is written for it either');
    ok(!str_contains($setupPage($fresh, ['host' => 'localhost:8080', 'method' => 'POST',
                                         'post' => ['app_url' => 'http://localhost:8080'] + $install])['body'], 'muss mit https:// beginnen'),
       'while one for this computer is accepted');
}

case_('Sign-in sessions are kept in the portal\'s own folder, and something empties it');
/* PHP's default session folder is the host's: on shared hosting often one folder
   for every customer on the machine, where a neighbour's script can list the
   session files and read or plant one. The portal's own folder sits beside the
   maintenance flag like everything else it stores. Debian and Ubuntu also switch
   PHP's own clean-up off and leave it to a cron job that only knows the default
   folder, so here PHP has to do it - without signing anybody out sooner than the
   portal's own idle limit. A request is started for real, in a process of its
   own, with the host's default set to a folder of the run's and its clean-up
   off, as Debian ships it; the maintenance flag stops it once the session has
   begun, before anything is migrated. */
$sessionProbe = function (string $work) use ($ownDb): string {
    mkdir($work, 0700);
    write_run_config($work.'/config.php', $ownDb, $work);
    file_put_contents($work.'/maintenance.flag', now());
    return $work.'/config.php';
};
$hostDefault = test_run_dir().'/host-sessions-'.bin2hex(random_bytes(4));
mkdir($hostDefault, 0700);
$startRequest = function (string $config, string $then = 'null', string $before = '') use ($hostDefault): array {
    $out = [];
    exec('CRM_CONFIG='.escapeshellarg($config).' '.escapeshellarg(PHP_BINARY).' -d session.save_path='.escapeshellarg($hostDefault)
         .' -d session.gc_probability=0 -d session.gc_maxlifetime=1440 -d error_log= -r '
         .escapeshellarg('$_SERVER["REQUEST_METHOD"] = "GET"; '.$before.' register_shutdown_function(function () { $then = '.$then.'; echo "\n", json_encode('
                         .'["status" => http_response_code(), "path" => session_save_path(), "id" => session_id(), "probability" => (int)ini_get("session.gc_probability"),'
                         .' "divisor" => (int)ini_get("session.gc_divisor"), "lifetime" => (int)ini_get("session.gc_maxlifetime"), "then" => $then]); });'
                         .' require $argv[1]; boot_http();')
         .' '.escapeshellarg(APP_ROOT.'/app/bootstrap.php').' 2>&1', $out, $code);
    $answer = json_decode((string)end($out), true);
    return (is_array($answer) ? $answer : ['status' => 0, 'path' => '', 'id' => '', 'probability' => 0, 'divisor' => 1, 'lifetime' => 0, 'then' => null])
        + ['log' => implode("\n", array_slice($out, 0, -1))];
};
if (!function_exists('exec')) {
    test_unsupported(array_merge(test_unsupported(), ['where a request keeps its session, asked of a request started for real (this PHP disables exec)']));
} else {
    $work = test_run_dir().'/sessions-'.bin2hex(random_bytes(4));
    $started = $startRequest($sessionProbe($work));
    $folder = $work.'/sessions';
    is_same($folder, $started['path'], 'a request keeps its session beside the maintenance flag, not in the host\'s folder'
            .($started['log'] !== '' ? ': '.$started['log'] : ''));
    ok($started['id'] !== '' && is_file($folder.'/sess_'.$started['id']), 'the session file is there');
    ok(!glob($hostDefault.'/sess_*'), 'and nothing was left in the host\'s folder');
    is_same('0700', is_dir($folder) ? sprintf('%04o', fileperms($folder) & 0777) : 'missing', 'the folder is this account\'s alone');
    ok(str_contains((string)@file_get_contents($folder.'/.htaccess'), 'Require all denied'),
       'and refuses the web by a deny file of its own, as the backups do, wherever the maintenance flag has been moved');
    ok($started['probability'] > 0 && $started['divisor'] > 0, 'PHP empties it itself, with the host\'s clean-up switched off');
    ok($started['lifetime'] >= (int)config('session_idle_minutes') * 60,
       'but never a session younger than the portal\'s own idle limit, or it would sign people out early ('.$started['lifetime'].'s)');

    // Two sessions left behind: one long gone, one an hour old - inside the
    // portal's idle limit, and past the host's 24 minutes.
    @touch($folder.'/sess_stale0000000000000000000000000', time() - 2 * 86400);
    @touch($folder.'/sess_recent000000000000000000000000', time() - 3600);
    ok(is_file($folder.'/sess_stale0000000000000000000000000') && is_file($folder.'/sess_recent000000000000000000000000'),
       'both are there before the clean-up, so what follows is measured');
    $swept = $startRequest($work.'/config.php', 'session_gc()');
    ok(!is_file($folder.'/sess_stale0000000000000000000000000'), 'a clean-up removes a session nobody has used for two days');
    ok(is_file($folder.'/sess_recent000000000000000000000000'), 'and keeps one that is an hour old, which the host\'s setting would have removed');
    ok(is_file($folder.'/.htaccess'), 'and the deny file, which is not a session');

    $blockedWork = test_run_dir().'/sessions-'.bin2hex(random_bytes(4));
    $blocked = $sessionProbe($blockedWork);
    file_put_contents($blockedWork.'/sessions', 'a file where the folder would go');
    $fallback = $startRequest($blocked);
    is_same($hostDefault, $fallback['path'], 'where the folder cannot be made, the host\'s folder is used rather than no session at all');
    ok($fallback['id'] !== '' && is_file($hostDefault.'/sess_'.$fallback['id']), 'and sign-in still works');
    ok(str_contains($fallback['log'], $blockedWork.'/sessions'), 'with a warning in the error log naming the folder it could not make');
}

case_('A portal with an https:// address is only ever served over HTTPS');
/* app_url decides, not the web server: a portal set up without a certificate
   keeps working exactly as before an update, which a rule in .htaccess could
   not promise. A page asked for over plain HTTP is sent to the same page on
   app_url's host; a form sent over it is refused rather than followed, because
   it has already crossed the network readable. The request is started for
   real, in a process of its own, as above: a redirect ends it before any session
   begins, and a request that is let through reaches the maintenance page. */
if (!function_exists('exec')) {
    test_unsupported(array_merge(test_unsupported(), ['the redirect to HTTPS, asked of a request started for real (this PHP disables exec)']));
} else {
    $secureWork = test_run_dir().'/https-'.bin2hex(random_bytes(4));
    $secure = $sessionProbe($secureWork);
    file_put_contents($secure, str_replace("'http://127.0.0.1:4192'", "'https://badminton.example.at'", (string)file_get_contents($secure)));
    $plainWork = test_run_dir().'/http-'.bin2hex(random_bytes(4));
    $plainConfig = $sessionProbe($plainWork);
    file_put_contents($plainConfig, str_replace("'http://127.0.0.1:4192'", "'http://badminton.example.at'", (string)file_get_contents($plainConfig)));
    $asking = fn(array $server, array $cookies = []): string
        => '$_SERVER = '.var_export($server + ['HTTP_HOST' => 'badminton.example.at', 'REQUEST_URI' => '/index.php?page=login',
                                                'SERVER_PORT' => 80, 'REQUEST_METHOD' => 'GET'], true).' + $_SERVER;'
           .' $_COOKIE = '.var_export($cookies, true).';';
    $sent = $startRequest($secure, 'null', $asking([]));
    is_same(301, $sent['status'], 'a page asked for over http:// is sent on, permanently'.($sent['log'] !== '' && $sent['status'] !== 301 ? ': '.$sent['log'] : ''));
    is_same('', $sent['id'], 'before a session, and its cookie, is started over plain HTTP');
    is_same(301, $startRequest($secure, 'null', $asking(['REQUEST_METHOD' => 'HEAD']))['status'], 'a HEAD request too');
    $form = $startRequest($secure, 'null', $asking(['REQUEST_METHOD' => 'POST']));
    is_same(403, $form['status'], 'a form sent over http:// is refused, not followed');
    is_same('', $form['id'], 'and nothing of it is acted on');
    foreach (['over HTTPS' => [['HTTPS' => 'on', 'SERVER_PORT' => 443], []],
              'from a proxy that says X-Forwarded-Proto: https' => [['HTTP_X_FORWARDED_PROTO' => 'https'], []],
              'from a proxy that says X-Forwarded-SSL: on' => [['HTTP_X_FORWARDED_SSL' => 'on'], []],
              'by a browser that holds the cookie a redirect leaves' => [[], ['badminton_https' => '1']],
              'on this computer' => [['HTTP_HOST' => 'localhost:8080'], []]] as $what => [$server, $cookies]) {
        $through = $startRequest($secure, 'null', $asking($server, $cookies));
        ok($through['status'] === 503 && $through['id'] !== '', 'a request '.$what.' is served: it reached the maintenance page with a session ('.$through['status'].')');
    }
    $plain = $startRequest($plainConfig, 'null', $asking([]));
    ok($plain['status'] === 503 && $plain['id'] !== '', 'a portal whose address is http:// is served over http:// exactly as before');
    ok($startRequest($plainConfig, 'null', $asking(['REQUEST_METHOD' => 'POST']))['status'] === 503, 'forms included');
}

$app = 'https://badminton.example.at';
$plainGet = ['HTTP_HOST' => 'badminton.example.at', 'REQUEST_URI' => '/index.php?page=login', 'SERVER_PORT' => 80, 'REQUEST_METHOD' => 'GET'];
is_same('https://badminton.example.at/index.php?page=login', https_address($plainGet, [], $app), 'the same page and query, on https://');
is_same('https://badminton.example.at/verein/index.php?page=students&id=4',
        https_address(['REQUEST_URI' => '/verein/index.php?page=students&id=4'] + $plainGet, [], $app.'/verein'), 'in a subdirectory too');
is_same('https://badminton.example.at:8443/index.php', https_address(['REQUEST_URI' => '/index.php'] + $plainGet, [], $app.':8443'),
        'on the port app_url names');
is_same('https://badminton.example.at/index.php', https_address(['HTTP_HOST' => 'www.somewhere-else.example', 'REQUEST_URI' => '/index.php'] + $plainGet, [], $app),
        'always on app_url\'s host, never the one the request named');
foreach (['http://evil.example/x?y=1' => '/x?y=1', '//evil.example/x' => '//evil.example/x', "/index.php\r\nSet-Cookie: x=1" => '/index.phpSet-Cookie:%20x=1', '' => '/']
         as $uri => $path) {
    $to = (string)https_address(['REQUEST_URI' => $uri] + $plainGet, [], $app);
    is_same('badminton.example.at', parse_url($to, PHP_URL_HOST), 'a request line of '.test_show($uri).' cannot move the redirect to another host');
    is_same('https://badminton.example.at'.$path, $to, 'and keeps what it can of the page asked for');
}
foreach (['over HTTPS' => ['HTTPS' => 'on'], 'on port 443' => ['SERVER_PORT' => 443],
          'with X-Forwarded-Proto: https' => ['HTTP_X_FORWARDED_PROTO' => 'https'],
          'with X-Forwarded-SSL: on' => ['HTTP_X_FORWARDED_SSL' => 'on'],
          'with X-Forwarded-Scheme: https' => ['HTTP_X_FORWARDED_SCHEME' => 'https']] as $what => $server)
    is_same(null, https_address($server + $plainGet, [], $app), 'no redirect for a request '.$what);
is_same(null, https_address($plainGet, ['badminton_https' => '1'], $app),
        'nor for one carrying the redirect\'s cookie, which is Secure and so only ever sent over HTTPS: a proxy that hides it gets one redirect per browser, not a loop');
is_same(null, https_address($plainGet, [], 'http://badminton.example.at'), 'nor for a portal whose address is http://');
foreach ($hosts as $host => $local) {
    if ($host === '') continue;
    is_same(!$local, https_address(['HTTP_HOST' => (string)$host] + $plainGet, [], $app) !== null,
            'the redirect agrees with setup about '.test_show((string)$host));
}
is_same('Strict-Transport-Security: max-age=31536000', strict_transport_security(['app_url' => $app, 'secure_cookies' => false]),
        'a portal with an https:// address tells the browser to use nothing else for a year');
is_same('Strict-Transport-Security: max-age=31536000', strict_transport_security(['app_url' => 'http://badminton.example.at', 'secure_cookies' => true]),
        'as one with secure cookies always did');
is_same(null, strict_transport_security(['app_url' => 'http://127.0.0.1:4192', 'secure_cookies' => false]), 'and a local test over http:// is not told so');

case_('The web server does not redirect: the portal does, because only it knows its address');
/* A rule in .htaccess cannot know whether the portal has a certificate, and
   public/.htaccess with rewrite rules would take down a host that forbids them.
   Both stay as they are; nginx keeps its port-80 redirect, because there whoever
   writes the server block also sets up the certificate. */
foreach (['.htaccess', 'public/.htaccess'] as $name)
    ok(!preg_match('~RewriteRule\s+\S+\s+https://~', (string)file_get_contents(APP_ROOT.'/'.$name)), $name.' sends nothing to https:// itself');
ok(!str_contains((string)file_get_contents(APP_ROOT.'/public/.htaccess'), 'RewriteEngine'), 'and public/.htaccess uses no rewrite rules at all');
$nginx = (string)file_get_contents(APP_ROOT.'/docs/nginx.conf.example');
ok(preg_match('~listen 80;.*?return 301 https://\$host\$request_uri;~s', $nginx) === 1, 'the nginx example sends port 80 to HTTPS');
ok(str_contains($nginx, '/.well-known/acme-challenge/'), 'and keeps the certificate\'s renewal reachable over HTTP');
ok(preg_match('~location = /setup\.php \{[^}]*fastcgi_pass~', $nginx) === 1, 'and passes setup.php to PHP, so a portal on nginx can be installed from the browser too');
db()->exec('DROP TABLE IF EXISTS schema_migrations');
run('DELETE FROM accounts');
