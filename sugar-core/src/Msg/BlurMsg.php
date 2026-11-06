<?php

declare(strict_types=1);

namespace SugarCraft\Core\Msg;

use SugarCraft\Core\Msg;

/**
 * Emitted when the terminal loses focus (ESC [O; `CSI ?1004h` must be
 * enabled). This is the live reader's focus-out event — not
 * {@see FocusLostMsg}, which no producer emits.
 */
final class BlurMsg implements Msg
{
}
