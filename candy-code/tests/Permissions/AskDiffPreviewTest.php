<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Permissions\AskDiffPreview;
use SugarCraft\Code\Tools\BuiltIn\Edit;

/**
 * C-SEC-2.b: the server-computed ask preview. Three laws, three groups of
 * arms — the diff is the tool's own (real file line numbers), a call that
 * would touch anything outside the session root gets NOTHING (never a
 * partial consent surface), and the wire never carries more than the bound,
 * replaced past it by an omitted note that still says how big the change is.
 */
final class AskDiffPreviewTest extends TestCase
{
    /** @var list<string> */
    private array $scratchDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->scratchDirs as $dir) {
            self::pruneScratch($dir);
        }
        $this->scratchDirs = [];
    }

    public function testTheEditPreviewIsTheToolOwnsWithRealHunkPositions(): void
    {
        $root = $this->scratchDir();
        file_put_contents($root . '/f.txt', self::nineLines('target', 6));

        $args = ['file_path' => 'f.txt', 'old_string' => "target\n", 'new_string' => "TARGET\n"];
        $diff = AskDiffPreview::for('Edit', $args, $root);

        self::assertNotNull($diff);
        // Line 6 changes with 3 context lines before it: a snippet-relative
        // mint (the client's old answer) starts its only hunk at -1.
        self::assertStringContainsString('@@ -3,', $diff, 'file-absolute hunk header');
        self::assertStringContainsString('-target', $diff);
        self::assertStringContainsString('+TARGET', $diff);
        self::assertSame(Edit::previewDiff($root, $args), $diff, 'delegated, not re-derived');
    }

    public function testABatchedEditPreviewsEveryHunkNotJustTheFirst(): void
    {
        $root = $this->scratchDir();
        // 18 lines apart: the context windows cannot merge, so a one-hunk
        // (truncated) preview would show here as a count of 1.
        $lines = array_map(static fn (int $i): string => "l$i", range(1, 20));
        $lines[1] = 'alpha';
        $lines[17] = 'omega';
        file_put_contents($root . '/f.txt', implode("\n", $lines) . "\n");

        $diff = AskDiffPreview::for('Edit', [
            'file_path' => 'f.txt',
            'old_string' => "alpha\n",
            'new_string' => "ALPHA\n",
            'edits' => [['old_string' => "omega\n", 'new_string' => "OMEGA\n"]],
        ], $root);

        self::assertNotNull($diff);
        self::assertGreaterThanOrEqual(2, substr_count($diff, '@@ -'), 'both hunks ride the preview');
        self::assertStringContainsString('+ALPHA', $diff);
        self::assertStringContainsString('+OMEGA', $diff);
    }

    public function testAWriteOfANotYetExistingFilePreviewsItsWholeBody(): void
    {
        $root = $this->scratchDir();

        $diff = AskDiffPreview::for('Write', ['file_path' => 'new/dir/x.txt', 'content' => "hello\n"], $root);

        self::assertNotNull($diff);
        self::assertStringContainsString('+hello', $diff);
    }

    public function testACallTargetingOutsideTheRootGetsNoPreviewAtAll(): void
    {
        $root = $this->scratchDir();
        $outside = $this->scratchDir();
        file_put_contents($outside . '/f.txt', self::nineLines('secret', 6));

        $absolute = AskDiffPreview::for('Edit', [
            'file_path' => $outside . '/f.txt',
            'old_string' => "secret\n",
            'new_string' => "EXPOSED\n",
        ], $root);
        $traversal = AskDiffPreview::for('Edit', [
            'file_path' => '../' . basename($outside) . '/f.txt',
            'old_string' => "secret\n",
            'new_string' => "EXPOSED\n",
        ], $root);

        self::assertNull($absolute);
        self::assertNull($traversal);
    }

    public function testAWriteEscapingThroughMissingDirectoriesIsRefusedToo(): void
    {
        $root = $this->scratchDir();

        self::assertNull(AskDiffPreview::for('Write', ['file_path' => '../../cc-sec2b-escape/x.txt', 'content' => "x\n"], $root));
    }

    public function testNoRootMeansNoPreview(): void
    {
        $root = $this->scratchDir();
        file_put_contents($root . '/f.txt', self::nineLines('target', 6));
        $args = ['file_path' => 'f.txt', 'old_string' => "target\n", 'new_string' => "TARGET\n"];

        self::assertNull(AskDiffPreview::for('Edit', $args, null));
        self::assertNull(AskDiffPreview::for('Edit', $args, ''));
    }

    public function testTheApplyPatchPreviewNamesRealPositions(): void
    {
        $root = $this->scratchDir();
        file_put_contents($root . '/f.txt', self::nineLines('target', 6));

        $diff = AskDiffPreview::for('ApplyPatch', ['patch' => self::twoFilePatch('target', 'TARGET')], $root);

        self::assertNotNull($diff);
        self::assertStringContainsString('@@ -3,', $diff, 'the Update section diffs against the real file');
        self::assertStringContainsString('+brand-new', $diff, 'the Add section rides too');
    }

    public function testAnApplyPatchWithAnyEscapingOperationYieldsNothing(): void
    {
        $root = $this->scratchDir();
        $outside = $this->scratchDir();
        file_put_contents($root . '/f.txt', self::nineLines('target', 6));
        file_put_contents($outside . '/g.txt', self::nineLines('other', 6));

        $patch = "*** Begin Patch\n"
            . "*** Update File: f.txt\n@@\n-target\n+TARGET\n"
            . "*** Update File: ../" . basename($outside) . "/g.txt\n@@\n-other\n+OTHER\n"
            . "*** End Patch\n";

        self::assertNull(AskDiffPreview::for('ApplyPatch', ['patch' => $patch], $root));
    }

    public function testAPatchThatWillNotLandFallsBackToItsRawText(): void
    {
        $root = $this->scratchDir();
        file_put_contents($root . '/f.txt', self::nineLines('target', 6));

        // Parses cleanly, but the hunk cannot land (no ghost line) and the
        // section's file does not exist either — ApplyPatch::previewDiff
        // answers null; the approver still gets the patch text it can read.
        $patch = "*** Begin Patch\n"
            . "*** Update File: f.txt\n@@\n-ghost\n+TARGET\n"
            . "*** End Patch\n";

        self::assertSame($patch, AskDiffPreview::for('ApplyPatch', ['patch' => $patch], $root));
    }

    public function testAnUnparseablePatchFallsBackToItsRawText(): void
    {
        $root = $this->scratchDir();

        self::assertSame('this is not the patch grammar', AskDiffPreview::for('ApplyPatch', ['patch' => 'this is not the patch grammar'], $root));
        self::assertNull(AskDiffPreview::for('ApplyPatch', ['patch' => ''], $root));
        self::assertNull(AskDiffPreview::for('ApplyPatch', [], $root));
    }

    public function testAFallbackPastTheWireCapBecomesTheOmittedNote(): void
    {
        $root = $this->scratchDir();
        $huge = str_repeat("aaaa\n", 8000); // 40,000 bytes, no patch grammar

        $note = AskDiffPreview::for('ApplyPatch', ['patch' => $huge], $root);

        self::assertNotNull($note);
        self::assertLessThan(strlen($huge), strlen($note), 'the raw text never rides');
        self::assertMatchesRegularExpression(
            '/\ADiff preview omitted: 40000 bytes over the 32768-byte wire bound \(changed region: \+0 -0 lines\)\.\z/',
            $note,
        );
    }

    public function testAPreviewPastTheWireCapBecomesTheOmittedNote(): void
    {
        $root = $this->scratchDir();
        $body = str_repeat("bbbb\n", 8000);

        $note = AskDiffPreview::for('Write', ['file_path' => 'big.txt', 'content' => $body], $root);

        self::assertNotNull($note);
        self::assertMatchesRegularExpression(
            '/\ADiff preview omitted: \d+ bytes over the 32768-byte wire bound \(changed region: \+8000 -0 lines\)\.\z/',
            $note,
        );
    }

    public function testACallWithNothingToPreviewYieldsNothing(): void
    {
        $root = $this->scratchDir();
        file_put_contents($root . '/f.txt', self::nineLines('target', 6));

        self::assertNull(AskDiffPreview::for('Bash', ['command' => 'ls'], $root));
        self::assertNull(AskDiffPreview::for('Edit', ['old_string' => "target\n", 'new_string' => "T\n"], $root), 'no file_path is nothing');
        self::assertNull(
            AskDiffPreview::for('Edit', ['file_path' => 'f.txt', 'old_string' => "absent\n", 'new_string' => "T\n"], $root),
            'an edit the matcher would refuse previews nothing',
        );
        self::assertNull(
            AskDiffPreview::for('Edit', ['file_path' => 'ghost.txt', 'old_string' => "x\n", 'new_string' => "y\n"], $root),
            'Edit cannot create a file that is not there',
        );
    }

    /** A nine-line file with $word on line $at (and a second pair when given). */
    private static function nineLines(string $word, int $at, ?string $second = null, ?int $secondAt = null): string
    {
        $lines = ['l1', 'l2', 'l3', 'l4', 'l5', 'l6', 'l7', 'l8', 'l9'];
        $lines[$at - 1] = $word;
        if ($second !== null && $secondAt !== null) {
            $lines[$secondAt - 1] = $second;
        }

        return implode("\n", $lines) . "\n";
    }

    private static function twoFilePatch(string $find, string $replace): string
    {
        return "*** Begin Patch\n"
            . "*** Update File: f.txt\n@@\n-{$find}\n+{$replace}\n"
            . "*** Add File: brand.txt\n+brand-new\n*** End Patch\n";
    }

    private function scratchDir(): string
    {
        $dir = \sys_get_temp_dir() . '/cc-sec2b-' . uniqid('p', true);
        self::assertNotFalse(mkdir($dir, 0700, true));
        $this->scratchDirs[] = $dir;

        return $dir;
    }

    private static function pruneScratch(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $dir . '/' . $entry;
            if (is_dir($child)) {
                self::pruneScratch($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($dir);
    }
}
