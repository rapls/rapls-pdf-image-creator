<?php
/**
 * Uninstalling cleans every site of a network, each by its own settings (R64-01).
 *
 * uninstall.php is run as WordPress runs it -- included once -- against a
 * stand-in network of two sites whose options and postmeta are kept apart.
 * Then rapls_pic_uninstall_site() is run again on one site, seeded afresh,
 * for the images posts still show and for each lookup that can fail
 * (review of 1.4.33).
 */

declare(strict_types=1);

define('WP_UNINSTALL_PLUGIN', 'rapls-pdf-image-creator/rapls-pdf-image-creator.php');
define('WP_LANG_DIR', sys_get_temp_dir());
define('ARRAY_A', 'ARRAY_A');

/**
 * Every option and transient the plugin keeps, as the plugin names them
 *
 * Read from includes/ and the main file rather than listed by hand, so a
 * name added there and forgotten in uninstall.php fails below.
 *
 * @return array{options: string[], transients: string[]}
 */
function plugin_names(): array
{
    $root = dirname(__DIR__);
    $files = [$root . '/rapls-pdf-image-creator.php'];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/includes', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ('php' === $file->getExtension()) {
            $files[] = $file->getPathname();
        }
    }

    $names = ['options' => [], 'transients' => []];
    foreach ($files as $file) {
        $source = (string) file_get_contents($file);
        // Constants named for what they hold: FOO_OPTION, OPTION_NAME, FOO_TRANSIENT, TRANSIENT_PREFIX.
        preg_match_all("/const\\s+(?:\\w*_)?(OPTION|TRANSIENT)(?:_\\w*)?\\s*=\\s*'([a-z0-9_]+)'/", $source, $constants, PREG_SET_ORDER);
        foreach ($constants as $constant) {
            $names['TRANSIENT' === $constant[1] ? 'transients' : 'options'][] = $constant[2];
        }
        preg_match_all("/define\\(\\s*'\\w*NOTICE'\\s*,\\s*'([a-z0-9_]+)'/", $source, $defines);
        foreach ($defines[1] as $name) {
            $names['transients'][] = $name;
        }
        // And names written out where they are used.
        preg_match_all("/\\b(?:get|add|update|delete|set)_(option|transient)\\(\\s*'([a-z0-9_]+)'/", $source, $literals, PREG_SET_ORDER);
        foreach ($literals as $literal) {
            $names['option' === $literal[1] ? 'options' : 'transients'][] = $literal[2];
        }
    }

    return ['options' => array_values(array_unique($names['options'])), 'transients' => array_values(array_unique($names['transients']))];
}

$GLOBALS['names'] = plugin_names();

function seed_options(array $options): array
{
    foreach ($GLOBALS['names']['options'] as $name) {
        $options[$name] = $options[$name] ?? 'seeded';
    }
    return $options;
}
/** A name ending in "_" is a prefix (the ICC paths); both of its keys are seeded. */
function seed_transients(): array
{
    $transients = [];
    foreach ($GLOBALS['names']['transients'] as $name) {
        if ('_' === substr($name, -1)) {
            $transients[$name . 'srgb'] = 'seeded';
            $transients[$name . 'cmyk'] = 'seeded';
        } else {
            $transients[$name] = 'seeded';
        }
    }
    return $transients;
}

$GLOBALS['blog'] = 1;
$GLOBALS['switches'] = [];
// Per site: options, transients, postmeta rows [post_id, key, value], posts
// [ID => content], the attachments that exist, and what was deleted.
$GLOBALS['sites'] = [
    1 => ['options' => seed_options(['rapls_pic_settings' => ['keep_on_uninstall' => false]]), 'transients' => seed_transients(), 'meta' => [[10, '_rapls_pic_thumbnail_id', '11'], [11, '_rapls_pic_is_thumbnail', '1'], [11, '_rapls_pic_source_pdf', '10']], 'posts' => [], 'attachments' => [11], 'deleted' => []],
    2 => ['options' => seed_options(['rapls_pic_settings' => ['keep_on_uninstall' => true]]), 'transients' => seed_transients(), 'meta' => [[20, '_rapls_pic_thumbnail_id', '21'], [21, '_rapls_pic_is_thumbnail', '1'], [21, '_rapls_pic_source_pdf', '20'], [25, '_thumbnail_id', '20'], [22, '_rapls_pic_thumbnail_id', '23'], [23, '_rapls_pic_is_thumbnail', '1'], [23, '_rapls_pic_source_pdf', '22']], 'posts' => [], 'attachments' => [21, 23], 'deleted' => []],
];
$GLOBALS['user_meta_deleted'] = [];
// A query whose SQL contains this fails, as a database error would: wpdb
// sets last_error and hands back an empty list, not null -- so the
// last_error checks are what stand between a failed query and a deletion.
// 'fail_returns' = null tries the other form too.
$GLOBALS['fail_on'] = null;
$GLOBALS['fail_returns'] = [];

function is_multisite() { return !empty($GLOBALS['multisite']); }
function get_sites($args = []) { return array_keys($GLOBALS['sites']); }
function switch_to_blog($id) { $GLOBALS['switches'][] = $GLOBALS['blog']; $GLOBALS['blog'] = (int) $id; return true; }
function restore_current_blog() { $GLOBALS['blog'] = array_pop($GLOBALS['switches']); return true; }
function &site() { return $GLOBALS['sites'][$GLOBALS['blog']]; }
function get_option($key, $default = false) { return site()['options'][$key] ?? $default; }
function delete_option($key) { unset(site()['options'][$key]); return true; }
function delete_transient($key) { unset(site()['transients'][$key]); return true; }
function wp_cache_delete($key, $group = '') { return true; }
function wp_basename($path, $suffix = '') { return basename($path, $suffix); }
function maybe_unserialize($value) { return is_string($value) && preg_match('/^a:\d+:\{/', $value) ? unserialize($value, ['allowed_classes' => false]) : $value; }
function delete_metadata($type, $id, $key, $value = '', $all = false) { $GLOBALS['user_meta_deleted'][] = $key; return true; }
function wp_delete_attachment($id, $force = false) { site()['deleted'][] = (int) $id; return true; }
function update_post_meta($id, $key, $value, $previous = '') {
    foreach (site()['meta'] as &$row) {
        if ($row[0] === $id && $row[1] === $key && ('' === $previous || $row[2] === (string) $previous)) {
            $row[2] = (string) $value;
        }
    }
    return true;
}
function meta_value(int $id, string $key): ?string {
    foreach (site()['meta'] as $row) {
        if ($row[0] === $id && $row[1] === $key) {
            return $row[2];
        }
    }
    return null;
}

$GLOBALS['wpdb'] = new class {
    public $postmeta = 'wp_postmeta';
    public $posts = 'wp_posts';
    public $last_error = '';
    private function failing(string $sql): bool {
        $this->last_error = (null !== $GLOBALS['fail_on'] && false !== strpos($sql, $GLOBALS['fail_on'])) ? 'Table is marked as crashed' : '';
        return '' !== $this->last_error;
    }
    public function prepare($sql, ...$args) { return vsprintf($sql, $args); }
    public function get_col($sql) {
        if ($this->failing($sql)) {
            return $GLOBALS['fail_returns'];
        }
        if (false !== strpos($sql, "used.meta_key IN ('_thumbnail_id', '_rapls_pic_thumbnail_id')")) {
            // A thumbnail some post other than its PDF names, when the SQL
            // asks for that; without the clause, the PDF's own record counts.
            $otherThanPdf = false !== strpos($sql, 'used.post_id <> CAST(source.meta_value AS UNSIGNED)');
            // Joined on the marker, the source LEFT JOINed: an image with no
            // source still counts (review of 1.4.39). Joined on the source,
            // it did not.
            $byMarker = false !== strpos($sql, "INNER JOIN wp_postmeta marker ON marker.post_id = CAST(used.meta_value AS UNSIGNED) AND marker.meta_key = '_rapls_pic_is_thumbnail'")
                && false !== strpos($sql, "LEFT JOIN wp_postmeta source ON source.post_id = marker.post_id AND source.meta_key = '_rapls_pic_source_pdf'")
                && false !== strpos($sql, '(source.meta_value IS NULL OR used.post_id <> CAST(source.meta_value AS UNSIGNED))');
            $used = [];
            foreach (site()['meta'] as $row) {
                if (in_array($row[1], ['_thumbnail_id', '_rapls_pic_thumbnail_id'], true)) {
                    $source = meta_value((int) $row[2], '_rapls_pic_source_pdf');
                    $marked = null !== meta_value((int) $row[2], '_rapls_pic_is_thumbnail');
                    $joined = $byMarker ? $marked : null !== $source;
                    if ($joined && (null === $source || !$otherThanPdf || $row[0] !== (int) $source)) {
                        $used[] = $row[2];
                    }
                }
            }
            return array_values(array_unique($used));
        }
        throw new RuntimeException('unexpected get_col: ' . $sql);
    }
    public function get_results($sql, $output = null) {
        if ($this->failing($sql)) {
            return $GLOBALS['fail_returns'];
        }
        if (false !== strpos($sql, "marker.meta_key = '_rapls_pic_is_thumbnail'")) {
            $rows = [];
            foreach (site()['meta'] as $row) {
                if ('_rapls_pic_is_thumbnail' === $row[1] && '1' === $row[2]) {
                    $rows[] = ['id' => (string) $row[0], 'file' => meta_value($row[0], '_wp_attached_file'), 'pdf' => meta_value($row[0], '_rapls_pic_source_pdf'), 'backup' => false !== strpos($sql, "backup.meta_key = '_wp_attachment_backup_sizes'") ? meta_value($row[0], '_wp_attachment_backup_sizes') : null];
                }
            }
            return $rows;
        }
        if (false !== strpos($sql, "thumb.meta_key = '_rapls_pic_thumbnail_id'")) {
            // Only thumbnails that are still attachments, when the SQL says so.
            $attachmentsOnly = false !== strpos($sql, "image.post_type = 'attachment'");
            $rows = [];
            foreach (site()['meta'] as $row) {
                if ('_thumbnail_id' === $row[1]) {
                    $thumbnail = meta_value((int) $row[2], '_rapls_pic_thumbnail_id');
                    if (null !== $thumbnail && (!$attachmentsOnly || in_array((int) $thumbnail, site()['attachments'], true))) {
                        $rows[] = ['post' => (string) $row[0], 'pdf' => $row[2], 'thumbnail' => $thumbnail];
                    }
                }
            }
            // Something changing a featured image between this lookup and the update.
            if (isset($GLOBALS['after_featured_lookup'])) {
                ($GLOBALS['after_featured_lookup'])();
            }
            return $rows;
        }
        if (preg_match('/WHERE ID > (\d+) .* LIMIT (\d+)/', $sql, $m)) {
            $GLOBALS['post_reads'] = ($GLOBALS['post_reads'] ?? 0) + 1;
            // Attachments' own descriptions, revisions, auto-drafts and
            // trashed posts left out, when the SQL says so.
            $skip = [];
            if (preg_match("/post_type NOT IN \\(([^)]*)\\)/", $sql, $types)) {
                if (false !== strpos($types[1], "'attachment'")) { $skip = array_merge($skip, site()['attachment_posts'] ?? []); }
                if (false !== strpos($types[1], "'revision'")) { $skip = array_merge($skip, site()['revisions'] ?? []); }
            }
            if (preg_match("/post_status NOT IN \\(([^)]*)\\)/", $sql, $statuses)) {
                foreach (site()['statuses'] ?? [] as $status => $ids) {
                    if (false !== strpos($statuses[1], "'$status'")) { $skip = array_merge($skip, $ids); }
                }
            }
            $rows = [];
            // The excerpt too, when the SQL asks for it (review after 1.4.37).
            $withExcerpt = 1 === preg_match('/SELECT ID, post_content, post_excerpt FROM/', $sql);
            $ids = array_unique(array_merge(array_keys(site()['posts']), array_keys(site()['excerpts'] ?? [])));
            sort($ids);
            // "post_content <> ''" read as MySQL would: a post with an empty
            // content and an excerpt is read only when the excerpt is asked
            // about too (review after 1.4.38).
            $nonEmptyContent = false !== strpos($sql, "post_content <> ''");
            $nonEmptyExcerpt = false !== strpos($sql, "post_excerpt <> ''");
            foreach ($ids as $id) {
                if ($nonEmptyContent && '' === (site()['posts'][$id] ?? '') && !($nonEmptyExcerpt && '' !== (site()['excerpts'][$id] ?? ''))) {
                    continue;
                }
                if ($id > (int) $m[1] && count($rows) < (int) $m[2] && !in_array($id, $skip, true)) {
                    $row = ['ID' => (string) $id, 'post_content' => site()['posts'][$id] ?? ''];
                    if ($withExcerpt) { $row['post_excerpt'] = site()['excerpts'][$id] ?? ''; }
                    $rows[] = $row;
                }
            }
            // A post PCRE then cannot be run over (review after 1.4.38):
            // starved only once this answer is read, so the stand-in itself
            // still reads the SQL.
            if (!empty($GLOBALS['starve_pcre'])) {
                ini_set('pcre.jit', '0');
                ini_set('pcre.backtrack_limit', '1');
            }
            return $rows;
        }
        throw new RuntimeException('unexpected get_results: ' . $sql);
    }
    public function delete($table, $where) {
        site()['meta'] = array_values(array_filter(site()['meta'], static function ($row) use ($where) { return $row[1] !== $where['meta_key']; }));
        return 1;
    }
};

$pass = 0;
$fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    $ok = $got === $want;
    $ok ? $pass++ : $fail++;
    printf("%s %-62s got=%s want=%s\n", $ok ? 'ok  ' : 'FAIL', $label, json_encode($got), json_encode($want));
}

check('the scan found the names it should', [count($GLOBALS['names']['options']) >= 8, count($GLOBALS['names']['transients']) >= 9, in_array('rapls_pic_activation_notice', $GLOBALS['names']['transients'], true), in_array('rapls_pic_icc_path_', $GLOBALS['names']['transients'], true)], [true, true, true, true]);

$GLOBALS['multisite'] = true;
require dirname(__DIR__) . '/uninstall.php';

check('site 1 (keep off): its generated image deleted', $GLOBALS['sites'][1]['deleted'], [11]);
check('site 2 (keep on): its generated image kept', $GLOBALS['sites'][2]['deleted'], []);
check('  ...and its unused one too', in_array(23, $GLOBALS['sites'][2]['deleted'], true), false);
check('both sites: every option gone', [array_keys($GLOBALS['sites'][1]['options']), array_keys($GLOBALS['sites'][2]['options'])], [[], []]);
check('both sites: every transient gone', [array_keys($GLOBALS['sites'][1]['transients']), array_keys($GLOBALS['sites'][2]['transients'])], [[], []]);
check('both sites: markers gone, a post\'s featured image kept', [$GLOBALS['sites'][1]['meta'], $GLOBALS['sites'][2]['meta']], [[], [[25, '_thumbnail_id', '21']]]);
check('back on the site it started from', [$GLOBALS['blog'], $GLOBALS['switches']], [1, []]);
check('user flags removed once, for the network', count($GLOBALS['user_meta_deleted']), 2);

echo "\n--- Images posts still show, keep off ---\n";

/**
 * One site with four PDFs and their thumbnails, and 250 posts
 *
 *  30/31  a post's featured image is the PDF
 *  40/41  a post shows a size of the thumbnail by its address (post 330,
 *         past the first 200, so the second batch has to be read)
 *  60/61  a post's block names the PDF by its wp-image- class only
 *  70/71  nothing uses it
 */
function seed_site(): void
{
    $posts = [];
    for ($id = 100; $id < 350; $id++) {
        $posts[$id] = '<p>Nothing here but <img src="https://example.test/wp-content/uploads/2026/10/other-photo.jpg"></p>';
    }
    $posts[330] = '<!-- wp:image {"id":40} --><figure><img src="https://example.test/wp-content/uploads/2026/10/b-pdf-thumbnail-300x200.jpg" alt=""/></figure><!-- /wp:image -->';
    $posts[120] = '<!-- wp:image {"id":60} --><figure><img src="https://cdn.example.test/x" class="wp-image-60"/></figure><!-- /wp:image -->';
    // An attachment's own description is not a post showing it.
    $posts[345] = '<img src="https://example.test/wp-content/uploads/2026/10/d-pdf-thumbnail.jpg">';

    $meta = [[90, '_thumbnail_id', '30']];
    foreach ([30 => 'a', 40 => 'b', 60 => 'c', 70 => 'd'] as $pdf => $name) {
        $meta[] = [$pdf, '_rapls_pic_thumbnail_id', (string) ($pdf + 1)];
        $meta[] = [$pdf + 1, '_rapls_pic_is_thumbnail', '1'];
        $meta[] = [$pdf + 1, '_rapls_pic_source_pdf', (string) $pdf];
        $meta[] = [$pdf + 1, '_wp_attached_file', "2026/10/$name-pdf-thumbnail.jpg"];
    }

    $GLOBALS['sites'][1] = ['options' => ['rapls_pic_settings' => ['keep_on_uninstall' => false]], 'transients' => [], 'meta' => $meta, 'posts' => $posts, 'attachment_posts' => [345], 'attachments' => [31, 41, 61, 71], 'deleted' => []];
    $GLOBALS['blog'] = 1;
}

seed_site();
rapls_pic_uninstall_site();
check('only the image nothing uses is deleted', $GLOBALS['sites'][1]['deleted'], [71]);
check('the featured PDF now names its image', meta_value(90, '_thumbnail_id'), '31');

foreach ([
    'the featured-image lookup' => "thumb.meta_key = '_rapls_pic_thumbnail_id'",
    'the list of thumbnails' => "marker.meta_key = '_rapls_pic_is_thumbnail'",
    'the used-elsewhere lookup' => "used.meta_key IN ('_thumbnail_id', '_rapls_pic_thumbnail_id')",
    'reading the posts' => 'post_content <>',
] as $label => $sql) {
    foreach ([[], null] as $returned) {
        seed_site();
        $GLOBALS['fail_on'] = $sql;
        $GLOBALS['fail_returns'] = $returned;
        rapls_pic_uninstall_site();
        $GLOBALS['fail_on'] = null;
        $GLOBALS['fail_returns'] = [];
        check("when $label fails (" . json_encode($returned) . '), nothing is deleted', $GLOBALS['sites'][1]['deleted'], []);
    }
}

echo "\n--- Names a post's content gives ---\n";

// A WebP thumbnail, shown by its address.
seed_site();
foreach ($GLOBALS['sites'][1]['meta'] as &$rapls_row) {
    if (71 === $rapls_row[0] && '_wp_attached_file' === $rapls_row[1]) {
        $rapls_row[2] = '2026/10/d-pdf-thumbnail.webp';
    }
}
unset($rapls_row);
$GLOBALS['sites'][1]['posts'][200] = '<img src="https://example.test/wp-content/uploads/2026/10/d-pdf-thumbnail-300x200.webp">';
rapls_pic_uninstall_site();
check('a WebP thumbnail a post shows is kept', $GLOBALS['sites'][1]['deleted'], []);

// A name that is not ASCII, percent-encoded in the address.
seed_site();
foreach ($GLOBALS['sites'][1]['meta'] as &$rapls_row) {
    if (71 === $rapls_row[0] && '_wp_attached_file' === $rapls_row[1]) {
        $rapls_row[2] = '2026/10/資料-pdf-thumbnail.jpg';
    }
}
unset($rapls_row);
$GLOBALS['sites'][1]['posts'][200] = '<img src="https://example.test/wp-content/uploads/2026/10/%E8%B3%87%E6%96%99-pdf-thumbnail-300x212.jpg">';
rapls_pic_uninstall_site();
check('a percent-encoded name a post shows is kept', $GLOBALS['sites'][1]['deleted'], []);

echo "\n--- Featured images: only to an attachment, and only if unchanged ---\n";

// A PDF whose recorded thumbnail is not an attachment any more (a post with
// that ID, or nothing): the post keeps the PDF rather than naming a non-image.
seed_site();
$GLOBALS['sites'][1]['meta'][] = [80, '_rapls_pic_thumbnail_id', '81'];
$GLOBALS['sites'][1]['meta'][] = [91, '_thumbnail_id', '80'];
rapls_pic_uninstall_site();
check('a thumbnail that is not an attachment is not made the featured image', meta_value(91, '_thumbnail_id'), '80');

// A featured image someone changes while this runs is left as they set it.
seed_site();
$GLOBALS['after_featured_lookup'] = static function (): void {
    foreach ($GLOBALS['sites'][1]['meta'] as &$row) {
        if (90 === $row[0] && '_thumbnail_id' === $row[1]) {
            $row[2] = '555';
        }
    }
};
rapls_pic_uninstall_site();
unset($GLOBALS['after_featured_lookup']);
check('  ...and one changed meanwhile is not overwritten', meta_value(90, '_thumbnail_id'), '555');

echo "\n--- Reading posts stops once every thumbnail is found ---\n";

// All three thumbnails nothing else keeps are shown by post 130, in the first
// 200 posts: the second batch is not read. The featured one (31) is not looked
// for at all -- if it were, nothing would show it and every post would be read.
seed_site();
$GLOBALS['sites'][1]['posts'][130] = '<img src="/u/2026/10/b-pdf-thumbnail.jpg"><img src="/u/2026/10/d-pdf-thumbnail-150x150.jpg">';
$GLOBALS['post_reads'] = 0;
rapls_pic_uninstall_site();
check('one batch read, and nothing deleted', [$GLOBALS['post_reads'], $GLOBALS['sites'][1]['deleted']], [1, []]);
seed_site();
$GLOBALS['post_reads'] = 0;
rapls_pic_uninstall_site();
check('  ...while one never found means every post is read', $GLOBALS['post_reads'], 2);

$rapls_stem = [\Rapls\PDFImageCreator\ContentUse::class, 'stem'];
check('a stem drops size, "-scaled" and extension', [$rapls_stem('2026/10/B-pdf-thumbnail-300x200.JPG'), $rapls_stem('x/b-pdf-thumbnail-scaled.webp'), $rapls_stem('b-pdf-thumbnail-1.jpg')], ['b-pdf-thumbnail', 'b-pdf-thumbnail', 'b-pdf-thumbnail-1']);

echo "\n--- Review of 1.4.36: galleries by ID, edited images, names compared whole ---\n";

// A classic [gallery] names its images by ID only.
seed_site();
$GLOBALS['sites'][1]['posts'][200] = '[gallery ids="5,71,9"]';
rapls_pic_uninstall_site();
check('an image a [gallery] names by ID is kept', $GLOBALS['sites'][1]['deleted'], []);

// A gallery block, by its "ids".
seed_site();
$GLOBALS['sites'][1]['posts'][200] = '<!-- wp:gallery {"ids":[71]} --><figure class="wp-block-gallery"></figure><!-- /wp:gallery -->';
rapls_pic_uninstall_site();
check('  ...and one a gallery block names by "ids"', $GLOBALS['sites'][1]['deleted'], []);

// A [gallery] with the PDF in include= showed its thumbnail.
seed_site();
$GLOBALS['sites'][1]['posts'][200] = "[gallery include='70']";
rapls_pic_uninstall_site();
check('  ...and one whose PDF a gallery includes', $GLOBALS['sites'][1]['deleted'], []);

// Edited after it was inserted: the attached file is the edited name, the
// post still shows the original, which the backup sizes record.
seed_site();
foreach ($GLOBALS['sites'][1]['meta'] as &$rapls_row) {
    if (71 === $rapls_row[0] && '_wp_attached_file' === $rapls_row[1]) {
        $rapls_row[2] = '2026/10/d-pdf-thumbnail-e1696000000000.jpg';
    }
}
unset($rapls_row);
$GLOBALS['sites'][1]['meta'][] = [71, '_wp_attachment_backup_sizes', serialize(['full-orig' => ['file' => 'd-pdf-thumbnail.jpg', 'width' => 1024, 'height' => 724]])];
$GLOBALS['sites'][1]['posts'][200] = '<img src="/wp-content/uploads/2026/10/d-pdf-thumbnail-300x212.jpg">';
rapls_pic_uninstall_site();
check('an edited image a post shows under its old name is kept', $GLOBALS['sites'][1]['deleted'], []);

// A name that only ends the same is another image.
seed_site();
$GLOBALS['sites'][1]['posts'][200] = '<img src="/wp-content/uploads/2026/10/annual-d-pdf-thumbnail.jpg">';
rapls_pic_uninstall_site();
check('a post showing "annual-d-pdf-thumbnail" does not keep "d-pdf-thumbnail"', $GLOBALS['sites'][1]['deleted'], [71]);

// A revision and an auto-draft do not count.
seed_site();
$GLOBALS['sites'][1]['posts'][200] = '<img src="/wp-content/uploads/2026/10/d-pdf-thumbnail.jpg">';
$GLOBALS['sites'][1]['posts'][201] = '<img class="wp-image-71">';
$GLOBALS['sites'][1]['revisions'] = [200];
$GLOBALS['sites'][1]['statuses'] = ['auto-draft' => [201]];
rapls_pic_uninstall_site();
check('a revision and an auto-draft keep nothing', $GLOBALS['sites'][1]['deleted'], [71]);

echo "\n--- Review after 1.4.37 ---\n";

// A trashed post does: it can be restored with its pictures.
seed_site();
$GLOBALS['sites'][1]['posts'][202] = '[gallery ids="71"]';
$GLOBALS['sites'][1]['statuses'] = ['trash' => [202]];
rapls_pic_uninstall_site();
check('a trashed post keeps what it shows, for when it is restored', $GLOBALS['sites'][1]['deleted'], []);

// An excerpt -- a WooCommerce product's short description.
seed_site();
$GLOBALS['sites'][1]['excerpts'][203] = '<img src="/wp-content/uploads/2026/10/d-pdf-thumbnail-150x150.jpg">';
rapls_pic_uninstall_site();
check('an image shown only in an excerpt is kept', $GLOBALS['sites'][1]['deleted'], []);

// This plugin's own shortcode naming the PDF, as a gallery naming it does.
seed_site();
$GLOBALS['sites'][1]['posts'][204] = '[rapls_pdf_thumbnail id="70" size="medium"]';
rapls_pic_uninstall_site();
check('a [rapls_pdf_thumbnail] naming its PDF keeps the image', $GLOBALS['sites'][1]['deleted'], []);

// A post the name pattern cannot be run over still keeps what it names.
seed_site();
$GLOBALS['sites'][1]['posts'][205] = '<img src="/wp-content/uploads/2026/10/d-pdf-thumbnail.jpg">' . str_repeat('a.', 20000);
$rapls_limit_was = ini_get('pcre.backtrack_limit');
ini_set('pcre.backtrack_limit', '1000');
rapls_pic_uninstall_site();
ini_set('pcre.backtrack_limit', (string) $rapls_limit_was);
check('past PCRE\'s backtrack limit, the image the post names is kept', $GLOBALS['sites'][1]['deleted'], []);

echo "\n--- Review after 1.4.38 ---\n";

// An excerpt on a post with no content at all: read too.
seed_site();
$GLOBALS['sites'][1]['excerpts'][400] = '<img src="/wp-content/uploads/2026/10/d-pdf-thumbnail-150x150.jpg">';
rapls_pic_uninstall_site();
check('an image shown in the excerpt of a post with no content is kept', $GLOBALS['sites'][1]['deleted'], []);

// A page builder keeps the image, or its PDF, by ID alone.
seed_site();
$GLOBALS['sites'][1]['posts'][206] = '[vc_single_image image="71" img_size="full"]';
rapls_pic_uninstall_site();
check('an image a page builder keeps by ID alone is kept', $GLOBALS['sites'][1]['deleted'], []);
seed_site();
$GLOBALS['sites'][1]['posts'][207] = '<p>71 apples</p> [vc_single_image image="710"]';
rapls_pic_uninstall_site();
check('  ...not for 71 in running text, nor for 710', $GLOBALS['sites'][1]['deleted'], [71]);
// Review of 1.4.39: a widget shows it -- a block widget by name, an Image
// widget by ID.
seed_site();
$GLOBALS['sites'][1]['options']['widget_block'] = [2 => ['content' => '<!-- wp:image --><figure><img src="https://example.test/wp-content/uploads/2026/10/d-pdf-thumbnail-150x150.jpg"/></figure><!-- /wp:image -->']];
rapls_pic_uninstall_site();
check('an image a block widget shows is kept', $GLOBALS['sites'][1]['deleted'], []);
seed_site();
$GLOBALS['sites'][1]['options']['widget_media_image'] = [3 => ['attachment_id' => 71, 'size' => 'medium']];
rapls_pic_uninstall_site();
check('  ...and one an Image widget names by ID', $GLOBALS['sites'][1]['deleted'], []);
// Review of 1.4.39: a marked image whose source was lost, a post's
// featured image.
seed_site();
$GLOBALS['sites'][1]['meta'] = array_values(array_filter($GLOBALS['sites'][1]['meta'], static function (array $row): bool { return !(71 === $row[0] && '_rapls_pic_source_pdf' === $row[1]); }));
$GLOBALS['sites'][1]['meta'][] = [95, '_thumbnail_id', '71'];
rapls_pic_uninstall_site();
check('a featured image with no source recorded is kept', $GLOBALS['sites'][1]['deleted'], []);
// Review of 1.4.38's fixes: a [gallery] list that a space divides.
seed_site();
$GLOBALS['sites'][1]['posts'][209] = '[gallery ids="5 71"]';
rapls_pic_uninstall_site();
check('an image second in a [gallery] that a space divides is kept', $GLOBALS['sites'][1]['deleted'], []);

// A post whose numbers cannot be read keeps every image.
seed_site();
$GLOBALS['sites'][1]['posts'][208] = str_repeat('=" " "', 2000) . '5';
$rapls_limit_was = ini_get('pcre.backtrack_limit');
$rapls_jit_was = ini_get('pcre.jit');
$GLOBALS['starve_pcre'] = true;
try {
    rapls_pic_uninstall_site();
} finally {
    $GLOBALS['starve_pcre'] = false;
    ini_set('pcre.backtrack_limit', (string) $rapls_limit_was);
    ini_set('pcre.jit', (string) $rapls_jit_was);
}
check('  ...a post whose numbers cannot be read: every image kept', $GLOBALS['sites'][1]['deleted'], []);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
