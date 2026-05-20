<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Msg;

use SugarCraft\Core\Msg;

/**
 * Focus-out event — terminal lost focus (CSI O).
 *
 * Mirrors charmbracelet/x/vt FocusOutMsg.
 */
final readonly class FocusOutMsg implements Msg
{
}
