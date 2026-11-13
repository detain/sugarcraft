<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use SugarCraft\Files\UndoAction;
use SugarCraft\Core\Undo\UndoActionType;
use PHPUnit\Framework\TestCase;

final class UndoActionTest extends TestCase
{
    public function testDeleteCreatesDeleteAction(): void
    {
        $items = [
            ['path' => '/tmp/test.txt', 'isDir' => false, 'content' => 'hello', 'stat' => []],
        ];
        $action = UndoAction::delete($items);

        $this->assertSame('items deleted: 1', $action->description);
        $this->assertSame($items, $action->items);
        $this->assertSame(UndoActionType::Delete, $action->type);
    }

    public function testDeleteMultipleItems(): void
    {
        $items = [
            ['path' => '/tmp/a.txt', 'isDir' => false, 'content' => 'a', 'stat' => []],
            ['path' => '/tmp/b.txt', 'isDir' => false, 'content' => 'b', 'stat' => []],
            ['path' => '/tmp/c.txt', 'isDir' => false, 'content' => 'c', 'stat' => []],
        ];
        $action = UndoAction::delete($items);

        $this->assertSame('items deleted: 3', $action->description);
        $this->assertCount(3, $action->items);
        $this->assertSame(UndoActionType::Delete, $action->type);
    }

    public function testDeleteWithDirectories(): void
    {
        $items = [
            ['path' => '/tmp/mydir', 'isDir' => true, 'content' => null, 'stat' => []],
        ];
        $action = UndoAction::delete($items);

        $this->assertSame('items deleted: 1', $action->description);
        $this->assertTrue($action->items[0]['isDir']);
        $this->assertSame(UndoActionType::Delete, $action->type);
    }

    public function testRenameCreatesRenameAction(): void
    {
        $renames = [
            '/tmp/old.txt' => '/tmp/new.txt',
        ];
        $action = UndoAction::rename($renames);

        $this->assertSame('items renamed: 1', $action->description);
        $this->assertSame($renames, $action->items);
        $this->assertSame(UndoActionType::Rename, $action->type);
    }

    public function testRenameMultipleItems(): void
    {
        $renames = [
            '/tmp/a.txt' => '/tmp/b.txt',
            '/tmp/c.txt' => '/tmp/d.txt',
        ];
        $action = UndoAction::rename($renames);

        $this->assertSame('items renamed: 2', $action->description);
        $this->assertCount(2, $action->items);
        $this->assertSame(UndoActionType::Rename, $action->type);
    }

    public function testMoveCreatesMoveAction(): void
    {
        $moves = [
            '/tmp/file.txt' => '/home/file.txt',
        ];
        $action = UndoAction::move($moves);

        $this->assertSame('items moved: 1', $action->description);
        $this->assertSame($moves, $action->items);
        $this->assertSame(UndoActionType::Move, $action->type);
    }

    public function testMoveMultipleItems(): void
    {
        $moves = [
            '/tmp/a.txt' => '/home/a.txt',
            '/tmp/b.txt' => '/home/b.txt',
        ];
        $action = UndoAction::move($moves);

        $this->assertSame('items moved: 2', $action->description);
        $this->assertCount(2, $action->items);
        $this->assertSame(UndoActionType::Move, $action->type);
    }

    public function testCopyCreatesCopyAction(): void
    {
        $copies = [
            '/tmp/source.txt' => '/tmp/dest.txt',
        ];
        $action = UndoAction::copy($copies);

        $this->assertSame('items copied: 1', $action->description);
        $this->assertSame($copies, $action->items);
        $this->assertSame(UndoActionType::Copy, $action->type);
    }

    public function testCopyMultipleItems(): void
    {
        $copies = [
            '/tmp/a.txt' => '/tmp/a_copy.txt',
            '/tmp/b.txt' => '/tmp/b_copy.txt',
        ];
        $action = UndoAction::copy($copies);

        $this->assertSame('items copied: 2', $action->description);
        $this->assertCount(2, $action->items);
        $this->assertSame(UndoActionType::Copy, $action->type);
    }

    public function testEmptyDeleteAction(): void
    {
        $action = UndoAction::delete([]);

        $this->assertSame('items deleted: 0', $action->description);
        $this->assertSame([], $action->items);
        $this->assertSame(UndoActionType::Delete, $action->type);
    }

    public function testEmptyRenameAction(): void
    {
        $action = UndoAction::rename([]);

        $this->assertSame('items renamed: 0', $action->description);
        $this->assertSame([], $action->items);
        $this->assertSame(UndoActionType::Rename, $action->type);
    }

    public function testEmptyMoveAction(): void
    {
        $action = UndoAction::move([]);

        $this->assertSame('items moved: 0', $action->description);
        $this->assertSame([], $action->items);
        $this->assertSame(UndoActionType::Move, $action->type);
    }

    public function testEmptyCopyAction(): void
    {
        $action = UndoAction::copy([]);

        $this->assertSame('items copied: 0', $action->description);
        $this->assertSame([], $action->items);
        $this->assertSame(UndoActionType::Copy, $action->type);
    }

    public function testDescriptionIsReadonly(): void
    {
        $action = UndoAction::delete([['path' => '/tmp/x', 'isDir' => false, 'content' => null, 'stat' => []]]);

        $this->assertSame('items deleted: 1', $action->description);
        $this->assertSame('items deleted: 1', $action->description); // Ensure it's consistent
        $this->assertSame(UndoActionType::Delete, $action->type);
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
