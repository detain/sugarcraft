<?php

declare(strict_types=1);

/**
 * Child-process probe for {@see \SugarCraft\Flip\Renderer::withAdaptiveSize()}.
 *
 * Driven by {@see \SugarCraft\Flip\Tests\RendererAdaptiveSizeDescriptorTest};
 * never collected by PHPUnit itself, because the suite only picks up files
 * whose name ends in `Test.php`.
 *
 * ## Why a child at all
 *
 * `withAdaptiveSize()` takes no arguments and asks about a standard
 * descriptor. WHICH one it asks about is only observable in a process whose
 * descriptors 1 and 2 are DIFFERENT terminals — and a PHPUnit run cannot
 * rearrange its own standard descriptors without taking itself down. So the
 * caller builds the arrangement and this reports from inside it.
 *
 * Results go to <result-file>, never to standard output: standard output is
 * a terminal device the harness is not reading.
 *
 * Usage: `php adaptive_size_probe.php <result-file>`
 */

use SugarCraft\Flip\Renderer;

require \dirname(__DIR__) . '/vendor/autoload.php';

$resultFile = $argv[1] ?? '';

$out = [
    // The arrangement, reported rather than assumed.
    'isatty_1' => posix_isatty(1),
    'isatty_2' => posix_isatty(2),

    // Control: proves the probe body ran to the end.
    'control'  => 'PROBE-RAN',
];

try {
    $renderer = Renderer::withAdaptiveSize();

    // The adaptive dimensions are private readonly state, and reading them
    // is the whole observation — the alternative, rendering an oversized
    // frame and counting glyphs back out of an SGR stream, measures the
    // clamping loop as well and would blur which of the two is under test.
    $reflection = new ReflectionClass($renderer);
    $out['result'] = 'ok';
    $out['rows']   = $reflection->getProperty('adaptiveRows')->getValue($renderer);
    $out['cols']   = $reflection->getProperty('adaptiveCols')->getValue($renderer);
} catch (\Throwable $e) {
    $out['result']  = 'throw';
    $out['class']   = $e::class;
    $out['message'] = $e->getMessage();
}

file_put_contents($resultFile, json_encode($out));
exit(0);
