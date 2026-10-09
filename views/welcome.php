<?php /* „Dein Foto" (ADR 0031, as amended): one optional question right after the
         first password, where the invitation's activation lands once - the
         child's picture for a family, one's own for a team member. Never the
         course switch, which waits on the child's page (§8). Nothing else leads
         here, so it is asked once; opened by hand it is the same harmless step.
         Saved or skipped, it goes on where a first password goes on without
         it (landing_after_first_password()), the one rule the activation and
         picture_save follow too: the child's page, or where a sign-in lands. */
$staff=is_staff($user);
$childId=$staff?0:login_student_id((int)$user['id']);
$own=$childId?student($childId):null;
// Whose picture this is: what avatar() draws, and what picture_save is told.
$who=$own??($staff?$user:null);
[$landPage,$landParams]=landing_after_first_password($user);
$already=$who!==null && has_picture($who);
$title=strtr(t('Willkommen, {name}!','Welcome, {name}!'),['{name}'=>greeting_first_name($user)]);
// A login with nothing to put a picture on, or a server that cannot make one, simply goes on.
if($who===null || !pictures_possible()):
    page_head($title); ?>
<section class="photo-step">
    <?=link_button(t('Weiter','Continue'),$landPage,$landParams)?>
</section>
<?php else:
page_head($title,$already?($staff?t('Dein Foto ist schon da.','Your photo is already there.'):t('Dein Trainerteam hat schon ein Foto von dir hinzugefügt.','Your coaches have already added a photo of you.'))
    :($staff?t('Möchtest du ein Foto? Die Familien sehen es in den Chats.','Would you like a photo? The families see it in the chats.')
        :t('Möchtest du ein Foto? Es ist freiwillig.','Would you like a photo? It is optional.')));
$hidden=($own?['student_id'=>$own['id']]:['kind'=>'account'])+['to'=>'landing']; ?>
<section class="photo-step">
    <?=avatar($who)?>
    <?php /* A photo the trainer already took is where a family first sees it:
             „Passt so" is the skip, „Anderes Foto" the way to their own. */
    if($already) echo link_button(t('Passt so','Looks good'),$landPage,$landParams,'primary photo-done');
    picture_form($hidden,'button '.($already?'secondary ':'').'picture-pick',function() use($already): void {
        echo icon('camera').e($already?t('Anderes Foto','Another photo'):t('Foto hinzufügen','Add a photo'));
    });
    if(!$already): ?>
    <a class="text-link photo-skip" href="<?=e(url($landPage,$landParams))?>"><?=e(t('Überspringen','Skip'))?></a>
    <?php endif ?>
    <p class="photo-note"><?=e($staff?t('Alle im Portal sehen es. Ändern kannst du es unter „Mein Konto“.','Everybody in the portal sees it. You change it under “My account”.')
        :t('Dein Trainerteam sieht es. Ob auch dein Kurs es sieht, entscheidest du später auf deiner Seite.','Your coaches see it. Whether your course sees it too, you decide later on your page.'))?></p>
</section>
<?php endif ?>
