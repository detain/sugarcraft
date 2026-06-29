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

    protected function setUp(): void
    {
        $this->tmpPath = \sys_get_temp_dir() . '/hermit_history_' . \uniqid() . '.jsonl';
    }

    protected function tearDown(): void
    {
        if (\is_file($this->tmpPath)) {
            \unlink($this->tmpPath);
        }
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
