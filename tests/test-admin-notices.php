<?php
/**
 * The admin notices and the settings save, line by line (review after 1.4.33).
 *
 *   php tests/test-admin-notices.php
 *
 * Mutation testing found these lines could be changed or removed with every
 * suite still passing: the escaping of the server's own error text, the
 * absint() on the page number, the colour notice's conditions, the clean-up
 * when the server recovers, and the dismissal's own clean-up.
 */

require __DIR__ . '/harness.php';

if (!defined('RAPLS_PIC_VERSION')) { define('RAPLS_PIC_VERSION', '9.9.9'); }
define('RAPLS_PIC_ACTIVATION_NOTICE', 'rapls_pic_activation_notice');

function absint($v) { return abs((int) $v); }
function get_intermediate_image_sizes() { return ['thumbnail', 'medium', 'large']; }
function wp_kses_post($s) { return (string) $s; }
function current_user_can($cap, ...$a) { return !empty($GLOBALS['can'][$cap]); }
function get_current_user_id() { return 1; }
function get_user_meta($u, $k, $s = false) { return $GLOBALS['user_meta'][$k] ?? ''; }
function update_user_meta($u, $k, $v) { $GLOBALS['user_meta'][$k] = $v; return true; }
function get_current_screen() { return $GLOBALS['screen'] ?? null; }
function delete_option($k) { $GLOBALS['option_deletes'][] = $k; unset($GLOBALS['options'][$k]); return true; }
function add_option($k, $v, $d = '', $a = null) { if (!isset($GLOBALS['options'][$k])) { $GLOBALS['options'][$k] = $v; } return true; }
// Translated and escaped, as core's are: a notice that left either out was
// not caught (mutation testing of 1.4.39).
function esc_html__($s, $d = null) { return esc_html(__($s, $d)); }
function esc_url($s) { return str_replace('&', '&#038;', (string) $s); }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function add_query_arg($k, $v = null, $u = null) { return (string) $u . '&' . $k . '=' . $v; }
function remove_query_arg($k, $u = null) { return 'https://example.test/wp-admin/upload.php?x=1'; }
function wp_nonce_url($u, $a) { return $u . '&_wpnonce=n'; }
// As before WordPress 6.1: an array is a TypeError.
function sanitize_key($v) { if (!is_scalar($v)) { throw new TypeError('sanitize_key(): array given'); } return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)); }
function sanitize_text_field($v) { return trim((string) $v); }
function wp_unslash($v) { return $v; }
function wp_verify_nonce($n, $a) { return 'ok:' . $a === $n; }

foreach (['includes/Settings.php', 'includes/FailureCode.php', 'includes/Engine/EngineInterface.php', 'includes/Engine/ConversionResult.php', 'includes/Engine/ColorProfile.php', 'includes/Engine/ImagickEngine.php', 'includes/Generator.php', 'includes/ReviewPrompt.php', 'includes/Admin.php', 'includes/Plugin.php'] as $file) {
    require_once RAPLS_PIC_PLUGIN_DIR . $file;
}

use Rapls\PDFImageCreator\Admin;
use Rapls\PDFImageCreator\FailureCode;
use Rapls\PDFImageCreator\Generator;
use Rapls\PDFImageCreator\Plugin;
use Rapls\PDFImageCreator\Settings;

/** An engine that answers with whatever status the test sets. */
final class StatusEngine implements \Rapls\PDFImageCreator\Engine\EngineInterface
{
    public static $status = [];
    public function getName(): string { return 'stand-in'; }
    public function getDisplayName(): string { return 'Stand-in'; }
    public function isAvailable(): bool { return 'ok' === self::$status['code']; }
    public function getAvailabilityStatus(): array { return self::$status; }
    public function getRequirements(): array { return []; }
    public function convert(string $pdfPath, string $outputPath, array $options = []): \Rapls\PDFImageCreator\Engine\ConversionResult
    {
        return \Rapls\PDFImageCreator\Engine\ConversionResult::failure('no');
    }
}

$generator = (new ReflectionClass(Generator::class))->newInstanceWithoutConstructor();
$engines = new ReflectionProperty(Generator::class, 'engines');
if (PHP_VERSION_ID < 80100) { $engines->setAccessible(true); }
$engines->setValue($generator, ['stand-in' => new StatusEngine()]);

$admin = (new ReflectionClass(Admin::class))->newInstanceWithoutConstructor();
foreach (['settings' => new Settings(), 'generator' => $generator] as $name => $value) {
    $prop = new ReflectionProperty(Admin::class, $name);
    if (PHP_VERSION_ID < 80100) { $prop->setAccessible(true); }
    $prop->setValue($admin, $value);
}

$private = static function (string $method) use ($admin): string {
    $m = new ReflectionMethod(Admin::class, $method);
    if (PHP_VERSION_ID < 80100) { $m->setAccessible(true); }
    ob_start();
    $m->invoke($admin);
    return (string) ob_get_clean();
};

echo "\n=== The settings save keeps every value to its list ===\n";
$GLOBALS['can'] = ['manage_options' => true];
$pages = array_map(static function ($page) use ($admin) { return $admin->sanitizeSettings(['page' => $page])['page']; }, ['abc', '-3', '2.7', 4]);
check('the page is a whole number: abc, -3, 2.7, 4', $pages, [0, 3, 2, 4]);
$low = $admin->sanitizeSettings(['max_width' => 5, 'max_height' => 9000, 'resolution' => 10, 'quality' => 5]);
$high = $admin->sanitizeSettings(['max_width' => 9000, 'max_height' => 5, 'resolution' => 1000, 'quality' => 101]);
check('sizes, resolution and quality keep to their ranges', [
    [$low['max_width'], $low['max_height'], $low['resolution'], $low['quality']],
    [$high['max_width'], $high['max_height'], $high['resolution'], $high['quality']],
], [[100, 4096, 72, 10], [4096, 100, 600, 100]]);
$odd = $admin->sanitizeSettings(['bgcolor' => 'red', 'insert_type' => 'iframe', 'insert_link' => 'javascript', 'auto_generate' => '', 'keep_on_uninstall' => '1', 'insert_size' => 'huge']);
$fine = $admin->sanitizeSettings(['bgcolor' => 'transparent', 'insert_type' => 'title', 'insert_link' => 'none', 'format' => 'png']);
check('a background, insert type, link or size not on the list is the default', [$odd['bgcolor'], $odd['insert_type'], $odd['insert_link'], $odd['insert_size'], $odd['auto_generate'], $odd['keep_on_uninstall']], ['white', 'image', 'file', 'medium', false, true]);
check('  ...one on the list is kept', [$fine['bgcolor'], $fine['insert_type'], $fine['insert_link'], $fine['format']], ['transparent', 'title', 'none', 'png']);

// A key not in the input was checked as its default, then read from the
// input anyway: null was saved.
$partial = $admin->sanitizeSettings(['page' => 1]);
check('a save that leaves keys out keeps their defaults, not null', [$partial['format'], $partial['bgcolor'], $partial['insert_type'], $partial['insert_size'], $partial['insert_link']], ['jpeg', 'white', 'image', 'medium', 'file']);
// Review after 1.4.37: what is saved stays where a save leaves it out; a
// checkbox the settings form leaves out is one unticked.
$GLOBALS['options'][Settings::OPTION_NAME] = ['format' => 'png', 'set_featured' => true, 'keep_on_uninstall' => true, 'auto_generate' => true, 'display_thumbnail_icon' => true, 'hide_generated_images' => true];
$fromCode = $admin->sanitizeSettings(['page' => 2]);
$fromForm = $admin->sanitizeSettings(['page' => 2, Admin::FORM_MARKER => '1']);
unset($GLOBALS['options'][Settings::OPTION_NAME]);
check('a save of a few keys keeps the rest as saved; the form\'s leaves every unticked box off', [
    [$fromCode['format'], $fromCode['set_featured'], $fromCode['keep_on_uninstall'], $fromCode['auto_generate'], $fromCode['display_thumbnail_icon'], $fromCode['hide_generated_images'], $fromCode['page']],
    [$fromForm['format'], $fromForm['set_featured'], $fromForm['keep_on_uninstall'], $fromForm['auto_generate'], $fromForm['display_thumbnail_icon'], $fromForm['hide_generated_images'], array_key_exists(Admin::FORM_MARKER, $fromForm)],
], [['png', true, true, true, true, true, 2], ['png', false, false, false, false, false, false]]);

echo "\n=== A failure's reason, in this plugin's words ===\n";
check('a code with a label says it', Admin::failureReason(FailureCode::PAGE_OUT_OF_RANGE), 'The chosen page is not in this PDF.');
check('  ...a server code says the server cannot, now', [Admin::failureReason(FailureCode::NO_EXTENSION), Admin::failureReason(FailureCode::ERROR)], ['This server cannot make PDF thumbnails at the moment.', 'This server cannot make PDF thumbnails at the moment.']);
check('  ...a code it does not know says nothing more', [Admin::failureReason('1'), Admin::failureReason('')], ['', '']);
$_GET = ['rapls_pic_error' => '1'];
$plain = $private('showRegenerateNotices');
$_GET = ['rapls_pic_error' => FailureCode::PDF_BLOCKED_BY_POLICY];
$server = $private('showRegenerateNotices');
// Review of 1.4.35: an array in the address is no reason, and no warning.
$_GET = ['rapls_pic_error' => ['x']];
set_error_handler(static function (int $no, string $message): bool { throw new \ErrorException($message, 0, $no); });
try {
    $arrayed = $private('showRegenerateNotices');
} catch (\Throwable $e) {
    $arrayed = get_class($e) . ': ' . $e->getMessage();
}
restore_error_handler();
$_GET = [];
check('an array for the code: the plain notice, no warning', [false !== strpos($arrayed, 'Failed to generate PDF thumbnail.'), false !== strpos($arrayed, 'cannot make PDF thumbnails')], [true, false]);
check('the regeneration notice for code 1 does not blame the server; for policy it does', [
    false !== strpos($plain, 'Failed to generate PDF thumbnail.'),
    false !== strpos($plain, 'cannot make PDF thumbnails'),
    false !== strpos($server, 'cannot make PDF thumbnails'),
], [true, false, true]);

echo "\n=== The engine notice ===\n";
$broken = ['code' => FailureCode::PDF_BLOCKED_BY_POLICY, 'label' => 'PDF <u>blocked</u>', 'summary' => 'Policy <i>says</i> no.', 'action' => 'Ask the host.', 'detail' => 'not authorized `<b>/tmp/x.pdf</b>\''];
StatusEngine::$status = $broken;
$GLOBALS['screen'] = (object) ['id' => 'upload'];
$GLOBALS['user_meta'] = [];
$out = $private('showEngineNotice');
check('ImageMagick\'s own text is shown as text', [false !== strpos($out, '&lt;b&gt;/tmp/x.pdf&lt;/b&gt;'), false !== strpos($out, '<b>')], [true, false]);
check('  ...its label and summary are escaped too (review after 1.4.38)', [false !== strpos($out, 'PDF &lt;u&gt;blocked&lt;/u&gt;'), false !== strpos($out, 'Policy &lt;i&gt;says&lt;/i&gt; no.'), false !== strpos($out, '<i>') || false !== strpos($out, '<u>')], [true, true, false]);

// On another screen it speaks only after an upload failed or just after
// activation, and not once this reason has been dismissed.
$GLOBALS['screen'] = (object) ['id' => 'dashboard'];
$GLOBALS['options'][Generator::UNAVAILABLE_OPTION] = FailureCode::PDF_BLOCKED_BY_POLICY;
$shown = '' !== $private('showEngineNotice');
$GLOBALS['user_meta']['rapls_pic_engine_notice_dismissed'] = FailureCode::PDF_BLOCKED_BY_POLICY;
$dismissed = '' !== $private('showEngineNotice');
$GLOBALS['user_meta']['rapls_pic_engine_notice_dismissed'] = FailureCode::NO_EXTENSION;
$otherDismissed = '' !== $private('showEngineNotice');
$GLOBALS['screen'] = (object) ['id' => 'upload'];
$GLOBALS['user_meta']['rapls_pic_engine_notice_dismissed'] = FailureCode::PDF_BLOCKED_BY_POLICY;
$ownScreen = '' !== $private('showEngineNotice');
check('elsewhere: shown after a failed upload, not once dismissed, again for another reason; always on its own screens', [$shown, $dismissed, $otherDismissed, $ownScreen], [true, false, true, true]);

// The server recovered: what was recorded about the failure goes.
StatusEngine::$status = ['code' => 'ok', 'label' => '', 'summary' => '', 'action' => '', 'detail' => ''];
$GLOBALS['transients'][RAPLS_PIC_ACTIVATION_NOTICE] = FailureCode::NO_EXTENSION;
$quiet = $private('showEngineNotice');
check('once the server can draw, the notice goes and so do the records of the failure', [$quiet, isset($GLOBALS['options'][Generator::UNAVAILABLE_OPTION]), isset($GLOBALS['transients'][RAPLS_PIC_ACTIVATION_NOTICE])], ['', false, false]);
// ...and with nothing recorded, nothing is deleted: this runs on every admin
// page, and a delete asks the database whether or not there is anything.
$GLOBALS['transient_deletes'] = [];
$GLOBALS['option_deletes'] = [];
$private('showEngineNotice');
check('  ...on the next page, with nothing left, nothing is deleted', [$GLOBALS['transient_deletes'], $GLOBALS['option_deletes']], [[], []]);

echo "\n=== Dismissing the engine notice ===\n";
$GLOBALS['transients'][RAPLS_PIC_ACTIVATION_NOTICE] = 'x';
$_GET = ['rapls_pic_dismiss_engine_notice' => 'PDF_Blocked<script>', '_wpnonce' => 'ok:rapls_pic_dismiss_engine_notice'];
$admin->maybeDismissEngineNotice();
$_GET = [];
check('the reason is stored as a key, and the activation notice goes with it', [$GLOBALS['user_meta']['rapls_pic_engine_notice_dismissed'], isset($GLOBALS['transients'][RAPLS_PIC_ACTIVATION_NOTICE])], ['pdf_blockedscript', false]);
// An array in the address (review of 1.4.36): no TypeError, nothing stored.
$GLOBALS['user_meta']['rapls_pic_engine_notice_dismissed'] = 'before';
$_GET = ['rapls_pic_dismiss_engine_notice' => ['x'], '_wpnonce' => 'ok:rapls_pic_dismiss_engine_notice'];
try {
    $admin->maybeDismissEngineNotice();
    $arrayAnswer = $GLOBALS['user_meta']['rapls_pic_engine_notice_dismissed'];
} catch (\Throwable $e) {
    $arrayAnswer = get_class($e);
}
$_GET = [];
check('  ...an array given for it: no fatal error, and nothing changed', $arrayAnswer, 'before');

echo "\n=== The colour notice ===\n";
$colour = static function (bool $admin, $option, string $dismissed) use ($private): bool {
    $GLOBALS['can'] = ['manage_options' => $admin];
    $GLOBALS['options'][Plugin::COLOR_NOTICE_OPTION] = $option;
    $GLOBALS['user_meta']['rapls_pic_color_notice_dismissed'] = $dismissed;
    $out = $private('showColorUpdateNotice');
    $GLOBALS['can'] = ['manage_options' => true];
    return false !== strpos($out, 'Color conversion has been improved');
};
check('shown to an administrator after the update, until dismissed', [
    $colour(true, '1', ''),
    $colour(false, '1', ''),
    $colour(true, '1', '1'),
    $colour(true, false, ''),
], [true, false, false, false]);
// Mutation testing of 1.4.39: its links and words escaped, translated or not.
$GLOBALS['can'] = ['manage_options' => true];
$GLOBALS['options'][Plugin::COLOR_NOTICE_OPTION] = '1';
$GLOBALS['user_meta']['rapls_pic_color_notice_dismissed'] = '';
$GLOBALS['translations']['Regenerate thumbnails'] = 'Re<b>generate</b>';
$colourOut = $private('showColorUpdateNotice');
unset($GLOBALS['translations']['Regenerate thumbnails']);
check('  ...its links and its words escaped', [substr_count($colourOut, '&#038;'), false !== strpos($colourOut, 'Re&lt;b&gt;generate&lt;/b&gt;'), false !== strpos($colourOut, '<b>')], [2 * substr_count($colourOut, 'href='), true, false]);

echo "\n=== Asked on every request, so read with the rest ===\n";
$upgrade = new ReflectionMethod(Plugin::class, 'maybeUpgrade');
if (PHP_VERSION_ID < 80100) { $upgrade->setAccessible(true); }
$GLOBALS['options'] = [Plugin::VERSION_OPTION => '1.4.33', Settings::OPTION_NAME => []];
$GLOBALS['autoload'] = [];
$upgrade->invoke((new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor());
$main = (string) file_get_contents(RAPLS_PIC_PLUGIN_DIR . 'rapls-pdf-image-creator.php');
check('the version is written autoloaded, on update and on activation', [$GLOBALS['options'][Plugin::VERSION_OPTION], $GLOBALS['autoload'][Plugin::VERSION_OPTION] ?? null, false !== strpos($main, "add_option('rapls_pic_version', RAPLS_PIC_VERSION, '', true)")], ['9.9.9', true, true]);
// Review after 1.4.37: activation stamped the version on every site, and one
// updated by replacing the folder and activating again was never upgraded.
check('  ...on activation only for a fresh install, whose settings were not there',
    [1 === preg_match('/\$rapls_pic_fresh = !get_option\(.*Settings::OPTION_NAME\);/', $main), false !== strpos($main, "if (\$rapls_pic_fresh && false === get_option('rapls_pic_version', false)) {"), false !== strpos($main, "update_option('rapls_pic_version'")],
    [true, true, false]);
// Review after 1.4.37: a version that will not be written leaves the probes
// alone, instead of measuring them afresh on every request.
$GLOBALS['options'] = [Plugin::VERSION_OPTION => '1.4.33', Settings::OPTION_NAME => []];
$GLOBALS['transient_deletes'] = [];
$GLOBALS['refuse_option'] = Plugin::VERSION_OPTION;
$upgrade->invoke((new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor());
unset($GLOBALS['refuse_option']);
check('  ...not written: nothing else is done, to be tried on the next request', [$GLOBALS['options'][Plugin::VERSION_OPTION], $GLOBALS['transient_deletes']], ['1.4.33', []]);
check('the activation error\'s title is translated', false !== strpos($main, "esc_html__('Plugin Activation Error', 'rapls-pdf-image-creator')"), true);

echo "\n=== What the Status tab says works is readable ===\n";
$css = (string) file_get_contents(RAPLS_PIC_PLUGIN_DIR . 'admin/css/admin.css');
$contrast = static function (string $hex): float {
    $lum = static function (string $hex): float {
        $c = array_map(static function ($i) use ($hex) { $v = hexdec(substr($hex, $i, 2)) / 255; return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4; }, [0, 2, 4]);
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    };
    return round((1.05) / ($lum($hex) + 0.05), 2);
};
$colours = [];
foreach (['ok', 'error'] as $kind) {
    preg_match('/\.rapls-pic-status-' . $kind . '\s*\{[^}]*color:\s*#([0-9a-f]{6})/i', $css, $m);
    $colours[] = isset($m[1]) && $contrast($m[1]) >= 4.5;
}
check('"working" and "not working" are at least 4.5:1 on white', $colours, [true, true]);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
