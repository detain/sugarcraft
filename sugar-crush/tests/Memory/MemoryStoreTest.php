<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Memory;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Memory\MemoryStore;

final class MemoryStoreTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/memory_store_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testAddCreatesMemoryFile(): void
    {
        $store = new MemoryStore($this->tempDir);

        $id = $store->add('Test memory content', 'user');

        $this->assertNotEmpty($id);
        // 'user'-scoped entries live under a dedicated user/ subdirectory,
        // not directly in tempDir -- that's what genuinely separates scopes.
        $this->assertFileExists($this->tempDir . '/user/' . $id . '.md');

        $content = file_get_contents($this->tempDir . '/user/' . $id . '.md');
        $this->assertStringContainsString('---', $content);
        $this->assertStringContainsString('id:', $content);
        $this->assertStringContainsString('type: pattern', $content);
        $this->assertStringContainsString('scope: user', $content);
        $this->assertStringContainsString('Test memory content', $content);
    }

    public function testListReturnsEntriesForScope(): void
    {
        $store = new MemoryStore($this->tempDir);

        $id1 = $store->add('User memory one', 'user');
        $id2 = $store->add('User memory two', 'user');
        $store->add('Project memory', 'project');

        $userEntries = $store->list('user');

        $this->assertCount(2, $userEntries);
        $ids = array_map(fn($e) => $e->id(), $userEntries);
        $contents = array_map(fn($e) => $e->content(), $userEntries);
        $this->assertContains($id1, $ids);
        $this->assertContains($id2, $ids);
        $this->assertContains('User memory one', $contents);
        $this->assertContains('User memory two', $contents);
    }

    public function testSearchFindsContentMatch(): void
    {
        $store = new MemoryStore($this->tempDir);

        $store->add('PHP is a great language', 'user');
        $store->add('Python is also great', 'user');

        $results = $store->search('PHP');

        $this->assertCount(1, $results);
        $this->assertStringContainsString('PHP', $results[0]->content());
    }

    public function testSearchReturnsEmptyWhenNoMatch(): void
    {
        $store = new MemoryStore($this->tempDir);

        $store->add('Some content here', 'user');

        $results = $store->search('nonexistent query');

        $this->assertCount(0, $results);
    }

    public function testDeleteRemovesFile(): void
    {
        $store = new MemoryStore($this->tempDir);

        $id = $store->add('Memory to delete', 'user');
        $filePath = $this->tempDir . '/user/' . $id . '.md';

        $this->assertFileExists($filePath);

        $store->delete($id);

        $this->assertFileDoesNotExist($filePath);
    }

    public function testClearRemovesAllForScope(): void
    {
        $store = new MemoryStore($this->tempDir);

        $store->add('User memory one', 'user');
        $store->add('User memory two', 'user');
        $store->add('Project memory', 'project');

        $store->clear('user');

        // 'user' scope's directory should now be empty; the surviving entry
        // lives under the separate project/ directory.
        $remainingFiles = glob($this->tempDir . '/project/*.md');
        $this->assertCount(1, $remainingFiles);

        $remainingContent = file_get_contents($remainingFiles[0]);
        $this->assertStringContainsString('Project memory', $remainingContent);
    }

    public function testListIgnoresOtherScope(): void
    {
        $store = new MemoryStore($this->tempDir);

        $store->add('User memory', 'user');
        $store->add('Agent memory', 'agent');
        $store->add('Project memory', 'project');

        $userEntries = $store->list('user');

        $this->assertCount(1, $userEntries);
        $this->assertEquals('user', $userEntries[0]->scope());
    }

    public function testSearchCaseInsensitive(): void
    {
        $store = new MemoryStore($this->tempDir);

        $store->add('JavaScript is awesome', 'user');

        $resultsUpper = $store->search('JAVASCRIPT');
        $resultsLower = $store->search('javascript');
        $resultsMixed = $store->search('JavaScript');

        $this->assertCount(1, $resultsUpper);
        $this->assertCount(1, $resultsLower);
        $this->assertCount(1, $resultsMixed);
    }

    public function testGetReturnsEntryById(): void
    {
        $store = new MemoryStore($this->tempDir);

        $id = $store->add('Retrieve this memory', 'user');

        $entry = $store->get($id);

        $this->assertNotNull($entry);
        $this->assertEquals($id, $entry->id());
        $this->assertEquals('Retrieve this memory', $entry->content());
        $this->assertEquals('user', $entry->scope());
        $this->assertEquals('pattern', $entry->type());
    }

    public function testGetReturnsNullForNonexistentId(): void
    {
        $store = new MemoryStore($this->tempDir);

        $result = $store->get('00000000000000000000000000000000');

        $this->assertNull($result);
    }

    public function testGenerateIndexCreatesIndexFile(): void
    {
        $store = new MemoryStore($this->tempDir);

        $store->add('First memory entry', 'user');

        $indexPath = $this->tempDir . '/MEMORY.md';
        $this->assertFileExists($indexPath);
    }

    public function testLoadIndexReturnsNullWhenNoIndex(): void
    {
        $store = new MemoryStore($this->tempDir);

        $result = $store->loadIndex();

        $this->assertNull($result);
    }

    public function testLoadIndexReturnsContentWhenIndexExists(): void
    {
        $store = new MemoryStore($this->tempDir);

        $store->add('Test memory content', 'user');
        $content = $store->loadIndex();

        $this->assertNotNull($content);
        $this->assertStringContainsString('Memory Index (user)', $content);
        $this->assertStringContainsString('Test memory content', $content);
    }

    public function testAddRegeneratesIndex(): void
    {
        $store = new MemoryStore($this->tempDir);

        $store->add('First entry', 'user');
        $content1 = $store->loadIndex('user');
        $this->assertStringContainsString('First entry', $content1);

        $store->add('Second entry', 'user');
        $content2 = $store->loadIndex('user');
        $this->assertStringContainsString('Second entry', $content2);
    }

    public function testDeleteRegeneratesIndex(): void
    {
        $store = new MemoryStore($this->tempDir);

        $id1 = $store->add('Entry to keep', 'user');
        $id2 = $store->add('Entry to delete', 'user');

        $store->delete($id2);

        $content = $store->loadIndex();
        $this->assertStringContainsString('Entry to keep', $content);
        $this->assertStringNotContainsString('Entry to delete', $content);
    }

    public function testClearRegeneratesIndex(): void
    {
        $store = new MemoryStore($this->tempDir);

        $store->add('User memory', 'user');
        $store->add('Project memory', 'project');

        $store->clear('user');

        $content = $store->loadIndex();
        $this->assertNull($content);
        $this->assertFileDoesNotExist($this->tempDir . '/MEMORY.md');
    }

    public function testIndexContainsEntryMetadata(): void
    {
        $store = new MemoryStore($this->tempDir);

        $id = $store->add('Memory with content', 'user');

        $content = $store->loadIndex();
        $this->assertStringContainsString('PATTERN', $content);
        $this->assertStringContainsString($id, $content);
    }

    public function testIndexFormatIsMarkdownList(): void
    {
        $store = new MemoryStore($this->tempDir);

        $store->add('First memory entry', 'user');
        $store->add('Second memory entry', 'user');

        $content = $store->loadIndex();
        $this->assertStringContainsString("# Memory Index (user)", $content);
        $this->assertStringContainsString("Loaded at:", $content);
        $this->assertStringContainsString("---", $content);
        $this->assertStringContainsString("Generated by sugar-crush memory system", $content);
    }

    public function testIndexShowsIdAndTags(): void
    {
        $store = new MemoryStore($this->tempDir);

        $store->add('Tagged memory', 'user', ['php', 'testing']);

        $content = $store->loadIndex();
        $this->assertStringContainsString('php, testing', $content);
    }

    public function testIndexEnforcesSizeAndLineCaps(): void
    {
        $store = new MemoryStore($this->tempDir);

        // Add enough entries to potentially exceed line cap.
        for ($i = 0; $i < 50; $i++) {
            $store->add("Memory entry number {$i} with some content here", 'user');
        }

        $content = $store->loadIndex();
        $this->assertNotNull($content);

        // Should be under 25KB.
        $this->assertLessThan(25 * 1024, strlen($content));

        // Count lines — should be under 200.
        $lineCount = substr_count($content, "\n");
        $this->assertLessThan(200, $lineCount + 1); // +1 because last line has no trailing newline.
    }

    public function testProjectAndUserScopesResolveToDifferentDirectories(): void
    {
        $store = new MemoryStore($this->tempDir);

        $projectId = $store->add('Project-scoped content', 'project');
        $userId = $store->add('User-scoped content', 'user');

        $projectDir = $this->tempDir . '/project';
        $userDir = $this->tempDir . '/user';

        // The whole point of this fix: scope must select a genuinely
        // different physical directory, not just a different YAML field
        // inside one shared directory.
        $this->assertDirectoryExists($projectDir);
        $this->assertDirectoryExists($userDir);
        $this->assertNotEquals($projectDir, $userDir);

        $this->assertFileExists($projectDir . '/' . $projectId . '.md');
        $this->assertFileExists($userDir . '/' . $userId . '.md');

        // Each entry must live ONLY under its own scope's directory.
        $this->assertFileDoesNotExist($userDir . '/' . $projectId . '.md');
        $this->assertFileDoesNotExist($projectDir . '/' . $userId . '.md');
    }

    public function testIndexByteCapTruncatesOnACharacterBoundary(): void
    {
        $store = new MemoryStore($this->tempDir);

        // A single very long multibyte tag (3-byte UTF-8 CJK characters)
        // inflates one rendered line to well past the 25KB cap while
        // adding almost no extra rendered LINES, so the byte cap -- not the
        // line cap -- is what actually binds here. The old implementation
        // truncated with a raw byte-offset substr(), which can (and, given
        // this content, provably does) land mid-character; mb_strcut()
        // rounds down to the nearest full character instead.
        $bigMultibyteTag = str_repeat('文', 20000); // 60,000 bytes, zero '\n'.
        $store->add('short content', 'user', [$bigMultibyteTag]);

        $content = $store->loadIndex();
        $this->assertNotNull($content);

        // Prove the cap actually bound -- otherwise the encoding assertion
        // below would be vacuous.
        $this->assertLessThanOrEqual(25 * 1024, strlen($content));
        $this->assertGreaterThan(25 * 1024 - 16, strlen($content));

        $this->assertTrue(
            mb_check_encoding($content, 'UTF-8'),
            'Truncated index content must remain valid UTF-8, never a raw byte cut mid multibyte character.'
        );
    }

    public function testIndexNeverExceeds200RenderedLines(): void
    {
        $store = new MemoryStore($this->tempDir);

        // Content with embedded newlines: the old cap counted PHP array
        // elements pushed to $lines (a fixed 3 per entry), which under-counts
        // the ACTUAL rendered "\n" occurrences once a preview line itself
        // contains literal newlines -- these two counts diverge.
        $multilineContent = "Line one of memory\nLine two of memory\nLine three";

        for ($i = 0; $i < 95; $i++) {
            $store->add($multilineContent, 'user');
        }

        $content = $store->loadIndex();
        $this->assertNotNull($content);

        $renderedLines = explode("\n", $content);
        $this->assertLessThanOrEqual(200, count($renderedLines));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
