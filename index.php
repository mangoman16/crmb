<?php
declare(strict_types=1);

/**
 * Only reached when the web root points at this folder and mod_rewrite is not
 * available, so the .htaccess beside this file could not send the request into
 * public/ itself. Redirecting keeps the portal usable on such hosting; the
 * address simply carries the /public suffix, which app_url then records.
 */
header('Location: public/', true, 302);
header('Cache-Control: no-store');
