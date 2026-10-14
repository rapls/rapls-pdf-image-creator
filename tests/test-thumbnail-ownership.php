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
    // Review after 1.4.36: the parent is written to the row, not through wp_update_post() and its hooks.
    function wp_update_post($args) {
        $GLOBALS['update_post_calls'] = ($GLOBALS['update_post_calls'] ?? 0) + 1;
        return $args['ID'];
    }
    function clean_post_cache($id) { $GLOBALS['cleaned'][] = $id; }
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
            // The content lookup (review of 1.4.35, 1.4.36): the posts after
            // the first argument whose content holds any of the LIKE
            // patterns, as MySQL would, ignoring case. Revisions and the
            // states the SQL leaves out are left out here too.
            if (false === strpos($q[0], 'post_content')) {
                throw new \RuntimeException('unexpected get_results: ' . $q[0]);
            }
            $this->contentQueries++;
            $this->last_error = $this->contentFails ? 'simulated failure' : '';
            if ($this->contentFails) { return []; }
            $after = (int) $q[1][0];
            $skip = [];
            foreach ($GLOBALS['content_kind'] ?? [] as $id => $kind) {
                if (false !== strpos($q[0], "'" . $kind . "'")) { $skip[] = $id; }
            }
            // As the SQL reads: the stem patterns, OR'd; then, grouped, the
            // ID's digits AND one of the forms (review after 1.4.36).
            $likes = array_slice($q[1], 1);
            $forms = [];
            $digits = null;
            if (preg_match('/\(post_content LIKE %s AND \(((?:post_content LIKE %s(?: OR )?)+)\)\)/', $q[0], $group)) {
                $forms = array_splice($likes, -substr_count($group[1], 'LIKE'));
                $digits = array_pop($likes);
            }
            $holds = static function (string $html, string $like): bool {
                return false !== stripos($html, stripslashes(substr($like, 1, -1)));
            };
            $rows = [];
            foreach ($GLOBALS['content'] ?? [] as $id => $html) {
                if ($id <= $after || in_array($id, $skip, true)) { continue; }
                $hit = false;
                foreach ($likes as $like) {
                    $hit = $hit || $holds($html, $like);
                }
                if (!$hit && null !== $digits && $holds($html, $digits)) {
                    foreach ($forms as $form) {
                        $hit = $hit || $holds($html, $form);
                    }
                }
                if ($hit) { $rows[] = ['ID' => (string) $id, 'post_content' => $html]; }
            }
            $GLOBALS['content_read'] = array_merge($GLOBALS['content_read'] ?? [], array_column($rows, 'ID'));
            ksort($rows);
            return preg_match('/LIMIT (\d+)/', $q[0], $limit) ? array_slice($rows, 0, (int) $limit[1]) : $rows;
        }
        /** Fails only the "named by another attachment" lookup. */
        public $attachmentLookupFails = false;
        public function get_var($q) {
            $failing = $this->fail || ($this->attachmentLookupFails && false !== strpos($q[0], "post_type = 'attachment'"));
            $this->last_error = $failing ? 'simulated failure' : '';
            if ($failing) { return null; }
            // As MySQL would: the PDF itself is left out only by the clause
            // that says so (mutation testing after 1.4.34).
            [$metaA, $metaB, $value] = $q[1];
            $except = preg_match('/\bAND post_id <> %d\b/', $q[0]) ? ($q[1][3] ?? null) : null;
            // Only attachments name it, when the SQL says so (another PDF).
            $attachmentsOnly = false !== strpos($q[0], "post_type = 'attachment'");
            foreach ($GLOBALS['meta'] as $post => $keys) {
                if (null !== $except && (int) $post === (int) $except) { continue; }
                if ($attachmentsOnly && 'attachment' !== ($GLOBALS['posts'][$post]->post_type ?? '')) { continue; }
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
    $GLOBALS['content_kind'] = [715 => 'revision', 716 => 'trash'];
    check('only a revision and a trashed post show it: deleted', [$generator->deleteThumbnail(250), $GLOBALS['deleted']], [true, [252]]);
    $GLOBALS['content_kind'] = [];

    // A post's featured image only, and no other PDF: an ordinary image, as
    // the settings screen says; it stayed marked and hidden.
    $GLOBALS['deleted'] = [];
    $shown(262, 260, '2026/10/n-pdf-thumbnail.jpg');
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
    check('named elsewhere, whether by another PDF unknown: ordinary, and said', [$namedKept, false !== strpos($namedLogged, 'could not tell whether another PDF uses image #266')], [[false, [], ''], true]);
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

    // Uninstalling keeps what something else uses too, as deleteThumbnail()
    // does. test-uninstall.php runs uninstall.php for that, query failures
    // included.

    echo "\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}
