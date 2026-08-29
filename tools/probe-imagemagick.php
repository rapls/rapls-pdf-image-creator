<?php
/**
 * Standalone ImageMagick / PDF policy probe.
 *
 * Answers one question on a server you do not administer: can this host
 * render a PDF, and if not, is it because the extension is missing or
 * because policy.xml forbids the PDF coder?
 *
 * Not part of the plugin. tools/ is export-ignored, so this never ships.
 *
 * USAGE
 *   1. Edit TOKEN below to something only you know.
 *   2. Upload this file to the web root.
 *   3. Open https://example.com/probe-imagemagick.php?token=YOUR_TOKEN
 *   4. DELETE IT. It prints absolute server paths.
 *
 * Read-only: no exec(), no writes, no network. It opens policy.xml files
 * for reading and asks Imagick what it supports. Nothing else.
 */

const TOKEN = 'CHANGE-ME';

if (!isset($_GET['token']) || !hash_equals(TOKEN, (string) $_GET['token']) || 'CHANGE-ME' === TOKEN) {
    header('HTTP/1.1 404 Not Found');
    exit("Not found\n");
}

header('Content-Type: text/plain; charset=UTF-8');

function h(string $title): void
{
    echo "\n" . $title . "\n" . str_repeat('-', strlen($title)) . "\n";
}

function kv(string $k, $v): void
{
    printf("  %-26s %s\n", $k, is_bool($v) ? var_export($v, true) : (string) $v);
}

echo "ImageMagick / PDF probe\n";
echo str_repeat('=', 60) . "\n";

h('PHP');
kv('version', PHP_VERSION);
kv('sapi', PHP_SAPI);
kv('uname', php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m'));

h('Imagick extension');
$hasExt = extension_loaded('imagick');
kv('extension_loaded(imagick)', $hasExt);
kv('class_exists(Imagick)', class_exists('Imagick'));

if (!$hasExt || !class_exists('Imagick')) {
    h('VERDICT');
    echo "  拡張がない (no_extension)\n";
    echo "  → ホスティング事業者に Imagick PHP 拡張の導入を依頼する。\n";
    exit;
}

$version = Imagick::getVersion();
kv('ImageMagick', $version['versionString'] ?? '?');
kv('extension version', phpversion('imagick'));

h('Format support');
foreach (['PDF', 'PS', 'EPS', 'PNG', 'JPEG', 'WEBP'] as $format) {
    try {
        $supported = Imagick::queryFormats($format);
    } catch (Throwable $e) {
        $supported = [];
    }
    kv($format, empty($supported) ? 'NO' : 'yes');
}

$pdfOk = false;
try {
    $pdfOk = !empty(Imagick::queryFormats('PDF'));
} catch (Throwable $e) {
    $pdfOk = false;
}

h('Where ImageMagick looks for configuration');
$dirs = [];

$env = getenv('MAGICK_CONFIGURE_PATH');
kv('MAGICK_CONFIGURE_PATH', false === $env || '' === $env ? '(unset)' : $env);
if (is_string($env) && '' !== $env) {
    $dirs = array_merge($dirs, explode(':', $env));
}

$home = getenv('MAGICK_HOME');
kv('MAGICK_HOME', false === $home || '' === $home ? '(unset)' : $home);
if (is_string($home) && '' !== $home) {
    $dirs[] = rtrim($home, '/') . '/etc/ImageMagick-7';
    $dirs[] = rtrim($home, '/') . '/etc/ImageMagick-6';
    $dirs[] = rtrim($home, '/') . '/config-Q16';
}

if (method_exists('Imagick', 'getConfigureOptions')) {
    try {
        foreach ((array) Imagick::getConfigureOptions('CONFIGURE_PATH') as $key => $value) {
            kv('getConfigureOptions', (is_string($key) ? $key . '=' : '') . $value);
            if (is_string($value) && '' !== $value) {
                $dirs = array_merge($dirs, explode(':', $value));
            }
        }
    } catch (Throwable $e) {
        kv('getConfigureOptions', 'threw: ' . $e->getMessage());
    }
} else {
    kv('getConfigureOptions', '(not available in this build)');
}

$dirs = array_merge($dirs, [
    '/etc/ImageMagick-7',
    '/etc/ImageMagick-6',
    '/etc/ImageMagick',
    '/usr/local/etc/ImageMagick-7',
    '/usr/local/etc/ImageMagick-6',
    '/opt/homebrew/etc/ImageMagick-7',
]);

h('policy.xml files found');
$found = [];
$seen = [];
foreach ($dirs as $dir) {
    $dir = rtrim(trim((string) $dir), '/');
    if ('' === $dir) {
        continue;
    }
    $file = $dir . '/policy.xml';
    if (isset($seen[$file])) {
        continue;
    }
    $seen[$file] = true;

    if (!is_readable($file)) {
        printf("  %-56s %s\n", $file, is_file($file) ? 'exists, unreadable' : '-');
        continue;
    }
    printf("  %-56s %s\n", $file, 'READABLE');
    $found[] = $file;
}

if (!$found) {
    echo "\n  読める policy.xml は見つかりませんでした。\n";
}

foreach ($found as $file) {
    h('coder rules in ' . $file);
    $xml = @file_get_contents($file);
    if (false === $xml || '' === $xml) {
        echo "  (empty)\n";
        continue;
    }

    // Strip comments FIRST. A rule inside <!-- --> is switched off, and
    // listing it as active sends the reader to the wrong conclusion.
    $active = preg_replace('/<!--.*?-->/s', '', $xml);
    $commented = [];
    if (preg_match_all('/<!--(.*?)-->/s', $xml, $cm)) {
        foreach ($cm[1] as $chunk) {
            if (preg_match_all('/<policy\b[^>]*>/i', $chunk, $inner)) {
                foreach ($inner[0] as $tag) {
                    $commented[] = trim($tag);
                }
            }
        }
    }

    echo "  [有効] --------------------------------------------------\n";
    $printed = 0;
    if (preg_match_all('/<policy\b[^>]*>/i', (string) $active, $m)) {
        foreach ($m[0] as $tag) {
            if (!preg_match('/\bdomain\s*=\s*"([^"]*)"/i', $tag, $d)) {
                continue;
            }
            if (!in_array(strtolower(trim($d[1])), ['coder', 'delegate', 'module'], true)) {
                continue;
            }
            echo '  ' . trim($tag) . "\n";
            $printed++;
        }
    }
    if (0 === $printed) {
        echo "  (coder/delegate/module の有効なルールなし)\n";
    }

    if ($commented) {
        echo "\n  [コメントアウト済み = 効いていない] ----------------------\n";
        foreach ($commented as $tag) {
            echo '  ' . $tag . "\n";
        }
    }
}

h('Actual read test');

/**
 * Build a minimal, structurally valid one-page PDF in memory.
 *
 * queryFormats() reports which coders were compiled in. Whether it also
 * applies the security policy turns out to differ between ImageMagick
 * builds, so it cannot settle the question on its own. Actually asking
 * ImageMagick to read a PDF can.
 */
function minimal_pdf(): string
{
    $objects = [
        "<< /Type /Catalog /Pages 2 0 R >>",
        "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 72 72] /Resources << >> >>",
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $i => $body) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
    }

    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n" . $xref . "\n%%EOF\n";

    return $pdf;
}

$readOk = false;
$readError = '';
try {
    $im = new Imagick();
    $im->setResolution(72, 72);
    $im->readImageBlob(minimal_pdf(), 'probe.pdf');
    kv('readImageBlob', 'OK');
    kv('rendered size', $im->getImageWidth() . ' x ' . $im->getImageHeight());
    $im->clear();
    $readOk = true;
} catch (Throwable $e) {
    $readError = $e->getMessage();
    kv('readImageBlob', 'FAILED');
    echo "\n  " . trim($readError) . "\n";
}

// ImageMagick 7 は "NotAuthorized `PDF'"、6 は "not authorized" と綴りが
// 違う。空白の有無で取りこぼすので \s* にしてある。
$notAuthorized = (bool) preg_match('/not\s*authoriz|security policy/i', $readError);
$noDelegate = (bool) preg_match('/no decode delegate|delegate.*(failed|missing)|FailedToExecuteCommand|gs.*not found/i', $readError);

h('ImageMagick generation');
$major = 0;
if (preg_match('/ImageMagick\s+(\d+)\./', (string) ($version['versionString'] ?? ''), $mv)) {
    $major = (int) $mv[1];
}
kv('major version', $major ?: '?');
if (6 === $major) {
    echo "\n  ImageMagick 6 です。PDF が読めても、CMYK の PDF は真っ白な画像に\n";
    echo "  なることがあります（bmpsep8 デバイスの出力を IM6 自身の BMP\n";
    echo "  リーダーが解釈できないため）。RGB の PDF には影響しません。\n";
}

h('VERDICT');
if ($readOk) {
    echo "  PDF を実際に読めました (ok)\n";
    echo "  → このサーバーでサムネイル生成は動きます。\n";
    if (6 === $major) {
        echo "  ただし CMYK の PDF は真っ白になる可能性があります。\n";
    }
} elseif ($notAuthorized) {
    echo "  ポリシーで拒否されました (pdf_blocked_by_policy)\n";
    echo "  → policy.xml で PDF コーダーに read 権限を、と依頼する。\n";
} elseif ($noDelegate) {
    echo "  デリゲートが見つかりません (pdf_unsupported)\n";
    echo "  → Ghostscript の導入と ImageMagick の PDF 対応を依頼する。\n";
} else {
    echo "  PDF を読めませんでした。上のエラーを読んでください。\n";
}

echo "\n  参考: queryFormats('PDF') は " . ($pdfOk ? "PDF を返しました" : "空でした") . "。\n";
if ($pdfOk !== $readOk) {
    echo "  ★ queryFormats と実際の読み込みで結果が食い違っています。\n";
    if ($notAuthorized) {
        echo "    queryFormats は PDF を返すのに、読み込みはポリシーで拒否\n";
        echo "    されました。この ImageMagick の queryFormats はポリシーを\n";
        echo "    反映しません。判定には実読み込みを使ってください。\n";
    } elseif ($noDelegate) {
        echo "    コーダーは登録されていますが、実際に描画する Ghostscript が\n";
        echo "    呼べません。ポリシーの問題ではありません。\n";
    } else {
        echo "    判定には実読み込みのほうを使ってください。\n";
    }
}

h('OLD VERDICT (queryFormats のみによる判定・参考)');
if ($pdfOk) {
    echo "  PDF は許可されています (ok)\n";
    echo "  → このサーバーでプラグインは動きます。\n";
} elseif ($found) {
    echo "  PDF が使えません。上の coder ルールを読んでください。\n";
    echo "  pattern に PDF / PS / {…,PDF,…} / * を含み rights が none または\n";
    echo "  read を含まないものがあれば、それが原因です (pdf_blocked_by_policy)。\n";
    echo "  → policy.xml で PDF コーダーに read 権限を、と依頼する。\n";
} else {
    echo "  PDF が使えず、policy.xml も読めませんでした (pdf_unsupported)。\n";
    echo "  → ビルドに PDF デリゲートが無い可能性が高い。ImageMagick の\n";
    echo "     PDF 対応と policy.xml の両方を確認するよう依頼する。\n";
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "確認したらこのファイルを削除してください。\n";
