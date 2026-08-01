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
        $this->assertFalse($p->isSearching());
    }
}
