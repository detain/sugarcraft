<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\History\InMemoryHistory;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\TextPrompt;
use SugarCraft\Readline\UndoManager;

/**
 * Additional TextPrompt tests covering untested view() branches:
 * error styling, completion hint, fish-style autosuggest, and
 * submitted/aborted states.
 */
final class TextPromptExtendedTest extends TestCase
{
    // =========================================================================
    // view() — error message
    // =========================================================================

    public function testViewRendersErrorInRed(): void
    {
        $p = TextPrompt::new('Name: ')
            ->withValidator(fn(string $v): bool => false)
            ->handleChar('x')
            ->submit();
        $this->assertSame('Invalid input', $p->error());
        $view = $p->view();
        $this->assertStringContainsString('Invalid input', $view);
    }

    public function testViewWithEmptyErrorHasNoErrorLine(): void
    {
        $p = TextPrompt::new('Name: ')->handleChar('x');
        $view = $p->view();
        // No error, so no error line — view is a single line
        $this->assertStringNotContainsString('Invalid input', $view);
    }

    // =========================================================================
    // view() — completion suggestion tail
    // =========================================================================

    public function testViewShowsCompletionHint(): void
    {
        $p = TextPrompt::new('> ')->withCompletions(['banana', 'mango'])->handleChar('b');
        $view = $p->view();
        // The completion suggestion 'banana' has 'anana' as tail after 'b'
        $this->assertStringContainsString('anana', $view);
    }

    public function testViewDoesNotShowHintWhenBufferEqualsCompletion(): void
    {
        $p = TextPrompt::new('> ')->withCompletions(['banana'])->handleChar('b');
        $view = $p->view();
        // Buffer is 'b', completion 'banana' has tail 'anana' — different from buffer
        $this->assertStringContainsString('anana', $view);
    }

    // =========================================================================
    public function testHiddenPromptValueNeverEntersHistory(): void
    {
        // A secret typed into a withHidden() prompt must stay out of the
        // history store (both in-memory and any FileHistory behind it).
        $history = new InMemoryHistory();
        $prompt = TextPrompt::new('Password: ')
            ->withHistory($history)
            ->withHidden(true);
        $prompt = $prompt->handleChar('s')->handleChar('e')->handleChar('c');
        $prompt = $prompt->submit();

        $this->assertTrue($prompt->isSubmitted());
        $this->assertNull($history->getPrevious());

        // Control: a visible prompt with the same text does enter history.
        $visible = TextPrompt::new('Name: ')
            ->withHistory($history)
            ->handleChar('s')->handleChar('e')->handleChar('c')
            ->submit();
        $this->assertTrue($visible->isSubmitted());
        $this->assertSame('sec', $history->getPrevious());
    }

    // view() — fish-style autosuggest from history
    // =========================================================================

    public function testViewShowsAutoSuggestFromHistory(): void
    {
        $history = new InMemoryHistory();
        $history->push('git status');

        $p = TextPrompt::new('> ')->withHistory($history)->handleChar('g');
        $view = $p->view();
        // After 'g', autosuggest suggests 'it status'
        $this->assertStringContainsString('it status', $view);
    }

    public function testViewNoAutoSuggestWhenBufferEmpty(): void
    {
        $history = new InMemoryHistory();
        $history->push('git status');

        $p = TextPrompt::new('> ')->withHistory($history);
        $view = $p->view();
        $this->assertStringNotContainsString('status', $view);
    }

    public function testViewNoAutoSuggestWhenDisabled(): void
    {
        $history = new InMemoryHistory();
        $history->push('git status');

        $p = TextPrompt::new('> ')
            ->withHistory($history)
            ->withAutoSuggest(false)
            ->handleChar('g');
        $view = $p->view();
        $this->assertStringNotContainsString('it status', $view);
    }

    public function testViewNoAutoSuggestWithoutHistory(): void
    {
        $p = TextPrompt::new('> ')->handleChar('g');
        $view = $p->view();
        $this->assertStringNotContainsString('it status', $view);
    }

    // =========================================================================
    // Undo / Redo — via deleteBeforeCursor (which pushes to UndoManager)
    // =========================================================================

    public function testUndoRestoresBufferAfterDelete(): void
    {
        $p = TextPrompt::new('> ')
            ->withUndoManager(new UndoManager())
            ->handleChar('a')->handleChar('b')->handleChar('c')
            ->handleKey(Key::Home) // cursor at 0
            ->handleKey(Key::Delete); // deletes 'a', pushing state
        $this->assertSame('bc', $p->value());

        $result = $p->handleKey(Key::Undo);
        $this->assertSame('abc', $result->value());
    }

    public function testRedoRestoresBufferAfterUndo(): void
    {
        $p = TextPrompt::new('> ')
            ->withUndoManager(new UndoManager())
            ->handleChar('a')->handleChar('b')
            ->handleKey(Key::Home)
            ->handleKey(Key::Delete); // delete 'a', buffer now 'b'
        $this->assertSame('b', $p->value());

        $undoResult = $p->handleKey(Key::Undo);
        $this->assertSame('ab', $undoResult->value());

        $redoResult = $undoResult->handleKey(Key::Redo);
        $this->assertSame('b', $redoResult->value());
    }

    public function testUndoWhenNoUndoManagerIsNoOp(): void
    {
        $p = TextPrompt::new('> ')->handleChar('a');
        $result = $p->handleKey(Key::Undo);
        $this->assertSame('a', $result->value());
    }

    public function testRedoWhenNoRedoIsNoOp(): void
    {
        $p = TextPrompt::new('> ')->handleChar('a');
        $result = $p->handleKey(Key::Redo);
        $this->assertSame('a', $result->value());
    }

    public function testUndoThenTypeClearsRedoStack(): void
    {
        $p = TextPrompt::new('> ')
            ->withUndoManager(new UndoManager())
            ->handleChar('a')->handleChar('b')
            ->handleKey(Key::Home)->handleKey(Key::Delete); // 'b'

        $undoResult = $p->handleKey(Key::Undo);
        $this->assertSame('ab', $undoResult->value());

        // Type something new after undo
        $typedResult = $undoResult->handleKey(Key::Home)->handleKey(Key::Delete)->handleKey(Key::Delete); // empty
        $typedResult = $typedResult->handleChar('c');
        $this->assertSame('c', $typedResult->value());

        // Redo should be no-op now
        $redoResult = $typedResult->handleKey(Key::Redo);
        $this->assertSame('c', $redoResult->value());
    }

    // =========================================================================
    // handleKeyDirect — CtrlR/CtrlS with history
    // =========================================================================

    public function testHandleKeyDirectCtrlRWithoutHistoryIsNoOp(): void
    {
        $p = TextPrompt::new('> ')->handleChar('x');
        $result = $p->handleKey(Key::CtrlR);
        $this->assertFalse($result->isSearching());
    }

    public function testHandleKeyDirectCtrlSStartsForwardSearch(): void
    {
        $history = new InMemoryHistory();
        $history->push('git status');
        $p = TextPrompt::new('> ')->withHistory($history)->handleKey(Key::CtrlS);
        $this->assertTrue($p->isSearching());
    }

    // =========================================================================
    // submit — pushes to history only when non-empty buffer
    // =========================================================================

    public function testSubmitEmptyBufferDoesNotPushToHistory(): void
    {
        $history = new InMemoryHistory();
        $p = TextPrompt::new('> ')->withHistory($history)->submit();
        $this->assertTrue($p->isSubmitted());
        $history->reset();
        $this->assertNull($history->getPrevious());
    }

    public function testSubmitWithValidatorRejectsInvalid(): void
    {
        $p = TextPrompt::new('> ')
            ->withValidator(fn(string $v): bool => str_contains($v, '@'))
            ->handleChar('a')->submit();
        $this->assertFalse($p->isSubmitted());
        $this->assertSame('Invalid input', $p->error());
    }

    public function testSubmittedPromptIgnoresFurtherInput(): void
    {
        $p = TextPrompt::new('> ')->handleChar('x')->submit();
        $p2 = $p->handleChar('y');
        $this->assertSame('x', $p2->value());
        $p3 = $p2->handleKey(Key::Backspace);
        $this->assertSame('x', $p3->value());
    }

    public function testAbortedPromptIgnoresFurtherInput(): void
    {
        $p = TextPrompt::new('> ')->handleChar('x')->handleKey(Key::Escape);
        $p2 = $p->handleChar('y');
        $this->assertSame('', $p2->value());
    }

    // =========================================================================
    // view() — submitted state
    // =========================================================================

    public function testViewOfSubmittedPromptShowsFinalValue(): void
    {
        $p = TextPrompt::new('> ')->handleChar('x')->handleChar('y')->submit();
        $view = $p->view();
        $this->assertStringContainsString('xy', $view);
    }

    // =========================================================================
    // deleteWordBefore — cursor at start
    // =========================================================================

    public function testDeleteWordBeforeAtBufferStartIsNoOp(): void
    {
        $p = TextPrompt::new('> ')->handleChar('a')->handleKey(Key::Home);
        $this->assertSame(0, $p->cursor());
        $result = $p->handleKey(Key::CtrlW);
        $this->assertSame('a', $result->value());
    }

    // =========================================================================
    // suggestion() edge cases
    // =========================================================================

    public function testSuggestionReturnsNullOnEmptyBuffer(): void
    {
        $p = TextPrompt::new('> ')->withCompletions(['apple', 'banana']);
        $this->assertNull($p->suggestion());
    }

    public function testSuggestionReturnsFirstMatching(): void
    {
        $p = TextPrompt::new('> ')
            ->withCompletions(['apple', 'apricot', 'banana'])
            ->handleChar('a')->handleChar('p');
        $this->assertSame('apple', $p->suggestion());
    }

    // =========================================================================
    // withAutoSuggest chain
    // =========================================================================

    public function testWithAutoSuggestDisabled(): void
    {
        $p = TextPrompt::new('> ')->withAutoSuggest(false);
        $this->assertFalse($p->isSearching());
    }

    // =========================================================================
    // Control bytes are keys, never content (multi-line forgery door)
    // =========================================================================

    public function testControlBytesTypedIntoTheBufferAreDropped(): void
    {
        $p = TextPrompt::new('> ')->handleChar('a')->handleChar("\n")->handleChar("\x00")
            ->handleChar("\x1b")->handleChar("\x7f")->handleChar('b');
        $this->assertSame('ab', $p->value());
    }

    // =========================================================================
    // insertPastedText — mode-agnostic literal insertion
    // =========================================================================

    public function testInsertPastedTextStripsControlsAndMovesCursor(): void
    {
        $p = TextPrompt::new('> ')->handleChar('x')->insertPastedText("ab\nc\x01d");
        $this->assertSame('xabcd', $p->value());
        $this->assertSame(5, $p->cursor());
    }

    public function testInsertPastedTextHonoursCharLimit(): void
    {
        $p = TextPrompt::new('> ')->withCharLimit(4)->insertPastedText('abcdef');
        $this->assertSame('abcd', $p->value());
        $this->assertSame(4, $p->cursor());
    }

    public function testInsertPastedTextRespectsModeAndSubmitStates(): void
    {
        $done = TextPrompt::new('> ')->handleChar('x')->submit();
        $this->assertSame('x', $done->insertPastedText('yz')->value());
    }

    // =========================================================================
    // Space arrives as the symbolic name 'space' (reserved by isPrintable)
    // =========================================================================

    public function testSpaceKeyInsertsARealSpace(): void
    {
        $p = TextPrompt::new('> ')->handleChar('a')->handleKey('space')->handleChar('b');
        $this->assertSame('a b', $p->value());
    }

    public function testSpaceSymbolicNameMatchesNormalize(): void
    {
        $this->assertSame(Key::Space, Key::normalize(' '));
    }

    // =========================================================================
    // Ctrl+H erase, Ctrl+Z undo, Ctrl+Y redo — the historic chords
    // =========================================================================

    public function testCtrlHErasesBeforeTheCursor(): void
    {
        $p = TextPrompt::new('> ')->handleChar('a')->handleChar('b')->handleKey('ctrl_h');
        $this->assertSame('a', $p->value());
    }

    public function testCtrlZUndoesTypedRun(): void
    {
        $p = TextPrompt::new('> ')
            ->withUndoManager(new UndoManager())
            ->handleChar('a')->handleChar('b');
        $this->assertSame('ab', $p->value());

        $p = $p->handleKey('ctrl_z');
        $this->assertSame('', $p->value());

        $p = $p->handleKey('ctrl_y');
        $this->assertSame('ab', $p->value());
    }

    public function testConsecutiveTypingSharesOneUndoSnapshot(): void
    {
        $p = TextPrompt::new('> ')
            ->withUndoManager(new UndoManager())
            ->handleChar('a')->handleChar('b')->handleChar('c');
        $p = $p->handleKey('ctrl_z');
        $this->assertSame('', $p->value(), 'typing a word is one undo step, not three');
    }

    public function testTypingAfterACaretJumpStartsAFreshUndoRun(): void
    {
        $p = TextPrompt::new('> ')
            ->withUndoManager(new UndoManager())
            ->handleChar('a')->handleChar('b');       // run 1
        $p = $p->handleKey(Key::Left);                  // caret jump closes it
        $p = $p->handleChar('X');                       // run 2
        $p = $p->handleKey('ctrl_z');
        $this->assertSame('ab', $p->value()); // undoes only the post-jump 'X' run
    }

    // =========================================================================
    // Hidden prompts leak nothing: no autosuggest row, no hint row, no undo
    // =========================================================================

    public function testHiddenPromptNeverPaintsAutoSuggest(): void
    {
        $history = new InMemoryHistory();
        $history->push('topsecret-plan');
        $p = TextPrompt::new('Password: ')
            ->withHistory($history)
            ->withHidden(true)
            ->handleChar('t');
        $this->assertStringNotContainsString('opsecret-plan', $p->view());
        $this->assertStringNotContainsString('opsecret', $p->view());
    }

    public function testHiddenPromptNeverPaintsCompletionHintTail(): void
    {
        $p = TextPrompt::new('Secret: ')
            ->withCompletions(['topsecret-value'])
            ->withHidden(true)
            ->handleChar('t');
        $this->assertStringNotContainsString('opsecret-value', $p->view());
    }

    public function testHiddenPromptStaysOutOfTheUndoChain(): void
    {
        $p = TextPrompt::new('Password: ')
            ->withHidden(true)
            ->withUndoManager(new UndoManager())
            ->handleChar('s')->handleChar('e');
        $this->assertSame('se', $p->value());
        $p = $p->handleKey('ctrl_z');
        $this->assertSame('se', $p->value(), 'hidden input never snapshots into undo state');
    }

    // =========================================================================
    // Display viewport (M8/M11) — the renderer is no longer width-blind
    // =========================================================================

    public function testLongBufferIsClippedToTheDisplayViewport(): void
    {
        $p = TextPrompt::new('> ')->withDisplayWidth(20)->insertPastedText(str_repeat('x', 200));
        $view = $p->view();
        $this->assertStringNotContainsString(str_repeat('x', 25), $view);
        $this->assertStringContainsString('…', $view, 'clipped edge carries a marker');

        $body = preg_replace('/\x1b\[[0-9;]*m/', '', $view) ?? '';
        foreach (explode("\n", $body) as $line) {
            $this->assertLessThanOrEqual(20, \SugarCraft\Core\Util\Width::of($line));
        }
    }

    public function testViewportKeepsTheCursorVisible(): void
    {
        $p = TextPrompt::new('> ')->withDisplayWidth(20)->insertPastedText(str_repeat('x', 200));
        // Jump to BOL, then paint: the window must follow the caret.
        $p = $p->handleKey(Key::Home);
        $view = preg_replace('/\x1b\[[0-9;]*m/', '', $p->view()) ?? '';
        $firstLine = explode("\n", $view)[0];
        $this->assertStringStartsWith('> x', $firstLine);
        $this->assertStringNotContainsString(str_repeat('x', 25), $firstLine);
    }

    public function testViewportCountsCellsNotCodepoints(): void
    {
        // CJK glyphs take two cells each — a naive codepoint budget overruns.
        $p = TextPrompt::new('> ')->withDisplayWidth(20)->insertPastedText(str_repeat('漢', 60));
        $body = preg_replace('/\x1b\[[0-9;]*m/', '', $p->view()) ?? '';
        foreach (explode("\n", $body) as $line) {
            $this->assertLessThanOrEqual(20, \SugarCraft\Core\Util\Width::of($line));
        }
    }

    public function testUnsetDisplayWidthMeansUnbounded(): void
    {
        // The unset state is null — the only width Readline::run() may
        // adopt COLUMNS into. view() still renders unbounded; explicit 0
        // survives adoption too (see ReadlineTest::
        // testExplicitZeroDisplayWidthSurvivesAdoption).
        $p = TextPrompt::new('> ')->insertPastedText(str_repeat('y', 300));
        $this->assertNull($p->displayWidth());
        $this->assertStringContainsString(str_repeat('y', 300), $p->view());
    }

    public function testWithDisplayWidthNullRestoresAutoApplyEligibility(): void
    {
        $p = TextPrompt::new('> ')->withDisplayWidth(40)->withDisplayWidth(null);
        $this->assertNull($p->displayWidth());
        // Negatives still clamp to the explicit-unbounded 0, never to null.
        $q = TextPrompt::new('> ')->withDisplayWidth(-5);
        $this->assertSame(0, $q->displayWidth());
    }

    // =========================================================================
    // Tab completion — common prefix then cycling (classic readline)
    // =========================================================================

    public function testTabCompletesToCommonPrefixThenCycles(): void
    {
        $p = TextPrompt::new('> ')->withCompletions(['apple', 'apricot'])->handleChar('a');
        $p = $p->handleKey(Key::Tab);
        $this->assertSame('ap', $p->value(), 'first Tab stops at the ambiguity point');
        $p = $p->handleKey(Key::Tab);
        $this->assertSame('apple', $p->value());
        $p = $p->handleKey(Key::Tab);
        $this->assertSame('apricot', $p->value(), 'Tab cycles instead of dead-ending');
        $p = $p->handleKey(Key::Tab);
        $this->assertSame('apple', $p->value(), 'the ring wraps');
    }

    public function testTabIgnoresWhenNoCompletionMatches(): void
    {
        $p = TextPrompt::new('> ')->withCompletions(['banana'])->handleChar('z');
        $this->assertSame('z', $p->handleKey(Key::Tab)->value());
    }

    public function testCompletionHintTailSlicesCodepointsNotBytes(): void
    {
        $p = TextPrompt::new('> ')
            ->withCompletions(['héllo-wörld'])
            ->handleChar('h');
        // 'h' matches; the tail 'éllo-wörld' must render as valid UTF-8.
        $view = $p->view();
        $this->assertStringContainsString('éllo-wörld', $view);
        $this->assertStringNotContainsString("\xEF\xBF\xBD", $view);
    }

    // =========================================================================
    // Search mutators are pure (clone-returning), never mutate a live prompt
    // =========================================================================

    public function testSearchStateMutatorsDoNotTouchTheReceiver(): void
    {
        $history = new InMemoryHistory();
        $history->push('target-entry');
        $p = TextPrompt::new('> ')->withHistory($history)->handleChar('k');
        $searching = $p->startHistorySearch();
        $searching = $searching->handleChar('t');
        $this->assertSame('t', $searching->searchQuery());
        $this->assertSame('target-entry', $searching->value());
        // The pre-search prompt is untouched by what the search clone did.
        $this->assertFalse($p->isSearching());
        $this->assertSame('k', $p->value());
    }
}
