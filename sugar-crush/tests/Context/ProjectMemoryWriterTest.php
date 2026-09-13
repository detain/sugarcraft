<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\MemoryScope;
use SugarCraft\Crush\Context\ProjectMemoryWriter;

/**
 * The repo-local project-note writer, E25 piece 2.
 *
 * Two contracts dominate: the resolver pair's duties (READ never creates,
 * WRITE may, BOTH refuse a corner that does not resolve inside the root) and
 * the write guard rails (fail-fast before a byte is stored). The fold that
 * consumes the resulting store is {@see MemoryBlockTest}'s merge pin and
 * MemoryPromptWiringTest's end-to-end hop.
 */
final class ProjectMemoryWriterTest extends TestCase
{
    private string $root;

    /** @var list<string> */
    private array $scratch = [];

    protected function setUp(): void
    {
        $this->root = $this->scratchDir('crush_projmem_');
    }

    protected function tearDown(): void
    {
        foreach ($this->scratch as $dir) {
            $this->wipeTree($dir);
        }
        $this->scratch = [];
    }

    private function scratchDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        mkdir($dir, 0o700, true);
        $this->scratch[] = $dir;

        return $dir;
    }

    private function wipeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_link($full) || !is_dir($full) ? @unlink($full) : $this->wipeTree($full);
        }
        @rmdir($path);
    }

    public function testTheWriterLandsTheNoteInTheRepoLocalTree(): void
    {
        $writer = ProjectMemoryWriter::createForRoot($this->root);
        $this->assertNotNull($writer);

        $id = $writer?->write('This repository targets PHP 8.3.', ['build']);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id ?? '');
        $this->assertFileExists($this->root . '/' . ProjectMemoryWriter::RELATIVE_DIRECTORY . "/project/{$id}.md");
    }

    public function testWhitespaceContentIsRefused(): void
    {
        $writer = ProjectMemoryWriter::createForRoot($this->root);
        $this->assertNotNull($writer);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        $writer?->write("  \n\t ");
    }

    public function testOversizedContentIsRefused(): void
    {
        $writer = ProjectMemoryWriter::createForRoot($this->root);
        $this->assertNotNull($writer);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('8192');

        $writer?->write(str_repeat('a', ProjectMemoryWriter::MAX_CONTENT_BYTES + 1));
    }

    public function testTheCeilingIsInclusive(): void
    {
        $writer = ProjectMemoryWriter::createForRoot($this->root);
        $this->assertNotNull($writer);

        $id = $writer?->write(str_repeat('a', ProjectMemoryWriter::MAX_CONTENT_BYTES));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id ?? '');
    }

    public function testTagsRoundTripThroughTheStore(): void
    {
        $writer = ProjectMemoryWriter::createForRoot($this->root);
        $entry = $writer?->store()->get($writer->write('tagged note', ['build', 'ci']));

        $this->assertSame(['build', 'ci'], $entry?->tags());
        $this->assertSame('tagged note', $entry?->content());
    }

    public function testForRootNeverCreatesTheTree(): void
    {
        $absent = ProjectMemoryWriter::forRoot($this->root);
        $this->assertNull($absent, 'a reader must not litter a pristine checkout');
        $this->assertDirectoryDoesNotExist($this->root . '/' . ProjectMemoryWriter::RELATIVE_DIRECTORY);

        $writer = ProjectMemoryWriter::createForRoot($this->root);
        $reader = ProjectMemoryWriter::forRoot($this->root);

        $this->assertNotNull($writer);
        $this->assertNotNull($reader);
        $this->assertSame($writer?->directory(), $reader?->directory());
        $this->assertNull(ProjectMemoryWriter::forRoot(''), 'an empty root would anchor containment at the process CWD');
    }

    public function testCreateForRootRefusesAbsentAndEmptyRoots(): void
    {
        $this->assertNull(ProjectMemoryWriter::createForRoot(''));
        $this->assertNull(ProjectMemoryWriter::createForRoot($this->root . '/no-such-tree'));
    }

    public function testASymlinkOutOfTheTreeIsRefusedByBothResolvers(): void
    {
        $outside = $this->scratchDir('crush_projmem_out_');
        mkdir($outside . '/memory', 0o700, true);
        mkdir($this->root . '/.sugar-crush', 0o700, true);
        @rmdir($this->root . '/.sugar-crush');
        symlink($outside, $this->root . '/.sugar-crush');

        $this->assertNull(ProjectMemoryWriter::forRoot($this->root));
        $this->assertNull(ProjectMemoryWriter::createForRoot($this->root));
        $this->assertSame([], array_diff(scandir($outside . '/memory') ?: [], ['.', '..']));
    }

    public function testTheStoreExposesWhatWasWritten(): void
    {
        $writer = ProjectMemoryWriter::createForRoot($this->root);
        $writer?->write('a repo-local convention');

        $entries = $writer?->store()->list(MemoryScope::Project);

        $this->assertCount(1, $entries);
        $this->assertSame('a repo-local convention', $entries[0]->content());
    }
}
