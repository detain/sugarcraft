<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Vim;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\Vim\VimAction;
use SugarCraft\Forms\Vim\VimKeyHandler;
use SugarCraft\Forms\Vim\VimOperator;
use SugarCraft\Forms\Vim\VimState;
use SugarCraft\Forms\Vim\TextObjectScope;

/**
 * Tests for {@see VimKeyHandler} — the unified vim keybinding handler.
 */
final class VimKeyHandlerTest extends TestCase
{
    // =========================================================================
    // handle() — Insert mode
    // =========================================================================

    public function testInsertModeEscapeEntersNormalMode(): void
    {
        $action = VimKeyHandler::handle('esc', VimState::Insert);
        $this->assertSame(VimAction::EnterNormalMode, $action);
    }

    public function testInsertModeArrowKeys(): void
    {
        $this->assertSame(VimAction::CursorLeft,  VimKeyHandler::handle('left',  VimState::Insert));
        $this->assertSame(VimAction::CursorRight, VimKeyHandler::handle('right', VimState::Insert));
        $this->assertSame(VimAction::HistoryUp,   VimKeyHandler::handle('up',     VimState::Insert));
        $this->assertSame(VimAction::HistoryDown, VimKeyHandler::handle('down',   VimState::Insert));
    }

    public function testInsertModeHomeEnd(): void
    {
        $this->assertSame(VimAction::CursorLineStart, VimKeyHandler::handle('home', VimState::Insert));
        $this->assertSame(VimAction::CursorLineEnd,  VimKeyHandler::handle('end',  VimState::Insert));
    }

    public function testInsertModeNoOpForUnrecognizedKey(): void
    {
        $this->assertSame(VimAction::NoOp, VimKeyHandler::handle('x', VimState::Insert));
        $this->assertSame(VimAction::NoOp, VimKeyHandler::handle('0', VimState::Insert));
    }

    public function testInsertModeDisabledReturnsNoOp(): void
    {
        $action = VimKeyHandler::handle('a', VimState::Insert, VimKeyHandler::FEAT_NORMAL);
        $this->assertSame(VimAction::NoOp, $action);
    }

    // =========================================================================
    // handle() — Normal mode
    // =========================================================================

    public function testNormalModeEscapeIsNoOp(): void
    {
        $this->assertSame(VimAction::NoOp, VimKeyHandler::handle('esc', VimState::Normal));
    }

    public function testNormalModeArrowKeys(): void
    {
        $this->assertSame(VimAction::CursorLeft,  VimKeyHandler::handle('left',  VimState::Normal));
        $this->assertSame(VimAction::CursorRight, VimKeyHandler::handle('right', VimState::Normal));
        $this->assertSame(VimAction::HistoryUp,   VimKeyHandler::handle('up',     VimState::Normal));
        $this->assertSame(VimAction::HistoryDown, VimKeyHandler::handle('down',   VimState::Normal));
    }

    public function testNormalModeHjkl(): void
    {
        $this->assertSame(VimAction::CursorLeft,        VimKeyHandler::handle('h', VimState::Normal));
        $this->assertSame(VimAction::CursorRight,       VimKeyHandler::handle('l', VimState::Normal));
        $this->assertSame(VimAction::CursorWordForward,  VimKeyHandler::handle('w', VimState::Normal));
        $this->assertSame(VimAction::CursorWordBackward, VimKeyHandler::handle('b', VimState::Normal));
    }

    public function testNormalModeLineBounds(): void
    {
        $this->assertSame(VimAction::CursorLineStart, VimKeyHandler::handle('0', VimState::Normal));
        $this->assertSame(VimAction::CursorLineEnd,   VimKeyHandler::handle('$', VimState::Normal));
    }

    public function testNormalModeEnterInsert(): void
    {
        $this->assertSame(VimAction::EnterInsertMode, VimKeyHandler::handle('i', VimState::Normal));
        $this->assertSame(VimAction::EnterInsertMode, VimKeyHandler::handle('a', VimState::Normal));
        $this->assertSame(VimAction::EnterInsertMode, VimKeyHandler::handle('A', VimState::Normal));
        $this->assertSame(VimAction::EnterInsertMode, VimKeyHandler::handle('I', VimState::Normal));
    }

    public function testNormalModeVimVisualOnlyWhenEnabled(): void
    {
        $this->assertSame(VimAction::EnterVisualMode, VimKeyHandler::handle('v', VimState::Normal, VimKeyHandler::FEAT_VISUAL));
        $this->assertSame(VimAction::NoOp,            VimKeyHandler::handle('v', VimState::Normal, VimKeyHandler::FEAT_NORMAL));
    }

    public function testNormalModeDeleteYankChange(): void
    {
        $this->assertSame(VimAction::DeleteChar, VimKeyHandler::handle('x', VimState::Normal));
        $this->assertSame(VimAction::DeleteLine, VimKeyHandler::handle('d', VimState::Normal));
        $this->assertSame(VimAction::YankLine,   VimKeyHandler::handle('y', VimState::Normal));
        $this->assertSame(VimAction::ChangeLine,  VimKeyHandler::handle('c', VimState::Normal));
    }

    public function testNormalModeUndoRedoWhenEnabled(): void
    {
        $this->assertSame(VimAction::Undo, VimKeyHandler::handle('u', VimState::Normal, VimKeyHandler::FEAT_UNDO));
        $this->assertSame(VimAction::NoOp, VimKeyHandler::handle('u', VimState::Normal, VimKeyHandler::FEAT_NORMAL));
        $this->assertSame(VimAction::Redo, VimKeyHandler::handle('ctrl_r', VimState::Normal, VimKeyHandler::FEAT_UNDO, true));
        $this->assertSame(VimAction::NoOp, VimKeyHandler::handle('ctrl_r', VimState::Normal, VimKeyHandler::FEAT_NORMAL, true));
    }

    public function testNormalModePaste(): void
    {
        $this->assertSame(VimAction::Paste, VimKeyHandler::handle('p', VimState::Normal));
    }

    public function testNormalModeDisabledReturnsNoOp(): void
    {
        $this->assertSame(VimAction::NoOp, VimKeyHandler::handle('h', VimState::Normal, VimKeyHandler::FEAT_INSERT));
        $this->assertSame(VimAction::NoOp, VimKeyHandler::handle('0', VimState::Normal, VimKeyHandler::FEAT_INSERT));
    }

    public function testNormalModeNoOpForUnrecognizedKey(): void
    {
        $this->assertSame(VimAction::NoOp, VimKeyHandler::handle('z', VimState::Normal));
    }

    // =========================================================================
    // handle() — Visual mode
    // =========================================================================

    public function testVisualModeEscapeEntersNormalMode(): void
    {
        $this->assertSame(VimAction::EnterNormalMode, VimKeyHandler::handle('esc', VimState::Visual));
    }

    public function testVisualModeDisabledReturnsNormalMode(): void
    {
        $this->assertSame(VimAction::EnterNormalMode, VimKeyHandler::handle('h', VimState::Visual, VimKeyHandler::FEAT_NORMAL));
    }

    public function testVisualModeMovement(): void
    {
        $this->assertSame(VimAction::CursorLeft,        VimKeyHandler::handle('h', VimState::Visual));
        $this->assertSame(VimAction::CursorRight,       VimKeyHandler::handle('l', VimState::Visual));
        $this->assertSame(VimAction::CursorWordForward,  VimKeyHandler::handle('w', VimState::Visual));
        $this->assertSame(VimAction::CursorWordBackward, VimKeyHandler::handle('b', VimState::Visual));
        $this->assertSame(VimAction::CursorLineStart,    VimKeyHandler::handle('0', VimState::Visual));
        $this->assertSame(VimAction::CursorLineEnd,      VimKeyHandler::handle('$', VimState::Visual));
    }

    public function testVisualModeNoOpForCtrl(): void
    {
        $this->assertSame(VimAction::NoOp, VimKeyHandler::handle('h', VimState::Visual, VimKeyHandler::FEAT_ALL, true));
    }

    public function testVisualModeNoOpForUnrecognizedKey(): void
    {
        $this->assertSame(VimAction::NoOp, VimKeyHandler::handle('x', VimState::Visual));
    }

    // =========================================================================
    // handle() — Visual-line mode
    // =========================================================================

    public function testVisualLineModeEscapeEntersNormalMode(): void
    {
        $this->assertSame(VimAction::EnterNormalMode, VimKeyHandler::handle('esc', VimState::VisualLine));
    }

    public function testVisualLineModeDisabledReturnsNormalMode(): void
    {
        $this->assertSame(VimAction::EnterNormalMode, VimKeyHandler::handle('j', VimState::VisualLine, VimKeyHandler::FEAT_NORMAL));
    }

    public function testVisualLineModeMovement(): void
    {
        // j/k are mapped to CursorLeft/Right in visual-line mode (vertical movement)
        $this->assertSame(VimAction::CursorLeft,     VimKeyHandler::handle('j', VimState::VisualLine));
        $this->assertSame(VimAction::CursorRight,    VimKeyHandler::handle('k', VimState::VisualLine));
        $this->assertSame(VimAction::CursorLineStart, VimKeyHandler::handle('0', VimState::VisualLine));
        $this->assertSame(VimAction::CursorLineEnd,   VimKeyHandler::handle('$', VimState::VisualLine));
    }

    public function testVisualLineModeNoOpForCtrl(): void
    {
        $this->assertSame(VimAction::NoOp, VimKeyHandler::handle('j', VimState::VisualLine, VimKeyHandler::FEAT_ALL, true));
    }

    public function testVisualLineModeNoOpForUnrecognizedKey(): void
    {
        $this->assertSame(VimAction::NoOp, VimKeyHandler::handle('x', VimState::VisualLine));
    }

    // =========================================================================
    // Case-insensitivity (key normalization to lowercase)
    // =========================================================================

    public function testHandleIsCaseInsensitive(): void
    {
        $this->assertSame(VimKeyHandler::handle('H', VimState::Normal), VimKeyHandler::handle('h', VimState::Normal));
        $this->assertSame(VimKeyHandler::handle('I', VimState::Normal), VimKeyHandler::handle('i', VimState::Normal));
        $this->assertSame(VimKeyHandler::handle('ESC', VimState::Insert), VimKeyHandler::handle('esc', VimState::Insert));
    }

    // =========================================================================
    // FEAT_* constant coverage
    // =========================================================================

    public function testFeatureConstantsAreDistinct(): void
    {
        $this->assertNotSame(VimKeyHandler::FEAT_ALL,        VimKeyHandler::FEAT_NORMAL);
        $this->assertNotSame(VimKeyHandler::FEAT_ALL,        VimKeyHandler::FEAT_INSERT);
        $this->assertNotSame(VimKeyHandler::FEAT_ALL,        VimKeyHandler::FEAT_VISUAL);
        $this->assertNotSame(VimKeyHandler::FEAT_ALL,        VimKeyHandler::FEAT_VISUAL_LINE);
        $this->assertNotSame(VimKeyHandler::FEAT_ALL,        VimKeyHandler::FEAT_UNDO);
        $this->assertNotSame(VimKeyHandler::FEAT_NORMAL,    VimKeyHandler::FEAT_INSERT);
        $this->assertNotSame(VimKeyHandler::FEAT_VISUAL,    VimKeyHandler::FEAT_VISUAL_LINE);
    }
}
