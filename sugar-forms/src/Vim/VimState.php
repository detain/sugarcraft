<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Vim;

/**
 * Vim editing states.
 *
 * Mirrors the vim editing modes supported by VimKeyHandler.
 */
enum VimState: string
{
    /** Insert mode — characters are inserted at cursor. */
    case Insert = 'insert';

    /** Normal mode — movement keys navigate; other keys issue commands. */
    case Normal = 'normal';

    /** Visual mode — character-wise selection; movements extend selection. */
    case Visual = 'visual';

    /**
     * Visual-line mode — entry-wise selection for list consumers. The
     * handler routes 'j'/'k' through CursorLeft/CursorRight (previous/next
     * entry): the pinned horizontal vocabulary of the E736 3.2 / R4 owner
     * ruling — whether this mode should grow true row motion stays open.
     */
    case VisualLine = 'visual-line';
}
