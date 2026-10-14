<?php
/**
 * Whether a post's content shows an image
 *
 * @package PDFImageCreator
 */

declare(strict_types=1);

namespace Rapls\PDFImageCreator;

/**
 * The one test both Generator and uninstall.php make
 *
 * A thumbnail is shown by a post when the post holds the image's file name
 * in any size, or names the image by ID: a "wp-image-N" class, an image
 * block's "id", a gallery block's "ids", or a [gallery] shortcode's ids or
 * include. The two used to make it separately: uninstall.php compared whole
 * names, Generator matched "%name%" in SQL, so "report-pdf-thumbnail" was
 * kept because a post showed "annual-report-pdf-thumbnail", and neither
 * looked at a gallery that names its images by ID only -- a regeneration
 * deleted the picture under it (review of 1.4.36).
 *
 * Only post_content is read. A widget, an option, a theme or page builder
 * setting that names an image by ID is not found.
 *
 * Plain functions of strings, so uninstall.php can load this file on its
 * own, without the plugin.
 */
final class ContentUse
{
    /**
     * Post types and states not counted as showing anything
     *
     * A revision keeps every image a post ever had, and kept a picture taken
     * out of the post years ago; an auto-draft or a trashed post is not
     * shown either.
     */
    public const SKIP_TYPES = ['attachment', 'revision'];
    public const SKIP_STATUSES = ['auto-draft', 'trash'];

    /**
     * A file name without folder, extension or size suffix, lower case
     *
     * "2026/10/A-pdf-thumbnail-300x200.jpg" and "a-pdf-thumbnail.jpg" both
     * give "a-pdf-thumbnail". Only ASCII is lowered: strtolower() on PHP 7
     * follows the locale and can alter a UTF-8 name's bytes.
     *
     * A second image extension goes too ("name.jpg.webp", as WebP plugins
     * name their copies), and a density suffix ("name-300x200@2x.jpg", as
     * retina plugins do): both were missed, and the image deleted (review
     * after 1.4.36).
     */
    public static function stem(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = strtr($name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
        $name = (string) preg_replace('/\.[a-z0-9]+$/', '', $name);
        $name = (string) preg_replace('/\.(?:jpe?g|png|gif|webp|avif)$/', '', $name);

        return (string) preg_replace('/(?:-scaled|-rotated|-\d+x\d+|@\d+(?:\.\d+)?x)+$/', '', $name);
    }

    /**
     * Every stem an image has had
     *
     * Its file now, and the files WordPress's image editor kept when it was
     * edited ("_wp_attachment_backup_sizes"): a post inserted before the
     * edit still shows the old name, and the attached file is now
     * "name-e1696…" (review of 1.4.36).
     *
     * @param mixed $backupSizes The backup sizes meta, as get_post_meta() gives it.
     * @return string[]
     */
    public static function stemsOf(string $attachedFile, $backupSizes = []): array
    {
        $stems = [];
        if ('' !== $attachedFile) {
            $stems[] = self::stem($attachedFile);
        }
        if (is_array($backupSizes)) {
            foreach ($backupSizes as $size) {
                if (is_array($size) && isset($size['file']) && is_string($size['file']) && '' !== $size['file']) {
                    $stems[] = self::stem($size['file']);
                }
            }
        }

        return array_values(array_unique(array_filter($stems, 'strlen')));
    }

    /**
     * The stems of every image file a piece of content names
     *
     * Decoded: an address percent-encodes a name that is not ASCII, and the
     * attached file holds it as it is (review of 1.4.34).
     *
     * @return array<string, true>
     */
    public static function stemsIn(string $content): array
    {
        $stems = [];
        if (preg_match_all('/([^\/\s"\'<>()=]+)\.(?:jpe?g|png|gif|webp|avif)\b/i', $content, $files)) {
            foreach ($files[0] as $file) {
                $stems[self::stem(rawurldecode($file))] = true;
            }
        }

        return $stems;
    }

    /**
     * The attachment IDs a piece of content names
     *
     * A "wp-image-N" class; an image block's {"id":N}; a gallery block's
     * {"ids":[…]}; a [gallery] shortcode's ids="…" or include="…".
     *
     * @return array<int, true>
     */
    public static function idsIn(string $content): array
    {
        $ids = [];
        if (preg_match_all('/\bwp-image-(\d+)\b/', $content, $m)) {
            foreach ($m[1] as $id) {
                $ids[(int) $id] = true;
            }
        }
        // The block's whole attributes: an object inside them ("lightbox":
        // {…}) ended a search for "id" at its first "}" (review after
        // 1.4.36). Read as JSON; where that fails, any "id" in them counts --
        // keeping an image too many, never deleting one shown.
        // Found with strpos(), not one pattern over the whole content: a lazy
        // ".*?" there could reach PCRE's backtrack limit on a long post and
        // answer nothing, which read as no ID at all (review after 1.4.36).
        $blocks = [];
        for ($at = 0; false !== ($at = strpos($content, 'wp:', $at)); $at = $end) {
            $end = strpos($content, '-->', $at);
            if (false === $end) {
                break;
            }
            // The comment's opening before "wp:", in any spacing, and the
            // block's name after it, "image" or "core/image": a fixed
            // "<!-- wp:image" missed "<!--  wp:image", a line break, and
            // the full name (review after 1.4.36).
            // Within a few characters before it: white space is all there is.
            $from = max(0, $at - 64);
            $open = strrpos(substr($content, $from, $at - $from), '<!--');
            $open = false === $open ? false : $from + $open;
            if (false === $open || 1 !== preg_match('/\A<!--\s+\z/', substr($content, $open, $at - $open))
                || 1 !== preg_match('/\Awp:(?:core\/)?image\s/', substr($content, $at, 20))) {
                $end = $at + 3;
                continue;
            }
            $comment = substr($content, $open, $end - $open);
            $found = preg_match('/\A<!--\s+wp:(?:core\/)?image\s+(\{.*\})\s*\/?\z/s', $comment, $one);
            if (1 === $found) {
                $blocks[] = $one[1];
            } elseif (false === $found) {
                // Not read: any "id" in it counts, below.
                $blocks[] = $comment;
            }
        }
        if ([] !== $blocks) {
            foreach ($blocks as $json) {
                $attributes = json_decode($json, true);
                if (is_array($attributes)) {
                    if (isset($attributes['id']) && is_numeric($attributes['id'])) {
                        $ids[(int) $attributes['id']] = true;
                    }
                } elseif (preg_match_all('/"id"\s*:\s*"?(\d+)/', $json, $found)) {
                    foreach ($found[1] as $id) {
                        $ids[(int) $id] = true;
                    }
                }
            }
        }
        $lists = [];
        // Numbers or numeric strings: ["12"] as well as [12].
        if (preg_match_all('/"ids"\s*:\s*\[([\d,\s"]*)\]/', $content, $m)) {
            $lists = array_merge($lists, str_replace('"', '', $m[1]));
        }
        if (preg_match_all('/\[gallery\b[^\]]*/i', $content, $shortcodes)) {
            foreach ($shortcodes[0] as $shortcode) {
                // As the editor may have stored it: ids=&quot;12&quot;.
                $shortcode = html_entity_decode($shortcode, ENT_QUOTES, 'UTF-8');
                if (preg_match_all('/\b(?:ids|include)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([\d,]+))/i', $shortcode, $m, PREG_SET_ORDER)) {
                    foreach ($m as $attribute) {
                        $lists[] = $attribute[1] . ($attribute[2] ?? '') . ($attribute[3] ?? '');
                    }
                }
            }
        }
        foreach ($lists as $list) {
            foreach (preg_split('/[\s,]+/', (string) $list) ?: [] as $id) {
                if (ctype_digit($id)) {
                    $ids[(int) $id] = true;
                }
            }
        }
        unset($ids[0]);

        return $ids;
    }

    /**
     * What any form that names the image by ID leaves in the content, beside the ID's digits
     */
    public const ID_FORMS = ['wp-image-', '"id"', '[gallery', '"ids"'];

    /**
     * The stem fragments one of which any post showing the image by name must hold
     *
     * The stem as it is or percent-encoded.
     *
     * @param string[] $stems
     * @return string[]
     */
    public static function fragments(array $stems): array
    {
        $fragments = [];
        foreach ($stems as $stem) {
            $fragments[] = $stem;
            if (rawurlencode($stem) !== $stem) {
                $fragments[] = rawurlencode($stem);
            }
        }

        return array_values(array_unique($fragments));
    }

    /**
     * Whether a post could show the image, as the SQL that narrows the posts read asks it
     *
     * One of the stem fragments; or the ID's digits together with one of
     * ID_FORMS. idsIn() and stemsIn() then decide. Any gallery at all once
     * made every post with a gallery a candidate, read in full for every
     * image (review after 1.4.36); every form that names an image by ID
     * holds its digits.
     *
     * @param string[] $stems
     */
    public static function candidate(string $content, int $imageId, array $stems): bool
    {
        foreach (self::fragments($stems) as $fragment) {
            if (false !== stripos($content, $fragment)) {
                return true;
            }
        }
        if (false === strpos($content, (string) $imageId)) {
            return false;
        }
        foreach (self::ID_FORMS as $form) {
            if (false !== stripos($content, $form)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this content shows the image
     *
     * @param string[] $stems
     */
    public static function shows(string $content, int $imageId, array $stems): bool
    {
        if (isset(self::idsIn($content)[$imageId])) {
            return true;
        }
        if ([] === $stems) {
            return false;
        }

        return [] !== array_intersect_key(self::stemsIn($content), array_fill_keys($stems, true));
    }
}
