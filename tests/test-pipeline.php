<?php
/**
 * End-to-end run of ImagickEngine::convert() against a complete Imagick stub,
 * asserting the §4-C step order.
 */

define('RAPLS_PIC_PLUGIN_DIR', dirname(__DIR__) . '/');
// A folder of this run's own, removed when it ends: one shared by every run
// made two suites run side by side fail each other, and left the files they
// wrote behind (review after 1.4.36).
if (!function_exists('rapls_pic_test_tmp')) {
    function rapls_pic_test_tmp(): string
    {
        // A child a suite starts works in its parent's folder, which the
        // parent removes.
        $inherited = getenv('RAPLS_PIC_TEST_TMP');

        if (is_string($inherited) && '' !== $inherited && is_dir($inherited)) {
            return $inherited;
        }

        $dir = sys_get_temp_dir() . '/rapls-pic-tests-' . getmypid() . '-' . bin2hex(random_bytes(4));
        @mkdir($dir, 0700, true);
        putenv('RAPLS_PIC_TEST_TMP=' . $dir);
        register_shutdown_function(static function () use ($dir): void {
            $remove = static function (string $path) use (&$remove): void {
                if (is_dir($path) && !is_link($path)) {
                    foreach ((array) scandir($path) as $name) {
                        if ('.' !== $name && '..' !== $name) {
                            $remove($path . '/' . $name);
                        }
                    }
                    @rmdir($path);
                } else {
                    @unlink($path);
                }
            };
            $remove($dir);
        });
        return $dir;
    }
}
define('RAPLS_PIC_TEST_TMP', rapls_pic_test_tmp());
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
    // As ImageMagick 7.1.1 numbers them here; ImageMagick 6 builds have no
    // width or height, and every build has the pixel cache's three.
    const RESOURCETYPE_WIDTH = 10;
    const RESOURCETYPE_HEIGHT = 4;
    const RESOURCETYPE_DISK = 2;
    const RESOURCETYPE_MAP = 5;
    const RESOURCETYPE_MEMORY = 6;
    /** @var array<int, float> Limits in force; unset is unlimited. */
    public static $resourceLimits = [];
    /** @var string[] Every limit set, in order. */
    public static $limitLog = [];
    /** What ImageMagick reports for no limit: 2^64, past PHP_INT_MAX. */
    const UNLIMITED = 1.8446744073709552E+19;
    /** What this build says of itself: Q16 without HDRI unless a test says otherwise. */
    public static $features = 'DPC Cipher Modules';
    public static $quantum = 16;
    public static function getConfigureOptions($pattern = '*') { return ['FEATURES' => self::$features]; }
    public static function getQuantumDepth() { return ['quantumDepthLong' => self::$quantum, 'quantumDepthString' => 'Q' . self::$quantum]; }
    /** Whether getResourceLimit() throws, as a build that will not say might. */
    public static $limitThrows = false;
    public static function getResourceLimit($type) {
        if (self::$limitThrows) {
            throw new ImagickException('unable to get resource limit');
        }
        return self::$resourceLimits[$type] ?? self::UNLIMITED;
    }
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
    /** Reads so far in this run, and the message a numbered one fails with. */
    public static $readCount = 0;
    public static $failAt = [];
    public function readImage($p) {
        $n = ++self::$readCount;
        if (isset(self::$failAt[$n])) {
            self::$log[] = 'readImage[n]';
            throw new ImagickException(self::$failAt[$n]);
        }
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
            // A limit of 0 refuses every read (measured on 7.1.1).
            $overW = $this->w > self::getResourceLimit(self::RESOURCETYPE_WIDTH);
            $overH = $this->h > self::getResourceLimit(self::RESOURCETYPE_HEIGHT);
            if ($overW || $overH) {
                throw new ImagickException('Invalid IHDR data');
            }
            // Under a pixel, Ghostscript draws nothing and the read fails
            // (measured: a 5-point page at 2 dpi).
            if (0 === $this->w || 0 === $this->h) {
                throw new ImagickException('Failed to read the file');
            }
            // Past memory and disk both, the pixel cache is refused: eight
            // bytes a pixel, Q16 RGBA.
            $cache = max(self::$resourceLimits[self::RESOURCETYPE_MEMORY] ?? self::UNLIMITED, self::$resourceLimits[self::RESOURCETYPE_DISK] ?? self::UNLIMITED);
            if ($this->w * $this->h * 8 > $cache) {
                throw new ImagickException("CacheResourcesExhausted `x.pdf' @ error/cache.c/OpenPixelCache/3892");
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
    /** The alpha a sampled pixel reports, when a test says. */
    public static $pixelAlpha = null;
    /** The colour a page with content reports at its middle. */
    public static $contentColor = ['r' => 12, 'g' => 34, 'b' => 56];
    public function getImagePixelColor($x, $y) {
        self::$log[] = 'getImagePixelColor';
        $pixel = new ImagickPixel($this->flat ? self::$flatColor : self::$contentColor);
        $pixel->alpha = self::$pixelAlpha ?? (self::$alphaRead && $this->flat ? 0.0 : 1.0);
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
// The file's page boxes, which decide whether a page is measured before it
// is drawn: an A4 page unless a test says otherwise.
function boxes(array ...$sizes): void {
    global $pdf;
    $body = '';
    foreach ($sizes as $size) {
        $body .= '<< /Type /Page /MediaBox [0 0 ' . $size[0] . ' ' . $size[1] . "] >>\n";
    }
    file_put_contents($pdf, "%PDF-1.4\n" . $body);
}
boxes([595, 842]);

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
    Imagick::$readCount = 0;
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
// 2.9 GB at 150 dpi. Since the review after 1.4.34, the file's own boxes are
// read first, with nothing run; a page is measured by drawing it only when
// they say it may be too large, or cannot say.
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
// The resolutions every read was given, in order.
$densities = static function (array $log): array {
    return array_values(preg_grep('/^setResolution/', $log));
};
// The page read, in points; the file's boxes are what boxes() wrote.
$page = static function (?array $size): void {
    Imagick::$pageSize = $size;
};
[$r, $log] = run(['format' => 'jpeg']);
check('an A4 page at 150 dpi is drawn at 150, from its box: nothing pinged, nothing measured', [$densities($log), $readsOf($log), in_array('pingImage[n]', $log, true)], [['setResolution(150)'], 1, false]);
boxes([14400, 14400]);
$page([14400, 14400]);
[$r, $log] = run(['format' => 'jpeg']);
// 40 megapixels; measured at the density that draws the largest box at 4
// megapixels (10 dpi): 2000 pixels, a pixel over.
check('a 200-inch page is drawn within 40 megapixels', [$densities($log), $r->isSuccess(), ImagickEngine::RENDER_MAX_PIXELS], [['setResolution(10)', 'setResolution(31.6)'], true, 40000000]);
[, $log] = run(['format' => 'jpeg', 'max_width' => 300, 'max_height' => 300]);
check('  ...whatever the thumbnail\'s size', $resolutionRead($log), 'setResolution(31.6)');
// An A4 page at 600 dpi is 35 megapixels: drawn as asked, for any thumbnail
// size -- the resolution is the site's choice, and the add-on's crops and
// before_resize get the page as drawn (review after 1.4.34).
boxes([595, 842]);
$page([595, 842]);
[, $log] = run(['format' => 'jpeg', 'resolution' => 600]);
$small = $resolutionRead($log);
[, $log] = run(['format' => 'jpeg', 'resolution' => 600, 'max_width' => 300, 'max_height' => 300]);
check('  ...an A4 page at 600 dpi is drawn at 600, for a 1024- or a 300-pixel thumbnail, from its box alone', [$small, $resolutionRead($log), $readsOf($log)], ['setResolution(600)', 'setResolution(600)', 1]);
// Exactly at the limit is drawn as asked; a pixel over is not.
boxes([612, 842]);
$page([612, 842]);
$at = static function ($limit) use ($resolutionRead) {
    [, $log] = run(['format' => 'jpeg', 'resolution' => 144], Imagick::COLORSPACE_CMYK, true, function () use ($limit) {
        add_filter('rapls_pdf_image_creator_max_render_pixels', function () use ($limit) { return $limit; });
    });
    return $resolutionRead($log);
};
check('  ...at the limit as asked, a pixel over it lowered', [$at(612 * 842 * 4), $at(612 * 842 * 4 - 1)], ['setResolution(144)', 'setResolution(143.89)']);
// Below 4 megapixels, the page is not measured at more than the resolution
// asked: that read would be larger than the one it is for.
[, $log] = run(['format' => 'jpeg', 'resolution' => 144], Imagick::COLORSPACE_CMYK, true, function () {
    add_filter('rapls_pdf_image_creator_max_render_pixels', function () { return 612 * 842 * 4 - 1; });
});
check('  ...and measured at no more than the resolution asked', max(array_map(static function ($line) { return (float) substr($line, 14); }, $densities($log))), 144.0);
// Only ever lowered; a listener can raise the limit, and nonsense from one is
// the default.
boxes([14400, 14400]);
$page([14400, 14400]);
check('  ...a listener can raise it', $at(PHP_INT_MAX), 'setResolution(144)');
check('  ...an answer that is not a positive number is the default', [$at(0), $at(-5)], ['setResolution(31.6)', 'setResolution(31.6)']);
[, $log] = run(['format' => 'jpeg'], Imagick::COLORSPACE_CMYK, true, function () {
    add_filter('rapls_pdf_image_creator_max_render_pixels', function () { return 'lots'; });
});
check('  ...and so is one that is not a number', $resolutionRead($log), 'setResolution(31.6)');
check('  ...or only begins with one', $at('20735 pixels'), 'setResolution(31.6)');
// The listener is handed the thumbnail's default.
$handed = null;
run(['format' => 'jpeg', 'max_width' => 300, 'max_height' => 200], Imagick::COLORSPACE_CMYK, true, function () use (&$handed) {
    add_filter('rapls_pdf_image_creator_max_render_pixels', function ($pixels) use (&$handed) { $handed = $pixels; return $pixels; });
});
check('  ...handed 40,000,000, whatever the thumbnail\'s size', $handed, 40000000);
boxes([595, 842]);
$page([595, 842]);
[, $log] = run(['format' => 'jpeg', 'resolution' => 72]);
check('  ...never raised', $resolutionRead($log), 'setResolution(72)');

// A 200,000-point page is drawn within 40 megapixels at 2.27 dpi; within a
// listener's lower limit it cannot be drawn at all.
boxes([200000, 200000]);
$page([200000, 200000]);
[$r, $log] = run(['format' => 'jpeg']);
$big = [$resolutionRead($log), $r->isSuccess()];
[$r] = run(['format' => 'jpeg'], Imagick::COLORSPACE_CMYK, true, function () {
    add_filter('rapls_pdf_image_creator_max_render_pixels', function () { return 16777215; });
});
check('a 200,000-point page is drawn within 40 megapixels; within a listener\'s lower limit, refused', [$big, $r->getCode()], [['setResolution(2.27)', true], 'resource_limit']);

// An A4 cover beside a 200-inch sheet: the boxes say the document may be too
// large, and the cover itself is measured before anything is lowered.
boxes([595, 842], [14400, 14400]);
$page([595, 842]);
[$r, $log] = run(['format' => 'jpeg']);
check('an A4 cover in a document with a 200-inch page is drawn at 150', [$resolutionRead($log), $r->isSuccess()], ['setResolution(150)', true]);
check('  ...measured first, at the density that keeps the largest box to 4 megapixels', array_slice($densities($log), 0, 2), ['setResolution(10)', 'setResolution(150)']);
boxes([595, 842], [2384, 3370]);
[, $log] = run(['format' => 'jpeg', 'resolution' => 600, 'max_width' => 4096, 'max_height' => 4096]);
check('  ...and an A4 cover beside an A0 sheet at 600 dpi', $resolutionRead($log), 'setResolution(600)');
// Measured a pixel large each way, never smaller than it is: an A4 page at
// 600 dpi with the limit just above it is not lowered on a rounding.
[, $log] = run(['format' => 'jpeg', 'resolution' => 600], Imagick::COLORSPACE_CMYK, true, function () {
    add_filter('rapls_pdf_image_creator_max_render_pixels', function () { return 595 * 842 * (600 / 72) ** 2; });
});
check('  ...measured a pixel over, so a page exactly at the limit is lowered a little', $resolutionRead($log) !== 'setResolution(600)' && (float) substr((string) $resolutionRead($log), 14) > 590, true);
// ...and the same for the height, on a strip whose width scales exactly.
boxes([7200, 71.9], [14400, 14400]);
$page([7200, 71.9]);
[, $log] = run(['format' => 'jpeg', 'resolution' => 144], Imagick::COLORSPACE_CMYK, true, function () {
    add_filter('rapls_pdf_image_creator_max_render_pixels', function () { return 7200 * 71.9 * 4; });
});
check('  ...in height as well as width', $resolutionRead($log) !== 'setResolution(144)', true);
// Review after 1.4.34: a measured page larger than the ping said was thrown
// away for the ping's answer. Now the measure is the page's size, larger
// than its boxes or not.
boxes([595, 842], [4000, 5000]);
$page([14400, 14400]);
[, $log] = run(['format' => 'jpeg']);
// Measured at 32 dpi for the largest box, it is refused by the width limit
// there, and measured again at 2: 14,436 points -- and, lowered by that, once
// more at the density that holds it to four million pixels, which gives its
// own 14,400 (review after 1.4.38: a page put at 2 dpi by a refusal was left
// at the coarse figure).
check('  ...a page measured larger than its boxes is drawn at its measured size', $resolutionRead($log), 'setResolution(31.6)');

// The measuring read failing. Not for its size: measured again at 36 dpi (a
// page under a pixel at the measuring density fails to draw at all), and a
// page that fails there too fails as the read would -- not drawn at the
// resolution asked from a ping's answer.
boxes([595, 842], [4000, 5000]);
$page([595, 842]);
Imagick::$failReads = 1;
[$r, $log] = run(['format' => 'jpeg']);
check('  ...a measuring read that fails is tried at 36 dpi, and the page drawn from that', [array_slice($densities($log), 1), $r->isSuccess()], [['setResolution(36)', 'setResolution(150)'], true]);
Imagick::$failReads = 2;
[$r, $log] = run(['format' => 'jpeg']);
check('  ...one that fails there too is the failure, in a read\'s words, and nothing more is read', [$readsOf($log), $r->getCode(), $r->getError()], [2, 'render_error', 'Imagick error: Failed to read the file']);
foreach ([
    'not authorized `PDF\' @ error/constitute.c/IsCoderAuthorized/426' => 'pdf_blocked_by_policy',
    "FailedToExecuteCommand `'gs' -dSAFER' (127) @ error/delegate.c/ExternalDelegateCommand/516" => 'pdf_unsupported',
] as $message => $code) {
    Imagick::$failMessage = $message;
    Imagick::$failReads = 2;
    [$r, $log] = run(['format' => 'jpeg']);
    check("  ...classified as a read's: $code", [$readsOf($log), $r->getCode()], [2, $code]);
}
Imagick::$failReads = 0;
Imagick::$failMessage = 'Failed to read the file';
// Review after 1.4.37: not at 36 dpi where the file holds a page that would
// be past the measuring read's bound there -- the limits refuse it only after
// Ghostscript has drawn it, and that drawing is bounded by nothing of ours.
boxes([595, 842], [14400, 14400]);
Imagick::$failReads = 1;
[$r, $log] = run(['format' => 'jpeg']);
check('  ...not tried at 36 dpi where a box in the file is past the bound there: the failure, after one read', [$readsOf($log), $r->getCode()], [1, 'render_error']);
Imagick::$failReads = 0;
// A page under a pixel at 2 dpi (a 5-point page, which Ghostscript draws at
// 150 but not at 2) in a file whose boxes cannot be read.
file_put_contents($pdf, '%PDF-1.4');
$page([5, 5]);
Imagick::$limitLog = [];
[$r, $log] = run(['format' => 'jpeg']);
check('  ...a page too small to draw at 2 dpi is measured at 36, under the same limit, and drawn', [$densities($log), Imagick::$limitLog, $r->isSuccess()], [['setResolution(2)', 'setResolution(36)', 'setResolution(150)'], ['10=6325', '4=6325', '10=' . PHP_INT_MAX, '4=' . PHP_INT_MAX, '10=6325', '4=6325', '10=' . PHP_INT_MAX, '4=' . PHP_INT_MAX], true]);

[$r, $log] = run(['format' => 'jpeg', 'resolution' => 24]);
check('  ...or at the resolution asked, when that is less', [$densities($log), $r->isSuccess()], [['setResolution(2)', 'setResolution(24)', 'setResolution(24)'], true]);

// Held at 2 dpi, the measuring read of a document with a 300,000-point box
// would be 69 megapixels: read under width and height limits, so that the
// sheet itself would be refused before its pixels are held, and put back.
boxes([595, 842], [300000, 300000]);
$page([595, 842]);
Imagick::$limitLog = [];
[$r, $log] = run(['format' => 'jpeg']);
check('a document with a 300,000-point page: the cover measured at 2 dpi under a limit and drawn at 150', [$densities($log), $r->isSuccess()], [['setResolution(2)', 'setResolution(150)'], true]);
check('  ...the limit an ordinary page at the default limit would fit, put back afterwards (unlimited, as the largest int)', [Imagick::$limitLog, Imagick::$resourceLimits], [['10=6325', '4=6325', '10=' . PHP_INT_MAX, '4=' . PHP_INT_MAX], [10 => PHP_INT_MAX, 4 => PHP_INT_MAX]]);
// Review after 1.4.34: a read the 4-megapixel density already bounds was
// left unlimited, and 1.4.34's own measure of a page the ping understated
// ran to 4 GB. Every measuring read is limited.
boxes([595, 842], [14400, 14400]);
Imagick::$resourceLimits = [];
Imagick::$limitLog = [];
run(['format' => 'jpeg']);
check('  ...and so is one the 4-megapixel density bounds', Imagick::$limitLog, ['10=6325', '4=6325', '10=' . PHP_INT_MAX, '4=' . PHP_INT_MAX]);
// A stricter limit the site set stays in force; one not set is lowered and
// put back.
boxes([595, 842], [300000, 300000]);
Imagick::$resourceLimits = [10 => 2000];
Imagick::$limitLog = [];
[$r, $log] = run(['format' => 'jpeg']);
check('  ...a stricter width limit the site set is left alone; the height is lowered and put back', [Imagick::$limitLog, Imagick::$resourceLimits, $r->isSuccess()], [['4=6325', '4=' . PHP_INT_MAX], [10 => 2000, 4 => PHP_INT_MAX], true]);
// A width or height of 0 is the strictest, not none: ImageMagick 7.1.1
// refuses every read under it (measured), and it was raised for the measuring
// read (review after 1.4.37). Memory, map and disk at 0 are none there.
Imagick::$resourceLimits = [10 => 0, 4 => 0];
Imagick::$limitLog = [];
run(['format' => 'jpeg']);
check('  ...a width and height of 0 are left as they are', [Imagick::$limitLog, Imagick::$resourceLimits], [[], [10 => 0, 4 => 0]]);
Imagick::$resourceLimits = [];
// A listener raising the limit (the add-on's crop plan) does not raise the
// measuring read's: that read is only ever an ordinary page's size.
Imagick::$limitLog = [];
run(['format' => 'jpeg'], Imagick::COLORSPACE_CMYK, true, function () {
    add_filter('rapls_pdf_image_creator_max_render_pixels', function () { return 1000000000; });
});
check('  ...under the same limit when a listener raises the drawing limit', array_slice(Imagick::$limitLog, 0, 2), ['10=6325', '4=6325']);
// The 300,000-point sheet itself: refused by the limit at 2 dpi, and not
// drawn again to find that out.
boxes([300000, 300000]);
$page([300000, 300000]);
Imagick::$limitLog = [];
[$r, $log] = run(['format' => 'jpeg']);
check('the 300,000-point page itself: refused by the limit once, and not drawn', [$readsOf($log), $r->getCode(), $r->getError(), Imagick::$resourceLimits[10] ?? null], [1, 'resource_limit', 'This page is too large to draw, even at the lowest resolution.', PHP_INT_MAX]);
boxes([10000000, 10000000]);
$page([10000000, 10000000]);
[$r, $log] = run(['format' => 'jpeg']);
check('a page too large at 2 dpi is refused, not drawn at 1 (Ghostscript fails at 1)', [$readsOf($log), $r->getCode()], [1, 'resource_limit']);
// Refused at more than 2 dpi -- by a stricter width limit the site set --
// it is measured again at 2 before it is called too large.
boxes([14400, 14400]);
$page([14400, 14400]);
Imagick::$resourceLimits = [10 => 1500];
[$r, $log] = run(['format' => 'jpeg']);
Imagick::$resourceLimits = [];
check('  ...a page refused at the measuring density is measured again at 2 dpi, then finer, and drawn within the site\'s limit', [$densities($log), $r->isSuccess()], [['setResolution(10)', 'setResolution(2)', 'setResolution(9.9750623441397)', 'setResolution(7.47)'], true]);
boxes([150000, 150000]);
$page([150000, 150000]);
[$r, $log] = run(['format' => 'jpeg']);
check('  ...one that fits at a little over 2 dpi is drawn at it', $resolutionRead($log), 'setResolution(3.03)');
// A strip whose boxes are known may be as long as its longest box: the boxes
// hold its area down. Under a square limit, it was refused at 2 dpi though
// it draws at 98.
boxes([300000, 72]);
$page([300000, 72]);
Imagick::$limitLog = [];
[$r, $log] = run(['format' => 'jpeg']);
check('a 300,000-point strip an inch wide is measured under a limit as long as it, and drawn at 98 dpi', [array_slice(Imagick::$limitLog, 0, 2), $resolutionRead($log), $r->isSuccess()], [['10=129102', '4=129102'], 'setResolution(97.95)', true]);

// A box judged either way round against the site's width and height
// limits: a page turned by /Rotate is as wide as its box is tall.
boxes([100, 14400]);
$page([14400, 100]);
Imagick::$resourceLimits = [10 => 3000];
[$r, $log] = run(['format' => 'jpeg']);
Imagick::$resourceLimits = [];
check('a tall box turned on its side is measured against the width limit, and drawn within it', [$resolutionRead($log), $r->isSuccess()], ['setResolution(14.95)', true]);
// A strip held at 2 dpi whose boxes keep it within 40 megapixels there is
// measured as long as it is.
boxes([300000, 20000]);
$page([300000, 20000]);
[$r, $log] = run(['format' => 'jpeg']);
check('  ...a strip past 4 megapixels at 2 dpi, within 40: measured whole, and drawn', [$densities($log), $r->isSuccess()], [['setResolution(2)', 'setResolution(5.87)'], true]);
// Measured again at 2 dpi after a refusal, and failing there otherwise: that
// failure, not "too large".
boxes([14400, 14400]);
$page([14400, 14400]);
Imagick::$resourceLimits = [10 => 1500];
Imagick::$failAt = [2 => 'not authorized `PDF\' @ error/constitute.c/IsCoderAuthorized/426'];
[$r, $log] = run(['format' => 'jpeg']);
Imagick::$failAt = [];
Imagick::$resourceLimits = [];
check('  ...measured again at 2 dpi and refused there for another reason: that reason', [$readsOf($log), $r->getCode()], [2, 'pdf_blocked_by_policy']);
// A site limit equal to the measuring read's is the site's, and left alone.
boxes([595, 842], [300000, 300000]);
$page([595, 842]);
Imagick::$resourceLimits = [10 => 6325, 4 => 6325];
Imagick::$limitLog = [];
[$r, $log] = run(['format' => 'jpeg']);
check('  ...a site limit equal to the measuring read\'s is left as it is', [Imagick::$limitLog, $r->isSuccess()], [[], true]);
Imagick::$resourceLimits = [];
// The measuring read lets go of its pixels at once.
boxes([595, 842], [14400, 14400]);
[, $log] = run(['format' => 'jpeg']);
check('  ...and the measuring read is cleared before the page is drawn', array_slice($log, 0, 5), ['setResolution(10)', 'readImage[n]', 'setIteratorIndex(0)', 'clear', 'setResolution(150)']);

// A file whose boxes cannot be read: page n is measured at 2 dpi, under the
// limit, and drawn from that.
file_put_contents($pdf, '%PDF-1.4');
$page([595, 842]);
Imagick::$limitLog = [];
[$r, $log] = run(['format' => 'jpeg']);
check('a page whose boxes cannot be read is measured at 2 dpi, bounded, and drawn as asked', [$densities($log), array_slice(Imagick::$limitLog, 0, 2), $r->isSuccess()], [['setResolution(2)', 'setResolution(150)'], ['10=6325', '4=6325'], true]);
$page([14400, 14400]);
[$r, $log] = run(['format' => 'jpeg']);
// 400 pixels at 2 dpi, measured a pixel over: 14,436 points -- and, lowered
// for that, measured again at the density that keeps it to 4 megapixels:
// 14,407 points, a pixel over at 9.97 dpi (review after 1.4.37).
check('  ...a large one measured so is lowered, measured again finer to lower it no more than it needs', [$resolutionRead($log), count($densities($log))], ['setResolution(31.6)', 3]);
foreach ([
    'not authorized `PDF\' @ error/constitute.c/IsCoderAuthorized/426' => 'pdf_blocked_by_policy',
    'Invalid IHDR data `/tmp/magick-x\' @ error/png.c/MagickPNGErrorHandler/1492' => 'resource_limit',
] as $message => $code) {
    Imagick::$failMessage = $message;
    Imagick::$failReads = 2;
    [$r, $log] = run(['format' => 'jpeg']);
    check("  ...a measuring read refused so is the failure: $code", [$readsOf($log), $r->getCode()], [false !== strpos($message, 'IHDR') ? 1 : 2, $code]);
}
Imagick::$failReads = 0;
Imagick::$failMessage = 'Failed to read the file';

// ImageMagick 6 has no width or height limit: the measuring read is held
// down by the pixel cache's memory and disk limits instead (review after
// 1.4.34: it was not read, and the ping refused an A4 cover beside a
// 300,000-point sheet).
$names = new ReflectionProperty(ImagickEngine::class, 'limitTypeNames');
if (PHP_VERSION_ID < 80100) { $names->setAccessible(true); }
$cacheNames = new ReflectionProperty(ImagickEngine::class, 'cacheTypeNames');
if (PHP_VERSION_ID < 80100) { $cacheNames->setAccessible(true); }
$namesWere = $names->getValue();
$cacheWere = $cacheNames->getValue();
$names->setValue(null, ['RESOURCETYPE_NO_SUCH_WIDTH', 'RESOURCETYPE_HEIGHT']);
boxes([595, 842], [300000, 300000]);
$page([595, 842]);
Imagick::$limitLog = [];
[$r, $log] = run(['format' => 'jpeg']);
// 40 megapixels at ten bytes each (five channels, Q16), put back afterwards.
$im6Cover = [$densities($log), $r->isSuccess(), Imagick::$limitLog];
$page([300000, 300000]);
boxes([300000, 300000]);
[$r, $log] = run(['format' => 'jpeg']);
$im6Sheet = [$readsOf($log), $r->getCode()];
check('  ...without width and height limits (ImageMagick 6), the cover is measured under memory and disk limits and drawn at 150', $im6Cover, [['setResolution(2)', 'setResolution(150)'], true, ['6=400000000', '5=400000000', '2=400000000', '6=' . PHP_INT_MAX, '5=' . PHP_INT_MAX, '2=' . PHP_INT_MAX]]);
check('  ...and the sheet itself refused by them, once', $im6Sheet, [1, 'resource_limit']);
// With no limit at all to set, the file's boxes are the only measure, and a
// file without them is drawn as asked, as before 1.4.34.
$cacheNames->setValue(null, ['RESOURCETYPE_NO_SUCH_MEMORY']);
[$r, $log] = run(['format' => 'jpeg']);
$noLimitSheet = [$readsOf($log), $r->getCode()];
file_put_contents($pdf, '%PDF-1.4');
$page([595, 842]);
[$r, $log] = run(['format' => 'jpeg']);
$names->setValue(null, $namesWere);
$cacheNames->setValue(null, $cacheWere);
check('  ...with no limit to set, a sheet the boxes say is too large is not read; a file without boxes is drawn as asked', [$noLimitSheet, [$densities($log), $r->isSuccess()]], [[0, 'resource_limit'], [['setResolution(150)'], true]]);

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
    . ' $m->invoke((new ReflectionClass(Rapls\\PDFImageCreator\\Engine\\ImagickEngine::class))->newInstanceWithoutConstructor(), "/tmp/none.pdf[0]", 2.0, 6325, 4.0E7); echo "survived"; }');
$dyingOut = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($dyingRead) . ' 2>&1', $dyingOut);
@unlink($dyingRead);
check('  ...and a request that dies inside the read puts them back at shutdown', implode("\n", $dyingOut), "died with {\"10\":6325,\"4\":6325}\nafter: {\"10\":" . PHP_INT_MAX . ",\"4\":" . PHP_INT_MAX . "}");
// Registered for shutdown once a request, however many pages are measured;
// and lowered inside the try, so that a limit refused as it is set is put
// back too (review after 1.4.34).
$onceChild = RAPLS_PIC_TEST_TMP . '/limits-once.php';
file_put_contents($onceChild, '<?php namespace { class ImagickException extends Exception {}'
    . ' class Imagick { const RESOURCETYPE_WIDTH = 10; const RESOURCETYPE_HEIGHT = 4; public static $l = [];'
    . ' public static function getResourceLimit($t) { return self::$l[$t] ?? 1.8446744073709552E+19; }'
    . ' public static function setResourceLimit($t, $v) { if (4 === $t && 6325 === $v) { throw new ImagickException("refused"); } self::$l[$t] = $v; return true; }'
    . ' public function setResolution($x, $y) {} public function readImage($p) { return true; } public function setIteratorIndex($i) { return true; }'
    . ' public function getImageWidth() { return 17; } public function getImageHeight() { return 23; } public function clear() {} } }'
    . ' namespace Rapls\\PDFImageCreator\\Engine { function register_shutdown_function($cb) { $GLOBALS["registered"][] = $cb; } }'
    . ' namespace { require ' . var_export(RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/EngineInterface.php', true) . ';'
    . ' require ' . var_export(RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ImagickEngine.php', true) . ';'
    . ' $m = new ReflectionMethod(Rapls\\PDFImageCreator\\Engine\\ImagickEngine::class, "measurePage"); if (PHP_VERSION_ID < 80100) { $m->setAccessible(true); }'
    . ' $e = (new ReflectionClass(Rapls\\PDFImageCreator\\Engine\\ImagickEngine::class))->newInstanceWithoutConstructor();'
    . ' foreach ([1, 2, 3] as $n) { try { $m->invoke($e, "/tmp/none.pdf[0]", 2.0, 6325, 4.0E7); } catch (ImagickException $x) { echo $x->getMessage(), " "; } }'
    . ' echo json_encode(Imagick::$l), " ", json_encode($GLOBALS["registered"] ?? []); }');
$onceOut = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($onceChild) . ' 2>&1', $onceOut);
@unlink($onceChild);
check('  ...registered for shutdown once, for three pages measured; a limit refused as it is set leaves the others put back', implode("\n", $onceOut), 'refused refused refused {"10":' . PHP_INT_MAX . ',"4":' . PHP_INT_MAX . '} ' . json_encode([['Rapls\\PDFImageCreator\\Engine\\ImagickEngine', 'restoreLimits']]));

// The server's own width and height limits, where stricter: a page is drawn
// within them, a pixel short, rather than drawn and refused (verification of
// 1.4.34). A limit of 0 or "unlimited" is none.
Imagick::$resourceLimits = [10 => 5000, 4 => 5000];
boxes([14400, 14400]);
$page([14400, 14400]);
[$r, $log] = run(['format' => 'jpeg']);
$sideFit = [$resolutionRead($log), $r->isSuccess()];
boxes([595, 842]);
$page([595, 842]);
[, $log] = run(['format' => 'jpeg', 'resolution' => 600, 'max_width' => 4096, 'max_height' => 4096]);
$a4Fit = $resolutionRead($log);
[, $log] = run(['format' => 'jpeg']);
$a4Within = $resolutionRead($log);
Imagick::$resourceLimits = [4 => 4000];
[, $log] = run(['format' => 'jpeg', 'resolution' => 600, 'max_width' => 4096, 'max_height' => 4096]);
$heightOnly = $resolutionRead($log);
Imagick::$resourceLimits = [];
check('a page is drawn within the server\'s width and height limits', [$sideFit, $a4Fit, $a4Within, $heightOnly], [['setResolution(24.98)', true], 'setResolution(427.45)', 'setResolution(150)', 'setResolution(341.94)']);
// ...and within what its pixel cache holds, where its disk is limited:
// measured, an A0 page was refused at 80 dpi with 64 MiB of memory and of
// disk, and drawn at 60 (review after 1.4.34).
boxes([2384, 3370]);
$page([2384, 3370]);
Imagick::$resourceLimits = [6 => 67108864, 2 => 67108864];
[$r, $log] = run(['format' => 'jpeg', 'max_width' => 4096, 'max_height' => 4096]);
$cacheFit = [$resolutionRead($log), $r->isSuccess()];
Imagick::$features = 'DPC HDRI Cipher OpenMP';
[, $log] = run(['format' => 'jpeg', 'max_width' => 4096, 'max_height' => 4096]);
$hdri = $resolutionRead($log);
Imagick::$features = 'DPC Cipher Modules';
Imagick::$quantum = 8;
[, $log] = run(['format' => 'jpeg', 'max_width' => 4096, 'max_height' => 4096]);
$q8 = $resolutionRead($log);
Imagick::$quantum = 16;
check('  ...counting four bytes a value on an HDRI build, one on a Q8 build', [$hdri, $q8], ['setResolution(46.51)', 'setResolution(93.03)']);
Imagick::$resourceLimits = [6 => 67108864, 2 => 16777216];
[, $log] = run(['format' => 'jpeg', 'max_width' => 4096, 'max_height' => 4096]);
$smallDisk = $resolutionRead($log);
Imagick::$resourceLimits = [6 => 67108864];
[, $log] = run(['format' => 'jpeg', 'max_width' => 4096, 'max_height' => 4096]);
Imagick::$resourceLimits = [];
// With less disk than memory, the copy flattening makes goes beside the
// page in memory: half of it.
check('  ...and within the pixel cache where the disk is limited; not where it is not', [$cacheFit, $smallDisk, $resolutionRead($log)], [['setResolution(65.78)', true], 'setResolution(46.51)', 'setResolution(150)']);

$page(null);
Imagick::$pingSize = $pingWas;
boxes([595, 842]);

echo "\n=== Review after 1.4.34: the file's boxes, read from its bytes ===\n";
$boxFile = static function (string $body, string $head = "%PDF-1.4\n"): string {
    $f = RAPLS_PIC_TEST_TMP . '/box-' . md5($head . $body) . '.pdf';
    file_put_contents($f, $head . $body . "\n%%EOF\n");
    return $f;
};
$stream = static function (string $dictionary, string $data): string {
    return '7 0 obj << ' . $dictionary . ' /Length ' . strlen($data) . " >>\nstream\n" . $data . "\nendstream\nendobj\n";
};
$a4 = '<< /Type /Page /MediaBox [0 0 595 842] >>';
check('the largest box, by area and by side, of every kind', ImagickEngine::largestPageBox($boxFile($a4 . "\n<< /CropBox [-10 -10 990 90] /TrimBox [0 0 2000 300] /BleedBox [0 0 10 10] /ArtBox [0 0 .5 1.5] >>")), [600000.0, 2000.0]);
check('  ...at least Letter and A4 together, the size of a page with no box', ImagickEngine::largestPageBox($boxFile('<< /MediaBox [0 0 10 10] >>')), [612.0 * 842.0, 842.0]);
check('  ...no box at all: no answer', ImagickEngine::largestPageBox($boxFile('<< /Type /Page >>')), null);
check('  ...a file not starting with %PDF- (ImageMagick would read it as something else): no answer', ImagickEngine::largestPageBox($boxFile($a4, "%!PS-Adobe-3.0\n%PDF-1.4\n")), null);
check('  .../UserUnit: no answer', ImagickEngine::largestPageBox($boxFile($a4 . "\n<< /MediaBox [0 0 595 842] /UserUnit 100 >>")), null);
check('  ...a box that is not four plain numbers: no answer', [ImagickEngine::largestPageBox($boxFile('<< /MediaBox 5 0 R >>')), ImagickEngine::largestPageBox($boxFile('<< /MediaBox [0 0 5 0 R 842] >>')), ImagickEngine::largestPageBox($boxFile('<< /MediaBox [0 0 1e6 1e6] >>'))], [null, null, null]);
check('  ...a key spelt with #xx escapes: no answer; a name with one that is not a box key, read', [ImagickEngine::largestPageBox($boxFile($a4 . "\n<< /Media#42ox [0 0 300000 300000] >>")), ImagickEngine::largestPageBox($boxFile($a4 . "\n<< /User#55nit 100 >>")), ImagickEngine::largestPageBox($boxFile($a4 . "\n<< /A#42C 1 >>"))], [null, null, [612.0 * 842.0, 842.0]]);
// Object streams, inflated: the page dictionaries of a modern PDF are in them.
$inside = gzcompress('1 0 3 50 << /Type /Page /MediaBox [0 0 100000 100000] >> << /Type /Pages >>');
check('  ...a box inside a compressed object stream', ImagickEngine::largestPageBox($boxFile($a4 . "\n" . $stream('/Type /ObjStm /N 2 /First 9 /Filter /FlateDecode', $inside))), [1.0E10, 100000.0]);
// Ghostscript 10.04 does not read a stream with a /First and no /Type
// /ObjStm as an object stream (review after 1.4.36); this reading opens one
// anyway, which can only make the answer larger.
check('  ...whatever its /Type says: opened by its /First, as the safe side', ImagickEngine::largestPageBox($boxFile($a4 . "\n" . $stream('/Type /XObject /N 2 /First 9 /Filter [/FlateDecode]', $inside))), [1.0E10, 100000.0]);
check('  ...an outline\'s /First is not a stream, nor is the next object\'s', ImagickEngine::largestPageBox($boxFile($a4 . "\n3 0 obj << /Type /Outlines /First 4 0 R >> endobj\n" . $stream('/Filter /LZWDecode', 'q Q'))), [612.0 * 842.0, 842.0]);
check('  ...a box upside down is as large', ImagickEngine::largestPageBox($boxFile('<< /MediaBox [0 50000 100 0] >>')), [5000000.0, 50000.0]);
check('  ...an object stream cut short: no answer', ImagickEngine::largestPageBox($boxFile($a4 . "\n" . $stream('/Type /ObjStm /N 2 /First 9 /Filter /FlateDecode', substr($inside, 0, -6)))), null);
check('  ...one unfiltered is read as it is', ImagickEngine::largestPageBox($boxFile($a4 . "\n" . $stream('/Type /ObjStm /N 1 /First 4', '1 0 << /MediaBox [0 0 3000 3000] >>'))), [9000000.0, 3000.0]);
check('  ...one filtered otherwise, with parameters, encrypted or broken: no answer', [
    ImagickEngine::largestPageBox($boxFile($a4 . "\n" . $stream('/Type /ObjStm /N 2 /First 9 /Filter /LZWDecode', $inside))),
    ImagickEngine::largestPageBox($boxFile($a4 . "\n" . $stream('/Type /ObjStm /N 2 /First 9 /Filter /FlateDecode /DecodeParms << /Predictor 12 >>', $inside))),
    ImagickEngine::largestPageBox($boxFile($a4 . "\n" . $stream('/Type /ObjStm /N 2 /First 9 /Filter /FlateDecode', str_rot13($inside)))),
    ImagickEngine::largestPageBox($boxFile($a4 . "\n" . $stream('/Type /ObjStm /N 2 /First 9 /Fil#74er /FlateDecode', $inside))),
], [null, null, null, null]);
check('  ...a /Length given as a reference: read to its endstream', ImagickEngine::largestPageBox($boxFile($a4 . "\n7 0 obj << /Type /ObjStm /N 2 /First 9 /Filter /FlateDecode /Length 8 0 R >>\nstream\n" . $inside . "\nendstream\nendobj\n")), [1.0E10, 100000.0]);
// ...a reference of more than one digit too: "/Length 12 0 R" was read as a
// length of 1 (review after 1.4.34).
check('  ...and one numbered 12', ImagickEngine::largestPageBox($boxFile($a4 . "\n7 0 obj << /Type /ObjStm /N 2 /First 9 /Filter /FlateDecode /Length 12 0 R >>\nstream\n" . $inside . "\nendstream\nendobj\n")), [1.0E10, 100000.0]);
// Inflated within BOX_MAX_INFLATED in all.
$bomb = gzcompress(str_repeat(' ', 17 * 1048576));
check('  ...an object stream that inflates past 16 MB: no answer', ImagickEngine::largestPageBox($boxFile($a4 . "\n" . $stream('/Type /ObjStm /N 1 /First 2 /Filter /FlateDecode', $bomb))), null);

echo "\n=== Review after 1.4.35: the file read token by token for its object streams ===\n";
// A 14,400-point page in an object stream whose dictionary was searched back
// for "obj" from its /First: a key ending in "obj", a value past 4 KB, or
// "obj" in a string cut the dictionary short, its /Filter went unseen, the
// stream was taken as plain -- and with a small box anywhere else, the page
// was drawn at 150 dpi in 97 s and 3.2 GB.
$hidden = gzcompress('3 0 << /Type /Page /MediaBox [0 0 14400 14400] >>');
$decoy = "% /MediaBox [0 0 612 792]\n";
$objStm = static function (string $dictionary) use ($stream, $hidden): string {
    return $stream($dictionary . ' /Length ' . strlen($hidden), $hidden);
};
$stream14400 = [207360000.0, 14400.0];
check('  ...a key ending in "obj" before /First: the stream is still opened', ImagickEngine::largestPageBox($boxFile($decoy . str_replace(' /Length ' . strlen($hidden) . ' /Length', ' /Length', $objStm('/Type /ObjStm /Filter /FlateDecode /Nobj 1 /N 1 /First 4')))), $stream14400);
check('  ...a value of more than 4 KB before /First: still opened', ImagickEngine::largestPageBox($boxFile($decoy . $objStm('/Type /ObjStm /Filter /FlateDecode /Pad <' . str_repeat('00', 2100) . '> /N 1 /First 4'))), $stream14400);
check('  ..."obj" in a string before /First: still opened', ImagickEngine::largestPageBox($boxFile($decoy . $objStm('/Type /ObjStm /Filter /FlateDecode /Note (1 0 obj) /N 1 /First 4'))), $stream14400);
check('  ...the numbers of a box apart by NUL, which PDF counts as white space', ImagickEngine::largestPageBox($boxFile("<< /MediaBox [0\x000\x003000\x003000] >>")), [9000000.0, 3000.0]);
check('  ...a box that starts left of 0 is as wide as it reaches', ImagickEngine::largestPageBox($boxFile('<< /MediaBox [-300000 0 10 10] >>')), [3000100.0, 300010.0]);
check('  ...a string with an escaped parenthesis is stepped over whole', ImagickEngine::largestPageBox($boxFile('<< /MediaBox [0 0 3000 3000] /T (a\) b (c) d) >>')), [9000000.0, 3000.0]);
// Review of the token reading: a value it did not take as one -- true,
// false, null -- left the dictionary waiting for a key, and every key after
// it was read as a value, /Type and /First among them: the stream was not
// taken for an object stream, and a 100,000-point page in it was drawn at
// 150 dpi.
$keywords = [];
foreach (['true', 'false', 'null'] as $word) {
    $keywords[] = ImagickEngine::largestPageBox($boxFile($decoy . $objStm('/Foo ' . $word . ' /Type /ObjStm /First 4 /N 1 /Filter /FlateDecode')));
}
check('  ...true, false and null are values: the keys after them are still keys', $keywords, [$stream14400, $stream14400, $stream14400]);
check('  ...a brace, an unknown word, a value where a key belongs, a key with no value, a broken reference: no answer', [
    ImagickEngine::largestPageBox($boxFile($decoy . $objStm('/Foo { /Type /ObjStm /First 4 /N 1 /Filter /FlateDecode'))),
    ImagickEngine::largestPageBox($boxFile($decoy . $objStm('/Foo } /Type /ObjStm /First 4 /N 1 /Filter /FlateDecode'))),
    ImagickEngine::largestPageBox($boxFile($decoy . $objStm('/Foo bar /Type /ObjStm /First 4 /N 1 /Filter /FlateDecode'))),
    ImagickEngine::largestPageBox($boxFile($decoy . $objStm('/Foo 1 2 /Type /ObjStm /First 4 /N 1 /Filter /FlateDecode'))),
    ImagickEngine::largestPageBox($boxFile($decoy . $objStm('/Foo (x) (y) /Type /ObjStm /First 4 /N 1 /Filter /FlateDecode'))),
    ImagickEngine::largestPageBox($boxFile($decoy . $objStm('/Foo 1 R /Type /ObjStm /First 4 /N 1 /Filter /FlateDecode'))),
    ImagickEngine::largestPageBox($boxFile($a4 . "\n<< /Foo >>")),
    ImagickEngine::largestPageBox($boxFile($a4 . "\n<< /A [1 bar] >>")),
    ImagickEngine::largestPageBox($boxFile($a4 . "\n<< /Foo 1 2 >>")),
], [null, null, null, null, null, null, null, null, null]);
check('  ...a /First where a value belongs is no object stream\'s, and leaves the count short: no answer', ImagickEngine::largestPageBox($boxFile($decoy . $objStm('/Foo /First /Type /ObjStm /N 1 /Filter /FlateDecode'))), null);
check('  ...every kind of value is read past: numbers, reals, strings, hex strings, names, arrays, dictionaries, references, keywords', ImagickEngine::largestPageBox($boxFile($decoy . $objStm('/A 1 /B -2.5 /C (s) /D <00ff> /E /n /F [1 (x) /y [2] << /z 3 >> 4 0 R true] /G << /H null >> /I 12 0 R /J false /Type /ObjStm /First 4 /N 1 /Filter /FlateDecode'))), $stream14400);
// A /First the token reading did not meet as a key: in a comment, a string or
// a stream's data, where a repaired file could still find an object stream.
check('  .../First in a comment, a string or a stream\'s data: no answer', [
    ImagickEngine::largestPageBox($boxFile($a4 . "\n% /First 4\n")),
    ImagickEngine::largestPageBox($boxFile($a4 . "\n<< /T (see /First) >>")),
    ImagickEngine::largestPageBox($boxFile($a4 . "\n" . $stream('', 'q /First Q'))),
], [null, null, null]);
check('  ...an outline\'s /First, in a dictionary, is read past', ImagickEngine::largestPageBox($boxFile($a4 . "\n3 0 obj << /Type /Outlines /First 4 0 R /Count 1 >> endobj\n")), [612.0 * 842.0, 842.0]);
check('  ...one in an object stream left unfiltered, read on as part of the file', ImagickEngine::largestPageBox($boxFile($a4 . "\n" . $stream('/Type /ObjStm /N 2 /First 8', '1 0 2 40 << /MediaBox [0 0 3000 3000] >> << /Type /Outlines /First 4 0 R >>'))), [9000000.0, 3000.0]);
check('  ...a file the token reading cannot follow: no answer', [
    ImagickEngine::largestPageBox($boxFile($a4 . "\n) stray")),
    ImagickEngine::largestPageBox($boxFile($a4 . "\n<< /T (open")),
    ImagickEngine::largestPageBox($boxFile($a4 . "\n<< /T <zz> >>")),
    ImagickEngine::largestPageBox($boxFile($a4 . "\n<< /A [1 2 >>")),
    ImagickEngine::largestPageBox($boxFile($a4 . "\n<< /A 1")),
    ImagickEngine::largestPageBox($boxFile($a4 . "\nendobj\nstream\nq Q\nendstream")),
    ImagickEngine::largestPageBox($boxFile($a4 . "\n<< /Length 3 >>\nstream\nq Q")),
], [null, null, null, null, null, null, null]);
// Review after 1.4.36: a name of 31 MB was copied whole into the match, its capture and its decoding, and a
// 128 MB limit ended in a fatal error. A name or word past TOKEN_BYTES_MAX ends the reading; a long hex string
// or comment is stepped over in place and still answered.
check('  ...a name or a word longer than the reading takes is no answer; a long hex string or comment is read past', [
    ImagickEngine::largestPageBox($boxFile($a4 . "\n<< /" . str_repeat('A', 32769) . " 1 >>")),
    ImagickEngine::largestPageBox($boxFile($a4 . "\n<< /K " . str_repeat('7', 32769) . " >>")),
    ImagickEngine::largestPageBox($boxFile($a4 . "\n<< /" . str_repeat('A', 32768) . " 1 >>")),
    ImagickEngine::largestPageBox($boxFile($a4 . "\n<< /K " . str_repeat('7', 32768) . " >>")),
    ImagickEngine::largestPageBox($boxFile($a4 . "\n<< /K <" . str_repeat('ab', 40000) . "> >>")),
    ImagickEngine::largestPageBox($boxFile($a4 . "\n%" . str_repeat('c', 80000) . "\n<< /K 1 >>")),
    ImagickEngine::largestPageBox($boxFile($a4 . "\n<< /K <ab" . str_repeat(' ', 9) . "zz> >>")),
], [null, null, [612.0 * 842.0, 842.0], [612.0 * 842.0, 842.0], [612.0 * 842.0, 842.0], [612.0 * 842.0, 842.0], null]);
check('  ...a stream inside an object stream, or an unfiltered one\'s /Filter given by reference: no answer', [
    ImagickEngine::largestPageBox($boxFile($a4 . "\n" . $stream('/Type /ObjStm /N 1 /First 4', "1 0 << /Length 3 >>\nstream\nq Q\nendstream"))),
    ImagickEngine::largestPageBox($boxFile($a4 . "\n" . $stream('/Type /ObjStm /N 1 /First 4 /Filter 9 0 R', '3 0 << /MediaBox [0 0 3000 3000] >>'))),
    ImagickEngine::largestPageBox($boxFile($a4 . "\n" . $stream('/Type /ObjStm /N 1 /Fir#73t 4 /Filter /FlateDecode', $hidden))),
], [null, null, null]);
check('  ...an object stream with no /First, or a /Filter given by reference: no answer', [
    ImagickEngine::largestPageBox($boxFile($a4 . "\n" . $stream('/Type /ObjStm /N 1 /Filter /FlateDecode', $hidden))),
    ImagickEngine::largestPageBox($boxFile($a4 . "\n" . $stream('/Type /ObjStm /N 1 /First 4 /Filter 9 0 R', $hidden))),
], [null, null]);
// The data's end: by a /Length that lands on "endstream", otherwise by the
// first "endstream" after it.
check('  ...a /Length past the end of the file: read to its endstream', ImagickEngine::largestPageBox($boxFile($a4 . "\n7 0 obj << /Type /ObjStm /N 1 /First 4 /Filter /FlateDecode /Length 99999999 >>\nstream\n" . $hidden . "\nendstream\nendobj\n")), $stream14400);
check('  ...a /Length that lands short of endstream: read to the endstream', ImagickEngine::largestPageBox($boxFile($a4 . "\n7 0 obj << /Type /ObjStm /N 1 /First 4 /Filter /FlateDecode /Length 3 >>\nstream\n" . $hidden . "\nendstream\nendobj\n")), $stream14400);
// The file's size and the memory to read it in.
$sparse = RAPLS_PIC_TEST_TMP . '/box-sparse.pdf';
$handle = fopen($sparse, 'wb');
fwrite($handle, "%PDF-1.4\n<< /MediaBox [0 0 595 842] >>\n");
ftruncate($handle, 33554433);
fclose($handle);
$pastSize = ImagickEngine::largestPageBox($sparse);
@unlink($sparse);
$small = $boxFile($a4);
$limitWas = ini_get('memory_limit');
ini_set('memory_limit', (string) (memory_get_usage() + 20 * 1048576));
$tight = ImagickEngine::largestPageBox($small);
ini_set('memory_limit', $limitWas);
check('  ...a file past 32 MB, or without the memory to read it and inflate 16 MB: no answer', [$pastSize, $tight, ImagickEngine::largestPageBox($small)], [null, null, [612.0 * 842.0, 842.0]]);
$many = RAPLS_PIC_TEST_TMP . '/box-tokens.pdf';
file_put_contents($many, "%PDF-1.4\n<< /MediaBox [0 0 595 842] >>\n" . str_repeat('1 ', 1000001));
$tooMany = ImagickEngine::largestPageBox($many);
file_put_contents($many, "%PDF-1.4\n<< /MediaBox [0 0 595 842] >>\n" . str_repeat('1 ', 990000));
$enough = ImagickEngine::largestPageBox($many);
@unlink($many);
check('  ...past two million tokens: no answer; under, read', [$tooMany, $enough], [null, [612.0 * 842.0, 842.0]]);

echo "\n=== Review after 1.4.36: what the token reading holds, and how far it goes ===\n";
// Nesting: 200 KB of "[" crashed PHP when the values were kept whole and
// freed recursively. Now 64 levels deep at most, and nothing kept inside.
$nested = static function (int $depth) use ($boxFile, $a4): ?array {
    return ImagickEngine::largestPageBox($boxFile($a4 . "\n" . str_repeat('[', $depth) . str_repeat(']', $depth)));
};
check('  ...arrays 64 deep are read; 65 deep, or 100,000, no answer -- and no crash', [$nested(64), $nested(65), $nested(100000)], [[612.0 * 842.0, 842.0], null, null]);
check('  ...dictionaries too', [
    ImagickEngine::largestPageBox($boxFile($a4 . "\n" . str_repeat('<< /A ', 64) . '1' . str_repeat(' >>', 64))),
    ImagickEngine::largestPageBox($boxFile($a4 . "\n" . str_repeat('<< /A ', 65) . '1' . str_repeat(' >>', 65))),
], [[612.0 * 842.0, 842.0], null]);
// Two million values in one array held 278 MB, and "/#41" names collected all
// at once 346 MB from a 24 KB file. Read in a child under 96 MB: the answer,
// with no fatal error.
$wideFile = RAPLS_PIC_TEST_TMP . '/box-wide.pdf';
file_put_contents($wideFile, "%PDF-1.4\n<< /MediaBox [0 0 595 842] /Junk [" . str_repeat('12345678 ', 990000) . "] >>\n%%EOF\n");
$namesFile = RAPLS_PIC_TEST_TMP . '/box-names.pdf';
file_put_contents($namesFile, "%PDF-1.4\n<< /MediaBox [0 0 595 842] >>\n" . $stream('/Type /ObjStm /N 1 /First 2 /Filter /FlateDecode', gzcompress('1 0 << /Pad [' . str_repeat('/#41 ', 3300000) . '] >>')));
$heldChild = RAPLS_PIC_TEST_TMP . '/box-held.php';
file_put_contents($heldChild, '<?php function apply_filters($t, $v) { return $v; } function __($s) { return $s; }'
    . ' require ' . var_export(RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/EngineInterface.php', true) . ';'
    . ' require ' . var_export(RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ConversionResult.php', true) . ';'
    . ' require ' . var_export(RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ImagickEngine.php', true) . ';'
    . ' echo json_encode(Rapls\\PDFImageCreator\\Engine\\ImagickEngine::largestPageBox($argv[1])), " ", memory_get_peak_usage() < 64 * 1048576 ? "small" : "large";');
$held = [];
foreach ([$wideFile, $namesFile] as $file) {
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=96M ' . escapeshellarg($heldChild) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    $held[] = [implode("\n", $out), $code];
}
check('  ...two million numbers in an array: read within 64 MB; 3.3 million #xx names: no answer, within 64 MB', $held, [['[515304,842] small', 0], ['null small', 0]]);
// One #xx name at a time, and so many at most.
check('  ...100,000 names with #xx escapes are looked at; one more, no answer', [
    ImagickEngine::largestPageBox($boxFile($a4 . "\n" . $stream('', str_repeat('/#41 ', 100000)))),
    ImagickEngine::largestPageBox($boxFile($a4 . "\n" . $stream('', str_repeat('/#41 ', 100001)))),
], [[612.0 * 842.0, 842.0], null]);
check('  .../Media#42ox inside a compressed object stream: no answer', ImagickEngine::largestPageBox($boxFile($a4 . "\n" . $stream('/Type /ObjStm /N 1 /First 4 /Filter /FlateDecode', gzcompress('3 0 << /Media#42ox [0 0 300000 300000] >>')))), null);
// The bytes inflated are counted across object streams, not one at a time.
$nine = gzcompress('3 0 << /MediaBox [0 0 3000 3000] >>' . str_repeat(' ', 9 * 1048576));
check('  ...two object streams that inflate to 18 MB together: no answer; one of them, read', [
    ImagickEngine::largestPageBox($boxFile($a4 . "\n" . $stream('/Type /ObjStm /N 1 /First 4 /Filter /FlateDecode', $nine) . $stream('/Type /ObjStm /N 1 /First 4 /Filter /FlateDecode', $nine))),
    ImagickEngine::largestPageBox($boxFile($a4 . "\n" . $stream('/Type /ObjStm /N 1 /First 4 /Filter /FlateDecode', $nine))),
], [null, [9000000.0, 3000.0]]);
unset($nine);
// "stream" ends its line with CRLF in many writers.
check('  ...an object stream whose data starts after CRLF', ImagickEngine::largestPageBox($boxFile($decoy . "7 0 obj << /Type /ObjStm /N 1 /First 4 /Filter /FlateDecode /Length " . strlen($hidden) . " >>\nstream\r\n" . $hidden . "\r\nendstream\nendobj\n")), $stream14400);
// Fonts carry /FirstChar: it is not /First.
check('  .../FirstChar is not /First', ImagickEngine::largestPageBox($boxFile($a4 . "\n<< /Type /Font /FirstChar 32 /LastChar 126 >>")), [612.0 * 842.0, 842.0]);
check('  ...a comment between the keys of an object stream\'s dictionary is stepped over', ImagickEngine::largestPageBox($boxFile($decoy . $objStm("/Type /ObjStm % a note\n /N 1 /First 4 /Filter /FlateDecode"))), $stream14400);
check('  ...a dictionary closed by "]", a stray endstream, a name and then "0 R": no answer', [
    ImagickEngine::largestPageBox($boxFile($a4 . "\n<< /A 1 ]")),
    ImagickEngine::largestPageBox($boxFile($a4 . "\nendstream")),
    ImagickEngine::largestPageBox($boxFile($a4 . "\n<< /A /x 0 R >>")),
], [null, null, null]);
// A /Length given as a reference is no length: the data runs to the first
// "endstream", which here is inside it, and what follows cannot be read.
check('  ...a /Length given as a reference is not taken for its number', ImagickEngine::largestPageBox($boxFile($a4 . "\n<< /Length 17 0 R >>\nstream\nAAendstreamBBBBBB\nendstream\n")), null);
// A /Length that is not a whole number is no length either.
check('  ...nor one that is not a whole number', ImagickEngine::largestPageBox($boxFile($a4 . "\n<< /Length 10.0 >>\nstream\nendstream\nendstream\n")), null);
// The first "endstream" ends a stream with no length, not the last.
check('  ...a stream with no length ends at the first endstream, not the file\'s last', ImagickEngine::largestPageBox($boxFile($decoy . "<< >>\nstream\nq Q\nendstream\n" . "7 0 obj << /Type /ObjStm /N 1 /First 4 /Filter /FlateDecode >>\nstream\n" . $hidden . "\nendstream\nendobj\n")), $stream14400);
// The limits' edges: as many boxes as BOX_MAX_COUNT, object streams as
// PAGE_TREE_MAX_NODES, and a file as large as PAGE_TREE_MAX_BYTES are read.
check('  ...100,000 boxes are read; 100,001, no answer', [
    ImagickEngine::largestPageBox($boxFile(str_repeat("<< /MediaBox [0 0 1 1] >>\n", 100000))),
    ImagickEngine::largestPageBox($boxFile(str_repeat("<< /MediaBox [0 0 1 1] >>\n", 100001))),
], [[612.0 * 842.0, 842.0], null]);
$oneSpace = gzcompress(' ');
$streams = static function (int $n) use ($stream, $oneSpace, $boxFile, $a4): ?array {
    return ImagickEngine::largestPageBox($boxFile($a4 . "\n" . str_repeat($stream('/Type /ObjStm /N 0 /First 0 /Filter /FlateDecode', $oneSpace), $n)));
};
check('  ...10,000 object streams are read; 10,001, no answer', [$streams(10000), $streams(10001)], [[612.0 * 842.0, 842.0], null]);
$limitWas = ini_get('memory_limit');
ini_set('memory_limit', '-1');
$atSize = RAPLS_PIC_TEST_TMP . '/box-at-size.pdf';
$handle = fopen($atSize, 'wb');
fwrite($handle, "%PDF-1.4\n<< /MediaBox [0 0 595 842] >>\n");
ftruncate($handle, 33554432);
fclose($handle);
$atSizeBox = ImagickEngine::largestPageBox($atSize);
@unlink($atSize);
ini_set('memory_limit', $limitWas);
check('  ...a file of exactly 32 MB is read', $atSizeBox, [612.0 * 842.0, 842.0]);
$inflate = new ReflectionMethod(ImagickEngine::class, 'inflateWithin');
if (PHP_VERSION_ID < 80100) { $inflate->setAccessible(true); }
$fiveK = gzcompress(str_repeat('a', 5000));
check('  ...inflated to exactly the most allowed: kept; a byte more, no answer', [strlen((string) $inflate->invoke(null, $fiveK, 5000)), $inflate->invoke(null, $fiveK, 4999)], [5000, null]);

echo "\n=== Review after 1.4.37: bounds where the boxes cannot give the largest ===\n";
// No upper bound, but a lower one: every box past the measuring read's bound
// at 2 dpi, with /UserUnit somewhere. Not read at all to find out it is too
// large: the limits refuse it only after Ghostscript has drawn it.
$pdfWas = $pdf;
$pdf = $boxFile("<< /Type /Page /MediaBox [0 0 10000000 10000000] /UserUnit 1 >>");
$page([10000000, 10000000]);
[$r, $log] = run(['format' => 'jpeg']);
check('every page past the measuring bound at 2 dpi, the boxes give no largest: refused, nothing read', [$readsOf($log), $r->getCode()], [0, 'resource_limit']);
// A smaller box anywhere -- a cover, a stale object, a comment -- is the
// lower bound, and the page is measured, as before.
$pdf = $boxFile("<< /Type /Page /MediaBox [0 0 595 842] >>\n<< /Type /Page /MediaBox [0 0 10000000 10000000] /UserUnit 1 >>");
$page([595, 842]);
[$r, $log] = run(['format' => 'jpeg']);
check('  ...a smaller box anywhere in the file: measured, and drawn', [$densities($log), $r->isSuccess()], [['setResolution(2)', 'setResolution(150)'], true]);
// A /UserUnit under 1 makes the bound smaller; one given by reference leaves
// it unknown.
$pdf = $boxFile("<< /Type /Page /MediaBox [0 0 10000000 10000000] /UserUnit 0.0001 >>");
$page([1000, 1000]);
[$r, $log] = run(['format' => 'jpeg']);
$underOne = [$densities($log)[0] ?? null, $r->isSuccess()];
// The factor scales both sides, so the area by its square: at 0.001 a
// 10,000,000-point box is a 10,000-point page (review of 1.4.37's fixes).
$pdf = $boxFile("<< /Type /Page /MediaBox [0 0 10000000 10000000] /UserUnit 0.001 >>");
$page([10000, 10000]);
[$r, $log] = run(['format' => 'jpeg']);
$atThousandth = [$densities($log)[0] ?? null, $r->isSuccess()];
$pdf = $boxFile("<< /Type /Page /MediaBox [0 0 10000000 10000000] /UserUnit 5 0 R >>");
$page([1000, 1000]);
[$r, $log] = run(['format' => 'jpeg']);
check('  ...a /UserUnit under 1, or one by reference: measured', [$underOne, $atThousandth, [$densities($log)[0] ?? null, $r->isSuccess()]], [['setResolution(2)', true], ['setResolution(2)', true], ['setResolution(2)', true]]);
// A file the token reading cannot follow says nothing of a lower bound
// either: one box unread could be the small one.
$pdf = $boxFile("<< /Type /Page /MediaBox [0 0 10000000 10000000] /X { >>");
[$r, $log] = run(['format' => 'jpeg']);
check('  ...a file the reading cannot follow: measured, not refused on its boxes', [$densities($log)[0] ?? null, $r->isSuccess()], ['setResolution(2)', true]);
// A page that is a pixel high at 36 dpi and none at 2, in a file whose boxes
// cannot be read: measured again at 36, and drawn.
$pdf = $boxFile("<< /Type /Page /UserUnit 1 >>");
$page([612, 12]);
[$r, $log] = run(['format' => 'jpeg']);
check('  ...a page under a pixel high at 2 dpi, with no box to read: measured at 36, drawn', [array_slice($densities($log), 0, 2), $r->isSuccess()], [['setResolution(2)', 'setResolution(36)'], true]);
$page(null);

// Review after 1.4.37: a file that does not start like a PDF is bounded too,
// unless it starts like a raster -- PostScript or EPS is drawn by Ghostscript
// just the same.
$pdf = RAPLS_PIC_TEST_TMP . '/drawing.eps';
file_put_contents($pdf, "%!PS-Adobe-3.0 EPSF-3.0\n%%BoundingBox: 0 0 14400 14400\n");
$page([14400, 14400]);
[$r, $log] = run(['format' => 'jpeg']);
$eps = [$densities($log)[0] ?? null, count($densities($log)), $resolutionRead($log), $r->isSuccess()];
$rasters = [];
foreach (["\x89PNG\r\n\x1a\nraster", "P7\nWIDTH 1\n", "P6\n1 1\n", "\xFF\xD8\xFF\xE0", "GIF89a", "II*\0", "MM\0*", "BM....", "RIFF\0\0\0\0WEBPVP8 "] as $magic) {
    $pdf = RAPLS_PIC_TEST_TMP . '/raster.img';
    file_put_contents($pdf, $magic);
    [$r, $log] = run(['format' => 'jpeg']);
    $rasters[] = count($densities($log));
}
@unlink(RAPLS_PIC_TEST_TMP . '/raster.img');
$page(null);
check('  ...an EPS file is measured and drawn within the limit; a raster of any kind is read once, as it is', [$eps, $rasters], [['setResolution(2)', 3, 'setResolution(31.6)', true], [1, 1, 1, 1, 1, 1, 1, 1, 1]]);

// Review after 1.4.38: only what Ghostscript draws is bounded. A list of the
// rasters let past turned away the ones it did not name -- BigTIFF, HEIC,
// AVIF, JPEG 2000 -- once a side was past the measuring read's bound.
$others = [];
// Review of 1.4.38's fixes: a raster whose text chunk or compressed data
// holds "%!" or "%PDF-" is still a raster, read as it is -- bounded as a
// page, one past 6,325 pixels a side was refused for good.
$pngWithText = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 20000, 20000) . "\x08\x02\0\0\0" . 'crc0'
    . pack('N', 30) . 'tEXt' . "Comment\0%!PS-Adobe %PDF-1.4 x" . 'crc1';
foreach (["II+\0\x08\0\0\0", "\0\0\0\x18ftypheic", "\0\0\0\x1CftypAVIF", "\0\0\0\x0CjP  \r\n\x87\n", "\xFF\x0A", "8BPS", "\x05\0\x02\0\0\0\0\0\0\0\0\0\x10\0\x10\0\x18\0", "\x09junk" . str_repeat("\xAA", 40) . "\0\0\0\0\0\0\0\0TRUEVISION-XFILE.\0", "\0\0\x01\0\x01\0",
    // Review of 1.4.38's fixes: the other rasters ImageMagick reads by
    // itself, each by its own magic -- a 7,000-pixel side was refused.
    "\x0A\x05\x01\x08\0\0\0\0\x57\x1B\xE7\x03", "\x76\x2F\x31\x01\x02\0\0\0", "id=ImageMagick  version=1.0\nclass=DirectClass colors=0 columns=7000 rows=1000\n", "SDPX\0\0\x20\0", "XPDS\0\x20\0\0",
    "DDS \x7C\0\0\0\x07\x10\0\0", "SIMPLE  =                    T / conforms\n", "#?RADIANCE\nFORMAT=32-bit_rle_rgbe\n", "\x01\xDA\x01\x01\0\x03\x1B\x58", "/* XPM */\nstatic char *x[] = {\n", "#define big_width 7000\n#define big_height 1000\n", "gimp xcf v011\0", "qoif\0\0\x1B\x58\0\0\x03\xE8", str_repeat("\0", 128) . "DICM\x02\0",
    $pngWithText, "\xFF\xD8\xFF\xE0..%!..", "GIF89a %PDF-1.4", "8BPS\0\x01 text %!PS inside"] as $magic) {
    $pdf = RAPLS_PIC_TEST_TMP . '/other.img';
    file_put_contents($pdf, $magic);
    [$r, $log] = run(['format' => 'jpeg']);
    $others[] = count($densities($log));
}
$ghostscript = [];
// By its bytes, under a name that says nothing: PostScript after a Ctrl-D,
// after white space, after a printer's job header (review of 1.4.38's
// fixes); a DOS EPS; a late PDF header.
// Review of 1.4.38's fixes, the third round: a byte order mark, a comment
// line before "%!", "%!PSftyp" (not ISO media), a PDF header past the first
// 1024 bytes, and anything else that is not a known raster -- measured.
foreach (["\x04%!PS-Adobe-3.0\n", "%!PS\n", "\n  %!PS-Adobe-3.0\n", "\x1B%-12345X@PJL JOB\r\n@PJL ENTER LANGUAGE=POSTSCRIPT\r\n%!PS\n", "\xC5\xD0\xD3\xC6 binary EPS", str_repeat(' ', 500) . "%PDF-1.4\n", "\xEF\xBB\xBF%!PS\n", "% a comment\n%!PS\n", "%!PSftyp", str_repeat("\x01", 2000) . "%PDF-1.4\n", "\x7FELF junk"] as $magic) {
    $pdf = RAPLS_PIC_TEST_TMP . '/drawn.img';
    file_put_contents($pdf, $magic);
    [$r, $log] = run(['format' => 'jpeg']);
    $ghostscript[] = $densities($log)[0] ?? null;
}
// By its name: a PDF whose header comes after the first 1024 bytes, and
// PostScript with no header, which ImageMagick still hands Ghostscript for
// the name (review of 1.4.38's fixes).
foreach (['late.pdf' => str_repeat(' ', 2000) . "%PDF-1.4\n", 'bare.ps' => "0 0 moveto showpage\n", 'art.AI' => "binary"] as $name => $bytes) {
    $pdf = RAPLS_PIC_TEST_TMP . '/' . $name;
    file_put_contents($pdf, $bytes);
    [$r, $log] = run(['format' => 'jpeg']);
    $ghostscript[] = $densities($log)[0] ?? null;
    @unlink($pdf);
}
@unlink(RAPLS_PIC_TEST_TMP . '/other.img');
@unlink(RAPLS_PIC_TEST_TMP . '/drawn.img');
$page(null);
check('  ...any other file is read once, as it is, a raster holding "%!" or "%PDF-" too; what Ghostscript draws, by its bytes or by its name, is measured', [$others, $ghostscript], [array_fill(0, 27, 1), array_fill(0, 14, 'setResolution(2)')]);

// Review after 1.4.38: a number past what a float holds is INF, INF - INF is
// NaN, and on PHP 8.2 and earlier max() and min() answer a NaN by the order
// of their arguments -- a 400-digit box after a 14,400-point one made the
// largest Letter and A4. A size that is not one is no answer, whatever the
// order, and so is a /UserUnit past a float.
$huge = str_repeat('9', 400);
$nanBoxes = [
    ImagickEngine::largestPageBox($boxFile("<< /MediaBox [0 0 14400 14400] >>\n<< /MediaBox [0 0 $huge $huge] >>")),
    ImagickEngine::largestPageBox($boxFile("<< /MediaBox [0 0 $huge $huge] >>\n<< /MediaBox [0 0 14400 14400] >>")),
    ImagickEngine::largestPageBox($boxFile("<< /MediaBox [0 0 14400 14400] >>\n<< /MediaBox [$huge 0 $huge 10] >>")),
    ImagickEngine::largestPageBox($boxFile("<< /MediaBox [0 0 14400 14400] >>\n<< /MediaBox [0 0 $huge 0] >>")),
];
$scan = new ReflectionMethod(ImagickEngine::class, 'scanPageBoxes');
if (PHP_VERSION_ID < 80100) { $scan->setAccessible(true); }
$nanUnits = $scan->invoke(null, $boxFile("<< /MediaBox [0 0 14400 14400] /UserUnit 1 >>\n<< /UserUnit $huge >>"));
check('  ...a box or a /UserUnit past what a float holds: no answer, in either order', [$nanBoxes, $nanUnits], [[null, null, null, null], null]);
$pdf = $boxFile("<< /Type /Page /MediaBox [0 0 14400 14400] >>\n<< /MediaBox [0 0 $huge $huge] >>");
$page([14400, 14400]);
[$r, $log] = run(['format' => 'jpeg']);
check('  ...and such a file is measured, not drawn at 150 on its boxes', [$densities($log)[0] ?? null, $r->isSuccess()], ['setResolution(2)', true]);
$page(null);

// A side a float holds but an int does not -- a box 10^300 points long and
// 10^-300 wide, a small area -- gives the measuring read a side bounded below
// PHP_INT_MAX: cast as it was, PHP 8.5 warned (review after 1.4.38).
$tiny = '0.' . str_repeat('0', 299) . '1';
$long = '1' . str_repeat('0', 300);
$pdf = $boxFile("<< /Type /Page /MediaBox [0 0 $tiny $long] >>");
$page([10, 10]);
$castWarnings = [];
set_error_handler(static function ($no, $message) use (&$castWarnings) { $castWarnings[] = $message; return true; });
[$r, $log] = run(['format' => 'jpeg']);
restore_error_handler();
check('  ...a side past what an int holds: measured, and nothing warned', [$readsOf($log) > 0, $castWarnings], [true, []]);
$page(null);

// Measured at 2 dpi because a far larger page is in the file, an A4 page
// is measured once more, finer, and drawn at 600 as asked: it was left at
// the coarse figure (review after 1.4.38).
$pdf = $boxFile("<< /Type /Page /MediaBox [0 0 595 842] >>\n<< /Type /Page /MediaBox [0 0 10000000 10000000] >>");
$page([595, 842]);
[$r, $log] = run(['format' => 'jpeg', 'resolution' => 600, 'max_width' => 4096, 'max_height' => 4096]);
check('  ...an A4 page measured at 2 dpi for a larger page elsewhere: measured finer, drawn at 600', [$densities($log)[0] ?? null, $resolutionRead($log)], ['setResolution(2)', 'setResolution(600)']);
// Not measured finer where that would be under twice the least: a
// 100,000-point page with no box, measured once at 2 dpi and drawn.
$pdf = $boxFile("<< /Type /Page /MediaBox [0 0 595 842] >>\n<< /Type /Page /UserUnit 1 >>");
$page([100000, 100000]);
[$r, $log] = run(['format' => 'jpeg']);
check('  ...a page that would be measured finer at under 4 dpi is not measured again', [$readsOf($log), $r->isSuccess()], [2, true]);
// Measured finer, a long page is given a side as long as it is there.
$page([595, 100000]);
[$r, $log] = run(['format' => 'jpeg']);
check('  ...a long page measured finer is given the side it needs', [$readsOf($log), $resolutionRead($log)], [3, 'setResolution(58.86)']);
$page(null);
// /UserUnit of 0 or less, or past BOX_MAX_COUNT of them: no answer.
check('  .../UserUnit 0, -1, and 100,001 of them: no answer', [
    $scan->invoke(null, $boxFile($a4 . "\n<< /UserUnit 0 >>")),
    $scan->invoke(null, $boxFile($a4 . "\n<< /UserUnit -1 >>")),
    $scan->invoke(null, $boxFile($a4 . "\n" . str_repeat("<< /UserUnit 1 >>\n", 100001))),
], [null, null, null]);
// Inflated a kilobyte at a time, the last piece read whatever its length,
// and no byte past the stream's own.
$incompressible = '';
for ($n = 0; strlen(gzcompress($incompressible, 0)) % 1024 !== 1; $n++) {
    $incompressible .= chr($n * 7 % 256);
}
$deflated = gzcompress($incompressible, 0);
$inflateAt = new ReflectionMethod(ImagickEngine::class, 'inflateWithin');
if (PHP_VERSION_ID < 80100) { $inflateAt->setAccessible(true); }
check('  ...a stream a byte past a whole kilobyte is inflated to its end; one inside a file, without the bytes after it', [
    $inflateAt->invoke(null, $deflated, 1 << 20) === $incompressible,
    $inflateAt->invoke(null, 'head' . $deflated . "\xFF\xFF\xFF\xFF", 1 << 20, 4, strlen($deflated)) === $incompressible,
], [true, true]);

// A name right after an escaped one is read too: searched on from a byte
// later, "/Media#42ox" after "/A#41" was stepped over (review of 1.4.36's
// fixes), and the box behind it went unseen.
check('  .../A#41/Media#42ox: the second name is seen, no answer', ImagickEngine::largestPageBox($boxFile($a4 . "\n<< /A#41/Media#42ox [0 0 14400 14400] >>")), null);

// The lower bound is asked before an Imagick with no limit to set lets the
// page through as asked (review after 1.4.38).
$names->setValue(null, ['RESOURCETYPE_NO_SUCH_WIDTH', 'RESOURCETYPE_HEIGHT']);
$cacheNames->setValue(null, ['RESOURCETYPE_NO_SUCH_MEMORY']);
$pdf = $boxFile("<< /Type /Page /MediaBox [0 0 10000000 10000000] /UserUnit 1 >>");
$page([10000000, 10000000]);
[$r, $log] = run(['format' => 'jpeg']);
$names->setValue(null, $namesWere);
$cacheNames->setValue(null, $cacheWere);
check('  ...with no limit to set, a file every page of which is past the bound is refused, not drawn as asked', [$readsOf($log), $r->getCode()], [0, 'resource_limit']);
$page(null);

// The lower bound's edge: refused just past the measuring bound at 2 dpi,
// measured at it -- the area times (2/72)^2 against 40,000,000 pixels.
$edge = sqrt(40000000) * 36;
$leastAt = static function (float $side) use ($boxFile, &$pdf, $page, $readsOf): int {
    $pdf = $boxFile('<< /Type /Page /MediaBox [0 0 ' . sprintf('%.4F', $side) . ' ' . sprintf('%.4F', $side) . '] /UserUnit 1 >>');
    $page([1000, 1000]);
    [, $log] = run(['format' => 'jpeg']);
    return $readsOf($log);
};
check('  ...a page whose lower bound is at the measuring bound is measured; a point past it, not read', [$leastAt($edge - 1) > 0, $leastAt($edge + 1)], [true, 0]);
$page(null);
$pdf = $pdfWas;

// Review after 1.4.37: the measuring reads and the blank-page retry put back
// both the limits and GS_OPTIONS, after two of each.
file_put_contents($pdf, '%PDF-1.4');
$page([14400, 14400]);
Imagick::$gsRescues = true;
Imagick::$resourceLimits = [];
putenv('GS_OPTIONS');
[$r1, $log1] = run(['format' => 'jpeg']);
[$r2, $log2] = run(['format' => 'jpeg']);
Imagick::$gsRescues = false;
check('  ...two measured, rescued conversions: both drawn, the limits and GS_OPTIONS as they were', [$r1->isSuccess(), $r2->isSuccess(), substr_count(implode(' ', $log1), 'readImage'), Imagick::$resourceLimits, getenv('GS_OPTIONS')], [true, true, 4, [10 => PHP_INT_MAX, 4 => PHP_INT_MAX], false]);
$page(null);
boxes([595, 842]);

// A limit that cannot be put back is said once in the error log: it stays
// with this PHP worker for every request after it.
$before = new ReflectionProperty(ImagickEngine::class, 'limitsBefore');
if (PHP_VERSION_ID < 80100) {
    $before->setAccessible(true);
}
$unrestoredLog = tempnam(sys_get_temp_dir(), 'limits');
$unrestoredWas = ini_set('error_log', $unrestoredLog);
foreach ([1, 2] as $time) {
    $before->setValue(null, [10 => 1.0E20]);
    ImagickEngine::restoreLimits();
}
$unrestored = (string) file_get_contents($unrestoredLog);
ini_set('error_log', (string) $unrestoredWas);
@unlink($unrestoredLog);
Imagick::$resourceLimits = [];
check('  ...a limit that cannot be put back is logged, once', substr_count($unrestored, 'could not be put back'), 1);

$fit = new ReflectionMethod(ImagickEngine::class, 'fittedResolution');
if (PHP_VERSION_ID < 80100) {
    $fit->setAccessible(true);
}
$fitEngine = new ImagickEngine();
check('  ...a size that is not a finite number is not one to draw as asked', [$fit->invoke($fitEngine, NAN, 842.0, 150.0, 4.0E7), $fit->invoke($fitEngine, INF, 842.0, 150.0, 4.0E7), $fit->invoke($fitEngine, 595.0, 842.0, 150.0, 4.0E7)], [null, null, 150.0]);

// Review after 1.4.37: what the box reading's tests did not reach.
check('  ...an escaped box key first in an object stream\'s data, or straight after another escaped name: no answer', [
    ImagickEngine::largestPageBox($boxFile($decoy . $stream('/Type /ObjStm /N 1 /First 4 /Filter /FlateDecode', gzcompress('/Media#42ox [0 0 300000 300000]')))),
    ImagickEngine::largestPageBox($boxFile($decoy . $stream('/Type /ObjStm /N 1 /First 4 /Filter /FlateDecode', gzcompress('3 0 << /A#41/Media#42ox [0 0 300000 300000] >>')))),
], [null, null]);
check('  ...an object stream left unfiltered, with no /Length: read to its endstream', ImagickEngine::largestPageBox($boxFile($a4 . "\n7 0 obj << /Type /ObjStm /N 1 /First 4 >>\nstream\n1 0 << /MediaBox [0 0 3000 3000] >>\nendstream\nendobj\n")), [9000000.0, 3000.0]);
// Exactly TOKENS_MAX tokens are read; one more, no answer.
$tokensOf = static function (string $bytes): int {
    $m = new ReflectionMethod(ImagickEngine::class, 'readTokens');
    if (PHP_VERSION_ID < 80100) {
        $m->setAccessible(true);
    }
    $state = ['firsts' => 0, 'tokens' => 0, 'streams' => []];
    $m->invokeArgs(null, [$bytes, false, &$state]);
    return $state['tokens'];
};
$head = "%PDF-1.4\n<< /MediaBox [0 0 595 842] >>\n";
$room = 2000000 - $tokensOf($head);
$exact = $head . str_repeat('1 ', intdiv($room, 2)) . ($room % 2 ? '1' : '');
$many = RAPLS_PIC_TEST_TMP . '/box-tokens.pdf';
file_put_contents($many, $exact);
$atMost = [$tokensOf($exact), ImagickEngine::largestPageBox($many)];
file_put_contents($many, $exact . ($room % 2 ? ' ' : '1'));
$onePast = ImagickEngine::largestPageBox($many);
@unlink($many);
check('  ...two million tokens exactly are read; one more, no answer', [$atMost, $onePast], [[2000000, [612.0 * 842.0, 842.0]], null]);

echo "\n=== Review after 1.4.35: the limits' edges ===\n";
// A filter answering 1 pixel is a limit of 1 pixel; one answering less, or
// nothing usable, is the default.
[$r] = run(['format' => 'jpeg'], Imagick::COLORSPACE_CMYK, true, function () {
    add_filter('rapls_pdf_image_creator_max_render_pixels', function () { return 1; });
});
$onePixel = $r->getCode();
[$r, $log] = run(['format' => 'jpeg'], Imagick::COLORSPACE_CMYK, true, function () {
    add_filter('rapls_pdf_image_creator_max_render_pixels', function () { return 0.5; });
});
check('a pixel limit of 1 is kept; one under 1 is the default', [$onePixel, $resolutionRead($log)], ['resource_limit', 'setResolution(150)']);
// A width limit of 1 is no limit a page could be drawn within: left out, as
// 0 (none) is.
Imagick::$resourceLimits = [10 => 1];
[$r, $log] = run(['format' => 'jpeg']);
Imagick::$resourceLimits = [];
check('  ...a side limit under 2 is not taken for the server\'s', [$r->isSuccess(), $resolutionRead($log)], [true, 'setResolution(150)']);
// A build that will not say its limits: none known, and the page drawn.
Imagick::$limitThrows = true;
[$r, $log] = run(['format' => 'jpeg']);
Imagick::$limitThrows = false;
check('  ...a build that throws for its limits draws the page as asked', [$r->isSuccess(), $resolutionRead($log)], [true, 'setResolution(150)']);
// A quantum depth the build does not give counts as Q16's.
boxes([2384, 3370]);
$page([2384, 3370]);
Imagick::$resourceLimits = [6 => 67108864, 2 => 67108864];
[, $log] = run(['format' => 'jpeg', 'max_width' => 4096, 'max_height' => 4096]);
$q16 = $resolutionRead($log);
Imagick::$quantum = 4;
[, $log] = run(['format' => 'jpeg', 'max_width' => 4096, 'max_height' => 4096]);
Imagick::$quantum = 16;
Imagick::$resourceLimits = [];
$page(null);
boxes([595, 842]);
check('  ...a quantum depth under 8 is counted as 16', $resolutionRead($log), $q16);
// An Error after the page was read lets the page go, as an exception does.
[$r, $log] = run(['format' => 'jpeg'], Imagick::COLORSPACE_CMYK, true, function () {
    add_filter('rapls_pdf_image_creator_before_resize', function () { throw new \Error('a listener broke'); });
});
check('  ...an Error after the read: the instance is cleared', [$r->isSuccess(), end($log)], [false, 'clear']);
// An ImageMagick 6 build with no width, height or map limit: the read is held
// down by memory and disk, and the limit it lacks is passed over.
$im6 = RAPLS_PIC_TEST_TMP . '/im6-limits.php';
file_put_contents($im6, '<?php
class Imagick {
    const RESOURCETYPE_MEMORY = 6;
    const RESOURCETYPE_DISK = 2;
    public static $set = [];
    public static function getResourceLimit($t) { return 0; }
    public static function setResourceLimit($t, $v) { self::$set[] = "$t"; return true; }
    public static function getQuantumDepth() { return ["quantumDepthLong" => 16]; }
    public static function getConfigureOptions($p = "*") { return []; }
}
spl_autoload_register(function ($c) {
    $p = "Rapls\\\\PDFImageCreator\\\\";
    if (0 === strpos($c, $p)) { require ' . var_export(RAPLS_PIC_PLUGIN_DIR, true) . ' . "includes/" . str_replace("\\\\", "/", substr($c, strlen($p))) . ".php"; }
});
$m = new ReflectionMethod("Rapls\\\\PDFImageCreator\\\\Engine\\\\ImagickEngine", "lowerLimits");
if (PHP_VERSION_ID < 80100) {
    $m->setAccessible(true);
}
$m->invoke(null, 1000, 1000000.0);
echo implode(",", Imagick::$set);
');
$im6Out = [];
exec(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($im6) . ' 2>&1', $im6Out);
@unlink($im6);
check('  ...ImageMagick 6 with no map limit: memory and disk lowered, nothing thrown', implode("\n", $im6Out), '6,2');
// Review after 1.4.34: measured on Ghostscript 10.04, ImageMagick 7.1.1.
// A 100,000-point page inside an object stream was pinged as Letter and
// drawn at 150 dpi for over two minutes; it is now measured.
boxes([595, 842]);
$objstm = $boxFile("1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n" . $stream('/Type /ObjStm /N 1 /First 4 /Filter /FlateDecode', gzcompress('5 0 << /Type /Page /MediaBox [0 0 100000 100000] >>')));
$pdfWas = $pdf;
$pdf = $objstm;
$page([100000, 100000]);
[$r, $log] = run(['format' => 'jpeg']);
$pdf = $pdfWas;
$page(null);
check('  ...a 100,000-point page in an object stream is measured, and drawn within the limit', [$densities($log), $r->isSuccess()], [['setResolution(2)', 'setResolution(4.55)'], true]);

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
$pdf = $treeFile("1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 /MediaBox [0 0 595 842] >> endobj");
[$r, $log] = run(['format' => 'jpeg', 'page' => 2]);
$confirmed = [$r->getCode(), in_array('pingImage', $log, true)];
Imagick::$countSays = 9;
[$r, $log] = run(['format' => 'jpeg', 'page' => 2]);
$understated = $r->getCode();
Imagick::$countSays = null;
$pdf = $treeFile("2 0 obj << /Type /Pages /Kids [3 0 R] /Count 5 /MediaBox [0 0 595 842] >> endobj");
[$r, $log] = run(['format' => 'jpeg', 'page' => 1]);
$within = [$r->getCode(), in_array('pingImage', $log, true)];
$pdf = $pdfWas;
// Review after 1.4.34: the tree's figure is the largest /Count in the file,
// which a file can overstate and an update deleting pages leaves behind --
// a page that is not there came back as a render error. ImageMagick counts
// first now, on a failure only.
check('  ...counted by ImageMagick first, whatever the page tree says', [$confirmed, $understated, $within], [['page_out_of_range', true], 'render_error', ['page_out_of_range', true]]);
// Review after 1.4.37: not where a page in the file is too large to draw at
// 2 dpi within the default limit, or the boxes cannot say -- the count draws
// every page at 2 dpi, under no limit of ours. The file's own figure then.
$pdf = $treeFile("2 0 obj << /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 /MediaBox [0 0 300000 300000] >> endobj");
Imagick::$countSays = 9;
[$r, $log] = run(['format' => 'jpeg', 'page' => 2]);
$hugeTree = [$r->getCode(), in_array('pingImage', $log, true)];
$pdf = $treeFile("2 0 obj << /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >> endobj");
[$r, $log] = run(['format' => 'jpeg', 'page' => 2]);
$noBoxTree = [$r->getCode(), in_array('pingImage', $log, true)];
Imagick::$countSays = null;
$pdf = $pdfWas;
check('  ...not counted by ImageMagick where a box is too large to draw small, or there is no box: the page tree\'s figure', [$hugeTree, $noBoxTree], [['page_out_of_range', false], ['page_out_of_range', false]]);
// Review after 1.4.38: the count draws every page, so the bound is the
// pages' together, and this site's limit where it set a lower one. Review of
// 1.4.38's fixes: not the pages the file states -- a /Count of 1 holding
// thousands was counted on its word -- but PAGES_ASSUMED (10,000) of the
// largest. The box answer is at least Letter-by-A4, about 398 pixels at
// 2 dpi: 10,000 of them are 4.0 million, whatever /Count says; a
// 2,500-point page is about 4,800, and 10,000 of those are past 40 million.
$counted = static function (int $count, ?int $limit = null, string $box = '595 842') use ($treeFile, &$pdf): bool {
    $pdf = $treeFile("2 0 obj << /Type /Pages /Kids [3 0 R 4 0 R] /Count $count /MediaBox [0 0 $box] >> endobj");
    Imagick::$countSays = 2;
    [, $log] = run(['format' => 'jpeg', 'page' => 2], Imagick::COLORSPACE_CMYK, true, null === $limit ? null : function () use ($limit) {
        add_filter('rapls_pdf_image_creator_max_render_pixels', function () use ($limit) { return $limit; });
    });
    Imagick::$countSays = null;
    return in_array('pingImage', $log, true);
};
check('  ...counted by ImageMagick only where 10,000 of the largest page fit together, at this site\'s limit, whatever /Count says', [$counted(1), $counted(120000), $counted(1, null, '2500 2500'), $counted(1, 4000000), $counted(1, 3900000)], [true, true, false, true, false]);
$pdf = $pdfWas;
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
Imagick::$failReads = 2;
Imagick::$failMessage = 'Failed to read the file';
[$r] = run(['format' => 'jpeg', 'page' => 1]);
$pdf = $pdfWas;
Imagick::$failReads = 0;
check('  ...a page ImageMagick calls too large is resource_limit, not missing; the same page failing otherwise is missing', [$overLimit, $r->getCode()], ['resource_limit', 'page_out_of_range']);
Imagick::$pageCount = $pagesWas;


echo "\n=== Mutation testing after 1.4.34 ===\n";
$priv = static function (string $name, ...$args) {
    $m = new ReflectionMethod(ImagickEngine::class, $name);
    if (PHP_VERSION_ID < 80100) { $m->setAccessible(true); }
    return $m->isStatic() ? $m->invoke(null, ...$args) : $m->invoke(new ImagickEngine(), ...$args);
};
// A page with something in its alpha channel is not empty, whatever its colour.
Imagick::$alphaRead = true;
$varied = new Imagick();
$varied->flat = false;
$flat = new Imagick();
$flat->flat = true;
check('alpha that varies is a page; alpha that does not, with one colour, is not', [$priv('looksEmpty', $varied), $priv('looksEmpty', $flat)], [false, true]);
Imagick::$alphaRead = false;
// White is all three channels, and opaque.
$white = static function (array $rgb, ?float $alpha = null, int $w = 10) use ($priv): bool {
    Imagick::$flatColor = $rgb;
    Imagick::$pixelAlpha = $alpha;
    $i = new Imagick();
    $i->flat = true;
    $i->w = $w;
    $result = $priv('looksWhite', $i);
    Imagick::$pixelAlpha = null;
    Imagick::$flatColor = ['r' => 255, 'g' => 255, 'b' => 255];
    return $result;
};
check('white is all three channels; yellow, cyan and magenta are not', [$white(['r' => 255, 'g' => 255, 'b' => 255]), $white(['r' => 255, 'g' => 255, 'b' => 0]), $white(['r' => 0, 'g' => 255, 'b' => 255]), $white(['r' => 255, 'g' => 0, 'b' => 255])], [true, false, false, false]);
check('  ...black under a little transparency is black; under none at all, white', [$white(['r' => 0, 'g' => 0, 'b' => 0], 0.3), $white(['r' => 0, 'g' => 0, 'b' => 0], 0.0)], [false, true]);
check('  ...a page with no pixels at all counts as white', $white(['r' => 0, 'g' => 0, 'b' => 0], null, 0), true);
// The header may sit anywhere in the first 1024 bytes.
$headed = static function (int $at) use ($priv): bool {
    $f = RAPLS_PIC_TEST_TMP . '/head-' . $at . '.pdf';
    file_put_contents($f, str_repeat(' ', $at) . '%PDF-1.4');
    $result = $priv('looksLikePdf', $f);
    @unlink($f);
    return $result;
};
check('a PDF header 100 bytes in is a PDF; 1020 bytes in, too; 1100, not', [$headed(100), $headed(1019), $headed(1100)], [true, true, false]);
// ...and such a file is measured, not drawn as asked: its boxes are not
// trusted, since ImageMagick may read it as something else.
$pdfWas = $pdf;
$pdf = RAPLS_PIC_TEST_TMP . '/late-header.pdf';
file_put_contents($pdf, str_repeat(' ', 100) . "%PDF-1.4\n<< /MediaBox [0 0 595 842] >>");
Imagick::$pageSize = [595, 842];
[$r, $log] = run(['format' => 'jpeg']);
Imagick::$pageSize = null;
@unlink($pdf);
$pdf = $pdfWas;
check('  ...and one whose header is not at the start is measured before it is drawn', array_values(preg_grep('/^setResolution/', $log)), ['setResolution(2)', 'setResolution(150)']);
// A size of 0 is no size, and no reason to draw a page as asked with nothing
// bounding it (review after 1.4.35; it was drawn as asked, and only the
// sizes the callers pass, never 0, kept that harmless).
check('a size of 0 either way is no resolution, not the one asked', [$priv('fittedResolution', 0.0, 100.0, 150.0, 1000.0), $priv('fittedResolution', 100.0, 0.0, 150.0, 1000.0), $priv('fittedResolution', -5.0, 100.0, 150.0, 1000.0)], [null, null, null]);
// Exactly 2 dpi is drawn; less is not.
check('  ...a page that fits at exactly 2 dpi is drawn at 2; one that fits at less, refused', [$priv('fittedResolution', 2592.0, 2592.0, 150.0, 5184.0), $priv('fittedResolution', 2592.0, 2592.0, 150.0, 5183.0)], [2.0, null]);
// No pages counted is no answer, from ImageMagick or from the file.
Imagick::$countSays = 0;
$noneCounted = $priv('pagesPinged', $pdf);
Imagick::$countSays = null;
check('a count of 0 is no answer, from ImageMagick or the page tree', [$noneCounted, ImagickEngine::pagesFromBytes($treeFile("2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj"))], [null, null]);
// The page tree's own limits.
check('  ...matches inside one dictionary, read once over: no answer', ImagickEngine::pagesFromBytes($treeFile('<< /Type /Pages /Count 3 /Type /Pages /Count 3 >>')), null);
$nodes = RAPLS_PIC_TEST_TMP . '/nodes.pdf';
file_put_contents($nodes, "%PDF-1.4\n" . str_repeat("<</Type/Pages/Count 1>>\n", 10000));
$atNodes = ImagickEngine::pagesFromBytes($nodes);
file_put_contents($nodes, "%PDF-1.4\n" . str_repeat("<</Type/Pages/Count 1>>\n", 10001));
check('  ...10,000 page-tree dictionaries are read; 10,001 are not', [$atNodes, ImagickEngine::pagesFromBytes($nodes)], [1, null]);
$limitWas = ini_get('memory_limit');
ini_set('memory_limit', '-1');
$tree = "%PDF-1.4\n<< /Type /Pages /Count 4 >>\n";
file_put_contents($nodes, $tree . str_repeat(' ', 33554432 - strlen($tree)));
$atBytes = ImagickEngine::pagesFromBytes($nodes);
file_put_contents($nodes, ' ', FILE_APPEND);
clearstatcache();
$pastBytes = ImagickEngine::pagesFromBytes($nodes);
ini_set('memory_limit', $limitWas);
@unlink($nodes);
check('  ...a 32 MB file is read; a byte more is not', [$atBytes, $pastBytes], [4, null]);
// A result listener is heard.
$other = \Rapls\PDFImageCreator\Engine\ConversionResult::failure('from a listener', 'render_error');
[$r] = run(['format' => 'jpeg'], Imagick::COLORSPACE_CMYK, true, function () use ($other) {
    add_filter('rapls_pdf_image_creator_imagick_result', function () use ($other) { return $other; });
});
check('a result listener\'s answer is the result', $r, $other);

echo "\n=== Review after 1.4.34: a failed conversion, counted and let go ===\n";
// The page that failed is let go of, not held to the end of the request.
Imagick::$failReads = 1;
[$r, $log] = run(['format' => 'jpeg']);
Imagick::$failReads = 0;
check('a read that fails: the instance is cleared', [$r->getCode(), array_slice($log, -2)], ['render_error', ['readImage[n]', 'clear']]);
// One count from ImageMagick on a failure, and the file's figure only when
// ImageMagick cannot count.
Imagick::$throwPastEnd = true;
Imagick::$pageCount = 2;
[$r, $log] = run(['format' => 'jpeg', 'page' => 3]);
check('a page past the end: ImageMagick counts once', [$r->getCode(), count(array_keys($log, 'pingImage', true))], ['page_out_of_range', 1]);
$pdfWas = $pdf;
$pdf = $treeFile("<< /MediaBox [0 0 595 842] >>\n2 0 obj << /Type /Pages /Kids [3 0 R] /Count 2 >> endobj");
Imagick::$pingThrows = true;
[$r, $log] = run(['format' => 'jpeg', 'page' => 3]);
Imagick::$pingThrows = false;
check('  ...one ImageMagick cannot count is counted from the page tree', [$r->getCode(), false !== strpos((string) $r->getError(), 'has 2 pages')], ['page_out_of_range', true]);
// A policy, or no Ghostscript, on a page past the first: that, not a missing
// page, and nothing counted.
$pdf = $pdfWas;
Imagick::$throwPastEnd = false;
Imagick::$pageCount = 1;
foreach (['not authorized `PDF\' @ error/constitute.c/IsCoderAuthorized/426' => 'pdf_blocked_by_policy', "FailedToExecuteCommand `'gs' -q' (127) @ error/delegate.c/ExternalDelegateCommand/516" => 'pdf_unsupported'] as $message => $code) {
    Imagick::$failReads = 1;
    Imagick::$failMessage = $message;
    [$r, $log] = run(['format' => 'jpeg', 'page' => 1]);
    check("  ...$code on page 1 of a one-page file is $code, and nothing is counted", [$r->getCode(), in_array('pingImage', $log, true)], [$code, false]);
}
Imagick::$failReads = 0;
Imagick::$failMessage = 'Failed to read the file';
Imagick::$pageCount = $pagesWas;

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
