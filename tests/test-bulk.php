<?php
/**
 * Bulk Generate reads the library a page at a time (Codex review of 1.4.25, 5)
 * and shortcode classes are cleaned one at a time (7).
 *
 *   php tests/test-bulk.php
 *
 * WP_Query is a stand-in over a list of PDF IDs that honours posts_where, so
 * the cursor BulkProcessor adds is what decides which page comes back. It
 * records every query, so a scan that asked for every PDF at once shows up.
 */

declare(strict_types=1);

namespace {
    define('RAPLS_PIC_PLUGIN_DIR', dirname(__DIR__) . '/');
    define('ABSPATH', sys_get_temp_dir() . '/');

    $GLOBALS['meta'] = [];
    $GLOBALS['posts'] = [];
    $GLOBALS['pdfs'] = [];
    $GLOBALS['files'] = [];
    $GLOBALS['filters'] = [];
    $GLOBALS['queries'] = [];
    $GLOBALS['primed'] = 0;
    $GLOBALS['evicted'] = 0;
    $GLOBALS['caps'] = ['manage_options' => true];

    function __($s, $d = null) { return $s; }
    function _n($one, $many, $n, $d = null) { return 1 === (int) $n ? $one : $many; }
    function absint($v) { return abs((int) $v); }
    function check_ajax_referer($action = -1, $q = false, $die = true) { return true; }
    function current_user_can($cap, ...$args) { return !empty($GLOBALS['caps'][$cap]); }
    function add_filter($tag, $cb, $prio = 10, $args = 1) { $GLOBALS['filters'][$tag][] = $cb; return true; }
    function remove_filter($tag, $cb, $prio = 10) {
        foreach ($GLOBALS['filters'][$tag] ?? [] as $i => $known) {
            if ($known === $cb) { unset($GLOBALS['filters'][$tag][$i]); }
        }
        return true;
    }
    function apply_filters($tag, $value, ...$args) {
        foreach ($GLOBALS['filters'][$tag] ?? [] as $cb) { $value = $cb($value, ...$args); }
        return $value;
    }
    function get_post($id) { return $GLOBALS['posts'][(int) $id] ?? null; }
    function get_post_meta($id, $key, $single = false) { return $GLOBALS['meta'][(int) $id][$key] ?? ''; }
    function delete_post_meta($id, $key) { unset($GLOBALS['meta'][(int) $id][$key]); return true; }
    function get_attached_file($id) { return $GLOBALS['files'][(int) $id] ?? false; }
    function update_postmeta_cache($ids) { $GLOBALS['primed'] += count($ids); return []; }
    function wp_using_ext_object_cache() { return !empty($GLOBALS['ext_cache']); }
    function wp_cache_delete($key, $group = '') { ++$GLOBALS['evicted']; return true; }
    function add_shortcode($tag, $cb) { $GLOBALS['shortcodes'][$tag] = $cb; }
    function shortcode_atts($pairs, $atts, $tag = '') { return array_merge($pairs, array_intersect_key((array) $atts, $pairs)); }
    function wp_get_attachment_image($id, $size, $icon, $attr) { return '<img class="' . $attr['class'] . '">'; }
    function wp_get_attachment_url($id) { return 'https://example.test/doc.pdf'; }
    function get_the_title($id) { return 'Doc'; }
    function esc_url($u) { return $u; }
    function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
    function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
    function sanitize_html_class($class, $fallback = '') {
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '', preg_replace('|%[a-fA-F0-9][a-fA-F0-9]|', '', (string) $class));
        return '' === $clean ? (string) $fallback : $clean;
    }

    final class JsonSent extends \Exception {
        public $success; public $data; public $status;
        public function __construct($success, $data, $status) { parent::__construct('json'); $this->success = $success; $this->data = $data; $this->status = $status; }
    }
    // The first answer is the one sent: core dies there, and the route's own
    // catch (Throwable) would otherwise turn this exception into a second one.
    function rapls_test_json($success, $d, $s) {
        $GLOBALS['json'] = $GLOBALS['json'] ?? [$success, $d];
        throw new JsonSent($success, $d, $s);
    }
    function wp_send_json_error($d = null, $s = null) { rapls_test_json(false, $d, $s); }
    function wp_send_json_success($d = null, $s = null) { rapls_test_json(true, $d, $s); }

    $GLOBALS['wpdb'] = new class {
        public $posts = 'wp_posts';
        public function prepare($sql, ...$args) { return vsprintf(str_replace('%d', '%d', $sql), array_map('intval', $args)); }
        public function get_results($sql) {
            $rows = [];
            foreach (array_count_values($GLOBALS['statuses'] ?? []) as $status => $n) {
                $rows[] = (object) ['post_status' => $status, 'n' => $n];
            }
            return $rows;
        }
    };

    // The stand-in: the PDFs past "ID > n" from posts_where, in ID order,
    // posts_per_page of them (-1 for all, as 1.4.25 asked).
    final class WP_Query {
        public $posts = [];
        public function __construct(array $args) {
            $GLOBALS['queries'][] = $args;
            $where = apply_filters('posts_where', '');
            $after = preg_match('/ID > (\d+)/', $where, $m) ? (int) $m[1] : 0;
            $ids = array_values(array_filter($GLOBALS['pdfs'], static function ($id) use ($after) { return $id > $after; }));
            sort($ids);
            $limit = (int) ($args['posts_per_page'] ?? 10);
            $this->posts = $limit < 0 ? $ids : array_slice($ids, 0, $limit);
        }
    }

    require RAPLS_PIC_PLUGIN_DIR . 'includes/FailureCode.php';
    require RAPLS_PIC_PLUGIN_DIR . 'includes/Settings.php';
    require RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ConversionResult.php';
    require RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/EngineInterface.php';
    require RAPLS_PIC_PLUGIN_DIR . 'includes/Generator.php';
    require RAPLS_PIC_PLUGIN_DIR . 'includes/BulkProcessor.php';
    require RAPLS_PIC_PLUGIN_DIR . 'includes/Plugin.php';

    use Rapls\PDFImageCreator\BulkProcessor;
    use Rapls\PDFImageCreator\Plugin;

    $pass = 0;
    $fail = 0;
    function check($label, $got, $want) {
        global $pass, $fail;
        $ok = $got === $want;
        $ok ? $pass++ : $fail++;
        printf("%s %-62s got=%s want=%s\n", $ok ? 'ok  ' : 'FAIL', $label,
            json_encode($got, JSON_UNESCAPED_SLASHES), json_encode($want, JSON_UNESCAPED_SLASHES));
    }

    $generator = (new ReflectionClass(\Rapls\PDFImageCreator\Generator::class))->newInstanceWithoutConstructor();
    $bulk = (new ReflectionClass(BulkProcessor::class))->newInstanceWithoutConstructor();
    $prop = new ReflectionProperty(BulkProcessor::class, 'generator');
    if (PHP_VERSION_ID < 80100) { $prop->setAccessible(true); }
    $prop->setValue($bulk, $generator);

    $call = static function (callable $route, array $post): array {
        $_POST = $post;
        $GLOBALS['json'] = null;
        try {
            $route();
        } catch (JsonSent $sent) {
        }
        return $GLOBALS['json'] ?? ['no answer', null];
    };

    // 450 PDFs, IDs 1001..1450 in steps of one; every third has a thumbnail.
    $library = static function (int $count, int $everyNth): void {
        $GLOBALS['pdfs'] = [];
        $GLOBALS['meta'] = [];
        $GLOBALS['posts'] = [];
        $GLOBALS['statuses'] = [];
        for ($i = 0; $i < $count; $i++) {
            $id = 1001 + $i;
            $GLOBALS['pdfs'][] = $id;
            $GLOBALS['statuses'][] = 'inherit';
            $GLOBALS['files'][$id] = '/uploads/doc-' . $id . '.pdf';
            if ($everyNth > 0 && 0 === $i % $everyNth) {
                $GLOBALS['meta'][$id]['_rapls_pic_thumbnail_id'] = 50000 + $id;
                $GLOBALS['posts'][50000 + $id] = (object) ['ID' => 50000 + $id];
            }
        }
    };

    // The scan the screen runs: page after page, counts carried along.
    $scan = static function (bool $include) use ($bulk, $call): array {
        $after = 0;
        $carried = ['total_pdfs' => 0, 'with_thumbnail' => 0];
        $pages = 0;
        do {
            [$ok, $data] = $call([$bulk, 'ajaxScan'], ['include_existing' => $include ? 1 : 0, 'after' => $after] + $carried);
            ++$pages;
            $after = (int) ($data['after'] ?? 0);
            $carried = ['total_pdfs' => $data['total_pdfs'] ?? 0, 'with_thumbnail' => $data['with_thumbnail'] ?? 0];
        } while ($ok && empty($data['done']) && $pages < 50);
        return [$ok, $data, $pages];
    };

    echo "--- Scan, a page at a time (Codex review of 1.4.25, 5) ---\n";

    $library(450, 3);
    $GLOBALS['queries'] = [];
    [$ok, $data, $pages] = $scan(false);
    check('450 PDFs are counted in 3 requests of at most 200', [$ok, $pages, $data['total_pdfs'], $data['with_thumbnail'], $data['total']], [true, 3, 450, 150, 300]);
    check('  ...no query asks for every PDF at once', array_values(array_unique(array_column($GLOBALS['queries'], 'posts_per_page'))), [BulkProcessor::PAGE]);
    check('  ...and the reply holds no list of PDFs', array_key_exists('pdfs', $data), false);
    check('  ...meta is read once per page, for the page', $GLOBALS['primed'], 450);
    check('  ...and let go of after each page (no persistent cache)', $GLOBALS['evicted'], 900);
    [, $data] = $scan(true);
    check('Including those with thumbnails, all 450 are to do', [$data['total'], $data['note']], [450, '']);

    $GLOBALS['ext_cache'] = true;
    $GLOBALS['evicted'] = 0;
    $scan(false);
    $GLOBALS['ext_cache'] = false;
    check('A persistent object cache is left alone', $GLOBALS['evicted'], 0);

    $library(400, 1);
    [, $data, $pages] = $scan(false);
    check('Exactly two full pages: a third, empty page says done', [$pages, $data['total_pdfs'], $data['total'], $data['retry']], [3, 400, 0, true]);
    check('  ...and the note offers to include them', false !== strpos($data['note'], 'All 400 PDFs already have thumbnails'), true);

    $library(0, 0);
    [, $data, $pages] = $scan(false);
    check('An empty library: one request, and the note says so', [$pages, $data['total'], false !== strpos($data['note'], 'There are no PDF files')], [1, 0, true]);

    $library(0, 0);
    $GLOBALS['statuses'] = ['inherit', 'inherit'];
    [, $data] = $scan(false);
    check('Rows the query did not see: the note says something filters it', false !== strpos($data['note'], 'The database has 2 PDF attachment(s) (inherit=2)'), true);

    $library(5, 0);
    $GLOBALS['caps'] = [];
    check('Scan: refused without manage_options', $call([$bulk, 'ajaxScan'], []), [false, ['message' => 'Permission denied.']]);
    check('Next: refused without manage_options', $call([$bulk, 'ajaxNext'], ['after' => 0])[0], false);
    check('Status: refused without manage_options', $call([$bulk, 'ajaxStatus'], [])[0], false);
    $GLOBALS['caps'] = ['manage_options' => true];

    echo "\n--- The next PDF to draw, from a cursor ---\n";

    $library(450, 3);
    check('The first PDF without a thumbnail', $call([$bulk, 'ajaxNext'], ['after' => 0])[1], ['pdf_id' => 1002, 'filename' => 'doc-1002.pdf', 'after' => 1002, 'done' => false]);
    check('  ...then the one after it, skipping those with thumbnails', $call([$bulk, 'ajaxNext'], ['after' => 1003])[1]['pdf_id'], 1005);
    check('  ...with force, the very next PDF', $call([$bulk, 'ajaxNext'], ['after' => 1003, 'force' => 1])[1]['pdf_id'], 1004);
    check('  ...past the last one: done', $call([$bulk, 'ajaxNext'], ['after' => 1450])[1], ['pdf_id' => null, 'filename' => '', 'after' => 1450, 'done' => true]);

    // 1,500 PDFs that all have thumbnails: one request looks through five
    // pages and says where it got to, rather than reading the whole library.
    $library(1500, 1);
    $GLOBALS['queries'] = [];
    $first = $call([$bulk, 'ajaxNext'], ['after' => 0])[1];
    check('Nothing to draw in five pages: says where it got to, not done', [$first['pdf_id'], $first['after'], $first['done'], count($GLOBALS['queries'])], [null, 2000, false, 5]);
    $second = $call([$bulk, 'ajaxNext'], ['after' => $first['after']])[1];
    check('  ...and the next request from there reaches the end', [$second['pdf_id'], $second['after'], $second['done']], [null, 2500, true]);

    echo "\n--- Status, a page at a time ---\n";

    $library(450, 3);
    $after = 0;
    $carried = ['total' => 0, 'with_thumbnail' => 0];
    $requests = 0;
    do {
        [, $data] = $call([$bulk, 'ajaxStatus'], ['after' => $after] + $carried);
        ++$requests;
        $after = $data['after'];
        $carried = ['total' => $data['total'], 'with_thumbnail' => $data['with_thumbnail']];
    } while (!$data['done'] && $requests < 10);
    check('The totals add up over three requests', [$requests, $data['total'], $data['with_thumbnail'], $data['without_thumbnail']], [3, 450, 150, 300]);
    check('getStats() counts the whole library, page by page', $bulk->getStats(), ['total' => 450, 'with_thumbnail' => 150, 'without_thumbnail' => 300]);

    echo "\n--- Shortcode classes, one at a time (Codex review of 1.4.25, 7) ---\n";

    check('Two classes stay two', Plugin::classList('alignleft shadow'), 'alignleft shadow');
    check('  ...whatever the spacing, each once', Plugin::classList("  a \t b\na  "), 'a b');
    check('  ...each cleaned on its own', Plugin::classList('ok x"onload=y <b>'), 'ok xonloady b');
    check('  ...nothing left is nothing', Plugin::classList(' "" '), '');

    // The three shortcodes that take a class, as a post would use them.
    $plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
    $gen = new ReflectionProperty(Plugin::class, 'generator');
    if (PHP_VERSION_ID < 80100) { $gen->setAccessible(true); }
    $gen->setValue($plugin, $generator);
    $register = new ReflectionMethod(Plugin::class, 'registerShortcodes');
    if (PHP_VERSION_ID < 80100) { $register->setAccessible(true); }
    $register->invoke($plugin);
    $GLOBALS['meta'][7]['_rapls_pic_thumbnail_id'] = 70;
    $GLOBALS['posts'][70] = (object) ['ID' => 70];
    $atts = ['id' => 7, 'class' => 'alignleft shadow'];
    check('[rapls_pdf_thumbnail class="alignleft shadow"]', ($GLOBALS['shortcodes']['rapls_pdf_thumbnail'])($atts), '<img class="alignleft shadow">');
    check('[rapls_pdf_clickable_thumbnail class="alignleft shadow"]', false !== strpos(($GLOBALS['shortcodes']['rapls_pdf_clickable_thumbnail'])($atts), '<img class="alignleft shadow">'), true);
    check('[rapls_pdf_download_link class="alignleft shadow x\"y"]', false !== strpos(($GLOBALS['shortcodes']['rapls_pdf_download_link'])(['id' => 7, 'class' => 'alignleft shadow x"y']), '<a href="https://example.test/doc.pdf" class="alignleft shadow xy" download>'), true);

    echo "\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}
