<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Msg;

use SugarCraft\Core\Msg;

/**
 * Focus-in event — terminal received focus (CSI I).
 *
 * Mirrors charmbracelet/x/vt FocusInMsg.
 *
 * @implements Msg
 */
final readonly class FocusInMsg implements Msg
{
}
