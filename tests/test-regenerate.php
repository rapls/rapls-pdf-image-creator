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
    $abs = sys_get_temp_dir() . '/rapls-pic-regen-' . bin2hex(random_bytes(4)) . '/';
    mkdir($abs . 'wp-admin/includes', 0777, true);
    file_put_contents($abs . 'wp-admin/includes/image.php', "<?php\n");
    define('ABSPATH', $abs);
    $uploads = $abs . 'uploads';
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
    function apply_filters($tag, $value, ...$args) { return $value; }
    function do_action($tag, ...$args) {}
    function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
    function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }
    function delete_option($key) { unset($GLOBALS['options'][$key]); return true; }
    function get_post($id) { return $GLOBALS['posts'][(int) $id] ?? null; }
    function get_post_mime_type($id) { return $GLOBALS['mimes'][(int) $id] ?? false; }
    function get_post_meta($id, $key, $single = false) { return $GLOBALS['meta'][(int) $id][$key] ?? ''; }
    function update_post_meta($id, $key, $value) {
        if ($key === $GLOBALS['fail_meta']) { return false; }
        $GLOBALS['meta'][(int) $id][$key] = $value;
        return true;
    }
    function delete_post_meta($id, $key) { unset($GLOBALS['meta'][(int) $id][$key]); return true; }
    function get_attached_file($id) { return $GLOBALS['files'][(int) $id] ?? false; }
    function wp_upload_dir() { return ['basedir' => $GLOBALS['uploads'], 'baseurl' => 'https://example.test/uploads']; }
    function wp_delete_file($path) { @unlink($path); }
    function wp_get_attachment_image_url($id, $size = 'thumbnail') { return 'https://example.test/' . (int) $id . '.jpg'; }
    function sanitize_file_name($name) { return preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $name); }
    function wp_check_filetype($name) { return ['ext' => 'jpg', 'type' => 'image/jpeg']; }
    function is_wp_error($thing) { return false; }
    function wp_insert_attachment($data, $file, $parent) {
        $id = $GLOBALS['next_id']++;
        $GLOBALS['posts'][$id] = (object) ['ID' => $id, 'post_type' => 'attachment'];
        $GLOBALS['files'][$id] = $file;
        return $id;
    }
    function wp_generate_attachment_metadata($id, $file) { return []; }
    function wp_update_attachment_metadata($id, $meta) { return true; }
    function wp_delete_attachment($id, $force = false) {
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
    function check_ajax_referer($action = -1, $q = false, $die = true) { return true; }
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
        throw new JsonSent($success, $d, $s);
    }
    function wp_send_json_error($d = null, $s = null) { rapls_test_json(false, $d, $s); }
    function wp_send_json_success($d = null, $s = null) { rapls_test_json(true, $d, $s); }

    require RAPLS_PIC_PLUGIN_DIR . 'includes/FailureCode.php';
    require RAPLS_PIC_PLUGIN_DIR . 'includes/Settings.php';
    require RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ConversionResult.php';
    require RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/EngineInterface.php';
    require RAPLS_PIC_PLUGIN_DIR . 'includes/Generator.php';
    require RAPLS_PIC_PLUGIN_DIR . 'includes/BulkProcessor.php';
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
    $GLOBALS['wpdb'] = new class {
        public $postmeta = 'wp_postmeta';
        public $last_error = '';
        public function prepare($sql, ...$args) { return [$sql, $args]; }
        public function get_var($q) { return null; }
    };

    final class StandInEngine implements \Rapls\PDFImageCreator\Engine\EngineInterface {
        public $renders = true;
        public function getName(): string { return 'stand-in'; }
        public function getDisplayName(): string { return 'Stand-in'; }
        public function isAvailable(): bool { return true; }
        public function getAvailabilityStatus(): array { return ['code' => 'ok', 'label' => '', 'summary' => '', 'action' => '', 'detail' => '']; }
        public function getRequirements(): array { return []; }
        public function convert(string $pdfPath, string $outputPath, array $options = []): \Rapls\PDFImageCreator\Engine\ConversionResult {
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
    check('Media Library: a PDF the user may edit is regenerated', $call([$library, 'ajaxRegenerateThumbnail'], ['attachment_id' => 80, 'force' => 1]), [true, null]);
    $GLOBALS['deleted'] = [];
    check('Media Library: a PDF the user may not edit is refused', $call([$library, 'ajaxRegenerateThumbnail'], ['attachment_id' => 90, 'force' => 1]), [false, 403]);
    check('  ...and its thumbnail is untouched', [$GLOBALS['deleted'], $GLOBALS['meta'][90]['_rapls_pic_thumbnail_id'] ?? null], [[], 91]);
    check('Bulk Generate: refused without manage_options', $call([$bulk, 'ajaxGenerate'], ['pdf_id' => 80, 'force' => 1]), [false, 403]);
    check('Bulk Scan: refused without manage_options', $call([$bulk, 'ajaxScan'], []), [false, 403]);
    $GLOBALS['caps'] = ['manage_options' => true, 'edit_post:80' => true];
    check('Bulk Generate: an admin may regenerate a PDF they may edit', $call([$bulk, 'ajaxGenerate'], ['pdf_id' => 80, 'force' => 1]), [true, null]);
    check('Bulk Generate: ...but not one edit_post refuses', $call([$bulk, 'ajaxGenerate'], ['pdf_id' => 90, 'force' => 1]), [false, 403]);
    $GLOBALS['caps'] = [];

    echo "\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}
