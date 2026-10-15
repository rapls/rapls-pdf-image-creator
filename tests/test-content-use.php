<?php
/**
 * ContentUse: whether a post's content shows an image (review of 1.4.36).
 *
 * The one test regenerating, deleting a PDF and uninstalling make. Names are
 * compared whole, and an image is also found by its ID: a "wp-image-" class,
 * an image block's "id", a gallery block's "ids", a [gallery] shortcode.
 *
 *   php tests/test-content-use.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/includes/ContentUse.php';
// The widgets' options, for widgetContent() (review of 1.4.40).
function get_option($key, $default = false) { return $GLOBALS['wp_options'][$key] ?? $default; }

use Rapls\PDFImageCreator\ContentUse;

$pass = 0;
$fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    $ok = $got === $want;
    $ok ? $pass++ : $fail++;
    printf("%s %-62s got=%s want=%s\n", $ok ? 'ok  ' : 'FAIL', $label, json_encode($got, JSON_UNESCAPED_UNICODE), json_encode($want, JSON_UNESCAPED_UNICODE));
}

echo "--- Stems ---\n";
check('size, "-scaled", "-rotated" and extension go, case is lowered', [
    ContentUse::stem('2026/10/B-PDF-Thumbnail-300x200.JPG'),
    ContentUse::stem('x/b-pdf-thumbnail-scaled.webp'),
    ContentUse::stem('b-pdf-thumbnail-rotated-150x150.png'),
    ContentUse::stem('b-pdf-thumbnail-1.jpg'),
], ['b-pdf-thumbnail', 'b-pdf-thumbnail', 'b-pdf-thumbnail', 'b-pdf-thumbnail-1']);
check('  ...a Windows path\'s folders go too', ContentUse::stem('2026\\10\\c-pdf-thumbnail.jpg'), 'c-pdf-thumbnail');
check('  ...a name that is not ASCII keeps its bytes', ContentUse::stem('資料-PDF-thumbnail.jpg'), '資料-pdf-thumbnail');
check('an edited image has the stems of its file and of its backups', ContentUse::stemsOf('2026/10/d-e1696000000000.jpg', ['full-orig' => ['file' => 'd.jpg'], 'thumbnail-orig' => ['file' => 'd-150x150.jpg'], 'odd' => 'x', 'odd2' => ['file' => []]]), ['d-e1696000000000', 'd']);
check('  ...backups that are not a list are left out', ContentUse::stemsOf('e.jpg', 'a:0:{}'), ['e']);
check('  ...no file: none', ContentUse::stemsOf('', null), []);

echo "\n--- Names in the content ---\n";
check('addresses, in any size, percent-encoded or not', array_keys(ContentUse::stemsIn('<img src="/u/a-300x200.jpg"><img src="/u/%E8%B3%87%E6%96%99.webp"> b.GIF c.avif')), ['a', '資料', 'b', 'c']);
check('  ...not a PDF or other file', ContentUse::stemsIn('<a href="/u/a.pdf">x</a> notes.txt'), []);
check('a name that only ends the same is another image', ContentUse::shows('<img src="/u/annual-report.jpg">', 9, ['report']), false);
check('  ...one that only starts the same too', ContentUse::shows('<img src="/u/report-2.jpg">', 9, ['report']), false);
check('  ...the same name, in another size: shown', ContentUse::shows('<img src="/u/Report-1024x724.jpg">', 9, ['report']), true);

echo "\n--- IDs in the content ---\n";
$ids = static function (string $content): array { $found = array_keys(ContentUse::idsIn($content)); sort($found); return $found; };
check('a wp-image- class, whole numbers only', $ids('<img class="wp-image-12 x"><img class="wp-image-120"> wp-image-7x'), [12, 120]);
check('an image block\'s "id"', $ids('<!-- wp:image {"id":46,"sizeSlug":"large"} --><!-- wp:image {"sizeSlug":"x","id":47} -->'), [46, 47]);
check('  ...not another block\'s', $ids('<!-- wp:file {"id":48} -->'), []);
check('a gallery block\'s "ids"', $ids('<!-- wp:gallery {"ids":[3, 4,5],"columns":2} -->'), [3, 4, 5]);
check('[gallery ids], quoted either way or not', $ids('[gallery ids="6,7"] [gallery columns="2" ids=\'8\'] [gallery ids=9,10]'), [6, 7, 8, 9, 10]);
check('[gallery include]', $ids('[gallery include="11, 12" size="large"]'), [11, 12]);
check('  ...not another shortcode\'s, nor exclude', $ids('[playlist ids="13"] [gallery exclude="14"]'), []);
check('  ...nor an ID of 0, nor words', $ids('[gallery ids="0,abc,15"]'), [15]);
check('shown by its ID', [ContentUse::shows('[gallery ids="5,71"]', 71, []), ContentUse::shows('[gallery ids="5,710"]', 71, [])], [true, false]);

echo "\n--- Fragments that narrow the posts read ---\n";
check('the stem fragments, as they are and percent-encoded, lower case', ContentUse::fragments(['d', '資料']), ['d', '資料', strtolower(rawurlencode('資料'))]);
check('  ...a stem not all ASCII gives its longest ASCII run too (review after 1.4.37)', ContentUse::fragments(['報告書-pdf-thumbnail']), ['報告書-pdf-thumbnail', strtolower(rawurlencode('報告書-pdf-thumbnail')), '-pdf-thumbnail']);
foreach ([
    'a class' => '<img class="wp-image-71">',
    'an image block' => '<!-- wp:image {"id":71} -->',
    'an image block, spaced' => '<!-- wp:image {"id": 71} -->',
    'a gallery block' => '<!-- wp:gallery {"ids":[1,71]} -->',
    'a [gallery]' => '[gallery ids="71"]',
    'an address' => '<img src="/u/d-300x200.jpg">',
    'an encoded address' => '<img src="/u/' . rawurlencode('資料') . '.jpg">',
] as $form => $content) {
    check("  ...$form is a candidate and is shown", [ContentUse::candidate($content, 71, ['d', '資料']), ContentUse::shows($content, 71, ['d', '資料'])], [true, true]);
}
// Review after 1.4.36: "[gallery" alone made every post with a gallery a candidate, read in full for every image.
check('  ...a gallery that cannot name this ID is not a candidate', [
    ContentUse::candidate('[gallery ids="5,6"]', 71, ['q-pdf-thumbnail']),
    ContentUse::candidate('<!-- wp:gallery {"ids":[5,6]} -->', 71, ['q-pdf-thumbnail']),
    ContentUse::candidate('<p>71 apples</p>', 71, ['q-pdf-thumbnail']),
], [false, false, false]);

echo "\n--- Forms missed before (review after 1.4.36) ---\n";
check('an image block with an object before "id"', $ids('<!-- wp:image {"lightbox":{"enabled":true},"id":71,"sizeSlug":"large"} -->'), [71]);
check('  ...attributes that are not JSON: any "id" in them counts', $ids('<!-- wp:image {"lightbox":{"enabled":true,},"id":72} -->'), [72]);
$limitWas = ini_get('pcre.backtrack_limit');
ini_set('pcre.backtrack_limit', '1000');
check('an image block found in a long post, past PCRE\'s backtrack limit', $ids(str_repeat('<p>text</p>', 2000) . '<!-- wp:image {"alt":"' . str_repeat('a', 5000) . '","id":78} -->'), [78]);
ini_set('pcre.backtrack_limit', (string) $limitWas);
check('an image block however the comment is spaced, and by its full name', $ids("<!--  wp:image {\"id\":81} -->\n<!--\nwp:image {\"id\":82} -->\n<!-- wp:core/image {\"id\":83} -->\n<!-- wp:imagery {\"id\":84} -->\n<p>wp:image {\"id\":85} --></p>"), [81, 82, 83]);
check('a gallery block whose IDs are strings', $ids('<!-- wp:gallery {"ids":["73", "74"]} -->'), [73, 74]);
check('[gallery] as the editor may store it, with entities', $ids('[gallery ids=&quot;75,76&quot;] [gallery include=&#039;77&#039;]'), [75, 76, 77]);
check('a density suffix and a second extension', [ContentUse::stem('d-300x200@2x.jpg'), ContentUse::stem('d.jpg.webp'), ContentUse::stem('d-1024x768.png.webp')], ['d', 'd', 'd']);
check('  ...shown by those names', [ContentUse::shows('<img src="/u/d-300x200@2x.jpg">', 9, ['d']), ContentUse::shows('<img src="/u/d.jpg.webp">', 9, ['d'])], [true, true]);


echo "\n--- Review after 1.4.37 ---\n";
check('trashed posts are read; revisions and auto-drafts are not', [ContentUse::SKIP_TYPES, ContentUse::SKIP_STATUSES], [['attachment', 'revision'], ['auto-draft']]);
check('a candidate whatever the case: "Report-…" for the stem "report-…"', ContentUse::candidate('<img src="/u/Report-PDF-Thumbnail-300x212.jpg">', 9, ['report-pdf-thumbnail']), true);
check('the digits must follow a form: "wp-image-71", "\"id\"…71", not 71 anywhere', [
    ContentUse::candidate('<!-- wp:paragraph --><p>71</p><img class="wp-image-5">', 71, []),
    ContentUse::candidate('<img class="wp-image-71">', 71, []),
    ContentUse::candidate('71 <!-- wp:image {"id":5} -->', 71, []),
    ContentUse::candidate('<!-- wp:image {"lightbox":{"enabled":true},"id":71} -->', 71, []),
], [false, true, false, true]);
$likes71 = ContentUse::idLikesSql(71, static function (string $s): string { return addcslashes($s, '_%\\'); });
check('the ID is asked by LIKE only: beside each character that can stand before it, and after a \\u00 escape', [count($likes71), $likes71[0], in_array('%\\\\71%', $likes71, true), end($likes71)], [18, '%=71%', true, '%u00%71%']);
check('a density suffix with a fraction, "@1.5x"', [ContentUse::stem('d-300x200@1.5x.jpg'), array_keys(ContentUse::stemsIn('<img src="/u/d-300x200@1.5x.jpg">'))], ['d', ['d']]);
check('a name partly percent-encoded, or with a character as an entity', [
    array_keys(ContentUse::stemsIn('<img src="/u/報告' . rawurlencode('書') . '-pdf-thumbnail.jpg">')),
    array_keys(ContentUse::stemsIn('<img src="/u/&#22577;告書-pdf-thumbnail.jpg">')),
    ContentUse::candidate('<img src="/u/報告' . rawurlencode('書') . '-pdf-thumbnail.jpg">', 9, ['報告書-pdf-thumbnail']),
], [['報告書-pdf-thumbnail'], ['報告書-pdf-thumbnail'], true]);
if (class_exists('Normalizer')) {
    check('a name written decomposed (macOS) is the composed one', ContentUse::stem(\Normalizer::normalize('が.jpg', \Normalizer::FORM_D)), 'が');
}
$limitWas = ini_get('pcre.backtrack_limit');
ini_set('pcre.backtrack_limit', '1000');
$long = '<img src="/u/e-pdf-thumbnail.jpg">' . str_repeat('a.', 20000);
check('names past PCRE\'s backtrack limit: not "none", the plainer test keeps it', [ContentUse::stemsIn($long), ContentUse::stemsShown($long, ['e-pdf-thumbnail', 'z-pdf-thumbnail']), ContentUse::shows($long, 9, ['e-pdf-thumbnail'])], [null, ['e-pdf-thumbnail'], true]);
ini_set('pcre.backtrack_limit', (string) $limitWas);
check('this plugin\'s own shortcodes naming a PDF', array_keys(ContentUse::shortcodePdfIdsIn('[rapls_pdf_thumbnail id="31"] [rapls_pdf_download_link id=32 text="x"] [rapls_pdf_thumbnail_url id=&quot;33&quot;] [rapls_pdf_clickable_thumbnail id=\'34\'] [other id="35"]')), [31, 32, 33, 34]);

echo "\n--- Review after 1.4.38 ---\n";
// Page builders keep an image by its ID alone, each in its own spelling.
foreach ([
    'WPBakery' => '[vc_single_image image="555" img_size="full"]',
    'Divi' => '[et_pb_gallery gallery_ids="12,555" _builder_version="4"]',
    'Avada' => '[fusion_imageframe image_id="555|full" linktarget="_self"]',
    'a JSON value' => '<!-- wp:acme/card {"mediaId":555} /-->',
    'a JSON string' => '<!-- wp:acme/card {"media":{"id":"555"}} /-->',
    'an entity-quoted value' => '[acme_image id=&quot;555&quot;]',
] as $builder => $content) {
    check("a page builder's ID: $builder is a candidate and is shown", [ContentUse::candidate($content, 555, []), ContentUse::shows($content, 555, [])], [true, true]);
}
check('  ...not a number in running text, nor a longer or a shorter one', [
    ContentUse::shows('<p>555 apples, page 555.</p>', 555, []),
    ContentUse::shows('[vc_single_image image="5550"]', 555, []),
    ContentUse::shows('[vc_single_image image="1555"]', 555, []),
    ContentUse::candidate('<p>555 apples</p>', 555, []),
], [false, false, false, false]);
// The SQL's LIKEs, read as MySQL reads LIKE: "%" any run, "_" one
// character, "\\" the next taken as it is.
$likeRegex = static function (string $like): string {
    $regex = '';
    for ($i = 0, $n = strlen($like); $i < $n; $i++) {
        $c = $like[$i];
        if ('\\' === $c && $i + 1 < $n) {
            $regex .= preg_quote($like[++$i], '/');
        } elseif ('%' === $c) {
            $regex .= '.*';
        } elseif ('_' === $c) {
            $regex .= '.';
        } else {
            $regex .= preg_quote($c, '/');
        }
    }
    return '/\A' . $regex . '\z/s';
};
$sqlFinds = static function (string $content, int $id = 555) use ($likeRegex): bool {
    foreach (ContentUse::idLikesSql($id, static function (string $s): string { return addcslashes($s, '_%\\'); }) as $like) {
        if (1 === preg_match($likeRegex($like), $content)) {
            return true;
        }
    }
    return false;
};
$asked = [];
foreach (['[vc_single_image image="555"]', 'gallery_ids="12,555"', 'image_id="555|full"', '{"id": 555}', '["1","555"]', 'class="wp-image-555"', 'ids=&quot;555&quot;', '<p>555</p>', 'image="5550"', 'image="1555"', '5551'] as $sample) {
    $asked[] = [$sqlFinds($sample), ContentUse::candidate($sample, 555, [])];
}
// The SQL only narrows: it may read a post the PHP then turns down, never
// leave out one the PHP would keep.
check('  ...the SQL\'s LIKEs find every sample candidate() finds, and candidate() every one they find', array_map(static function (array $pair): bool { return $pair[1] === $pair[0]; }, $asked), array_fill(0, 11, true));
check('  ...turning down running text and a number with a digit before it; a longer one is read, and the PHP turns it down', [$asked[7][0], $asked[8][0], $asked[10][0], ContentUse::shows('image="5550"', 555, [])], [false, true, false, false]);

echo "\n--- Review of 1.4.38's fixes ---\n";
// A list that spaces divide, as wp_parse_id_list() reads it; an attribute
// quoted inside a string; a quote as a numeric entity; leading zeros.
foreach ([
    'a [gallery] divided by a space' => '[gallery ids="1 555"]',
    '  ...by a tab' => "[gallery include=\"1\t555\"]",
    '  ...entity-quoted' => '[gallery ids=&quot;1 555&quot;]',
    'an attribute quoted inside a string' => '{"html":"[vc_single_image image=\\"555\\"]"}',
    'a quote as a numeric entity' => '[acme_image id=&#34;555&#34;]',
    'leading zeros' => '<!-- wp:acme/card {"id":"0555"} /-->',
    'a quoted number after spaces' => '{"id":          "555"}',
    'deeply indented JSON' => "{\n  \"image\":\n" . str_repeat(' ', 40) . "555\n}",
    'a quoted number in a list' => '{"ids":["1","555"]}',
    'a list a newline divides' => "[acme_list ids=\"1\n555\"]",
    'a space-divided list with leading zeros' => '[acme image_ids="1 0555"]',
    'a quote JSON wrote as \\u0022' => '{"html":"[acme image=\\u0022555\\u0022]"}',
] as $what => $content) {
    check("$what: a candidate, shown, and found by the SQL", [ContentUse::candidate($content, 555, []), ContentUse::shows($content, 555, []), $sqlFinds($content)], [true, true, true]);
}
// The run of spaces and quotes is bounded at 64 in the PHP; the SQL reads
// the post all the same (the quote right before the ID), and the PHP turns
// it down -- reading one too many, never leaving one out (review of 1.4.39).
$farOff = '{"id":' . str_repeat('"', 70) . '555}';
check('  ...past 64 quotes: not counted by the PHP, read by the SQL', [ContentUse::shows($farOff, 555, []), $sqlFinds($farOff)], [false, true]);
check('  ...a number after a number with nothing between is not taken', [ContentUse::shows('[gallery ids="1555"]', 555, []), ContentUse::candidate('[gallery ids="1555"]', 555, [])], [false, false]);
check('a name in JSON written with \\u escapes', [
    array_keys(ContentUse::stemsIn('<!-- wp:acme/card {"img":"https:\\/\\/x\\/u\\/\\u5831\\u544a\\u66f8-pdf-thumbnail.jpg"} /-->')),
    ContentUse::shows('{"img":"\\/u\\/\\u5831\\u544a\\u66f8-pdf-thumbnail-300x200.jpg"}', 9, ['報告書-pdf-thumbnail']),
], [['報告書-pdf-thumbnail'], true]);
$limitWas = ini_get('pcre.backtrack_limit');
ini_set('pcre.backtrack_limit', '1000');
$long = '<img src="/u/E-PDF-Thumbnail.jpg">' . str_repeat('a.', 20000);
check('  ...past the backtrack limit, the plainer test ignores case too', ContentUse::stemsShown($long, ['e-pdf-thumbnail']), ['e-pdf-thumbnail']);
ini_set('pcre.backtrack_limit', (string) $limitWas);
$limitWas = ini_get('pcre.backtrack_limit');
$jitWas = ini_get('pcre.jit');
ini_set('pcre.backtrack_limit', '1');
ini_set('pcre.jit', '0');
$unread = str_repeat('=" " "', 2000) . '5';
check('numbers the pattern cannot be run over: not "none", kept', [ContentUse::idsInContext($unread), ContentUse::shows($unread, 9, [])], [null, true]);
ini_set('pcre.backtrack_limit', (string) $limitWas);
ini_set('pcre.jit', (string) $jitWas);
echo "\n--- Review of 1.4.39 ---\n";
// A block writes "--" in its JSON attributes as \u002d\u002d: the PHP read
// it, the SQL never handed it the post (from "report-.pdf").
$dashed = '<!-- wp:acme/pdf-card {"thumb":"https:\\/\\/x\\/u\\/report\\u002d\\u002dpdf-thumbnail.jpg"} /-->';
check('a name holding "--" written \\u002d\\u002d: shown, and a candidate by its longest piece', [ContentUse::shows($dashed, 9, ['report--pdf-thumbnail']), ContentUse::candidate($dashed, 9, ['report--pdf-thumbnail']), ContentUse::fragments(['report--pdf-thumbnail'])], [true, true, ['report--pdf-thumbnail', 'pdf-thumbnail']]);
check('  ...a stem not all ASCII, its ASCII run holding "--"', ContentUse::fragments(['資料--pdf-thumbnail']), ['資料--pdf-thumbnail', strtolower(rawurlencode('資料--pdf-thumbnail')), '--pdf-thumbnail', 'pdf-thumbnail', '%e8%b3%87%e6%96%99']);
check('an ID first in a list that is not a gallery\'s: {"image_ids":[555]}', [ContentUse::shows('<!-- wp:acme/slider {"image_ids":[555,12]} /-->', 555, []), $sqlFinds('{"image_ids":[555,12]}')], [true, true]);
// A character outside the Basic Multilingual Plane is a pair of escapes,
// decoded together or not at all.
check('a name with a character JSON writes as a surrogate pair', ContentUse::shows('{"img":"\\/u\\/\\ud83d\\ude00-pdf-thumbnail.jpg"}', 9, [ContentUse::stem("\u{1F600}-pdf-thumbnail.jpg")]), true);
$limitWas = ini_get('pcre.backtrack_limit');
$jitWas = ini_get('pcre.jit');
ini_set('pcre.backtrack_limit', '1000');
ini_set('pcre.jit', '0');
$longEscaped = '<img src="/u/\\u0072eport-pdf-thumbnail.jpg">' . str_repeat('a.', 20000);
check('  ...past the backtrack limit, the plainer test reads \\u escapes too', [ContentUse::stemsIn($longEscaped), ContentUse::stemsShown($longEscaped, ['report-pdf-thumbnail'])], [null, ['report-pdf-thumbnail']]);
ini_set('pcre.backtrack_limit', (string) $limitWas);
ini_set('pcre.jit', (string) $jitWas);
// Many "wp:" and no "-->" near them -- text that mentions "wp:", one comment
// at the end: each was followed by a search for "-->" over the rest of the
// post.
$many = str_repeat('see wp: here ', 200000) . '<!-- wp:image {"id":77} -->';
$t = microtime(true);
$found77 = isset(ContentUse::idsIn($many)[77]);
check('  ...a post with many "wp:" and one comment is read in one pass', [$found77, microtime(true) - $t < 1.0], [true, true]);

check('a stem\'s ASCII run is the longest, of four characters or more', [ContentUse::fragments(['報告-ab-書-pdf-thumbnail']), ContentUse::fragments(['報告abc書'])], [['報告-ab-書-pdf-thumbnail', strtolower(rawurlencode('報告-ab-書-pdf-thumbnail')), '-pdf-thumbnail'], ['報告abc書', strtolower(rawurlencode('報告abc書'))]]);

echo "\n--- Review of 1.4.40 ---\n";
// Mutation testing of 1.4.40: each edge, held.
check('an ID after "|", as a builder lists "12|555": shown, and the SQL asks it', [ContentUse::shows('[acme ids="12|555"]', 555, []), ContentUse::candidate('[acme ids="12|555"]', 555, []), $sqlFinds('[acme ids="12|555"]')], [true, true, true]);
check('a shortcode naming PDF 1', ContentUse::shortcodePdfIdsIn('[rapls_pdf_thumbnail id="1"]'), [1 => true]);
check('a stem\'s ASCII run of exactly four is a fragment; of three, not', [ContentUse::fragments(['資料abcd']), ContentUse::fragments(['資料abc'])], [['資料abcd', strtolower(rawurlencode('資料abcd')), 'abcd'], ['資料abc', strtolower(rawurlencode('資料abc'))]]);
check('a piece between "--" of exactly four is a fragment; of three, not', [ContentUse::fragments(['abcd--abc']), ContentUse::fragments(['abc--ab'])], [['abcd--abc', 'abcd'], ['abc--ab']]);
check('the ID anywhere after "u00": a candidate, as LIKE \'%u00%ID%\' finds it', [ContentUse::candidate('u00xx555', 555, []), ContentUse::candidate('xx555', 555, [])], [true, false]);
// The widgets: each value after its key, a string as it is.
$GLOBALS['wp_options'] = ['widget_block' => [2 => ['content' => 'a\u002d\u002db.jpg']], 'widget_media_gallery' => [5 => ['ids' => [7, 612]]]];
check('the widgets as one string: a string as it is, a number after its key', ContentUse::widgetContent(), "\"content\": a\\u002d\\u002db.jpg\n\"0\": 7\n\"1\": 612");
// A value that starts with a file name: the name a word of its own. Joined
// to the key's colon it was read as ":report-pdf-thumbnail", and missed
// (review of 1.4.40's fixes).
$GLOBALS['wp_options'] = ['widget_custom_html' => [3 => ['title' => '', 'content' => 'report-pdf-thumbnail.jpg']]];
check('a widget value that starts with a file name: found', ContentUse::stemsShown((string) ContentUse::widgetContent(), ['report-pdf-thumbnail']), ['report-pdf-thumbnail']);
// Widgets as WordPress stores them: booleans, floats and many values. None
// of them makes the widgets unreadable -- that would keep every image on
// every regeneration and uninstall -- and an image they do not show is not
// found, one they show by ID is, a float ID too.
$texts = ['_multiwidget' => 1];
for ($i = 2; $i < 40; $i++) {
    $texts[$i] = ['title' => 'Box ' . $i, 'text' => '<p>Opening hours ' . $i . '</p>', 'filter' => true, 'visual' => false];
}
$GLOBALS['wp_options'] = [
    'widget_text' => $texts,
    'widget_media_image' => [2 => ['attachment_id' => 555.0, 'url' => 'https://example.test/x.jpg', 'size' => 'full', 'width' => 1024, 'height' => 768.5, 'link_target_blank' => false, 'image_title' => '', 'caption' => null], '_multiwidget' => 1],
];
$real = ContentUse::widgetContent();
check('widgets with booleans, floats and many values: read; an ID not shown is not found, a float ID shown is', [is_string($real), ContentUse::shows((string) $real, 999, ['other-pdf-thumbnail']), ContentUse::shows((string) $real, 555, ['not-shown-pdf-thumbnail'])], [true, false, true]);
$GLOBALS['wp_options'] = [];

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
