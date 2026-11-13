<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Files\ConfirmState;
use SugarCraft\Files\Entry;
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

    /**
     * Redo of a delete whose target has ALREADY vanished (delete -> undo ->
     * external rm -> redo) must not count an error: the delete's goal state
     * holds, so the item is a no-op success. Pre-fix, redoDelete() tried to
     * rename a nonexistent source (impossible), fell through to removePath()
     * (false for missing paths), and reported "redo ... with 1 error(s)".
     */
    public function testRedoOfAlreadyGoneFileIsNotAnError(): void
    {
        $file = $this->tmpDir . '/gone-once.txt';
        file_put_contents($file, 'bye');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, ' '));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'd'));
        [$deleted] = $m->update(new KeyMsg(KeyType::Char, 'y'));
        [$undone] = $deleted->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertFileExists($file, 'undo must restore before the external rm');

        unlink($file);

        [$redone] = $undone->update(new KeyMsg(KeyType::Char, 'y', false, true));

        $this->assertFileDoesNotExist($file);
        $this->assertStringNotContainsString('error', $redone->status, 'status: ' . $redone->status);
        $this->assertStringContainsString('redone', $redone->status, 'status: ' . $redone->status);
    }

    /**
     * docs-fix-1023 count integrity: the deleted status counted the ARMED
     * name list, inflating the number when a stale selection entry was
     * filtered ('..'/null/''). Applied actions and skipped names are
     * reported separately.
     */
    public function testDeleteCountsSkippedNamesSeparately(): void
    {
        file_put_contents($this->tmpDir . '/keep.txt', 'x');

        $base = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        $stale = new \SugarCraft\Files\Pane($base->left->cwd, $base->left->entries, $base->left->cursor, ['..' => true, 'keep.txt' => true]);
        $m = Manager::builder()
            ->withLeft($stale)
            ->withRight($base->right)
            ->withLister($this->lister)
            ->build();

        [$armed] = $m->update(new KeyMsg(KeyType::Char, 'd'));
        $this->assertSame(ConfirmState::DeleteSelected, $armed->confirm);
        [$deleted] = $armed->update(new KeyMsg(KeyType::Char, 'y'));

        $this->assertSame('deleted 1 entries (1 skipped)', $deleted->status,
            'count must report ACTIONS APPLIED, not the arm-time intention');
        $this->assertFileDoesNotExist($this->tmpDir . '/keep.txt');
    }

    /**
     * docs-fix-1023 EXDEV polarity flip: a delete whose bytes were removed
     * PERMANENTLY (cross-device fallback, trash '') could not be undone,
     * yet undo reported FULL SUCCESS — a data-loss lie on the screen the
     * user watches. Undo must now count it as an error.
     */
    public function testUndoOfPermanentDeleteReportsAnError(): void
    {
        $ghost = $this->tmpDir . '/already-gone.txt';   // never existed: bytes are gone
        $left = \SugarCraft\Files\Pane::open($this->tmpDir, $this->lister);
        $m = Manager::builder()
            ->withLeft($left)
            ->withRight($left)
            ->withLister($this->lister)
            ->withUndoStack([\SugarCraft\Files\UndoAction::delete([
                ['path' => $ghost, 'isDir' => false, 'trash' => ''],
            ])])
            ->build();

        [$undone] = $m->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertStringContainsString('error', $undone->status,
            'unrecoverable undo must NOT report success: ' . $undone->status);
        $this->assertStringContainsString('1', $undone->status);
        $this->assertFileDoesNotExist($ghost);
    }

    /**
     * CF-04 guard: a temp-dir delete is SAME-device, so the trash rename
     * succeeds and armDelete must predict the ORDINARY (undo-able) prompt,
     * never the PERMANENT variant. Proves deleteTouchesForeignDevice() does
     * not misfire on the common path.
     */
    public function testSameDeviceDeleteArmsTheOrdinaryRecoverablePrompt(): void
    {
        file_put_contents($this->tmpDir . '/same-dev.txt', 'x');
        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, ' '));
        [$armed] = $m->update(new KeyMsg(KeyType::Char, 'd'));
        $this->assertSame(ConfirmState::DeleteSelected, $armed->confirm);
        $this->assertStringNotContainsString('PERMANENT', $armed->status,
            'same-device trash move must not advertise a permanent delete: ' . $armed->status);
    }

    /**
     * CF-04 made the permanent-record honest (isDir captured BEFORE
     * removePath — the pre-cure bug asked is_dir('') and got FALSE for
     * every permanent directory). CF-08/035 then settles what undo may do
     * with such a record: NOTHING rebuildable — a directory re-created
     * without its payload is a hollow shell advertising a recovery that
     * never happened, so the leg counts as an error, no skeleton appears,
     * and the unrecorded 0700 mode rewrite is gone with the branch.
     */
    public function testPermanentDirectoryUndoReportsErrorWithoutHollowSkeleton(): void
    {
        $dir = $this->tmpDir . '/lost-dir';                 // gone (permanently removed)
        $left = \SugarCraft\Files\Pane::open($this->tmpDir, $this->lister);
        $m = Manager::builder()
            ->withLeft($left)
            ->withRight($left)
            ->withLister($this->lister)
            ->withUndoStack([\SugarCraft\Files\UndoAction::delete([
                // CF-04 shape: isDir TRUE + trash '' (permanent) for a directory.
                ['path' => $dir, 'isDir' => true, 'trash' => ''],
            ])])
            ->build();

        [$undone] = $m->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertStringContainsString('error', $undone->status, 'status: ' . $undone->status);
        $this->assertDirectoryDoesNotExist($dir, 'CF-08/035: no hollow skeleton restore');
        $this->assertTrue($undone->canUndo(), 'CF-08/067: the failed action never left its stack');
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
    // ------------------------------------------------------- CF-08 undo engine

    /**
     * 018: the file_exists-only trash gate never saw a broken symlink (it
     * follows), so undo of a deleted broken link reported failure while the
     * link sat unclaimed in trash. The two-term check restores the link.
     */
    public function testBrokenSymlinkRoundTrip(): void
    {
        $probe = $this->tmpDir . '/.probe-link';
        if (!@symlink('nowhere-xyz', $probe)) {
            $this->markTestSkipped('symlinks unavailable for this user/platform');
        }
        @unlink($probe);
        $link = $this->tmpDir . '/dangling';
        symlink('nowhere-xyz', $link);

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));       // onto the link
        $this->assertSame('dangling', $m->activePane()->currentEntry()?->name);
        [$armed] = $m->update(new KeyMsg(KeyType::Char, 'd'));
        [$gone] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        $this->assertFalse(is_link($link), 'delete trashed the broken link');

        [$undone] = $gone->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertTrue(is_link($link), 'undo must restore the broken link itself');
        $this->assertSame('nowhere-xyz', readlink($link));
        $this->assertStringNotContainsString('error', $undone->status, 'status: ' . $undone->status);
    }

    /**
     * 007: undo renames ONLY onto a free path. A file the user created at
     * the original location since the delete must never be eaten — the
     * bytes wait in trash and the status names the collision.
     */
    public function testUndoRefusesToEatAFileCreatedAfterTheDelete(): void
    {
        $path = $this->tmpDir . '/report.txt';
        $blob = $this->tmpDir . '/trash-blob';
        file_put_contents($path, 'today bytes');
        file_put_contents($blob, 'original bytes');

        $left = \SugarCraft\Files\Pane::open($this->tmpDir, $this->lister);
        $m = Manager::builder()
            ->withLeft($left)
            ->withRight($left)
            ->withLister($this->lister)
            ->withUndoStack([\SugarCraft\Files\UndoAction::delete([
                ['path' => $path, 'isDir' => false, 'trash' => $blob],
            ])])
            ->build();

        [$undone] = $m->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertStringContainsString('occupied', $undone->status, 'status: ' . $undone->status);
        $this->assertSame('today bytes', file_get_contents($path), 'the new file is untouched');
        $this->assertFileExists($blob, 'the original stays in trash — recoverable after the user clears the collision');
    }

    /**
     * 067: when EVERY leg errored the action stays on its own stack — no
     * cross-stack transfer may advertise a reversibility it does not have.
     */
    public function testAllLegsFailedUndoStaysPressable(): void
    {
        $path = $this->tmpDir . '/gone-for-good';
        $left = \SugarCraft\Files\Pane::open($this->tmpDir, $this->lister);
        $m = Manager::builder()
            ->withLeft($left)
            ->withRight($left)
            ->withLister($this->lister)
            ->withUndoStack([\SugarCraft\Files\UndoAction::delete([
                ['path' => $path, 'isDir' => false, 'trash' => ''],
            ])])
            ->build();

        [$once] = $m->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertStringContainsString('error', $once->status, 'status: ' . $once->status);
        $this->assertTrue($once->canUndo(), 'the failed action never moved to redo');
        $this->assertFalse($once->canRedo(), 'a failed undo must not manufacture a redo');

        [$twice] = $once->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertStringContainsString('error', $twice->status, 'the retry hits the same honest leg');
    }

    /**
     * CF-25 (closes 037): a long-but-legal 240-char basename must not defeat
     * the reversible-delete story. Before the NAME_MAX cap, the trash rename
     * hit ENAMETOOLONG, fell through to the permanent removePath path with
     * trash='', and `u` only errored later. The hex prefix carries uniqueness;
     * the recorded trash leg must exist and undo must restore the FULL name.
     */
    public function testLongButLegalNameStaysReversibleThroughTrash(): void
    {
        $longName = str_repeat('x', 240) . '.txt';
        $file = $this->tmpDir . '/' . $longName;
        file_put_contents($file, 'long-name payload');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        // Walk to the LAST entry (the long-named file sorts after short ones):
        for ($i = 0; $i < 64; $i++) {
            [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
            if ($m->activePane()->entries[$m->activePane()->cursor]->name === $longName) {
                break;
            }
        }
        $this->assertSame(
            $longName,
            $m->activePane()->entries[$m->activePane()->cursor]->name,
            'fixture cursor must land on the long-named file',
        );

        [$m] = $m->update(new KeyMsg(KeyType::Char, ' '));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'd'));
        [$deleted] = $m->update(new KeyMsg(KeyType::Char, 'y'));

        $this->assertFileDoesNotExist($file);
        $item = $deleted->undoStack[array_key_last($deleted->undoStack)]->items[0];
        $this->assertNotSame('', $item['trash'], 'NAME_MAX overflow must not force permanent removal');
        $this->assertFileExists($item['trash']);
        $this->assertLessThanOrEqual(255, strlen(basename($item['trash'])), 'trash component fits NAME_MAX');

        [$restored] = $deleted->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertFileExists($file, 'undo must restore the full-length original name');
        $this->assertSame('long-name payload', file_get_contents($file));
        $this->assertStringNotContainsString('error', $restored->status, 'status: ' . $restored->status);
    }

    /**
     * CF-20 (closes audit 028): with both panes on one cwd (the startup
     * default) a delete re-homes bytes that BOTH twins list — before the
     * fix only the active pane re-read and its twin kept rendering the
     * deleted file until Tab + `r`.
     */
    public function testDeleteWithTwinCwdRefreshesBothPanes(): void
    {
        $dir = $this->tmpDir . '/twin';
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/gone.txt', 'x');

        $m = Manager::start($dir, $dir, $this->lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));   // onto gone.txt
        [$m] = $m->update(new KeyMsg(KeyType::Char, ' '));   // select
        [$armed] = $m->update(new KeyMsg(KeyType::Char, 'd'));
        $this->assertSame(ConfirmState::DeleteSelected, $armed->confirm);
        [$done] = $armed->update(new KeyMsg(KeyType::Char, 'y'));

        $leftNames = array_map(static fn(Entry $e): string => $e->name, $done->left->entries);
        $rightNames = array_map(static fn(Entry $e): string => $e->name, $done->right->entries);
        $this->assertNotContains('gone.txt', $leftNames);
        $this->assertNotContains('gone.txt', $rightNames, 'the twin pane must not keep showing a deleted file');
    }
}
