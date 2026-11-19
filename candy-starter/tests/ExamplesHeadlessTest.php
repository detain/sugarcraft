<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Runs examples/headless.php as a child process and pins its transcript.
 * The example is the copyable headless driver the docs page points at, so
 * a change to Counter's transitions or frame must show up here as well.
 */
final class ExamplesHeadlessTest extends TestCase
{
    public function testHeadlessDriverPrintsTheFixedKeyTrace(): void
    {
        $script = dirname(__DIR__) . '/examples/headless.php';
        $this->assertFileExists($script);

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' < /dev/null 2>&1';
        exec($command, $lines, $code);

        $this->assertSame(0, $code, implode("\n", $lines));
        $this->assertSame([
            'start  count=0',
            'up     count=1  cmd=none',
            'up     count=2  cmd=none',
            'down   count=1  cmd=none',
            'x      count=1  cmd=none',
            'q      count=1  cmd=quit',
            '╭─────────────────────────────────────────╮',
            '│                                         │',
            '│  count: 1                               │',
            '│  ↑ ↓ to change · q / Esc / Ctrl+C quit  │',
            '│                                         │',
            '╰─────────────────────────────────────────╯',
        ], $lines);
    }
}
