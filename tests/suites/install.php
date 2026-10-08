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
foreach (['PHP', 'pdo_mysql', 'mbstring', 'openssl', 'session', 'fileinfo', 'iconv', 'ctype', 'filter', 'config/', 'storage/'] as $needed)
    ok(str_contains($labels, $needed), 'it checks ' . $needed);
// This process is running the suite, so these must be satisfied here by definition.
foreach ($requirements as $check)
    if (in_array($check['fatal'], [true], true) && str_contains($check['label'], 'pdo_mysql'))
        ok($check['ok'], 'pdo_mysql is reported as present, which it is');
ok(count(install_blockers()) <= count($requirements), 'blockers are a subset of the requirements');

case_('A PHP without fileinfo is refused before installing, and handed to the System page by name');
/* Uploads read a file's type from its bytes, and only fileinfo can: without it
   every photo and receipt is refused with a sentence about the file. So setup
   must not install on such a PHP, and extension_checks() must give the System
   page (views/_settings_system.php, which draws whatever it returns as not ok)
   that one to name. Nothing is taken away from the PHP running this: the
   function is given the suite's own answer to which extensions are loaded. */
$withoutFileinfo = fn(string $extension): bool => $extension !== 'fileinfo';
$fileinfoRow = array_values(array_filter(install_requirements([], $withoutFileinfo),
                                         fn($c) => str_contains($c['label'], 'fileinfo')))[0] ?? null;
ok($fileinfoRow !== null && $fileinfoRow['ok'] === false && $fileinfoRow['fatal'] === true,
   'setup lists fileinfo as missing, and as something it does not install without');
ok(in_array($fileinfoRow['label'] ?? null, array_column(install_blockers([], $withoutFileinfo), 'label'), true),
   'so it is among what stops the installation');
ok(str_contains($fileinfoRow['fix'] ?? '', 'fileinfo') && preg_match('/Foto|photo/', $fileinfoRow['fix'] ?? '') === 1,
   'with what it breaks and what to do: ' . ($fileinfoRow['fix'] ?? '(no sentence)'));
is_same(['fileinfo'], array_column(array_filter(extension_checks($withoutFileinfo), fn($c) => !$c['ok']), 'extension'),
        'the System page is given exactly that one to name');
$named = array_values(array_filter(extension_checks($withoutFileinfo), fn($c) => !$c['ok']))[0] ?? ['label' => ['', ''], 'fix' => ['', '']];
ok(str_contains($named['fix'][0], 'Hosting-Panel') && str_contains($named['fix'][1], 'hosting panel'),
   'in German and in English, whichever the administrator reads');
is_same([], array_column(array_filter(extension_checks(), fn($c) => !$c['ok']), 'extension'),
        'and on the PHP running this suite, which has every one, it names none');

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

case_('The migrations are listed from their folder as it is named, a [ in it included [code review]');
/* glob() read a [ in where the portal sits as a pattern: installed in
   „portal[1]" beside a „portal1", it listed the neighbour's migrations, and an
   update would have applied those. */
$own = test_run_dir() . '/portal[1]/database/migrations';
$neighbour = test_run_dir() . '/portal1/database/migrations';
foreach ([$own, $neighbour] as $dir) @mkdir($dir, 0750, true);
foreach ($shipped as $file) copy($file, $own . '/' . basename($file));
file_put_contents($neighbour . '/' . basename($shipped[0]), "-- the neighbour's\n");
is_same(array_map(fn(string $file): string => $own . '/' . basename($file), $shipped), migration_files($own),
        'in „portal[1]" every shipped migration is listed, by its path there, in name order, and nothing from „portal1"');
is_same($fingerprint, schema_fingerprint($own), 'so the fingerprint is the shipped one');
foreach ([$own, $neighbour] as $dir) {
    foreach (dir_entries($dir) as $name) unlink($dir . '/' . $name);
    rmdir($dir);
    rmdir(dirname($dir));
    rmdir(dirname($dir, 2));
}

case_('The copy an unfinished update names is found as named, whatever its folder or its name holds [code review]');
/* glob() read the copy's name, which comes from storage/update-unfinished.json,
   and the folder storage/ sits in as a pattern: a [ in either found a
   neighbour's copy or missed its own, so the closed page said a copy was there
   when it was not, or the other way round. */
$storage = $GLOBALS['config']['maintenance_file'];
$copyIn = function (string $folder, string $name): void {
    @mkdir(test_run_dir() . '/' . $folder . '/backups', 0750, true);
    file_put_contents(test_run_dir() . '/' . $folder . '/backups/' . $name, '-- copy');
};
$named = '2026-10-08-120000-vor-update';
try {
    $GLOBALS['config']['maintenance_file'] = test_run_dir() . '/ablage[2]/maintenance.flag';
    $copyIn('ablage2', $named . '-0000000a.sql');
    is_same(false, schema_copy_exists($named), 'with storage/ in „ablage[2]", the copy in „ablage2" beside it is not taken for its own');
    unlink(test_run_dir() . '/ablage2/backups/' . $named . '-0000000a.sql');
    $copyIn('ablage[2]', $named . '-0000000b.sql');
    is_same(true, schema_copy_exists($named), 'and its own copy is found');
    $GLOBALS['config']['maintenance_file'] = test_run_dir() . '/ablage/maintenance.flag';
    $copyIn('ablage', 'kopie1-0000000c.sql');
    is_same(false, schema_copy_exists('kopie[1]'), 'a record naming „kopie[1]" does not find „kopie1-…"');
    is_same(false, schema_copy_exists('*'), 'nor does one naming „*" find every copy');
    $copyIn('ablage', 'kopie[1]-0000000d.sql');
    is_same(true, schema_copy_exists('kopie[1]'), 'and „kopie[1]" finds „kopie[1]-…" once it is there');
    is_same([false, false], [schema_copy_exists('kopie'), schema_copy_exists(null)],
            'while „kopie", which names neither, finds nothing, and no name at all - an update run with skip-backup - finds nothing');
} finally {
    $GLOBALS['config']['maintenance_file'] = $storage;
}
foreach (['ablage[2]', 'ablage2', 'ablage'] as $folder) {
    $dir = test_run_dir() . '/' . $folder . '/backups';
    foreach (dir_entries($dir) as $name) unlink($dir . '/' . $name);
    @rmdir($dir);
    @rmdir(dirname($dir));
}

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
ok(str_contains($dropped?->de ?? '', 'students (vorher 12, jetzt 0)'), 'and names the table, with its rows before and now');
ok(str_contains($dropped?->en ?? '', 'students (12 before, 0 now)'), 'in English too');
ok(str_contains($dropped?->de ?? '', 'storage/backups'), 'and says where the copy from beforehand is');

case_('Only tables still guarded are compared, and a guarded table that is gone counts as emptied [ADR 0026 §7, 0027 §2]');
/* A table counted before the update that is no longer in schema_guarded_tables()
   is one a release took off the list on purpose, with the reason beside it:
   comparing it would keep closed the portal that release exists to reopen
   (ADR 0027 §5 b). Within one run the list cannot change, so there this skips
   nothing. A table still on the list that cannot be counted has lost every row. */
does_not_throw(fn() => schema_verify_counts(['no_such_table' => 5, 'students' => 0], fn(string $l) => null),
               'a table that is not on the list is not compared, whatever it held before');
ok(in_array('contacts', schema_guarded_tables(), true), 'contacts is on the list');
db()->exec('RENAME TABLE contacts TO contacts_away_for_a_moment');
$gone = null;
try { schema_verify_counts(['contacts' => 3], fn(string $l) => null); } catch (Throwable $e) { $gone = $e; }
finally { db()->exec('RENAME TABLE contacts_away_for_a_moment TO contacts'); }
ok($gone instanceof UpdateBlocked && str_contains($gone->de, 'contacts (vorher 3, jetzt 0)'),
   'and with contacts gone, three contacts counted before are three lost, rather than a table skipped');

case_('A refusal during an unfinished update names the versions, the copy and the way back [ADR 0027 §4]');
$now = schema_counts();
$record = ['started' => '2026-10-07 12:32:10', 'from' => '0.5.2', 'to' => '0.6.0', 'backup' => '2026-10-07-123210-vor-update',
           'counts' => ['contacts' => ($now['contacts'] ?? 0) + 120, 'students' => $now['students'] ?? 0]];
$refusal = function (array $record): ?UpdateBlocked {
    try { schema_verify_counts($record['counts'], fn(string $l) => null, $record); } catch (UpdateBlocked $e) { return $e; }
    return null;
};
$lost = $refusal($record);
$was = $record['counts']['contacts'];
$is = $now['contacts'] ?? 0;
is_same('Nach der Aktualisierung auf Version 0.6.0 fehlen Datensätze: contacts (vorher ' . $was . ', jetzt ' . $is . '). Deshalb bleibt das Portal geschlossen.'
        . ' So kommen sie zurück: zuerst die Dateien von Version 0.5.2 wieder hochladen, dann die Sicherung „2026-10-07-123210-vor-update-…“ aus dem Ordner storage/backups einspielen,'
        . ' wie INSTALL.md unter „Wiederherstellen“ beschreibt. Beim nächsten Aufruf zählt das Portal nach und öffnet sich, wenn nichts mehr fehlt.',
        $lost?->de, 'in German: which table lost how many, only the one that fell, and the order of the way back');
is_same('Records are missing after the update to version 0.6.0: contacts (' . $was . ' before, ' . $is . ' now). That is why the portal stays closed.'
        . ' To bring them back: first upload the files of version 0.5.2 again, then import the copy “2026-10-07-123210-vor-update-…” from the storage/backups folder,'
        . ' as INSTALL.md describes under „Wiederherstellen“. On the next page view the portal counts again and opens once nothing is missing.',
        $lost?->en, 'and in English');
$log = $lost?->getMessage() ?? '';
ok(str_contains($log, schema_unfinished_file()) && str_contains($log, '2026-10-07 12:32:10 UTC'), 'the log line names the file’s full path and when the update began');
ok(str_contains($log, 'storage/backups/2026-10-07-123210-vor-update-*.sql') && str_contains($log, 'off schema_guarded_tables()')
   && str_contains($log, 'delete that file'), 'and the three ways back');
$skipped = $refusal(['backup' => null] + $record);
ok(str_contains($skipped?->de ?? '', 'dann die Sicherung, die vor der Aktualisierung im Hosting-Panel angelegt wurde, einspielen, wie INSTALL.md'),
   'an update run with skip-backup names the copy exported from the hosting panel instead');
ok(str_contains($skipped?->en ?? '', 'then import the copy exported from the hosting panel before the update, as INSTALL.md'), 'in English too');
$unknown = $refusal(['from' => ''] + $record);
ok(str_contains($unknown?->de ?? '', 'zuerst die Dateien der vorherigen Version wieder hochladen'), 'without the version it came from, it says the previous version');
ok(str_contains($unknown?->en ?? '', 'first upload the files of the previous version again'), 'in English too');

case_('The numbers from before an update are kept in a file, and only a run that passes deletes it [ADR 0027 §1]');
@unlink(schema_unfinished_file());
is_same(false, schema_is_unfinished(), 'without the file no update is unfinished');
is_same(null, schema_unfinished(), 'and there is no record to read');
$version = one("SELECT setting_value FROM settings WHERE setting_key='schema_written_by'");
set_setting('schema_written_by', '0.5.2');
setting_cache_clear();
// A copy is of a portal with something in it: backup_database() refuses a
// database that holds nothing (ADR 0029 §3), and here the ledger is gone and
// the guarded tables are empty, so one row stands in for the families.
$someone = make_account();
$copy = backup_database('vor-update');
run('DELETE FROM accounts WHERE id=?', [$someone]);
$written = schema_mark_unfinished(['contacts' => 3, 'students' => 2], $copy);
$text = (string)file_get_contents(schema_unfinished_file());
is_same(true, schema_is_unfinished(), 'written, an update is unfinished');
is_same($written, schema_unfinished(), 'and the record reads back exactly as it was written');
is_same(['0.5.2', app_version()], [$written['from'], $written['to']], 'naming the version the database was on, and the version of the files');
is_same(['contacts' => 3, 'students' => 2], $written['counts'], 'with the counts it was given');
ok(preg_match('/^' . preg_quote((string)$written['backup'], '/') . '-([0-9a-f]{8})\.sql$/D', basename($copy), $random) === 1,
   'and the copy’s name without its random part and extension: ' . test_show($written['backup']));
ok(isset($random[1]) && !str_contains($text, $random[1]), 'which appears nowhere in the file');
ok(str_contains($text, 'UPDATING.md') && str_contains($text, '„A refused update“'), 'a note in it says, in German and English, where to read what to do');
is_same(false, schema_is_current(), 'while it is there the portal is not current, whatever the stamp and the settings say');
schema_mark_finished();
is_same(false, schema_is_unfinished(), 'the end of a run that passes deletes it');
does_not_throw(fn() => schema_mark_finished(), 'and there being none to delete is no error');
is_same(null, schema_mark_unfinished(['contacts' => 3], null)['backup'], 'an update run with skip-backup names no copy');
schema_mark_finished();
@unlink($copy);
if ($version === null) run("DELETE FROM settings WHERE setting_key='schema_written_by'");
else run("UPDATE settings SET setting_value=? WHERE setting_key='schema_written_by'", [$version['setting_value']]);
setting_cache_clear();

case_('Without a record nothing is migrated, and a record that cannot be read refuses [ADR 0027 §1]');
mkdir(schema_unfinished_file());
throws(fn() => schema_mark_unfinished(['contacts' => 3], null), 'a record whose place a folder takes cannot be written, and the update is refused', 'Cannot write');
ok(!is_file(schema_unfinished_file() . '.part'), 'with nothing half-written left beside it');
rmdir(schema_unfinished_file());
$shape = ['started' => '2026-10-07 12:32:10', 'from' => '0.5.2', 'to' => '0.6.0', 'backup' => null, 'counts' => ['contacts' => 3]];
foreach (['{' => 'half a JSON object', '[]' => 'an empty list', '"contacts"' => 'a string',
          json_encode(['counts' => 'alle Kontakte'] + $shape) => 'counts that are a sentence',
          json_encode(['counts' => [3, 2]] + $shape) => 'counts without table names',
          json_encode(['counts' => ['contacts' => -1]] + $shape) => 'a count below nothing',
          json_encode(['counts' => ['contacts' => 2.5]] + $shape) => 'a count that is not a whole number',
          json_encode(['from' => 52] + $shape) => 'a version that is a number',
          json_encode(array_diff_key($shape, ['backup' => 1])) => 'no word on the copy at all'] as $broken => $what) {
    file_put_contents(schema_unfinished_file(), $broken);
    throws(fn() => schema_unfinished(), 'refused, rather than read as no record: ' . $what, 'Cannot read');
}
file_put_contents(schema_unfinished_file(), json_encode($shape));
does_not_throw(fn() => schema_unfinished(), 'while the same shape, whole, is read');
@unlink(schema_unfinished_file());

case_('The closed page tells a family there is nothing to do, and whoever looks after the portal what happened [ADR 0027 §4]');
$page = schema_blocked_html(new UpdateBlocked('Grund <script>alert(1)</script>', 'Reason <script>alert(2)</script>', 'SQLSTATE[42S02]: for the log only'));
foreach (['Das Portal ist vorübergehend geschlossen.', 'Du musst nichts tun. Bitte versuche es später noch einmal.',
          'Für die Person, die das Portal betreut: ', 'The portal is temporarily closed.',
          'There is nothing you need to do. Please try again later.', 'For whoever looks after the portal: '] as $line)
    ok(str_contains($page, $line), 'it says ' . test_show($line));
ok(strpos($page, 'Das Portal ist') < strpos($page, 'The portal is'), 'German first');
ok(!str_contains($page, '<script>') && str_contains($page, 'Grund &lt;script&gt;alert(1)&lt;/script&gt;')
   && str_contains($page, 'Reason &lt;script&gt;alert(2)'), 'a reason carrying markup is printed as text, in both languages');
ok(!str_contains($page, 'SQLSTATE'), 'and the log line, which may carry SQL, is not on it');
ok(str_contains($page, '<meta http-equiv="refresh" content="300">'), 'a tab left open asks again after the five minutes Retry-After names, so the portal reopens in it by itself');
ok(str_contains($page, '<meta name="color-scheme" content="light dark">'), 'and the page follows the phone\'s light or dark setting');
$lostPage = schema_blocked_html($lost ?? new RuntimeException('none'));
ok(str_contains($lostPage, 'contacts (vorher ' . $was . ', jetzt ' . $is . ')') && str_contains($lostPage, 'contacts (' . $was . ' before, ' . $is . ' now)')
   && str_contains($lostPage, '„2026-10-07-123210-vor-update-…“'), 'a loss is named on it with its counts and its copy, in both languages');
$failed = schema_blocked_html(new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'crm_live'@'localhost'"));
ok(!str_contains($failed, 'SQLSTATE') && !str_contains($failed, 'crm_live'), 'a database error is not quoted at all');
ok(str_contains($failed, 'Fehlerprotokoll') && str_contains($failed, 'error log'), 'the page points at the hosting error log instead');

case_('A copy that could not be taken is reported on the closed page without the path or the database error its BackupError carries [security finding 5]');
// backup_database()'s message names the folder of copies, whose path on shared
// hosting holds the hosting account's name, or carries the database's own
// error; the page is public, so the message goes to the log alone.
$refusal = schema_backup_failed(new BackupError('Cannot create /secret/path'));
$page = schema_blocked_html($refusal);
ok(!str_contains($page, '/secret/path'), 'the path the BackupError names is not on the page');
ok(str_contains($page, 'skip-backup') && str_contains($page, 'Fehlerprotokoll') && str_contains($page, 'error log'),
   'what to do is, in both languages, and where the reason is');
ok(str_contains($refusal->getMessage(), 'Cannot create /secret/path'), 'and the log line keeps the reason');
// The runner's own refusal, from a folder of copies that cannot be made because
// a file has its name: the path is on the log line and nowhere on the page. The
// ledger and the logins are put back as they were.
$keptFlag = $GLOBALS['config']['maintenance_file'];
$elsewhere = test_run_dir() . '/no-copies-' . bin2hex(random_bytes(4));
mkdir($elsewhere, 0700);
file_put_contents($elsewhere . '/backups', 'a file where the folder of copies belongs');
$ledger = test_has_table('schema_migrations') ? rows('SELECT version, checksum, applied_at FROM schema_migrations') : null;
$someone = make_account();   // a portal with something in it, so a copy is due (ADR 0029 §3)
$said = null;
try {
    $GLOBALS['config']['maintenance_file'] = $elsewhere . '/maintenance.flag';
    db()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL)');
    db()->exec('DELETE FROM schema_migrations');   // every migration pending
    try { schema_refuse_unsafe(fn(string $l) => null); } catch (Throwable $e) { $said = $e; }
} finally {
    $GLOBALS['config']['maintenance_file'] = $keptFlag;
    db()->exec('DELETE FROM schema_migrations');
    if ($ledger === null) db()->exec('DROP TABLE schema_migrations');
    else foreach ($ledger as $row) run('INSERT INTO schema_migrations (version, checksum, applied_at) VALUES (?, ?, ?)', [$row['version'], $row['checksum'], $row['applied_at']]);
    run('DELETE FROM accounts WHERE id = ?', [$someone]);
}
ok($said instanceof UpdateBlocked && str_contains($said->getMessage(), $elsewhere . '/backups'),
   'the runner refuses the update, and its log line names the folder it could not make' . ($said && !$said instanceof UpdateBlocked ? ': ' . $said->getMessage() : ''));
ok(!str_contains(schema_blocked_html($said ?? new RuntimeException('none')), $elsewhere), 'while the closed page names no part of that path');

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

case_('A skip-backup or a record the portal cannot delete is refused, not taken as done');
/* A skip-backup that stays would skip the copy before every later update, and a
   record that stays would refuse a later update for rows removed on purpose
   since. Each in a folder of its own, beside the maintenance flag as the real
   ones are, made so the portal cannot delete it: for anybody but root a folder
   without write permission does that; root it does not stop, so for root the
   file is made immutable (chattr +i), where the file system allows. Returns how
   to undo that, or null where this run can do neither. */
$keptFlag = $GLOBALS['config']['maintenance_file'];
$undeletable = function (string $name): ?Closure {
    $folder = test_run_dir().'/locked-'.bin2hex(random_bytes(4));
    mkdir($folder, 0700);
    $GLOBALS['config']['maintenance_file'] = $folder.'/maintenance.flag';
    file_put_contents($folder.'/'.$name, '{}');
    file_put_contents($folder.'/probe', '');
    chmod($folder, 0500);
    if (!@unlink($folder.'/probe')) return fn() => chmod($folder, 0700);
    chmod($folder, 0700);
    if (!function_exists('exec')) return null;
    exec('chattr +i '.escapeshellarg($folder.'/'.$name).' 2>&1', $out, $code);
    return $code === 0 ? fn() => exec('chattr -i '.escapeshellarg($folder.'/'.$name).' 2>&1') : null;
};
$unprotected = [];
$undo = $undeletable('skip-backup');
try {
    if ($undo === null) $unprotected[] = 'a skip-backup the portal cannot delete';
    else {
        throws(fn() => backup_override_claimed(), 'a skip-backup the portal cannot delete is not claimed', 'Cannot remove');
        // An empty ledger, so that every migration is pending.
        db()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL)');
        $said = null;
        try { schema_refuse_unsafe(fn(string $l) => null); } catch (Throwable $e) { $said = $e; }
        // Where the whole folder is read-only, the record's place is refused
        // first, with the same remedy.
        ok($said instanceof UpdateBlocked && str_contains($said->de, '(755)') && str_contains($said->en, '(755)'),
           'with something pending the update is refused rather than run without a copy, saying the storage folder must be writable'
           .($said && !$said instanceof UpdateBlocked ? ': '.$said->getMessage() : ''));
        ok(is_file(backup_override_file()), 'and the file is still there, still meaning one update');
    }
} finally {
    if ($undo) $undo();
    $GLOBALS['config']['maintenance_file'] = $keptFlag;
}
$undo = $undeletable('update-unfinished.json');
try {
    if ($undo === null) $unprotected[] = 'a record the portal cannot delete';
    else throws(fn() => schema_mark_finished(), 'a record the portal cannot delete refuses the run that passed', 'cannot be deleted');
} finally {
    if ($undo) $undo();
    $GLOBALS['config']['maintenance_file'] = $keptFlag;
}
if ($unprotected)
    test_unsupported(array_merge(test_unsupported(), [implode(' and ', $unprotected).' (this run can make no file undeletable: root, and no chattr)']));

case_('A copy names the release whose database it holds, not the one about to replace it [devops review]');
/* The copy before an update holds the previous release's tables; its first
   line named the release about to replace them, and a restore went looking for
   the wrong files. */
$writtenBy = (string)setting('schema_written_by');
$someone = make_account();   // a copy is of a portal with something in it (ADR 0029 §3)
foreach (['0.5.9-beta.1' => 'the release that wrote the database', '' => 'and a database no release has written yet, this one'] as $wrote => $what) {
    set_setting('schema_written_by', $wrote);
    $copy = backup_database('vor-update');
    is_same('-- Badminton CRM '.($wrote !== '' ? $wrote : app_version()).' — vor-update', strtok((string)file_get_contents($copy), "\n"),
            'the first line names '.$what);
    unlink($copy);
}
run('DELETE FROM accounts WHERE id=?', [$someone]);
set_setting('schema_written_by', $writtenBy);

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

case_('The copies are listed and pruned in their own folder, whatever it is called [code review]');
/* glob() read a [ in where storage/ sits as a pattern: with storage/ in
   „sicherung[1]" beside a „sicherung1", backups() listed the neighbour's copies,
   backup_prune() deleted those beyond five and never its own, and a stale
   half-written copy stayed. dir_entries() lists the folder as it is named. */
$storage = $GLOBALS['config']['maintenance_file'];
$own = test_run_dir().'/sicherung[1]/backups';
$neighbour = test_run_dir().'/sicherung1/backups';
// The neighbour's copies have names of their own, so a listing of the wrong
// folder cannot pass for the right one by finding the same names there.
foreach ([$own => 'kopie', $neighbour => 'nachbar'] as $dir => $slug) {
    @mkdir($dir, 0750, true);
    for ($i = 0; $i <= BACKUP_KEEP; $i++) {
        file_put_contents($dir.'/2026-01-0'.($i + 1).'-000000-'.$slug.'-0000000'.$i.'.sql', '-- copy '.$i);
        touch($dir.'/2026-01-0'.($i + 1).'-000000-'.$slug.'-0000000'.$i.'.sql', time() - 3600 * (10 - $i));
    }
    file_put_contents($dir.'/2026-01-01-000000-'.$slug.'-halb.sql.part', '--');
    touch($dir.'/2026-01-01-000000-'.$slug.'-halb.sql.part', time() - 7200);
}
$GLOBALS['config']['maintenance_file'] = test_run_dir().'/sicherung[1]/maintenance.flag';
try {
    is_same(BACKUP_KEEP + 1, count(backups()), 'with storage/ in „sicherung[1]", its own copies are listed');
    is_same('2026-01-0'.(BACKUP_KEEP + 1).'-000000-kopie-0000000'.BACKUP_KEEP.'.sql', backups()[0]['name'] ?? null, 'the newest first');
    backup_prune();
    is_same(BACKUP_KEEP, count(backups()), 'and its own are pruned to the newest '.BACKUP_KEEP);
} finally {
    $GLOBALS['config']['maintenance_file'] = $storage;
}
clearstatcache();
ok(!is_file($own.'/2026-01-01-000000-kopie-00000000.sql') && !is_file($own.'/2026-01-01-000000-kopie-halb.sql.part'),
   'the oldest copy and the stale half-written one in its own folder went');
is_same(BACKUP_KEEP + 1, count(dir_entries($neighbour, '.sql')), 'while every copy in „sicherung1" beside it is still there');
ok(is_file($neighbour.'/2026-01-01-000000-nachbar-halb.sql.part'), 'and so is its half-written one');
foreach ([$own, $neighbour] as $dir) {
    foreach (dir_entries($dir) as $name) unlink($dir.'/'.$name);
    rmdir($dir);
    rmdir(dirname($dir));
}

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
ok(!str_contains($stopped->de.$stopped->en, 'storage/backups'), 'told nothing about a copy, it sends nobody looking for one');
$copied = new SchemaError('007_example.sql', 4, 12, 'ALTER TABLE students ADD COLUMN x INT', 'Duplicate column name', copied: true);
ok(str_contains($copied->de, 'Die Sicherung von vorher liegt im Ordner storage/backups.') && str_contains($copied->en, 'The copy taken beforehand is in storage/backups.'),
   'told the copy is there, it says where');

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
// The cases above dropped the ledger on purpose; a tick runs only on a portal
// whose ledger records its migrations (ADR 0029 §1 (b), tick_work()).
test_ledger_recorded();
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

case_('The background work lets go of the browser’s session before it begins');
/* The same phone's next request - the club's stylesheet, the next page - waits
   for the session the last one holds, and the work after a page view held it to
   the end: the stylesheet waited 8.1 s behind an 8-second mail stall, and 2 min
   15 s behind a mail server that never answered. Asked in a process of its own
   (tests/background-request.php), because PHP starts a session only before
   anything is printed. */
if (!function_exists('exec')) {
    test_unsupported(array_merge(test_unsupported(), ['the session let go before the background work (this PHP disables exec)']));
} else {
    $work = test_run_dir().'/background-'.bin2hex(random_bytes(4));
    mkdir($work, 0700);
    write_run_config($work.'/config.php', config('db'), $work);
    $out = [];
    exec('CRM_CONFIG='.escapeshellarg($work.'/config.php').' '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(TEST_ROOT.'/background-request.php').' 2>&1', $out);
    $answer = json_decode((string)end($out), true);
    $answer = is_array($answer) ? $answer : ['output' => implode("\n", $out)];
    is_same('', $answer['let_go'] ?? null, 'the session is saved and let go before the run is even stamped, before any of the work');
    ok(str_contains((string)($answer['written'] ?? ''), 'written by the page'), 'with what the page put in it');
    ok((string)($answer['stamped'] ?? '') !== '', 'and the work then ran');
    setting_cache_clear();
}

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
    $head = (string)strstr($page['body'], '</head>', true);
    is_same(1, substr_count($head, 'name="color-scheme"'), 'its head says once how it may be drawn');
    ok(str_contains($head, '<meta name="color-scheme" content="light dark">')
       && strpos($head, 'name="color-scheme"') < (int)strpos($head, '<link rel="stylesheet"'),
       'light and dark alike, before the stylesheet, so a phone in dark mode does not draw it white first');
    /* The first page the owner sees wears the portal's mark too (C16a): the
       shuttlecock, as the file the browser tab shows, because setup.php has no
       icon() - and no letter, which app.css no longer draws. */
    $setupMark = preg_match('~<header class="public-header">.*?<span class="brand-mark[^"]*">(.*?)</span>~s', $page['body'], $drawn) ? $drawn[1] : null;
    ok($setupMark !== null && trim(strip_tags($setupMark)) === '' && preg_match('~^<img src="assets/favicon\.svg\?v=[0-9a-f]+" alt=""~', $setupMark) === 1,
       'its header shows the shuttlecock, with no letter in the mark');
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

case_('The setup page is sent with the same security headers as the portal [security audit]');
/* setup.php answers before there is a configuration to boot from, so it never
   ran boot_http() and was sent without a Content-Security-Policy, a refusal to
   be framed, a Referrer-Policy or nosniff. Both now send security_headers().
   Asked of a real web server, because the command line keeps no headers:
   setup.php over a configuration with an administrator, which answers 403 -
   what anybody finds there after the install - and the portal's sign-in page
   from the same server, so that neither caller can drop the list unnoticed. */
$listed = [];
foreach (security_headers() as $line) $listed[strtolower((string)strstr($line, ':', true))] = $line;
foreach (['content-security-policy', 'x-frame-options', 'referrer-policy', 'x-content-type-options'] as $needed)
    ok(isset($listed[$needed]), 'the list has '.$needed);
ok(str_contains($listed['content-security-policy'] ?? '', "frame-ancestors 'none'"), 'and its policy refuses every frame');
if (!function_exists('proc_open')) {
    test_unsupported(array_merge(test_unsupported(), ['the security headers of the setup page, asked of a real web server (this PHP disables proc_open)']));
} else {
    $web = test_run_dir().'/headers-'.bin2hex(random_bytes(4));
    mkdir($web.'/sessions', 0700, true);
    write_run_config($web.'/config.php', $ownDb, $web);
    // The run's schema is made from the migration files: the portal is told it is current.
    file_put_contents($web.'/schema.stamp', schema_state());
    // The server's folder is the run's own; the two pages are reached through
    // this one line, as the robustness suite reaches the portal.
    file_put_contents($web.'/router.php', '<?php require parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH) === "/setup.php" ? '
        .var_export(realpath(APP_ROOT.'/public/setup.php'), true).' : '.var_export(realpath(APP_ROOT.'/public/index.php'), true).";\n");
    $headersAdmin = make_account(['role' => 'admin', 'email' => 'kopfzeilen@example.test']);
    $server = null; $port = 0;
    for ($try = 0; $try < 5 && $server === null; $try++) {
        $port = random_int(20000, 60999);
        $started = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=', '-S', '127.0.0.1:'.$port, $web.'/router.php'],
                             [0 => ['file', '/dev/null', 'r'], 1 => ['file', $web.'/server.log', 'a'], 2 => ['file', $web.'/server.log', 'a']],
                             $pipes, $web, ['CRM_CONFIG' => $web.'/config.php'] + getenv());
        if (!is_resource($started)) continue;
        for ($wait = 0; $wait < 60; $wait++) {
            if ($socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1)) { fclose($socket); $server = $started; break; }
            if (!proc_get_status($started)['running']) break;
            usleep(50000);
        }
        if ($server === null) proc_terminate($started);
    }
    if ($server === null) {
        ok(false, 'php -S answers for the setup page: '.trim(substr((string)@file_get_contents($web.'/server.log'), -300)));
    } else {
        try {
            foreach (['/setup.php' => ['the setup page', 403], '/index.php?page=login' => ['the portal\'s sign-in page', 200]] as $path => [$what, $status]) {
                $body = @file_get_contents('http://127.0.0.1:'.$port.$path, false,
                                           stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30]]));
                $answer = $http_response_header ?? [];
                preg_match('~^HTTP/\S+ (\d{3})~', $answer[0] ?? '', $m);
                is_same($status, (int)($m[1] ?? 0), $what.' answers '.$status);
                is_same([], array_values(array_diff(security_headers(), array_map('trim', array_slice($answer, 1)))),
                        $what.' is sent with every security header on the list');
            }
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }
    run('DELETE FROM accounts WHERE id=?', [$headersAdmin]);
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

/* What an unfinished update looks like on disk, for a request or the console
   started in a folder of their own: counts far above anything in this run's
   database, so that a run comparing with them refuses before it writes. */
$unfinishedRecord = fn(): string => (string)json_encode(['started' => now(), 'from' => '0.5.2', 'to' => app_version(),
    'backup' => '2026-10-07-123210-vor-update', 'counts' => ['accounts' => 1000000, 'students' => 0]]);

case_('Nobody gets in through maintenance mode while an update is unfinished, an administrator included [ADR 0027 §3]');
/* Maintenance mode lets an administrator in, so she can switch it off without a
   shell. While an update is unfinished, what she added would change the numbers
   the update compares, and the way back is the file manager anyway. Asked of a
   request started for real, signed in as an administrator by her session. */
if (!function_exists('exec')) {
    test_unsupported(array_merge(test_unsupported(), ['an administrator kept out by an unfinished update, asked of a request started for real (this PHP disables exec)']));
} else {
    $work = test_run_dir().'/unfinished-'.bin2hex(random_bytes(4));
    $config = $sessionProbe($work);
    $admin = make_account(['role' => 'admin', 'email' => 'verwaltung.wartung@example.test']);
    mkdir($work.'/sessions', 0700);
    $sid = bin2hex(random_bytes(16));
    file_put_contents($work.'/sessions/sess_'.$sid, 'user_id|i:'.$admin.';auth_version|i:1;last_seen|i:'.time().';');
    $asAdmin = '$_COOKIE = '.var_export(['badminton_session' => $sid], true).';';
    file_put_contents($work.'/update-unfinished.json', $unfinishedRecord());
    $held = $startRequest($config, 'is_admin()', $asAdmin);
    is_same(503, $held['status'], 'with the record and the maintenance flag, the administrator\'s request is answered 503'.($held['status'] !== 503 ? ': '.$held['log'] : ''));
    ok(str_contains($held['log'], 'Das Portal wird gerade aktualisiert.'), 'with the maintenance notice');
    is_same(true, $held['then'], 'although the session is the administrator\'s: it is the unfinished update that keeps her out');
    unlink($work.'/maintenance.flag');
    $closed = $startRequest($config, 'null', $asAdmin);
    is_same(503, $closed['status'], 'without the flag, the same request runs the update, which refuses: 503'.($closed['status'] !== 503 ? ': '.$closed['log'] : ''));
    ok(str_contains($closed['log'], 'Für die Person, die das Portal betreut: Nach der Aktualisierung auf Version '.app_version().' fehlen Datensätze: accounts (vorher 1000000,'),
       'with the closed page, naming what is missing');
    file_put_contents($work.'/maintenance.flag', now());
    unlink($work.'/update-unfinished.json');
    $in = $startRequest($config, 'is_admin()', $asAdmin);
    ok($in['status'] !== 503 && $in['then'] === true, 'without the record, the same administrator gets in through maintenance mode'.($in['status'] === 503 ? ': '.$in['log'] : ''));
}

case_('The console writes nothing while an update is unfinished [ADR 0027 §3]');
/* Only check, status, migrate, update, maintenance:on and maintenance:off run;
   everything else stops with one sentence on STDERR and exit code 1, as an
   allowlist, so a command added later is refused too. billing:run is the one a
   cron job runs on the 1st: here it has a child to charge. */
if (!function_exists('proc_open')) {
    test_unsupported(array_merge(test_unsupported(), ['the console refusing to write during an unfinished update (this PHP disables proc_open)']));
} else {
    $work = test_run_dir().'/console-'.bin2hex(random_bytes(4));
    mkdir($work, 0700);
    write_run_config($work.'/config.php', $ownDb, $work);
    // Nothing to read on its input, so a command that asks - create-admin - ends
    // rather than waiting for somebody to type.
    $console = function (string ...$arguments) use ($work): array {
        $process = proc_open(array_merge([PHP_BINARY, APP_ROOT.'/bin/console.php'], $arguments), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                             $pipes, null, ['CRM_CONFIG' => $work.'/config.php'] + getenv());
        fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return ['out' => $out, 'err' => $err, 'code' => proc_close($process)];
    };
    $course = make_class();
    $child = make_student();
    $tariff = make_tariff(['class_id' => $course]);
    make_enrolment($course, $child, ['tariff_id' => $tariff]);
    $charges = fn(): int => (int)scalar('SELECT COUNT(*) FROM charges');
    $before = $charges();
    file_put_contents($work.'/update-unfinished.json', $unfinishedRecord());
    $refused = $console('billing:run', '2026-09');
    is_same(1, $refused['code'], 'billing:run stops with exit code 1');
    is_same(['An update is unfinished ('.$work.'/update-unfinished.json), so until it has passed only check, status, migrate, update, maintenance:on and maintenance:off run; UPDATING.md, "A refused update", says what to do.'],
            explode("\n", trim($refused['err'])), 'with one sentence on STDERR');
    is_same($before, $charges(), 'and adds no charge');
    foreach (['mail:work', 'backup', 'maintenance', 'demo:fill', 'create-admin', 'a-command-added-later'] as $command) {
        $other = $console($command);
        ok($other['code'] === 1 && str_starts_with($other['err'], 'An update is unfinished'), $command.' is refused the same way'.($other['code'] !== 1 ? ': '.$other['out'] : ''));
    }
    $status = $console('status');
    ok(str_contains($status['out'], 'files    '.app_version()) && str_contains($status['out'], 'state'), 'status runs'.($status['err'] !== '' ? ': '.$status['err'] : ''));
    // check is what somebody runs when an update has lost a table: it names the
    // table in a sentence rather than stopping on the engine's error.
    $healthy = $console('check');
    is_same([0, ''], [$healthy['code'], $healthy['err']], 'check runs, and finds nothing missing');
    db()->exec('RENAME TABLE contacts TO contacts_away_for_a_moment');
    try { $short = $console('check'); }
    finally { db()->exec('RENAME TABLE contacts_away_for_a_moment TO contacts'); }
    is_same([1, ['The database is missing the table contacts; UPDATING.md, "A refused update", says how to bring it back.']],
            [$short['code'], explode("\n", trim($short['err']))], 'with contacts gone, check exits 1 naming it in one sentence');
    $report = json_decode($short['out'], true);
    ok(is_array($report) && array_key_exists('contacts', $report['rows'] ?? []) && $report['rows']['contacts'] === null
       && is_int($report['rows']['students'] ?? null), 'and still reports every other table, with contacts as null');
    ok(!str_contains($short['out'].$short['err'], 'SQLSTATE'), 'and no engine error');
    unlink($work.'/update-unfinished.json');
    $charged = $console('billing:run', '2026-09');
    ok($charged['code'] === 0 && $charges() > $before, 'without the record the same command charges the child, so it was the record that held it back'
       .($charged['code'] !== 0 ? ': '.$charged['err'] : ''));
    $login = (int)scalar('SELECT account_id FROM students WHERE id=?', [$child]);
    run('DELETE FROM charges WHERE student_id=?', [$child]);
    run('DELETE FROM class_students WHERE student_id=?', [$child]);
    run('DELETE FROM students WHERE id=?', [$child]);
    run('DELETE FROM accounts WHERE id=?', [$login]);
    run('DELETE FROM tariffs WHERE id=?', [$tariff]);
    run('DELETE FROM classes WHERE id=?', [$course]);
}

/* ADR 0029: a copy being restored. The marker is the table its first statement
   makes and its last drops; the portal itself never touches it, so the tests
   make and drop it here. An old proof file, in the suite's own upload folder,
   stands for every upload whose row has not arrived yet. */
$marker = fn(bool $there) => db()->exec($there ? 'CREATE TABLE IF NOT EXISTS `'.IMPORT_UNFINISHED_TABLE.'` (importing TINYINT NULL) ENGINE=InnoDB'
                                               : 'DROP TABLE IF EXISTS `'.IMPORT_UNFINISHED_TABLE.'`');
$oldProof = function (string $dir): string {
    @mkdir($dir, 0700, true);
    $path = $dir.'/'.bin2hex(random_bytes(16)).'.jpg';
    file_put_contents($path, 'x');
    touch($path, time() - 7200);
    return $path;
};

case_('The sweep deletes nothing while a copy is being restored, whoever calls it [ADR 0029 §3, test 7]');
$keeper = make_account(['role' => 'admin', 'email' => 'kehrt.nicht@example.test']);
$proof = $oldProof(upload_dir('proof'));
$marker(true);
try {
    ok(schema_restore_refusal() !== null, 'with the marker there is a reason');
    is_same(0, prune_uploads(), 'and the sweep returns 0');
    clearstatcache();
    ok(is_file($proof), 'the old proof file, which no row names, is still there');
} finally { $marker(false); }
ok(schema_restore_refusal() === null, 'without the marker the reason is gone');
is_same(1, prune_uploads(), 'and the same sweep removes the file: it was the reason that held it back');
run('DELETE FROM accounts WHERE id=?', [$keeper]);
// The other state the sweep can meet: no table at all, and the stamp present.
// That needs an empty database, so it is asked of the second one, in a
// process of its own, with a stamp in a folder of its own.
if ($noTables === '' || !function_exists('exec')) {
    test_unsupported(array_merge(test_unsupported(), ['the sweep on an empty database over a used folder (needs CRM_MIGRATION_CONFIG and exec)']));
} else {
    $emptyDb = (require $noTables)['db'];
    $used = test_run_dir().'/used-folder-'.bin2hex(random_bytes(4));
    mkdir($used, 0700);
    write_run_config($used.'/config.php', $emptyDb, $used);
    file_put_contents($used.'/schema.stamp', 'a run passed here once');
    $orphan = $oldProof($used.'/uploads/proof');
    $out = [];
    exec('CRM_CONFIG='.escapeshellarg($used.'/config.php').' '.escapeshellarg(PHP_BINARY).' -d error_log= -r '
         .escapeshellarg('require $argv[1]; echo json_encode(["reason" => schema_restore_refusal()?->getMessage(), "removed" => prune_uploads()]);')
         .' '.escapeshellarg(APP_ROOT.'/app/bootstrap.php').' 2>&1', $out, $code);
    $swept = json_decode((string)end($out), true) ?: [];
    ok(str_contains((string)($swept['reason'] ?? ''), $used.'/schema.stamp'), 'an empty database over a used folder has a reason naming the stamp'.($swept ? '' : ': '.implode("\n", $out)));
    is_same(0, $swept['removed'] ?? null, 'and the sweep there returns 0');
    ok(is_file($orphan), 'leaving the old proof file');
    is_same(0, (int)install_connect($emptyDb)->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn(),
            'and the empty database still has no table');
}

case_('The console runs only what reads while a copy is being restored, and maintenance honours the flag [ADR 0029 §3, test 8]');
if (!function_exists('proc_open')) {
    test_unsupported(array_merge(test_unsupported(), ['the console refusing to write during a restore (this PHP disables proc_open)']));
} else {
    $copies = fn(): int => count(glob($work.'/backups/*.sql') ?: []);
    $consoleProof = $oldProof($work.'/uploads/proof');
    $before = $copies();
    $marker(true);
    try {
        $expected = (string)schema_restore_refusal()?->getMessage();
        ok($expected !== '', 'with the marker there is a reason');
        foreach (['backup', 'maintenance', 'billing:run', 'mail:work', 'demo:fill', 'a-command-added-later'] as $command) {
            $held = $console($command);
            ok($held['code'] === 1 && str_starts_with(trim($held['err']), $expected) && substr_count(trim($held['err']), "\n") === 0,
               $command.' stops with exit code 1 and the reason as its one sentence'.($held['code'] !== 1 ? ': '.$held['out'] : ': '.$held['err']));
        }
        is_same($before, $copies(), 'no new copy was written');
        clearstatcache();
        ok(is_file($consoleProof), 'and the old proof file is still there');
        $status = $console('status');
        ok($status['code'] === 0 && str_contains($status['out'], 'files    '.app_version()), 'status runs'.($status['err'] !== '' ? ': '.$status['err'] : ''));
    } finally { $marker(false); }
    // Decided with ADR 0029: the cron job's maintenance honours the maintenance
    // flag, as mail:work does, so a restore done with the flag on is not swept.
    file_put_contents($work.'/maintenance.flag', now());
    try {
        $paused = $console('maintenance');
        ok($paused['code'] === 1 && str_starts_with($paused['err'], 'Maintenance mode is on'), 'with the maintenance flag, maintenance stops with exit code 1 and says so'.($paused['code'] !== 1 ? ': '.$paused['out'] : ''));
    } finally { unlink($work.'/maintenance.flag'); }
    $swept = $console('maintenance');
    is_same(0, $swept['code'], 'without the marker and the flag, maintenance runs'.($swept['err'] !== '' ? ': '.$swept['err'] : ''));
    @unlink($consoleProof);
}

case_('Setup says a used folder in words, in the page\'s language, and makes nothing [ADR 0029 §3, test 10]');
/* Pointing setup at a new, empty database over a storage/ folder a portal has
   used is state (c). The page shows the sentence, not the log line with the
   stamp's path, and the database stays as empty as it was. */
if ($noTables === '' || !function_exists('exec')) {
    test_unsupported(array_merge(test_unsupported(), ['setup over an empty database with a stamp (needs CRM_MIGRATION_CONFIG and exec)']));
} else {
    $emptyDb = (require $noTables)['db'];
    $usedBySetup = test_run_dir().'/used-by-setup-'.bin2hex(random_bytes(4));
    mkdir($usedBySetup, 0700);
    write_run_config($usedBySetup.'/config.php', $emptyDb, $usedBySetup);
    file_put_contents($usedBySetup.'/schema.stamp', 'a run passed here once');
    $offered = $setupPage($usedBySetup.'/config.php');
    is_same(200, $offered['status'], 'the page is offered, as the database has no tables yet'.($offered['log'] !== '' ? ': '.$offered['log'] : ''));
    preg_match('/\b[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}\b/', (string)@file_get_contents($usedBySetup.'/setup-code.txt'), $code);
    ok(($code[0] ?? '') !== '', 'and the setup code was written beside the stamp');
    foreach (['de' => 'Die Datenbank ist leer, aber in diesem Ordner lief schon ein Portal.',
              'en' => 'The database is empty, but a portal has run in this folder before.'] as $lang => $sentence) {
        $refused = $setupPage($usedBySetup.'/config.php', ['method' => 'POST', 'post' => ['setup_code' => $code[0] ?? '', 'lang' => $lang] + $firstAccount]);
        ok(str_contains($refused['body'], $sentence), 'in '.$lang.' the page says: '.$sentence);
        ok(!str_contains($refused['body'], $usedBySetup.'/schema.stamp') && !str_contains($refused['body'], 'a run has passed on this folder'),
           'and not the log line with the stamp\'s path');
        ok(str_contains($refused['log'], 'CRM setup: ') && str_contains($refused['log'], $usedBySetup.'/schema.stamp'), 'which went to the log');
    }
    is_same(0, (int)install_connect($emptyDb)->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn(),
            'the database still has no table, the ledger\'s included, so no administrator either');
    ok(!glob($usedBySetup.'/backups/*'), 'and no copy was written');
}

case_('The console writes no copy of a database that holds nothing [ADR 0029 §3, code review]');
/* console backup calls backup_database() directly, past the runner. With the
   stamp deleted by hand and before the first page view, nothing else refuses: an
   empty copy would be written, and the pruning could push an old one out of the
   five. Asked of the second, empty database, with no stamp in its folder. */
if ($noTables === '' || !function_exists('proc_open')) {
    test_unsupported(array_merge(test_unsupported(), ['the console refusing to copy an empty database (needs CRM_MIGRATION_CONFIG and proc_open)']));
} else {
    $emptyDb = (require $noTables)['db'];
    $bare = test_run_dir().'/holds-nothing-'.bin2hex(random_bytes(4));
    mkdir($bare, 0700);
    write_run_config($bare.'/config.php', $emptyDb, $bare);
    $process = proc_open([PHP_BINARY, APP_ROOT.'/bin/console.php', 'backup'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                         $pipes, null, ['CRM_CONFIG' => $bare.'/config.php'] + getenv());
    fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]); $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    is_same(1, proc_close($process), 'backup on an empty database with no stamp stops with exit code 1'.($out !== '' ? ': '.$out : ''));
    ok(str_starts_with($err, 'No copy written: the database holds nothing'), 'in one sentence saying why: '.trim($err));
    ok(!glob($bare.'/backups/*'), 'and writes nothing');
    is_same(0, (int)install_connect($emptyDb)->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn(),
            'the database is as empty as it was');
    // The same command on this run's portal, which has a ledger and data, writes one.
    $had = glob($work.'/backups/*.sql') ?: [];
    $written = $console('backup', 'test');
    $now = array_diff(glob($work.'/backups/*.sql') ?: [], $had);
    ok($written['code'] === 0 && count($now) === 1, 'on a portal with data the same command writes one copy'.($written['err'] !== '' ? ': '.$written['err'] : ''));
    foreach ($now as $copy) unlink($copy);
}

case_('The background work does nothing while a copy is being restored [ADR 0029 §6, security review]');
/* A restore that keeps the files is not refused by the page path, so the tick
   that follows a page view would mail, sweep and bill on a database being
   emptied or imported; until now only the owner's maintenance flag kept it out.
   The nightly prune, due, is what shows whether the tick ran. */
$billingWas = (bool)setting('auto_billing');
set_setting('auto_billing', false);
set_setting('prune_last_run', '');
$marker(true);
try {
    tick_work();
    is_same('', (string)setting('prune_last_run'), 'with the marker, the prune that is due does not run: the tick did nothing');
} finally { $marker(false); }
tick_work();
ok((string)setting('prune_last_run') !== '', 'without the marker the same tick runs it: it was the marker that held it back');
set_setting('auto_billing', $billingWas);

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

case_('A copy makes the marker of an import first, drops it last, and never lists it among its tables [ADR 0029 §2]');
/* Its first statement after the SET lines makes import_unfinished, and its last
   drops it: while the table is there, a copy is being imported, or its import
   stopped. Carried in the middle, the table would be dropped there and the
   portal opened on half the data, so backup_tables() leaves it out even when it
   exists - as it does during an import, which is when the console might copy. */
db()->exec('CREATE TABLE IF NOT EXISTS `'.IMPORT_UNFINISHED_TABLE.'` (importing TINYINT NULL)');
$reader = connect();
ok(!in_array(IMPORT_UNFINISHED_TABLE, backup_tables($reader), true), 'backup_tables() leaves the marker out, though it exists');
ok(in_array('accounts', backup_tables($reader), true), 'and lists the tables that are the portal\'s');
$dump = fopen('php://memory', 'w+b');
backup_write($dump, $reader, 'test');
rewind($dump);
$copy = split_sql((string)stream_get_contents($dump));
fclose($dump);
is_same(['SET NAMES utf8mb4', 'SET FOREIGN_KEY_CHECKS=0', "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'"], array_slice($copy, 0, 3), 'the copy opens with its three SET lines');
ok(str_starts_with($copy[3] ?? '', 'CREATE TABLE IF NOT EXISTS `'.IMPORT_UNFINISHED_TABLE.'`'), 'then makes the marker, before any table is dropped');
ok(str_contains($copy[3] ?? '', 'IF NOT EXISTS') && str_contains($copy[3] ?? '', 'COMMENT='), 'IF NOT EXISTS, so a stopped import can be run again, and with a comment phpMyAdmin shows');
is_same('DROP TABLE IF EXISTS `'.IMPORT_UNFINISHED_TABLE.'`', end($copy), 'and its last statement drops it');
is_same([3, count($copy) - 1], array_keys(array_filter($copy, fn(string $statement): bool => str_contains($statement, IMPORT_UNFINISHED_TABLE))),
        'no other statement names it: it is never dropped or made in the middle');
ok(str_contains(implode("\n", $copy), 'DROP TABLE IF EXISTS `accounts`'), 'while the portal\'s tables are dropped and made as before');
db()->exec('DROP TABLE `'.IMPORT_UNFINISHED_TABLE.'`');

case_('The three states in which nothing touches the database are decided in one place, with the sentences the closed page shows [ADR 0029 §1, §5]');
/* On the run's own database: the marker over a full ledger with data; the ledger
   empty, then gone, under data; the database empty with and without the stamp.
   The sentences are compared whole, because they are what the owner reads in the
   file manager's moment of doubt. */
$stampWas = is_file(schema_stamp_file()) ? (string)file_get_contents(schema_stamp_file()) : null;
@unlink(schema_stamp_file());
db()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL)');
db()->exec('DELETE FROM schema_migrations');
foreach (migration_files() as $file)
    run('INSERT INTO schema_migrations (version,checksum,applied_at) VALUES (?,?,?)', [basename($file), hash_file('sha256', $file), now()]);
make_account(['role' => 'admin', 'name' => 'Bleibt da']);
is_same(null, schema_restore_refusal(), 'a portal whose ledger records its migrations and which holds data: nothing to refuse');
db()->exec('CREATE TABLE `'.IMPORT_UNFINISHED_TABLE.'` (importing TINYINT NULL)');
$a = schema_restore_refusal();
ok($a instanceof UpdateBlocked, '(a) the marker a copy makes first refuses, over a full ledger with data');
is_same(['Gerade wird eine Sicherung eingespielt, oder das Einspielen ist abgebrochen. Solange bleibt das Portal geschlossen. Meldet phpMyAdmin, dass das Einspielen fertig ist: diese Seite neu laden. Ist es abgebrochen: dieselbe Datei in phpMyAdmin noch einmal einspielen – dabei wird nichts doppelt.',
         'A copy is being imported, or the import stopped. The portal stays closed meanwhile. Once phpMyAdmin says the import has finished: reload this page. If it stopped: import the same file again in phpMyAdmin; nothing is doubled.'],
        [$a?->de, $a?->en], 'in the words of ADR 0029 §5 (a)');
ok(str_contains($a?->getMessage() ?? '', IMPORT_UNFINISHED_TABLE), 'and the log line names the table');
db()->exec('DROP TABLE `'.IMPORT_UNFINISHED_TABLE.'`');
db()->exec('DELETE FROM schema_migrations');
$b = schema_restore_refusal();
ok($b instanceof UpdateBlocked, '(b) an empty ledger under data refuses');
is_same(['In der Datenbank fehlt die Tabelle schema_migrations, die jedes Portal hat. Wird gerade eine Sicherung eingespielt: warten, bis phpMyAdmin fertig meldet, dann diese Seite neu laden. Ist das Einspielen abgebrochen: dieselbe Datei noch einmal einspielen. Wird nichts eingespielt, nennt config/config.php eine fremde Datenbank. Das Portal hat nichts verändert.',
         "The database is missing the table schema_migrations, which every portal has. If a copy is being imported: wait until phpMyAdmin says it has finished, then reload this page. If the import stopped: import the same file again. If nothing is being imported, config/config.php names a database that is not the portal's. The portal has changed nothing."],
        [$b?->de, $b?->en], 'in the words of ADR 0029 §5 (b)');
ok(str_contains($b?->getMessage() ?? '', 'accounts 1'), 'and the log line names the table with rows and how many: ' . test_show(mb_substr($b?->getMessage() ?? '', 0, 120)));
db()->exec('DROP TABLE schema_migrations');
is_same([$b?->de, $b?->en], [schema_restore_refusal()?->de, schema_restore_refusal()?->en], 'with no ledger table at all, the same');
db()->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (schema_guarded_tables() as $table) run('DELETE FROM '.sql_name($table, 'table'));
db()->exec('SET FOREIGN_KEY_CHECKS=1');
is_same(null, schema_restore_refusal(), 'empty, with no ledger and no stamp: a first install, nothing to refuse');
is_same(true, schema_first_install(), 'which is what schema_first_install() says too');
// backup_database() itself refuses such a database, once for every caller: the
// runner goes on from it, the console stops with its sentence (ADR 0029 §3).
$copiesBefore = count(backups());
try { backup_database('test'); $noCopy = null; } catch (BackupError $e) { $noCopy = $e; }
ok($noCopy instanceof NothingToCopy, 'backup_database() refuses a database that holds nothing, as the one BackupError the runner goes on from');
ok(str_starts_with((string)$noCopy?->getMessage(), 'No copy written: the database holds nothing'), 'with the sentence the console shows: '.test_show(mb_substr((string)$noCopy?->getMessage(), 0, 80)));
is_same($copiesBefore, count(backups()), 'and the copies are as they were');
is_same([], glob(backup_dir().'/*.part') ?: [], 'with no half-written file either');
file_put_contents(schema_stamp_file(), 'ein früherer Stand');
$c = schema_restore_refusal();
ok($c instanceof UpdateBlocked, '(c) the same empty database over a folder with a stamp refuses');
is_same(['Die Datenbank ist leer, aber in diesem Ordner lief schon ein Portal. Zum Wiederherstellen: die Sicherung in phpMyAdmin einspielen, dann diese Seite neu laden. Soll hier ein neues, leeres Portal entstehen: im Dateimanager die Datei storage/schema.stamp löschen und diese Seite neu laden. Vorsicht: Belege und Fotos des alten Portals werden dann gelöscht; seine Sicherungen in storage/backups bleiben.',
         "The database is empty, but a portal has run in this folder before. To restore: import the copy in phpMyAdmin, then reload this page. If a new, empty portal is meant to start here: delete the file storage/schema.stamp in the file manager and reload this page. Careful: the old portal's receipts and photos are deleted then; its copies in storage/backups stay."],
        [$c?->de, $c?->en], 'in the words of ADR 0029 §5 (c)');
ok(str_contains($c?->getMessage() ?? '', schema_stamp_file()), 'and the log line names the stamp\'s full path');
is_same('ein früherer Stand', (string)file_get_contents(schema_stamp_file()), 'the stamp\'s content is untouched: asking changes nothing');
@unlink(schema_stamp_file());
if ($stampWas !== null) file_put_contents(schema_stamp_file(), $stampWas);
db()->exec('DROP TABLE IF EXISTS schema_migrations');
