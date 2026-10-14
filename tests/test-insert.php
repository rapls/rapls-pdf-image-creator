<?php
/**
 * What goes into a post: the shortcodes and "Insert into post" (review of 1.4.33).
 *
 *   php tests/test-insert.php
 *
 * The shortcodes are registered as Plugin registers them and called as a
 * post would call them. esc_url(), esc_attr() and esc_html() here change what
 * they are given, so a value printed without them shows in the output.
 */

declare(strict_types=1);

namespace {
    define('RAPLS_PIC_PLUGIN_DIR', dirname(__DIR__) . '/');

    $GLOBALS['meta'] = [];
    $GLOBALS['posts'] = [];
    $GLOBALS['mime_of'] = [];
    $GLOBALS['status_of'] = [];
    $GLOBALS['parent_of'] = [];
    $GLOBALS['password'] = [];
    $GLOBALS['can_read'] = [];
    $GLOBALS['options'] = [];

    function __($s, $d = null) { return $s; }
    function absint($v) { return abs((int) $v); }
    function apply_filters($tag, $value, ...$args) { return isset($GLOBALS['filtered'][$tag]) ? $GLOBALS['filtered'][$tag]($value, ...$args) : $value; }
    function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
    function get_post($id) { return $GLOBALS['posts'][(int) $id] ?? null; }
    function get_post_meta($id, $key, $single = false) { return $GLOBALS['meta'][(int) $id][$key] ?? ''; }
    function delete_post_meta($id, $key) { unset($GLOBALS['meta'][(int) $id][$key]); return true; }
    function get_post_mime_type($id) { return $GLOBALS['mime_of'][(int) $id] ?? false; }
    function get_post_status($id) { return $GLOBALS['status_of'][(int) $id] ?? 'publish'; }
    function get_post_status_object($status) { return in_array($status, ['publish', 'private', 'draft', 'future'], true) ? (object) ['public' => 'publish' === $status] : null; }
    function wp_get_post_parent_id($id) { return $GLOBALS['parent_of'][(int) $id] ?? 0; }
    function post_password_required($post = null) { return !empty($GLOBALS['password'][(int) $post]); }
    // As core's map_meta_cap() answers: read_post on a published post is plain
    // "read", whatever its password, so any logged-in user has it.
    function current_user_can($cap, ...$args) {
        $id = (int) ($args[0] ?? 0);
        if ('read_post' === $cap) {
            return 'publish' === get_post_status($id) ? !empty($GLOBALS['logged_in']) : !empty($GLOBALS['can_read'][$id]);
        }
        return 'edit_post' === $cap && !empty($GLOBALS['can_edit'][$id]);
    }
    function get_post_type($id) { return 99 === (int) $id ? false : ($GLOBALS['type_of'][(int) $id] ?? 'post'); }
    function get_intermediate_image_sizes() { return ['thumbnail', 'medium', 'large']; }
    function add_shortcode($tag, $cb) { $GLOBALS['shortcodes'][$tag] = $cb; }
    function shortcode_atts($pairs, $atts, $tag = '') { return array_merge($pairs, array_intersect_key((array) $atts, $pairs)); }
    function wp_get_attachment_image($id, $size = 'thumbnail', $icon = false, $attr = '') {
        if (!empty($GLOBALS['no_image_html'])) { return ''; }
        $out = '<img src="https://example.test/' . (int) $id . '.jpg"';
        foreach ((array) $attr as $name => $value) {
            $out .= ' ' . $name . '="' . esc_attr($value) . '"';
        }
        return $out . '>';
    }
    function wp_get_attachment_image_url($id, $size = 'thumbnail') { return $GLOBALS['image_url'] ?? 'https://example.test/' . (int) $id . '-' . $size . '.jpg'; }
    function wp_get_attachment_url($id) { return $GLOBALS['pdf_url'] ?? 'https://example.test/doc.pdf'; }
    function get_attachment_link($id) { return 'https://example.test/?attachment_id=' . (int) $id; }
    function get_the_title($id) { return $GLOBALS['title'] ?? 'Doc'; }
    // Stand-ins that leave a mark: a value printed without them keeps its quotes and brackets.
    function esc_url($u) { return str_replace(['"', "'", '<', '>', ' '], ['%22', '%27', '%3C', '%3E', '%20'], (string) $u); }
    function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
    function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
    function wp_kses_post($s) { return $s; }
    function sanitize_html_class($class, $fallback = '') {
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '', preg_replace('|%[a-fA-F0-9][a-fA-F0-9]|', '', (string) $class));
        return '' === $clean ? (string) $fallback : $clean;
    }

    require RAPLS_PIC_PLUGIN_DIR . 'includes/Settings.php';
    require RAPLS_PIC_PLUGIN_DIR . 'includes/Generator.php';
    require RAPLS_PIC_PLUGIN_DIR . 'includes/Plugin.php';
    require RAPLS_PIC_PLUGIN_DIR . 'includes/Inserter.php';

    use Rapls\PDFImageCreator\Generator;
    use Rapls\PDFImageCreator\Inserter;
    use Rapls\PDFImageCreator\Plugin;
    use Rapls\PDFImageCreator\Settings;

    $pass = 0;
    $fail = 0;
    function check($label, $got, $want) {
        global $pass, $fail;
        $ok = $got === $want;
        $ok ? $pass++ : $fail++;
        printf("%s %-62s got=%s want=%s\n", $ok ? 'ok  ' : 'FAIL', $label,
            json_encode($got, JSON_UNESCAPED_SLASHES), json_encode($want, JSON_UNESCAPED_SLASHES));
    }

    $generator = (new ReflectionClass(Generator::class))->newInstanceWithoutConstructor();
    $plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
    $gen = new ReflectionProperty(Plugin::class, 'generator');
    if (PHP_VERSION_ID < 80100) { $gen->setAccessible(true); }
    $gen->setValue($plugin, $generator);
    $register = new ReflectionMethod(Plugin::class, 'registerShortcodes');
    if (PHP_VERSION_ID < 80100) { $register->setAccessible(true); }
    $register->invoke($plugin);
    $shortcode = static function (string $tag, array $atts): string {
        return ($GLOBALS['shortcodes'][$tag])($atts);
    };

    // PDF 7, its thumbnail 70.
    $GLOBALS['mime_of'][7] = 'application/pdf';
    $GLOBALS['meta'][7]['_rapls_pic_thumbnail_id'] = 70;
    $GLOBALS['posts'][70] = (object) ['ID' => 70, 'post_type' => 'attachment'];

    echo "--- No class given: no class attribute ---\n";
    check('[rapls_pdf_thumbnail] keeps the default class', $shortcode('rapls_pdf_thumbnail', ['id' => 7]), '<img src="https://example.test/70.jpg" class="pic-thumbnail" alt="Doc">');
    check('[rapls_pdf_thumbnail class=""] too', $shortcode('rapls_pdf_thumbnail', ['id' => 7, 'class' => '  ']), '<img src="https://example.test/70.jpg" class="pic-thumbnail" alt="Doc">');
    check('[rapls_pdf_clickable_thumbnail] too', false !== strpos($shortcode('rapls_pdf_clickable_thumbnail', ['id' => 7]), 'class="pic-thumbnail"'), true);
    check('a class given replaces it', $shortcode('rapls_pdf_thumbnail', ['id' => 7, 'class' => 'a b']), '<img src="https://example.test/70.jpg" class="a b" alt="Doc">');

    // A size that a shortcode_atts_* filter hands back as a number (review
    // of 1.4.35): two of the four passed it on uncast, a TypeError in this
    // strict file, on the front end.
    $numbered = [];
    foreach (['rapls_pdf_thumbnail', 'rapls_pdf_thumbnail_url', 'rapls_pdf_clickable_thumbnail', 'rapls_pdf_download_link'] as $tag) {
        try {
            $numbered[$tag] = is_string($shortcode($tag, ['id' => 7, 'size' => 300]));
        } catch (\Throwable $e) {
            $numbered[$tag] = get_class($e);
        }
    }
    check('a size given as a number: no TypeError in any shortcode', $numbered, array_fill_keys(array_keys($numbered), true));

    echo "\n--- What a contributor writes is escaped ---\n";
    $clickable = $shortcode('rapls_pdf_clickable_thumbnail', ['id' => 7, 'target' => 'x" onmouseover="y']);
    check('target', [false !== strpos($clickable, 'onmouseover="y'), false !== strpos($clickable, 'target="x&quot; onmouseover=&quot;y"')], [false, true]);
    $download = $shortcode('rapls_pdf_download_link', ['id' => 7, 'text' => '<script>x()</script>', 'show_thumbnail' => 'false']);
    check('text', [false !== strpos($download, '<script>'), false !== strpos($download, '&lt;script&gt;x()&lt;/script&gt;')], [false, true]);
    $GLOBALS['title'] = 'A "quoted" <b>title</b>';
    check('the title, as alt and as link text', [
        false !== strpos($shortcode('rapls_pdf_thumbnail', ['id' => 7]), 'alt="A &quot;quoted&quot; &lt;b&gt;title&lt;/b&gt;"'),
        false !== strpos($shortcode('rapls_pdf_download_link', ['id' => 7, 'show_thumbnail' => 'false']), '>A &quot;quoted&quot; &lt;b&gt;title&lt;/b&gt;</span>'),
    ], [true, true]);
    unset($GLOBALS['title']);
    $GLOBALS['pdf_url'] = 'https://example.test/a"onclick="x.pdf';
    $GLOBALS['image_url'] = 'https://example.test/t"onerror="x.jpg';
    check('the PDF\'s and the image\'s addresses', [
        $shortcode('rapls_pdf_thumbnail_url', ['id' => 7]),
        false !== strpos($shortcode('rapls_pdf_clickable_thumbnail', ['id' => 7]), 'href="https://example.test/a%22onclick=%22x.pdf"'),
        false !== strpos($shortcode('rapls_pdf_download_link', ['id' => 7]), 'href="https://example.test/a%22onclick=%22x.pdf"'),
    ], ['https://example.test/t%22onerror=%22x.jpg', true, true]);
    unset($GLOBALS['pdf_url'], $GLOBALS['image_url']);

    echo "\n--- Only a PDF this visitor could open ---\n";
    $all = static function () use ($shortcode): array {
        return [
            $shortcode('rapls_pdf_thumbnail', ['id' => 7]) !== '',
            $shortcode('rapls_pdf_thumbnail_url', ['id' => 7]) !== '',
            $shortcode('rapls_pdf_clickable_thumbnail', ['id' => 7]) !== '',
            $shortcode('rapls_pdf_download_link', ['id' => 7]) !== '',
        ];
    };
    check('attached to a published post, or to none: shown', $all(), [true, true, true, true]);
    foreach (['private', 'draft', 'future'] as $status) {
        $GLOBALS['status_of'][7] = $status;
        check("attached to a $status post: nothing", $all(), [false, false, false, false]);
        $GLOBALS['can_read'][7] = true;
        check("  ...unless this visitor may read it", $all(), [true, true, true, true]);
        $GLOBALS['can_read'] = [];
    }
    $GLOBALS['status_of'][7] = 'publish';
    $GLOBALS['parent_of'][7] = 5;
    $GLOBALS['password'][5] = true;
    check('attached to a post behind a password: nothing', $all(), [false, false, false, false]);
    $GLOBALS['logged_in'] = true;
    check('  ...nor to a logged-in reader who has not given it', $all(), [false, false, false, false]);
    $GLOBALS['can_edit'][7] = true;
    check('  ...but to someone who may edit it', $all(), [true, true, true, true]);
    $GLOBALS['can_edit'] = [];
    $GLOBALS['logged_in'] = false;
    $GLOBALS['password'][5] = false;
    check('  ...once the password is given: shown', $all(), [true, true, true, true]);
    // Review after 1.4.34: a PDF attached to a reusable block, or to a post
    // that is gone, was shown before and is shown still -- whether a type
    // has public pages is not whether its attachments are hidden.
    $GLOBALS['type_of'][5] = 'wp_block';
    check('attached to a published post of a type with no public pages: shown, as before', $all(), [true, true, true, true]);
    $GLOBALS['parent_of'][7] = 99;
    check('attached to a post that no longer exists: shown, as before', $all(), [true, true, true, true]);
    $GLOBALS['parent_of'][7] = 5;
    $GLOBALS['type_of'] = [];
    $GLOBALS['status_of'][7] = 'no-such-status';
    check('a status WordPress does not know: nothing', $all(), [false, false, false, false]);
    $GLOBALS['status_of'] = [];
    $GLOBALS['parent_of'] = [];

    echo "\n--- \"Insert into post\" honours the media window's Link To ---\n";
    $insert = static function (array $options, array $attachment) use ($generator): string {
        $GLOBALS['options'][Settings::OPTION_NAME] = $options;
        return (new Inserter($generator, new Settings()))->filterMediaSendToEditor('core', 7, $attachment);
    };
    $asTitle = ['insert_type' => 'title', 'insert_link' => 'file'];
    check('the window links to the attachment page', $insert($asTitle, ['id' => 7, 'url' => 'https://example.test/?attachment_id=7']), '<a href="https://example.test/?attachment_id=7" class="rapls-pic-pdf-link" data-pdf-id="7">Doc</a>');
    check('the window says None: no link', $insert($asTitle, ['id' => 7, 'post_title' => 'Doc']), 'Doc');
    check('the window\'s address is escaped', $insert($asTitle, ['id' => 7, 'url' => 'https://example.test/x"y']), '<a href="https://example.test/x%22y" class="rapls-pic-pdf-link" data-pdf-id="7">Doc</a>');
    check('no window (another caller): the setting decides', [
        $insert($asTitle, []),
        $insert(['insert_type' => 'title', 'insert_link' => 'attachment'], []),
        $insert(['insert_type' => 'title', 'insert_link' => 'none'], []),
    ], [
        '<a href="https://example.test/doc.pdf" class="rapls-pic-pdf-link" data-pdf-id="7">Doc</a>',
        '<a href="https://example.test/?attachment_id=7" class="rapls-pic-pdf-link" data-pdf-id="7">Doc</a>',
        'Doc',
    ]);
    $image = ['insert_type' => 'image', 'insert_link' => 'none', 'insert_size' => 'medium'];
    check('an image: the window\'s link wins over the setting\'s None', 0 === strpos($insert($image, ['id' => 7, 'url' => 'https://example.test/doc.pdf']), '<a href="https://example.test/doc.pdf" class="rapls-pic-pdf-link" data-pdf-id="7"><img '), true);
    check('  ...and its None over the setting\'s file', 0 === strpos($insert(['insert_type' => 'image', 'insert_link' => 'file', 'insert_size' => 'medium'], ['id' => 7]), '<img '), true);
    check('a custom template with none set falls back to the image, same link', 0 === strpos($insert(['insert_type' => 'custom', 'custom_html' => '', 'insert_size' => 'medium'], ['id' => 7, 'url' => 'https://example.test/p']), '<a href="https://example.test/p" '), true);
    $GLOBALS['options'][Settings::OPTION_NAME] = $asTitle;
    $loose = new Inserter($generator, new Settings());
    set_error_handler(static function (int $no, string $message): bool { throw new \ErrorException($message, 0, $no); });
    try {
        $handedOn = [$loose->filterMediaSendToEditor(null, new \stdClass(), []), $loose->filterMediaSendToEditor('core', 7, 'not an array'), $loose->filterMediaSendToEditor('core', '7')];
    } catch (\Throwable $e) {
        $handedOn = get_class($e);
    } finally {
        restore_error_handler();
    }
    check('another plugin\'s odd arguments: no fatal error', $handedOn, [null, '<a href="https://example.test/doc.pdf" class="rapls-pic-pdf-link" data-pdf-id="7">Doc</a>', '<a href="https://example.test/doc.pdf" class="rapls-pic-pdf-link" data-pdf-id="7">Doc</a>']);
    $GLOBALS['title'] = '<script>x</script>';
    check('no link: the title alone, escaped', $insert(['insert_type' => 'title', 'insert_link' => 'none'], []), '&lt;script&gt;x&lt;/script&gt;');
    // Core gives no image markup (a filter emptied it): the plugin writes its
    // own <img>, the title as its alt (mutation testing of 1.4.36).
    $GLOBALS['title'] = 'A "q" <b>';
    $GLOBALS['no_image_html'] = true;
    $ownImg = $insert(['insert_type' => 'image', 'insert_link' => 'none', 'insert_size' => 'medium'], ['id' => 7]);
    check('core gives no markup: the plugin\'s own <img>, its alt escaped', [0 === strpos($ownImg, '<img src="'), false !== strpos($ownImg, 'alt="A &quot;q&quot; &lt;b&gt;"'), false !== strpos($ownImg, '<b>')], [true, true, false]);
    unset($GLOBALS['title'], $GLOBALS['no_image_html']);

    echo "\n--- \"Insert into post\" honours the media window's Size ---\n";
    $sizeOf = static function (array $attachment) use ($insert): string {
        preg_match('/ class="[^"]*size-([\w-]+)"/', $insert(['insert_type' => 'image', 'insert_link' => 'none', 'insert_size' => 'medium'], $attachment), $m);
        return $m[1] ?? '';
    };
    check('the window picked Large', $sizeOf(['id' => 7, 'image-size' => 'large']), 'large');
    check('  ...or Full Size', $sizeOf(['id' => 7, 'image-size' => 'full']), 'full');
    check('a size that does not exist, or none sent: the setting', [$sizeOf(['id' => 7, 'image-size' => 'huge"x']), $sizeOf(['id' => 7, 'image-size' => ['large']]), $sizeOf(['id' => 7])], ['medium', 'medium', 'medium']);
    check('a custom template\'s image fallback takes it too', false !== strpos($insert(['insert_type' => 'custom', 'custom_html' => '', 'insert_size' => 'medium'], ['id' => 7, 'image-size' => 'large']), 'size-large'), true);

    echo "\n--- The window's Link To starts at the setting ---\n";
    define('RAPLS_PIC_PLUGIN_URL', 'https://example.test/wp-content/plugins/rapls-pdf-image-creator/');
    define('RAPLS_PIC_VERSION', 'test');
    function wp_enqueue_script($handle, $src = '', $deps = [], ...$rest) { $GLOBALS['enqueued'][$handle] = [basename((string) $src), $deps]; }
    function wp_localize_script($handle, $name, $data) { $GLOBALS['localized'][$handle] = [$name, $data]; return true; }
    $GLOBALS['options'][Settings::OPTION_NAME] = ['insert_link' => 'attachment'];
    (new Inserter($generator, new Settings()))->enqueueInsertLink();
    if (!function_exists('add_action')) {
        function add_action($tag, $cb, $prio = 10, $args = 1) { $GLOBALS['actions'][$tag][] = $cb; }
    }
    if (!function_exists('add_filter')) {
        function add_filter($tag, $cb, $prio = 10, $args = 1) { $GLOBALS['filters'][$tag][] = $cb; }
    }
    $hooked = new Inserter($generator, new Settings());
    $hooked->init();
    check('init() hooks it to wp_enqueue_media', in_array([$hooked, 'enqueueInsertLink'], $GLOBALS['actions']['wp_enqueue_media'] ?? [], true), true);
    check('insert-link.js after the media window and editor, given the settings', [$GLOBALS['enqueued']['pic-insert-link'] ?? null, $GLOBALS['localized']['pic-insert-link'] ?? null], [['insert-link.js', ['media-views', 'media-editor']], ['raplsPicInsertLink', ['link' => 'attachment', 'size' => 'medium', 'sizes' => ['thumbnail', 'medium', 'large', 'full']]]]);
    // The sizes the window lists, as image_size_names_choose says (review of
    // 1.4.35), so the script can tell a setting it cannot show there.
    $GLOBALS['filtered']['image_size_names_choose'] = static function ($sizes) { return $sizes + ['medium_large' => 'Medium Large']; };
    (new Inserter($generator, new Settings()))->enqueueInsertLink();
    $withTheme = $GLOBALS['localized']['pic-insert-link'][1]['sizes'] ?? null;
    $GLOBALS['filtered']['image_size_names_choose'] = static function () { return 'not a list'; };
    (new Inserter($generator, new Settings()))->enqueueInsertLink();
    $broken = $GLOBALS['localized']['pic-insert-link'][1]['sizes'] ?? null;
    unset($GLOBALS['filtered']['image_size_names_choose']);
    check('  ...with the sizes the window lists, as filtered; none for a broken filter', [$withTheme, $broken], [['thumbnail', 'medium', 'large', 'full', 'medium_large'], []]);

    echo "\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}
