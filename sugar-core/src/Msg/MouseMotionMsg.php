<?php

declare(strict_types=1);

namespace SugarCraft\Core\Msg;

/**
 * Mouse-motion event. Emitted while the mouse moves with a button held
 * (cell-motion mode) or always (all-motion mode). Held button is in
 * {@see MouseMsg::$button}.
 */
final class MouseMotionMsg extends MouseMsg
{
}
