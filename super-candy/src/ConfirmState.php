<?php

declare(strict_types=1);

namespace CandyCore\SuperCandy;

/**
 * Pending-confirmation state for the {@see Manager}'s gate.
 *
 * `None` → the manager dispatches keys normally.
 * Any other value → the next keystroke is consumed by the
 * confirmation handler. `y` confirms; anything else cancels.
 */
enum ConfirmState
{
    case None;
    case DeleteSelected;
}
