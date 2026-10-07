<?php
/* The link card (ADR 0023 §6, A5): a sign-in link its maker can still share -
   the QR code, the link to copy, the sign-in name and when it lapses. Shown on
   the wizard's done page and on the access card, only to the member of staff
   who made it and only while it still works (signin_link_shown()); afterwards
   it cannot be shown again, and a new one is made instead. It only reads.

   The includer sets $signinLogin (the login's row), $signinFirstName and
   $signinStudentId. Built by backend-dev as the working minimum; frontend-dev
   gives it the designer's card (spec §3). */
$shown=signin_link_shown((int)$signinLogin['id']);
if($shown):
    $linkId='signin-link-'.(int)$signinLogin['id']; ?>
<div class="pay-box signin-link-card">
    <div class="pay-qr"><?=qr_svg($shown['link'],200)?></div>
    <div class="pay-details">
        <dl class="facts">
            <div><dt><?=e(t('Benutzername','Username'))?></dt><dd class="mono"><?=e(sign_in_name($signinLogin))?></dd></div>
            <div><dt><?=e(t('Gilt einmal, bis','Works once, until'))?></dt><dd><?=e(fmt_datetime($shown['expires_at']))?></dd></div>
        </dl>
        <p><?=e(strtr(t('{name} scannt den Code mit der Handykamera – oder du schickst den Link an {name} oder die Eltern.',
                        '{name} scans the code with a phone camera – or you send the link to {name} or the parents.'),['{name}'=>$signinFirstName]))?></p>
        <div class="support-copy">
            <label for="<?=e($linkId)?>"><?=e(t('Der Link','The link'))?></label>
            <textarea id="<?=e($linkId)?>" class="support-text" readonly rows="3"><?=e($shown['link'])?></textarea>
            <div class="row-actions">
                <button type="button" class="button secondary" data-copy-from="<?=e($linkId)?>" hidden><?=e(t('Link kopieren','Copy link'))?></button>
                <span class="copy-status" data-copy-status role="status" hidden><?=e(t('Kopiert','Copied'))?></span>
            </div>
        </div>
        <div class="notice warn"><strong><?=e(strtr(t('Wer diesen Link hat, kann sich einmal als {name} anmelden.','Whoever has this link can sign in once as {name}.'),['{name}'=>$signinFirstName]))?></strong>
            <p><?=e(strtr(t('Schick ihn nur an {name} oder die Eltern, nicht in eine Gruppe. Später lässt er sich nicht mehr anzeigen – dann erstellst du einen neuen.',
                            'Send it only to {name} or the parents, not to a group. It cannot be shown again later – then you create a new one.'),['{name}'=>$signinFirstName]))?></p></div>
        <?php start_form('signin_link',['student_id'=>(int)$signinStudentId,'mode'=>'withdraw'],'inline-form');submit_button(t('Link zurückziehen','Withdraw the link'),'subtle danger-text');?></form>
    </div>
</div>
<?php endif ?>
