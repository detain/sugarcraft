<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cli\Application;
use SugarCraft\Vcr\Tests\Support\Stream;

/**
 * Regression: `render-batch -r` reported per-file results keyed by
 * basename, so two same-named tapes in different subdirectories shared a
 * single verdict — the summary table printed one tape's status for both
 * rows. Results are keyed by the tape path and labelled relative to the
 * batch root.
 */
final class RenderBatchCollisionTest extends TestCase
{
    public function testSameBasenameTapesKeepSeparateVerdicts(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not available');
        }

        $root = $this->makeBatchRoot();

        try {
            // one/x.tape renders; two/x.tape compiles to an empty event
            // stream and fails. Identical basenames, opposite outcomes.
            file_put_contents(
                $root . '/one/x.tape',
                "Set Theme \"TokyoNight\"\nSet Width 700\nSet Height 240\nType \"x\"\nEnter\n",
            );
            file_put_contents($root . '/two/x.tape', "Set Theme \"TokyoNight\"\n");

            [$exit, $out] = $this->runBatch($root);
            // Row labels are root-relative paths in the host's own separator.
            $out = str_replace(DIRECTORY_SEPARATOR, '/', $out);

            $this->assertSame(1, $exit, 'one tape failed, so the batch must exit 1');
            $this->assertStringContainsString('one/x.tape', $out);
            $this->assertStringContainsString('two/x.tape', $out);
            $this->assertSame(
                1,
                substr_count($out, ' OK '),
                'exactly one tape succeeded; the other row must not inherit its verdict',
            );
            $this->assertSame(
                1,
                substr_count($out, 'FAIL'),
                'exactly one tape failed; the other row must not inherit its verdict',
            );
            $this->assertStringContainsString('Succeeded: 1 | Failed: 1', $out);
            $this->assertFileExists($root . '/one/x.gif');
        } finally {
            $this->removeTree($root);
        }
    }

    public function testSharedOutputDirRefusesTheSecondSameNamedTape(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not available');
        }

        $root = $this->makeBatchRoot();
        $outDir = $root . '/gif';
        if (!mkdir($outDir, 0700, true) && !is_dir($outDir)) {
            $this->fail("Failed to create output dir: {$outDir}");
        }

        try {
            $tape = "Set Theme \"TokyoNight\"\nSet Width 700\nSet Height 240\nType \"x\"\nEnter\n";
            file_put_contents($root . '/one/x.tape', $tape);
            file_put_contents($root . '/two/x.tape', $tape);

            [$exit, $out] = $this->runBatch($root, $outDir);

            // Both tapes would have written gif/x.gif; the second is refused
            // loudly rather than silently overwriting the first render.
            $this->assertSame(1, $exit);
            $this->assertStringContainsString('already claimed by', $out);
            $this->assertSame(1, substr_count($out, ' OK '));
            $gifCount = 0;
            foreach ((array) scandir($outDir) as $entry) {
                if (is_string($entry) && str_ends_with($entry, '.gif')) {
                    $gifCount++;
                }
            }
            $this->assertSame(1, $gifCount, 'only one GIF may be written into the shared output dir');
        } finally {
            $this->removeTree($root);
        }
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runBatch(string $root, ?string $outDir = null): array
    {
        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');
        $this->assertNotFalse($stdout);
        $this->assertNotFalse($stderr);

        $argv = ['candy-vcr', 'render-batch', $root, '-r', '--encoder', 'php', '--backend', 'gd'];
        if ($outDir !== null) {
            $argv[] = '-o';
            $argv[] = $outDir;
        }

        $exit = (new Application())->run($argv, $stdout, $stderr);

        rewind($stdout);

        return [$exit, (string) stream_get_contents($stdout)];
    }

    private function makeBatchRoot(): string
    {
        $root = sys_get_temp_dir() . '/candy-vcr-batch-collision-' . bin2hex(random_bytes(4));
        if (!mkdir($root . '/one', 0700, true) && !is_dir($root . '/one')) {
            $this->fail("Failed to create temp dir tree: {$root}/one");
        }
        if (!mkdir($root . '/two', 0700, true) && !is_dir($root . '/two')) {
            $this->fail("Failed to create temp dir tree: {$root}/two");
        }

        return $root;
    }

    private function removeTree(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $entry) {
            if ($entry instanceof \SplFileInfo) {
                $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
        }
        @rmdir($root);
    }
}
