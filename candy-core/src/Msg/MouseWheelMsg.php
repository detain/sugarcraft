<?php

declare(strict_types=1);

namespace CandyCore\Core\Msg;

/**
 * Mouse-wheel scroll event. The direction is in
 * {@see MouseMsg::$button} ({@see \CandyCore\Core\MouseButton::WheelUp}
 * or {@see \CandyCore\Core\MouseButton::WheelDown}).
 */
final class MouseWheelMsg extends MouseMsg
{
}
