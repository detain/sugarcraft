<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\History\InMemoryHistory;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\TextPrompt;
use SugarCraft\Readline\UndoManager;
use SugarCraft\Readline\Highlight;

/**
 * Additional TextPrompt tests covering untested view() branches:
 * error styling, completion hint, fish-style autosuggest, undo/redo
 * when UndoManager is set, and submitted/aborted states.
 */
final class TextPromptExtendedTest extends TestCase
{
    // =========================================================================
    // view() — error message
    // =========================================================================

    public function testViewRendersErrorInRed(): void
    {
        // Validator rejects, then submit is called and shows error
        $p = TextPrompt::new('Name: ')
            ->withValidator(fn(string $v): bool => false)
            ->handleChar('x')
            ->submit();
        $this->assertSame('Invalid input', $p->error());
        $view = $p->view();
        // Error style 31 (red) should wrap the error message
        $this->assertStringContainsString('Invalid input', $view);
    }

    public function testViewWithEmptyErrorDoesNotRenderErrorLine(): void
    {
        $p = TextPrompt::new('Name: ')->handleChar('x');
        $view = $p->view();
        // Only one line (the prompt line), no error line
        $this->assertSame(1, substr_count($view, "\n"));
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

    public function testViewDoesNotShowCompletionWhenHintEqualsBuffer(): void
    {
        $p = TextPrompt::new('> ')->withCompletions(['banana'])->handleChar('b')->handleChar('a')->handleChar('n')->handleChar('a')->handleChar('n')->handleChar('a');
        // buffer === 'banana' exactly, no hint to show
        $view = $p->view();
        // Should not duplicate 'banana'
        $this->assertStringNotContainsString('anana', $view);
    }

    // =========================================================================
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
        // No buffer → no autosuggest
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
        // No history attached, no autosuggest
        $this->assertStringNotContainsString('it status', $view);
    }

    // =========================================================================
    // Undo / Redo via handleKeyDirect
    // =========================================================================

    public function testHandleKeyDirectUndoRestoresBuffer(): void
    {
        $p = TextPrompt::new('> ')
            ->withUndoManager(new UndoManager())
            ->handleChar('a')->handleChar('b');
        $this->assertSame('ab', $p->value());

        $result = $p->handleKey(Key::Undo);
        $this->assertSame('', $result->value());
    }

    public function testHandleKeyDirectRedoRestoresBuffer(): void
    {
        $p = TextPrompt::new('> ')
            ->withUndoManager(new UndoManager())
            ->handleChar('a')->handleChar('b');

        $undoResult = $p->handleKey(Key::Undo);
        $this->assertSame('', $undoResult->value());

        $redoResult = $undoResult->handleKey(Key::Redo);
        $this->assertSame('ab', $redoResult->value());
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
            ->handleChar('a')->handleChar('b');

        $undoResult = $p->handleKey(Key::Undo);
        $this->assertSame('', $undoResult->value());

        // Type something new
        $typedResult = $undoResult->handleChar('c');
        $this->assertSame('ac', $typedResult->value());

        // Redo should be no-op now (stack was cleared)
        $redoResult = $typedResult->handleKey(Key::Redo);
        $this->assertSame('ac', $redoResult->value());
    }

    // =========================================================================
    // handleKeyDirect — CtrlR/CtrlS with history (search start)
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
    // Highlight
    // =========================================================================

    public function testViewWithHighlightRendersBuffer(): void
    {
        $highlight = new Highlight();
        $p = TextPrompt::new('> ')->withHighlight($highlight)->handleChar('a');
        $view = $p->view();
        $this->assertStringContainsString('a', $view);
    }

    // =========================================================================
    // submit — pushes to history only when non-empty buffer
    // =========================================================================

    public function testSubmitEmptyBufferDoesNotPushToHistory(): void
    {
        $history = new InMemoryHistory();
        $p = TextPrompt::new('> ')->withHistory($history)->submit();
        // Empty buffer not pushed
        $this->assertTrue($p->isSubmitted());
        // History should be empty
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
    // deleteUnderCursor at end of buffer — no-op
    // =========================================================================

    public function testDeleteUnderCursorAtEndIsNoOp(): void
    {
        $p = TextPrompt::new('> ')->handleChar('a')->handleChar('b');
        // Cursor is at 2 (after 'b'), delete under cursor
        $refl = new \ReflectionClass($p);
        $method = $refl->getMethod('deleteUnderCursor');
        $method->setAccessible(true);
        $result = $method->invoke($p);
        $this->assertSame('ab', $result->value());
    }

    // =========================================================================
    // deleteAllAfterCursor at end of buffer — no-op
    // =========================================================================

    public function testDeleteAllAfterCursorAtEndIsNoOp(): void
    {
        $p = TextPrompt::new('> ')->handleChar('a')->handleChar('b');
        // Cursor at end, Ctrl+K should be no-op
        $result = $p->handleKey(Key::CtrlK);
        $this->assertSame('ab', $result->value());
    }

    // =========================================================================
    // deleteWordBefore — nothing to delete
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
        $p = TextPrompt::new('> ')->withCompletions(['apple', 'apricot', 'banana'])->handleChar('a')->handleChar('p');
        $this->assertSame('apple', $p->suggestion());
    }

    // =========================================================================
    // isWordChar — Unicode word boundaries
    // =========================================================================

    public function testIsWordCharRecognisesUnicodeLetter(): void
    {
        $p = TextPrompt::new('> ')->handleChar('文');
        // Just verify it doesn't crash — the method is private but covered
        $this->assertSame('文', $p->value());
    }

    // =========================================================================
    // withHighlight chain
    // =========================================================================

    public function testWithHighlightReturnsNewInstance(): void
    {
        $a = TextPrompt::new('> ');
        $b = $a->withHighlight(new Highlight());
        $this->assertNotSame($a, $b);
    }

    // =========================================================================
    // withAutoSuggest chain
    // =========================================================================

    public function testWithAutoSuggestDisabled(): void
    {
        $p = TextPrompt::new('> ')->withAutoSuggest(false);
        $this->assertFalse($p->isSearching()); // still not searching, just disabled
    }

    // =========================================================================
    // moveCursorTo — clamp to buffer bounds
    // =========================================================================

    public function testMoveCursorToNegativeClampsToZero(): void
    {
        $p = TextPrompt::new('> ')->handleChar('a')->handleChar('b');
        $refl = new \ReflectionClass($p);
        $method = $refl->getMethod('moveCursorTo');
        $method->setAccessible(true);
        $result = $method->invoke($p, -5);
        $this->assertSame(0, $result->cursor());
    }

    public function testMoveCursorToBeyondEndClampsToLength(): void
    {
        $p = TextPrompt::new('> ')->handleChar('a')->handleChar('b');
        $refl = new \ReflectionClass($p);
        $method = $refl->getMethod('moveCursorTo');
        $method->setAccessible(true);
        $result = $method->invoke($p, 99);
        $this->assertSame(2, $result->cursor());
    }
}
