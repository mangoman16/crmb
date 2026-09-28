<?php
/** The defaults registry: nothing should ever read as undefined. */

case_('Every declared setting has a usable default');
foreach (setting_schema() as $key => $spec) {
    ok(array_key_exists('default', $spec), $key.' declares a default');
    ok(isset($spec['kind']), $key.' declares a kind');
    ok(isset($spec['label'][0], $spec['label'][1]), $key.' is labelled in both languages');
}

case_('A key with no stored row reads as its declared default');
run('DELETE FROM settings');
setting_cache_clear();
is_same('Badminton', setting('club_name'), 'falls back to the declared value');
is_same(14, setting('billing_due_days'), 'an integer default keeps its type');
is_same(false, setting('privacy_ready'), 'a boolean default keeps its type');
ok(is_array(setting('statuses')), 'a map default is an array');
throws(fn() => setting_default('no_such_setting'), 'an undeclared key is an error, not a silent empty string');

case_('A stored value wins, including a falsy one');
set_setting('club_name', 'Verein X');
is_same('Verein X', setting('club_name'), 'the stored value is returned');
set_setting('billing_due_days', 0);
is_same(0, setting('billing_due_days'), 'zero is a real value, not treated as absent');
set_setting('portal_tagline', '');
is_same('', setting('portal_tagline'), 'an empty string is a real value too');

case_('Validation matches what each kind promises');
$int = setting_schema()['billing_due_days'];
is_same(30, setting_validate('billing_due_days', $int, '30'), 'an integer parses');
throws(fn() => setting_validate('billing_due_days', $int, 'x'), 'rejects non-numeric');
throws(fn() => setting_validate('billing_due_days', $int, '-1'), 'rejects below the minimum');
throws(fn() => setting_validate('billing_due_days', $int, '999'), 'rejects above the maximum');
$text = setting_schema()['club_name'];
is_same('Verein', setting_validate('club_name', $text, '  Verein  '), 'text is trimmed');
throws(fn() => setting_validate('club_name', $text, ''), 'a required field rejects empty');
throws(fn() => setting_validate('club_name', $text, str_repeat('x', 200)), 'rejects over the length limit');
$list = setting_schema()['payment_methods'];
is_same(['Bar','Karte'], setting_validate('payment_methods', $list, "Bar\nKarte\n\nBar"), 'a list de-duplicates and drops blanks');
throws(fn() => setting_validate('payment_methods', $list, "\n \n"), 'a list may not be empty');

case_('Seeded defaults leave a usable installation');
test_reset();
ok(setting('club_name') !== '', 'the portal has a name');
ok(count((array)setting('statuses')) > 0, 'membership statuses exist');
ok(count((array)setting('attendance_statuses')) > 0, 'attendance statuses exist');
ok(count(levels()) > 0, 'levels to put children into');
ok(level_default() !== null, 'and one of them is where a new child starts');
ok(count(age_groups()) > 0, 'age groups covering the usual ages');
is_same([], age_group_warnings(), 'the shipped age groups leave no gap and no overlap');
ok((int)scalar('SELECT COUNT(*) FROM payment_profiles') > 0, 'a payment profile to fill in');
is_same((int)scalar('SELECT id FROM payment_profiles LIMIT 1'), (int)setting('default_payment_profile'),
        'and it is the configured default');

case_('The privacy notice says what is still in the way, rather than just "no"');
$long = str_repeat('Diese Erklärung beschreibt die Verarbeitung. ', 20);
sign_in_as(make_account(['role'=>'admin']));
$save = fn(string $de, string $en, bool $ready) =>
    act('privacy_save', ['privacy_de'=>$de, 'privacy_en'=>$en] + ($ready ? ['privacy_ready'=>'1'] : []));
throws(fn() => $save('kurz', $long, true), 'a version that is too short is named', 'Deutsch');
throws(fn() => $save($long, 'short', true), 'and so is the other one', 'English');
throws(fn() => $save($long.'[Name des Betreibers]', $long, true), 'a leftover placeholder is quoted back', '[Name des Betreibers]');
throws(fn() => $save($long, $long.'[operator name]', true), 'in either version', '[operator name]');
does_not_throw(fn() => $save($long, $long, true), 'two complete versions are accepted');
is_same(true, (bool)setting('privacy_ready'), 'and the notice is released');
// Saving a draft must work even while it is incomplete, or there is nowhere to
// keep half-finished text and the page looks as though it ignored the edit.
does_not_throw(fn() => $save('Entwurf', 'Draft', false), 'an incomplete draft still saves');
is_same('Entwurf', setting('privacy_de'), 'and the text is actually stored');
is_same(false, (bool)setting('privacy_ready'), 'with the release tick cleared');

case_('Only the German notice is needed to release it, and an English reader is told');
/* ADR 0011. A club with no English-speaking families should not have to
   translate a legal text nobody reads before it can invite anyone. An English
   text that is there is still checked, because half a translation is not a
   notice to release. */
does_not_throw(fn() => $save($long, '', true), 'the German text alone is released');
is_same(true, (bool)setting('privacy_ready'), 'and the notice counts as released');
mail_ready(true);
set_setting('privacy_ready', true);   // as the save above left it, not as the fixture says
is_same(true, account_mail_ready(), 'which is all the invitation gate asks of the notice');
set_setting('smtp', []); set_setting('smtp_last_test', []);
throws(fn() => $save($long, 'short', true), 'an English text that is there is checked like the German', 'English');
throws(fn() => $save($long, $long.'[operator name]', true), 'placeholders included', '[operator name]');
does_not_throw(fn() => $save($long, '', true), 'so the German one alone again');
$_SESSION['locale'] = 'en';
is_same(privacy_text('de'), privacy_text(), 'an English reader is shown the German text rather than nothing');
is_same(true, privacy_in_german_only(), 'and the page can tell that it is doing so');
$_SESSION['locale'] = 'de';
is_same(false, privacy_in_german_only(), 'a German reader is told nothing');
$germanOnly = notice_version();
is_same(substr(hash('sha256', (string)json_encode([privacy_filled((string)setting('privacy_de')), ''], JSON_UNESCAPED_UNICODE)), 0, 16), $germanOnly,
        'the version is the German text and the English text as stored - none - and never the German twice');
does_not_throw(fn() => $save($long, $long, true), 'an English text is added later, word for word the German');
ok(notice_version() !== $germanOnly, 'which is a new version, because it is one');
is_same(false, privacy_in_german_only('en'), 'and an English reader now reads the English');

case_('Mail counts as working once a test of the saved settings passed, and not after they change');
set_setting('smtp', []); set_setting('smtp_last_test', []);
is_same(false, smtp_tested_ok(), 'nothing saved, nothing tested');
$smtpForm = ['host'=>'mail.example.test', 'port'=>'587', 'username'=>'portal@example.test', 'smtp_password'=>'hunter2-and-then-some',
             'encryption'=>'tls', 'from_email'=>'portal@example.test', 'from_name'=>'Badminton'];
act('smtp_save', $smtpForm);
is_same(false, smtp_tested_ok(), 'saved and never tested is not working');
// What a passing smtp_test stores; a real server is TESTING.md's business.
set_setting('smtp_last_test', ['ok'=>true, 'summary'=>'ok', 'transcript'=>'', 'sent_to'=>'', 'at'=>now()]);
is_same(true, smtp_tested_ok(), 'a passing test of those settings is');
act('smtp_save', ['smtp_password'=>''] + $smtpForm);
is_same(true, smtp_tested_ok(), 're-saving the form without touching the password keeps the test');
act('smtp_save', $smtpForm);
is_same(true, smtp_tested_ok(), 'and so does typing the same password again, though it is sealed anew');
act('smtp_save', ['host'=>'smtp.anderer-anbieter.test'] + $smtpForm);
is_same(false, smtp_tested_ok(), 'another server is untested until it is tested');
is_same([], setting('smtp_last_test', []), 'and the old result is gone rather than shown as its answer');
set_setting('smtp_last_test', ['ok'=>true, 'summary'=>'ok', 'transcript'=>'', 'sent_to'=>'', 'at'=>now()]);
act('smtp_save', ['smtp_password'=>'ein-ganz-anderes-passwort'] + $smtpForm);
is_same(false, smtp_tested_ok(), 'a different password is untested too');

case_('A mail server’s reply that is not valid UTF-8 is stored, not a 503');
/* A broken server answered in bytes that are not UTF-8; the test result is
   stored as it came, and refusing it ended the SMTP test in a 503. */
does_not_throw(fn() => set_setting('smtp_last_test', ['ok'=>false, 'summary'=>"Antwort: \xC3\x28 \xFF kaputt",
    'transcript'=>"220 \xFE\xFF mail\n", 'sent_to'=>'', 'at'=>now()]), 'a reply with invalid bytes is stored');
ok(str_contains((string)(setting('smtp_last_test', [])['summary'] ?? ''), 'kaputt'), 'and read back, the readable part intact');

case_('A reference setting offers and accepts only rows that can be chosen');
$reference = setting_schema()['default_payment_profile'];
is_same(['reference', 'payment_profiles'], [$reference['kind'], $reference['table']], 'the default recipient is a reference to a recipient');
$profile = fn(string $name, int $archived) => fixture('payment_profiles', ['name'=>$name, 'recipient'=>'', 'iban'=>'', 'bic'=>'',
    'currency'=>'EUR', 'note'=>'', 'qr_template'=>'', 'archived'=>$archived, 'created_at'=>now()]);
$live = $profile('Vereinskonto Sparkasse', 0);
$gone = $profile('Altes Konto', 1);
is_same('Vereinskonto Sparkasse', setting_reference_options($reference)[$live] ?? null, 'a recipient in use is offered by name');
ok(!isset(setting_reference_options($reference)[$gone]), 'an archived one is not offered');
is_same($live, setting_validate('default_payment_profile', $reference, (string)$live), 'the one chosen is stored as its id');
is_same(0, setting_validate('default_payment_profile', $reference, ''), 'nothing chosen is none');
is_same(0, setting_validate('default_payment_profile', $reference, '0'), 'and so is 0');
throws(fn() => setting_validate('default_payment_profile', $reference, (string)$gone), 'an archived one is refused', 'nicht verfügbar');
throws(fn() => setting_validate('default_payment_profile', $reference, '999999'), 'one that does not exist is refused', 'nicht verfügbar');
throws(fn() => setting_validate('default_payment_profile', $reference, $live.' OR 1=1'), 'and anything that is not an id', 'nicht verfügbar');
throws(fn() => setting_reference_options(['table'=>'accounts'] + $reference), 'a table not on the allowlist is refused before any SQL', 'not allowed');
$payments = [];
foreach (settings_in_group('payments') as $key => $spec)
    $payments['set_'.$key] = $spec['kind'] === 'list' ? implode("\n", (array)setting($key)) : (is_bool(setting($key)) ? (setting($key) ? '1' : '') : (string)setting($key));
act('defaults_registry_save', ['group'=>'payments', 'set_default_payment_profile'=>(string)$live] + $payments);
is_same($live, setting('default_payment_profile'), 'the settings form stores the choice');
throws(fn() => act('defaults_registry_save', ['group'=>'payments', 'set_default_payment_profile'=>(string)$gone] + $payments),
       'and refuses an archived recipient posted anyway', 'nicht verfügbar');

case_('What most portals never touch is marked, and what is switched elsewhere is not listed twice');
is_same(true, setting_schema()['auto_background']['advanced'] ?? false, 'the background work is advanced');
is_same(true, setting_schema()['history_months']['advanced'] ?? false, 'and so is how long changes are kept');
ok(!array_key_exists('auto_billing', settings_in_group('system')), 'automatic charges are switched on the Beiträge page, not here');
ok(!array_key_exists('setup_hidden', settings_in_group('system')), 'and the checklist is hidden from the checklist, not here');
is_same(false, setting_default('setup_hidden'), 'which is shown until she hides it');
throws(fn() => setting_default('default_tariff'), 'a default tariff nothing uses any more is not declared at all');
ok(!str_contains((string)file_get_contents(APP_ROOT.'/database/defaults.php'), 'default_tariff'), 'nor seeded on a new portal');

case_('Every note in the shipped drafts counts as a placeholder, however long');
/* The check used to stop at 80 characters, and six of the eight notes in each
   draft are longer: a notice still saying „[Vollständiger Name …]“ could be
   released. Counted against the drafts themselves, so a new note is covered. */
foreach (['de', 'en'] as $lang) {
    $draft = (string)file_get_contents(ROOT.'/docs/privacy-draft-'.$lang.'.txt');
    is_same(8, substr_count($draft, '['), $lang.': the draft has eight notes, so the count below means something');
    is_same(8, count(privacy_draft_placeholders($draft)), $lang.': and all eight are found');
    ok(max([0, ...array_map('mb_strlen', privacy_draft_placeholders($draft))]) > 250, $lang.': the longest among them too, at over 250 characters');
    throws(fn() => $save($lang === 'de' ? $draft.$long : $long, $lang === 'en' ? $draft.$long : $long, true),
           $lang.': the draft itself cannot be released', $lang === 'de' ? '[Vollständiger Name' : '[Full name');
}
$complete = "Verantwortlich: Badminton Hofer, Hauptstraße 1, 12345 Musterstadt, info@beispiel.test\n"
    ."Rechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO, für Protokolle Art. 6 Abs. 1 lit. f DSGVO (Art. 6 (1) (f) GDPR).\n"
    ."Beschwerden: https://www.bfdi.bund.de/DE/Home/home_node.html?view=renderKontakt#top und https://example.org/a?x[]=1\n"
    ."Wie das Gesetz sagt: „Personenbezogene Daten müssen […] verarbeitet werden“ [1].\n"
    ."Mehr dazu [hier](https://example.org/datenschutz). Stand: 24.09.2026\n";
is_same([], privacy_draft_placeholders($complete), 'a finished notice with laws, links, a footnote and an elision has none');
does_not_throw(fn() => $save($complete.$long, $complete.$long, true), 'and is released');
is_same(['[Datum]'], privacy_draft_placeholders("Stand: [Datum]\nText [ohne\nEnde]"),
        'a bracket left open by a typo does not swallow the next line');

case_('One long line of brackets is answered at once, and a text it cannot read is refused in words');
/* 30,000 characters is what the field accepts. The earlier pattern looked ahead
   past every further „[“ from every „[“, and took over a second on this line
   with PCRE's JIT and ten without it: a request anybody on the settings page
   could make hang. The closing „]“ matters - without one, PCRE gives up before
   it starts, and the line would prove nothing. */
$brackets = str_repeat('[', 29999).']';
is_same(30000, mb_strlen($brackets), 'the line is as long as the field allows');
$started = microtime(true);
$found = privacy_draft_placeholders($brackets);
$took = microtime(true) - $started;
is_same([], $found, 'it holds no placeholder, having no letter');
ok($took < 0.2, sprintf('and is checked in a moment, not seconds (%.4f s)', $took));
does_not_throw(fn() => $save($brackets, $long, true), 'and saved through the page, it is answered rather than left to hang');
throws(fn() => privacy_draft_placeholders("Stand: [Datum] \xFF"), 'a text the pattern cannot read is refused with a sentence, not a crash',
       'nicht auf Platzhalter prüfen');

// ---------------------------------------------------------------------------
case_('The connection test answers while she waits, and says which step failed');
/* It used to queue a message and send her to the outbox to look for it. A wrong
   port, a blocked outgoing connection and a rejected password all looked the
   same from there - nothing arrived, and nothing said why. */
sign_in_as(make_account(['role'=>'admin', 'email'=>'chefin@example.test']));
set_setting('smtp', []);
$result = smtp_check();
is_same(false, $result['ok'], 'nothing is configured yet, so it fails');
/* smtp_check() asks for the mail library before it reads the settings, so a copy
   without vendor/ - a Git checkout nobody ran composer in - answers that the
   library is missing, which is the true first problem there. Each answer is held
   to what it should say, and a run that could only check the second one says so
   in its footer rather than passing as though it had checked the first. */
if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
    ok(str_contains($result['summary'], 'Absenderadresse'),
       'and it says what is missing, rather than "could not connect" (PHPMailer is installed)');
} else {
    ok(str_contains($result['summary'], 'PHPMailer') && str_contains($result['summary'], 'vendor/'),
       'and without vendor/ it says the mail library is missing, rather than "could not connect"');
    test_unsupported(array_merge(test_unsupported(),
        ['the SMTP test naming a missing sender address (PHPMailer is not installed: vendor/ is missing)']));
}
ok(str_contains($result['transcript'], 'kein Server eingetragen'), 'the transcript starts with what it was working from');
does_not_throw(fn() => smtp_check(), 'a failing test is an answer, never an exception');

$target = act('smtp_test', ['mode'=>'connect']);
is_same(['settings', ['tab'=>'smtp']], $target, 'the button comes back to the SMTP tab, not to the outbox');
$stored = setting('smtp_last_test', []);
is_same(false, $stored['ok'], 'the outcome is kept, so the page can show it after the redirect');
ok($stored['at'] !== '', 'with the time it was run');
is_same(0, (int)scalar("SELECT COUNT(*) FROM mail_jobs WHERE category='test'"),
        'and nothing was queued: the test is the connection, not a message in a list');

case_('Nothing in a transcript is a password');
/* The transcript is shown on a screen, stored in the settings table and copied
   into support emails. AUTH LOGIN sends the user name and the password as two
   lines of base64, which is not encryption, it is spelling. */
$smtp = ['host'=>'mail.example.test', 'port'=>587, 'username'=>'portal@example.test',
         'password'=>seal('hunter2-and-then-some'), 'encryption'=>'tls',
         'from_email'=>'portal@example.test', 'from_name'=>'Badminton'];
$conversation = "CLIENT -> SERVER: AUTH LOGIN\n"
    ."SERVER -> CLIENT: 334 VXNlcm5hbWU6\n"
    ."CLIENT -> SERVER: ".base64_encode('portal@example.test')."\n"
    ."SERVER -> CLIENT: 334 UGFzc3dvcmQ6\n"
    ."CLIENT -> SERVER: ".base64_encode('hunter2-and-then-some')."\n"
    ."SERVER -> CLIENT: 235 authenticated\n"
    ."CLIENT -> SERVER: MAIL FROM:<portal@example.test>\n";
$clean = smtp_redact($conversation, $smtp);
ok(!str_contains($clean, base64_encode('hunter2-and-then-some')), 'the password is not in the AUTH exchange');
ok(!str_contains($clean, base64_encode('portal@example.test')), 'nor is the user name');
ok(!str_contains($clean, 'hunter2-and-then-some'), 'and not in the clear anywhere');
ok(str_contains($clean, '235 authenticated'), 'what the server answered is still readable');
ok(str_contains($clean, 'MAIL FROM:<portal@example.test>'), 'and so is the rest of the conversation');
// AUTH PLAIN puts both on the command line, where a rule written for AUTH LOGIN
// would walk straight past them.
$plain = smtp_redact("CLIENT -> SERVER: AUTH PLAIN ".base64_encode("\0portal\0hunter2-and-then-some"), $smtp);
ok(!str_contains($plain, base64_encode("\0portal\0hunter2-and-then-some")), 'AUTH PLAIN is redacted on its own line');
ok(str_contains($plain, 'AUTH PLAIN'), 'though it still says which mechanism was used');
// A password that turns up outside the AUTH exchange - inside an error message,
// say - must go too, whatever the line looks like.
ok(!str_contains(smtp_redact('SMTP ERROR: hunter2-and-then-some rejected', $smtp), 'hunter2-and-then-some'),
   'and a password quoted back in an error message goes with it');

case_('A mail library’s failure is translated into something to change');
is_same(t('Benutzername oder Passwort hat der Server nicht angenommen.', 'The server did not accept the user name or the password.'),
        smtp_explain('SMTP Error: Could not authenticate.'), 'a refused sign-in is a password');
ok(str_contains(smtp_explain('535 5.7.8 Authentication credentials invalid'), 'Passwort'), 'and so is a 535');
ok(str_contains(smtp_explain('stream_socket_enable_crypto(): certificate verify failed'), 'STARTTLS'),
   'a TLS failure names the setting that is usually wrong');
ok(str_contains(smtp_explain('SMTP Error: Could not connect to SMTP host.'), 'Port'),
   'a silent server sends her to the port');
ok(str_contains(smtp_explain('550 5.7.1 Relay access denied'), 'Absender'), 'a refused relay is the sender address');
is_same('Etwas ganz anderes', smtp_explain('Etwas ganz anderes'), 'and anything unrecognised is passed through unchanged');
