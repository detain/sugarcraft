<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Undo\UndoActionType;
use SugarCraft\Files\ConfirmState;
use SugarCraft\Files\Entry;
use SugarCraft\Files\Manager;
use SugarCraft\Files\Msg\CopyCompletedMsg;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;

final class ManagerCopyTest extends TestCase
{
    private string $tmpDir;
    private \Closure $lister;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sugarcraft-copy-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
        $this->lister = \SugarCraft\Files\FsLister::lister();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $path): void
    {
        $items = @scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            // is_link check first: never recurse through a symlinked dir
            // (a self-referencing link would otherwise loop / delete its target).
            if (is_link($full) || !is_dir($full)) {
                @unlink($full);
            } else {
                $this->removeDir($full);
            }
        }
        @rmdir($path);
    }

    public function testArmCopyWithNoSelectionShowsError(): void
    {
        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        [$next] = $m->update(new KeyMsg(KeyType::Char, 'c'));
        $this->assertStringContainsString('nothing to copy', $next->status);
    }

    public function testArmCopyWithCurrentEntryShowsConfirm(): void
    {
        // Create a file in tmpDir
        file_put_contents($this->tmpDir . '/testfile.txt', 'content');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        // Cursor starts at 0 (parent sentinel '..'), move down to get to testfile.txt
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$armed] = $m->update(new KeyMsg(KeyType::Char, 'c'));

        $this->assertSame(ConfirmState::CopySelected, $armed->confirm);
        $this->assertStringContainsString('copy', $armed->status);
        $this->assertStringContainsString('testfile.txt', $armed->status);
        $this->assertNotNull($armed->pendingOpDest);
        $this->assertSame('copy', $armed->pendingOpType);
    }

    /**
     * End-to-end copy through the async chain — the audit's HIGH item.
     *
     * Before the fix this test was tautological: it never executed the Cmd,
     * asserted the SOURCE files still existed, and `canUndo() || $cmd` was
     * true by construction. CopyCompletedMsg also implemented no Core\Msg,
     * so Program's `?Msg` dispatch TypeError'd on resolution and update()
     * additionally returned `self` where `array` was declared — completion
     * NEVER reached the Model. This test now mirrors candy-core Program's
     * AsyncCmd wiring exactly (promise->then(?Msg -> update()), then
     * Loop::run() drains the futureTick I/O) and pins the whole chain:
     * files land, pane refreshes, undo finalizes, honest status.
     */
    public function testCopyConfirmedWithYCompletesEndToEnd(): void
    {
        $srcDir = $this->tmpDir . '/source';
        $dstDir = $this->tmpDir . '/dest';
        mkdir($srcDir . '/subdir', 0755, true);
        mkdir($dstDir, 0755, true);
        file_put_contents($srcDir . '/subdir/nested.txt', 'nested');

        $m = Manager::start($srcDir, $dstDir, $this->lister);
        // Cursor: '..' sentinel → file1 slot… walk down onto 'subdir'.
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, ' '));  // select subdir
        [$armed] = $m->update(new KeyMsg(KeyType::Char, 'c'));
        $this->assertSame(ConfirmState::CopySelected, $armed->confirm);

        [$pending, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        $this->assertInstanceOf(\Closure::class, $cmd);
        $async = $cmd();                       // Program runs the Cmd closure…
        $this->assertInstanceOf(AsyncCmd::class, $async); // …and dispatches an AsyncCmd
        // Nothing has been copied yet — the work is deferred to the loop.
        $this->assertFileDoesNotExist($dstDir . '/subdir/nested.txt');

        // …then feed the resolution back through update(), like the
        // promise->then(?Msg -> dispatch) hop in candy-core Program.
        // Pre-fix, CopyCompletedMsg was not a Msg and this very step threw.
        $this->extractResolved($async);
        $finalState = null;
        $async->promise->then(function (?Msg $resolved) use ($pending, &$finalState): void {
            if ($resolved !== null) {
                [$finalState] = $pending->update($resolved);
            }
        });
        Loop::run();

        $this->assertInstanceOf(Manager::class, $finalState);
        // 1. The bytes really landed in the destination.
        $this->assertFileExists($dstDir . '/subdir/nested.txt');
        $this->assertSame('nested', file_get_contents($dstDir . '/subdir/nested.txt'));
        // 2. Honest completion status (not the optimistic arm-time one).
        $this->assertSame('copied 1 entries', $finalState->status);
        $this->assertSame(ConfirmState::None, $finalState->confirm);
        // 3. Undo finalized — the dead performCopy used to push this.
        $this->assertTrue($finalState->canUndo());
        $top = $finalState->undoStack[array_key_last($finalState->undoStack)];
        $this->assertSame(UndoActionType::Copy, $top->type);
        $this->assertArrayHasKey($srcDir . '/subdir', $top->items);
        // 4. Both panes re-read: the destination listing shows the copy.
        $dstNames = array_map(static fn(Entry $e): string => $e->name, $finalState->right->entries);
        $this->assertContains('subdir', $dstNames, 'destination pane must refresh after completion');
    }

    /** Drive one futureTick round and capture what the promise resolves to. */
    private function extractResolved(AsyncCmd $async): void
    {
        $resolved = null;
        $delivered = false;
        $async->promise->then(static function (?Msg $m) use (&$resolved, &$delivered): void {
            $delivered = true;
            $resolved = $m;
        });
        Loop::run();
        $this->assertTrue($delivered, 'copy promise never delivered to the dispatcher');
        $this->assertInstanceOf(Msg::class, $resolved, 'Program dispatches through ?Msg — a null/non-Msg resolution throws');
    }

    /**
     * The audit's ":712 errors discarded" half: per-file failures counted
     * by the promise chain must surface in the final status instead of
     * vanishing. Source removed between arm and loop drain ⇒ 1 error.
     */
    public function testCopyErrorsSurfaceInCompletionStatus(): void
    {
        $srcDir = $this->tmpDir . '/src2';
        $dstDir = $this->tmpDir . '/dst2';
        mkdir($srcDir, 0755, true);
        mkdir($dstDir, 0755, true);
        file_put_contents($srcDir . '/vanishing.txt', 'x');

        $m = Manager::start($srcDir, $dstDir, $this->lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'c'));
        [$pending, $cmd] = $m->update(new KeyMsg(KeyType::Char, 'y'));
        $async = $cmd();

        // Deferred I/O has not run yet (futureTick) — pull the rug out.
        unlink($srcDir . '/vanishing.txt');

        $finalState = null;
        $async->promise->then(function (?Msg $resolved) use ($pending, &$finalState): void {
            if ($resolved !== null) {
                [$finalState, $followUp] = $pending->update($resolved);
                $this->assertNull($followUp, 'completion must not spawn further commands');
            }
        });
        Loop::run();

        $this->assertInstanceOf(Manager::class, $finalState);
        $this->assertStringContainsString('copied with 1 errors', $finalState->status);
        $this->assertFileDoesNotExist($dstDir . '/vanishing.txt');
    }

    /**
     * Type-level pin of the HIGH defect: the completion message crosses
     * candy-core's `?Msg`-typed dispatch closure, so it MUST be a Msg —
     * the class shipped without the interface and the copy path died with
     * an "Unhandled promise rejection" TypeError.
     */
    public function testCopyCompletedMsgIsACoreMsg(): void
    {
        $msg = new CopyCompletedMsg([], 0, [], '/dst');
        $this->assertInstanceOf(Msg::class, $msg);
    }

    public function testCopyCancelledWithN(): void
    {
        $srcFile = $this->tmpDir . '/source.txt';
        file_put_contents($srcFile, 'content');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'c'));
        $this->assertSame(ConfirmState::CopySelected, $m->confirm);
        [$cancelled] = $m->update(new KeyMsg(KeyType::Char, 'n'));
        $this->assertSame(ConfirmState::None, $cancelled->confirm);
        $this->assertStringContainsString('cancelled', $cancelled->status);
    }

    public function testCopyFileMethod(): void
    {
        $srcFile = $this->tmpDir . '/source.txt';
        $dstFile = $this->tmpDir . '/dest.txt';
        file_put_contents($srcFile, 'test content');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);

        $result = $m->copy($srcFile, $dstFile);
        $this->assertTrue($result);
        $this->assertFileExists($dstFile);
        $this->assertSame('test content', file_get_contents($dstFile));
    }

    public function testCopyDirectoryMethod(): void
    {
        $srcDir = $this->tmpDir . '/srcdir';
        $dstDir = $this->tmpDir . '/dstdir';
        mkdir($srcDir, 0755, true);
        file_put_contents($srcDir . '/file.txt', 'content');
        mkdir($srcDir . '/subdir', 0755);
        file_put_contents($srcDir . '/subdir/nested.txt', 'nested');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);

        $result = $m->copy($srcDir, $dstDir);
        $this->assertTrue($result);
        $this->assertFileExists($dstDir . '/file.txt');
        $this->assertFileExists($dstDir . '/subdir/nested.txt');
        $this->assertSame('content', file_get_contents($dstDir . '/file.txt'));
    }

    /**
     * Depth cap: a directory nested deeper than MAX_COPY_DEPTH must abort
     * with false rather than recurse without bound. Without the cap a
     * copy-into-self or hardlink/bind cycle would exhaust the stack / flood
     * the disk. Revert the guard and this returns true → the test fails.
     */
    public function testCopyDirStopsAtMaxDepth(): void
    {
        // One mkdir builds the whole chain; go two levels past the cap so
        // the recursion is forced to trip.
        $depth = Manager::MAX_COPY_DEPTH + 2;
        $deepRoot = $this->tmpDir . '/deep';
        $leaf = $deepRoot . str_repeat('/d', $depth);
        $this->assertTrue(mkdir($leaf, 0755, true), 'setup: deep tree created');
        file_put_contents($leaf . '/leaf.txt', 'bottom');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        $result = $m->copy($deepRoot, $this->tmpDir . '/deep-copy');

        $this->assertFalse($result, 'copy past MAX_COPY_DEPTH must abort');
    }

    /**
     * A directory containing a symlink that points back at itself (or a
     * parent) must not send copyDir into infinite recursion. The symlink is
     * copied as a symlink, never followed, and the copy completes.
     */
    public function testCopyDirHandlesSelfReferencingSymlink(): void
    {
        $base = $this->tmpDir . '/loopy';
        mkdir($base, 0755, true);
        file_put_contents($base . '/real.txt', 'data');
        if (@symlink($base, $base . '/self') === false) {
            $this->markTestSkipped('symlinks not supported on this filesystem');
        }

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        $dst = $this->tmpDir . '/loopy-copy';
        $result = $m->copy($base, $dst);

        $this->assertTrue($result, 'self-referencing symlink must not break the copy');
        $this->assertFileExists($dst . '/real.txt');
        // The loop entry is preserved as a symlink, not recursed into.
        $this->assertTrue(is_link($dst . '/self'), 'symlink copied as a link, not followed');
    }
}
