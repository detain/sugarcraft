<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\PrivateRetainedDir;
use SugarCraft\Crush\Support\ToolOutputSpill;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap 2.8: the store a tool result's overflow is saved to — owner-only on
 * disk, refused rather than written through when planted, swept after seven
 * days, and readable back only by the exact paths it hands out.
 *
 * Every test points the store at a private sandbox through
 * {@see ToolOutputSpill::useDirectoryForTesting()}, so nothing here touches the
 * real per-user store and a planted entry cannot leak into a sibling test.
 */
final class ToolOutputSpillTest extends TestCase
{
    private string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/sc_spill_' . getmypid() . '_' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->sandbox, 0o700, true));
        ToolOutputSpill::useDirectoryForTesting($this->sandbox . '/store');
    }

    protected function tearDown(): void
    {
        ToolOutputSpill::useDirectoryForTesting(null);
        self::removeTree($this->sandbox);
    }

    public function testAStoredFileIsOwnerOnlyInsideAnOwnerOnlyDirectory(): void
    {
        $path = ToolOutputSpill::store(str_repeat("line\n", 5000));

        self::assertIsString($path);
        clearstatcache();
        self::assertSame(0o600, fileperms($path) & 0o777, 'the saved output is readable by other users');
        self::assertSame(0o700, fileperms($this->sandbox . '/store') & 0o777, 'the store was not created owner-only');
        self::assertSame(str_repeat("line\n", 5000), file_get_contents($path));
        self::assertSame([], glob($this->sandbox . '/store/*.partial') ?: [], 'a write left its intermediate behind');
    }

    /**
     * Bash captures past its cap when a spill can keep the overflow, so the
     * saved file holds the WHOLE output — the real middle of a large log —
     * while the result itself stays within the cap.
     */
    public function testAnOverflowingBashResultSavesItsWholeOutput(): void
    {
        $cap = ToolOutputSpill::MIN_CAP_BYTES * 2;
        $result = (new \SugarCraft\Crush\Tools\BuiltIn\Bash(maxOutputBytes: $cap))->execute([
            'command' => 'for i in $(seq 1 10000); do echo "line-$i-padding"; done',
            'id' => 'call_spill',
        ]);

        $expected = '';
        for ($i = 1; $i <= 10000; ++$i) {
            $expected .= "line-{$i}-padding\n";
        }

        self::assertFalse($result->isError(), $result->content());
        self::assertSame(
            1,
            preg_match('/\[saved: the (\d+) bytes this tool captured are in (\S+) /', $result->content(), $m),
            $result->content(),
        );
        self::assertStringNotContainsString('were never captured', $result->content());
        self::assertSame(strlen(rtrim($expected, "\n")), (int) $m[1]);
        self::assertSame(rtrim($expected, "\n"), rtrim((string) file_get_contents($m[2]), "\n"));
        self::assertStringNotContainsString('line-5000-padding', $result->content(), 'the middle is only in the file');
        self::assertLessThan($cap + 1024, strlen($result->content()));
    }

    public function testTheSameTextStoredTwiceInOneProcessIsOneFile(): void
    {
        $first = ToolOutputSpill::store('same bytes');
        $second = ToolOutputSpill::store('same bytes');

        self::assertSame($first, $second, 'a probe clip and the final clip of one call must share one file');
        self::assertNotSame($first, ToolOutputSpill::store('other bytes'));
    }

    public function testASymlinkAtTheStoreIsRefusedAndNothingIsWrittenThroughIt(): void
    {
        $victim = $this->sandbox . '/victim';
        self::assertTrue(mkdir($victim, 0o700));
        self::assertTrue(symlink($victim, $this->sandbox . '/store'));

        self::assertNull(ToolOutputSpill::store('secret build output'));
        self::assertSame(['.', '..'], scandir($victim), 'the spill wrote through the planted link');
        self::assertTrue(is_link($this->sandbox . '/store'), 'the refusal removed the link it refused');
    }

    public function testALooseStoreIsRefusedForWritingAndForReading(): void
    {
        $path = ToolOutputSpill::store('kept');
        self::assertIsString($path);
        self::assertSame(realpath($path), ToolOutputSpill::readablePath($path));

        self::assertTrue(chmod($this->sandbox . '/store', 0o755));

        self::assertNull(ToolOutputSpill::store('more'), 'a store other users can enter was written into');
        self::assertNull(ToolOutputSpill::readablePath($path), 'a store other users can enter was trusted for reading');
    }

    public function testOnlySpillFilesInTheStoreAreReadable(): void
    {
        $path = (string) ToolOutputSpill::store('payload');
        $store = $this->sandbox . '/store';

        file_put_contents($store . '/notes.txt', 'not a spill');
        file_put_contents($store . '/out-' . str_repeat('a', 32) . '.txt.partial', 'torn');
        $outside = $this->sandbox . '/out-' . str_repeat('b', 32) . '.txt';
        file_put_contents($outside, 'outside the store');
        $link = $store . '/out-' . str_repeat('c', 32) . '.txt';
        self::assertTrue(symlink($outside, $link));
        mkdir($store . '/s-deep/nested', 0o700, true);
        $deep = $store . '/s-deep/nested/out-' . str_repeat('d', 32) . '.txt';
        file_put_contents($deep, 'two levels down');

        self::assertSame(realpath($path), ToolOutputSpill::readablePath($path));
        self::assertNull(ToolOutputSpill::readablePath($store . '/notes.txt'), 'a file not named like a spill');
        self::assertNull(ToolOutputSpill::readablePath($store . '/out-' . str_repeat('a', 32) . '.txt.partial'));
        self::assertNull(ToolOutputSpill::readablePath($outside), 'a spill-shaped name outside the store');
        self::assertNull(ToolOutputSpill::readablePath($link), 'a link in the store that resolves outside it');
        self::assertNull(ToolOutputSpill::readablePath($deep), 'deeper than one session directory');
        self::assertNull(ToolOutputSpill::readablePath($store), 'the store directory itself');
        self::assertNull(ToolOutputSpill::readablePath("{$path}\0"));
    }

    public function testRetentionSweepsOnlyFilesOlderThanSevenDays(): void
    {
        $store = $this->sandbox . '/store';
        PrivateRetainedDir::verified($store, 'test');
        $session = $store . '/s-old';
        PrivateRetainedDir::verified($session, 'test');

        $now = time();
        $stale = $store . '/out-' . str_repeat('1', 32) . '.txt';
        $fresh = $store . '/out-' . str_repeat('2', 32) . '.txt';
        $staleInSession = $session . '/out-' . str_repeat('3', 32) . '.txt';
        foreach ([$stale, $fresh, $staleInSession] as $file) {
            file_put_contents($file, 'x');
        }
        touch($stale, $now - ToolOutputSpill::RETENTION_SECONDS - 60);
        touch($staleInSession, $now - ToolOutputSpill::RETENTION_SECONDS - 60);
        touch($fresh, $now - ToolOutputSpill::RETENTION_SECONDS + 3600);

        // A link in the store is never followed, and never removed, by the sweep.
        $target = $this->sandbox . '/linked-target';
        file_put_contents($target, 'must survive');
        touch($target, $now - ToolOutputSpill::RETENTION_SECONDS - 60);
        symlink($target, $store . '/out-' . str_repeat('4', 32) . '.txt');

        $removed = PrivateRetainedDir::sweep($store, ToolOutputSpill::RETENTION_SECONDS, 'test', $now);

        self::assertSame(2, $removed);
        self::assertFileDoesNotExist($stale);
        self::assertFileDoesNotExist($staleInSession);
        self::assertDirectoryDoesNotExist($session, 'an emptied session directory is reclaimed');
        self::assertFileExists($fresh);
        self::assertFileExists($target);
    }

    public function testTheFirstSpillOfAProcessSweepsTheStore(): void
    {
        $store = $this->sandbox . '/store';
        PrivateRetainedDir::verified($store, 'test');
        $stale = $store . '/out-' . str_repeat('9', 32) . '.txt';
        file_put_contents($stale, 'old');
        touch($stale, time() - ToolOutputSpill::RETENTION_SECONDS - 60);

        ToolOutputSpill::store('new output');

        self::assertFileDoesNotExist($stale, 'the lazy launch sweep never ran');
    }

    public function testAResultOverTheWindowShareIsPreviewedHeadAndTailAndSavedPerSession(): void
    {
        $lines = [];
        for ($i = 1; $i <= 2000; $i++) {
            $lines[] = sprintf('row %04d of the listing', $i);
        }
        $text = implode("\n", $lines);

        $out = ToolOutputSpill::forModel(
            new ToolResult('c1', $text),
            'WebSearch',
            [],
            'sess_20261003000000_abcdef12',
            static fn (): int => 8192,
        );

        $content = $out->content();
        // 30% of an 8,192-token window is 2,457 tokens, previewed at 3 bytes each.
        self::assertLessThanOrEqual(2457 * 3, strlen($content));
        self::assertStringStartsWith('row 0001', $content);
        self::assertStringContainsString('row 2000', $content, 'the tail of the output is part of the preview');
        self::assertStringContainsString('... [middle omitted]', $content);
        self::assertSame(1, preg_match('/saved: the (\d+) bytes this tool captured are in (\S+) — Read it with offset\/limit/', $content, $m));
        self::assertSame(strlen($text), (int) $m[1]);
        self::assertSame($this->sandbox . '/store/s-sess_20261003000000_abcdef12', \dirname($m[2]), 'the saved file is not session-scoped');
        self::assertSame($text, file_get_contents($m[2]));
        self::assertSame(realpath($m[2]), ToolOutputSpill::readablePath($m[2]));
    }

    public function testATaskOrSkillResultIsNeverSpilled(): void
    {
        $text = str_repeat("the sub-agent's whole answer\n", 4000);

        foreach (['Task', 'Skill'] as $tool) {
            $out = ToolOutputSpill::forModel(new ToolResult('c', $text), $tool, [], 'sess', static fn (): int => 4096);
            self::assertSame($text, $out->content(), "$tool's result IS the answer");
        }
    }

    public function testAResultUnderTheShareIsUntouchedAndTheWindowIsNotAskedForSmallOnes(): void
    {
        $asked = 0;
        $window = static function () use (&$asked): int {
            $asked++;

            return 200_000;
        };

        $small = new ToolResult('c', 'tiny');
        self::assertSame($small, ToolOutputSpill::forModel($small, 'Bash', [], 'sess', $window));
        self::assertSame(0, $asked, 'a short result must not cost a provider lookup');

        $medium = new ToolResult('c', str_repeat('x', 50_000));
        self::assertSame($medium, ToolOutputSpill::forModel($medium, 'Bash', [], 'sess', $window));
    }

    public function testReadingASpillFileIsBoundedWithoutSavingItAgain(): void
    {
        $saved = (string) ToolOutputSpill::store(str_repeat("saved line\n", 3000));
        $before = glob($this->sandbox . '/store/out-*.txt') ?: [];

        $out = ToolOutputSpill::forModel(
            new ToolResult('c', str_repeat("saved line\n", 3000)),
            'Read',
            ['file_path' => $saved],
            '',
            static fn (): int => 8192,
        );

        self::assertStringContainsString('window cap:', $out->content());
        self::assertStringContainsString('ask for less (a smaller Read limit', $out->content());
        self::assertStringNotContainsString('... [saved:', $out->content());
        self::assertSame($before, glob($this->sandbox . '/store/out-*.txt') ?: [], 'paging a saved file saved it again');
    }

    public function testAToolSidePointerIsMovedIntoTheSessionDirectory(): void
    {
        $pending = (string) ToolOutputSpill::store('overflow written by a forked tool');
        self::assertSame($this->sandbox . '/store', \dirname($pending));

        $result = new ToolResult('c', "preview\n" . ToolOutputSpill::pointer($pending, 33));
        $out = ToolOutputSpill::forModel($result, 'Bash', [], 'sess_x', static fn (): int => 100_000);

        $moved = $this->sandbox . '/store/s-sess_x/' . basename($pending);
        self::assertStringContainsString($moved, $out->content());
        self::assertStringNotContainsString($pending . ' ', $out->content());
        self::assertFileExists($moved);
        self::assertFileDoesNotExist($pending);
        self::assertSame(0o700, fileperms(\dirname($moved)) & 0o777);
    }

    public function testASessionIdIsNeverAPath(): void
    {
        self::assertSame('s-sess_1-a', ToolOutputSpill::sessionDirName('sess_1-a'));
        self::assertMatchesRegularExpression('/^s-[0-9a-f]{32}$/', ToolOutputSpill::sessionDirName('../../etc'));
        self::assertMatchesRegularExpression('/^s-[0-9a-f]{32}$/', ToolOutputSpill::sessionDirName(''));
    }

    public function testTheTailIsCutOnALineBoundaryAndNeverInsideACharacter(): void
    {
        self::assertSame("ccc\n", ToolOutputSpill::tailOf("aaa\nbbb\nccc\n", 5));
        self::assertSame('', ToolOutputSpill::tailOf('abc', 0));
        $tail = ToolOutputSpill::tailOf(str_repeat("\u{2014}", 10), 5);
        self::assertTrue(mb_check_encoding($tail, 'UTF-8'));
        self::assertSame("\u{2014}", $tail);
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
        @chmod($path, 0o700);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
