<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Pins the pump-reuse contract: one Readline instance fed by the owned
 * php://stdin pump serves SEVERAL successive run() calls from a single
 * piped feed — the shape every REPL built on this library takes.
 *
 * The pre-fix loop built a fresh stream + StreamInputDriver per run():
 * the eager first read drained the pipe into run #1's driver, and run #2
 * saw an emptied stream, so every prompt after the first silently received
 * nothing (`printf 'a\nb\n' | php repl.php` executed only 'a'). A closed
 * pump and a merely-empty one are indistinguishable to the input layer, so
 * this can only be pinned in a child whose stdin is a real pipe — and it
 * must be a PIPE, not a seekable file: the regression needs the drained-
 * into-internal-queue shape, which `php child < file` also reproduces
 * (the stream reads once and feofs). The child's idle sleeper exits 7 so
 * an empty-but-open mis-wire fails fast instead of hanging.
 *
 * @see Readline::runLoop()
 */
final class ReadlinePumpReuseTest extends TestCase
{
    public function testSuccessiveRunsShareOneOwnedPumpAcrossAPipedFeed(): void
    {
        $child = __DIR__ . '/Support/pump_reuse_child.php';
        self::assertFileExists($child);

        // Same popen choice as ReadlineEofTest (proc_open pipe descriptors
        // read back EBADF in the parent on this box — measured).
        $command = "printf 'one\\ntwo\\n' | "
            . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($child) . ' 2>&1';

        $handle = popen($command, 'r');
        self::assertIsResource($handle);

        $output = '';
        while (!feof($handle)) {
            $chunk = fgets($handle);
            if (false === $chunk) {
                break;
            }
            $output .= $chunk;
        }
        $status = pclose($handle);

        self::assertStringNotContainsString('SPUN', $output, 'the pump must never idle on a drained pipe');
        self::assertSame(0, $status);
        self::assertStringContainsString('GOT|one|two||', $output, $output);
        self::assertStringContainsString('submitted=y', $output, $output);
        self::assertStringContainsString('drained=y', $output, 'a run after EOF must see EOF, not reopen the pump');
    }
}
