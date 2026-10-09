<?php
/* „Mehr", staff's fifth place on a phone's bar (ADR 0028): the places the bar
   has no room for, then Mein Konto, privacy and help, and „Abmelden". The
   places are worked out from the full menu (more_entries()), so an entry or a
   count added there arrives here by itself; they are never listed a second
   time. On a computer the sidebar holds them, and this page is harmless there.
   It only reads. */
page_head(t('Mehr','More'));
if($places=more_entries($user)): ?>
<section class="card">
<?php foreach($places as $place) echo nav_link($place,'more',$user,true); ?>
</section>
<?php endif ?>
<section class="card">
    <a class="editor-list-item nav-row" href="<?=e(url('profile'))?>"><span><?=icon('person')?><strong><?=e(t('Mein Konto','My account'))?></strong><small><?=e(my_account_hint())?></small></span><?=icon('chevron')?></a>
</section>
<?php privacy_and_help_group(); ?>
<?php sign_out_row(); ?>
