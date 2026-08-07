<?php
/**
 * Standalone harness for ColorProfile / ImagickEngine logic.
 * Stubs the WordPress and Imagick surfaces these classes touch.
 */

define('RAPLS_PIC_PLUGIN_DIR', dirname(__DIR__) . '/');
define('RAPLS_PIC_TEST_TMP', sys_get_temp_dir() . '/rapls-pic-tests');
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);

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
function _x($s, $c, $d = null) { return $s; }
function esc_html($s) { return $s; }
function wp_mkdir_p($d) { return @mkdir($d, 0777, true) || is_dir($d); }

/** Records every call so we can assert on the pipeline order. */
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

    public static $log = [];
    public $colorspace;
    public $profiles = [];
    /** @var callable|null throws from profileImage when set */
    public static $profileImageFails = false;

    /** version string reported by getVersion(); tests swap this out */
    public static $versionString = 'ImageMagick 7.1.1-47 Q16 aarch64';

    public function __construct($colorspace = self::COLORSPACE_CMYK) { $this->colorspace = $colorspace; }
    public static function getVersion() { return ['versionString' => self::$versionString]; }
    public static function queryFormats($p = '*') { return ['PDF']; }
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
}

class ImagickException extends Exception {}
class ImagickPixel { public $c; public function __construct($c = 'white') {
    if (!in_array(strtolower($c), ['white','black','transparent','#f5f5f5','red'], true)) {
        throw new ImagickException("unparseable color: $c");
    }
    $this->c = $c; } public function __toString() { return $this->c; } }

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
}
