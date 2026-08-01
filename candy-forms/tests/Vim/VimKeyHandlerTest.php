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

    public function testInsertModeCtrlACtrlE(): void
    {
        $this->assertSame(VimAction::CursorLineStart, VimKeyHandler::handle('ctrl_a', VimState::Insert, VimKeyHandler::FEAT_ALL, true));
        $this->assertSame(VimAction::CursorLineEnd,  VimKeyHandler::handle('ctrl_e', VimState::Insert, VimKeyHandler::FEAT_ALL, true));
    }

    public function testInsertModeCtrlU(): void
    {
        $this->assertSame(VimAction::DeleteToStart, VimKeyHandler::handle('ctrl_u', VimState::Insert, VimKeyHandler::FEAT_ALL, true));
    }

    public function testInsertModeCtrlK(): void
    {
        $this->assertSame(VimAction::DeleteToEnd, VimKeyHandler::handle('ctrl_k', VimState::Insert, VimKeyHandler::FEAT_ALL, true));
    }

    public function testInsertModeCtrlP(): void
    {
        $this->assertSame(VimAction::HistoryUp, VimKeyHandler::handle('ctrl_p', VimState::Insert, VimKeyHandler::FEAT_ALL, true));
    }

    public function testInsertModeCtrlN(): void
    {
        $this->assertSame(VimAction::HistoryDown, VimKeyHandler::handle('ctrl_n', VimState::Insert, VimKeyHandler::FEAT_ALL, true));
    }

    public function testInsertModeNoOpForUnrecognizedCtrl(): void
    {
        $this->assertSame(VimAction::NoOp, VimKeyHandler::handle('ctrl_x', VimState::Insert, VimKeyHandler::FEAT_ALL, true));
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

    public function testNormalModeUndoRedo(): void
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

    public function testNormalModeCtrlPHistoryUp(): void
    {
        $this->assertSame(VimAction::HistoryUp, VimKeyHandler::handle('p', VimState::Normal, VimKeyHandler::FEAT_ALL, true));
    }

    public function testNormalModeCtrlNHistoryDown(): void
    {
        $this->assertSame(VimAction::HistoryDown, VimKeyHandler::handle('n', VimState::Normal, VimKeyHandler::FEAT_ALL, true));
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
    // normalizeKeyMsg() — KeyType::Char paths
    // =========================================================================

    public function testNormalizeKeyMsgCharNoCtrl(): void
    {
        $msg = new KeyMsg(KeyType::Char, 'h', false, false);
        [$key, $ctrl] = VimKeyHandler::normalizeKeyMsg($msg);
        $this->assertSame('h', $key);
        $this->assertFalse($ctrl);
    }

    public function testNormalizeKeyMsgCtrlLetter(): void
    {
        // Ctrl+A through Ctrl+Z (rune 'a' through 'z')
        $msg = new KeyMsg(KeyType::Char, 'a', true, false);
        [$key, $ctrl] = VimKeyHandler::normalizeKeyMsg($msg);
        $this->assertSame('ctrl_a', $key);
        $this->assertTrue($ctrl);

        $msg = new KeyMsg(KeyType::Char, 'z', true, false);
        [$key, $ctrl] = VimKeyHandler::normalizeKeyMsg($msg);
        $this->assertSame('ctrl_z', $key);
        $this->assertTrue($ctrl);
    }

    public function testNormalizeKeyMsgCtrlUpperCase(): void
    {
        // Ctrl+Shift+A should map to ctrl_a (normalized)
        $msg = new KeyMsg(KeyType::Char, 'A', true, false);
        [$key, $ctrl] = VimKeyHandler::normalizeKeyMsg($msg);
        $this->assertSame('ctrl_a', $key);
        $this->assertTrue($ctrl);
    }

    // =========================================================================
    // normalizeKeyMsg() — special KeyTypes
    // =========================================================================

    public function testNormalizeKeyMsgArrowKeys(): void
    {
        [$key] = VimKeyHandler::normalizeKeyMsg(new KeyMsg(KeyType::Left));
        $this->assertSame('left', $key);

        [$key] = VimKeyHandler::normalizeKeyMsg(new KeyMsg(KeyType::Right));
        $this->assertSame('right', $key);

        [$key] = VimKeyHandler::normalizeKeyMsg(new KeyMsg(KeyType::Up));
        $this->assertSame('up', $key);

        [$key] = VimKeyHandler::normalizeKeyMsg(new KeyMsg(KeyType::Down));
        $this->assertSame('down', $key);
    }

    public function testNormalizeKeyMsgHomeEnd(): void
    {
        [$key] = VimKeyHandler::normalizeKeyMsg(new KeyMsg(KeyType::Home));
        $this->assertSame('home', $key);

        [$key] = VimKeyHandler::normalizeKeyMsg(new KeyMsg(KeyType::End));
        $this->assertSame('end', $key);
    }

    public function testNormalizeKeyMsgEscape(): void
    {
        [$key] = VimKeyHandler::normalizeKeyMsg(new KeyMsg(KeyType::Escape));
        $this->assertSame('esc', $key);
    }

    public function testNormalizeKeyMsgBackspaceDelete(): void
    {
        [$key] = VimKeyHandler::normalizeKeyMsg(new KeyMsg(KeyType::Backspace));
        $this->assertSame('backspace', $key);

        [$key] = VimKeyHandler::normalizeKeyMsg(new KeyMsg(KeyType::Delete));
        $this->assertSame('delete', $key);
    }

    public function testNormalizeKeyMsgTabSpaceEnter(): void
    {
        [$key] = VimKeyHandler::normalizeKeyMsg(new KeyMsg(KeyType::Tab));
        $this->assertSame('tab', $key);

        [$key] = VimKeyHandler::normalizeKeyMsg(new KeyMsg(KeyType::Space));
        $this->assertSame('space', $key);

        [$key] = VimKeyHandler::normalizeKeyMsg(new KeyMsg(KeyType::Enter));
        $this->assertSame('enter', $key);
    }

    public function testNormalizeKeyMsgFunctionKeys(): void
    {
        [$key] = VimKeyHandler::normalizeKeyMsg(new KeyMsg(KeyType::F1));
        $this->assertSame('f1', $key);

        [$key] = VimKeyHandler::normalizeKeyMsg(new KeyMsg(KeyType::F12));
        $this->assertSame('f12', $key);
    }

    public function testNormalizeKeyMsgPageUpPageDown(): void
    {
        [$key] = VimKeyHandler::normalizeKeyMsg(new KeyMsg(KeyType::PageUp));
        $this->assertSame('pageup', $key);

        [$key] = VimKeyHandler::normalizeKeyMsg(new KeyMsg(KeyType::PageDown));
        $this->assertSame('pagedown', $key);
    }

    public function testNormalizeKeyMsgUnknownYieldsUnknown(): void
    {
        [$key] = VimKeyHandler::normalizeKeyMsg(new KeyMsg(KeyType::Unknown));
        $this->assertSame('unknown', $key);
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

    // =========================================================================
    // Case-insensitivity (key normalization to lowercase)
    // =========================================================================

    public function testHandleIsCaseInsensitive(): void
    {
        $this->assertSame(VimKeyHandler::handle('H', VimState::Normal), VimKeyHandler::handle('h', VimState::Normal));
        $this->assertSame(VimKeyHandler::handle('I', VimState::Normal), VimKeyHandler::handle('i', VimState::Normal));
        $this->assertSame(VimKeyHandler::handle('ESC', VimState::Insert), VimKeyHandler::handle('esc', VimState::Insert));
    }
}
