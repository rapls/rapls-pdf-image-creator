<?php
/**
 * The one-time review request.
 *
 * Ported from rapls-passkey/tests/smoke-review-prompt.php along with the class
 * itself. The cases are the same ones, because the conditions are the same
 * conditions -- the only difference is what counts as "actually used it".
 *
 * Most of these are negative. A review request that appears when it should not
 * is worse than one that never appears at all: the plugin gets a bad review
 * for asking, from someone who could not make it work.
 */

declare(strict_types=1);

require __DIR__ . '/harness.php';

/** Redirects end in exit(); make them catchable so one case does not end the run. */
class Redirected extends Exception
{
    public string $to;

    public function __construct(string $to)
    {
        parent::__construct('redirect');
        $this->to = $to;
    }
}

class Screen
{
    public string $id;

    public function __construct(string $id)
    {
        $this->id = $id;
    }
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

function current_user_can($cap) { return $GLOBALS['caps'] ?? true; }
function get_current_screen() { return $GLOBALS['screen'] ?? null; }
function add_action($tag, $cb, $p = 10, $a = 1) { return true; }
function wp_json_encode($v) { return json_encode($v); }
function wp_add_inline_script($handle, $js) { $GLOBALS['inline'][] = $js; return true; }
function esc_html_e($s, $d = null) { echo $s; }
function esc_url($s) { return $s; }
function esc_attr($s) { return $s; }
function wp_nonce_url($url, $action = -1) { return $url . '&_wpnonce=test'; }
function add_query_arg($key, $value = null, $url = '') { return 'https://example.test/wp-admin/?' . $key . '=' . $value; }
function remove_query_arg($keys, $url = '') { return 'https://example.test/wp-admin/'; }
function check_admin_referer($action = -1, $q = '_wpnonce') { return true; }
function check_ajax_referer($action = -1, $q = false, $die = true) { return true; }
function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k)); }
function wp_unslash($v) { return $v; }
function wp_create_nonce($a = -1) { return 'nonce'; }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function wp_send_json_error($d = null, $s = null) { throw new Redirected('json-error'); }
function wp_send_json_success($d = null) { throw new Redirected('json-success'); }
function wp_redirect($url, $status = 302) { throw new Redirected($url); }
function wp_safe_redirect($url, $status = 302) { throw new Redirected($url); }

/** Just enough $wpdb to answer "has anything ever been generated". */
class FakeWpdb
{
    public string $postmeta = 'wp_postmeta';
    public $answer = 1;

    public function prepare($sql, ...$args) { return $sql; }
    public function get_var($sql) { return $this->answer; }
}

$GLOBALS['wpdb'] = new FakeWpdb();

require RAPLS_PIC_PLUGIN_DIR . 'includes/Settings.php';
require RAPLS_PIC_PLUGIN_DIR . 'includes/Admin.php';
require RAPLS_PIC_PLUGIN_DIR . 'includes/ReviewPrompt.php';

use Rapls\PDFImageCreator\Admin;
use Rapls\PDFImageCreator\ReviewPrompt;

/** Does the notice render under the current state? */
function shows(): bool
{
    ob_start();
    (new ReviewPrompt())->render();

    return '' !== trim((string) ob_get_clean());
}

/** The state in which it SHOULD appear. */
function ready(): void
{
    $GLOBALS['options'] = [
        ReviewPrompt::ACTIVATED_OPTION => gmdate('Y-m-d H:i:s', time() - (8 * DAY_IN_SECONDS)),
    ];
    $GLOBALS['caps'] = true;
    $GLOBALS['screen'] = new Screen('settings_page_' . Admin::PAGE_SLUG);
    $GLOBALS['filters'] = [];
    $GLOBALS['wpdb']->answer = 1;
    $_GET = [];
}

echo "--- 出す条件 ---\n";

ready();
check('すべての条件が揃えば表示する', shows(), true);

echo "\n--- 出さない条件 ---\n";

ready();
$GLOBALS['options'][ReviewPrompt::ACTIVATED_OPTION] = gmdate('Y-m-d H:i:s', time() - (6 * DAY_IN_SECONDS));
check('7日未満では表示しない', shows(), false);

ready();
$GLOBALS['wpdb']->answer = null;
check('サムネイルが1枚も作れていなければ表示しない', shows(), false);

ready();
$GLOBALS['screen'] = new Screen('dashboard');
check('ダッシュボードには出さない', shows(), false);

ready();
$GLOBALS['screen'] = new Screen('plugins');
check('プラグイン一覧には出さない', shows(), false);

ready();
$GLOBALS['screen'] = new Screen('options-general');
check('他プラグインの設定画面には出さない', shows(), false);

ready();
$GLOBALS['screen'] = new Screen('upload');
check('メディアライブラリにも出さない', shows(), false);

ready();
$GLOBALS['screen'] = null;
check('画面が判定できないときは出さない', shows(), false);

ready();
$GLOBALS['caps'] = false;
check('権限が無いユーザーには出さない', shows(), false);

ready();
$GLOBALS['options'][ReviewPrompt::OPTION] = 'done';
check('一度答えたら二度と出さない', shows(), false);

ready();
add_filter('rapls_pdf_image_creator_show_review_prompt', function () { return false; });
check('フィルターで完全に無効化できる', shows(), false);

ready();
unset($GLOBALS['options'][ReviewPrompt::ACTIVATED_OPTION]);
check('有効化日時が無ければ出さない（誤爆防止）', shows(), false);

echo "\n--- どのボタンでも決着する ---\n";

foreach (['go' => 'wordpress.org', 'did' => 'wp-admin', 'no' => 'wp-admin'] as $answer => $expect) {
    ready();
    $_GET = ['rapls_pic_review' => $answer];
    $went = '';

    try {
        (new ReviewPrompt())->handleDismiss();
    } catch (Redirected $e) {
        $went = $e->to;
    }

    check("「{$answer}」で決着し、二度と出ない", get_option(ReviewPrompt::OPTION, ''), 'done');
    check("  ...行き先が正しい（{$expect}）", false !== strpos($went, $expect), true);
}

echo "\n--- 閉じるボタンも決着させる ---\n";

ready();
try {
    (new ReviewPrompt())->handleAjaxDismiss();
} catch (Redirected $e) {
    // wp_send_json_success
}
check('閉じるボタンでも記録する', get_option(ReviewPrompt::OPTION, ''), 'done');

ready();
$GLOBALS['caps'] = false;
$sent = '';
try {
    (new ReviewPrompt())->handleAjaxDismiss();
} catch (Redirected $e) {
    $sent = $e->to;
}
check('  ...権限が無ければ受け付けない', $sent, 'json-error');
check('  ...記録も残さない', get_option(ReviewPrompt::OPTION, ''), '');

echo "\n--- 中身 ---\n";

ready();
ob_start();
(new ReviewPrompt())->render();
$html = (string) ob_get_clean();

check('レビューを書くリンクがある', false !== strpos($html, 'wordpress.org') || false !== strpos($html, 'rapls_pic_review=go'), true);
check('「もう書いた」の道がある', false !== strpos($html, 'rapls_pic_review=did'), true);
check('「今はいい」の道がある', false !== strpos($html, 'rapls_pic_review=no'), true);
check('閉じられる notice である', false !== strpos($html, 'is-dismissible'), true);
check('一度きりだと書いてある', false !== strpos($html, 'Asked once'), true);
check('閉じるボタンを拾う JS を出す', !empty($GLOBALS['inline']), true);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
