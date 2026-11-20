<?php

declare(strict_types=1);

/**
 * Test fixture — loads the `HelloMetrics` middleware shipped in
 * `examples/ssh-server-with-metrics.php` in a separate PHP process and
 * exercises it against the REAL {@see \SugarCraft\SshServer\Middleware}
 * interface.
 *
 * Why a subprocess: a `handle()` signature that is incompatible with the
 * interface (e.g. the historical missing `Context $ctx` parameter, which
 * shipped in this example once) is an uncatchable compile-time fatal at
 * class-declaration time — `php -l` never sees it (single-file parse, no
 * interface linkage) and try/catch cannot swallow it. The child process
 * surfaces the fatal to the parent test as exit code 255.
 *
 * The example ends in `Server::new()->...->serve()`, which would block on
 * a live session, so we slice out just the `use` imports, the class
 * definition, and the registry setup lines (everything between the first
 * `use SugarCraft` line and the `Server::new(` invocation) and eval that.
 * The registry construction is side-effect-free; the file backend's
 * destructor flush on child exit writes a scratch textfile the parent
 * test removes.
 *
 * Usage:
 *   php load-middleware-example.php <path-to-example.php>
 *
 * Exit codes:
 *   0    HelloMetrics defined, interface-compatible, invoked, continuation ran
 *   2    unexpected throwable while invoking (e.g. a runtime TypeError)
 *   3    HelloMetrics never called the $next continuation
 *   4    could not locate the extraction markers in the example
 *   255  class-declaration fatal (signature incompatible with Middleware)
 */

require __DIR__ . '/../../../vendor/autoload.php';

use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Session;

$examplePath = $argv[1] ?? (__DIR__ . '/../../../examples/ssh-server-with-metrics.php');
$src = (string) \file_get_contents($examplePath);

$start = \strpos($src, 'use SugarCraft');
$end = \strpos($src, 'Server::new(');
// Cut the slice at the line boundary before the invocation so the eval
// never contains an unclosed chain expression.
$lineStart = $end === false ? false : \strrpos(\substr($src, 0, $end), "\n");
if ($lineStart !== false) {
    $end = $lineStart;
}
if ($start === false || $end === false || $end <= $start) {
    \fwrite(\STDERR, "could not find extraction markers in {$examplePath}\n");
    exit(4);
}

$slice = \substr($src, $start, $end - $start);
eval($slice);

// Non-interactive session so handle() has no I/O expectations beyond its
// own echo.
$session = new Session(
    user: 'tester', clientHost: '203.0.113.9', clientPort: 4242,
    serverHost: '198.51.100.2', serverPort: 22, term: 'xterm-256color',
    cols: 80, rows: 24, tty: null, command: null, lang: 'C.UTF-8',
);

$nextCalled = false;
try {
    $greeting = new HelloMetrics();
    $greeting->handle(
        Context::background(),
        $session,
        static function (Context $c, Session $s) use (&$nextCalled): void {
            $nextCalled = true;
        },
    );
} catch (\Throwable $e) {
    \fwrite(\STDERR, 'invoke failed: ' . $e::class . ': ' . $e->getMessage() . "\n");
    exit(2);
}

if (!$nextCalled) {
    \fwrite(\STDERR, "HelloMetrics did not call the \$next continuation\n");
    exit(3);
}

echo "\nHELLO-METRICS-OK\n";
exit(0);
