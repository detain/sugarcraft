<?php

declare(strict_types=1);

/*
 * ParaTest 7.4.9 worker-loop replacement, loaded with
 *   --passthru-php="'-d' 'auto_prepend_file=/abs/path/paratest-worker-prelude.php'"
 * It runs BEFORE vendor/brianium/paratest/bin/phpunit-wrapper.php, does that file's job, then exits so the stock loop never runs.
 * Differences from stock: commands are read through a PRIVATE dup of fd 0, and blocking mode is re-asserted before every read,
 * so a test that sets STDIN non-blocking (or a bootstrap that fclose()s STDIN) can no longer end the worker with exit 0.
 * 7.4.9 is the last ParaTest line for PHPUnit 10, so the internal API copied here is frozen.
 */

use ParaTest\WrapperRunner\ApplicationForWrapperWorker;
use ParaTest\WrapperRunner\WrapperWorker;

if (PHP_SAPI !== 'cli' || !str_ends_with((string) ($_SERVER['argv'][0] ?? ''), 'phpunit-wrapper.php')) {
    return;
}

(static function (): void {
    $getopt = getopt('', ['status-file:', 'progress-file:', 'unexpected-output-file:', 'testresult-file:', 'teamcity-file:', 'testdox-file:', 'testdox-color', 'testdox-columns:', 'phpunit-argv:']);
    $wrapperDir = dirname(realpath($_SERVER['argv'][0]));
    foreach ([dirname($wrapperDir, 3) . '/autoload.php', dirname($wrapperDir, 2) . '/vendor/autoload.php', dirname($wrapperDir) . '/vendor/autoload.php'] as $file) {
        if (file_exists($file)) {
            define('PHPUNIT_COMPOSER_INSTALL', $file);
            require_once $file;
            break;
        }
    }
    $version = \Composer\InstalledVersions::getVersion('brianium/paratest');
    if (!str_starts_with((string) $version, '7.4.')) {
        fwrite(STDERR, "paratest-worker-prelude: written for paratest 7.4.x, found {$version}\n");
        exit(1);
    }
    $statusFile = fopen($getopt['status-file'], 'wb');
    $phpunitArgv = unserialize($getopt['phpunit-argv'], ['allowed_classes' => false]);
    $control = fopen('php://stdin', 'rb'); // private dup, taken before the bootstrap can touch fd 0
    $application = new ApplicationForWrapperWorker(
        $phpunitArgv,
        $getopt['progress-file'],
        $getopt['unexpected-output-file'],
        $getopt['testresult-file'],
        $getopt['teamcity-file'] ?? null,
        $getopt['testdox-file'] ?? null,
        isset($getopt['testdox-color']),
        isset($getopt['testdox-columns']) ? (int) $getopt['testdox-columns'] : null,
    );
    while (true) {
        stream_set_blocking($control, true); // O_NONBLOCK lives on the shared open file description: undo any test's change
        if (feof($control)) {
            $application->end();
            exit;
        }
        $testPath = fgets($control);
        if ($testPath === false || $testPath === WrapperWorker::COMMAND_EXIT) {
            $application->end();
            exit;
        }
        $exitCode = $application->runTest(trim($testPath, "\n"));
        fwrite($statusFile, (string) $exitCode);
        fflush($statusFile);
    }
})();
exit;
