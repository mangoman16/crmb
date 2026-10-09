<?php
declare(strict_types=1);

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Render arbitrary text as an inline SVG QR code.
 *
 * Inline SVG rather than an <img>: the CSP allows no external images, and an
 * inline element inherits currentColor so the code stays legible in dark mode.
 * Nothing leaves the server - no QR web service is involved.
 */
function qr_svg(string $payload, int $size = 220): string {
    if ($payload === '') return '';
    // Level M tolerates a little print smudging while keeping the grid coarse
    // enough for a phone camera to lock on at screen size.
    $writer = new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd()));
    $svg = $writer->writeString($payload, 'UTF-8', ErrorCorrectionLevel::M());
    // Strip the XML prolog so the fragment can be embedded in an HTML document.
    $svg = preg_replace('/<\?xml[^>]*\?>\s*/', '', $svg) ?? $svg;
    return trim($svg);
}

/** A template as it is stored and read: its lines ended by LF, whatever the browser posted. */
function qr_template_lines(string $template): string {
    return str_replace(["\r\n", "\r"], "\n", $template);
}

/**
 * Whether a template makes a SEPA credit transfer by EPC069-12 into the
 * profile's own account: BCD on its first line, and on its sixth and seventh
 * exactly {recipient} and {iban} - the name and the IBAN the form shows,
 * valid_iban() checked, the change log keeps and the administrators are told
 * about (ADR 0025, amended 2026-10-08). The template is text that the trainer's
 * login may change: without this its code could open a web page, or pay an
 * IBAN of its own. The one check for profile_save, which refuses such a
 * template, and for qr_payload(), which draws no code from one saved before.
 */
function qr_template_pays_profile(string $template): bool {
    $lines = explode("\n", qr_template_lines($template));
    return $lines[0] === 'BCD' && ($lines[5] ?? null) === '{recipient}' && ($lines[6] ?? null) === '{iban}';
}

/**
 * Build the QR payload for one amount owed, from the profile's template, or ''
 * for no code at all: without a template, or with one that is not a transfer
 * into the profile's account (qr_template_pays_profile()).
 *
 * The template is editable so the lines a bank reads can follow its quirks
 * without touching code. The default is EPC069-12, the SEPA credit transfer
 * format that European banking apps prefill a transfer from.
 */
function qr_payload(array $profile, int $amountCents, string $reference): string {
    $template = (string)($profile['qr_template'] ?? '');
    if (trim($template) === '' || !qr_template_pays_profile($template)) return '';
    $replacements = [
        '{recipient}' => (string)($profile['recipient'] ?? ''),
        '{iban}'      => preg_replace('/\s+/', '', (string)($profile['iban'] ?? '')) ?? '',
        '{bic}'       => strtoupper(trim((string)($profile['bic'] ?? ''))),
        '{currency}'  => strtoupper(trim((string)($profile['currency'] ?? 'EUR'))),
        // EPC expects a plain decimal point and no thousands separator.
        '{amount}'    => number_format($amountCents / 100, 2, '.', ''),
        '{reference}' => $reference,
    ];
    // Each value on one line: a line break in the recipient's name would move
    // the IBAN down and put a line of its own in its place.
    $replacements = array_map(fn(string $value): string => preg_replace('/[\r\n]+/', ' ', $value) ?? '', $replacements);
    // A CR would corrupt the line-oriented EPC payload; normalise to LF.
    return qr_template_lines(strtr($template, $replacements));
}
