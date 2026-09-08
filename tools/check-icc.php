<?php
/**
 * Check that a file dropped into icc/ is what it claims to be.
 *
 *   php tools/check-icc.php icc/sRGB2014.icc
 *
 * The plugin puts the destination profile inside every thumbnail it writes, so
 * the wrong file here is copied into images a site serves to the public. This
 * reads the ICC header and says what it actually is.
 */

declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "usage: php tools/check-icc.php <profile.icc>\n");
    exit(1);
}

$path = $argv[1];

if (!is_readable($path)) {
    fwrite(STDERR, "cannot read: $path\n");
    exit(1);
}

$raw = (string) file_get_contents($path);
$size = strlen($raw);

$fail = [];
$warn = [];

printf("ファイル   : %s\n", $path);
printf("サイズ     : %s bytes\n", number_format($size));

// An ICC profile opens with a 128-byte header. Bytes 36-39 are the signature.
if ($size < 132) {
    $fail[] = 'ICC ヘッダに満たない大きさです';
} else {
    $signature = substr($raw, 36, 4);
    printf("シグネチャ : %s %s\n", $signature, 'acsp' === $signature ? '(正しい)' : '(★ ICC ではありません)');

    if ('acsp' !== $signature) {
        $fail[] = "シグネチャが acsp ではありません";
    }

    // Bytes 0-3 hold the size the profile says it is.
    $declared = unpack('N', substr($raw, 0, 4))[1] ?? 0;
    printf("宣言サイズ : %s bytes %s\n", number_format($declared), $declared === $size ? '(一致)' : '(★ 実際と違う)');

    if ($declared !== $size) {
        $fail[] = '宣言サイズと実サイズが一致しません';
    }

    $class = substr($raw, 12, 4);
    $space = substr($raw, 16, 4);
    $pcs = substr($raw, 20, 4);
    printf("クラス     : %s\n", $class);
    printf("色空間     : %s\n", trim($space));
    printf("PCS        : %s\n", trim($pcs));
}

echo "\n";

// The plugin looks for two kinds and treats them very differently.
$isRgb = isset($space) && 'RGB ' === $space;
$isCmyk = isset($space) && 'CMYK' === $space;

if ($isRgb) {
    echo "用途       : destination（出力に埋め込まれます）\n";
    echo "             → 再配布を許すライセンスであることが必須です。\n";
} elseif ($isCmyk) {
    echo "用途       : source（変換に使われ、出力には残りません）\n";
    echo "             → icc/ に置く必要はありません。ホスト上のものが使われます。\n";
    $warn[] = 'CMYK プロファイルを icc/ に置く理由は通常ありません';
} else {
    $warn[] = '想定外の色空間です';
}

// Copyright text lives in a tag, but it is plain ASCII in the file either way.
if (preg_match('/(Copyright[^\x00]{0,120})/i', $raw, $m)) {
    printf("著作権表示 : %s\n", trim($m[1]));

    if (false !== stripos($m[1], 'artifex') || false !== stripos($m[1], 'ghostscript')) {
        $fail[] = 'Ghostscript / Artifex のプロファイルです。AGPL なので destination には使えません';
    }
} else {
    $warn[] = '著作権表示が読めませんでした';
}

if (preg_match('/(International Color Consortium|ICC)/', $raw)) {
    echo "出所       : ICC の表記あり\n";
}

echo "\n";

foreach ($warn as $w) {
    echo "注意: $w\n";
}

if ($fail) {
    foreach ($fail as $f) {
        echo "NG  : $f\n";
    }
    exit(1);
}

echo "OK  : プロファイルとして妥当です\n";
