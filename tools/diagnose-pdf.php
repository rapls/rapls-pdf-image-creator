<?php
/**
 * PDF conversion diagnostic.
 *
 * Runs one PDF through the same steps ImagickEngine::convert() performs and
 * reports the image state after each one, so a thumbnail that comes out blank,
 * black or wrongly coloured can be traced to the step that did it.
 *
 * Usage:
 *   php tools/diagnose-pdf.php <file.pdf> [options]
 *
 *   --page=0            page index, 0-based (default: plugin setting, else 0)
 *   --format=jpeg       jpeg | png | webp
 *   --bg=white          white | black | transparent
 *   --resolution=150    DPI passed to setResolution()
 *   --dump=<dir>        write the image after each step, to look at by eye
 *   --no-wp             skip the WordPress bootstrap (use when the site's
 *                       database is not running; filters and plugin settings
 *                       will not apply)
 *
 * Uses only the Imagick API. It starts no processes and writes nothing outside
 * --dump. This file never ships: tools/ is export-ignore in .gitattributes.
 *
 * @package PDFImageCreator\Tools
 */

if ('cli' !== PHP_SAPI) {
    exit("Run this from the command line.\n");
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

/* ------------------------------------------------------------------ args */

$argvRest = array_slice($argv, 1);
$pdfPath = null;
$opt = [
    'page' => null,
    'format' => null,
    'bg' => null,
    'resolution' => 150,
    'dump' => null,
];

$skipWordPress = false;

foreach ($argvRest as $arg) {
    if ('--no-wp' === $arg) {
        $skipWordPress = true;
    } elseif (preg_match('/^--([a-z]+)=(.*)$/', $arg, $m) && array_key_exists($m[1], $opt)) {
        $opt[$m[1]] = $m[2];
    } elseif ('-' !== substr($arg, 0, 1)) {
        $pdfPath = $arg;
    }
}

if (null === $pdfPath) {
    exit("usage: php tools/diagnose-pdf.php <file.pdf> [--page=0] [--format=jpeg] [--bg=white]\n"
        . "                                 [--resolution=150] [--dump=DIR] [--no-wp]\n");
}

$pdfPath = realpath($pdfPath) ?: $pdfPath;

/* ------------------------------------------------------- environment prep */

/**
 * Load WordPress if we can find it, so the real filters, transients and
 * settings apply. Falls back to just enough stubs to exercise the classes.
 */
function rapls_diag_bootstrap(bool $skip): string
{
    $dir = __DIR__;

    if (!$skip) {
        for ($i = 0; $i < 8; $i++) {
            $dir = dirname($dir);
            if (!is_file($dir . '/wp-load.php')) {
                continue;
            }

            // A dead database makes WordPress print a full HTML error page and
            // call die(), so the line after the require never runs. Buffer the
            // bootstrap, and let a shutdown handler throw the HTML away and say
            // what actually went wrong.
            register_shutdown_function(static function (): void {
                if (function_exists('did_action') && did_action('init')) {
                    return;
                }

                $html = '';
                while (ob_get_level() > 0) {
                    $html .= (string) ob_get_clean();
                }

                if (preg_match('#<h1>(.*?)</h1>#s', $html, $m)) {
                    fwrite(STDERR, "\nWordPress halted during bootstrap: " . trim(strip_tags($m[1])) . "\n");
                } else {
                    fwrite(STDERR, "\nWordPress halted during bootstrap.\n");
                }

                fwrite(STDERR, "Start the site (or its database), or re-run with --no-wp to diagnose without it.\n");
            });

            define('WP_USE_THEMES', false);
            ob_start();
            require_once $dir . '/wp-load.php';
            ob_end_clean();

            return 'WordPress (' . $dir . ')';
        }
    }

    // Standalone: the plugin classes only need these.
    if (!function_exists('apply_filters')) {
        function apply_filters($tag, $value, ...$rest) { return $value; }
        function get_transient($key) { return false; }
        function set_transient($key, $value, $ttl = 0) { return true; }
        function delete_transient($key) { return true; }
        function get_option($key, $default = false) { return $default; }
        function update_option($key, $value, $autoload = null) { return true; }
        function __($text, $domain = null) { return $text; }
        function wp_mkdir_p($dir) { return is_dir($dir) || mkdir($dir, 0777, true); }
    }
    if (!defined('DAY_IN_SECONDS')) {
        define('DAY_IN_SECONDS', 86400);
        define('HOUR_IN_SECONDS', 3600);
    }

    return $skip
        ? 'standalone by request (--no-wp) — filters and plugin settings are NOT applied'
        : 'standalone (no WordPress found) — filters and plugin settings are NOT applied';
}

$context = rapls_diag_bootstrap($skipWordPress);

if (!defined('RAPLS_PIC_PLUGIN_DIR')) {
    define('RAPLS_PIC_PLUGIN_DIR', dirname(__DIR__) . '/');
}

require_once RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ColorProfile.php';

use Rapls\PDFImageCreator\Engine\ColorProfile;

/* --------------------------------------------------------------- helpers */

/**
 * Map an Imagick constant value back to its name.
 *
 * Values differ between ImageMagick 6 and 7 builds — CMYK is 12 on some and 2
 * on others — so nothing here hardcodes a number.
 */
function rapls_diag_constant_name(string $prefix, $value): string
{
    static $cache = [];

    if (!isset($cache[$prefix])) {
        $cache[$prefix] = [];
        foreach ((new ReflectionClass('Imagick'))->getConstants() as $name => $constant) {
            if (0 === strpos($name, $prefix) && is_int($constant)) {
                $cache[$prefix][$constant] = $name;
            }
        }
    }

    return ($cache[$prefix][$value] ?? 'UNKNOWN') . " ($value)";
}

/**
 * Sample the image and describe what is actually in it.
 *
 * Downsizes to a small grid first so this stays cheap on a 150 DPI page, then
 * reads every pixel of the grid. A uniform result is the thing we are hunting.
 *
 * @return array<string, mixed>
 */
function rapls_diag_sample(Imagick $image, int $grid = 24): array
{
    $probe = clone $image;

    try {
        if (Imagick::COLORSPACE_SRGB !== $probe->getImageColorspace()) {
            // transformImageColorspace() converts. setImageColorspace() would
            // only relabel, and reading CMYK channel data as if it were RGB
            // reports colours that are pure fiction — which is the bug this
            // whole tool exists to investigate. The figures below are for
            // orientation only; a CMYK stage is sampled through the same
            // arithmetic conversion the plugin is trying to avoid.
            $probe->transformImageColorspace(Imagick::COLORSPACE_SRGB);
        }
    } catch (Throwable $e) {
        // Report whatever we can rather than losing the whole stage.
    }

    try {
        $probe->resizeImage($grid, $grid, Imagick::FILTER_BOX, 1, true);
    } catch (Throwable $e) {
        $probe->clear();
        return ['error' => 'could not resize for sampling: ' . $e->getMessage()];
    }

    $width = $probe->getImageWidth();
    $height = $probe->getImageHeight();

    $min = [255, 255, 255];
    $max = [0, 0, 0];
    $sum = [0, 0, 0];
    $alphaMin = 1.0;
    $alphaMax = 0.0;
    $colors = [];
    $count = 0;

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            // Mode 1 is the normalized one: r/g/b/a all arrive as 0.0–1.0.
            // Mode 2 looks similar but returns 0–255 ints, which would double
            // the scaling below.
            $c = $probe->getImagePixelColor($x, $y)->getColor(1);
            $rgb = [(int) round($c['r'] * 255), (int) round($c['g'] * 255), (int) round($c['b'] * 255)];
            for ($i = 0; $i < 3; $i++) {
                $min[$i] = min($min[$i], $rgb[$i]);
                $max[$i] = max($max[$i], $rgb[$i]);
                $sum[$i] += $rgb[$i];
            }
            $alpha = isset($c['a']) ? (float) $c['a'] : 1.0;
            $alphaMin = min($alphaMin, $alpha);
            $alphaMax = max($alphaMax, $alpha);
            $colors[sprintf('%02X%02X%02X', $rgb[0], $rgb[1], $rgb[2])] = true;
            $count++;
        }
    }

    $probe->clear();

    if (0 === $count) {
        return ['error' => 'no pixels sampled'];
    }

    $mean = array_map(static function ($v) use ($count) {
        return (int) round($v / $count);
    }, $sum);

    arsort($colors);

    return [
        'samples' => $count,
        'distinct' => count($colors),
        'min' => sprintf('#%02X%02X%02X', $min[0], $min[1], $min[2]),
        'max' => sprintf('#%02X%02X%02X', $max[0], $max[1], $max[2]),
        'mean' => sprintf('#%02X%02X%02X', $mean[0], $mean[1], $mean[2]),
        'alpha' => sprintf('%.2f–%.2f', $alphaMin, $alphaMax),
        'uniform' => 1 === count($colors),
        'dominant' => '#' . array_key_first($colors),
    ];
}

/**
 * Print the full state of the image at one point in the pipeline.
 */
function rapls_diag_report(string $label, Imagick $image, ?string $dumpDir = null, ?int $step = null): void
{
    echo "\n" . str_repeat('─', 72) . "\n";
    echo "▶ $label\n";
    echo str_repeat('─', 72) . "\n";

    try {
        $geometry = $image->getImageGeometry();
        $page = $image->getImagePage();
        $profiles = $image->getImageProfiles('icc', false);

        printf("  image geometry     %d x %d\n", $geometry['width'], $geometry['height']);
        printf(
            "  page geometry      %d x %d at +%d+%d%s\n",
            $page['width'],
            $page['height'],
            $page['x'],
            $page['y'],
            ($page['width'] !== $geometry['width'] || $page['height'] !== $geometry['height'] || $page['x'] || $page['y'])
                ? '   <<< differs from the image — mergeImageLayers(FLATTEN) sizes its canvas from THIS'
                : ''
        );
        printf("  colorspace         %s\n", rapls_diag_constant_name('COLORSPACE_', $image->getImageColorspace()));
        printf("  image type         %s\n", rapls_diag_constant_name('IMGTYPE_', $image->getImageType()));
        printf("  depth              %d\n", $image->getImageDepth());
        printf("  alpha channel      %s\n", $image->getImageAlphaChannel() ? 'active' : 'none');
        printf("  ICC profile        %s\n", $profiles ? 'yes (' . implode(', ', $profiles) . ')' : 'no');
        printf("  images in list     %d\n", $image->getNumberImages());
    } catch (Throwable $e) {
        echo "  (state unavailable: " . $e->getMessage() . ")\n";
    }

    $sample = rapls_diag_sample($image);

    if (isset($sample['error'])) {
        echo "  pixels             {$sample['error']}\n";
        return;
    }

    printf(
        "  pixels             mean %s   range %s → %s   alpha %s\n",
        $sample['mean'],
        $sample['min'],
        $sample['max'],
        $sample['alpha']
    );
    printf("  distinct colours   %d of %d samples\n", $sample['distinct'], $sample['samples']);

    if ($sample['uniform']) {
        printf("  >>> UNIFORM: every sampled pixel is %s — the image is blank at this point\n", $sample['dominant']);
    }

    if (null !== $dumpDir) {
        $file = sprintf('%s/step%02d-%s.png', $dumpDir, $step, preg_replace('/[^a-z0-9]+/i', '-', strtolower($label)));
        try {
            $copy = clone $image;
            $copy->setImageFormat('PNG');
            $copy->writeImage($file);
            $copy->clear();
            echo "  dumped             $file\n";
        } catch (Throwable $e) {
            echo "  dump failed        " . $e->getMessage() . "\n";
        }
    }
}

/* ------------------------------------------------------------ environment */

echo str_repeat('═', 72) . "\n";
echo "Rapls PDF Image Creator — conversion diagnostic\n";
echo str_repeat('═', 72) . "\n";
echo "context            $context\n";
echo 'php                ' . PHP_VERSION . "\n";

if (!extension_loaded('imagick') || !class_exists('Imagick')) {
    exit("\nImagick is not loaded. Nothing further can be checked.\n");
}

$version = Imagick::getVersion();
echo 'imagick ext        ' . phpversion('imagick') . "\n";
echo 'imagemagick        ' . ($version['versionString'] ?? '?') . "\n";

foreach (['PDF', 'PNG', 'JPEG', 'WEBP'] as $format) {
    try {
        $supported = (bool) Imagick::queryFormats($format);
    } catch (Throwable $e) {
        $supported = false;
    }
    printf("format %-12s %s\n", $format, $supported ? 'yes' : 'NO');
}

echo "\nresource limits (a render that exceeds these can come back blank)\n";
foreach (['AREA', 'MEMORY', 'MAP', 'DISK', 'FILE', 'THREAD', 'TIME', 'WIDTH', 'HEIGHT'] as $name) {
    $constant = 'Imagick::RESOURCETYPE_' . $name;
    if (!defined($constant)) {
        continue;
    }
    try {
        $limit = Imagick::getResourceLimit(constant($constant));
        printf("  %-8s %s\n", $name, -1 == $limit ? 'unlimited' : number_format((float) $limit));
    } catch (Throwable $e) {
        printf("  %-8s (unavailable)\n", $name);
    }
}

/* ---------------------------------------------------------- colour status */

$colorProfile = new ColorProfile();
$status = $colorProfile->getStatus();

echo "\ncolour management\n";
printf("  mode             %s\n", $status['mode']);
printf("  sRGB profile     %s\n", $status['srgb'] ?? 'not found');
printf("  CMYK profile     %s\n", $status['cmyk'] ?? 'not found');
printf("  ICC active       %s\n", $status['managed'] ? 'yes' : 'no — falling back to the arithmetic conversion');

/* -------------------------------------------------------------- the file */

echo "\ninput\n";
printf("  path             %s\n", $pdfPath);

if (!is_file($pdfPath) || !is_readable($pdfPath)) {
    exit("\nCannot read that file.\n");
}

printf("  size             %s bytes\n", number_format(filesize($pdfPath)));

// Settings, when WordPress gave us the plugin.
$page = null !== $opt['page'] ? (int) $opt['page'] : null;
$format = $opt['format'];
$bg = $opt['bg'];

if (class_exists('\Rapls\PDFImageCreator\Plugin')) {
    try {
        $settings = \Rapls\PDFImageCreator\Plugin::getInstance()->getSettings();
        $page ??= $settings->getPage();
        $format ??= $settings->getFormat();
        $bg ??= $settings->getBgColor();
        echo "  settings source  plugin options\n";
    } catch (Throwable $e) {
        echo "  settings source  defaults (" . $e->getMessage() . ")\n";
    }
}

$page ??= 0;
$format = strtoupper($format ?? 'jpeg');
$format = in_array($format, ['PNG', 'WEBP'], true) ? $format : 'JPEG';
$bg ??= 'white';
$resolution = (float) $opt['resolution'];

printf("  page             %d (0-based)\n", $page);
printf("  format           %s\n", $format);
printf("  background       %s\n", $bg);
printf("  resolution       %s dpi\n", $resolution);

$dumpDir = null;
if (null !== $opt['dump']) {
    $dumpDir = rtrim($opt['dump'], '/');
    if (!is_dir($dumpDir) && !mkdir($dumpDir, 0777, true)) {
        exit("\nCannot create dump directory $dumpDir\n");
    }
    printf("  dumping to       %s\n", $dumpDir);
}

/* ------------------------------------------------------------- the run */

$step = 0;

try {
    $image = new Imagick();
    $image->setResolution($resolution, $resolution);

    echo "\n\n" . str_repeat('═', 72) . "\n";
    echo "PIPELINE\n";
    echo str_repeat('═', 72) . "\n";

    try {
        $image->readImage($pdfPath . '[' . $page . ']');
    } catch (Throwable $e) {
        echo "\nreadImage() FAILED: " . $e->getMessage() . "\n\n";
        echo "The message above usually names the Ghostscript device ImageMagick chose\n";
        echo "(-sDEVICE=...). pamcmyk32 means it asked for CMYK; pngalpha means RGB with\n";
        echo "alpha. That single word decides which branch of the plugin runs.\n";
        exit(1);
    }

    rapls_diag_report('1. after readImage()', $image, $dumpDir, ++$step);

    $colorMode = $colorProfile->convertToSrgb($image);
    rapls_diag_report("2. after colour conversion (mode: $colorMode)", $image, $dumpDir, ++$step);

    $flattenBg = ('transparent' === strtolower($bg) && 'JPEG' === $format) ? 'white' : strtolower($bg);
    if ($flattenBg !== strtolower($bg)) {
        echo "\n  note: background '$bg' is not possible in JPEG, using '$flattenBg'\n";
    }

    $image->setImageBackgroundColor(new ImagickPixel($flattenBg));
    $flattened = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
    $image->clear();
    $image = $flattened;
    rapls_diag_report("3. after flatten onto '$flattenBg'", $image, $dumpDir, ++$step);

    if ('transparent' !== $flattenBg) {
        if (defined('Imagick::ALPHACHANNEL_REMOVE')) {
            $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        } else {
            $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_OPAQUE);
        }
        rapls_diag_report('4. after alpha removal', $image, $dumpDir, ++$step);
    }

    $maxWidth = 1024;
    $maxHeight = 1024;
    if (isset($settings)) {
        $maxWidth = $settings->getMaxWidth();
        $maxHeight = $settings->getMaxHeight();
    }

    $width = $image->getImageWidth();
    $height = $image->getImageHeight();
    if ($width > $maxWidth || $height > $maxHeight) {
        $ratio = min($maxWidth / $width, $maxHeight / $height);
        $image->resizeImage((int) round($width * $ratio), (int) round($height * $ratio), Imagick::FILTER_LANCZOS, 1);
        rapls_diag_report('5. after resize', $image, $dumpDir, ++$step);
    } else {
        echo "\n(no resize needed: {$width}x{$height} fits {$maxWidth}x{$maxHeight})\n";
    }

    $image->setImageFormat($format);
    if ('JPEG' === $format) {
        $image->setImageCompression(Imagick::COMPRESSION_JPEG);
    }
    $image->setImageCompressionQuality(isset($settings) ? $settings->getQuality() : 90);
    $image->stripImage();

    if (ColorProfile::MODE_ICC === $colorMode) {
        $colorProfile->attachSrgb($image);
    }

    rapls_diag_report('6. after format, strip and re-tag (final)', $image, $dumpDir, ++$step);

    /* ------------------------------------------------------------ verdict */

    echo "\n\n" . str_repeat('═', 72) . "\n";
    echo "VERDICT\n";
    echo str_repeat('═', 72) . "\n";

    $final = rapls_diag_sample($image);

    if (!empty($final['uniform'])) {
        printf("The final image is a single flat colour: %s\n\n", $final['dominant']);
        echo "Look back through the steps above for the first one reporting UNIFORM.\n";
        echo "That step is the culprit. If step 1 is already uniform, Ghostscript\n";
        echo "returned a blank page and nothing in the plugin can recover it — the\n";
        echo "problem is in the render, not the conversion.\n";
    } else {
        printf(
            "The final image has %d distinct colours (mean %s). It is not blank.\n",
            $final['distinct'],
            $final['mean']
        );
        printf("Colour conversion used: %s\n", $colorMode);
        if (ColorProfile::MODE_NAIVE === $colorMode) {
            echo "\nThat is the arithmetic conversion. Colours will be oversaturated.\n";
            echo "Install a CMYK ICC profile or set rapls_pdf_image_creator_icc_paths.\n";
        }
    }

    $image->clear();
    $image->destroy();
} catch (Throwable $e) {
    echo "\n\nFAILED: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
