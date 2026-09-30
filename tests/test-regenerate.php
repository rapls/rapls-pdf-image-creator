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
    function apply_filters($tag, $value, ...$args) { return isset($GLOBALS['filters'][$tag]) ? ($GLOBALS['filters'][$tag])($value) : $value; }
    function do_action($tag, ...$args) { if (isset($GLOBALS['actions'][$tag])) { ($GLOBALS['actions'][$tag])(...$args); } }
    function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
    function update_option($key, $value, $autoload = null) {
        // As core: false when nothing changes, and when the write fails.
        if (($GLOBALS['options'][$key] ?? null) === $value || !empty($GLOBALS['option_write_fails'])) { return false; }
        $GLOBALS['options'][$key] = $value;
        return true;
    }
    function delete_option($key) { unset($GLOBALS['options'][$key]); return true; }
    function get_post($id) { return $GLOBALS['posts'][(int) $id] ?? null; }
    function get_post_mime_type($id) { return $GLOBALS['mimes'][(int) $id] ?? false; }
    function get_post_meta($id, $key, $single = false) { return $GLOBALS['meta'][(int) $id][$key] ?? ''; }
    function update_post_meta($id, $key, $value) {
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
        $id = $GLOBALS['next_id']++;
        $GLOBALS['posts'][$id] = (object) ['ID' => $id, 'post_type' => 'attachment', 'post_mime_type' => $data['post_mime_type']];
        // An update_attached_file filter that names another file (R58-02).
        $GLOBALS['files'][$id] = $GLOBALS['attach_elsewhere'] ?? $file;
        return $id;
    }
    function update_attached_file($id, $file) {
        if (!empty($GLOBALS['attach_stuck'])) { return true; }
        $GLOBALS['files'][(int) $id] = $file;
        return true;
    }
    function wp_generate_attachment_metadata($id, $file) { return !empty($GLOBALS['no_metadata']) ? false : ['file' => basename($file), 'sizes' => []]; }
    function wp_update_attachment_metadata($id, $meta) {
        if (!empty($GLOBALS['fail_attachment_meta'])) { return false; }
        $GLOBALS['attachment_meta'][(int) $id] = $meta;
        return true;
    }
    function wp_get_attachment_metadata($id) { return $GLOBALS['attachment_meta'][(int) $id] ?? false; }
    function wp_delete_attachment($id, $force = false) {
        if ((int) $id === ($GLOBALS['undeletable'] ?? 0) || !empty($GLOBALS['nothing_deletes'])) { return false; }
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
        public $sawReserved = null;
        public $throws = false;
        public $format = null;
        public function convert(string $pdfPath, string $outputPath, array $options = []): \Rapls\PDFImageCreator\Engine\ConversionResult {
            $this->sawReserved = is_file($outputPath) && 0 === filesize($outputPath);
            $this->format = $options['format'] ?? null;
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
    $pdf(210, 211);
    $engine->renders = false;
    $leftBefore = glob($uploads . '/doc-210-pdf-thumbnail*');
    $generator->generate(210, true);
    $engine->renders = true;
    check('  ...and a failed render leaves no empty file behind', glob($uploads . '/doc-210-pdf-thumbnail*'), $leftBefore);

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

    echo "\n--- R58-03: an old thumbnail that cannot be deleted does not fail the new one ---\n";

    $pdf(280, 281);
    $GLOBALS['undeletable'] = 281;
    $made = $generator->generate(280, true);
    $GLOBALS['undeletable'] = 0;
    check('old delete refused: new one kept and returned, old one still there', [is_int($made), (int) ($GLOBALS['meta'][280]['_rapls_pic_thumbnail_id'] ?? 0) === $made, isset($GLOBALS['posts'][281])], [true, true, true]);

    echo "\n--- R58-04: exceptions do not leave files, nor turn success into failure ---\n";

    $pdf(250, 251);
    $engine->throws = true;
    $leftBefore = glob($uploads . '/doc-250-pdf-thumbnail*');
    $caught = null;
    try { $generator->generate(250, true); } catch (\RuntimeException $e) { $caught = $e->getMessage(); }
    $engine->throws = false;
    check('engine throws: passed on, and no empty file left', [$caught, glob($uploads . '/doc-250-pdf-thumbnail*')], ['engine boom', $leftBefore]);
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
