<?php
/**
 * Uninstalling cleans every site of a network, each by its own settings (R64-01).
 *
 * uninstall.php is run as WordPress runs it -- included once -- against a
 * stand-in network of two sites whose options and postmeta are kept apart.
 */

declare(strict_types=1);

define('WP_UNINSTALL_PLUGIN', 'rapls-pdf-image-creator/rapls-pdf-image-creator.php');
define('WP_LANG_DIR', sys_get_temp_dir());

$GLOBALS['blog'] = 1;
$GLOBALS['switches'] = [];
// Per site: options, postmeta rows [post_id, key, value], and what was deleted.
$GLOBALS['sites'] = [
    1 => ['options' => ['rapls_pic_settings' => ['keep_on_uninstall' => false], 'rapls_pic_version' => '1.4.19'], 'meta' => [[10, '_rapls_pic_thumbnail_id', '11'], [11, '_rapls_pic_is_thumbnail', '1'], [11, '_rapls_pic_source_pdf', '10']], 'deleted' => []],
    2 => ['options' => ['rapls_pic_settings' => ['keep_on_uninstall' => true], 'rapls_pic_version' => '1.4.19'], 'meta' => [[20, '_rapls_pic_thumbnail_id', '21'], [21, '_rapls_pic_is_thumbnail', '1'], [21, '_rapls_pic_source_pdf', '20']], 'deleted' => []],
];
$GLOBALS['user_meta_deleted'] = [];

function is_multisite() { return !empty($GLOBALS['multisite']); }
function get_sites($args = []) { return array_keys($GLOBALS['sites']); }
function switch_to_blog($id) { $GLOBALS['switches'][] = $GLOBALS['blog']; $GLOBALS['blog'] = (int) $id; return true; }
function restore_current_blog() { $GLOBALS['blog'] = array_pop($GLOBALS['switches']); return true; }
function &site() { return $GLOBALS['sites'][$GLOBALS['blog']]; }
function get_option($key, $default = false) { return site()['options'][$key] ?? $default; }
function delete_option($key) { unset(site()['options'][$key]); return true; }
function delete_transient($key) { return true; }
function wp_cache_delete($key, $group = '') { return true; }
function delete_metadata($type, $id, $key, $value = '', $all = false) { $GLOBALS['user_meta_deleted'][] = $key; return true; }
function wp_delete_attachment($id, $force = false) { site()['deleted'][] = (int) $id; return true; }

$GLOBALS['wpdb'] = new class {
    public $postmeta = 'wp_postmeta';
    public $last_error = '';
    public function get_col($sql) {
        if (false !== strpos($sql, "meta_key = '_rapls_pic_is_thumbnail'")) {
            return array_map(static function ($row) { return (string) $row[0]; }, array_values(array_filter(site()['meta'], static function ($row) { return '_rapls_pic_is_thumbnail' === $row[1]; })));
        }
        return [];   // nothing else uses the images
    }
    public function delete($table, $where) {
        site()['meta'] = array_values(array_filter(site()['meta'], static function ($row) use ($where) { return $row[1] !== $where['meta_key']; }));
        return 1;
    }
};

$pass = 0;
$fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    $ok = $got === $want;
    $ok ? $pass++ : $fail++;
    printf("%s %-62s got=%s want=%s\n", $ok ? 'ok  ' : 'FAIL', $label, json_encode($got), json_encode($want));
}

$GLOBALS['multisite'] = true;
require dirname(__DIR__) . '/uninstall.php';

check('site 1 (keep off): its generated image deleted', $GLOBALS['sites'][1]['deleted'], [11]);
check('site 2 (keep on): its generated image kept', $GLOBALS['sites'][2]['deleted'], []);
check('both sites: options gone', [array_keys($GLOBALS['sites'][1]['options']), array_keys($GLOBALS['sites'][2]['options'])], [[], []]);
check('both sites: markers gone', [$GLOBALS['sites'][1]['meta'], $GLOBALS['sites'][2]['meta']], [[], []]);
check('back on the site it started from', [$GLOBALS['blog'], $GLOBALS['switches']], [1, []]);
check('user flags removed once, for the network', count($GLOBALS['user_meta_deleted']), 2);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
