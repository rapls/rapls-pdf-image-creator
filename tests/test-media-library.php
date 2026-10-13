<?php
/**
 * Which image a PDF answers with when core asks for one.
 *
 * Core draws its own `-pdf.jpg` preview at upload, without the blank-page
 * retry, so for a PDF that needs the retry that preview is white. The Edit
 * Media screen asks wp_get_attachment_image_src() for 900x450 and got core's
 * white preview, because the filter only stepped in when core found nothing.
 */

declare(strict_types=1);

namespace {
    define('RAPLS_PIC_PLUGIN_DIR', dirname(__DIR__) . '/');

    $GLOBALS['meta'] = [];
    $GLOBALS['posts'] = [];
    $GLOBALS['mimes'] = [];
    $GLOBALS['srcs'] = [];

    function get_post_mime_type($id) { return $GLOBALS['mimes'][$id] ?? false; }
    function get_post_meta($id, $key, $single = false) { return $GLOBALS['meta'][$id][$key] ?? ''; }
    function delete_post_meta($id, $key) { unset($GLOBALS['meta'][$id][$key]); return true; }
    function get_post($id) { return $GLOBALS['posts'][$id] ?? null; }
    function wp_get_attachment_image_src($id, $size = 'thumbnail', $icon = false) { $GLOBALS['src_sizes'][] = $size; return $GLOBALS['srcs'][$id] ?? false; }
    function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
    function trailingslashit($s) { return rtrim($s, '/\\') . '/'; }
    function wp_get_upload_dir() { return ['basedir' => $GLOBALS['uploads'] ?? '']; }
    function get_attached_file($id, $unfiltered = false) {
        $rel = $GLOBALS['meta'][$id]['_wp_attached_file'] ?? '';
        return $rel === '' ? false : $GLOBALS['uploads'] . '/' . $rel;
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
        printf("%s %-58s got=%s want=%s\n", $ok ? 'ok  ' : 'FAIL', $label,
            json_encode($got, JSON_UNESCAPED_SLASHES), json_encode($want, JSON_UNESCAPED_SLASHES));
    }

    $settings = (new ReflectionClass(\Rapls\PDFImageCreator\Settings::class))->newInstanceWithoutConstructor();
    $generator = (new ReflectionClass(\Rapls\PDFImageCreator\Generator::class))->newInstanceWithoutConstructor();
    $library = new \Rapls\PDFImageCreator\MediaLibrary($generator, $settings);

    $corePreview = ['https://example.test/doc-pdf-1024x724.jpg', 900, 636, true];
    $thumbnail = ['https://example.test/doc-thumbnail.png', 900, 636, true];

    // PDF 10 has a generated thumbnail (image 11); PDF 20 has none.
    $GLOBALS['mimes'] = [10 => 'application/pdf', 11 => 'image/png', 20 => 'application/pdf', 30 => 'image/jpeg'];
    $GLOBALS['meta'][10]['_rapls_pic_thumbnail_id'] = 11;
    $GLOBALS['posts'][11] = (object) ['ID' => 11, 'post_type' => 'attachment'];
    $GLOBALS['srcs'][11] = $thumbnail;

    check('PDF with thumbnail, core found its preview: thumbnail', $library->filterAttachmentImageSrc($corePreview, 10, [900, 450], true), $thumbnail);
    check('PDF with thumbnail, core found nothing: thumbnail', $library->filterAttachmentImageSrc(false, 10, 'medium', false), $thumbnail);
    check('PDF without thumbnail: core preview kept', $library->filterAttachmentImageSrc($corePreview, 20, [900, 450], true), $corePreview);
    check('PDF without thumbnail, nothing found: false', $library->filterAttachmentImageSrc(false, 20, 'medium', false), false);
    check('not a PDF: untouched', $library->filterAttachmentImageSrc($corePreview, 30, 'medium', false), $corePreview);
    check('a PDF ID given as a string still gets its thumbnail', $library->filterAttachmentImageSrc($corePreview, '10', 'large', false), $thumbnail);

    // The thumbnail's file is gone: fall back to what core found.
    unset($GLOBALS['srcs'][11]);
    check('thumbnail has no source: core preview kept', $library->filterAttachmentImageSrc($corePreview, 10, [900, 450], true), $corePreview);

    // Auto-generate off: core's preview is deleted from the PDF's own
    // year/month folder. Core's PDF metadata has no `file` key, so reading
    // the folder from it deleted a same-named file in the uploads root and
    // left the real preview on disk.
    $uploads = sys_get_temp_dir() . '/rapls-pic-test-' . getmypid();
    @mkdir($uploads . '/2026/09', 0777, true);
    file_put_contents($uploads . '/2026/09/doc.pdf', '%PDF');
    file_put_contents($uploads . '/2026/09/doc-pdf.jpg', 'preview');
    file_put_contents($uploads . '/2026/09/doc-pdf-116x150.jpg', 'preview');
    file_put_contents($uploads . '/doc-pdf.jpg', 'someone else');
    $GLOBALS['uploads'] = $uploads;
    $GLOBALS['meta'][20]['_wp_attached_file'] = '2026/09/doc.pdf';
    $GLOBALS['options']['rapls_pic_settings'] = ['auto_generate' => false];
    $coreMeta = ['sizes' => [
        'full' => ['file' => 'doc-pdf.jpg', 'width' => 1088, 'height' => 1408],
        'thumbnail' => ['file' => 'doc-pdf-116x150.jpg', 'width' => 116, 'height' => 150],
    ]];
    $out = $library->maybeSuppressCorePdfPreview($coreMeta, 20, 'create');
    check('auto-generate off: sizes removed from metadata', $out['sizes'], []);
    check('auto-generate off: preview in year/month folder deleted', file_exists($uploads . '/2026/09/doc-pdf.jpg') || file_exists($uploads . '/2026/09/doc-pdf-116x150.jpg'), false);
    check('auto-generate off: same name in uploads root kept', file_exists($uploads . '/doc-pdf.jpg'), true);
    check('auto-generate off: the PDF itself kept', file_exists($uploads . '/2026/09/doc.pdf'), true);

    // Nothing to tell where the PDF is: delete nothing rather than guess.
    file_put_contents($uploads . '/2026/09/doc-pdf.jpg', 'preview');
    $GLOBALS['mimes'][21] = 'application/pdf';
    $out = $library->maybeSuppressCorePdfPreview($coreMeta, 21, 'create');
    check('no attached file: nothing deleted', file_exists($uploads . '/doc-pdf.jpg') && file_exists($uploads . '/2026/09/doc-pdf.jpg'), true);

    foreach (['2026/09/doc.pdf', '2026/09/doc-pdf.jpg', '2026/09/doc-pdf-116x150.jpg', 'doc-pdf.jpg'] as $f) {
        @unlink($uploads . '/' . $f);
    }
    @rmdir($uploads . '/2026/09');
    @rmdir($uploads . '/2026');
    @rmdir($uploads);

    // R66-01: the hide clause is ANDed with an existing meta query, not added to its OR.
    if (!function_exists('apply_filters')) { function apply_filters($tag, $value, ...$args) { return $value; } }
    $orQuery = ['relation' => 'OR', ['key' => 'a', 'value' => '1'], ['key' => 'b', 'value' => '1']];
    $joined = \Rapls\PDFImageCreator\MediaLibrary::withoutGeneratedImages($orQuery);
    check('R66-01: an OR query is kept whole and ANDed', [$joined['relation'] ?? null, $joined[0] ?? null, $joined[1]['relation'] ?? null, $joined[1][0]['compare'] ?? null], ['AND', $orQuery, 'OR', 'NOT EXISTS']);
    check('  ...no query: the hide clause alone', count(\Rapls\PDFImageCreator\MediaLibrary::withoutGeneratedImages([])), 1);

    // R66-02: an image picker gets PDFs only with a thumbnail.
    if (!class_exists('WP_Query')) {
        eval('final class WP_Query { private $vars; private $main; public function __construct(array $v = [], bool $main = false) { $this->vars = $v; $this->main = $main; } public function get($k) { return $this->vars[$k] ?? ""; } public function set($k, $v) { $this->vars[$k] = $v; } public function is_main_query() { return $this->main; } }');
    }
    $GLOBALS['wpdb'] = (object) ['posts' => 'wp_posts', 'postmeta' => 'wp_postmeta'];
    $args = $library->allowPdfsInImageSelection(['post_mime_type' => ['image', 'application/pdf', \Rapls\PDFImageCreator\MediaLibrary::PICKER_MARK]]);
    check('R66-02: a picker of this plugin\'s takes PDFs, is marked, and loses the mark', [$args['post_mime_type'], $args['rapls_pic_image_pick'] ?? null], [['image', 'application/pdf'], true]);
    $markOnly = $library->allowPdfsInImageSelection(['post_mime_type' => ['image', \Rapls\PDFImageCreator\MediaLibrary::PICKER_MARK]]);
    check('  ...the mark alone adds PDFs', $markOnly['post_mime_type'], ['image', 'application/pdf']);
    // Codex review of 1.4.29, 1: another plugin's image picker asks for images only, and gets images only.
    check('  ...another plugin\'s image query is left as it asked (Codex review of 1.4.29, 1)', [$library->allowPdfsInImageSelection(['post_mime_type' => 'image'])['post_mime_type'], isset($library->allowPdfsInImageSelection(['post_mime_type' => ['image']])['rapls_pic_image_pick'])], ['image', false]);
    check('  ...and one that asks for images and PDFs itself is not narrowed', isset($library->allowPdfsInImageSelection(['post_mime_type' => ['image', 'application/pdf']])['rapls_pic_image_pick']), false);
    $where = $library->limitPdfsToThumbnailed(' AND 1=1', new WP_Query($args));
    check('  ...and its WHERE lets a PDF in only with a thumbnail key', [false !== strpos($where, "post_mime_type <> 'application/pdf' OR EXISTS"), false !== strpos($where, "'_rapls_pic_thumbnail_id', '_thumbnail_id'")], [true, true]);
    check('  ...and the image the key names must exist (R67-01)', [false !== strpos($where, 'INNER JOIN wp_posts rapls_pic_image ON rapls_pic_image.ID = CAST(rapls_pic_thumb.meta_value AS UNSIGNED)'), false !== strpos($where, "rapls_pic_image.post_type = 'attachment'")], [true, true]);
    check('  ...other queries are left alone', $library->limitPdfsToThumbnailed(' AND 1=1', new WP_Query(['post_mime_type' => 'image'])), ' AND 1=1');
    check('  ...a non-image query is not marked', isset($library->allowPdfsInImageSelection(['post_mime_type' => 'application/pdf'])['rapls_pic_image_pick']), false);

    // Codex review of 1.4.29, 1: the classic editor's featured image script loads on a classic post screen only.
    if (!defined('RAPLS_PIC_PLUGIN_URL')) { define('RAPLS_PIC_PLUGIN_URL', 'https://example.test/wp-content/plugins/rapls-pdf-image-creator/'); }
    if (!defined('RAPLS_PIC_VERSION')) { define('RAPLS_PIC_VERSION', 'test'); }
    if (!function_exists('wp_enqueue_script')) { function wp_enqueue_script($handle, ...$rest) { $GLOBALS['enqueued'][] = $handle; } }
    if (!function_exists('wp_register_script')) { function wp_register_script($handle, $src, $deps = [], ...$rest) { $GLOBALS['registered'][$handle] = [$src, $deps]; return true; } }
    if (!function_exists('get_current_screen')) { function get_current_screen() { return $GLOBALS['screen'] ?? null; } }
    $screens = [
        'classic post' => [(object) ['base' => 'post'], true],
        'block editor' => [new class { public $base = 'post'; public function is_block_editor() { return true; } }, false],
        'classic, says so' => [new class { public $base = 'post'; public function is_block_editor() { return false; } }, true],
        'another screen (widgets)' => [(object) ['base' => 'widgets'], false],
        'an attachment\'s edit screen' => [(object) ['base' => 'post', 'post_type' => 'attachment'], false],
        'no screen' => [null, false],
    ];
    $loaded = [];
    foreach ($screens as $label => [$screen, $want]) {
        $GLOBALS['enqueued'] = [];
        $GLOBALS['screen'] = $screen;
        $library->enqueueClassicFeaturedImage();
        $loaded[$label] = in_array('pic-core-media-states', $GLOBALS['enqueued'], true);
    }
    check('  ...the core featured image and gallery script: classic post screens only', $loaded, array_map(static function (array $x): bool { return $x[1]; }, $screens));
    check('  ...and it is core-media-states.js, after media-views', [basename((string) ($GLOBALS['registered']['pic-core-media-states'][0] ?? '')), $GLOBALS['registered']['pic-core-media-states'][1] ?? null], ['core-media-states.js', ['media-views']]);

    // R66-03: an image attached to a PDF, without this plugin's marks, keeps its own URL.
    if (!function_exists('wp_get_attachment_url')) { function wp_get_attachment_url($id) { return $GLOBALS['urls'][$id] ?? 'https://example.test/' . $id . '.pdf'; } }
    if (!function_exists('get_attachment_link')) { function get_attachment_link($id) { return 'https://example.test/?attachment_id=' . $id; } }
    $GLOBALS['mimes'][200] = 'image/jpeg';
    if (!class_exists('WP_Post')) {
        eval('final class WP_Post { public $ID; public $post_parent; public $post_mime_type; public $post_type = "attachment"; public function __construct($id, $parent, $mime) { $this->ID = $id; $this->post_parent = $parent; $this->post_mime_type = $mime; } }');
    }
    $child = new WP_Post(200, 10, 'image/jpeg');
    $response = $library->filterAttachmentForJs(['url' => 'https://example.test/cover.jpg', 'mime' => 'image/jpeg'], $child, []);
    check("R66-03: someone else's image under a PDF keeps its URL", [$response['url'], isset($response['picIsThumbnail'])], ['https://example.test/cover.jpg', false]);

    // R68-02: a generated image's REST response keeps core's source_url and link.
    if (!class_exists('WP_REST_Response')) {
        eval('final class WP_REST_Response { private $data; public function __construct($d = []) { $this->data = $d; } public function get_data() { return $this->data; } public function set_data($d) { $this->data = $d; } }');
        eval('final class WP_REST_Request {}');
    }
    $GLOBALS['mimes'][210] = 'image/jpeg';
    $GLOBALS['meta'][210]['_rapls_pic_is_thumbnail'] = '1';
    $GLOBALS['meta'][210]['_rapls_pic_source_pdf'] = 10;
    // Review of 1.4.32: a generated image shown with its PDF's url keeps an
    // image for every size, so a gallery's "large" is not the PDF.
    $generatedJs = $library->filterAttachmentForJs(['url' => 'https://example.test/doc-pdf-thumbnail.jpg', 'mime' => 'image/jpeg', 'sizes' => ['full' => ['url' => 'https://example.test/doc-pdf-thumbnail.jpg', 'width' => 724, 'height' => 1024], 'thumbnail' => ['url' => 'https://example.test/doc-pdf-thumbnail-150x150.jpg', 'width' => 150, 'height' => 150]]], new WP_Post(210, 10, 'image/jpeg'), []);
    check("a generated image under its PDF's url: every size an image, its own sizes kept", [
        false !== strpos((string) ($generatedJs['url'] ?? ''), '.jpg'),
        $generatedJs['sizes']['large']['url'] ?? null,
        $generatedJs['sizes']['thumbnail']['url'] ?? null,
    ], [false, 'https://example.test/doc-pdf-thumbnail.jpg', 'https://example.test/doc-pdf-thumbnail-150x150.jpg']);
    $rest = $library->filterRestAttachment(new WP_REST_Response(['id' => 210, 'mime_type' => 'image/jpeg', 'source_url' => 'https://example.test/doc-pdf-thumbnail.jpg', 'link' => 'https://example.test/?attachment_id=210']), new WP_Post(210, 10, 'image/jpeg'), new WP_REST_Request())->get_data();
    check("R68-02: a generated image's source_url stays its own", [$rest['source_url'], $rest['link'], $rest['rapls_pic_source_pdf_id'] ?? null, $rest['rapls_pic_source_pdf_url'] ?? null], ['https://example.test/doc-pdf-thumbnail.jpg', 'https://example.test/?attachment_id=210', 10, 'https://example.test/10.pdf']);

    // Review after 1.4.31: a PDF's sizes are addressed beside its image's own
    // address -- a CDN's, where one rewrote it -- not under the uploads folder.
    if (!function_exists('wp_basename')) { function wp_basename($p) { return basename((string) $p); } }
    if (!function_exists('wp_get_attachment_metadata')) { function wp_get_attachment_metadata($id) { return $GLOBALS['attachment_meta'][$id] ?? false; } }
    if (!function_exists('wp_upload_dir')) { function wp_upload_dir() { return ['basedir' => '/srv/uploads', 'baseurl' => 'https://example.test/wp-content/uploads']; } }
    $GLOBALS['mimes'][300] = 'application/pdf';
    $GLOBALS['meta'][300][\Rapls\PDFImageCreator\Generator::THUMBNAIL_META_KEY] = 3000;
    $GLOBALS['posts'][3000] = new WP_Post(3000, 300, 'image/jpeg');
    $GLOBALS['meta'][3000]['_wp_attached_file'] = '2026/10/doc-pdf-thumbnail.jpg';
    $GLOBALS['urls'][3000] = 'https://cdn.example.net/media/2026/10/doc-pdf-thumbnail.jpg';
    $GLOBALS['attachment_meta'][3000] = ['width' => 1024, 'height' => 768, 'sizes' => ['medium' => ['file' => 'doc-pdf-thumbnail-300x225.jpg', 'width' => 300, 'height' => 225]]];
    $cdnJs = $library->filterAttachmentForJs(['url' => 'https://example.test/300.pdf', 'mime' => 'application/pdf'], new WP_Post(300, 0, 'application/pdf'), []);
    $cdnRest = $library->filterRestAttachment(new WP_REST_Response(['id' => 300, 'mime_type' => 'application/pdf', 'source_url' => 'https://example.test/300.pdf', 'media_details' => []]), new WP_Post(300, 0, 'application/pdf'), new WP_REST_Request())->get_data();
    check('a PDF\'s sizes sit beside its image\'s own address (a CDN\'s), in the media modal and the REST answer (review after 1.4.31)', [
        $cdnJs['sizes']['medium']['url'] ?? null,
        $cdnRest['media_details']['sizes']['medium']['source_url'] ?? null,
    ], [
        'https://cdn.example.net/media/2026/10/doc-pdf-thumbnail-300x225.jpg',
        'https://cdn.example.net/media/2026/10/doc-pdf-thumbnail-300x225.jpg',
    ]);
    // Review of 1.4.32: a size the thumbnail has no file for -- "large" for a
    // 1024-pixel image -- is the full image, never the PDF the block editor
    // would fall back to (a gallery asks for "large").
    if (!function_exists('get_intermediate_image_sizes')) { function get_intermediate_image_sizes() { return ['thumbnail', 'medium', 'medium_large', 'large', '1536x1536']; } }
    $cdnJs = $library->filterAttachmentForJs(['url' => 'https://example.test/300.pdf', 'mime' => 'application/pdf'], new WP_Post(300, 0, 'application/pdf'), []);
    $cdnRest = $library->filterRestAttachment(new WP_REST_Response(['id' => 300, 'mime_type' => 'application/pdf', 'source_url' => 'https://example.test/300.pdf', 'media_details' => []]), new WP_Post(300, 0, 'application/pdf'), new WP_REST_Request())->get_data();
    $full = 'https://cdn.example.net/media/2026/10/doc-pdf-thumbnail.jpg';
    check('a size not made is the full image, in the media modal and the REST answer; a size made stays its own', [
        $cdnJs['sizes']['large']['url'] ?? null,
        $cdnJs['sizes']['1536x1536']['url'] ?? null,
        $cdnRest['media_details']['sizes']['large']['source_url'] ?? null,
        $cdnRest['media_details']['sizes']['thumbnail']['source_url'] ?? null,
        $cdnJs['sizes']['medium']['url'] ?? null,
    ], [$full, $full, $full, $full, 'https://cdn.example.net/media/2026/10/doc-pdf-thumbnail-300x225.jpg']);

    // R67-03: only the Media Library screen's main query hides generated images.
    if (!function_exists('is_admin')) { function is_admin() { return true; } }
    $hideOn = new ReflectionProperty(\Rapls\PDFImageCreator\Settings::class, 'settings');
    if (PHP_VERSION_ID < 80100) { $hideOn->setAccessible(true); }
    $hideOn->setValue($settings, ['hide_generated_images' => true]);
    $narrowed = static function (string $page, bool $main) use ($library): bool {
        $GLOBALS['pagenow'] = $page;
        $q = new WP_Query(['post_type' => 'attachment'], $main);
        $library->filterMediaLibrary($q);
        return '' !== $q->get('meta_query');
    };
    check("R67-03: upload.php's main query hides generated images", $narrowed('upload.php', true), true);
    check('  ...another query on that screen is left alone', $narrowed('upload.php', false), false);
    check("  ...another plugin's admin-ajax query is left alone", $narrowed('admin-ajax.php', true), false);

    // Review of 1.4.32: the plugin's own queries are not hidden from; the
    // icon and attribute filters do nothing with "Display Thumbnail Icon"
    // off; an image's metadata is not given a PDF's keys; the regenerate
    // link is only for someone who may edit the PDF.
    $GLOBALS['pagenow'] = 'upload.php';
    $own = new WP_Query(['post_type' => 'attachment', 'rapls_pic_internal' => true], true);
    $library->filterMediaLibrary($own);
    $GLOBALS['options'][\Rapls\PDFImageCreator\Settings::OPTION_NAME] = ['display_thumbnail_icon' => false];
    $settingsAgain = new \Rapls\PDFImageCreator\Settings();
    $settingsField = new ReflectionProperty(\Rapls\PDFImageCreator\MediaLibrary::class, 'settings');
    if (PHP_VERSION_ID < 80100) { $settingsField->setAccessible(true); }
    $settingsWere = $settingsField->getValue($library);
    $settingsField->setValue($library, $settingsAgain);
    $GLOBALS['mimes'][40] = 'application/pdf';
    $GLOBALS['meta'][40]['_rapls_pic_thumbnail_id'] = 41;
    $GLOBALS['posts'][41] = (object) ['ID' => 41, 'post_type' => 'attachment'];
    $GLOBALS['srcs'][41] = ['https://example.test/doc-40-thumbnail.png', 724, 1024, false];
    $iconOff = [
        $library->filterAttachmentImageAttributes(['src' => 'icon.png'], new WP_Post(40, 0, 'application/pdf'), 'thumbnail'),
        $library->filterMimeTypeIcon('https://example.test/document.png', 'application/pdf', 10),
    ];
    $settingsField->setValue($library, $settingsWere);
    unset($GLOBALS['options'][\Rapls\PDFImageCreator\Settings::OPTION_NAME]);
    $imageMeta = ['width' => 300, 'height' => 200, 'sizes' => ['thumbnail' => ['file' => 'a-150x150.jpg']]];
    $GLOBALS['meta'][30]['_wp_attached_file'] = '2026/10/a.jpg';
    check('the plugin\'s own query is not hidden from; with the icon setting off nothing is swapped; an image\'s metadata is left as it is', [
        $own->get('meta_query'),
        $iconOff,
        $library->ensurePdfMetadataHasImageKeys($imageMeta, 30),
    ], ['', [['src' => 'icon.png'], 'https://example.test/document.png'], $imageMeta]);
    if (!function_exists('current_user_can')) { function current_user_can($cap, ...$a) { return !empty($GLOBALS['can_edit']); } }
    if (!function_exists('wp_nonce_url')) { function wp_nonce_url($u, $a) { return $u . '&_wpnonce=n'; } }
    if (!function_exists('admin_url')) { function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; } }
    if (!function_exists('esc_url')) { function esc_url($u) { return $u; } }
    if (!function_exists('esc_html__')) { function esc_html__($s, $d = null) { return $s; } }
    $GLOBALS['can_edit'] = false;
    $withoutEdit = $library->addRowActions(['edit' => 'Edit'], new WP_Post(10, 0, 'application/pdf'));
    $GLOBALS['can_edit'] = true;
    $withEdit = $library->addRowActions(['edit' => 'Edit'], new WP_Post(10, 0, 'application/pdf'));
    check('  ...the regenerate link only for someone who may edit the PDF', [array_keys($withoutEdit), isset($withEdit['rapls_pic_regenerate'])], [['edit'], true]);

    // Review of 1.4.32: core hands these filters what its caller gave it --
    // '' from an empty post meta, null for $icon -- and a typed parameter made
    // that a fatal error on any page. Called as core calls them, not strictly.
    $untyped = [];
    $GLOBALS['mimes'][977] = 'application/pdf';
    foreach ([
        'image_src' => [[$library, 'filterAttachmentImageSrc'], [false, '', 'large', null], false],
        'image_src null id' => [[$library, 'filterAttachmentImageSrc'], [false, null, 'large', false], false],
        'image' => [[$library, 'filterAttachmentImage'], ['', '', 'large', null, ''], ''],
        // Review after 1.4.32: a size of null or a number reaches the
        // thumbnail's own call, which takes a name.
        'image of a PDF, size null' => [[$library, 'filterAttachmentImage'], ['', 977, null, false, ''], ''],
        'image of a PDF, size 0' => [[$library, 'filterAttachmentImage'], ['', 977, 0, false, ''], ''],
        'mime icon false' => [[$library, 'filterMimeTypeIcon'], [false, 'application/pdf', ''], false],
        'posts_where one arg' => [[$library, 'limitPdfsToThumbnailed'], [' AND 1=1'], ' AND 1=1'],
    ] as $label => [$callback, $args, $want]) {
        try {
            $untyped[$label] = call_user_func_array($callback, $args) === $want;
        } catch (\Throwable $e) {
            $untyped[$label] = get_class($e);
        }
    }
    check("core's filters may pass '' or null: no fatal error, the value comes back", $untyped, array_fill_keys(array_keys($untyped), true));
    // ...and the size it then asks for is core's default, the thumbnail, for
    // a size of null or ''; a name or a number is passed on as a name.
    if (!function_exists('get_the_title')) { function get_the_title($id) { return 'PDF ' . $id; } }
    if (!function_exists('wp_get_attachment_image')) { function wp_get_attachment_image($id, $size = 'thumbnail', $icon = false, $attr = '') { $GLOBALS['image_sizes'][] = $size; return '<img>'; } }
    $GLOBALS['meta'][977]['_rapls_pic_thumbnail_id'] = 978;
    $GLOBALS['posts'][978] = new WP_Post(978, 977, 'image/jpeg');
    $GLOBALS['image_sizes'] = [];
    foreach ([null, '', 'medium', 300, ['a' => 1]] as $askedSize) {
        $library->filterAttachmentImage('', 977, $askedSize, false, '');
    }
    unset($GLOBALS['meta'][977], $GLOBALS['posts'][978]);
    check('  ...a size of null or \'\' asks for the thumbnail; a name or number is passed on; an array is the full size', $GLOBALS['image_sizes'], ['thumbnail', 'thumbnail', 'medium', '300', 'full']);

    // Review of 1.4.33. A library with these settings, read afresh.
    $libraryWith = static function (array $options) use ($generator): \Rapls\PDFImageCreator\MediaLibrary {
        $GLOBALS['options'][\Rapls\PDFImageCreator\Settings::OPTION_NAME] = $options;
        return new \Rapls\PDFImageCreator\MediaLibrary($generator, new \Rapls\PDFImageCreator\Settings());
    };

    // Core's PDF preview is removed only with auto-generate off, and only a PDF's.
    $previewDir = sys_get_temp_dir() . '/rapls-pic-gate-' . getmypid();
    @mkdir($previewDir . '/2026/10', 0777, true);
    $GLOBALS['uploads'] = $previewDir;
    $GLOBALS['mimes'][500] = 'application/pdf';
    $GLOBALS['mimes'][501] = 'image/jpeg';
    $GLOBALS['meta'][500]['_wp_attached_file'] = '2026/10/gate.pdf';
    $GLOBALS['meta'][501]['_wp_attached_file'] = '2026/10/gate.jpg';
    $gateMeta = ['sizes' => ['full' => ['file' => 'gate-pdf.jpg']]];
    $gate = [];
    foreach ([[true, 500], [false, 501], [false, 500]] as [$auto, $id]) {
        file_put_contents($previewDir . '/2026/10/gate-pdf.jpg', 'preview');
        $out = $libraryWith(['auto_generate' => $auto])->maybeSuppressCorePdfPreview($gateMeta, $id, 'create');
        $gate[] = [$out['sizes'] === [], file_exists($previewDir . '/2026/10/gate-pdf.jpg')];
    }
    @unlink($previewDir . '/2026/10/gate-pdf.jpg');
    @rmdir($previewDir . '/2026/10');
    @rmdir($previewDir . '/2026');
    @rmdir($previewDir);
    check('core\'s preview: kept with auto-generate on, kept for an image, removed for a PDF with it off', $gate, [[false, true], [false, true], [true, false]]);

    // A PDF deleted with "hide generated images" off: its own image stays, as
    // an ordinary image -- both marks off it, and the PDF's keys gone.
    $GLOBALS['mimes'][600] = 'application/pdf';
    $GLOBALS['meta'][600] = ['_rapls_pic_thumbnail_id' => 601, '_thumbnail_id' => 601];
    $GLOBALS['meta'][601] = ['_rapls_pic_is_thumbnail' => '1', '_rapls_pic_source_pdf' => 600];
    $GLOBALS['posts'][601] = (object) ['ID' => 601, 'post_type' => 'attachment'];
    $libraryWith(['hide_generated_images' => false])->onAttachmentDeleted(600);
    check('a PDF deleted, hiding off: its image loses both marks, the PDF its keys', [$GLOBALS['meta'][601], $GLOBALS['meta'][600]], [[], []]);

    // Only an attachment counts as a thumbnail, as limitPdfsToThumbnailed() asks.
    $GLOBALS['mimes'][610] = 'application/pdf';
    $GLOBALS['meta'][610]['_thumbnail_id'] = 611;
    $GLOBALS['posts'][611] = (object) ['ID' => 611, 'post_type' => 'page'];
    check('a PDF whose key names a page has no thumbnail', $generator->getThumbnailId(610), null);

    // A generated image's JS response with sizes but no "full": left as it is.
    $noFull = ['url' => 'https://example.test/x.jpg', 'mime' => 'image/jpeg', 'sizes' => ['thumbnail' => ['url' => 'https://example.test/x-150x150.jpg']]];
    try {
        $noFullOut = $library->filterAttachmentForJs($noFull, new WP_Post(210, 10, 'image/jpeg'), [])['sizes'];
    } catch (\Throwable $e) {
        $noFullOut = get_class($e);
    }
    check('a generated image\'s sizes without "full" are left as they are', $noFullOut, $noFull['sizes']);
    // ...and a "full" that some other filter left as something other than an array.
    $oddFull = ['url' => 'https://example.test/x.jpg', 'mime' => 'image/jpeg', 'sizes' => ['full' => 'https://example.test/x.jpg']];
    try {
        $oddFullOut = $library->filterAttachmentForJs($oddFull, new WP_Post(210, 10, 'image/jpeg'), [])['sizes'];
    } catch (\Throwable $e) {
        $oddFullOut = get_class($e);
    }
    check('  ...nor a "full" that is not an array', $oddFullOut, $oddFull['sizes']);

    // The attributes filter asks for the size core was asked for -- an array
    // too, not the 150px square -- and takes a null post without a fatal error.
    $iconOn = $libraryWith(['display_thumbnail_icon' => true]);
    $GLOBALS['src_sizes'] = [];
    $attrOut = [
        $iconOn->filterAttachmentImageAttributes(['src' => 'core.png', 'srcset' => 'a 1w'], new WP_Post(40, 0, 'application/pdf'), [300, 200]),
        $iconOn->filterAttachmentImageAttributes(['src' => 'core.png'], new WP_Post(40, 0, 'application/pdf'), 'medium'),
        $iconOn->filterAttachmentImageAttributes(['src' => 'core.png'], new WP_Post(40, 0, 'application/pdf'), null),
    ];
    // A warning counts: reading ->ID off null is one before it is anything else.
    set_error_handler(static function (int $no, string $message): bool { throw new \ErrorException($message, 0, $no); });
    try {
        $attrOut[] = $iconOn->filterAttachmentImageAttributes(['src' => 'core.png'], null, 'thumbnail');
    } catch (\Throwable $e) {
        $attrOut[] = get_class($e);
    } finally {
        restore_error_handler();
    }
    check('the attributes filter: the size asked, srcset dropped, null post untouched', [$GLOBALS['src_sizes'], $attrOut], [
        [[300, 200], 'medium', 'thumbnail'],
        [['src' => 'https://example.test/doc-40-thumbnail.png'], ['src' => 'https://example.test/doc-40-thumbnail.png'], ['src' => 'https://example.test/doc-40-thumbnail.png'], ['src' => 'core.png']],
    ]);
    unset($GLOBALS['options'][\Rapls\PDFImageCreator\Settings::OPTION_NAME]);


    echo "\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}
