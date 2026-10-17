<?php

declare(strict_types=1);

namespace SugarCraft\Core\Msg;

/**
 * Mouse-button press. Adds no new fields over {@see MouseMsg}; the
 * separate type lets handlers pattern-match without checking
 * {@see \SugarCraft\Core\MouseAction}.
 */
final class MouseClickMsg extends MouseMsg
{
}
