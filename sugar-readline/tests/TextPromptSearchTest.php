<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\History\InMemoryHistory;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\Mode\EmacsMode;
use SugarCraft\Readline\Mode\ViMode;
use SugarCraft\Readline\TextPrompt;

/**
 * Incremental history search (readline Ctrl+R / Ctrl+S) state machine.
 */
final class TextPromptSearchTest extends TestCase
{
    /** History with entries pushed oldest→newest (index 0 = newest). */
    private function history(string ...$entries): InMemoryHistory
    {
        $h = new InMemoryHistory();
        foreach ($entries as $entry) {
            $h->push($entry);
        }
        return $h;
    }

    // =========================================================================
    // Entering search mode
    // =========================================================================

    public function testCtrlREntersReverseSearchMode(): void
    {
        $p = TextPrompt::new('> ')->withHistory($this->history('git status'))
            ->handleKey(Key::CtrlR);

        $this->assertTrue($p->isSearching());
        $this->assertSame('', $p->searchQuery());
        $this->assertFalse($p->isSubmitted());
    }

    public function testRawCtrlRByteAlsoEntersSearchMode(): void
    {
        $p = TextPrompt::new('> ')->withHistory($this->history('ls'))
            ->handleKey("\x12");

        $this->assertTrue($p->isSearching());
    }

    public function testCtrlRWithoutHistoryIsNoOp(): void
    {
        $p = TextPrompt::new('> ')->handleChar('x')->handleKey(Key::CtrlR);

        $this->assertFalse($p->isSearching());
        $this->assertSame('x', $p->value());
    }

    public function testCtrlRWithEmptyHistoryShowsFailedFeedback(): void
    {
        $p = TextPrompt::new('> ')->withHistory(new InMemoryHistory())
            ->handleKey(Key::CtrlR);

        // Search engages (not a broken/dead state) but immediately reports failure.
        $this->assertTrue($p->isSearching());
        $this->assertStringContainsString('failed reverse-i-search', $p->view());
    }

    // =========================================================================
    // Query refinement — newest match first
    // =========================================================================

    public function testTypedQueryFindsNewestMatchFirst(): void
    {
        $p = TextPrompt::new('> ')
            ->withHistory($this->history('git log', 'ls -la', 'git status'))
            ->handleKey(Key::CtrlR)
            ->handleChar('g')->handleChar('i')->handleChar('t');

        $this->assertSame('git', $p->searchQuery());
        $this->assertSame('git status', $p->value()); // newest match, not 'git log'
    }

    public function testRefiningQueryNarrowsMatch(): void
    {
        $p = TextPrompt::new('> ')
            ->withHistory($this->history('git log', 'git status'))
            ->handleKey(Key::CtrlR)
            ->handleChar('l')->handleChar('o');

        $this->assertSame('git log', $p->value());
    }

    public function testUnmatchedQueryFlagsFailureButKeepsLastMatch(): void
    {
        $p = TextPrompt::new('> ')
            ->withHistory($this->history('git status'))
            ->handleKey(Key::CtrlR)
            ->handleChar('g')->handleChar('z');

        $this->assertSame('gz', $p->searchQuery());
        $this->assertSame('git status', $p->value()); // last successful match kept
        $this->assertStringContainsString('failed reverse-i-search', $p->view());
    }

    // =========================================================================
    // Stepping through matches
    // =========================================================================

    public function testRepeatedCtrlRStepsToOlderMatch(): void
    {
        $p = TextPrompt::new('> ')
            ->withHistory($this->history('git log', 'ls', 'git status'))
            ->handleKey(Key::CtrlR)
            ->handleChar('g')->handleChar('i')->handleChar('t');
        $this->assertSame('git status', $p->value());

        $p = $p->handleKey(Key::CtrlR);
        $this->assertSame('git log', $p->value());
    }

    public function testSteppingPastOldestMatchFlagsFailure(): void
    {
        $p = TextPrompt::new('> ')
            ->withHistory($this->history('git status'))
            ->handleKey(Key::CtrlR)
            ->handleChar('g')
            ->handleKey(Key::CtrlR); // no older match

        $this->assertSame('git status', $p->value()); // match retained
        $this->assertStringContainsString('failed', $p->view());
    }

    public function testCtrlSStepsBackTowardNewerMatch(): void
    {
        $p = TextPrompt::new('> ')
            ->withHistory($this->history('git log', 'ls', 'git status'))
            ->handleKey(Key::CtrlR)
            ->handleChar('g')->handleChar('i')->handleChar('t')
            ->handleKey(Key::CtrlR);  // older: 'git log'
        $this->assertSame('git log', $p->value());

        $p = $p->handleKey(Key::CtrlS); // newer again: 'git status'
        $this->assertSame('git status', $p->value());
        $this->assertStringContainsString('(i-search)', $p->view());
    }

    public function testBackspaceErasesQueryCharAndResearches(): void
    {
        $p = TextPrompt::new('> ')
            ->withHistory($this->history('git log', 'ls -la'))
            ->handleKey(Key::CtrlR)
            ->handleChar('l')->handleChar('o'); // 'lo' only matches the older 'git log'
        $this->assertSame('git log', $p->value());

        $p = $p->handleKey(Key::Backspace); // 'l' → newest match ('ls -la') again
        $this->assertSame('l', $p->searchQuery());
        $this->assertSame('ls -la', $p->value());
    }

    // =========================================================================
    // Accept / cancel
    // =========================================================================

    public function testEnterAcceptsMatchWithoutSubmitting(): void
    {
        $p = TextPrompt::new('> ')
            ->withHistory($this->history('git status'))
            ->handleChar('x')
            ->handleKey(Key::CtrlR)
            ->handleChar('g')
            ->handleKey(Key::Enter);

        $this->assertFalse($p->isSearching());
        $this->assertFalse($p->isSubmitted()); // review before submit
        $this->assertSame('git status', $p->value());

        // A second Enter submits the accepted line.
        $p = $p->handleKey(Key::Enter);
        $this->assertTrue($p->isSubmitted());
    }

    public function testEnterWithNoMatchRestoresOriginalLine(): void
    {
        $p = TextPrompt::new('> ')
            ->withHistory(new InMemoryHistory())
            ->handleChar('k')->handleChar('e')->handleChar('e')->handleChar('p')
            ->handleKey(Key::CtrlR)
            ->handleKey(Key::Enter);

        $this->assertFalse($p->isSearching());
        $this->assertSame('keep', $p->value());
    }

    public function testEscapeCancelsRestoringOriginalBufferAndCursor(): void
    {
        $p = TextPrompt::new('> ')
            ->withHistory($this->history('git status'))
            ->handleChar('a')->handleChar('b')->handleKey(Key::Left) // cursor 1
            ->handleKey(Key::CtrlR)
            ->handleChar('g');
        $this->assertSame('git status', $p->value());

        $p = $p->handleKey(Key::Escape);
        $this->assertFalse($p->isSearching());
        $this->assertFalse($p->isAborted()); // cancels the search, not the prompt
        $this->assertSame('ab', $p->value());
        $this->assertSame(1, $p->cursor());
    }

    public function testCtrlGCancelsSearch(): void
    {
        $p = TextPrompt::new('> ')
            ->withHistory($this->history('git status'))
            ->handleChar('a')
            ->handleKey(Key::CtrlR)
            ->handleChar('g')
            ->handleKey(Key::CtrlG);

        $this->assertFalse($p->isSearching());
        $this->assertSame('a', $p->value());
    }

    public function testOtherKeysAreIgnoredWhileSearching(): void
    {
        $p = TextPrompt::new('> ')
            ->withHistory($this->history('git status'))
            ->handleKey(Key::CtrlR)
            ->handleChar('g')
            ->handleKey(Key::Up)
            ->handleKey(Key::Tab);

        $this->assertTrue($p->isSearching());
        $this->assertSame('git status', $p->value());
    }

    // =========================================================================
    // Rendering
    // =========================================================================

    public function testViewRendersReverseSearchIndicator(): void
    {
        $p = TextPrompt::new('> ')
            ->withHistory($this->history('git status'))
            ->handleKey(Key::CtrlR)
            ->handleChar('g');

        $this->assertStringContainsString("(reverse-i-search)`g': git status", $p->view());
    }

    // =========================================================================
    // Mode integration
    // =========================================================================

    public function testEmacsModeCtrlREntersReverseSearch(): void
    {
        $p = TextPrompt::new('> ')
            ->withHistory($this->history('git status'))
            ->withMode(new EmacsMode())
            ->handleKey("\x12")
            ->handleChar('g');

        $this->assertTrue($p->isSearching());
        $this->assertSame('git status', $p->value());
    }

    public function testEmacsModeCtrlSEntersForwardSearch(): void
    {
        $p = TextPrompt::new('> ')
            ->withHistory($this->history('git log', 'git status'))
            ->withMode(new EmacsMode())
            ->handleKey("\x13");

        $this->assertTrue($p->isSearching());
        $this->assertStringContainsString('(i-search)', $p->view());
    }

    public function testEmacsModeSearchStepsAndCancels(): void
    {
        $p = TextPrompt::new('> ')
            ->withHistory($this->history('git log', 'git status'))
            ->withMode(new EmacsMode())
            ->handleChar('z')
            ->handleKey("\x12")
            ->handleChar('g')
            ->handleKey("\x12"); // step older within emacs mode
        $this->assertSame('git log', $p->value());

        $p = $p->handleKey(Key::Escape);
        $this->assertFalse($p->isSearching());
        $this->assertSame('z', $p->value());
    }

    public function testViInsertModeCtrlREntersSearch(): void
    {
        // Vi insert mode delegates unhandled keys to the default bindings.
        $p = TextPrompt::new('> ')
            ->withHistory($this->history('git status'))
            ->withMode(new ViMode())
            ->handleKey("\x12")
            ->handleChar('g');

        $this->assertTrue($p->isSearching());
        $this->assertSame('git status', $p->value());
    }
}
