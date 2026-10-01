<?php
declare(strict_types=1);

/**
 * app.css read as rules rather than as strings, so a test about the stylesheet
 * holds however it is formatted, and can ask which of two rules wins.
 *
 *   css_rules($css)            one row per declaration: ['media', 'selector',
 *                              'property', 'value', 'important', 'order']
 *   css_specificity($sel)      [ids, classes, elements] as the browser counts them
 *   css_matching($rules, $re)  the rows whose selector matches a pattern
 *   css_wins($over, $general)  whether one row beats another for the same element
 *   css_max_width($media)      the phone width an @media applies up to, or 0
 *
 * What it gets wrong, so a test built on it does not claim more than it knows:
 *   - A brace inside a quoted value (content:"}") ends the rule early. app.css
 *     has none; a rule written that way would be read wrongly.
 *   - css_wins() compares where and how strongly two rules apply, not *when*: an
 *     override that only applies to an open <details> is accepted against a
 *     general rule that applies open or shut. The test asking has to know the
 *     general rule only matters in the state the override covers.
 *   - css_max_width() reads only the max-width: "(max-width:760px) and
 *     (prefers-reduced-motion:reduce)" counts as the phone layout, though it
 *     applies to only some phones.
 *   - Nested at-rules are joined with a space and compared as text; a rule in
 *     @supports inside @media is its own media, applying nowhere else.
 *   - A shorthand is recognised by its prefix only: margin covers
 *     margin-bottom, but inset does not cover left, nor place-items align-items.
 *   - The attribute operators ~= and |= are taken for combinators.
 *   - Rows declared inside @keyframes read as rules for selectors "from"/"to".
 */

/** $text split at $separator where it is not inside quotes, brackets or parentheses. */
function css_split_top(string $text, string $separator): array {
    $parts = []; $depth = 0; $quote = null; $current = '';
    foreach (str_split($text) as $char) {
        if ($quote !== null) { $current .= $char; if ($char === $quote) $quote = null; continue; }
        if ($char === '"' || $char === "'") $quote = $char;
        elseif ($char === '(' || $char === '[') $depth++;
        elseif ($char === ')' || $char === ']') $depth--;
        elseif ($char === $separator && $depth === 0) { $parts[] = $current; $current = ''; continue; }
        $current .= $char;
    }
    $parts[] = $current;
    return array_values(array_filter(array_map('trim', $parts), fn($p) => $p !== ''));
}

/** One selector as written, with the spacing that does not matter taken out and ::before spelt :before. */
function css_normalise_selector(string $selector): string {
    return (string)preg_replace(['/\s*([>+~])\s*/', '/\s+/', '/::(before|after)\b/'], ['$1', ' ', ':$1'], trim($selector));
}

/**
 * Every declaration in $css, one row each. 'order' is the rule's place in the
 * file, shared by the declarations of one rule; 'media' is the enclosing
 * at-rules' preludes joined by a space, '' outside any.
 */
function css_rules(string $css): array {
    $css = (string)preg_replace('~/\*.*?\*/~s', '', $css);
    $rows = []; $media = []; $head = ''; $order = 0;
    for ($i = 0, $length = strlen($css); $i < $length; $i++) {
        $char = $css[$i];
        if ($char === '}') { array_pop($media); $head = ''; continue; }
        if ($char !== '{') { $head .= $char; continue; }
        // A statement at-rule (@import ...;) before this block is not part of it.
        $cut = strrpos($head, ';');
        $head = trim($cut === false ? $head : substr($head, $cut + 1));
        if (str_starts_with($head, '@')) { $media[] = $head; $head = ''; continue; }
        $end = strpos($css, '}', $i);
        if ($end === false) break;
        $order++;
        $selectors = array_map('css_normalise_selector', css_split_top($head, ','));
        foreach (css_split_top(substr($css, $i + 1, $end - $i - 1), ';') as $declaration) {
            if (!str_contains($declaration, ':')) continue;
            [$property, $value] = array_map('trim', explode(':', $declaration, 2));
            $important = preg_match('/\s*!\s*important\s*$/i', $value) === 1;
            foreach ($selectors as $selector)
                $rows[] = ['media' => implode(' ', $media), 'selector' => $selector, 'property' => strtolower($property),
                           'value' => trim((string)preg_replace('/\s*!\s*important\s*$/i', '', $value)),
                           'important' => $important, 'order' => $order];
        }
        $i = $end; $head = '';
    }
    return $rows;
}

/**
 * [ids, classes/attributes/pseudo-classes, elements/pseudo-elements] for one
 * selector. :is(), :not() and :has() count as their most specific argument,
 * :where() as nothing - as the browser counts them.
 */
function css_specificity(string $selector): array {
    $a = $b = $c = 0;
    $selector = (string)preg_replace_callback('/:(is|not|has|where)\(((?:[^()]|\((?2)\))*)\)/', function (array $m) use (&$a, &$b, &$c): string {
        if ($m[1] === 'where') return ' ';
        $best = [0, 0, 0];
        foreach (css_split_top($m[2], ',') as $inner) $best = max($best, css_specificity($inner));
        $a += $best[0]; $b += $best[1]; $c += $best[2];
        return ' ';
    }, $selector);
    $pseudoElement = '/::?(before|after|marker|placeholder|selection|first-line|first-letter|-webkit-[\w-]+)\b/';
    $b += preg_match_all('/\[[^\]]*\]/', $selector);  $selector = (string)preg_replace('/\[[^\]]*\]/', ' ', $selector);
    $c += preg_match_all($pseudoElement, $selector);  $selector = (string)preg_replace($pseudoElement, ' ', $selector);
    // With its argument: the "odd" of :nth-child(odd) or the "de" of :lang(de)
    // is not an element.
    $pseudoClass = '/:[\w-]+(\([^)]*\))?/';
    $b += preg_match_all($pseudoClass, $selector);   $selector = (string)preg_replace($pseudoClass, ' ', $selector);
    $a += preg_match_all('/#[\w-]+/', $selector);     $selector = (string)preg_replace('/#[\w-]+/', ' ', $selector);
    $b += preg_match_all('/\.[\w-]+/', $selector);    $selector = (string)preg_replace('/\.[\w-]+/', ' ', $selector);
    $c += preg_match_all('/(?<![\w-])[a-zA-Z][\w-]*/', $selector);
    return [$a, $b, $c];
}

/** The rows of $rules whose selector matches the regular expression $pattern. */
function css_matching(array $rules, string $pattern): array {
    return array_values(array_filter($rules, fn(array $row) => preg_match($pattern, $row['selector']) === 1));
}

/**
 * Whether the row $over beats the row $general on an element both match: it
 * sets the same property or its shorthand, applies wherever $general does (no
 * @media, or the same one), and is more specific - or as specific and later.
 * An !important $general is beaten only by an !important $over. Not whether
 * both apply in the same state: see the header.
 */
function css_wins(array $over, array $general): bool {
    if ($over['property'] !== $general['property'] && $over['property'] !== explode('-', $general['property'])[0]) return false;
    if ($over['media'] !== '' && $over['media'] !== $general['media']) return false;
    if ($general['important'] && !$over['important']) return false;
    $mine = css_specificity($over['selector']); $theirs = css_specificity($general['selector']);
    return $mine > $theirs || ($mine == $theirs && $over['order'] > $general['order']);
}

/** The width an @media applies up to, in px - 0 when it has none, or also a min-width. */
function css_max_width(string $media): int {
    return preg_match('/max-width\s*:\s*(\d+)px/', $media, $m) === 1 && !str_contains($media, 'min-width') ? (int)$m[1] : 0;
}

/** Whether a length is zero on every side it names: 0, 0px, 0 0, ... */
function css_is_zero(string $value): bool {
    return preg_match('/^0(px|rem|em|%)?(\s+0(px|rem|em|%)?){0,3}$/', $value) === 1;
}
