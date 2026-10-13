<?php
/**
 * Standalone harness for ColorProfile / ImagickEngine logic.
 * Stubs the WordPress and Imagick surfaces these classes touch.
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
function delete_transient($k) { $GLOBALS['transient_deletes'][] = $k; unset($GLOBALS['transients'][$k]); return true; }
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['options'][$k] = $v; $GLOBALS['autoload'][$k] = $a; return true; }
// A translation can be given, to see that a translated string is escaped.
function __($s, $d = null) { return $GLOBALS['translations'][$s] ?? $s; }
if (!function_exists('_n')) { function _n($one, $many, $n, $d = null) { return 1 === (int) $n ? $one : $many; } }
function _x($s, $c, $d = null) { return $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function wp_mkdir_p($d) { return @mkdir($d, 0777, true) || is_dir($d); }

/** Records every call so we can assert on the pipeline order. */
class Imagick
{
    /** Older builds throw from getConfigureOptions() instead of answering. */
    public static $configureThrows = false;
    public static function getConfigureOptions($pattern = '*') {
        if (self::$configureThrows) {
            throw new ImagickException('not supported by this build');
        }
        return [];
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

    public static $log = [];
    public $colorspace;
    public $profiles = [];
    /** @var callable|null throws from profileImage when set */
    public static $profileImageFails = false;

    /** version string reported by getVersion(); tests swap this out */
    public static $versionString = 'ImageMagick 7.1.1-47 Q16 aarch64';

    /**
     * When true, readImage() comes back flat unless GS_OPTIONS is set -- the
     * Ghostscript 9.27 fault, modelled. Off by default so that every other
     * suite reads a page with content in it.
     */
    public static $blankWithoutGsOptions = false;

    /** Whether this instance's raster is one flat colour. */
    public $uniform = false;

    public function __construct($colorspace = self::COLORSPACE_CMYK) { $this->colorspace = $colorspace; }
    public function setResolution($x, $y) { self::$log[] = "setResolution($x)"; }
    /** GS_OPTIONS as each read saw it. */
    public static $gsOptionsSeen = [];
    /** When true, every read comes back flat, GS_OPTIONS or not. */
    public static $alwaysBlank = false;
    /** The path each read was given. */
    public static $readPaths = [];
    /** When true, a page number on a probe file is ignored and every page comes back, the first first. */
    public static $pageNumberIgnored = false;
    /** Whether each probe file was on disk when it was read. */
    public static $probeFilesSeen = [];
    public function readImage($p) {
        self::$log[] = 'readImage';
        self::$readPaths[] = $p;
        // The page-selection check's two-page file, read by page number.
        if (preg_match('#/rapls-pic-page-probe-[0-9a-f]+\.pdf\[(\d+)\]$#', (string) $p, $asked)) {
            self::$probeFilesSeen[] = is_file(substr($p, 0, -strlen('[' . $asked[1] . ']')));
            if (null === self::$blobError) { throw new Error('readImage cannot render the probe in this stub'); }
            if ('' !== self::$blobError) { throw new ImagickException(self::$blobError); }
            $this->fromBlob = true;
            $this->index = self::$pageNumberIgnored ? 0 : (int) $asked[1];
            $this->pages = self::$pageNumberIgnored ? 2 : 1;
            return true;
        }
        self::$gsOptionsSeen[] = getenv('GS_OPTIONS');
        // A request killed mid-read (max_execution_time): no catch, no finally.
        if (!empty($GLOBALS['die_on_switch']) && false !== strpos((string) getenv('GS_OPTIONS'), '-dNOTRANSPARENCY')) {
            echo 'died with ' . getenv('GS_OPTIONS') . "\n";
            register_shutdown_function(static function (): void { echo 'after: ' . getenv('GS_OPTIONS'); });
            exit(1);
        }
        if (self::$alwaysBlank) {
            $this->uniform = true;
        } elseif (self::$blankWithoutGsOptions) {
            $gs = getenv('GS_OPTIONS');
            $this->uniform = false === $gs || '' === $gs || false === strpos($gs, '-dNOTRANSPARENCY');
        }
        return true;
    }
    /** Size of what the in-memory test PDFs read back as; [0, 0] is an empty raster. */
    public static $blobSize = [100, 100];
    /** The colour at the middle of each page read from a blob, by page. */
    public static $blobColours = [['r' => 0, 'g' => 255, 'b' => 255]];
    /** How many pages a blob reads back as. */
    public static $blobPages = 1;
    private $fromBlob = false;
    private $index = 0;
    public function getImageWidth() { return $this->fromBlob ? self::$blobSize[0] : 100; }
    public function getImageHeight() { return $this->fromBlob ? self::$blobSize[1] : 100; }
    /** Pages this read gave, when not the blob's count. */
    private $pages = null;
    public function getNumberImages() { return $this->fromBlob ? ($this->pages ?? self::$blobPages) : 1; }
    /** A count or a size, without drawing: one A4 page unless a test says otherwise. */
    public function pingImage($p) { self::$log[] = 'pingImage'; return true; }
    public function setIteratorIndex($i) { $this->index = (int) $i; return true; }
    public function getImage() { $one = clone $this; return $one; }
    public function getImagePixelColor($x, $y) {
        $rgb = self::$blobColours[$this->index] ?? self::$blobColours[0];
        $pixel = new ImagickPixel('white');
        $pixel->rgb = $rgb;
        return $pixel;
    }
    public function getImageChannelRange($channel) {
        return $this->uniform
            ? ['minima' => 65535.0, 'maxima' => 65535.0]
            : ['minima' => 0.0, 'maxima' => 65535.0];
    }
    public static function getVersion() { return ['versionString' => self::$versionString]; }
    /** Formats this ImageMagick reports; a test adds WEBP. */
    public static $formats = ['PDF'];
    public static function queryFormats($p = '*') {
        return '*' === $p ? self::$formats : array_values(array_filter(self::$formats, function ($f) use ($p) { return strtoupper($f) === strtoupper($p); }));
    }
    public function getImageColorspace() { return $this->colorspace; }
    public function setImageColorspace($cs) { self::$log[] = "setImageColorspace($cs)"; $this->colorspace = $cs; }
    public function transformImageColorspace($cs) { self::$log[] = "transformImageColorspace($cs)"; $this->colorspace = $cs; }
    public function getImageProfiles($name, $values = true) { return $values ? $this->profiles : array_keys($this->profiles); }
    public function profileImage($name, $data) {
        if (self::$profileImageFails) { throw new ImagickException('bad profile'); }
        self::$log[] = 'profileImage(' . strlen($data) . 'B)';
        $this->profiles[$name] = $data;
    }
    public function setImageRenderingIntent($i) { self::$log[] = "setImageRenderingIntent($i)"; }
    public function setImageBackgroundColor($p) { self::$log[] = 'setImageBackgroundColor(' . $p . ')'; }
    public function mergeImageLayers($m) { self::$log[] = 'mergeImageLayers'; return $this; }
    public function clear() { self::$log[] = 'clear'; }
    public function setImageAlphaChannel($a) { self::$log[] = "setImageAlphaChannel($a)"; }
    public function stripImage() { self::$log[] = 'stripImage'; $this->profiles = []; }

    /**
     * The in-memory test PDFs: read, or refused with this message
     *
     * Null by default: as before this stub had the method, it cannot run at
     * all (an Error, which the CMYK check takes as "not tested"). A test sets
     * the message ImageMagick would give, or '' to read (review after 1.4.31).
     */
    public static $blobError = null;
    public function readImageBlob($blob, $name = null) {
        if (null === self::$blobError) { throw new Error('readImageBlob cannot render in this stub'); }
        if ('' === self::$blobError) { $this->fromBlob = true; return true; }
        throw new ImagickException(self::$blobError);
    }
}

class ImagickException extends Exception {}
class ImagickPixel { public $c; public $rgb = ['r' => 255, 'g' => 255, 'b' => 255]; public function __construct($c = 'white') {
    if (!in_array(strtolower($c), ['white','black','transparent','#f5f5f5','red'], true)) {
        throw new ImagickException("unparseable color: $c");
    }
    $this->c = $c; } public function __toString() { return $this->c; } public function getColor() { return $this->rgb; } }

require RAPLS_PIC_PLUGIN_DIR . 'includes/FailureCode.php';
require RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ColorProfile.php';

use Rapls\PDFImageCreator\Engine\ColorProfile;

/* --- build fixture profiles ------------------------------------------- */
$dir = RAPLS_PIC_TEST_TMP . '/icc';
@mkdir($dir, 0777, true);
function makeIcc($path, $size = 200, $sig = 'acsp') {
    $data = str_repeat("\0", 36) . $sig . str_repeat("\0", max(0, $size - 40));
    file_put_contents($path, $data);
    return $path;
}
$goodSrgb = makeIcc("$dir/sRGB2014.icc");
$goodCmyk = makeIcc("$dir/CoatedFOGRA39.icc", 300);
$badSig   = makeIcc("$dir/bogus.icc", 200, 'XXXX');
$tooSmall = makeIcc("$dir/tiny.icc", 10);
file_put_contents("$dir/notprofile.txt", str_repeat('a', 500));

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    $ok = $got === $want;
    $ok ? $pass++ : $fail++;
    printf("%s %-58s got=%s want=%s\n", $ok ? 'ok  ' : 'FAIL', $label,
        var_export($got, true), var_export($want, true));
}
function reset_state() {
    $GLOBALS['transients'] = [];
    $GLOBALS['filters'] = [];
    $GLOBALS['options'] = [];
    Imagick::$log = [];
    Imagick::$profileImageFails = false;
    Imagick::$versionString = 'ImageMagick 7.1.1-47 Q16 aarch64';
    Imagick::$blankWithoutGsOptions = false;
    Imagick::$alwaysBlank = false;
    Imagick::$readPaths = [];
    Imagick::$blobSize = [100, 100];
    Imagick::$blobColours = [['r' => 0, 'g' => 255, 'b' => 255]];
    Imagick::$blobPages = 1;
    Imagick::$pageNumberIgnored = false;
    Imagick::$probeFilesSeen = [];
    putenv('GS_OPTIONS');
}
