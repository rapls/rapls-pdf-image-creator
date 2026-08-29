<?php
/**
 * Why the engine is unavailable, not just whether.
 *
 * A missing extension and a policy.xml that forbids the PDF coder both make
 * isAvailable() return false, but they need different requests to a hosting
 * provider. These assert the two stay apart.
 */

require __DIR__ . '/harness.php';

require RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/EngineInterface.php';
require RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ConversionResult.php';
require RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ImagickEngine.php';

use Rapls\PDFImageCreator\Engine\ImagickEngine;

$pass = $fail = 0;

/** Call a private method. These are internal by design; the behaviour is not. */
function call_private(object $object, string $method, array $args = [])
{
    $ref = new ReflectionMethod($object, $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs($object, $args);
}

$engine = new ImagickEngine();

echo "=== policy pattern matching ===\n";
foreach (['PDF', 'pdf', 'PDF*', '*', '{PS,PS2,PS3,EPS,PDF,XPS}', '{ PDF , XPS }'] as $pattern) {
    check('covers ' . $pattern, call_private($engine, 'patternCoversPdf', [$pattern]), true);
}
foreach (['PNG', '{PS,EPS}', 'PDFX', '', 'EPHEMERAL'] as $pattern) {
    check('ignores ' . var_export($pattern, true), call_private($engine, 'patternCoversPdf', [$pattern]), false);
}

echo "\n=== policy.xml parsing ===\n";

$deny = '<policymap>
  <policy domain="coder" rights="none" pattern="PDF" />
</policymap>';
check('deny rule detected', call_private($engine, 'policyDeniesPdf', [$deny]), true);

$denyGroup = '<policymap>
  <policy domain="coder" rights="none" pattern="{PS,PS2,PS3,EPS,PDF,XPS}" />
</policymap>';
check('grouped deny rule detected', call_private($engine, 'policyDeniesPdf', [$denyGroup]), true);

// Debian ships this to *restore* PDF after the 2018 Ghostscript advisory.
$allow = '<policymap>
  <policy domain="coder" rights="read|write" pattern="PDF" />
</policymap>';
check('read rights are not a block', call_private($engine, 'policyDeniesPdf', [$allow]), false);

$otherDomain = '<policymap>
  <policy domain="delegate" rights="none" pattern="PDF" />
  <policy domain="path" rights="none" pattern="@*" />
</policymap>';
check('other domains ignored', call_private($engine, 'policyDeniesPdf', [$otherDomain]), false);

$unrelated = '<policymap>
  <policy domain="resource" name="memory" value="256MiB"/>
  <policy domain="coder" rights="none" pattern="MVG" />
</policymap>';
check('unrelated coder deny ignored', call_private($engine, 'policyDeniesPdf', [$unrelated]), false);

// A policy.xml the plugin cannot parse must not become a fatal, and must not
// be reported as a block either -- guessing wrong sends the user to their host
// with the wrong request.
foreach (['', 'not xml at all', '<policymap><policy domain="coder"', '<<<>>>'] as $junk) {
    check('junk ' . var_export(substr($junk, 0, 12), true) . ' is not a block',
        call_private($engine, 'policyDeniesPdf', [$junk]), false);
}

echo "\n=== file lookup ===\n";

@mkdir(RAPLS_PIC_TEST_TMP, 0777, true);
$denyFile = RAPLS_PIC_TEST_TMP . '/policy-deny.xml';
$allowFile = RAPLS_PIC_TEST_TMP . '/policy-allow.xml';
file_put_contents($denyFile, $deny);
file_put_contents($allowFile, $allow);

reset_state();
add_filter('rapls_pdf_image_creator_policy_paths', function () use ($allowFile, $denyFile) {
    return [$allowFile, $denyFile];
});
check('finds the denying file', call_private($engine, 'findPdfPolicyBlock'), $denyFile);

reset_state();
add_filter('rapls_pdf_image_creator_policy_paths', function () use ($allowFile) {
    return [$allowFile, '/no/such/policy.xml'];
});
// The '/no/such' entry is the point: an unreadable candidate must be skipped
// rather than become a fatal on every admin page.
check('permissive policy returns null', call_private($engine, 'findPdfPolicyBlock'), null);

reset_state();
add_filter('rapls_pdf_image_creator_policy_paths', function () { return []; });
check('no candidates returns null', call_private($engine, 'findPdfPolicyBlock'), null);

// The lookup hits the filesystem, and the notice runs on every admin page.
reset_state();
$calls = 0;
add_filter('rapls_pdf_image_creator_policy_paths', function ($p) use (&$calls, $denyFile) {
    $calls++;
    return [$denyFile];
});
call_private($engine, 'findPdfPolicyBlock');
call_private($engine, 'findPdfPolicyBlock');
check('result is cached, filesystem hit once', $calls, 1);

echo "\n=== candidate paths ===\n";

// Verified against a real ImageMagick 7.1.1: with MAGICK_CONFIGURE_PATH
// pointing at a policy.xml that denies the PDF coder, queryFormats('PDF')
// returns empty while getConfigureOptions() still reports the *build*
// directory. Searching only the latter finds nothing and the plugin then
// blames a missing delegate instead of the policy.
reset_state();
putenv('MAGICK_CONFIGURE_PATH=/custom/config-Q16');
putenv('MAGICK_HOME=/opt/im');
$candidates = call_private($engine, 'getPolicyFileCandidates');
putenv('MAGICK_CONFIGURE_PATH');
putenv('MAGICK_HOME');

check('runtime configure path searched',
    in_array('/custom/config-Q16/policy.xml', $candidates, true), true);
check('  ...and searched first', $candidates[0], '/custom/config-Q16/policy.xml');
check('MAGICK_HOME searched too',
    in_array('/opt/im/etc/ImageMagick-7/policy.xml', $candidates, true), true);
check('distribution paths still searched',
    in_array('/etc/ImageMagick-6/policy.xml', $candidates, true), true);
check('no duplicates', count($candidates), count(array_unique($candidates)));

reset_state();
$bare = call_private($engine, 'getPolicyFileCandidates');
check('works with no environment set', count($bare) > 0, true);

echo "\n=== availability status ===\n";

reset_state();
$status = $engine->getAvailabilityStatus();

// Imagick is stubbed as a plain class here, so extension_loaded() is false --
// the same state as a server that never installed it.
check('no extension is reported as such', $status['code'], 'no_extension');
check('  ...carries a label', '' !== $status['label'], true);
check('  ...carries something to ask the host', '' !== $status['action'], true);
check('  ...names the extension, not the policy',
    false !== stripos($status['action'], 'imagick'), true);

foreach (['code', 'label', 'summary', 'action', 'detail'] as $key) {
    check('status has ' . $key, array_key_exists($key, $status), true);
}

// Every branch must return the same shape, or the notice and Site Health
// break on whichever branch nobody reproduced by hand. extension_loaded()
// cannot be faked, so exercise the builder each branch goes through.
$labels = [];
$summaries = [];
foreach (['ok', 'no_extension', 'pdf_blocked_by_policy', 'pdf_unsupported', 'error'] as $code) {
    $branch = call_private($engine, 'statusFor', [$code]);

    check($code . ': code echoed back', $branch['code'], $code);
    check($code . ': same five keys', array_keys($branch), ['code', 'label', 'summary', 'action', 'detail']);
    check($code . ': label is set', '' !== $branch['label'], true);
    check($code . ': summary is set', '' !== $branch['summary'], true);
    check($code . ': action set unless ok', '' !== $branch['action'], 'ok' !== $code);

    $labels[] = $branch['label'];
    $summaries[] = $branch['summary'];
}

// The whole point is that the user can tell the branches apart.
check('every label distinct', count(array_unique($labels)), 5);
check('every summary distinct', count(array_unique($summaries)), 5);

// An unknown code must not produce an empty notice.
$unknown = call_private($engine, 'statusFor', ['something-new']);
check('unknown code falls back to error', $unknown['code'], 'error');
check('  ...and still says something', '' !== $unknown['summary'], true);

// The policy branch is the one that carries the file path.
$withDetail = call_private($engine, 'statusFor', ['pdf_blocked_by_policy', '/etc/ImageMagick-6/policy.xml']);
check('detail is passed through', $withDetail['detail'], '/etc/ImageMagick-6/policy.xml');
check('policy branch names policy.xml',
    false !== stripos($withDetail['action'], 'policy.xml'), true);
check('delegate branch does not blame policy alone',
    false !== stripos(call_private($engine, 'statusFor', ['pdf_unsupported'])['action'], 'ImageMagick'), true);

echo "\n=== requirements still readable without the extension ===\n";
$reqs = $engine->getRequirements();
check('extension row present', $reqs['extension']['status'], false);
check('no pdf_support row without the extension', isset($reqs['pdf_support']), false);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
