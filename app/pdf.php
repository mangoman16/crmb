<?php
declare(strict_types=1);

/**
 * A small PDF writer, for invoices and nothing else.
 *
 * Written here rather than installed, deliberately. Every dependency is
 * something the operator has to keep patched, through a file manager, without a
 * shell; a PDF library is tens of thousands of lines to lay out one page of text
 * in one font. This is about two hundred, uses only the fonts every reader
 * already has, and produces a file that opens in Preview, Acrobat and a phone.
 *
 * What it does: A4 pages, Helvetica and Helvetica-Bold, left and right aligned
 * text, horizontal rules, page breaks. What it does not: images, colour beyond
 * greys, embedded fonts, tables that flow. An invoice needs none of them.
 *
 * Text goes out as WinAnsi (CP1252), which is the encoding the built-in fonts
 * are defined in and which covers German and the euro sign. Anything outside it
 * is transliterated rather than dropped, so a name with a Polish ł arrives as
 * "l" instead of as a hole.
 */

const PDF_PAGE_WIDTH  = 595.28;   // A4 at 72dpi
const PDF_PAGE_HEIGHT = 841.89;
const PDF_MARGIN      = 56.0;

/** A new empty document. Pass it by reference to the pdf_* helpers. */
function pdf_new(): array {
    return ['pages' => [], 'current' => '', 'y' => PDF_PAGE_HEIGHT - PDF_MARGIN];
}

/** Start a page. Called once by pdf_text() if nothing has started one yet. */
function pdf_page(array &$doc): void {
    if ($doc['current'] !== '') $doc['pages'][] = $doc['current'];
    $doc['current'] = '';
    $doc['y'] = PDF_PAGE_HEIGHT - PDF_MARGIN;
}

/** Where the next line would go, measured from the top of the page. */
function pdf_y(array $doc): float { return $doc['y']; }

/** Move down the page, starting a new one when there is no room left. */
function pdf_down(array &$doc, float $points): void {
    $doc['y'] -= $points;
    if ($doc['y'] < PDF_MARGIN) pdf_page($doc);
}

/**
 * How wide a string is, in points.
 *
 * Exact for digits, spaces and the punctuation that appears in an amount, which
 * is the only place right alignment is used. For prose it is close enough to
 * decide a line break and is never relied on to align anything.
 */
function pdf_width(string $winAnsi, float $size, bool $bold = false): float {
    static $regular, $bolder;
    if ($regular === null) {
        // Adobe's Helvetica metrics, in 1/1000 em, for the printable ASCII range.
        $regular = array_merge(
            array_fill(32, 95, 556),
            [32=>278, 33=>278, 34=>355, 35=>556, 36=>556, 37=>889, 38=>667, 39=>191,
             40=>333, 41=>333, 42=>389, 43=>584, 44=>278, 45=>333, 46=>278, 47=>278,
             58=>278, 59=>278, 60=>584, 61=>584, 62=>584, 63=>556, 64=>1015,
             65=>667, 66=>667, 67=>722, 68=>722, 69=>667, 70=>611, 71=>778, 72=>722,
             73=>278, 74=>500, 75=>667, 76=>556, 77=>833, 78=>722, 79=>778, 80=>667,
             81=>778, 82=>722, 83=>667, 84=>611, 85=>722, 86=>667, 87=>944, 88=>667,
             89=>667, 90=>611, 91=>278, 92=>278, 93=>278, 94=>469, 95=>556, 96=>333,
             97=>556, 98=>556, 99=>500, 100=>556, 101=>556, 102=>278, 103=>556, 104=>556,
             105=>222, 106=>222, 107=>500, 108=>222, 109=>833, 110=>556, 111=>556, 112=>556,
             113=>556, 114=>333, 115=>500, 116=>278, 117=>556, 118=>500, 119=>722, 120=>500,
             121=>500, 122=>500, 123=>334, 124=>260, 125=>334, 126=>584]);
        // Helvetica-Bold differs mainly in the letters; digits, spaces and the
        // punctuation in an amount are the same width in both, which is what
        // keeps a bold total lined up with the figures above it.
        $bolder = array_merge($regular,
            [65=>722, 66=>722, 67=>722, 68=>722, 69=>667, 70=>611, 71=>778, 72=>722,
             74=>556, 75=>722, 76=>611, 77=>833, 78=>722, 79=>778, 80=>667, 81=>778,
             82=>722, 83=>667, 84=>611, 85=>722, 86=>667, 87=>944, 88=>667, 89=>667, 90=>611,
             97=>556, 98=>611, 99=>556, 100=>611, 101=>556, 102=>333, 103=>611, 104=>611,
             105=>278, 106=>278, 107=>556, 108=>278, 109=>889, 110=>611, 111=>611, 112=>611,
             113=>611, 114=>389, 115=>556, 116=>333, 117=>611, 118=>556, 119=>778, 120=>556,
             121=>556, 122=>500]);
    }
    $table = $bold ? $bolder : $regular;
    $total = 0;
    for ($i = 0, $n = strlen($winAnsi); $i < $n; $i++) {
        $code = ord($winAnsi[$i]);
        // Accented Latin-1 letters are within a hair of their unaccented forms,
        // and the euro sign matches a digit.
        $total += $table[$code] ?? ($code >= 0xC0 ? 600 : 556);
    }
    return $total * $size / 1000;
}

/** UTF-8 in, WinAnsi out, with anything outside it transliterated. */
function pdf_encode(string $text): string {
    $out = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $text);
    if ($out === false) $out = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';
    return (string)$out;
}

/** Escape the three characters a PDF string literal cannot carry raw. */
function pdf_escape(string $winAnsi): string {
    return strtr($winAnsi, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
}

/** One line of text at an absolute position. */
function pdf_at(array &$doc, float $x, float $y, string $text, float $size = 10, bool $bold = false, float $grey = 0.0): void {
    if ($text === '') return;
    $doc['current'] .= sprintf("BT %.3f %.3f %.3f rg /%s %.2f Tf %.2f %.2f Td (%s) Tj ET\n",
        $grey, $grey, $grey, $bold ? 'F2' : 'F1', $size, $x, $y, pdf_escape(pdf_encode($text)));
}

/** One line of text at the current position, then move down. */
function pdf_text(array &$doc, string $text, float $size = 10, bool $bold = false, float $x = PDF_MARGIN, float $grey = 0.0): void {
    pdf_at($doc, $x, $doc['y'], $text, $size, $bold, $grey);
    pdf_down($doc, $size * 1.45);
}

/** Text ending at $right, for amounts. */
function pdf_right(array &$doc, float $right, float $y, string $text, float $size = 10, bool $bold = false, float $grey = 0.0): void {
    $encoded = pdf_encode($text);
    pdf_at($doc, $right - pdf_width($encoded, $size, $bold), $y, $text, $size, $bold, $grey);
}

/** A horizontal rule across the text column. */
function pdf_rule(array &$doc, float $grey = 0.8): void {
    $doc['current'] .= sprintf("%.3f G 0.6 w %.2f %.2f m %.2f %.2f l S\n",
        $grey, PDF_MARGIN, $doc['y'] + 4, PDF_PAGE_WIDTH - PDF_MARGIN, $doc['y'] + 4);
    pdf_down($doc, 10);
}

/**
 * Break a paragraph into lines that fit the column.
 *
 * Words only; a word longer than the column is left to overhang rather than
 * broken, because the strings this handles are names and sentences, and a
 * hyphenated IBAN would be worse than a wide line.
 */
function pdf_wrap(string $text, float $width, float $size = 10, bool $bold = false): array {
    $lines = [];
    foreach (preg_split('/\R/', $text) ?: [$text] as $paragraph) {
        $line = '';
        foreach (preg_split('/\s+/', trim($paragraph)) ?: [] as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if ($line !== '' && pdf_width(pdf_encode($candidate), $size, $bold) > $width) {
                $lines[] = $line; $line = $word;
            } else $line = $candidate;
        }
        $lines[] = $line;
    }
    return $lines;
}

/** A paragraph, wrapped to the text column. */
function pdf_paragraph(array &$doc, string $text, float $size = 10, bool $bold = false, float $grey = 0.0): void {
    foreach (pdf_wrap($text, PDF_PAGE_WIDTH - 2 * PDF_MARGIN, $size, $bold) as $line)
        pdf_text($doc, $line, $size, $bold, PDF_MARGIN, $grey);
}

/**
 * The finished file.
 *
 * Objects are written in order and their byte offsets recorded as they go, which
 * is what the cross-reference table at the end is: a reader jumps straight to an
 * object rather than scanning, and an offset that is one byte out makes the
 * whole file unreadable. So the offsets are taken from the string being built
 * rather than counted separately.
 */
function pdf_render(array $doc, array $meta = []): string {
    if ($doc['current'] !== '') $doc['pages'][] = $doc['current'];
    if (!$doc['pages']) $doc['pages'] = [''];
    $pageCount = count($doc['pages']);

    // 1 catalog, 2 page tree, 3 + 4 fonts, then one page object and one content
    // stream per page.
    $firstPage = 5;
    $objects = [];
    $kids = [];
    for ($i = 0; $i < $pageCount; $i++) $kids[] = ($firstPage + $i * 2) . ' 0 R';

    $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[2] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count " . $pageCount . " >>";
    $objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
    $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";
    foreach ($doc['pages'] as $i => $stream) {
        $page = $firstPage + $i * 2;
        $objects[$page] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " . PDF_PAGE_WIDTH . " " . PDF_PAGE_HEIGHT . "]"
            . " /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents " . ($page + 1) . " 0 R >>";
        $objects[$page + 1] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
    }
    $info = [];
    foreach (['Title' => 'title', 'Author' => 'author', 'Subject' => 'subject'] as $key => $from)
        if (!empty($meta[$from])) $info[] = '/' . $key . ' (' . pdf_escape(pdf_encode((string)$meta[$from])) . ')';
    $info[] = '/Producer (Badminton CRM)';
    $info[] = '/CreationDate (D:' . gmdate('YmdHis') . 'Z)';
    $infoNumber = max(array_keys($objects)) + 1;
    $objects[$infoNumber] = '<< ' . implode(' ', $info) . ' >>';

    $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    ksort($objects);
    foreach ($objects as $number => $body) {
        $offsets[$number] = strlen($out);
        $out .= $number . " 0 obj\n" . $body . "\nendobj\n";
    }
    $size = count($objects) + 1;
    $xref = strlen($out);
    $out .= "xref\n0 " . $size . "\n0000000000 65535 f \n";
    for ($n = 1; $n < $size; $n++) $out .= sprintf("%010d 00000 n \n", $offsets[$n] ?? 0);
    $out .= "trailer\n<< /Size " . $size . " /Root 1 0 R /Info " . $infoNumber . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    return $out;
}
