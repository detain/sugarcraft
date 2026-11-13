<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use SugarCraft\Files\UndoAction;
use SugarCraft\Core\Undo\UndoActionType;
use PHPUnit\Framework\TestCase;

final class UndoActionTest extends TestCase
{
    /**
     * CF-41 (audit 053): every delete fixture here used to carry the
     * pre-trash schema {path,isDir,content,stat} — keys production has not
     * written since the CF-04 trash move made undo a byte-rename, not a
     * content replay. The record is a LOCATION ({'trash'} = where the
     * bytes live now, '' = permanently gone), so that is what these
     * fixtures, and the engine round-trip at the bottom, pin.
     */
    public function testDeleteCreatesDeleteAction(): void
    {
        $items = [
            ['path' => '/tmp/test.txt', 'isDir' => false, 'trash' => '/tmp/trash/test.txt'],
        ];
        $action = UndoAction::delete($items);

        $this->assertSame('items deleted: 1', $action->description);
        $this->assertSame($items, $action->items);
        $this->assertSame(UndoActionType::Delete, $action->type);
    }

    public function testDeleteMultipleItems(): void
    {
        $items = [
            ['path' => '/tmp/a.txt', 'isDir' => false, 'trash' => '/tmp/trash/a.txt'],
            ['path' => '/tmp/b.txt', 'isDir' => false, 'trash' => '/tmp/trash/b.txt'],
            ['path' => '/tmp/c.txt', 'isDir' => false, 'trash' => ''],   // EXDEV-permanent leg
        ];
        $action = UndoAction::delete($items);

        $this->assertSame('items deleted: 3', $action->description);
        $this->assertCount(3, $action->items);
        $this->assertSame(UndoActionType::Delete, $action->type);
    }

    public function testDeleteWithDirectories(): void
    {
        $items = [
            ['path' => '/tmp/mydir', 'isDir' => true, 'trash' => '/tmp/trash/mydir'],
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
        $action = UndoAction::delete([['path' => '/tmp/x', 'isDir' => false, 'trash' => '/tmp/trash/x']]);

        $this->assertSame('items deleted: 1', $action->description);
        $this->assertSame('items deleted: 1', $action->description); // Ensure it's consistent
        $this->assertSame(UndoActionType::Delete, $action->type);
    }

    public function testItemsPreservesOriginalStructure(): void
    {
        $originalItems = [
            ['path' => '/tmp/test.txt', 'isDir' => false, 'trash' => '/tmp/trash/test.txt'],
        ];
        $action = UndoAction::delete($originalItems);

        $this->assertSame($originalItems, $action->items);
        $this->assertSame('/tmp/test.txt', $action->items[0]['path']);
        $this->assertSame('/tmp/trash/test.txt', $action->items[0]['trash']);
    }

    /**
     * CF-41 (audit 053): the fixtures above describe the record; this one
     * PROVES production writes it. Delete a real file through the real
     * dispatch (j, d, y) and read the action back off the stack: the keys
     * must be exactly the live {path,isDir,trash} schema — the record's
     * trash leg must point at bytes that actually exist — and undo must
     * resurrect them by LOCATION rename, not by replaying stored content
     * (a stored content string would be the dead schema sneaking back).
     */
    public function testProductionDeleteWritesTheLiveSchemaAndRestoresByLocation(): void
    {
        $dir = sys_get_temp_dir() . '/sugarcraft-uaschema-' . uniqid('', true);
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/victim.txt', 'LOCATION-RESTORED');

        $m = \SugarCraft\Files\Manager::start($dir, $dir, \SugarCraft\Files\FsLister::lister());
        [$m] = $m->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Char, 'j'));
        [$armed] = $m->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Char, 'd'));
        [$deleted] = $armed->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Char, 'y'));

        $this->assertCount(1, $deleted->undoStack);
        $record = $deleted->undoStack[0];
        $this->assertSame(UndoActionType::Delete, $record->type);
        $this->assertSame(['path', 'isDir', 'trash'], array_keys($record->items[0]), 'the production schema is path/isDir/trash — nothing else');
        $this->assertSame($dir . '/victim.txt', $record->items[0]['path']);
        $this->assertFalse($record->items[0]['isDir']);
        $trash = $record->items[0]['trash'];
        // No conditional escape (the pattern CF-41/055 just executed): a
        // same-device tmp root trash-renames, always — ManagerDeleteTest's
        // CF-04 guard pins that premise from the other side.
        $this->assertNotSame('', $trash, 'a temp-dir delete trashes; an empty location here means the trash engine regressed');
        $this->assertFileExists($trash, 'the record names where the bytes actually live');
        $this->assertStringContainsString('LOCATION-RESTORED', (string) file_get_contents($trash));

        [$undone] = $deleted->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Char, 'u'));
        $this->assertFileExists($dir . '/victim.txt');
        $this->assertSame('LOCATION-RESTORED', file_get_contents($dir . '/victim.txt'));
        $this->assertStringContainsString('undone', $undone->status);

        // Sweep the trash root this test created (own-PID prefix only, CF-41/074 law).
        foreach (glob(sys_get_temp_dir() . '/candyfiles-trash-' . getmypid() . '-*') ?: [] as $root) {
            foreach (@scandir($root) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    @unlink($root . '/' . $name);
                }
            }
            @rmdir($root);
        }
        @unlink($dir . '/victim.txt');
        @rmdir($dir);
    }
}
