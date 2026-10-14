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
check('the stem fragments, as they are and percent-encoded', ContentUse::fragments(['d', '資料']), ['d', '資料', rawurlencode('資料')]);
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

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
