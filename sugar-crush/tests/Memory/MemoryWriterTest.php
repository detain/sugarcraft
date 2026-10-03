<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Memory;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\MemoryScope;
use SugarCraft\Crush\Context\ProjectMemoryWriter;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Memory\MemoryWriter;

/**
 * Roadmap 5.1-2: the one router `/memory add` and the `Memory` tool share.
 */
final class MemoryWriterTest extends TestCase
{
    private string $dir;

    private string $home;

    private string $root;

    private MemoryStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush_memwriter_' . bin2hex(random_bytes(6));
        $this->home = $this->dir . '/home';
        $this->root = $this->dir . '/repo';
        mkdir($this->home, 0o700, true);
        mkdir($this->root, 0o700, true);
        $this->store = new MemoryStore($this->home);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->dir);
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
            is_dir($full) && !is_link($full) ? $this->rmrf($full) : @unlink($full);
        }
        @rmdir($path);
    }

    public function testAProjectNoteGoesToTheRepository(): void
    {
        $saved = MemoryWriter::new($this->store, $this->root)->save('repo convention', 'project', 'convention', ['ci']);

        self::assertTrue($saved->inRepository);
        self::assertFalse($saved->fellBackToHome);
        $entry = ProjectMemoryWriter::forRoot($this->root)?->store()->get($saved->id);
        self::assertNotNull($entry);
        self::assertSame('convention', $entry->type());
        self::assertSame(['ci'], $entry->tags());
        self::assertNull($this->store->get($saved->id), 'not also in the home store');
    }

    public function testAProjectNoteFallsBackToTheHomeStoreAndSaysSo(): void
    {
        $saved = MemoryWriter::new($this->store, '')->save('no repository here');

        self::assertFalse($saved->inRepository);
        self::assertTrue($saved->fellBackToHome);
        self::assertSame('project', $this->store->get($saved->id)?->scope());
    }

    public function testAUserNoteGoesToTheHomeStore(): void
    {
        $saved = MemoryWriter::new($this->store, $this->root)->save('answer tersely', 'user', 'preference');

        self::assertFalse($saved->inRepository);
        self::assertFalse($saved->fellBackToHome);
        self::assertSame('preference', $this->store->get($saved->id)?->type());
        self::assertSame('user', $this->store->get($saved->id)?->scope());
    }

    public function testBadInputIsRefused(): void
    {
        $writer = MemoryWriter::new($this->store, $this->root);
        foreach ([['  ', 'project', 'pattern'], ['x', 'galaxy', 'pattern'], ['x', 'user', 'rumour']] as [$content, $scope, $type]) {
            try {
                $writer->save($content, $scope, $type);
                self::fail("accepted {$content}/{$scope}/{$type}");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAnIdResolvesInTheRepositoryFirst(): void
    {
        $writer = MemoryWriter::new($this->store, $this->root);
        $saved = $writer->save('the checkout copy');
        $repo = $writer->repository();
        self::assertNotNull($repo);

        // A home twin under the same id: the repository copy is the one the
        // prompt index shows, so it is the one an id names.
        $twin = $repo->get($saved->id)?->withContent('the home copy')->withScope('project');
        self::assertNotNull($twin);
        $this->store->update($saved->id, $twin);

        [$entry, $owner] = $writer->locate($saved->id) ?? [null, null];
        self::assertSame('the checkout copy', $entry?->content());
        self::assertSame($repo->get($saved->id)?->id(), $owner?->get($saved->id)?->id());
        self::assertFalse($owner?->writesIndex(), 'the owning store is the repository store');
    }

    public function testReplaceNeedsExactlyOneOccurrence(): void
    {
        $writer = MemoryWriter::new($this->store, '');
        $id = $writer->save('run tests from the lib root; the lib root holds phpunit.xml', 'user')->id;

        try {
            $writer->replace($id, 'lib root', 'package root');
            self::fail('an ambiguous old_str was applied');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('2 times', $e->getMessage());
        }

        try {
            $writer->replace($id, 'nowhere', 'x');
            self::fail('a missing old_str was applied');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('does not occur', $e->getMessage());
        }

        $writer->replace($id, 'holds phpunit.xml', 'holds phpunit.xml.dist');
        self::assertSame('run tests from the lib root; the lib root holds phpunit.xml.dist', $this->store->get($id)?->content());
    }

    public function testDeleteAndRecall(): void
    {
        $writer = MemoryWriter::new($this->store, $this->root);
        $repoId = $writer->save('deploy with make ship')->id;
        $homeId = $writer->save('ship on fridays never', 'user')->id;

        $found = array_map(static fn ($e): string => $e->id(), $writer->recall('ship'));
        sort($found);
        $expected = [$repoId, $homeId];
        sort($expected);
        self::assertSame($expected, $found);

        self::assertTrue($writer->delete($repoId));
        self::assertFalse($writer->delete($repoId));
        self::assertNull($writer->locate($repoId));
    }

    public function testTheHomeStoreIsOpenedOnlyWhenUsed(): void
    {
        $opened = 0;
        $writer = MemoryWriter::new(function () use (&$opened): MemoryStore {
            ++$opened;

            return $this->store;
        }, '');
        self::assertSame(0, $opened, 'building the writer opens nothing');

        $writer->save('first', 'user');
        $writer->save('second', 'user');
        self::assertSame(1, $opened);
    }

    public function testAnUnavailableHomeStoreIsAnErrorNotACrash(): void
    {
        $writer = MemoryWriter::new(static fn (): ?MemoryStore => null, '');

        $this->expectException(\RuntimeException::class);
        $writer->save('nowhere to go', 'user');
    }

    public function testTheSlashCommandAndTheWriterAgreeOnTheHomeScopeDirectory(): void
    {
        $id = MemoryWriter::new($this->store, '')->save('scoped', 'user')->id;

        self::assertSame([$id], array_map(static fn ($e): string => $e->id(), $this->store->list(MemoryScope::User)));
    }
}
