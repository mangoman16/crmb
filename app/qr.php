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

/**
 * Build the QR payload for one amount owed, from the profile's template.
 *
 * The template is operator-editable so a different payload standard can be
 * dropped in without touching code. The default is EPC069-12, the SEPA credit
 * transfer format that European banking apps prefill a transfer from.
 */
function qr_payload(array $profile, int $amountCents, string $reference): string {
    $template = (string)($profile['qr_template'] ?? '');
    if (trim($template) === '') return '';
    $replacements = [
        '{recipient}' => (string)($profile['recipient'] ?? ''),
        '{iban}'      => preg_replace('/\s+/', '', (string)($profile['iban'] ?? '')) ?? '',
        '{bic}'       => strtoupper(trim((string)($profile['bic'] ?? ''))),
        '{currency}'  => strtoupper(trim((string)($profile['currency'] ?? 'EUR'))),
        // EPC expects a plain decimal point and no thousands separator.
        '{amount}'    => number_format($amountCents / 100, 2, '.', ''),
        '{reference}' => $reference,
    ];
    $payload = strtr($template, $replacements);
    // A CR would corrupt the line-oriented EPC payload; normalise to LF.
    return str_replace(["\r\n", "\r"], "\n", $payload);
}
