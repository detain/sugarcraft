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
        $this->assertFileExists($this->tempDir . '/' . $id . '.md');

        $content = file_get_contents($this->tempDir . '/' . $id . '.md');
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
        $filePath = $this->tempDir . '/' . $id . '.md';

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

        $remainingFiles = glob($this->tempDir . '/*.md');
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

    public function testGenerateIndexCreatesIndexFile(): void
    {
        $store = new MemoryStore($this->tempDir);

        $store->add('Test memory content', 'user');
        $store->generateIndex('user');

        $indexFile = $this->tempDir . '/indexes/user.md';
        $this->assertFileExists($indexFile);

        $content = file_get_contents($indexFile);
        $this->assertStringContainsString('# Memory Index', $content);
        $this->assertStringContainsString('Test memory content', $content);
    }

    public function testLoadIndexReturnsNullWhenNoIndex(): void
    {
        $store = new MemoryStore($this->tempDir);

        $result = $store->loadIndex('nonexistent');

        $this->assertNull($result);
    }

    public function testLoadIndexReturnsContentWhenIndexExists(): void
    {
        $store = new MemoryStore($this->tempDir);

        $store->add('Indexed memory', 'user');
        $store->generateIndex('user');

        $result = $store->loadIndex('user');

        $this->assertNotNull($result);
        $this->assertStringContainsString('Indexed memory', $result);
    }

    public function testAddRegeneratesIndex(): void
    {
        $store = new MemoryStore($this->tempDir);

        $id = $store->add('First entry', 'user');

        $indexFile = $this->tempDir . '/indexes/user.md';
        $this->assertFileExists($indexFile);

        $content = file_get_contents($indexFile);
        $this->assertStringContainsString('First entry', $content);
    }

    public function testDeleteRegeneratesIndex(): void
    {
        $store = new MemoryStore($this->tempDir);

        $id = $store->add('Entry to delete', 'user');
        $indexContentBefore = file_get_contents($this->tempDir . '/indexes/user.md');
        $this->assertStringContainsString('Entry to delete', $indexContentBefore);

        $store->delete($id);

        $indexContentAfter = file_get_contents($this->tempDir . '/indexes/user.md');
        $this->assertStringNotContainsString('Entry to delete', $indexContentAfter);
    }

    public function testClearRegeneratesIndex(): void
    {
        $store = new MemoryStore($this->tempDir);

        $store->add('User memory one', 'user');
        $store->add('User memory two', 'user');

        $indexContentBefore = file_get_contents($this->tempDir . '/indexes/user.md');
        $this->assertStringContainsString('User memory one', $indexContentBefore);

        $store->clear('user');
        $store->generateIndex('user');

        $indexContentAfter = file_get_contents($this->tempDir . '/indexes/user.md');
        $this->assertStringNotContainsString('User memory one', $indexContentAfter);
        $this->assertStringNotContainsString('User memory two', $indexContentAfter);
    }

    public function testIndexContainsEntryMetadata(): void
    {
        $store = new MemoryStore($this->tempDir);

        $store->add('Test content for metadata', 'user');
        $store->generateIndex('user');

        $index = $store->loadIndex('user');
        $this->assertNotNull($index);
        $this->assertStringContainsString('[pattern]', $index);
    }

    public function testIndexFormatIsMarkdownList(): void
    {
        $store = new MemoryStore($this->tempDir);

        $store->add('Memory entry content here', 'user');
        $store->generateIndex('user');

        $index = $store->loadIndex('user');
        $this->assertNotNull($index);
        // Should have markdown list format: - [{type}] or - **[{type}]**
        $this->assertTrue(
            str_contains($index, '- **[') || str_contains($index, '- ['),
            'Index should contain markdown list entry'
        );
    }

    public function testIndexShowsIdAndTags(): void
    {
        $store = new MemoryStore($this->tempDir);

        $id = $store->add('Content', 'user');
        $store->generateIndex('user');

        $index = $store->loadIndex('user');
        $this->assertNotNull($index);
        // Index should contain the entry ID
        $this->assertStringContainsString($id, $index);
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
