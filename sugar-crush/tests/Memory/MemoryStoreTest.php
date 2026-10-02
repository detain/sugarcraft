<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Memory;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\MemoryScope;
use SugarCraft\Crush\Context\MemoryBlock;
use SugarCraft\Crush\Memory\MemoryEntry;
use SugarCraft\Crush\Memory\MemoryStore;

final class MemoryStoreTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/memory_store_' . uniqid((string) getmypid(), true);
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

    public function testNoteAndIndexLandOwnerOnlyWithNoOrphanTemps(): void
    {
        // Audit M3: notes/index went out via bare file_put_contents (0644
        // under a default umask, torn on crash). They now publish through
        // AtomicFileWriter at 0600; scope dirs are created 0700.
        $store = new MemoryStore($this->tempDir);

        $id = $store->add('Durable fact for the perm pin', 'user');
        $store->generateIndex('user');

        $note = $this->tempDir . '/user/' . $id . '.md';
        $index = $this->tempDir . '/user/MEMORY.md';
        clearstatcache();
        $this->assertSame(0o600, fileperms($note) & 0o777, 'memory note must be owner-only');
        $this->assertSame(0o600, fileperms($index) & 0o777, 'generated index must be owner-only');
        $this->assertSame(0o700, fileperms($this->tempDir . '/user') & 0o777, 'scope dir must be owner-only');
        // scandir+filter, not a wildcard glob(): glob-shaped literals in tests
        // join the GlobDialectDifferentialTest corpus and drift its pinned
        // PathGlob docblock figure.
        $this->assertSame([], array_values(array_filter(
            scandir($this->tempDir . '/user') ?: [],
            static fn(string $entry): bool => str_contains($entry, '.tmp.'),
        )), 'no orphan temp survives the publish');

        // The published bytes are exactly what the reader expects — rename
        // landed the FULL payload (idempotent re-read through the public API).
        $entry = $store->get($id);
        $this->assertNotNull($entry);
        $this->assertStringContainsString('Durable fact', $entry->content());
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
        // No timestamp: the index is derived from the notes alone (audit 15d-23).
        $this->assertStringNotContainsString("Loaded at:", $content);
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

    /**
     * unreadable() reads EVERY scope before answering — skipped() alone knows
     * only what a list() or search() has already met — and forgets a broken
     * file that has since been deleted or fixed, so the launch notice built on
     * it describes the store as it is now.
     */
    public function testUnreadableReadsEveryScopeAndForgetsFixedOrVanishedFiles(): void
    {
        $store = new MemoryStore($this->tempDir);
        $store->add('A readable note', 'user');
        mkdir($this->tempDir . '/agent', 0o700, true);
        $userBad = $this->tempDir . '/user/broken.md';
        $agentBad = $this->tempDir . '/agent/broken.md';
        file_put_contents($userBad, "no frontmatter\n");
        file_put_contents($agentBad, "---\nid: x\n---\nmissing type and scope\n");

        $this->assertSame([], $store->skipped(), 'nothing has been read yet');

        $unreadable = $store->unreadable();
        $this->assertSame([$agentBad, $userBad], array_keys($unreadable), 'every scope, sorted by path');
        $this->assertNotSame('', $unreadable[$userBad]);

        unlink($agentBad);
        file_put_contents($userBad, "---\nid: fixed\ntype: note\nscope: user\n---\nnow readable\n");
        $this->assertSame([], $store->unreadable(), 'a fixed note and a deleted one are both forgotten');
        $this->assertSame([], $store->skipped());
    }

    /**
     * Audit 15d-04: one hand-edited note must never take the whole store (and,
     * through MemoryBlock::capture(), every turn's system prompt) down with it.
     * Every case here used to escape list() as a TypeError or be dropped
     * without a trace; a recoverable shape now loads, an unrecoverable one is
     * skipped and named by skipped().
     *
     * @param array<string>|null $expectedTags null when the note must be skipped
     */
    #[DataProvider('malformedEntryProvider')]
    public function testAMalformedEntryIsSkippedNotFatal(string $frontmatter, ?array $expectedTags): void
    {
        $store = new MemoryStore($this->tempDir);
        $siblingId = $store->add('Valid sibling note', 'project');

        $badFile = $this->tempDir . '/project/hand-edited.md';
        file_put_contents($badFile, "---\n{$frontmatter}\n---\nHand-edited body\n");

        $entries = $store->list('project');
        $byId = [];
        foreach ($entries as $entry) {
            $byId[$entry->id()] = $entry;
        }

        $this->assertArrayHasKey($siblingId, $byId, 'the valid sibling must survive a bad neighbour');

        $rendered = MemoryBlock::capture($store)->render();
        $this->assertStringContainsString('Valid sibling note', $rendered);

        if ($expectedTags === null) {
            $this->assertCount(1, $entries);
            $this->assertArrayHasKey($badFile, $store->skipped());
            $this->assertNotSame('', $store->skipped()[$badFile]);
            $this->assertStringNotContainsString('Hand-edited body', $rendered);

            return;
        }

        $this->assertCount(2, $entries);
        $this->assertSame([], $store->skipped());
        // The id is the file name, not the frontmatter's `id:` (audit 15d-23).
        $loaded = $byId['hand-edited'] ?? null;
        $this->assertNotNull($loaded);
        $this->assertSame('Hand-edited body', $loaded->content());
        $this->assertSame($expectedTags, $loaded->tags());
        $this->assertStringContainsString('Hand-edited body', $rendered);
    }

    /**
     * @return array<string, array{string, array<string>|null}>
     */
    public static function malformedEntryProvider(): array
    {
        $head = "id: 0123456789abcdef0123456789abcdef\nscope: project\n";

        return [
            // Symfony turns an unquoted date into an int timestamp.
            'unquoted dates load as timestamps' => [
                $head . "type: note\ntags: []\ncreatedAt: 2024-01-01\nmodifiedAt: 2024-01-02",
                [],
            ],
            'missing type is skipped' => [
                $head . "tags: []\ncreatedAt: '2024-01-01T00:00:00+00:00'\nmodifiedAt: '2024-01-01T00:00:00+00:00'",
                null,
            ],
            'a scalar string tag is coerced to a one-element list' => [
                $head . "type: note\ntags: \"x\"\ncreatedAt: '2024-01-01T00:00:00+00:00'\nmodifiedAt: '2024-01-01T00:00:00+00:00'",
                ['x'],
            ],
            'a tag containing --- loads' => [
                $head . "type: note\ntags: ['a---b']\ncreatedAt: '2024-01-01T00:00:00+00:00'\nmodifiedAt: '2024-01-01T00:00:00+00:00'",
                ['a---b'],
            ],
            // The id is the file name (audit 15d-23), so `id:` is optional.
            'a missing id loads under the file name' => [
                "scope: project\ntype: note",
                [],
            ],
            'missing timestamps fall back instead of failing' => [
                $head . 'type: note',
                [],
            ],
            'non-mapping frontmatter is skipped' => [
                "- just\n- a list",
                null,
            ],
            'a non-string tag list member is skipped' => [
                $head . "type: note\ntags: [[nested]]",
                null,
            ],
            'an unparseable date string is skipped' => [
                $head . "type: note\ncreatedAt: 'not a date at all'",
                null,
            ],
        ];
    }

    public function testASkipIsForgottenOnceTheFileIsFixed(): void
    {
        $store = new MemoryStore($this->tempDir);
        $store->add('Valid sibling note', 'project');
        $file = $this->tempDir . '/project/hand-edited.md';

        file_put_contents($file, "---\nscope: project\nid: x\n---\nhello\n");
        $store->list('project');
        $this->assertArrayHasKey($file, $store->skipped());

        file_put_contents($file, "---\nscope: project\nid: x\ntype: note\n---\nhello\n");
        $this->assertCount(2, $store->list('project'));
        $this->assertSame([], $store->skipped());
    }

    /**
     * Audit 15d-22: the store globbed its own location, so a root such as
     * `~/work/[acme]/site` read `[acme]` as a character class. A note was
     * written to disk and then never found again by list(), get(), update(),
     * delete() or unreadable() -- and the class also matched the sibling
     * `x1` here, so the store answered with ANOTHER tree's notes. Both
     * directions are pinned: every operation sees its own note and never the
     * decoy.
     */
    public function testARootContainingGlobMetacharactersListsItsNotes(): void
    {
        $root = $this->tempDir . '/x[1]';
        $decoyRoot = $this->tempDir . '/x1';
        mkdir($root, 0o700, true);
        mkdir($decoyRoot, 0o700, true);
        $decoyId = (new MemoryStore($decoyRoot))->add('DECOY sibling note', 'project');

        $store = new MemoryStore($root);
        $id = $store->add('Bracketed root note', 'project');
        $this->assertFileExists($root . '/project/' . $id . '.md');

        $listed = $store->list('project');
        $this->assertCount(1, $listed);
        $this->assertSame($id, $listed[0]->id());
        $this->assertNotNull($store->loadIndex('project'), 'the index is generated from the same listing');
        $this->assertStringContainsString($id, (string) $store->loadIndex('project'));

        $this->assertSame([$id], array_map(static fn(MemoryEntry $e): string => $e->id(), $store->search('note')));
        $this->assertNull($store->get($decoyId), 'get() must not reach the sibling tree');

        $entry = $store->get($id);
        $this->assertNotNull($entry);
        $this->assertSame('Bracketed root note', $entry->content());

        // A scope move must find and remove the old copy, which it locates
        // the same way get() does.
        $store->update($id, $entry->withContent('Moved note')->withScope('user'));
        $this->assertFileDoesNotExist($root . '/project/' . $id . '.md');
        $this->assertSame([], $store->list('project'));
        $this->assertSame('Moved note', $store->get($id)?->content());

        file_put_contents($root . '/user/broken.md', "no frontmatter\n");
        $this->assertSame([$root . '/user/broken.md'], array_keys($store->unreadable()));

        $store->delete($id);
        $this->assertNull($store->get($id));
        $this->assertFileDoesNotExist($root . '/user/' . $id . '.md');

        $store->clear('user');
        $this->assertFileDoesNotExist($root . '/user/broken.md');
        $this->assertSame([], $store->unreadable());

        $this->assertFileExists($decoyRoot . '/project/' . $decoyId . '.md', 'the sibling tree is never touched');
    }

    /**
     * Audit 15d-23: the listing printed the frontmatter `id:` while delete()
     * looked the note up by file name, so `cp <id>.md deploy-variant.md`
     * listed two notes under ONE id; deleting it removed the original and the
     * copy could then never be edited or deleted. The file name is the id now.
     */
    public function testACopiedNoteFileIsListedAndDeletableUnderItsOwnFilename(): void
    {
        $store = new MemoryStore($this->tempDir);
        $id = $store->add('original note', 'project');
        $original = $this->tempDir . '/project/' . $id . '.md';
        $copy = $this->tempDir . '/project/deploy-variant.md';
        file_put_contents($copy, str_replace('original note', 'VARIANT note', (string) file_get_contents($original)));

        $ids = array_map(static fn(MemoryEntry $e): string => $e->id(), $store->list('project'));
        $this->assertEqualsCanonicalizing(['deploy-variant', $id], $ids, 'each file lists under its own name, never twice under one id');
        $this->assertCount(2, array_unique($ids));

        $store->delete($id);
        $this->assertFileDoesNotExist($original);
        $this->assertSame(['deploy-variant'], array_map(static fn(MemoryEntry $e): string => $e->id(), $store->list('project')));

        $variant = $store->get('deploy-variant');
        $this->assertNotNull($variant);
        $this->assertSame('VARIANT note', $variant->content());

        // An edit rewrites the copied file's stale `id:` from its name.
        $store->update('deploy-variant', $variant->withContent('edited variant'));
        $this->assertStringContainsString('id: deploy-variant', (string) file_get_contents($copy));
        $this->assertStringNotContainsString($id, (string) file_get_contents($copy));
        $this->assertSame('edited variant', $store->get('deploy-variant')?->content());

        $store->delete('deploy-variant');
        $this->assertFileDoesNotExist($copy);
        $this->assertSame([], $store->list('project'));
    }

    /**
     * Audit 15d-23: a hand-written `deploy-notes.md` listed as `deploy-notes`,
     * but get() refused every id that was not 32-hex and delete() threw
     * "Invalid memory entry id format". A readable stem is an id like any other.
     */
    public function testAHandAuthoredReadableIdIsDeletable(): void
    {
        $store = new MemoryStore($this->tempDir);
        $store->add('a minted note', 'project');
        $file = $this->tempDir . '/project/deploy-notes.md';
        file_put_contents($file, "---\nid: deploy-notes\ntype: pattern\nscope: project\n---\nhand note\n");
        $noId = $this->tempDir . '/project/release.v2_steps.md';
        file_put_contents($noId, "---\ntype: pattern\nscope: project\n---\nno id line\n");

        $ids = array_map(static fn(MemoryEntry $e): string => $e->id(), $store->list('project'));
        $this->assertContains('deploy-notes', $ids);
        $this->assertContains('release.v2_steps', $ids, 'a note with no `id:` lists under its file name');
        $this->assertSame([], $store->skipped());

        $this->assertSame('hand note', $store->get('deploy-notes')?->content());
        $store->generateIndex('project');
        $this->assertStringContainsString('[PATTERN] deploy-notes', (string) $store->loadIndex('project'));

        $store->delete('deploy-notes');
        $this->assertFileDoesNotExist($file);
        $this->assertNull($store->get('deploy-notes'));

        $store->delete('release.v2_steps');
        $this->assertFileDoesNotExist($noId);
        $this->assertCount(1, $store->list('project'), 'the minted note is untouched');
    }

    /**
     * A file whose stem cannot be an id is reported, not listed under an id
     * no command would accept back -- the bug 15d-23 is about, one step on.
     */
    public function testAFileWhoseNameIsNotANoteIdIsReportedNotListed(): void
    {
        $store = new MemoryStore($this->tempDir);
        $store->add('a minted note', 'project');
        $file = $this->tempDir . '/project/my deploy notes.md';
        file_put_contents($file, "---\nid: deploy\ntype: pattern\nscope: project\n---\nspaced name\n");

        $this->assertCount(1, $store->list('project'));
        $this->assertArrayHasKey($file, $store->skipped());
        $this->assertStringContainsString('not a usable memory id', $store->skipped()[$file]);
        $this->assertNull($store->get('deploy'), 'the frontmatter id is not an alias for the file');
    }

    /**
     * Audit 15d-23: every write regenerated the index with a fresh
     * `Loaded at:` timestamp, so in the git-visible repo store each note
     * change also dirtied MEMORY.md, and two branches that each added a note
     * conflicted on it. Unchanged notes now mean an unchanged file -- the
     * same bytes, and not even rewritten.
     */
    public function testGenerateIndexIsByteStableWhenNotesAreUnchanged(): void
    {
        $store = new MemoryStore($this->tempDir);
        $store->add('first note', 'project', ['deploy']);
        $store->add('second note', 'project');
        $index = $this->tempDir . '/project/MEMORY.md';
        $before = (string) file_get_contents($index);
        $this->assertStringNotContainsString('Loaded at', $before);
        $this->assertDoesNotMatchRegularExpression('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $before, 'no timestamp of any spelling');

        touch($index, time() - 3600);
        clearstatcache();
        $mtime = filemtime($index);

        $store->generateIndex('project');
        (new MemoryStore($this->tempDir))->generateIndex('project');
        clearstatcache();

        $this->assertSame($before, (string) file_get_contents($index));
        $this->assertSame($mtime, filemtime($index), 'an index whose bytes would not change is not rewritten');

        // A real change still reaches the index.
        $store->add('third note', 'project');
        $this->assertStringContainsString('third note', (string) $store->loadIndex('project'));
    }

    /**
     * The id becomes `<scope dir>/<id>.md`, so its validation is the traversal
     * guard: nothing with a separator, a leading dot, the index's own name, or
     * a trailing newline may reach a path. Planted files at each target show
     * none of them is read, removed or overwritten.
     */
    #[DataProvider('unsafeIdProvider')]
    public function testAnIdThatIsNotASafeFileStemIsRefused(string $id): void
    {
        $store = new MemoryStore($this->tempDir);
        $store->add('a minted note', 'project');
        $note = "---\nid: planted\ntype: pattern\nscope: project\n---\nplanted\n";
        $planted = [
            $this->tempDir . '/x.md',
            $this->tempDir . '/project/.hidden.md',
            $this->tempDir . '/project/a/b.md',
        ];
        mkdir($this->tempDir . '/project/a', 0o700);
        foreach ($planted as $file) {
            file_put_contents($file, $note);
        }
        $index = $this->tempDir . '/project/MEMORY.md';
        $indexBytes = (string) file_get_contents($index);

        $this->assertNull($store->get($id));

        try {
            $store->delete($id);
            $this->fail('delete() accepted an unsafe id');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid memory entry id format', $e->getMessage());
        }

        try {
            $store->update($id, MemoryEntry::new(type: 'pattern', content: 'clobber', scope: 'project'));
            $this->fail('update() accepted an unsafe id');
        } catch (\InvalidArgumentException) {
        }

        foreach ($planted as $file) {
            $this->assertSame($note, (string) file_get_contents($file), basename($file) . ' was touched');
        }
        $this->assertSame($indexBytes, (string) file_get_contents($index), 'the index was touched');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeIdProvider(): array
    {
        return [
            'parent traversal' => ['../x'],
            'dot dot' => ['..'],
            'dot' => ['.'],
            'leading dot' => ['.hidden'],
            'separator' => ['a/b'],
            'backslash' => ['a\\b'],
            'empty' => [''],
            'trailing newline' => ["x\n"],
            'nul byte' => ["x\0"],
            'index stem' => ['MEMORY'],
            'index stem, other case' => ['memory'],
            'longer than 64' => [str_repeat('a', 65)],
        ];
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
