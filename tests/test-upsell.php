<?php
/**
 * The four places that say the add-on exists (includes/Upsell.php).
 *
 * The rules are CLAUDE.md's "Telling people the add-on exists": four places,
 * one filter that removes all of them, the free route beside the paid one,
 * nothing sold that cannot work here, and D once, dismissed for good.
 */

declare(strict_types=1);

require __DIR__ . '/harness.php';

class Redirected extends Exception
{
    public string $to;

    public function __construct(string $to)
    {
        parent::__construct('redirect');
        $this->to = $to;
    }
}

function current_user_can($cap) { return $GLOBALS['caps'] ?? true; }
function add_action($tag, $cb, $p = 10, $a = 1) { return true; }
function esc_html_e($s, $d = null) { echo $s; }
function esc_html__($s, $d = null) { return $s; }
function esc_url($s) { return $s; }
function wp_nonce_url($url, $action = -1) { return $url . '&_wpnonce=test'; }
function add_query_arg($key, $value = null, $url = '') {
    if (is_array($key)) {
        return $value . '?' . http_build_query($key);
    }
    return 'https://example.test/wp-admin/?' . $key . '=' . $value;
}
function remove_query_arg($keys, $url = '') { return 'https://example.test/wp-admin/'; }
function check_admin_referer($action = -1, $q = '_wpnonce') { $GLOBALS['nonce_asked'][] = $action; if (!empty($GLOBALS['nonce_fails'])) { throw new Redirected('nonce-died'); } return true; }
function wp_safe_redirect($url, $status = 302) { throw new Redirected($url); }
function get_current_user_id() { return 7; }
function get_user_meta($id, $key, $single = false) { return $GLOBALS['user_meta'][$id][$key] ?? ($single ? '' : []); }
function update_user_meta($id, $key, $value) { $GLOBALS['user_meta'][$id][$key] = $value; return true; }

if (!defined('RAPLS_PIC_PLUGIN_BASENAME')) {
    define('RAPLS_PIC_PLUGIN_BASENAME', 'rapls-pdf-image-creator/rapls-pdf-image-creator.php');
}

require_once RAPLS_PIC_PLUGIN_DIR . 'includes/Upsell.php';

use Rapls\PDFImageCreator\Upsell;

$out = static function (callable $render): string {
    ob_start();
    $render();
    return (string) ob_get_clean();
};
$off = static function (): void {
    $GLOBALS['filters']['rapls_pdf_image_creator_show_upsell'] = ['__return_false_upsell'];
};
function __return_false_upsell() { return false; }
$on = static function (): void {
    unset($GLOBALS['filters']['rapls_pdf_image_creator_show_upsell']);
};

echo "=== A: the Plugins screen row ===\n";
$upsell = new Upsell();
$row = $upsell->pluginRowMeta(['Version 1'], RAPLS_PIC_PLUGIN_BASENAME);
check('a link in this plugin\'s row, campaign a', [count($row), false !== strpos($row[1], 'utm_campaign=a'), false !== strpos($row[1], 'raplsworks.com/rapls-pdf-image-creator-pro/')], [2, true, true]);
check('  ...not in another plugin\'s row', $upsell->pluginRowMeta(['x'], 'other/other.php'), ['x']);
check('  ...whatever another filter passed, passed on', $upsell->pluginRowMeta('not an array', RAPLS_PIC_PLUGIN_BASENAME), 'not an array');

echo "\n=== B: the Settings tab card ===\n";
$card = $out([Upsell::class, 'renderSettingsCard']);
check('says first that this page stays free, then what the add-on does and needs', [
    false !== strpos($card, 'Everything on this page stays free.'),
    strpos($card, 'stays free') < strpos($card, '<h3>'),
    false !== strpos($card, 'exec or proc_open'),
    false !== strpos($card, '14-day money-back'),
    false !== strpos($card, 'utm_campaign=b'),
], [true, true, true, true, true]);

echo "\n=== C: the Status tab, beside the free options ===\n";
$options = $out([Upsell::class, 'renderStatusOptions']);
check('three options at once, the add-on third, with why this plugin cannot', [
    substr_count($options, '<li>'),
    strpos($options, 'Ask your hosting provider') < strpos($options, 'optional add-on (paid)'),
    false !== strpos($options, 'WordPress.org does not allow plugins in its directory to start other programs'),
    false !== strpos($options, 'utm_campaign=c'),
], [3, true, true, true]);
// Whether this server forbids starting programs is read from ini_get(); the
// test process cannot change disable_functions, so the reading is tested
// on its own terms: both must be listed.
check('  ...the add-on is not sold where this server forbids starting programs (reading the setting)', Upsell::programsBlocked() === (in_array('exec', array_map('trim', explode(',', strtolower((string) ini_get('disable_functions')))), true) && in_array('proc_open', array_map('trim', explode(',', strtolower((string) ini_get('disable_functions')))), true)), true);

echo "\n=== E: one line on the Status tab where this server renders PDFs ===\n";
$note = $out([Upsell::class, 'renderStatusNote']);
check('a short line that says everything here works, campaign e', [false !== strpos($note, 'everything here works as it is'), false !== strpos($note, 'utm_campaign=e'), false !== strpos($note, '<h3>')], [true, true, true]);

echo "\n=== D: once, under the page-number field ===\n";
$GLOBALS['user_meta'] = [];
check('not while the page is the first', $out(static function () { Upsell::renderPageHint(0); }), '');
$hint = $out(static function () { Upsell::renderPageHint(2); });
check('  ...after it was changed: a line, a link and a way to dismiss it', [false !== strpos($hint, 'every PDF'), false !== strpos($hint, 'utm_campaign=d'), false !== strpos($hint, 'rapls_pic_dismiss_page_hint')], [true, true, true]);
$GLOBALS['caps'] = false;
check('  ...not to someone who cannot change the settings', $out(static function () { Upsell::renderPageHint(2); }), '');
$GLOBALS['caps'] = true;
$_GET['rapls_pic_dismiss_page_hint'] = '1';
$GLOBALS['nonce_fails'] = true;
try {
    $upsell->handleDismiss();
} catch (Redirected $e) {
}
check('  ...dismissing asks the nonce, and a failed one records nothing', [$GLOBALS['nonce_asked'] ?? [], $GLOBALS['user_meta'][7] ?? []], [['rapls_pic_dismiss_page_hint'], []]);
$GLOBALS['nonce_fails'] = false;
try {
    $upsell->handleDismiss();
} catch (Redirected $e) {
}
unset($_GET['rapls_pic_dismiss_page_hint']);
check('  ...dismissed for good', [$GLOBALS['user_meta'][7][Upsell::DISMISSED_META] ?? null, $out(static function () { Upsell::renderPageHint(2); })], [1, '']);
$GLOBALS['user_meta'] = [];

echo "\n=== the filter removes all four ===\n";
$off();
check('false from rapls_pdf_image_creator_show_upsell: nothing in any of the five', [
    $upsell->pluginRowMeta(['Version 1'], RAPLS_PIC_PLUGIN_BASENAME),
    $out([Upsell::class, 'renderSettingsCard']),
    $out([Upsell::class, 'renderStatusOptions']),
    $out(static function () { Upsell::renderPageHint(2); }),
    $out([Upsell::class, 'renderStatusNote']),
], [['Version 1'], '', '', '', '']);
$on();

echo "\n=== words ===\n";
$all = $card . $options . $hint . $note . implode('', $row);
check('no "upgrade to pro", "available in pro", "pro version"', preg_match('/upgrade\s+to\s+pro|available\s+in\s+(the\s+)?pro|\bpro\s+version\b/i', $all), 0);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
