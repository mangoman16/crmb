<?php
declare(strict_types=1);

/**
 * Colour arithmetic for the portal's own colours (ADR 0013).
 *
 * Pure: no database, no settings, no files. setting_validate() in defaults.php
 * needs the contrast check to refuse an unreadable background, and brand.php
 * needs all of it to work out the stylesheet, so this sits below both.
 *
 * Every colour that leaves this file is lower-case #rrggbb. That is the one
 * shape the generated stylesheet accepts, so nothing typed into a settings box
 * can reach the CSS without having been through colour_normalise() first.
 */

/** The dark ink the dark scheme prints on the main colour (app.css --on-accent). */
const COLOUR_DARK_INK = '#0b1218';

/**
 * A colour as lower-case #rrggbb, or null when it is not one.
 *
 * Accepts #rgb and #rrggbb, with or without the #, in any case - what people
 * copy out of a style guide or a colour picker. Nothing else: no names, no
 * rgb(), and certainly nothing with a semicolon or a brace in it.
 */
function colour_normalise(string $value): ?string {
    $value = strtolower(trim($value));
    if (!preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/D', $value, $m)) return null;
    $hex = $m[1];
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    return '#' . $hex;
}

/** [r, g, b], 0 to 255, of a colour colour_normalise() accepts. */
function colour_channels(string $colour): array {
    $hex = colour_normalise($colour);
    if ($hex === null) throw new InvalidArgumentException('Not a colour: ' . $colour);
    return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
}

/** WCAG 2 relative luminance: 0 for black, 1 for white. */
function colour_luminance(string $colour): float {
    $linear = array_map(function (int $channel): float {
        $c = $channel / 255;
        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }, colour_channels($colour));
    return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
}

/** WCAG 2 contrast ratio, from 1 (the same) to 21 (black on white). Order does not matter. */
function colour_contrast(string $a, string $b): float {
    $la = colour_luminance($a);
    $lb = colour_luminance($b);
    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

/**
 * $a and $b blended, with $weightOfA of $a: 1 is $a, 0 is $b.
 *
 * The weight is clamped to 0..1, so a derivation that overshoots gives one of
 * the two colours rather than a channel below 0 or above 255 - which would
 * print as three hex digits, or seven, and break the rule every value in the
 * stylesheet is checked against.
 */
function colour_mix(string $a, string $b, float $weightOfA): string {
    $w = max(0.0, min(1.0, $weightOfA));
    $ca = colour_channels($a);
    $cb = colour_channels($b);
    $out = '#';
    for ($i = 0; $i < 3; $i++) $out .= sprintf('%02x', (int)round($ca[$i] * $w + $cb[$i] * (1 - $w)));
    return $out;
}

/** White or the dark ink, whichever reads better on $background. */
function colour_text_on(string $background): string {
    return colour_contrast($background, '#ffffff') >= colour_contrast($background, COLOUR_DARK_INK)
        ? '#ffffff' : COLOUR_DARK_INK;
}

/** Whether $colour reaches $min against every colour in $against. */
function colour_contrasts_with_all(string $colour, array $against, float $min): bool {
    foreach ($against as $other) if (colour_contrast($colour, $other) < $min) return false;
    return true;
}

/**
 * [hue 0..360, saturation 0..1, lightness 0..1] of a colour.
 *
 * HSL rather than a perceptual space such as OKLCH: at a fixed hue and
 * saturation every lightness from 0 to 1 is a colour a screen can show, so
 * moving the lightness never needs clipping back into range - which in OKLCH
 * shifts the hue of exactly the saturated club colours this is for.
 */
function colour_to_hsl(string $colour): array {
    [$r, $g, $b] = array_map(fn(int $c) => $c / 255, colour_channels($colour));
    $max = max($r, $g, $b); $min = min($r, $g, $b);
    $l = ($max + $min) / 2;
    $d = $max - $min;
    if ($d == 0) return [0.0, 0.0, $l];
    $s = $d / (1 - abs(2 * $l - 1));
    $h = match ($max) {
        $r => 60 * fmod(($g - $b) / $d + 6, 6),
        $g => 60 * (($b - $r) / $d + 2),
        default => 60 * (($r - $g) / $d + 4),
    };
    return [$h, min(1.0, $s), $l];
}

/** The #rrggbb of a hue, saturation and lightness; lightness is clamped to 0..1. */
function colour_from_hsl(float $h, float $s, float $l): string {
    $l = max(0.0, min(1.0, $l));
    $c = (1 - abs(2 * $l - 1)) * $s;
    $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
    $m = $l - $c / 2;
    [$r, $g, $b] = match (true) {
        $h < 60 => [$c, $x, 0], $h < 120 => [$x, $c, 0], $h < 180 => [0, $c, $x],
        $h < 240 => [0, $x, $c], $h < 300 => [$x, 0, $c], default => [$c, 0, $x],
    };
    return sprintf('#%02x%02x%02x', (int)round(($r + $m) * 255), (int)round(($g + $m) * 255), (int)round(($b + $m) * 255));
}

/**
 * $colour made darker or lighter just far enough to reach $min against every
 * colour in $against. $direction is 'darker' or 'lighter'.
 *
 * Only the lightness moves: hue and saturation stay, so a pale yellow becomes
 * a dark mustard rather than a grey olive, and she recognises her colour in
 * the result shown beside her choice. The smallest step that does it, so the
 * colour changes as little as readability needs. When even black or white
 * does not reach $min, that end is the best there is and is returned: the walk
 * stops at the end of the line rather than running on, or giving back the
 * colour that failed.
 */
function colour_until_contrast(string $colour, array $against, float $min, string $direction): string {
    if (!in_array($direction, ['darker', 'lighter'], true)) throw new InvalidArgumentException('darker or lighter, not '.$direction);
    [$h, $s, $l] = colour_to_hsl($colour);
    $end = $direction === 'darker' ? 0.0 : 1.0;
    $steps = 512;
    for ($i = 0; $i <= $steps; $i++) {
        $candidate = $i === 0 ? (string)colour_normalise($colour) : colour_from_hsl($h, $s, $l + ($end - $l) * $i / $steps);
        if (colour_contrasts_with_all($candidate, $against, $min)) return $candidate;
    }
    return $direction === 'darker' ? '#000000' : '#ffffff';
}
