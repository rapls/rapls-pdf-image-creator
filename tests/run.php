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

$suites = [
    'test-colorprofile.php',
    'test-engine.php',
    'test-pipeline.php',
    'test-availability.php',
    'test-failure-codes.php',
    'test-no-trialware.php',
    'test-review-prompt.php',
    'test-media-library.php',
    'test-thumbnail-ownership.php',
    'test-regenerate.php',
    'test-bulk.php',
    'test-insert.php',
    'test-uninstall.php',
    'test-admin-screens.php',
    'test-admin-notices.php',
    'test-site-health.php',
    // JavaScript, run with node when there is one.
    'test-admin-tabs.js',
    'test-block-editor.js',
    'test-bulk-admin.js',
    'test-core-media-states.js',
    'test-insert-link.js',
];
$failed = [];
// Seconds one suite may take; RAPLS_PIC_TEST_TIMEOUT changes it.
$timeout = max(1, (int) (getenv('RAPLS_PIC_TEST_TIMEOUT') ?: 300));

foreach ($suites as $suite) {
    echo str_repeat('=', 70) . "\n$suite\n" . str_repeat('=', 70) . "\n";

    // Every notice, deprecations included: leaving those out hid the
    // plugin's own along with the tests' (review of 1.4.35).
    $command = [PHP_BINARY, '-d', 'error_reporting=E_ALL', __DIR__ . '/' . $suite];

    if ('.js' === substr($suite, -3)) {
        $node = trim((string) @shell_exec('command -v node 2>/dev/null'));

        if ('' === $node) {
            echo "skipped: node not found\n\n";
            continue;
        }

        $command = [$node, __DIR__ . '/' . $suite];
    }

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes);

    if (!is_resource($process)) {
        $failed[] = $suite;
        continue;
    }

    // Read both pipes as they fill, until both close or the suite's time is
    // up. A suite that never ends -- a loop that no longer stops -- hung the
    // whole run before (mutation testing of 1.4.34); now it is stopped and
    // counted as failed.
    $deadline = microtime(true) + $timeout;
    $open = [1 => $pipes[1], 2 => $pipes[2]];
    $stderr = '';
    $timedOut = false;
    foreach ($open as $pipe) {
        stream_set_blocking($pipe, false);
    }
    while ([] !== $open) {
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            break;
        }
        $read = array_values($open);
        $write = null;
        $except = null;
        // A select that fails is waited out and the pipes read anyway: giving
        // up on them left a suite that writes a lot blocked on a full pipe,
        // and reported as timed out (review of 1.4.35).
        if (false === @stream_select($read, $write, $except, 1)) {
            usleep(10000);
        }
        foreach ($open as $n => $pipe) {
            $chunk = (string) fread($pipe, 65536);
            if (1 === $n) {
                echo $chunk;
            } else {
                $stderr .= $chunk;
            }
            if (feof($pipe)) {
                unset($open[$n]);
            }
        }
    }

    // The exit status, read while the process is still known: proc_close()
    // answers -1 for one proc_get_status() has already seen end, before PHP 8.3.
    $exit = null;
    while (!$timedOut) {
        $status = proc_get_status($process);
        if (!$status['running']) {
            $exit = $status['exitcode'];
            break;
        }
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            break;
        }
        usleep(10000);
    }

    if ($timedOut) {
        // proc_terminate() reaches the suite's own process only, and a
        // Ghostscript or PHP it started was left running (review after
        // 1.4.34): its descendants are found by ps and stopped first. Not
        // one whose parent has already exited -- it is then PID 1's child,
        // and nothing here can tell it from anyone else's.
        $pid = (int) (proc_get_status($process)['pid'] ?? 0);
        if ($pid > 0 && function_exists('posix_kill')) {
            $children = [];
            $listing = (string) @shell_exec('ps -A -o pid= -o ppid= 2>/dev/null');
            // No ps, or one this process may not run (a sandbox): said, since
            // whatever the suite started may still be running.
            if ('' === trim($listing)) {
                echo "\n(could not list the suite's child processes; anything it started may still be running)\n";
            }
            foreach (preg_split('/\n/', $listing) as $line) {
                if (preg_match('/^\s*(\d+)\s+(\d+)\s*$/', $line, $m)) {
                    $children[(int) $m[2]][] = (int) $m[1];
                }
            }
            $kill = [];
            $todo = [$pid];
            while ([] !== $todo) {
                foreach ($children[array_pop($todo)] ?? [] as $child) {
                    $kill[] = $child;
                    $todo[] = $child;
                }
            }
            foreach (array_reverse($kill) as $child) {
                posix_kill($child, 9);
            }
        }
        proc_terminate($process, 9);
        echo "\nTIMED OUT after {$timeout}s\n";
    }
    array_map('fclose', $pipes);

    if ('' !== trim($stderr)) {
        echo "stderr:\n$stderr";
    }

    $closed = proc_close($process);
    if ($timedOut || 0 !== ($exit ?? $closed)) {
        $failed[] = $suite;
    }

    echo "\n";
}

if ($failed) {
    echo 'FAILED: ' . implode(', ', $failed) . "\n";
    exit(1);
}

echo "All suites passed.\n";
