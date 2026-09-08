<?php
/**
 * End-to-end run of ImagickEngine::convert() against a complete Imagick stub,
 * asserting the §4-C step order.
 */

define('RAPLS_PIC_PLUGIN_DIR', dirname(__DIR__) . '/');
define('RAPLS_PIC_TEST_TMP', sys_get_temp_dir() . '/rapls-pic-tests');
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);
define('WEEK_IN_SECONDS', 604800);

$GLOBALS['transients'] = [];
$GLOBALS['filters'] = [];
$GLOBALS['options'] = [];

function add_filter($tag, $cb, $prio = 10, $args = 1) { $GLOBALS['filters'][$tag][] = $cb; }
function apply_filters($tag, $value, ...$rest) {
    foreach ($GLOBALS['filters'][$tag] ?? [] as $cb) { $value = $cb($value, ...$rest); }
    return $value;
}
function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['transients'][$k]); return true; }
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['options'][$k] = $v; return true; }
function __($s, $d = null) { return $s; }
function wp_mkdir_p($d) { return @mkdir($d, 0777, true) || is_dir($d); }

class ImagickException extends Exception {}
class ImagickPixel {
    public $c;
    public function __construct($c = 'white') {
        if (!in_array(strtolower($c), ['white', 'black', 'transparent'], true)) {
            throw new ImagickException("unparseable: $c");
        }
        $this->c = strtolower($c);
    }
    public function __toString() { return $this->c; }
}

class Imagick
{
    const COLORSPACE_CMYK = 12;
    const COLORSPACE_SRGB = 13;
    const RENDERINGINTENT_RELATIVE = 3;
    const LAYERMETHOD_FLATTEN = 6;
    const ALPHACHANNEL_REMOVE = 12;
    const ALPHACHANNEL_OPAQUE = 4;
    const COMPRESSION_JPEG = 8;
    const FILTER_LANCZOS = 22;
    const CHANNEL_ALL = 134217727;

    public static $log = [];
    public static $readColorspace = self::COLORSPACE_CMYK;

    /**
     * Which reads come back as one flat colour, keyed by the path handed in.
     *
     * 'suffix' covers a read with a [n] on the end, 'whole' a read without.
     * Modelling them separately is the whole point: on ImageMagick 6.9.13-25
     * one returns an empty raster and the other returns the page, for the
     * same file at the same moment.
     *
     * @var array<string, bool>
     */
    public static $emptyFor = ['suffix' => false, 'whole' => false];

    /** Pages a read without a suffix reports. */
    public static $pageCount = 3;

    public $flat = false;

    public $colorspace = self::COLORSPACE_SRGB;
    public $profiles = [];
    public $w = 2000, $h = 3000;

    public function setResolution($x, $y) { self::$log[] = "setResolution($x)"; }
    public function readImage($p) {
        $suffixed = (bool) preg_match('/\[\d+\]$/', $p);
        self::$log[] = 'readImage' . ($suffixed ? '[n]' : '(whole)');
        $this->colorspace = self::$readColorspace;
        $this->flat = self::$emptyFor[$suffixed ? 'suffix' : 'whole'];
    }
    public function getImageChannelRange($channel) {
        return $this->flat ? ['minima' => 65535.0, 'maxima' => 65535.0] : ['minima' => 0.0, 'maxima' => 65535.0];
    }
    public function getNumberImages() { return self::$pageCount; }
    public function setIteratorIndex($i) { self::$log[] = "setIteratorIndex($i)"; return true; }
    public function getImage() { self::$log[] = 'getImage'; $n = clone $this; return $n; }
    public function getImageColorspace() { return $this->colorspace; }
    public function setImageColorspace($cs) { self::$log[] = "setImageColorspace($cs)"; $this->colorspace = $cs; }
    public function transformImageColorspace($cs) { self::$log[] = 'transformImageColorspace'; $this->colorspace = $cs; }
    public function getImageProfiles($n, $v = true) { return $v ? $this->profiles : array_keys($this->profiles); }
    public function profileImage($n, $d) { self::$log[] = 'profileImage'; $this->profiles[$n] = $d; }
    public function setImageRenderingIntent($i) { self::$log[] = 'setImageRenderingIntent'; }
    public function setImageBackgroundColor($p) { self::$log[] = "setImageBackgroundColor($p)"; }
    public function mergeImageLayers($m) { self::$log[] = 'mergeImageLayers'; $n = clone $this; return $n; }
    public function setImageAlphaChannel($a) { self::$log[] = "setImageAlphaChannel($a)"; }
    public function resizeImage($w, $h, $f, $b) { self::$log[] = "resizeImage($w,$h)"; $this->w = $w; $this->h = $h; }
    public function getImageWidth() { return $this->w; }
    public function getImageHeight() { return $this->h; }
    public function setImageFormat($f) { self::$log[] = "setImageFormat($f)"; }
    public function setImageCompression($c) { self::$log[] = 'setImageCompression'; }
    public function setImageCompressionQuality($q) { self::$log[] = "quality($q)"; }
    public function stripImage() { self::$log[] = 'stripImage'; $this->profiles = []; }
    public function writeImage($p) { self::$log[] = 'writeImage'; file_put_contents($p, str_repeat('x', 42)); return true; }
    public function clear() { self::$log[] = 'clear'; }
    public function destroy() { self::$log[] = 'destroy'; }
    public static function queryFormats($p) { return ['PDF']; }
    public static function getVersion() { return ['versionString' => 'ImageMagick 7.1.1-47']; }
}

require RAPLS_PIC_PLUGIN_DIR . 'includes/FailureCode.php';
require RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/EngineInterface.php';
require RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ConversionResult.php';
require RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ColorProfile.php';
require RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ImagickEngine.php';

use Rapls\PDFImageCreator\Engine\ImagickEngine;

$dir = RAPLS_PIC_TEST_TMP . '/icc';
@mkdir($dir, 0777, true);
foreach ([['sRGB2014.icc', 200], ['CoatedFOGRA39.icc', 300]] as [$n, $s]) {
    file_put_contents("$dir/$n", str_repeat("\0", 36) . 'acsp' . str_repeat("\0", $s - 40));
}
$pdf = RAPLS_PIC_TEST_TMP . '/fake.pdf';
file_put_contents($pdf, '%PDF-1.4');

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    $ok = $got === $want;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? 'ok  ' : 'FAIL', $label);
    if (!$ok) { echo "     got:  " . json_encode($got) . "\n     want: " . json_encode($want) . "\n"; }
}
function run(array $options, $colorspace = Imagick::COLORSPACE_CMYK, $withProfiles = true, ?callable $alsoHook = null) {
    global $pdf, $dir;
    $GLOBALS['transients'] = [];
    $GLOBALS['filters'] = [];
    $GLOBALS['options'] = [];
    Imagick::$log = [];
    Imagick::$readColorspace = $colorspace;
    if ($withProfiles) {
        add_filter('rapls_pdf_image_creator_icc_paths', function ($p, $t) use ($dir) {
            return $t === 'srgb' ? ["$dir/sRGB2014.icc"] : ["$dir/CoatedFOGRA39.icc"];
        });
    } else {
        add_filter('rapls_pdf_image_creator_icc_paths', function ($p, $t) { return []; });
    }
    // run() wipes the filter table, so anything a test wants hooked has to be
    // registered here rather than before the call.
    if (null !== $alsoHook) {
        $alsoHook();
    }
    $r = (new ImagickEngine())->convert($pdf, RAPLS_PIC_TEST_TMP . '/out.img', $options);
    return [$r, Imagick::$log];
}

echo "=== R-2: CMYK PDF, profiles present, JPEG ===\n";
[$r, $log] = run(['format' => 'jpeg', 'bgcolor' => 'white', 'quality' => 90]);
check('conversion succeeded', $r->isSuccess(), true);
check('step order', $log, [
    'setResolution(150)',
    'readImage[n]',
    'profileImage',                 // 3. colour conversion, before resize
    'setImageRenderingIntent',
    'profileImage',
    'setImageColorspace(13)',
    'setImageBackgroundColor(white)',
    'mergeImageLayers',             // 4. alpha flattened onto white
    'clear',
    'setImageAlphaChannel(12)',
    'resizeImage(683,1024)',        // 6. resize after conversion
    'setImageFormat(JPEG)',         // 7. format
    'setImageCompression',
    'quality(90)',
    'stripImage',                   // 8. strip last
    'profileImage',                 // sRGB re-tagged after strip
    'writeImage',
    'clear',
    'destroy',
]);
check('diagnostics recorded CMYK', $GLOBALS['options'][ImagickEngine::DIAGNOSTICS_OPTION]['colorspace'], 12);

echo "\n=== 1.4.0: recovering a page that reads back empty ===\n";

// Measured on ImageMagick 6.9.13-25: asking for file.pdf[0] returns a raster
// of the right size with nothing in it, while reading the whole file and
// selecting page 0 returns the page. Same file, same server, same second.
// A blank first read is therefore not proof of a blank page.

Imagick::$emptyFor = ['suffix' => false, 'whole' => false];
Imagick::$pageCount = 3;
[$r, $log] = run(['format' => 'jpeg']);
check('a page that reads fine is read once', substr_count(implode(' ', $log), 'readImage'), 1);
check('  ...with the page suffix', in_array('readImage[n]', $log, true), true);
check('  ...and nothing is remembered', $GLOBALS['transients']['rapls_pic_page_suffix'] ?? null, null);

// The failure this exists for.
Imagick::$emptyFor = ['suffix' => true, 'whole' => false];
[$r, $log] = run(['format' => 'jpeg']);
check('an empty suffixed read is retried', in_array('readImage(whole)', $log, true), true);
check('  ...and the page is selected from the whole file', in_array('setIteratorIndex(0)', $log, true), true);
check('  ...the conversion still succeeded', $r->isSuccess(), true);
check('  ...and the server is remembered as needing it', $GLOBALS['transients']['rapls_pic_page_suffix'] ?? null, 'yes');

// A page that really is blank must not cost a second render every time.
Imagick::$emptyFor = ['suffix' => true, 'whole' => true];
[$r, $log] = run(['format' => 'jpeg']);
check('a genuinely blank page is retried once', in_array('readImage(whole)', $log, true), true);
check('  ...and recorded as not the suffix', $GLOBALS['transients']['rapls_pic_page_suffix'] ?? null, 'no');

// Once known broken, the suffix is not tried at all.
Imagick::$emptyFor = ['suffix' => true, 'whole' => false];
[$r, $log] = run(['format' => 'jpeg'], Imagick::COLORSPACE_CMYK, true, function () {
    set_transient('rapls_pic_page_suffix', 'yes', 604800);
});
check('a known-broken suffix is skipped', in_array('readImage[n]', $log, true), false);
check('  ...and the whole file is read instead', in_array('readImage(whole)', $log, true), true);

// Asking for a page the file does not have must not lose the thumbnail.
Imagick::$emptyFor = ['suffix' => true, 'whole' => false];
Imagick::$pageCount = 1;
[$r, $log] = run(['format' => 'jpeg', 'page' => 5]);
check('an out-of-range page falls back to the first read', $r->isSuccess(), true);
Imagick::$pageCount = 3;

Imagick::$emptyFor = ['suffix' => false, 'whole' => false];

echo "\n=== 1.4.0: the before_resize extension point ===\n";

// With nothing listening it must be invisible. The whole argument for adding a
// filter to a shipped plugin is that its absence changes nothing.
check('no listener leaves the log untouched', in_array('before_resize', $log, true), false);

$seen = [];
$watch = function () use (&$seen) {
    add_filter('rapls_pdf_image_creator_before_resize', function ($image, $options) use (&$seen) {
        $seen['class'] = get_class($image);
        $seen['size'] = $image->getImageWidth() . 'x' . $image->getImageHeight();
        $seen['attachment_id'] = $options['attachment_id'] ?? null;
        $seen['source_path'] = $options['source_path'] ?? null;
        Imagick::$log[] = 'before_resize';
        return $image;
    });
};

[$r2, $log2] = run(
    ['format' => 'jpeg', 'attachment_id' => 42, 'source_path' => '/uploads/x.pdf'],
    Imagick::COLORSPACE_CMYK,
    true,
    $watch
);

check('the filter ran', isset($seen['class']), true);
check('it is handed an Imagick', $seen['class'] ?? '', 'Imagick');
check('it carries the attachment id', $seen['attachment_id'] ?? null, 42);
check('it carries the source path', $seen['source_path'] ?? null, '/uploads/x.pdf');

// Position is the point. After the alpha channel is gone, because measuring a
// page against its background is meaningless while transparency survives;
// before the resize, because a bounding box read off a downsampled page is a
// blurred bounding box.
$alphaAt = array_search('setImageAlphaChannel(12)', $log2, true);
$filterAt = array_search('before_resize', $log2, true);
$resizeAt = false;
foreach ($log2 as $i => $entry) {
    if (0 === strpos($entry, 'resizeImage')) { $resizeAt = $i; break; }
}
check('runs after the alpha channel is removed', $alphaAt < $filterAt, true);
check('runs before the resize', $filterAt < $resizeAt, true);

// The page it sees is the full render, not something already scaled down.
// The stub renders 2000x3000 and the resize step takes it to 683x1024. The
// filter has to see the former.
check('sees the unresized page', $seen['size'] ?? '', '2000x3000');

// A listener that returns rubbish must not take the thumbnail with it.
[$r3, $log3] = run(['format' => 'jpeg'], Imagick::COLORSPACE_CMYK, true, function () {
    add_filter('rapls_pdf_image_creator_before_resize', function ($image, $options) { return null; });
});
check('a filter returning null is ignored', $r3->isSuccess(), true);
check('and the page still gets written', in_array('writeImage', $log3, true), true);

// One that swaps the instance is honoured: cropping produces a new Imagick.
[$r4, $log4] = run(['format' => 'jpeg'], Imagick::COLORSPACE_CMYK, true, function () {
    add_filter('rapls_pdf_image_creator_before_resize', function ($image, $options) {
        $replacement = new Imagick();
        $replacement->w = 400;
        $replacement->h = 400;
        return $replacement;
    });
});
check('a replacement instance is used', $r4->getWidth(), 400);
check('diagnostics recorded icc', $GLOBALS['options'][ImagickEngine::DIAGNOSTICS_OPTION]['mode'], 'icc');

echo "\n=== R-1: RGB PDF is untouched by colour code ===\n";
[$r, $log] = run(['format' => 'jpeg', 'bgcolor' => 'white'], Imagick::COLORSPACE_SRGB);
check('no colour conversion calls', array_values(array_filter($log, function ($s) {
    return in_array($s, ['profileImage', 'transformImageColorspace', 'setImageRenderingIntent'], true);
})), []);
check('mode passthrough', $GLOBALS['options'][ImagickEngine::DIAGNOSTICS_OPTION]['mode'], 'passthrough');

echo "\n=== R-6: no profiles anywhere -> arithmetic, no fatal ===\n";
[$r, $log] = run(['format' => 'jpeg'], Imagick::COLORSPACE_CMYK, false);
check('still succeeds', $r->isSuccess(), true);
check('used arithmetic', in_array('transformImageColorspace', $log, true), true);
check('no sRGB re-tag after strip', in_array('profileImage', $log, true), false);
check('mode naive', $GLOBALS['options'][ImagickEngine::DIAGNOSTICS_OPTION]['mode'], 'naive');

echo "\n=== R-4 / R-8: transparent background per format ===\n";
[$r, $log] = run(['format' => 'png', 'bgcolor' => 'transparent']);
check('PNG keeps transparency', in_array('setImageBackgroundColor(transparent)', $log, true), true);
check('PNG does not strip alpha', in_array('setImageAlphaChannel(12)', $log, true), false);

[$r, $log] = run(['format' => 'webp', 'bgcolor' => 'transparent']);
check('WebP keeps transparency', in_array('setImageBackgroundColor(transparent)', $log, true), true);
check('WebP does not strip alpha', in_array('setImageAlphaChannel(12)', $log, true), false);

[$r, $log] = run(['format' => 'jpeg', 'bgcolor' => 'transparent']);
check('JPEG forces white (was the black-thumbnail bug)', in_array('setImageBackgroundColor(white)', $log, true), true);
check('JPEG strips alpha', in_array('setImageAlphaChannel(12)', $log, true), true);

[$r, $log] = run(['format' => 'jpeg', 'bgcolor' => 'black']);
check('explicit black is respected', in_array('setImageBackgroundColor(black)', $log, true), true);

echo "\n=== R-8: all three formats get the same colour treatment ===\n";
$modes = [];
foreach (['jpeg', 'png', 'webp'] as $f) {
    run(['format' => $f, 'bgcolor' => 'white']);
    $modes[$f] = $GLOBALS['options'][ImagickEngine::DIAGNOSTICS_OPTION]['mode'];
}
check('identical conversion mode', array_unique(array_values($modes)), ['icc']);

echo "\n=== R-5: grayscale PDF keeps its channel ===\n";
define('COLORSPACE_GRAY', 2);
[$r, $log] = run(['format' => 'jpeg'], COLORSPACE_GRAY);
check('grayscale succeeds', $r->isSuccess(), true);
check('no colour conversion', $GLOBALS['options'][ImagickEngine::DIAGNOSTICS_OPTION]['mode'], 'passthrough');
check('colorspace never reassigned', in_array('setImageColorspace(13)', $log, true), false);

echo "\n=== R-7: no Imagick extension ===\n";
check('isAvailable() false without the extension', (new ImagickEngine())->isAvailable(), false);
$reqs = (new ImagickEngine())->getRequirements();
check('requirements report it', $reqs['extension']['status'], false);
check('no colour row without the extension', isset($reqs['color_management']), false);

echo "\n=== R-9: later pages take the same path ===\n";
[$r, $log] = run(['format' => 'jpeg', 'page' => 3]);
check('page 3 still colour managed', $GLOBALS['options'][ImagickEngine::DIAGNOSTICS_OPTION]['mode'], 'icc');

echo "\n=== §7-2: naive filter restores the old colours ===\n";
$GLOBALS['transients'] = []; $GLOBALS['filters'] = []; $GLOBALS['options'] = [];
Imagick::$log = []; Imagick::$readColorspace = Imagick::COLORSPACE_CMYK;
add_filter('rapls_pdf_image_creator_icc_paths', function ($p, $t) use ($dir) {
    return $t === 'srgb' ? ["$dir/sRGB2014.icc"] : ["$dir/CoatedFOGRA39.icc"];
});
add_filter('rapls_pdf_image_creator_color_conversion', function ($m) { return 'naive'; });
$r = (new ImagickEngine())->convert($pdf, RAPLS_PIC_TEST_TMP . '/out.img', ['format' => 'jpeg']);
check('pinned to arithmetic despite profiles', in_array('transformImageColorspace', Imagick::$log, true), true);
check('no ICC calls', in_array('profileImage', Imagick::$log, true), false);

echo "\n=== rendering resolution ===\n";
[$r, $log] = run(['format' => 'jpeg']);
check('defaults to 150 DPI', in_array('setResolution(150)', $log, true), true);

[$r, $log] = run(['format' => 'jpeg', 'resolution' => 300]);
check('explicit DPI reaches Imagick', in_array('setResolution(300)', $log, true), true);
check('  ...and still before readImage', array_search('setResolution(300)', $log, true) < array_search('readImage[n]', $log, true), true);

// The setting goes through a filter, so a caller can hand back something that
// would make Imagick render an empty page.
foreach ([0, -1, 'abc'] as $bad) {
    [$r, $log] = run(['format' => 'jpeg', 'resolution' => $bad]);
    check('bad DPI ' . json_encode($bad) . ' falls back to 150', in_array('setResolution(150)', $log, true), true);
}

// Raising the max dimensions alone cannot produce a bigger thumbnail -- the
// resize step only ever scales down. Raising the DPI is what makes the source
// raster bigger. This is what the 1.2.0 setting exists for.
[$r, $log] = run(['format' => 'jpeg', 'max_width' => 4096, 'max_height' => 4096]);
check('max dimensions above the raster do not upscale', array_values(array_filter($log, function ($s) {
    return strpos($s, 'resizeImage') === 0;
})), []);

echo "\n=== failure paths ===\n";
$r = (new ImagickEngine())->convert('/no/such.pdf', RAPLS_PIC_TEST_TMP . '/out.img', []);
check('missing PDF reports failure', $r->isSuccess(), false);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
