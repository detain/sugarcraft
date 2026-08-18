<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Tools\BuiltIn\Edit;

/**
 * @see Edit
 */
final class EditTest extends TestCase
{
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];
    }

    // =========================================================================
    // Diff generation - the failure mode being fixed: old code returned only
    // "File updated: $path" with zero before/after visibility. The diff now
    // rides ToolResult::diff() as a standalone field (crush_feat.md §1 E3)
    // rather than being concatenated into the free-text content summary.
    // =========================================================================

    public function testExecuteIncludesUnifiedDiffMarkersOnSuccess(): void
    {
        $tool = new Edit();
        $path = $this->createTempFile('Hello World');

        $result = $tool->execute([
            'id' => 'call_1',
            'file_path' => $path,
            'old_string' => 'World',
            'new_string' => 'PHP',
        ]);

        $this->assertFalse($result->isError());
        // These assertions fail against the pre-diff implementation, which
        // returned the bare string "File updated: $path" with none of this.
        $this->assertTrue($result->hasDiff());
        $this->assertStringContainsString('--- a/', (string)$result->diff());
        $this->assertStringContainsString('+++ b/', (string)$result->diff());
        $this->assertStringContainsString('@@', (string)$result->diff());
    }

    public function testExecuteKeepsContentAsCleanSummaryWithoutTheDiffBlob(): void
    {
        $tool = new Edit();
        $path = $this->createTempFile('Hello World');

        $result = $tool->execute([
            'id' => 'call_summary',
            'file_path' => $path,
            'old_string' => 'World',
            'new_string' => 'PHP',
        ]);

        // Exact, not a prefix check: the tally is now part of the summary
        // (it is the only account of the edit's size the MODEL ever sees --
        // ToolResult::$diff never reaches the conversation), and the diff blob
        // still must not be in there.
        $this->assertSame("File updated: $path (+1 -1 lines)", $result->content());
        $this->assertStringNotContainsString('@@', $result->content());
        $this->assertStringNotContainsString('Hello PHP', $result->content(), 'the new text is not echoed back');
    }

    public function testExecuteDiffShowsExactBeforeAfterLineChange(): void
    {
        $tool = new Edit();
        $path = $this->createTempFile('Hello World');

        $result = $tool->execute([
            'id' => 'call_2',
            'file_path' => $path,
            'old_string' => 'World',
            'new_string' => 'PHP',
        ]);

        // Byte-exact and prefix-free: this is what DiffViewer::fromRawDiff()
        // consumes, so a stray "File updated:" header would break it.
        $expected = "--- a/$path\n"
            . "+++ b/$path\n"
            . "@@ -1,1 +1,1 @@\n"
            . "-Hello World\n"
            . "+Hello PHP\n";

        $this->assertSame($expected, $result->diff());
    }

    public function testExecuteDiffIncludesUnchangedContextLines(): void
    {
        $tool = new Edit();
        $original = "line1\nline2\nline3\nline4\nline5\n";
        $path = $this->createTempFile($original);

        $result = $tool->execute([
            'id' => 'call_3',
            'file_path' => $path,
            'old_string' => 'line3',
            'new_string' => 'LINE3-CHANGED',
        ]);

        $content = (string)$result->diff();
        $this->assertStringContainsString("@@ -1,5 +1,5 @@\n", $content);
        $this->assertStringContainsString(" line1\n", $content);
        $this->assertStringContainsString(" line2\n", $content);
        $this->assertStringContainsString("-line3\n", $content);
        $this->assertStringContainsString("+LINE3-CHANGED\n", $content);
        $this->assertStringContainsString(" line4\n", $content);
        $this->assertStringContainsString(" line5\n", $content);
    }

    public function testExecuteDiffSplitsFarApartChangesIntoSeparateHunks(): void
    {
        $lines = [];
        for ($i = 1; $i <= 20; $i++) {
            $lines[] = $i === 2 || $i === 18 ? 'TARGET' : "line$i";
        }
        $original = implode("\n", $lines) . "\n";
        $path = $this->createTempFile($original);

        $tool = new Edit();
        $result = $tool->execute([
            'id' => 'call_4',
            'file_path' => $path,
            'old_string' => 'TARGET',
            'new_string' => 'REPLACED',
            'replace_all' => true,
        ]);

        $this->assertFalse($result->isError());
        // Each hunk header is "@@ -x,y +a,b @@" - two "@@" tokens per hunk -
        // so count the leading "@@ -" marker to get the hunk count itself.
        $this->assertSame(2, substr_count((string)$result->diff(), '@@ -'));
        $this->assertSame(2, substr_count((string)$result->diff(), '-TARGET'));
        $this->assertSame(2, substr_count((string)$result->diff(), '+REPLACED'));
    }

    public function testExecuteReplacesFileContentsOnDiskAlongsideTheDiff(): void
    {
        $tool = new Edit();
        $path = $this->createTempFile('Hello World');

        $tool->execute([
            'id' => 'call_5',
            'file_path' => $path,
            'old_string' => 'World',
            'new_string' => 'PHP',
        ]);

        $this->assertSame('Hello PHP', file_get_contents($path));
    }

    public function testExecuteFailsAndLeavesFileUntouchedWhenOldStringNotFound(): void
    {
        // A zero-match edit used to str_replace() (a no-op), rewrite the file
        // with identical bytes and report isError:false / "File updated" - a
        // false claim of success that hides a mis-specified old_string from
        // the model. It must be a real error, with the file untouched.
        $tool = new Edit();
        $path = $this->createTempFile('Hello World');
        $mtimeBefore = filemtime($path);

        $result = $tool->execute([
            'id' => 'call_6',
            'file_path' => $path,
            'old_string' => 'NonExistentString',
            'new_string' => 'Replaced',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('old_string not found', $result->content());
        $this->assertStringContainsString('left unchanged', $result->content());
        $this->assertNull($result->diff());
        $this->assertSame('Hello World', file_get_contents($path));
        clearstatcache(true, $path);
        $this->assertSame($mtimeBefore, filemtime($path));
    }

    public function testExecuteStillReturnsErrorForEmptyOldStringWithNoDiff(): void
    {
        $tool = new Edit();
        $path = $this->createTempFile('content');

        $result = $tool->execute([
            'id' => 'call_7',
            'file_path' => $path,
            'old_string' => '',
            'new_string' => 'new',
        ]);

        $this->assertTrue($result->isError());
        $this->assertNull($result->diff());
    }

    public function testExecuteStillReturnsErrorForNonexistentFileWithNoDiff(): void
    {
        $tool = new Edit();

        $result = $tool->execute([
            'id' => 'call_8',
            'file_path' => '/nonexistent/file.txt',
            'old_string' => 'old',
            'new_string' => 'new',
        ]);

        $this->assertTrue($result->isError());
        $this->assertNull($result->diff());
    }

    public function testExecuteDiffHandlesMultiLineInsertion(): void
    {
        $tool = new Edit();
        $path = $this->createTempFile("start\nmiddle\nend\n");

        $result = $tool->execute([
            'id' => 'call_9',
            'file_path' => $path,
            'old_string' => 'middle',
            'new_string' => "middle\nextra1\nextra2",
        ]);

        $content = (string)$result->diff();
        // "middle" itself is unchanged (common prefix) - a minimal diff
        // shows only the two inserted lines, not a spurious delete/re-add
        // of the line the insertion happened after.
        $this->assertStringContainsString(' middle', $content);
        $this->assertStringNotContainsString('-middle', $content);
        $this->assertStringContainsString('+extra1', $content);
        $this->assertStringContainsString('+extra2', $content);
    }

    public function testExecuteDiffMergesHunksSeparatedByExactlyContextWindowPlusOne(): void
    {
        // Boundary regression for the buildHunks() merge threshold: two
        // single-line changes with exactly 6 unchanged lines between them
        // (op-index distance 2*CONTEXT_LINES+1 = 7) is the point at which
        // real `diff -u`/`git diff` still joins them into ONE hunk with
        // shared context, since the two hunks' 3-line context windows
        // exactly touch.
        $lines = [];
        for ($i = 1; $i <= 12; $i++) {
            $lines[] = $i === 2 || $i === 9 ? 'TARGET' : "line$i";
        }
        $original = implode("\n", $lines) . "\n";
        $path = $this->createTempFile($original);

        $tool = new Edit();
        $result = $tool->execute([
            'id' => 'call_merge_boundary',
            'file_path' => $path,
            'old_string' => 'TARGET',
            'new_string' => 'REPLACED',
            'replace_all' => true,
        ]);

        $this->assertFalse($result->isError());
        $this->assertSame(1, substr_count((string)$result->diff(), '@@ -'));
        $this->assertSame(2, substr_count((string)$result->diff(), '-TARGET'));
        $this->assertSame(2, substr_count((string)$result->diff(), '+REPLACED'));
    }

    public function testExecuteDiffSplitsHunksSeparatedByOneMoreThanContextWindow(): void
    {
        // One unchanged line further apart than the merge boundary above
        // (op-index distance 2*CONTEXT_LINES+2 = 8) must stay as two
        // separate hunks, since the context windows no longer touch.
        $lines = [];
        for ($i = 1; $i <= 13; $i++) {
            $lines[] = $i === 2 || $i === 10 ? 'TARGET' : "line$i";
        }
        $original = implode("\n", $lines) . "\n";
        $path = $this->createTempFile($original);

        $tool = new Edit();
        $result = $tool->execute([
            'id' => 'call_split_boundary',
            'file_path' => $path,
            'old_string' => 'TARGET',
            'new_string' => 'REPLACED',
            'replace_all' => true,
        ]);

        $this->assertFalse($result->isError());
        $this->assertSame(2, substr_count((string)$result->diff(), '@@ -'));
        $this->assertSame(2, substr_count((string)$result->diff(), '-TARGET'));
        $this->assertSame(2, substr_count((string)$result->diff(), '+REPLACED'));
    }

    public function testExecuteDiffUsesZeroForNewSideWhenDeletionEmptiesTheFile(): void
    {
        // `diff -u` reports a start line of 0 (not the last old line number)
        // when the new side of a hunk is empty, since there's no line to
        // anchor to. Deleting a single-line file's only content is the
        // reachable-through-execute() case; the symmetric zero-old-side
        // case (pure insertion with no surrounding context) is exercised
        // directly against buildHunks() below since it isn't reachable
        // through the public Edit API (old_string can never match inside
        // an empty file).
        $tool = new Edit();
        $path = $this->createTempFile("onlyline\n");

        $result = $tool->execute([
            'id' => 'call_zero_new_side',
            'file_path' => $path,
            'old_string' => "onlyline\n",
            'new_string' => '',
        ]);

        $this->assertFalse($result->isError());
        $this->assertStringContainsString("@@ -1,1 +0,0 @@\n", (string)$result->diff());
        $this->assertStringNotContainsString('+1,0', (string)$result->diff());
    }

    public function testBuildHunksUsesZeroForOldSideOnPureInsertionWithNoContext(): void
    {
        $method = new \ReflectionMethod(Edit::class, 'buildHunks');
        $method->setAccessible(true);

        // A hunk whose only op is a single insertion has zero old-side
        // lines (no eq/del ops at all), so the header's old start must be
        // 0 per `diff -u` convention, not a fabricated line number.
        $ops = [['ins', 'new line']];

        $hunks = $method->invoke(null, $ops);

        $this->assertStringContainsString("@@ -0,0 +1,1 @@\n", $hunks);
    }

    private function createTempFile(string $content): string
    {
        $path = sys_get_temp_dir() . '/edit_test_' . uniqid('', true) . '.txt';
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    // =========================================================================
    // Nested instruction files must never leak into the persisted file.
    // =========================================================================

    public function testExecuteWithNestedInstructionLoaderDoesNotCorruptFileOnDisk(): void
    {
        $root = sys_get_temp_dir() . '/edit_test_root_' . uniqid('', true);
        $sub = $root . '/sub';
        mkdir($sub, 0777, true);

        file_put_contents($sub . '/CLAUDE.md', 'PROJECT RULES: do not do X.');
        $targetPath = $sub . '/target.txt';
        file_put_contents($targetPath, 'Hello World');

        $loader = new InstructionFileLoader($root);
        $tool = new Edit(root: $root, instructionLoader: $loader);

        $result = $tool->execute([
            'id' => 'call_nested',
            'file_path' => 'sub/target.txt',
            'old_string' => 'World',
            'new_string' => 'PHP',
        ]);

        $this->assertFalse($result->isError());

        $onDisk = file_get_contents($targetPath);
        $this->assertSame('Hello PHP', $onDisk);
        $this->assertStringNotContainsString('PROJECT RULES', $onDisk);

        unlink($sub . '/CLAUDE.md');
        unlink($targetPath);
        rmdir($sub);
        rmdir($root);
    }
}
