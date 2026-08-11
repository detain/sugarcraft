<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Memory;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Memory\ForeignMemoryImporter;
use SugarCraft\Crush\Memory\MemoryStore;

final class ForeignMemoryImporterTest extends TestCase
{
    private string $tempDir;
    private string $storeDir;
    private string $projectRoot;
    private MemoryStore $store;
    private ForeignMemoryImporter $importer;
    private string $origHome;
    private string $origErrorLog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/foreign_memory_' . uniqid();
        $this->storeDir = $this->tempDir . '/store';
        $this->projectRoot = $this->tempDir . '/project';
        mkdir($this->storeDir, 0777, true);
        mkdir($this->projectRoot, 0777, true);

        // The default ~/.claude lookup must not read the real machine's memory.
        $this->origHome = $_SERVER['HOME'] ?? '/root';
        $_SERVER['HOME'] = $this->tempDir . '/default-empty-home';
        mkdir($_SERVER['HOME'], 0777, true);

        // Keep the skip-a-malformed-file error_log() calls out of the suite's stderr.
        $this->origErrorLog = (string) ini_get('error_log');
        ini_set('error_log', $this->tempDir . '/error.log');

        $this->store = new MemoryStore($this->storeDir);
        $this->importer = new ForeignMemoryImporter($this->store);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->origErrorLog);
        $_SERVER['HOME'] = $this->origHome;
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testImportClaudeCodeImportsEntriesTaggedWithProvenance(): void
    {
        $home = $this->claudeMemoryDir($this->projectRoot);
        file_put_contents(
            $home . '/pattern_one.md',
            "---\ndescription: Ship-as-you-go cadence\n---\nOne PR per change-set.\n"
        );

        $count = $this->importer->importClaudeCode($this->projectRoot, $this->tempDir . '/claude');

        $this->assertSame(1, $count);
        // MemoryScope::Local is stored under the 'agent' scope directory.
        $entries = $this->store->list('agent');
        $this->assertCount(1, $entries);
        $this->assertSame("Ship-as-you-go cadence\n\nOne PR per change-set.", $entries[0]->content());
        $this->assertSame(['source:claude'], $entries[0]->tags());
    }

    public function testImportClaudeCodeSkipsGeneratedIndexFile(): void
    {
        $dir = $this->claudeMemoryDir($this->projectRoot);
        file_put_contents($dir . '/MEMORY.md', "---\ndescription: Index\n---\n- [a](a.md)\n");
        file_put_contents($dir . '/a.md', "---\ndescription: A\n---\nbody a\n");

        $count = $this->importer->importClaudeCode($this->projectRoot, $this->tempDir . '/claude');

        $this->assertSame(1, $count);
        $this->assertSame("A\n\nbody a", $this->store->list('agent')[0]->content());
    }

    public function testImportClaudeCodeSkipsFilesWithoutFrontmatter(): void
    {
        $dir = $this->claudeMemoryDir($this->projectRoot);
        file_put_contents($dir . '/notes.md', "just a plain note, no frontmatter\n");

        $this->assertSame(0, $this->importer->importClaudeCode($this->projectRoot, $this->tempDir . '/claude'));
        $this->assertSame([], $this->store->list('agent'));
    }

    public function testImportClaudeCodeFallsBackToFilenameWhenDescriptionIsMissing(): void
    {
        $dir = $this->claudeMemoryDir($this->projectRoot);
        file_put_contents($dir . '/feedback_pr_size.md', "---\ntype: preference\n---\nBundle 2-4 items.\n");

        $this->importer->importClaudeCode($this->projectRoot, $this->tempDir . '/claude');

        $this->assertSame("feedback_pr_size\n\nBundle 2-4 items.", $this->store->list('agent')[0]->content());
    }

    /**
     * A YAML `description:` decoding to a list must not be concatenated as the
     * literal "Array" (nor raise an Array-to-string conversion warning).
     */
    public function testImportClaudeCodeFallsBackToFilenameWhenDescriptionIsNotAString(): void
    {
        $dir = $this->claudeMemoryDir($this->projectRoot);
        file_put_contents($dir . '/listy.md', "---\ndescription:\n  - one\n  - two\n---\nbody\n");

        $count = $this->importer->importClaudeCode($this->projectRoot, $this->tempDir . '/claude');

        $this->assertSame(1, $count);
        $this->assertSame("listy\n\nbody", $this->store->list('agent')[0]->content());
    }

    public function testImportClaudeCodeRecordsOriginSessionIdAsATag(): void
    {
        $dir = $this->claudeMemoryDir($this->projectRoot);
        file_put_contents(
            $dir . '/origin.md',
            "---\ndescription: With origin\nmetadata:\n  originSessionId: abc-123\n---\nbody\n"
        );

        $this->importer->importClaudeCode($this->projectRoot, $this->tempDir . '/claude');

        $this->assertSame(['source:claude', 'origin:abc-123'], $this->store->list('agent')[0]->tags());
    }

    /**
     * A `metadata:` block that is not a map, or an origin id that is not a
     * scalar, yields no origin tag rather than a bogus one.
     */
    public function testImportClaudeCodeDropsNonScalarOrigin(): void
    {
        $dir = $this->claudeMemoryDir($this->projectRoot);
        file_put_contents($dir . '/a.md', "---\ndescription: A\nmetadata: not-a-map\n---\nbody\n");
        file_put_contents(
            $dir . '/b.md',
            "---\ndescription: B\nmetadata:\n  originSessionId:\n    nested: yes\n---\nbody\n"
        );

        $count = $this->importer->importClaudeCode($this->projectRoot, $this->tempDir . '/claude');

        $this->assertSame(2, $count);
        foreach ($this->store->list('agent') as $entry) {
            $this->assertSame(['source:claude'], $entry->tags());
        }
    }

    public function testImportClaudeCodeSkipsMalformedYamlButImportsTheRest(): void
    {
        $dir = $this->claudeMemoryDir($this->projectRoot);
        file_put_contents($dir . '/broken.md', "---\ndescription: \"unterminated\nfoo: [bar\n---\nbody\n");
        file_put_contents($dir . '/good.md', "---\ndescription: Good\n---\nbody good\n");

        $count = $this->importer->importClaudeCode($this->projectRoot, $this->tempDir . '/claude');

        $this->assertSame(1, $count);
        $this->assertSame("Good\n\nbody good", $this->store->list('agent')[0]->content());
    }

    public function testImportClaudeCodeReturnsZeroWhenTheForeignDirectoryIsAbsent(): void
    {
        $this->assertSame(0, $this->importer->importClaudeCode($this->projectRoot, $this->tempDir . '/nope'));
        $this->assertSame([], $this->store->list('agent'));
    }

    /**
     * Claude Code never writes a trailing separator into its project slug, so a
     * caller passing a trailing-slash root must resolve to the same directory.
     */
    public function testImportClaudeCodeIgnoresATrailingSlashOnTheProjectRoot(): void
    {
        $dir = $this->claudeMemoryDir($this->projectRoot);
        file_put_contents($dir . '/a.md', "---\ndescription: A\n---\nbody\n");

        $count = $this->importer->importClaudeCode($this->projectRoot . '/', $this->tempDir . '/claude');

        $this->assertSame(1, $count);
    }

    public function testImportClaudeCodeDefaultsToClaudeHomeUnderHome(): void
    {
        $_SERVER['HOME'] = $this->tempDir . '/fake-home';
        $dir = $this->claudeMemoryDir($this->projectRoot, $this->tempDir . '/fake-home/.claude');
        file_put_contents($dir . '/a.md', "---\ndescription: From home\n---\nbody\n");

        $count = $this->importer->importClaudeCode($this->projectRoot);

        $this->assertSame(1, $count);
        $this->assertSame("From home\n\nbody", $this->store->list('agent')[0]->content());
    }

    public function testImportOpencodeImportsWholeFilesWithFilenameTitles(): void
    {
        $dir = $this->projectRoot . '/.opencode/memory';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/now.md', "current focus: wave 1\n");
        file_put_contents($dir . '/archive.md', "older notes\n");

        $count = $this->importer->importOpencode($this->projectRoot);

        $this->assertSame(2, $count);
        $entries = $this->store->list('agent');
        $this->assertCount(2, $entries);
        $contents = array_map(static fn($e) => $e->content(), $entries);
        $this->assertContains("# now\n\ncurrent focus: wave 1", $contents);
        $this->assertContains("# archive\n\nolder notes", $contents);
        foreach ($entries as $entry) {
            $this->assertSame(['source:opencode'], $entry->tags());
        }
    }

    /**
     * opencode memory files have no frontmatter, so a file that happens to
     * start with `---` is still imported whole rather than skipped.
     */
    public function testImportOpencodeDoesNotRequireFrontmatter(): void
    {
        $dir = $this->projectRoot . '/.opencode/memory';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/raw.md', "---\nnot: parsed\n---\nbody\n");

        $count = $this->importer->importOpencode($this->projectRoot);

        $this->assertSame(1, $count);
        $this->assertSame("# raw\n\n---\nnot: parsed\n---\nbody", $this->store->list('agent')[0]->content());
    }

    public function testImportOpencodeReturnsZeroWhenTheForeignDirectoryIsAbsent(): void
    {
        $this->assertSame(0, $this->importer->importOpencode($this->projectRoot));
        $this->assertSame([], $this->store->list('agent'));
    }

    public function testImportOpencodeIgnoresATrailingSlashOnTheProjectRoot(): void
    {
        $dir = $this->projectRoot . '/.opencode/memory';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/a.md', "body\n");

        $this->assertSame(1, $this->importer->importOpencode($this->projectRoot . '/'));
    }

    /**
     * Imports are not idempotent -- MemoryStore mints a fresh id per add() --
     * which is why de-duplication is the caller's sentinel-file job.
     */
    public function testRepeatedImportDuplicatesEntries(): void
    {
        $dir = $this->projectRoot . '/.opencode/memory';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/a.md', "body\n");

        $this->importer->importOpencode($this->projectRoot);
        $this->importer->importOpencode($this->projectRoot);

        $this->assertCount(2, $this->store->list('agent'));
    }

    /**
     * Build the Claude Code memory directory for $projectRoot under $claudeHome
     * (default: this test's fake ~/.claude), mirroring the real
     * `<home>/projects/<path-with-dashes>/memory` layout.
     */
    private function claudeMemoryDir(string $projectRoot, ?string $claudeHome = null): string
    {
        $slug = '-' . ltrim(str_replace('/', '-', rtrim($projectRoot, '/')), '-');
        $dir = ($claudeHome ?? $this->tempDir . '/claude') . '/projects/' . $slug . '/memory';
        mkdir($dir, 0777, true);

        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
