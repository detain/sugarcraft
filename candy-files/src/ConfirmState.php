<?php

declare(strict_types=1);

namespace SugarCraft\Files;

/**
 * Pending-confirmation state for the {@see Manager}'s gate.
 *
 * No upstream — house pattern (finding 051). superfile confirms through
 * bubbletea dialog panes; this explicit enum gate is the library's own.
 *
 * `None` → the manager dispatches keys normally. Any other value →
 * the next keystroke is consumed by the confirmation handler, which
 * takes one of two shapes: DeleteSelected/CopySelected/MoveSelected
 * run a single-key gate (`y` OR the affirmative the active locale's
 * prompt teaches — German `j`, Russian `д`, Turkish `e` — confirms,
 * anything else cancels), while RenameSelected and MkdirPrompt are
 * the same text-entry submode — printable runes append to the
 * buffer, Enter commits, Esc cancels (CF-10: `N` creates a directory
 * through it).
 */
enum ConfirmState
{
    case None;
    case DeleteSelected;
    case CopySelected;
    case MoveSelected;
    case RenameSelected;
    case MkdirPrompt;
}
