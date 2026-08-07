<?php
require __DIR__ . '/harness.php';

use Rapls\PDFImageCreator\Engine\ColorProfile;

echo "--- file validation (R-6 groundwork) ---\n";

reset_state();
add_filter('rapls_pdf_image_creator_icc_paths', function ($p, $t) use ($goodSrgb, $goodCmyk) {
    return $t === 'srgb' ? [$goodSrgb] : [$goodCmyk];
});
$cp = new ColorProfile();
check('valid srgb profile found', $cp->findProfilePath('srgb'), $goodSrgb);
check('valid cmyk profile found', $cp->findProfilePath('cmyk'), $goodCmyk);

reset_state();
add_filter('rapls_pdf_image_creator_icc_paths', function ($p, $t) use ($badSig) { return [$badSig]; });
check('rejects missing acsp signature', (new ColorProfile())->findProfilePath('srgb'), null);

reset_state();
add_filter('rapls_pdf_image_creator_icc_paths', function ($p, $t) use ($tooSmall) { return [$tooSmall]; });
check('rejects undersized file', (new ColorProfile())->findProfilePath('srgb'), null);

reset_state();
add_filter('rapls_pdf_image_creator_icc_paths', function ($p, $t) use ($dir) { return ["$dir/notprofile.txt"]; });
check('rejects wrong extension', (new ColorProfile())->findProfilePath('srgb'), null);

reset_state();
add_filter('rapls_pdf_image_creator_icc_paths', function ($p, $t) { return ['/nope/../nope/x.icc', '/etc/passwd']; });
check('rejects nonexistent + traversal', (new ColorProfile())->findProfilePath('srgb'), null);

reset_state();
add_filter('rapls_pdf_image_creator_icc_paths', function ($p, $t) { return 'not-an-array'; });
check('survives non-array filter return', (new ColorProfile())->findProfilePath('srgb'), null);

reset_state();
add_filter('rapls_pdf_image_creator_icc_paths', function ($p, $t) use ($badSig, $goodSrgb) { return [$badSig, $goodSrgb]; });
check('falls through to next candidate', (new ColorProfile())->findProfilePath('srgb'), $goodSrgb);

echo "\n--- caching ---\n";
reset_state();
$calls = 0;
add_filter('rapls_pdf_image_creator_icc_paths', function ($p, $t) use ($goodSrgb, &$calls) { $calls++; return [$goodSrgb]; });
$cp = new ColorProfile();
$cp->findProfilePath('srgb');
$cp->findProfilePath('srgb');
$cp->findProfilePath('srgb');
check('hit cached after first scan', $calls, 1);

reset_state();
$calls = 0;
add_filter('rapls_pdf_image_creator_icc_paths', function ($p, $t) use (&$calls) { $calls++; return ['/no/such.icc']; });
$cp = new ColorProfile();
$cp->findProfilePath('srgb');
$cp->findProfilePath('srgb');
check('miss is cached too', $calls, 1);

echo "\n--- conversion modes ---\n";

// non-CMYK image is left alone (R-1: RGB PDFs unchanged)
reset_state();
$im = new Imagick(Imagick::COLORSPACE_SRGB);
check('sRGB input -> passthrough', (new ColorProfile())->convertToSrgb($im), ColorProfile::MODE_PASSTHROUGH);
check('  ...and untouched', Imagick::$log, []);

// no profiles anywhere -> arithmetic (R-6)
reset_state();
add_filter('rapls_pdf_image_creator_icc_paths', function ($p, $t) { return []; });
$im = new Imagick(Imagick::COLORSPACE_CMYK);
check('no profiles -> naive', (new ColorProfile())->convertToSrgb($im), ColorProfile::MODE_NAIVE);
check('  ...used transformImageColorspace', Imagick::$log, ['transformImageColorspace(13)']);

// only sRGB available, no CMYK -> arithmetic
reset_state();
add_filter('rapls_pdf_image_creator_icc_paths', function ($p, $t) use ($goodSrgb) { return $t === 'srgb' ? [$goodSrgb] : []; });
$im = new Imagick(Imagick::COLORSPACE_CMYK);
check('srgb only -> naive', (new ColorProfile())->convertToSrgb($im), ColorProfile::MODE_NAIVE);

// both available -> ICC
reset_state();
add_filter('rapls_pdf_image_creator_icc_paths', function ($p, $t) use ($goodSrgb, $goodCmyk) {
    return $t === 'srgb' ? [$goodSrgb] : [$goodCmyk];
});
$im = new Imagick(Imagick::COLORSPACE_CMYK);
check('both profiles -> icc', (new ColorProfile())->convertToSrgb($im), ColorProfile::MODE_ICC);
check('  ...cmyk attached, then srgb converts', Imagick::$log, [
    'profileImage(300B)',
    'setImageRenderingIntent(3)',
    'profileImage(200B)',
    'setImageColorspace(13)',
]);

// image already carries a profile -> skip attach, convert straight to sRGB
reset_state();
add_filter('rapls_pdf_image_creator_icc_paths', function ($p, $t) use ($goodSrgb, $goodCmyk) {
    return $t === 'srgb' ? [$goodSrgb] : [$goodCmyk];
});
$im = new Imagick(Imagick::COLORSPACE_CMYK);
$im->profiles['icc'] = 'embedded';
Imagick::$log = [];
check('embedded profile -> icc', (new ColorProfile())->convertToSrgb($im), ColorProfile::MODE_ICC);
check('  ...no source profile attached', Imagick::$log, [
    'setImageRenderingIntent(3)',
    'profileImage(200B)',
    'setImageColorspace(13)',
]);

// profileImage throws -> fall back, no fatal (C-5)
reset_state();
add_filter('rapls_pdf_image_creator_icc_paths', function ($p, $t) use ($goodSrgb, $goodCmyk) {
    return $t === 'srgb' ? [$goodSrgb] : [$goodCmyk];
});
$im = new Imagick(Imagick::COLORSPACE_CMYK);
Imagick::$profileImageFails = true;
check('profileImage throws -> naive', (new ColorProfile())->convertToSrgb($im), ColorProfile::MODE_NAIVE);

// 'naive' filter pins old behaviour (§7-2)
reset_state();
add_filter('rapls_pdf_image_creator_icc_paths', function ($p, $t) use ($goodSrgb, $goodCmyk) {
    return $t === 'srgb' ? [$goodSrgb] : [$goodCmyk];
});
add_filter('rapls_pdf_image_creator_color_conversion', function ($m) { return 'naive'; });
$im = new Imagick(Imagick::COLORSPACE_CMYK);
check('color_conversion=naive forces old path', (new ColorProfile())->convertToSrgb($im), ColorProfile::MODE_NAIVE);
check('  ...no ICC calls', Imagick::$log, ['transformImageColorspace(13)']);

// bogus filter value falls back to auto
reset_state();
add_filter('rapls_pdf_image_creator_icc_paths', function ($p, $t) use ($goodSrgb, $goodCmyk) {
    return $t === 'srgb' ? [$goodSrgb] : [$goodCmyk];
});
add_filter('rapls_pdf_image_creator_color_conversion', function ($m) { return 'nonsense'; });
$im = new Imagick(Imagick::COLORSPACE_CMYK);
check('bogus mode falls back to auto', (new ColorProfile())->convertToSrgb($im), ColorProfile::MODE_ICC);

// rendering intent filter
reset_state();
add_filter('rapls_pdf_image_creator_icc_paths', function ($p, $t) use ($goodSrgb, $goodCmyk) {
    return $t === 'srgb' ? [$goodSrgb] : [$goodCmyk];
});
add_filter('rapls_pdf_image_creator_rendering_intent', function ($i) { return 1; });
$im = new Imagick(Imagick::COLORSPACE_CMYK);
(new ColorProfile())->convertToSrgb($im);
check('rendering intent is filterable', in_array('setImageRenderingIntent(1)', Imagick::$log, true), true);

echo "\n--- status reporting ---\n";
reset_state();
add_filter('rapls_pdf_image_creator_icc_paths', function ($p, $t) use ($goodSrgb, $goodCmyk) {
    return $t === 'srgb' ? [$goodSrgb] : [$goodCmyk];
});
$s = (new ColorProfile())->getStatus();
check('status managed', $s['managed'], true);
check('status mode', $s['mode'], 'auto');

reset_state();
add_filter('rapls_pdf_image_creator_icc_paths', function ($p, $t) use ($goodSrgb) { return $t === 'srgb' ? [$goodSrgb] : []; });
$s = (new ColorProfile())->getStatus();
check('status unmanaged when cmyk missing', $s['managed'], false);
check('status reports missing cmyk', $s['cmyk'], null);

echo "\n--- stale cache recovery ---\n";
reset_state();
$tmp = "$dir/temp.icc";
makeIcc($tmp);
add_filter('rapls_pdf_image_creator_icc_paths', function ($p, $t) use ($tmp, $goodSrgb) { return [$tmp, $goodSrgb]; });
$cp = new ColorProfile();
$cp->findProfilePath('srgb');            // caches $tmp
unlink($tmp);                            // profile disappears from the host
check('rescans after cached path vanishes', strlen((string) $cp->loadProfile('srgb')), 200);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
