<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Memory;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\MemoryScope;
use SugarCraft\Crush\Memory\MemoryEntry;
use SugarCraft\Crush\Memory\MemoryStore;

/**
 * Audit 15d-05: the home store's `project` scope is keyed by the canonical
 * project root, and unkeyed legacy notes bind to the first project that
 * reads them.
 */
final class MemoryStoreProjectKeyTest extends TestCase
{
    private string $tmp;
    private string $memory;
    private string $projA;
    private string $projB;

    protected function setUp(): void
    {
        $this->tmp = (string) realpath(sys_get_temp_dir()) . '/sc_memkey_' . bin2hex(random_bytes(6));
        $this->memory = $this->tmp . '/memory';
        $this->projA = $this->tmp . '/a/app';
        $this->projB = $this->tmp . '/b/app';
        foreach ([$this->memory, $this->projA, $this->projB] as $dir) {
            mkdir($dir, 0o700, true);
        }
    }

    protected function tearDown(): void
    {
        self::rmrf($this->tmp);
    }

    public function testAProjectNoteWrittenForOneRootIsInvisibleToAnother(): void
    {
        $a = MemoryStore::forProject($this->memory, $this->projA);
        $b = MemoryStore::forProject($this->memory, $this->projB);

        $id = $a->add('projA: deploy with kubectl apply -f prod/', MemoryScope::Project);

        self::assertCount(1, $a->list(MemoryScope::Project));
        self::assertSame([], $b->list(MemoryScope::Project));
        self::assertNull($b->get($id));
        self::assertSame([], $b->search('kubectl'));
        self::assertNull($b->loadIndex(MemoryScope::Project));
        self::assertNotNull($a->loadIndex(MemoryScope::Project));

        // The user scope is not project-shaped and stays shared.
        $a->add('a personal preference', MemoryScope::User);
        self::assertCount(1, $b->list(MemoryScope::User));
    }

    public function testTheKeyFollowsTheCanonicalRootNotItsSpelling(): void
    {
        self::assertSame(
            MemoryStore::projectKeyFor($this->projA),
            MemoryStore::projectKeyFor($this->projA . '/./'),
        );
        // Same last segment, different repository: different directories.
        self::assertNotSame(MemoryStore::projectKeyFor($this->projA), MemoryStore::projectKeyFor($this->projB));
        self::assertMatchesRegularExpression('/\Aapp-[0-9a-f]{16}\z/', MemoryStore::projectKeyFor($this->projA));
        self::assertSame(
            $this->memory . '/project/' . MemoryStore::projectKeyFor($this->projA),
            MemoryStore::forProject($this->memory, $this->projA)->projectDirectory(),
        );
    }

    public function testLegacyUnkeyedNotesBindToTheFirstProjectThatReadsThem(): void
    {
        $legacy = new MemoryStore($this->memory);
        $id = $legacy->add('written before notes were keyed', MemoryScope::Project);
        self::assertFileExists($this->memory . '/project/MEMORY.md');

        $a = MemoryStore::forProject($this->memory, $this->projA);
        self::assertSame([$id], array_map(static fn (MemoryEntry $e): string => $e->id(), $a->list(MemoryScope::Project)));
        self::assertFileDoesNotExist($this->memory . '/project/' . $id . '.md');
        self::assertFileExists($a->projectDirectory() . '/' . $id . '.md');
        self::assertFileDoesNotExist($this->memory . '/project/MEMORY.md', 'the legacy index described notes that moved');
        self::assertFileExists($a->projectDirectory() . '/MEMORY.md');

        // A later project does not get them too.
        self::assertSame([], MemoryStore::forProject($this->memory, $this->projB)->list(MemoryScope::Project));

        // The binding is reported once, by whichever instance asks first.
        $fresh = MemoryStore::forProject($this->memory, $this->projA);
        self::assertSame([$id . '.md'], $fresh->takeBoundLegacyNotes());
        self::assertSame([], $fresh->takeBoundLegacyNotes());
        self::assertSame([], MemoryStore::forProject($this->memory, $this->projA)->takeBoundLegacyNotes());
    }

    public function testBindingNeverOverwritesANoteTheKeyedDirectoryAlreadyHolds(): void
    {
        $a = MemoryStore::forProject($this->memory, $this->projA);
        $a->update('shared-id', MemoryEntry::new(type: 'pattern', content: 'keyed copy', scope: 'project', id: 'shared-id'));

        file_put_contents(
            $this->memory . '/project/shared-id.md',
            "---\ntype: pattern\nscope: project\n---\nlegacy copy\n",
        );

        $again = MemoryStore::forProject($this->memory, $this->projA);
        self::assertSame('keyed copy', $again->get('shared-id')?->content());
        self::assertFileExists($this->memory . '/project/shared-id.md');
        self::assertSame([], $again->takeBoundLegacyNotes());
    }

    public function testAMalformedKeyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MemoryStore($this->memory, projectKey: '../escape');
    }

    private static function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::rmrf($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
