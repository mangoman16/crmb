<?php
/**
 * The portal's own icon (ADR 0008): how it looks where people meet it - on a
 * home screen and in a browser tab - and the way to change it.
 *
 * Its own card with its own forms, below the settings form rather than inside
 * it: a form inside a form is markup the browser throws away, and a picture is
 * not one more value of that form anyway.
 */
$iconUrl=portal_icon_url(); $ownIcon=$iconUrl!=='';
$clubName=(string)setting('club_name','Badminton');
$iconMin=PORTAL_ICON_MIN_SIZE.' × '.PORTAL_ICON_MIN_SIZE;
?>
<section class="card">
    <h2><?=e(t('Symbol des Portals','Portal icon'))?></h2>
    <p class="muted icon-intro"><?=e(t('Erscheint im Browser-Tab und auf dem Home-Bildschirm.','Shown in the browser tab and on the home screen.'))?></p>
    <div class="avatar-editor">
        <?php /* The tile sits on the same grey in both appearances and has black
                 behind the picture, because black is what an iPhone puts where a
                 picture is see-through - and that should show up here, not on
                 somebody's phone. */ ?>
        <figure class="icon-preview">
            <span class="icon-home">
                <span class="icon-tile"><img src="<?=e($ownIcon?$iconUrl:asset_url('apple-touch-icon.png'))?>" alt="" width="60" height="60"></span>
                <span class="icon-home-name"><?=e($clubName)?></span>
            </span>
            <span class="icon-tab"><img src="<?=e($ownIcon?$iconUrl:asset_url('favicon.svg'))?>" alt="" width="16" height="16"><span><?=e($clubName)?></span></span>
            <?php if(!$ownIcon): ?><figcaption><?=e(t('Standard-Symbol','Standard icon'))?></figcaption><?php endif ?>
        </figure>
        <div>
            <?php start_form('portal_icon_save',[],'form',true);
            file_field('icon',t('Bild auswählen','Choose a picture'),'icon',
                t('Quadratisches PNG, am besten 512 × 512 Pixel (mindestens '.$iconMin.'). Durchsichtige Stellen zeigt das iPhone schwarz.',
                  'A square PNG, ideally 512 × 512 pixels (at least '.$iconMin.'). The iPhone shows transparent areas as black.'));
            submit_button(t('Symbol speichern','Save the icon'),'secondary'); ?></form>
            <?php if($ownIcon){
                start_form('portal_icon_save',['remove'=>1]);
                submit_button(t('Standard-Symbol verwenden','Use the standard icon'),'subtle');
                echo '</form>';
            } ?>
        </div>
    </div>
    <p class="muted icon-note"><?=e(t('Wer das Portal schon auf dem Home-Bildschirm hat, sieht das neue Symbol erst, wenn er es dort neu ablegt.','Anyone who already has the portal on their home screen sees the new icon only once they add it there again.'))?></p>
</section>
