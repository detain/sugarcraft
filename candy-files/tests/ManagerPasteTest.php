<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Files\ConfirmState;
use SugarCraft\Files\Entry;
use SugarCraft\Files\Manager;

/**
 * CF-49 (closes 096): bracketed paste. The bin turns on DEC mode 2004,
 * sugar-core's reader arrives at the model with one PasteMsg per paste,
 * and these pins hold the two invariants that make the mode honest:
 * a paste is an ATOMIC EDIT into whichever buffer owns the keyboard,
 * and it can never masquerade as keystrokes anywhere else.
 */
final class ManagerPasteTest extends TestCase
{
    private function fakeFs(): \Closure
    {
        $tree = [
            '/' => [
                new Entry('home',   true,  0, 0),
                new Entry('etc',    true,  0, 0),
                new Entry('readme', false, 100, 0),
            ],
            '/home' => [
                new Entry('alice', true, 0, 0),
                new Entry('bob',   true, 0, 0),
            ],
        ];
        return static fn(string $path): array => $tree[$path] ?? [];
    }

    private function manager(): Manager
    {
        return Manager::start('/', '/home', $this->fakeFs());
    }

    public function testPasteLandsWholeStringInRenameBufferAsOneAtomicEdit(): void
    {
        $m = $this->manager();                              // sort name-asc: etc, home, readme
        [$armed] = $m->update(new KeyMsg(KeyType::Char, 'R')); // cursor 0 = etc; rename pre-fills
        $this->assertSame(ConfirmState::RenameSelected, $armed->confirm);
        $this->assertNull($armed->inputBuffer, 'the buffer starts empty; the old name rides pendingOpDest');

        [$pasted] = $armed->update(new PasteMsg("etc-renamed\nsecond line\ttail"));
        // Flatten: runs of CR/LF/TAB collapse to one space (candy-git
        // precedent). Nothing commits — a pasted Enter is a SPACE here,
        // never the key that finalizes the rename.
        $this->assertSame(ConfirmState::RenameSelected, $pasted->confirm, 'paste never auto-commits');
        $this->assertSame("etc-renamed second line tail", $pasted->inputBuffer);
    }

    public function testPasteIntoSearchAppendsAndRebuildsResults(): void
    {
        $m = $this->manager();
        [$searching] = $m->update(new KeyMsg(KeyType::Char, '/'));
        $this->assertNotNull($searching->searchQuery);

        [$pasted] = $searching->update(new PasteMsg("re"));
        $this->assertSame('re', $pasted->searchQuery);
        $this->assertNotEmpty($pasted->searchResults, 'the paste rebuilds the result set like typed chars');

        [$spacey] = $pasted->update(new PasteMsg("sume\nday"));
        $this->assertSame('resume day', $spacey->searchQuery, 'flattened tail appends atomically');
    }

    public function testPasteOutsideAnyBufferIsDroppedNotRetyped(): void
    {
        $m = $this->manager();
        // Without 2004 a paste of "q" would stream as the quit keystroke.
        // With it, an ungathered paste must be inert: same model, no quit.
        [$next, $cmd] = $m->update(new PasteMsg("q"));
        $this->assertSame($m, $next);
        $this->assertNull($cmd, 'a dropped paste must not emit the Cmd its bytes spell');

        // Even the empty payload changes nothing.
        [$same] = $m->update(new PasteMsg(""));
        $this->assertSame($m, $same);
    }

    public function testPasteNeverAnswersAnArmedYesNoGate(): void
    {
        $m = $this->manager();
        [$searching] = $m->update(new KeyMsg(KeyType::Char, '/'));
        [$armed] = $searching->update(new KeyMsg(KeyType::Escape, '')); // exit search
        [$armed] = $armed->update(new KeyMsg(KeyType::Char, 'j'));      // cursor to home
        [$gate] = $armed->update(new KeyMsg(KeyType::Char, 'd'));
        $this->assertSame(ConfirmState::DeleteSelected, $gate->confirm);

        [$after] = $gate->update(new PasteMsg('y'));
        $this->assertSame(ConfirmState::DeleteSelected, $after->confirm, 'the gate stays armed');
        $this->assertNull($after->inputBuffer, 'the y/n gate collects no buffer');
    }

    public function testPasteIntoPatternBufferFlattensGlobRun(): void
    {
        $m = $this->manager();
        [$pattern] = $m->update(new KeyMsg(KeyType::Char, '*'));
        $this->assertSame(ConfirmState::SelectPattern, $pattern->confirm);
        [$pasted] = $pattern->update(new PasteMsg("*.txt\n*.md"));
        $this->assertSame('*.txt *.md', $pasted->inputBuffer, 'one flattened edit, no commit');
    }
}
