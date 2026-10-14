<?php
/**
 * Site Health's filters take whatever an earlier listener answered (review
 * after 1.4.33).
 *
 *   php tests/test-site-health.php
 *
 * site_status_tests and debug_information are core's filters, and another
 * plugin ahead of this one can answer something that is not an array. Typed
 * as arrays, these callbacks were a TypeError, and the Site Health screen
 * went with it.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('RAPLS_PIC_PLUGIN_DIR', dirname(__DIR__) . '/');
define('RAPLS_PIC_VERSION', '0.0.0-test');

function __($s, $d = null) { return $s; }
function esc_html__($s, $d = null) { return $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($u) { return (string) $u; }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }

require RAPLS_PIC_PLUGIN_DIR . 'includes/FailureCode.php';
require RAPLS_PIC_PLUGIN_DIR . 'includes/Settings.php';
require RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ConversionResult.php';
require RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/EngineInterface.php';
require RAPLS_PIC_PLUGIN_DIR . 'includes/Generator.php';
require RAPLS_PIC_PLUGIN_DIR . 'includes/Admin.php';
require RAPLS_PIC_PLUGIN_DIR . 'includes/SiteHealth.php';

$pass = 0;
$fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    $ok = $got === $want;
    $ok ? $pass++ : $fail++;
    printf("%s %-62s got=%s want=%s\n", $ok ? 'ok  ' : 'FAIL', $label, json_encode($got), json_encode($want));
}

$settings = (new ReflectionClass(\Rapls\PDFImageCreator\Settings::class))->newInstanceWithoutConstructor();
$settingsProp = new ReflectionProperty(\Rapls\PDFImageCreator\Settings::class, 'settings');
if (PHP_VERSION_ID < 80100) { $settingsProp->setAccessible(true); }
$settingsProp->setValue($settings, $settings->getDefaults());
$generator = (new ReflectionClass(\Rapls\PDFImageCreator\Generator::class))->newInstanceWithoutConstructor();
$enginesProp = new ReflectionProperty(\Rapls\PDFImageCreator\Generator::class, 'engines');
if (PHP_VERSION_ID < 80100) { $enginesProp->setAccessible(true); }
$enginesProp->setValue($generator, []);
$health = new \Rapls\PDFImageCreator\SiteHealth($settings, $generator);

echo "--- site_status_tests ---\n";
foreach (['null' => null, 'a string' => 'tests', 'false' => false] as $label => $answer) {
    try {
        $got = $health->registerTest($answer);
    } catch (\Throwable $e) {
        $got = get_class($e);
    }
    check("an earlier listener's $label is handed back as it was", $got, $answer);
}
$tests = $health->registerTest(['async' => []]);
check('an array without direct tests gains this one', [array_keys($tests), array_keys($tests['direct'] ?? [])], [['async', 'direct'], ['rapls_pic_pdf_rendering']]);
$tests = $health->registerTest(['direct' => 'broken']);
check('  ...and one whose direct tests are not a list gets a list', array_keys($tests['direct'] ?? []), ['rapls_pic_pdf_rendering']);
$tests = $health->registerTest(['direct' => ['other' => ['label' => 'x']]]);
check('  ...another plugin\'s test is kept beside it', array_keys($tests['direct']), ['other', 'rapls_pic_pdf_rendering']);

echo "\n--- debug_information ---\n";
foreach (['null' => null, 'a string' => 'info'] as $label => $answer) {
    try {
        $got = $health->registerDebugInformation($answer);
    } catch (\Throwable $e) {
        $got = get_class($e);
    }
    check("an earlier listener's $label is handed back as it was", $got, $answer);
}
$info = $health->registerDebugInformation(['wp-core' => ['label' => 'WordPress']]);
check('an array gains this plugin\'s section, with no engine reported as such', [array_keys($info), $info['rapls-pdf-image-creator']['fields']['availability']['debug'] ?? null], [['wp-core', 'rapls-pdf-image-creator'], 'no_engine']);


echo "\n--- the failure, escaped (review after 1.4.34) ---\n";
// The summary, action and detail come from ImageMagick's own words and the
// server's paths: none of it is markup.
$enginesProp->setValue($generator, [new class implements \Rapls\PDFImageCreator\Engine\EngineInterface {
    public function getName(): string { return 'stub'; }
    public function getDisplayName(): string { return 'Stub'; }
    public function isAvailable(): bool { return false; }
    public function getAvailabilityStatus(): array {
        return ['code' => 'pdf_unsupported', 'label' => 'x', 'summary' => 'Summary <b>bold</b>', 'action' => 'Action <i>x</i>', 'detail' => 'Detail <script>y</script>'];
    }
    public function getRequirements(): array { return []; }
    public function convert(string $pdfPath, string $outputPath, array $options = []): \Rapls\PDFImageCreator\Engine\ConversionResult { throw new \RuntimeException('not here'); }
}]);
$result = $health->runTest();
check('the summary, the action and the detail are escaped', [
    false !== strpos($result['description'], 'Summary &lt;b&gt;bold&lt;/b&gt;'),
    false !== strpos($result['description'], 'Action &lt;i&gt;x&lt;/i&gt;'),
    false !== strpos($result['description'], 'Detail &lt;script&gt;y&lt;/script&gt;'),
    false === strpos($result['description'], '<b>') && false === strpos($result['description'], '<script>'),
    $result['status'],
], [true, true, true, true, 'critical']);


echo "\n--- a server that renders (review after 1.4.38) ---\n";
// The good result and the requirements in debug information had no test:
// a working engine gives "good" and no actions, and its requirements are
// listed, pdf_support aside.
$enginesProp->setValue($generator, ['stub' => new class implements \Rapls\PDFImageCreator\Engine\EngineInterface {
    public function getName(): string { return 'stub'; }
    public function getDisplayName(): string { return 'Stub'; }
    public function isAvailable(): bool { return true; }
    public function getAvailabilityStatus(): array {
        return ['code' => 'ok', 'label' => 'Available', 'summary' => '', 'action' => '', 'detail' => ''];
    }
    public function getRequirements(): array {
        return [
            'imagick' => ['name' => 'Imagick', 'met' => true, 'message' => 'Loaded'],
            'pdf_support' => ['name' => 'PDF', 'met' => true, 'message' => 'Yes'],
        ];
    }
    public function convert(string $pdfPath, string $outputPath, array $options = []): \Rapls\PDFImageCreator\Engine\ConversionResult { throw new \RuntimeException('not here'); }
}]);
$result = $health->runTest();
$fields = $health->registerDebugInformation([])['rapls-pdf-image-creator']['fields'] ?? [];
check('a server that renders: good, no actions; its requirements listed, PDF support aside', [
    $result['status'],
    $result['actions'],
    $fields['availability']['debug'] ?? null,
    $fields['stub_imagick']['value'] ?? null,
    isset($fields['stub_pdf_support']),
    isset($fields['availability_detail']),
], ['good', '', 'ok', 'Loaded', false, false]);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit(0 === $fail ? 0 : 1);
