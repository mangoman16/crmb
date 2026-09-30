<?php
/**
 * The club's logo (ADR 0014): how it looks, and the way to change or remove it.
 *
 * Its own card with its own forms, beside the settings form rather than inside
 * it, like the icon's: a form inside a form is thrown away by the browser. The
 * switches for the name next to it are on the „Aussehen" card, because they
 * are saved with the colours.
 */
$logoUrl=portal_logo_url();
[$logoWidth,$logoHeight]=$logoUrl!==''?portal_logo_size():[0,0];
?>
<section class="card" id="logo">
    <h2><?=e(t('Logo','Logo'))?></h2>
    <p class="muted icon-intro"><?=e(t('Erscheint oben links im Menü, auf der Anmeldeseite und auf dem Handy oben in der Leiste. Ohne Logo steht dort das Symbol des Portals, ohne Symbol ein „B“.',
        'Shown top left in the menu, on the sign-in page and in the bar at the top on a phone. Without a logo the portal icon is shown there, without an icon a “B”.'))?></p>
    <div class="avatar-editor">
        <figure class="logo-preview">
            <?php if($logoUrl!==''): ?>
            <span class="brand-plate"><img class="brand-logo" src="<?=e($logoUrl)?>" alt="<?=e(t('Das Logo','The logo'))?>" width="<?=(int)$logoWidth?>" height="<?=(int)$logoHeight?>"></span>
            <?php else: ?>
            <span class="logo-empty"><?=e(t('Noch kein Logo','No logo yet'))?></span>
            <?php endif ?>
        </figure>
        <div>
            <?php start_form('portal_logo_save',[],'form',true);
            file_field('logo',t('Bild auswählen','Choose a picture'),'logo',
                t('PNG, JPEG oder WebP, mindestens ','PNG, JPEG or WebP, at least ').PORTAL_LOGO_MIN_HEIGHT
                .t(' Pixel hoch. Höchstens fünfmal so breit wie hoch und höchstens doppelt so hoch wie breit. Das Logo steht immer auf einer weißen Fläche.',
                   ' pixels tall. At most five times as wide as it is tall, and at most twice as tall as it is wide. The logo always sits on a white background.'));
            submit_button(t('Logo speichern','Save the logo'),'secondary'); ?></form>
            <?php if($logoUrl!==''): ?>
            <?php start_form('portal_logo_save',['remove'=>1],'form logo-remove');
            submit_button(t('Logo entfernen','Remove the logo'),'subtle danger-text'); ?>
            <p class="muted"><?=e(t('Die Datei wird gelöscht. Zum Zurückholen einfach wieder hochladen.','The file is deleted. To get it back, upload it again.'))?></p></form>
            <?php endif ?>
        </div>
    </div>
</section>
