<?php
/**
 * The free plugin is not a trial.
 *
 * CLAUDE.md states the rule; this file is the part of it a machine can hold.
 * It scans what actually ships for two things: any sign that a capability has
 * been put behind the paid add-on, and any sign that a capability people
 * already have has gone missing.
 *
 * A failure here is not a style problem. It means the plugin has started
 * becoming a demo with a paywall.
 */

define('RAPLS_PIC_PLUGIN_DIR', dirname(__DIR__) . '/');

$checks = 0;
$failures = [];

function check(string $label, $actual, $expected): void
{
    global $checks, $failures;
    $checks++;
    if ($actual === $expected) {
        echo "ok   $label\n";
        return;
    }
    $failures[] = $label;
    echo "FAIL $label\n     got:  " . var_export($actual, true) . "\n     want: " . var_export($expected, true) . "\n";
}

/**
 * Everything that reaches an installed copy.
 *
 * git archive drops the export-ignore entries in .gitattributes, so this list
 * is the complement of that: no tests, no tools, no docs, no assets.
 *
 * @return array<int, string>
 */
function shipped_php_files(): array
{
    $roots = ['includes', 'admin'];
    $files = [
        RAPLS_PIC_PLUGIN_DIR . 'rapls-pdf-image-creator.php',
        RAPLS_PIC_PLUGIN_DIR . 'uninstall.php',
    ];

    foreach ($roots as $root) {
        $dir = RAPLS_PIC_PLUGIN_DIR . $root;
        if (!is_dir($dir)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($iterator as $file) {
            if ($file->isFile() && 'php' === strtolower($file->getExtension())) {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

$files = shipped_php_files();
check('there are shipped files to scan', count($files) > 5, true);

// ---------------------------------------------------------------------------
echo "\n=== nothing here knows the add-on exists ===\n";

// Any one of these in shipped code is the beginning of a gate. The add-on
// hooks this plugin; this plugin must never reach the other way.
$forbidden = [
    'rapls-pdf-image-creator-pro' => 'add-on slug',
    'PDFImageCreatorPro' => 'add-on namespace',
    'RAPLS_PDFP_' => 'add-on constant',
    '_rapls_pdfp_' => 'add-on post meta',
    'rapls_pdfp_' => 'add-on option',
];

foreach ($forbidden as $needle => $description) {
    $hits = [];
    foreach ($files as $file) {
        $contents = file_get_contents($file);
        if (false !== $contents && false !== strpos($contents, $needle)) {
            $hits[] = str_replace(RAPLS_PIC_PLUGIN_DIR, '', $file);
        }
    }
    check("no $description in shipped code", $hits, []);
}

// ---------------------------------------------------------------------------
echo "\n=== no capability is gated ===\n";

// Shapes that would put an existing feature behind a purchase. Deliberately
// narrow: the aim is to catch the gate, not to ban the word "pro" from a
// comment.
$gates = [
    '/\bis_pro\s*\(/i' => 'is_pro() call',
    '/\bhas_pro\s*\(/i' => 'has_pro() call',
    '/\bis_premium\s*\(/i' => 'is_premium() call',
    '/\bpro_(is_)?active\b/i' => 'pro_active flag',
    '/\blicense_(is_)?valid\b/i' => 'licence check',
    '/upgrade\s+to\s+pro/i' => 'upsell string "upgrade to pro"',
    '/available\s+in\s+(the\s+)?pro/i' => 'upsell string "available in pro"',
    '/\bpro\s+version\b/i' => 'upsell string "pro version"',
];

foreach ($gates as $pattern => $description) {
    $hits = [];
    foreach ($files as $file) {
        $contents = file_get_contents($file);
        if (false !== $contents && preg_match($pattern, $contents)) {
            $hits[] = str_replace(RAPLS_PIC_PLUGIN_DIR, '', $file);
        }
    }
    check("no $description", $hits, []);
}

// ---------------------------------------------------------------------------
echo "\n=== the settings people already have are still here ===\n";

require RAPLS_PIC_PLUGIN_DIR . 'includes/Settings.php';

$defaults = \Rapls\PDFImageCreator\Settings::DEFAULTS;

// The page setting is named in CLAUDE.md by name. The add-on sells per-PDF
// page selection; the moment this disappears so that it has something to sell,
// this stops being a free plugin with a paid add-on.
check('the global page setting exists', array_key_exists('page', $defaults), true);
check('it still defaults to the first page', $defaults['page'], 0);

foreach (['max_width', 'max_height', 'resolution', 'quality', 'format', 'bgcolor',
          'auto_generate', 'set_featured', 'insert_size', 'insert_type', 'insert_link',
          'custom_html', 'display_thumbnail_icon', 'hide_generated_images'] as $key) {
    check("setting '$key' exists", array_key_exists($key, $defaults), true);
}

// A gate can also be a quietly worse default.
check('quality has not been lowered', $defaults['quality'] >= 90, true);
check('max width has not been lowered', $defaults['max_width'] >= 1024, true);
check('resolution has not been lowered', $defaults['resolution'] >= 150, true);
check('formats are not restricted at the default', $defaults['format'], 'jpeg');

// ---------------------------------------------------------------------------
echo "\n=== the settings screen still offers them ===\n";

$page = file_get_contents(RAPLS_PIC_PLUGIN_DIR . 'admin/views/settings-page.php');

check('the page number field is on the screen', false !== strpos($page, 'rapls_pic_settings[page]'), true);
check('the resolution field is on the screen', false !== strpos($page, 'rapls_pic_settings[resolution]'), true);
check('the quality field is on the screen', false !== strpos($page, 'rapls_pic_settings[quality]'), true);
check('bulk generate is still a tab', false !== strpos($page, 'tab-bulk'), true);

// ---------------------------------------------------------------------------
echo "\n=== the public surface is intact ===\n";

$generator = file_get_contents(RAPLS_PIC_PLUGIN_DIR . 'includes/Generator.php');

// Every conversion option is filterable per attachment. This is what the
// add-on builds on, and it is also what any other developer builds on. It may
// not become conditional.
foreach (['page', 'max_width', 'max_height', 'resolution', 'quality', 'format', 'bgcolor'] as $option) {
    check(
        "the $option filter is still applied",
        false !== strpos($generator, "rapls_pdf_image_creator_thumbnail_$option"),
        true
    );
}

$main = file_get_contents(RAPLS_PIC_PLUGIN_DIR . 'rapls-pdf-image-creator.php');

foreach (['rapls_pic_get_thumbnail_url', 'rapls_pic_get_thumbnail_id',
          'rapls_pic_get_thumbnail_image', 'rapls_pic_has_thumbnail',
          'rapls_pic_generate_thumbnail'] as $fn) {
    check("template function $fn() still exists", false !== strpos($main, "function $fn("), true);
}

$plugin = file_get_contents(RAPLS_PIC_PLUGIN_DIR . 'includes/Plugin.php');

foreach (['rapls_pdf_thumbnail', 'rapls_pdf_thumbnail_url',
          'rapls_pdf_clickable_thumbnail', 'rapls_pdf_download_link'] as $shortcode) {
    check("shortcode [$shortcode] still registered", false !== strpos($plugin, $shortcode), true);
}

// ---------------------------------------------------------------------------
echo "\n=== the extension point is safe to leave unhooked ===\n";

$engine = file_get_contents(RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ImagickEngine.php');

check(
    'the before_resize filter exists',
    false !== strpos($engine, "apply_filters('rapls_pdf_image_creator_before_resize'"),
    true
);

// Its return value is checked before use, so a listener that returns nothing
// leaves the page alone rather than destroying it. Without this, the plugin's
// own output would depend on somebody being hooked.
check(
    'its return value is type-checked',
    false !== strpos($engine, '$filtered instanceof \Imagick'),
    true
);

// ---------------------------------------------------------------------------
echo "\n=== no secrets in the tools ===\n";

// A token was once committed to a public repository by being swept up in a
// `git add -A` alongside everything else. The files that carry one are meant
// to be edited on the server they are uploaded to, never here, so the copy in
// the repository must always hold the placeholder.
foreach (glob(RAPLS_PIC_PLUGIN_DIR . 'tools/*.php') as $tool) {
    $contents = (string) file_get_contents($tool);

    if (!preg_match("/const TOKEN\s*=\s*'([^']*)'/", $contents, $m)) {
        continue;
    }

    check(
        basename($tool) . ' has no real token in it',
        $m[1],
        'CHANGE-ME'
    );
}

echo "\n";
if ($failures) {
    echo count($failures) . " of $checks failed\n";
    exit(1);
}
echo "$checks passed, 0 failed\n";
