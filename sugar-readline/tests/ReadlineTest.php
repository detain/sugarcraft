<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\{
    ConfirmationPrompt,
    Key,
    MultiSelectPrompt,
    SelectionPrompt,
    TextareaPrompt,
    TextPrompt,
};
use SugarCraft\Readline\History\InMemoryHistory;
use SugarCraft\Readline\History\FileHistory;

final class ReadlineTest extends TestCase
{
    // =========================================================================
    // TextPrompt
    // =========================================================================

    public function testTextPromptStartsEmpty(): void
    {
        $p = TextPrompt::new('Name: ');
        $this->assertSame('', $p->value());
        $this->assertSame(0, $p->cursor());
        $this->assertFalse($p->isSubmitted());
        $this->assertFalse($p->isAborted());
    }

    public function testTextPromptHandleCharAppends(): void
    {
        $p = TextPrompt::new('> ')->handleChar('h')->handleChar('i');
        $this->assertSame('hi', $p->value());
        $this->assertSame(2, $p->cursor());
    }

    public function testTextPromptHandleCharRejectsMultiCharString(): void
    {
        $p = TextPrompt::new('> ')->handleChar('hi');  // not a single char
        $this->assertSame('', $p->value());
    }

    public function testTextPromptHandleCharAcceptsMultibyte(): void
    {
        $p = TextPrompt::new('> ')->handleChar('é')->handleChar('日');
        $this->assertSame('é日', $p->value());
        $this->assertSame(2, $p->cursor());
    }

    public function testTextPromptBackspaceAtCursor(): void
    {
        $p = TextPrompt::new('> ')->handleChar('x')->handleChar('y');
        $p = $p->handleKey(Key::Backspace);
        $this->assertSame('x', $p->value());
        $this->assertSame(1, $p->cursor());
    }

    public function testTextPromptBackspaceAtStartIsNoOp(): void
    {
        $p = TextPrompt::new('> ')->handleKey(Key::Backspace);
        $this->assertSame('', $p->value());
        $this->assertSame(0, $p->cursor());
    }

    public function testTextPromptDeleteUnderCursor(): void
    {
        $p = TextPrompt::new('> ')->handleChar('a')->handleChar('b')->handleChar('c')
            ->handleKey(Key::Home)->handleKey(Key::Delete);
        $this->assertSame('bc', $p->value());
        $this->assertSame(0, $p->cursor());
    }

    public function testTextPromptCursorMovement(): void
    {
        $p = TextPrompt::new('> ')->handleChar('a')->handleChar('b')->handleChar('c');
        $this->assertSame(3, $p->cursor());
        $p = $p->handleKey(Key::Left);
        $this->assertSame(2, $p->cursor());
        $p = $p->handleKey(Key::Right);
        $this->assertSame(3, $p->cursor());
    }

    public function testTextPromptHomeAndEnd(): void
    {
        $p = TextPrompt::new('> ')->handleChar('a')->handleChar('b')->handleChar('c');
        $p = $p->handleKey(Key::Home);
        $this->assertSame(0, $p->cursor());
        $p = $p->handleKey(Key::End);
        $this->assertSame(3, $p->cursor());
    }

    public function testTextPromptInsertInMiddle(): void
    {
        $p = TextPrompt::new('> ')->handleChar('a')->handleChar('c')
            ->handleKey(Key::Left)->handleChar('b');
        $this->assertSame('abc', $p->value());
        $this->assertSame(2, $p->cursor());
    }

    public function testTextPromptCtrlUDeletesAllBeforeCursor(): void
    {
        $p = TextPrompt::new('> ')->handleChar('a')->handleChar('b')->handleChar('c')
            ->handleKey(Key::Left)->handleKey(Key::CtrlU);
        $this->assertSame('c', $p->value());
        $this->assertSame(0, $p->cursor());
    }

    public function testTextPromptCtrlKDeletesAllAfterCursor(): void
    {
        $p = TextPrompt::new('> ')->handleChar('a')->handleChar('b')->handleChar('c')
            ->handleKey(Key::Left)->handleKey(Key::CtrlK);
        $this->assertSame('ab', $p->value());
        $this->assertSame(2, $p->cursor());
    }

    public function testTextPromptWithDefault(): void
    {
        $p = TextPrompt::new('> ')->withDefault('hello');
        $this->assertSame('hello', $p->value());
        $this->assertSame(5, $p->cursor());
    }

    public function testTextPromptCharLimit(): void
    {
        $p = TextPrompt::new('> ')->withCharLimit(3)
            ->handleChar('a')->handleChar('b')->handleChar('c')->handleChar('d');
        $this->assertSame('abc', $p->value());
    }

    public function testTextPromptHiddenViewMasksInput(): void
    {
        $p = TextPrompt::new('PIN: ')->withHidden()->handleChar('x')->handleChar('y');
        $this->assertSame('xy', $p->value());      // value() always returns the truth
        $this->assertStringContainsString('*', $p->view());
        $this->assertStringNotContainsString('x', $p->view());
        $this->assertStringNotContainsString('y', $p->view());
    }

    public function testTextPromptTabCompletionAppliesUniqueMatch(): void
    {
        $p = TextPrompt::new('> ')->withCompletions(['banana', 'mango'])
            ->handleChar('b')->handleKey(Key::Tab);
        $this->assertSame('banana', $p->value());
        $this->assertSame(6, $p->cursor());
    }

    public function testTextPromptTabCompletionSuggestsFirstMatch(): void
    {
        $p = TextPrompt::new('> ')->withCompletions(['baby', 'banana'])
            ->handleChar('b')->handleChar('a');
        $this->assertSame('baby', $p->suggestion());
    }

    public function testTextPromptTabIsNoOpWithoutCompletions(): void
    {
        $p = TextPrompt::new('> ')->handleChar('x')->handleKey(Key::Tab);
        $this->assertSame('x', $p->value());
    }

    public function testTextPromptValidatorRejectsSubmit(): void
    {
        $p = TextPrompt::new('> ')->withValidator(fn(string $v): bool => $v !== 'xyz')
            ->handleChar('x')->handleChar('y')->handleChar('z')->submit();
        $this->assertFalse($p->isSubmitted());
        $this->assertSame('Invalid input', $p->error());
    }

    public function testTextPromptValidatorAcceptsSubmit(): void
    {
        $p = TextPrompt::new('> ')->withValidator(fn(string $v): bool => $v !== '')
            ->handleChar('x')->submit();
        $this->assertTrue($p->isSubmitted());
        $this->assertSame('', $p->error());
    }

    public function testTextPromptEnterSubmits(): void
    {
        $p = TextPrompt::new('> ')->handleChar('x')->handleKey(Key::Enter);
        $this->assertTrue($p->isSubmitted());
    }

    public function testTextPromptEscapeAborts(): void
    {
        $p = TextPrompt::new('> ')->handleChar('x')->handleKey(Key::Escape);
        $this->assertTrue($p->isAborted());
        $this->assertSame('', $p->value());
    }

    public function testTextPromptInputIgnoredAfterSubmit(): void
    {
        $p = TextPrompt::new('> ')->handleChar('x')->submit()->handleChar('y');
        $this->assertSame('x', $p->value());
    }

    public function testTextPromptIsImmutable(): void
    {
        $a = TextPrompt::new('> ');
        $b = $a->handleChar('x');
        $this->assertSame('', $a->value());
        $this->assertSame('x', $b->value());
    }

    public function testTextPromptViewContainsLabel(): void
    {
        $p = TextPrompt::new('Name: ')->handleChar('A');
        $this->assertStringContainsString('Name:', $p->view());
        $this->assertStringContainsString('A', $p->view());
    }

    // =========================================================================
    // TextPrompt — History navigation
    // =========================================================================

    public function testTextPromptUpNavigatesToHistoryEntry(): void
    {
        $history = new InMemoryHistory();
        $history->push('previous cmd');

        $p = TextPrompt::new('> ')->withHistory($history)
            ->handleChar('x');

        // Up should navigate to history entry.
        $p = $p->handleKey(Key::Up);
        $this->assertSame('previous cmd', $p->value());
    }

    public function testTextPromptUpSavesCurrentBufferWhenStartingNavigation(): void
    {
        $history = new InMemoryHistory();
        $history->push('old entry');

        $p = TextPrompt::new('> ')->withHistory($history)
            ->handleChar('x')->handleChar('y');

        $p = $p->handleKey(Key::Up);

        // Buffer should now be the history entry.
        $this->assertSame('old entry', $p->value());
    }

    public function testTextPromptDownAfterUpRestoresSavedBuffer(): void
    {
        $history = new InMemoryHistory();
        $history->push('old entry');

        $p = TextPrompt::new('> ')->withHistory($history)
            ->handleChar('x');

        $p = $p->handleKey(Key::Up);   // navigate to history
        $p = $p->handleKey(Key::Down); // back to live buffer

        $this->assertSame('x', $p->value());
    }

    public function testTextPromptDownAtLiveBufferIsNoOp(): void
    {
        $history = new InMemoryHistory();
        $history->push('old');

        $p = TextPrompt::new('> ')->withHistory($history)
            ->handleChar('x');

        // Not navigating history yet — Down should be no-op.
        $p = $p->handleKey(Key::Down);
        $this->assertSame('x', $p->value());
    }

    public function testTextPromptUpThenDownTwiceRestoresSavedBuffer(): void
    {
        $history = new InMemoryHistory();
        $history->push('first');
        $history->push('second');

        $p = TextPrompt::new('> ')->withHistory($history)
            ->handleChar('l')->handleChar('i')->handleChar('v')->handleChar('e');

        $p = $p->handleKey(Key::Up);   // to 'second'
        $p = $p->handleKey(Key::Up);   // to 'first'
        $p = $p->handleKey(Key::Down); // back to 'second'
        $p = $p->handleKey(Key::Down); // back to 'first'
        $p = $p->handleKey(Key::Down); // back to 'live'

        $this->assertSame('live', $p->value());
    }

    public function testTextPromptTypingResetsHistoryNavigation(): void
    {
        $history = new InMemoryHistory();
        $history->push('history cmd');

        $p = TextPrompt::new('> ')->withHistory($history)
            ->handleChar('x');

        $p = $p->handleKey(Key::Up);   // now at history entry
        $p = $p->handleChar('y');      // typing resets navigation position

        // After typing 'y', buffer contains 'history cmd' + 'y' appended at the
        // cursor position (end of buffer), and history navigation is reset.
        $this->assertSame('history cmdy', $p->value());

        // Next Up should navigate from the live buffer — starting fresh at 'history cmd'.
        $p = $p->handleKey(Key::Up);
        $this->assertSame('history cmd', $p->value());
    }

    public function testTextPromptSubmitPushesToHistory(): void
    {
        $history = new InMemoryHistory();

        $p = TextPrompt::new('> ')->withHistory($history)
            ->handleChar('a')->handleChar('b')->submit();

        // History should now contain 'ab'.
        $this->assertSame('ab', $history->getPrevious());
    }

    public function testTextPromptAbortDoesNotPushToHistory(): void
    {
        $history = new InMemoryHistory();

        $p = TextPrompt::new('> ')->withHistory($history)
            ->handleChar('a')->handleKey(Key::Escape);

        // History should be empty — abort does not push.
        $this->assertNull($history->getPrevious());
    }

    public function testTextPromptUpWithEmptyBufferDoesNotSaveToHistory(): void
    {
        $history = new InMemoryHistory();
        $history->push('old entry');

        $p = TextPrompt::new('> ')->withHistory($history)
            ->handleKey(Key::Up);

        // Empty buffer is not saved; navigates directly to history entry.
        $this->assertSame('old entry', $p->value());
    }

    public function testTextPromptUpNavigationPastOldestStaysAtOldest(): void
    {
        $history = new InMemoryHistory();
        $history->push('only');

        $p = TextPrompt::new('> ')->withHistory($history)
            ->handleChar('current');

        $p = $p->handleKey(Key::Up);   // to 'only'
        $p = $p->handleKey(Key::Up);   // past oldest — stays at 'only'

        $this->assertSame('only', $p->value());
    }

    public function testTextPromptWithoutHistoryIgnoresUpDown(): void
    {
        $p = TextPrompt::new('> ')->handleChar('x');
        $p = $p->handleKey(Key::Up);
        $this->assertSame('x', $p->value());
        $p = $p->handleKey(Key::Down);
        $this->assertSame('x', $p->value());
    }

    // =========================================================================
    // SelectionPrompt
    // =========================================================================

    public function testSelectionStartsAtFirstChoice(): void
    {
        $p = SelectionPrompt::new('Pick:', ['a', 'b', 'c']);
        $this->assertSame('a', $p->selectedValue());
        $this->assertSame(0, $p->cursor());
    }

    public function testSelectionDownAndUp(): void
    {
        $p = SelectionPrompt::new('Pick:', ['a', 'b', 'c'])
            ->handleKey(Key::Down)->handleKey(Key::Down);
        $this->assertSame('c', $p->selectedValue());
        $p = $p->handleKey(Key::Up);
        $this->assertSame('b', $p->selectedValue());
    }

    public function testSelectionDownClampsAtEnd(): void
    {
        $p = SelectionPrompt::new('Pick:', ['a', 'b']);
        $p = $p->handleKey(Key::Down)->handleKey(Key::Down)->handleKey(Key::Down);
        $this->assertSame('b', $p->selectedValue());
    }

    public function testSelectionFilterReducesChoices(): void
    {
        $p = SelectionPrompt::new('Pick:', ['apple', 'banana', 'cherry', 'avocado'])
            ->withFilter('a');
        // 'apple', 'banana', 'avocado' all contain 'a'
        $this->assertSame(3, $p->filteredCount());
        $this->assertSame('apple', $p->selectedValue());
    }

    public function testSelectionFilterEmptyMatchesAll(): void
    {
        $p = SelectionPrompt::new('Pick:', ['a', 'b'])->withFilter('xyz')->withFilter('');
        $this->assertSame(2, $p->filteredCount());
        $this->assertSame('a', $p->selectedValue());
    }

    public function testSelectionFilterNoMatch(): void
    {
        $p = SelectionPrompt::new('Pick:', ['a', 'b'])->withFilter('zzz');
        $this->assertSame(0, $p->filteredCount());
        $this->assertNull($p->selectedValue());
    }

    public function testSelectionEnterSubmits(): void
    {
        $p = SelectionPrompt::new('Pick:', ['a', 'b'])->handleKey(Key::Enter);
        $this->assertTrue($p->isSubmitted());
        $this->assertSame('a', $p->selectedValue());
    }

    public function testSelectionEscapeAborts(): void
    {
        $p = SelectionPrompt::new('Pick:', ['a', 'b'])->handleKey(Key::Escape);
        $this->assertTrue($p->isAborted());
        $this->assertNull($p->selectedValue());
    }

    public function testSelectionPagination(): void
    {
        $items = range('A', 'Z');                         // 26 items
        $p = SelectionPrompt::new('Pick:', $items)->withPageSize(5);
        $this->assertSame(6, $p->totalPages());
        $this->assertSame(0, $p->currentPage());
        $this->assertSame(['A', 'B', 'C', 'D', 'E'], $p->currentPageItems());

        $p = $p->handleKey(Key::PageDown);
        $this->assertSame(1, $p->currentPage());
        $this->assertSame(['F', 'G', 'H', 'I', 'J'], $p->currentPageItems());
    }

    public function testSelectionDownCrossesPageBoundary(): void
    {
        $p = SelectionPrompt::new('Pick:', range('A', 'J'))->withPageSize(3);
        for ($i = 0; $i < 4; $i++) {
            $p = $p->handleKey(Key::Down);
        }
        $this->assertSame('E', $p->selectedValue());     // index 4
        $this->assertSame(1, $p->currentPage());          // page 1 = D,E,F
    }

    public function testSelectionViewContainsLabelAndChoices(): void
    {
        $view = SelectionPrompt::new('Pick:', ['apple', 'banana'])->view();
        $this->assertStringContainsString('Pick:', $view);
        $this->assertStringContainsString('apple', $view);
        $this->assertStringContainsString('banana', $view);
    }

    // =========================================================================
    // MultiSelectPrompt
    // =========================================================================

    public function testMultiSelectStartsEmpty(): void
    {
        $p = MultiSelectPrompt::new('Pick:', ['a', 'b']);
        $this->assertSame(0, $p->selectionCount());
        $this->assertSame([], $p->selectedValues());
    }

    public function testMultiSelectSpaceTogglesCurrent(): void
    {
        $p = MultiSelectPrompt::new('Pick:', ['a', 'b', 'c']);
        $p = $p->handleKey(Key::Space);                       // mark a
        $p = $p->handleKey(Key::Down)->handleKey(Key::Space); // mark b
        $this->assertSame(['a', 'b'], $p->selectedValues());
    }

    public function testMultiSelectSpaceUnmarks(): void
    {
        $p = MultiSelectPrompt::new('Pick:', ['a', 'b'])
            ->handleKey(Key::Space)->handleKey(Key::Space);
        $this->assertSame([], $p->selectedValues());
    }

    public function testMultiSelectMaxRolloverFifo(): void
    {
        // Max 2 marks: marking a third deselects the oldest (FIFO).
        $p = MultiSelectPrompt::new('Pick:', ['a', 'b', 'c', 'd'])
            ->withMaxSelections(2)
            ->handleKey(Key::Space)->handleKey(Key::Down)
            ->handleKey(Key::Space)->handleKey(Key::Down)
            ->handleKey(Key::Space);
        $this->assertSame(['b', 'c'], $p->selectedValues());
    }

    public function testMultiSelectCanSubmitRespectsMin(): void
    {
        $p = MultiSelectPrompt::new('Pick:', ['a', 'b', 'c'])->withMinSelections(2);
        $this->assertFalse($p->canSubmit());
        $p = $p->handleKey(Key::Space);
        $this->assertFalse($p->canSubmit());
        $p = $p->handleKey(Key::Down)->handleKey(Key::Space);
        $this->assertTrue($p->canSubmit());
    }

    public function testMultiSelectEnterRespectsMin(): void
    {
        $p = MultiSelectPrompt::new('Pick:', ['a', 'b'])->withMinSelections(1)
            ->handleKey(Key::Enter);
        $this->assertFalse($p->isSubmitted());

        $p = $p->handleKey(Key::Space)->handleKey(Key::Enter);
        $this->assertTrue($p->isSubmitted());
    }

    public function testMultiSelectFilterPreservesMarks(): void
    {
        $p = MultiSelectPrompt::new('Pick:', ['apple', 'banana', 'cherry'])
            ->handleKey(Key::Space);                        // mark apple
        $p = $p->withFilter('rry');                          // shows only cherry
        $this->assertSame(1, $p->filteredCount());
        $this->assertSame(['apple'], $p->selectedValues()); // mark survives filter
    }

    public function testMultiSelectAbortClearsResult(): void
    {
        $p = MultiSelectPrompt::new('Pick:', ['a', 'b'])
            ->handleKey(Key::Space)->handleKey(Key::CtrlC);
        $this->assertTrue($p->isAborted());
        $this->assertSame([], $p->selectedValues());
    }

    public function testMultiSelectPagination(): void
    {
        $p = MultiSelectPrompt::new('Pick:', range('A', 'J'))->withPageSize(4);
        $this->assertSame(3, $p->totalPages());
        $this->assertSame(['A', 'B', 'C', 'D'], $p->currentPageItems());
        $p = $p->handleKey(Key::PageDown);
        $this->assertSame(['E', 'F', 'G', 'H'], $p->currentPageItems());
    }

    public function testMultiSelectViewShowsMarkers(): void
    {
        $view = MultiSelectPrompt::new('Pick:', ['a', 'b'])->view();
        $this->assertStringContainsString('○', $view);     // unmarked glyph
    }

    // =========================================================================
    // ConfirmationPrompt
    // =========================================================================

    public function testConfirmationDefaultYes(): void
    {
        $p = ConfirmationPrompt::new('Sure?')->submit();
        $this->assertTrue($p->result());
    }

    public function testConfirmationDefaultNo(): void
    {
        $p = ConfirmationPrompt::new('Sure?', false)->submit();
        $this->assertFalse($p->result());
    }

    public function testConfirmationYKeySelects(): void
    {
        $p = ConfirmationPrompt::new('Sure?', false);
        $this->assertFalse($p->currentValue());
        $p = $p->handleKey('y');
        $this->assertTrue($p->currentValue());
        $this->assertFalse($p->isSubmitted());           // y selects but does not submit
    }

    public function testConfirmationNKeySelects(): void
    {
        $p = ConfirmationPrompt::new('Sure?')->handleKey('n');
        $this->assertFalse($p->currentValue());
        $this->assertFalse($p->isSubmitted());
    }

    public function testConfirmationLeftSelectsYesRightSelectsNo(): void
    {
        $p = ConfirmationPrompt::new('Sure?')->handleKey(Key::Right);
        $this->assertFalse($p->currentValue());
        $p = $p->handleKey(Key::Left);
        $this->assertTrue($p->currentValue());
    }

    public function testConfirmationTabToggles(): void
    {
        $p = ConfirmationPrompt::new('Sure?');
        $this->assertTrue($p->currentValue());
        $p = $p->handleKey(Key::Tab);
        $this->assertFalse($p->currentValue());
        $p = $p->handleKey(Key::Tab);
        $this->assertTrue($p->currentValue());
    }

    public function testConfirmationChangeMindAfterN(): void
    {
        // n selects No, then left switches back to Yes, then submit.
        $p = ConfirmationPrompt::new('Sure?')
            ->handleKey('n')->handleKey(Key::Left)->submit();
        $this->assertTrue($p->isSubmitted());
        $this->assertTrue($p->result());
    }

    public function testConfirmationEscapeAborts(): void
    {
        $p = ConfirmationPrompt::new('Sure?')->handleKey(Key::Escape);
        $this->assertTrue($p->isAborted());
        $this->assertFalse($p->result());
    }

    public function testConfirmationViewContainsLabel(): void
    {
        $view = ConfirmationPrompt::new('Continue?')->view();
        $this->assertStringContainsString('Continue?', $view);
        $this->assertStringContainsString('Yes', $view);
        $this->assertStringContainsString('No', $view);
    }

    // =========================================================================
    // TextareaPrompt
    // =========================================================================

    public function testTextareaStartsEmpty(): void
    {
        $p = TextareaPrompt::new('Notes:');
        $this->assertSame('', $p->value());
        $this->assertSame(1, $p->lineCount());
        $this->assertSame(0, $p->cursorLine());
        $this->assertSame(0, $p->cursorCol());
    }

    public function testTextareaTypeOnSingleLine(): void
    {
        $p = TextareaPrompt::new('> ')->handleChar('H')->handleChar('i');
        $this->assertSame('Hi', $p->value());
        $this->assertSame(2, $p->cursorCol());
    }

    public function testTextareaEnterStartsNewLine(): void
    {
        $p = TextareaPrompt::new('> ')->handleChar('A')->handleKey(Key::Enter)->handleChar('B');
        $this->assertSame("A\nB", $p->value());
        $this->assertSame(1, $p->cursorLine());
        $this->assertSame(1, $p->cursorCol());
    }

    public function testTextareaArrowMovesBetweenLines(): void
    {
        $p = TextareaPrompt::new('> ')->withDefault("alpha\nbeta");
        $this->assertSame(1, $p->cursorLine());
        $p = $p->handleKey(Key::Up);
        $this->assertSame(0, $p->cursorLine());
        $p = $p->handleKey(Key::Down);
        $this->assertSame(1, $p->cursorLine());
    }

    public function testTextareaWithDefaultPositionsCursorAtEnd(): void
    {
        $p = TextareaPrompt::new('> ')->withDefault("alpha\nbeta");
        $this->assertSame(1, $p->cursorLine());
        $this->assertSame(4, $p->cursorCol());
    }

    public function testTextareaBackspaceMergesLines(): void
    {
        $p = TextareaPrompt::new('> ')->handleChar('A')->handleKey(Key::Enter)
            ->handleChar('B')->handleKey(Key::Home)->handleKey(Key::Backspace);
        $this->assertSame('AB', $p->value());
        $this->assertSame(0, $p->cursorLine());
        $this->assertSame(1, $p->cursorCol());
    }

    public function testTextareaMaxLinesEnforced(): void
    {
        $p = TextareaPrompt::new('> ')->withMaxLines(2)
            ->handleKey(Key::Enter)->handleKey(Key::Enter);
        $this->assertSame(2, $p->lineCount());
    }

    public function testTextareaSubmit(): void
    {
        $p = TextareaPrompt::new('> ')->handleChar('x')->submit();
        $this->assertTrue($p->isSubmitted());
        $this->assertSame('x', $p->value());
    }

    public function testTextareaAbort(): void
    {
        $p = TextareaPrompt::new('> ')->handleChar('x')->handleKey(Key::Escape);
        $this->assertTrue($p->isAborted());
        $this->assertSame('', $p->value());
    }

    public function testTextareaViewContainsLabel(): void
    {
        $p = TextareaPrompt::new('Notes:')->handleChar('h')->handleChar('i');
        $view = $p->view();
        $this->assertStringContainsString('Notes:', $view);
        $this->assertStringContainsString('h', $view);
        $this->assertStringContainsString('i', $view);
    }
}
