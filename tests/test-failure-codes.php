<?php
/**
 * Failure classification (1.4.0).
 *
 * The point of a code rather than a sentence is that two failures needing
 * different answers stop looking the same. Most of these assertions are really
 * about that boundary: policy refusal versus missing delegate, and permanent
 * versus worth retrying.
 */

require __DIR__ . '/harness.php';
require RAPLS_PIC_PLUGIN_DIR . 'includes/Engine/ConversionResult.php';

use Rapls\PDFImageCreator\FailureCode;
use Rapls\PDFImageCreator\Engine\ConversionResult;

echo "=== real ImageMagick messages ===\n";

// Every one of these was copied from an actual exception, not invented.
$messages = [
    // The free plugin shipped a pattern for this that read "not authoriz" with
    // a space, and matched none of them. ImageMagick writes it as one word.
    "NotAuthorized `PDF' @ error/constitute.c/ReadImage/746"
        => FailureCode::PDF_BLOCKED_BY_POLICY,
    "attempt to perform an operation not allowed by the security policy `PDF'"
        => FailureCode::PDF_BLOCKED_BY_POLICY,

    "FailedToExecuteCommand `'gs' -sstdout=%stderr -dQUIET ...' (1)"
        => FailureCode::PDF_UNSUPPORTED,
    "no decode delegate for this image format `PDF' @ error/constitute.c/ReadImage/746"
        => FailureCode::PDF_UNSUPPORTED,
    "NoDecodeDelegateForThisImageFormat `PDF' @ error/constitute.c/ReadImage/746"
        => FailureCode::PDF_UNSUPPORTED,

    // Measured: this is what an ImageMagick resource limit produces.
    "CacheResourcesExhausted `' @ error/cache.c/OpenPixelCache/3892"
        => FailureCode::RESOURCE_LIMIT,
    "width or height exceeds limit `big.pdf' @ error/cache.c/OpenPixelCache/3911"
        => FailureCode::RESOURCE_LIMIT,

    "unable to open image `/var/www/uploads/x.jpg': Permission denied"
        => FailureCode::WRITE_FAILED,
    "unable to write blob `out.jpg': No space left on device"
        => FailureCode::WRITE_FAILED,

    "something nobody has seen before" => FailureCode::RENDER_ERROR,
    "" => FailureCode::RENDER_ERROR,
];

foreach ($messages as $message => $expected) {
    $short = strlen($message) > 44 ? substr($message, 0, 41) . '...' : ($message ?: '(empty)');
    check($short, FailureCode::fromExceptionMessage($message), $expected);
}

echo "\n=== policy and delegate stay apart ===\n";

// This is the distinction the whole scheme exists for. One means "ask the host
// to allow PDFs", the other means "ask the host to install the delegate", and
// they go to different people at the hosting company.
check(
    'a policy refusal is not a missing delegate',
    FailureCode::fromExceptionMessage("NotAuthorized `PDF'") === FailureCode::fromExceptionMessage("no decode delegate for this image format `PDF'"),
    false
);

echo "\n=== permanence ===\n";

// A queue that retries these makes no progress while looking busy.
foreach ([FailureCode::NO_EXTENSION, FailureCode::PDF_BLOCKED_BY_POLICY,
          FailureCode::PDF_UNSUPPORTED, FailureCode::NOT_A_PDF,
          FailureCode::SOURCE_MISSING, FailureCode::RESOURCE_LIMIT] as $code) {
    check("$code is permanent", FailureCode::isPermanent($code), true);
}

foreach ([FailureCode::WRITE_FAILED, FailureCode::RENDER_ERROR, FailureCode::ERROR] as $code) {
    check("$code is worth retrying", FailureCode::isPermanent($code), false);
}

// A full disk empties; a page that is 200 megapixels is 200 megapixels again
// tomorrow. The two look similar and are not.
check(
    'a full disk and an oversized page are classed differently',
    FailureCode::isPermanent(FailureCode::WRITE_FAILED) === FailureCode::isPermanent(FailureCode::RESOURCE_LIMIT),
    false
);

echo "\n=== what a person is told ===\n";

// Anything a site owner can act on has to say what to do. A code with a
// description and no next step is a support ticket.
foreach ([FailureCode::SOURCE_MISSING, FailureCode::RESOURCE_LIMIT,
          FailureCode::WRITE_FAILED, FailureCode::RENDER_ERROR] as $code) {
    $described = FailureCode::describe($code);
    check("$code has a label", '' !== $described['label'], true);
    check("$code has an action", '' !== $described['action'], true);
}

// The environment codes are answered by ImagickEngine::statusFor(), which can
// name the policy file it found. Answering them here as well would be two
// wordings for one situation.
foreach ([FailureCode::NO_EXTENSION, FailureCode::PDF_BLOCKED_BY_POLICY,
          FailureCode::PDF_UNSUPPORTED] as $code) {
    check("$code is left to statusFor()", FailureCode::describe($code)['label'], '');
}

// The oversized-page advice has to be actionable, not a diagnosis.
check(
    'the too-large advice says what to change',
    false !== stripos(FailureCode::describe(FailureCode::RESOURCE_LIMIT)['action'], 'resolution'),
    true
);

check('an unknown code does not fatal', FailureCode::describe('made_up_code'), ['label' => '', 'action' => '']);

echo "\n=== the result carries the code ===\n";

$failure = ConversionResult::failure('boom', FailureCode::RESOURCE_LIMIT);
check('failure keeps its code', $failure->getCode(), FailureCode::RESOURCE_LIMIT);
check('failure keeps its message', $failure->getError(), 'boom');
check('failure is not a success', $failure->isSuccess(), false);

$default = ConversionResult::failure('boom');
check('an unclassified failure is a render error', $default->getCode(), FailureCode::RENDER_ERROR);

$success = ConversionResult::success('/tmp/x.jpg', 100, 200, 300, 0.5);
check('success has no code', $success->getCode(), '');
check('success still reports its size', $success->getWidth(), 100);

echo "\n=== the vocabulary is closed ===\n";

// Anything the engine can produce has to be a code the rest of the plugin
// knows, or the Status tab will one day be handed a string it cannot describe.
$all = FailureCode::all();
foreach ($messages as $message => $expected) {
    check('classifier returns a known code for: ' . (substr($message, 0, 20) ?: '(empty)'), in_array($expected, $all, true), true);
}

check('no duplicates in the vocabulary', count($all), count(array_unique($all)));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit(0 === $fail ? 0 : 1);
