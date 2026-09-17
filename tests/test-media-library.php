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

    echo "\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}
