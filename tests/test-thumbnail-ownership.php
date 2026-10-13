<?php
/**
 * Only a thumbnail made from this PDF is ever deleted.
 *
 * getThumbnailId() also answers with `_thumbnail_id`, which another plugin
 * can point at any image -- one embedded in posts -- and a translation
 * plugin can copy this plugin's own meta from another PDF. A forced
 * regeneration, or deleting the PDF, deleted that image for good.
 */

declare(strict_types=1);

namespace {
    define('RAPLS_PIC_PLUGIN_DIR', dirname(__DIR__) . '/');

    $GLOBALS['meta'] = [];
    $GLOBALS['posts'] = [];
    $GLOBALS['mimes'] = [];
    $GLOBALS['deleted'] = [];

    function get_post_mime_type($id) { return $GLOBALS['mimes'][$id] ?? false; }
    function get_post_meta($id, $key, $single = false) { return $GLOBALS['meta'][$id][$key] ?? ''; }
    function delete_post_meta($id, $key) { unset($GLOBALS['meta'][$id][$key]); return true; }
    function get_post($id) { return $GLOBALS['posts'][$id] ?? null; }
    function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
    function wp_delete_attachment($id, $force = false) {
        $GLOBALS['deleted'][] = $id;
        $post = $GLOBALS['posts'][$id] ?? null;
        unset($GLOBALS['posts'][$id]);
        return $post ?? false;
    }

    require RAPLS_PIC_PLUGIN_DIR . 'includes/Settings.php';
    require RAPLS_PIC_PLUGIN_DIR . 'includes/Generator.php';
    require RAPLS_PIC_PLUGIN_DIR . 'includes/MediaLibrary.php';

    $pass = 0;
    $fail = 0;
    function check($label, $got, $want) {
        global $pass, $fail;
        $ok = $got === $want;
        $ok ? $pass++ : $fail++;
        printf("%s %-62s got=%s want=%s\n", $ok ? 'ok  ' : 'FAIL', $label,
            json_encode($got, JSON_UNESCAPED_SLASHES), json_encode($want, JSON_UNESCAPED_SLASHES));
    }

    // The reverse lookup deleteThumbnail() makes, answered from $GLOBALS['meta'].
    $GLOBALS['wpdb'] = new class {
        public $postmeta = 'wp_postmeta';
        public $last_error = '';
        public $fail = false;
        public function prepare($sql, ...$args) { return [$sql, $args]; }
        public $posts = 'wp_posts';
        public $contentFails = false;
        public function esc_like($text) { return addcslashes($text, '_%\\'); }
        public function get_var($q) {
            // The content lookup (review of 1.4.35): a post whose content
            // holds any of the LIKE patterns, as MySQL would, ignoring case.
            if (false !== strpos($q[0], 'post_content')) {
                $this->last_error = $this->contentFails ? 'simulated failure' : '';
                if ($this->contentFails) { return null; }
                foreach ($GLOBALS['content'] ?? [] as $id => $html) {
                    foreach ($q[1] as $like) {
                        $needle = stripslashes(substr($like, 1, -1));
                        if (false !== stripos($html, $needle)) { return (string) $id; }
                    }
                }
                return null;
            }
            $this->last_error = $this->fail ? 'simulated failure' : '';
            if ($this->fail) { return null; }
            // As MySQL would: the PDF itself is left out only by the clause
            // that says so (mutation testing after 1.4.34).
            [$metaA, $metaB, $value] = $q[1];
            $except = preg_match('/\bAND post_id <> %d\b/', $q[0]) ? ($q[1][3] ?? null) : null;
            foreach ($GLOBALS['meta'] as $post => $keys) {
                if (null !== $except && (int) $post === (int) $except) { continue; }
                foreach ([$metaA, $metaB] as $key) {
                    if (isset($keys[$key]) && (string) $keys[$key] === $value) { return (string) $post; }
                }
            }
            return null;
        }
    };

    $generator = (new ReflectionClass(\Rapls\PDFImageCreator\Generator::class))->newInstanceWithoutConstructor();
    $own = static function (int $image, int $pdf): void {
        $GLOBALS['posts'][$image] = (object) ['ID' => $image, 'post_type' => 'attachment'];
        $GLOBALS['meta'][$image]['_rapls_pic_is_thumbnail'] = '1';
        $GLOBALS['meta'][$image]['_rapls_pic_source_pdf'] = $pdf;
    };

    // PDF 10's own thumbnail: deleted, as always.
    $own(11, 10);
    $GLOBALS['meta'][10]['_rapls_pic_thumbnail_id'] = 11;
    check('own thumbnail: deleted', [$generator->deleteThumbnail(10), $GLOBALS['deleted']], [true, [11]]);

    // PDF 20's featured image is someone else's picture.
    $GLOBALS['deleted'] = [];
    $GLOBALS['posts'][99] = (object) ['ID' => 99, 'post_type' => 'attachment'];
    $GLOBALS['meta'][20]['_thumbnail_id'] = 99;
    check('featured image from elsewhere: not deleted', [$generator->deleteThumbnail(20), $GLOBALS['deleted'], isset($GLOBALS['posts'][99])], [false, [], true]);
    check('  ...only unlinked from this PDF', $GLOBALS['meta'][20]['_thumbnail_id'] ?? '', '');

    // PDF 31 is a translation of PDF 30 whose meta was copied: it names 30's thumbnail.
    $GLOBALS['deleted'] = [];
    $own(32, 30);
    $GLOBALS['meta'][30]['_rapls_pic_thumbnail_id'] = 32;
    $GLOBALS['meta'][31]['_rapls_pic_thumbnail_id'] = 32;
    $GLOBALS['meta'][31]['_thumbnail_id'] = 32;
    check('another PDF\'s thumbnail (copied meta): not deleted', [$generator->deleteThumbnail(31), $GLOBALS['deleted'], isset($GLOBALS['posts'][32])], [false, [], true]);
    check('  ...the other PDF keeps it', $GLOBALS['meta'][30]['_rapls_pic_thumbnail_id'] ?? '', 32);

    // Deleting that translation with "hide generated images" off must not
    // untag the other PDF's thumbnail either.
    $settings = (new ReflectionClass(\Rapls\PDFImageCreator\Settings::class))->newInstanceWithoutConstructor();
    $prop = new ReflectionProperty(\Rapls\PDFImageCreator\Settings::class, 'settings');
    if (PHP_VERSION_ID < 80100) { $prop->setAccessible(true); }
    $prop->setValue($settings, ['hide_generated_images' => false]);
    $library = new \Rapls\PDFImageCreator\MediaLibrary($generator, $settings);
    $GLOBALS['mimes'][31] = 'application/pdf';
    $GLOBALS['meta'][31]['_rapls_pic_thumbnail_id'] = 32;
    $library->onAttachmentDeleted(31);
    check('deleting the translation keeps the other PDF\'s tags', [$GLOBALS['meta'][32]['_rapls_pic_is_thumbnail'] ?? '', $GLOBALS['meta'][32]['_rapls_pic_source_pdf'] ?? ''], ['1', 30]);
    $GLOBALS['mimes'][30] = 'application/pdf';
    $library->onAttachmentDeleted(30);
    check('  ...deleting the PDF itself untags its own', $GLOBALS['meta'][32]['_rapls_pic_is_thumbnail'] ?? '', '');

    // A thumbnail this PDF made, shared with a translation's copy (same meta)
    // or taken by a post as its featured image: not deleted, only let go of.
    $GLOBALS['deleted'] = [];
    $own(42, 40);
    $GLOBALS['meta'][40]['_rapls_pic_thumbnail_id'] = 42;
    $GLOBALS['meta'][41]['_rapls_pic_thumbnail_id'] = 42;   // the translation shares it
    check('own thumbnail shared with a translation: kept', [$generator->deleteThumbnail(40), $GLOBALS['deleted'], isset($GLOBALS['posts'][42])], [false, [], true]);
    check('  ...the translation still points at it', $GLOBALS['meta'][41]['_rapls_pic_thumbnail_id'] ?? '', 42);
    check('  ...this PDF lets go of it', $GLOBALS['meta'][40]['_rapls_pic_thumbnail_id'] ?? '', '');

    $own(52, 50);
    $GLOBALS['meta'][50]['_rapls_pic_thumbnail_id'] = 52;
    $GLOBALS['meta'][99]['_thumbnail_id'] = '52';            // a post's featured image
    check('own thumbnail used as a post\'s featured image: kept', [$generator->deleteThumbnail(50), $GLOBALS['deleted']], [false, []]);

    $own(62, 60);
    $GLOBALS['meta'][60]['_rapls_pic_thumbnail_id'] = 62;
    $GLOBALS['wpdb']->fail = true;
    check('database does not answer: kept', [$generator->deleteThumbnail(60), $GLOBALS['deleted']], [false, []]);
    $GLOBALS['wpdb']->fail = false;

    $own(72, 70);
    $GLOBALS['meta'][70]['_rapls_pic_thumbnail_id'] = 72;
    check('own thumbnail nobody else uses: still deleted', [$generator->deleteThumbnail(70), $GLOBALS['deleted']], [true, [72]]);

    // No database object at all: kept, as when the database does not answer.
    $saved = $GLOBALS['wpdb'];
    unset($GLOBALS['wpdb']);
    $GLOBALS['deleted'] = [];
    $own(82, 80);
    $GLOBALS['meta'][80]['_rapls_pic_thumbnail_id'] = 82;
    check('no database object: kept', [$generator->deleteThumbnail(80), $GLOBALS['deleted']], [false, []]);
    $GLOBALS['wpdb'] = $saved;

    // Review of 1.4.35: asking is read-only. A key naming an image that is
    // gone is not deleted from a page view.
    $GLOBALS['meta'][90]['_rapls_pic_thumbnail_id'] = 91;   // 91 does not exist
    check('a thumbnail that is gone: none, and the key is left for the next generation', [$generator->getThumbnailId(90), $GLOBALS['meta'][90]['_rapls_pic_thumbnail_id'] ?? null], [null, 91]);
    unset($GLOBALS['meta'][90]);

    // Shown in a post's content (review of 1.4.35): an image block or the
    // classic editor's insert holds the thumbnail's own address, and a
    // forced regeneration or deleting the PDF deleted the file under it.
    echo "\n--- A thumbnail a post's content shows ---\n";
    $GLOBALS['wpdb']->fail = false;
    $shown = static function (int $image, int $pdf, string $file) use ($own): void {
        $own($image, $pdf);
        $GLOBALS['meta'][$image]['_wp_attached_file'] = $file;
        $GLOBALS['meta'][$pdf]['_rapls_pic_thumbnail_id'] = $image;
    };

    $GLOBALS['deleted'] = [];
    $shown(102, 100, '2026/10/report-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [700 => '<!-- wp:image --><figure><img src="https://example.test/wp-content/uploads/2026/10/Report-PDF-Thumbnail-300x212.jpg" alt=""/></figure>'];
    check('a post shows it in another size: kept', [$generator->deleteThumbnail(100), $GLOBALS['deleted'], isset($GLOBALS['posts'][102])], [false, [], true]);
    check('  ...and made an ordinary image, which this PDF lets go of',
        [$GLOBALS['meta'][102]['_rapls_pic_is_thumbnail'] ?? '', $GLOBALS['meta'][102]['_rapls_pic_source_pdf'] ?? '', $GLOBALS['meta'][100]['_rapls_pic_thumbnail_id'] ?? ''],
        ['', '', '']);

    $GLOBALS['deleted'] = [];
    $shown(112, 110, '2026/10/a.jpg');
    $GLOBALS['content'] = [701 => '<img class="wp-image-112" src="https://cdn.example.test/x.jpg">'];
    check('a "wp-image-" class names it: kept', [$generator->deleteThumbnail(110), $GLOBALS['deleted']], [false, []]);

    $GLOBALS['deleted'] = [];
    $shown(122, 120, '2026/10/b.jpg');
    $GLOBALS['content'] = [702 => '<img class="wp-image-1220" src="https://cdn.example.test/y.jpg"><img class="wp-image-12">'];
    check('  ...another image\'s class that starts the same: deleted', [$generator->deleteThumbnail(120), $GLOBALS['deleted']], [true, [122]]);

    $GLOBALS['deleted'] = [];
    $shown(132, 130, '2026/10/資料-pdf-thumbnail.png');
    $GLOBALS['content'] = [703 => '<img src="/wp-content/uploads/2026/10/' . rawurlencode('資料-pdf-thumbnail') . '-1024x724.png">'];
    check('its name percent-encoded in the post: kept', [$generator->deleteThumbnail(130), $GLOBALS['deleted']], [false, []]);

    $GLOBALS['deleted'] = [];
    $shown(142, 140, '2026/10/c-pdf-thumbnail-scaled.webp');
    $GLOBALS['content'] = [704 => '<img src="/wp-content/uploads/2026/10/c-pdf-thumbnail.webp">'];
    check('"-scaled" on the file, not in the post: kept', [$generator->deleteThumbnail(140), $GLOBALS['deleted']], [false, []]);

    $GLOBALS['deleted'] = [];
    $shown(152, 150, '2026/10/d-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [705 => '<p>Nothing of this one.</p><img src="/e-pdf-thumbnail.jpg">'];
    check('a post that shows something else: deleted', [$generator->deleteThumbnail(150), $GLOBALS['deleted']], [true, [152]]);

    $GLOBALS['deleted'] = [];
    $shown(162, 160, '2026/10/f-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [];
    $GLOBALS['wpdb']->contentFails = true;
    check('the content lookup fails: kept, tagged, and still linked',
        [$generator->deleteThumbnail(160), $GLOBALS['deleted'], $GLOBALS['meta'][162]['_rapls_pic_is_thumbnail'] ?? '', $GLOBALS['meta'][160]['_rapls_pic_thumbnail_id'] ?? ''],
        [false, [], '1', 162]);
    $GLOBALS['wpdb']->contentFails = false;

    // The meta lookup still comes first: a translation sharing it keeps the
    // tags, whatever the content says.
    $GLOBALS['deleted'] = [];
    $shown(172, 170, '2026/10/g-pdf-thumbnail.jpg');
    $GLOBALS['meta'][171]['_rapls_pic_thumbnail_id'] = 172;
    $GLOBALS['content'] = [706 => '<img src="/g-pdf-thumbnail.jpg">'];
    check('named by a translation and shown: kept with its tags', [$generator->deleteThumbnail(170), $GLOBALS['deleted'], $GLOBALS['meta'][172]['_rapls_pic_is_thumbnail'] ?? ''], [false, [], '1']);

    // No posts table to ask: kept.
    $GLOBALS['deleted'] = [];
    $shown(182, 180, '2026/10/h-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [];
    $postsTable = $GLOBALS['wpdb']->posts;
    $GLOBALS['wpdb']->posts = null;
    check('no posts table: kept and still linked', [$generator->deleteThumbnail(180), $GLOBALS['deleted'], $GLOBALS['meta'][180]['_rapls_pic_thumbnail_id'] ?? ''], [false, [], 182]);
    $GLOBALS['wpdb']->posts = $postsTable;
    $GLOBALS['content'] = [];

    // Uninstalling keeps what something else uses too, as deleteThumbnail()
    // does. test-uninstall.php runs uninstall.php for that, query failures
    // included.

    echo "\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}
