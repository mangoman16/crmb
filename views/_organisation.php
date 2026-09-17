<?php
/**
 * Who is running this, entered once.
 *
 * Used in two places that both legally require it: the privacy notice needs a
 * named controller, and an Austrian invoice needs the issuer's name and address
 * (§ 11 Abs 1 UStG). Typing it twice is how the two end up disagreeing.
 */
?>
<section class="card">
    <h2><?=e(t('Wer betreibt das Portal','Who runs this portal'))?></h2>
    <p class="muted"><?=e(t('Diese Angaben stehen auf jeder Rechnung und in der Datenschutzerklärung. Sie werden beim Ausstellen einer Rechnung festgeschrieben – eine spätere Änderung wirkt sich nur auf neue Rechnungen aus.','These details appear on every invoice and in the privacy notice. They are frozen into an invoice when it is issued – a later change affects only new invoices.'))?></p>
    <?php $problems=invoice_issuer_problems(); if($problems): ?>
    <div class="notice warn"><strong><?=e(t('Für Rechnungen fehlt noch:','Still missing for invoices:'))?></strong>
        <?php foreach($problems as $problem): ?><br><?=e($problem)?><?php endforeach ?></div>
    <?php else: ?>
    <div class="notice"><?=e(t('Vollständig. Rechnungen können ausgestellt werden.','Complete. Invoices can be issued.'))?></div>
    <?php endif ?>
</section>

<section class="card">
    <h2><?=e(t('So sieht es auf der Rechnung aus','How it looks on an invoice'))?></h2>
    <?php /* The preview is the same lines the PDF writes, in the same order, so
             a missing comma is visible here rather than on a document that has
             already gone to a family. */ ?>
    <div class="invoice-preview">
        <strong><?=e(setting('org_name')?:t('[Name fehlt]','[name missing]'))?></strong>
        <?php foreach([(string)setting('org_street'),
                       trim((string)setting('org_zip').' '.(string)setting('org_city')),
                       (string)setting('org_country'),
                       (string)setting('org_email'),
                       (string)setting('org_phone')] as $line):
            if(trim($line)==='')continue; ?>
        <span><?=e($line)?></span>
        <?php endforeach ?>
        <?php if(setting('org_vat_id')):?><span>UID: <?=e(setting('org_vat_id'))?></span><?php endif ?>
        <?php if(setting('org_register_no')):?><span><?=e(setting('org_register_no'))?></span><?php endif ?>
        <hr>
        <?php if(setting('org_tax_mode')==='vat'): ?>
        <span><?=e(t('Beträge enthalten ','Amounts include ').(int)setting('org_vat_rate').' % '.t('Umsatzsteuer.','VAT.'))?></span>
        <?php else: ?>
        <span><?=e(setting('org_tax_note'))?></span>
        <?php endif ?>
    </div>
    <p class="muted"><?=e(t('Rechtlicher Hintergrund: Eine österreichische Rechnung braucht Name und Anschrift des Ausstellers, Name des Empfängers, Art und Umfang der Leistung, Leistungszeitraum, Entgelt, Steuersatz und Steuerbetrag oder einen Hinweis auf die Steuerbefreiung, Ausstellungsdatum und eine fortlaufende Nummer (§ 11 Abs 1 UStG). Kleinunternehmer nach § 6 Abs 1 Z 27 UStG weisen keine Umsatzsteuer aus und müssen stattdessen auf die Befreiung hinweisen. Das Portal setzt all das aus diesen Feldern zusammen; ob die Einordnung stimmt, entscheidet die Steuerberatung.',
        'Legal background: an Austrian invoice needs the issuer’s name and address, the recipient’s name, the type and extent of the supply, the period of supply, the amount, the tax rate and tax amount or a note about the exemption, the issue date and a consecutive number (§ 11 Abs 1 UStG). A small business under § 6 Abs 1 Z 27 UStG shows no VAT and must state the exemption instead. The portal assembles all of that from these fields; whether the classification is right is a question for your accountant.'))?></p>
</section>
