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
function set_transient($k, $v, $t = 0) { $GLOBALS['transients'][$k] = $v; $GLOBALS['transient_ttl'][$k] = $t; return true; }
function delete_transient($k) { unset($GLOBALS['transients'][$k]); return true; }
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['options'][$k] = $v; return true; }
function __($s, $d = null) { return $s; }
if (!function_exists('_n')) { function _n($one, $many, $n, $d = null) { return 1 === (int) $n ? $one : $many; } }
function wp_mkdir_p($d) { return @mkdir($d, 0777, true) || is_dir($d); }

class ImagickException extends Exception {}
class ImagickPixel {
    public $c;
    public $rgb = ['r' => 255, 'g' => 255, 'b' => 255];
    public function __construct($c = 'white') {
        // An array is the plugin sampling a pixel; a name is it naming one.
        if (is_array($c)) { $this->rgb = $c; $this->c = 'sampled'; return; }
        if (!in_array(strtolower($c), ['white', 'black', 'transparent'], true)) {
            throw new ImagickException("unparseable: $c");
        }
        $this->c = strtolower($c);
    }
    public function getColor() { return $this->rgb; }
    /** Alpha of a sampled pixel, 0 transparent to 1 opaque, as ImagickPixel normalises it. */
    public $alpha = 1.0;
    public function getColorValue($which) { return Imagick::COLOR_ALPHA === $which ? $this->alpha : 0.0; }
    public function __toString() { return $this->c; }
}

class Imagick
{
    // As ImageMagick 7.1.1 numbers them here; ImageMagick 6 builds have neither.
    const RESOURCETYPE_WIDTH = 10;
    const RESOURCETYPE_HEIGHT = 4;
    /** @var array<int, float> Limits in force; unset is unlimited. */
    public static $resourceLimits = [];
    /** @var string[] Every limit set, in order. */
    public static $limitLog = [];
    /** What ImageMagick reports for no limit: 2^64, past PHP_INT_MAX. */
    const UNLIMITED = 1.8446744073709552E+19;
    public static function getResourceLimit($type) { return self::$resourceLimits[$type] ?? self::UNLIMITED; }
    public static function setResourceLimit($type, $limit) {
        // As on PHP 7.4, where the parameter is an int: a float past
        // PHP_INT_MAX is refused, and the limit stays where it was.
        if (is_float($limit) && $limit >= (float) PHP_INT_MAX) {
            throw new TypeError('setResourceLimit(): Argument #2 ($limit) must be of type int, float given');
        }
        self::$limitLog[] = "$type=$limit"; self::$resourceLimits[$type] = $limit; return true;
    }
    const COLORSPACE_CMYK = 12;
    const COLORSPACE_SRGB = 13;
    const RENDERINGINTENT_RELATIVE = 3;
    const LAYERMETHOD_FLATTEN = 6;
    const ALPHACHANNEL_REMOVE = 12;
    const ALPHACHANNEL_OPAQUE = 4;
    const COMPRESSION_JPEG = 8;
    const FILTER_LANCZOS = 22;
    const CHANNEL_ALL = 134217727;
    const CHANNEL_ALPHA = 8;
    const COLOR_ALPHA = 18;

    public static $log = [];
    public static $readColorspace = self::COLORSPACE_CMYK;

    /**
     * ImageMagick 7 reading through pngalpha: the raster has an alpha
     * channel, and an unpainted page is transparent colour -- alpha 0
     * everywhere -- so its range over every channel at once is 0 to 65535
     * (measured on 7.1.1-15, review after 1.4.31).
     */
    public static $alphaRead = false;

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
    /** The size a read comes back at. */
    public static $readW = 2000, $readH = 3000;
    /** How many times a raster was scanned for its range of values. */
    public static $rangeScans = 0;

    /** The density this instance was given; a read's size follows it when $pageSize is set. */
    public $density = 72;
    public function setResolution($x, $y) { self::$log[] = "setResolution($x)"; $this->density = $x; }
    /** When set, a suffixed read of a page past $pageCount throws, as Ghostscript's "FirstPage > LastPage" does. */
    public static $throwPastEnd = false;
    /** The path the last read was given. */
    public static $lastPath = '';
    public function readImage($p) {
        $suffixed = (bool) preg_match('/\[(\d+)\]$/', $p, $asked);
        if ($suffixed && self::$throwPastEnd && (int) $asked[1] >= self::$pageCount) {
            self::$log[] = 'readImage[n]';
            throw new ImagickException('Failed to read the file');
        }
        if (self::$failReads > 0) {
            self::$failReads--;
            self::$log[] = 'readImage[n]';
            throw new ImagickException(self::$failMessage);
        }
        self::$log[] = 'readImage' . ($suffixed ? '[n]' : '(whole)');
        self::$lastPath = $p;
        [$this->w, $this->h] = [self::$readW, self::$readH];
        // The page itself, in points: drawn at this instance's density, and
        // rounded down -- the way that makes a measured page look smallest.
        if (null !== self::$pageSize) {
            $this->w = (int) floor(self::$pageSize[0] * $this->density / 72);
            $this->h = (int) floor(self::$pageSize[1] * $this->density / 72);
            // Past the width or height limit, ImageMagick 7 refuses the read.
            // A limit of 0 is none.
            $overW = self::getResourceLimit(self::RESOURCETYPE_WIDTH) > 0 && $this->w > self::getResourceLimit(self::RESOURCETYPE_WIDTH);
            $overH = self::getResourceLimit(self::RESOURCETYPE_HEIGHT) > 0 && $this->h > self::getResourceLimit(self::RESOURCETYPE_HEIGHT);
            if ($overW || $overH) {
                throw new ImagickException('Invalid IHDR data');
            }
        }
        $this->colorspace = self::$readColorspace;
        $this->flat = self::$emptyFor[$suffixed ? 'suffix' : 'whole'];
        // Ghostscript 9.27's fault: drawn only with transparency switched off.
        if (self::$gsRescues) {
            $this->flat = false === strpos((string) getenv('GS_OPTIONS'), '-dNOTRANSPARENCY');
        }
    }
    /** When true, a read is flat unless GS_OPTIONS carries -dNOTRANSPARENCY. */
    public static $gsRescues = false;
    public function getImageChannelRange($channel) {
        self::$rangeScans++;
        if (self::$alphaRead) {
            if (self::CHANNEL_ALL === $channel) {
                return ['minima' => 0.0, 'maxima' => 65535.0];
            }
            if (self::CHANNEL_ALPHA === $channel) {
                return $this->flat ? ['minima' => 0.0, 'maxima' => 0.0] : ['minima' => 0.0, 'maxima' => 65535.0];
            }
        }
        return $this->flat ? ['minima' => 65535.0, 'maxima' => 65535.0] : ['minima' => 0.0, 'maxima' => 65535.0];
    }
    public function getImageAlphaChannel() { return self::$alphaRead; }
    /** The colour a flat page reports. White is the failure; ink is a page. */
    public static $flatColor = ['r' => 255, 'g' => 255, 'b' => 255];
    /** The colour a page with content reports at its middle. */
    public static $contentColor = ['r' => 12, 'g' => 34, 'b' => 56];
    public function getImagePixelColor($x, $y) {
        self::$log[] = 'getImagePixelColor';
        $pixel = new ImagickPixel($this->flat ? self::$flatColor : self::$contentColor);
        $pixel->alpha = self::$alphaRead && $this->flat ? 0.0 : 1.0;
        return $pixel;
    }
    /** What a count (ping) answers, when a test makes it differ from the pages a read finds. */
    public static $countSays = null;
    public function getNumberImages() { return self::$countSays ?? self::$pageCount; }
    /** A ping's page size in points, as ImageMagick reports it at 72 dpi (A4 unless a test says otherwise). */
    public static $pingSize = [595, 842];
    /** The page read, in points, when a test makes it differ from what a ping reports (ImageMagick 7 pings the largest page). */
    public static $pageSize = null;
    /** How many reads still to fail, from the next one on. */
    public static $failReads = 0;
    /** What a read failing that way says. */
    public static $failMessage = 'Failed to read the file';
    /** When set, asking a page's size throws. */
    public static $pingThrows = false;
    /** What a ping that throws says. */
    public static $pingMessage = 'Failed to read the file';
    /** A page's size ([n]), or the pages counted (no suffix) -- without drawing. */
    public function pingImage($p) {
        $suffixed = (bool) preg_match('/\[(\d+)\]$/', $p, $asked);
        self::$log[] = $suffixed ? 'pingImage[n]' : 'pingImage';
        if (self::$pingThrows || ($suffixed && self::$throwPastEnd && (int) $asked[1] >= self::$pageCount)) {
            throw new ImagickException(self::$pingThrows ? self::$pingMessage : 'Failed to read the file');
        }
        [$this->w, $this->h] = self::$pingSize;
        return true;
    }
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
    'setResolution(72)',            // the page's size, without drawing it
    'pingImage[n]',
    'clear',
    'setResolution(150)',           // 1. resolution, before the read
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

echo "\n=== 1.4.0: a page that renders white is a failure, not a thumbnail ===\n";

// Ghostscript can fail to parse a content stream, report it on stderr where
// nobody looks, and hand back a correctly sized white page. Nothing throws.
// Storing that is worse than storing nothing, because it looks like an answer.
Imagick::$emptyFor = ['suffix' => true, 'whole' => true];
Imagick::$flatColor = ['r' => 255, 'g' => 255, 'b' => 255];
[$r, $log] = run(['format' => 'jpeg']);
check('an all-white page is refused', $r->isSuccess(), false);
check('  ...with a code that names the cause', $r->getCode(), 'blank_render');
check('  ...and nothing is written', in_array('writeImage', $log, true), false);
// Review after 1.4.31: a page blank with the Ghostscript retry too says
// nothing about the server, and "no" written over a "yes" took the Status
// tab's "Needed a workaround" away. Nothing is recorded.
[$r] = run(['format' => 'jpeg'], Imagick::COLORSPACE_CMYK, true, function () {
    set_transient('rapls_pic_page_suffix', 'yes', 604800);
    set_transient('rapls_pic_gs_options', 'yes', 604800);
});
check('  ...and the Ghostscript retry, blank too, does not take "needed a workaround" away', [$r->getCode(), $GLOBALS['transients']['rapls_pic_gs_options'] ?? null], ['blank_render', 'yes']);

// Review after 1.4.31: on ImageMagick 7 the page reads with an alpha channel,
// and a blank one is transparent everywhere, so its range over every channel
// at once is not one value. It was kept as a thumbnail. Transparent black
// under it is still white once flattened.
Imagick::$alphaRead = true;
Imagick::$flatColor = ['r' => 0, 'g' => 0, 'b' => 0];
[$r, $log] = run(['format' => 'jpeg']);
check('an unpainted page read with an alpha channel (ImageMagick 7) is refused as blank', [$r->isSuccess(), $r->getCode(), in_array('writeImage', $log, true)], [false, 'blank_render', false]);
Imagick::$emptyFor = ['suffix' => false, 'whole' => false];
[$r, $log] = run(['format' => 'jpeg']);
check('  ...a page with content read the same way is kept', [$r->isSuccess(), in_array('writeImage', $log, true)], [true, true]);
Imagick::$alphaRead = false;
Imagick::$emptyFor = ['suffix' => true, 'whole' => true];
Imagick::$flatColor = ['r' => 255, 'g' => 255, 'b' => 255];

// A page of solid ink is one colour and is a real page.
Imagick::$flatColor = ['r' => 0, 'g' => 163, 'b' => 218];
[$r, $log] = run(['format' => 'jpeg']);
check('a page of solid colour is kept', $r->isSuccess(), true);
check('  ...and written out', in_array('writeImage', $log, true), true);

// Somebody whose cover page really is blank can say so.
Imagick::$flatColor = ['r' => 255, 'g' => 255, 'b' => 255];
[$r, $log] = run(['format' => 'jpeg'], Imagick::COLORSPACE_CMYK, true, function () {
    add_filter('rapls_pdf_image_creator_refuse_blank_page', function () { return false; });
});
check('the filter can allow a blank page through', $r->isSuccess(), true);

Imagick::$emptyFor = ['suffix' => false, 'whole' => false];

echo "\n=== Review after 1.4.33: a flat page is not read again as the whole document ===\n";

// The whole-document retry took its limits from the page count the file
// states, while Ghostscript draws every page it finds: a 60-page file stating
// one page took 954 MB. It had never been seen to help, and is gone. A flat
// page is read again only with Ghostscript's transparency switch.
Imagick::$emptyFor = ['suffix' => false, 'whole' => false];
Imagick::$pageCount = 3;
[$r, $log] = run(['format' => 'jpeg']);
check('a page that reads fine is read once', substr_count(implode(' ', $log), 'readImage'), 1);
check('  ...with the page suffix', in_array('readImage[n]', $log, true), true);
check('  ...and nothing is remembered', $GLOBALS['transients']['rapls_pic_page_suffix'] ?? null, null);

Imagick::$emptyFor = ['suffix' => true, 'whole' => false];
Imagick::$flatColor = ['r' => 0, 'g' => 163, 'b' => 218];
[$r, $log] = run(['format' => 'jpeg']);
check('a flat page is never read as the whole document', [in_array('readImage(whole)', $log, true), in_array('setIteratorIndex(0)', $log, true)], [false, false]);
check('  ...only by page, again with the switch', array_values(preg_grep('/^readImage/', $log)), ['readImage[n]', 'readImage[n]']);
check('  ...and nothing is written about reading by page', $GLOBALS['transients']['rapls_pic_page_suffix'] ?? null, null);
check('  ...the second read at the same resolution', array_values(preg_grep('/^(setResolution\(150\)|readImage)/', $log)), ['setResolution(150)', 'readImage[n]', 'setResolution(150)', 'readImage[n]']);
[$r, $log] = run(['format' => 'jpeg'], Imagick::COLORSPACE_CMYK, true, function () {
    set_transient('rapls_pic_page_suffix', 'yes', 604800);
});
check('  ...not even where an earlier version left "read it whole" behind', [in_array('readImage(whole)', $log, true), in_array('readImage[n]', $log, true)], [false, true]);
Imagick::$flatColor = ['r' => 255, 'g' => 255, 'b' => 255];

// A raster an add-on drew with Ghostscript and hands over to be finished is
// not a PDF: a flat one is read once, and nothing is written into this
// server's answers about how Ghostscript renders PDFs.
$pdfWas = $pdf;
$pdf = RAPLS_PIC_TEST_TMP . '/raster.png';
file_put_contents($pdf, "\x89PNG\r\n\x1a\nraster");
Imagick::$emptyFor = ['suffix' => true, 'whole' => true];
Imagick::$flatColor = ['r' => 0, 'g' => 0, 'b' => 0];
[$r, $log] = run(['format' => 'jpeg'], Imagick::COLORSPACE_CMYK, true, function () {
    set_transient('rapls_pic_gs_options', 'yes', 604800);
});
check('a raster that is not a PDF is read once, without the PDF retry', [substr_count(implode(' ', $log), 'readImage'), in_array('readImage(whole)', $log, true)], [1, false]);
check('  ...and this server\'s answers are left as they were', [$GLOBALS['transients']['rapls_pic_page_suffix'] ?? null, $GLOBALS['transients']['rapls_pic_gs_options'] ?? null], [null, 'yes']);
check('  ...and its size is not asked', in_array('pingImage[n]', $log, true), false);
@unlink($pdf);
$pdf = $pdfWas;
Imagick::$flatColor = ['r' => 255, 'g' => 255, 'b' => 255];

// The flatness is scanned once a read: asked again for the white check, the
// whole raster was scanned a third time for every page with anything on it.
Imagick::$emptyFor = ['suffix' => false, 'whole' => false];
Imagick::$rangeScans = 0;
run(['format' => 'jpeg']);
$scansDrawn = Imagick::$rangeScans;
Imagick::$emptyFor = ['suffix' => true, 'whole' => true];
Imagick::$rangeScans = 0;
run(['format' => 'jpeg']);
check('a page is scanned for flatness once a read: once when drawn, twice when flat and read again', [$scansDrawn, Imagick::$rangeScans], [1, 2]);
Imagick::$emptyFor = ['suffix' => false, 'whole' => false];

// Only a flat page is judged on its colour: a page with something on it and
// white in the middle -- most documents -- is a page, read directly or
// rescued by Ghostscript's switch.
Imagick::$emptyFor = ['suffix' => false, 'whole' => false];
Imagick::$contentColor = ['r' => 255, 'g' => 255, 'b' => 255];
[$r] = run(['format' => 'jpeg']);
$direct = $r->isSuccess();
Imagick::$gsRescues = true;
[$r, $log] = run(['format' => 'jpeg']);
Imagick::$gsRescues = false;
check('a page with content and white in the middle is kept, read directly or rescued', [$direct, $r->isSuccess(), $GLOBALS['transients']['rapls_pic_gs_options'] ?? null], [true, true, 'yes']);
Imagick::$contentColor = ['r' => 12, 'g' => 34, 'b' => 56];

echo "\n=== Review after 1.4.33: a page too large to draw at the resolution asked is drawn at less ===\n";

// A 470-byte PDF declaring one page of 200 by 200 inches took 76 s and
// 2.9 GB at 150 dpi. Its size is asked first, without drawing it.
$pingWas = Imagick::$pingSize;
// The resolution of the read that draws the page: the last one.
$resolutionRead = static function (array $log): ?string {
    $reads = array_keys($log, 'readImage[n]', true);
    $at = $reads ? end($reads) : false;
    $before = array_values(preg_grep('/^setResolution/', array_slice($log, 0, false === $at ? 0 : $at)));
    return $before ? end($before) : null;
};
$readsOf = static function (array $log): int {
    return count(array_keys($log, 'readImage[n]', true));
};
// A ping in ImageMagick 7 answers for the largest page in the document, so
// these say the page read is that page unless a test says otherwise.
$sized = static function (array $size, ?array $page = null): void {
    Imagick::$pingSize = $size;
    Imagick::$pageSize = $page ?? $size;
};
[$r, $log] = run(['format' => 'jpeg']);
check('an A4 page at 150 dpi is drawn at 150', [$resolutionRead($log), in_array('pingImage[n]', $log, true)], ['setResolution(150)', true]);
check('  ...its size asked at 72 dpi, before the read', array_slice($log, 0, 4), ['setResolution(72)', 'pingImage[n]', 'clear', 'setResolution(150)']);
check('  ...and nothing else read to measure it', $readsOf($log), 1);
$sized([14400, 14400]);
[$r, $log] = run(['format' => 'jpeg']);
check('a 200-inch page is drawn within 40 megapixels', [$resolutionRead($log), ImagickEngine::RENDER_MAX_PIXELS], ['setResolution(31.62)', 40000000]);
check('  ...and still made into a thumbnail', $r->isSuccess(), true);
check('  ...after drawing it small to measure it (4 megapixels)', [$readsOf($log), in_array('setResolution(10)', $log, true)], [2, true]);
// Exactly at the limit is drawn as asked; a pixel over is not.
$sized([72, 72]);
$at = static function ($limit) use ($resolutionRead) {
    [, $log] = run(['format' => 'jpeg', 'resolution' => 144], Imagick::COLORSPACE_CMYK, true, function () use ($limit) {
        add_filter('rapls_pdf_image_creator_max_render_pixels', function () use ($limit) { return $limit; });
    });
    return $resolutionRead($log);
};
check('  ...at the limit as asked, a pixel over it lowered', [$at(20736), $at(20735)], ['setResolution(144)', 'setResolution(143.99)']);
// Below 4 megapixels, the page is not measured at more than the resolution
// asked: that read would be larger than the one it is for.
[, $log] = run(['format' => 'jpeg', 'resolution' => 144], Imagick::COLORSPACE_CMYK, true, function () {
    add_filter('rapls_pdf_image_creator_max_render_pixels', function () { return 20735; });
});
check('  ...and measured at no more than the resolution asked', max(array_map(static function ($line) { return (float) substr($line, 14); }, preg_grep('/^setResolution/', $log))), 144.0);
// Only ever lowered; a listener can raise the limit, and nonsense from one is
// the default.
$sized([14400, 14400]);
check('  ...a listener can raise it', $at(PHP_INT_MAX), 'setResolution(144)');
check('  ...an answer that is not a positive number is the default', [$at(0), $at(-5)], ['setResolution(31.62)', 'setResolution(31.62)']);
[, $log] = run(['format' => 'jpeg'], Imagick::COLORSPACE_CMYK, true, function () {
    add_filter('rapls_pdf_image_creator_max_render_pixels', function () { return 'lots'; });
});
check('  ...and so is one that is not a number', $resolutionRead($log), 'setResolution(31.62)');
$sized([72, 72]);
check('  ...or only begins with one', $at('20735 pixels'), 'setResolution(144)');
$sized([595, 842]);
[, $log] = run(['format' => 'jpeg', 'resolution' => 72]);
check('  ...never raised', $resolutionRead($log), 'setResolution(72)');

// Verification of this change: ImageMagick 7 pings the largest page whichever
// page is named, so an A4 cover beside a 200-inch sheet came out at 256
// pixels instead of 1024. The page asked is measured before anything is
// lowered.
$sized([14400, 14400], [595, 842]);
[$r, $log] = run(['format' => 'jpeg']);
check('an A4 cover in a document with a 200-inch page is drawn at 150', [$resolutionRead($log), $r->isSuccess()], ['setResolution(150)', true]);
check('  ...measured first, at the density that keeps the largest page to 4 megapixels', array_slice(preg_grep('/^setResolution|^readImage|^pingImage/', $log), 0, 4), ['setResolution(72)', 'pingImage[n]', 'setResolution(10)', 'readImage[n]']);
$sized([2384, 3370], [595, 842]);
[, $log] = run(['format' => 'jpeg', 'resolution' => 600]);
check('  ...and an A4 cover beside an A0 sheet at 600 dpi', $resolutionRead($log), 'setResolution(600)');
// Measured a pixel large each way, never smaller than it is: an A4 page at
// 600 dpi with the limit just above it is not lowered on a rounding.
$sized([2384, 3370], [595, 842]);
[, $log] = run(['format' => 'jpeg', 'resolution' => 600], Imagick::COLORSPACE_CMYK, true, function () {
    add_filter('rapls_pdf_image_creator_max_render_pixels', function () { return 595 * 842 * (600 / 72) ** 2; });
});
check('  ...measured a pixel over, so a page exactly at the limit is lowered a little', $resolutionRead($log) !== 'setResolution(600)' && (float) substr((string) $resolutionRead($log), 14) > 590, true);
// ...and the same for the height, on a strip whose width scales exactly.
$sized([14400, 14400], [7200, 71.9]);
[, $log] = run(['format' => 'jpeg', 'resolution' => 144], Imagick::COLORSPACE_CMYK, true, function () {
    add_filter('rapls_pdf_image_creator_max_render_pixels', function () { return 7200 * 71.9 * 4; });
});
check('  ...in height as well as width', $resolutionRead($log) !== 'setResolution(144)', true);
// The measuring read failing: the ping stands, which only ever lowers more.
$sized([14400, 14400], [595, 842]);
Imagick::$failReads = 1;
[$r, $log] = run(['format' => 'jpeg']);
Imagick::$failReads = 0;
check('  ...a measuring read that fails leaves the ping to decide', [$resolutionRead($log), $r->isSuccess()], ['setResolution(31.62)', true]);
// Held at 2 dpi, the measuring read of a document with a 200,000-point page
// is 31 megapixels: read. With a 300,000-point page it would be 69: not read,
// and the ping says no resolution Ghostscript draws at fits.
$sized([200000, 200000], [595, 842]);
[$r, $log] = run(['format' => 'jpeg']);
check('a document with a 200,000-point page: the cover measured at 2 dpi and drawn at 150', [array_values(array_intersect(['setResolution(2)'], $log)), $resolutionRead($log)], [['setResolution(2)'], 'setResolution(150)']);
// With a 300,000-point page in the document, the cover is still measured at 2
// dpi -- under width and height limits, so that the sheet itself would be
// refused before its pixels are held -- and drawn as asked (verification of
// this change: it used to be refused unread).
$sized([300000, 300000], [595, 842]);
Imagick::$limitLog = [];
[$r, $log] = run(['format' => 'jpeg']);
check('  ...with a 300,000-point page, the cover measured at 2 dpi under a limit and drawn at 150', [$readsOf($log), $resolutionRead($log), $r->isSuccess()], [2, 'setResolution(150)', true]);
check('  ...the limit an ordinary page at the default limit would fit, put back afterwards (unlimited, as the largest int)', [Imagick::$limitLog, Imagick::$resourceLimits], [['10=6325', '4=6325', '10=' . PHP_INT_MAX, '4=' . PHP_INT_MAX], [10 => PHP_INT_MAX, 4 => PHP_INT_MAX]]);
// A stricter limit the site set stays in force, and 0 -- no limit -- is lowered
// and put back as 0.
Imagick::$resourceLimits = [10 => 2000, 4 => 0];
Imagick::$limitLog = [];
[$r, $log] = run(['format' => 'jpeg']);
check('  ...a stricter width limit the site set is left alone; a height of 0 (none) is lowered and put back', [Imagick::$limitLog, Imagick::$resourceLimits, $r->isSuccess()], [['4=6325', '4=0'], [10 => 2000, 4 => 0], true]);
// ImageMagick 6 has no width or height limit: nothing is read unbounded, and
// the ping decides.
$names = new ReflectionProperty(ImagickEngine::class, 'limitTypeNames');
if (PHP_VERSION_ID < 80100) { $names->setAccessible(true); }
$namesWere = $names->getValue();
$names->setValue(null, ['RESOURCETYPE_NO_SUCH_WIDTH', 'RESOURCETYPE_HEIGHT']);
Imagick::$resourceLimits = [];
Imagick::$limitLog = [];
[$r, $log] = run(['format' => 'jpeg']);
$names->setValue(null, $namesWere);
check('  ...without the limits (ImageMagick 6), the cover is not read at all, and the ping refuses it', [$readsOf($log), Imagick::$limitLog, $r->getCode()], [0, [], 'resource_limit']);
// A request that dies inside the read runs no finally: the limits are put
// back at shutdown, for the next request this worker serves.
$dyingRead = RAPLS_PIC_TEST_TMP . '/limits-die.php';
file_put_contents($dyingRead, '<?php namespace { class ImagickException extends Exception {}'
    . ' class Imagick { const RESOURCETYPE_WIDTH = 10; const RESOURCETYPE_HEIGHT = 4; public static $l = [];'
    . ' public static function getResourceLimit($t) { return self::$l[$t] ?? 1.8446744073709552E+19; }'
    . ' public static function setResourceLimit($t, $v) { self::$l[$t] = $v; return true; }'
    . ' public function setResolution($x, $y) {}'
    . ' public function readImage($p) { echo "died with " . json_encode(self::$l) . "\n"; register_shutdown_function(static function () { echo "after: " . json_encode(Imagick::$l); }); exit(1); } }'
    . ' require ' . var_export(RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/EngineInterface.php', true) . ';'
    . ' require ' . var_export(RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ImagickEngine.php', true) . ';'
    . ' $m = new ReflectionMethod(Rapls\\PDFImageCreator\\Engine\\ImagickEngine::class, "measurePage"); if (PHP_VERSION_ID < 80100) { $m->setAccessible(true); }'
    . ' $m->invoke((new ReflectionClass(Rapls\\PDFImageCreator\\Engine\\ImagickEngine::class))->newInstanceWithoutConstructor(), "/tmp/none.pdf[0]", 2.0, 6325); echo "survived"; }');
$dyingOut = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($dyingRead) . ' 2>&1', $dyingOut);
@unlink($dyingRead);
check('  ...and a request that dies inside the read puts them back at shutdown', implode("\n", $dyingOut), "died with {\"10\":6325,\"4\":6325}\nafter: {\"10\":" . PHP_INT_MAX . ",\"4\":" . PHP_INT_MAX . "}");
// Registered for shutdown once a request, however many pages are measured.
$onceChild = RAPLS_PIC_TEST_TMP . '/limits-once.php';
file_put_contents($onceChild, '<?php namespace { class ImagickException extends Exception {}'
    . ' class Imagick { const RESOURCETYPE_WIDTH = 10; const RESOURCETYPE_HEIGHT = 4; public static $l = [];'
    . ' public static function getResourceLimit($t) { return self::$l[$t] ?? 1.8446744073709552E+19; }'
    . ' public static function setResourceLimit($t, $v) { self::$l[$t] = $v; return true; }'
    . ' public function setResolution($x, $y) {} public function readImage($p) { return true; } public function setIteratorIndex($i) { return true; }'
    . ' public function getImageWidth() { return 17; } public function getImageHeight() { return 23; } public function clear() {} } }'
    . ' namespace Rapls\\PDFImageCreator\\Engine { function register_shutdown_function($cb) { $GLOBALS["registered"][] = $cb; } }'
    . ' namespace { require ' . var_export(RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/EngineInterface.php', true) . ';'
    . ' require ' . var_export(RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ImagickEngine.php', true) . ';'
    . ' $m = new ReflectionMethod(Rapls\\PDFImageCreator\\Engine\\ImagickEngine::class, "measurePage"); if (PHP_VERSION_ID < 80100) { $m->setAccessible(true); }'
    . ' $e = (new ReflectionClass(Rapls\\PDFImageCreator\\Engine\\ImagickEngine::class))->newInstanceWithoutConstructor();'
    . ' foreach ([1, 2, 3] as $n) { $m->invoke($e, "/tmp/none.pdf[0]", 2.0, 6325); }'
    . ' echo json_encode($GLOBALS["registered"] ?? []); }');
$onceOut = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($onceChild) . ' 2>&1', $onceOut);
@unlink($onceChild);
check('  ...registered for shutdown once, for three pages measured', implode("\n", $onceOut), json_encode([['Rapls\\PDFImageCreator\\Engine\\ImagickEngine', 'restoreLimits']]));
// The server's own width and height limits, where stricter: a page is drawn
// within them, a pixel short, rather than drawn and refused (verification of
// 1.4.34). A limit of 0 or "unlimited" is none.
Imagick::$resourceLimits = [10 => 5000, 4 => 5000];
$sized([14400, 14400]);
[$r, $log] = run(['format' => 'jpeg']);
$sideFit = [$resolutionRead($log), $r->isSuccess()];
$sized([595, 842]);
[, $log] = run(['format' => 'jpeg', 'resolution' => 600]);
$a4Fit = $resolutionRead($log);
[, $log] = run(['format' => 'jpeg']);
$a4Within = $resolutionRead($log);
Imagick::$resourceLimits = [10 => 0, 4 => 4000];
[, $log] = run(['format' => 'jpeg', 'resolution' => 600]);
$heightOnly = $resolutionRead($log);
Imagick::$resourceLimits = [];
check('a page is drawn within the server\'s width and height limits', [$sideFit, $a4Fit, $a4Within, $heightOnly], [['setResolution(24.99)', true], 'setResolution(427.46)', 'setResolution(150)', 'setResolution(341.95)']);
$sized([300000, 300000], [595, 842]);
// A listener raising the limit (the add-on's crop plan) does not raise the
// measuring read's: that read is only ever an ordinary page's size.
Imagick::$resourceLimits = [];
Imagick::$limitLog = [];
run(['format' => 'jpeg'], Imagick::COLORSPACE_CMYK, true, function () {
    add_filter('rapls_pdf_image_creator_max_render_pixels', function () { return 1000000000; });
});
check('  ...under the same limit when a listener raises the drawing limit', array_slice(Imagick::$limitLog, 0, 2), ['10=6325', '4=6325']);
Imagick::$resourceLimits = [];
$sized([300000, 300000]);
[$r, $log] = run(['format' => 'jpeg']);
check('the 300,000-point page itself: refused by the limit, and not drawn', [$readsOf($log), $r->getCode(), Imagick::$resourceLimits[10] ?? null], [1, 'resource_limit', PHP_INT_MAX]);
Imagick::$resourceLimits = [];
$sized([10000000, 10000000]);
[$r, $log] = run(['format' => 'jpeg']);
check('a page too large at 2 dpi is refused, not drawn at 1 (Ghostscript fails at 1)', [$readsOf($log), $r->getCode()], [1, 'resource_limit']);
Imagick::$resourceLimits = [];
// Within the limit at 2 dpi, nothing is limited.
$sized([14400, 14400]);
Imagick::$limitLog = [];
run(['format' => 'jpeg']);
check('  ...a measuring read the 4-megapixel density already bounds sets no limit', Imagick::$limitLog, []);
$sized([150000, 150000]);
[$r, $log] = run(['format' => 'jpeg']);
check('  ...one that fits at a little over 2 dpi is drawn at it', $resolutionRead($log), 'setResolution(3.03)');
// A size that cannot be asked: drawn as before, and the read says what is wrong.
$sized([595, 842]);
// A ping that fails: the page is measured by a bounded read at 2 dpi instead,
// and drawn from that (verification of this change: it used to be read at
// the resolution asked, unbounded -- a 300,000-point page past the server's
// own width limit had Ghostscript draw it at 150 dpi for over five minutes).
Imagick::$pingThrows = true;
Imagick::$limitLog = [];
[$r, $log] = run(['format' => 'jpeg']);
check('a page whose size cannot be pinged is measured at 2 dpi, bounded, and drawn as asked', [$readsOf($log), in_array('setResolution(2)', $log, true), array_slice(Imagick::$limitLog, 0, 2), $resolutionRead($log), $r->isSuccess()], [2, true, ['10=6325', '4=6325'], 'setResolution(150)', true]);
$sized([14400, 14400]);
[$r, $log] = run(['format' => 'jpeg']);
// 400 pixels at 2 dpi, measured a pixel over: 14,436 points, a little under 31.62.
check('  ...a large one measured so is lowered as a pinged one is', $resolutionRead($log), 'setResolution(31.54)');
$sized([595, 842]);
// Both failing: nothing more is read, and the ping's error is the failure,
// in the words and with the code a failed read would have had.
Imagick::$failReads = 1;
[$r, $log] = run(['format' => 'jpeg']);
check('  ...and one the bounded read cannot draw either is not read again: the ping\'s error, as a read\'s', [$readsOf($log), $r->getCode(), $r->getError()], [1, 'render_error', 'Imagick error: Failed to read the file']);
foreach ([
    'not authorized `PDF\' @ error/constitute.c/IsCoderAuthorized/426' => 'pdf_blocked_by_policy',
    'Invalid IHDR data `/tmp/magick-x\' @ error/png.c/MagickPNGErrorHandler/1492' => 'resource_limit',
] as $message => $code) {
    Imagick::$pingMessage = $message;
    Imagick::$failReads = 1;
    [$r, $log] = run(['format' => 'jpeg']);
    check("  ...classified as a read's: $code", [$readsOf($log), $r->getCode()], [1, $code]);
}
Imagick::$failReads = 0;
Imagick::$pingMessage = 'Failed to read the file';
// ImageMagick 6 has no limit to bound the read with: not read at all.
$names = new ReflectionProperty(ImagickEngine::class, 'limitTypeNames');
if (PHP_VERSION_ID < 80100) { $names->setAccessible(true); }
$names->setValue(null, ['RESOURCETYPE_NO_SUCH_WIDTH', 'RESOURCETYPE_HEIGHT']);
[$r, $log] = run(['format' => 'jpeg']);
$names->setValue(null, ['RESOURCETYPE_WIDTH', 'RESOURCETYPE_HEIGHT']);
check('  ...without the limits (ImageMagick 6), a failed ping reads nothing and is the failure', [$readsOf($log), $r->getCode()], [0, 'render_error']);
Imagick::$pingThrows = false;
$sized([0, 0]);
[$r, $log] = run(['format' => 'jpeg']);
check('  ...and one that answers no size', $resolutionRead($log), 'setResolution(150)');
Imagick::$pingSize = $pingWas;
Imagick::$pageSize = null;

echo "\n=== Review after 1.4.33: size limits from a filter that are not positive ===\n";
foreach ([0, -10] as $bad) {
    [$r, $log] = run(['format' => 'jpeg', 'max_width' => $bad, 'max_height' => $bad]);
    check('max size ' . $bad . ' falls back to 1024', [$r->isSuccess(), array_values(preg_grep('/^resizeImage/', $log))], [true, ['resizeImage(683,1024)']]);
}
// A page far wider than it is tall keeps a height of at least a pixel.
Imagick::$pingSize = [595, 842];
$wideWas = [Imagick::$readW, Imagick::$readH];
[Imagick::$readW, Imagick::$readH] = [100000, 10];
[$r, $log] = run(['format' => 'jpeg']);
[Imagick::$readW, Imagick::$readH] = $wideWas;
check('a page a thousand times wider than tall is resized to a height of 1, not 0', array_values(preg_grep('/^resizeImage/', $log)), ['resizeImage(1024,1)']);

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

echo "\n=== Review after 1.4.31: numbers that are not ints, and errors that are not Exceptions ===\n";

// A filter answering "800" (a post meta value) or 512.0 reached Imagick's int
// parameters as a TypeError that nothing caught, and the upload died.
Imagick::$emptyFor = ['suffix' => false, 'whole' => false];
[$r, $log] = run(['format' => 'jpeg', 'max_width' => '800', 'max_height' => 512.0, 'quality' => '85', 'resolution' => '150', 'page' => '0']);
check('options given as strings and floats are made whole numbers', [$r->isSuccess(), in_array('quality(85)', $log, true), (bool) preg_grep('/^resizeImage\(\d+,\d+\)$/', $log)], [true, true, true]);
[$r] = run(['format' => 'jpeg', 'max_width' => 'wide']);
check('  ...a value that is not a number falls back to the default', $r->isSuccess(), true);

// An Error from a listener is a failed conversion, not a fatal.
[$r] = run(['format' => 'jpeg'], Imagick::COLORSPACE_CMYK, true, function () {
    add_filter('rapls_pdf_image_creator_before_resize', function () { throw new \Error('a listener broke'); });
});
check('an Error during the conversion is a failure, not a fatal', [$r->isSuccess(), false !== strpos((string) $r->getError(), 'a listener broke')], [false, true]);
[$r] = run(['format' => 'jpeg'], Imagick::COLORSPACE_CMYK, true, function () {
    add_filter('rapls_pdf_image_creator_imagick_result', function () { return 'not a result'; });
});
check('a result listener answering something else leaves the result as it was', [$r instanceof \Rapls\PDFImageCreator\Engine\ConversionResult, $r->isSuccess()], [true, true]);

echo "\n=== Review of 1.4.32: a negative page from a filter is page 0 ===\n";
Imagick::$emptyFor = ['suffix' => false, 'whole' => false];
[$r] = run(['format' => 'jpeg', 'page' => -3]);
check('a page below 0 is read as the first page', [$r->isSuccess(), substr(Imagick::$lastPath, -3)], [true, '[0]']);

echo "\n=== Review of 1.4.32: the page count from the file's own page tree ===\n";
$treeFile = static function (string $body): string {
    $f = RAPLS_PIC_TEST_TMP . '/tree-' . md5($body) . '.pdf';
    file_put_contents($f, "%PDF-1.4\n" . $body . "\n%%EOF\n");
    return $f;
};
check('the root /Count is read', ImagickEngine::pagesFromBytes($treeFile("1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [3 0 R 4 0 R] /Count 7 >> endobj")), 7);
check('  ...the largest, across a file saved over itself', ImagickEngine::pagesFromBytes($treeFile("2 0 obj << /Type /Pages /Count 3 /Kids [] >> endobj\n2 0 obj << /Type /Pages /Count 5 /Kids [] >> endobj\n9 0 obj << /Type /Pages /Count 2 /Parent 2 0 R >> endobj")), 5);
check('  ...a /Type /Page is not a page tree', ImagickEngine::pagesFromBytes($treeFile("3 0 obj << /Type /Page /Count 99 >> endobj")), null);
check('  ...a tree with a dictionary inside it is left to ImageMagick', ImagickEngine::pagesFromBytes($treeFile("2 0 obj << /Type /Pages /Resources << /Font 1 >> /Count 4 >> endobj")), null);
check('  ...and one whose own /Count sits after a dictionary holding another /Count', ImagickEngine::pagesFromBytes($treeFile("2 0 obj << /Type /Pages /Kids [3 0 R] /X << /Count 50 >> /Count 2 >> endobj")), null);
check('  ...so is a file with object streams', ImagickEngine::pagesFromBytes($treeFile("2 0 obj << /Type /Pages /Count 4 >> endobj\n5 0 obj << /Type /ObjStm /N 3 >> endobj")), null);
check('  ...and an outline\'s /Count is not read as pages', ImagickEngine::pagesFromBytes($treeFile("7 0 obj << /Type /Outlines /Count 40 >> endobj\n2 0 obj << /Type /Pages /Count 2 >> endobj")), 2);
check('  ...no file, no answer', ImagickEngine::pagesFromBytes(RAPLS_PIC_TEST_TMP . '/missing.pdf'), null);
// Review after 1.4.33: a page tree that does not open, or does not close, is
// no answer -- not a count read from whatever dictionary is nearest.
check('  ...a page tree with no << before it, or no >> after it, is no answer', [
    ImagickEngine::pagesFromBytes($treeFile("/Type /Pages /Count 6 >> endobj")),
    ImagickEngine::pagesFromBytes($treeFile("2 0 obj << /Type /Pages /Count 2 >> endobj\n3 0 obj << /Type /Pages /Count 6")),
], [null, null]);
// Review after 1.4.32: half a million page-tree dictionaries in an 11 MB file
// made the list of matches alone a fatal error under a 128 MB limit. Read one
// at a time, and past PAGE_TREE_MAX_NODES left to ImageMagick -- asked in a
// PHP with a 64 MB limit, where the old way could not finish.
$manyNodes = RAPLS_PIC_TEST_TMP . '/many-nodes.pdf';
file_put_contents($manyNodes, "%PDF-1.4\n" . str_repeat("<</Type/Pages/Count 1>>\n", 500000) . "%%EOF\n");
$nodesChild = RAPLS_PIC_TEST_TMP . '/many-nodes.php';
file_put_contents($nodesChild, '<?php define("ABSPATH", "/"); function __($s, $d = null) { return $s; }'
    . ' require ' . var_export(RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/EngineInterface.php', true) . ';'
    . ' require ' . var_export(RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ColorProfile.php', true) . ';'
    . ' require ' . var_export(RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ImagickEngine.php', true) . ';'
    . ' var_export(Rapls\\PDFImageCreator\\Engine\\ImagickEngine::pagesFromBytes($argv[1]));');
$nodesOut = [];
exec(escapeshellarg(PHP_BINARY) . ' -n -d memory_limit=64M ' . escapeshellarg($nodesChild) . ' ' . escapeshellarg($manyNodes) . ' 2>&1', $nodesOut);
file_put_contents($manyNodes, "%PDF-1.4\n" . str_repeat("<</Type/Pages/Count 2>>\n", 300) . "%%EOF\n");
check('  ...half a million page-tree nodes: no answer, and no fatal error under 64 MB; some hundreds are read', [implode("\n", $nodesOut), ImagickEngine::pagesFromBytes($manyNodes)], ['NULL', 2]);
@unlink($manyNodes);
@unlink($nodesChild);
// ...and many matches inside one dictionary the size of the file are not each
// searched and copied whole (75 s of CPU for a 32 MB file before).
file_put_contents($manyNodes, "%PDF-1.4\n<<" . str_repeat('/Type /Pages /Count 1 ' . str_repeat(' ', 3200), 2000) . ">>\n%%EOF\n");
$started = microtime(true);
$overlapping = ImagickEngine::pagesFromBytes($manyNodes);
check('  ...matches that share one dictionary: no answer, at once', [$overlapping, microtime(true) - $started < 2.0], [null, true]);
@unlink($manyNodes);
// Review after 1.4.32: a file is read for its page tree only with room for
// it -- read whole on a 64 MB limit, a 32 MB file was a fatal error.
$memoryFor = new ReflectionMethod(ImagickEngine::class, 'memoryFor');
if (PHP_VERSION_ID < 80100) { $memoryFor->setAccessible(true); }
$limitWas = ini_get('memory_limit');
ini_set('memory_limit', (string) (int) ((memory_get_usage() + 20 * 1048576) / 1048576) . 'M');
$roomFor = [$memoryFor->invoke(null, 1048576), $memoryFor->invoke(null, 16 * 1048576)];
ini_set('memory_limit', '-1');
$roomFor[] = $memoryFor->invoke(null, 32 * 1048576);
ini_set('memory_limit', $limitWas);
check('  ...read only with room for it: 1 MB in 20 MB to spare, not 16 MB; no limit, anything', $roomFor, [true, false, true]);
// A limit that reads as no bytes at all is not a reason to refuse: PHP would
// not run under one, so it is a value misread, and the file is still read.
$zeroChild = RAPLS_PIC_TEST_TMP . '/memory-zero.php';
file_put_contents($zeroChild, '<?php define("ABSPATH", "/"); function __($s, $d = null) { return $s; } function wp_convert_hr_to_bytes($v) { return 0; }'
    . ' require ' . var_export(RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/EngineInterface.php', true) . ';'
    . ' require ' . var_export(RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ColorProfile.php', true) . ';'
    . ' require ' . var_export(RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ImagickEngine.php', true) . ';'
    . ' $m = new ReflectionMethod(Rapls\\PDFImageCreator\\Engine\\ImagickEngine::class, "memoryFor"); if (PHP_VERSION_ID < 80100) { $m->setAccessible(true); }'
    . ' echo ini_get("memory_limit"), " ", var_export($m->invoke(null, 32 * 1048576), true);');
$zeroOut = [];
exec(escapeshellarg(PHP_BINARY) . ' -n -d memory_limit=64M ' . escapeshellarg($zeroChild) . ' 2>&1', $zeroOut);
@unlink($zeroChild);
check('  ...a limit read as 0 bytes refuses nothing', implode("\n", $zeroOut), '64M true');

echo "\n=== Review after 1.4.31: a page past the end of the PDF says so ===\n";
Imagick::$emptyFor = ['suffix' => false, 'whole' => false];
Imagick::$throwPastEnd = true;
$pagesWas = Imagick::$pageCount;
Imagick::$pageCount = 2;
[$r] = run(['format' => 'jpeg', 'page' => 3]);
check('a page past the end is page_out_of_range, with both numbers', [$r->getCode(), false !== strpos((string) $r->getError(), 'Page 3 was asked for (counting from 0), but this PDF has 2 pages.')], ['page_out_of_range', true]);
check('  ...a permanent failure', \Rapls\PDFImageCreator\FailureCode::isPermanent('page_out_of_range'), true);
[$r] = run(['format' => 'jpeg', 'page' => 1]);
check('  ...a page the file has is drawn as before', $r->isSuccess(), true);
// Verification of the change: the page equal to the count is past the end too
// (pages count from 0), and one page is "1 page".
[$r] = run(['format' => 'jpeg', 'page' => 2]);
$atCount = $r->getCode();
Imagick::$pageCount = 1;
[$r] = run(['format' => 'jpeg', 'page' => 1]);
check('  ...the page equal to the count is past the end; a one-page PDF has "1 page"', [$atCount, $r->getCode(), false !== strpos((string) $r->getError(), 'but this PDF has 1 page.')], ['page_out_of_range', 'page_out_of_range', true]);
// The count comes from the file's page tree where it can. A page the tree
// has is not counted again by ImageMagick; a page the tree does not have is
// confirmed by it first, since a damaged file can state fewer pages than
// Ghostscript finds (review after 1.4.32).
$pdfWas = $pdf;
$pdf = $treeFile("1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >> endobj");
[$r, $log] = run(['format' => 'jpeg', 'page' => 2]);
$confirmed = [$r->getCode(), in_array('pingImage', $log, true)];
Imagick::$countSays = 9;
[$r, $log] = run(['format' => 'jpeg', 'page' => 2]);
$understated = $r->getCode();
Imagick::$countSays = null;
$pdf = $treeFile("2 0 obj << /Type /Pages /Kids [3 0 R] /Count 5 >> endobj");
[$r, $log] = run(['format' => 'jpeg', 'page' => 1]);
$within = [$r->getCode(), in_array('pingImage', $log, true)];
$pdf = $pdfWas;
check('  ...counted from the page tree; a page past it is confirmed by ImageMagick before it is called missing', [$confirmed, $understated, $within], [['page_out_of_range', true], 'render_error', ['render_error', false]]);
// Verification of 1.4.34: a page past the server's width or height limit is
// left out of a ping of the whole document, and was then called missing. When
// ImageMagick says the page was too large, it is too large.
Imagick::$throwPastEnd = false;
Imagick::$pageCount = 1;
$pdf = $treeFile("2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj");
Imagick::$failReads = 1;
Imagick::$failMessage = "Invalid IHDR data `/tmp/magick-x' @ error/png.c/MagickPNGErrorHandler/1492";
[$r] = run(['format' => 'jpeg', 'page' => 1]);
$overLimit = $r->getCode();
Imagick::$failReads = 1;
Imagick::$failMessage = 'Failed to read the file';
[$r] = run(['format' => 'jpeg', 'page' => 1]);
$pdf = $pdfWas;
Imagick::$failReads = 0;
check('  ...a page ImageMagick calls too large is resource_limit, not missing; the same page failing otherwise is missing', [$overLimit, $r->getCode()], ['resource_limit', 'page_out_of_range']);
Imagick::$pageCount = $pagesWas;

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
