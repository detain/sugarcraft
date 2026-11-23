<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Memory;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\MemoryScope;
use SugarCraft\Code\Context\MemoryBlock;
use SugarCraft\Code\Memory\MemoryEntry;
use SugarCraft\Code\Memory\MemoryStore;
use SugarCraft\Code\Memory\NoteParseMemo;

/**
 * AUD §7 rank 40: a memory note is decoded once per process while it is
 * unchanged, however many listings (MemoryBlock, MemoryRecallBlock, /memory)
 * read it.
 *
 * Two kinds of pin, as for the config memo: the memo HITS when nothing moved
 * (the decode counter stays put), and every way a note can move still reaches
 * the next read: a store write, a same-size same-mtime hand edit a stat gate
 * would miss, and an mtime-only touch that re-dates an undated note.
 */
final class NoteParseMemoTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/candy-note-memo-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
        NoteParseMemo::resetForTesting();
    }

    protected function tearDown(): void
    {
        NoteParseMemo::resetForTesting();
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function testARepeatListingOfUnchangedNotesIsServedFromTheMemo(): void
    {
        $store = new MemoryStore($this->dir);
        $store->add('alpha', 'project');
        $store->add('beta', 'project');

        $first = $store->list(MemoryScope::Project);
        $decoded = NoteParseMemo::decodes();
        $second = $store->list(MemoryScope::Project);

        self::assertCount(2, $first);
        self::assertEquals($first, $second, 'a memo hit answers exactly what the decode did');
        self::assertSame($decoded, NoteParseMemo::decodes(), 'nothing moved, so nothing was re-decoded');
    }

    public function testTheMemoIsSharedAcrossStoreInstances(): void
    {
        (new MemoryStore($this->dir))->add('alpha', 'project');

        (new MemoryStore($this->dir))->list(MemoryScope::Project);
        $decoded = NoteParseMemo::decodes();
        $entries = (new MemoryStore($this->dir))->list(MemoryScope::Project);

        self::assertSame('alpha', $entries[0]->content());
        self::assertSame($decoded, NoteParseMemo::decodes(), 'a rebuilt store (ProjectMemoryWriter::forRoot) still hits');
    }

    public function testMemoryBlockCaptureDecodesEachNoteOnce(): void
    {
        $store = new MemoryStore($this->dir);
        $store->add('a project note', 'project');
        $store->add('a user note', 'user');

        $first = MemoryBlock::capture($store);
        $decoded = NoteParseMemo::decodes();
        $second = MemoryBlock::capture($store);

        self::assertSame($first->render(), $second->render());
        self::assertSame($decoded, NoteParseMemo::decodes(), 'a second capture over unchanged notes parses nothing');
    }

    public function testAStoreWriteInvalidatesTheNote(): void
    {
        $store = new MemoryStore($this->dir);
        $id = $store->add('before', 'project');
        $store->list(MemoryScope::Project);
        $decoded = NoteParseMemo::decodes();

        $entry = $store->get($id);
        self::assertInstanceOf(MemoryEntry::class, $entry);
        $store->update($id, $entry->withContent('after!'));

        self::assertSame('after!', $store->list(MemoryScope::Project)[0]->content());
        self::assertGreaterThan($decoded, NoteParseMemo::decodes());
    }

    public function testASameSizeSameMtimeHandEditIsSeenAtTheNextRead(): void
    {
        $store = new MemoryStore($this->dir);
        $id = $store->add('four', 'project');
        $file = (string) $store->notePath($id);
        touch($file, time() - 100);
        clearstatcache(true, $file);
        $mtime = (int) filemtime($file);
        $store->list(MemoryScope::Project);

        file_put_contents($file, str_replace('four', 'five', (string) file_get_contents($file)));
        touch($file, $mtime);

        self::assertSame('five', $store->list(MemoryScope::Project)[0]->content(), 'the byte key sees what a stat gate would miss');
    }

    public function testAnMtimeOnlyTouchReDatesAnUndatedNote(): void
    {
        $dir = $this->dir . '/project';
        mkdir($dir, 0o700, true);
        $file = $dir . '/undated.md';
        file_put_contents($file, "---\ntype: note\nscope: project\n---\nbody");
        touch($file, 1_000_000_000);

        $store = new MemoryStore($this->dir);
        self::assertSame(1_000_000_000, $store->list(MemoryScope::Project)[0]->createdAt()->getTimestamp());

        touch($file, 1_500_000_000);
        self::assertSame(
            1_500_000_000,
            $store->list(MemoryScope::Project)[0]->createdAt()->getTimestamp(),
            'the mtime an undated note is dated by is part of the key',
        );
    }

    public function testARefusedNoteKeepsItsReasonOnAMemoHit(): void
    {
        $dir = $this->dir . '/project';
        mkdir($dir, 0o700, true);
        $file = $dir . '/broken.md';
        file_put_contents($file, "no frontmatter here\n");

        $store = new MemoryStore($this->dir);
        $store->list(MemoryScope::Project);
        $reason = $store->skipped()[$file] ?? null;
        $decoded = NoteParseMemo::decodes();

        $again = new MemoryStore($this->dir);
        self::assertSame([], $again->list(MemoryScope::Project));
        self::assertNotNull($reason);
        self::assertSame($reason, $again->skipped()[$file] ?? null, 'the memoised refusal reports the same reason');
        self::assertSame($decoded, NoteParseMemo::decodes());
    }

    public function testTheMemoIsBounded(): void
    {
        $entry = MemoryEntry::new(type: 'note', content: 'x', scope: 'project', id: 'x');
        for ($i = 0; $i <= NoteParseMemo::MAX_ENTRIES; $i++) {
            NoteParseMemo::decode('/memo/' . $i . '.md', 1, 'x', static fn(): MemoryEntry => $entry);
        }

        self::assertLessThanOrEqual(NoteParseMemo::MAX_ENTRIES, NoteParseMemo::size());
        self::assertGreaterThan(0, NoteParseMemo::size());
    }
}
