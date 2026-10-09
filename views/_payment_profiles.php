<?php
/**
 * Where the money goes: the bank details behind the transfer QR code.
 *
 * Under Verwaltung rather than Einstellungen, because it is her account number
 * and her problem when it is wrong, not the administrator's.
 */
$editProfile=(int)($_GET['edit']??0);
$profile=$editProfile?one('SELECT * FROM payment_profiles WHERE id=?',[$editProfile]):null;
$sepa="BCD\n002\n1\nSCT\n{bic}\n{recipient}\n{iban}\n{currency}{amount}\n\n{reference}";
?>
<div class="settings-grid">
    <section class="card">
        <div class="section-heading"><h2><?=e(t('Zahlungsempfänger','Payment profiles'))?></h2><?=link_button(t('+ Neu','+ New'),'manage',['tab'=>'payments'],'secondary')?></div>
        <?php foreach(payment_profiles(true) as $row):?>
        <a class="editor-list-item <?=$editProfile===(int)$row['id']?'selected':''?>" href="<?=e(url('manage',['tab'=>'payments','edit'=>$row['id']]))?>">
            <span><strong><?=e($row['name'])?></strong><small class="mono"><?=e($row['iban']!==''?iban_groups($row['iban']):t('Keine IBAN hinterlegt','No IBAN set'))?></small></span>
            <?php if($row['archived'])badge(t('Archiviert','Archived'));else echo icon('chevron');?>
        </a>
        <?php endforeach ?>
        <p class="muted"><?=e(t('Ein Kurs kann einen eigenen Empfänger haben. Ohne eigenen wird der Standard aus „Vorgaben“ verwendet.','A class can have its own recipient. Without one, the default from “Defaults” is used.'))?></p>
    </section>
    <section class="card">
        <div class="section-heading"><h2><?=e($profile?t('Empfänger bearbeiten','Edit profile'):t('Empfänger anlegen','Create profile'))?></h2></div>
        <?php start_form('profile_save',['id'=>$editProfile]);?>
        <div class="grid two"><?php
        input('name',t('Bezeichnung','Label'),$profile['name']??'','text',true);
        input('recipient',t('Empfängername wie bei der Bank','Recipient name as held by the bank'),$profile['recipient']??setting('club_name'));
        input('iban','IBAN',$profile['iban']??'','text',false,t('Wird auf Prüfsumme kontrolliert.','Checked against its checksum.'));
        input('bic',t('BIC (optional)','BIC (optional)'),$profile['bic']??'');
        input('currency',t('Währung','Currency'),$profile['currency']??'EUR');
        ?></div>
        <?php input('note',t('Hinweis für Eltern','Note shown to parents'),$profile['note']??'');
        input('qr_template',t('Inhalt des QR-Codes','QR code contents'),$profile['qr_template']??$sepa,'textarea',false,
            t('Das SEPA-Format, das Bank-Apps lesen: „BCD“ in der ersten Zeile, {recipient} in der sechsten und {iban} in der siebten. Weitere Platzhalter: {bic} {currency} {amount} {reference}.',
              'The SEPA format banking apps read: “BCD” on the first line, {recipient} on the sixth and {iban} on the seventh. Other placeholders: {bic} {currency} {amount} {reference}.'));
        check_field('archived',t('Archivieren','Archive'),(bool)($profile['archived']??false),'',false,true);
        submit_button();?></form>
        <?php if($profile && $profile['iban']!==''): $demo=qr_payload($profile,4500,t('Beispiel','Example'));?>
        <h3><?=e(t('Vorschau','Preview'))?></h3>
        <p class="muted"><?=e(t('So sieht der Code für 45,00 € aus.','This is the code for 45.00.'))?></p>
        <div class="qr-plate"><?=$demo!==''?qr_svg($demo,170):''?></div>
        <?php /* A template saved before profile_save refused one: qr_payload()
                 draws no code from it, on this preview and on every family's
                 „Beiträge", and the form says why rather than leaving it blank. */
        if($demo==='' && trim((string)$profile['qr_template'])!==''): ?>
        <div class="notice warn"><?=e(t('Dieser Inhalt ergibt keinen QR-Code: Er muss mit „BCD“ beginnen und in der sechsten Zeile {recipient}, in der siebten {iban} haben.',
                                        'This content makes no QR code: it has to begin with “BCD” and have {recipient} on the sixth line and {iban} on the seventh.'))?></div>
        <?php endif ?>
        <?php endif ?>
    </section>
</div>

