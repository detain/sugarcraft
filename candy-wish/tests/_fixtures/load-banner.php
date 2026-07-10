<?php

declare(strict_types=1);

/**
 * Test fixture — loads the inline `Banner` middleware from
 * `examples/hello-server.php` in a separate PHP process and exercises
 * it against the REAL {@see \SugarCraft\Wish\Middleware} interface.
 *
 * Why a subprocess: a `handle()` signature that is incompatible with
 * the interface (e.g. the historical missing `Context $ctx` parameter)
 * is an uncatchable compile-time fatal at class-declaration time —
 * `php -l` never sees it (single-file parse, no interface linkage) and
 * a try/catch cannot swallow it. Loading the class in a child process
 * lets the parent test observe the fatal as a non-zero exit code
 * instead of taking the whole PHPUnit run down with it.
 *
 * The example ends in `Server::new()->...->serve()`, which would block
 * on a live session, so we slice out just the `use` imports + the
 * `Banner` class definition (everything between the first
 * `use SugarCraft` line and the `Server::new(` invocation) and eval
 * that. eval keeps the file's own `use` aliases, so the class links
 * against the real interface exactly as it would when the example runs.
 *
 * Usage:
 *   php load-banner.php <path-to-hello-server.php>
 *
 * Exit codes:
 *   0  Banner defined, interface-compatible, invoked, continuation ran
 *   2  unexpected throwable while invoking (e.g. a runtime TypeError)
 *   3  Banner never called the $next continuation
 *   4  could not locate the extraction markers in the example
 *  255 class-declaration fatal (signature incompatible with Middleware)
 */

require __DIR__ . '/../../vendor/autoload.php';

use SugarCraft\Wish\Context;
use SugarCraft\Wish\Session;

$examplePath = $argv[1] ?? (__DIR__ . '/../../examples/hello-server.php');
$src = (string) \file_get_contents($examplePath);

$start = \strpos($src, 'use SugarCraft');
$end = \strpos($src, 'Server::new(');
if ($start === false || $end === false || $end <= $start) {
    \fwrite(\STDERR, "could not find extraction markers in {$examplePath}\n");
    exit(4);
}

// Slice = the example's `use` imports + the inline Banner class. eval
// links Banner against the real Middleware interface here; a bad
// signature aborts the process with a compile-time fatal (exit 255).
$slice = \substr($src, $start, $end - $start);
eval($slice);

// Non-interactive session (tty=null) so Banner::handle() does NOT block
// on fread(STDIN, 1); it still echoes the banner to stdout.
$session = new Session(
    user: 'tester', clientHost: '203.0.113.9', clientPort: 4242,
    serverHost: '198.51.100.2', serverPort: 22, term: 'xterm-256color',
    cols: 80, rows: 24, tty: null, command: null, lang: 'C.UTF-8',
);

$nextCalled = false;
try {
    $banner = new Banner();
    $banner->handle(
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
    \fwrite(\STDERR, "Banner did not call the \$next continuation\n");
    exit(3);
}

echo "\nBANNER-OK\n";
exit(0);
