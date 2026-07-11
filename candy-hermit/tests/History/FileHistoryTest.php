<?php

declare(strict_types=1);

namespace SugarCraft\Hermit\Tests\History;

use SugarCraft\Hermit\FilteredItem;
use SugarCraft\Hermit\History\FileHistory;
use PHPUnit\Framework\TestCase;

/**
 * Verify FileHistory persistent history functionality.
 */
final class FileHistoryTest extends TestCase
{
    private string $tmpPath;
    private string $baseDir;

    protected function setUp(): void
    {
        $this->tmpPath = \sys_get_temp_dir() . '/hermit_history_' . \uniqid() . '.jsonl';
        $this->baseDir = \sys_get_temp_dir() . '/hermit_base_' . \uniqid();
        \mkdir($this->baseDir, 0700, true);
    }

    protected function tearDown(): void
    {
        if (\is_file($this->tmpPath)) {
            \unlink($this->tmpPath);
        }
        foreach (\glob($this->baseDir . '/*') ?: [] as $f) {
            @\unlink($f);
        }
        @\rmdir($this->baseDir);
        // Clean up an escape artifact in the parent, in case confinement is bypassed.
        @\unlink(\dirname($this->baseDir) . '/hermit_evil.jsonl');
    }

    public function testAppendStoresItem(): void
    {
        $history = new FileHistory($this->tmpPath);
        $item = new FilteredItem(1, 'apple');

        $history->append($item);

        $this->assertFileExists($this->tmpPath);
    }

    public function testAllReturnsEmptyArrayWhenNoFile(): void
    {
        $history = new FileHistory($this->tmpPath);
        $this->assertSame([], $history->all());
    }

    public function testAllReturnsStoredItems(): void
    {
        $history = new FileHistory($this->tmpPath);
        $history->append(new FilteredItem(1, 'apple'));
        $history->append(new FilteredItem(2, 'banana'));

        $items = $history->all();

        $this->assertCount(2, $items);
        $this->assertSame(1, $items[0]->number());
        $this->assertSame('apple', $items[0]->value());
        $this->assertSame(2, $items[1]->number());
        $this->assertSame('banana', $items[1]->value());
    }

    public function testClearRemovesFile(): void
    {
        $history = new FileHistory($this->tmpPath);
        $history->append(new FilteredItem(1, 'test'));

        $history->clear();

        $this->assertFalse(\is_file($this->tmpPath));
    }

    public function testPathReturnsCorrectPath(): void
    {
        $history = new FileHistory($this->tmpPath);
        $this->assertSame($this->tmpPath, $history->path());
    }

    public function testAllSkipsCorruptLine(): void
    {
        // Write a good line, a bad JSON line, then another good line.
        \file_put_contents(
            $this->tmpPath,
            \json_encode(['n' => 1, 'v' => 'first']) . "\n"
                . "{bad json\n"
                . \json_encode(['n' => 2, 'v' => 'third']) . "\n",
        );

        $history = new FileHistory($this->tmpPath);
        $items = $history->all();

        $this->assertCount(2, $items);
        $this->assertSame('first', $items[0]->value());
        $this->assertSame(1, $items[0]->number());
        $this->assertSame('third', $items[1]->value());
        $this->assertSame(2, $items[1]->number());
    }

    public function testAllClosesHandleEvenOnCorruptLines(): void
    {
        // Write corrupt lines that would throw JsonException during read.
        \file_put_contents($this->tmpPath, "{bad}\n{also bad}\n");

        $history = new FileHistory($this->tmpPath);

        // Calling all() repeatedly must not exhaust file descriptors.
        // If the handle leaked, a second call would fail or behave differently.
        $first = $history->all();
        $second = $history->all();

        $this->assertSame([], $first);
        $this->assertSame([], $second);
    }

    public function testBaseDirConfinementRejectsTraversalEscape(): void
    {
        // A '..' traversal that resolves outside the base dir must be rejected at
        // construction. Revert the confinement check and this escape is allowed —
        // the constructor would silently accept a path that append() writes into
        // the parent directory, outside the intended base.
        $this->expectException(\InvalidArgumentException::class);
        new FileHistory($this->baseDir . '/../hermit_evil.jsonl', $this->baseDir);
    }

    public function testBaseDirConfinementRejectsAbsoluteOutsideBase(): void
    {
        // An absolute path pointing entirely outside the base is rejected too.
        $this->expectException(\InvalidArgumentException::class);
        new FileHistory('/etc/hermit_evil.jsonl', $this->baseDir);
    }

    public function testBaseDirConfinementAllowsInBasePath(): void
    {
        // A legitimate path inside the base dir is accepted and works end to end.
        $history = new FileHistory($this->baseDir . '/hist.jsonl', $this->baseDir);
        $history->append(new FilteredItem(1, 'apple'));

        $items = $history->all();
        $this->assertCount(1, $items);
        $this->assertSame('apple', $items[0]->value());
        // The resolved path lives inside the base directory.
        $this->assertStringStartsWith(\realpath($this->baseDir), $history->path());
    }

    public function testMultipleAppendsPersist(): void
    {
        $history = new FileHistory($this->tmpPath);

        for ($i = 1; $i <= 5; $i++) {
            $history->append(new FilteredItem($i, "item_$i"));
        }

        $items = $history->all();
        $this->assertCount(5, $items);

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($i + 1, $items[$i]->number());
            $this->assertSame("item_" . ($i + 1), $items[$i]->value());
        }
    }
}
