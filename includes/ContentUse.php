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
 * A post's content and its excerpt are read: WooCommerce keeps a product's
 * short description in post_excerpt, and an image inserted there was
 * deleted (review after 1.4.37). A widget, an option, a theme or page
 * builder setting kept outside the post is not read.
 *
 * Beyond the forms core writes, any number where an ID could stand -- an
 * attribute's value, a JSON value, an item of a list -- counts as naming
 * that attachment (idsInContext()). Page builders keep an image in the
 * content by its ID alone, each in its own spelling ([vc_single_image
 * image="555"], [et_pb_gallery gallery_ids="555"], [fusion_imageframe
 * image_id="555|full"]), and regenerating deleted the image under them
 * (review after 1.4.38). A number that only looks like an ID -- a width,
 * a column count -- keeps an image too many, never deletes one shown.
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
     * out of the post years ago; an auto-draft is shown to nobody.
     *
     * A trashed post is read: it comes back from the trash with its
     * pictures, for 30 days, and one deleted meanwhile was gone for good
     * when it did (review after 1.4.37). Keeping an image too many is
     * recoverable; deleting one a post will show again is not.
     */
    public const SKIP_TYPES = ['attachment', 'revision'];
    public const SKIP_STATUSES = ['auto-draft'];

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
        // One spelling of each character: a name macOS wrote decomposed
        // ("が" as "か" and a combining mark) and the same name composed,
        // as a post holds it, compared unequal (review after 1.4.37).
        // Without intl, names are compared as they are.
        if (class_exists('Normalizer') && 1 === preg_match('/[\x80-\xff]/', $name)) {
            $normal = \Normalizer::normalize($name, \Normalizer::FORM_C);
            if (is_string($normal)) {
                $name = $normal;
            }
        }
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
     * attached file holds it as it is (review of 1.4.34); and an editor can
     * write a character as an HTML entity ("&#22577;"), and a block or a
     * plugin that stores JSON without JSON_UNESCAPED_UNICODE writes it as
     * "\u5831" -- the name was not found there, and the image was deleted
     * (review after 1.4.38).
     *
     * The folder is not part of it: the same name in another month's folder
     * counts as this image, which keeps one too many and never deletes one
     * a post shows.
     *
     * Null when the pattern could not be run over the content -- PCRE's
     * backtrack limit, on a long run of dotted words: that was read as "no
     * file named", and an image the post showed was deleted (review after
     * 1.4.37). stemsShown() falls back to a plainer test then.
     *
     * @return array<string, true>|null
     */
    public static function stemsIn(string $content): ?array
    {
        $content = self::unescapeJson($content);
        $stems = [];
        $found = preg_match_all('/([^\/\s"\'<>()=]+)\.(?:jpe?g|png|gif|webp|avif)\b/i', $content, $files);
        if (false === $found) {
            return null;
        }
        // The whole match, extension and all: stem() takes a density suffix
        // ("@1.5x") off only once the extension is gone, and without it
        // "name@1.5x" lost ".5x" as if that were the extension.
        foreach ($files[0] as $file) {
            $stems[self::stem(rawurldecode(html_entity_decode($file, ENT_QUOTES | ENT_HTML5, 'UTF-8')))] = true;
        }

        return $stems;
    }

    /**
     * Which of these stems a piece of content names
     *
     * stemsIn() when it answers; when it cannot, each stem is looked for as
     * it is, ignoring case -- a stem found inside a longer name then counts,
     * which keeps an image too many and never deletes one shown.
     *
     * @param string[] $stems
     * @return string[]
     */
    public static function stemsShown(string $content, array $stems): array
    {
        if ([] === $stems) {
            return [];
        }
        $named = self::stemsIn($content);
        if (null !== $named) {
            return array_keys(array_intersect_key(array_fill_keys($stems, true), $named));
        }
        $lower = strtr(self::unescapeJson($content), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
        $shown = [];
        foreach ($stems as $stem) {
            foreach (self::fragments([(string) $stem]) as $fragment) {
                if (false !== strpos($lower, $fragment)) {
                    $shown[] = (string) $stem;
                    break;
                }
            }
        }

        return $shown;
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
     * What can stand right before an ID in a value
     *
     * "=" of an attribute, ":" of JSON, "," "|" or "[" of a list, ";" of an
     * entity-quoted value (ids=&quot;12&quot;, &#34;12&#34;), and
     * "wp-image-"; then up to 64 spaces, quotes and backslashes (an
     * attribute quoted inside a string, image=\"555\"; indented JSON) --
     * bounded, as an unbounded run gave MySQL 8's ICU a run to try from
     * every such character of a long post, under regexp_time_limit. Or a
     * digit and white space: the second and later IDs of a list that spaces
     * divide, as WordPress's own wp_parse_id_list() reads [gallery ids="1
     * 555"] -- a list that only ",", "=" or "[" could start missed them, and
     * the image the gallery showed was deleted (review of 1.4.38's fixes).
     * That branch keeps an image for any number after another and a space,
     * "1024 768" among them: too many, never too few, accepted. After
     * either, leading zeros ("0555" is 555, as (int) and the gallery read
     * it). The content is read with JSON's \uXXXX written out, so a quote
     * written \u0022 -- json_encode()'s JSON_HEX_QUOT -- is a quote (review
     * of 1.4.38's fixes). Not counted, and accepted: a run of more than 64
     * spaces or quotes before an ID, which no editor or builder writes, and
     * "\U0022", which JSON does not define (its escape is a lower-case u).
     *
     * The SQL asks a superset. The first branch in a REGEXP every MySQL and
     * MariaDB read alike (ID_CONTEXT_SQL: no backslash inside a bracket,
     * which they do not); the second as LIKEs on the white space before the
     * ID (idLikesSql()), not in the REGEXP: "[0-9]" there had MySQL 8's ICU
     * try every digit of every candidate post for a two- or three-digit ID,
     * under regexp_time_limit, and a query that runs past it fails -- the
     * image is then kept, but kept every time (review of 1.4.38's fixes).
     */
    private const ID_CONTEXT = '(?:(?:[=:,|;\[]|wp-image-)[\s"\'\\\\]{0,64}|(?<=\d)[ \t\r\n]{1,8})0*';
    private const ID_CONTEXT_SQL = '([=:,|;]|\\[|wp-image-)[^0-9a-zA-Z]{0,64}0*';

    /**
     * The LIKE patterns, for the SQL, that find an ID after a digit and white space
     *
     * The white space right before it, each kind ID_CONTEXT's second branch
     * takes, with or without leading zeros between; and any \u00XX escape
     * before it, which the PHP writes out before it reads (a quote as
     * \u0022). A superset, as the PHP then decides.
     *
     * @return string[]
     */
    public static function idLikesSql(int $id): array
    {
        $likes = [];
        foreach ([' ', "\t", "\n", "\r"] as $space) {
            $likes[] = '%' . $space . $id . '%';
            $likes[] = '%' . $space . '0%' . $id . '%';
        }
        $likes[] = '%u00%' . $id . '%';

        return $likes;
    }

    /**
     * Every number in a piece of content that stands where an ID could
     *
     * A superset of idsIn(): what any page builder writes for an image it
     * keeps by ID, and what some other number happens to look like.
     *
     * Null when the pattern could not be run over the content: the caller
     * then keeps, as for stemsIn().
     *
     * @return array<int, true>|null
     */
    public static function idsInContext(string $content): ?array
    {
        $found = preg_match_all('/' . self::ID_CONTEXT . '(\d+)/', self::unescapeJson($content), $m);
        if (false === $found) {
            return null;
        }
        $ids = [];
        foreach ($m[1] as $id) {
            $ids[(int) $id] = true;
        }
        unset($ids[0]);

        return $ids;
    }

    /**
     * The regular expression, for MySQL's REGEXP, a post that could name this ID matches
     *
     * The ID after one of ID_CONTEXT's characters, and no digit after it.
     */
    public static function idRegexSql(int $id): string
    {
        return self::ID_CONTEXT_SQL . $id . '([^0-9]|$)';
    }

    /**
     * JSON's \uXXXX escapes, written out
     *
     * Only runs of them, each run decoded as JSON would, so a pair that
     * stands for one character becomes it; a run that is not valid JSON
     * stays as it was.
     */
    private static function unescapeJson(string $content): string
    {
        if (false === strpos($content, '\\u')) {
            return $content;
        }
        $decoded = preg_replace_callback('/(?:\\\\u[0-9a-fA-F]{4})+/', static function (array $m): string {
            $text = json_decode('"' . $m[0] . '"');

            return is_string($text) ? $text : $m[0];
        }, $content);

        return is_string($decoded) ? $decoded : $content;
    }

    /**
     * The PDF IDs this plugin's own shortcodes in a piece of content name
     *
     * Only uninstall.php asks: a [rapls_pdf_thumbnail id=N] stops showing
     * anything once the plugin is gone, and the image is kept for the post
     * to be pointed at, as for a PDF in a gallery. Regenerating needs no
     * such test, since the shortcode then shows the new thumbnail.
     *
     * @return array<int, true>
     */
    public static function shortcodePdfIdsIn(string $content): array
    {
        $ids = [];
        if (preg_match_all('/\[rapls_pdf_(?:thumbnail|thumbnail_url|clickable_thumbnail|download_link)\b[^\]]*/i', $content, $shortcodes)) {
            foreach ($shortcodes[0] as $shortcode) {
                $shortcode = html_entity_decode($shortcode, ENT_QUOTES, 'UTF-8');
                if (preg_match('/\bid\s*=\s*["\']?(\d+)/i', $shortcode, $m) && (int) $m[1] > 0) {
                    $ids[(int) $m[1]] = true;
                }
            }
        }

        return $ids;
    }

    /**
     * The stem fragments one of which any post showing the image by name must hold
     *
     * Lower case, as the SQL compares them -- LOWER() of the content, so a
     * case-sensitive collation (utf8mb4_bin, utf8mb4_ja_0900_as_cs) finds
     * "Report-pdf-thumbnail.jpg" too; the fragments were lower case and the
     * content was not, the post was not read, and the image it showed was
     * deleted (review after 1.4.37).
     *
     * The stem as it is or percent-encoded. A stem that is not all ASCII
     * gives its longest run of ASCII too: a post can hold the name partly
     * encoded, or with a character as an HTML entity, and neither form of
     * the whole stem is in it.
     *
     * @param string[] $stems
     * @return string[]
     */
    public static function fragments(array $stems): array
    {
        $fragments = [];
        foreach ($stems as $stem) {
            $stem = (string) $stem;
            $fragments[] = $stem;
            $encoded = strtr(rawurlencode($stem), 'ABCDEF', 'abcdef');
            if ($encoded !== $stem) {
                $fragments[] = $encoded;
            }
            if (1 === preg_match('/[^\x20-\x7e]/', $stem) && preg_match_all('/[\x21-\x7e]{4,}/', $stem, $runs)) {
                usort($runs[0], static function (string $a, string $b): int {
                    return strlen($b) - strlen($a);
                });
                $fragments[] = $runs[0][0];
            }
        }

        return array_values(array_unique(array_filter($fragments, 'strlen')));
    }

    /**
     * The LIKE patterns, by name, the SQL that narrows the posts read is made of
     *
     * One per stem fragment, '%fragment%'. The ID is asked apart, with
     * idRegexSql(): one REGEXP per column instead of a LIKE per form of it,
     * each of which had LOWER() work out the whole column again (review
     * after 1.4.38).
     *
     * @param string[] $stems
     * @return string[]
     */
    public static function patterns(array $stems): array
    {
        return self::fragments($stems);
    }

    /**
     * Whether a post could show the image, as the SQL that narrows the posts read asks it
     *
     * A stem fragment, ignoring case, or the ID where an ID could stand.
     * idsIn(), idsInContext() and stemsIn() then decide.
     *
     * @param string[] $stems
     */
    public static function candidate(string $content, int $imageId, array $stems): bool
    {
        if (1 === preg_match('/' . self::ID_CONTEXT . $imageId . '(?![0-9])/', self::unescapeJson($content))) {
            return true;
        }
        $content = strtr($content, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
        foreach (self::patterns($stems) as $fragment) {
            if (false !== strpos($content, $fragment)) {
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
        $context = self::idsInContext($content);
        if (null === $context || isset($context[$imageId])) {
            return true;
        }

        return [] !== self::stemsShown($content, $stems);
    }
}
