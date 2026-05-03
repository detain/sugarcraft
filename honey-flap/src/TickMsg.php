<?php

declare(strict_types=1);

namespace CandyCore\Flap;

use CandyCore\Core\Msg;

/**
 * Frame-tick. Fired every ~33ms (≈30 fps) by a `Cmd::tick(...)` the
 * Game schedules from `init()` and re-schedules from every `update()`
 * — gives us a tight render loop without busy-waiting in the main
 * fiber.
 */
final class TickMsg implements Msg
{
}
