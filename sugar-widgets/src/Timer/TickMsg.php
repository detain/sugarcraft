<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Timer;

/**
 * @deprecated Renamed to {@see TimerTick} (sugar-widgets-013): three
 *             unrelated `TickMsg` types shared this name across Timer,
 *             Stopwatch and the Spinner façade, so a wrong-kind tick was
 *             silently swallowed. The alias keeps old `use` lines
 *             resolving; new code names `TimerTick`. Removal at 2.0.
 */
class_alias('SugarCraft\Widgets\Timer\TimerTick', 'SugarCraft\Widgets\Timer\TickMsg');
