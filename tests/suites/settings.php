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
