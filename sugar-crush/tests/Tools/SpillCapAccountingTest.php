<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\ToolOutputSpill;
use SugarCraft\Crush\Tools\BuiltIn\Glob;
use SugarCraft\Crush\Tools\BuiltIn\Grep;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Two accounting holes in the roadmap 2.8 spill, both in
 * {@see \SugarCraft\Crush\Tools\Concerns\TruncatesOutput}:
 *
 *  - the pointer was charged at the length the TOOL emitted, but
 *    {@see ToolOutputSpill::forModel()} then moves the file into `s-<session>/`
 *    and rewrites the path, so a saturated result came back over its cap by
 *    up to the length of that directory name (SpillToFileTest's Grep measured
 *    65,553 bytes against 65,536 whenever the line clip left < 18 spare bytes);
 *  - Grep and Glob clip once at a floor to learn which paths the result shows,
 *    and that probe SAVED a file — orphaned whenever the output's size fell
 *    between the floor and the real cap, where the final clip keeps it whole.
 */
final class SpillCapAccountingTest extends TestCase
{
    private string $sandbox;
    private string $root;
    private string $store;

    protected function setUp(): void
    {
        $this->sandbox = (string) realpath(sys_get_temp_dir()) . '/sc_spillcap_' . getmypid() . '_' . bin2hex(random_bytes(6));
        $this->root = $this->sandbox . '/workspace';
        $this->store = $this->sandbox . '/store';
        self::assertTrue(mkdir($this->root, 0o755, true));
        ToolOutputSpill::useDirectoryForTesting($this->store);
    }

    protected function tearDown(): void
    {
        ToolOutputSpill::useDirectoryForTesting(null);
        self::removeTree($this->sandbox);
    }

    public function testASaturatedGrepResultStaysInsideItsCapAfterTheSessionMove(): void
    {
        // Ragged line lengths so the line clip leaves a different amount of
        // slack at each cap: somewhere in the sweep it leaves almost none,
        // which is where the post-hoc path rewrite used to overrun.
        $lines = [];
        for ($i = 1; $i <= 4000; $i++) {
            $lines[] = 'needle ' . $i . ' ' . str_repeat('x', ($i * 7) % 41);
        }
        file_put_contents($this->root . '/log.txt', implode("\n", $lines) . "\n");

        $longestSession = str_repeat('s', 100);
        $spilled = 0;
        for ($cap = ToolOutputSpill::MIN_CAP_BYTES; $cap < ToolOutputSpill::MIN_CAP_BYTES + 48; $cap++) {
            $result = (new Grep($this->root, $cap))->execute(['pattern' => 'needle', 'path' => '.']);
            $content = self::adopted($result, $longestSession)->content();

            self::assertLessThanOrEqual($cap, strlen($content), "cap $cap overran after the session move");
            $spilled += str_contains($content, '/s-' . $longestSession . '/out-') ? 1 : 0;
        }

        self::assertSame(48, $spilled, 'every cap in the sweep spilled, so every one was measured after the move');
    }

    public function testAGrepResultBetweenTheProbeFloorAndTheCapLeavesNoSpillFile(): void
    {
        $lines = [];
        for ($i = 1; $i <= 600; $i++) {
            $lines[] = 'needle line ' . $i;
        }
        file_put_contents($this->root . '/hits.txt', implode("\n", $lines) . "\n");

        $whole = (new Grep($this->root, 0))->execute(['pattern' => 'needle', 'path' => '.'])->content();
        $cap = strlen($whole) + 50;
        self::assertGreaterThanOrEqual(ToolOutputSpill::MIN_CAP_BYTES, $cap, 'the fixture must be big enough to spill');

        $content = (new Grep($this->root, $cap))->execute(['pattern' => 'needle', 'path' => '.'])->content();

        self::assertSame($whole, $content, 'under its cap the result is whole');
        self::assertSame([], $this->savedFiles(), 'the floor probe saved a file nothing names');
    }

    public function testAGlobResultBetweenTheProbeFloorAndTheCapLeavesNoSpillFile(): void
    {
        for ($i = 1; $i <= 400; $i++) {
            touch(sprintf('%s/a-reasonably-long-file-name-%04d.txt', $this->root, $i));
        }

        $whole = (new Glob($this->root, maxOutputBytes: 0))->execute(['pattern' => '*.txt', 'path' => '.'])->content();
        $cap = strlen($whole) + 50;
        self::assertGreaterThanOrEqual(ToolOutputSpill::MIN_CAP_BYTES, $cap, 'the fixture must be big enough to spill');

        $content = (new Glob($this->root, maxOutputBytes: $cap))->execute(['pattern' => '*.txt', 'path' => '.'])->content();

        self::assertSame($whole, $content, 'under its cap the result is whole');
        self::assertSame([], $this->savedFiles(), 'the floor probe saved a file nothing names');
    }

    public function testAGrepResultOverItsCapStillSavesExactlyOneFile(): void
    {
        $lines = [];
        for ($i = 1; $i <= 3000; $i++) {
            $lines[] = 'needle line ' . $i;
        }
        file_put_contents($this->root . '/hits.txt', implode("\n", $lines) . "\n");

        $content = (new Grep($this->root, ToolOutputSpill::MIN_CAP_BYTES))->execute(['pattern' => 'needle', 'path' => '.'])->content();

        self::assertSame(1, preg_match('/are in (\S+) — Read it/', $content, $m));
        self::assertSame([$m[1]], $this->savedFiles());
        self::assertStringContainsString("hits.txt:1500:needle line 1500\n", (string) file_get_contents($m[1]));
    }

    private static function adopted(ToolResult $result, string $sessionId): ToolResult
    {
        return ToolOutputSpill::forModel($result, 'Grep', [], $sessionId, static fn (): int => 0);
    }

    /** @return list<string> */
    private function savedFiles(): array
    {
        $found = array_merge(
            glob($this->store . '/out-*.txt') ?: [],
            glob($this->store . '/s-*/out-*.txt') ?: [],
        );
        sort($found);

        return $found;
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
