<?php
/**
 * Children's pictures (ADR 0031): who sees one, who adds, changes and removes
 * it, the family's yes for the course, and what goes with a child.
 *
 * The square a photo becomes is made in the uploads suite, from bytes. The
 * route's headers, sign-out's and one real upload are in the robustness suite,
 * through the real router, because a command line keeps no headers and only a
 * real request carries a file. What the code must never do is in the structure
 * suite. Each rule here was broken once on purpose and seen to fail.
 */

$admin   = make_account(['role'=>'admin', 'name'=>'Anna Admin']);
$trainer = make_account(['role'=>'trainer', 'name'=>'Tina Trainerin']);
$famA = make_account(['name'=>'Mia Adler']);
$famB = make_account(['name'=>'Ben Berger']);
$famC = make_account(['name'=>'Clara Christ']);
$mia   = make_student(['account_id'=>$famA, 'first_name'=>'Mia', 'last_name'=>'Adler']);
$ben   = make_student(['account_id'=>$famB, 'first_name'=>'Ben', 'last_name'=>'Berger']);
$clara = make_student(['account_id'=>$famC, 'first_name'=>'Clara', 'last_name'=>'Christ']);
// A child whose family has not signed in yet: a placeholder login (ADR 0023 §3).
$paul  = make_student(['first_name'=>'Paul', 'last_name'=>'Platz']);
$course  = make_class(['name'=>'Kindertraining']);
$youth   = make_class(['name'=>'Jugend']);
$summer  = make_class(['name'=>'Sommercamp', 'archived'=>1]);
foreach ([$mia, $ben, $paul] as $child) make_enrolment($course, $child);
make_enrolment($youth, $clara);
foreach ([$mia, $clara] as $child) make_enrolment($summer, $child);

/**
 * A child's picture as store_upload() leaves one, and the row naming it, written
 * as no action writes it: a fixture. $fill is repeated into the 32 characters a
 * stored name has.
 */
function pictures_put(int $student, string $fill, int $yes = 0): string {
    $name = substr(str_repeat($fill, 32), 0, 32).'.jpg';
    @mkdir(upload_dir('picture'), 0775, true);
    file_put_contents(upload_dir('picture').'/'.$name, 'a square JPEG');
    run('UPDATE students SET picture_name=?, course_sees_picture=? WHERE id=?', [$name, $yes, $student]);
    picture_audience_clear();
    return $name;
}
function pictures_row(int $student): array {
    return one('SELECT * FROM students WHERE id=?', [$student]);
}
pictures_put($mia, 'a');
pictures_put($ben, 'b', 1);
pictures_put($clara, 'c', 1);
pictures_put($paul, 'd');

// ---------------------------------------------------------------------------
case_('Who sees a child’s picture is one rule, and the page and the route ask it alike [ADR 0031 §5, test 2]');
/* Each line is asked three ways: of may_see_picture() itself, of avatar() -
   whether it draws the picture - and of picture_for_download(), whether the
   route would serve it. One rule, so the three cannot come to disagree: a page
   never shows a face the route refuses, nor links one the route would serve to
   somebody else. A new request each time, as a page view is: the rule's memo
   and the settings are emptied between them. */
$asked = function (int $viewer, int $child): array {
    picture_audience_clear(); setting_cache_clear();
    sign_in_as($viewer);
    $rule = may_see_picture(current_user(), picture_of(one('SELECT id, account_id, picture_name, course_sees_picture FROM students WHERE id=?', [$child])));
    $drawn = str_contains(avatar(pictures_row($child)), '<img ');
    try { picture_for_download('student', $child); $served = true; } catch (NotFound) { $served = false; }
    return [$rule, $drawn, $served];
};
$table = function (array $lines) use ($asked): void {
    foreach ($lines as [$viewer, $child, $expected, $what])
        is_same([$expected, $expected, $expected], $asked($viewer, $child), $what.' ('.($expected ? 'sees it' : 'does not').', as rule, page and route)');
};
$table([
    [$admin, $mia, true, 'an administrator sees every child’s picture'],
    [$trainer, $ben, true, 'so does a trainer'],
    [$trainer, $paul, true, 'a child whose family has not signed in yet included'],
    [$famA, $mia, true, 'a family sees its own child’s'],
    [$famA, $ben, true, 'and another child’s whose family said yes, both in a running course now - though its own has not: seeing is not the price of showing'],
    [$famB, $mia, false, 'but not one whose family has not said yes'],
    [$famA, $clara, false, 'nor one whose family said yes but who shares only an archived course with it'],
    [$famA, $paul, false, 'nor a placeholder child’s, for whom nobody has said yes'],
    [$famC, $ben, false, 'nor one in another course'],
]);
run('UPDATE students SET course_sees_picture=0 WHERE id=?', [$ben]);
$table([[$famA, $ben, false, 'once Ben’s family takes the yes back, the classmate no longer sees it']]);
run('UPDATE students SET course_sees_picture=1 WHERE id=?', [$ben]);
run('UPDATE class_students SET left_on=? WHERE class_id=? AND student_id=?', [today(), $course, $mia]);
$table([[$famA, $ben, false, 'nor after its own child has left the course']]);
run('UPDATE class_students SET left_on=NULL WHERE class_id=? AND student_id=?', [$course, $mia]);
run('UPDATE class_students SET left_on=? WHERE class_id=? AND student_id=?', [today(), $course, $ben]);
$table([[$famA, $ben, false, 'nor after the other child has left it']]);
run('UPDATE class_students SET left_on=NULL WHERE class_id=? AND student_id=?', [$course, $ben]);
run('UPDATE classes SET archived=0 WHERE id=?', [$summer]);
$table([[$famA, $clara, true, 'the course of the two running again, the yes counts again']]);
run('UPDATE classes SET archived=1 WHERE id=?', [$course]);
$table([[$famA, $ben, false, 'and once a course is archived, it no longer does']]);
run('UPDATE classes SET archived=0 WHERE id=?', [$course]);
run('UPDATE classes SET archived=1 WHERE id=?', [$summer]);
set_setting('pictures_in_course', false);
$table([
    [$famA, $ben, false, 'with the club’s setting off, no classmate’s picture'],
    [$famA, $mia, true, 'but still its own child’s'],
    [$trainer, $ben, true, 'and staff still every child’s'],
]);
is_same(1, (int)scalar('SELECT course_sees_picture FROM students WHERE id=?', [$ben]), 'the answer given is kept while the setting is off');
set_setting('pictures_in_course', true);
$table([[$famA, $ben, true, 'and counts again once it is on']]);
sign_out();
ok(!str_contains(avatar(pictures_row($mia)), '<img '), 'signed out, nobody’s picture is drawn');
$thrown = null;
try { picture_for_download('student', $mia); } catch (Throwable $e) { $thrown = $e; }
ok($thrown instanceof SignInRequired, 'and the route asks who is signed in before anything else, which sends to the sign-in page, not to a picture');

// ---------------------------------------------------------------------------
case_('The route serves a picture only by the rule, and says the same about nobody, no picture and not allowed [ADR 0031 §6, test 3]');
/* serve_download() is the route itself. A refusal throws before a byte is
   read, which the front controller turns into a 404; a picture served would end
   the run with exit, so while the route is asked an exit is caught on the way
   out and reported as the failure it is. What is served - the type, the week in
   the cache, no Expires or Pragma - is asked through the real router in the
   robustness suite. */
$pictureCall = new stdClass; $pictureCall->asking = '';
register_shutdown_function(function () use ($pictureCall) {
    if ($pictureCall->asking === '') return;
    fwrite(STDERR, "\nFAIL pictures: the route served ".$pictureCall->asking." instead of refusing, and ended the run.\n");
    exit(1);
});
$route = function (int $id, mixed $kind = 'student') use ($pictureCall): string {
    $_GET = ['page'=>'download', 'what'=>'picture', 'id'=>(string)$id, 'v'=>'abc'] + ($kind === null ? [] : ['kind'=>$kind]);
    $pictureCall->asking = 'the picture of '.json_encode($kind).' '.$id;
    try { serve_download(); return 'served'; }
    catch (NotFound $e) { return $e->getMessage(); }
    finally { $_GET = []; $pictureCall->asking = ''; }
};
$noPicture = make_student(['account_id'=>make_account(['name'=>'Nora Neu']), 'first_name'=>'Nora', 'last_name'=>'Neu']);
make_enrolment($course, $noPicture);
$fileGone = make_student(['account_id'=>make_account(['name'=>'Fritz Fort']), 'first_name'=>'Fritz', 'last_name'=>'Fort']);
run('UPDATE students SET picture_name=?, course_sees_picture=1 WHERE id=?', [str_repeat('9', 32).'.jpg', $fileGone]);
make_enrolment($course, $fileGone);
sign_in_as($famA);
picture_audience_clear();
$said = ['nobody'      => $route(987654321),
         'no picture'  => $route($noPicture),
         'a picture whose file is gone' => $route($fileGone),
         'not allowed' => $route($clara)];
is_same(array_fill_keys(array_keys($said), t('Dieses Bild gibt es nicht.', 'There is no such picture.')), $said,
        'a child who is not there, one with no picture, one whose file is gone and one not allowed are the same 404, in the same words');
/* The kind is part of the address (ADR 0031 §6, as amended): student or
   account, and nothing else - Ben's picture, which famA may see, asked for any
   other way is the same 404. */
$said = ['no kind' => $route($ben, null), 'the old spelling, team' => $route($ben, 'team'),
         'in capitals' => $route($ben, 'Student'), 'a list' => $route($ben, ['student'])];
is_same(array_fill_keys(array_keys($said), t('Dieses Bild gibt es nicht.', 'There is no such picture.')), $said,
        'a classmate’s picture asked for with no kind, or any kind but student, is the same 404');
ok(str_ends_with(picture_for_download('student', $ben), '.jpg'), 'a classmate whose family said yes is served');
$reads = new ReflectionFunction('picture_for_download');
$body = implode('', array_slice(file($reads->getFileName()), $reads->getStartLine() - 1, $reads->getEndLine() - $reads->getStartLine() + 1));
ok(!preg_match('/\bstudent\s*\(/', $body), 'picture_for_download() does not go through student(), which hands over the whole record');
preg_match_all("/'(SELECT [^']*)'/", $body, $read);
is_same(['SELECT id, account_id, picture_name, course_sees_picture FROM students WHERE id=?', 'SELECT id, role, picture_name FROM accounts WHERE id=?'], $read[1],
        'and reads the columns the rule needs and nothing else: of a child four, of a login three');
ok(str_contains($body, 'may_see_picture($viewer, $picture)'), 'and asks the rule');
$name = (string)scalar('SELECT picture_name FROM students WHERE id=?', [$ben]);
is_same('private, max-age=604800', picture_cache_control($name, upload_version($name)), 'kept a week, privately, at the address of the picture stored now');
is_same('private, no-store', picture_cache_control($name, 'bbbbbbbbbbbX'), 'at an older address answered, but kept nowhere');
is_same('private, no-store', picture_cache_control($name, null), 'nor without a version');
is_same('private, no-store', picture_cache_control($name, ['x']), 'nor with a version that is not text');
is_same('private, no-store', picture_cache_control('', ''), 'and a picture removed matches nothing');
foreach ([upload_version($name), 'x', null] as $version)
    ok(!str_contains(picture_cache_control($name, $version), 'public') && !str_contains(picture_cache_control($name, $version), 'immutable'),
       'never public, never immutable ('.var_export($version, true).')');

// ---------------------------------------------------------------------------
case_('One helper draws a person: the picture where the rule holds and the file is there, the initials otherwise [ADR 0031 §6, test 4]');
sign_in_as($famA);
picture_audience_clear();
$own = avatar(pictures_row($mia));
$address = e(url('download', ['what'=>'picture', 'kind'=>'student', 'id'=>$mia, 'v'=>upload_version((string)scalar('SELECT picture_name FROM students WHERE id=?', [$mia]))]));
is_same('<span class="avatar"><img src="'.$address.'" alt="" width="40" height="40" loading="lazy"></span>', $own,
        'the own child’s picture, at its address with its version, escaped, with no words of its own - the name stands beside it');
ok(str_contains($address, '&amp;v='), 'the address is escaped as it is printed');
is_same('<span class="avatar large"><img src="'.$address.'" alt="" width="72" height="72" loading="lazy"></span>', avatar(pictures_row($mia), 'large'),
        'the size large is 72, as the child’s page draws it');
ok(!str_contains(avatar(current_user(), 'tiny'), 'loading='), 'the top bar draws its own face at once: in view on every page, it would only wait');
ok(str_contains(avatar(current_user(), 'tiny'), 'width="32"'), 'and as tiny, 32');
is_same('<span class="avatar small">CC</span>', avatar(pictures_row($clara), 'small'), 'a child it may not see is the initials, as a child with no picture is');
$gone = pictures_row($fileGone);
sign_in_as($trainer);
is_same('<span class="avatar">FF</span>', avatar($gone), 'a picture whose file is gone is the initials, even for staff');
/* The team's faces (the owner, 2026-10-08): a team member's own picture, from
   the login, for everybody signed in; a family's login has none of its own. */
$teamPicture = str_repeat('5', 32).'.jpg';
file_put_contents(upload_dir('picture').'/'.$teamPicture, 'a square JPEG');
run('UPDATE accounts SET picture_name=? WHERE id=?', [$teamPicture, $trainer]);
$tina = one('SELECT '.chat_person_columns('a').' FROM accounts a WHERE a.id=?', [$trainer]);
$teamAddress = e(url('download', ['what'=>'picture', 'kind'=>'account', 'id'=>$trainer, 'v'=>upload_version($teamPicture)]));
foreach ([$famA => 'a family', $admin => 'an administrator', $trainer => 'the trainer herself'] as $viewer => $who) {
    sign_in_as($viewer);
    picture_audience_clear();
    is_same('<span class="avatar"><img src="'.$teamAddress.'" alt="" width="40" height="40"'.($viewer === $trainer ? '' : ' loading="lazy"').'></span>', avatar($tina),
            $who.' sees the trainer’s own picture, as the chat draws her');
    is_same($teamPicture, picture_for_download('account', $trainer), 'and the route serves it to '.$who);
}
sign_in_as($trainer);
ok(str_contains(avatar(current_user(), 'tiny'), $teamAddress), 'her top bar draws her face');
sign_out();
ok(!str_contains(avatar($tina), '<img '), 'signed out, nobody sees a team member’s picture either');
$thrown = null;
try { picture_for_download('account', $trainer); } catch (Throwable $e) { $thrown = $e; }
ok($thrown instanceof SignInRequired, 'and the route sends to the sign-in page');
sign_in_as($trainer);
run("UPDATE accounts SET picture_name=? WHERE id=?", [str_repeat('6', 32).'.jpg', $famB]);
file_put_contents(upload_dir('picture').'/'.str_repeat('6', 32).'.jpg', 'a picture no action writes');
$benLogin = one('SELECT '.chat_person_columns('a').' FROM accounts a WHERE a.id=?', [$famB]);
ok(!str_contains(avatar($benLogin), '6666'), 'a family’s login draws no picture of its own, whatever the column holds');
ok(str_contains(avatar($benLogin), 'what=picture&amp;kind=student&amp;id='.$ben.'&amp;'), 'it draws its child’s');
$thrown = null;
try { picture_for_download('account', $famB); } catch (Throwable $e) { $thrown = $e; }
ok($thrown instanceof NotFound, 'and the route serves none as the team’s');
run("UPDATE accounts SET picture_name='' WHERE id=?", [$famB]);
is_same('<span class="avatar">AA</span>', avatar(one('SELECT '.chat_person_columns('a').' FROM accounts a WHERE a.id=?', [$admin])),
        'a team member without a picture is the initials');
is_same('<span class="avatar">K</span>', avatar(['name'=>'Kindertraining']), 'and so is a course’s letter');
/* No query per face: the rows a page draws carry what the rule needs, and the
   one question the rule asks of the database is asked once a request. */
$classmates = [];
for ($i = 0; $i < 20; $i++) {
    $classmates[$i] = make_student(['account_id'=>make_account(['name'=>'Kind '.$i]), 'first_name'=>'Kind', 'last_name'=>'Nummer'.$i]);
    make_enrolment($course, $classmates[$i]);
    pictures_put($classmates[$i], sprintf('%02x', 0x80 + $i), 1);
}
$rows = rows('SELECT * FROM students WHERE id IN ('.implode(',', $classmates).')');
sign_in_as($famA);
picture_audience_clear();
$pictures = 0;
$faces = query_count(function () use ($rows, &$pictures) { foreach ($rows as $row) $pictures += (int)str_contains(avatar($row), '<img '); });
is_same(20, $pictures, 'twenty classmates whose families said yes are twenty pictures');
is_same(1, $faces, 'drawn with one query between them, the rule’s');
sign_in_as($trainer);
is_same(0, query_count(fn() => array_map('avatar', $rows)), 'and staff, who see every face, with none');
$group = course_group_thread($course);
$sheet = function (int $members) use ($course, $group, $classmates, $famA): int {
    run('UPDATE class_students SET left_on=? WHERE class_id=? AND student_id IN ('.implode(',', $classmates).')', [today(), $course]);
    run('UPDATE class_students SET left_on=NULL WHERE class_id=? AND student_id IN ('.implode(',', array_slice($classmates, 0, $members)).')', [$course]);
    sign_in_as($famA);
    picture_audience_clear();
    return query_count(fn() => render_view('messages', ['id'=>(string)$group, 'members'=>'1']));
};
$sheet(20);   // once, so what is kept for a request is kept before either is counted
is_same($sheet(2), $sheet(20), 'the member sheet of a group asks as many queries with twenty faces as with two');
run('UPDATE class_students SET left_on=NULL WHERE class_id=?', [$course]);

// ---------------------------------------------------------------------------
case_('One writer: a picture staff put on ends the yes and tells the family, the old file goes at once [ADR 0031 §7, test 6]');
$dir = upload_dir('picture');
$putOn = function (int $actor, int $child, string $fill) use ($dir): array {
    sign_in_as($actor);
    $before = (string)scalar('SELECT picture_name FROM students WHERE id=?', [$child]);
    $name = str_repeat($fill, 32).'.jpg';
    file_put_contents($dir.'/'.$name, 'a new square JPEG');
    transactional(fn() => write_child_picture(student($child), $name));
    return [$before, $name];
};
run('UPDATE students SET course_sees_picture=1 WHERE id=?', [$mia]);
$bells = fn(int $account): array => rows('SELECT kind, title, body, link_page, link_params FROM notifications WHERE account_id=? ORDER BY id', [$account]);
$versionsBefore = (int)scalar("SELECT COUNT(*) FROM record_versions WHERE entity='students' AND entity_id=?", [$mia]);
[$old, $new] = $putOn($trainer, $mia, 'e');
is_same([$new, 0], [(string)scalar('SELECT picture_name FROM students WHERE id=?', [$mia]), (int)scalar('SELECT course_sees_picture FROM students WHERE id=?', [$mia])],
        'a picture the trainer puts on the child is the child’s, and takes the course’s view away in the same write');
ok(!is_file($dir.'/'.$old), 'the file it replaced is gone the moment the writer returns');
ok(is_file($dir.'/'.$new), 'and the new one is there');
is_same([['kind'=>'picture', 'title'=>'Neues Foto von Mia',
          'body'=>'Tina Trainerin hat es hinzugefügt. Du kannst es jederzeit ändern oder entfernen. Im Kurs-Chat erscheint es erst, wenn ein Elternteil wieder zustimmt.',
          'link_page'=>'student', 'link_params'=>http_build_query(['id'=>$mia, '#'=>'picture'])]], $bells($famA),
        'and one notice in the family’s bell, saying the yes ended and that a parent gives it again - Mia has no birth date - linking to the picture');
$log = rows('SELECT purpose, enabled FROM consent_log WHERE account_id=? ORDER BY id', [$famA]);
is_same(['purpose'=>'course_sees_picture', 'enabled'=>0], end($log) ?: null, 'and the family’s consent log ends with the yes ended: the answer that holds now');
ok(!str_contains(json_encode($bells($famA)), 'download'), 'which holds no picture and no picture’s address');
$versions = rows("SELECT * FROM record_versions WHERE entity='students' AND entity_id=? ORDER BY id", [$mia]);
is_same($versionsBefore + 1, count($versions), '„Änderungen" has one line for it');
$line = end($versions);
is_same($trainer, (int)$line['actor_id'], 'with the trainer as who changed it');
is_same(['picture_name', 'course_sees_picture'], array_keys(version_changes($line)), 'the picture and the yes');
sign_in_as($admin);
$page = render_view('history', ['entity'=>'students', 'record'=>(string)$mia]);
ok(str_contains($page, '<dt>'.e('Profilbild').'</dt>') && str_contains($page, '<span class="was">'.e('Bild').'</span> → '.e('Bild')),
   'labelled „Profilbild", the picture read as „Bild"');
ok(str_contains($page, '<dt>'.e('Im Kurs-Chat sichtbar').'</dt>') && str_contains($page, '<span class="was">'.e('ja').'</span> → '.e('nein')),
   'and the yes it ended as ja and nein');
ok(!str_contains($page, $old) && !str_contains($page, $new) && !str_contains($page, substr($new, 0, 12)), 'and naming no file');
run('UPDATE students SET course_sees_picture=1, birth_date=? WHERE id=?', [date('Y-m-d', strtotime('-15 years')), $mia]);
sign_in_as($trainer);
$putOn($trainer, $mia, '0');
$latest = $bells($famA);
ok(str_ends_with((string)(end($latest)['body'] ?? ''), 'Im Kurs-Chat zeigst du es erst, wenn du wieder zustimmst.'),
   'at fifteen the notice says the child gives the yes again herself (consent_age, 14)');
run('UPDATE students SET birth_date=NULL WHERE id=?', [$mia]);
$new = (string)scalar('SELECT picture_name FROM students WHERE id=?', [$mia]);
$logged = fn(int $account): int => (int)scalar('SELECT COUNT(*) FROM consent_log WHERE account_id=?', [$account]);
$paulLogin = (int)scalar('SELECT account_id FROM students WHERE id=?', [$paul]);
$paulLogged = $logged($paulLogin);
[$oldPaul] = $putOn($admin, $paul, 'f');
is_same($paulLogged, $logged($paulLogin), 'with no yes to end, staff’s picture leaves the consent log as it was');
is_same([], $bells((int)scalar('SELECT account_id FROM students WHERE id=?', [$paul])), 'a placeholder’s bell takes nothing: nobody reads it (notify())');
ok(!is_file($dir.'/'.$oldPaul), 'and the old file goes all the same');
run('UPDATE students SET course_sees_picture=1 WHERE id=?', [$ben]);
$bellsBefore = count($bells($famB));
$benLogged = $logged($famB);
[$oldBen, $newBen] = $putOn($famB, $ben, '7');
is_same($benLogged, $logged($famB), 'the family’s own picture ends no yes, so the consent log is left as it was');
is_same(1, (int)scalar('SELECT course_sees_picture FROM students WHERE id=?', [$ben]), 'the family’s own picture leaves its answer as it was');
is_same($bellsBefore, count($bells($famB)), 'and tells nobody');
ok(!is_file($dir.'/'.$oldBen), 'its old file goes too');
sign_in_as($famB);
act('picture_save', ['student_id'=>(string)$ben, 'remove'=>'1']);
is_same(['', 1], [(string)scalar('SELECT picture_name FROM students WHERE id=?', [$ben]), (int)scalar('SELECT course_sees_picture FROM students WHERE id=?', [$ben])],
        'removing empties the column and leaves the yes for the next picture');
ok(!is_file($dir.'/'.$newBen), 'and deletes the file at once');
is_same(['message'=>'Foto gelöscht.', 'kind'=>'success'], $_SESSION['flash'] ?? null, 'and says so');
$lineCount = (int)scalar("SELECT COUNT(*) FROM record_versions WHERE entity='students' AND entity_id=?", [$ben]);
act('picture_save', ['student_id'=>(string)$ben, 'remove'=>'1']);
is_same($lineCount, (int)scalar("SELECT COUNT(*) FROM record_versions WHERE entity='students' AND entity_id=?", [$ben]),
        'removing a picture that is not there writes no line');
throws(fn() => act('picture_save', ['student_id'=>(string)$mia, 'remove'=>'1']), 'another family’s child’s picture cannot be removed', 'nicht gefunden');
ok(is_file($dir.'/'.$new), 'and Mia’s is still there');
/* Twenty photos an hour per login, as for a receipt. Counted before the photo
   is read, so even a photo refused counts: making the square is what costs. */
run_counter('DELETE FROM rate_limits');
$_FILES = [];
for ($i = 1; $i <= 20; $i++)
    throws(fn() => act('picture_save', ['student_id'=>(string)$ben]), 'photo '.$i.' is read, and refused as no photo', 'Es wurde keine Datei ausgewählt.');
throws(fn() => act('picture_save', ['student_id'=>(string)$ben]), 'the twenty-first in an hour is refused before anything is read',
       'Zu viele Fotos in kurzer Zeit. In einer Stunde geht es wieder.');
act('picture_save', ['student_id'=>(string)$ben, 'remove'=>'1']);
ok(true, 'removing is not counted, and still works then');
run_counter('DELETE FROM rate_limits');

// ---------------------------------------------------------------------------
case_('Only the child’s own login says yes for the course; staff only take it back [ADR 0031 §8, test 7]');
$yes = fn(int $child): int => (int)scalar('SELECT course_sees_picture FROM students WHERE id=?', [$child]);
$consents = fn(int $account): array => rows("SELECT purpose, enabled, notice_version FROM consent_log WHERE account_id=? AND purpose IN ('course_sees_picture', 'course_sees_picture_by_parent') ORDER BY id", [$account]);
run('UPDATE students SET course_sees_picture=0, birth_date=? WHERE id=?', [date('Y-m-d', strtotime('-10 years')), $mia]);
$earlier = count($consents($famA));   // the yes the trainer's pictures ended, above
sign_in_as($famA);
act('picture_consent', ['student_id'=>(string)$mia, 'on'=>'1']);
is_same(1, $yes($mia), 'the family turns it on');
is_same([['purpose'=>'course_sees_picture_by_parent', 'enabled'=>1, 'notice_version'=>notice_version()]], array_slice($consents($famA), $earlier),
        'and the consent log keeps the yes with the notice’s version, as a parent’s: Mia is ten (§ 4 Abs. 4 DSG)');
is_same(['message'=>'Im Kurs-Chat sichtbar.', 'kind'=>'success'], $_SESSION['flash'] ?? null, 'the page says what it does now');
$line = rows("SELECT * FROM record_versions WHERE entity='students' AND entity_id=? ORDER BY id DESC LIMIT 1", [$mia])[0];
is_same([$famA, ['course_sees_picture']], [(int)$line['actor_id'], array_keys(version_changes($line))], 'and „Änderungen" who said it');
act('picture_consent', ['student_id'=>(string)$mia, 'on'=>'1']);
is_same(1, count($consents($famA)) - $earlier, 'said twice, it is kept once');
act('picture_consent', ['student_id'=>(string)$mia]);
is_same([0, 2], [$yes($mia), count($consents($famA)) - $earlier], 'turned off, a box left unticked, and logged as such');
is_same(['message'=>'Im Kurs-Chat ausgeblendet.', 'kind'=>'success'], $_SESSION['flash'] ?? null, 'and says so');
foreach ([$trainer => 'a trainer', $admin => 'an administrator'] as $staff => $who) {
    sign_in_as($staff);
    throws(fn() => act('picture_consent', ['student_id'=>(string)$mia, 'on'=>'1']), $who.' is refused turning it on: nobody says yes in a family’s place',
           'Einschalten kann nur die Familie');
    is_same(0, $yes($mia), 'and nothing changed');
}
throws(fn() => act('picture_consent', ['student_id'=>(string)$paul, 'on'=>'1']), 'nor for a child whose family has not signed in yet, whom nobody can speak for',
       'Einschalten kann nur die Familie');
sign_in_as($famA);
act('picture_consent', ['student_id'=>(string)$mia, 'on'=>'1']);
sign_in_as($trainer);
act('picture_consent', ['student_id'=>(string)$mia, 'on'=>'0']);
is_same(0, $yes($mia), 'staff take it back, for a family that asks on the phone');
$logged = $consents($famA);
is_same(['purpose'=>'course_sees_picture', 'enabled'=>0, 'notice_version'=>notice_version()], end($logged) ?: null,
        'logged on the family’s login, whose yes it was, under the purpose itself: staff are no parent');
is_same($trainer, (int)scalar("SELECT actor_id FROM record_versions WHERE entity='students' AND entity_id=? ORDER BY id DESC LIMIT 1", [$mia]),
        'with the trainer as who took it back');
sign_in_as($famB);
throws(fn() => act('picture_consent', ['student_id'=>(string)$mia, 'on'=>'1']), 'another family cannot speak for the child', 'nicht gefunden');
sign_in_as($famA);
act('picture_consent', ['student_id'=>(string)$mia, 'on'=>'1']);
view_as($admin, $famA);
foreach (['picture_consent' => ['student_id'=>(string)$mia, 'on'=>'0'], 'picture_save' => ['student_id'=>(string)$mia, 'remove'=>'1']] as $action => $fields)
    throws(fn() => act($action, $fields), $action.' is refused while viewing as the family', viewing_refusal());
is_same(1, $yes($mia), 'and the yes is as the family left it');
ok((string)scalar('SELECT picture_name FROM students WHERE id=?', [$mia]) !== '', 'and so is the picture');
sign_in_as($admin);
set_setting('pictures_in_course', false);
sign_in_as($famA);
act('picture_consent', ['student_id'=>(string)$mia, 'on'=>'0']);
is_same(0, $yes($mia), 'with the club’s setting off, a family may still take its yes back');
throws(fn() => act('picture_consent', ['student_id'=>(string)$mia, 'on'=>'1']), 'but not give one', 'Der Verein zeigt im Kurs-Chat keine Fotos.');
set_setting('pictures_in_course', true);
/* From 14 the child says yes alone; under 14, or with no birth date to tell,
   a parent does, through the family's login (§ 4 Abs. 4 DSG; the owner,
   2026-10-08). Asked of the birth date on the day of the yes. */
$aged = fn(?string $when): array => ['birth_date' => $when === null ? null : date('Y-m-d', strtotime($when))];
is_same([true, false, false, true, true],
        [needs_a_parents_yes($aged('-14 years +1 day')), needs_a_parents_yes($aged('-14 years')), needs_a_parents_yes($aged('-40 years')),
         needs_a_parents_yes($aged(null)), needs_a_parents_yes(['birth_date' => '2016-02-30'])],
        'needs_a_parents_yes(): a parent’s the day before the 14th birthday, the child’s own on it and after, and a parent’s without a birth date or with one that is none');
$ages = ['14 today'          => [date('Y-m-d', strtotime('-14 years')), '', 'her own'],
         'fifteen'           => [date('Y-m-d', strtotime('-15 years')), '', 'her own'],
         'a day short of 14' => [date('Y-m-d', strtotime('-14 years +1 day')), '_by_parent', 'a parent’s'],
         'of no known age'   => [null, '_by_parent', 'a parent’s']];
foreach ($ages as $age => [$born, $whose, $said]) {
    run('UPDATE students SET birth_date=?, course_sees_picture=0 WHERE id=?', [$born, $clara]);
    sign_in_as($famC);
    act('picture_consent', ['student_id'=>(string)$clara, 'on'=>'1']);
    $last = $consents($famC);
    is_same('course_sees_picture'.$whose, end($last)['purpose'] ?? null, 'Clara '.$age.': the yes is '.$said);
}
sign_in_as($admin);
set_setting('consent_age', 16);
ok(needs_a_parents_yes($aged('-15 years')) && !needs_a_parents_yes($aged('-16 years')), 'the age follows consent_age: at 16, fifteen is a parent’s and sixteen the child’s own');
run('UPDATE students SET birth_date=?, course_sees_picture=0 WHERE id=?', [date('Y-m-d', strtotime('-15 years')), $clara]);
sign_in_as($famC);
act('picture_consent', ['student_id'=>(string)$clara, 'on'=>'1']);
$last = $consents($famC);
is_same('course_sees_picture_by_parent', end($last)['purpose'] ?? null, 'where the club has set 16 (Germany), at fifteen it is a parent’s');
sign_in_as($admin);
set_setting('consent_age', 14);
sign_in_as($famC);
run('UPDATE students SET birth_date=? WHERE id=?', [date('Y-m-d', strtotime('-14 years +1 day')), $clara]);
run('UPDATE students SET course_sees_picture=0 WHERE id=?', [$clara]);
act('picture_consent', ['student_id'=>(string)$clara, 'on'=>'1']);
run('UPDATE students SET birth_date=? WHERE id=?', [date('Y-m-d', strtotime('-15 years')), $clara]);
picture_audience_clear();
sign_in_as($famA);
run('UPDATE classes SET archived=0 WHERE id=?', [$summer]);
ok(str_contains(avatar(pictures_row($clara)), '<img '), 'a parent’s yes stays when the child turns 14: nobody is asked again');
run('UPDATE classes SET archived=1 WHERE id=?', [$summer]);
is_same(1, $yes($clara), 'Clara’s family has said yes');
sign_in_as($admin);
transactional(fn() => replace_login_with_placeholder(pictures_row($clara), one('SELECT * FROM accounts WHERE id=?', [$famC])));
is_same(0, $yes($clara), 'and once her login is replaced with a placeholder the yes has ended: whoever gave it no longer holds the login');

// ---------------------------------------------------------------------------
case_('A child deleted, or the example data cleared, takes the pictures with it at once; the prune takes what nothing names [ADR 0031 §9, test 8]');
sign_in_as($trainer);
$leaving = make_student(['first_name'=>'Lea', 'last_name'=>'Los']);
$leavingPicture = pictures_put($leaving, '1');
act('student_delete', ['id'=>(string)$leaving, 'confirmation'=>'Lea Los']);
ok(!one('SELECT id FROM students WHERE id=?', [$leaving]), 'the child is deleted');
ok(!is_file($dir.'/'.$leavingPicture), 'and the picture with it, at once, not at night');
$demo = make_student(['first_name'=>'Bea', 'last_name'=>'Beispiel', 'is_demo'=>1]);
$demoPicture = pictures_put($demo, '2');
$kept = pictures_put($clara, '3');
transactional(fn() => demo_clear());
ok(!is_file($dir.'/'.$demoPicture), 'clearing the example data deletes the example children’s pictures at once');
ok(is_file($dir.'/'.$kept), 'and no real child’s');
ok(isset(upload_references()['picture']), 'the prune knows the picture folder');
$orphan = str_repeat('4', 32).'.jpg';
foreach ([$orphan, $kept] as $name) { file_put_contents($dir.'/'.$name, 'x'); touch($dir.'/'.$name, time() - 7200); }
db()->exec('CREATE TABLE IF NOT EXISTS `'.IMPORT_UNFINISHED_TABLE.'` (importing TINYINT NULL) ENGINE=InnoDB');
try {
    is_same(0, prune_uploads(), 'while a copy is being restored, the prune deletes nothing (ADR 0029)');
} finally {
    db()->exec('DROP TABLE IF EXISTS `'.IMPORT_UNFINISHED_TABLE.'`');
}
ok(is_file($dir.'/'.$orphan), 'the picture nothing names is still there then');
prune_uploads();
clearstatcache();
ok(!is_file($dir.'/'.$orphan), 'once its grace is past, a picture nothing names goes');
ok(is_file($dir.'/'.$kept), 'and one a child has stays');

// ---------------------------------------------------------------------------
case_('Without gd, a picture is refused as the server’s problem, and nothing is written [ADR 0031 §4, test 9]');
/* gd is faked away here, as fileinfo is for uploaded_file_type(): the PHP this
   runs on keeps its gd. store_upload() makes the square before it makes a
   folder or a name, so a refusal there leaves nothing behind. */
$photo = test_run_dir().'/gd-probe.jpg';
file_put_contents($photo, (function (): string { ob_start(); imagejpeg(imagecreatetruecolor(400, 300)); return (string)ob_get_clean(); })());
sign_in_as($famA);
throws(fn() => square_picture($photo, 'image/jpeg', false), 'a family is told it is the server’s, and that an administrator sets it up',
       'Fotos gehen auf diesem Server noch nicht. Das richtet eine Administratorin ein.');
sign_in_as($admin);
throws(fn() => square_picture($photo, 'image/jpeg', false), 'an administrator where to look',
       'Fotos gehen auf diesem Server noch nicht. Unter „Einstellungen“ → „System“ steht, was fehlt.');
ok(str_starts_with(square_picture($photo, 'image/jpeg', true), "\xFF\xD8"), 'and with gd the same photo becomes a picture');
unlink($photo);
$stores = new ReflectionFunction('store_upload');
$storing = implode('', array_slice(file($stores->getFileName()), $stores->getStartLine() - 1, $stores->getEndLine() - $stores->getStartLine() + 1));
$squared = strpos($storing, "square_picture((string)\$file['tmp_name'], \$mime)");
ok($squared !== false && $squared < (int)strpos($storing, '@mkdir(') && $squared < (int)strpos($storing, 'file_put_contents($path'),
   'store_upload() makes the square before it makes the folder or writes a byte');
is_same(pictures_possible(), function_exists('imagecreatefromstring'), 'pictures_possible() asks whether gd is there');

// ---------------------------------------------------------------------------
case_('A team member’s own picture: added, replaced and removed by them, on Mein Konto, and gone with the login [ADR 0031; the owner, 2026-10-08]');
/* The families see the team's faces too. Only a team member has a picture of
   their own; a student's login never has one - the child's is on the child. */
$teamDir = upload_dir('picture');
$coach = make_account(['role'=>'trainer', 'name'=>'Carla Coach']);
sign_in_as($coach);
$first = str_repeat('8', 32).'.jpg';
file_put_contents($teamDir.'/'.$first, 'a square JPEG');
transactional(fn() => write_team_picture($coach, $first));
is_same($first, (string)scalar('SELECT picture_name FROM accounts WHERE id=?', [$coach]), 'a team member’s picture is on her login');
$line = rows("SELECT * FROM record_versions WHERE entity='accounts' AND entity_id=? ORDER BY id DESC LIMIT 1", [$coach])[0] ?? [];
is_same([$coach, ['picture_name']], [(int)($line['actor_id'] ?? 0), array_keys(version_changes($line))], '„Änderungen" says she changed her picture');
$second = str_repeat('9', 31).'8.jpg';
file_put_contents($teamDir.'/'.$second, 'another square JPEG');
transactional(fn() => write_team_picture($coach, $second));
ok(!is_file($teamDir.'/'.$first) && is_file($teamDir.'/'.$second), 'replaced, the old file goes at once');
is_same(['profile', ['#'=>'picture']], act('picture_save', ['kind'=>'account', 'remove'=>'1']), 'removed on Mein Konto, back to the picture there');
ok((string)scalar('SELECT picture_name FROM accounts WHERE id=?', [$coach]) === '' && !is_file($teamDir.'/'.$second), 'the column empties and the file goes');
is_same(['message'=>'Foto gelöscht.', 'kind'=>'success'], $_SESSION['flash'] ?? null, 'and the page says so');
sign_in_as($famA);
throws(fn() => act('picture_save', ['kind'=>'account', 'remove'=>'1']), 'a family has no picture of its own to save', 'Nur das Team hat ein eigenes Foto');
throws(fn() => transactional(fn() => write_team_picture($famA, $first)), 'and the writer refuses one on a family’s login, whoever asks', 'Nur das Team hat ein eigenes Foto');
sign_in_as($coach);
file_put_contents($teamDir.'/'.$first, 'a square JPEG');
transactional(fn() => write_team_picture($coach, $first));
sign_in_as($admin);
act('account_state', ['id'=>(string)$coach, 'mode'=>'delete', 'confirmation'=>(string)scalar('SELECT email FROM accounts WHERE id=?', [$coach])]);
ok(!one('SELECT id FROM accounts WHERE id=?', [$coach]), 'deleted on Zugänge, the login is gone');
ok(!is_file($teamDir.'/'.$first), 'and her picture with it, at once (delete_login())');
$demoCoach = make_account(['role'=>'trainer', 'name'=>'Beispiel Trainer', 'is_demo'=>1]);
$demoFace = str_repeat('7', 31).'8.jpg';
file_put_contents($teamDir.'/'.$demoFace, 'x');
run('UPDATE accounts SET picture_name=? WHERE id=?', [$demoFace, $demoCoach]);
transactional(fn() => demo_clear());
ok(!is_file($teamDir.'/'.$demoFace), 'clearing the example data deletes an example login’s picture at once');
is_same(["SELECT picture_name AS name FROM students WHERE picture_name<>''", "SELECT picture_name AS name FROM accounts WHERE picture_name<>''"],
        upload_references()['picture'], 'and the prune knows the team’s pictures beside the children’s');
touch($teamDir.'/'.$teamPicture, time() - 7200);
prune_uploads();
ok(is_file($teamDir.'/'.$teamPicture), 'so the trainer’s picture, which her login names, stays');

// ---------------------------------------------------------------------------
case_('„Dein Foto“: the first password leads to the picture, and saved or skipped, on to where it landed before: a family to its child’s page [ADR 0031; the owner, 2026-10-08]');
$routerText = (string)file_get_contents(APP_ROOT.'/public/index.php');
ok(preg_match("/\\\$allowed=\\[[^\\]]*'welcome'/", $routerText) === 1, 'the step is a page of the portal, for whoever is signed in');
set_setting('privacy_ready', true);
$newcomer = make_account(['email'=>'neu.im.verein@beispiel.test', 'state'=>'invited', 'verified_at'=>null, 'password_hash'=>null]);
$newChild = make_student(['email'=>'neu.im.verein@beispiel.test', 'account_id'=>$newcomer, 'first_name'=>'Nele', 'last_name'=>'Neu']);
sign_out();
run_counter('DELETE FROM rate_limits');
$_SESSION['activation_hash'] = hash('sha256', make_token($newcomer, 'invite'));
is_same(['welcome', []], submit('activate', ['password'=>'Federball-2026-Halle!', 'password_confirm'=>'Federball-2026-Halle!', 'privacy_seen'=>'1']),
        'a family setting up its login lands on „Dein Foto“, for the child’s picture');
is_same('Dein Konto ist bereit. Du meldest dich ab jetzt mit neu.im.verein@beispiel.test an.', $_SESSION['flash']['message'] ?? null,
        'told how it signs in from now on, and no more - the step itself says welcome, and the child’s page what is left (ADR 0023 §5, ADR 0031)');
$newTrainer = make_account(['role'=>'trainer', 'email'=>'neu.im.team@beispiel.test', 'state'=>'invited', 'verified_at'=>null, 'password_hash'=>null]);
sign_out();
run_counter('DELETE FROM rate_limits');
$_SESSION['activation_hash'] = hash('sha256', make_token($newTrainer, 'invite'));
is_same(['welcome', []], submit('activate', ['password'=>'Federball-2026-Halle!', 'password_confirm'=>'Federball-2026-Halle!', 'privacy_seen'=>'1']),
        'and so does a team member, for their own');
is_same(landing_after_sign_in(current_user()), act('picture_save', ['kind'=>'account', 'remove'=>'1', 'to'=>'landing']),
        'saved there, the team member’s goes on to where a first password landed before: landing_after_sign_in()');
sign_in_as($newcomer);
is_same(['student', ['id'=>$newChild]], act('picture_save', ['student_id'=>(string)$newChild, 'remove'=>'1', 'to'=>'landing']),
        'and the family’s to its child’s page');
is_same(['student', ['id'=>$newChild, '#'=>'picture']], act('picture_save', ['student_id'=>(string)$newChild, 'remove'=>'1']),
        'from the child’s page, back to the picture there');
$step = render_view('welcome', []);
ok(str_contains($step, 'value="picture_save"') && !str_contains($step, 'picture_consent'), 'the step offers the picture alone: the course’s switch waits on the child’s page');
/* Asked once, at the first password: a new password from a reset link, a new
   address confirmed and a later sign-in land where they always did. */
sign_out();
run_counter('DELETE FROM rate_limits');
$_SESSION['activation_hash'] = hash('sha256', make_token($newcomer, 'reset'));
$landing = landing_after_sign_in(one('SELECT * FROM accounts WHERE id=?', [$newcomer]));
is_same($landing, submit('activate', ['password'=>'Federball-2027-Halle!', 'password_confirm'=>'Federball-2027-Halle!']),
        'a new password from a reset link lands where a sign-in does, not on „Dein Foto“');
$_SESSION['activation_hash'] = hash('sha256', make_token($newcomer, 'email', 'neu.im.verein.2@beispiel.test'));
is_same($landing, act('activate', []), 'and so does a new address confirmed');
sign_out();
run_counter('DELETE FROM rate_limits');
is_same($landing, submit('login', ['login'=>'neu.im.verein.2@beispiel.test', 'password'=>'Federball-2027-Halle!']), 'and so does every later sign-in');
run_counter('DELETE FROM rate_limits');
/* Where this server cannot make a picture there is no step, and the banner says
   the welcome the step's title would have: once, by the step or by the banner
   (ADR 0023 §5, ADR 0031). The suite's PHP has gd, so the landing is asked
   without it, as square_picture() is. */
sign_in_as($newcomer);
is_same(['student', ['id'=>$newChild]], first_password_landing(current_user(), false), 'without gd a family’s first password lands on its child’s page: there is no step');
is_same('Dein Konto ist bereit. Du meldest dich ab jetzt mit neu.im.verein.2@beispiel.test an. Willkommen, Nele! Schau kurz, ob alles stimmt, und ergänze, was fehlt. Frag deine Eltern, wenn du etwas nicht weißt.',
        $_SESSION['flash']['message'] ?? null, 'so the banner says welcome');
is_same(['welcome', 'Dein Konto ist bereit. Du meldest dich ab jetzt mit neu.im.verein.2@beispiel.test an.'],
        [first_password_landing(current_user(), true)[0], $_SESSION['flash']['message'] ?? null], 'with gd the step says it, and the banner does not');
sign_in_as($newTrainer);
is_same([landing_after_sign_in(current_user()), 'Dein Konto ist bereit. Du meldest dich ab jetzt mit neu.im.team@beispiel.test an.'],
        [first_password_landing(current_user(), false), $_SESSION['flash']['message'] ?? null], 'a team member without gd lands where every sign-in does, told how they sign in');
ok(str_contains(named_blocks_of(APP_ROOT.'/app/actions.php')['first_password_landing'] ?? '', 'return landing_after_first_password($signed);')
   && str_contains(named_blocks_of(APP_ROOT.'/app/actions_config.php')['picture_save'] ?? '', "if(post('to')==='landing') return landing_after_first_password(\$u);"),
   'a host without gd and a photo saved on the step go on by one rule, landing_after_first_password()');

// ---------------------------------------------------------------------------
case_('A face tapped at attendance saves the marks made so far, and the photo comes back to the list [the screens’ spec, §2]');
/* The face of a child without a photo is a button of the attendance form:
   a photo is a page load of its own, so what she ticked is saved first. With
   nothing ticked, or a day still to come, nothing is saved and nothing said. */
sign_in_as($trainer);
$today = today();
$marks = fn(): int => (int)scalar('SELECT COUNT(*) FROM attendance WHERE class_id=? AND session_on=?', [$course, $today]);
unset($_SESSION['flash']);
is_same(['attendance', ['id'=>$course, 'on'=>$today, 'photo'=>$noPicture]],
        act('attendance_save', ['class_id'=>(string)$course, 'session_on'=>$today, 'photo'=>(string)$noPicture, 'present'=>[(string)$ben=>'', (string)$noPicture=>'']]),
        'nothing ticked: back to the list for the same course and day, with the sheet for the child tapped');
is_same([0, null], [$marks(), $_SESSION['flash'] ?? null], 'nothing saved, and no „0 Einträge" said');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
is_same(['attendance', ['id'=>$course, 'on'=>$tomorrow, 'photo'=>$noPicture]],
        act('attendance_save', ['class_id'=>(string)$course, 'session_on'=>$tomorrow, 'photo'=>(string)$noPicture, 'present'=>[(string)$ben=>'present']]),
        'a day still to come: neither saved nor refused, on to the sheet');
is_same([0, null], [(int)scalar('SELECT COUNT(*) FROM attendance WHERE session_on=?', [$tomorrow]), $_SESSION['flash'] ?? null], 'and nothing written or said');
throws(fn() => act('attendance_save', ['class_id'=>(string)$course, 'session_on'=>$tomorrow, 'present'=>[(string)$ben=>'present']]),
       'saved as usual, a day still to come is refused as it always was', 'Das Datum liegt in der Zukunft.');
$present = array_key_first(attendance_statuses());
is_same(['attendance', ['id'=>$course, 'on'=>$today, 'photo'=>$noPicture]],
        act('attendance_save', ['class_id'=>(string)$course, 'session_on'=>$today, 'photo'=>(string)$noPicture, 'present'=>[(string)$ben=>$present]]),
        'one ticked: back to the sheet');
is_same(1, $marks(), 'with the mark saved first');
is_same('1 Eintrag gespeichert.', $_SESSION['flash']['message'] ?? null, 'and said as a save says it');
is_same(['classes', ['id'=>$course, 'tab'=>'attendance', 'on'=>$today]],
        act('attendance_save', ['class_id'=>(string)$course, 'session_on'=>$today, 'present'=>[(string)$ben=>$present]]),
        'the save button lands where it always did');
$_FILES = [];
run_counter('DELETE FROM rate_limits');
is_same(['attendance', ['id'=>$course, 'on'=>$today, 'photo'=>$noPicture]],
        act('picture_save', ['student_id'=>(string)$noPicture, 'class_id'=>(string)$course, 'on'=>$today]),
        'a photo the sheet could not take comes back to the sheet');
is_same(['message'=>'Es wurde keine Datei ausgewählt.', 'kind'=>'error'], $_SESSION['flash'] ?? null, 'with why, at the top');
throws(fn() => act('picture_save', ['student_id'=>(string)$noPicture, 'class_id'=>(string)$course, 'on'=>'2026-02-30']),
       'a day that is none is refused as any form refuses it', 'gültiges Datum');
run_counter('DELETE FROM rate_limits');

// ---------------------------------------------------------------------------
case_('The club decides whether a course sees pictures, on Datenschutz, and from what age a child agrees alone [ADR 0031 §5, §8]');
$spec = setting_schema()['pictures_in_course'] ?? [];
is_same(['bool', true, 'privacy', 'Kinder im selben Kurs sehen Fotos'], [$spec['kind'] ?? null, $spec['default'] ?? null, $spec['group'] ?? null, $spec['label'][0] ?? null],
        'pictures_in_course is a switch on Datenschutz, on by default: the owner’s answer');
$age = setting_schema()['consent_age'] ?? [];
is_same(['int', 14, 13, 16, 'privacy', true], [$age['kind'] ?? null, $age['default'] ?? null, $age['min'] ?? null, $age['max'] ?? null, $age['group'] ?? null, $age['advanced'] ?? null],
        'consent_age is a number from 13 to 16, 14 unless set, under „Erweitert" on Datenschutz');
sign_in_as($admin);
act('defaults_registry_save', ['group'=>'privacy', 'set_consent_age'=>'16', 'to_page'=>'settings', 'to_tab'=>'privacy']);
is_same([false, 16], [(bool)setting('pictures_in_course'), (int)setting('consent_age')], 'an administrator saves the card: the switch left off is off, and the age is kept');
throws(fn() => act('defaults_registry_save', ['group'=>'privacy', 'set_pictures_in_course'=>'1', 'set_consent_age'=>'12']), 'an age below 13 is refused', 'zu klein');
act('defaults_registry_save', ['group'=>'privacy', 'set_pictures_in_course'=>'1', 'set_consent_age'=>'14']);
is_same([true, 14], [(bool)setting('pictures_in_course'), (int)setting('consent_age')], 'and both back as they were');
sign_in_as($trainer);
throws(fn() => act('defaults_registry_save', ['group'=>'privacy', 'set_consent_age'=>'14']), 'a trainer is refused it: it is a decision about families’ data', 'Nur für Administratoren');
ok(str_contains(setting_schema()['upload_max_kb']['hint'][0] ?? '', 'Profilfotos'), 'and the upload limit’s hint names profile photos again');

// What this suite put in the picture folder goes with it: the suites after it
// count what the prune finds there.
foreach (dir_entries(upload_dir('picture')) as $name) @unlink(upload_dir('picture').'/'.$name);
