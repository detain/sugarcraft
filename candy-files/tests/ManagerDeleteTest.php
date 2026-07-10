<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Files\ConfirmState;
use SugarCraft\Files\Manager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the trash-on-delete path.
 *
 * The delete flow is supposed to MOVE the target into a per-process trash
 * directory (via @rename) so the operation is undoable. A prior bug never
 * created that trash directory, so the first delete's rename failed and the
 * code fell through to a permanent removePath() — destroying the file and
 * making undo a silent no-op. These tests assert the file is genuinely
 * recoverable, not just that a status string says "deleted"/"undone".
 */
final class ManagerDeleteTest extends TestCase
{
    private string $tmpDir;
    private \Closure $lister;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sugarcraft-delete-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
        $this->lister = \SugarCraft\Files\FsLister::lister();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        // Sweep any leftover trash roots this process may have created.
        foreach (glob(sys_get_temp_dir() . '/candyfiles-trash-*') ?: [] as $trash) {
            $this->removeDir($trash);
        }
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        $items = @scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->removeDir($path . '/' . $item);
        }
        @rmdir($path);
    }

    /**
     * The FIRST delete through the real Manager path must move the file into
     * trash (recoverable) rather than permanently unlinking it.
     */
    public function testFirstDeleteMovesFileToTrashInsteadOfDestroyingIt(): void
    {
        $file = $this->tmpDir . '/keep-me.txt';
        file_put_contents($file, 'precious content');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        // Cursor 0 is the '..' parent sentinel; move down to keep-me.txt.
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, ' ')); // select
        $this->assertNotEmpty($m->activePane()->selected, 'file should be selected');

        // Arm + confirm the delete.
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'd'));
        $this->assertSame(ConfirmState::DeleteSelected, $m->confirm);
        [$deleted] = $m->update(new KeyMsg(KeyType::Char, 'y'));

        // The original is gone from its location...
        $this->assertFileDoesNotExist($file);
        // ...but the delete is undoable and the bytes still live in trash.
        $this->assertTrue($deleted->canUndo(), 'delete must be undoable');

        $action = $deleted->undoStack[array_key_last($deleted->undoStack)];
        $item = $action->items[0];
        $this->assertNotSame('', $item['trash'], 'trash path must be recorded (empty means permanent removal)');
        $this->assertFileExists($item['trash'], 'file must physically survive in trash');
        $this->assertSame(
            'precious content',
            file_get_contents($item['trash']),
            'trashed file must retain its original content',
        );
    }

    /**
     * Undo after the first delete restores the file to its original location
     * with its content intact — proving it was never permanently destroyed.
     */
    public function testFirstDeleteIsUndoable(): void
    {
        $file = $this->tmpDir . '/undo-me.txt';
        file_put_contents($file, 'restore this');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, ' '));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'd'));
        [$deleted] = $m->update(new KeyMsg(KeyType::Char, 'y'));

        $this->assertFileDoesNotExist($file);

        // Undo it.
        [$undone] = $deleted->update(new KeyMsg(KeyType::Char, 'u'));

        $this->assertFileExists($file, 'undo must restore the deleted file');
        $this->assertSame('restore this', file_get_contents($file), 'restored content must match');
        $this->assertFalse($undone->canUndo());
        $this->assertTrue($undone->canRedo());
    }

    /**
     * Redo of a delete (delete -> undo -> redo) must re-trash the restored file
     * through redoDelete()'s guarded rename path. This drives the SUCCESS side
     * of that guard (trash path non-null, rename succeeds), proving the happy
     * path degrades to trash rather than crashing on a null trash path or
     * silently unlinking.
     */
    public function testRedoOfDeleteReTrashesFile(): void
    {
        $file = $this->tmpDir . '/redo-me.txt';
        file_put_contents($file, 'redo content');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, ' '));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'd'));
        [$deleted] = $m->update(new KeyMsg(KeyType::Char, 'y'));
        $this->assertFileDoesNotExist($file);

        // Undo restores the file to its original location...
        [$undone] = $deleted->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertFileExists($file, 'undo must restore the file before redo');
        $this->assertTrue($undone->canRedo());

        // ...and redo (Ctrl+Y) re-deletes it, moving it back into trash.
        [$redone] = $undone->update(new KeyMsg(KeyType::Char, 'y', false, true));

        $this->assertFileDoesNotExist($file, 'redo must re-delete the restored file');
        $this->assertFalse($redone->canRedo(), 'nothing left to redo after redo');
        $this->assertTrue($redone->canUndo(), 'the re-delete is itself undoable');
        // The bytes must survive in trash — proving the guarded rename branch
        // ran, not the permanent-removal fallback.
        $this->assertSame(
            'redo content',
            $this->findInTrash('redo content'),
            'redone delete must move the file into trash, not destroy it',
        );
    }

    /** Content of the first trashed file matching $needle, or '' if none is found. */
    private function findInTrash(string $needle): string
    {
        foreach (glob(sys_get_temp_dir() . '/candyfiles-trash-*') ?: [] as $trash) {
            foreach (@scandir($trash) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $full = $trash . '/' . $name;
                if (is_file($full) && file_get_contents($full) === $needle) {
                    return $needle;
                }
            }
        }
        return '';
    }
}
