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
check('the notices\' own parameters come out of the address', $admin->removableQueryArgs(['other']), ['other', 'rapls_pic_generated', 'rapls_pic_error', 'rapls_pic_rechecked', 'rapls_pic_dismiss_engine_notice', 'rapls_pic_dismiss_color_notice']);
// Review of 1.4.32: dismissing the engine notice stays on the screen it was
// on -- another plugin's admin.php?page=..., Pages -- not on the bare file.
if (!function_exists('add_query_arg')) {
    function add_query_arg($key, $value = null, $url = null) {
        if (is_array($key)) {
            $url = (string) $value;
            foreach ($key as $k => $v) { $url = add_query_arg($k, $v, $url); }
            return $url;
        }
        $url = null === $url ? (string) ($_SERVER['REQUEST_URI'] ?? '') : (string) $url;
        return $url . (false === strpos($url, '?') ? '?' : '&') . $key . '=' . $value;
    }
}
if (!function_exists('delete_option')) { function delete_option($k) { unset($GLOBALS['options'][$k]); return true; } }
if (!function_exists('wp_nonce_url')) { function wp_nonce_url($url, $action) { return $url . '&_wpnonce=n'; } }
$dismissUrl = new ReflectionMethod(Admin::class, 'getEngineNoticeDismissUrl');
if (PHP_VERSION_ID < 80100) { $dismissUrl->setAccessible(true); }
$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=acme-tools';
$GLOBALS['pagenow'] = 'admin.php';
if (!function_exists('remove_query_arg')) {
    function remove_query_arg($keys, $url = null) {
        $url = null === $url ? (string) ($_SERVER['REQUEST_URI'] ?? '') : (string) $url;
        foreach ((array) $keys as $k) { $url = (string) preg_replace('/([?&])' . preg_quote($k, '/') . '=[^&]*&?/', '$1', $url); }
        return rtrim($url, '?&');
    }
}
check('the engine notice\'s dismiss link keeps the screen it is on', $dismissUrl->invoke($admin, 'no_engine'), '/wp-admin/admin.php?page=acme-tools&rapls_pic_dismiss_engine_notice=no_engine&_wpnonce=n');
// Review after 1.4.32: not the screen's own nonce and action -- on Plugins'
// "Delete" confirmation, this link's nonce took the place of the screen's.
$_SERVER['REQUEST_URI'] = '/wp-admin/plugins.php?action=delete-selected&checked=x&_wpnonce=screen';
$_GET = ['action' => 'delete-selected', 'checked' => 'x', '_wpnonce' => 'screen'];
$oneTimeUrl = $dismissUrl->invoke($admin, 'no_engine');
// ...but an action that is the screen itself stays: post.php without it is the Posts list.
$_SERVER['REQUEST_URI'] = '/wp-admin/post.php?post=5&action=edit';
$_GET = ['post' => '5', 'action' => 'edit'];
$editUrl = $dismissUrl->invoke($admin, 'no_engine');
$_GET = [];
check('  ...without the screen\'s own nonce, and its action only when that came with a nonce', [$oneTimeUrl, $editUrl], ['/wp-admin/plugins.php?checked=x&rapls_pic_dismiss_engine_notice=no_engine&_wpnonce=n', '/wp-admin/post.php?post=5&action=edit&rapls_pic_dismiss_engine_notice=no_engine&_wpnonce=n']);
unset($_SERVER['REQUEST_URI'], $GLOBALS['pagenow']);
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
// Strips script elements, as the real one does, so that its use can be seen.
if (!function_exists('wp_kses_post')) { function wp_kses_post($s) { return (string) preg_replace('#<script\b[^>]*>.*?</script>#is', '', (string) $s); } }
require_once RAPLS_PIC_PLUGIN_DIR . 'includes/Generator.php';
require_once RAPLS_PIC_PLUGIN_DIR . 'includes/Inserter.php';
$GLOBALS['options'][\Rapls\PDFImageCreator\Settings::OPTION_NAME] = ['custom_html' => '<a href="%PDF_URL%" data-a="%ATTACHMENT_URL%" title="%TITLE%"><img src="%THUMBNAIL_URL%"></a>'];
$inserter = (new ReflectionClass(\Rapls\PDFImageCreator\Inserter::class))->newInstanceWithoutConstructor();
$settingsProp = new ReflectionProperty(\Rapls\PDFImageCreator\Inserter::class, 'settings');
if (PHP_VERSION_ID < 80100) { $settingsProp->setAccessible(true); }
$settingsProp->setValue($inserter, new \Rapls\PDFImageCreator\Settings());
$custom = new ReflectionMethod(\Rapls\PDFImageCreator\Inserter::class, 'generateCustomHtml');
if (PHP_VERSION_ID < 80100) { $custom->setAccessible(true); }
$made = (string) $custom->invoke($inserter, 7, 'https://e.test/a"b.pdf', 'He said "hi"', 'https://e.test/?attachment_id=7"x', 'https://e.test/t"h.jpg', '', 'medium');
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

echo "\n=== Review of 1.4.32: the script only where it does something ===\n";
$enqueued = [];
if (!function_exists('wp_enqueue_style')) { function wp_enqueue_style($h, ...$a) { $GLOBALS['enqueued'][] = 'style:' . $h; } }
if (!function_exists('wp_enqueue_script')) { function wp_enqueue_script($h, ...$a) { $GLOBALS['enqueued'][] = 'script:' . $h; } }
if (!function_exists('wp_localize_script')) { function wp_localize_script($h, $n, $d) { $GLOBALS['enqueued'][] = 'data:' . $h; } }
if (!function_exists('wp_create_nonce')) { function wp_create_nonce($a) { return 'n'; } }
if (!function_exists('admin_url')) { function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; } }
if (!defined('RAPLS_PIC_PLUGIN_URL')) { define('RAPLS_PIC_PLUGIN_URL', 'https://example.test/wp-content/plugins/rapls-pdf-image-creator/'); }
if (!defined('RAPLS_PIC_VERSION')) { define('RAPLS_PIC_VERSION', '9.9.9'); }
$enqueueOn = static function (string $hook) use ($admin): array {
    $GLOBALS['enqueued'] = [];
    $admin->enqueueAssets($hook);
    return $GLOBALS['enqueued'];
};
check('the Media Library gets the stylesheet only; the settings page the script too', [$enqueueOn('upload.php'), in_array('script:rapls-pic-admin', $enqueueOn('settings_page_' . Admin::PAGE_SLUG), true), $enqueueOn('index.php')], [['style:rapls-pic-admin'], true, []]);
// Review of 1.4.34: admin_enqueue_scripts run by hand, with no argument.
$GLOBALS['enqueued'] = [];
try {
    $admin->enqueueAssets();
    $bare = $GLOBALS['enqueued'];
} catch (\Throwable $e) {
    $bare = get_class($e);
}
check('  ...and called with nothing, nothing and no fatal error', $bare, []);

echo "\n=== Review of 1.4.32: what an update clears, and when it says so ===\n";
if (!defined('RAPLS_PIC_VERSION')) { define('RAPLS_PIC_VERSION', '9.9.9'); }
if (!function_exists('add_option')) { function add_option($k, $v, $d = '', $a = null) { if (!isset($GLOBALS['options'][$k])) { $GLOBALS['options'][$k] = $v; } return true; } }
foreach (['includes/ReviewPrompt.php', 'includes/Engine/EngineInterface.php', 'includes/Engine/ColorProfile.php', 'includes/Engine/ConversionResult.php', 'includes/Engine/ImagickEngine.php', 'includes/Generator.php', 'includes/Plugin.php'] as $file) {
    require_once RAPLS_PIC_PLUGIN_DIR . $file;
}
$upgrade = new ReflectionMethod(\Rapls\PDFImageCreator\Plugin::class, 'maybeUpgrade');
if (PHP_VERSION_ID < 80100) { $upgrade->setAccessible(true); }
$plugin = (new ReflectionClass(\Rapls\PDFImageCreator\Plugin::class))->newInstanceWithoutConstructor();
$upgradeFrom = static function (string $version) use ($upgrade, $plugin): array {
    $GLOBALS['options'] = [\Rapls\PDFImageCreator\Plugin::VERSION_OPTION => $version, \Rapls\PDFImageCreator\Settings::OPTION_NAME => ['format' => 'jpeg']];
    $GLOBALS['transients'] = ['rapls_pic_icc_path_srgb' => '/old/sRGB.icc'];
    $upgrade->invoke($plugin);
    return [isset($GLOBALS['options'][\Rapls\PDFImageCreator\Plugin::COLOR_NOTICE_OPTION]), isset($GLOBALS['transients']['rapls_pic_icc_path_srgb'])];
};
check('an update from before the colour fix says so once; from it or after, not; profile paths are looked for again', [$upgradeFrom('1.0.9'), $upgradeFrom('1.1.0'), $upgradeFrom('1.4.20')], [[true, false], [false, false], [false, false]]);

echo "\n=== Review of 1.4.32: a failed regeneration says why ===\n";
if (!function_exists('current_user_can')) { function current_user_can($cap, ...$a) { return $GLOBALS['admin_can'] ?? true; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 1; } }
if (!function_exists('get_user_meta')) { function get_user_meta($u, $k, $s = false) { return ''; } }
if (!function_exists('esc_html__')) { function esc_html__($s, $d = null) { return $s; } }
if (!function_exists('esc_attr__')) { function esc_attr__($s, $d = null) { return $s; } }
if (!function_exists('esc_html_e')) { function esc_html_e($s, $d = null) { echo $s; } }
if (!function_exists('get_current_screen')) { function get_current_screen() { return null; } }
if (!function_exists('sanitize_key')) { function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)); } }
if (!function_exists('wp_unslash')) { function wp_unslash($v) { return $v; } }
$regenNotice = static function (string $code) use ($admin): string {
    $_GET = ['rapls_pic_error' => $code];
    // The site's last failure: another PDF's, in ImageMagick's words. Not shown.
    $GLOBALS['options']['rapls_pic_last_failure'] = ['code' => 'render_error', 'message' => "Imagick error: FailedToExecuteCommand `'gs' -f/home/site/public_html/wp-content/uploads/other.pdf' (1)"];
    ob_start();
    try {
        $notices = new ReflectionMethod(Admin::class, 'showRegenerateNotices');
        if (PHP_VERSION_ID < 80100) { $notices->setAccessible(true); }
        $notices->invoke($admin);
    } catch (\Throwable $e) {
        echo 'THREW ' . get_class($e) . ': ' . $e->getMessage();
    }
    $_GET = [];
    unset($GLOBALS['options']['rapls_pic_last_failure']);
    return (string) ob_get_clean();
};
$noticeHtml = $regenNotice('page_out_of_range');
check('the failure notice gives the reason and the way to the Status tab', [false !== strpos($noticeHtml, 'The chosen page is not in this PDF.'), false !== strpos($noticeHtml, '#tab-status'), false !== strpos($noticeHtml, 'THREW')], [true, true, false]);
// Review after 1.4.32: this attempt's reason in this plugin's words -- never
// ImageMagick's text, with its command line and the server's paths, and never
// the site's last failure, which may be another PDF's.
$serverNotice = $regenNotice('pdf_unsupported');
$uncheckedNotice = $regenNotice('error');
$plainNotice = $regenNotice('1');
check('  ...in its own words: no ImageMagick text or server path, and a server reason said as one', [
    false !== strpos($noticeHtml . $serverNotice . $plainNotice, 'public_html'),
    false !== strpos($serverNotice, 'This server cannot make PDF thumbnails at the moment.') && false !== strpos($uncheckedNotice, 'This server cannot make PDF thumbnails at the moment.'),
    false !== strpos($plainNotice, 'Failed to generate PDF thumbnail.'),
], [false, true, true]);
$GLOBALS['admin_can'] = false;
$authorNotice = $regenNotice('render_error');
unset($GLOBALS['admin_can']);
check('  ...someone who cannot manage options gets the reason without the Status tab link', [false !== strpos($authorNotice, 'ImageMagick could not render the page.'), false !== strpos($authorNotice, '#tab-status')], [true, false]);
// A translation is printed escaped too (review of 1.4.34).
$GLOBALS['translations']['ImageMagick could not render the page.'] = 'Le <b>rendu</b> a échoué.';
$translatedNotice = $regenNotice('render_error');
unset($GLOBALS['translations']);
check('  ...and the reason is escaped, translated or not', [false !== strpos($translatedNotice, 'Le &lt;b&gt;rendu&lt;/b&gt; a échoué.'), false !== strpos($translatedNotice, '<b>')], [true, false]);

echo "\n=== Review of 1.4.32: markup a screen reader and the stylesheet rely on ===\n";
$viewNow = (string) file_get_contents(RAPLS_PIC_PLUGIN_DIR . 'admin/views/settings-page.php');
$cssNow = (string) file_get_contents(RAPLS_PIC_PLUGIN_DIR . 'admin/css/admin.css');
check('every radio group names itself', [substr_count($viewNow, '<fieldset>'), substr_count($viewNow, '<legend class="screen-reader-text">')], [4, 4]);
check('links that open a new tab say so', [substr_count($viewNow, 'target="_blank"'), substr_count($viewNow, "(opens in a new tab)")], [2, 2]);
preg_match_all('/#(rapls-pic-[a-z-]+)/', $cssNow, $cssIds);
$missingIds = array_values(array_filter(array_unique($cssIds[1]), static function ($id) use ($viewNow) { return false === strpos($viewNow, 'id="' . $id . '"'); }));
check('every id the stylesheet styles is on the page', $missingIds, []);
check('no style for ids the page does not have', (bool) preg_match('/#pic-bulk-/', $cssNow), false);

echo "\n=== Review of 1.4.32: the notices' own buttons check the nonce and who is asking ===\n";
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($v) { return trim((string) $v); } }
if (!function_exists('sanitize_key')) { function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)); } }
if (!function_exists('wp_unslash')) { function wp_unslash($v) { return $v; } }
// sanitize_key() takes the colon out, as it would a real nonce's anything-but-[a-z0-9_-].
if (!function_exists('wp_verify_nonce')) { function wp_verify_nonce($n, $a) { return in_array($n, ['ok:' . $a, 'ok' . $a], true); } }
if (!function_exists('update_user_meta')) { function update_user_meta($u, $k, $v) { $GLOBALS['user_meta'][$k] = $v; return true; } }
if (!defined('RAPLS_PIC_ACTIVATION_NOTICE')) { define('RAPLS_PIC_ACTIVATION_NOTICE', 'rapls_pic_activation_notice'); }
final class AdminStopped extends \Exception {}
if (!function_exists('wp_safe_redirect')) { function wp_safe_redirect($u, $s = 302) { throw new AdminStopped($u); } }
$press = static function (string $method, array $get, bool $can = true) use ($admin): array {
    $GLOBALS['user_meta'] = [];
    $GLOBALS['transients'] = ['rapls_pic_pdf_read' => ['ok' => false]];
    $GLOBALS['admin_can'] = $can;
    $_GET = $get;
    $redirected = false;
    try {
        $admin->$method();
    } catch (AdminStopped $e) {
        $redirected = true;
    }
    $_GET = [];
    $GLOBALS['admin_can'] = true;
    return [$GLOBALS['user_meta'], isset($GLOBALS['transients']['rapls_pic_pdf_read']), $redirected];
};
check('dismissing either notice needs its own nonce', [
    $press('maybeDismissColorNotice', ['rapls_pic_dismiss_color_notice' => '1', '_wpnonce' => 'ok:rapls_pic_dismiss_engine_notice'])[0],
    $press('maybeDismissColorNotice', ['rapls_pic_dismiss_color_notice' => '1', '_wpnonce' => 'ok:rapls_pic_dismiss_color_notice'])[0] !== [],
    $press('maybeDismissEngineNotice', ['rapls_pic_dismiss_engine_notice' => 'x', '_wpnonce' => 'ok:rapls_pic_dismiss_color_notice'])[0],
    $press('maybeDismissEngineNotice', ['rapls_pic_dismiss_engine_notice' => 'x', '_wpnonce' => 'ok:rapls_pic_dismiss_engine_notice'])[0] !== [],
], [[], true, [], true]);
check('"Check this server again" needs manage_options and its nonce; then it forgets and goes back', [
    $press('maybeRecheckProbes', ['rapls_pic_recheck' => '1', '_wpnonce' => 'ok:rapls_pic_recheck'], false),
    $press('maybeRecheckProbes', ['rapls_pic_recheck' => '1', '_wpnonce' => 'ok:other']),
    $press('maybeRecheckProbes', ['rapls_pic_recheck' => '1', '_wpnonce' => 'ok:rapls_pic_recheck']),
], [[[], true, false], [[], true, false], [[], false, true]]);

echo "\n=== Review of 1.4.32: saved settings keep to their lists, and what is inserted is escaped ===\n";
if (!function_exists('get_intermediate_image_sizes')) { function get_intermediate_image_sizes() { return ['thumbnail', 'medium', 'large']; } }
if (!function_exists('absint')) { function absint($v) { return abs((int) $v); } }
if (!function_exists('sanitize_hex_color')) { function sanitize_hex_color($v) { return $v; } }
$saved = $admin->sanitizeSettings(['format' => 'gif', 'insert_size' => 'huge', 'custom_html' => '<script>steal()</script><p>%TITLE%</p>', 'bgcolor' => 'white']);
$savedOk = $admin->sanitizeSettings(['format' => 'webp', 'insert_size' => 'large']);
check('a format or size not on the list is the default; a template loses scripts', [$saved['format'], $saved['insert_size'], $saved['custom_html'], $savedOk['format'], $savedOk['insert_size']], ['jpeg', 'medium', '<p>%TITLE%</p>', 'webp', 'large']);
// in_array() without strict: true matched any non-empty string and was saved as true.
$savedTrue = $admin->sanitizeSettings(['format' => true, 'bgcolor' => true, 'insert_link' => true]);
check('  ...a true is not on any list', [$savedTrue['format'], $savedTrue['bgcolor'], $savedTrue['insert_link']], ['jpeg', 'white', 'file']);
if (!function_exists('get_post_mime_type')) { function get_post_mime_type($id) { return $GLOBALS['mime_of'][(int) $id] ?? false; } }
if (!function_exists('get_post_meta')) { function get_post_meta($id, $k, $single = false) { return $GLOBALS['meta_of'][(int) $id][$k] ?? ''; } }
if (!function_exists('get_post')) { function get_post($id) { return isset($GLOBALS['mime_of'][(int) $id]) ? (object) ['ID' => (int) $id, 'post_type' => 'attachment'] : null; } }
if (!function_exists('wp_get_attachment_url')) { function wp_get_attachment_url($id) { return 'https://example.test/' . (int) $id . '.pdf'; } }
if (!function_exists('get_the_title')) { function get_the_title($id) { return $GLOBALS['title_of'][(int) $id] ?? ''; } }
if (!function_exists('wp_get_attachment_image_url')) { function wp_get_attachment_image_url($id, $size = 'thumbnail') { return 'https://example.test/' . (int) $id . '-' . $size . '.jpg'; } }
if (!function_exists('get_attachment_link')) { function get_attachment_link($id) { return 'https://example.test/?attachment_id=' . (int) $id; } }
$generatorProp = new ReflectionProperty(\Rapls\PDFImageCreator\Inserter::class, 'generator');
if (PHP_VERSION_ID < 80100) { $generatorProp->setAccessible(true); }
$generatorProp->setValue($inserter, (new ReflectionClass(\Rapls\PDFImageCreator\Generator::class))->newInstanceWithoutConstructor());
$GLOBALS['mime_of'] = [70 => 'application/pdf', 71 => 'image/jpeg', 80 => 'application/pdf'];
$GLOBALS['meta_of'] = [70 => [\Rapls\PDFImageCreator\Generator::THUMBNAIL_META_KEY => 71]];
$GLOBALS['title_of'] = [70 => 'Plans <b>2026</b>'];
$GLOBALS['options'][\Rapls\PDFImageCreator\Settings::OPTION_NAME] = ['insert_type' => 'title', 'insert_link' => 'file'];
$settingsProp->setValue($inserter, new \Rapls\PDFImageCreator\Settings());
$titleLink = $inserter->filterMediaSendToEditor('core html', 70, []);
$noThumbnail = $inserter->filterMediaSendToEditor('core html', 80, []);
$GLOBALS['options'][\Rapls\PDFImageCreator\Settings::OPTION_NAME] = ['insert_type' => 'custom', 'custom_html' => '<p>%TITLE%</p><script>x()</script>'];
$settingsProp->setValue($inserter, new \Rapls\PDFImageCreator\Settings());
$customOut = $inserter->filterMediaSendToEditor('core html', 70, []);
unset($GLOBALS['options'][\Rapls\PDFImageCreator\Settings::OPTION_NAME]);
check('the title link escapes the title; a PDF with no thumbnail keeps core\'s HTML; a template\'s output is cleaned', [
    false !== strpos($titleLink, 'Plans &lt;b&gt;2026&lt;/b&gt;'),
    $noThumbnail,
    false !== strpos($customOut, '<script>'),
], [true, 'core html', false]);

echo "\n=== Review of 1.4.32: the engine notice is for administrators only ===\n";
$GLOBALS['admin_can'] = false;
ob_start();
try {
    $engineNotice = new ReflectionMethod(Admin::class, 'showEngineNotice');
    if (PHP_VERSION_ID < 80100) { $engineNotice->setAccessible(true); }
    $engineNotice->invoke($admin);
} catch (\Throwable $e) {
    echo 'went on to ask the server';
}
$GLOBALS['admin_can'] = true;
check('someone without manage_options is not told about the server', (string) ob_get_clean(), '');

echo "\n=== Review of 1.4.34: the settings page and its Support box ===\n";
$GLOBALS['admin_can'] = false;
ob_start();
try {
    $admin->renderSettingsPage();
    $drawn = (string) ob_get_clean();
} catch (\Throwable $e) {
    ob_end_clean();
    $drawn = get_class($e);
}
$GLOBALS['admin_can'] = true;
check('someone without manage_options gets no settings page', $drawn, '');
check('the Support box only while this server can make thumbnails', [
    Admin::showsSupportBox(['availability' => ['code' => 'ok']]),
    Admin::showsSupportBox(['availability' => ['code' => 'pdf_unsupported']]),
    Admin::showsSupportBox(['availability' => null]),
    Admin::showsSupportBox(null),
], [true, false, false, false]);
// Review of 1.4.35: and not while the last upload found the server unable,
// as the review request waits for.
$GLOBALS['options'][\Rapls\PDFImageCreator\Generator::UNAVAILABLE_OPTION] = ['time' => 1];
$afterFailedUpload = Admin::showsSupportBox(['availability' => ['code' => 'ok']]);
unset($GLOBALS['options'][\Rapls\PDFImageCreator\Generator::UNAVAILABLE_OPTION]);
check('  ...nor after an upload found it unable, as the review request', $afterFailedUpload, false);
check('  ...as the view asks it', substr_count($viewNow, '<?php if (\\Rapls\\PDFImageCreator\\Admin::showsSupportBox($capabilities)) : ?>'), 1);
check('the tabs are hidden only where JavaScript runs', [(bool) preg_match('/^\.rapls-pic-tab-content \{[^}]*display: none/m', $cssNow), false !== strpos($cssNow, ".js .rapls-pic-tab-content {\n    display: none;"), false !== strpos($cssNow, ".js .rapls-pic-tab-content.active {\n    display: block;")], [false, true, true]);

// Review of 1.4.35: without JavaScript, the buttons only admin.js answers
// did nothing when pressed. Each sits in a block the stylesheet hides where
// JavaScript does not run, after a <noscript> line that says what to use.
$jsBlocks = [];
preg_match_all('~<noscript>\s*<p class="description">(.*?)</p>\s*</noscript>\s*<p class="rapls-pic-needs-js">(.*?)</p>~s', $viewNow, $jsBlocks, PREG_SET_ORDER);
$buttonsInBlocks = [];
foreach ($jsBlocks as $block) {
    preg_match_all('~<button type="button" id="(rapls-pic-[a-z-]+)"~', $block[2], $ids);
    $buttonsInBlocks = array_merge($buttonsInBlocks, $ids[1]);
}
preg_match_all('~<button type="button" id="(rapls-pic-[a-z-]+)"~', $viewNow, $allButtons);
check('the buttons only JavaScript answers are each in a block hidden without it, after a <noscript> line', [
    count($jsBlocks),
    $buttonsInBlocks,
    array_values(array_diff(array_intersect($allButtons[1], ['rapls-pic-bulk-scan', 'rapls-pic-bulk-start', 'rapls-pic-bulk-stop', 'rapls-pic-refresh-stats']), $buttonsInBlocks)),
    count(array_filter($jsBlocks, static function (array $block): bool {
        return false !== strpos($block[1], 'Generate Thumbnail') && false !== strpos($block[1], "esc_html_e(");
    })),
    (bool) preg_match('~body:not\(\.js\) \.rapls-pic-needs-js \{\s*display: none;~', $cssNow),
    (bool) preg_match('~^\.rapls-pic-needs-js \{[^}]*display: none~m', $cssNow),
], [2, ['rapls-pic-bulk-scan', 'rapls-pic-bulk-start', 'rapls-pic-bulk-stop', 'rapls-pic-refresh-stats'], [], 2, true, false]);

// Review of 1.4.36: the Bulk tab's "Include existing" checkbox, read only by
// admin.js, and the number beside the quality slider, which only admin.js
// moves, are hidden without it too; the saved quality is said instead.
check('the Bulk options and the quality number are hidden without JavaScript', [
    (bool) preg_match('~<table class="form-table rapls-pic-needs-js">\s*<tr>\s*<th scope="row"><\?php esc_html_e\(\'Options\'.*?id="rapls-pic-include-existing"~s', $viewNow),
    (bool) preg_match('~<output class="rapls-pic-needs-js">.*?</output>\s*<noscript><span>.*?__\(\'Saved: %d\'~s', $viewNow),
], [true, true]);

// Mutation testing of 1.4.35: text straight after a closing tag that starts
// with "=" -- an "=" typed after the tag's ">" -- prints a stray "=" on the
// screen.
$strays = [];
foreach (token_get_all($viewNow) as $token) {
    if (is_array($token) && T_INLINE_HTML === $token[0] && preg_match('/^=/', $token[1])) {
        $strays[] = $token[2];
    }
}
check('the settings view prints no stray "=" after a PHP tag', $strays, []);

// The release's version, said in three places that must agree, with a
// changelog section and an upgrade notice WordPress.org will show (review of
// 1.4.35); and no "Domain Path" for a folder the zip leaves out.
$main = (string) file_get_contents(RAPLS_PIC_PLUGIN_DIR . 'rapls-pdf-image-creator.php');
$readme = (string) file_get_contents(RAPLS_PIC_PLUGIN_DIR . 'readme.txt');
preg_match('/^ \* Version:\s+(\S+)$/m', $main, $header);
preg_match("/define\\('RAPLS_PIC_VERSION', '([^']+)'\\);/", $main, $constant);
preg_match('/^Stable tag:\s*(\S+)$/m', $readme, $stable);
$version = $header[1] ?? '';
preg_match('/== Upgrade Notice ==\s*= ' . preg_quote($version, '/') . ' =\n(.+)\n/', $readme, $notice);
check('the version agrees in the header, the constant and the stable tag', [$version, $constant[1] ?? null, $stable[1] ?? null], [$version, $version, $version]);
check('  ...with a changelog section, and an upgrade notice of at most 300 characters',
    [false !== strpos($readme, "== Changelog ==\n= {$version} =\n"), isset($notice[1]) && strlen($notice[1]) <= 300],
    [true, true]);
check('no Domain Path header', false !== strpos($main, 'Domain Path:'), false);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
