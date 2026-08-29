<?php
/**
 * Test runner.
 *
 *   php tests/run.php
 *
 * Each suite runs in its own process because they define conflicting Imagick
 * stubs. No WordPress and no ImageMagick required — everything these suites
 * touch is stubbed in harness.php.
 */

$suites = ['test-colorprofile.php', 'test-engine.php', 'test-pipeline.php', 'test-availability.php'];
$failed = [];

foreach ($suites as $suite) {
    echo str_repeat('=', 70) . "\n$suite\n" . str_repeat('=', 70) . "\n";

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        [PHP_BINARY, '-d', 'error_reporting=E_ALL & ~E_DEPRECATED', __DIR__ . '/' . $suite],
        $descriptors,
        $pipes
    );

    if (!is_resource($process)) {
        $failed[] = $suite;
        continue;
    }

    echo stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    array_map('fclose', $pipes);

    if ('' !== trim($stderr)) {
        echo "stderr:\n$stderr";
    }

    if (0 !== proc_close($process)) {
        $failed[] = $suite;
    }

    echo "\n";
}

if ($failed) {
    echo 'FAILED: ' . implode(', ', $failed) . "\n";
    exit(1);
}

echo "All suites passed.\n";
