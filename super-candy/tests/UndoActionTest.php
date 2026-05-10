<?php

declare(strict_types=1);

namespace SugarCraft\SuperCandy\Tests;

use SugarCraft\SuperCandy\UndoAction;
use PHPUnit\Framework\TestCase;

final class UndoActionTest extends TestCase
{
    public function testDeleteCreatesDeleteAction(): void
    {
        $items = [
            ['path' => '/tmp/test.txt', 'isDir' => false, 'content' => 'hello', 'stat' => []],
        ];
        $action = UndoAction::delete($items);

        $this->assertSame('delete 1 item(s)', $action->description);
        $this->assertSame($items, $action->items);
    }

    public function testDeleteMultipleItems(): void
    {
        $items = [
            ['path' => '/tmp/a.txt', 'isDir' => false, 'content' => 'a', 'stat' => []],
            ['path' => '/tmp/b.txt', 'isDir' => false, 'content' => 'b', 'stat' => []],
            ['path' => '/tmp/c.txt', 'isDir' => false, 'content' => 'c', 'stat' => []],
        ];
        $action = UndoAction::delete($items);

        $this->assertSame('delete 3 item(s)', $action->description);
        $this->assertCount(3, $action->items);
    }

    public function testDeleteWithDirectories(): void
    {
        $items = [
            ['path' => '/tmp/mydir', 'isDir' => true, 'content' => null, 'stat' => []],
        ];
        $action = UndoAction::delete($items);

        $this->assertSame('delete 1 item(s)', $action->description);
        $this->assertTrue($action->items[0]['isDir']);
    }

    public function testRenameCreatesRenameAction(): void
    {
        $renames = [
            '/tmp/old.txt' => '/tmp/new.txt',
        ];
        $action = UndoAction::rename($renames);

        $this->assertSame('rename 1 item(s)', $action->description);
        $this->assertSame($renames, $action->items);
    }

    public function testRenameMultipleItems(): void
    {
        $renames = [
            '/tmp/a.txt' => '/tmp/b.txt',
            '/tmp/c.txt' => '/tmp/d.txt',
        ];
        $action = UndoAction::rename($renames);

        $this->assertSame('rename 2 item(s)', $action->description);
        $this->assertCount(2, $action->items);
    }

    public function testMoveCreatesMoveAction(): void
    {
        $moves = [
            '/tmp/file.txt' => '/home/file.txt',
        ];
        $action = UndoAction::move($moves);

        $this->assertSame('move 1 item(s)', $action->description);
        $this->assertSame($moves, $action->items);
    }

    public function testMoveMultipleItems(): void
    {
        $moves = [
            '/tmp/a.txt' => '/home/a.txt',
            '/tmp/b.txt' => '/home/b.txt',
        ];
        $action = UndoAction::move($moves);

        $this->assertSame('move 2 item(s)', $action->description);
        $this->assertCount(2, $action->items);
    }

    public function testCopyCreatesCopyAction(): void
    {
        $copies = [
            '/tmp/source.txt' => '/tmp/dest.txt',
        ];
        $action = UndoAction::copy($copies);

        $this->assertSame('copy 1 item(s)', $action->description);
        $this->assertSame($copies, $action->items);
    }

    public function testCopyMultipleItems(): void
    {
        $copies = [
            '/tmp/a.txt' => '/tmp/a_copy.txt',
            '/tmp/b.txt' => '/tmp/b_copy.txt',
        ];
        $action = UndoAction::copy($copies);

        $this->assertSame('copy 2 item(s)', $action->description);
        $this->assertCount(2, $action->items);
    }

    public function testMkdirCreatesMkdirAction(): void
    {
        $paths = ['/tmp/newdir'];
        $action = UndoAction::mkdir($paths);

        $this->assertSame('mkdir 1 item(s)', $action->description);
        $this->assertCount(1, $action->items);
        $this->assertSame('/tmp/newdir', $action->items[0]['path']);
        $this->assertTrue($action->items[0]['isDir']);
    }

    public function testMkdirMultiplePaths(): void
    {
        $paths = ['/tmp/dir1', '/tmp/dir2', '/tmp/dir3'];
        $action = UndoAction::mkdir($paths);

        $this->assertSame('mkdir 3 item(s)', $action->description);
        $this->assertCount(3, $action->items);
        foreach ($action->items as $item) {
            $this->assertTrue($item['isDir']);
        }
    }

    public function testMkdirTransformsPathsToItemsFormat(): void
    {
        $paths = ['/tmp/mydir'];
        $action = UndoAction::mkdir($paths);

        // mkdir transforms simple paths into the full item format
        $this->assertArrayHasKey('path', $action->items[0]);
        $this->assertArrayHasKey('isDir', $action->items[0]);
        $this->assertSame('/tmp/mydir', $action->items[0]['path']);
        $this->assertTrue($action->items[0]['isDir']);
    }

    public function testEmptyDeleteAction(): void
    {
        $action = UndoAction::delete([]);

        $this->assertSame('delete 0 item(s)', $action->description);
        $this->assertSame([], $action->items);
    }

    public function testEmptyRenameAction(): void
    {
        $action = UndoAction::rename([]);

        $this->assertSame('rename 0 item(s)', $action->description);
        $this->assertSame([], $action->items);
    }

    public function testEmptyMoveAction(): void
    {
        $action = UndoAction::move([]);

        $this->assertSame('move 0 item(s)', $action->description);
        $this->assertSame([], $action->items);
    }

    public function testEmptyCopyAction(): void
    {
        $action = UndoAction::copy([]);

        $this->assertSame('copy 0 item(s)', $action->description);
        $this->assertSame([], $action->items);
    }

    public function testEmptyMkdirAction(): void
    {
        $action = UndoAction::mkdir([]);

        $this->assertSame('mkdir 0 item(s)', $action->description);
        $this->assertSame([], $action->items);
    }

    public function testDescriptionIsReadonly(): void
    {
        $action = UndoAction::delete([['path' => '/tmp/x', 'isDir' => false, 'content' => null, 'stat' => []]]);

        $this->assertSame('delete 1 item(s)', $action->description);
        $this->assertSame('delete 1 item(s)', $action->description); // Ensure it's consistent
    }

    public function testItemsPreservesOriginalStructure(): void
    {
        $originalItems = [
            ['path' => '/tmp/test.txt', 'isDir' => false, 'content' => 'content here', 'stat' => ['size' => 12]],
        ];
        $action = UndoAction::delete($originalItems);

        $this->assertSame($originalItems, $action->items);
        $this->assertSame('/tmp/test.txt', $action->items[0]['path']);
        $this->assertSame('content here', $action->items[0]['content']);
        $this->assertSame(['size' => 12], $action->items[0]['stat']);
    }
}
