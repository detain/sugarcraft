<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\Plot\Chart\Gauge
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\Plot\Chart\Gauge as CanonicalGauge;

class_alias(CanonicalGauge::class, __NAMESPACE__ . '\Gauge');
