<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Pins the true-EOF exit of the shipped run-loop.
 *
 * A closed pump and a merely-empty one are the same signal (null) to the
 * input layer, so the probe cannot be run in-process with a fake — it is a
 * child PHP whose stdin is /dev/null: the loop owns that pump, must see the
 * end-of-file, and must return the prompt rather than start the idle-poll
 * backoff ladder. The child's idle sleeper exits 7 loudly, so a regression
 * that re-introduces the spin (mistaking a closed pump for an empty one)
 * fails fast and visibly instead of hanging the suite.
 *
 * @see https://github.com/sugarcraft/sugar-readline (docs contract on Readline::run())
 */
final class ReadlineEofTest extends TestCase
{
    public function testTrueEofOnTheOwnedPumpEndsTheRunPromptly(): void
    {
        $child = __DIR__ . '/Support/eof_probe_child.php';
        self::assertFileExists($child);

        // popen rather than proc_open: on this box proc_open's pipe
        // descriptors read back EBADF in the parent (measured), while the
        // child itself runs fine — popen streams are reliable here.
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($child) . ' < /dev/null 2>&1';

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

        self::assertStringContainsString('RETURNED submitted=false aborted=false', $output);
        self::assertStringNotContainsString('SPUN', $output);
        self::assertSame(0, $status, 'child must exit through the EOF return, not the idle-ladder guard');
    }
}
