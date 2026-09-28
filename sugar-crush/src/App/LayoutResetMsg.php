<?php

declare(strict_types=1);

namespace SugarCraft\Crush\App;

/**
 * Message to reset the dock to the launch default — the command twin of the
 * gesture phase's `layout reset`. No payload: the reset target is fixed.
 */
final readonly class LayoutResetMsg implements Msg
{
}
