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

    if (!preg_match_all('/<policy\b[^>]*>/i', $xml, $m)) {
        echo "  (no <policy> elements)\n";
        continue;
    }

    $printed = 0;
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
    if (0 === $printed) {
        echo "  (no coder/delegate/module rules — nothing here blocks PDF)\n";
    }

    // Commented-out rules matter: they are how a host *unblocks* PDF.
    if (preg_match_all('/<!--\s*(<policy\b[^>]*(?:PDF|PS)[^>]*>)\s*-->/i', $xml, $c)) {
        echo "\n  コメントアウト済み (無効化されている行):\n";
        foreach ($c[1] as $tag) {
            echo '    ' . trim($tag) . "\n";
        }
    }
}

h('VERDICT');
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
