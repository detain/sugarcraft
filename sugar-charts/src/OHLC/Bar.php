<?php

declare(strict_types=1);

namespace SugarCraft\Charts\OHLC;

/**
 * CH-57 (sugar-charts-089): `OHLC\Bar` collided with `BarChart\Bar` — two
 * public classes, one short name. The candle value object is now
 * {@see Candle}; this file declares no class of its own and only keeps the
 * old FQN resolvable through the autoloader for one minor, house alias
 * seat as in sugar-widgets.
 *
 * @deprecated Use SugarCraft\Charts\OHLC\Candle; REMOVED-IN 2.0.0.
 */
class_alias(Candle::class, Bar::class);
