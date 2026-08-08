<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Memory;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\MemoryScope;
use SugarCraft\Crush\Memory\MemoryEntry;
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

    public function testUpdateModifiesContentInPlaceWithinSameScope(): void
    {
        $store = new MemoryStore($this->tempDir);

        $id = $store->add('Original content', 'user');
        $original = $store->get($id);
        $this->assertNotNull($original);

        $store->update($id, $original->withContent('Updated content'));

        $entry = $store->get($id);
        $this->assertNotNull($entry);
        $this->assertEquals('Updated content', $entry->content());
        $this->assertEquals('user', $entry->scope());
        $this->assertFileExists($this->tempDir . '/user/' . $id . '.md');

        $index = $store->loadIndex('user');
        $this->assertStringContainsString('Updated content', $index);
    }

    public function testUpdateMovingScopeRelocatesFileAndRegeneratesBothIndexes(): void
    {
        $store = new MemoryStore($this->tempDir);

        $id = $store->add('Movable content', 'user');
        $this->assertFileExists($this->tempDir . '/user/' . $id . '.md');

        $original = $store->get($id);
        $this->assertNotNull($original);

        $store->update($id, $original->withScope('project'));

        // The stale copy in the old scope directory must be gone, and the
        // same id must now live under the new scope's directory instead --
        // never in both places at once.
        $this->assertFileDoesNotExist($this->tempDir . '/user/' . $id . '.md');
        $this->assertFileExists($this->tempDir . '/project/' . $id . '.md');

        $entry = $store->get($id);
        $this->assertNotNull($entry);
        $this->assertEquals('project', $entry->scope());

        // Both the old scope's index (now empty -> removed) and the new
        // scope's index (now containing the moved entry) must be
        // regenerated as part of the same update() call.
        $this->assertNull($store->loadIndex('user'));
        $this->assertFileDoesNotExist($this->tempDir . '/user/MEMORY.md');

        $projectIndex = $store->loadIndex('project');
        $this->assertNotNull($projectIndex);
        $this->assertStringContainsString('Movable content', $projectIndex);
    }

    public function testUpdateMovingScopeLeavesOldScopeIndexCorrectWhenOtherEntriesRemain(): void
    {
        $store = new MemoryStore($this->tempDir);

        $keepId = $store->add('Stays in user scope', 'user');
        $moveId = $store->add('Moves to project scope', 'user');

        $moving = $store->get($moveId);
        $this->assertNotNull($moving);
        $store->update($moveId, $moving->withScope('project'));

        // The old scope's index must be regenerated to reflect that the
        // moved entry is gone, while still describing the entry that stayed.
        $userIndex = $store->loadIndex('user');
        $this->assertNotNull($userIndex);
        $this->assertStringContainsString('Stays in user scope', $userIndex);
        $this->assertStringNotContainsString('Moves to project scope', $userIndex);

        $projectIndex = $store->loadIndex('project');
        $this->assertNotNull($projectIndex);
        $this->assertStringContainsString('Moves to project scope', $projectIndex);

        $this->assertFileExists($this->tempDir . '/user/' . $keepId . '.md');
        $this->assertFileDoesNotExist($this->tempDir . '/user/' . $moveId . '.md');
        $this->assertFileExists($this->tempDir . '/project/' . $moveId . '.md');
    }

    public function testUpdateWithUnknownIdInsertsNewEntry(): void
    {
        $store = new MemoryStore($this->tempDir);

        // update() is documented as an upsert: an id that has never been
        // written before must still succeed and create the file, rather
        // than requiring a prior add().
        $id = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
        $entry = MemoryEntry::new(
            type: 'pattern',
            content: 'Inserted via update',
            scope: 'user',
            id: $id,
        );

        $store->update($id, $entry);

        $this->assertFileExists($this->tempDir . '/user/' . $id . '.md');
        $fetched = $store->get($id);
        $this->assertNotNull($fetched);
        $this->assertEquals('Inserted via update', $fetched->content());

        $index = $store->loadIndex('user');
        $this->assertStringContainsString('Inserted via update', $index);
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
        // lives under the separate project/ directory. Exclude project/'s own
        // MEMORY.md index file from the count -- it's a sibling artifact in
        // the same directory, not an entry file.
        $remainingFiles = array_values(array_filter(
            glob($this->tempDir . '/project/*.md') ?: [],
            static fn(string $file): bool => basename($file) !== 'MEMORY.md',
        ));
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

        $indexPath = $this->tempDir . '/user/MEMORY.md';
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
        $projectId = $store->add('Project memory', 'project');

        $store->clear('user');

        // Clearing 'user' removes only 'user's own index...
        $content = $store->loadIndex('user');
        $this->assertNull($content);
        $this->assertFileDoesNotExist($this->tempDir . '/user/MEMORY.md');

        // ...and must never touch 'project's index or physical file: each
        // scope's index lives in its own subdirectory, so clearing one scope
        // can't clobber or delete another scope's index/entries.
        $projectContent = $store->loadIndex('project');
        $this->assertNotNull($projectContent);
        $this->assertStringContainsString('Project memory', $projectContent);
        $this->assertFileExists($this->tempDir . '/project/' . $projectId . '.md');
    }

    public function testAddingToOneScopeDoesNotClobberAnotherScopesIndex(): void
    {
        $store = new MemoryStore($this->tempDir);

        $store->add('User entry A', 'user');
        $store->add('Project entry A', 'project');

        // Each scope gets its own index file, generated only from that
        // scope's own entries -- mutating 'project' must never overwrite or
        // erase what 'user's index says, and vice versa.
        $userIndex = $store->loadIndex('user');
        $projectIndex = $store->loadIndex('project');

        $this->assertNotNull($userIndex);
        $this->assertNotNull($projectIndex);
        $this->assertStringContainsString('User entry A', $userIndex);
        $this->assertStringNotContainsString('Project entry A', $userIndex);
        $this->assertStringContainsString('Project entry A', $projectIndex);
        $this->assertStringNotContainsString('User entry A', $projectIndex);
    }

    public function testClearingOneScopeLeavesOtherScopesIndexAndFilesIntact(): void
    {
        $store = new MemoryStore($this->tempDir);

        $store->add('User entry A', 'user');
        $projectId = $store->add('Project entry A', 'project');

        // Regression repro for the cross-scope clobber: clearing an unrelated
        // scope must not delete a still-live entry (or its index) in a scope
        // that was never touched.
        $store->clear('user');

        $this->assertNull($store->loadIndex('user'));
        $this->assertFileDoesNotExist($this->tempDir . '/user/MEMORY.md');

        $projectIndex = $store->loadIndex('project');
        $this->assertNotNull($projectIndex);
        $this->assertStringContainsString('Project entry A', $projectIndex);
        $this->assertFileExists($this->tempDir . '/project/' . $projectId . '.md');
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

    public function testAddAcceptsMemoryScopeEnumAndResolvesToMatchingDirectory(): void
    {
        $store = new MemoryStore($this->tempDir);

        // The whole point of this fix, part two: the enum itself must be
        // able to drive directory selection, not just its ->value string.
        $projectId = $store->add('Project via enum', MemoryScope::Project);
        $userId = $store->add('User via enum', MemoryScope::User);

        $this->assertFileExists($this->tempDir . '/project/' . $projectId . '.md');
        $this->assertFileExists($this->tempDir . '/user/' . $userId . '.md');

        // Passing the enum must resolve to the SAME directory as passing
        // the equivalent legacy string -- add()/list() are interchangeable
        // regardless of which form the caller uses.
        $viaString = $store->list('project');
        $viaEnum = $store->list(MemoryScope::Project);
        $this->assertCount(1, $viaString);
        $this->assertCount(1, $viaEnum);
        $this->assertEquals($viaString[0]->id(), $viaEnum[0]->id());
    }

    public function testMemoryScopeLocalResolvesToSameDirectoryAsLegacyAgentString(): void
    {
        $store = new MemoryStore($this->tempDir);

        // MemoryScope's own vocabulary spells this case 'local', but every
        // existing string-based caller (Chat.php) spells it 'agent'. Both
        // forms must land in the same physical directory so a caller that
        // adopts the enum can't silently fragment scope storage away from
        // callers still using the legacy string.
        $viaEnumId = $store->add('Local via enum', MemoryScope::Local);
        $viaStringId = $store->add('Agent via string', 'agent');

        $this->assertFileExists($this->tempDir . '/agent/' . $viaEnumId . '.md');
        $this->assertFileExists($this->tempDir . '/agent/' . $viaStringId . '.md');
        $this->assertDirectoryDoesNotExist($this->tempDir . '/local');

        $entries = $store->list(MemoryScope::Local);
        $this->assertCount(2, $entries);
        $ids = array_map(fn($e) => $e->id(), $entries);
        $this->assertContains($viaEnumId, $ids);
        $this->assertContains($viaStringId, $ids);
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

    public function testIndexByteCapTruncatesOnACharacterBoundaryWithFourByteEmoji(): void
    {
        $store = new MemoryStore($this->tempDir);

        // Same repro as above but with a 4-byte UTF-8 sequence (emoji)
        // rather than a 3-byte CJK character, so the cap is proven not to
        // depend on the specific byte-width of the multibyte content that
        // happens to straddle the truncation boundary.
        $bigMultibyteTag = str_repeat('🎉', 15000); // 60,000 bytes, zero '\n'.
        $store->add('short content', 'user', [$bigMultibyteTag]);

        $content = $store->loadIndex();
        $this->assertNotNull($content);

        $this->assertLessThanOrEqual(25 * 1024, strlen($content));
        $this->assertGreaterThan(25 * 1024 - 16, strlen($content));

        $this->assertTrue(
            mb_check_encoding($content, 'UTF-8'),
            'Truncated index content must remain valid UTF-8 even when the boundary falls inside a 4-byte character.'
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

    public function testIndexNeverExceeds200RenderedLinesWithHeavyEmbeddedNewlines(): void
    {
        $store = new MemoryStore($this->tempDir);

        // Heavier repro: 60 entries x 10 embedded newlines each (600 raw
        // content newlines), well beyond the 95-entry/3-line-each case
        // above, to prove the cap holds under a much larger embedded-newline
        // load, not just the specific input size already covered.
        $tenLineContent = implode("\n", array_fill(0, 10, 'Embedded line of memory content'));

        for ($i = 0; $i < 60; $i++) {
            $store->add($tenLineContent, 'user');
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
