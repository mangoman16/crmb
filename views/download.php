<?php
/**
 * What you see when a download did not happen.
 *
 * serve_download() answers with the file and leaves, so reaching this page means
 * the file could not be produced: a link to an invoice that was deleted, a
 * bookmark from before something was cancelled, or an address typed by hand.
 */
page_head(t('Datei nicht gefunden','File not found'),
          t('Diese Datei gibt es nicht oder sie gehört zu jemand anderem.','That file does not exist, or it belongs to somebody else.'));
?>
<div class="card">
    <p class="muted"><?=e(t('Rechnungen stehen beim jeweiligen Kind unter „Rechnungen“. Wenn der Link aus einer E-Mail stammt, ist die Rechnung vielleicht inzwischen storniert worden.','Invoices are on each child’s “Rechnungen” tab. If the link came from an email, the invoice may since have been cancelled.'))?></p>
    <?=link_button(t('Zur Übersicht','Back to the overview'),'dashboard')?>
</div>
