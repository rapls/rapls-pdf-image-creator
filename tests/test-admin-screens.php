<?php
/**
 * What the settings screen decides to show, apart from rendering it.
 *
 * The view itself needs most of WordPress to draw; the decisions it makes
 * live in small functions, tested here, and the view is checked to call them.
 */

require __DIR__ . '/harness.php';
require RAPLS_PIC_PLUGIN_DIR . 'includes/Settings.php';
require RAPLS_PIC_PLUGIN_DIR . 'includes/Admin.php';

use Rapls\PDFImageCreator\Admin;

$view = (string) file_get_contents(RAPLS_PIC_PLUGIN_DIR . 'admin/views/settings-page.php');

echo "\n=== The CMYK box on the Status tab (review after 1.4.31) ===\n";

// It sat under "Working on this server (tested)" on every ImageMagick 6
// server: the condition was only that the row existed.
check('no box where there is no CMYK row (ImageMagick 7)', Admin::showsCmykWarning(null), false);
check('no box where the test rendered correctly', Admin::showsCmykWarning(['status' => true, 'message' => 'Working on this server (tested)']), false);
check('a box where the test came back blank', Admin::showsCmykWarning(['status' => false, 'message' => 'Broken on this server (tested)']), true);
check('a box where the test could not be run', Admin::showsCmykWarning(['status' => false, 'message' => 'not tested']), true);
check('the view asks it', substr_count($view, "Admin::showsCmykWarning(\$rapls_pic_imagick_engine['requirements']['cmyk_pdf'] ?? null)"), 1);
check('  ...and says some builds, not every ImageMagick 6', [false !== strpos($view, "Some ImageMagick 6 builds render CMYK PDFs"), false !== strpos($view, "'ImageMagick 6 renders CMYK PDFs")], [true, false]);
check('  ...and that delegates.xml does nothing where Ghostscript is a library', false !== strpos($view, 'This has no effect where ImageMagick calls Ghostscript as a library'), true);

echo "\n=== WebP only where it can work (review after 1.4.31) ===\n";
function wp_get_mime_types() { return $GLOBALS['mime_types']; }
$GLOBALS['mime_types'] = ['jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
Imagick::$formats = ['PDF', 'WEBP'];
check('WebP works where WordPress accepts it and ImageMagick writes it', Admin::webpWorks(), true);
Imagick::$formats = ['PDF'];
check('  ...not where ImageMagick has no WebP encoder', Admin::webpWorks(), false);
Imagick::$formats = ['PDF', 'WEBP'];
$GLOBALS['mime_types'] = ['jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png'];
check('  ...nor on WordPress before 5.8, which has no WebP MIME type', Admin::webpWorks(), false);
Imagick::$formats = ['PDF'];
check('the format setting says so when it cannot', false !== strpos($view, "<?php if (!\\Rapls\\PDFImageCreator\\Admin::webpWorks()) : ?>"), true);

echo "\n=== The settings save, notices and rechecks (review after 1.4.31) ===\n";
$admin = (new ReflectionClass(Admin::class))->newInstanceWithoutConstructor();
$settingsOf = new ReflectionProperty(Admin::class, 'settings');
if (PHP_VERSION_ID < 80100) { $settingsOf->setAccessible(true); }
$settingsOf->setValue($admin, new \Rapls\PDFImageCreator\Settings());
$GLOBALS['options']['rapls_pic_settings'] = ['max_width' => 800];
$kept = null;
try {
    $kept = $admin->sanitizeSettings('');
} catch (\Throwable $e) {
    $kept = get_class($e);
}
check('a save that is not a list keeps the settings as they are', [is_array($kept) ? $kept['max_width'] : $kept, is_array($kept) ? $kept['format'] : null], [800, 'jpeg']);
check('the notices\' own parameters come out of the address', $admin->removableQueryArgs(['other']), ['other', 'rapls_pic_generated', 'rapls_pic_error', 'rapls_pic_rechecked']);
$adminSource = (string) file_get_contents(RAPLS_PIC_PLUGIN_DIR . 'includes/Admin.php');
check('"Check this server again" also forgets where the ICC profiles are', false !== strpos($adminSource, "(new \\Rapls\\PDFImageCreator\\Engine\\ColorProfile())->flushCache();"), true);
$pluginSource = (string) file_get_contents(RAPLS_PIC_PLUGIN_DIR . 'includes/Plugin.php');
check('an update forgets what the previous version measured about the server', false !== strpos($pluginSource, "foreach (\\Rapls\\PDFImageCreator\\Engine\\ImagickEngine::probeTransients() as \$transient) {\n            delete_transient(\$transient);"), true);
$inserterSource = (string) file_get_contents(RAPLS_PIC_PLUGIN_DIR . 'includes/Inserter.php');
check('no insert data is added to every PDF in the media modal', [false !== strpos($inserterSource, 'wp_prepare_attachment_for_js'), false !== strpos($inserterSource, 'picInsertHtml')], [true, false]);

// Verification of the change: the template's placeholders are escaped, so a
// title or URL with a quote in it cannot close the attribute it sits in.
if (!function_exists('esc_attr')) { function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); } }
if (!function_exists('esc_url')) { function esc_url($s) { return str_replace(['"', "'", '<', '>'], ['%22', '%27', '%3C', '%3E'], (string) $s); } }
if (!function_exists('wp_kses_post')) { function wp_kses_post($s) { return $s; } }
require_once RAPLS_PIC_PLUGIN_DIR . 'includes/Generator.php';
require_once RAPLS_PIC_PLUGIN_DIR . 'includes/Inserter.php';
$GLOBALS['options'][\Rapls\PDFImageCreator\Settings::OPTION_NAME] = ['custom_html' => '<a href="%PDF_URL%" data-a="%ATTACHMENT_URL%" title="%TITLE%"><img src="%THUMBNAIL_URL%"></a>'];
$inserter = (new ReflectionClass(\Rapls\PDFImageCreator\Inserter::class))->newInstanceWithoutConstructor();
$settingsProp = new ReflectionProperty(\Rapls\PDFImageCreator\Inserter::class, 'settings');
if (PHP_VERSION_ID < 80100) { $settingsProp->setAccessible(true); }
$settingsProp->setValue($inserter, new \Rapls\PDFImageCreator\Settings());
$custom = new ReflectionMethod(\Rapls\PDFImageCreator\Inserter::class, 'generateCustomHtml');
if (PHP_VERSION_ID < 80100) { $custom->setAccessible(true); }
$made = (string) $custom->invoke($inserter, 7, 'https://e.test/a"b.pdf', 'He said "hi"', 'https://e.test/?attachment_id=7"x', 'https://e.test/t"h.jpg');
unset($GLOBALS['options'][\Rapls\PDFImageCreator\Settings::OPTION_NAME]);
check('a custom template gets escaped placeholders (verification of the change)', [
    false !== strpos($made, 'title="He said &quot;hi&quot;"'),
    false !== strpos($made, 'href="https://e.test/a%22b.pdf"'),
    false !== strpos($made, 'data-a="https://e.test/?attachment_id=7%22x"'),
    false !== strpos($made, 'src="https://e.test/t%22h.jpg"'),
], [true, true, true, true]);

echo "\n=== Smaller fixes from the review after 1.4.31, checked so they stay ===\n";
// The getters take their defaults from DEFAULTS, the one place they live.
// Saved as null (an import, another plugin), which is where a getter's own
// default would show through.
$GLOBALS['options'][\Rapls\PDFImageCreator\Settings::OPTION_NAME] = array_fill_keys(array_keys(\Rapls\PDFImageCreator\Settings::DEFAULTS), null);
$fresh = new \Rapls\PDFImageCreator\Settings();
$defaults = \Rapls\PDFImageCreator\Settings::DEFAULTS;
check('with nothing usable saved, each getter answers DEFAULTS', [
    $fresh->getMaxWidth(), $fresh->getMaxHeight(), $fresh->getResolution(), $fresh->getQuality(), $fresh->getFormat(), $fresh->getPage(), $fresh->getInsertSize(), $fresh->getInsertLink(),
], [
    (int) $defaults['max_width'], (int) $defaults['max_height'], (int) $defaults['resolution'], (int) $defaults['quality'], (string) $defaults['format'], (int) $defaults['page'], (string) $defaults['insert_size'], (string) $defaults['insert_link'],
]);
unset($GLOBALS['options'][\Rapls\PDFImageCreator\Settings::OPTION_NAME]);
// The Media Library column's marks use the stylesheet's class names, and say
// themselves to a screen reader.
$libSource = (string) file_get_contents(RAPLS_PIC_PLUGIN_DIR . 'includes/MediaLibrary.php');
$css = (string) file_get_contents(RAPLS_PIC_PLUGIN_DIR . 'admin/css/admin.css');
check('the column marks are styled, and read out', [
    false !== strpos($libSource, 'class="rapls-pic-status rapls-pic-status-yes"'), false !== strpos($css, '.rapls-pic-status-yes'),
    false !== strpos($libSource, 'class="rapls-pic-status rapls-pic-status-no"'), false !== strpos($css, '.rapls-pic-status-no'),
    substr_count($libSource, '<span class="screen-reader-text">'),
], [true, true, true, true, 2]);
// The notice arguments are taken off the address after showing, and the
// Insert Size list shows the names in this plugin's own words.
check('removable_query_args is hooked', false !== strpos((string) file_get_contents(RAPLS_PIC_PLUGIN_DIR . 'includes/Admin.php'), "add_filter('removable_query_args', [\$this, 'removableQueryArgs'])"), true);
check('the Insert Size names go through image_size_names_choose', false !== strpos($view, "apply_filters('image_size_names_choose', ["), true);
// The block editor script asks only for what it uses.
preg_match("/'pic-block-editor',\s*RAPLS_PIC_PLUGIN_URL \. 'admin\/js\/block-editor\.js',.*?\[([^\]]*)\]/s", $libSource, $deps);
check('the block editor script does not depend on wp-edit-post, wp-blocks or wp-dom-ready', [isset($deps[1]), (bool) preg_match('/wp-edit-post|wp-blocks|wp-dom-ready/', $deps[1] ?? '')], [true, false]);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
