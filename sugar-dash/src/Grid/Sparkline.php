<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\Plot\Chart\Sparkline
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\Plot\Chart\Sparkline as CanonicalSparkline;

class_alias(CanonicalSparkline::class, __NAMESPACE__ . '\Sparkline');
