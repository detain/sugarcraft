<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Vim;

/**
 * Actions returned by VimKeyHandler after processing a key in a given VimState.
 *
 * Each action represents the INTENT of the keypress — the consuming model
 * is responsible for executing the action on its own state.
 *
 * Mirrors vim editing actions from Charmbracelet/bubbles TextInput.
 */
enum VimAction: string
{
    /** Move cursor one character to the left. */
    case CursorLeft = 'cursor-left';

    /** Move cursor one character to the right. */
    case CursorRight = 'cursor-right';

    /** Move cursor to the start of the next word. */
    case CursorWordForward = 'cursor-word-forward';

    /** Move cursor to the start of the previous word. */
    case CursorWordBackward = 'cursor-word-backward';

    /** Move cursor to the beginning of the line. */
    case CursorLineStart = 'cursor-line-start';

    /** Move cursor to the end of the line. */
    case CursorLineEnd = 'cursor-line-end';

    /** Delete the character under the cursor (vim x). */
    case DeleteChar = 'delete-char';

    /** Delete from cursor to the beginning of the line (vim Ctrl+u in insert mode). */
    case DeleteToStart = 'delete-to-start';

    /** Delete from cursor to the end of the line (vim Ctrl+k in insert mode). */
    case DeleteToEnd = 'delete-to-end';

    /** Delete the entire line (vim dd). */
    case DeleteLine = 'delete-line';

    /** Yank (copy) the entire line (vim yy). */
    case YankLine = 'yank-line';

    /** Paste the most recently yanked text after cursor (vim p). */
    case Paste = 'paste';

    /** Switch to Insert mode. */
    case EnterInsertMode = 'enter-insert-mode';

    /** Switch to Normal mode. */
    case EnterNormalMode = 'enter-normal-mode';

    /** Switch to Visual mode. */
    case EnterVisualMode = 'enter-visual-mode';

    /** Switch to Visual-line mode. */
    case EnterVisualLineMode = 'enter-visual-line-mode';

    /** Navigate to the previous history entry. */
    case HistoryUp = 'history-up';

    /** Navigate to the next history entry. */
    case HistoryDown = 'history-down';

    /** Undo the last change (vim u). */
    case Undo = 'undo';

    /** Redo the last undone change (vim Ctrl+r). */
    case Redo = 'redo';

    /** The key press has no effect in the current state. */
    case NoOp = 'noop';
}
