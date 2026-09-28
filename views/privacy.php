<article class="card prose"><h1><?=e(t('Datenschutzerklärung','Privacy notice'))?></h1>
<?php if(!setting('privacy_ready',false)): ?><div class="notice"><?=e(t('Die Datenschutzerklärung wird vom Betreiber noch eingerichtet. Einladungen sind bis dahin deaktiviert.','The operator is still preparing the privacy notice. Invitations are disabled until then.'))?></div><?php endif ?>
<?php /* Only the German notice is required (ADR 0011). An English reader is
         shown it, said to be German, and marked as German so a screen reader
         reads it in the right voice. */
$germanOnly=privacy_in_german_only();
if($germanOnly): ?><div class="notice"><?=e(t('Diese Datenschutzerklärung gibt es nur auf Deutsch. Wenn darin etwas unklar ist, frag bitte deine Trainerin.','This privacy notice is only available in German. If anything in it is unclear, please ask your coach.'))?></div><?php endif ?>
<div class="prewrap"<?=$germanOnly?' lang="de"':''?>><?=e(privacy_text()?:t('Noch keine Datenschutzerklärung hinterlegt.','No privacy notice has been entered yet.'))?></div><p class="muted"><?=e(t('Fassung','Version'))?> <?=e(notice_version())?></p><a class="text-link" href="<?=e(url($user?'dashboard':'login'))?>"><?=e(t('Zurück','Back'))?></a></article>
