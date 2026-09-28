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
        public function get_var($q) {
            $this->last_error = $this->fail ? 'simulated failure' : '';
            if ($this->fail) { return null; }
            [$metaA, $metaB, $value, $except] = $q[1];
            foreach ($GLOBALS['meta'] as $post => $keys) {
                if ((int) $post === (int) $except) { continue; }
                foreach ([$metaA, $metaB] as $key) {
                    if (isset($keys[$key]) && (string) $keys[$key] === $value) { return (string) $post; }
                }
            }
            return null;
        }
    };

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

    // Uninstalling keeps what something else uses too, as deleteThumbnail()
    // does: the images another post names, and nothing at all when that
    // cannot be asked. Read from the file -- it runs only as an uninstall.
    $uninstall = (string) file_get_contents(RAPLS_PIC_PLUGIN_DIR . 'uninstall.php');
    check('uninstall keeps images another post uses', [
        false !== strpos($uninstall, 'AND used.post_id <> CAST(source.meta_value AS UNSIGNED)'),
        false !== strpos($uninstall, 'in_array((int) $rapls_pic_thumbnail_id, $rapls_pic_used, true)'),
        false !== strpos($uninstall, '!$rapls_pic_used_failed'),
    ], [true, true, true]);

    echo "\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}
