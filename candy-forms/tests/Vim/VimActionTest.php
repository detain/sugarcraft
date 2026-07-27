<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Vim;

use PHPUnit\Framework\TestCase;
use SugarCraft\Forms\Vim\VimAction;

/**
 * Tests for {@see VimAction} — actions returned by {@see VimKeyHandler} after processing a key.
 */
final class VimActionTest extends TestCase
{
    // =========================================================================
    // All VimAction cases exist and have expected string values
    // =========================================================================

    public function testCursorLeftCaseExists(): void
    {
        $this->assertSame('cursor-left', VimAction::CursorLeft->value);
    }

    public function testCursorRightCaseExists(): void
    {
        $this->assertSame('cursor-right', VimAction::CursorRight->value);
    }

    public function testCursorWordForwardCaseExists(): void
    {
        $this->assertSame('cursor-word-forward', VimAction::CursorWordForward->value);
    }

    public function testCursorWordBackwardCaseExists(): void
    {
        $this->assertSame('cursor-word-backward', VimAction::CursorWordBackward->value);
    }

    public function testCursorLineStartCaseExists(): void
    {
        $this->assertSame('cursor-line-start', VimAction::CursorLineStart->value);
    }

    public function testCursorLineEndCaseExists(): void
    {
        $this->assertSame('cursor-line-end', VimAction::CursorLineEnd->value);
    }

    public function testDeleteCharCaseExists(): void
    {
        $this->assertSame('delete-char', VimAction::DeleteChar->value);
    }

    public function testDeleteToStartCaseExists(): void
    {
        $this->assertSame('delete-to-start', VimAction::DeleteToStart->value);
    }

    public function testDeleteToEndCaseExists(): void
    {
        $this->assertSame('delete-to-end', VimAction::DeleteToEnd->value);
    }

    public function testDeleteLineCaseExists(): void
    {
        $this->assertSame('delete-line', VimAction::DeleteLine->value);
    }

    public function testYankLineCaseExists(): void
    {
        $this->assertSame('yank-line', VimAction::YankLine->value);
    }

    public function testChangeLineCaseExists(): void
    {
        $this->assertSame('change-line', VimAction::ChangeLine->value);
    }

    public function testDeleteTextObjectCaseExists(): void
    {
        $this->assertSame('delete-text-object', VimAction::DeleteTextObject->value);
    }

    public function testChangeTextObjectCaseExists(): void
    {
        $this->assertSame('change-text-object', VimAction::ChangeTextObject->value);
    }

    public function testYankTextObjectCaseExists(): void
    {
        $this->assertSame('yank-text-object', VimAction::YankTextObject->value);
    }

    public function testPasteCaseExists(): void
    {
        $this->assertSame('paste', VimAction::Paste->value);
    }

    public function testEnterInsertModeCaseExists(): void
    {
        $this->assertSame('enter-insert-mode', VimAction::EnterInsertMode->value);
    }

    public function testEnterNormalModeCaseExists(): void
    {
        $this->assertSame('enter-normal-mode', VimAction::EnterNormalMode->value);
    }

    public function testEnterVisualModeCaseExists(): void
    {
        $this->assertSame('enter-visual-mode', VimAction::EnterVisualMode->value);
    }

    public function testEnterVisualLineModeCaseExists(): void
    {
        $this->assertSame('enter-visual-line-mode', VimAction::EnterVisualLineMode->value);
    }

    public function testHistoryUpCaseExists(): void
    {
        $this->assertSame('history-up', VimAction::HistoryUp->value);
    }

    public function testHistoryDownCaseExists(): void
    {
        $this->assertSame('history-down', VimAction::HistoryDown->value);
    }

    public function testUndoCaseExists(): void
    {
        $this->assertSame('undo', VimAction::Undo->value);
    }

    public function testRedoCaseExists(): void
    {
        $this->assertSame('redo', VimAction::Redo->value);
    }

    public function testNoOpCaseExists(): void
    {
        $this->assertSame('noop', VimAction::NoOp->value);
    }

    // =========================================================================
    // All cases are distinct
    // =========================================================================

    public function testAllCasesAreDistinct(): void
    {
        $cases = VimAction::cases();
        $this->assertCount(25, $cases);

        $values = array_map(static fn(VimAction $c): string => $c->value, $cases);
        $this->assertCount(25, array_unique($values));
    }

    // =========================================================================
    // Backed enum — valueOf / tryFrom work
    // =========================================================================

    public function testValueOfReturnsCorrectCase(): void
    {
        $this->assertSame(VimAction::CursorLeft, VimAction::from('cursor-left'));
        $this->assertSame(VimAction::NoOp, VimAction::from('noop'));
        $this->assertSame(VimAction::EnterInsertMode, VimAction::from('enter-insert-mode'));
        $this->assertSame(VimAction::DeleteChar, VimAction::from('delete-char'));
        $this->assertSame(VimAction::Undo, VimAction::from('undo'));
    }

    public function testTryFromReturnsNullForInvalidValue(): void
    {
        $this->assertNull(VimAction::tryFrom('left'));
        $this->assertNull(VimAction::tryFrom('noop-do'));
        $this->assertNull(VimAction::tryFrom(''));
        $this->assertNull(VimAction::tryFrom('NOOP')); // case-sensitive
    }

    // =========================================================================
    // Categorizing action types — useful for consumers
    // =========================================================================

    public function testCursorActionsAreDistinctFromModeSwitchingActions(): void
    {
        $cursorActions = [
            VimAction::CursorLeft,
            VimAction::CursorRight,
            VimAction::CursorWordForward,
            VimAction::CursorWordBackward,
            VimAction::CursorLineStart,
            VimAction::CursorLineEnd,
        ];
        $modeActions = [
            VimAction::EnterInsertMode,
            VimAction::EnterNormalMode,
            VimAction::EnterVisualMode,
            VimAction::EnterVisualLineMode,
        ];

        foreach ($cursorActions as $action) {
            $this->assertNotContains($action, $modeActions);
        }
        foreach ($modeActions as $action) {
            $this->assertNotContains($action, $cursorActions);
        }
    }

    public function testDeleteActionsAreDistinctFromYankAndChangeActions(): void
    {
        $deleteActions = [VimAction::DeleteChar, VimAction::DeleteLine, VimAction::DeleteTextObject];
        $yankActions = [VimAction::YankLine, VimAction::YankTextObject];
        $changeActions = [VimAction::ChangeLine, VimAction::ChangeTextObject];

        foreach ($deleteActions as $action) {
            $this->assertNotContains($action, $yankActions);
            $this->assertNotContains($action, $changeActions);
        }
    }
}
