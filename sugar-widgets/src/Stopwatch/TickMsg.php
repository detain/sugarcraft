<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Stopwatch;

/**
 * @deprecated Renamed to {@see StopwatchTick} (sugar-widgets-013) — see
 *             the {@see \SugarCraft\Widgets\Timer\TickMsg} shim for the
 *             collision story. The alias keeps old `use` lines resolving;
 *             new code names `StopwatchTick`. Removal at 2.0.
 */
class_alias('SugarCraft\Widgets\Stopwatch\StopwatchTick', 'SugarCraft\Widgets\Stopwatch\TickMsg');
