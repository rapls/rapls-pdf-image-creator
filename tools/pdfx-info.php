<?php
/**
 * Report what a PDF says about its own colour intent.
 *
 *   php tools/pdfx-info.php <file.pdf>
 *
 * Answers the question "is this PDF/X, and if so what does it target", which
 * decides whether a renderer has a declared output condition to honour or has
 * to guess the source colour space.
 *
 * Reads the file directly. No Ghostscript, no processes, no PDF library --
 * the same constraint the plugin runs under, so anything this can see is
 * something the plugin could see too.
 *
 * The catch worth knowing: since PDF 1.5 most objects live inside compressed
 * object streams, so grepping the raw bytes finds nothing even when the
 * markers are there. This inflates those streams first. It is a marker search,
 * not a parser: it will not resolve indirect references.
 */

declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "usage: php tools/pdfx-info.php <file.pdf>\n");
    exit(1);
}

$path = $argv[1];

if (!is_readable($path)) {
    fwrite(STDERR, "cannot read: $path\n");
    exit(1);
}

$raw = (string) file_get_contents($path);

if ('%PDF-' !== substr($raw, 0, 5)) {
    fwrite(STDERR, "not a PDF\n");
    exit(1);
}

/**
 * The raw bytes, plus the inflated contents of the streams worth looking in.
 *
 * Not every stream: a 23 MB print PDF is mostly photographs, and inflating
 * those exhausts the memory limit while adding nothing a marker search can
 * use. Only object streams, metadata and other small compressed dictionaries
 * are expanded.
 */
function searchable(string $raw): string
{
    $text = $raw;
    $expanded = 0;
    $skipped = 0;

    // Compressed object streams are rarely more than a few hundred kilobytes,
    // and XMP packets are smaller still. Anything past this is image data.
    $maxCompressed = 512 * 1024;
    $budget = 24 * 1024 * 1024;

    if (!preg_match_all('/stream\r?\n/', $raw, $m, PREG_OFFSET_CAPTURE)) {
        return $text;
    }

    foreach ($m[0] as $hit) {
        $start = $hit[1] + strlen($hit[0]);
        $end = strpos($raw, 'endstream', $start);

        if (false === $end) {
            continue;
        }

        $length = $end - $start;

        if ($length > $maxCompressed || $length < 8) {
            $skipped++;
            continue;
        }

        // The dictionary sits immediately before the stream keyword. Reading
        // it is what separates "a table of objects" from "a photograph".
        $dict = substr($raw, max(0, $hit[1] - 400), min(400, $hit[1]));

        if (preg_match('~/Subtype\s*/Image|/DCTDecode|/JPXDecode|/CCITTFaxDecode|/JBIG2Decode~', $dict)) {
            $skipped++;
            continue;
        }

        $blob = substr($raw, $start, $length);

        // Anything that is not zlib warns once per object otherwise.
        $plain = @gzuncompress($blob);
        if (false === $plain || '' === $plain) {
            $plain = @gzinflate($blob);
        }

        if (!is_string($plain) || '' === $plain) {
            continue;
        }

        // Content streams are drawing operators; they carry no markers and
        // there are thousands of them. Keep what looks like a dictionary.
        if (false === strpos($plain, '/') && false === strpos($plain, '<')) {
            continue;
        }

        $budget -= strlen($plain);
        if ($budget <= 0) {
            $skipped++;
            break;
        }

        $text .= "\n" . $plain;
        $expanded++;
    }

    fwrite(STDERR, sprintf(
        "  (圧縮ストリーム: %d 個を展開、%d 個は画像などとして読み飛ばし)\n\n",
        $expanded,
        $skipped
    ));

    return $text;
}

$text = searchable($raw);

function firstMatch(string $text, string $pattern): ?string
{
    return preg_match($pattern, $text, $m) ? trim($m[1]) : null;
}

// --------------------------------------------------------------- basic facts

preg_match('/^%PDF-([0-9.]+)/', $raw, $v);
$version = $v[1] ?? '?';

echo "ファイル      : " . basename($path) . "\n";
echo "PDF バージョン: $version\n";
echo "サイズ        : " . number_format(strlen($raw)) . " bytes\n";

// ------------------------------------------------------------- PDF/X markers

echo "\n== PDF/X の宣言 ==\n";

$claims = [
    'GTS_PDFXVersion' => firstMatch($text, '/GTS_PDFXVersion[^(\/]*[(\/]\s*([^)\/>]+)/'),
    'GTS_PDFXConformance' => firstMatch($text, '/GTS_PDFXConformance[^(\/]*[(\/]\s*([^)\/>]+)/'),
    'pdfxid:GTS_PDFXVersion' => firstMatch($text, '/pdfxid:GTS_PDFXVersion>\s*([^<]+)/'),
    'pdfaid:part (参考: PDF/A)' => firstMatch($text, '/pdfaid:part>\s*([^<]+)/'),
];

$isPdfx = false;
foreach ($claims as $label => $value) {
    printf("  %-26s %s\n", $label, null === $value ? '-' : $value);
    if (null !== $value && 0 === strpos($label, 'GTS_PDFX')) {
        $isPdfx = true;
    }
    if (null !== $value && 0 === strpos($label, 'pdfxid')) {
        $isPdfx = true;
    }
}

// ------------------------------------------------------------ output intents

echo "\n== 出力インテント ==\n";

$intentCount = preg_match_all('/\/OutputIntents?/', $text);
printf("  /OutputIntents            %s\n", $intentCount ? "$intentCount 箇所" : '-');
printf("  /S /GTS_PDFX              %s\n", preg_match('/\/S\s*\/GTS_PDFX/', $text) ? 'あり（PDF/X の出力インテント）' : '-');

$condition = firstMatch($text, '/\/OutputConditionIdentifier\s*\(([^)]*)\)/');
printf("  OutputConditionIdentifier %s\n", $condition ?? '-');

$info = firstMatch($text, '/\/Info\s*\(([^)]*)\)/');
printf("  Info                      %s\n", $info ?? '-');

$hasDest = preg_match('/\/DestOutputProfile/', $text);
printf("  DestOutputProfile         %s\n", $hasDest ? 'あり（ICC が埋め込まれている）' : '-');

printf("  /Trapped                  %s\n", firstMatch($text, '/\/Trapped\s*\/(\w+)/') ?? '-');

// -------------------------------------------------------------- colour usage

echo "\n== 使われている色空間 ==\n";
foreach (['DeviceCMYK', 'DeviceRGB', 'DeviceGray', 'ICCBased', 'Separation', 'DeviceN', 'Indexed', 'CalRGB', 'Lab'] as $space) {
    $n = preg_match_all('/\/' . $space . '\b/', $text);
    if ($n) {
        printf("  %-12s %d\n", $space, $n);
    }
}

// ICCBased streams declare their channel count in /N.
$n1 = preg_match_all('/\/N\s+1\b/', $text);
$n3 = preg_match_all('/\/N\s+3\b/', $text);
$n4 = preg_match_all('/\/N\s+4\b/', $text);
printf("  埋め込み ICC  gray=%d rgb=%d cmyk=%d\n", $n1, $n3, $n4);

// -------------------------------------------------------------------- verdict

echo "\n== 判定 ==\n";

if ($isPdfx) {
    echo "  PDF/X です。出力条件が宣言されているので、レンダラーはソース色空間を\n";
    echo "  推測せずに済みます。\n";
} elseif (preg_match('/\/S\s*\/GTS_PDFX/', $text)) {
    echo "  PDF/X を名乗ってはいませんが、PDF/X 形式の出力インテントを持っています。\n";
} elseif ($intentCount) {
    echo "  出力インテントはありますが PDF/X ではありません。\n";
} else {
    echo "  PDF/X ではありません。出力インテントもありません。\n";
    if ($n4) {
        echo "  CMYK の内容はありますが、どの印刷条件を想定した CMYK なのかは\n";
        echo "  ファイル自身が何も言っていません。**変換するには推測が要ります。**\n";
    }
}
