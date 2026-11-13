<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use SugarCraft\Core\InputReader;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Files\ConfirmState;
use SugarCraft\Files\Manager;
use PHPUnit\Framework\TestCase;

/**
 * CF-05: bare-letter shortcuts must not capture modified chords.
 *
 * InputReader decodes the legacy control bytes (0x01..0x1A) to a Char
 * message whose RUNE is the letter with ctrl set, and ESC-prefixed pairs
 * to the same rune with alt set. Before the cure every rune-only dispatch
 * arm fired on those chords: Ctrl+D (0x04) armed a delete, Ctrl+R refreshed,
 * Ctrl+U undid, Alt+Q quit — accidents the keyboard has no undo for.
 *
 * These tests send REAL terminal bytes through the parser, not fabricated
 * KeyMsg shapes (the C-1 lesson): the premise assertions prove the decoder
 * really emits the rune+ctrl form the old arms matched.
 */
final class ManagerChordGuardTest extends TestCase
{
    private InputReader $reader;

    protected function setUp(): void
    {
        $this->reader = new InputReader();
    }

    /**
     * Decode $bytes through the real InputReader and push every resulting
     * message through update(). Returns [final Manager, decoded msgs].
     *
     * @return array{0:Manager,1:list<\SugarCraft\Core\Msg>}
     */
    private function feed(Manager $m, string $bytes): array
    {
        $msgs = $this->reader->parse($bytes);
        $this->assertNotEmpty($msgs, "premise: parser must decode {$bytes}");
        foreach ($msgs as $msg) {
            [$m] = $m->update($msg);
        }
        return [$m, $msgs];
    }

    private function manager(): Manager
    {
        $entries = [
            new \SugarCraft\Files\Entry('alpha.txt', false, 1, 0),
            new \SugarCraft\Files\Entry('beta.txt', false, 2, 0),
            new \SugarCraft\Files\Entry('gamma.txt', false, 3, 0),
        ];
        $lister = static fn(string $p): array => $p === '/' ? $entries : [];
        return Manager::start('/', '/', $lister);
    }

    public function testCtrlDDoesNotArmDelete(): void
    {
        [$armed] = $this->feed($this->manager(), "\x64"); // plain 'd' — arms
        $this->assertSame(ConfirmState::DeleteSelected, $armed->confirm, 'premise: plain d arms');

        [$m, $msgs] = $this->feed($this->manager(), "\x04"); // Ctrl+D
        $this->assertInstanceOf(KeyMsg::class, $msgs[0]);
        $this->assertSame('d', $msgs[0]->rune, 'premise: legacy byte 0x04 surfaces rune d');
        $this->assertTrue($msgs[0]->ctrl, 'premise: with ctrl set');
        $this->assertSame(ConfirmState::None, $m->confirm, 'Ctrl+D must not arm a delete');
        $this->assertSame('', $m->status, 'Ctrl+D must not even change the status');
    }

    public function testCtrlRDoesNotRefreshAndCtrlUDoesNotUndo(): void
    {
        [$mR] = $this->feed($this->manager(), "\x12"); // Ctrl+R
        $this->assertSame('', $mR->status, 'Ctrl+R must not fire the refresh shortcut');

        $base = $this->manager();
        [$m] = $this->feed($base, "\x15"); // Ctrl+U
        $this->assertSame('', $m->status, 'Ctrl+U must not fire undo');
        // Plain 'u' still works (the intended binding survives).
        [$plain] = $this->feed($base, "\x75");
        $this->assertStringContainsString('nothing', strtolower($plain->status), 'premise: plain u reports undo state');
    }

    public function testAltAndCtrlVariantsOfTheOtherLetterArmsAreInert(): void
    {
        // Ctrl+G (0x07 → rune 'g'): must NOT jump to top... cursor is at 0
        // anyway, so move down first and check it stays.
        $base = $this->manager();
        [$moved] = $this->feed($base, "\x6a"); // plain j -> cursor 1
        $this->assertSame(1, $moved->left->cursor, 'premise: plain j moved the cursor');
        [$same] = $this->feed($moved, "\x07"); // Ctrl+g
        $this->assertSame(1, $same->left->cursor, 'Ctrl+G must not gotoTop');
        [$same2] = $this->feed($moved, "\x1b\x67"); // Alt+g
        $this->assertSame(1, $same2->left->cursor, 'Alt+G must not gotoTop');
        // Ctrl+K must not move up.
        [$same3] = $this->feed($moved, "\x0b");
        $this->assertSame(1, $same3->left->cursor, 'Ctrl+K must not move the cursor');
        // Ctrl+C (rune 'c') must not arm a copy.
        [$same4] = $this->feed($base, "\x03");
        $this->assertSame(ConfirmState::None, $same4->confirm, 'Ctrl+C must not arm copy');
        // Ctrl+M / Ctrl+T likewise inert.
        [$same5] = $this->feed($base, "\x0d\x74", );
        // 0x0d decodes as Enter (navigate) — ctrl+m is legacy Enter; that is
        // a Type arm, not a letter arm; just assert no copy/move armed state.
        $this->assertSame(ConfirmState::None, $same5->confirm);
    }

    public function testPlainLettersStillFireTheirShortcuts(): void
    {
        $m = $this->manager();
        [$m, $msgs] = $this->feed($m, "\x6a\x6a"); // j j
        $this->assertSame(2, $m->left->cursor, 'plain j still pages the cursor');
        [$m] = $this->feed($m, "\x47"); // plain shift-'G' → rune 'G'
        $this->assertSame(2, $m->left->cursor, 'plain G still jumps to bottom (already there)');
        [$armed] = $this->feed($m, "\x64"); // d
        $this->assertSame(ConfirmState::DeleteSelected, $armed->confirm, 'plain d still arms delete');
    }

    /**
     * The destructive edge: Ctrl+Y at a delete prompt must NOT confirm.
     * Ctrl+Y is the redo chord; any non-affirmative key cancels.
     */
    public function testCtrlYCannotConfirmADelete(): void
    {
        $m = $this->manager();
        [$m] = $this->feed($m, "\x20"); // space: toggle selection (via Char? space is Space type — 0x20)
        // 0x20 decodes to KeyType::Space, rune ' ' — the dispatch Space arm selects the entry.
        $this->assertNotEmpty($m->left->selected, 'premise: 0x20 selected the entry');
        [$m] = $this->feed($m, "\x64"); // d arms the delete
        $this->assertSame(ConfirmState::DeleteSelected, $m->confirm);
        [$after] = $this->feed($m, "\x19"); // Ctrl+Y
        $this->assertSame(ConfirmState::None, $after->confirm, 'Ctrl+Y must resolve the prompt…');
        $this->assertStringContainsString('cancel', strtolower($after->status), '…as a CANCEL, never as a confirmation');
    }

    public function testCtrlZUndoChordStillWorksAndIsNotShadowedByPlainU(): void
    {
        // Build a manager with an undoable action: trash-delete one file.
        $tmp = sys_get_temp_dir() . '/candyfiles-chord-' . uniqid('', true);
        mkdir($tmp, 0755, true);
        file_put_contents($tmp . '/gone.txt', 'x');
        try {
            $lister = \SugarCraft\Files\FsLister::lister();
            $m = Manager::start($tmp, $tmp, $lister);
            [$m] = $this->feed($m, "\x6a\x20\x64\x79"); // j, space, d, y → deleted to trash
            $this->assertFileDoesNotExist($tmp . '/gone.txt');
            [$undone] = $this->feed($m, "\x1a"); // Ctrl+Z
            $this->assertFileExists($tmp . '/gone.txt', 'Ctrl+Z keeps its owned chord');
        } finally {
            @unlink($tmp . '/gone.txt');
            @rmdir($tmp);
            foreach (glob(sys_get_temp_dir() . '/candyfiles-trash-*') ?: [] as $trash) {
                $this->removeDir($trash);
            }
        }
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (@scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            is_dir($full) ? $this->removeDir($full) : @unlink($full);
        }
        @rmdir($path);
    }
}
