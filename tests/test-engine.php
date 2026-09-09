<?php
require __DIR__ . '/harness.php';

// Extra Imagick surface the engine touches.
class ImagickFull extends Imagick
{
    public $w = 2000, $h = 3000;
    public $hasMerge = true;
    public function setResolution($x, $y) { self::$log[] = "setResolution($x)"; }
    public function readImage($p) { self::$log[] = 'readImage'; }
    public function setImageFormat($f) { self::$log[] = "setImageFormat($f)"; }
    public function setImageCompression($c) { self::$log[] = 'setImageCompression'; }
    public function setImageCompressionQuality($q) { self::$log[] = "quality($q)"; }
    public function resizeImage($w, $h, $f, $b) { self::$log[] = "resizeImage($w,$h)"; $this->w = $w; $this->h = $h; }
    public function getImageWidth() { return $this->w; }
    public function getImageHeight() { return $this->h; }
    public function writeImage($p) { self::$log[] = 'writeImage'; file_put_contents($p, 'x'); return true; }
    public function destroy() { self::$log[] = 'destroy'; }
}

require RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/EngineInterface.php';
require RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ConversionResult.php';
require RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ImagickEngine.php';

use Rapls\PDFImageCreator\Engine\ColorProfile;
use Rapls\PDFImageCreator\Engine\ImagickEngine;

/*
 * These are the engine's private helpers. The whole convert() pipeline is
 * covered separately by test-pipeline.php, which stubs Imagick completely;
 * here we poke at the decision logic directly.
 */
$engine = new ImagickEngine();
$ref = new ReflectionClass($engine);
function priv($engine, $ref, $name, ...$args) {
    return $ref->getMethod($name)->invoke($engine, ...$args);
}

echo "--- format normalization ---\n";
check('jpeg', priv($engine, $ref, 'normalizeFormat', 'jpeg'), 'JPEG');
check('jpg falls back to JPEG', priv($engine, $ref, 'normalizeFormat', 'jpg'), 'JPEG');
check('png', priv($engine, $ref, 'normalizeFormat', 'png'), 'PNG');
check('webp', priv($engine, $ref, 'normalizeFormat', 'WebP'), 'WEBP');
check('garbage defaults to JPEG', priv($engine, $ref, 'normalizeFormat', 'tiff'), 'JPEG');

echo "\n--- background resolution (R-4, R-8) ---\n";
reset_state();
check('white stays white', priv($engine, $ref, 'resolveBackground', 'white', 'JPEG'), 'white');
check('black stays black', priv($engine, $ref, 'resolveBackground', 'black', 'PNG'), 'black');
check('transparent kept for PNG', priv($engine, $ref, 'resolveBackground', 'transparent', 'PNG'), 'transparent');
check('transparent kept for WEBP', priv($engine, $ref, 'resolveBackground', 'transparent', 'WEBP'), 'transparent');
check('transparent -> white for JPEG', priv($engine, $ref, 'resolveBackground', 'transparent', 'JPEG'), 'white');

reset_state();
add_filter('rapls_pdf_image_creator_flatten_background', function ($c, $f) { return '#F5F5F5'; });
check('filter overrides, lowercased', priv($engine, $ref, 'resolveBackground', 'white', 'JPEG'), '#f5f5f5');

reset_state();
add_filter('rapls_pdf_image_creator_flatten_background', function ($c, $f) { return 123; });
check('non-string filter -> white', priv($engine, $ref, 'resolveBackground', 'white', 'JPEG'), 'white');

reset_state();
add_filter('rapls_pdf_image_creator_flatten_background', function ($c, $f) { return ''; });
check('empty filter -> white', priv($engine, $ref, 'resolveBackground', 'white', 'JPEG'), 'white');

echo "\n--- background pixel parsing ---\n";
check('white pixel', (string) priv($engine, $ref, 'getBgColor', 'white'), 'white');
check('black pixel', (string) priv($engine, $ref, 'getBgColor', 'black'), 'black');
check('transparent pixel', (string) priv($engine, $ref, 'getBgColor', 'transparent'), 'transparent');
check('hex pixel passes through', (string) priv($engine, $ref, 'getBgColor', '#f5f5f5'), '#f5f5f5');
check('unparseable colour -> white', (string) priv($engine, $ref, 'getBgColor', 'chartreusey'), 'white');

echo "\n--- flatten releases the old instance ---\n";
reset_state();
$im = new ImagickFull();
$result = priv($engine, $ref, 'flatten', $im);
check('merge used', in_array('mergeImageLayers', Imagick::$log, true), true);
check('original cleared', in_array('clear', Imagick::$log, true), true);

echo "\n--- alpha removal (C-5: old Imagick) ---\n";
reset_state();
$im = new ImagickFull();
priv($engine, $ref, 'removeAlphaChannel', $im);
check('uses ALPHACHANNEL_REMOVE when defined', Imagick::$log, ['setImageAlphaChannel(12)']);

echo "\n--- diagnostics option ---\n";
reset_state();
priv($engine, $ref, 'recordDiagnostics', Imagick::COLORSPACE_CMYK, 'icc');
check('records colorspace', $GLOBALS['options'][ImagickEngine::DIAGNOSTICS_OPTION]['colorspace'], 12);
check('records mode', $GLOBALS['options'][ImagickEngine::DIAGNOSTICS_OPTION]['mode'], 'icc');
$firstTime = $GLOBALS['options'][ImagickEngine::DIAGNOSTICS_OPTION]['time'];
$GLOBALS['options'][ImagickEngine::DIAGNOSTICS_OPTION]['time'] = 0;
priv($engine, $ref, 'recordDiagnostics', Imagick::COLORSPACE_CMYK, 'icc');
check('unchanged value skips the write', $GLOBALS['options'][ImagickEngine::DIAGNOSTICS_OPTION]['time'], 0);
priv($engine, $ref, 'recordDiagnostics', Imagick::COLORSPACE_SRGB, 'passthrough');
check('changed value rewrites', $GLOBALS['options'][ImagickEngine::DIAGNOSTICS_OPTION]['mode'], 'passthrough');

echo "\n--- status row for the Status tab ---\n";
reset_state();
add_filter('rapls_pdf_image_creator_icc_paths', function ($p, $t) use ($goodSrgb, $goodCmyk) {
    return $t === 'srgb' ? [$goodSrgb] : [$goodCmyk];
});
$row = priv($engine, $ref, 'getColorManagementStatus');
check('managed status true', $row['status'], true);
check('names both profiles', $row['detail'], 'Converting CoatedFOGRA39.icc to sRGB2014.icc');

reset_state();
add_filter('rapls_pdf_image_creator_icc_paths', function ($p, $t) { return []; });
$row = priv($engine, $ref, 'getColorManagementStatus');
check('unmanaged status false', $row['status'], false);
check('explains the gap', $row['detail'], 'Neither a CMYK nor an sRGB profile was found on this server. Colour conversion needs both.');

// One present and one missing is the case that decides what to do next: a
// server needing one profile is a different errand from a server needing two.
// Reporting whichever was checked first left that unanswerable.
reset_state();
add_filter('rapls_pdf_image_creator_icc_paths', function ($p, $t) use ($goodSrgb) {
    return 'srgb' === $t ? [$goodSrgb] : [];
});
$row = priv($engine, $ref, 'getColorManagementStatus');
check('only CMYK missing is said so', false !== strpos($row['detail'], 'No CMYK profile'), true);
check('  ...and the other is reported present', false !== strpos($row['detail'], 'the other one is present'), true);

reset_state();
add_filter('rapls_pdf_image_creator_icc_paths', function ($p, $t) use ($goodCmyk) {
    return 'cmyk' === $t ? [$goodCmyk] : [];
});
$row = priv($engine, $ref, 'getColorManagementStatus');
check('only sRGB missing is said so', false !== strpos($row['detail'], 'No sRGB profile'), true);

reset_state();
add_filter('rapls_pdf_image_creator_color_conversion', function ($m) { return 'naive'; });
$row = priv($engine, $ref, 'getColorManagementStatus');
check('naive mode reported distinctly', $row['message'], 'Disabled by filter — using simple conversion');

echo "\n--- ImageMagick 6 CMYK PDF warning ---\n";
reset_state();
check('IM7 major version parsed', priv($engine, $ref, 'getImageMagickMajorVersion'), 7);
check('IM7 gets no warning row', priv($engine, $ref, 'getCmykPdfStatus'), null);

reset_state();
Imagick::$versionString = 'ImageMagick 6.9.10-68 Q16 x86_64 2026-03-26 https://imagemagick.org';
check('IM6 major version parsed', priv($engine, $ref, 'getImageMagickMajorVersion'), 6);
$row = priv($engine, $ref, 'getCmykPdfStatus');
check('IM6 gets a warning row', is_array($row), true);
check('  ...flagged as a problem', $row['status'], false);
check('  ...names the version', $row['message'], 'ImageMagick 6 — CMYK PDFs may produce a blank image (not tested)');
// The stub has no readImageBlob, so the probe cannot run. That is the branch
// under test: it must admit it did not measure anything, and it must say what
// stopped it. Until 1.4.0 it captured the reason and then discarded it, which
// left the one answer meaning "I do not know" with nothing to act on.
check('  ...admits it is not a measurement', false !== strpos($row['message'], '(not tested)'), true);
check('  ...says what stopped it', false !== strpos($row['detail'], 'What stopped it'), true);
check('  ...and RGB is still exonerated', false !== strpos($row['detail'], 'RGB PDFs are not affected'), true);

reset_state();
Imagick::$versionString = 'ImageMagick 6.9.11-35 Q16 x86_64';
check('other IM6 build also warned', is_array(priv($engine, $ref, 'getCmykPdfStatus')), true);

reset_state();
Imagick::$versionString = 'ImageMagick 8.0.1 Q16';
check('a future IM8 is not warned', priv($engine, $ref, 'getCmykPdfStatus'), null);

reset_state();
Imagick::$versionString = 'nonsense';
check('unparseable version is not warned', priv($engine, $ref, 'getCmykPdfStatus'), null);
check('  ...and reports unknown major', priv($engine, $ref, 'getImageMagickMajorVersion'), null);

echo "\n--- the warning reaches getRequirements() ---\n";
reset_state();
Imagick::$versionString = 'ImageMagick 6.9.10-68 Q16 x86_64';
$reqs = $engine->getRequirements();
check('no cmyk_pdf key without the extension', isset($reqs['cmyk_pdf']), false);

/*
 * The Ghostscript blank-page retry.
 *
 * Modelled on what Xserver sv17007 actually does: Ghostscript 9.27 returns a
 * flat white page for a PDF carrying a transparency group, and returns the
 * picture once GS_OPTIONS carries -dNOTRANSPARENCY. The stub's readImage()
 * reads the environment the same way, so these exercise the real decision.
 */
echo "\n--- the Ghostscript blank-page retry ---\n";

reset_state();
check('this process may set its own environment', priv($engine, $ref, 'canSetEnvironment'), true);

reset_state();
Imagick::$blankWithoutGsOptions = true;
$page = priv($engine, $ref, 'readPage', new Imagick(), '/tmp/none.pdf', 0, 150);
check('a blank page is retried and recovered', $page->uniform, false);
check('  ...and the server is remembered as needing it', get_transient('rapls_pic_gs_options'), 'yes');
check('  ...and GS_OPTIONS is left unset afterwards', getenv('GS_OPTIONS'), false);

reset_state();
check(
    'no Ghostscript row until it has been needed',
    priv($engine, $ref, 'getGhostscriptWorkaroundStatus'),
    null
);
set_transient('rapls_pic_gs_options', 'yes', 1);
check(
    'the Status tab says so once it has',
    is_array(priv($engine, $ref, 'getGhostscriptWorkaroundStatus')),
    true
);

reset_state();
$page = priv($engine, $ref, 'readPage', new Imagick(), '/tmp/none.pdf', 0, 150);
check('a page with content is read once, not twice', count(array_keys(Imagick::$log, 'readImage')), 1);
check('  ...and nothing is recorded about Ghostscript', get_transient('rapls_pic_gs_options'), false);

reset_state();
add_filter('rapls_pdf_image_creator_ghostscript_options', function () { return ''; });
check(
    'an empty filter value skips the retry',
    priv($engine, $ref, 'readWithGhostscriptOptions', '/tmp/none.pdf', 0, 150),
    null
);
check('  ...without reading anything', Imagick::$log, []);

reset_state();
add_filter('rapls_pdf_image_creator_ghostscript_options', function () { return "-dSAFER\nPATH=/evil"; });
check(
    'a newline in the filter value is refused',
    priv($engine, $ref, 'readWithGhostscriptOptions', '/tmp/none.pdf', 0, 150),
    null
);

reset_state();
putenv('GS_OPTIONS=-dFirstPage=2');
priv($engine, $ref, 'readWithGhostscriptOptions', '/tmp/none.pdf', 0, 150);
check('an existing GS_OPTIONS is restored, not cleared', getenv('GS_OPTIONS'), '-dFirstPage=2');
putenv('GS_OPTIONS');

echo "\n--- the retry is one of the transients the re-check button clears ---\n";
check(
    'probeTransients() lists it',
    in_array('rapls_pic_gs_options', ImagickEngine::probeTransients(), true),
    true
);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
