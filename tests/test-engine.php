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

// disable_functions read as PHP reads it: compared with PHP's own answer, in
// a child process started with each list.
foreach (['putenv', 'exec putenv', 'exec,putenv', 'exec , putenv', 'PUTENV', "exec\tputenv", 'putenvx', ''] as $list) {
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' -n -d ' . escapeshellarg('disable_functions=' . $list) . ' -r ' . escapeshellarg('echo function_exists("putenv") ? "on" : "off";'), $out);
    check(
        sprintf('disable_functions %s: putenv %s, as PHP says', json_encode($list), implode('', $out)),
        priv($engine, $ref, 'isListedDisabled', 'putenv', $list) ? 'off' : 'on',
        implode('', $out)
    );
}

reset_state();
Imagick::$blankWithoutGsOptions = true;
$page = priv($engine, $ref, 'readPage', new Imagick(), '/tmp/none.pdf', 0, 150);
check('a blank page is retried and recovered', $page->uniform, false);
check('  ...and the server is remembered as needing it', get_transient('rapls_pic_gs_options'), 'yes');
check('  ...and GS_OPTIONS is left unset afterwards', getenv('GS_OPTIONS'), false);
// Review of 1.4.32: read from the process's own environment (a FastCGI
// parameter of the same name is not this process's, and was written into it).
check('  ...GS_OPTIONS is read from the process environment only', substr_count((string) file_get_contents(RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ImagickEngine.php'), "getenv('GS_OPTIONS', true)"), 1);

// Review after 1.4.31: the retry adds its switch to what the site set, and
// puts the site's value back.
reset_state();
Imagick::$blankWithoutGsOptions = true;
Imagick::$gsOptionsSeen = [];
putenv('GS_OPTIONS=-sFONTPATH=/srv/fonts');
priv($engine, $ref, 'readPage', new Imagick(), '/tmp/none.pdf', 0, 150);
$seenDuring = array_values(array_filter(Imagick::$gsOptionsSeen, function ($v) { return is_string($v) && false !== strpos($v, '-dNOTRANSPARENCY'); }));
check('the retry keeps the site\'s own GS_OPTIONS beside its switch', [$seenDuring[0] ?? null, getenv('GS_OPTIONS')], ['-sFONTPATH=/srv/fonts -dNOTRANSPARENCY', '-sFONTPATH=/srv/fonts']);
putenv('GS_OPTIONS');
// (A page blank with the retry too is tested in test-pipeline.php, whose
// Review after 1.4.32: a request that dies inside the retry's read --
// max_execution_time -- runs no finally. The switch is still taken out of the
// worker's environment at shutdown, and the site's own value put back.
$dying = sys_get_temp_dir() . '/rapls-pic-gs-dies-' . getmypid() . '.php';
file_put_contents($dying, '<?php require ' . var_export(__DIR__ . '/harness.php', true) . ';'
    . ' require ' . var_export(RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/EngineInterface.php', true) . ';'
    . ' require ' . var_export(RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ConversionResult.php', true) . ';'
    . ' require ' . var_export(RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ImagickEngine.php', true) . ';'
    . ' putenv("GS_OPTIONS=-sFONTPATH=/srv/fonts"); Imagick::$blankWithoutGsOptions = true;'
    . ' $GLOBALS["die_on_switch"] = true;'
    . ' $m = new ReflectionMethod(Rapls\\PDFImageCreator\\Engine\\ImagickEngine::class, "readPage"); if (PHP_VERSION_ID < 80100) { $m->setAccessible(true); }'
    . ' $m->invoke(new Rapls\\PDFImageCreator\\Engine\\ImagickEngine(), new Imagick(), "/tmp/none.pdf", 0, 150);'
    . ' echo "survived";');
$out = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($dying) . ' 2>&1', $out);
@unlink($dying);
check('a request that dies inside the retry leaves the site\'s GS_OPTIONS, without the switch', implode("\n", $out), "died with -sFONTPATH=/srv/fonts -dNOTRANSPARENCY\nafter: -sFONTPATH=/srv/fonts");
// stub models the whole-file route that case goes through.)

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

echo "\n--- review after 1.4.31: a failed PDF read check is kept briefly unless the reason lasts ---\n";
$probeTtl = static function (?string $error) use ($engine, $ref): array {
    $GLOBALS['transients'] = [];
    $GLOBALS['transient_ttl'] = [];
    Imagick::$blobError = $error;
    $answer = priv($engine, $ref, 'probePdfRead');
    Imagick::$blobError = null;
    return [$answer['ok'], $GLOBALS['transient_ttl']['rapls_pic_pdf_read'] ?? null];
};
check('a read that works is kept twelve hours', $probeTtl(''), [true, 12 * HOUR_IN_SECONDS]);
check('  ...one a policy refuses, twelve hours', $probeTtl('not authorized `PDF\' @ error/constitute.c/IsCoderAuthorized/426'), [false, 12 * HOUR_IN_SECONDS]);
check('  ...no PDF support, twelve hours', $probeTtl("NoDecodeDelegateForThisImageFormat `PDF'"), [false, 12 * HOUR_IN_SECONDS]);
check('  ...anything else (a full /tmp), ten minutes', $probeTtl('unable to open image `/tmp/magick-x\': No space left on device'), [false, ImagickEngine::PASSING_FAILURE_SECONDS]);
// Review of 1.4.32: Ghostscript run but failing (exit 1, or killed: 9) is this
// moment's; not found (127, 32512) is the server's.
check('  ...Ghostscript run but failing or killed, ten minutes; not found, twelve hours', [
    $probeTtl("FailedToExecuteCommand `'gs' -sstdout=%stderr ...' (256) @ error/ghostscript-private.h/InvokeGhostscriptDelegate/120")[1],
    $probeTtl("FailedToExecuteCommand `'gs' -sstdout=%stderr ...' (9) @ error/ghostscript-private.h/InvokeGhostscriptDelegate/120")[1],
    $probeTtl("FailedToExecuteCommand `'gs' -sstdout=%stderr ...' (32512) @ error/ghostscript-private.h/InvokeGhostscriptDelegate/120")[1],
    $probeTtl("UnableToOpenConfigureFile `delegates.xml' @ warning/configure.c/GetConfigureOptions/722")[1],
], [ImagickEngine::PASSING_FAILURE_SECONDS, ImagickEngine::PASSING_FAILURE_SECONDS, 12 * HOUR_IN_SECONDS, ImagickEngine::PASSING_FAILURE_SECONDS]);

echo "\n--- Review of 1.4.32: the checks' own rules ---\n";
// A page still flat with -dNOTRANSPARENCY is not counted as the switch
// working: nothing is remembered as "this server needs it".
reset_state();
Imagick::$alwaysBlank = true;
priv($engine, $ref, 'readPage', new Imagick(), '/tmp/none.pdf', 0, 150);
check('a page flat with the switch too: the server is not remembered as needing it', get_transient('rapls_pic_gs_options'), false);
// A cached read check from another ImageMagick version is not used.
reset_state();
set_transient('rapls_pic_pdf_read', ['ok' => false, 'error' => 'old', 'version' => 'ImageMagick 6.9.0'], 600);
Imagick::$blobError = '';
$fresh = priv($engine, $ref, 'probePdfRead');
Imagick::$blobError = null;
check('a cached read check from another ImageMagick is measured again', $fresh['ok'], true);
// The CMYK check: an empty raster, and a black page, are failures, not "not tested".
reset_state();
Imagick::$blobError = '';
Imagick::$blobSize = [0, 0];
$empty = priv($engine, $ref, 'probeCmykRender');
reset_state();
Imagick::$blobError = '';
Imagick::$blobColours = [['r' => 3, 'g' => 2, 'b' => 4]];
$black = priv($engine, $ref, 'probeCmykRender');
reset_state();
Imagick::$blobError = '';
$cyan = priv($engine, $ref, 'probeCmykRender');
Imagick::$blobError = null;
check('the CMYK check: an empty raster or a black page fails, cyan passes', [$empty['ok'], $black['ok'], $cyan['ok']], [false, false, true]);
// The page selection check, read the way a conversion reads a page (review
// after 1.4.33): from a file, by page number. A build that ignores the number
// fails; one that honours it passes; the file is gone afterwards.
reset_state();
Imagick::$blobError = '';
Imagick::$pageNumberIgnored = true;
Imagick::$blobColours = [['r' => 255, 'g' => 255, 'b' => 255], ['r' => 0, 'g' => 0, 'b' => 0]];
$ignored = priv($engine, $ref, 'probePageSelection');
reset_state();
Imagick::$blobError = '';
Imagick::$blobColours = [['r' => 255, 'g' => 255, 'b' => 255], ['r' => 0, 'g' => 0, 'b' => 0]];
$two = priv($engine, $ref, 'probePageSelection');
$probePaths = array_values(preg_grep('/rapls-pic-page-probe/', Imagick::$readPaths));
Imagick::$blobError = null;
check('the page check: a page number ignored fails, honoured passes', [$ignored['ok'], $two['ok'], $two['error']], [false, true, 'FFFFFF / 000000']);
check('  ...read from a file, by page number, flattened as a conversion is', [count($probePaths), (bool) preg_match('/\[0\]$/', $probePaths[0] ?? ''), (bool) preg_match('/\[1\]$/', $probePaths[1] ?? ''), Imagick::$probeFilesSeen, in_array('mergeImageLayers', Imagick::$log, true)], [2, true, true, [true, true], true]);
check('  ...and the file is deleted afterwards', file_exists(substr($probePaths[0] ?? 'x[0]', 0, -3)), false);
// A name someone made first -- a link left in a shared /tmp pointing at a
// file of theirs -- is refused, not written through.
$victim = RAPLS_PIC_TEST_TMP . '/victim.txt';
$planted = RAPLS_PIC_TEST_TMP . '/planted-probe.pdf';
file_put_contents($victim, 'keep me');
@unlink($planted);
symlink($victim, $planted);
try {
    priv($engine, $ref, 'probeFile', '%PDF-1.4 test', $planted);
    $plantedOutcome = 'written';
} catch (\RuntimeException $e) {
    $plantedOutcome = 'refused';
}
check('  ...a test file whose name is already taken by a link is refused, and its target left alone', [$plantedOutcome, file_get_contents($victim)], ['refused', 'keep me']);
@unlink($planted);
@unlink($victim);
reset_state();
Imagick::$blobError = "NoDecodeDelegateForThisImageFormat `PDF'";
$refusedProbe = priv($engine, $ref, 'probePageSelection');
$refusedPaths = array_values(preg_grep('/rapls-pic-page-probe/', Imagick::$readPaths));
Imagick::$blobError = null;
check('  ...a server that cannot read it is "not tested", and the file is deleted all the same', [$refusedProbe['ok'], file_exists(substr($refusedPaths[0] ?? 'x[0]', 0, -3))], [null, false]);
// A policy refusal with no policy file found shows what ImageMagick said.
reset_state();
$refused = priv($engine, $ref, 'statusFromProbe', ['ok' => false, 'error' => "NotAuthorized `PDF' @ error/constitute.c/IsCoderAuthorized/454"]);
check('a policy refusal with no file found carries ImageMagick\'s words', [$refused['code'], $refused['detail']], ['pdf_blocked_by_policy', "NotAuthorized `PDF' @ error/constitute.c/IsCoderAuthorized/454"]);
// Review after 1.4.32: Ghostscript run and exiting 1 is cached for ten
// minutes as a passing failure, and the status says the same -- not "no PDF
// support" for those ten minutes.
$exited = priv($engine, $ref, 'statusFromProbe', ['ok' => false, 'error' => "FailedToExecuteCommand `'gs' -dSAFER ...' (1) @ error/delegate.c/ExternalDelegateCommand/516"]);
$missing = priv($engine, $ref, 'statusFromProbe', ['ok' => false, 'error' => "FailedToExecuteCommand `'gs' -dSAFER ...' (127) @ error/delegate.c/ExternalDelegateCommand/516"]);
check('Ghostscript exiting 1 in the check is "unable to check", not "no PDF support"; not found still is', [$exited['code'], $missing['code']], ['error', 'pdf_unsupported']);
// putenv on the host's disable_functions turns the retry off, asked in a
// PHP that has it there.
$child = sys_get_temp_dir() . '/rapls-pic-canset-' . getmypid() . '.php';
file_put_contents($child, '<?php define("ABSPATH", "/"); function __($s, $d = null) { return $s; } require ' . var_export(RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/EngineInterface.php', true) . '; require ' . var_export(RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ColorProfile.php', true) . '; require ' . var_export(RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ImagickEngine.php', true) . '; $m = new ReflectionMethod(Rapls\\PDFImageCreator\\Engine\\ImagickEngine::class, "canSetEnvironment"); if (PHP_VERSION_ID < 80100) { $m->setAccessible(true); } echo $m->invoke(new Rapls\\PDFImageCreator\\Engine\\ImagickEngine()) ? "yes" : "no";');
$asked = [];
foreach (['putenv', 'exec'] as $list) {
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' -n -d ' . escapeshellarg('disable_functions=' . $list) . ' ' . escapeshellarg($child) . ' 2>&1', $out);
    $asked[$list] = implode('', $out);
}
@unlink($child);
check('putenv disabled on the host: the retry is not tried; otherwise it is', $asked, ['putenv' => 'no', 'exec' => 'yes']);

echo "\n--- Review after 1.4.33: what was not pinned ---\n";
// The device note names bmpsep8 as the one ImageMagick cannot read back, and
// only that one.
$deviceRow = static function (string $device) use ($engine, $ref): string {
    reset_state();
    Imagick::$versionString = 'ImageMagick 6.9.10-68 Q16 x86_64';
    set_transient('rapls_pic_cmyk_delegate', ['device' => $device], 600);
    $row = priv($engine, $ref, 'getCmykPdfStatus');
    return (string) $row['detail'];
};
$bmp = $deviceRow('bmpsep8');
$pam = $deviceRow('pamcmyk32');
check('the CMYK row names the device, and calls only bmpsep8 unreadable', [
    false !== strpos($bmp, 'through the bmpsep8 device.'),
    false !== strpos($bmp, 'That is the one ImageMagick cannot read back.'),
    false !== strpos($pam, 'through the pamcmyk32 device.'),
    false !== strpos($pam, 'cannot read back'),
], [true, true, true, false]);
Imagick::$versionString = 'ImageMagick 7.1.1-47 Q16 aarch64';

// restoreGsOptions() with no retry in flight leaves what the site set alone:
// it runs at every shutdown once registered.
reset_state();
putenv('GS_OPTIONS=-sFONTPATH=/srv/site');
ImagickEngine::restoreGsOptions();
check('restoring with no retry in flight leaves the site\'s GS_OPTIONS', getenv('GS_OPTIONS'), '-sFONTPATH=/srv/site');
putenv('GS_OPTIONS');
ImagickEngine::restoreGsOptions();
check('  ...and leaves an unset one unset', getenv('GS_OPTIONS'), false);

// The flatness of a page is scanned once a read (review after 1.4.33).
reset_state();
$scans = 0;
$counting = new class () extends Imagick {
    public static $scans = 0;
    public function getImageChannelRange($channel) { self::$scans++; return parent::getImageChannelRange($channel); }
};
priv($engine, $ref, 'readPage', $counting, '/tmp/none.pdf', 0, 150);
check('a page with content is scanned for flatness once', get_class($counting)::$scans, 1);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
