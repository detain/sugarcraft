<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Files\ConfirmState;
use SugarCraft\Files\Entry;
use SugarCraft\Files\Manager;
use PHPUnit\Framework\TestCase;

final class ManagerRenameTest extends TestCase
{
    private string $tmpDir;
    private \Closure $lister;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sugarcraft-rename-test-' . uniqid('', true);
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
            if (is_dir($full) === true) {
                $this->removeDir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }

    /**
     * C-1 family: a bare 0x20 decodes to KeyType::Space, not a printable
     * Char. While the rename submode's append leg only accepted Char,
     * no name containing a space could ever be typed — a broken user
     * promise the docblock itself contradicts ("printables append").
     */
    public function testRenameCanTypeSpaceInTheNewName(): void
    {
        file_put_contents($this->tmpDir . '/oldname.txt', 'content');
        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));   // off the parent sentinel onto oldname.txt
        [$armed] = $m->update(new KeyMsg(KeyType::Char, 'R'));
        $this->assertSame(ConfirmState::RenameSelected, $armed->confirm);
        foreach (['m', 'y', ' ', 'f', 'i', 'l', 'e'] as $ch) {
            // The space arrives as the REAL parser shape: KeyType::Space,
            // rune ' ' (InputReader maps 0x20 that way, never Char).
            $key = $ch === ' '
                ? new KeyMsg(KeyType::Space, ' ')
                : new KeyMsg(KeyType::Char, $ch);
            [$armed] = $armed->update($key);
        }
        $this->assertSame('my file', $armed->inputBuffer, 'space must land in the typed buffer');
        [$done] = $armed->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertTrue(file_exists($this->tmpDir . '/my file'), 'rename with a space committed');
        $this->assertFalse(file_exists($this->tmpDir . '/oldname.txt'), 'old name is gone');
        $this->assertSame(ConfirmState::None, $done->confirm);
    }

    public function testArmRenameWithNoSelectionShowsError(): void
    {
        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        [$next] = $m->update(new KeyMsg(KeyType::Char, 'R'));
        $this->assertStringContainsString('nothing to rename', $next->status);
    }

    public function testArmRenameWithCurrentEntryShowsConfirm(): void
    {
        file_put_contents($this->tmpDir . '/oldname.txt', 'content');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        // Cursor starts at 0 (parent sentinel '..'), move down to get to oldname.txt
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$armed] = $m->update(new KeyMsg(KeyType::Char, 'R'));

        $this->assertSame(ConfirmState::RenameSelected, $armed->confirm);
        $this->assertStringContainsString('rename', $armed->status);
        $this->assertStringContainsString('oldname.txt', $armed->status);
        $this->assertSame('oldname.txt', $armed->pendingOpDest);
        $this->assertSame('rename', $armed->pendingOpType);
    }

    public function testRenameFileMethod(): void
    {
        $srcFile = $this->tmpDir . '/oldname.txt';
        file_put_contents($srcFile, 'test content');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);

        $result = $m->rename($srcFile, 'newname.txt');
        $this->assertTrue($result);
        $this->assertFileDoesNotExist($srcFile);
        $this->assertFileExists($this->tmpDir . '/newname.txt');
        $this->assertSame('test content', file_get_contents($this->tmpDir . '/newname.txt'));
    }

    public function testRenameCancelledWhenSameName(): void
    {
        $file = $this->tmpDir . '/file.txt';
        file_put_contents($file, 'content');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'R'));

        // Simulate entering the same name (file.txt) - pendingOpDest is 'file.txt' from armRename
        // The actual rename operation with same name should cancel gracefully
        // Since rename uses pendingOpDest, and it equals current name, performRename returns cancelled
        $this->assertSame(ConfirmState::RenameSelected, $m->confirm);
    }

    public function testRenameTypingAccumulatesInputBuffer(): void
    {
        $file = $this->tmpDir . '/oldname.txt';
        file_put_contents($file, 'content');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'R'));
        $this->assertSame(ConfirmState::RenameSelected, $m->confirm);

        // Type 'n'
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'n'));
        $this->assertSame('n', $m->inputBuffer);

        // Type 'e'
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'e'));
        $this->assertSame('ne', $m->inputBuffer);

        // Type 'w'
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'w'));
        $this->assertSame('new', $m->inputBuffer);
    }

    /**
     * N-4 (step 13): the rename buffer echo joins the status through the same
     * Entry::sanitizeName() choke as every other name-bearing write. A rune
     * carrying control bytes is not something the live parser emits (this is
     * belt-braces against a forged KeyMsg), but a choke is a choke — revert
     * the wrap and the raw ESC byte reaches the status string and this reddens.
     */
    public function testRenameBufferEchoIsSanitizedInStatus(): void
    {
        $file = $this->tmpDir . '/oldname.txt';
        file_put_contents($file, 'content');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'R'));

        [$m] = $m->update(new KeyMsg(KeyType::Char, "a\x1b[31mb"));
        $this->assertStringNotContainsString("\x1b", $m->status);
        $this->assertStringContainsString('ab_', $m->status);
        $this->assertSame("a\x1b[31mb", $m->inputBuffer, 'buffer keeps raw truth for the commit path');
    }

    public function testRenameBackspaceRemovesLastChar(): void
    {
        $file = $this->tmpDir . '/oldname.txt';
        file_put_contents($file, 'content');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'R'));

        // Type 'new'
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'n'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'e'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'w'));
        $this->assertSame('new', $m->inputBuffer);

        // Backspace
        [$m] = $m->update(new KeyMsg(KeyType::Backspace, ''));
        $this->assertSame('ne', $m->inputBuffer);
    }

    public function testRenameEscapeCancels(): void
    {
        $file = $this->tmpDir . '/oldname.txt';
        file_put_contents($file, 'content');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'R'));
        $this->assertSame(ConfirmState::RenameSelected, $m->confirm);

        // Type some characters
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'n'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'e'));
        $this->assertSame('ne', $m->inputBuffer);

        // Escape should cancel
        [$cancelled] = $m->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertSame(ConfirmState::None, $cancelled->confirm);
        $this->assertNull($cancelled->inputBuffer);
        $this->assertStringContainsString('cancelled', $cancelled->status);
    }

    public function testRenameEmptyBufferCancels(): void
    {
        $file = $this->tmpDir . '/oldname.txt';
        file_put_contents($file, 'content');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'R'));
        $this->assertSame(ConfirmState::RenameSelected, $m->confirm);

        // Press Enter with empty buffer should cancel
        [$cancelled] = $m->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame(ConfirmState::None, $cancelled->confirm);
        $this->assertStringContainsString('cancelled', $cancelled->status);
    }

    public function testRenameWithPathTraversalBlocked(): void
    {
        $file = $this->tmpDir . '/oldname.txt';
        file_put_contents($file, 'content');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'R'));

        // Try to type a path traversal attempt
        [$m] = $m->update(new KeyMsg(KeyType::Char, '.'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, '.'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, '/'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'e'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'v'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'i'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'l'));

        // Enter to confirm - should fail due to path traversal
        [$result] = $m->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame(ConfirmState::None, $result->confirm);
        $this->assertStringContainsString('failed', $result->status);
        // Original file should still exist
        $this->assertFileExists($file);
    }

    public function testRenameSuccessful(): void
    {
        $file = $this->tmpDir . '/oldname.txt';
        file_put_contents($file, 'test content');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'R'));

        [$m] = $m->update(new KeyMsg(KeyType::Char, 'n'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'e'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'w'));
        $this->assertSame('new', $m->inputBuffer);

        // Commit. (The shipped version of this test REFUSED to commit here:
        // the guard used \Phar::canonicalize(), which is undefined on this
        // PHP 8.3.6 build — every real rename threw an uncaught Error. The
        // guard is now Manager::canonicalPath(), pure lexical and portable.)
        [$done] = $m->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertStringContainsString('renamed oldname.txt to new', $done->status);
        $this->assertFileDoesNotExist($file);
        $this->assertFileExists($this->tmpDir . '/new');
        $this->assertTrue($done->canUndo());
    }

    /**
     * SECURITY (docs-fix-1023, report MAJOR-2 cluster): filenames are
     * attacker-writable bytes and the rename status line embeds the OLD
     * name verbatim. A crafted name carrying an SGR sequence plus BEL must
     * render INERT: every escape byte is swept at the message-build choke.
     */
    public function testRenamedStatusCarriesNoEscapeBytesFromSourceName(): void
    {
        $evil = "x\x1b[7mE\x07.txt";   // reverse-video SGR + BEL inside the name
        file_put_contents($this->tmpDir . '/' . $evil, 'content');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));   // onto the evil name
        [$armed] = $m->update(new KeyMsg(KeyType::Char, 'R'));
        $this->assertStringNotContainsString("\x1b", $armed->status, 'arm prompt was already sanitized — stays so');

        [$m] = $armed->update(new KeyMsg(KeyType::Char, 's'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'a'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'f'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'e'));
        [$done] = $m->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertStringNotContainsString("\x1b", $done->status, 'status line must not carry ESC from a crafted filename');
        $this->assertStringNotContainsString("\x07", $done->status, 'nor BEL');
        $this->assertStringContainsString('safe', $done->status);
        $this->assertFileExists($this->tmpDir . '/safe');
    }

    /**
     * The same choke on the FAILURE path: rename_failed embeds the source
     * name, so a blocked traversal attempt on an escape-named file must
     * still report inertly.
     */
    public function testRenameFailedStatusCarriesNoEscapeBytes(): void
    {
        $evil = "v\x1b]0;pwned\x07ictim.txt";
        file_put_contents($this->tmpDir . '/' . $evil, 'content');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'R'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, '.'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, '.'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, '/'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'o'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'u'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 't'));
        [$blocked] = $m->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertStringContainsString('failed', $blocked->status);
        $this->assertStringNotContainsString("\x1b", $blocked->status, 'even the refusal must not echo raw escape bytes');
        $this->assertStringNotContainsString("\x07", $blocked->status);
    }

    /**
     * STRUCTURAL pin (docs-fix-1023): the resolved-destination containment
     * check must compare against the source directory WITH a '/' boundary.
     * Without the boundary a sibling like '/tmp/pane' would prefix-match
     * '/tmp/panex'; unreachable through typed input today (the separator
     * guard above rejects it first), so the spelling itself is pinned.
     */
    public function testRenameContainmentComparesWithTrailingBoundary(): void
    {
        $src = file_get_contents(__DIR__ . '/../src/Manager.php');
        $this->assertIsString($src);
        $this->assertStringContainsString("\$resolvedCwd . '/'", $src,
            'performRename must str_starts_with against $resolvedCwd . "/"');
        // The single-home rule for recursive copy: Manager must NOT keep a
        // private copyDir duplicate of AsyncOps' cycle-guarded engine.
        $this->assertFalse(method_exists(Manager::class, 'copyDir'),
            'recursive copy has exactly ONE home (AsyncOps::copyDir)');
        $this->assertFalse((new \ReflectionClass(Manager::class))->hasConstant('MAX_COPY_DEPTH'),
            'the depth cap has exactly ONE home (AsyncOps::MAX_COPY_DEPTH)');
        $copyPath = new \ReflectionMethod(\SugarCraft\Files\AsyncOps::class, 'copyPath');
        $this->assertTrue($copyPath->isPublic() && $copyPath->isStatic(),
            'AsyncOps::copyPath is the shared synchronous copy engine');
        // The rename guard must stay extension-free: \Phar::canonicalize()
        // is absent on this box's PHP build and crashed the commit path.
        $this->assertStringNotContainsString('Phar::canonicalize', $src,
            'path canonicalization must not depend on ext-phar');

        $cp = new \ReflectionMethod(Manager::class, 'canonicalPath');
        $cp->setAccessible(true);
        /** @param string $in */
        $canon = static fn(string $in): string => $cp->invoke(null, $in);
        $this->assertSame('/a/b', $canon('/a//./b'));
        $this->assertSame('/a', $canon('/a/b/..'));
        $this->assertSame('/', $canon('/..'));
        $this->assertSame('/', $canon('/'));
        $this->assertSame('/a', $canon('/a'));
    }
    /**
     * CF-06 (closes audit 006, rename leg): renaming onto an existing name
     * silently eats that file — the status must carry the overwrite marker.
     */
    public function testRenameAnnouncesOverwrittenVictim(): void
    {
        // NameAsc sort: source.txt sorts ahead of victim.txt.
        file_put_contents($this->tmpDir . '/source.txt', 'payload');
        file_put_contents($this->tmpDir . '/victim.txt', 'old bytes');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));   // onto source.txt
        [$armed] = $m->update(new KeyMsg(KeyType::Char, 'R'));
        $this->assertSame(ConfirmState::RenameSelected, $armed->confirm);
        foreach (str_split('victim.txt') as $char) {
            [$m] = $armed->update(new KeyMsg(KeyType::Char, $char));
            $armed = $m;
        }
        [$done] = $armed->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame('renamed source.txt to victim.txt (1 overwrote)', $done->status);
        $this->assertFileDoesNotExist($this->tmpDir . '/source.txt');
        $this->assertSame('payload', file_get_contents($this->tmpDir . '/victim.txt'));
    }
}
