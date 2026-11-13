<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cli\Application;
use SugarCraft\Vcr\Cli\RenderBatchCommand;
use SugarCraft\Vcr\Tests\Support\Stream;
use Symfony\Component\Process\Process;

/**
 * VCR-S30 (finding candy-vcr-057): `render-batch --jobs=N` renders through
 * render-tape child processes while the parent only schedules, polls, and
 * reports. The child invocations are real CLI subprocesses, so this suite
 * exercises the shipped bin end-to-end.
 */
final class RenderBatchJobsTest extends TestCase
{
    private const TAPE_COUNT = 8;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('ext-gd not available');
        }
    }

    public function testJobsFourMatchesSerialReportAndGifBytes(): void
    {
        $serialDir = $this->makeFixture();
        $parallelDir = $this->makeFixture();

        try {
            [$serialExit, $serialOut] = $this->runBatch($serialDir, 1);
            [$parallelExit, $parallelOut] = $this->runBatch($parallelDir, 4);

            $this->assertSame(0, $serialExit);
            $this->assertSame(0, $parallelExit, 'parallel batch must exit 0 when every tape renders: ' . $parallelOut);

            // The report table (header, per-tape rows, total line) must match
            // the serial output byte-for-byte apart from measured times; the
            // progress bar itself is excluded because children complete out of
            // order, which only changes intermediate redraws.
            $this->assertSame(
                $this->normalizeReport($serialOut),
                $this->normalizeReport($parallelOut),
            );

            // Every tape produced its GIF, and the pixels match the serial run.
            for ($i = 0; $i < self::TAPE_COUNT; $i++) {
                $serialGif = $serialDir . '/t' . $i . '.gif';
                $parallelGif = $parallelDir . '/t' . $i . '.gif';
                $this->assertFileExists($parallelGif);
                $this->assertSame(
                    md5_file($serialGif),
                    md5_file($parallelGif),
                    "t{$i}.gif must be byte-identical across scheduling modes",
                );
            }
        } finally {
            $this->removeTree($serialDir);
            $this->removeTree($parallelDir);
        }
    }

    /**
     * Pool pacing proof with fake children (each sleeps 250ms): the first
     * $jobs spawns must fire together (the pool fills immediately — a serial
     * scheduler would leave ~250ms between them), and the ($jobs+1)-th spawn
     * must wait for a completion (an unbounded spawner would launch all 8 in
     * the first instant). Both mutations are caught by design.
     */
    public function testPoolKeepsAtMostJobsChildrenInFlight(): void
    {
        $dir = $this->makeFixture();

        try {
            /** @var list<float> $spawnTimes */
            $spawnTimes = [];
            $factory = static function (array $argv) use (&$spawnTimes): Process {
                $spawnTimes[] = microtime(true);
                return new Process([\PHP_BINARY, '-r', 'usleep(250000);']);
            };

            $command = new RenderBatchCommand();
            $command->setProcessFactory($factory);
            $app = new Application(['render-batch' => $command]);

            $stdout = Stream::memory('w+');
            $this->assertNotFalse($stdout);
            $stderr = Stream::memory('w+');
            $this->assertNotFalse($stderr);

            $exit = $app->run(
                ['candy-vcr', 'render-batch', $dir, '--encoder', 'php', '--backend', 'gd', '--jobs', '4'],
                $stdout,
                $stderr,
            );
            rewind($stdout);
            $out = (string) stream_get_contents($stdout);

            $this->assertSame(0, $exit, $out);
            $this->assertCount(self::TAPE_COUNT, $spawnTimes, 'one child per tape');
            $this->assertStringContainsString('Succeeded: ' . self::TAPE_COUNT . ' | Failed: 0', $out);

            $this->assertLessThan(
                0.15,
                $spawnTimes[3] - $spawnTimes[0],
                'the first four children must launch together (a serial pool spaces them by the 250ms child sleep)',
            );
            $this->assertGreaterThan(
                0.12,
                $spawnTimes[4] - $spawnTimes[0],
                'the fifth child must wait for a slot (an unbounded spawner launches all eight at once)',
            );
        } finally {
            $this->removeTree($dir);
        }
    }

    public function testParentProgressBarTracksEveryCompletion(): void
    {
        $dir = $this->makeFixture();

        try {
            [$exit, $out, $err] = $this->runBatch($dir, 4);

            $this->assertSame(0, $exit);
            // The bar lives on the parent. Symfony's ConsoleProgressBar
            // renders bars to stderr for ConsoleOutput apps (stdout stays
            // pipe-clean) — the same placement the serial path has always
            // had; the final redraw counts all tapes.
            $this->assertStringContainsString((string) self::TAPE_COUNT . '/' . self::TAPE_COUNT, $err . $out);
        } finally {
            $this->removeTree($dir);
        }
    }

    public function testPerTapeStatusAggregateWithOneBadTape(): void
    {
        $dir = $this->makeFixture();
        // A tape that compiles to an empty event stream fails its render
        // (same no-op fixture shape as RenderBatchCollisionTest).
        file_put_contents($dir . '/bad.tape', "Set Theme \"TokyoNight\"\n");

        try {
            [$exit, $out] = $this->runBatch($dir, 4);

            $this->assertSame(1, $exit, 'one failed child must fail the batch');
            $this->assertStringContainsString('Succeeded: ' . self::TAPE_COUNT . ' | Failed: 1', $out);
            $this->assertStringContainsString('bad.tape', $out);
            // The aggregate is per tape: each good row keeps its own OK
            // verdict and the single bad row is the only FAIL.
            $report = $this->normalizeReport($out);
            $this->assertSame(
                self::TAPE_COUNT,
                preg_match_all('/t[0-7]\.tape\s+OK/', $report),
                'every good tape must print its own OK row',
            );
            $this->assertSame(1, preg_match_all('/bad\.tape\s+FAIL/', $report));
        } finally {
            $this->removeTree($dir);
        }
    }

    public function testNonPositiveJobsIsRefusedBeforeAnyRender(): void
    {
        $dir = $this->makeFixture();

        try {
            [$exit, $out] = $this->runBatch($dir, 0);

            $this->assertSame(1, $exit);
            $this->assertStringContainsString('--jobs must be a positive integer', $out);
            $this->assertFileDoesNotExist($dir . '/t0.gif', 'refusal must happen before the first render');
        } finally {
            $this->removeTree($dir);
        }
    }

    /**
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runBatch(string $dir, int $jobs, array $extraArgs = []): array
    {
        $process = new Process(array_merge([
            \PHP_BINARY,
            self::binPath(),
            'render-batch',
            $dir,
            '--encoder', 'php',
            '--backend', 'gd',
            '--jobs', (string) $jobs,
        ], $extraArgs));
        $process->setTimeout(300.0);
        $process->run();

        return [(int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
    }

    private static function binPath(): string
    {
        $bin = \dirname(__DIR__, 2) . '/bin/candy-vcr';
        self::assertFileExists($bin);

        return $bin;
    }

    private function makeFixture(): string
    {
        $dir = sys_get_temp_dir() . '/vcr-jobs-' . bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir, 0o777, true));
        for ($i = 0; $i < self::TAPE_COUNT; $i++) {
            file_put_contents(
                $dir . '/t' . $i . '.tape',
                "Set Theme \"TokyoNight\"\nSet Width 300\nSet Height 100\nType \"a{$i}\"\nEnter\nSleep 0.05\nType \"b\"\nEnter\n",
            );
        }

        return $dir;
    }

    /**
     * The deterministic part of the batch output: from the summary table
     * header down, with measured seconds replaced by a fixed token.
     */
    private function normalizeReport(string $out): string
    {
        $start = strpos($out, 'filename');
        $this->assertNotFalse($start, 'batch output must carry the summary table');
        $report = substr($out, $start);

        return preg_replace('/\d+\.\d{3}s/', 'TIME', $report) ?? $report;
    }

    private function removeTree(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
