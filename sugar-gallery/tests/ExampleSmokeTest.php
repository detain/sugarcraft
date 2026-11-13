<?php

declare(strict_types=1);

namespace SugarCraft\Gallery\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Runtime smoke for the shipped examples (sugar-gallery-027).
 *
 * The two files under examples/ are the lib's advertised integration
 * recipe — the README points at them as the current proof of the seams —
 * yet nothing in the suite ever RAN them. A signature drift in either
 * direction (a wither renamed, a getter dropped, a clamp that starts to
 * throw) used to be catchable only by hand-invoking the demo. These two
 * tests exec each example exactly as the header comment advertises, under
 * a bounded wall clock so a wedged pump can never outlive the suite.
 *
 * Children are accounted, not assumed: stdout and stderr ride temp files
 * (no pipe-buffer deadlock on a large frame), stdin is /dev/null, and the
 * proc handle is closed before the files are read — the house
 * drain-then-proc_close reap idiom. tools/check-child-lifetimes.php scans
 * src autoload roots only, so a test-file spawn is out of its scope by
 * design; correctness is pinned here instead.
 */
final class ExampleSmokeTest extends TestCase
{
    /** One-shot dumps; half a minute is two orders of magnitude of slack. */
    private const WALL_CLOCK_BUDGET_SECONDS = 30;

    /** @return array{exit:int,out:string,err:string} */
    private function runExample(string $file): array
    {
        $path = __DIR__ . '/../examples/' . $file;
        self::assertFileExists($path);

        $outFile = (string) tempnam(sys_get_temp_dir(), 'sg-smoke-out-');
        $errFile = (string) tempnam(sys_get_temp_dir(), 'sg-smoke-err-');

        try {
            $process = proc_open(
                [
                    '/usr/bin/timeout', '-s', 'KILL', (string) self::WALL_CLOCK_BUDGET_SECONDS,
                    \PHP_BINARY, $path,
                ],
                [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['file', $outFile, 'w'],
                    2 => ['file', $errFile, 'w'],
                ],
                $pipes,
                \dirname(__DIR__),
            );
            self::assertIsResource($process, 'could not start example ' . $file);

            $exit = proc_close($process);

            return [
                'exit' => $exit,
                'out' => (string) file_get_contents($outFile),
                'err' => (string) file_get_contents($errFile),
            ];
        } finally {
            @unlink($outFile);
            @unlink($errFile);
        }
    }

    public function testThePosterGridExamplePaintsItsAdvertisedFrame(): void
    {
        $run = $this->runExample('poster-grid.php');

        self::assertSame(0, $run['exit'], 'poster-grid.php exited ' . $run['exit'] . "\nstderr: " . $run['err']);
        // The focus cursor and the skeleton tile — the two glyphs the
        // header paragraph advertises ("The ▸ tile is the cursor; the ░
        // tile is an index whose page has not been fetched yet").
        self::assertStringContainsString('▸', $run['out'], 'the focus cursor must reach the frame');
        self::assertStringContainsString('░', $run['out'], 'the skeleton must reach the frame');
        // And a piece of card data a render-only smoke could silently lose.
        self::assertStringContainsString('Blade Runner', $run['out']);
    }

    public function testTheMosaicExampleRunsTheRealImagePipeline(): void
    {
        // Same gate, same words as MosaicPosterIntegrationTest: the demo
        // synthesises its images with GD so it must run offline.
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd is required to synthesise poster images without a network.');
        }

        $run = $this->runExample('poster-grid-mosaic.php');

        self::assertSame(0, $run['exit'], 'poster-grid-mosaic.php exited ' . $run['exit'] . "\nstderr: " . $run['err']);
        // Half-block poster art and the promoted-cache section are the two
        // claims this example makes that no unit test restates.
        self::assertStringContainsString('▀', $run['out'], 'half-block poster art must reach the frame');
        self::assertStringContainsString('Disk', $run['out'], 'the DiskCache seam must stay in the demo');
    }
}
