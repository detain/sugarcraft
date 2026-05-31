<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Vim;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * Unified vim keybinding handler.
 *
 * Accepts a key event (either a normalized string key or a KeyMsg) and the current
 * VimState, and returns the corresponding VimAction to be executed by the
 * consuming model. This collapses 4 independent vim-mode codepaths into 1.
 *
 * Key format for string keys:
 *   - Single characters: 'h', 'l', 'w', 'b', '0', '$', 'x', 'i', 'a', 'A', 'I', 'v', 'd', 'y', 'p', 'u'
 *   - Special keys: 'left', 'right', 'up', 'down', 'home', 'end', 'esc', 'backspace', 'delete',
 *                   'ctrl_a', 'ctrl_e', 'ctrl_u', 'ctrl_k', 'ctrl_p', 'ctrl_n', 'ctrl_r'
 *
 * Mirrors Charmbracelet/bubbles TextInput vim mode and erikgeiser/promptkit ViMode.
 *
 * @see https://github.com/charmbracelet/bubbles/blob/master/textinput.go
 * @see https://github.com/erikgeiser/promptkit
 */
final class VimKeyHandler
{
    /** Enable all vim features (all modes + all actions). */
    public const FEAT_ALL = 0b11111;

    /** Enable normal mode navigation and commands. */
    public const FEAT_NORMAL = 0b00001;

    /** Enable insert mode (Escape returns to normal). */
    public const FEAT_INSERT = 0b00010;

    /** Enable visual mode (character-wise selection). */
    public const FEAT_VISUAL = 0b00100;

    /** Enable visual-line mode (line-wise selection). */
    public const FEAT_VISUAL_LINE = 0b01000;

    /** Enable undo/redo. */
    public const FEAT_UNDO = 0b10000;

    /**
     * @param string          $key      Normalized key (single char or special name)
     * @param VimState        $state    Current vim state
     * @param int             $features Bitmask of FEAT_* constants
     * @param bool            $ctrl     Whether ctrl modifier is active (for KeyMsg path)
     *
     * @return ?VimAction The action to perform, or null if the key is not handled
     */
    public static function handle(string $key, VimState $state, int $features = self::FEAT_ALL, bool $ctrl = false): ?VimAction
    {
        // Normalize the key to lowercase for matching
        $keyLower = strtolower($key);

        return match ($state) {
            VimState::Insert => self::handleInsertMode($keyLower, $key, $features, $ctrl),
            VimState::Normal => self::handleNormalMode($keyLower, $key, $features, $ctrl),
            VimState::Visual => self::handleVisualMode($keyLower, $key, $features, $ctrl),
            VimState::VisualLine => self::handleVisualLineMode($keyLower, $key, $features, $ctrl),
        };
    }

    /**
     * Handle a key in Insert mode.
     */
    private static function handleInsertMode(string $keyLower, string $key, int $features, bool $ctrl): ?VimAction
    {
        // Escape enters normal mode
        if ($keyLower === 'esc') {
            return VimAction::EnterNormalMode;
        }

        if (!($features & self::FEAT_INSERT)) {
            return VimAction::NoOp;
        }

        // Ctrl combinations in insert mode
        if ($ctrl) {
            return match ($keyLower) {
                'ctrl_a' => VimAction::CursorLineStart,
                'ctrl_e' => VimAction::CursorLineEnd,
                'ctrl_u' => VimAction::DeleteToStart,
                'ctrl_k' => VimAction::DeleteToEnd,
                'ctrl_p' => VimAction::HistoryUp,
                'ctrl_n' => VimAction::HistoryDown,
                default => VimAction::NoOp,
            };
        }

        // Arrow keys in insert mode
        return match ($keyLower) {
            'left'  => VimAction::CursorLeft,
            'right' => VimAction::CursorRight,
            'up'    => VimAction::HistoryUp,
            'down'  => VimAction::HistoryDown,
            'home'  => VimAction::CursorLineStart,
            'end'   => VimAction::CursorLineEnd,
            default => VimAction::NoOp,
        };
    }

    /**
     * Handle a key in Normal mode.
     */
    private static function handleNormalMode(string $keyLower, string $key, int $features, bool $ctrl): ?VimAction
    {
        if (!($features & self::FEAT_NORMAL)) {
            return VimAction::NoOp;
        }

        // Escape stays in normal mode (no-op)
        if ($keyLower === 'esc') {
            return VimAction::NoOp;
        }

        // Arrow keys always work in normal mode
        if (!$ctrl) {
            $arrowAction = match ($keyLower) {
                'left'  => VimAction::CursorLeft,
                'right' => VimAction::CursorRight,
                'up'    => VimAction::HistoryUp,
                'down'  => VimAction::HistoryDown,
                default => null,
            };
            if ($arrowAction !== null) {
                return $arrowAction;
            }
            // Not an arrow key, continue to character bindings
        }

        // Ctrl combinations in normal mode
        if ($ctrl) {
            return match ($keyLower) {
                'p' => VimAction::HistoryUp,
                'n' => VimAction::HistoryDown,
                'r' => ($features & self::FEAT_UNDO) ? VimAction::Redo : VimAction::NoOp,
                default => VimAction::NoOp,
            };
        }

        // Character bindings in normal mode
        return match ($keyLower) {
            // Movement
            'h' => VimAction::CursorLeft,
            'l' => VimAction::CursorRight,
            'w' => VimAction::CursorWordForward,
            'b' => VimAction::CursorWordBackward,
            '0' => VimAction::CursorLineStart,
            '$' => VimAction::CursorLineEnd,

            // Enter insert mode
            'i' => VimAction::EnterInsertMode,
            'a' => VimAction::EnterInsertMode, // consuming model should move cursor right too
            'A' => VimAction::EnterInsertMode, // consuming model should move to EOL too
            'I' => VimAction::EnterInsertMode, // consuming model should move to BOL too

            // Enter visual mode
            'v' => ($features & self::FEAT_VISUAL) ? VimAction::EnterVisualMode : VimAction::NoOp,

            // Delete (x deletes char, dd handled as pending motion)
            'x' => VimAction::DeleteChar,

            // Pending motions (dd, yy) — handler sets a pending flag
            'd' => VimAction::DeleteLine, // dd = delete line (consumer should detect double)
            'y' => VimAction::YankLine,   // yy = yank line (consumer should detect double)

            // Undo/redo
            'u' => ($features & self::FEAT_UNDO) ? VimAction::Undo : VimAction::NoOp,

            // Paste
            'p' => VimAction::Paste,

            default => VimAction::NoOp,
        };
    }

    /**
     * Handle a key in Visual mode.
     */
    private static function handleVisualMode(string $keyLower, string $key, int $features, bool $ctrl): ?VimAction
    {
        if (!($features & self::FEAT_VISUAL)) {
            return VimAction::EnterNormalMode;
        }

        // Escape cancels visual mode back to normal
        if ($keyLower === 'esc') {
            return VimAction::EnterNormalMode;
        }

        // Movement keys in visual mode extend selection
        if (!$ctrl) {
            return match ($keyLower) {
                'h' => VimAction::CursorLeft,
                'l' => VimAction::CursorRight,
                'w' => VimAction::CursorWordForward,
                'b' => VimAction::CursorWordBackward,
                '0' => VimAction::CursorLineStart,
                '$' => VimAction::CursorLineEnd,
                default => VimAction::NoOp,
            };
        }

        return VimAction::NoOp;
    }

    /**
     * Handle a key in Visual-line mode.
     */
    private static function handleVisualLineMode(string $keyLower, string $key, int $features, bool $ctrl): ?VimAction
    {
        if (!($features & self::FEAT_VISUAL_LINE)) {
            return VimAction::EnterNormalMode;
        }

        // Escape cancels visual-line mode back to normal
        if ($keyLower === 'esc') {
            return VimAction::EnterNormalMode;
        }

        // Movement keys in visual-line mode
        if (!$ctrl) {
            return match ($keyLower) {
                'j' => VimAction::CursorLeft,   // down one line
                'k' => VimAction::CursorRight,  // up one line
                '0' => VimAction::CursorLineStart,
                '$' => VimAction::CursorLineEnd,
                default => VimAction::NoOp,
            };
        }

        return VimAction::NoOp;
    }

    /**
     * Convert a KeyMsg to a normalized key string for VimKeyHandler.
     *
     * @return array{0: string, 1: bool} [normalized key string, whether ctrl is pressed]
     */
    public static function normalizeKeyMsg(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Char) {
            $key = $msg->rune;
            if ($msg->ctrl) {
                // Map any ctrl+letter combination to ctrl_<letter> format
                // This handles ctrl+a-z (codes 1-26) as well as ctrl+k (code 11)
                // when the test directly creates KeyMsg with ctrl=true
                $ord = ord(strtoupper($key));
                if ($ord >= 65 && $ord <= 90) {
                    // It's a letter A-Z / a-z, prefix with ctrl_
                    $key = 'ctrl_' . strtolower($key);
                } elseif ($ord >= 0x01 && $ord <= 0x1A) {
                    // It's a control code 1-26 (ctrl+a-z via InputReader mapping)
                    $key = 'ctrl_' . chr(0x60 + $ord);
                }
            }
            return [$key, $msg->ctrl];
        }

        // Map KeyType to string key name
        $key = match ($msg->type) {
            KeyType::Left      => 'left',
            KeyType::Right     => 'right',
            KeyType::Up        => 'up',
            KeyType::Down      => 'down',
            KeyType::Home      => 'home',
            KeyType::End       => 'end',
            KeyType::Escape    => 'esc',
            KeyType::Backspace => 'backspace',
            KeyType::Delete    => 'delete',
            KeyType::Tab       => 'tab',
            KeyType::Enter     => 'enter',
            KeyType::Space     => 'space',
            KeyType::PageUp    => 'pageup',
            KeyType::PageDown  => 'pagedown',
            KeyType::F1        => 'f1',
            KeyType::F2        => 'f2',
            KeyType::F3        => 'f3',
            KeyType::F4        => 'f4',
            KeyType::F5        => 'f5',
            KeyType::F6        => 'f6',
            KeyType::F7        => 'f7',
            KeyType::F8        => 'f8',
            KeyType::F9        => 'f9',
            KeyType::F10       => 'f10',
            KeyType::F11       => 'f11',
            KeyType::F12       => 'f12',
            default           => 'unknown',
        };

        return [$key, false];
    }
}
