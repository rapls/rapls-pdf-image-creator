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
    // As core answers: $single gives the value, otherwise a list of every value
    // (mutation testing after 1.4.37: the stand-in ignored it, and a call
    // asking for the list instead of the value went unnoticed).
    function get_post_meta($id, $key, $single = false) {
        if (!isset($GLOBALS['meta'][$id][$key])) { return $single ? '' : []; }
        return $single ? $GLOBALS['meta'][$id][$key] : [$GLOBALS['meta'][$id][$key]];
    }
    function delete_post_meta($id, $key) { unset($GLOBALS['meta'][$id][$key]); return true; }
    function update_post_meta($id, $key, $value) { $GLOBALS['meta'][$id][$key] = $value; return true; }
    function get_post($id) { return $GLOBALS['posts'][$id] ?? null; }
    // Core's, which every supported WordPress has; the calls are kept so a
    // test can see the component asked for (mutation testing of 1.4.39: with
    // no stand-in, only the parse_url() fallback ever ran).
    function wp_parse_url($url, $component = -1) { $GLOBALS['wp_parse_url_calls'][] = [$url, $component]; return parse_url((string) $url, $component); }
    function get_option($key, $default = false) {
        // PCRE starved once a widget is read, so the widgets alone cannot
        // be run over (review of 1.4.40).
        if (!empty($GLOBALS['starve_pcre_on'][$key])) {
            ini_set('pcre.jit', '0');
            ini_set('pcre.backtrack_limit', '1');
        }
        return $GLOBALS['options'][$key] ?? $default;
    }
    // Review after 1.4.36: the parent is written to the row, not through wp_update_post() and its hooks.
    function wp_update_post($args) {
        $GLOBALS['update_post_calls'] = ($GLOBALS['update_post_calls'] ?? 0) + 1;
        return $args['ID'];
    }
    function clean_post_cache($id) { $GLOBALS['cleaned'][] = $id; }
    function wp_delete_attachment($id, $force = false) {
        // Refused, as pre_delete_attachment can: nothing deleted.
        if (in_array((int) $id, $GLOBALS['refuse_delete'] ?? [], true)) {
            return $GLOBALS['posts'][$id] ?? false;
        }
        $GLOBALS['deleted'][] = $id;
        $post = $GLOBALS['posts'][$id] ?? null;
        unset($GLOBALS['posts'][$id]);
        return $post ?? false;
    }

    require __DIR__ . '/fake-content-sql.php';
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
        public function update($table, $data, $where, $format = null, $whereFormat = null) {
            if ('wp_posts' === $table && isset($GLOBALS['posts'][$where['ID']])) {
                foreach ($data as $field => $value) { $GLOBALS['posts'][$where['ID']]->$field = $value; }
                return 1;
            }
            return false;
        }
        /** Each content query, so a test can count them. */
        public $contentQueries = 0;
        public function get_results($q, $output = null) {
            // The content lookup (review of 1.4.35, 1.4.36, after 1.4.37):
            // answered as MySQL would answer the SQL it is given -- its
            // LIKE patterns, case for case unless it asks LOWER(), the
            // types and states it leaves out (fake-content-sql.php).
            if (false === strpos($q[0], 'post_content')) {
                throw new \RuntimeException('unexpected get_results: ' . $q[0]);
            }
            $this->contentQueries++;
            $this->last_error = $this->contentFails ? 'simulated failure' : '';
            if ($this->contentFails) { return []; }
            $GLOBALS['content_sql'][] = $q[0];
            $rows = rapls_test_content_rows($q, $GLOBALS['content'] ?? [], $GLOBALS['excerpts'] ?? [], $GLOBALS['content_kind'] ?? []);
            $GLOBALS['content_read'] = array_merge($GLOBALS['content_read'] ?? [], array_column($rows, 'ID'));
            return $rows;
        }
        /** The ordinary images still attached to a PDF (reparentKeptImages()). */
        public $markedLookupFails = false;
        public function get_col($q) {
            $this->last_error = '';
            // The marked images whose source is a PDF (reparentKeptImages()),
            // as the SQL asks: by the source key, marked, attachments only.
            if (false !== strpos($q[0], "s.meta_key = '_rapls_pic_source_pdf' AND s.meta_value = %s")) {
                if ($this->markedLookupFails) { $this->last_error = 'simulated failure'; return []; }
                $markedOnly = false !== strpos($q[0], "EXISTS (SELECT 1 FROM {$this->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '_rapls_pic_is_thumbnail')");
                $attachmentsOnly = false !== strpos($q[0], "p.post_type = 'attachment'");
                $ids = [];
                foreach ($GLOBALS['posts'] as $id => $post) {
                    if ((string) ($GLOBALS['meta'][$id]['_rapls_pic_source_pdf'] ?? '') === (string) $q[1][0]
                        && (!$markedOnly || isset($GLOBALS['meta'][$id]['_rapls_pic_is_thumbnail']))
                        && (!$attachmentsOnly || 'attachment' === ($post->post_type ?? ''))) {
                        $ids[] = (string) $id;
                    }
                }
                return $ids;
            }
            if (false === strpos($q[0], 'p.post_parent = %d')) {
                throw new \RuntimeException('unexpected get_col: ' . $q[0]);
            }
            $ids = [];
            // Marked images left out only by the clause that says so.
            $unmarkedOnly = false !== strpos($q[0], "NOT EXISTS (SELECT 1 FROM {$this->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '_rapls_pic_is_thumbnail')");
            // Only attachments, when the SQL says so (mutation testing of 1.4.40).
            $attachmentsOnly = false !== strpos($q[0], "p.post_type = 'attachment'");
            foreach ($GLOBALS['posts'] as $id => $post) {
                if ((int) ($post->post_parent ?? 0) === (int) $q[1][0] && (!$attachmentsOnly || 'attachment' === ($post->post_type ?? '')) && !($unmarkedOnly && isset($GLOBALS['meta'][$id]['_rapls_pic_is_thumbnail']))) {
                    $ids[] = (string) $id;
                }
            }
            return $ids;
        }
        /** Fails only the "named by another attachment" lookup. */
        public $attachmentLookupFails = false;
        public function get_var($q) {
            $failing = $this->fail || ($this->attachmentLookupFails && false !== strpos($q[0], "post_type = 'attachment'"));
            $this->last_error = $failing ? 'simulated failure' : '';
            if ($failing) { return null; }
            // As MySQL would: the PDF itself is left out only by the clause
            // that says so (mutation testing after 1.4.34).
            // One key or two, as the SQL asks: another PDF by this plugin's
            // key alone (review of 1.4.40).
            $two = false !== strpos($q[0], 'meta_key IN (%s, %s)');
            $metaKeys = $two ? [$q[1][0], $q[1][1]] : [$q[1][0]];
            $value = $q[1][$two ? 2 : 1];
            $except = preg_match('/\bAND (?:meta\.)?post_id <> %d\b/', $q[0]) ? ($q[1][$two ? 3 : 2] ?? null) : null;
            // In ID order only when the SQL asks for it.
            $rows = $GLOBALS['meta'];
            if (false !== strpos($q[0], 'ORDER BY meta.post_id')) { ksort($rows); }
            // Only attachments name it, when the SQL says so (another PDF).
            $attachmentsOnly = false !== strpos($q[0], "post_type = 'attachment'");
            // Only PDFs, when the SQL says so: an audio file's cover image is
            // not another PDF's thumbnail (review after 1.4.38).
            $pdfsOnly = false !== strpos($q[0], "post_mime_type = 'application/pdf'");
            foreach ($rows as $post => $keys) {
                if (null !== $except && (int) $post === (int) $except) { continue; }
                if ($attachmentsOnly && 'attachment' !== ($GLOBALS['posts'][$post]->post_type ?? '')) { continue; }
                if ($pdfsOnly && 'application/pdf' !== ($GLOBALS['posts'][$post]->post_mime_type ?? 'application/pdf')) { continue; }
                foreach ($metaKeys as $key) {
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
    $GLOBALS['posts'][41] = (object) ['ID' => 41, 'post_type' => 'attachment', 'post_mime_type' => 'application/pdf'];
    check('own thumbnail shared with a translation: kept', [$generator->deleteThumbnail(40), $GLOBALS['deleted'], isset($GLOBALS['posts'][42])], [false, [], true]);
    check('  ...the translation still points at it', $GLOBALS['meta'][41]['_rapls_pic_thumbnail_id'] ?? '', 42);
    check('  ...this PDF lets go of it', $GLOBALS['meta'][40]['_rapls_pic_thumbnail_id'] ?? '', '');
    // Review of 1.4.39: still that PDF's, so its own regeneration or
    // deletion finds it; left naming PDF 40, it was nobody's.
    check('  ...and is now the translation\'s: its source names PDF 41, still marked', [(int) ($GLOBALS['meta'][42]['_rapls_pic_source_pdf'] ?? 0), $GLOBALS['meta'][42]['_rapls_pic_is_thumbnail'] ?? '', $generator->isOwnThumbnail(42, 41)], [41, '1', true]);

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

    // Review after 1.4.36: "[gallery" alone made every gallery a candidate, read in full for every image. Only one that
    // holds the ID's digits is read now; a block's "id" with a space after the colon is still found.
    $GLOBALS['deleted'] = [];
    $GLOBALS['content_read'] = [];
    $shown(162, 160, '2026/10/g-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [705 => '[gallery ids="5,6"]', 706 => '<!-- wp:gallery {"ids":[7,8]} -->', 707 => '<!-- wp:image {"id": 162} -->'];
    check('a gallery that cannot name it is not read; an image block spaced "id": 162 keeps it',
        [$generator->deleteThumbnail(160), $GLOBALS['deleted'], $GLOBALS['content_read']], [false, [], ['707']]);

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
    // Review of 1.4.36: left marked and linked "for the next attempt", it
    // had none -- the PDF was being deleted, or already named its new
    // thumbnail -- and stayed hidden with nothing pointing at it.
    check('the content lookup fails: kept as an ordinary image, let go of',
        [$generator->deleteThumbnail(160), $GLOBALS['deleted'], $GLOBALS['meta'][162]['_rapls_pic_is_thumbnail'] ?? '', $GLOBALS['meta'][160]['_rapls_pic_thumbnail_id'] ?? ''],
        [false, [], '', '']);
    $GLOBALS['wpdb']->contentFails = false;

    // The meta lookup still comes first: a translation sharing it keeps the
    // tags, whatever the content says.
    $GLOBALS['deleted'] = [];
    $shown(172, 170, '2026/10/g-pdf-thumbnail.jpg');
    $GLOBALS['posts'][171] = (object) ['ID' => 171, 'post_type' => 'attachment'];
    $GLOBALS['meta'][171]['_rapls_pic_thumbnail_id'] = 172;
    $GLOBALS['content'] = [706 => '<img src="/g-pdf-thumbnail.jpg">'];
    check('named by a translation and shown: kept with its tags', [$generator->deleteThumbnail(170), $GLOBALS['deleted'], $GLOBALS['meta'][172]['_rapls_pic_is_thumbnail'] ?? ''], [false, [], '1']);

    // No posts table to ask: kept.
    $GLOBALS['deleted'] = [];
    $shown(182, 180, '2026/10/h-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [];
    $postsTable = $GLOBALS['wpdb']->posts;
    $GLOBALS['wpdb']->posts = null;
    check('no posts table: kept as an ordinary image', [$generator->deleteThumbnail(180), $GLOBALS['deleted'], $GLOBALS['meta'][182]['_rapls_pic_is_thumbnail'] ?? ''], [false, [], '']);
    $GLOBALS['wpdb']->posts = $postsTable;
    $GLOBALS['content'] = [];

    echo "\n--- Review of 1.4.36: by ID, compared whole, read once ---\n";

    $GLOBALS['deleted'] = [];
    $shown(202, 200, '2026/10/i-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [710 => '[gallery ids="5,202"]'];
    check('a [gallery] that names it by ID only: kept', [$generator->deleteThumbnail(200), $GLOBALS['deleted'], $GLOBALS['meta'][202]['_rapls_pic_is_thumbnail'] ?? ''], [false, [], '']);

    $GLOBALS['deleted'] = [];
    $shown(212, 210, '2026/10/j-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [711 => '<!-- wp:gallery {"ids":[1,212]} --><figure></figure><!-- /wp:gallery -->'];
    check('a gallery block\'s "ids": kept', [$generator->deleteThumbnail(210), $GLOBALS['deleted']], [false, []]);

    $GLOBALS['deleted'] = [];
    $shown(222, 220, '2026/10/k-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [712 => '<!-- wp:image {"id":222,"sizeSlug":"large"} --><figure><img src="https://cdn.example.test/z"/></figure><!-- /wp:image -->'];
    check('an image block\'s "id", its address rewritten by a CDN: kept', [$generator->deleteThumbnail(220), $GLOBALS['deleted']], [false, []]);

    $GLOBALS['deleted'] = [];
    $shown(232, 230, '2026/10/l-pdf-thumbnail-e1696000000000.jpg');
    $GLOBALS['meta'][232]['_wp_attachment_backup_sizes'] = ['full-orig' => ['file' => 'l-pdf-thumbnail.jpg'], 'medium-orig' => ['file' => 'l-pdf-thumbnail-300x212.jpg']];
    $GLOBALS['content'] = [713 => '<img src="/wp-content/uploads/2026/10/l-pdf-thumbnail-300x212.jpg">'];
    check('edited since: the post shows the name it had, kept', [$generator->deleteThumbnail(230), $GLOBALS['deleted']], [false, []]);

    $GLOBALS['deleted'] = [];
    $shown(242, 240, '2026/10/report-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [714 => '<img src="/wp-content/uploads/2026/10/annual-report-pdf-thumbnail-300x212.jpg">'];
    check('a post showing "annual-report-…" is another image: deleted', [$generator->deleteThumbnail(240), $GLOBALS['deleted']], [true, [242]]);

    $GLOBALS['deleted'] = [];
    $shown(252, 250, '2026/10/m-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [715 => '<img class="wp-image-252">', 716 => '[gallery ids="252"]'];
    $GLOBALS['content_kind'] = [715 => ['type' => 'revision'], 716 => ['status' => 'auto-draft']];
    check('only a revision and an auto-draft show it: deleted', [$generator->deleteThumbnail(250), $GLOBALS['deleted']], [true, [252]]);
    // Review after 1.4.37: a trashed post comes back with its pictures.
    $GLOBALS['deleted'] = [];
    $shown(256, 255, '2026/10/mt-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [717 => '<img src="/wp-content/uploads/2026/10/mt-pdf-thumbnail.jpg">'];
    $GLOBALS['content_kind'] = [717 => ['status' => 'trash']];
    check('  ...a trashed post shows it: kept, for when it is restored', [$generator->deleteThumbnail(255), $GLOBALS['deleted']], [false, []]);
    $GLOBALS['content_kind'] = [];

    // A post's featured image only, and no other PDF: an ordinary image, as
    // the settings screen says; it stayed marked and hidden. The PDF is an
    // attachment that names it too, and is left out of the question by the
    // SQL itself (mutation testing after 1.4.37).
    $GLOBALS['deleted'] = [];
    $shown(262, 260, '2026/10/n-pdf-thumbnail.jpg');
    $GLOBALS['posts'][260] = (object) ['ID' => 260, 'post_type' => 'attachment'];
    $GLOBALS['content'] = [];
    $GLOBALS['posts'][800] = (object) ['ID' => 800, 'post_type' => 'post'];
    $GLOBALS['meta'][800]['_thumbnail_id'] = '262';
    check('a post\'s featured image, no other PDF: kept and made ordinary', [$generator->deleteThumbnail(260), $GLOBALS['deleted'], $GLOBALS['meta'][262]['_rapls_pic_is_thumbnail'] ?? ''], [false, [], '']);
    unset($GLOBALS['meta'][800]);

    // Named elsewhere, and whether by another PDF cannot be told: an
    // ordinary image -- visible, recoverable -- and said.
    $GLOBALS['deleted'] = [];
    $shown(266, 265, '2026/10/nn-pdf-thumbnail.jpg');
    $GLOBALS['meta'][801]['_thumbnail_id'] = '266';
    $GLOBALS['wpdb']->attachmentLookupFails = true;
    $namedLog = sys_get_temp_dir() . '/rapls-pic-own-log-' . bin2hex(random_bytes(4));
    $namedLogWas = ini_set('error_log', $namedLog);
    $namedKept = [$generator->deleteThumbnail(265), $GLOBALS['deleted'], $GLOBALS['meta'][266]['_rapls_pic_is_thumbnail'] ?? ''];
    ini_set('error_log', (string) $namedLogWas);
    $GLOBALS['wpdb']->attachmentLookupFails = false;
    $namedLogged = is_file($namedLog) ? (string) file_get_contents($namedLog) : '';
    @unlink($namedLog);
    check('named elsewhere, whether by another PDF unknown: ordinary, and said', [$namedKept, false !== strpos($namedLogged, 'could not tell whether another PDF uses image #266'), $generator->keptReasonFor(265)], [[false, [], ''], true, 'unknown']);
    unset($GLOBALS['meta'][801]);

    // The content is read once, by 100s, until one shows it.
    $GLOBALS['deleted'] = [];
    $shown(272, 270, '2026/10/o-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [];
    for ($id = 1000; $id < 1150; $id++) { $GLOBALS['content'][$id] = '<img src="/o-pdf-thumbnail-x.jpg">'; }
    $GLOBALS['content'][1149] = '<img src="/o-pdf-thumbnail.jpg">';
    $GLOBALS['wpdb']->contentQueries = 0;
    check('read on past the first 100 candidates: kept', [$generator->deleteThumbnail(270), $GLOBALS['deleted'], $GLOBALS['wpdb']->contentQueries], [false, [], 2]);
    $GLOBALS['content'] = [];


    // Mutation testing after 1.4.37: named by a post, and no posts table to
    // ask whether by another PDF too: an ordinary image, and said.
    $GLOBALS['deleted'] = [];
    $shown(268, 267, '2026/10/nt-pdf-thumbnail.jpg');
    $GLOBALS['meta'][802]['_thumbnail_id'] = '268';
    $postsTable = $GLOBALS['wpdb']->posts;
    $GLOBALS['wpdb']->posts = null;
    $tableLog = sys_get_temp_dir() . '/rapls-pic-own-log-' . bin2hex(random_bytes(4));
    $tableLogWas = ini_set('error_log', $tableLog);
    $tableKept = [$generator->deleteThumbnail(267), $GLOBALS['deleted'], $GLOBALS['meta'][268]['_rapls_pic_is_thumbnail'] ?? ''];
    ini_set('error_log', (string) $tableLogWas);
    $GLOBALS['wpdb']->posts = $postsTable;
    $tableLogged = is_file($tableLog) ? (string) file_get_contents($tableLog) : '';
    @unlink($tableLog);
    check('  ...no posts table to ask it: ordinary, and said', [$tableKept, false !== strpos($tableLogged, 'could not tell whether another PDF uses image #268')], [[false, [], ''], true]);
    unset($GLOBALS['meta'][802]);

    echo "\n--- Review after 1.4.37: case, the excerpt, the first post, the SQL ---\n";

    // A case-sensitive collation: the stand-in compares case for case unless
    // the SQL asks LOWER(), as utf8mb4_bin would.
    $GLOBALS['deleted'] = [];
    $shown(342, 340, '2026/10/Report-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [730 => '<img src="/wp-content/uploads/2026/10/Report-pdf-thumbnail-300x212.jpg">'];
    check('a capital in the name, a case-sensitive collation: kept', [$generator->deleteThumbnail(340), $GLOBALS['deleted']], [false, []]);

    $GLOBALS['deleted'] = [];
    $shown(352, 350, '2026/10/r-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [731 => ''];
    $GLOBALS['excerpts'] = [731 => '<img src="/wp-content/uploads/2026/10/r-pdf-thumbnail-150x150.jpg">'];
    check('shown only in an excerpt (a product\'s short description): kept', [$generator->deleteThumbnail(350), $GLOBALS['deleted']], [false, []]);
    $GLOBALS['excerpts'] = [];

    $GLOBALS['deleted'] = [];
    $shown(362, 360, '2026/10/s-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [1 => '<img src="/wp-content/uploads/2026/10/s-pdf-thumbnail.jpg">'];
    check('shown by the site\'s first post, ID 1: kept', [$generator->deleteThumbnail(360), $GLOBALS['deleted']], [false, []]);

    $GLOBALS['deleted'] = [];
    $shown(372, 370, '2026/10/t-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [732 => '<img src="/wp-content/uploads/2026/10/t-pdf-thumbnail@1.5x.jpg">'];
    check('a density suffix with a fraction, "@1.5x": kept', [$generator->deleteThumbnail(370), $GLOBALS['deleted']], [false, []]);

    // The digits only after a form that names an image by ID: "372" in a
    // post with a block is no longer enough to be read.
    $GLOBALS['deleted'] = [];
    $GLOBALS['content_read'] = [];
    $shown(382, 380, '2026/10/u-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [733 => '<!-- wp:paragraph --><p>382 apples</p><!-- /wp:paragraph --><img class="wp-image-5">', 734 => '<!-- wp:image {"id":382} -->'];
    check('the ID\'s digits apart from any form: not read; after "id": read and kept',
        [$generator->deleteThumbnail(380), $GLOBALS['deleted'], $GLOBALS['content_read']], [false, [], ['734']]);

    $GLOBALS['content_sql'] = [];
    $shown(392, 390, '2026/10/v-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [];
    $generator->deleteThumbnail(390);
    $sql = (string) ($GLOBALS['content_sql'][0] ?? '');
    check('the SQL: content and excerpt each on its own, lowered, trash read, revisions and auto-drafts not', [
        false !== strpos($sql, '(post_content LIKE %s AND (' . implode(' OR ', array_fill(0, count(\Rapls\PDFImageCreator\ContentUse::idLikesSql(392, 'strval')), 'post_content LIKE %s')) . ')) OR (post_excerpt LIKE %s AND (post_excerpt LIKE %s') && false === strpos($sql, 'REGEXP') && false !== strpos($sql, 'LOWER(post_content) LIKE %s OR LOWER(post_excerpt) LIKE %s') && false === strpos($sql, 'CONCAT') && count(\Rapls\PDFImageCreator\ContentUse::fragments(['v-pdf-thumbnail'])) === substr_count($sql, 'LOWER(post_content)'),
        false !== strpos($sql, "post_type NOT IN ('attachment', 'revision')"),
        false !== strpos($sql, "post_status NOT IN ('auto-draft')"),
        1 === preg_match('/\bID > %d\b/', $sql),
    ], [true, true, true, true]);
    // Review of 1.4.39's fixes: each column's ID LIKEs stand behind one for the
    // ID's digits, so a post without them is passed over in one scan.
    check('the SQL: the ID\'s digits asked first, each form only where they are', [
        2 === substr_count($sql, 'LIKE %s AND ('),
        in_array('%392%', (array) ($GLOBALS['content_patterns'][0] ?? []), true) || !isset($GLOBALS['content_patterns']),
    ], [true, true]);
    $GLOBALS['content'] = [];

    // Review of 1.4.39: a widget shows it -- a block widget by its name, an
    // Image widget by its ID; neither was read.
    $GLOBALS['deleted'] = [];
    $shown(412, 410, '2026/10/wg-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [];
    $GLOBALS['options']['widget_block'] = [2 => ['content' => '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.test/wp-content/uploads/2026/10/wg-pdf-thumbnail-300x200.jpg" alt=""/></figure><!-- /wp:image -->'], '_multiwidget' => 1];
    $byBlockWidget = [$generator->deleteThumbnail(410), $GLOBALS['deleted']];
    unset($GLOBALS['options']['widget_block']);
    $shown(414, 413, '2026/10/wi-pdf-thumbnail.jpg');
    $GLOBALS['options']['widget_media_image'] = [3 => ['attachment_id' => 414, 'url' => 'https://cdn.example.test/x.jpg', 'size' => 'medium'], '_multiwidget' => 1];
    $byImageWidget = [$generator->deleteThumbnail(413), $GLOBALS['deleted']];
    unset($GLOBALS['options']['widget_media_image']);
    $shown(416, 415, '2026/10/wn-pdf-thumbnail.jpg');
    $GLOBALS['options']['widget_media_image'] = [3 => ['attachment_id' => 4160, 'url' => 'https://cdn.example.test/x.jpg'], '_multiwidget' => 1];
    $notWidget = [$generator->deleteThumbnail(415), $GLOBALS['deleted']];
    unset($GLOBALS['options']['widget_media_image']);
    check('an image a widget shows is kept: a block widget by name, an Image widget by ID; another ID is not it', [$byBlockWidget, $byImageWidget, $notWidget], [[false, []], [false, []], [true, [416]]]);
    // Review of 1.4.39's fixes: a byte that is not UTF-8 in a widget's text
    // dropped that whole string from the JSON, the image named in it too.
    $GLOBALS['deleted'] = [];
    $shown(418, 417, '2026/10/wu-pdf-thumbnail.jpg');
    $GLOBALS['options']['widget_text'] = [4 => ['text' => "caf\xE9 <img src=\"https://example.test/wp-content/uploads/2026/10/wu-pdf-thumbnail.jpg\">"], '_multiwidget' => 1];
    $byBadText = [$generator->deleteThumbnail(417), $GLOBALS['deleted']];
    unset($GLOBALS['options']['widget_text']);
    check('  ...a widget\'s text with a stray non-UTF-8 byte still shows its image: kept', $byBadText, [false, []]);

    // Review after 1.4.38: a page builder keeps an image by ID alone.
    $GLOBALS['deleted'] = [];
    $shown(402, 400, '2026/10/w-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [735 => '[vc_row][vc_column][vc_single_image image="402" img_size="full"][/vc_column][/vc_row]'];
    check('an image a page builder keeps by ID alone: kept', [$generator->deleteThumbnail(400), $GLOBALS['deleted']], [false, []]);
    // Review of 1.4.38's fixes: the second ID of a list that a space divides.
    $GLOBALS['deleted'] = [];
    $shown(452, 450, '2026/10/g-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [739 => '[gallery ids="7 452" columns="2"]'];
    check('a [gallery] that a space divides, the image second: kept', [$generator->deleteThumbnail(450), $GLOBALS['deleted']], [false, []]);
    $GLOBALS['deleted'] = [];
    $shown(462, 460, '2026/10/h-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [740 => '[acme_image image=\\"462\\"]', 741 => '<!-- wp:acme/card {"id":"0472"} /-->'];
    check('  ...an attribute quoted inside a string: kept', [$generator->deleteThumbnail(460), $GLOBALS['deleted']], [false, []]);
    $GLOBALS['deleted'] = [];
    $shown(482, 480, '2026/10/i-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [742 => '[et_pb_gallery gallery_ids="7 482"]'];
    check('  ...a page builder\'s list that a space divides: kept', [$generator->deleteThumbnail(480), $GLOBALS['deleted']], [false, []]);
    $GLOBALS['deleted'] = [];
    $shown(492, 490, '2026/10/j-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [744 => '[acme image_ids="1 0492"]'];
    check('  ...leading zeros after a space: kept', [$generator->deleteThumbnail(490), $GLOBALS['deleted']], [false, []]);
    $GLOBALS['deleted'] = [];
    $shown(502, 500, '2026/10/k-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [745 => '{"html":"[acme image=\\u0022502\\u0022]"}'];
    check('  ...a quote JSON wrote as \\u0022: kept', [$generator->deleteThumbnail(500), $GLOBALS['deleted']], [false, []]);
    $GLOBALS['deleted'] = [];
    $shown(412, 410, '2026/10/x-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [736 => '[et_pb_gallery gallery_ids="7,4120"]', 737 => '<p>412 apples</p>'];
    check('  ...a longer number, or one in running text: deleted', [$generator->deleteThumbnail(410), $GLOBALS['deleted']], [true, [412]]);
    // The content and the excerpt are read as two, not run together: a
    // name ending the content must not take the excerpt's first word.
    $GLOBALS['deleted'] = [];
    $shown(422, 420, '2026/10/y-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [738 => 'See /wp-content/uploads/2026/10/y-pdf-thumbnail.jpg'];
    $GLOBALS['excerpts'] = [738 => 'more'];
    check('  ...a name that ends the content, an excerpt after it: kept', [$generator->deleteThumbnail(420), $GLOBALS['deleted']], [false, []]);
    $GLOBALS['content'] = [];
    $GLOBALS['excerpts'] = [];

    echo "\n--- Review after 1.4.38: why it was kept, and what is left ---\n";
    // Why a release kept the image, for the notice to say.
    $GLOBALS['deleted'] = [];
    $shown(432, 430, '2026/10/z1-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [740 => '<img src="/u/z1-pdf-thumbnail.jpg">'];
    $generator->deleteThumbnail(430);
    $reasons = [$generator->keptReasonFor(430)];
    $GLOBALS['content'] = [];
    $shown(442, 440, '2026/10/z2-pdf-thumbnail.jpg');
    $GLOBALS['meta'][802]['_thumbnail_id'] = '442';
    $generator->deleteThumbnail(440);
    $reasons[] = $generator->keptReasonFor(440);
    unset($GLOBALS['meta'][802]);
    $shown(452, 450, '2026/10/z3-pdf-thumbnail.jpg');
    $GLOBALS['posts'][451] = (object) ['ID' => 451, 'post_type' => 'attachment', 'post_mime_type' => 'application/pdf'];
    $GLOBALS['meta'][451]['_rapls_pic_thumbnail_id'] = 452;
    $generator->deleteThumbnail(450);
    $reasons[] = $generator->keptReasonFor(450);
    $shown(462, 460, '2026/10/z4-pdf-thumbnail.jpg');
    $GLOBALS['wpdb']->contentFails = true;
    $quiet = ini_set('error_log', '/dev/null');
    $generator->deleteThumbnail(460);
    ini_set('error_log', (string) $quiet);
    $GLOBALS['wpdb']->contentFails = false;
    $reasons[] = $generator->keptReasonFor(460);
    $shown(472, 470, '2026/10/z5-pdf-thumbnail.jpg');
    $generator->deleteThumbnail(470);
    $reasons[] = $generator->keptReasonFor(470);
    check('why it was kept: shown, featured, another PDF\'s, not known; deleted: none', $reasons, ['shown', 'featured', 'shared', 'unknown', null]);
    // Mutation testing of 1.4.39: a reason is this release's, not one left
    // from the PDF's last.
    $shown(434, 430, '2026/10/z6-pdf-thumbnail.jpg');
    $generator->deleteThumbnail(430);
    check('  ...the same PDF\'s next release, deleted: no reason left from the last', $generator->keptReasonFor(430), null);

    // Another attachment that is not a PDF -- an audio file's cover -- is a
    // featured image, not another PDF's thumbnail: ordinary, not left marked.
    $GLOBALS['deleted'] = [];
    $shown(482, 480, '2026/10/z6-pdf-thumbnail.jpg');
    $GLOBALS['posts'][481] = (object) ['ID' => 481, 'post_type' => 'attachment', 'post_mime_type' => 'audio/mpeg'];
    $GLOBALS['meta'][481]['_thumbnail_id'] = '482';
    check('an audio file\'s cover image is no other PDF\'s thumbnail: ordinary', [$generator->deleteThumbnail(480), $GLOBALS['meta'][482]['_rapls_pic_is_thumbnail'] ?? '', $generator->keptReasonFor(480)], [false, '', 'featured']);
    unset($GLOBALS['meta'][481], $GLOBALS['posts'][481]);

    // Refused while the PDF itself is deleted: no next attempt, so ordinary.
    $GLOBALS['deleted'] = [];
    $shown(492, 490, '2026/10/z7-pdf-thumbnail.jpg');
    $GLOBALS['meta'][490]['_rapls_pic_thumbnail_id'] = 492;
    $GLOBALS['refuse_delete'] = [492];
    $refusedLog = sys_get_temp_dir() . '/rapls-pic-own-log-' . bin2hex(random_bytes(4));
    $refusedLogWas = ini_set('error_log', $refusedLog);
    $refused = [$generator->deleteThumbnail(490, true), $GLOBALS['deleted'], $GLOBALS['meta'][492]['_rapls_pic_is_thumbnail'] ?? '', $GLOBALS['meta'][490]['_rapls_pic_thumbnail_id'] ?? ''];
    ini_set('error_log', (string) $refusedLogWas);
    $refusedLogged = is_file($refusedLog) ? (string) file_get_contents($refusedLog) : '';
    @unlink($refusedLog);
    $GLOBALS['refuse_delete'] = [];
    check('deletion refused while the PDF goes: ordinary, let go of, and said', [$refused, false !== strpos($refusedLogged, 'image #492 of PDF #490 could not be deleted with the PDF')], [[false, [], '', ''], true]);
    // Mutation testing of 1.4.39: and said to be a failure, not a keep.
    $shown(496, 495, '2026/10/z8-pdf-thumbnail.jpg');
    $GLOBALS['refuse_delete'] = [496];
    $release = new ReflectionMethod(\Rapls\PDFImageCreator\Generator::class, 'releaseThumbnail');
    if (PHP_VERSION_ID < 80100) { $release->setAccessible(true); }
    $refusedLogWas = ini_set('error_log', '/dev/null');
    $releasedAs = $release->invoke($generator, 495, 496, true);
    ini_set('error_log', (string) $refusedLogWas);
    $GLOBALS['refuse_delete'] = [];
    check('  ...released as failed', $releasedAs, 'failed');

    // An old thumbnail a regeneration kept, still attached to the PDF, goes
    // to the PDF's parent when the PDF is deleted; a marked one stays.
    $GLOBALS['posts'][500] = (object) ['ID' => 500, 'post_type' => 'attachment', 'post_parent' => 77];
    $GLOBALS['posts'][501] = (object) ['ID' => 501, 'post_type' => 'attachment', 'post_parent' => 500];
    $own(502, 500);
    $GLOBALS['posts'][502]->post_parent = 500;
    $GLOBALS['cleaned'] = [];
    // 503: marked, from PDF 500, still the one PDF 500 names -- left alone.
    $own(503, 500);
    $GLOBALS['posts'][503]->post_parent = 500;
    $GLOBALS['meta'][500]['_rapls_pic_thumbnail_id'] = 503;
    $generator->reparentKeptImages(500);
    check('the PDF\'s ordinary images go to its parent, their cache cleared; an orphan marked one (502) made ordinary and moved too; the one it names (503) stays', [$GLOBALS['posts'][501]->post_parent, $GLOBALS['posts'][502]->post_parent, isset($GLOBALS['meta'][502]['_rapls_pic_is_thumbnail']), $GLOBALS['posts'][503]->post_parent, isset($GLOBALS['meta'][503]['_rapls_pic_is_thumbnail'])], [77, 77, false, 500, true]);
    unset($GLOBALS['posts'][500], $GLOBALS['posts'][501], $GLOBALS['posts'][502], $GLOBALS['posts'][503], $GLOBALS['meta'][500], $GLOBALS['meta'][502], $GLOBALS['meta'][503]);

    // A marked orphan from PDF 504 that another PDF (506) names by this
    // plugin's key: handed to 506, attached to it, still marked.
    $GLOBALS['posts'][504] = (object) ['ID' => 504, 'post_type' => 'attachment', 'post_mime_type' => 'application/pdf', 'post_parent' => 77];
    $GLOBALS['posts'][506] = (object) ['ID' => 506, 'post_type' => 'attachment', 'post_mime_type' => 'application/pdf'];
    $own(505, 504);
    $GLOBALS['posts'][505]->post_parent = 504;
    $GLOBALS['meta'][506]['_rapls_pic_thumbnail_id'] = 505;
    $generator->reparentKeptImages(504);
    check('an orphan marked image another PDF names: handed to it and attached to it', [$GLOBALS['meta'][505]['_rapls_pic_source_pdf'] ?? null, isset($GLOBALS['meta'][505]['_rapls_pic_is_thumbnail']), $GLOBALS['posts'][505]->post_parent], [506, true, 506]);
    unset($GLOBALS['posts'][504], $GLOBALS['posts'][505], $GLOBALS['posts'][506], $GLOBALS['meta'][505], $GLOBALS['meta'][506]);

    // The marked lookup fails: nothing marked is touched.
    $GLOBALS['posts'][507] = (object) ['ID' => 507, 'post_type' => 'attachment', 'post_parent' => 77];
    $own(508, 507);
    $GLOBALS['posts'][508]->post_parent = 507;
    $GLOBALS['wpdb']->markedLookupFails = true;
    $generator->reparentKeptImages(507);
    $GLOBALS['wpdb']->markedLookupFails = false;
    check('the marked lookup fails: the marked image stays as it was', [isset($GLOBALS['meta'][508]['_rapls_pic_is_thumbnail']), $GLOBALS['posts'][508]->post_parent], [true, 507]);
    unset($GLOBALS['posts'][507], $GLOBALS['posts'][508], $GLOBALS['meta'][508]);

    // An image with only the source key, no marker -- not this plugin's
    // thumbnail any more -- attached elsewhere: left alone (mutation testing
    // after 1.4.41: the marker's EXISTS was not held).
    $GLOBALS['posts'][509] = (object) ['ID' => 509, 'post_type' => 'attachment', 'post_parent' => 77];
    $GLOBALS['posts'][510] = (object) ['ID' => 510, 'post_type' => 'attachment', 'post_parent' => 88];
    $GLOBALS['meta'][510] = ['_rapls_pic_source_pdf' => 509];
    $generator->reparentKeptImages(509);
    check('an image with the source key and no marker: left alone, its key and its parent as they were', [$GLOBALS['meta'][510]['_rapls_pic_source_pdf'] ?? null, $GLOBALS['posts'][510]->post_parent], [509, 88]);
    unset($GLOBALS['posts'][509], $GLOBALS['posts'][510], $GLOBALS['meta'][510]);

    // No file recorded: its address names it; with neither, kept.
    $GLOBALS['deleted'] = [];
    $own(532, 530);
    $GLOBALS['meta'][530]['_rapls_pic_thumbnail_id'] = 532;
    $GLOBALS['posts'][532]->guid = 'https://example.test/wp-content/uploads/2026/10/zz-pdf-thumbnail.jpg';
    $GLOBALS['content'] = [741 => '<img src="/wp-content/uploads/2026/10/zz-pdf-thumbnail-300x200.jpg">'];
    $GLOBALS['wp_parse_url_calls'] = [];
    $noFile = [$generator->deleteThumbnail(530), $GLOBALS['deleted'], $GLOBALS['wp_parse_url_calls']];
    $GLOBALS['content'] = [];
    check('no file recorded: found by the address WordPress gave it, its path read by core\'s wp_parse_url()', $noFile, [false, [], [['https://example.test/wp-content/uploads/2026/10/zz-pdf-thumbnail.jpg', PHP_URL_PATH]]]);
    // The address is percent-encoded; the post holds the name as it is
    // (review of 1.4.38's fixes).
    $GLOBALS['deleted'] = [];
    $own(534, 533);
    $GLOBALS['meta'][533]['_rapls_pic_thumbnail_id'] = 534;
    $GLOBALS['posts'][534]->guid = 'https://example.test/wp-content/uploads/2026/10/' . rawurlencode('報告') . '-pdf-thumbnail.jpg';
    $GLOBALS['content'] = [743 => '<img src="/wp-content/uploads/2026/10/報告-pdf-thumbnail.jpg">'];
    $encodedGuid = [$generator->deleteThumbnail(533), $GLOBALS['deleted']];
    $GLOBALS['content'] = [];
    check('  ...an address percent-encoded, a post naming it as it is: kept', $encodedGuid, [false, []]);

    // "_" in a name is a letter, not LIKE's any-character: escaped.
    $GLOBALS['deleted'] = [];
    $GLOBALS['content_read'] = [];
    // Two words of its own, so "thumbnail" is not asked (ContentUse::fragments()).
    $shown(552, 550, '2026/10/ab_cd-efgh-ijkl-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [742 => '<img src="/u/abxcd-pdf-thumbnail.jpg">'];
    check('"_" in a name is matched as itself: another name is not read', [$generator->deleteThumbnail(550), $GLOBALS['content_read']], [true, []]);
    $GLOBALS['content'] = [];

    // No postmeta table to ask whether another post names it: kept.
    $GLOBALS['deleted'] = [];
    $shown(562, 560, '2026/10/zy-pdf-thumbnail.jpg');
    $postmetaTable = $GLOBALS['wpdb']->postmeta;
    $GLOBALS['wpdb']->postmeta = null;
    $quietMeta = ini_set('error_log', '/dev/null');
    check('no postmeta table: kept', [$generator->deleteThumbnail(560), $GLOBALS['deleted']], [false, []]);
    ini_set('error_log', (string) $quietMeta);
    $GLOBALS['wpdb']->postmeta = $postmetaTable;

    echo "\n--- Review of 1.4.36: deleting a thumbnail, or its PDF ---\n";

    // A thumbnail deleted on its own from the Media Library: the PDF stops
    // naming it, rather than naming an attachment that is gone.
    $own(282, 280);
    $GLOBALS['meta'][280]['_rapls_pic_thumbnail_id'] = 282;
    $GLOBALS['mimes'][282] = 'image/jpeg';
    $library->onAttachmentDeleted(282);
    check('a thumbnail deleted on its own: its PDF lets go of it', $GLOBALS['meta'][280]['_rapls_pic_thumbnail_id'] ?? '', '');
    $GLOBALS['meta'][290]['_rapls_pic_thumbnail_id'] = 999;
    $own(292, 290);
    $GLOBALS['mimes'][292] = 'image/jpeg';
    $library->onAttachmentDeleted(292);
    check('  ...a PDF naming another image keeps it', $GLOBALS['meta'][290]['_rapls_pic_thumbnail_id'] ?? '', 999);
    $GLOBALS['mimes'][299] = 'image/jpeg';
    $GLOBALS['meta'][299]['_rapls_pic_source_pdf'] = 280;
    $GLOBALS['meta'][280]['_rapls_pic_thumbnail_id'] = 299;
    $library->onAttachmentDeleted(299);
    check('  ...an image without the marker is not a thumbnail', $GLOBALS['meta'][280]['_rapls_pic_thumbnail_id'] ?? '', 299);

    // Deleting a PDF with "Hide Generated Images" off: its image is kept,
    // and attached where the PDF was, not to an item that is gone.
    $own(312, 310);
    $GLOBALS['posts'][310] = (object) ['ID' => 310, 'post_type' => 'attachment', 'post_parent' => 7];
    $GLOBALS['posts'][312]->post_parent = 310;
    $GLOBALS['meta'][310]['_rapls_pic_thumbnail_id'] = 312;
    $GLOBALS['mimes'][310] = 'application/pdf';
    $library->onAttachmentDeleted(310);
    check('PDF deleted, images kept: the image is attached to the PDF\'s parent', [$GLOBALS['posts'][312]->post_parent, $GLOBALS['meta'][312]['_rapls_pic_is_thumbnail'] ?? ''], [7, '']);
    check('  ...written to the row, its cache cleared, no wp_update_post() and its hooks (review after 1.4.36)', [$GLOBALS['update_post_calls'] ?? 0, in_array(312, $GLOBALS['cleaned'] ?? [], true)], [0, true]);
    $own(317, 315);
    $GLOBALS['posts'][315] = (object) ['ID' => 315, 'post_type' => 'attachment', 'post_parent' => 7];
    $GLOBALS['posts'][317]->post_parent = 555;
    $GLOBALS['meta'][315]['_rapls_pic_thumbnail_id'] = 317;
    $GLOBALS['mimes'][315] = 'application/pdf';
    $library->onAttachmentDeleted(315);
    check('  ...one attached to something else stays there', $GLOBALS['posts'][317]->post_parent, 555);
    // An old thumbnail a regeneration kept, an ordinary image still
    // attached to the PDF: moved to the PDF's parent too (review after 1.4.38).
    $GLOBALS['posts'][520] = (object) ['ID' => 520, 'post_type' => 'attachment', 'post_parent' => 8];
    $GLOBALS['posts'][521] = (object) ['ID' => 521, 'post_type' => 'attachment', 'post_parent' => 520];
    $GLOBALS['mimes'][520] = 'application/pdf';
    $library->onAttachmentDeleted(520);
    check('  ...an old thumbnail a regeneration kept goes to the PDF\'s parent too', $GLOBALS['posts'][521]->post_parent, 8);
    // ...not a post that names the PDF as its parent: only attachments move.
    $GLOBALS['posts'][525] = (object) ['ID' => 525, 'post_type' => 'attachment', 'post_parent' => 8];
    $GLOBALS['posts'][526] = (object) ['ID' => 526, 'post_type' => 'page', 'post_parent' => 525];
    $GLOBALS['mimes'][525] = 'application/pdf';
    $library->onAttachmentDeleted(525);
    check('  ...a post whose parent is the PDF is not moved', $GLOBALS['posts'][526]->post_parent, 525);
    unset($GLOBALS['posts'][520], $GLOBALS['posts'][521], $GLOBALS['posts'][525], $GLOBALS['posts'][526]);
    // A thumbnail shared with a translation's copy, its PDF deleted with
    // "Hide Generated Images" on (off, the image is let go of as an ordinary
    // one, as above): the
    // translation's now, attached to it, not to the PDF that is gone (review
    // of 1.4.39's fixes).
    $own(532, 530);
    $GLOBALS['posts'][530] = (object) ['ID' => 530, 'post_type' => 'attachment', 'post_parent' => 9];
    $GLOBALS['posts'][531] = (object) ['ID' => 531, 'post_type' => 'attachment', 'post_parent' => 9, 'post_mime_type' => 'application/pdf'];
    $GLOBALS['posts'][532]->post_parent = 530;
    $GLOBALS['meta'][530]['_rapls_pic_thumbnail_id'] = 532;
    $GLOBALS['meta'][531]['_rapls_pic_thumbnail_id'] = 532;
    $GLOBALS['mimes'][530] = 'application/pdf';
    $GLOBALS['mimes'][531] = 'application/pdf';
    $prop->setValue($settings, ['hide_generated_images' => true]);
    $library->onAttachmentDeleted(530);
    $prop->setValue($settings, ['hide_generated_images' => false]);
    check('  ...one a translation shares goes to that translation, still its thumbnail', [$GLOBALS['posts'][532]->post_parent, (int) ($GLOBALS['meta'][532]['_rapls_pic_source_pdf'] ?? 0), $GLOBALS['meta'][532]['_rapls_pic_is_thumbnail'] ?? ''], [531, 531, '1']);
    unset($GLOBALS['posts'][530], $GLOBALS['posts'][531], $GLOBALS['posts'][532]);

    // With it on, and a post showing the image: the same.
    $prop->setValue($settings, ['hide_generated_images' => true]);
    $shown(322, 320, '2026/10/p-pdf-thumbnail.jpg');
    $GLOBALS['posts'][320] = (object) ['ID' => 320, 'post_type' => 'attachment', 'post_parent' => 0];
    $GLOBALS['posts'][322]->post_parent = 320;
    $GLOBALS['mimes'][320] = 'application/pdf';
    $GLOBALS['content'] = [720 => '<img src="/p-pdf-thumbnail.jpg">'];
    $library->onAttachmentDeleted(320);
    check('  ...and with "Hide Generated Images" on, for one a post shows', [isset($GLOBALS['posts'][322]), $GLOBALS['posts'][322]->post_parent], [true, 0]);
    $GLOBALS['content'] = [];

    // Regenerating keeps the parent: the PDF is still there.
    $shown(332, 330, '2026/10/q-pdf-thumbnail.jpg');
    $GLOBALS['posts'][332]->post_parent = 330;
    $GLOBALS['content'] = [721 => '<img src="/q-pdf-thumbnail.jpg">'];
    $generator->deleteThumbnail(330);
    check('  ...not when only the thumbnail is let go of', $GLOBALS['posts'][332]->post_parent, 330);
    $GLOBALS['content'] = [];

    // Review of 1.4.40: a hand-over with "Hide Generated Images" off, and on
    // a regeneration, attaches the image to the PDF that takes it.
    $own(542, 540);
    $GLOBALS['posts'][540] = (object) ['ID' => 540, 'post_type' => 'attachment', 'post_parent' => 9];
    $GLOBALS['posts'][541] = (object) ['ID' => 541, 'post_type' => 'attachment', 'post_parent' => 9, 'post_mime_type' => 'application/pdf'];
    $GLOBALS['posts'][542]->post_parent = 540;
    $GLOBALS['meta'][540]['_rapls_pic_thumbnail_id'] = 542;
    $GLOBALS['meta'][541]['_rapls_pic_thumbnail_id'] = 542;
    $GLOBALS['mimes'][540] = 'application/pdf';
    $GLOBALS['mimes'][541] = 'application/pdf';
    $prop->setValue($settings, ['hide_generated_images' => false]);
    $library->onAttachmentDeleted(540);
    check('"Hide Generated Images" off: one a translation shares is handed to it, still its thumbnail', [$GLOBALS['posts'][542]->post_parent, (int) ($GLOBALS['meta'][542]['_rapls_pic_source_pdf'] ?? 0), $GLOBALS['meta'][542]['_rapls_pic_is_thumbnail'] ?? ''], [541, 541, '1']);
    unset($GLOBALS['posts'][540], $GLOBALS['posts'][541], $GLOBALS['posts'][542]);
    $GLOBALS['deleted'] = [];
    $own(552, 550);
    $GLOBALS['posts'][551] = (object) ['ID' => 551, 'post_type' => 'attachment', 'post_parent' => 0, 'post_mime_type' => 'application/pdf'];
    $GLOBALS['posts'][552]->post_parent = 550;
    $GLOBALS['meta'][550]['_rapls_pic_thumbnail_id'] = 552;
    $GLOBALS['meta'][551]['_rapls_pic_thumbnail_id'] = 552;
    $generator->deleteThumbnail(550);
    check('  ...and when only the thumbnail is let go of: attached to that PDF too', [$GLOBALS['deleted'], $GLOBALS['posts'][552]->post_parent, (int) ($GLOBALS['meta'][552]['_rapls_pic_source_pdf'] ?? 0)], [[], 551, 551]);
    // ...a PDF whose ID is 1 takes it as any other does.
    $own(692, 690);
    $GLOBALS['posts'][1] = (object) ['ID' => 1, 'post_type' => 'attachment', 'post_parent' => 0, 'post_mime_type' => 'application/pdf'];
    $GLOBALS['meta'][690]['_rapls_pic_thumbnail_id'] = 692;
    $GLOBALS['meta'][1]['_rapls_pic_thumbnail_id'] = 692;
    $generator->deleteThumbnail(690);
    check('  ...PDF 1 takes it as any PDF does', [(int) ($GLOBALS['meta'][692]['_rapls_pic_source_pdf'] ?? 0), $GLOBALS['meta'][692]['_rapls_pic_is_thumbnail'] ?? ''], [1, '1']);
    unset($GLOBALS['meta'][1], $GLOBALS['posts'][1]);
    // Review of 1.4.40: a PDF whose `_thumbnail_id` alone names the image
    // (its own key names another) does not take it; it becomes ordinary.
    $own(562, 560);
    $GLOBALS['posts'][561] = (object) ['ID' => 561, 'post_type' => 'attachment', 'post_parent' => 0, 'post_mime_type' => 'application/pdf'];
    $GLOBALS['meta'][560]['_rapls_pic_thumbnail_id'] = 562;
    $GLOBALS['meta'][561]['_rapls_pic_thumbnail_id'] = 999;
    $GLOBALS['meta'][561]['_thumbnail_id'] = 562;
    $generator->deleteThumbnail(560);
    check('  ...a PDF that names it only as its featured image: not handed to it, an ordinary image', [$GLOBALS['deleted'], $GLOBALS['meta'][562]['_rapls_pic_is_thumbnail'] ?? '', $GLOBALS['meta'][562]['_rapls_pic_source_pdf'] ?? '', $generator->keptReasonFor(560)], [[], '', '', 'featured']);
    // ...and of several that do, the lowest ID each time.
    $own(572, 570);
    $GLOBALS['meta'][579] = ['_rapls_pic_thumbnail_id' => 572];
    $GLOBALS['meta'][571] = ['_rapls_pic_thumbnail_id' => 572];
    $GLOBALS['posts'][571] = (object) ['ID' => 571, 'post_type' => 'attachment', 'post_mime_type' => 'application/pdf'];
    $GLOBALS['posts'][579] = (object) ['ID' => 579, 'post_type' => 'attachment', 'post_mime_type' => 'application/pdf'];
    $GLOBALS['meta'][570]['_rapls_pic_thumbnail_id'] = 572;
    $generator->deleteThumbnail(570);
    check('  ...of two PDFs that name it, the lowest ID takes it', (int) ($GLOBALS['meta'][572]['_rapls_pic_source_pdf'] ?? 0), 571);
    // Mutation testing after 1.4.41: an image the user attached to a post
    // is handed over and stays attached to that post.
    $own(6592, 6590);
    $GLOBALS['posts'][6591] = (object) ['ID' => 6591, 'post_type' => 'attachment', 'post_parent' => 0, 'post_mime_type' => 'application/pdf'];
    $GLOBALS['posts'][6592]->post_parent = 77;
    $GLOBALS['meta'][6590]['_rapls_pic_thumbnail_id'] = 6592;
    $GLOBALS['meta'][6591]['_rapls_pic_thumbnail_id'] = 6592;
    $generator->deleteThumbnail(6590);
    check('  ...one the user attached to a post: handed over, still attached to the post', [(int) ($GLOBALS['meta'][6592]['_rapls_pic_source_pdf'] ?? 0), $GLOBALS['posts'][6592]->post_parent], [6591, 77]);
    // ...and an attachment that is no PDF -- an audio file carrying this
    // plugin's key -- does not take it.
    $own(6597, 6595);
    $GLOBALS['posts'][6596] = (object) ['ID' => 6596, 'post_type' => 'attachment', 'post_parent' => 0, 'post_mime_type' => 'audio/mpeg'];
    $GLOBALS['meta'][6595]['_rapls_pic_thumbnail_id'] = 6597;
    $GLOBALS['meta'][6596]['_rapls_pic_thumbnail_id'] = 6597;
    $generator->deleteThumbnail(6595);
    check('  ...an audio file carrying the key: not handed to it, an ordinary image', [$GLOBALS['meta'][6597]['_rapls_pic_source_pdf'] ?? '', $GLOBALS['meta'][6597]['_rapls_pic_is_thumbnail'] ?? ''], ['', '']);
    // Review of 1.4.40: a refused deletion for a PDF whose own key is empty
    // -- named only by its `_thumbnail_id` -- is left for the next attempt.
    $own(582, 580);
    $GLOBALS['meta'][580] = ['_thumbnail_id' => 582];
    $GLOBALS['refuse_delete'] = [582];
    $refused = $generator->deleteThumbnail(580);
    $GLOBALS['refuse_delete'] = [];
    check('a refused deletion, the PDF\'s own key empty: left as it was', [$refused, $GLOBALS['meta'][582]['_rapls_pic_is_thumbnail'] ?? '', (int) ($GLOBALS['meta'][580]['_thumbnail_id'] ?? 0)], [false, '1', 582]);
    // Review of 1.4.40: the content and the excerpt each on its own, as the
    // SQL asks them; joined, a number ending one and the ID starting the
    // other made an ID.
    $GLOBALS['deleted'] = [];
    $shown(592, 590, '2026/10/z-pdf-thumbnail.jpg');
    $GLOBALS['content'] = [760 => 'z-pdf-thumbnail-notes 1024'];
    $GLOBALS['excerpts'] = [760 => '592 apples'];
    check('the content and the excerpt are read each on its own', [$generator->deleteThumbnail(590), $GLOBALS['deleted']], [true, [592]]);
    $GLOBALS['content'] = [];
    $GLOBALS['excerpts'] = [];
    // Review of 1.4.40: the widgets, each kind, and their values as they are.
    $widgetKept = static function (int $image, int $pdf, string $file, string $option, $value) use ($shown, $generator): array {
        $GLOBALS['deleted'] = [];
        $shown($image, $pdf, $file);
        $GLOBALS['options'][$option] = $value;
        $result = [$generator->deleteThumbnail($pdf), $GLOBALS['deleted']];
        unset($GLOBALS['options'][$option]);
        return $result;
    };
    check('a block widget naming the image with "--", written as a block writes it: kept',
        $widgetKept(602, 600, '2026/10/r--pdf-thumbnail.jpg', 'widget_block', [2 => ['content' => '<!-- wp:cover {"url":"https://example.test/wp-content/uploads/2026/10/r\u002d\u002dpdf-thumbnail.jpg"} /-->'], '_multiwidget' => 1]), [false, []]);
    check('  ...a Gallery widget by ID: kept',
        $widgetKept(612, 610, '2026/10/gw-pdf-thumbnail.jpg', 'widget_media_gallery', [5 => ['ids' => [7, 612], 'columns' => 3], '_multiwidget' => 1]), [false, []]);
    check('  ...a Custom HTML widget by name: kept',
        $widgetKept(622, 620, '2026/10/hw-pdf-thumbnail.jpg', 'widget_custom_html', [6 => ['title' => '', 'content' => '<img src="/wp-content/uploads/2026/10/hw-pdf-thumbnail-150x150.jpg">'], '_multiwidget' => 1]), [false, []]);
    $deep = ['attachment_id' => 632];
    for ($i = 0; $i < 600; $i++) { $deep = [$deep]; }
    check('  ...nested deeper than JSON allows, with a NaN beside it: still read, kept',
        $widgetKept(632, 630, '2026/10/dw-pdf-thumbnail.jpg', 'widget_media_image', [3 => $deep, 4 => ['x' => NAN, 'y' => INF]]), [false, []]);
    check('  ...an object a plugin stored, read as its properties: kept, as shown',
        [$widgetKept(642, 640, '2026/10/ow-pdf-thumbnail.jpg', 'widget_media_image', [3 => (object) ['attachment_id' => 642]]), $generator->keptReasonFor(640)], [[false, []], 'shown']);
    $loop = ['a' => 1];
    $loop['self'] = &$loop;
    check('  ...one that holds itself: cannot tell, kept, as unknown',
        [$widgetKept(652, 650, '2026/10/lw-pdf-thumbnail.jpg', 'widget_text', [4 => $loop]), $generator->keptReasonFor(650)], [[false, []], 'unknown']);
    unset($loop);
    check('  ...and a widget that names something else does not keep it',
        $widgetKept(662, 660, '2026/10/nw-pdf-thumbnail.jpg', 'widget_media_gallery', [5 => ['ids' => [6620, 7]], '_multiwidget' => 1]), [true, [662]]);
    $GLOBALS['options']['widget_media_gallery'] = [5 => ['ids' => [7]]];
    $limitWas = ini_get('pcre.backtrack_limit');
    $jitWas = ini_get('pcre.jit');
    $GLOBALS['deleted'] = [];
    $shown(672, 670, '2026/10/pw-pdf-thumbnail.jpg');
    $GLOBALS['starve_pcre_on'] = ['widget_media_gallery' => true];
    try {
        $pcreKept = [$generator->deleteThumbnail(670), $GLOBALS['deleted']];
    } finally {
        $GLOBALS['starve_pcre_on'] = [];
        ini_set('pcre.backtrack_limit', (string) $limitWas);
        ini_set('pcre.jit', (string) $jitWas);
    }
    unset($GLOBALS['options']['widget_media_gallery']);
    check('  ...widgets the ID pattern cannot be run over: kept', $pcreKept, [false, []]);

    // Uninstalling keeps what something else uses too, as deleteThumbnail()
    // does. test-uninstall.php runs uninstall.php for that, query failures
    // included.

    echo "\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}
