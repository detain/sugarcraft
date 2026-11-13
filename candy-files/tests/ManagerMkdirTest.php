<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Files\Manager;

/**
 * CF-10: mkdir via `N` — the mc-F7/superfile-N baseline that the GAP-003
 * review found deleted-and-never-replaced. Drives the real text-entry
 * grammar (N, typed name, Enter) against a live temporary directory and
 * rides the CF-08 undo laws: a created directory is removed by undo only
 * while it is empty; a filled one is reported occupied, never eaten.
 */
final class ManagerMkdirTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/candyfiles-mkdir-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
        file_put_contents($this->dir . '/seed.txt', 'x');
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    private function manager(): Manager
    {
        return Manager::start($this->dir, $this->dir);
    }

    /** @param array{0:Manager,1:?\Closure} $pair */
    private function key(array $pair, KeyMsg $msg): Manager
    {
        [$m] = $pair[0]->update($msg);
        return $m;
    }

    private function type(Manager $m, string $s): Manager
    {
        foreach (mb_str_split($s) as $ch) {
            [$m] = $m->update(new KeyMsg(KeyType::Char, $ch));
        }
        return $m;
    }

    public function testCreateUndoRedoUndoFullCycle(): void
    {
        $m = $this->manager();
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'N'));
        $this->assertStringContainsString('create directory', $m->status);
        $m = $this->type($m, 'newdir');
        [$m] = $m->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertDirectoryExists($this->dir . '/newdir');
        $this->assertStringContainsString('created', $m->status);
        $this->assertTrue($m->canUndo());

        // Undo removes the EMPTY created dir.
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertDirectoryDoesNotExist($this->dir . '/newdir');
        $this->assertTrue($m->canRedo());

        // Redo recreates it.
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'y', ctrl: true));
        $this->assertDirectoryExists($this->dir . '/newdir');

        // And undo a second time still removes it.
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertDirectoryDoesNotExist($this->dir . '/newdir');
    }

    public function testCreatedDirIsListedInPane(): void
    {
        $m = $this->manager();
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'N'));
        $m = $this->type($m, 'listed');
        [$m] = $m->update(new KeyMsg(KeyType::Enter, ''));
        $names = array_map(static fn($e) => $e->name, $m->activePane()->entries);
        $this->assertContains('listed', $names);
    }

    public function testEmptyBufferCancelsLikeRename(): void
    {
        $m = $this->manager();
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'N'));
        [$m] = $m->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertStringContainsString('cancelled', $m->status);
        $this->assertFalse($m->canUndo());
    }

    public function testEscapeCancels(): void
    {
        $m = $this->manager();
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'N'));
        $m = $this->type($m, 'ghost');
        [$m] = $m->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertStringContainsString('cancelled', $m->status);
        $this->assertDirectoryDoesNotExist($this->dir . '/ghost');
    }

    public function testBackspaceEditsTheBuffer(): void
    {
        $m = $this->manager();
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'N'));
        $m = $this->type($m, 'ab');
        [$m] = $m->update(new KeyMsg(KeyType::Backspace, ''));
        $m = $this->type($m, 'c');
        [$m] = $m->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertDirectoryExists($this->dir . '/ac');
        $this->assertDirectoryDoesNotExist($this->dir . '/ab');
    }

    /**
     * Name legality: separators, traversal, and control bytes are not
     * names. A refusal must create NOTHING and touch no undo stack.
     */
    public function testIllegalNamesAreRefusedWithoutCreation(): void
    {
        foreach (['a/b', 'a\\b', '..', '.', "bad\x01name"] as $bad) {
            $m = $this->manager();
            [$m] = $m->update(new KeyMsg(KeyType::Char, 'N'));
            $m = $this->type($m, $bad);
            [$m] = $m->update(new KeyMsg(KeyType::Enter, ''));
            $this->assertStringContainsString('cannot create', $m->status, "refusal for: $bad");
            $this->assertFalse($m->canUndo(), "no undo entry for: $bad");
        }
        $this->assertDirectoryDoesNotExist($this->dir . '/a');
    }

    public function testExistingNameIsRefused(): void
    {
        $m = $this->manager();
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'N'));
        $m = $this->type($m, 'seed.txt');
        [$m] = $m->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertStringContainsString('cannot create', $m->status);
        $this->assertSame('x', file_get_contents($this->dir . '/seed.txt'));
        $this->assertFalse($m->canUndo());
    }

    /**
     * CF-08 law on the create path: undoing a mkdir that has since been
     * FILLED is refused as occupied — the user's bytes survive, the action
     * stays retryable, and nothing recursive is deleted.
     */
    public function testUndoOfFilledDirIsRefusedAndContentsSurvive(): void
    {
        $m = $this->manager();
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'N'));
        $m = $this->type($m, 'box');
        [$m] = $m->update(new KeyMsg(KeyType::Enter, ''));
        file_put_contents($this->dir . '/box/inner.txt', 'payload');

        [$m] = $m->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertStringContainsString('occupied', $m->status);
        $this->assertDirectoryExists($this->dir . '/box');
        $this->assertSame('payload', file_get_contents($this->dir . '/box/inner.txt'));
        $this->assertTrue($m->canUndo(), 'failed undo keeps the action retryable (067)');
        $this->assertFalse($m->canRedo(), 'failed undo must not mint a redo entry');
    }

    /**
     * Dotfiles inside a created dir count as content even though the
     * visibility law hides them — undo of a hidden-filled dir is refused.
     */
    public function testHiddenChildrenCountAsContent(): void
    {
        $m = $this->manager();
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'N'));
        $m = $this->type($m, 'secrets');
        [$m] = $m->update(new KeyMsg(KeyType::Enter, ''));
        file_put_contents($this->dir . '/secrets/.env', 'k=v');

        [$m] = $m->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertStringContainsString('occupied', $m->status);
        $this->assertDirectoryExists($this->dir . '/secrets/.');
        $this->assertSame('k=v', file_get_contents($this->dir . '/secrets/.env'));
    }

    /**
     * Redo goal-state: the directory reappeared on its own (a third party
     * recreated it) — the replay succeeds as a no-op, not an error.
     */
    public function testRedoWithPreexistingDirectoryHoldsGoal(): void
    {
        $m = $this->manager();
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'N'));
        $m = $this->type($m, 'phoenix');
        [$m] = $m->update(new KeyMsg(KeyType::Enter, ''));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertDirectoryDoesNotExist($this->dir . '/phoenix');
        mkdir($this->dir . '/phoenix'); // third party recreated it

        [$m] = $m->update(new KeyMsg(KeyType::Char, 'y', ctrl: true));
        $this->assertStringContainsString('redone', $m->status);
        $this->assertDirectoryExists($this->dir . '/phoenix');
    }

    /**
     * Ctrl+N must not arm mkdir (CF-05 guard family — bare-letter chords
     * only). A plain-N test doubles as the positive counter-leg.
     */
    public function testCtrlNDoesNotArmMkdir(): void
    {
        $m = $this->manager();
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'n', ctrl: true));
        $this->assertStringNotContainsString('create directory', $m->status);
        $m2 = $this->manager();
        [$m2] = $m2->update(new KeyMsg(KeyType::Char, 'N'));
        $this->assertStringContainsString('create directory', $m2->status);
    }
}
