<?php

declare(strict_types=1);

/*
 * ParaTest 7.4.x worker loop with a private command channel.
 *
 * Loaded into every worker by scripts/paratest.sh with
 *   --passthru-php="'-d' 'auto_prepend_file=<abs>/scripts/paratest-worker-prelude.php'"
 * so it runs BEFORE vendor/brianium/paratest/bin/phpunit-wrapper.php, does that
 * file's whole job, and exits: the stock loop never runs. In any other PHP
 * process (the paratest parent, a test's own `php` child) it returns at once.
 *
 * WHY: the stock wrapper reads its "run this file" commands with fgets(STDIN),
 * and STDIN in a worker is the parent's command pipe. Two things in our suites
 * break that pipe for the rest of the worker's life:
 *   - a test that sets the real STDIN non-blocking (sugar-core's injected-termios
 *     PosixBackend tests did; O_NONBLOCK lives on the shared open file
 *     description, so every handle on the pipe sees it). The next fgets()
 *     returns false on an empty pipe and the worker exits 0;
 *   - a bootstrap that fclose()s STDIN on purpose (candy-code's, so no spawned
 *     child can ever read the developer's terminal). The next fgets() reads a
 *     closed handle and the worker exits 0.
 * Either way ParaTest reports `WorkerCrashedException ... Exit Code: 0(OK)`
 * against the file it handed out NEXT, not the file that did the damage.
 *
 * WHAT IS DIFFERENT FROM STOCK: commands are read from a private handle on fd 0
 * taken before the bootstrap runs, and blocking mode is re-asserted on it
 * before every read. Everything else, down to the one status byte per file, is
 * the stock 7.4.9 wrapper.
 *
 * LIMIT: the private handle is still inherited by children the tests spawn.
 * PHP can neither set close-on-exec on it nor reopen the pipe by path
 * (fopen('/proc/self/fd/0') resolves the symlink and fails on a pipe).
 *
 * 7.4.9 is the last ParaTest release for PHPUnit 10, so the internal API this
 * copies (ApplicationForWrapperWorker, WrapperWorker::COMMAND_EXIT) is frozen.
 * It refuses to run on anything else rather than guess.
 */

use ParaTest\WrapperRunner\ApplicationForWrapperWorker;
use ParaTest\WrapperRunner\WrapperWorker;

if (\PHP_SAPI !== 'cli' || !\str_ends_with((string) ($_SERVER['argv'][0] ?? ''), 'phpunit-wrapper.php')) {
    return;
}

(static function (): void {
    $getopt = \getopt('', [
        'status-file:',
        'progress-file:',
        'unexpected-output-file:',
        'testresult-file:',
        'teamcity-file:',
        'testdox-file:',
        'testdox-color',
        'testdox-columns:',
        'phpunit-argv:',
    ]);

    // The same autoloader search the stock wrapper does, anchored on the
    // wrapper's own location rather than this file's.
    $wrapperDir = \dirname((string) \realpath($_SERVER['argv'][0]));
    $candidates = [
        \dirname($wrapperDir, 3) . '/autoload.php',
        \dirname($wrapperDir, 2) . '/vendor/autoload.php',
        \dirname($wrapperDir) . '/vendor/autoload.php',
    ];
    foreach ($candidates as $file) {
        if (\file_exists($file)) {
            \define('PHPUNIT_COMPOSER_INSTALL', $file);
            require_once $file;
            break;
        }
    }

    $version = (string) \Composer\InstalledVersions::getVersion('brianium/paratest');
    if (!\str_starts_with($version, '7.4.')) {
        \fwrite(\STDERR, "paratest-worker-prelude: written for brianium/paratest 7.4.x, found {$version}; run without the prelude or update it\n");
        exit(1);
    }

    $statusFile = \fopen((string) $getopt['status-file'], 'wb');
    if ($statusFile === false) {
        \fwrite(\STDERR, "paratest-worker-prelude: cannot open the status file\n");
        exit(1);
    }
    $phpunitArgv = \unserialize((string) $getopt['phpunit-argv'], ['allowed_classes' => false]);

    // Taken before ApplicationForWrapperWorker loads the suite's bootstrap,
    // which may close STDIN or point fd 0 at /dev/null.
    $control = \fopen('php://stdin', 'rb');

    $application = new ApplicationForWrapperWorker(
        $phpunitArgv,
        (string) $getopt['progress-file'],
        (string) $getopt['unexpected-output-file'],
        (string) $getopt['testresult-file'],
        $getopt['teamcity-file'] ?? null,
        $getopt['testdox-file'] ?? null,
        isset($getopt['testdox-color']),
        isset($getopt['testdox-columns']) ? (int) $getopt['testdox-columns'] : null,
    );

    while (true) {
        // Undo any test's O_NONBLOCK before blocking for the next command.
        \stream_set_blocking($control, true);
        if (\feof($control)) {
            $application->end();
            exit;
        }

        $testPath = \fgets($control);
        if ($testPath === false || $testPath === WrapperWorker::COMMAND_EXIT) {
            $application->end();
            exit;
        }

        // One byte per file: the parent counts the status file's size.
        $exitCode = $application->runTest(\trim($testPath, "\n"));
        \fwrite($statusFile, (string) $exitCode);
        \fflush($statusFile);
    }
})();

exit;
