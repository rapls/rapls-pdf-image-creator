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
    function wp_get_attachment_image_src($id, $size = 'thumbnail', $icon = false) { return $GLOBALS['srcs'][$id] ?? false; }
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
    $GLOBALS['posts'][11] = (object) ['ID' => 11];
    $GLOBALS['srcs'][11] = $thumbnail;

    check('PDF with thumbnail, core found its preview: thumbnail', $library->filterAttachmentImageSrc($corePreview, 10, [900, 450], true), $thumbnail);
    check('PDF with thumbnail, core found nothing: thumbnail', $library->filterAttachmentImageSrc(false, 10, 'medium', false), $thumbnail);
    check('PDF without thumbnail: core preview kept', $library->filterAttachmentImageSrc($corePreview, 20, [900, 450], true), $corePreview);
    check('PDF without thumbnail, nothing found: false', $library->filterAttachmentImageSrc(false, 20, 'medium', false), false);
    check('not a PDF: untouched', $library->filterAttachmentImageSrc($corePreview, 30, 'medium', false), $corePreview);

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

    echo "\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}
