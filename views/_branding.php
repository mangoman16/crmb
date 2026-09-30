<?php
/**
 * „Aussehen": the club's colours and the two switches for the name top left
 * (ADR 0013, 0014), on the Portal tab.
 *
 * The form is the settings form of the `branding` group, drawn by
 * _settings_registry.php like every other group, so a setting added to that
 * group appears here with no change to this file. What this adds is the
 * heading, the sentence that explains it, and a preview of the colours in use.
 *
 * The preview shows what is saved, not what is being typed: working a colour
 * out in the browser would need a style attribute, which the
 * Content-Security-Policy refuses, and a second copy of the colour rules in
 * JavaScript (ADR 0013 rejects both).
 */
$registryGroup='branding';
$registryCard=['id'=>'branding','head'=>function() use ($user): void { ?>
    <h2><?=e(t('Aussehen','Appearance'))?></h2>
    <p class="muted icon-intro"><?=e(t('Farben und wie der Name oben links steht – für alle, auch auf der Anmeldeseite. Leer heißt: die eingebaute Farbe. Ist eine Farbe für gut lesbare Schrift zu hell oder zu dunkel, passt das Portal sie an und zeigt dir hier, welche es verwendet.',
        'Colours and how the name is shown top left – for everyone, the sign-in page included. Empty means the built-in colour. If a colour is too light or too dark for readable text, the portal adjusts it and shows you here which one it uses.'))?></p>
    <?php /* Drawn with the page's own tokens, so it is the brand stylesheet
             that colours it, exactly as it colours the menu and the sign-in page. */ ?>
    <div class="brand-preview" aria-hidden="true">
        <figure class="brand-preview-tile brand-preview-menu">
            <figcaption><?=e(t('Menü','Menu'))?></figcaption>
            <?php brand_block($user,'sidebar',null); ?>
            <span class="brand-preview-entry is-current"><?=icon('home')?><?=e(t('Übersicht','Overview'))?></span>
            <span class="brand-preview-entry"><?=icon('users')?><?=e(t('Schüler','Students'))?></span>
        </figure>
        <figure class="brand-preview-tile brand-preview-public">
            <figcaption><?=e(t('Anmeldeseite','Sign-in page'))?></figcaption>
            <?php brand_block(null,'public',null); ?>
            <span class="brand-preview-muted"><?=e(t('So liest sich grauer Text.','This is how grey text reads.'))?></span>
        </figure>
    </div>
    <p class="muted brand-preview-caption"><?=e(t('So sieht es gerade aus. Neue Farben erscheinen hier nach dem Speichern.','This is how it looks now. New colours show here after saving.'))?>
    <?php if(($user['accent']??'')!==''): ?> <?=e(t('Du selbst siehst auf Knöpfen und Links deine eigene Farbe aus „Mein Konto“.','You yourself see your own colour from “My account” on buttons and links.'))?><?php endif ?></p>
<?php }];
require ROOT.'/views/_settings_registry.php';
unset($registryGroup,$registryCard);
