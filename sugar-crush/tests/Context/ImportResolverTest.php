<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\ImportResolver;

/**
 * Tests for ImportResolver — covers new(), expand()'s path resolution,
 * recursion, depth cap, and code-span skipping.
 */
final class ImportResolverTest extends TestCase
{
    private string $tempDir;
    private ?string $originalHome = null;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/import_resolver_test_' . uniqid((string) getmypid(), true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        if ($this->originalHome !== null) {
            putenv('HOME=' . $this->originalHome);
            $this->originalHome = null;
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function write(string $relativePath, string $content): string
    {
        $fullPath = $this->tempDir . '/' . $relativePath;
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($fullPath, $content);

        return $fullPath;
    }

    // ─── new() factory ───────────────────────────────────────────────

    public function testNewReturnsWorkingInstance(): void
    {
        $this->write('README.md', 'IMPORTED');

        $resolver = ImportResolver::new();
        $output = $resolver->expand('See @README.md', $this->tempDir);

        $this->assertSame('See IMPORTED', $output);
    }

    // ─── expand() basic resolution ──────────────────────────────────

    public function testExpandReplacesBareRelativeReference(): void
    {
        $this->write('AGENTS.md', 'AGENT RULES HERE');

        $output = (new ImportResolver())->expand('Root text @AGENTS.md end', $this->tempDir);

        $this->assertSame('Root text AGENT RULES HERE end', $output);
    }

    public function testExpandReplacesDotSlashReference(): void
    {
        $this->write('AGENTS.md', 'AGENT RULES HERE');

        $output = (new ImportResolver())->expand('@./AGENTS.md', $this->tempDir);

        $this->assertSame('AGENT RULES HERE', $output);
    }

    public function testExpandResolvesTildeHomeReference(): void
    {
        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=' . $this->tempDir);
        $this->write('notes.md', 'HOME NOTES');

        $output = (new ImportResolver())->expand('@~/notes.md', '/irrelevant/base');

        $this->assertSame('HOME NOTES', $output);
    }

    public function testExpandLeavesUnresolvedReferenceUntouchedWhenFileMissing(): void
    {
        $output = (new ImportResolver())->expand('See @NOPE.md here', $this->tempDir);

        $this->assertSame('See @NOPE.md here', $output);
    }

    public function testExpandLeavesContentWithNoReferencesUnchanged(): void
    {
        $output = (new ImportResolver())->expand('Plain content, nothing special.', $this->tempDir);

        $this->assertSame('Plain content, nothing special.', $output);
    }

    // ─── code-span skipping ──────────────────────────────────────────

    public function testExpandSkipsReferenceWrappedInBackticks(): void
    {
        $this->write('README.md', 'IMPORTED');

        $output = (new ImportResolver())->expand('See `@README.md` for syntax.', $this->tempDir);

        $this->assertSame('See `@README.md` for syntax.', $output);
    }

    public function testExpandSkipsReferenceInsideFencedCodeBlock(): void
    {
        $this->write('parent.md', 'REAL PARENT CONTENT');

        $output = (new ImportResolver())->expand(
            "Example:\n```\n@parent.md\n```\nEnd.",
            $this->tempDir,
        );

        $this->assertSame("Example:\n```\n@parent.md\n```\nEnd.", $output);
        $this->assertStringNotContainsString('REAL PARENT CONTENT', $output);
    }

    public function testExpandStillReplacesReferencesOutsideFencedCodeBlock(): void
    {
        $this->write('README.md', 'IMPORTED');

        $output = (new ImportResolver())->expand(
            "Before @README.md\n```\nno import here\n```\nAfter",
            $this->tempDir,
        );

        $this->assertSame("Before IMPORTED\n```\nno import here\n```\nAfter", $output);
    }

    // ─── recursion ───────────────────────────────────────────────────

    public function testExpandRecursesIntoImportedFilesUsingTheirOwnDirectory(): void
    {
        mkdir($this->tempDir . '/nested', 0777, true);
        $this->write('nested/child.md', 'CHILD-TEXT @grandchild.md');
        $this->write('nested/grandchild.md', 'GRANDCHILD-TEXT');
        $this->write('root.md', 'ROOT @nested/child.md');

        $output = (new ImportResolver())->expand('@root.md', $this->tempDir);

        $this->assertSame('ROOT CHILD-TEXT GRANDCHILD-TEXT', $output);
    }

    public function testExpandResolvesParentDirectoryReference(): void
    {
        // Two same-named files, one in the parent dir and one in the sub dir
        // being imported from, so a broken "../" resolution that silently
        // collapses to $baseDir would read the WRONG (sub-dir) file.
        $this->write('parent.md', 'REAL PARENT CONTENT');
        $this->write('sub/parent.md', 'WRONG SUB CONTENT');
        $subDir = $this->tempDir . '/sub';

        $output = (new ImportResolver())->expand('@../parent.md', $subDir);

        $this->assertSame('REAL PARENT CONTENT', $output);
    }

    public function testExpandHandlesMultipleReferencesInSameContent(): void
    {
        $this->write('one.md', 'ONE');
        $this->write('two.md', 'TWO');

        $output = (new ImportResolver())->expand('@one.md and @two.md', $this->tempDir);

        $this->assertSame('ONE and TWO', $output);
    }

    // ─── depth cap ───────────────────────────────────────────────────

    public function testExpandStopsAtMaxDepthLeavingDeeperReferenceLiteral(): void
    {
        // Chain: root -> file1 -> file2 -> file3 -> file4 -> file5.
        // MAX_DEPTH=4 means the expand() call made at depth=4 (while
        // processing file3's @file4.md match) bails out immediately and
        // returns file4's raw content, so file4's own "@file5.md"
        // reference is never expanded — file5's text must NOT appear.
        $this->write('file5.md', 'LEVEL5');
        $this->write('file4.md', 'LEVEL4 @file5.md');
        $this->write('file3.md', 'LEVEL3 @file4.md');
        $this->write('file2.md', 'LEVEL2 @file3.md');
        $this->write('file1.md', 'LEVEL1 @file2.md');

        $output = (new ImportResolver())->expand('ROOT @file1.md', $this->tempDir);

        $this->assertSame('ROOT LEVEL1 LEVEL2 LEVEL3 LEVEL4 @file5.md', $output);
        $this->assertStringNotContainsString('LEVEL5', $output);
    }

    public function testExpandReturnsContentUnchangedWhenStartingDepthAlreadyAtMax(): void
    {
        $this->write('README.md', 'IMPORTED');

        $output = (new ImportResolver())->expand('@README.md', $this->tempDir, 4);

        $this->assertSame('@README.md', $output);
    }

    // ─── immutability / no shared state across calls ─────────────────

    public function testExpandDoesNotMutateResolverBetweenCalls(): void
    {
        $this->write('a.md', 'A-CONTENT');
        $this->write('b.md', 'B-CONTENT');
        $resolver = new ImportResolver();

        $first = $resolver->expand('@a.md', $this->tempDir);
        $second = $resolver->expand('@b.md', $this->tempDir);

        $this->assertSame('A-CONTENT', $first);
        $this->assertSame('B-CONTENT', $second);
    }
}
