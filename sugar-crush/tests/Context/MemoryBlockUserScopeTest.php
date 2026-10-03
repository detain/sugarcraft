<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\MemoryScope;
use SugarCraft\Crush\Context\MemoryBlock;
use SugarCraft\Crush\Memory\MemoryStore;

/**
 * Roadmap 0.6 / decision D4: user-scope notes reach the prompt, listed FIRST
 * under their own label and capped at {@see MemoryBlock::USER_MAX_ENTRIES}
 * notes / {@see MemoryBlock::USER_MAX_BYTES} bytes INSIDE the block's one
 * {@see MemoryBlock::MAX_ENTRIES} / {@see MemoryBlock::MAX_BYTES} budget, so
 * the operator's cross-project notes can never crowd out the project's own.
 */
final class MemoryBlockUserScopeTest extends TestCase
{
    private const PERSONAL_LABEL = 'Kept by the user across all of their projects (user scope)';

    private string $dir;

    private MemoryStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush_memblock_user_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
        $this->store = new MemoryStore($this->dir);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->dir);
        $this->rmrf($this->dir . '_local');
    }

    private function rmrf(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->rmrf($full) : @unlink($full);
        }
        @rmdir($path);
    }

    public function testTheSubBudgetSitsInsideTheTotalAndAdmitsOneWholeNote(): void
    {
        $this->assertLessThan(MemoryBlock::MAX_ENTRIES, MemoryBlock::USER_MAX_ENTRIES);
        $this->assertLessThan(MemoryBlock::MAX_BYTES, MemoryBlock::USER_MAX_BYTES);
        $this->assertLessThanOrEqual(
            MemoryBlock::USER_MAX_BYTES,
            MemoryBlock::MAX_ENTRY_BYTES,
            'a single clipped note must always fit the user sub-budget, or the first user note could zero the group',
        );
        $this->assertSame(4, MemoryBlock::USER_MAX_ENTRIES, 'D4: at most four user notes');
        $this->assertSame(1024, MemoryBlock::USER_MAX_BYTES, 'D4: at most 1 KB of user notes');
    }

    public function testAUserNoteAloneReachesTheBlockUnderItsOwnLabel(): void
    {
        $this->store->add('Prefer short answers.', MemoryScope::User);

        $block = MemoryBlock::capture($this->store);
        $rendered = $block->render();

        $this->assertCount(1, $block->userEntries());
        $this->assertSame([], $block->entries());
        $this->assertStringStartsWith('<project-memory>', $rendered);
        $this->assertStringContainsString(self::PERSONAL_LABEL, $rendered);
        $this->assertStringContainsString('- [pattern] Prefer short answers.', $rendered);
        $this->assertStringContainsString(
            sprintf('at most %d notes and %d bytes of that', MemoryBlock::USER_MAX_ENTRIES, MemoryBlock::USER_MAX_BYTES),
            $rendered,
            'the header states the sub-cap from the constants that enforce it',
        );
    }

    public function testUserNotesAreListedBeforeEveryProjectGroup(): void
    {
        $this->store->add('Home project convention.', MemoryScope::Project);
        $this->store->add('Personal preference.', MemoryScope::User);

        $localDir = $this->dir . '_local';
        mkdir($localDir, 0o700, true);
        $local = new MemoryStore($localDir);
        $local->add('Repository note.', MemoryScope::Project);

        $rendered = MemoryBlock::capture($this->store, $local)->render();

        $personal = strpos($rendered, 'Personal preference.');
        $repository = strpos($rendered, 'Repository note.');
        $home = strpos($rendered, 'Home project convention.');
        $this->assertNotFalse($personal);
        $this->assertNotFalse($repository);
        $this->assertNotFalse($home);
        $this->assertLessThan($repository, $personal);
        $this->assertLessThan($home, $repository);
    }

    public function testTheRepositoryStoreIsNeverAskedForUserNotes(): void
    {
        // A clone cannot speak as the operator: a user-scope note planted in a
        // repo-local tree must not reach the prompt.
        $localDir = $this->dir . '_local';
        mkdir($localDir, 0o700, true);
        $local = new MemoryStore($localDir);
        $local->add('Planted by the checkout as if it were the user.', MemoryScope::User);
        $this->store->add('A project note.', MemoryScope::Project);

        $block = MemoryBlock::capture($this->store, $local);

        $this->assertSame([], $block->userEntries());
        $this->assertStringNotContainsString('Planted by the checkout', $block->render());
    }

    public function testUserNotesAreCappedAtFourAndProjectNotesKeepTheRest(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->store->add("personal note {$i}", MemoryScope::User);
        }
        for ($i = 1; $i <= 12; $i++) {
            $this->store->add("project note {$i}", MemoryScope::Project);
        }

        $rendered = MemoryBlock::capture($this->store)->render();

        $this->assertSame(MemoryBlock::USER_MAX_ENTRIES, preg_match_all('/personal note \d+/', $rendered));
        $this->assertSame(
            MemoryBlock::MAX_ENTRIES - MemoryBlock::USER_MAX_ENTRIES,
            preg_match_all('/project note \d+/', $rendered),
            'the user notes spend their slots out of the one total, never more',
        );
        $this->assertStringContainsString(' 6 further note(s) were omitted by those limits.', $rendered);
    }

    public function testFewerUserNotesLeaveTheirUnusedSlotsToTheProject(): void
    {
        $this->store->add('one personal note', MemoryScope::User);
        for ($i = 1; $i <= 12; $i++) {
            $this->store->add("project note {$i}", MemoryScope::Project);
        }

        $rendered = MemoryBlock::capture($this->store)->render();

        $this->assertSame(MemoryBlock::MAX_ENTRIES - 1, preg_match_all('/project note \d+/', $rendered));
    }

    public function testUserNotesAreCappedAtTheirByteBudget(): void
    {
        // Each line ~410 bytes: two fit in 1024, a third does not.
        for ($i = 1; $i <= 3; $i++) {
            $this->store->add("personal {$i} " . str_repeat('x', 400), MemoryScope::User);
        }
        $this->store->add('project survives', MemoryScope::Project);

        $rendered = MemoryBlock::capture($this->store)->render();

        $this->assertSame(2, preg_match_all('/personal \d x+/', $rendered));
        $this->assertStringContainsString('project survives', $rendered);
        $this->assertStringContainsString(' 1 further note(s) were omitted by those limits.', $rendered);
    }

    public function testAnUnreadableUserNoteIsAnnouncedWithoutCallingItAProjectNote(): void
    {
        // add() creates the scope directory the broken note is planted in.
        $this->store->add('A readable preference.', MemoryScope::User);
        file_put_contents($this->dir . '/user/broken.md', "no frontmatter here\n");

        $block = MemoryBlock::capture($this->store);
        $rendered = $block->render();

        $this->assertCount(1, $block->skipped());
        $this->assertStringContainsString('1 memory note(s) could not be read and are not included here: broken.md (', $rendered);
        $this->assertStringNotContainsString('project memory note(s)', $rendered);
    }
}
