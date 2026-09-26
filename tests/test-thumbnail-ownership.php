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

    $generator = (new ReflectionClass(\Rapls\PDFImageCreator\Generator::class))->newInstanceWithoutConstructor();
    $own = static function (int $image, int $pdf): void {
        $GLOBALS['posts'][$image] = (object) ['ID' => $image];
        $GLOBALS['meta'][$image]['_rapls_pic_is_thumbnail'] = '1';
        $GLOBALS['meta'][$image]['_rapls_pic_source_pdf'] = $pdf;
    };

    // PDF 10's own thumbnail: deleted, as always.
    $own(11, 10);
    $GLOBALS['meta'][10]['_rapls_pic_thumbnail_id'] = 11;
    check('own thumbnail: deleted', [$generator->deleteThumbnail(10), $GLOBALS['deleted']], [true, [11]]);

    // PDF 20's featured image is someone else's picture.
    $GLOBALS['deleted'] = [];
    $GLOBALS['posts'][99] = (object) ['ID' => 99];
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

    echo "\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}
