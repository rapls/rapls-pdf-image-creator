<?php
/**
 * A forced regeneration keeps the old thumbnail until the new one is the
 * PDF's (R55-03), never touches a featured image this plugin did not make
 * (R55-02), and the AJAX routes that start one ask about this PDF, not only
 * about uploading in general (R55-01).
 *
 * generate() is run end to end with a stand-in engine that writes a file.
 */

declare(strict_types=1);

namespace {
    define('RAPLS_PIC_PLUGIN_DIR', dirname(__DIR__) . '/');

    // generate() loads wp-admin/includes/image.php; an empty one will do.
    // Under the run's own folder when run.php gives one, and removed when
    // this suite ends: one was left behind on every run, some 110 files each
    // (review after 1.4.37).
    $base = getenv('RAPLS_PIC_TEST_TMP');
    $base = is_string($base) && '' !== $base && is_dir($base) ? $base : sys_get_temp_dir();
    $abs = $base . '/rapls-pic-regen-' . bin2hex(random_bytes(4)) . '/';
    mkdir($abs . 'wp-admin/includes', 0777, true);
    // Not in the child that dies while drawing: that one removes its own
    // folder last, after the plugin's shutdown function has had its turn,
    // which is what it checks.
    '1' === getenv('RAPLS_PIC_DIE_WHILE_DRAWING') || register_shutdown_function(static function () use ($abs): void {
        if (!is_dir($abs)) {
            return;
        }
        $tree = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($tree as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($abs);
    });
    file_put_contents($abs . 'wp-admin/includes/image.php', "<?php\n");
    define('ABSPATH', $abs);
    $uploads = $abs . 'uploads';
    // For glob(): a "[" in the temporary folder's name begins a character class.
    $uploadsGlob = str_replace(['[', '?'], ['[[]', '[?]'], $uploads);
    mkdir($uploads);

    $GLOBALS['meta'] = [];
    $GLOBALS['posts'] = [];
    $GLOBALS['mimes'] = [];
    $GLOBALS['files'] = [];
    $GLOBALS['options'] = [];
    $GLOBALS['deleted'] = [];
    $GLOBALS['next_id'] = 500;
    $GLOBALS['fail_meta'] = '';
    $GLOBALS['caps'] = [];

    function __($s, $d = null) { return $s; }
    if (!function_exists('_n')) { function _n($one, $many, $n, $d = null) { return 1 === (int) $n ? $one : $many; } }
    function apply_filters($tag, $value, ...$args) { return isset($GLOBALS['filters'][$tag]) ? ($GLOBALS['filters'][$tag])($value) : $value; }
    function do_action($tag, ...$args) { if (isset($GLOBALS['actions'][$tag])) { ($GLOBALS['actions'][$tag])(...$args); } }
    function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
    function update_option($key, $value, $autoload = null) {
        $GLOBALS['option_writes'][] = $key;
        $GLOBALS['option_autoload'][$key] = $autoload;
        // As core: false when nothing changes, and when the write fails.
        if (($GLOBALS['options'][$key] ?? null) === $value || !empty($GLOBALS['option_write_fails'])) { return false; }
        $GLOBALS['options'][$key] = $value;
        return true;
    }
    function delete_option($key) { unset($GLOBALS['options'][$key]); return true; }
    function get_post($id) {
        if ((int) $id === ($GLOBALS['get_post_throws'] ?? 0)) { throw new \RuntimeException('get_post threw'); }
        return $GLOBALS['posts'][(int) $id] ?? null;
    }
    function get_post_mime_type($id) { return $GLOBALS['mimes'][(int) $id] ?? false; }
    // This request's object cache: a value read earlier stays until the
    // post's meta is evicted or written, as core's does.
    // $single as core answers it: the value, or a list of every value
    // (mutation testing after 1.4.37).
    function get_post_meta($id, $key, $single = false) {
        if (isset($GLOBALS['meta_cache'][(int) $id]) && array_key_exists($key, $GLOBALS['meta_cache'][(int) $id])) {
            $value = $GLOBALS['meta_cache'][(int) $id][$key];
        } elseif (isset($GLOBALS['meta'][(int) $id][$key])) {
            $value = $GLOBALS['meta'][(int) $id][$key];
        } else {
            return $single ? '' : [];
        }
        return $single ? $value : [$value];
    }
    function update_post_meta($id, $key, $value) {
        unset($GLOBALS['meta_cache'][(int) $id]);
        if ($key === $GLOBALS['fail_meta'] || (is_array($GLOBALS['fail_meta']) && in_array($key . ':' . $value, $GLOBALS['fail_meta'], true))) { return false; }
        $GLOBALS['meta'][(int) $id][$key] = $value;
        return true;
    }
    function delete_post_meta($id, $key) { unset($GLOBALS['meta'][(int) $id][$key]); return true; }
    function get_attached_file($id) { return $GLOBALS['files'][(int) $id] ?? false; }
    function wp_upload_dir() { return ['basedir' => $GLOBALS['uploads'], 'baseurl' => 'https://example.test/uploads']; }
    function wp_delete_file($path) { @unlink($path); }
    function wp_get_attachment_image_url($id, $size = 'thumbnail') { return 'https://example.test/' . (int) $id . '.jpg'; }
    function sanitize_file_name($name) { return preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $name); }
    function wp_check_filetype($name) { $ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION)); return ['ext' => $ext, 'type' => ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? false]; }
    function is_wp_error($thing) { return false; }
    function wp_insert_attachment($data, $file, $parent) {
        if (!empty($GLOBALS['insert_fails'])) {
            return 0;
        }
        $id = $GLOBALS['next_id']++;
        $GLOBALS['posts'][$id] = (object) ['ID' => $id, 'post_type' => 'attachment', 'post_mime_type' => $data['post_mime_type']];
        // An update_attached_file filter that names another file (R58-02).
        $GLOBALS['files'][$id] = $GLOBALS['attach_elsewhere'] ?? $file;
        // A listener on add_attachment or save_post that throws, after the row is in.
        if (!empty($GLOBALS['insert_listener_dies'])) {
            throw new \RuntimeException('a listener died');
        }
        return $id;
    }
    function update_attached_file($id, $file) {
        if (!empty($GLOBALS['attach_stuck'])) { return true; }
        $GLOBALS['files'][(int) $id] = $file;
        return true;
    }
    function wp_generate_attachment_metadata($id, $file) {
        if (!empty($GLOBALS['metadata_dies'])) {
            throw new \RuntimeException('a listener died');
        }
        // As core past big_image_size_threshold, on an output-format
        // conversion or an EXIF rotation: a copy beside the file, named by the
        // attachment, the drawn file kept as original_image (review of 1.4.39).
        if (!empty($GLOBALS['core_scales'])) {
            $scaled = preg_replace('/(\.[^.]+)$/', '-scaled$1', $file);
            copy($file, $scaled);
            $GLOBALS['files'][(int) $id] = $scaled;
            return ['file' => basename($scaled), 'sizes' => [], 'original_image' => $GLOBALS['core_scales'] === 'elsewhere' ? 'someone-elses.jpg' : basename($file)];
        }
        return !empty($GLOBALS['no_metadata']) ? false : ['file' => basename($file), 'sizes' => []];
    }
    function wp_update_attachment_metadata($id, $meta) {
        if (!empty($GLOBALS['fail_attachment_meta'])) { return false; }
        $GLOBALS['attachment_meta'][(int) $id] = $meta;
        return true;
    }
    function wp_get_attachment_metadata($id) { return $GLOBALS['attachment_meta'][(int) $id] ?? false; }
    function wp_delete_attachment($id, $force = false) {
        if ((int) $id === ($GLOBALS['undeletable'] ?? 0) || !empty($GLOBALS['nothing_deletes'])) { return false; }
        // pre_delete_attachment answering with the post (R63-01): truthy, nothing deleted.
        if ((int) $id === ($GLOBALS['pretends'] ?? 0)) { return $GLOBALS['posts'][(int) $id] ?? null; }
        // Deleted by someone else first: core answers null, and it is gone.
        if ((int) $id === ($GLOBALS['vanishes'] ?? 0)) { unset($GLOBALS['posts'][(int) $id]); return null; }
        $GLOBALS['deleted'][] = (int) $id;
        $post = $GLOBALS['posts'][(int) $id] ?? null;
        unset($GLOBALS['posts'][(int) $id]);
        if (isset($GLOBALS['files'][(int) $id])) { @unlink($GLOBALS['files'][(int) $id]); }
        // As core: every _thumbnail_id naming it goes.
        foreach ($GLOBALS['meta'] as $post_id => $keys) {
            if ((int) ($keys['_thumbnail_id'] ?? 0) === (int) $id) { unset($GLOBALS['meta'][$post_id]['_thumbnail_id']); }
        }
        return $post ?? false;
    }
    function current_user_can($cap, ...$args) {
        $key = $args ? $cap . ':' . $args[0] : $cap;
        return !empty($GLOBALS['caps'][$key]);
    }
    function check_ajax_referer($action = -1, $q = false, $die = true) { $GLOBALS['nonce_asked'][] = [$action, $q]; return empty($GLOBALS['nonce_fails']); }
    function absint($v) { return abs((int) $v); }
    final class JsonSent extends \Exception {
        public $success; public $data; public $status;
        public function __construct($success, $data, $status) { parent::__construct('json'); $this->success = $success; $this->data = $data; $this->status = $status; }
    }
    // The first answer is the one sent: core dies there. BulkProcessor's own
    // catch (Throwable) would otherwise turn the stand-in's exception into a
    // second, different answer.
    function rapls_test_json($success, $d, $s) {
        $GLOBALS['json'] = $GLOBALS['json'] ?? [$success, $s];
        $GLOBALS['json_message'] = $GLOBALS['json_message'] ?? (is_array($d) ? ($d['message'] ?? null) : null);
        $GLOBALS['json_data'] = $GLOBALS['json_data'] ?? $d;
        throw new JsonSent($success, $d, $s);
    }
    function wp_send_json_error($d = null, $s = null) { rapls_test_json(false, $d, $s); }
    function wp_send_json_success($d = null, $s = null) { rapls_test_json(true, $d, $s); }

    require __DIR__ . '/fake-content-sql.php';
    require RAPLS_PIC_PLUGIN_DIR . 'includes/FailureCode.php';
    require RAPLS_PIC_PLUGIN_DIR . 'includes/Settings.php';
    require RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ConversionResult.php';
    require RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/EngineInterface.php';
    require RAPLS_PIC_PLUGIN_DIR . 'includes/Generator.php';
    require RAPLS_PIC_PLUGIN_DIR . 'includes/BulkProcessor.php';
    // Bulk Generate says why an old thumbnail was kept in Admin's words.
    require RAPLS_PIC_PLUGIN_DIR . 'includes/Admin.php';
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

    // Nobody else uses anything here, as far as the reverse lookup can tell.
    // No post's content shows anything either, unless a test says so.
    $GLOBALS['content'] = [];
    $GLOBALS['wpdb'] = new class {
        public $postmeta = 'wp_postmeta';
        public $posts = 'wp_posts';
        public $last_error = '';
        public function prepare($sql, ...$args) { return [$sql, $args]; }
        public function esc_like($text) { return addcslashes($text, '_%\\'); }
        public $contentFails = false;
        public $contentQueries = 0;
        /** Whether another post names the image, from $GLOBALS['meta'] (review after 1.4.38). */
        public function get_var($q) {
            $this->last_error = '';
            [$keyA, $keyB, $value, $except] = $q[1] + [null, null, null, null];
            $pdfsOnly = false !== strpos($q[0], "post_mime_type = 'application/pdf'");
            foreach ($GLOBALS['meta'] as $post => $keys) {
                if ((int) $post === (int) $except) { continue; }
                if ($pdfsOnly && 'application/pdf' !== ($GLOBALS['mimes'][$post] ?? '')) { continue; }
                foreach ([$keyA, $keyB] as $key) {
                    if (isset($keys[$key]) && (string) $keys[$key] === (string) $value) { return (string) $post; }
                }
            }
            return null;
        }
        public function get_results($q, $output = null) {
            if (false === strpos($q[0], 'post_content')) { return []; }
            $this->contentQueries++;
            $this->last_error = $this->contentFails ? 'simulated failure' : '';
            if ($this->contentFails) { return []; }
            // As MySQL would answer the SQL given (fake-content-sql.php).
            return rapls_test_content_rows($q, $GLOBALS['content'], $GLOBALS['excerpts'] ?? []);
        }
    };

    final class StandInEngine implements \Rapls\PDFImageCreator\Engine\EngineInterface {
        public $renders = true;
        public function getName(): string { return 'stand-in'; }
        public function getDisplayName(): string { return 'Stand-in'; }
        public function isAvailable(): bool { return true; }
        public function getAvailabilityStatus(): array { return ['code' => 'ok', 'label' => '', 'summary' => '', 'action' => '', 'detail' => '']; }
        public function getRequirements(): array { return []; }
        public $sawReserved = null;
        public $throws = false;
        public $format = null;
        public $options = [];
        /** Run while drawing: another request at the same moment. */
        public $during = null;
        public function convert(string $pdfPath, string $outputPath, array $options = []): \Rapls\PDFImageCreator\Engine\ConversionResult {
            $this->sawReserved = is_file($outputPath) && 0 === filesize($outputPath);
            if (is_callable($this->during)) { ($this->during)(); }
            $this->format = $options['format'] ?? null;
            $this->options = $options;
            if ($this->throws) { throw new \RuntimeException('engine boom'); }
            if (!$this->renders) { return \Rapls\PDFImageCreator\Engine\ConversionResult::failure('render failed'); }
            file_put_contents($outputPath, 'jpeg');
            return \Rapls\PDFImageCreator\Engine\ConversionResult::success($outputPath);
        }
    }

    $settings = (new ReflectionClass(\Rapls\PDFImageCreator\Settings::class))->newInstanceWithoutConstructor();
    $settingsProp = new ReflectionProperty(\Rapls\PDFImageCreator\Settings::class, 'settings');
    if (PHP_VERSION_ID < 80100) { $settingsProp->setAccessible(true); }
    $useSettings = static function (array $values) use ($settings, $settingsProp): void {
        $settingsProp->setValue($settings, array_merge($settings->getDefaults(), $values));
    };
    $useSettings(['set_featured' => false]);

    $engine = new StandInEngine();
    $generator = (new ReflectionClass(\Rapls\PDFImageCreator\Generator::class))->newInstanceWithoutConstructor();
    foreach (['settings' => $settings, 'engines' => ['stand-in' => $engine]] as $name => $value) {
        $prop = new ReflectionProperty(\Rapls\PDFImageCreator\Generator::class, $name);
        if (PHP_VERSION_ID < 80100) { $prop->setAccessible(true); }
        $prop->setValue($generator, $value);
    }

    /** A PDF with its file on disk and, when asked, a thumbnail it made. */
    $pdf = static function (int $id, ?int $thumbnail = null, bool $fileThere = true) use ($uploads): void {
        $GLOBALS['posts'][$id] = (object) ['ID' => $id, 'post_type' => 'attachment'];
        $GLOBALS['mimes'][$id] = 'application/pdf';
        $GLOBALS['files'][$id] = $uploads . "/doc-$id.pdf";
        if ($fileThere) { file_put_contents($GLOBALS['files'][$id], '%PDF-1.4'); }
        if (null !== $thumbnail) {
            $GLOBALS['posts'][$thumbnail] = (object) ['ID' => $thumbnail, 'post_type' => 'attachment'];
            $GLOBALS['files'][$thumbnail] = $uploads . "/doc-$id-pdf-thumbnail.jpg";
            file_put_contents($GLOBALS['files'][$thumbnail], 'old jpeg');
            $GLOBALS['meta'][$thumbnail]['_rapls_pic_is_thumbnail'] = '1';
            $GLOBALS['meta'][$thumbnail]['_rapls_pic_source_pdf'] = $id;
            $GLOBALS['meta'][$id]['_rapls_pic_thumbnail_id'] = $thumbnail;
        }
    };

    // Review after 1.4.33: run again in a child that dies while drawing, as a
    // request does at max_execution_time. The shutdown function this
    // registered deletes the reserved file; nothing else runs.
    if ('1' === getenv('RAPLS_PIC_DIE_WHILE_DRAWING')) {
        $pdf(207);
        $engine->during = static function () use ($uploadsGlob, $abs): void {
            echo 'drawing:', count(glob($uploadsGlob . '/doc-207-pdf-thumbnail*')), "\n";
            register_shutdown_function(static function () use ($uploadsGlob, $abs): void {
                echo 'after:', count(glob($uploadsGlob . '/doc-207-pdf-thumbnail*'));
                $tree = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($tree as $node) { $node->isDir() ? @rmdir($node->getPathname()) : @unlink($node->getPathname()); }
                @rmdir($abs);
            });
            exit(1);
        };
        $generator->generate(207, true);
        echo 'survived';
        exit(0);
    }

    echo "--- R55-03: the old thumbnail stays until the new one is the PDF's ---\n";

    $pdf(10, 11, false);   // the PDF's file is missing
    $GLOBALS['deleted'] = [];
    check('PDF file missing: nothing made, old thumbnail kept', [$generator->generate(10, true), $GLOBALS['deleted'], $GLOBALS['meta'][10]['_rapls_pic_thumbnail_id'] ?? null], [null, [], 11]);

    $pdf(20, 21);
    $engine->renders = false;
    check('render fails: old thumbnail kept', [$generator->generate(20, true), $GLOBALS['deleted'], $GLOBALS['meta'][20]['_rapls_pic_thumbnail_id'] ?? null], [null, [], 21]);
    $engine->renders = true;

    $pdf(30, 31);
    $GLOBALS['fail_meta'] = '_rapls_pic_thumbnail_id';
    $made = $generator->generate(30, true);
    $GLOBALS['fail_meta'] = '';
    check('thumbnail meta not saved: new image removed, old kept', [$made, $GLOBALS['meta'][30]['_rapls_pic_thumbnail_id'] ?? null, in_array(31, $GLOBALS['deleted'], true), count($GLOBALS['deleted'])], [null, 31, false, 1]);

    $pdf(40, 41);
    $GLOBALS['deleted'] = [];
    $new = $generator->generate(40, true);
    check('success: the PDF names the new one, the old one is deleted after', [is_int($new) && 41 !== $new, $GLOBALS['meta'][40]['_rapls_pic_thumbnail_id'] ?? null, $GLOBALS['deleted']], [true, $new, [41]]);

    // A post shows the old one (review of 1.4.35): an image block given the
    // PDF holds its address. Regenerating deleted the file under the post.
    $pdf(45, 46);
    $GLOBALS['meta'][46]['_wp_attached_file'] = '2026/10/doc-45-pdf-thumbnail.jpg';
    $GLOBALS['content'] = [900 => '<img src="/wp-content/uploads/2026/10/doc-45-pdf-thumbnail-300x212.jpg" class="wp-image-46">'];
    $GLOBALS['deleted'] = [];
    $keptLog = sys_get_temp_dir() . '/rapls-pic-regen-log-' . bin2hex(random_bytes(4));
    $keptLogWas = ini_set('error_log', $keptLog);
    $new = $generator->generate(45, true);
    ini_set('error_log', (string) $keptLogWas);
    $keptLogged = is_file($keptLog) ? (string) file_get_contents($keptLog) : '';
    @unlink($keptLog);
    check('a post shows the old one: the PDF takes the new one, the old one stays',
        [is_int($new) && 46 !== $new, $GLOBALS['meta'][45]['_rapls_pic_thumbnail_id'] ?? null, $GLOBALS['deleted'], isset($GLOBALS['posts'][46]), $GLOBALS['meta'][46]['_rapls_pic_is_thumbnail'] ?? ''],
        [true, $new, [], true, '']);
    check('  ...kept on purpose, so not logged as a delete that failed', false !== strpos($keptLogged, 'could not be deleted'), false);
    // Review after 1.4.37: and said where it was regenerated -- the post
    // still shows the old picture.
    check('  ...and the outcome says the old one was kept', $generator->outcomeFor(45)['kept_previous'] ?? 'none', 46);
    $GLOBALS['content'] = [];
    $generator->generate(40, true);
    $deletedOutcome = $generator->outcomeFor(40) ?? [];
    check('  ...one deleted is not said to be kept', [array_key_exists('kept_previous', $deletedOutcome), $deletedOutcome['kept_previous'] ?? null], [true, null]);

    // Review of 1.4.36: the posts are read once. Asking again whether the
    // image was "used elsewhere" after keeping it read them a second time.
    $pdf(47, 48);
    $GLOBALS['meta'][48]['_wp_attached_file'] = '2026/10/doc-47-pdf-thumbnail.jpg';
    $GLOBALS['content'] = [901 => '<img src="/wp-content/uploads/2026/10/doc-47-pdf-thumbnail.jpg">'];
    $GLOBALS['wpdb']->contentQueries = 0;
    $generator->generate(47, true);
    check('  ...and the posts are read once', $GLOBALS['wpdb']->contentQueries, 1);
    $GLOBALS['content'] = [];

    // The content lookup fails while regenerating: the PDF already names its
    // new thumbnail, so the old one cannot wait for "the next attempt". It
    // stayed marked -- hidden -- with nothing pointing at it, and nothing
    // was logged (review of 1.4.36). It is now an ordinary image, and said.
    $pdf(49, 59);
    $GLOBALS['wpdb']->contentFails = true;
    $GLOBALS['deleted'] = [];
    $failLog = sys_get_temp_dir() . '/rapls-pic-regen-log-' . bin2hex(random_bytes(4));
    $failLogWas = ini_set('error_log', $failLog);
    $new = $generator->generate(49, true);
    ini_set('error_log', (string) $failLogWas);
    $failLogged = is_file($failLog) ? (string) file_get_contents($failLog) : '';
    @unlink($failLog);
    $GLOBALS['wpdb']->contentFails = false;
    check('the content lookup fails: the old one is kept as an ordinary image',
        [is_int($new) && 59 !== $new, $GLOBALS['deleted'], isset($GLOBALS['posts'][59]), $GLOBALS['meta'][59]['_rapls_pic_is_thumbnail'] ?? '', $GLOBALS['meta'][59]['_rapls_pic_source_pdf'] ?? ''],
        [true, [], true, '', '']);
    check('  ...and that is logged', false !== strpos($failLogged, 'could not tell whether a post shows image #59'), true);

    echo "\n--- R55-02: a featured image this plugin did not make is left alone ---\n";

    $pdf(50, 51);
    $GLOBALS['posts'][88] = (object) ['ID' => 88, 'post_type' => 'attachment'];
    $GLOBALS['meta'][50]['_thumbnail_id'] = 88;   // chosen by hand
    $GLOBALS['deleted'] = [];
    $new = $generator->generate(50, true);
    check('regenerate: the hand-picked featured image stays', [$GLOBALS['meta'][50]['_thumbnail_id'] ?? null, isset($GLOBALS['posts'][88]), $GLOBALS['deleted']], [88, true, [51]]);
    $GLOBALS['deleted'] = [];
    $generator->deleteThumbnail(50);
    check('deleteThumbnail: removes only the key that names the image', [$GLOBALS['meta'][50]['_thumbnail_id'] ?? null, $GLOBALS['meta'][50]['_rapls_pic_thumbnail_id'] ?? null], [88, null]);

    // No thumbnail of its own, only a hand-picked featured image: a forced
    // regeneration used to unlink it; the new one is found first anyway.
    $pdf(60);
    $GLOBALS['meta'][60]['_thumbnail_id'] = 88;
    $GLOBALS['deleted'] = [];
    $new = $generator->generate(60, true);
    check('featured image only: new thumbnail made, featured image kept', [is_int($new), $GLOBALS['meta'][60]['_thumbnail_id'] ?? null, $GLOBALS['deleted']], [true, 88, []]);

    $useSettings(['set_featured' => true]);
    $pdf(70, 71);
    $GLOBALS['meta'][70]['_thumbnail_id'] = 71;
    $GLOBALS['deleted'] = [];
    $new = $generator->generate(70, true);
    check('set_featured on: the featured image moves to the new one', [$GLOBALS['meta'][70]['_thumbnail_id'] ?? null, $GLOBALS['deleted']], [$new, [71]]);
    $useSettings(['set_featured' => false]);

    echo "\n--- R56-01: the new image is kept only with its marks and metadata ---\n";

    foreach (['_rapls_pic_is_thumbnail', '_rapls_pic_source_pdf'] as $i => $mark) {
        $pdf(100 + $i * 10, 101 + $i * 10);
        $GLOBALS['deleted'] = [];
        $before = $GLOBALS['next_id'];
        $GLOBALS['fail_meta'] = $mark;
        $made = $generator->generate(100 + $i * 10, true);
        $GLOBALS['fail_meta'] = '';
        check("$mark not saved: fails, new image removed, old kept", [$made, $GLOBALS['deleted'], $GLOBALS['meta'][100 + $i * 10]['_rapls_pic_thumbnail_id'] ?? null], [null, [$before], 101 + $i * 10]);
    }
    $pdf(120, 121);
    $GLOBALS['deleted'] = [];
    $before = $GLOBALS['next_id'];
    $GLOBALS['fail_attachment_meta'] = true;
    $made = $generator->generate(120, true);
    $GLOBALS['fail_attachment_meta'] = false;
    check('attachment metadata not saved: fails, new removed, old kept', [$made, $GLOBALS['deleted'], $GLOBALS['meta'][120]['_rapls_pic_thumbnail_id'] ?? null], [null, [$before], 121]);

    echo "\n--- R56-02: a featured image that was not saved is not success ---\n";

    $useSettings(['set_featured' => true]);
    $pdf(130, 131);
    $GLOBALS['meta'][130]['_thumbnail_id'] = 131;
    $GLOBALS['deleted'] = [];
    $before = $GLOBALS['next_id'];
    $GLOBALS['fail_meta'] = ['_thumbnail_id:' . $before];
    $made = $generator->generate(130, true);
    $GLOBALS['fail_meta'] = '';
    check('_thumbnail_id not saved: fails, the PDF keeps the old one', [$made, (int) ($GLOBALS['meta'][130]['_rapls_pic_thumbnail_id'] ?? 0), $GLOBALS['meta'][130]['_thumbnail_id'] ?? null, $GLOBALS['deleted']], [null, 131, 131, [$before]]);
    $pdf(140, 141);
    $GLOBALS['meta'][140]['_thumbnail_id'] = 141;
    $GLOBALS['deleted'] = [];
    $before = $GLOBALS['next_id'];
    $GLOBALS['fail_meta'] = ['_thumbnail_id:' . $before, '_rapls_pic_thumbnail_id:141'];
    $made = $generator->generate(140, true);
    $GLOBALS['fail_meta'] = '';
    check('  ...and when putting it back fails too, nothing is deleted', [$made, $GLOBALS['meta'][140]['_rapls_pic_thumbnail_id'] ?? null, $GLOBALS['deleted'], isset($GLOBALS['posts'][141])], [null, $before, [], true]);
    $useSettings(['set_featured' => false]);

    echo "\n--- R56-03: an image that could not be deleted keeps its link ---\n";

    $pdf(150, 151);
    $GLOBALS['undeletable'] = 151;
    check('deleteThumbnail: delete fails, the PDF keeps its link', [$generator->deleteThumbnail(150), $GLOBALS['meta'][150]['_rapls_pic_thumbnail_id'] ?? null], [false, 151]);
    $GLOBALS['undeletable'] = 0;

    echo "\n--- R57-01: a failed featured image puts each key back as it was ---\n";

    $useSettings(['set_featured' => true]);
    $featuredFails = static function (int $pdfId) use ($generator): array {
        $GLOBALS['deleted'] = [];
        $before = $GLOBALS['next_id'];
        $GLOBALS['fail_meta'] = ['_thumbnail_id:' . $before];
        $made = $generator->generate($pdfId, true);
        $GLOBALS['fail_meta'] = '';
        return [$made, (int) ($GLOBALS['meta'][$pdfId]['_rapls_pic_thumbnail_id'] ?? 0), (int) ($GLOBALS['meta'][$pdfId]['_thumbnail_id'] ?? 0), $GLOBALS['deleted'] === [$before]];
    };
    $pdf(160);
    $GLOBALS['meta'][160]['_thumbnail_id'] = 88;
    check('A: no own thumbnail, featured 88: own key stays empty', $featuredFails(160), [null, 0, 88, true]);
    $pdf(170, 171);
    $GLOBALS['meta'][170]['_thumbnail_id'] = 88;
    check('B: own 171, featured 88: both as they were', $featuredFails(170), [null, 171, 88, true]);
    $pdf(180, 181);
    $GLOBALS['meta'][180]['_thumbnail_id'] = 181;
    check('C: own 181, featured 181: both as they were', $featuredFails(180), [null, 181, 181, true]);
    $useSettings(['set_featured' => false]);

    echo "\n--- R57-02: no metadata is not success ---\n";

    $pdf(190, 191);
    $GLOBALS['deleted'] = [];
    $before = $GLOBALS['next_id'];
    $GLOBALS['no_metadata'] = true;
    $made = $generator->generate(190, true);
    $GLOBALS['no_metadata'] = false;
    check('metadata could not be generated: fails, new removed, old kept', [$made, $GLOBALS['deleted'], $GLOBALS['meta'][190]['_rapls_pic_thumbnail_id'] ?? null], [null, [$before], 191]);

    echo "\n--- O56-01: the output name is taken, not only looked at ---\n";

    $pdf(200);
    $generator->generate(200, true);
    check('the engine draws into a file this request already created', $engine->sawReserved, true);
    // Review of 1.4.32: a request that dies while drawing (max_execution_time)
    // runs no catch; the shutdown clean-up deletes the reserved file. One that
    // got as far as the Media Library keeps its image.
    $pdf(205);
    $reservedSeen = null;
    $engine->during = static function () use (&$reservedSeen, $uploadsGlob): void {
        $files = glob($uploadsGlob . '/doc-205-pdf-thumbnail*');
        \Rapls\PDFImageCreator\Generator::releaseHeld();
        $reservedSeen = [count($files), count(glob($uploadsGlob . '/doc-205-pdf-thumbnail*'))];
    };
    $generator->generate(205, true);
    $engine->during = null;
    $pdf(206);
    $kept = $generator->generate(206, true);
    \Rapls\PDFImageCreator\Generator::releaseHeld();
    check('a request that dies while drawing leaves no reserved file; one that finished keeps its image', [$reservedSeen, is_int($kept) && is_file($GLOBALS['files'][$kept] ?? '')], [[1, 0], true]);
    // ...by the shutdown function itself, in a request that really dies.
    $died = [];
    exec('RAPLS_PIC_DIE_WHILE_DRAWING=1 ' . escapeshellarg(PHP_BINARY) . ' -d error_reporting=E_ALL -d display_errors=stderr ' . escapeshellarg(__FILE__) . ' 2>&1', $died);
    check('  ...and in a request that dies there, the shutdown function deletes it', implode("\n", $died), "drawing:1\nafter:0");
    $pdf(210, 211);
    $engine->renders = false;
    $leftBefore = glob($uploadsGlob . '/doc-210-pdf-thumbnail*');
    $generator->generate(210, true);
    $engine->renders = true;
    check('  ...and a failed render leaves no empty file behind', glob($uploadsGlob . '/doc-210-pdf-thumbnail*'), $leftBefore);
    // Review after 1.4.32: a request that dies after the attachment is in the
    // Media Library -- making sub-sizes, or in a listener on the metadata --
    // leaves that attachment its file. Bulk Generate catches the error and
    // carries on, so shutdown still runs releaseHeld().
    $pdf(212);
    $idsBefore = $GLOBALS['next_id'];
    $GLOBALS['metadata_dies'] = true;
    try {
        $generator->generate(212, true);
    } catch (\RuntimeException $e) {
    }
    $GLOBALS['metadata_dies'] = false;
    \Rapls\PDFImageCreator\Generator::releaseHeld();
    $afterMetadata = is_file((string) ($GLOBALS['files'][$idsBefore] ?? ''));
    $pdf(213);
    $idsBefore = $GLOBALS['next_id'];
    $GLOBALS['insert_listener_dies'] = true;
    try {
        $generator->generate(213, true);
    } catch (\RuntimeException $e) {
    }
    $GLOBALS['insert_listener_dies'] = false;
    \Rapls\PDFImageCreator\Generator::releaseHeld();
    // The hold is let go before the insert, so an insert that makes nothing
    // deletes the drawn file itself.
    $pdf(214);
    $GLOBALS['insert_fails'] = true;
    $noInsert = $generator->generate(214, true);
    $GLOBALS['insert_fails'] = false;
    $insertLeft = glob($uploadsGlob . '/doc-214-pdf-thumbnail*');
    check('  ...and one that dies after the attachment was added leaves the attachment its file -- in the metadata, or in a listener on the insert; an insert that makes nothing leaves no file', [$afterMetadata, is_file((string) ($GLOBALS['files'][$idsBefore] ?? '')), $noInsert, $insertLeft], [true, true, null, []]);

    echo "\n--- R58-01: a new attachment that cannot be deleted keeps its file ---\n";

    $pdf(220, 221);
    $GLOBALS['nothing_deletes'] = true;
    $GLOBALS['fail_meta'] = '_rapls_pic_source_pdf';
    $before = $GLOBALS['next_id'];
    $made = $generator->generate(220, true);
    $GLOBALS['fail_meta'] = '';
    $GLOBALS['nothing_deletes'] = false;
    check('delete refused: fails, and the attachment still has its file', [$made, isset($GLOBALS['posts'][$before]), is_file((string) ($GLOBALS['files'][$before] ?? ''))], [null, true, true]);

    echo "\n--- R58-02: the attachment must name the file drawn ---\n";

    $pdf(230, 231);
    $other = $uploads . '/someone-elses.jpg';
    file_put_contents($other, 'not ours');
    $GLOBALS['attach_elsewhere'] = $other;
    $before = $GLOBALS['next_id'];
    $made = $generator->generate(230, true);
    unset($GLOBALS['attach_elsewhere']);
    check('named another file: pointed back, removed, old kept', [$made, isset($GLOBALS['posts'][$before]), is_file($other), $GLOBALS['meta'][230]['_rapls_pic_thumbnail_id'] ?? null], [null, false, true, 231]);
    $pdf(240, 241);
    $GLOBALS['attach_elsewhere'] = $other;
    $GLOBALS['attach_stuck'] = true;
    $before = $GLOBALS['next_id'];
    $made = $generator->generate(240, true);
    unset($GLOBALS['attach_elsewhere']);
    $GLOBALS['attach_stuck'] = false;
    check('  ...cannot be pointed back: nothing deleted, not even the other file', [$made, isset($GLOBALS['posts'][$before]), is_file($other)], [null, true, true]);

    // Review of 1.4.39: core's own "-scaled" copy past 2560 pixels a side
    // names the attachment, the drawn file is its original_image. Every
    // such thumbnail was thrown away, and the -scaled file left on disk.
    $pdf(242, 243);
    $GLOBALS['core_scales'] = true;
    $before = $GLOBALS['next_id'];
    $made = $generator->generate(242, true);
    unset($GLOBALS['core_scales']);
    check('core named its -scaled copy, the drawn file as original_image: kept, and the PDF names it', [$made, (int) ($GLOBALS['meta'][242]['_rapls_pic_thumbnail_id'] ?? 0), (bool) preg_match('/-scaled\.[a-z]+$/', (string) ($GLOBALS['files'][$before] ?? ''))], [$before, $before, true]);
    $pdf(244, 245);
    $GLOBALS['core_scales'] = 'elsewhere';
    $before = $GLOBALS['next_id'];
    $made = $generator->generate(244, true);
    unset($GLOBALS['core_scales']);
    check('  ...but an original_image that is another file is still another file: removed, old kept', [$made, isset($GLOBALS['posts'][$before]), $GLOBALS['meta'][244]['_rapls_pic_thumbnail_id'] ?? null], [null, false, 245]);

    echo "\n--- R58-03: an old thumbnail that cannot be deleted does not fail the new one ---\n";

    $pdf(280, 281);
    $GLOBALS['undeletable'] = 281;
    $made = $generator->generate(280, true);
    $GLOBALS['undeletable'] = 0;
    check('old delete refused: new one kept and returned, old one still there', [is_int($made), (int) ($GLOBALS['meta'][280]['_rapls_pic_thumbnail_id'] ?? 0) === $made, isset($GLOBALS['posts'][281])], [true, true, true]);
    // Review of 1.4.39: and an ordinary image, not one marked and hidden
    // that nothing names.
    check('  ...the old one kept as an ordinary image', [isset($GLOBALS['meta'][281]['_rapls_pic_is_thumbnail']), isset($GLOBALS['meta'][281]['_rapls_pic_source_pdf'])], [false, false]);

    echo "\n--- R58-04: exceptions do not leave files, nor turn success into failure ---\n";

    $pdf(250, 251);
    $engine->throws = true;
    $leftBefore = glob($uploadsGlob . '/doc-250-pdf-thumbnail*');
    $caught = null;
    try { $generator->generate(250, true); } catch (\RuntimeException $e) { $caught = $e->getMessage(); }
    $engine->throws = false;
    check('engine throws: passed on, and no empty file left', [$caught, glob($uploadsGlob . '/doc-250-pdf-thumbnail*')], ['engine boom', $leftBefore]);
    $pdf(260, 261);
    $GLOBALS['actions']['rapls_pdf_image_creator_after_generate'] = static function () { throw new \RuntimeException('after hook boom'); };
    $threw = null;
    try { $generator->generate(260, true); } catch (\RuntimeException $e) { $threw = $e->getMessage(); }
    unset($GLOBALS['actions']['rapls_pdf_image_creator_after_generate']);
    $told = $generator->outcomeFor(260);
    // R60-03: passed on, as WordPress does; the outcome is already recorded (R58-04, R60-02).
    check('after_generate throws: passed on, and the outcome says made', [$threw, $told['ok'] ?? null, $told['thumbnail_id'] ?? null, (int) ($GLOBALS['meta'][260]['_rapls_pic_thumbnail_id'] ?? 0) === ($told['thumbnail_id'] ?? -1)], ['after hook boom', true, $told['thumbnail_id'] ?? 'none', true]);

    echo "\n--- R58-05: the format filter decides the name and the type too ---\n";

    $pdf(270);
    $GLOBALS['filters']['rapls_pdf_image_creator_thumbnail_format'] = static function () { return 'PNG'; };
    $made = $generator->generate(270, true);
    unset($GLOBALS['filters']['rapls_pdf_image_creator_thumbnail_format']);
    check('filtered to PNG: engine, extension and MIME agree', [$engine->format, pathinfo((string) $GLOBALS['files'][$made], PATHINFO_EXTENSION), $GLOBALS['posts'][$made]->post_mime_type], ['png', 'png', 'image/png']);

    echo "\n--- R59-01 / R59-02: failures that cannot be cleaned up or observed ---\n";

    $log = sys_get_temp_dir() . '/rapls-pic-regen-log-' . bin2hex(random_bytes(4));
    $logWas = ini_set('error_log', $log);
    $pdf(290, 291);
    $GLOBALS['nothing_deletes'] = true;
    $GLOBALS['fail_meta'] = ['_rapls_pic_thumbnail_id:' . $GLOBALS['next_id']];
    $before = $GLOBALS['next_id'];
    $made = $generator->generate(290, true);
    $GLOBALS['fail_meta'] = '';
    $GLOBALS['nothing_deletes'] = false;
    check('R59-01: new image not recorded nor deletable: said in the log', [$made, false !== strpos((string) @file_get_contents($log), "new thumbnail #$before of PDF #290")], [null, true]);

    $pdf(300, 301, false);   // file missing
    $GLOBALS['actions']['rapls_pdf_image_creator_generation_failed'] = static function () { throw new \RuntimeException('listener boom'); };
    $threw = false;
    try { $generator->generate(300, true); } catch (\Throwable $e) { $threw = true; }
    unset($GLOBALS['actions']['rapls_pdf_image_creator_generation_failed']);
    check('R59-02/R60-02: a failing listener does not replace the failure', [$threw, $generator->outcomeFor(300)['code'] ?? null, $GLOBALS['options'][\Rapls\PDFImageCreator\Generator::LAST_FAILURE_OPTION]['code'] ?? null], [true, 'source_missing', 'source_missing']);

    // R60-01: an attachment that did not come out as drawn, and cannot be deleted, is said.
    $pdf(310, 311);
    $GLOBALS['nothing_deletes'] = true;
    $GLOBALS['fail_meta'] = '_rapls_pic_source_pdf';
    $before = $GLOBALS['next_id'];
    $made = $generator->generate(310, true);
    $GLOBALS['fail_meta'] = '';
    $GLOBALS['nothing_deletes'] = false;
    check('R60-01: invalid new attachment not deletable: kept with its file, said', [$made, isset($GLOBALS['posts'][$before]), is_file((string) ($GLOBALS['files'][$before] ?? '')), false !== strpos((string) @file_get_contents($log), "new thumbnail #$before of PDF #310 did not come out as drawn")], [null, true, true, true]);
    $pdf(320, 321);
    $generator->generate(320, true);
    $engine->throws = true;
    try { $generator->generate(320, true); } catch (\RuntimeException $e) {}
    $engine->throws = false;
    check('outcomeFor: an attempt that threw before an outcome leaves none, not the last one', $generator->outcomeFor(320), null);
    // R62-02: forgotten from generate()'s first line, before anything that can throw.
    foreach ([true, false] as $lastWorked) {
        $pdf(330, 331, $lastWorked);
        $generator->generate(330, true);
        $GLOBALS['get_post_throws'] = 330;
        try { $generator->generate(330, true); } catch (\RuntimeException $e) {}
        $GLOBALS['get_post_throws'] = 0;
        check('R62-02: the first check throws: no outcome, not the last ' . ($lastWorked ? 'success' : 'failure'), $generator->outcomeFor(330), null);
    }
    // Review after 1.4.38: every outcome has the keys its docblock lists,
    // kept_previous among them, also for a thumbnail that was already there.
    $pdf(340, 341);
    $generator->generate(340, true);
    $generator->generate(340, false);
    check('outcomeFor: a thumbnail already there has kept_previous too (null)', array_key_exists('kept_previous', (array) $generator->outcomeFor(340)) && null === $generator->outcomeFor(340)['kept_previous'], true);
    check('outcomeFor: a new attempt forgets the last one; unknown PDF is null', [$generator->outcomeFor(310)['ok'] ?? null, $generator->outcomeFor(999999)], [false, null]);
    ini_set('error_log', (string) $logWas);
    @unlink($log);

    echo "\n--- R59-03: Settings::save() is judged by what is stored ---\n";

    $fresh = new \Rapls\PDFImageCreator\Settings();
    $GLOBALS['options'][\Rapls\PDFImageCreator\Settings::OPTION_NAME] = ['max_width' => 1000];
    $fresh->get(true);
    $GLOBALS['options'][\Rapls\PDFImageCreator\Settings::OPTION_NAME] = ['max_width' => 1200, 'quality' => 70];   // changed elsewhere
    check('already so: true, and the copy is no longer stale', [$fresh->save(['max_width' => 1200]), $fresh->getMaxWidth()], [true, 1200]);
    $fresh->save(['max_height' => 900]);
    check('  ...a later save keeps what someone else changed', $GLOBALS['options'][\Rapls\PDFImageCreator\Settings::OPTION_NAME]['quality'] ?? null, 70);
    $GLOBALS['option_write_fails'] = true;
    check('  ...a write that did not happen is false', $fresh->save(['max_width' => 800]), false);
    $GLOBALS['option_write_fails'] = false;

    echo "\n--- R63-01: deleted means gone, whatever the return value ---\n";

    $pdf(340, 341);
    $GLOBALS['pretends'] = 341;
    check('A: pre_delete answers with the post: not deleted, the PDF keeps its link', [$generator->deleteThumbnail(340), isset($GLOBALS['posts'][341]), (int) ($GLOBALS['meta'][340]['_rapls_pic_thumbnail_id'] ?? 0)], [false, true, 341]);
    $GLOBALS['pretends'] = 0;

    $log = sys_get_temp_dir() . '/rapls-pic-regen-log-' . bin2hex(random_bytes(4));
    $logWas = ini_set('error_log', $log);
    $pdf(350, 351);
    $GLOBALS['pretends'] = $GLOBALS['next_id'];
    $GLOBALS['fail_meta'] = '_rapls_pic_source_pdf';
    $before = $GLOBALS['next_id'];
    $made = $generator->generate(350, true);
    $GLOBALS['fail_meta'] = '';
    $GLOBALS['pretends'] = 0;
    check('B: new image "deleted" by a truthy pre_delete: still there, and said', [$made, isset($GLOBALS['posts'][$before]), is_file((string) ($GLOBALS['files'][$before] ?? '')), false !== strpos((string) @file_get_contents($log), "new thumbnail #$before of PDF #350")], [null, true, true, true]);
    ini_set('error_log', (string) $logWas);
    @unlink($log);

    $pdf(360, 361);
    $GLOBALS['vanishes'] = 361;
    check('C: already gone (null): counted as deleted, the PDF lets go', [$generator->deleteThumbnail(360), $GLOBALS['meta'][360]['_rapls_pic_thumbnail_id'] ?? null], [true, null]);
    $GLOBALS['vanishes'] = 0;

    echo "\n--- R55-01: the AJAX routes ask about this PDF ---\n";

    $library = (new ReflectionClass(\Rapls\PDFImageCreator\MediaLibrary::class))->newInstanceWithoutConstructor();
    $bulk = (new ReflectionClass(\Rapls\PDFImageCreator\BulkProcessor::class))->newInstanceWithoutConstructor();
    foreach ([[\Rapls\PDFImageCreator\MediaLibrary::class, $library], [\Rapls\PDFImageCreator\BulkProcessor::class, $bulk]] as [$class, $object]) {
        $prop = new ReflectionProperty($class, 'generator');
        if (PHP_VERSION_ID < 80100) { $prop->setAccessible(true); }
        $prop->setValue($object, $generator);
    }
    $call = static function (callable $route, array $post): array {
        $_POST = $post;
        $GLOBALS['json'] = null;
        try {
            $route();
        } catch (JsonSent $sent) {
        }
        return $GLOBALS['json'] ?? ['no answer', null];
    };

    $pdf(80, 81);
    $pdf(90, 91);
    $GLOBALS['caps'] = ['upload_files' => true, 'edit_post:80' => true];
    $GLOBALS['deleted'] = [];
    // Review after 1.4.31: the AJAX regenerate endpoint is gone -- nothing on
    // any screen called it; the row action (handleRegenerateAction()) does it.
    $librarySource = (string) file_get_contents(RAPLS_PIC_PLUGIN_DIR . 'includes/MediaLibrary.php');
    check('Media Library: no AJAX regenerate endpoint is left to answer', [method_exists($library, 'ajaxRegenerateThumbnail'), false !== strpos($librarySource, "wp_ajax_rapls_pic_regenerate_thumbnail")], [false, false]);
    check('Bulk Generate: refused without manage_options', $call([$bulk, 'ajaxGenerate'], ['pdf_id' => 80, 'force' => 1]), [false, 403]);
    check('Bulk Scan: refused without manage_options', $call([$bulk, 'ajaxScan'], []), [false, 403]);

    // Review of 1.4.32: every Bulk route checks the page's nonce (field
    // "nonce", action rapls_pic_admin) before anything else, admin or not.
    $GLOBALS['caps'] = ['manage_options' => true, 'edit_post:80' => true];
    $GLOBALS['nonce_fails'] = true;
    $GLOBALS['nonce_asked'] = [];
    $refusedRoutes = [];
    foreach (['ajaxScan' => [], 'ajaxNext' => ['after' => 0], 'ajaxGenerate' => ['pdf_id' => 80, 'force' => 1], 'ajaxStatus' => []] as $route => $post) {
        $GLOBALS['deleted'] = [];
        $GLOBALS['json_message'] = null;
        $refusedRoutes[$route] = [$call([$bulk, $route], $post)[0], $GLOBALS['json_message'], $GLOBALS['deleted']];
    }
    $GLOBALS['nonce_fails'] = false;
    check('Bulk: a stale or missing nonce is refused on every route, and nothing is done', [$refusedRoutes, array_unique(array_map('json_encode', $GLOBALS['nonce_asked']))], [array_fill_keys(['ajaxScan', 'ajaxNext', 'ajaxGenerate', 'ajaxStatus'], [false, 'Security check failed.', []]), ['["rapls_pic_admin","nonce"]']]);

    // Review of 1.4.32: the Media Library's "Regenerate Thumbnail" row action --
    // its nonce for this PDF, edit_post on it, and back to the list as it was.
    if (!function_exists('sanitize_text_field')) { function sanitize_text_field($v) { return trim((string) $v); } }
    if (!function_exists('wp_unslash')) { function wp_unslash($v) { return $v; } }
    if (!function_exists('esc_html__')) { function esc_html__($s, $d = null) { return $s; } }
    if (!function_exists('wp_verify_nonce')) { function wp_verify_nonce($n, $a) { return 'ok:' . $a === $n; } }
    final class RegenStopped extends \Exception {}
    if (!function_exists('wp_die')) { function wp_die($m = '') { throw new RegenStopped('died: ' . $m); } }
    if (!function_exists('wp_safe_redirect')) { function wp_safe_redirect($u, $s = 302) { throw new RegenStopped('to: ' . $u); } }
    if (!function_exists('admin_url')) { function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; } }
    if (!function_exists('add_query_arg')) { function add_query_arg($k, $v, $u) { return $u . (false === strpos($u, '?') ? '?' : '&') . $k . '=' . $v; } }
    if (!function_exists('remove_query_arg')) { function remove_query_arg($keys, $u) { foreach ((array) $keys as $k) { $u = (string) preg_replace('/([?&])' . preg_quote($k, '/') . '=[^&]*&?/', '$1', $u); } return rtrim($u, '?&'); } }
    if (!function_exists('wp_get_referer')) { function wp_get_referer() { return $GLOBALS['referer'] ?? false; } }
    $rowAction = static function (int $id, string $nonce, ?string $referer = null) use ($library): string {
        $_GET = ['action' => 'rapls_pic_regenerate', 'attachment_id' => (string) $id, '_wpnonce' => $nonce];
        $GLOBALS['referer'] = $referer;
        try {
            $library->handleRegenerateAction();
        } catch (RegenStopped $e) {
            return $e->getMessage();
        } finally {
            $_GET = [];
            unset($GLOBALS['referer']);
        }
        return 'carried on';
    };
    $pdf(95, 96);
    $GLOBALS['caps'] = ['edit_post:95' => true];
    $wrongNonce = $rowAction(95, 'ok:rapls_pic_regenerate_94');
    $GLOBALS['caps'] = [];
    $noCapability = $rowAction(95, 'ok:rapls_pic_regenerate_95');
    check('the row action: another PDF\'s nonce stops it, and so does no edit_post on this one', [$wrongNonce, $noCapability], ['died: Security check failed.', 'died: You do not have permission to do this.']);
    $GLOBALS['caps'] = ['edit_post:95' => true];
    check('  ...back to the list it came from, its page and search kept, an old notice argument dropped', [
        $rowAction(95, 'ok:rapls_pic_regenerate_95', 'https://example.test/wp-admin/upload.php?mode=list&paged=3&s=report&rapls_pic_error=1'),
        $rowAction(95, 'ok:rapls_pic_regenerate_95', 'https://evil.example/elsewhere'),
    ], [
        'to: https://example.test/wp-admin/upload.php?mode=list&paged=3&s=report&rapls_pic_generated=1',
        'to: https://example.test/wp-admin/upload.php?rapls_pic_generated=1',
    ]);
    // Review after 1.4.32: a failure carries this attempt's code, for the
    // notice to say in its own words.
    if (!function_exists('sanitize_key')) { function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)); } }
    $engine->renders = false;
    $failedTo = $rowAction(95, 'ok:rapls_pic_regenerate_95', 'https://example.test/wp-admin/upload.php?paged=2');
    $engine->renders = true;
    check('  ...a failure goes back with its code', $failedTo, 'to: https://example.test/wp-admin/upload.php?paged=2&rapls_pic_error=' . ($generator->outcomeFor(95)['code'] ?? 'none'));
    check('  ...which is a code, not 1', in_array($generator->outcomeFor(95)['code'] ?? '', ['', '1'], true), false);
    // Review after 1.4.38: a kept old thumbnail goes back with why it was
    // kept, for the notice to say it; Bulk Generate says it the same way.
    $pdf(97, 98);
    $GLOBALS['meta'][98]['_wp_attached_file'] = '2026/10/doc-97-pdf-thumbnail.jpg';
    $GLOBALS['content'] = [905 => '<img src="/wp-content/uploads/2026/10/doc-97-pdf-thumbnail.jpg">'];
    $GLOBALS['caps'] = ['edit_post:97' => true];
    $keptTo = $rowAction(97, 'ok:rapls_pic_regenerate_97', 'https://example.test/wp-admin/upload.php');
    $GLOBALS['content'] = [];
    $pdf(99, 100);
    $GLOBALS['meta'][906]['_thumbnail_id'] = '100';
    $GLOBALS['caps'] = ['edit_post:99' => true];
    $featuredTo = $rowAction(99, 'ok:rapls_pic_regenerate_99', 'https://example.test/wp-admin/upload.php');
    unset($GLOBALS['meta'][906]);
    check('  ...a kept old thumbnail goes back with why: shown in a post, a featured image', [$keptTo, $featuredTo], ['to: https://example.test/wp-admin/upload.php?rapls_pic_generated=kept', 'to: https://example.test/wp-admin/upload.php?rapls_pic_generated=kept-featured']);
    $pdf(101, 102);
    $GLOBALS['meta'][102]['_wp_attached_file'] = '2026/10/doc-101-pdf-thumbnail.jpg';
    $GLOBALS['content'] = [907 => '[vc_single_image image="102"]'];
    $GLOBALS['caps'] = ['manage_options' => true, 'edit_post:101' => true];
    $GLOBALS['json_message'] = null;
    $GLOBALS['json_data'] = null;
    $bulkKept = $call([$bulk, 'ajaxGenerate'], ['pdf_id' => 101, 'force' => 1]);
    $GLOBALS['content'] = [];
    check('  ...and Bulk Generate says why it kept one, and which', [$bulkKept[0], false !== strpos((string) $GLOBALS['json_message'], 'Thumbnail generated. A post may still show the previous thumbnail'), $GLOBALS['json_data']['kept_previous'] ?? null], [true, true, 102]);
    $GLOBALS['json_message'] = null;
    $GLOBALS['json_data'] = null;
    $call([$bulk, 'ajaxGenerate'], ['pdf_id' => 101, 'force' => 1]);
    // Mutation testing of 1.4.39: the reason is this PDF's, not "shown"
    // whatever it was.
    $GLOBALS['meta'][908]['_thumbnail_id'] = (string) ($GLOBALS['meta'][101]['_rapls_pic_thumbnail_id'] ?? '');
    $GLOBALS['json_message'] = null;
    $call([$bulk, 'ajaxGenerate'], ['pdf_id' => 101, 'force' => 1]);
    unset($GLOBALS['meta'][908]);
    check('  ...a featured image kept is said as one', false !== strpos((string) $GLOBALS['json_message'], 'featured image, or another item'), true);
    $GLOBALS['json_message'] = null;
    $GLOBALS['json_data'] = null;
    $call([$bulk, 'ajaxGenerate'], ['pdf_id' => 101, 'force' => 1]);
    check('  ...and says nothing of it when none was kept', [$GLOBALS['json_message'], is_array($GLOBALS['json_data']) && array_key_exists('kept_previous', $GLOBALS['json_data']) ? $GLOBALS['json_data']['kept_previous'] : 'missing'], ['Thumbnail generated.', null]);
    $GLOBALS['caps'] = ['manage_options' => true, 'edit_post:80' => true];
    check('Bulk Generate: an admin may regenerate a PDF they may edit', $call([$bulk, 'ajaxGenerate'], ['pdf_id' => 80, 'force' => 1]), [true, null]);
    check('Bulk Generate: ...but not one edit_post refuses', $call([$bulk, 'ajaxGenerate'], ['pdf_id' => 90, 'force' => 1]), [false, 403]);
    $GLOBALS['caps'] = [];

    // Review after 1.4.31: asked to fill a gap, and another request filled it
    // while this one drew -- the upload hook and Bulk Generate at once. Theirs
    // stands, this one's image goes, and nothing is left as nobody's.
    if (!function_exists('wp_cache_delete')) { function wp_cache_delete($key, $group = '') { if ('post_meta' === $group) { unset($GLOBALS['meta_cache'][(int) $key]); } return true; } }
    $pdf(9090);
    $GLOBALS['deleted'] = [];
    // This request's cache still holds the empty answer it read at the start.
    $engine->during = static function () use ($pdf): void { $pdf(9090, 9091); $GLOBALS['meta_cache'][9090] = ['_rapls_pic_thumbnail_id' => '']; };
    $raced = $generator->generate(9090, false);
    $engine->during = null;
    check('a gap filled by another request meanwhile: theirs stands, this image is deleted', [$raced, $GLOBALS['meta'][9090]['_rapls_pic_thumbnail_id'] ?? null, count($GLOBALS['deleted']), in_array(9091, $GLOBALS['deleted'], true)], [9091, 9091, 1, false]);
    // Asked to replace, it replaces whatever is there, as before.
    $pdf(9092);
    $engine->during = static function () use ($pdf): void { $pdf(9092, 9093); };
    $forced = $generator->generate(9092, true);
    $engine->during = null;
    check('  ...a replacement asked for still replaces', [$forced !== null && $forced !== 9093, (int) ($GLOBALS['meta'][9092]['_rapls_pic_thumbnail_id'] ?? 0) === (int) $forced], [true, true]);

    // Review after 1.4.31: numbers a listener hands back as strings or floats
    // reach the engine as whole numbers.
    $numberTags = ['page' => '2', 'max_width' => '800.7', 'max_height' => '600', 'resolution' => 150.0, 'quality' => '85'];
    foreach ($numberTags as $key => $answer) {
        $GLOBALS['filters']['rapls_pdf_image_creator_thumbnail_' . $key] = static function () use ($answer) { return $answer; };
    }
    $pdf(90);
    $generator->generate(90, true);
    foreach (array_keys($numberTags) as $key) {
        unset($GLOBALS['filters']['rapls_pdf_image_creator_thumbnail_' . $key]);
    }
    check('numbers from the option filters reach the engine as integers', array_map(static function ($key) use ($engine) { return $engine->options[$key] ?? null; }, array_keys($numberTags)), [2, 800, 600, 150, 85]);

    // Review of 1.4.32: the generator's own rules, each of which a mutant
    // could remove without a test noticing.
    $G = \Rapls\PDFImageCreator\Generator::class;
    // A first thumbnail keeps the plain name; a forced one takes a time suffix,
    // so browsers holding the old image do not keep showing it.
    $pdf(600);
    $first = $generator->generate(600, false);
    $again = $generator->generate(600, true);
    check('a first thumbnail has the plain name; a regenerated one a name of its own', [
        basename((string) ($GLOBALS['files'][$first] ?? '')),
        (bool) preg_match('/^doc-600-pdf-thumbnail-[0-9a-f]{7,}\.jpg$/', basename((string) ($GLOBALS['files'][$again] ?? ''))),
    ], ['doc-600-pdf-thumbnail.jpg', true]);
    // A file already under the name -- another request's, half made -- is
    // not drawn into: the name is taken by creating it, not by opening it.
    $pdf(601);
    file_put_contents($uploads . '/doc-601-pdf-thumbnail.jpg', 'someone else\'s');
    $beside = $generator->generate(601, false);
    check('  ...a name already there is left alone, and the next one taken', [basename((string) ($GLOBALS['files'][$beside] ?? '')), file_get_contents($uploads . '/doc-601-pdf-thumbnail.jpg')], ['doc-601-pdf-thumbnail-1.jpg', 'someone else\'s']);
    // A format the engine does not know becomes JPEG, name and all.
    $GLOBALS['filters']['rapls_pdf_image_creator_thumbnail_format'] = static function () { return 'GIF'; };
    $pdf(602);
    $gif = $generator->generate(602, false);
    unset($GLOBALS['filters']['rapls_pdf_image_creator_thumbnail_format']);
    check('  ...a format the engine does not know is JPEG', [$engine->format, pathinfo((string) ($GLOBALS['files'][$gif] ?? ''), PATHINFO_EXTENSION)], ['jpeg', 'jpg']);
    // A success clears the last failure the Status tab shows; asking for
    // something that is not a PDF records none.
    $GLOBALS['options'][$G::LAST_FAILURE_OPTION] = ['code' => 'render_error'];
    $pdf(603);
    $generator->generate(603, false);
    $clearedOnSuccess = isset($GLOBALS['options'][$G::LAST_FAILURE_OPTION]);
    $GLOBALS['mimes'][604] = 'image/jpeg';
    $GLOBALS['posts'][604] = (object) ['ID' => 604, 'post_type' => 'attachment'];
    $generator->generate(604, true);
    check('  ...a success clears the last failure; asking for a non-PDF records none', [$clearedOnSuccess, isset($GLOBALS['options'][$G::LAST_FAILURE_OPTION])], [false, false]);
    // A thumbnail record naming an image that is gone answers none, and is
    // left for the next generation: reading it on a page view deleted it,
    // a write from the front end (review of 1.4.35).
    $GLOBALS['meta'][605][$G::THUMBNAIL_META_KEY] = 99999;
    check('  ...a record naming a deleted image: none, and left as it is', [$generator->getThumbnailId(605), isset($GLOBALS['meta'][605][$G::THUMBNAIL_META_KEY])], [null, true]);
    // With no engine, "unavailable" is written once, not on every PDF of a run.
    $enginesProp = new ReflectionProperty($G, 'engines');
    if (PHP_VERSION_ID < 80100) { $enginesProp->setAccessible(true); }
    $enginesWere = $enginesProp->getValue($generator);
    $enginesProp->setValue($generator, []);
    $GLOBALS['option_writes'] = [];
    $pdf(606);
    $generator->generate(606, false);
    $generator->generate(606, false);
    $enginesProp->setValue($generator, $enginesWere);
    check('  ...with no engine, "unavailable" is written once, autoloaded (the admin notice reads it on every page)', [count(array_keys($GLOBALS['option_writes'], $G::UNAVAILABLE_OPTION, true)), $GLOBALS['option_autoload'][$G::UNAVAILABLE_OPTION] ?? null], [1, true]);
    // ...under a code the vocabulary knows (review after 1.4.33), and one a
    // retry with the same settings will not change.
    check('  ...the code it fails with is no_engine, known and permanent', [$generator->outcomeFor(606)['code'] ?? null, in_array('no_engine', \Rapls\PDFImageCreator\FailureCode::all(), true), \Rapls\PDFImageCreator\FailureCode::isPermanent('no_engine')], ['no_engine', true, true]);

    // Review after 1.4.31: an engines listener answering something that is
    // not a list leaves the plugin's own; a list keeps only its engines.
    require_once RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ColorProfile.php';
    require_once RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ImagickEngine.php';
    $initEngines = new ReflectionMethod(\Rapls\PDFImageCreator\Generator::class, 'initEngines');
    $enginesOf = new ReflectionProperty(\Rapls\PDFImageCreator\Generator::class, 'engines');
    if (PHP_VERSION_ID < 80100) { $initEngines->setAccessible(true); $enginesOf->setAccessible(true); }
    $answers = [];
    foreach (['not a list' => 'nonsense', 'a list with a stranger' => ['imagick' => new \stdClass(), 'stand-in' => $engine], 'none on purpose' => []] as $label => $answer) {
        $GLOBALS['filters']['rapls_pdf_image_creator_engines'] = static function () use ($answer) { return $answer; };
        try {
            $initEngines->invoke($generator);
            $answers[$label] = array_keys($enginesOf->getValue($generator));
        } catch (\Throwable $e) {
            $answers[$label] = get_class($e);
        }
    }
    unset($GLOBALS['filters']['rapls_pdf_image_creator_engines']);
    check('an engines listener: not a list keeps the plugin\'s own, a list keeps only engines, an empty list means none', $answers, ['not a list' => ['imagick'], 'a list with a stranger' => ['stand-in'], 'none on purpose' => []]);

    echo "\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}
